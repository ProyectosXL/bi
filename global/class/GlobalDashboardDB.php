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
        ", array_merge([$desde, $hasta], $pT));

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
        [$sfS, $pS] = Filters::build($fp, 's', $this->campoVendedor, $this->origen, true, true);
        [$sfT, $pT] = Filters::build($fp, 't', $this->campoVendedor, $this->origen, true, false);

        $rows = $this->query("
            SELECT
                CAST(s.FECHA AS DATE) AS fecha,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion,
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) AS unidades
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfS}
            GROUP BY CAST(s.FECHA AS DATE)
            ORDER BY 1 ASC
        ", array_merge([$desde, $hasta], $pS));

        $tickRows = $this->query("
            SELECT
                CAST(t.FECHA AS DATE) AS fecha,
                COUNT(DISTINCT t.N_COMP) AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_tickets
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT}
            GROUP BY CAST(t.FECHA AS DATE)
        ", array_merge([$desde, $hasta], $pT));

        $tickMap = [];
        foreach ($tickRows as $tr) {
            $tickMap[$tr['fecha']->format('Y-m-d')] = $tr;
        }

        $result = [];
        foreach ($rows as $row) {
            $fecha    = $row['fecha']->format('Y-m-d');
            $tickInfo = $tickMap[$fecha] ?? null;
            $tickets  = (int)($tickInfo['tickets']      ?? 0);
            $sumaT    = (float)($tickInfo['suma_tickets'] ?? 0);
            $result[] = [
                'fecha'           => $fecha,
                'facturacion'     => (float)$row['facturacion'],
                'unidades'        => (float)$row['unidades'],
                'tickets'         => $tickets,
                'ticket_promedio' => $tickets > 0 ? $sumaT / $tickets : 0,
            ];
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
