<?php
/**
 * GlobalDashboardDB
 * Acceso a datos para el dashboard global (GERENCIA / SUPERVISION).
 * Soporta multi-origen (argentina, uruguay, franquicias).
 *
 * Usa Filters (centralización de WHERE) y PeriodHelper (cálculo de períodos).
 */
class GlobalDashboardDB
{
    private $conn;
    private string $campoVendedor;
    private string $tablaObjetivos;
    private string $origen;

    public function __construct(string $origen = 'argentina')
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Filters.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';

        $cfg = getConfigForOrigen($origen);
        $this->origen         = $origen;
        $this->campoVendedor  = $cfg['campo_vendedor'];
        $this->tablaObjetivos = $cfg['tabla_objetivos'];

        $cid        = new Conexion();
        $this->conn = $cid->conectar($cfg['db']);
    }

    /* ──────────────────────────────────────────────
     *  HELPERS
     * ────────────────────────────────────────────── */

    private function query(string $sql, array $params = []): array
    {
        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            throw new RuntimeException('SQL error: ' . ($err[0]['message'] ?? 'unknown'));
        }
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $rows;
    }

    private function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }

    /** Construye el array de params de filtro normalizado para Filters::build(). */
    private function fp(?int $sucursal, string $vendedor, string $rubro, ?string $grupo, ?string $tipoTienda): array
    {
        return [
            'sucursal'    => $sucursal,
            'grupo'       => $grupo,
            'tipo_tienda' => $tipoTienda,
            'vendedor'    => $vendedor,
            'rubro'       => $rubro,
        ];
    }

    /* ──────────────────────────────────────────────
     *  PERÍODO — delega a PeriodHelper
     * ────────────────────────────────────────────── */

    public static function calcularPeriodo(string $tipo): array
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';
        return PeriodHelper::calcularPeriodo($tipo);
    }

    /* ──────────────────────────────────────────────
     *  KPIs GLOBALES
     * ────────────────────────────────────────────── */

    public function getKPIs(
        string $desde, string $hasta,
        ?int $sucursal = null, string $vendedor = '%', string $rubro = '%',
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $cv = $this->campoVendedor;
        $fp = $this->fp($sucursal, $vendedor, $rubro, $grupo, $tipoTienda);

        // Filtros para la tabla de ventas (alias s) — sucursal + vendedor + rubro
        [$sfS, $pS] = Filters::build($fp, 's', $cv, $this->origen, true, true);
        // Filtros para tickets (alias t) — sucursal + vendedor, sin rubro
        [$sfT, $pT] = Filters::build($fp, 't', $cv, $this->origen, true, false);
        // Filtros para objetivos (alias o) — solo sucursal, columna NRO_SUCURSAL
        [$sfO, $pO] = Filters::build($fp, 'o', $cv, $this->origen, false, false, 'NRO_SUCURSAL');

        $row = $this->queryOne("
            SELECT
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion,
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(CASE WHEN s.CANTIDAD > 0
                                 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END), 0) AS unidades_positivas,
                ISNULL(SUM(CASE WHEN s.CANTIDAD < 0
                                 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END) * -1, 0) AS cambios
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfS}
        ", array_merge([$desde, $hasta], $pS));

        $rowT = $this->queryOne("
            SELECT
                COUNT(DISTINCT t.N_COMP) AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_tickets
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT}
        ", array_merge([$desde, $hasta], $pT));

        $rowObj = $this->queryOne("
            SELECT ISNULL(SUM(o.IMPORTE_OBJ), 0) AS objetivo
            FROM {$this->tablaObjetivos} o
            WHERE o.FECHA >= ? AND o.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfO}
        ", array_merge([$desde, $hasta], $pO));

        $tickets    = (int)($rowT['tickets']     ?? 0);
        $sumaT      = (float)($rowT['suma_tickets'] ?? 0);
        $facturacion = (float)($row['facturacion'] ?? 0);
        $unidades    = (float)($row['unidades']    ?? 0);
        $unidadesPos = (float)($row['unidades_positivas'] ?? 0);
        $cambios     = (float)($row['cambios']     ?? 0);

        return [
            'facturacion'     => $facturacion,
            'unidades'        => $unidades,
            'tickets'         => $tickets,
            'ticket_promedio' => $tickets > 0 ? $sumaT / $tickets : 0,
            'cambios'         => $cambios,
            'porc_cambios'    => $unidadesPos > 0 ? $cambios / $unidadesPos : 0,
            'objetivo'        => (float)($rowObj['objetivo'] ?? 0),
        ];
    }

    /* ──────────────────────────────────────────────
     *  TICKETS 2DO Y 3ER PRODUCTO
     * ────────────────────────────────────────────── */

    public function getTicketsProductos(
        string $desde, string $hasta,
        ?int $sucursal = null, string $vendedor = '%',
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp($sucursal, $vendedor, '%', $grupo, $tipoTienda);
        [$sfT, $pT] = Filters::build($fp, 't', $this->campoVendedor, $this->origen, true, false);

        $row = $this->queryOne("
            SELECT
                COUNT(DISTINCT t.N_COMP) AS total,
                COUNT(DISTINCT CASE WHEN t.CANTIDAD > 1 THEN t.N_COMP END) AS seg,
                COUNT(DISTINCT CASE WHEN t.CANTIDAD > 2 THEN t.N_COMP END) AS ter
            FROM BI_SALES_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfT}
        ", array_merge([$desde, $hasta], $pT));

        $total = (int)($row['total'] ?? 0);
        $seg   = (int)($row['seg']   ?? 0);
        $ter   = (int)($row['ter']   ?? 0);

        return [
            'tickets_2do' => $seg,
            'porc_2do'    => $total > 0 ? $seg / $total : 0,
            'tickets_3ro' => $ter,
            'porc_3ro'    => $total > 0 ? $ter / $total : 0,
        ];
    }

    /* ──────────────────────────────────────────────
     *  TICKET PROMEDIO 2DO PRODUCTO
     * ────────────────────────────────────────────── */

    public function getTicketPromedio2do(
        string $desde, string $hasta,
        ?int $sucursal = null, string $vendedor = '%',
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp($sucursal, $vendedor, '%', $grupo, $tipoTienda);
        [$sfTk, $pTk] = Filters::build($fp, 'tk', $this->campoVendedor, $this->origen, true, false);
        [$sfTt, $pTt] = Filters::build($fp, 'tt', $this->campoVendedor, $this->origen, true, false);

        $row = $this->queryOne("
            SELECT
                COUNT(DISTINCT tt.N_COMP) AS tickets_con_2do,
                ISNULL(SUM(tt.IMP_TOTAL_TICKET), 0) AS facturacion_con_2do
            FROM BI_SALES_TOTAL_TICKETS tt
            INNER JOIN (
                SELECT DISTINCT N_COMP
                FROM BI_SALES_TICKETS tk
                WHERE tk.FECHA >= ? AND tk.FECHA < DATEADD(day,1,CAST(? AS DATE))
                  {$sfTk}
                  AND tk.CANTIDAD > 1
            ) t2 ON t2.N_COMP = tt.N_COMP
            WHERE tt.FECHA >= ? AND tt.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND tt.T_COMP = 'FAC' {$sfTt}
        ", array_merge([$desde, $hasta], $pTk, [$desde, $hasta], $pTt));

        $cant = (int)($row['tickets_con_2do']     ?? 0);
        $fact = (float)($row['facturacion_con_2do'] ?? 0);

        return [
            'tickets_con_2do'     => $cant,
            'facturacion_con_2do' => $fact,
            'ticket_promedio_2do' => $cant > 0 ? $fact / $cant : 0,
        ];
    }

    /* ──────────────────────────────────────────────
     *  % INCREMENTAL
     * ────────────────────────────────────────────── */

    public function getIncremental(
        string $desde, string $hasta,
        ?int $sucursal = null, string $vendedor = '%',
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp($sucursal, $vendedor, '%', $grupo, $tipoTienda);
        [$sfP, $pP] = Filters::build($fp, 'p', $this->campoVendedor, $this->origen, true, false);

        $row = $this->queryOne("
            SELECT
                ISNULL(SUM(p.CAMBIO), 0)       AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0) AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day,1,CAST(? AS DATE)) {$sfP}
        ", array_merge([$desde, $hasta], $pP));

        $cambios = (float)($row['cambios_incr']  ?? 0);
        $devol   = (float)($row['devoluciones'] ?? 0);

        return [
            'cambios_incr'     => $cambios,
            'devoluciones'     => $devol,
            'porc_incremental' => $devol != 0 ? ($cambios - $devol) / $devol : 0,
        ];
    }

    /* ──────────────────────────────────────────────
     *  CONVERSIÓN
     * ────────────────────────────────────────────── */

    public function getConversion(
        string $desde, string $hasta,
        ?int $sucursal = null,
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp($sucursal, '%', '%', $grupo, $tipoTienda);
        [$sfI, $pI] = Filters::build($fp, 'i', $this->campoVendedor, $this->origen, false, false);
        [$sfT, $pT] = Filters::build($fp, 't', $this->campoVendedor, $this->origen, false, false);

        $rowI = $this->queryOne("
            SELECT ISNULL(SUM(i.INGRESOS), 0) AS total_ingresos
            FROM BI_T_INGRESOS_SUCURSALES i
            WHERE i.FECHA >= ? AND i.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfI}
        ", array_merge([$desde, $hasta], $pI));

        $rowT = $this->queryOne("
            SELECT COUNT(DISTINCT t.N_COMP) AS total_tickets
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT}
              AND EXISTS (
                  SELECT 1
                  FROM BI_T_INGRESOS_SUCURSALES i2
                  WHERE i2.NRO_SUCURS = t.NRO_SUCURS
                    AND CAST(i2.FECHA AS DATE) = CAST(t.FECHA AS DATE)
                    AND i2.FECHA >= ? AND i2.FECHA < DATEADD(day,1,CAST(? AS DATE))
              )
        ", array_merge([$desde, $hasta], $pT, [$desde, $hasta]));

        $ingresos = (int)($rowI['total_ingresos'] ?? 0);
        $tickets  = (int)($rowT['total_tickets']  ?? 0);

        return [
            'ingresos'   => $ingresos,
            'tickets'    => $tickets,
            'conversion' => $ingresos > 0 ? $tickets / $ingresos : 0,
        ];
    }

    /* ──────────────────────────────────────────────
     *  SERIE TEMPORAL (sparklines)
     * ────────────────────────────────────────────── */

    public function getSerieFacturacion(
        string $desde, string $hasta,
        ?int $sucursal = null, string $vendedor = '%', string $rubro = '%',
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp($sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
        [$sfS,  $pS]  = Filters::build($fp, 's',  $this->campoVendedor, $this->origen, true,  true);
        [$sfT,  $pT]  = Filters::build($fp, 't',  $this->campoVendedor, $this->origen, true,  false);
        [$sfTk, $pTk] = Filters::build($fp, 'tk', $this->campoVendedor, $this->origen, true,  false);
        [$sfP,  $pP]  = Filters::build($fp, 'p',  $this->campoVendedor, $this->origen, false, false);
        [$sfI,  $pI]  = Filters::build($fp, 'ig', $this->campoVendedor, $this->origen, false, false);

        // Ventas: facturación, unidades, unidades positivas y cambios por día
        $rows = $this->query("
            SELECT
                CAST(s.FECHA AS DATE) AS fecha,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion,
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(CASE WHEN s.CANTIDAD > 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) AS unidades_positivas,
                ISNULL(SUM(CASE WHEN s.CANTIDAD < 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END) * -1, 0) AS cambios
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfS}
            GROUP BY CAST(s.FECHA AS DATE)
            ORDER BY 1 ASC
        ", array_merge([$desde, $hasta], $pS));

        // Tickets con 2do y 3er producto vía LEFT JOIN
        $tickRows = $this->query("
            SELECT
                CAST(t.FECHA AS DATE) AS fecha,
                COUNT(DISTINCT t.N_COMP) AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_tickets,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 1 THEN t.N_COMP END) AS tickets_2do,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 2 THEN t.N_COMP END) AS tickets_3ro
            FROM BI_SALES_TOTAL_TICKETS t
            LEFT JOIN BI_SALES_TICKETS tk
                ON t.N_COMP = tk.N_COMP
               AND tk.FECHA >= ? AND tk.FECHA < DATEADD(day,1,CAST(? AS DATE))
               {$sfTk}
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT}
            GROUP BY CAST(t.FECHA AS DATE)
        ", array_merge([$desde, $hasta], $pTk, [$desde, $hasta], $pT));

        $tickMap = [];
        foreach ($tickRows as $tr) {
            $tickMap[$tr['fecha']->format('Y-m-d')] = $tr;
        }

        // T. Prom. 2do Producto por día
        [$sfTk2, $pTk2] = Filters::build($fp, 'tk2', $this->campoVendedor, $this->origen, true,  false);
        [$sfTt2, $pTt2] = Filters::build($fp, 'tt2', $this->campoVendedor, $this->origen, true,  false);

        $tp2Rows = $this->query("
            SELECT
                CAST(tt2.FECHA AS DATE) AS fecha,
                COUNT(DISTINCT tt2.N_COMP) AS tickets_con_2do,
                ISNULL(SUM(tt2.IMP_TOTAL_TICKET), 0) AS facturacion_con_2do
            FROM BI_SALES_TOTAL_TICKETS tt2
            INNER JOIN (
                SELECT DISTINCT N_COMP
                FROM BI_SALES_TICKETS tk2
                WHERE tk2.FECHA >= ? AND tk2.FECHA < DATEADD(day,1,CAST(? AS DATE))
                  {$sfTk2}
                  AND tk2.CANTIDAD > 1
            ) t2 ON t2.N_COMP = tt2.N_COMP
            WHERE tt2.FECHA >= ? AND tt2.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND tt2.T_COMP = 'FAC' {$sfTt2}
            GROUP BY CAST(tt2.FECHA AS DATE)
        ", array_merge([$desde, $hasta], $pTk2, [$desde, $hasta], $pTt2));

        $tp2Map = [];
        foreach ($tp2Rows as $tr) {
            $tp2Map[$tr['fecha']->format('Y-m-d')] = $tr;
        }

        // Incremental por día
        $incrRows = $this->query("
            SELECT
                CAST(p.FECHA_MOV AS DATE) AS fecha,
                ISNULL(SUM(p.CAMBIO), 0) AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0) AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day,1,CAST(? AS DATE)) {$sfP}
            GROUP BY CAST(p.FECHA_MOV AS DATE)
        ", array_merge([$desde, $hasta], $pP));

        $incrMap = [];
        foreach ($incrRows as $ir) {
            $incrMap[$ir['fecha']->format('Y-m-d')] = $ir;
        }

        // Ingresos por día
        $ingRows = $this->query("
            SELECT
                CAST(ig.FECHA AS DATE) AS fecha,
                ISNULL(SUM(ig.INGRESOS), 0) AS ingresos
            FROM BI_T_INGRESOS_SUCURSALES ig
            WHERE ig.FECHA >= ? AND ig.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfI}
            GROUP BY CAST(ig.FECHA AS DATE)
        ", array_merge([$desde, $hasta], $pI));

        $ingMap = [];
        foreach ($ingRows as $ig) {
            $ingMap[$ig['fecha']->format('Y-m-d')] = (int)$ig['ingresos'];
        }

        $result = [];
        foreach ($rows as $row) {
            $fecha       = $row['fecha']->format('Y-m-d');
            $tickInfo    = $tickMap[$fecha]  ?? null;
            $tp2Info     = $tp2Map[$fecha]   ?? null;
            $incrInfo    = $incrMap[$fecha]  ?? null;

            $tickets     = (int)($tickInfo['tickets']      ?? 0);
            $sumaT       = (float)($tickInfo['suma_tickets'] ?? 0);
            $t2do        = (int)($tickInfo['tickets_2do']  ?? 0);
            $t3ro        = (int)($tickInfo['tickets_3ro']  ?? 0);
            $tp2Cnt      = (int)($tp2Info['tickets_con_2do']      ?? 0);
            $tp2Fact     = (float)($tp2Info['facturacion_con_2do'] ?? 0);
            $cambiosIncr = (float)($incrInfo['cambios_incr']   ?? 0);
            $devoluciones= (float)($incrInfo['devoluciones']   ?? 0);
            $unidadesPos = (float)$row['unidades_positivas'];
            $cambios     = (float)$row['cambios'];
            $ingresos    = $ingMap[$fecha] ?? 0;

            $result[] = [
                'fecha'               => $fecha,
                'facturacion'         => (float)$row['facturacion'],
                'unidades'            => (float)$row['unidades'],
                'tickets'             => $tickets,
                'ticket_promedio'     => $tickets > 0 ? $sumaT / $tickets : 0,
                'ticket_promedio_2do' => $tp2Cnt > 0 ? $tp2Fact / $tp2Cnt : 0,
                'porc_2do'            => $tickets > 0 ? $t2do / $tickets : 0,
                'porc_3ro'            => $tickets > 0 ? $t3ro / $tickets : 0,
                'porc_cambios'        => $unidadesPos > 0 ? $cambios / $unidadesPos : 0,
                'porc_incremental'    => $devoluciones != 0 ? ($cambiosIncr - $devoluciones) / $devoluciones : 0,
                'ingresos'            => $ingresos,
                'conversion'          => $ingresos > 0 ? $tickets / $ingresos : 0,
            ];
        }
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  SERIE DIARIA OBJETIVO
     * ────────────────────────────────────────────── */

    public function getSerieObjetivo(
        string $desde, string $hasta,
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp(null, '%', '%', $grupo, $tipoTienda);
        [$sfO, $pO] = Filters::build($fp, 'o', $this->campoVendedor, $this->origen, false, false, 'NRO_SUCURSAL');
        $rows = $this->query("
            SELECT CAST(o.FECHA AS DATE) AS fecha, ISNULL(SUM(o.IMPORTE_OBJ), 0) AS objetivo
            FROM {$this->tablaObjetivos} o
            WHERE o.FECHA >= ? AND o.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfO}
            GROUP BY CAST(o.FECHA AS DATE)
            ORDER BY 1 ASC
        ", array_merge([$desde, $hasta], $pO));
        $result = [];
        foreach ($rows as $row) {
            $result[$row['fecha']->format('Y-m-d')] = (float)$row['objetivo'];
        }
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  OBJETIVOS POR SUCURSAL
     * ────────────────────────────────────────────── */

    public function getObjetivosPorSucursal(
        string $desde_act, string $hasta_act,
        string $desde_total, string $hasta_total,
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp(null, '%', '%', $grupo, $tipoTienda);
        [$sfO, $pO] = Filters::build($fp, 'o', $this->campoVendedor, $this->origen, false, false, 'NRO_SUCURSAL');

        $rowsAct = $this->query("
            SELECT o.NRO_SUCURSAL, ISNULL(SUM(o.IMPORTE_OBJ), 0) AS objetivo_fecha
            FROM {$this->tablaObjetivos} o
            WHERE o.FECHA >= ? AND o.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfO}
            GROUP BY o.NRO_SUCURSAL
        ", array_merge([$desde_act, $hasta_act], $pO));

        $rowsTotal = $this->query("
            SELECT o.NRO_SUCURSAL, ISNULL(SUM(o.IMPORTE_OBJ), 0) AS objetivo_total
            FROM {$this->tablaObjetivos} o
            WHERE o.FECHA >= ? AND o.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfO}
            GROUP BY o.NRO_SUCURSAL
        ", array_merge([$desde_total, $hasta_total], $pO));

        $totalMap = [];
        foreach ($rowsTotal as $r) {
            $totalMap[(int)$r['NRO_SUCURSAL']] = (float)$r['objetivo_total'];
        }

        $result = [];
        foreach ($rowsAct as $r) {
            $nro = (int)$r['NRO_SUCURSAL'];
            $result[$nro] = [
                'nro_sucursal'   => $nro,
                'objetivo_fecha' => (float)$r['objetivo_fecha'],
                'objetivo_total' => $totalMap[$nro] ?? 0.0,
            ];
        }
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  FACTURACIÓN POR SUCURSAL
     * ────────────────────────────────────────────── */

    public function getFacturacionPorSucursal(
        string $desde_act, string $hasta_act,
        string $desde_prev, string $hasta_prev,
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp(null, '%', '%', $grupo, $tipoTienda);
        [$sfS, $pS] = Filters::build($fp, 's', $this->campoVendedor, $this->origen, false, false);

        $rowsAct = $this->query("
            SELECT s.NRO_SUCURS, ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfS}
            GROUP BY s.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pS));

        $rowsPrev = $this->query("
            SELECT s.NRO_SUCURS, ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfS}
            GROUP BY s.NRO_SUCURS
        ", array_merge([$desde_prev, $hasta_prev], $pS));

        $prevMap = [];
        foreach ($rowsPrev as $r) {
            $prevMap[(int)$r['NRO_SUCURS']] = (float)$r['facturacion'];
        }

        $result = [];
        foreach ($rowsAct as $r) {
            $nro  = (int)$r['NRO_SUCURS'];
            $fact = (float)$r['facturacion'];
            $prev = $prevMap[$nro] ?? 0.0;
            $result[$nro] = [
                'nro_sucurs'      => $nro,
                'facturacion'     => $fact,
                'facturacion_prev' => $prev,
                'var_fact'        => $prev > 0 ? ($fact - $prev) / $prev : ($fact > 0 ? 1 : 0),
            ];
        }
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  LISTAS PARA FILTROS
     * ────────────────────────────────────────────── */

    public function getSucursalesLista(): array
    {
        return $this->query("
            SELECT DISTINCT s.NRO_SUCURS, sl.DESC_SUCURSAL
            FROM BI_SALES_SUCURSALES s
            LEFT JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
              ON sl.NRO_SUCURSAL = s.NRO_SUCURS
            WHERE sl.HABILITADO = 1 OR sl.HABILITADO IS NULL
            ORDER BY sl.DESC_SUCURSAL
        ");
    }

    public function getGruposLista(): array
    {
        if ($this->origen !== 'argentina') return [];
        return $this->query("
            SELECT DISTINCT GRUPO FROM BI_DIM_SUCURSALES_GRUPO ORDER BY GRUPO
        ");
    }

    public function getTiposTiendaLista(): array
    {
        if ($this->origen !== 'argentina') return [];
        return $this->query("
            SELECT DISTINCT sl.TIPO_TIENDA
            FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
            WHERE sl.TIPO_TIENDA IS NOT NULL AND sl.HABILITADO = 1
            ORDER BY sl.TIPO_TIENDA
        ");
    }

    public function getVendedoresFiltro(?string $desde = null, ?string $hasta = null, ?int $sucursal = null): array
    {
        $cv     = $this->campoVendedor;
        $params = [];
        $where  = [];
        if ($desde !== null && $hasta !== null) {
            $where[]  = "FECHA BETWEEN ? AND ?";
            $params[] = $desde;
            $params[] = $hasta;
        }
        if ($sucursal !== null) {
            $where[]  = "NRO_SUCURS = ?";
            $params[] = $sucursal;
        }
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return $this->query("
            SELECT DISTINCT {$cv} FROM BI_SALES_SUCURSALES {$whereClause} ORDER BY {$cv}
        ", $params);
    }

    public function getRubrosFiltro(string $desde, string $hasta, ?int $sucursal = null): array
    {
        $sfS = $sucursal !== null ? "AND NRO_SUCURS = ?" : "";
        $suc = $sucursal !== null ? [$sucursal] : [];
        return $this->query("
            SELECT DISTINCT RUBRO FROM BI_SALES_SUCURSALES
            WHERE FECHA BETWEEN ? AND ?
              AND RUBRO NOT IN ('CONCEPTO','PACKAGING') {$sfS}
            ORDER BY RUBRO
        ", array_merge([$desde, $hasta], $suc));
    }

    /* ──────────────────────────────────────────────
     *  MAILS (tickets con email válido)
     * ────────────────────────────────────────────── */

    /* ──────────────────────────────────────────────
     *  SCORING POR SUCURSAL
     * ────────────────────────────────────────────── */

    public function getScorePorSucursal(
        string $desde_act,  string $hasta_act,
        string $desde_prev, string $hasta_prev,
        string $primerDiaMes, string $ultimoDiaMes,
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp  = $this->fp(null, '%', '%', $grupo, $tipoTienda);
        [$sfS,  $pS]  = Filters::build($fp, 's',  $this->campoVendedor, $this->origen, false, false);
        [$sfT,  $pT]  = Filters::build($fp, 't',  $this->campoVendedor, $this->origen, false, false);
        [$sfTk, $pTk] = Filters::build($fp, 'tk', $this->campoVendedor, $this->origen, false, false);
        [$sfTt, $pTt] = Filters::build($fp, 'tt', $this->campoVendedor, $this->origen, false, false);
        [$sfP,  $pP]  = Filters::build($fp, 'p',  $this->campoVendedor, $this->origen, false, false);

        // 1. Facturación + tickets + ticket_promedio (actual)
        $kpiRows = $this->query("
            SELECT
                t.NRO_SUCURS,
                COUNT(DISTINCT t.N_COMP)              AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0)   AS facturacion
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT}
            GROUP BY t.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pT));

        // 2. Unidades + cambios por sucursal (para porc_cambios)
        $unidRows = $this->query("
            SELECT
                s.NRO_SUCURS,
                ISNULL(SUM(CASE WHEN s.CANTIDAD > 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(CASE WHEN s.CANTIDAD > 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                THEN s.CANTIDAD ELSE 0 END), 0) AS unidades_positivas,
                ISNULL(SUM(CASE WHEN s.CANTIDAD < 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                THEN s.CANTIDAD ELSE 0 END) * -1, 0) AS cambios
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfS}
            GROUP BY s.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pS));

        // 3. % 2do y 3er producto por sucursal
        $tickRows = $this->query("
            SELECT
                tk.NRO_SUCURS,
                COUNT(DISTINCT tk.N_COMP)                                   AS total_tickets,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 1 THEN tk.N_COMP END) AS tickets_2do,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 2 THEN tk.N_COMP END) AS tickets_3ro
            FROM BI_SALES_TICKETS tk
            WHERE tk.FECHA >= ? AND tk.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfTk}
            GROUP BY tk.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pTk));

        // 4. Ticket promedio 2do producto por sucursal
        $tp2Rows = $this->query("
            SELECT
                tt.NRO_SUCURS,
                COUNT(DISTINCT tt.N_COMP)               AS tickets_con_2do,
                ISNULL(SUM(tt.IMP_TOTAL_TICKET), 0)     AS facturacion_con_2do
            FROM BI_SALES_TOTAL_TICKETS tt
            INNER JOIN (
                SELECT DISTINCT tk.N_COMP
                FROM BI_SALES_TICKETS tk
                WHERE tk.FECHA >= ? AND tk.FECHA < DATEADD(day,1,CAST(? AS DATE))
                  {$sfTk}
                  AND tk.CANTIDAD > 1
            ) t2 ON t2.N_COMP = tt.N_COMP
            WHERE tt.FECHA >= ? AND tt.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND tt.T_COMP = 'FAC' {$sfTt}
            GROUP BY tt.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pTk, [$desde_act, $hasta_act], $pTt));

        // 5. % Incremental por sucursal
        $incrRows = $this->query("
            SELECT
                p.NRO_SUCURS,
                ISNULL(SUM(p.CAMBIO), 0)        AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0)  AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day,1,CAST(? AS DATE)) {$sfP}
            GROUP BY p.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pP));

        // 6. Facturación período previo (para var_fact)
        $prevRows = $this->query("
            SELECT
                t.NRO_SUCURS,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS facturacion_prev
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT}
            GROUP BY t.NRO_SUCURS
        ", array_merge([$desde_prev, $hasta_prev], $pT));

        // 7. Objetivos pro-rated
        $objPorSuc = $this->getObjetivosPorSucursal(
            $desde_act, $hasta_act, $primerDiaMes, $ultimoDiaMes, $grupo, $tipoTienda
        );

        // ── Combinar en array indexado por NRO_SUCURS ──────────────────────
        $byNro = [];
        foreach ($kpiRows as $r) {
            $nro = (int)$r['NRO_SUCURS'];
            $byNro[$nro] = [
                'nro_sucurs'          => $nro,
                'facturacion'         => (float)($r['facturacion'] ?? 0),
                'tickets'             => (int)($r['tickets']       ?? 0),
                'ticket_promedio'     => 0.0,
                'unidades'            => 0.0,
                'porc_2do'            => 0.0,
                'porc_3ro'            => 0.0,
                'porc_cambios'        => 0.0,
                'porc_incremental'    => 0.0,
                'ticket_promedio_2do' => 0.0,
                'var_fact'            => 0.0,
                'cumplimiento'        => 1.0,
                'objetivo_fecha'      => 0.0,
            ];
        }

        foreach ($unidRows as $r) {
            $nro = (int)$r['NRO_SUCURS'];
            if (!isset($byNro[$nro])) continue;
            $unidPos = (float)($r['unidades_positivas'] ?? 0);
            $cambios = (float)($r['cambios']            ?? 0);
            $byNro[$nro]['unidades']     = (float)($r['unidades'] ?? 0);
            $byNro[$nro]['porc_cambios'] = $unidPos > 0 ? $cambios / $unidPos : 0.0;
        }

        foreach ($tickRows as $r) {
            $nro = (int)$r['NRO_SUCURS'];
            if (!isset($byNro[$nro])) continue;
            $tot = (int)($r['total_tickets'] ?? 0);
            $byNro[$nro]['porc_2do'] = $tot > 0 ? (int)$r['tickets_2do'] / $tot : 0.0;
            $byNro[$nro]['porc_3ro'] = $tot > 0 ? (int)$r['tickets_3ro'] / $tot : 0.0;
        }

        foreach ($tp2Rows as $r) {
            $nro  = (int)$r['NRO_SUCURS'];
            if (!isset($byNro[$nro])) continue;
            $cant = (int)($r['tickets_con_2do']     ?? 0);
            $fact = (float)($r['facturacion_con_2do'] ?? 0);
            $byNro[$nro]['ticket_promedio_2do'] = $cant > 0 ? $fact / $cant : 0.0;
        }

        foreach ($incrRows as $r) {
            $nro  = (int)$r['NRO_SUCURS'];
            if (!isset($byNro[$nro])) continue;
            $camb = (float)($r['cambios_incr']  ?? 0);
            $devol = (float)($r['devoluciones'] ?? 0);
            $byNro[$nro]['porc_incremental'] = $devol != 0 ? ($camb - $devol) / $devol : 0.0;
        }

        foreach ($prevRows as $r) {
            $nro  = (int)$r['NRO_SUCURS'];
            if (!isset($byNro[$nro])) continue;
            $prev = (float)$r['facturacion_prev'];
            $act  = $byNro[$nro]['facturacion'];
            $byNro[$nro]['var_fact'] = $prev != 0 ? ($act - $prev) / $prev : ($act > 0 ? 1.0 : 0.0);
        }

        foreach ($objPorSuc as $nro => $o) {
            if (!isset($byNro[$nro])) continue;
            $obj = (float)$o['objetivo_fecha'];
            $act = $byNro[$nro]['facturacion'];
            $byNro[$nro]['objetivo_fecha'] = $obj;
            $byNro[$nro]['cumplimiento']   = $obj > 0 ? $act / $obj : ($act > 0 ? 1.0 : 0.0);
        }

        // ticket_promedio
        foreach ($byNro as $nro => &$s) {
            $s['ticket_promedio'] = $s['tickets'] > 0 ? $s['facturacion'] / $s['tickets'] : 0.0;
        }
        unset($s);

        // ── Scoring ────────────────────────────────────────────────────────
        $weights = [
            'cumplimiento'        => 0.30,
            'var_fact'            => 0.15,
            'ticket_promedio'     => 0.10,
            'unidades'            => 0.10,
            'porc_2do'            => 0.10,
            'tickets'             => 0.05,
            'ticket_promedio_2do' => 0.05,
            'porc_3ro'            => 0.05,
            'porc_cambios'        => 0.05,
            'porc_incremental'    => 0.05,
        ];
        $types = [
            'cumplimiento'        => 'normal',
            'var_fact'            => 'index',
            'ticket_promedio'     => 'normal',
            'unidades'            => 'normal',
            'porc_2do'            => 'normal',
            'tickets'             => 'normal',
            'ticket_promedio_2do' => 'normal',
            'porc_3ro'            => 'normal',
            'porc_cambios'        => 'inverse',
            'porc_incremental'    => 'index',
        ];

        $sucList = array_values($byNro);
        $cnt = count($sucList);
        if ($cnt === 0) return [];

        // Averages across all sucursales
        $avgs = [];
        foreach (array_keys($weights) as $kpi) {
            $sum = 0.0;
            foreach ($sucList as $s) { $sum += (float)($s[$kpi] ?? 0); }
            $avgs[$kpi] = $cnt > 0 ? $sum / $cnt : 0.0;
        }

        $normalize = function(string $type, float $val, float $avg): float {
            if ($type === 'inverse') {
                return max(0.1, min(3.0, $val != 0 ? $avg / $val : ($avg >= 0 ? 3.0 : 0.1)));
            } elseif ($type === 'index') {
                $denom = 1.0 + $avg;
                return max(0.1, min(3.0, $denom != 0 ? (1.0 + $val) / $denom : 1.0));
            } else { // normal
                return max(0.1, min(3.0, $avg != 0 ? $val / $avg : ($val > 0 ? 3.0 : 0.1)));
            }
        };

        $result = [];
        foreach ($sucList as $suc) {
            $score   = 0.0;
            $detalle = [];
            foreach ($weights as $kpi => $w) {
                $nv    = $normalize($types[$kpi], (float)$suc[$kpi], $avgs[$kpi]);
                $contrib = $nv * $w;
                $score  += $contrib;
                $detalle[$kpi] = [
                    'valor'    => round((float)$suc[$kpi], 6),
                    'promedio' => round($avgs[$kpi],        6),
                    'norm'     => round($nv,                4),
                    'peso'     => $w,
                    'contrib'  => round($contrib * 100, 2),
                ];
            }
            $suc['score']   = round($score * 100, 2);
            $suc['detalle'] = $detalle;
            $result[]       = $suc;
        }

        usort($result, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach ($result as $i => &$s) { $s['rank'] = $i + 1; }
        unset($s);

        return $result;
    }

    /* ──────────────────────────────────────────────
     *  MAILS (tickets con email válido)
     * ────────────────────────────────────────────── */

    public function getMails(
        string $desde, string $hasta,
        ?int $sucursal = null, ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp($sucursal, '%', '%', $grupo, $tipoTienda);
        [$sfT, $pT] = Filters::build($fp, 't', $this->campoVendedor, $this->origen, false, false);

        $noMails = ['mails' => 0];
        try {
            $row = $this->queryOne("
                SELECT COUNT(DISTINCT t.N_COMP) AS mails
                FROM BI_SALES_TOTAL_TICKETS t
                WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
                  AND t.T_COMP = 'FAC'
                  AND ISNULL(t.EMAIL, '') <> ''
                  AND ISNULL(t.EMAIL, '') <> 'invalido' {$sfT}
            ", array_merge([$desde, $hasta], $pT));
            return ['mails' => (int)($row['mails'] ?? 0)];
        } catch (Throwable $_) {
            return $noMails;
        }
    }
}
