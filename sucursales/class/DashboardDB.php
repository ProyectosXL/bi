<?php
/**
 * DashboardDB
 * Acceso a datos para el dashboard de ventas.
 * La base de datos y el campo de vendedor se resuelven desde config.php
 * según $_SESSION['tipo'] (LOCAL_PROPIO / LOCAL_PROPIO_UY / FRANQUICIA).
 */
class DashboardDB
{
    private $cid;
    private $conn;
    private $campoVendedor;
    private $tablaObjetivos;
    private bool $isUruguay = false;

    public function __construct()
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
        $config = getConfig();
        $this->cid            = new Conexion();
        $this->conn           = $this->cid->conectar($config['db']);
        $this->campoVendedor  = $config['campo_vendedor'];
        $this->tablaObjetivos = $config['tabla_objetivos'];
        $this->isUruguay      = ($config['db'] === 'power_uy');
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

    private function fromVentasSucursales(): string
    {
        $tipo = $_SESSION['tipo'] ?? 'LOCAL_PROPIO';
        if ($tipo !== 'FRANQUICIA' && $tipo !== 'GRUPO') {
            return '(SELECT * FROM BI_SALES_SUCURSALES WITH (NOLOCK))';
        }
        return "(
            SELECT NRO_SUCURS, FECHA, IMPORTE, CANTIDAD, RUBRO, COD_VENDED, DESC_VENDEDOR, NULL AS CATEGORIA
            FROM BI_SALES_SUCURSALES WITH (NOLOCK)
            UNION ALL
            SELECT pv.idTango AS NRO_SUCURS, fd.fecha AS FECHA, fd.importeVentaReal AS IMPORTE, 0 AS CANTIDAD, 'FRANQUICIA_ST' AS RUBRO, '0' AS COD_VENDED, 'SIN TANGO' AS DESC_VENDEDOR, NULL AS CATEGORIA
            FROM sistemas.dbo.FP_ObjetivosFinalesDetalle fd WITH (NOLOCK)
            INNER JOIN [SERVIDORTESTING].dbXLSales.dbo.PuntosDeVenta pv WITH (NOLOCK) ON fd.idPOS = pv.id
            INNER JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WITH (NOLOCK) ON pv.idTango = sl.NRO_SUCURSAL
            WHERE (sl.TANGO IS NULL OR sl.TANGO <> 1)
        )";
    }

    /* ──────────────────────────────────────────────
     *  FILTROS DE PERÍODO
     *  Devuelve [desde_actual, hasta_actual, desde_previo, hasta_previo]
     * ────────────────────────────────────────────── */

    public static function calcularPeriodo(string $tipo): array
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';
        return PeriodHelper::calcularPeriodo($tipo);
    }

    /* ──────────────────────────────────────────────
     *  KPIs GLOBALES
     * ────────────────────────────────────────────── */

    /**
     * Facturación, unidades y objetivo del período.
     * $sucursal = '%' para todas.
     */
    public function getKPIs(string $desde, string $hasta, ?int $nroSucurs = null, string $vendedor = '%', string $rubro = '%'): array
    {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfT  = $nroSucurs !== null ? "AND t.NRO_SUCURS = ?" : "";
        $sfO  = $nroSucurs !== null ? "AND o.NRO_SUCURSAL = ?" : "";
        $sfVS = $vendedor !== '%' ? "AND s.{$cv} = ?" : "";
        $sfVT = $vendedor !== '%' ? "AND t.{$cv} = ?" : "";
        $sfRS = $rubro !== '%' ? "AND s.RUBRO = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor !== '%' ? [$vendedor] : [];
        $rub  = $rubro !== '%' ? [$rubro] : [];
        $from = $this->fromVentasSucursales();
        $sql = "
            SELECT
                ISNULL(SUM(s.IMPORTE), 0)   AS facturacion,
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(CASE WHEN s.CANTIDAD > 0
                                 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END), 0) AS unidades_positivas,
                ISNULL(SUM(CASE WHEN s.CANTIDAD < 0
                                 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END) * -1, 0) AS cambios
            FROM {$from} s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfS} {$sfVS} {$sfRS}
        ";
        $row = $this->queryOne($sql, array_merge([$desde, $hasta], $suc, $vend, $rub));

        // Tickets + ticket promedio en una sola query
        $sqlT = "
            SELECT
                COUNT(DISTINCT t.N_COMP)           AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_tickets
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              AND t.T_COMP = 'FAC'
              {$sfT} {$sfVT}
        ";
        $rowT = $this->queryOne($sqlT, array_merge([$desde, $hasta], $suc, $vend));

        $tickets      = (int)($rowT['tickets']      ?? 0);
        $sumaTickets  = (float)($rowT['suma_tickets'] ?? 0);
        $ticketProm   = $tickets > 0 ? $sumaTickets / $tickets : 0;

        // Objetivo (sin filtro de vendedor/rubro, es a nivel de sucursal)
        $sqlObj = "
            SELECT ISNULL(SUM(o.IMPORTE_OBJ), 0) AS objetivo
            FROM {$this->tablaObjetivos} o
            WHERE o.FECHA >= ? AND o.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfO}
        ";
        $rowObj = $this->queryOne($sqlObj, array_merge([$desde, $hasta], $suc));

        $facturacion      = (float)($row['facturacion'] ?? 0);
        $unidades         = (float)($row['unidades'] ?? 0);
        $unidadesPos      = (float)($row['unidades_positivas'] ?? 0);
        $cambios          = (float)($row['cambios'] ?? 0);
        $porcCambios      = $unidadesPos > 0 ? $cambios / $unidadesPos : 0;

        return [
            'facturacion'       => $facturacion,
            'unidades'          => $unidades,
            'tickets'           => $tickets,
            'ticket_promedio'   => $ticketProm,
            'cambios'           => $cambios,
            'porc_cambios'      => $porcCambios,
            'objetivo'          => (float)($rowObj['objetivo'] ?? 0),
        ];
    }

    /* ──────────────────────────────────────────────
     *  TICKETS 2DO Y 3ER PRODUCTO
     * ────────────────────────────────────────────── */

    public function getTicketsProductos(string $desde, string $hasta, ?int $nroSucurs = null, string $vendedor = '%'): array
    {
        $cv   = $this->campoVendedor;
        $sfT  = $nroSucurs !== null ? "AND t.NRO_SUCURS = ?" : "";
        $sfVT = $vendedor !== '%' ? "AND t.{$cv} = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor !== '%' ? [$vendedor] : [];
        $sql = "
            SELECT
                COUNT(DISTINCT t.N_COMP) AS total,
                COUNT(DISTINCT CASE WHEN t.CANTIDAD > 1 THEN t.N_COMP END) AS seg,
                COUNT(DISTINCT CASE WHEN t.CANTIDAD > 2 THEN t.N_COMP END) AS ter
            FROM BI_SALES_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfT} {$sfVT}
        ";
        $row   = $this->queryOne($sql, array_merge([$desde, $hasta], $suc, $vend));
        $total = (int)($row['total'] ?? 0);
        $seg   = (int)($row['seg']   ?? 0);
        $ter   = (int)($row['ter']   ?? 0);

        return [
            'tickets_2do'      => $seg,
            'porc_2do'         => $total > 0 ? $seg / $total : 0,
            'tickets_3ro'      => $ter,
            'porc_3ro'         => $total > 0 ? $ter / $total : 0,
        ];
    }

    /* ──────────────────────────────────────────────
     *  % INCREMENTAL
     * ────────────────────────────────────────────── */

    public function getIncremental(string $desde, string $hasta, ?int $nroSucurs = null, string $vendedor = '%'): array
    {
        $cv   = $this->campoVendedor;
        $sfP  = $nroSucurs !== null ? "AND p.NRO_SUCURS = ?" : "";
        $sfVP = $vendedor !== '%' ? "AND p.{$cv} = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor !== '%' ? [$vendedor] : [];
        $sql = "
            SELECT
                ISNULL(SUM(p.CAMBIO), 0)       AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0) AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day, 1, CAST(? AS DATE))
              {$sfP} {$sfVP}
        ";
        $row = $this->queryOne($sql, array_merge([$desde, $hasta], $suc, $vend));
        $cambios  = (float)($row['cambios_incr'] ?? 0);
        $devol    = (float)($row['devoluciones'] ?? 0);
        $porc     = $devol != 0 ? ($cambios - $devol) / $devol : 0;
        return [
            'cambios_incr'  => $cambios,
            'devoluciones'  => $devol,
            'porc_incremental' => $porc,
        ];
    }

    /* ──────────────────────────────────────────────
     *  VENDEDORES
     * ────────────────────────────────────────────── */

    public function getVendedores(string $desde, string $hasta, ?int $nroSucurs = null, string $vendedor = '%', string $rubro = '%'): array
    {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfT  = $nroSucurs !== null ? "AND t.NRO_SUCURS = ?" : "";
        $sfP  = $nroSucurs !== null ? "AND p.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor !== '%' ? "AND s.{$cv} = ?" : "";
        $sfVT = $vendedor !== '%' ? "AND t.{$cv} = ?" : "";
        $sfVP = $vendedor !== '%' ? "AND p.{$cv} = ?" : "";
        $sfRS = $rubro !== '%' ? "AND s.RUBRO = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor !== '%' ? [$vendedor] : [];
        $rub  = $rubro !== '%' ? [$rubro] : [];

        // Incluir DESC_VENDEDOR y la fecha más reciente de cada combinación (código, nombre).
        // Se usa ultima_fecha en PHP para resolver cuál nombre es el vigente cuando
        // un COD_VENDED fue reasignado y coexisten nombres distintos en el período.
        $selectDescVend  = $cv === 'DESC_VENDEDOR' ? "s.DESC_VENDEDOR, CONVERT(VARCHAR(10), MAX(s.FECHA), 120) AS ultima_fecha," : "";
        $groupByDescVend = $cv === 'DESC_VENDEDOR' ? ", s.DESC_VENDEDOR" : "";

        $from = $this->fromVentasSucursales();
        // Facturación, unidades y cambios por vendedor
        $sql = "
            SELECT
                s.COD_VENDED,
                {$selectDescVend}
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(CASE WHEN s.CANTIDAD > 0
                                 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END), 0) AS unidades_positivas,
                ISNULL(SUM(CASE WHEN s.CANTIDAD < 0
                                 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END) * -1, 0) AS cambios,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM {$from} s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfS} {$sfVS} {$sfRS}
            GROUP BY s.COD_VENDED{$groupByDescVend}
        ";
        $ventas = $this->query($sql, array_merge([$desde, $hasta], $suc, $vend, $rub));

        // Q-T1: tickets y monto — sin JOIN para evitar inflación del SUM por los ítems del ticket
        $sqlT1 = "
            SELECT
                t.{$cv} AS cv_key,
                COUNT(DISTINCT t.N_COMP)           AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_ticket
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              AND t.T_COMP = 'FAC'
              {$sfT} {$sfVT}
            GROUP BY t.{$cv}
        ";
        $tickRows = $this->query($sqlT1, array_merge([$desde, $hasta], $suc, $vend));
        $tickMap  = [];
        foreach ($tickRows as $row) {
            $tickMap[(string)$row['cv_key']] = ['tickets' => (int)$row['tickets'], 'suma_ticket' => (float)$row['suma_ticket']];
        }

        // Q-T2: 2do/3er producto — desde BI_SALES_TICKETS directamente (igual que getKPIsBulk Q3)
        $sfTK  = $nroSucurs !== null ? "AND NRO_SUCURS = ?" : "";
        $sfVTK = $vendedor !== '%'   ? "AND {$cv} = ?"      : "";
        $sqlT2 = "
            SELECT
                {$cv} AS cv_key,
                COUNT(DISTINCT CASE WHEN CANTIDAD > 1 THEN N_COMP END) AS tickets_2do,
                COUNT(DISTINCT CASE WHEN CANTIDAD > 2 THEN N_COMP END) AS tickets_3ro
            FROM BI_SALES_TICKETS
            WHERE FECHA >= ? AND FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfTK} {$sfVTK}
            GROUP BY {$cv}
        ";
        $prodRows = $this->query($sqlT2, array_merge([$desde, $hasta], $suc, $vend));
        $prodMap  = [];
        foreach ($prodRows as $row) {
            $prodMap[(string)$row['cv_key']] = ['tickets_2do' => (int)$row['tickets_2do'], 'tickets_3ro' => (int)$row['tickets_3ro']];
        }

        // Incremental por vendedor
        $sqlI = "
            SELECT
                p.COD_VENDED,
                ISNULL(SUM(p.CAMBIO), 0)       AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0) AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day, 1, CAST(? AS DATE))
              {$sfP} {$sfVP}
            GROUP BY p.COD_VENDED
        ";
        $incr = $this->query($sqlI, array_merge([$desde, $hasta], $suc, $vend));
        $incrMap = [];
        foreach ($incr as $i) {
            $incrMap[$i['COD_VENDED']] = $i;
        }

        // Presencialidad por vendedor (COD_VENDED)
        $sfH = "";
        $pH = [$desde, $hasta];
        if ($nroSucurs !== null) {
            $sfH = "AND m.nro_sucursal = ?";
            $pH[] = $nroSucurs;
        }
        $sqlH = "
            SELECT
                h.COD_VENDED,
                COUNT(*) AS total_horas,
                SUM(CASE WHEN h.TIPO_HORA = 'ausencia' THEN 1 ELSE 0 END) AS horas_ausencia
            FROM sistemas.dbo.FP_GESTION_HORARIOS h WITH (NOLOCK)
            LEFT JOIN (
                SELECT 
                    ID_DEPARTAMENTO AS id_depto,
                    CASE 
                        WHEN DESC_DEPARTAMENTO LIKE '%UNICENTER%' THEN 2
                        ELSE TRY_CAST(REPLACE(REPLACE(COD_DEPARTAMENTO, 'LOC', ''), 'loc', '') AS INT)
                    END AS nro_sucursal
                FROM OPENQUERY([XL-SUELDOS], 'SELECT ID_DEPARTAMENTO, COD_DEPARTAMENTO, DESC_DEPARTAMENTO FROM LAKERS_CORP_SA.dbo.DEPARTAMENTO')
                WHERE COD_DEPARTAMENTO LIKE 'LOC%'
            ) m ON h.NRO_SUCURSAL = m.id_depto
            WHERE h.FECHA >= ? AND h.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfH}
            GROUP BY h.COD_VENDED
        ";
        $presenceRows = $this->query($sqlH, $pH);
        $presenceMap = [];
        foreach ($presenceRows as $row) {
            $presenceMap[(string)$row['COD_VENDED']] = [
                'total_horas' => (float)$row['total_horas'],
                'horas_ausencia' => (float)$row['horas_ausencia']
            ];
        }

        // Para cada COD_VENDED elegir el nombre vigente (el de la fecha más reciente)
        // y así evitar filas duplicadas cuando un código fue reasignado a otra persona.
        $nameMap = [];
        if ($cv === 'DESC_VENDEDOR') {
            foreach ($ventas as $v) {
                $cod   = $v['COD_VENDED'];
                $fecha = (string)($v['ultima_fecha'] ?? '');
                if (!isset($nameMap[$cod]) || $fecha > $nameMap[$cod]['fecha']) {
                    $nameMap[$cod] = ['name' => $v['DESC_VENDEDOR'] ?? (string)$cod, 'fecha' => $fecha];
                }
            }
        }

        // Agrupar en PHP por nombre de vendedor para consolidar códigos duplicados.
        // Si una persona fue dada de alta con dos COD_VENDED distintos, sus
        // métricas de ventas e incremental se suman bajo un único nombre.
        $agg = [];
        foreach ($ventas as $v) {
            $cod  = $v['COD_VENDED'];
            $key  = $cv === 'DESC_VENDEDOR'
                ? ($nameMap[$cod]['name'] ?? $v['DESC_VENDEDOR'] ?? $cod)
                : $cod;

            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'label'             => $key,
                    'unidades'          => 0.0,
                    'unidades_positivas'=> 0.0,
                    'cambios'           => 0.0,
                    'facturacion'       => 0.0,
                    '_codes'            => [],
                ];
            }
            $agg[$key]['unidades']           += (float)$v['unidades'];
            $agg[$key]['unidades_positivas'] += (float)$v['unidades_positivas'];
            $agg[$key]['cambios']            += (float)$v['cambios'];
            $agg[$key]['facturacion']        += (float)$v['facturacion'];
            $agg[$key]['_codes'][]            = $cod;
        }

        $result = [];
        foreach ($agg as $key => $a) {
            $ticks    = $tickMap[(string)$key] ?? [];
            $prods    = $prodMap[(string)$key] ?? [];
            $tickets_ = (int)($ticks['tickets']       ?? 0);
            $sumaT    = (float)($ticks['suma_ticket']  ?? 0);
            $t2       = (int)($prods['tickets_2do']    ?? 0);
            $t3       = (int)($prods['tickets_3ro']    ?? 0);

            // Incremental y Presencialidad — lookup por COD_VENDED (consistente con BI_SALES_SUCURSALES)
            $cambI = 0.0; $devol = 0.0;
            $totHrs = 0.0; $ausHrs = 0.0;
            foreach ($a['_codes'] as $cod) {
                $inc   = $incrMap[$cod] ?? [];
                $cambI += (float)($inc['cambios_incr']  ?? 0);
                $devol += (float)($inc['devoluciones']  ?? 0);

                $pres  = $presenceMap[(string)$cod] ?? [];
                $totHrs += (float)($pres['total_horas'] ?? 0);
                $ausHrs += (float)($pres['horas_ausencia'] ?? 0);
            }
            $porcPresencia = $totHrs > 0 ? ($totHrs - $ausHrs) / $totHrs : 1.0;

            $result[] = [
                'vendedor'         => $a['label'],
                'cod_vended'       => implode(' / ', $a['_codes']),
                'unidades'         => $a['unidades'],
                'facturacion'      => $a['facturacion'],
                'tickets'          => $tickets_,
                'ticket_promedio'  => $tickets_ > 0 ? $sumaT / $tickets_ : 0,
                'porc_2do'         => $tickets_ > 0 ? $t2 / $tickets_ : 0,
                'porc_3ro'         => $tickets_ > 0 ? $t3 / $tickets_ : 0,
                'porc_cambios'     => $a['unidades_positivas'] > 0 ? $a['cambios'] / $a['unidades_positivas'] : 0,
                'porc_incremental' => $devol != 0 ? ($cambI - $devol) / $devol : 0,
                'porc_presencia'   => $porcPresencia,
            ];
        }

        usort($result, fn($a, $b) => $b['facturacion'] <=> $a['facturacion']);
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  RUBROS
     * ────────────────────────────────────────────── */

    public function getRubros(string $desde, string $hasta, ?int $nroSucurs = null, string $vendedor = '%'): array
    {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor !== '%' ? "AND s.{$cv} = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor !== '%' ? [$vendedor] : [];
        $from = $this->fromVentasSucursales();
        $sql = "
            SELECT
                s.RUBRO,
                ISNULL(SUM(s.CANTIDAD), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM {$from} s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfS} {$sfVS}
            GROUP BY s.RUBRO
            ORDER BY unidades DESC
        ";
        return $this->query($sql, array_merge([$desde, $hasta], $suc, $vend));
    }

    public function getCategorias(string $desde, string $hasta, ?int $nroSucurs = null, string $rubro = '', string $vendedor = '%'): array
    {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor !== '%' ? "AND s.{$cv} = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor !== '%' ? [$vendedor] : [];
        $from = $this->fromVentasSucursales();
        $sql = "
            SELECT
                s.CATEGORIA AS RUBRO,
                ISNULL(SUM(s.CANTIDAD), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM {$from} s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              AND s.RUBRO = ?
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfS} {$sfVS}
            GROUP BY s.CATEGORIA
            ORDER BY unidades DESC
        ";
        return $this->query($sql, array_merge([$desde, $hasta, $rubro], $suc, $vend));
    }

    /* ──────────────────────────────────────────────
     *  SERIE TEMPORAL (para mini-charts)
     * ────────────────────────────────────────────── */

    public function getSerieFacturacion(string $desde, string $hasta, ?int $nroSucurs = null, string $vendedor = '%', string $rubro = '%', bool $lightweight = false): array
    {
        $cv   = $this->campoVendedor;
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor !== '%' ? [$vendedor] : [];
        $rub  = $rubro !== '%' ? [$rubro] : [];

        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor !== '%' ? "AND s.{$cv} = ?" : "";
        $sfRS = $rubro !== '%' ? "AND s.RUBRO = ?" : "";

        $from = $this->fromVentasSucursales();
        // Modo lightweight: solo facturación diaria (sin tickets, incremental ni ingresos)
        if ($lightweight) {
            $sql = "
                SELECT
                    CAST(s.FECHA AS DATE) AS fecha,
                    ISNULL(SUM(s.IMPORTE), 0) AS facturacion
                FROM {$from} s
                WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
                  {$sfS} {$sfVS} {$sfRS}
                GROUP BY CAST(s.FECHA AS DATE)
                ORDER BY 1 ASC
            ";
            $rows = $this->query($sql, array_merge([$desde, $hasta], $suc, $vend, $rub));
            $result = [];
            foreach ($rows as $row) {
                $result[] = [
                    'fecha'       => $row['fecha']->format('Y-m-d'),
                    'facturacion' => (float)$row['facturacion'],
                ];
            }
            return $result;
        }

        // Modo completo: facturación, unidades y cambios por fecha
        $sql = "
            SELECT
                CAST(s.FECHA AS DATE) AS fecha,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion,
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(CASE WHEN s.CANTIDAD > 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) AS unidades_positivas,
                ISNULL(SUM(CASE WHEN s.CANTIDAD < 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END) * -1, 0) AS cambios
            FROM {$from} s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfS} {$sfVS} {$sfRS}
            GROUP BY CAST(s.FECHA AS DATE)
            ORDER BY 1 ASC
        ";
        $rows = $this->query($sql, array_merge([$desde, $hasta], $suc, $vend, $rub));

        // Tickets por fecha (count, suma, 2do y 3er producto en una sola query con LEFT JOIN)
        $sfT  = $nroSucurs !== null ? "AND t.NRO_SUCURS = ?" : "";
        $sfVT = $vendedor !== '%' ? "AND t.{$cv} = ?" : "";
        $sqlT = "
            SELECT
                CAST(t.FECHA AS DATE) AS fecha,
                COUNT(DISTINCT t.N_COMP) AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_tickets,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 1 THEN t.N_COMP END) AS tickets_2do,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 2 THEN t.N_COMP END) AS tickets_3ro
            FROM BI_SALES_TOTAL_TICKETS t WITH (NOLOCK)
            LEFT JOIN BI_SALES_TICKETS tk WITH (NOLOCK)
                ON t.N_COMP = tk.N_COMP
               AND tk.FECHA >= ? AND tk.FECHA < DATEADD(day, 1, CAST(? AS DATE))
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT} {$sfVT}
            GROUP BY CAST(t.FECHA AS DATE)
        ";
        $ticketsData = $this->query($sqlT, array_merge([$desde, $hasta, $desde, $hasta], $suc, $vend));
        $ticketsMap = [];
        foreach ($ticketsData as $td) {
            $ticketsMap[$td['fecha']->format('Y-m-d')] = $td;
        }

        // Incremental por fecha
        $sfP  = $nroSucurs !== null ? "AND p.NRO_SUCURS = ?" : "";
        $sfVP = $vendedor !== '%' ? "AND p.{$cv} = ?" : "";
        $sqlIncr = "
            SELECT
                CAST(p.FECHA_MOV AS DATE) AS fecha,
                ISNULL(SUM(p.CAMBIO), 0) AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0) AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p WITH (NOLOCK)
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day, 1, CAST(? AS DATE))
              {$sfP} {$sfVP}
            GROUP BY CAST(p.FECHA_MOV AS DATE)
        ";
        $incrData = $this->query($sqlIncr, array_merge([$desde, $hasta], $suc, $vend));
        $incrMap = [];
        foreach ($incrData as $id) {
            $incrMap[$id['fecha']->format('Y-m-d')] = $id;
        }

        // Ingresos por fecha (solo filtro por sucursal, es dato de local completo)
        $sfIn  = $nroSucurs !== null ? "AND ig.NRO_SUCURS = ?" : "";
        $condFechaHora = $this->isUruguay ? "" : "AND ig.FECHA_HORA IS NOT NULL";
        $sqlIn = "
            SELECT
                CAST(ig.FECHA AS DATE) AS fecha,
                ISNULL(SUM(ig.INGRESOS), 0) AS ingresos
            FROM BI_T_INGRESOS_SUCURSALES ig WITH (NOLOCK)
            WHERE ig.FECHA >= ? AND ig.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$condFechaHora}
              {$sfIn}
            GROUP BY CAST(ig.FECHA AS DATE)
        ";
        $ingresosData = $this->query($sqlIn, array_merge([$desde, $hasta], $suc));
        $ingresosMap  = [];
        foreach ($ingresosData as $ig) {
            $ingresosMap[$ig['fecha']->format('Y-m-d')] = (int)$ig['ingresos'];
        }

        // Presencialidad por fecha (solo a nivel sucursal)
        $sfH = "";
        $pH = [$desde, $hasta];
        if ($nroSucurs !== null) {
            $sfH = "AND m.nro_sucursal = ?";
            $pH[] = $nroSucurs;
        }
        $sqlH = "
            SELECT
                CAST(h.FECHA AS DATE) AS fecha,
                COUNT(*) AS total_horas,
                SUM(CASE WHEN h.TIPO_HORA = 'ausencia' THEN 1 ELSE 0 END) AS horas_ausencia
            FROM sistemas.dbo.FP_GESTION_HORARIOS h WITH (NOLOCK)
            LEFT JOIN (
                SELECT 
                    ID_DEPARTAMENTO AS id_depto,
                    CASE 
                        WHEN DESC_DEPARTAMENTO LIKE '%UNICENTER%' THEN 2
                        ELSE TRY_CAST(REPLACE(REPLACE(COD_DEPARTAMENTO, 'LOC', ''), 'loc', '') AS INT)
                    END AS nro_sucursal
                FROM OPENQUERY([XL-SUELDOS], 'SELECT ID_DEPARTAMENTO, COD_DEPARTAMENTO, DESC_DEPARTAMENTO FROM LAKERS_CORP_SA.dbo.DEPARTAMENTO')
                WHERE COD_DEPARTAMENTO LIKE 'LOC%'
            ) m ON h.NRO_SUCURSAL = m.id_depto
            WHERE h.FECHA >= ? AND h.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfH}
            GROUP BY CAST(h.FECHA AS DATE)
        ";
        $presenceData = $this->query($sqlH, $pH);
        $presenceMap = [];
        foreach ($presenceData as $pd) {
            $presenceMap[$pd['fecha']->format('Y-m-d')] = $pd;
        }

        // Combinar todos los datos
        $result = [];
        foreach ($rows as $row) {
            $fecha = $row['fecha']->format('Y-m-d');
            $tickInfo = $ticketsMap[$fecha] ?? null;
            $incrInfo = $incrMap[$fecha] ?? null;
            $presInfo = $presenceMap[$fecha] ?? null;

            $tickets = (int)($tickInfo['tickets'] ?? 0);
            $sumaTickets = (float)($tickInfo['suma_tickets'] ?? 0);
            $tickets2do = (int)($tickInfo['tickets_2do'] ?? 0);
            $tickets3ro = (int)($tickInfo['tickets_3ro'] ?? 0);
            $cambiosIncr = (float)($incrInfo['cambios_incr'] ?? 0);
            $devoluciones = (float)($incrInfo['devoluciones'] ?? 0);

            $unidades = (float)$row['unidades'];
            $unidadesPos = (float)$row['unidades_positivas'];
            $cambios = (float)$row['cambios'];

            $hrs = (float)($presInfo['total_horas'] ?? 0);
            $aus = (float)($presInfo['horas_ausencia'] ?? 0);
            $porcPresencia = $hrs > 0 ? ($hrs - $aus) / $hrs : 1.0;

            $result[] = [
                'fecha' => $fecha,
                'facturacion' => (float)$row['facturacion'],
                'unidades' => $unidades,
                'cambios' => $cambios,
                'tickets' => $tickets,
                'ticket_promedio' => $tickets > 0 ? $sumaTickets / $tickets : 0,
                'porc_2do' => $tickets > 0 ? $tickets2do / $tickets : 0,
                'porc_3ro' => $tickets > 0 ? $tickets3ro / $tickets : 0,
                'porc_cambios' => $unidadesPos > 0 ? $cambios / $unidadesPos : 0,
                'porc_incremental' => $devoluciones != 0 ? ($cambiosIncr - $devoluciones) / $devoluciones : 0,
                'ingresos'   => $ingresosMap[$fecha] ?? 0,
                'conversion' => ($ingresosMap[$fecha] ?? 0) > 0 ? $tickets / $ingresosMap[$fecha] : 0,
                'porc_presencia' => $porcPresencia,
            ];
        }

        return $result;
    }

    /* ──────────────────────────────────────────────
     *  SERIE OBJETIVO DIARIO
     * ────────────────────────────────────────────── */

    /**
     * Objetivo diario del período, como mapa fecha → importe.
     */
    public function getSerieObjetivo(string $desde, string $hasta, ?int $nroSucurs = null): array
    {
        $sfO    = $nroSucurs !== null ? "AND o.NRO_SUCURSAL = ?" : "";
        $params = [$desde, $hasta];
        if ($nroSucurs !== null) $params[] = $nroSucurs;

        $sql = "
            SELECT
                CAST(o.FECHA AS DATE) AS fecha,
                ISNULL(SUM(o.IMPORTE_OBJ), 0) AS objetivo
            FROM {$this->tablaObjetivos} o WITH (NOLOCK)
            WHERE o.FECHA >= ? AND o.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfO}
            GROUP BY CAST(o.FECHA AS DATE)
            ORDER BY 1 ASC
        ";

        $rows   = $this->query($sql, $params);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['fecha']->format('Y-m-d')] = (float)$row['objetivo'];
        }
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  KPIs BULK — actual + previo + benchmark en 5 queries
     *  Reemplaza 9+ llamadas separadas (getKPIs×3, getTicketsProductos×3,
     *  getIncremental×3) usando agregación condicional CASE WHEN por tabla.
     * ────────────────────────────────────────────── */

    /**
     * @return array{actual:array, previo:array, benchmark:array}
     */
    public function getKPIsBulk(
        string $da, string $ha,
        string $dp, string $hp,
        ?int   $nroSucurs = null,
        string $vendedor  = '%',
        string $rubro     = '%'
    ): array {
        $cv   = $this->campoVendedor;
        // Límites exclusivos: evita DATEADD en SQL y facilita uso de índices
        $haX  = (new DateTime($ha))->modify('+1 day')->format('Y-m-d');
        $hpX  = (new DateTime($hp))->modify('+1 day')->format('Y-m-d');

        $sfS  = $nroSucurs !== null ? 'AND NRO_SUCURS = ?'    : '';
        $sfV  = $vendedor  !== '%'  ? "AND {$cv} = ?"         : '';
        $sfR  = $rubro     !== '%'  ? 'AND RUBRO = ?'          : '';
        $sfST = $nroSucurs !== null ? 'AND NRO_SUCURS = ?'    : '';
        $sfVT = $vendedor  !== '%'  ? "AND {$cv} = ?"         : '';
        $sfSP = $nroSucurs !== null ? 'AND NRO_SUCURS = ?'    : '';
        $sfVP = $vendedor  !== '%'  ? "AND {$cv} = ?"         : '';
        $sfSO = $nroSucurs !== null ? 'AND NRO_SUCURSAL = ?'  : '';

        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor  !== '%'  ? [$vendedor]  : [];
        $rub  = $rubro     !== '%'  ? [$rubro]     : [];

        $from = $this->fromVentasSucursales();
        // ── Q1: BI_SALES_SUCURSALES — un scan para act + prev + benchmark ──
        $q1 = "
            SELECT
                ISNULL(SUM(CASE WHEN is_a=1 THEN imp  ELSE 0 END),0)             AS fact_act,
                ISNULL(SUM(CASE WHEN is_p=1 THEN imp  ELSE 0 END),0)             AS fact_prev,
                ISNULL(SUM(CASE WHEN is_b=1 THEN imp  ELSE 0 END),0)             AS fact_bench,
                ISNULL(SUM(CASE WHEN is_a=1 AND rg=0 THEN qty  ELSE 0 END),0)   AS unid_act,
                ISNULL(SUM(CASE WHEN is_p=1 AND rg=0 THEN qty  ELSE 0 END),0)   AS unid_prev,
                ISNULL(SUM(CASE WHEN is_b=1 AND rg=0 THEN qty  ELSE 0 END),0)   AS unid_bench,
                ISNULL(SUM(CASE WHEN is_a=1 AND rg=0 AND qty>0 THEN  qty ELSE 0 END),0) AS upos_act,
                ISNULL(SUM(CASE WHEN is_p=1 AND rg=0 AND qty>0 THEN  qty ELSE 0 END),0) AS upos_prev,
                ISNULL(SUM(CASE WHEN is_b=1 AND rg=0 AND qty>0 THEN  qty ELSE 0 END),0) AS upos_bench,
                ISNULL(SUM(CASE WHEN is_a=1 AND rg=0 AND qty<0 THEN -qty ELSE 0 END),0) AS camb_act,
                ISNULL(SUM(CASE WHEN is_p=1 AND rg=0 AND qty<0 THEN -qty ELSE 0 END),0) AS camb_prev,
                ISNULL(SUM(CASE WHEN is_b=1 AND rg=0 AND qty<0 THEN -qty ELSE 0 END),0) AS camb_bench
            FROM (
                SELECT IMPORTE AS imp, CANTIDAD AS qty,
                    CASE WHEN RUBRO IN ('CONCEPTO','PACKAGING') THEN 1 ELSE 0 END AS rg,
                    CASE WHEN FECHA >= ? AND FECHA < ? {$sfS} {$sfV} {$sfR} THEN 1 ELSE 0 END AS is_a,
                    CASE WHEN FECHA >= ? AND FECHA < ? {$sfS} {$sfV} {$sfR} THEN 1 ELSE 0 END AS is_p,
                    CASE WHEN FECHA >= ? AND FECHA < ?                       THEN 1 ELSE 0 END AS is_b
                FROM {$from} s
                WHERE (FECHA >= ? AND FECHA < ?) OR (FECHA >= ? AND FECHA < ?)
            ) t
        ";
        $p1 = array_merge(
            [$da, $haX], $suc, $vend, $rub,
            [$dp, $hpX], $suc, $vend, $rub,
            [$da, $haX],
            [$da, $haX, $dp, $hpX]
        );
        $r1 = $this->queryOne($q1, $p1) ?? [];

        // ── Q2: BI_SALES_TOTAL_TICKETS — conteo + suma ticket ──────────────
        $q2 = "
            SELECT
                COUNT(DISTINCT CASE WHEN is_a=1 THEN nc ELSE NULL END) AS tick_act,
                ISNULL(SUM(CASE WHEN is_a=1 THEN imp ELSE 0 END),0)    AS suma_act,
                COUNT(DISTINCT CASE WHEN is_p=1 THEN nc ELSE NULL END) AS tick_prev,
                ISNULL(SUM(CASE WHEN is_p=1 THEN imp ELSE 0 END),0)    AS suma_prev,
                COUNT(DISTINCT CASE WHEN is_b=1 THEN nc ELSE NULL END) AS tick_bench,
                ISNULL(SUM(CASE WHEN is_b=1 THEN imp ELSE 0 END),0)    AS suma_bench
            FROM (
                SELECT N_COMP AS nc, IMP_TOTAL_TICKET AS imp,
                    CASE WHEN FECHA >= ? AND FECHA < ? {$sfST} {$sfVT} THEN 1 ELSE 0 END AS is_a,
                    CASE WHEN FECHA >= ? AND FECHA < ? {$sfST} {$sfVT} THEN 1 ELSE 0 END AS is_p,
                    CASE WHEN FECHA >= ? AND FECHA < ?                  THEN 1 ELSE 0 END AS is_b
                FROM BI_SALES_TOTAL_TICKETS WITH (NOLOCK)
                WHERE T_COMP = 'FAC'
                  AND ((FECHA >= ? AND FECHA < ?) OR (FECHA >= ? AND FECHA < ?))
            ) t
        ";
        $p2 = array_merge(
            [$da, $haX], $suc, $vend,
            [$dp, $hpX], $suc, $vend,
            [$da, $haX],
            [$da, $haX, $dp, $hpX]
        );
        $r2 = $this->queryOne($q2, $p2) ?? [];

        // ── Q3: BI_SALES_TICKETS — 2do y 3er producto ──────────────────────
        $q3 = "
            SELECT
                COUNT(DISTINCT CASE WHEN is_a=1             THEN nc ELSE NULL END) AS tot_act,
                COUNT(DISTINCT CASE WHEN is_a=1 AND qty > 1 THEN nc ELSE NULL END) AS t2_act,
                COUNT(DISTINCT CASE WHEN is_a=1 AND qty > 2 THEN nc ELSE NULL END) AS t3_act,
                COUNT(DISTINCT CASE WHEN is_p=1             THEN nc ELSE NULL END) AS tot_prev,
                COUNT(DISTINCT CASE WHEN is_p=1 AND qty > 1 THEN nc ELSE NULL END) AS t2_prev,
                COUNT(DISTINCT CASE WHEN is_p=1 AND qty > 2 THEN nc ELSE NULL END) AS t3_prev,
                COUNT(DISTINCT CASE WHEN is_b=1             THEN nc ELSE NULL END) AS tot_bench,
                COUNT(DISTINCT CASE WHEN is_b=1 AND qty > 1 THEN nc ELSE NULL END) AS t2_bench,
                COUNT(DISTINCT CASE WHEN is_b=1 AND qty > 2 THEN nc ELSE NULL END) AS t3_bench
            FROM (
                SELECT N_COMP AS nc, CANTIDAD AS qty,
                    CASE WHEN FECHA >= ? AND FECHA < ? {$sfST} {$sfVT} THEN 1 ELSE 0 END AS is_a,
                    CASE WHEN FECHA >= ? AND FECHA < ? {$sfST} {$sfVT} THEN 1 ELSE 0 END AS is_p,
                    CASE WHEN FECHA >= ? AND FECHA < ?                  THEN 1 ELSE 0 END AS is_b
                FROM BI_SALES_TICKETS WITH (NOLOCK)
                WHERE (FECHA >= ? AND FECHA < ?) OR (FECHA >= ? AND FECHA < ?)
            ) t
        ";
        $r3 = $this->queryOne($q3, $p2) ?? [];  // mismos params que Q2

        // ── Q4: BI_SALES_PORC_INCREMENTAL ──────────────────────────────────
        $q4 = "
            SELECT
                ISNULL(SUM(CASE WHEN is_a=1 THEN ci ELSE 0 END),0) AS ci_act,
                ISNULL(SUM(CASE WHEN is_a=1 THEN dv ELSE 0 END),0) AS dv_act,
                ISNULL(SUM(CASE WHEN is_p=1 THEN ci ELSE 0 END),0) AS ci_prev,
                ISNULL(SUM(CASE WHEN is_p=1 THEN dv ELSE 0 END),0) AS dv_prev,
                ISNULL(SUM(CASE WHEN is_b=1 THEN ci ELSE 0 END),0) AS ci_bench,
                ISNULL(SUM(CASE WHEN is_b=1 THEN dv ELSE 0 END),0) AS dv_bench
            FROM (
                SELECT CAMBIO AS ci, DEVOLUCIONES AS dv,
                    CASE WHEN FECHA_MOV >= ? AND FECHA_MOV < ? {$sfSP} {$sfVP} THEN 1 ELSE 0 END AS is_a,
                    CASE WHEN FECHA_MOV >= ? AND FECHA_MOV < ? {$sfSP} {$sfVP} THEN 1 ELSE 0 END AS is_p,
                    CASE WHEN FECHA_MOV >= ? AND FECHA_MOV < ?                  THEN 1 ELSE 0 END AS is_b
                FROM BI_SALES_PORC_INCREMENTAL WITH (NOLOCK)
                WHERE (FECHA_MOV >= ? AND FECHA_MOV < ?) OR (FECHA_MOV >= ? AND FECHA_MOV < ?)
            ) t
        ";
        $p4 = array_merge(
            [$da, $haX], $suc, $vend,
            [$dp, $hpX], $suc, $vend,
            [$da, $haX],
            [$da, $haX, $dp, $hpX]
        );
        $r4 = $this->queryOne($q4, $p4) ?? [];

        // ── Q5: Tabla objetivos ─────────────────────────────────────────────
        $q5 = "
            SELECT
                ISNULL(SUM(CASE WHEN is_a=1 THEN obj ELSE 0 END),0) AS obj_act,
                ISNULL(SUM(CASE WHEN is_p=1 THEN obj ELSE 0 END),0) AS obj_prev
            FROM (
                SELECT IMPORTE_OBJ AS obj,
                    CASE WHEN FECHA >= ? AND FECHA < ? {$sfSO} THEN 1 ELSE 0 END AS is_a,
                    CASE WHEN FECHA >= ? AND FECHA < ? {$sfSO} THEN 1 ELSE 0 END AS is_p
                FROM {$this->tablaObjetivos} WITH (NOLOCK)
                WHERE (FECHA >= ? AND FECHA < ?) OR (FECHA >= ? AND FECHA < ?)
            ) t
        ";
        $p5 = array_merge(
            [$da, $haX], $suc,
            [$dp, $hpX], $suc,
            [$da, $haX, $dp, $hpX]
        );
        $r5 = $this->queryOne($q5, $p5) ?? [];

        // ── Q6: sistemas.dbo.FP_GESTION_HORARIOS — planificacion horaria y ausencias ──
        $sfH_act = $nroSucurs !== null ? "m.nro_sucursal = ? AND" : "";
        $sfH_prev = $nroSucurs !== null ? "m.nro_sucursal = ? AND" : "";
        $q6 = "
            SELECT
                ISNULL(SUM(CASE WHEN is_a=1 THEN 1 ELSE 0 END),0) AS hrs_act,
                ISNULL(SUM(CASE WHEN is_a=1 AND TIPO_HORA='ausencia' THEN 1 ELSE 0 END),0) AS aus_act,
                ISNULL(SUM(CASE WHEN is_p=1 THEN 1 ELSE 0 END),0) AS hrs_prev,
                ISNULL(SUM(CASE WHEN is_p=1 AND TIPO_HORA='ausencia' THEN 1 ELSE 0 END),0) AS aus_prev,
                ISNULL(SUM(CASE WHEN is_b=1 THEN 1 ELSE 0 END),0) AS hrs_bench,
                ISNULL(SUM(CASE WHEN is_b=1 AND TIPO_HORA='ausencia' THEN 1 ELSE 0 END),0) AS aus_bench
            FROM (
                SELECT h.TIPO_HORA,
                    CASE WHEN {$sfH_act} h.FECHA >= ? AND h.FECHA < ? THEN 1 ELSE 0 END AS is_a,
                    CASE WHEN {$sfH_prev} h.FECHA >= ? AND h.FECHA < ? THEN 1 ELSE 0 END AS is_p,
                    CASE WHEN h.FECHA >= ? AND h.FECHA < ? THEN 1 ELSE 0 END AS is_b
                FROM sistemas.dbo.FP_GESTION_HORARIOS h WITH (NOLOCK)
                LEFT JOIN (
                    SELECT 
                        ID_DEPARTAMENTO AS id_depto,
                        CASE 
                            WHEN DESC_DEPARTAMENTO LIKE '%UNICENTER%' THEN 2
                            ELSE TRY_CAST(REPLACE(REPLACE(COD_DEPARTAMENTO, 'LOC', ''), 'loc', '') AS INT)
                        END AS nro_sucursal
                    FROM OPENQUERY([XL-SUELDOS], 'SELECT ID_DEPARTAMENTO, COD_DEPARTAMENTO, DESC_DEPARTAMENTO FROM LAKERS_CORP_SA.dbo.DEPARTAMENTO')
                    WHERE COD_DEPARTAMENTO LIKE 'LOC%'
                ) m ON h.NRO_SUCURSAL = m.id_depto
                WHERE (h.FECHA >= ? AND h.FECHA < ?) OR (h.FECHA >= ? AND h.FECHA < ?)
            ) t
        ";
        $p6 = [];
        if ($nroSucurs !== null) { $p6[] = $nroSucurs; }
        $p6[] = $da; $p6[] = $haX;
        if ($nroSucurs !== null) { $p6[] = $nroSucurs; }
        $p6[] = $dp; $p6[] = $hpX;
        $p6[] = $da; $p6[] = $haX;
        $p6[] = $da; $p6[] = $haX;
        $p6[] = $dp; $p6[] = $hpX;

        $r6 = $this->queryOne($q6, $p6) ?? [];

        $hrsAct = (float)($r6['hrs_act'] ?? 0);
        $ausAct = (float)($r6['aus_act'] ?? 0);
        $porcPresenciaAct = $hrsAct > 0 ? ($hrsAct - $ausAct) / $hrsAct : 1.0;

        $hrsPrev = (float)($r6['hrs_prev'] ?? 0);
        $ausPrev = (float)($r6['aus_prev'] ?? 0);
        $porcPresenciaPrev = $hrsPrev > 0 ? ($hrsPrev - $ausPrev) / $hrsPrev : 1.0;

        $hrsBench = (float)($r6['hrs_bench'] ?? 0);
        $ausBench = (float)($r6['aus_bench'] ?? 0);
        $porcPresenciaBench = $hrsBench > 0 ? ($hrsBench - $ausBench) / $hrsBench : 1.0;

        // ── Ensamblar resultados ────────────────────────────────────────────
        $make = function (
            $fact, $unid, $upos, $camb,
            $ticks, $suma,
            $tot, $t2, $t3,
            $ci, $dv, $obj,
            $porcPresencia = 1.0
        ): array {
            $fact  = (float)($fact  ?? 0);
            $unid  = (float)($unid  ?? 0);
            $upos  = (float)($upos  ?? 0);
            $camb  = (float)($camb  ?? 0);
            $ticks = (int)  ($ticks ?? 0);
            $suma  = (float)($suma  ?? 0);
            $tot   = (int)  ($tot   ?? 0);
            $t2    = (int)  ($t2    ?? 0);
            $t3    = (int)  ($t3    ?? 0);
            $ci    = (float)($ci    ?? 0);
            $dv    = (float)($dv    ?? 0);
            $obj   = (float)($obj   ?? 0);
            $porcPresencia = (float)($porcPresencia ?? 1.0);
            return [
                'facturacion'      => $fact,
                'unidades'         => $unid,
                'tickets'          => $ticks,
                'ticket_promedio'  => $ticks > 0 ? $suma / $ticks : 0.0,
                'porc_cambios'     => $upos  > 0 ? $camb / $upos  : 0.0,
                'objetivo'         => $obj,
                'cambios'          => $camb,
                'porc_2do'         => $ticks > 0 ? $t2   / $ticks : 0.0,
                'porc_3ro'         => $ticks > 0 ? $t3   / $ticks : 0.0,
                'porc_incremental' => $dv   != 0 ? ($ci  - $dv) / $dv : 0.0,
                'porc_presencia'   => $porcPresencia,
            ];
        };

        return [
            'actual' => $make(
                $r1['fact_act'],   $r1['unid_act'],   $r1['upos_act'],   $r1['camb_act'],
                $r2['tick_act'],   $r2['suma_act'],
                $r3['tot_act'],    $r3['t2_act'],      $r3['t3_act'],
                $r4['ci_act'],     $r4['dv_act'],      $r5['obj_act'],
                $porcPresenciaAct
            ),
            'previo' => $make(
                $r1['fact_prev'],  $r1['unid_prev'],  $r1['upos_prev'],  $r1['camb_prev'],
                $r2['tick_prev'],  $r2['suma_prev'],
                $r3['tot_prev'],   $r3['t2_prev'],     $r3['t3_prev'],
                $r4['ci_prev'],    $r4['dv_prev'],     $r5['obj_prev'],
                $porcPresenciaPrev
            ),
            'benchmark' => $make(
                $r1['fact_bench'], $r1['unid_bench'], $r1['upos_bench'], $r1['camb_bench'],
                $r2['tick_bench'], $r2['suma_bench'],
                $r3['tot_bench'],  $r3['t2_bench'],    $r3['t3_bench'],
                $r4['ci_bench'],   $r4['dv_bench'],    0.0,
                $porcPresenciaBench
            ),
        ];
    }

    /* ──────────────────────────────────────────────
     *  OBJETIVO MENSUAL TOTAL (para mes actual)
     * ────────────────────────────────────────────── */

    public function getObjetivo(string $da, string $ha, ?int $nroSucurs = null): float
    {
        $haX  = (new DateTime($ha))->modify('+1 day')->format('Y-m-d');
        $sfO  = $nroSucurs !== null ? 'AND NRO_SUCURSAL = ?' : '';
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $sql  = "SELECT ISNULL(SUM(IMPORTE_OBJ),0) AS obj
                 FROM {$this->tablaObjetivos} WITH (NOLOCK)
                 WHERE FECHA >= ? AND FECHA < ? {$sfO}";
        $row  = $this->queryOne($sql, array_merge([$da, $haX], $suc));
        return (float)($row['obj'] ?? 0);
    }

    /* ──────────────────────────────────────────────
     *  CONVERSIÓN (Tickets / Ingresos)
     * ────────────────────────────────────────────── */

    /**
     * Devuelve conversión para ambos períodos en 3 queries (vs 4 antes).
     * Reemplaza 2 llamadas a getConversion().
     * Usa JOIN en lugar de IN (SELECT) para los tickets filtrados por días con ingresos.
     *
     * @return array{actual:array, previo:array}
     */
    public function getConversionBoth(
        string $da, string $ha,
        string $dp, string $hp,
        ?int   $nroSucurs = null
    ): array {
        $haX = (new DateTime($ha))->modify('+1 day')->format('Y-m-d');
        $hpX = (new DateTime($hp))->modify('+1 day')->format('Y-m-d');
        $sfI = $nroSucurs !== null ? 'AND NRO_SUCURS = ?'   : '';
        $sfT = $nroSucurs !== null ? 'AND t.NRO_SUCURS = ?' : '';
        $suc = $nroSucurs !== null ? [$nroSucurs] : [];

        $condFechaHora = $this->isUruguay ? "" : "AND FECHA_HORA IS NOT NULL";
        // Q1: Ingresos de ambos períodos en una sola pasada
        $qI = "
            SELECT
                ISNULL(SUM(CASE WHEN FECHA >= ? AND FECHA < ? THEN INGRESOS ELSE 0 END),0) AS ing_act,
                ISNULL(SUM(CASE WHEN FECHA >= ? AND FECHA < ? THEN INGRESOS ELSE 0 END),0) AS ing_prev
            FROM BI_T_INGRESOS_SUCURSALES WITH (NOLOCK)
            WHERE ((FECHA >= ? AND FECHA < ?) OR (FECHA >= ? AND FECHA < ?))
              {$condFechaHora}
              {$sfI}
        ";
        $pI  = array_merge([$da, $haX, $dp, $hpX, $da, $haX, $dp, $hpX], $suc);
        $rI  = $this->queryOne($qI, $pI) ?? [];

        // Q2: Tickets actual — JOIN reemplaza IN (SELECT) lento
        $qTA = "
            SELECT COUNT(DISTINCT t.N_COMP) AS total_tickets
            FROM BI_SALES_TOTAL_TICKETS t WITH (NOLOCK)
            INNER JOIN (
                SELECT DISTINCT CAST(FECHA AS DATE) AS dia
                FROM BI_T_INGRESOS_SUCURSALES WITH (NOLOCK)
                WHERE FECHA >= ? AND FECHA < ? {$condFechaHora} {$sfI}
            ) dias ON CAST(t.FECHA AS DATE) = dias.dia
            WHERE t.FECHA >= ? AND t.FECHA < ?
              AND t.T_COMP = 'FAC' {$sfT}
        ";
        $pTA = array_merge([$da, $haX], $suc, [$da, $haX], $suc);
        $rTA = $this->queryOne($qTA, $pTA) ?? [];

        // Q3: Tickets previo
        $pTP = array_merge([$dp, $hpX], $suc, [$dp, $hpX], $suc);
        $rTP = $this->queryOne($qTA, $pTP) ?? [];  // misma query, distintos params

        $ingAct   = (int)($rI['ing_act']          ?? 0);
        $ingPrev  = (int)($rI['ing_prev']         ?? 0);
        $tickAct  = (int)($rTA['total_tickets']   ?? 0);
        $tickPrev = (int)($rTP['total_tickets']   ?? 0);

        return [
            'actual' => [
                'ingresos'   => $ingAct,
                'tickets'    => $tickAct,
                'conversion' => $ingAct > 0 ? $tickAct  / $ingAct  : 0.0,
            ],
            'previo' => [
                'ingresos'   => $ingPrev,
                'tickets'    => $tickPrev,
                'conversion' => $ingPrev > 0 ? $tickPrev / $ingPrev : 0.0,
            ],
        ];
    }

    /**
     * @deprecated Usar getConversionBoth() para mejor performance.
     */
    public function getConversion(string $desde, string $hasta, ?int $nroSucurs = null): array
    {
        $sfI  = $nroSucurs !== null ? "AND i.NRO_SUCURS = ?" : "";
        $sfT  = $nroSucurs !== null ? "AND t.NRO_SUCURS = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];

        $condFechaHora = $this->isUruguay ? "" : "AND i.FECHA_HORA IS NOT NULL";
        $condFechaHoraI2 = $this->isUruguay ? "" : "AND FECHA_HORA IS NOT NULL";
        $sqlI = "
            SELECT ISNULL(SUM(i.INGRESOS), 0) AS total_ingresos
            FROM BI_T_INGRESOS_SUCURSALES i WITH (NOLOCK)
            WHERE i.FECHA >= ? AND i.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$condFechaHora}
              {$sfI}
        ";
        $rowI = $this->queryOne($sqlI, array_merge([$desde, $hasta], $suc));

        $haX  = (new DateTime($hasta))->modify('+1 day')->format('Y-m-d');
        $sfI2 = $nroSucurs !== null ? 'AND NRO_SUCURS = ?' : '';
        $sqlT = "
            SELECT COUNT(DISTINCT t.N_COMP) AS total_tickets
            FROM BI_SALES_TOTAL_TICKETS t WITH (NOLOCK)
            INNER JOIN (
                SELECT DISTINCT CAST(FECHA AS DATE) AS dia
                FROM BI_T_INGRESOS_SUCURSALES WITH (NOLOCK)
                WHERE FECHA >= ? AND FECHA < ? {$condFechaHoraI2} {$sfI2}
            ) dias ON CAST(t.FECHA AS DATE) = dias.dia
            WHERE t.FECHA >= ? AND t.FECHA < ?
              AND t.T_COMP = 'FAC' {$sfT}
        ";
        $rowT = $this->queryOne($sqlT, array_merge([$desde, $haX], $suc, [$desde, $haX], $suc));

        $ingresos = (int)($rowI['total_ingresos'] ?? 0);
        $tickets  = (int)($rowT['total_tickets']  ?? 0);

        return [
            'ingresos'   => $ingresos,
            'tickets'    => $tickets,
            'conversion' => $ingresos > 0 ? $tickets / $ingresos : 0,
        ];
    }

    /* ──────────────────────────────────────────────
     *  FILTROS DISPONIBLES
     * ────────────────────────────────────────────── */

    /* ──────────────────────────────────────────────
     *  ÚLTIMA FECHA CON DATOS
     * ────────────────────────────────────────────── */

    public function getUltimaFecha(?int $nroSucurs = null): string
    {
        $from   = $this->fromVentasSucursales();
        $sf     = $nroSucurs !== null ? "WHERE NRO_SUCURS = ?" : "";
        $params = $nroSucurs !== null ? [$nroSucurs] : [];
        $sql    = "SELECT MAX(CAST(FECHA AS DATE)) AS ultima_fecha FROM {$from} s {$sf}";
        $row    = $this->queryOne($sql, $params);
        return ($row && $row['ultima_fecha']) ? $row['ultima_fecha']->format('Y-m-d') : '';
    }

    public function getVendedoresFiltro(?string $desde = null, ?string $hasta = null, ?int $nroSucurs = null): array
    {
        $from = $this->fromVentasSucursales();
        $cv  = $this->campoVendedor;
        $params = [];
        $where  = [];

        if ($desde !== null && $hasta !== null) {
            $where[]  = "FECHA BETWEEN ? AND ?";
            $params[] = $desde;
            $params[] = $hasta;
        }
        if ($nroSucurs !== null) {
            $where[]  = "NRO_SUCURS = ?";
            $params[] = $nroSucurs;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Deduplicar por el campo usado como valor del filtro (nombre o código).
        // Si hay dos códigos para el mismo nombre, aparece una sola opción.
        $sql = "
            SELECT DISTINCT {$cv}
            FROM {$from} s
            {$whereClause}
            ORDER BY {$cv}
        ";
        return $this->query($sql, $params);
    }

    public function getRubrosFiltro(string $desde, string $hasta, ?int $nroSucurs = null): array
    {
        $from = $this->fromVentasSucursales();
        $sfS = $nroSucurs !== null ? "AND NRO_SUCURS = ?" : "";
        $suc = $nroSucurs !== null ? [$nroSucurs] : [];
        $sql = "
            SELECT DISTINCT RUBRO
            FROM {$from} s
            WHERE FECHA BETWEEN ? AND ?
              AND RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfS}
            ORDER BY RUBRO
        ";
        return $this->query($sql, array_merge([$desde, $hasta], $suc));
    }

    public function getConversionPorHora(
        string $desde, string $hasta,
        ?int $nroSucurs = null
    ): array {
        if ($this->isUruguay) {
            return [];
        }
        $haX = (new DateTime($hasta))->modify('+1 day')->format('Y-m-d');
        $sfI = $nroSucurs !== null ? 'AND NRO_SUCURS = ?'   : '';
        $sfT = $nroSucurs !== null ? 'AND t.NRO_SUCURS = ?' : '';
        $suc = $nroSucurs !== null ? [$nroSucurs] : [];

        // Ingresos agrupados por hora
        $ingRows = $this->query("
            SELECT
                DATEPART(HOUR, i.FECHA_HORA) AS hora,
                ISNULL(SUM(i.INGRESOS), 0) AS ingresos
            FROM BI_T_INGRESOS_SUCURSALES i WITH (NOLOCK)
            WHERE i.FECHA >= ? AND i.FECHA < ?
              AND i.FECHA_HORA IS NOT NULL
              AND i.INGRESOS > 0 {$sfI}
            GROUP BY DATEPART(HOUR, i.FECHA_HORA)
        ", array_merge([$desde, $haX], $suc));

        $ingByHour = array_fill(0, 24, 0);
        $totalIng = 0;
        foreach ($ingRows as $r) {
            $h = (int)$r['hora'];
            if ($h >= 0 && $h < 24) {
                $ingByHour[$h] = (int)$r['ingresos'];
                $totalIng += (int)$r['ingresos'];
            }
        }

        if ($totalIng === 0) return [];   // sin datos

        // Tickets agrupados por hora
        $tickRows = $this->query("
            SELECT
                (t.HORA_EMIS / 10000) AS hora,
                COUNT(DISTINCT t.N_COMP) AS tickets
            FROM BI_SALES_TOTAL_TICKETS t WITH (NOLOCK)
            WHERE t.FECHA >= ? AND t.FECHA < ?
              AND t.T_COMP = 'FAC'
              AND t.HORA_EMIS IS NOT NULL AND t.HORA_EMIS >= 0
              {$sfT}
              AND EXISTS (
                  SELECT 1 FROM BI_T_INGRESOS_SUCURSALES i2 WITH (NOLOCK)
                  WHERE i2.NRO_SUCURS = t.NRO_SUCURS
                    AND CAST(i2.FECHA AS DATE) = CAST(t.FECHA AS DATE)
                    AND i2.FECHA >= ? AND i2.FECHA < ?
                    AND i2.FECHA_HORA IS NOT NULL
                    AND i2.INGRESOS > 0
              )
            GROUP BY (t.HORA_EMIS / 10000)
        ", array_merge([$desde, $haX], $suc, [$desde, $haX]));

        $tickByHour = array_fill(0, 24, 0);
        foreach ($tickRows as $r) {
            $h = (int)$r['hora'];
            if ($h >= 0 && $h < 24) $tickByHour[$h] = (int)$r['tickets'];
        }

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = [
                'hora'       => $h,
                'label'      => str_pad($h, 2, '0', STR_PAD_LEFT) . 'h',
                'tickets'    => $tickByHour[$h],
                'ingresos'   => $ingByHour[$h],
                'conversion' => $ingByHour[$h] > 0 ? $tickByHour[$h] / $ingByHour[$h] : 0,
            ];
        }
        return $result;
    }

    /** Obtiene la última fecha/hora de actualización de los datos */
    public function getUltimaActualizacion(): ?string
    {
        $sql = "SELECT MAX(last_update) as last_update FROM (
                    SELECT MAX(last_user_update) as last_update
                    FROM sys.dm_db_index_usage_stats
                    WHERE database_id = DB_ID()
                      AND object_id = OBJECT_ID('dbo.BI_SALES_SUCURSALES')
                ) t";
        $stmt = sqlsrv_query($this->conn, $sql);
        $res = null;
        if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (!empty($row['last_update'])) {
                $res = is_object($row['last_update']) ? $row['last_update']->format('Y-m-d H:i:s') : $row['last_update'];
            }
            sqlsrv_free_stmt($stmt);
        }

        // Fallback: si por alguna razón no tenemos estadísticas, usamos el max(FECHA) de BI_SALES_SUCURSALES
        if (!$res) {
            $sqlFallback = "SELECT MAX(FECHA) as last_update FROM dbo.BI_SALES_SUCURSALES";
            $stmtFallback = sqlsrv_query($this->conn, $sqlFallback);
            if ($stmtFallback !== false && $rowFallback = sqlsrv_fetch_array($stmtFallback, SQLSRV_FETCH_ASSOC)) {
                if (!empty($rowFallback['last_update'])) {
                    $res = is_object($rowFallback['last_update']) ? $rowFallback['last_update']->format('Y-m-d H:i:s') : $rowFallback['last_update'];
                }
                sqlsrv_free_stmt($stmtFallback);
            }
        }
        return $res;
    }
}
