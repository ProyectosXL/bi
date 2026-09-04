<?php
/**
 * AvanceQuincenalDB
 * Avance parcial de venta (facturación acumulada a la fecha vs. el objetivo REAL del mes
 * completo, no prorateado) para el reporte de mitad de mes de Premios Comercial — ver
 * premios/README.md, sección "Avance de los primeros 15 días".
 *
 * Deliberadamente SEPARADA de PremiosDB: esta clase solo LEE tablas DIARIAS
 * (BI_SALES_SUCURSALES, BI_OBJETIVOS_SUCURSALES, BI_SALES_TOTAL_TICKETS, BI_SALES_TICKETS —
 * las mismas que usan sales/ y global/, que sí se actualizan a diario, a diferencia de las
 * tablas mensuales de premios) y nunca escribe ni calcula un importe de premio. Así es
 * estructuralmente imposible que el avance parcial se sume con el cierre mensual real
 * (BI_T_ESTADISTICAS_VENTAS_PROPIOS, que carga un SP una sola vez al mes) — son fuentes de
 * datos completamente distintas.
 *
 * El único punto de contacto con las tablas de premios es de solo lectura:
 * mapeoSucursalSupervisora() lee el último mes YA CERRADO de BI_T_ESTADISTICAS_VENTAS_PROPIOS
 * únicamente para saber qué sucursal (NRO_SUCURS) es de qué supervisora — ese mapeo no
 * existe en las tablas diarias, que están indexadas solo por NRO_SUCURS.
 *
 * Alcance v1: solo Locales Propios (Argentina) — no Franquicias.
 */
class AvanceQuincenalDB
{
    /** NRO_SUCURS=1 ("CENTRAL") es una fila sintética sin ventas reales — igual criterio que PremiosDB. */
    private const CASA_CENTRAL_NRO = 1;

    /** @var resource Conexión a XL-APPS/POWER_BI_CONTROL */
    private $conn;

    public function __construct(
        private readonly string $desde,
        private readonly string $hasta,
    ) {
        require_once __DIR__ . '/../../class/classEnv.php';
        require_once __DIR__ . '/../../class/Conexion.php';
        require_once __DIR__ . '/PremiosDB.php';

        $this->conn = (new Conexion())->conectar('power');
        if (!$this->conn) {
            throw new RuntimeException('No se pudo conectar a XL-APPS/POWER_BI_CONTROL');
        }
    }

