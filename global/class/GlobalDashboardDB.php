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
    private string  $origen;
    private bool    $soloActivas        = false;
    private bool    $aplicarFiltroGrupo = true;
    private ?array  $grupoSucursales    = null; // override explícito para casos que alteran $_SESSION['tipo']

    public function setSoloActivas(bool $v): void      { $this->soloActivas = $v; }

    /**
     * Cuando se llama con true, las queries de esta instancia NO aplicarán
     * el filtro de sucursalesGrupo aunque el usuario sea GRUPO.
     * Usar para instancias de benchmark.
     */
    public function setIgnorarFiltroGrupo(bool $ignorar): void
    {
        $this->aplicarFiltroGrupo = !$ignorar;
    }

    /**
     * Fija explícitamente las sucursales del grupo para esta instancia.
     * Útil cuando $_SESSION['tipo'] fue temporalmente sobreescrito (ej: analisis.php).
     */
    public function setGrupoSucursales(array $sucursales): void
    {
        $this->grupoSucursales = array_values($sucursales);
    }

    /**
     * Retorna el fragmento WHERE + params para restringir por las sucursales
     * del perfil GRUPO. Retorna ['', []] cuando no aplica.
     */
    private function grupoFiltro(string $alias, string $col = 'NRO_SUCURS'): array
    {
        if (!$this->aplicarFiltroGrupo) return ['', []];
        // Prioridad: sucursales fijadas explícitamente (evita dependencia de $_SESSION['tipo'])
        if ($this->grupoSucursales !== null) {
            return Filters::sucursalesGrupo($this->grupoSucursales, $alias, $col);
        }
        if (($_SESSION['tipo'] ?? '') !== 'GRUPO') return ['', []];
        $suc = $_SESSION['sucursalesGrupo'] ?? [];
        return Filters::sucursalesGrupo($suc, $alias, $col);
    }

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
        // Lectura sin bloqueo de escrituras (equivalente a WITH NOLOCK global)
        sqlsrv_query($this->conn, 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
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
            'solo_activas' => $this->soloActivas,
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

        // Filtro GRUPO (sucursales permitidas)
        [$sfGS, $pGS] = $this->grupoFiltro('s');
        [$sfGT, $pGT] = $this->grupoFiltro('t');
        [$sfGO, $pGO] = $this->grupoFiltro('o', 'NRO_SUCURSAL');

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
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfS} {$sfGS}
        ", array_merge([$desde, $hasta], $pS, $pGS));

        $rowT = $this->queryOne("
            SELECT
                COUNT(DISTINCT t.N_COMP) AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_tickets
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT} {$sfGT}
        ", array_merge([$desde, $hasta], $pT, $pGT));

        $rowObj = $this->queryOne("
            SELECT ISNULL(SUM(o.IMPORTE_OBJ), 0) AS objetivo
            FROM {$this->tablaObjetivos} o
            WHERE o.FECHA >= ? AND o.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfO} {$sfGO}
        ", array_merge([$desde, $hasta], $pO, $pGO));

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
        [$sfT, $pT]   = Filters::build($fp, 't', $this->campoVendedor, $this->origen, true, false);
        [$sfGT, $pGT] = $this->grupoFiltro('t');

        $row = $this->queryOne("
            SELECT
                COUNT(DISTINCT t.N_COMP) AS total,
                COUNT(DISTINCT CASE WHEN t.CANTIDAD > 1 THEN t.N_COMP END) AS seg,
                COUNT(DISTINCT CASE WHEN t.CANTIDAD > 2 THEN t.N_COMP END) AS ter
            FROM BI_SALES_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfT} {$sfGT}
        ", array_merge([$desde, $hasta], $pT, $pGT));

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
        [$sfTk, $pTk]   = Filters::build($fp, 'tk', $this->campoVendedor, $this->origen, true, false);
        [$sfTt, $pTt]   = Filters::build($fp, 'tt', $this->campoVendedor, $this->origen, true, false);
        [$sfGTk, $pGTk] = $this->grupoFiltro('tk');
        [$sfGTt, $pGTt] = $this->grupoFiltro('tt');

        $row = $this->queryOne("
            SELECT
                COUNT(DISTINCT tt.N_COMP) AS tickets_con_2do,
                ISNULL(SUM(tt.IMP_TOTAL_TICKET), 0) AS facturacion_con_2do
            FROM BI_SALES_TOTAL_TICKETS tt
            INNER JOIN (
                SELECT DISTINCT N_COMP
                FROM BI_SALES_TICKETS tk
                WHERE tk.FECHA >= ? AND tk.FECHA < DATEADD(day,1,CAST(? AS DATE))
                  {$sfTk} {$sfGTk}
                  AND tk.CANTIDAD > 1
            ) t2 ON t2.N_COMP = tt.N_COMP
            WHERE tt.FECHA >= ? AND tt.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND tt.T_COMP = 'FAC' {$sfTt} {$sfGTt}
        ", array_merge([$desde, $hasta], $pTk, $pGTk, [$desde, $hasta], $pTt, $pGTt));

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
        [$sfP, $pP]   = Filters::build($fp, 'p', $this->campoVendedor, $this->origen, true, false);
        [$sfGP, $pGP] = $this->grupoFiltro('p');

        $row = $this->queryOne("
            SELECT
                ISNULL(SUM(p.CAMBIO), 0)       AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0) AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day,1,CAST(? AS DATE)) {$sfP} {$sfGP}
        ", array_merge([$desde, $hasta], $pP, $pGP));

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
        [$sfI, $pI]   = Filters::build($fp, 'i', $this->campoVendedor, $this->origen, false, false);
        [$sfT, $pT]   = Filters::build($fp, 't', $this->campoVendedor, $this->origen, false, false);
        [$sfGI, $pGI] = $this->grupoFiltro('i');
        [$sfGT, $pGT] = $this->grupoFiltro('t');

        $rowI = $this->queryOne("
            SELECT ISNULL(SUM(i.INGRESOS), 0) AS total_ingresos
            FROM BI_T_INGRESOS_SUCURSALES i
            WHERE i.FECHA >= ? AND i.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND i.INGRESOS > 0 {$sfI} {$sfGI}
        ", array_merge([$desde, $hasta], $pI, $pGI));

        $rowT = $this->queryOne("
            SELECT COUNT(DISTINCT t.N_COMP) AS total_tickets
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT} {$sfGT}
              AND EXISTS (
                  SELECT 1
                  FROM BI_T_INGRESOS_SUCURSALES i2
                  WHERE i2.NRO_SUCURS = t.NRO_SUCURS
                    AND CAST(i2.FECHA AS DATE) = CAST(t.FECHA AS DATE)
                    AND i2.FECHA >= ? AND i2.FECHA < DATEADD(day,1,CAST(? AS DATE))
                    AND i2.INGRESOS > 0
              )
        ", array_merge([$desde, $hasta], $pT, $pGT, [$desde, $hasta]));

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

        // Filtros GRUPO
        [$sfGS,  $pGS]  = $this->grupoFiltro('s');
        [$sfGT,  $pGT]  = $this->grupoFiltro('t');
        [$sfGTk, $pGTk] = $this->grupoFiltro('tk');
        [$sfGP,  $pGP]  = $this->grupoFiltro('p');
        [$sfGI,  $pGI]  = $this->grupoFiltro('ig');

        // Ventas: facturación, unidades, unidades positivas y cambios por día
        $rows = $this->query("
            SELECT
                CAST(s.FECHA AS DATE) AS fecha,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion,
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(CASE WHEN s.CANTIDAD > 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) AS unidades_positivas,
                ISNULL(SUM(CASE WHEN s.CANTIDAD < 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END) * -1, 0) AS cambios
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfS} {$sfGS}
            GROUP BY CAST(s.FECHA AS DATE)
            ORDER BY 1 ASC
        ", array_merge([$desde, $hasta], $pS, $pGS));

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
               {$sfTk} {$sfGTk}
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT} {$sfGT}
            GROUP BY CAST(t.FECHA AS DATE)
        ", array_merge([$desde, $hasta], $pTk, $pGTk, [$desde, $hasta], $pT, $pGT));

        // Tickets filtrados por sucursales con ingresos ese día (para conversión correcta)
        $tickConvRows = $this->query("
            SELECT
                CAST(t.FECHA AS DATE) AS fecha,
                COUNT(DISTINCT t.N_COMP) AS tickets_conv
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT} {$sfGT}
              AND EXISTS (
                  SELECT 1 FROM BI_T_INGRESOS_SUCURSALES i2
                  WHERE i2.NRO_SUCURS = t.NRO_SUCURS
                    AND CAST(i2.FECHA AS DATE) = CAST(t.FECHA AS DATE)
                    AND i2.INGRESOS > 0
              )
            GROUP BY CAST(t.FECHA AS DATE)
        ", array_merge([$desde, $hasta], $pT, $pGT));

        $tickConvMap = [];
        foreach ($tickConvRows as $tr) {
            $tickConvMap[$tr['fecha']->format('Y-m-d')] = (int)$tr['tickets_conv'];
        }

        $tickMap = [];
        foreach ($tickRows as $tr) {
            $tickMap[$tr['fecha']->format('Y-m-d')] = $tr;
        }

        // T. Prom. 2do Producto por día
        [$sfTk2,  $pTk2]  = Filters::build($fp, 'tk2', $this->campoVendedor, $this->origen, true, false);
        [$sfTt2,  $pTt2]  = Filters::build($fp, 'tt2', $this->campoVendedor, $this->origen, true, false);
        [$sfGTk2, $pGTk2] = $this->grupoFiltro('tk2');
        [$sfGTt2, $pGTt2] = $this->grupoFiltro('tt2');

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
                  {$sfTk2} {$sfGTk2}
                  AND tk2.CANTIDAD > 1
            ) t2 ON t2.N_COMP = tt2.N_COMP
            WHERE tt2.FECHA >= ? AND tt2.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND tt2.T_COMP = 'FAC' {$sfTt2} {$sfGTt2}
            GROUP BY CAST(tt2.FECHA AS DATE)
        ", array_merge([$desde, $hasta], $pTk2, $pGTk2, [$desde, $hasta], $pTt2, $pGTt2));

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
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day,1,CAST(? AS DATE)) {$sfP} {$sfGP}
            GROUP BY CAST(p.FECHA_MOV AS DATE)
        ", array_merge([$desde, $hasta], $pP, $pGP));

        $incrMap = [];
        foreach ($incrRows as $ir) {
            $incrMap[$ir['fecha']->format('Y-m-d')] = $ir;
        }

        // Ingresos por día (solo registros con INGRESOS > 0)
        $ingRows = $this->query("
            SELECT
                CAST(ig.FECHA AS DATE) AS fecha,
                ISNULL(SUM(ig.INGRESOS), 0) AS ingresos
            FROM BI_T_INGRESOS_SUCURSALES ig
            WHERE ig.FECHA >= ? AND ig.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND ig.INGRESOS > 0 {$sfI} {$sfGI}
            GROUP BY CAST(ig.FECHA AS DATE)
        ", array_merge([$desde, $hasta], $pI, $pGI));

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
            $ingresos     = $ingMap[$fecha] ?? 0;
            $ticketsConv  = $tickConvMap[$fecha] ?? 0;

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
                'conversion'          => $ingresos > 0 ? $ticketsConv / $ingresos : 0,
            ];
        }
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  SERIE TEMPORAL SIMPLE (solo facturación — sparkline previo)
     * ────────────────────────────────────────────── */

    public function getSerieFacturacionSimple(
        string $desde, string $hasta,
        ?int $sucursal = null, string $vendedor = '%', string $rubro = '%',
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp($sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
        [$sfS,  $pS]  = Filters::build($fp, 's', $this->campoVendedor, $this->origen, true, true);
        [$sfGS, $pGS] = $this->grupoFiltro('s');

        $rows = $this->query("
            SELECT
                CAST(s.FECHA AS DATE) AS fecha,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfS} {$sfGS}
            GROUP BY CAST(s.FECHA AS DATE)
            ORDER BY 1 ASC
        ", array_merge([$desde, $hasta], $pS, $pGS));

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'fecha'       => $row['fecha']->format('Y-m-d'),
                'facturacion' => (float)$row['facturacion'],
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
        [$sfO, $pO]   = Filters::build($fp, 'o', $this->campoVendedor, $this->origen, false, false, 'NRO_SUCURSAL');
        [$sfGO, $pGO] = $this->grupoFiltro('o', 'NRO_SUCURSAL');
        $rows = $this->query("
            SELECT CAST(o.FECHA AS DATE) AS fecha, ISNULL(SUM(o.IMPORTE_OBJ), 0) AS objetivo
            FROM {$this->tablaObjetivos} o
            WHERE o.FECHA >= ? AND o.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfO} {$sfGO}
            GROUP BY CAST(o.FECHA AS DATE)
            ORDER BY 1 ASC
        ", array_merge([$desde, $hasta], $pO, $pGO));
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
        ?string $grupo = null, ?string $tipoTienda = null,
        ?int $sucursal = null
    ): array {
        $fp = $this->fp($sucursal, '%', '%', $grupo, $tipoTienda);
        [$sfO, $pO]   = Filters::build($fp, 'o', $this->campoVendedor, $this->origen, true, false, 'NRO_SUCURSAL');
        [$sfGO, $pGO] = $this->grupoFiltro('o', 'NRO_SUCURSAL');

        // Un solo scan con CASE WHEN en lugar de dos queries al servidor vinculado
        $haX  = (new DateTime($hasta_act))->modify('+1 day')->format('Y-m-d');
        $htX  = (new DateTime($hasta_total))->modify('+1 day')->format('Y-m-d');
        $rows = $this->query("
            SELECT o.NRO_SUCURSAL,
                ISNULL(SUM(CASE WHEN o.FECHA >= ? AND o.FECHA < ? THEN o.IMPORTE_OBJ ELSE 0 END), 0) AS objetivo_fecha,
                ISNULL(SUM(CASE WHEN o.FECHA >= ? AND o.FECHA < ? THEN o.IMPORTE_OBJ ELSE 0 END), 0) AS objetivo_total
            FROM {$this->tablaObjetivos} o WITH (NOLOCK)
            WHERE ((o.FECHA >= ? AND o.FECHA < ?) OR (o.FECHA >= ? AND o.FECHA < ?))
              {$sfO} {$sfGO}
            GROUP BY o.NRO_SUCURSAL
        ", array_merge([$desde_act, $haX, $desde_total, $htX, $desde_act, $haX, $desde_total, $htX], $pO, $pGO));

        $result = [];
        foreach ($rows as $r) {
            $nro = (int)$r['NRO_SUCURSAL'];
            $result[$nro] = [
                'nro_sucursal'   => $nro,
                'objetivo_fecha' => (float)$r['objetivo_fecha'],
                'objetivo_total' => (float)$r['objetivo_total'],
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
        ?string $grupo = null, ?string $tipoTienda = null,
        ?int $sucursal = null
    ): array {
        $fp = $this->fp($sucursal, '%', '%', $grupo, $tipoTienda);
        [$sfS, $pS]   = Filters::build($fp, 's', $this->campoVendedor, $this->origen, true, false);
        [$sfGS, $pGS] = $this->grupoFiltro('s');

        // Un solo scan con CASE WHEN en lugar de dos queries separadas
        $haX = (new DateTime($hasta_act))->modify('+1 day')->format('Y-m-d');
        $hpX = (new DateTime($hasta_prev))->modify('+1 day')->format('Y-m-d');
        $rows = $this->query("
            SELECT s.NRO_SUCURS,
                ISNULL(SUM(CASE WHEN s.FECHA >= ? AND s.FECHA < ? THEN s.IMPORTE ELSE 0 END), 0) AS fact_act,
                ISNULL(SUM(CASE WHEN s.FECHA >= ? AND s.FECHA < ? THEN s.IMPORTE ELSE 0 END), 0) AS fact_prev
            FROM BI_SALES_SUCURSALES s WITH (NOLOCK)
            WHERE ((s.FECHA >= ? AND s.FECHA < ?) OR (s.FECHA >= ? AND s.FECHA < ?))
              {$sfS} {$sfGS}
            GROUP BY s.NRO_SUCURS
        ", array_merge([$desde_act, $haX, $desde_prev, $hpX, $desde_act, $haX, $desde_prev, $hpX], $pS, $pGS));

        $result = [];
        foreach ($rows as $r) {
            $nro  = (int)$r['NRO_SUCURS'];
            $fact = (float)$r['fact_act'];
            $prev = (float)$r['fact_prev'];
            $result[$nro] = [
                'nro_sucurs'       => $nro,
                'facturacion'      => $fact,
                'facturacion_prev' => $prev,
                'var_fact'         => $prev > 0 ? ($fact - $prev) / $prev : ($fact > 0 ? 1 : 0),
            ];
        }
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  LISTAS PARA FILTROS
     * ────────────────────────────────────────────── */

    public function getSucursalesLista(bool $soloActivas = false): array
    {
        if ($soloActivas) {
            return $this->query("
                SELECT DISTINCT s.NRO_SUCURS, sl.DESC_SUCURSAL
                FROM BI_SALES_SUCURSALES s
                INNER JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
                  ON sl.NRO_SUCURSAL = s.NRO_SUCURS
                WHERE sl.HABILITADO = 1
                ORDER BY sl.DESC_SUCURSAL
            ");
        }
        return $this->query("
            SELECT DISTINCT s.NRO_SUCURS, sl.DESC_SUCURSAL
            FROM BI_SALES_SUCURSALES s
            LEFT JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
              ON sl.NRO_SUCURSAL = s.NRO_SUCURS
            WHERE sl.HABILITADO = 1 OR sl.HABILITADO IS NULL
            ORDER BY sl.DESC_SUCURSAL
        ");
    }

    public function getSucursalesActivasIds(): array
    {
        $rows = $this->query("
            SELECT NRO_SUCURSAL
            FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS
            WHERE HABILITADO = 1
        ");
        return array_values(array_map(fn($r) => (int)$r['NRO_SUCURSAL'], $rows));
    }

    /* ──────────────────────────────────────────────
     *  EVOLUCIÓN MENSUAL (unidades o tickets)
     *  Usa UNION ALL con tabla BK si existe.
     * ────────────────────────────────────────────── */

    public function getEvolucionMensual(
        string $tipo,
        ?int   $sucursal    = null,
        string $vendedor    = '%',
        string $rubro       = '%',
        ?string $grupo      = null,
        ?string $tipoTienda = null
    ): array {
        $fp = $this->fp($sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
        $cv = $this->campoVendedor;

        if ($tipo === 'unidades') {
            $mainTable    = 'BI_SALES_SUCURSALES';
            $bkTable      = 'BI_SALES_SUCURSALES_BK';
            [$sfS, $pS]   = Filters::build($fp, 's', $cv, $this->origen, true, true);
            [$sfGS, $pGS] = $this->grupoFiltro('s');
            $sfSAll = $sfS . ' ' . $sfGS;

            $hasBK = false;
            try {
                $ck = $this->queryOne(
                    "SELECT CASE WHEN OBJECT_ID('{$bkTable}') IS NOT NULL THEN 1 ELSE 0 END AS v"
                );
                $hasBK = ((int)($ck['v'] ?? 0)) === 1;
            } catch (\Throwable $e) { /* tabla BK inexistente */ }

            $pSAll    = array_merge($pS, $pGS);
            $valExpr  = "SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END)";
            $mainSql  = "
                SELECT YEAR(s.FECHA) AS anio, MONTH(s.FECHA) AS mes, {$valExpr} AS valor
                FROM {$mainTable} s WITH (NOLOCK)
                WHERE s.FECHA IS NOT NULL {$sfSAll}
                GROUP BY YEAR(s.FECHA), MONTH(s.FECHA)";
            $unionSql = $hasBK ? "
                UNION ALL
                SELECT YEAR(s.FECHA) AS anio, MONTH(s.FECHA) AS mes, {$valExpr} AS valor
                FROM {$bkTable} s WITH (NOLOCK)
                WHERE s.FECHA IS NOT NULL {$sfSAll}
                GROUP BY YEAR(s.FECHA), MONTH(s.FECHA)" : '';
            $params = $hasBK ? array_merge($pSAll, $pSAll) : $pSAll;

        } else {
            // tickets
            $mainTable    = 'BI_SALES_TOTAL_TICKETS';
            $bkTable      = 'BI_SALES_TOTAL_TICKETS_BK';
            [$sfT, $pT]   = Filters::build($fp, 't', $cv, $this->origen, true, false);
            [$sfGT, $pGT] = $this->grupoFiltro('t');
            $sfTAll = $sfT . ' ' . $sfGT;

            $hasBK = false;
            try {
                $ck = $this->queryOne(
                    "SELECT CASE WHEN OBJECT_ID('{$bkTable}') IS NOT NULL THEN 1 ELSE 0 END AS v"
                );
                $hasBK = ((int)($ck['v'] ?? 0)) === 1;
            } catch (\Throwable $e) { /* tabla BK inexistente */ }

            $pTAll    = array_merge($pT, $pGT);
            $valExpr  = "COUNT(DISTINCT t.N_COMP)";
            $mainSql  = "
                SELECT YEAR(t.FECHA) AS anio, MONTH(t.FECHA) AS mes, {$valExpr} AS valor
                FROM {$mainTable} t WITH (NOLOCK)
                WHERE t.T_COMP = 'FAC' AND t.FECHA IS NOT NULL {$sfTAll}
                GROUP BY YEAR(t.FECHA), MONTH(t.FECHA)";
            $unionSql = $hasBK ? "
                UNION ALL
                SELECT YEAR(t.FECHA) AS anio, MONTH(t.FECHA) AS mes, {$valExpr} AS valor
                FROM {$bkTable} t WITH (NOLOCK)
                WHERE t.T_COMP = 'FAC' AND t.FECHA IS NOT NULL {$sfTAll}
                GROUP BY YEAR(t.FECHA), MONTH(t.FECHA)" : '';
            $params = $hasBK ? array_merge($pTAll, $pTAll) : $pTAll;
        }

        $rows = $this->query("
            SELECT anio, mes, SUM(valor) AS valor
            FROM ({$mainSql}{$unionSql}) AS combined
            GROUP BY anio, mes
            ORDER BY anio, mes
        ", $params);

        /* Agrupar por año */
        $byAnioMes = [];
        $aniosSet  = [];
        foreach ($rows as $r) {
            $anio  = (int)$r['anio'];
            $mes   = (int)$r['mes'];
            $valor = (float)$r['valor'];
            $byAnioMes[$anio][$mes] = $valor;
            $aniosSet[$anio]        = true;
        }

        $anios = array_keys($aniosSet);
        rsort($anios);

        $meses  = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        $series = [];
        foreach ($anios as $anio) {
            $valores = [];
            for ($m = 1; $m <= 12; $m++) {
                $valores[] = isset($byAnioMes[$anio][$m]) ? $byAnioMes[$anio][$m] : null;
            }
            $series[] = ['anio' => $anio, 'valores' => $valores];
        }

        return ['anios' => $anios, 'meses' => $meses, 'series' => $series];
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
        $extras = '';
        if ($desde !== null && $hasta !== null) {
            $extras  .= " AND s.FECHA BETWEEN ? AND ?";
            $params[] = $desde;
            $params[] = $hasta;
        }
        if ($sucursal !== null) {
            $extras  .= " AND s.NRO_SUCURS = ?";
            $params[] = $sucursal;
        }
        [$sfGrupo, $pGrupo] = $this->grupoFiltro('s');
        $params = array_merge($params, $pGrupo);
        return $this->query("
            SELECT DISTINCT s.{$cv}
            FROM BI_SALES_SUCURSALES s
            WHERE 1=1 {$extras} {$sfGrupo}
            ORDER BY s.{$cv}
        ", $params);
    }

    public function getRubrosFiltro(string $desde, string $hasta, ?int $sucursal = null): array
    {
        $sfS = $sucursal !== null ? "AND NRO_SUCURS = ?" : "";
        $suc = $sucursal !== null ? [$sucursal] : [];
        [$sfGS, $pGS] = $this->grupoFiltro('s');
        return $this->query("
            SELECT DISTINCT RUBRO FROM BI_SALES_SUCURSALES s
            WHERE FECHA BETWEEN ? AND ?
              AND RUBRO NOT IN ('CONCEPTO','PACKAGING') {$sfS} {$sfGS}
            ORDER BY RUBRO
        ", array_merge([$desde, $hasta], $suc, $pGS));
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

        // Filtros GRUPO para scoring
        [$sfGS,  $pGS]  = $this->grupoFiltro('s');
        [$sfGT,  $pGT]  = $this->grupoFiltro('t');
        [$sfGTk, $pGTk] = $this->grupoFiltro('tk');
        [$sfGTt, $pGTt] = $this->grupoFiltro('tt');
        [$sfGP,  $pGP]  = $this->grupoFiltro('p');

        // 1. Facturación + tickets + ticket_promedio (actual)
        $kpiRows = $this->query("
            SELECT
                t.NRO_SUCURS,
                COUNT(DISTINCT t.N_COMP)              AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0)   AS facturacion
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT} {$sfGT}
            GROUP BY t.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pT, $pGT));

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
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfS} {$sfGS}
            GROUP BY s.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pS, $pGS));

        // 3. % 2do y 3er producto por sucursal
        $tickRows = $this->query("
            SELECT
                tk.NRO_SUCURS,
                COUNT(DISTINCT tk.N_COMP)                                   AS total_tickets,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 1 THEN tk.N_COMP END) AS tickets_2do,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 2 THEN tk.N_COMP END) AS tickets_3ro
            FROM BI_SALES_TICKETS tk
            WHERE tk.FECHA >= ? AND tk.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfTk} {$sfGTk}
            GROUP BY tk.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pTk, $pGTk));

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
                  {$sfTk} {$sfGTk}
                  AND tk.CANTIDAD > 1
            ) t2 ON t2.N_COMP = tt.N_COMP
            WHERE tt.FECHA >= ? AND tt.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND tt.T_COMP = 'FAC' {$sfTt} {$sfGTt}
            GROUP BY tt.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pTk, $pGTk, [$desde_act, $hasta_act], $pTt, $pGTt));

        // 5. % Incremental por sucursal
        $incrRows = $this->query("
            SELECT
                p.NRO_SUCURS,
                ISNULL(SUM(p.CAMBIO), 0)        AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0)  AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day,1,CAST(? AS DATE)) {$sfP} {$sfGP}
            GROUP BY p.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pP, $pGP));

        // 6. Facturación período previo (para var_fact)
        $prevRows = $this->query("
            SELECT
                t.NRO_SUCURS,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS facturacion_prev
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT} {$sfGT}
            GROUP BY t.NRO_SUCURS
        ", array_merge([$desde_prev, $hasta_prev], $pT, $pGT));

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
     *  KPIs BULK — actual + previo en 5 queries (un scan por tabla)
     *  Siguiendo el patrón de DashboardDB::getKPIsBulk de sucursales.
     * ────────────────────────────────────────────── */

    public function getKPIsBulk(
        string $da, string $ha,
        string $dp, string $hp,
        ?int $sucursal = null, string $vendedor = '%', string $rubro = '%',
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $cv  = $this->campoVendedor;
        $haX = (new DateTime($ha))->modify('+1 day')->format('Y-m-d');
        $hpX = (new DateTime($hp))->modify('+1 day')->format('Y-m-d');
        // Params base: is_a CASE WHEN + is_p CASE WHEN + WHERE dates (8 posiciones)
        $pBase = [$da, $haX, $dp, $hpX, $da, $haX, $dp, $hpX];

        $fp = $this->fp($sucursal, $vendedor, $rubro, $grupo, $tipoTienda);

        [$sfS,  $pS]  = Filters::build($fp, 's',  $cv, $this->origen, true,  true);
        [$sfT,  $pT]  = Filters::build($fp, 't',  $cv, $this->origen, true,  false);
        [$sfTk, $pTk] = Filters::build($fp, 'tk', $cv, $this->origen, true,  false);
        [$sfP,  $pP]  = Filters::build($fp, 'p',  $cv, $this->origen, false, false);
        [$sfO,  $pO]  = Filters::build($fp, 'o',  $cv, $this->origen, false, false, 'NRO_SUCURSAL');

        [$sfGS,  $pGS]  = $this->grupoFiltro('s');
        [$sfGT,  $pGT]  = $this->grupoFiltro('t');
        [$sfGTk, $pGTk] = $this->grupoFiltro('tk');
        [$sfGP,  $pGP]  = $this->grupoFiltro('p');
        [$sfGO,  $pGO]  = $this->grupoFiltro('o', 'NRO_SUCURSAL');

        // ── Q1: BI_SALES_SUCURSALES — facturación, unidades, cambios ──────────
        $r1 = $this->queryOne("
            SELECT
                ISNULL(SUM(CASE WHEN is_a=1 THEN imp ELSE 0 END),0)               AS fact_act,
                ISNULL(SUM(CASE WHEN is_p=1 THEN imp ELSE 0 END),0)               AS fact_prev,
                ISNULL(SUM(CASE WHEN is_a=1 AND rg=0 THEN qty ELSE 0 END),0)      AS unid_act,
                ISNULL(SUM(CASE WHEN is_p=1 AND rg=0 THEN qty ELSE 0 END),0)      AS unid_prev,
                ISNULL(SUM(CASE WHEN is_a=1 AND rg=0 AND qty>0 THEN  qty ELSE 0 END),0) AS upos_act,
                ISNULL(SUM(CASE WHEN is_p=1 AND rg=0 AND qty>0 THEN  qty ELSE 0 END),0) AS upos_prev,
                ISNULL(SUM(CASE WHEN is_a=1 AND rg=0 AND qty<0 THEN -qty ELSE 0 END),0) AS camb_act,
                ISNULL(SUM(CASE WHEN is_p=1 AND rg=0 AND qty<0 THEN -qty ELSE 0 END),0) AS camb_prev
            FROM (
                SELECT s.IMPORTE AS imp, s.CANTIDAD AS qty,
                    CASE WHEN s.RUBRO IN ('CONCEPTO','PACKAGING') THEN 1 ELSE 0 END AS rg,
                    CASE WHEN s.FECHA >= ? AND s.FECHA < ? THEN 1 ELSE 0 END AS is_a,
                    CASE WHEN s.FECHA >= ? AND s.FECHA < ? THEN 1 ELSE 0 END AS is_p
                FROM BI_SALES_SUCURSALES s WITH (NOLOCK)
                WHERE ((s.FECHA >= ? AND s.FECHA < ?) OR (s.FECHA >= ? AND s.FECHA < ?))
                  {$sfS} {$sfGS}
            ) t
        ", array_merge($pBase, $pS, $pGS)) ?? [];

        // ── Q2: BI_SALES_TOTAL_TICKETS — tickets, ticket_promedio, mails ──────
        // franquicias no tiene columna EMAIL → usar 0 AS hm
        $hmExpr = ($this->origen === 'franquicias')
            ? '0'
            : "CASE WHEN ISNULL(t.EMAIL,'') <> '' AND ISNULL(t.EMAIL,'') <> 'invalido' THEN 1 ELSE 0 END";
        $r2 = $this->queryOne("
            SELECT
                COUNT(DISTINCT CASE WHEN is_a=1          THEN nc ELSE NULL END) AS tick_act,
                ISNULL(SUM(CASE WHEN is_a=1 THEN imp ELSE 0 END),0)            AS suma_act,
                COUNT(DISTINCT CASE WHEN is_p=1          THEN nc ELSE NULL END) AS tick_prev,
                ISNULL(SUM(CASE WHEN is_p=1 THEN imp ELSE 0 END),0)            AS suma_prev,
                COUNT(DISTINCT CASE WHEN is_a=1 AND hm=1 THEN nc ELSE NULL END) AS mails_act,
                COUNT(DISTINCT CASE WHEN is_p=1 AND hm=1 THEN nc ELSE NULL END) AS mails_prev
            FROM (
                SELECT t.N_COMP AS nc, t.IMP_TOTAL_TICKET AS imp,
                    {$hmExpr} AS hm,
                    CASE WHEN t.FECHA >= ? AND t.FECHA < ? THEN 1 ELSE 0 END AS is_a,
                    CASE WHEN t.FECHA >= ? AND t.FECHA < ? THEN 1 ELSE 0 END AS is_p
                FROM BI_SALES_TOTAL_TICKETS t WITH (NOLOCK)
                WHERE t.T_COMP = 'FAC'
                  AND ((t.FECHA >= ? AND t.FECHA < ?) OR (t.FECHA >= ? AND t.FECHA < ?))
                  {$sfT} {$sfGT}
            ) t
        ", array_merge($pBase, $pT, $pGT)) ?? [];

        // ── Q3: BI_SALES_TICKETS — 2do y 3er producto ───────────────────────
        try {
            $r3 = $this->queryOne("
                SELECT
                    COUNT(DISTINCT CASE WHEN is_a=1           THEN nc ELSE NULL END) AS tot_act,
                    COUNT(DISTINCT CASE WHEN is_a=1 AND qty>1 THEN nc ELSE NULL END) AS t2_act,
                    COUNT(DISTINCT CASE WHEN is_a=1 AND qty>2 THEN nc ELSE NULL END) AS t3_act,
                    COUNT(DISTINCT CASE WHEN is_p=1           THEN nc ELSE NULL END) AS tot_prev,
                    COUNT(DISTINCT CASE WHEN is_p=1 AND qty>1 THEN nc ELSE NULL END) AS t2_prev,
                    COUNT(DISTINCT CASE WHEN is_p=1 AND qty>2 THEN nc ELSE NULL END) AS t3_prev
                FROM (
                    SELECT tk.N_COMP AS nc, tk.CANTIDAD AS qty,
                        CASE WHEN tk.FECHA >= ? AND tk.FECHA < ? THEN 1 ELSE 0 END AS is_a,
                        CASE WHEN tk.FECHA >= ? AND tk.FECHA < ? THEN 1 ELSE 0 END AS is_p
                    FROM BI_SALES_TICKETS tk WITH (NOLOCK)
                    WHERE ((tk.FECHA >= ? AND tk.FECHA < ?) OR (tk.FECHA >= ? AND tk.FECHA < ?))
                      {$sfTk} {$sfGTk}
                ) t
            ", array_merge($pBase, $pTk, $pGTk)) ?? [];
        } catch (Throwable $_) {
            $r3 = ['tot_act' => 0, 't2_act' => 0, 't3_act' => 0, 'tot_prev' => 0, 't2_prev' => 0, 't3_prev' => 0];
        }

        // ── Q4: BI_SALES_PORC_INCREMENTAL ───────────────────────────────────
        try {
            $r4 = $this->queryOne("
                SELECT
                    ISNULL(SUM(CASE WHEN is_a=1 THEN ci ELSE 0 END),0) AS ci_act,
                    ISNULL(SUM(CASE WHEN is_a=1 THEN dv ELSE 0 END),0) AS dv_act,
                    ISNULL(SUM(CASE WHEN is_p=1 THEN ci ELSE 0 END),0) AS ci_prev,
                    ISNULL(SUM(CASE WHEN is_p=1 THEN dv ELSE 0 END),0) AS dv_prev
                FROM (
                    SELECT p.CAMBIO AS ci, p.DEVOLUCIONES AS dv,
                        CASE WHEN p.FECHA_MOV >= ? AND p.FECHA_MOV < ? THEN 1 ELSE 0 END AS is_a,
                        CASE WHEN p.FECHA_MOV >= ? AND p.FECHA_MOV < ? THEN 1 ELSE 0 END AS is_p
                    FROM BI_SALES_PORC_INCREMENTAL p WITH (NOLOCK)
                    WHERE ((p.FECHA_MOV >= ? AND p.FECHA_MOV < ?) OR (p.FECHA_MOV >= ? AND p.FECHA_MOV < ?))
                      {$sfP} {$sfGP}
                ) t
            ", array_merge($pBase, $pP, $pGP)) ?? [];
        } catch (Throwable $_) {
            $r4 = ['ci_act' => 0, 'dv_act' => 0, 'ci_prev' => 0, 'dv_prev' => 0];
        }

        // ── Q5: Tabla objetivos ──────────────────────────────────────────────
        try {
            $r5 = $this->queryOne("
                SELECT
                    ISNULL(SUM(CASE WHEN is_a=1 THEN obj ELSE 0 END),0) AS obj_act,
                    ISNULL(SUM(CASE WHEN is_p=1 THEN obj ELSE 0 END),0) AS obj_prev
                FROM (
                    SELECT o.IMPORTE_OBJ AS obj,
                        CASE WHEN o.FECHA >= ? AND o.FECHA < ? THEN 1 ELSE 0 END AS is_a,
                        CASE WHEN o.FECHA >= ? AND o.FECHA < ? THEN 1 ELSE 0 END AS is_p
                    FROM {$this->tablaObjetivos} o WITH (NOLOCK)
                    WHERE ((o.FECHA >= ? AND o.FECHA < ?) OR (o.FECHA >= ? AND o.FECHA < ?))
                      {$sfO} {$sfGO}
                ) t
            ", array_merge($pBase, $pO, $pGO)) ?? [];
        } catch (Throwable $_) {
            $r5 = ['obj_act' => 0, 'obj_prev' => 0];
        }

        // ── Q6+Q7: Ticket promedio 2do producto (INNER JOIN con subquery — separadas) ──
        $noTp2 = ['tickets_con_2do' => 0, 'facturacion_con_2do' => 0, 'ticket_promedio_2do' => 0];
        try { $tp2a = $this->getTicketPromedio2do($da, $ha, $sucursal, $vendedor, $grupo, $tipoTienda); } catch (Throwable $_) { $tp2a = $noTp2; }
        try { $tp2p = $this->getTicketPromedio2do($dp, $hp, $sucursal, $vendedor, $grupo, $tipoTienda); } catch (Throwable $_) { $tp2p = $noTp2; }

        // Derivadas
        $tA  = (int)($r2['tick_act']  ?? 0);
        $tP  = (int)($r2['tick_prev'] ?? 0);
        $sA  = (float)($r2['suma_act']  ?? 0);
        $sP  = (float)($r2['suma_prev'] ?? 0);
        $totA = (int)($r3['tot_act']  ?? 0); $t2A = (int)($r3['t2_act'] ?? 0); $t3A = (int)($r3['t3_act'] ?? 0);
        $totP = (int)($r3['tot_prev'] ?? 0); $t2P = (int)($r3['t2_prev'] ?? 0); $t3P = (int)($r3['t3_prev'] ?? 0);
        $ciA = (float)($r4['ci_act'] ?? 0); $dvA = (float)($r4['dv_act'] ?? 0);
        $ciP = (float)($r4['ci_prev'] ?? 0); $dvP = (float)($r4['dv_prev'] ?? 0);
        $uposA = (float)($r1['upos_act'] ?? 0); $cambA = (float)($r1['camb_act'] ?? 0);
        $uposP = (float)($r1['upos_prev'] ?? 0); $cambP = (float)($r1['camb_prev'] ?? 0);

        return [
            'actual' => [
                'facturacion'         => (float)($r1['fact_act'] ?? 0),
                'unidades'            => (float)($r1['unid_act'] ?? 0),
                'tickets'             => $tA,
                'ticket_promedio'     => $tA > 0 ? $sA / $tA : 0,
                'porc_cambios'        => $uposA > 0 ? $cambA / $uposA : 0,
                'objetivo'            => (float)($r5['obj_act'] ?? 0),
                'porc_2do'            => $totA > 0 ? $t2A / $totA : 0,
                'porc_3ro'            => $totA > 0 ? $t3A / $totA : 0,
                'porc_incremental'    => $dvA != 0 ? ($ciA - $dvA) / $dvA : 0,
                'mails'               => (int)($r2['mails_act'] ?? 0),
                'ticket_promedio_2do' => $tp2a['ticket_promedio_2do'],
                'tickets_con_2do'     => $tp2a['tickets_con_2do'],
                'ingresos'            => 0,
                'conversion'          => 0,
            ],
            'previo' => [
                'facturacion'         => (float)($r1['fact_prev'] ?? 0),
                'unidades'            => (float)($r1['unid_prev'] ?? 0),
                'tickets'             => $tP,
                'ticket_promedio'     => $tP > 0 ? $sP / $tP : 0,
                'porc_cambios'        => $uposP > 0 ? $cambP / $uposP : 0,
                'objetivo'            => (float)($r5['obj_prev'] ?? 0),
                'porc_2do'            => $totP > 0 ? $t2P / $totP : 0,
                'porc_3ro'            => $totP > 0 ? $t3P / $totP : 0,
                'porc_incremental'    => $dvP != 0 ? ($ciP - $dvP) / $dvP : 0,
                'mails'               => (int)($r2['mails_prev'] ?? 0),
                'ticket_promedio_2do' => $tp2p['ticket_promedio_2do'],
                'tickets_con_2do'     => $tp2p['tickets_con_2do'],
                'ingresos'            => 0,
                'conversion'          => 0,
            ],
        ];
    }

    /* ──────────────────────────────────────────────
     *  KPIs COMPLETOS (agrupa las 5 queries de un período)
     * ────────────────────────────────────────────── */

    public function getKPIsCompletos(
        string $desde, string $hasta,
        ?int $sucursal = null, string $vendedor = '%', string $rubro = '%',
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $noTp2  = ['tickets_con_2do' => 0, 'facturacion_con_2do' => 0, 'ticket_promedio_2do' => 0];
        $noIncr = ['cambios_incr' => 0, 'devoluciones' => 0, 'porc_incremental' => 0];

        $kpi  = $this->getKPIs($desde, $hasta, $sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
        $tick = $this->getTicketsProductos($desde, $hasta, $sucursal, $vendedor, $grupo, $tipoTienda);
        try { $tp2  = $this->getTicketPromedio2do($desde, $hasta, $sucursal, $vendedor, $grupo, $tipoTienda); } catch (Throwable $_) { $tp2  = $noTp2; }
        try { $incr = $this->getIncremental($desde, $hasta, $sucursal, $vendedor, $grupo, $tipoTienda);      } catch (Throwable $_) { $incr = $noIncr; }
        try { $mails = $this->getMails($desde, $hasta, $sucursal, $grupo, $tipoTienda);                      } catch (Throwable $_) { $mails = ['mails' => 0]; }

        return [
            'facturacion'         => $kpi['facturacion'],
            'unidades'            => $kpi['unidades'],
            'tickets'             => $kpi['tickets'],
            'ticket_promedio'     => $kpi['ticket_promedio'],
            'porc_cambios'        => $kpi['porc_cambios'],
            'objetivo'            => $kpi['objetivo'],
            'porc_2do'            => $tick['porc_2do'],
            'porc_3ro'            => $tick['porc_3ro'],
            'porc_incremental'    => $incr['porc_incremental'],
            'mails'               => $mails['mails'],
            'ticket_promedio_2do' => $tp2['ticket_promedio_2do'],
            'tickets_con_2do'     => $tp2['tickets_con_2do'],
            'ingresos'            => 0,
            'conversion'          => 0,
        ];
    }

    /* ──────────────────────────────────────────────
     *  MAILS (tickets con email válido)
     * ────────────────────────────────────────────── */

    public function getMails(
        string $desde, string $hasta,
        ?int $sucursal = null, ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $fp = $this->fp($sucursal, '%', '%', $grupo, $tipoTienda);
        [$sfT, $pT]   = Filters::build($fp, 't', $this->campoVendedor, $this->origen, false, false);
        [$sfGT, $pGT] = $this->grupoFiltro('t');

        $noMails = ['mails' => 0];
        try {
            $row = $this->queryOne("
                SELECT COUNT(DISTINCT t.N_COMP) AS mails
                FROM BI_SALES_TOTAL_TICKETS t
                WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
                  AND t.T_COMP = 'FAC'
                  AND ISNULL(t.EMAIL, '') <> ''
                  AND ISNULL(t.EMAIL, '') <> 'invalido' {$sfT} {$sfGT}
            ", array_merge([$desde, $hasta], $pT, $pGT));
            return ['mails' => (int)($row['mails'] ?? 0)];
        } catch (Throwable $_) {
            return $noMails;
        }
    }

    /* ──────────────────────────────────────────────
     *  COTIZACIÓN DÓLAR OFICIAL BCRA
     * ────────────────────────────────────────────── */

    /**
     * Retorna la cotización más reciente disponible.
     * Usado sólo para el label de display en el toggle de moneda.
     */
    public function getCotizacionDolar(): array
    {
        try {
            $row = $this->queryOne("
                SELECT TOP 1 TCC, CONVERT(varchar(10), Fecha, 120) AS Fecha
                FROM RO_V_DOLAR_OFICIAL_BCRA
                ORDER BY Fecha DESC
            ");
            return [
                'tcc'   => (float)($row['TCC']   ?? 1),
                'fecha' => (string)($row['Fecha'] ?? ''),
            ];
        } catch (Throwable $_) {
            return ['tcc' => 1, 'fecha' => ''];
        }
    }

    /**
     * Retorna la cotización del último día disponible de cada mes
     * dentro del rango [rangoDesde, rangoHasta], más la tcc_actual (top 1).
     *
     * Para meses sin cotización aplica fallback hacia el mes anterior más cercano.
     *
     * @return array{cotizaciones: array<string,float>, tcc_actual: float}
     */
    public function getCotizacionesMensuales(string $rangoDesde, string $rangoHasta): array
    {
        // tcc_actual: última cotización disponible (para display)
        $tccActual = 1.0;
        try {
            $row = $this->queryOne("
                SELECT TOP 1 TCC FROM RO_V_DOLAR_OFICIAL_BCRA ORDER BY Fecha DESC
            ");
            $tccActual = (float)($row['TCC'] ?? 1);
        } catch (Throwable $_) {}

        // Cotización del último día disponible por mes (una sola fila por mes garantizada)
        $cotizaciones = [];
        try {
            $rows = $this->query("
                SELECT mes_key, TCC
                FROM (
                    SELECT
                        FORMAT(Fecha, 'yyyy-MM') AS mes_key,
                        TCC,
                        ROW_NUMBER() OVER (PARTITION BY FORMAT(Fecha, 'yyyy-MM') ORDER BY Fecha DESC) AS rn
                    FROM RO_V_DOLAR_OFICIAL_BCRA
                    WHERE Fecha >= ? AND Fecha <= ?
                ) x
                WHERE rn = 1
                ORDER BY mes_key ASC
            ", [$rangoDesde, $rangoHasta]);

            foreach ($rows as $r) {
                $cotizaciones[(string)$r['mes_key']] = (float)$r['TCC'];
            }
        } catch (Throwable $_) {}

        // Fallback: rellenar meses sin cotización con el valor anterior más cercano
        if ($cotizaciones) {
            $cursor = new DateTime($rangoDesde);
            $end    = new DateTime($rangoHasta);
            $lastKnown = reset($cotizaciones); // primer valor disponible
            while ($cursor <= $end) {
                $mk = $cursor->format('Y-m');
                if (isset($cotizaciones[$mk])) {
                    $lastKnown = $cotizaciones[$mk];
                } else {
                    $cotizaciones[$mk] = $lastKnown;
                }
                $cursor->modify('+1 month');
            }
            ksort($cotizaciones);
        }

        return [
            'cotizaciones' => $cotizaciones,
            'tcc_actual'   => $tccActual,
        ];
    }

    /* ──────────────────────────────────────────────
     *  EVOLUCIÓN MENSUAL — FACTURACIÓN
     * ────────────────────────────────────────────── */

    public function getEvolucionMensualFacturacion(
        ?int   $sucursal    = null,
        string $vendedor    = '%',
        string $rubro       = '%',
        ?string $grupo      = null,
        ?string $tipoTienda = null
    ): array {
        $fp = $this->fp($sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
        $cv = $this->campoVendedor;

        $mainTable    = 'BI_SALES_SUCURSALES';
        $bkTable      = 'BI_SALES_SUCURSALES_BK';
        [$sfS, $pS]   = Filters::build($fp, 's', $cv, $this->origen, true, true);
        [$sfGS, $pGS] = $this->grupoFiltro('s');
        $sfSAll = $sfS . ' ' . $sfGS;
        $pSAll  = array_merge($pS, $pGS);

        $hasBK = false;
        try {
            $ck = $this->queryOne(
                "SELECT CASE WHEN OBJECT_ID('{$bkTable}') IS NOT NULL THEN 1 ELSE 0 END AS v"
            );
            $hasBK = ((int)($ck['v'] ?? 0)) === 1;
        } catch (\Throwable $e) { /* tabla BK inexistente */ }

        $valExpr = "ISNULL(SUM(s.IMPORTE), 0)";
        $mainSql = "
            SELECT YEAR(s.FECHA) AS anio, MONTH(s.FECHA) AS mes, {$valExpr} AS valor
            FROM {$mainTable} s WITH (NOLOCK)
            WHERE s.FECHA IS NOT NULL {$sfSAll}
            GROUP BY YEAR(s.FECHA), MONTH(s.FECHA)";
        $unionSql = $hasBK ? "
            UNION ALL
            SELECT YEAR(s.FECHA) AS anio, MONTH(s.FECHA) AS mes, {$valExpr} AS valor
            FROM {$bkTable} s WITH (NOLOCK)
            WHERE s.FECHA IS NOT NULL {$sfSAll}
            GROUP BY YEAR(s.FECHA), MONTH(s.FECHA)" : '';
        $params = $hasBK ? array_merge($pSAll, $pSAll) : $pSAll;

        $rows = $this->query("
            SELECT anio, mes, SUM(valor) AS valor
            FROM ({$mainSql}{$unionSql}) AS combined
            GROUP BY anio, mes
            ORDER BY anio, mes
        ", $params);

        $byAnioMes = [];
        $aniosSet  = [];
        foreach ($rows as $r) {
            $anio  = (int)$r['anio'];
            $mes   = (int)$r['mes'];
            $valor = (float)$r['valor'];
            $byAnioMes[$anio][$mes] = $valor;
            $aniosSet[$anio]        = true;
        }

        $anios = array_keys($aniosSet);
        rsort($anios);

        $meses  = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        $series = [];
        foreach ($anios as $anio) {
            $valores = [];
            for ($m = 1; $m <= 12; $m++) {
                $valores[] = isset($byAnioMes[$anio][$m]) ? $byAnioMes[$anio][$m] : null;
            }
            $series[] = ['anio' => $anio, 'valores' => $valores];
        }

        return ['anios' => $anios, 'meses' => $meses, 'series' => $series];
    }

    /* ──────────────────────────────────────────────
     *  SUCURSALES POR IDS  (para perfil GRUPO)
     * ────────────────────────────────────────────── */

    /**
     * Devuelve NRO_SUCURS + DESC_SUCURSAL para un listado de IDs.
     * Usa conexión central porque la conexión principal apunta a power_franquicias.
     *
     * @param  int[]  $ids  IDs de NRO_SUCURSAL a resolver
     * @return array[]      Filas con NRO_SUCURS y DESC_SUCURSAL
     */
    public function getSucursalesPorIds(array $ids): array
    {
        if (empty($ids)) return [];

        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        $connC = (new Conexion())->conectar('central');

        $ph     = implode(',', array_fill(0, count($ids), '?'));
        $params = array_values(array_map('intval', $ids));

        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $stmt = sqlsrv_query($connC,
            "SELECT sl.NRO_SUCURSAL AS NRO_SUCURS, sl.DESC_SUCURSAL
             FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
             WHERE sl.NRO_SUCURSAL IN ({$ph})
             ORDER BY sl.DESC_SUCURSAL",
            $params
        );

        if ($stmt === false) return [];

        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $rows;
    }
}
