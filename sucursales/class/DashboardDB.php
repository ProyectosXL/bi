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

    public function __construct()
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
        $config = getConfig();
        $this->cid            = new Conexion();
        $this->conn           = $this->cid->conectar($config['db']);
        $this->campoVendedor  = $config['campo_vendedor'];
        $this->tablaObjetivos = $config['tabla_objetivos'];
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

    /* ──────────────────────────────────────────────
     *  FILTROS DE PERÍODO
     *  Devuelve [desde_actual, hasta_actual, desde_previo, hasta_previo]
     * ────────────────────────────────────────────── */

    public static function calcularPeriodo(string $tipo): array
    {
        $tz    = new DateTimeZone('America/Argentina/Buenos_Aires');
        $hoy   = new DateTime('today', $tz);
        $ayer  = (clone $hoy)->modify('-1 day');

        switch ($tipo) {
            case 'ayer':
                $da = $dp = $ayer->format('Y-m-d');
                $ha = $dp;
                $hp = (clone $ayer)->modify('-1 day')->format('Y-m-d');
                return [$da, $ha, $hp, $hp];

            case '7':
                $da = (clone $ayer)->modify('-6 days')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                $hp = (clone $ayer)->modify('-7 days')->format('Y-m-d');
                $dp = (clone $ayer)->modify('-13 days')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case '30':
                $da = (clone $ayer)->modify('-29 days')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                $hp = (clone $ayer)->modify('-30 days')->format('Y-m-d');
                $dp = (clone $ayer)->modify('-59 days')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case '90':
                $da = (clone $ayer)->modify('-89 days')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                $hp = (clone $ayer)->modify('-90 days')->format('Y-m-d');
                $dp = (clone $ayer)->modify('-179 days')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case '180':
                $da = (clone $ayer)->modify('-179 days')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                $hp = (clone $ayer)->modify('-180 days')->format('Y-m-d');
                $dp = (clone $ayer)->modify('-359 days')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case 'mes_actual':
                $da = (clone $hoy)->modify('first day of this month')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                // mismo mes año anterior hasta misma cantidad de días
                $da_p = (clone $hoy)->modify('first day of this month')->modify('-1 year')->format('Y-m-d');
                $ha_p = (clone $ayer)->modify('-1 year')->format('Y-m-d');
                return [$da, $ha, $da_p, $ha_p];

            case 'mes_pasado':
                $primero  = (clone $hoy)->modify('first day of last month');
                $ultimo   = (clone $hoy)->modify('last day of last month');
                $dias     = (int)$ultimo->format('d');
                $da = $primero->format('Y-m-d');
                $ha = $ultimo->format('Y-m-d');
                $primero_p = (clone $primero)->modify('-1 year');
                $da_p = $primero_p->format('Y-m-d');
                $ha_p = (clone $primero_p)->modify('+' . ($dias - 1) . ' days')->format('Y-m-d');
                return [$da, $ha, $da_p, $ha_p];

            case 'año_actual':
                $da   = (clone $hoy)->modify('first day of january this year')->format('Y-m-d');
                $ha   = $ayer->format('Y-m-d');
                $da_p = (clone $hoy)->modify('first day of january last year')->format('Y-m-d');
                $ha_p = (clone $ayer)->modify('-1 year')->format('Y-m-d');
                return [$da, $ha, $da_p, $ha_p];

            case 'año_pasado':
                $da   = (clone $hoy)->modify('first day of january last year')->format('Y-m-d');
                $ha   = (clone $hoy)->modify('last day of december last year')->format('Y-m-d');
                $da_p = (clone $hoy)->modify('first day of january')->modify('-2 years')->format('Y-m-d');
                $ha_p = (clone $hoy)->modify('last day of december')->modify('-2 years')->format('Y-m-d');
                return [$da, $ha, $da_p, $ha_p];

            default:
                // custom: espera desde|hasta
                return [];
        }
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
            FROM BI_SALES_SUCURSALES s
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

        // Incluir DESC_VENDEDOR en SELECT y GROUP BY solo cuando es el campo de vendedor
        $selectDescVend  = $cv === 'DESC_VENDEDOR' ? "s.DESC_VENDEDOR," : "";
        $groupByDescVend = $cv === 'DESC_VENDEDOR' ? ", s.DESC_VENDEDOR" : "";

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
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfS} {$sfVS} {$sfRS}
            GROUP BY s.COD_VENDED{$groupByDescVend}
        ";
        $ventas = $this->query($sql, array_merge([$desde, $hasta], $suc, $vend, $rub));

        // Tickets por vendedor
        $sqlT = "
            SELECT
                t.COD_VENDED,
                COUNT(DISTINCT t.N_COMP)                          AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0)               AS suma_ticket,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 1 THEN t.N_COMP END) AS tickets_2do,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 2 THEN t.N_COMP END) AS tickets_3ro
            FROM BI_SALES_TOTAL_TICKETS t
            LEFT JOIN BI_SALES_TICKETS tk ON t.N_COMP = tk.N_COMP
                AND tk.FECHA >= ? AND tk.FECHA < DATEADD(day, 1, CAST(? AS DATE))
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              AND t.T_COMP = 'FAC'
              {$sfT} {$sfVT}
            GROUP BY t.COD_VENDED
        ";
        $tickets = $this->query($sqlT, array_merge([$desde, $hasta, $desde, $hasta], $suc, $vend));
        $tickMap = [];
        foreach ($tickets as $t) {
            $tickMap[$t['COD_VENDED']] = $t;
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

        $result = [];
        foreach ($ventas as $v) {
            $cod      = $v['COD_VENDED'];
            $ticks    = $tickMap[$cod] ?? [];
            $inc      = $incrMap[$cod] ?? [];
            $tickets_ = (int)($ticks['tickets'] ?? 0);
            $sumaT    = (float)($ticks['suma_ticket'] ?? 0);
            $t2       = (int)($ticks['tickets_2do'] ?? 0);
            $t3       = (int)($ticks['tickets_3ro'] ?? 0);
            $cambI    = (float)($inc['cambios_incr'] ?? 0);
            $devol    = (float)($inc['devoluciones'] ?? 0);
            $unidades = (float)$v['unidades'];
            $unidadesPos = (float)$v['unidades_positivas'];
            $cambios = (float)$v['cambios'];

            // Etiqueta del vendedor según el campo configurado
            $labelVendedor = $cv === 'DESC_VENDEDOR'
                ? ($v['DESC_VENDEDOR'] ?? $v['COD_VENDED'])
                : $v['COD_VENDED'];

            $result[] = [
                'vendedor'        => $labelVendedor,
                'unidades'        => $unidades,
                'facturacion'     => (float)$v['facturacion'],
                'tickets'         => $tickets_,
                'ticket_promedio' => $tickets_ > 0 ? $sumaT / $tickets_ : 0,
                'porc_2do'        => $tickets_ > 0 ? $t2 / $tickets_ : 0,
                'porc_3ro'        => $tickets_ > 0 ? $t3 / $tickets_ : 0,
                'porc_cambios'    => $unidadesPos > 0 ? $cambios / $unidadesPos : 0,
                'porc_incremental'=> $devol != 0 ? ($cambI - $devol) / $devol : 0,
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
        $sql = "
            SELECT
                s.RUBRO,
                ISNULL(SUM(s.CANTIDAD), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
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
        $sql = "
            SELECT
                s.CATEGORIA AS RUBRO,
                ISNULL(SUM(s.CANTIDAD), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
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

    public function getSerieFacturacion(string $desde, string $hasta, ?int $nroSucurs = null, string $vendedor = '%', string $rubro = '%'): array
    {
        $cv   = $this->campoVendedor;
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor !== '%' ? [$vendedor] : [];
        $rub  = $rubro !== '%' ? [$rubro] : [];

        // Serie principal: facturación, unidades y cambios por fecha
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor !== '%' ? "AND s.{$cv} = ?" : "";
        $sfRS = $rubro !== '%' ? "AND s.RUBRO = ?" : "";
        $sql = "
            SELECT
                CAST(s.FECHA AS DATE) AS fecha,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion,
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(CASE WHEN s.CANTIDAD > 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) AS unidades_positivas,
                ISNULL(SUM(CASE WHEN s.CANTIDAD < 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END) * -1, 0) AS cambios
            FROM BI_SALES_SUCURSALES s
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
            FROM BI_SALES_TOTAL_TICKETS t
            LEFT JOIN BI_SALES_TICKETS tk
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
            FROM BI_SALES_PORC_INCREMENTAL p
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
        $sqlIn = "
            SELECT
                CAST(ig.FECHA AS DATE) AS fecha,
                ISNULL(SUM(ig.INGRESOS), 0) AS ingresos
            FROM BI_T_INGRESOS_SUCURSALES ig
            WHERE ig.FECHA >= ? AND ig.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfIn}
            GROUP BY CAST(ig.FECHA AS DATE)
        ";
        $ingresosData = $this->query($sqlIn, array_merge([$desde, $hasta], $suc));
        $ingresosMap  = [];
        foreach ($ingresosData as $ig) {
            $ingresosMap[$ig['fecha']->format('Y-m-d')] = (int)$ig['ingresos'];
        }

        // Combinar todos los datos
        $result = [];
        foreach ($rows as $row) {
            $fecha = $row['fecha']->format('Y-m-d');
            $tickInfo = $ticketsMap[$fecha] ?? null;
            $incrInfo = $incrMap[$fecha] ?? null;

            $tickets = (int)($tickInfo['tickets'] ?? 0);
            $sumaTickets = (float)($tickInfo['suma_tickets'] ?? 0);
            $tickets2do = (int)($tickInfo['tickets_2do'] ?? 0);
            $tickets3ro = (int)($tickInfo['tickets_3ro'] ?? 0);
            $cambiosIncr = (float)($incrInfo['cambios_incr'] ?? 0);
            $devoluciones = (float)($incrInfo['devoluciones'] ?? 0);

            $unidades = (float)$row['unidades'];
            $unidadesPos = (float)$row['unidades_positivas'];
            $cambios = (float)$row['cambios'];

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
            ];
        }

        return $result;
    }

    /* ──────────────────────────────────────────────
     *  CONVERSIÓN (Tickets / Ingresos)
     * ────────────────────────────────────────────── */

    /**
     * Devuelve ingresos totales, tickets totales y tasa de conversión
     * para el período indicado. No filtra por vendedor/rubro porque
     * los ingresos son un dato de sucursal completa.
     */
    public function getConversion(string $desde, string $hasta, ?int $nroSucurs = null): array
    {
        $sfI = $nroSucurs !== null ? "AND i.NRO_SUCURS = ?" : "";
        $sfT = $nroSucurs !== null ? "AND t.NRO_SUCURS = ?" : "";
        $suc = $nroSucurs !== null ? [$nroSucurs] : [];

        $sqlI = "
            SELECT ISNULL(SUM(i.INGRESOS), 0) AS total_ingresos
            FROM BI_T_INGRESOS_SUCURSALES i
            WHERE i.FECHA >= ? AND i.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              {$sfI}
        ";
        $rowI = $this->queryOne($sqlI, array_merge([$desde, $hasta], $suc));

        $sqlT = "
            SELECT COUNT(DISTINCT t.N_COMP) AS total_tickets
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day, 1, CAST(? AS DATE))
              AND t.T_COMP = 'FAC'
              {$sfT}
        ";
        $rowT = $this->queryOne($sqlT, array_merge([$desde, $hasta], $suc));

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

    public function getVendedoresFiltro(string $desde, string $hasta, ?int $nroSucurs = null): array
    {
        $cv  = $this->campoVendedor;
        $sfS = $nroSucurs !== null ? "AND NRO_SUCURS = ?" : "";
        $suc = $nroSucurs !== null ? [$nroSucurs] : [];

        // Incluir DESC_VENDEDOR en el SELECT solo cuando es el campo de vendedor
        $extraSelect = $cv === 'DESC_VENDEDOR' ? ", DESC_VENDEDOR" : "";

        $sql = "
            SELECT DISTINCT COD_VENDED{$extraSelect}
            FROM BI_SALES_SUCURSALES
            WHERE FECHA BETWEEN ? AND ?
              {$sfS}
            ORDER BY {$cv}
        ";
        return $this->query($sql, array_merge([$desde, $hasta], $suc));
    }

    public function getRubrosFiltro(string $desde, string $hasta, ?int $nroSucurs = null): array
    {
        $sfS = $nroSucurs !== null ? "AND NRO_SUCURS = ?" : "";
        $suc = $nroSucurs !== null ? [$nroSucurs] : [];
        $sql = "
            SELECT DISTINCT RUBRO
            FROM BI_SALES_SUCURSALES
            WHERE FECHA BETWEEN ? AND ?
              AND RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfS}
            ORDER BY RUBRO
        ";
        return $this->query($sql, array_merge([$desde, $hasta], $suc));
    }
}