    /**
     * @return array<int,string> Mapa NRO_SUCURS => nombre de supervisora (formateado),
     * tomado del último mes YA CERRADO disponible en BI_T_ESTADISTICAS_VENTAS_PROPIOS.
     * Excluye NRO_SUCURS=1 ("CENTRAL", sin ventas reales) y la fila sintética
     * SUPERVISORA='TODAS' (ECOMMERCE, no pertenece a ninguna supervisora).
     */
    public function mapeoSucursalSupervisora(): array
    {
        $sql = "SELECT NRO_SUCURS, SUPERVISORA
                FROM BI_T_ESTADISTICAS_VENTAS_PROPIOS
                WHERE FECHA = (SELECT MAX(FECHA) FROM BI_T_ESTADISTICAS_VENTAS_PROPIOS)
                  AND NRO_SUCURS <> " . self::CASA_CENTRAL_NRO;
        $stmt = sqlsrv_query($this->conn, $sql);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_T_ESTADISTICAS_VENTAS_PROPIOS: ' . print_r(sqlsrv_errors(), true));
        }
        $out = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $nombre = PremiosDB::formatearNombre($r['SUPERVISORA']);
            if ($nombre === 'Todas') continue;
            $out[(int) $r['NRO_SUCURS']] = $nombre;
        }
        return $out;
    }

    /** Fila vacía por defecto para una sucursal que todavía no tiene entrada en $out — evita repetir el literal. */
    private static function filaVacia(int $nro, string $sucursal = ''): array
    {
        return [
            'nro_sucurs'       => $nro,
            'sucursal'         => $sucursal,
            'facturacion'      => 0.0,
            'facturacion_prev' => 0.0,
            'objetivo'         => 0.0,
            'tickets'          => 0,
            'tickets_2do_prod' => 0,
            'tickets_3er_prod' => 0,
        ];
    }

    /**
     * Facturación (actual + mismo rango un año atrás) y tickets (total + con 2do/3er
     * producto) acumulados entre $desde y $hasta, más el objetivo del MES COMPLETO (no
     * prorateado a $hasta — ver más abajo), por sucursal — de las tablas DIARIAS
     * (BI_SALES_SUCURSALES, BI_OBJETIVOS_SUCURSALES, BI_SALES_TOTAL_TICKETS,
     * BI_SALES_TICKETS), no de las mensuales de premios. Se agrupan por NRO_SUCURS y se
     * cruzan en PHP (los nombres de columna difieren entre tablas: NRO_SUCURS vs. NRO_SUCURSAL).
     *
     * @return array<int,array{nro_sucurs:int,sucursal:string,facturacion:float,facturacion_prev:float,objetivo:float,tickets:int,tickets_2do_prod:int,tickets_3er_prod:int}>
     *         'objetivo' = objetivo real del mes completo, no el prorateado a $hasta.
     */
    public function avancePorSucursal(): array
    {
        $sqlFact = "SELECT NRO_SUCURS, MAX(SUCURSAL) AS SUCURSAL, SUM(IMPORTE) AS FACTURACION
                    FROM BI_SALES_SUCURSALES WITH (NOLOCK)
                    WHERE FECHA >= ? AND FECHA < DATEADD(day, 1, CAST(? AS DATE))
                    GROUP BY NRO_SUCURS";
        $stmtFact = sqlsrv_query($this->conn, $sqlFact, [$this->desde, $this->hasta]);
        if ($stmtFact === false) {
            throw new RuntimeException('Error consultando BI_SALES_SUCURSALES: ' . print_r(sqlsrv_errors(), true));
        }

        $out = [];
        while ($r = sqlsrv_fetch_array($stmtFact, SQLSRV_FETCH_ASSOC)) {
            $nro = (int) $r['NRO_SUCURS'];
            $out[$nro] = self::filaVacia($nro, trim($r['SUCURSAL'] ?? ''));
            $out[$nro]['facturacion'] = (float) $r['FACTURACION'];
        }

        // El objetivo es el del MES COMPLETO, no prorateado a $this->hasta (día 15): la idea
        // del avance es "cuánto del objetivo real del mes ya se hizo", no compararse contra
        // un objetivo parcial inventado. Se recalculan los límites del mes a partir de
        // $this->desde en vez de asumir que ya viene como 1° del mes, por si en el futuro se
        // llama con un rango que no arranca ahí.
        $mesInicio = (new DateTime($this->desde))->format('Y-m-01');
        $mesFin    = (new DateTime($this->desde))->format('Y-m-t');
        $sqlObj = "SELECT NRO_SUCURSAL, SUM(IMPORTE_OBJ) AS OBJETIVO
                   FROM BI_OBJETIVOS_SUCURSALES WITH (NOLOCK)
                   WHERE FECHA >= ? AND FECHA < DATEADD(day, 1, CAST(? AS DATE))
                   GROUP BY NRO_SUCURSAL";
        $stmtObj = sqlsrv_query($this->conn, $sqlObj, [$mesInicio, $mesFin]);
        if ($stmtObj === false) {
            throw new RuntimeException('Error consultando BI_OBJETIVOS_SUCURSALES: ' . print_r(sqlsrv_errors(), true));
        }
        while ($r = sqlsrv_fetch_array($stmtObj, SQLSRV_FETCH_ASSOC)) {
            $nro = (int) $r['NRO_SUCURSAL'];
            $out[$nro] ??= self::filaVacia($nro);
            $out[$nro]['objetivo'] = (float) $r['OBJETIVO'];
        }

        // Mismo rango de días, un año atrás — para "Facturación Var %" (mismo patrón que
        // GlobalDashboardDB::getFacturacionPorSucursal(), comparando dos rangos de BI_SALES_SUCURSALES).
        $desdePrev = (new DateTime($this->desde))->modify('-1 year')->format('Y-m-d');
        $hastaPrev = (new DateTime($this->hasta))->modify('-1 year')->format('Y-m-d');
        $sqlFactPrev = "SELECT NRO_SUCURS, SUM(IMPORTE) AS FACTURACION_PREV
                        FROM BI_SALES_SUCURSALES WITH (NOLOCK)
                        WHERE FECHA >= ? AND FECHA < DATEADD(day, 1, CAST(? AS DATE))
                        GROUP BY NRO_SUCURS";
        $stmtFactPrev = sqlsrv_query($this->conn, $sqlFactPrev, [$desdePrev, $hastaPrev]);
        if ($stmtFactPrev === false) {
            throw new RuntimeException('Error consultando BI_SALES_SUCURSALES (período anterior): ' . print_r(sqlsrv_errors(), true));
        }
        while ($r = sqlsrv_fetch_array($stmtFactPrev, SQLSRV_FETCH_ASSOC)) {
            $nro = (int) $r['NRO_SUCURS'];
            $out[$nro] ??= self::filaVacia($nro);
            $out[$nro]['facturacion_prev'] = (float) $r['FACTURACION_PREV'];
        }

        // Tickets totales (T_COMP='FAC', mismo criterio que GlobalDashboardDB::getKpisPeriodo())
        // — de BI_SALES_TOTAL_TICKETS, que NO trae CANTIDAD (eso vive en BI_SALES_TICKETS), así
        // que hace falta la consulta aparte de más abajo para 2do/3er producto.
        $sqlTickets = "SELECT NRO_SUCURS, COUNT(DISTINCT N_COMP) AS TICKETS
                       FROM BI_SALES_TOTAL_TICKETS WITH (NOLOCK)
                       WHERE FECHA >= ? AND FECHA < DATEADD(day, 1, CAST(? AS DATE))
                         AND T_COMP = 'FAC'
                       GROUP BY NRO_SUCURS";
        $stmtTickets = sqlsrv_query($this->conn, $sqlTickets, [$this->desde, $this->hasta]);
        if ($stmtTickets === false) {
            throw new RuntimeException('Error consultando BI_SALES_TOTAL_TICKETS: ' . print_r(sqlsrv_errors(), true));
        }
        while ($r = sqlsrv_fetch_array($stmtTickets, SQLSRV_FETCH_ASSOC)) {
            $nro = (int) $r['NRO_SUCURS'];
            $out[$nro] ??= self::filaVacia($nro);
            $out[$nro]['tickets'] = (int) $r['TICKETS'];
        }

        // Tickets con 2do/3er producto: BI_SALES_TICKETS trae una fila por ítem del ticket
        // (CANTIDAD = cantidad de ítems distintos ya vendidos en ese N_COMP a esa fila) — mismo
        // criterio que GlobalDashboardDB::getTicketsProductos() (CANTIDAD > 1 / > 2).
        $sqlTickets23 = "SELECT NRO_SUCURS,
                                COUNT(DISTINCT CASE WHEN CANTIDAD > 1 THEN N_COMP END) AS TICKETS_2DO,
                                COUNT(DISTINCT CASE WHEN CANTIDAD > 2 THEN N_COMP END) AS TICKETS_3ER
                         FROM BI_SALES_TICKETS WITH (NOLOCK)
                         WHERE FECHA >= ? AND FECHA < DATEADD(day, 1, CAST(? AS DATE))
                         GROUP BY NRO_SUCURS";
        $stmtTickets23 = sqlsrv_query($this->conn, $sqlTickets23, [$this->desde, $this->hasta]);
        if ($stmtTickets23 === false) {
            throw new RuntimeException('Error consultando BI_SALES_TICKETS: ' . print_r(sqlsrv_errors(), true));
        }
        while ($r = sqlsrv_fetch_array($stmtTickets23, SQLSRV_FETCH_ASSOC)) {
            $nro = (int) $r['NRO_SUCURS'];
            $out[$nro] ??= self::filaVacia($nro);
            $out[$nro]['tickets_2do_prod'] = (int) $r['TICKETS_2DO'];
            $out[$nro]['tickets_3er_prod'] = (int) $r['TICKETS_3ER'];
        }

        return $out;
    }

    /**
     * Mismo criterio que PremiosDB::cumplimientoObjVenta() — se duplica la fórmula (2
     * líneas) en vez de acoplar esta clase a PremiosDB, a propósito: esta clase no debe
     * depender de la clase que calcula premios reales.
     */
    private function pctCumplimiento(float $fact, float $obj): ?float
    {
        if ($obj <= 0) return $fact > 0 ? null : -1.0;
        return $fact / $obj - 1;
    }

    /** Mismo criterio que PremiosDB::facturacionVarPct() — duplicado por el mismo motivo que pctCumplimiento(). */
    private function pctVar(float $fact, float $factAnt): ?float
    {
        if ($factAnt <= 0) return $fact > 0 ? null : -1.0;
        return $fact / $factAnt - 1;
    }

    /** Mismo criterio que PremiosDB::ticketPromedioEst() — duplicado por el mismo motivo que pctCumplimiento(). */
    private function ticketPromedioEst(float $fact, int $tickets): float
    {
        if ($tickets <= 0) return 0.0;
        return ceil(($fact / $tickets) / 100) * 100;
    }

    /**
     * Ticket promedio de marca (sin filtro de supervisora) para el rango — mismo criterio
     * que PremiosDB::ticketPromedioMarca(), pero sobre $filasMapeadas (sucursales de
     * avancePorSucursal() ya restringidas a supervisoras activas — ver avancePorSupervisora()).
     */
    private function ticketPromedioMarca(array $filasMapeadas): float
    {
        $fact = 0.0; $tickets = 0;
        foreach ($filasMapeadas as $f) {
            $fact    += $f['facturacion'];
            $tickets += $f['tickets'];
        }
        return $this->ticketPromedioEst($fact, $tickets);
    }

    /** % ticket 2do/3er producto de marca — mismo criterio que PremiosDB::pctTicketProductoMarca(). */
    private function pctTicketProductoMarca(array $filasMapeadas, string $campo): float
    {
        $tickets = 0; $prod = 0;
        foreach ($filasMapeadas as $f) {
            $tickets += $f['tickets'];
            $prod    += $f[$campo];
        }
        return $tickets > 0 ? $prod / $tickets : 0.0;
    }

    /**
     * % Cumplimiento Coach (ex "% Cumpl. Cadena", renombrado a pedido del cliente,
     * 2026-09-01 — la fórmula NO cambió): mismo criterio que
     * PremiosDB::pctCumplimientoCoach(), pero sobre las sucursales de avancePorSucursal()
     * (CENTRAL ya viene excluida por mapeoSucursalSupervisora(), así que no hace falta
     * filtrarla de nuevo acá).
     *
     * @param array $filasSucursal  Sucursales de una supervisora (avancePorSupervisora()[n]['sucursales'])
     * @param array $benchmarks     ['ticket_marca'=>float,'pct2_marca'=>float,'pct3_marca'=>float]
     */
    public function pctCumplimientoCoach(array $filasSucursal, array $benchmarks): float
    {
        $total = 0;
        $cumple = 0;
        foreach ($filasSucursal as $f) {
            $total += 3;
            if ($f['tickets'] <= 0) continue;

            if ($this->ticketPromedioEst($f['facturacion'], $f['tickets']) > $benchmarks['ticket_marca']) {
                $cumple++;
            }
            if (($f['tickets_2do_prod'] / $f['tickets']) > $benchmarks['pct2_marca']) {
                $cumple++;
            }
            if (($f['tickets_3er_prod'] / $f['tickets']) > $benchmarks['pct3_marca']) {
                $cumple++;
            }
        }
        return $total > 0 ? $cumple / $total : 0.0;
    }

    /**
     * Agrupa avancePorSucursal() por supervisora, usando mapeoSucursalSupervisora(). Los
     * benchmarks de marca (ticket promedio, % ticket 2do/3er producto) se calculan sobre
     * TODAS las sucursales mapeadas a alguna supervisora activa (no solo las de la
     * supervisora que se está mirando) — mismo criterio que PremiosDB::resumenPorSupervisora()
     * con "todosLosPropios".
     *
     * @param string[] $supervisorasActivas Lista de supervisoras a incluir (mismo orden que
     *        PremiosDB::getSupervisoras(), ya filtrado por visibilidad) — una sucursal cuyo
     *        mapeo apunte a una supervisora oculta/inactiva queda afuera del resultado.
     * @return array<int,array{supervisora:string,sucursales:array,facturacion_total:float,facturacion_prev_total:float,objetivo_total:float,pct_cumplimiento:?float,pct_var:?float,ticket_promedio:float,pct_ticket_2do:float,pct_ticket_3er:float,pct_cumplimiento_cadena:float,benchmarks:array{ticket_marca:float,pct2_marca:float,pct3_marca:float}}>
     */
    public function avancePorSupervisora(array $supervisorasActivas): array
    {
        $mapeo = $this->mapeoSucursalSupervisora();
        $porSucursal = $this->avancePorSucursal();

        $mapeadas = [];
        foreach ($porSucursal as $nro => $fila) {
            $supervisora = $mapeo[$nro] ?? null;
            if ($supervisora === null || !in_array($supervisora, $supervisorasActivas, true)) {
                continue;
            }
            $mapeadas[] = $fila;
        }
        $benchmarks = [
            'ticket_marca' => $this->ticketPromedioMarca($mapeadas),
            'pct2_marca'   => $this->pctTicketProductoMarca($mapeadas, 'tickets_2do_prod'),
            'pct3_marca'   => $this->pctTicketProductoMarca($mapeadas, 'tickets_3er_prod'),
        ];

        $vacio = ['sucursales' => [], 'facturacion_total' => 0.0, 'facturacion_prev_total' => 0.0, 'objetivo_total' => 0.0, 'tickets_total' => 0, 'tickets_2do_total' => 0, 'tickets_3er_total' => 0];
        $agrupado = [];
        foreach ($porSucursal as $nro => $fila) {
            $supervisora = $mapeo[$nro] ?? null;
            if ($supervisora === null || !in_array($supervisora, $supervisorasActivas, true)) {
                continue;
            }
            $agrupado[$supervisora] ??= $vacio;
            $agrupado[$supervisora]['sucursales'][] = $fila;
            $agrupado[$supervisora]['facturacion_total'] += $fila['facturacion'];
            $agrupado[$supervisora]['facturacion_prev_total'] += $fila['facturacion_prev'];
            $agrupado[$supervisora]['objetivo_total'] += $fila['objetivo'];
            $agrupado[$supervisora]['tickets_total'] += $fila['tickets'];
            $agrupado[$supervisora]['tickets_2do_total'] += $fila['tickets_2do_prod'];
            $agrupado[$supervisora]['tickets_3er_total'] += $fila['tickets_3er_prod'];
        }

        $out = [];
        foreach ($supervisorasActivas as $sup) {
            $datos = $agrupado[$sup] ?? $vacio;
            $out[] = [
                'supervisora'             => $sup,
                'sucursales'              => $datos['sucursales'],
                'facturacion_total'       => $datos['facturacion_total'],
                'facturacion_prev_total'  => $datos['facturacion_prev_total'],
                'objetivo_total'          => $datos['objetivo_total'],
                'pct_cumplimiento'        => $this->pctCumplimiento($datos['facturacion_total'], $datos['objetivo_total']),
                'pct_var'                 => $this->pctVar($datos['facturacion_total'], $datos['facturacion_prev_total']),
                'ticket_promedio'         => $this->ticketPromedioEst($datos['facturacion_total'], $datos['tickets_total']),
                'pct_ticket_2do'          => $datos['tickets_total'] > 0 ? $datos['tickets_2do_total'] / $datos['tickets_total'] : 0.0,
                'pct_ticket_3er'          => $datos['tickets_total'] > 0 ? $datos['tickets_3er_total'] / $datos['tickets_total'] : 0.0,
                'pct_cumplimiento_cadena' => $this->pctCumplimientoCoach($datos['sucursales'], $benchmarks),
                // Mismos benchmarks para toda supervisora (calculados sobre TODA la cadena, no
                // repetidos por sucursal) — se devuelven acá, no en una consulta aparte, para no
                // volver a correr avancePorSucursal()/mapeoSucursalSupervisora(). El front los usa
                // para pintar cada indicador en verde/rojo igual que la tabla de "Locales Propios".
                'benchmarks'              => $benchmarks,
            ];
        }
        return $out;
    }
}
