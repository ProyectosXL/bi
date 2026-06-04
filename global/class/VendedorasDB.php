<?php
/**
 * VendedorasDB
 * Top vendedoras y comparación head-to-head.
 */
class VendedorasDB
{
    private $conn;
    private string $campoVendedor;
    private string $origen;

    public function __construct(string $origen = 'argentina')
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Filters.php';

        $cfg = getConfigForOrigen($origen);
        $this->origen        = $origen;
        $this->campoVendedor = $cfg['campo_vendedor'];

        $cid        = new Conexion();
        $this->conn = $cid->conectar($cfg['db']);
    }

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

    private function grupoFiltro(string $alias): array
    {
        if (($_SESSION['tipo'] ?? '') !== 'GRUPO') return ['', []];
        $suc = $_SESSION['sucursalesGrupo'] ?? [];
        return Filters::sucursalesGrupo($suc, $alias);
    }

    private function buildFilter(?int $sucursal, ?string $grupo, ?string $tipoTienda, string $alias, ?string $canal = null, bool $tieneCanal = true): array
    {
        $clauses = [];
        $params  = [];
        if ($sucursal !== null) {
            $clauses[] = "{$alias}.NRO_SUCURS = ?";
            $params[]  = $sucursal;
        }
        if ($grupo !== null && $this->origen === 'argentina') {
            $clauses[] = "{$alias}.NRO_SUCURS IN (SELECT g.NRO_SUCURS FROM BI_DIM_SUCURSALES_GRUPO g WHERE g.GRUPO = ?)";
            $params[]  = $grupo;
        }
        if ($tipoTienda !== null && $this->origen === 'argentina') {
            $clauses[] = "{$alias}.NRO_SUCURS IN (SELECT sl.NRO_SUCURSAL FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WHERE sl.TIPO_TIENDA = ?)";
            $params[]  = $tipoTienda;
        }
        if (!empty($canal) && $this->origen === 'argentina') {
            if ($tieneCanal) {
                if ($canal === 'PROPIOS') $clauses[] = "{$alias}.CANAL = 'LOCALES PROPIOS'";
                elseif ($canal === 'ECOMMERCE') $clauses[] = "{$alias}.CANAL = 'ECOMMERCE'";
            } else {
                if ($canal === 'PROPIOS') $clauses[] = "{$alias}.NRO_SUCURS NOT IN (1, 9)";
                elseif ($canal === 'ECOMMERCE') $clauses[] = "{$alias}.NRO_SUCURS IN (1, 9)";
            }
        }
        $sql = $clauses ? 'AND ' . implode(' AND ', $clauses) : '';
        return [$sql, $params];
    }

    /**
     * KPIs completos por vendedora para el período.
     * Devuelve todos los vendedores con sus métricas, ordenados por facturación.
     */
    public function getKPIsVendedoras(
        string $desde, string $hasta,
        ?int $sucursal = null, string $rubro = '%',
        ?string $grupo = null, ?string $tipoTienda = null, ?string $canal = null
    ): array {
        $cv = $this->campoVendedor;
        [$sfS, $pS] = $this->buildFilter($sucursal, $grupo, $tipoTienda, 's', $canal);
        [$sfT, $pT] = $this->buildFilter($sucursal, $grupo, $tipoTienda, 't', $canal);
        [$sfP, $pP] = $this->buildFilter($sucursal, $grupo, $tipoTienda, 'p', $canal, false);

        [$sfGS, $pGS] = $this->grupoFiltro('s');
        $sfS .= ' ' . $sfGS; $pS = array_merge($pS, $pGS);
        [$sfGT, $pGT] = $this->grupoFiltro('t');
        $sfT .= ' ' . $sfGT; $pT = array_merge($pT, $pGT);
        [$sfGP, $pGP] = $this->grupoFiltro('p');
        $sfP .= ' ' . $sfGP; $pP = array_merge($pP, $pGP);
        $sfRS = $rubro !== '%' ? "AND s.RUBRO = ?" : "";
        $rub  = $rubro !== '%' ? [$rubro] : [];

        // Ventas por vendedora
        $rowsVentas = $this->query("
            SELECT
                s.COD_VENDED, s.{$cv} AS nombre,
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(CASE WHEN s.CANTIDAD > 0
                                 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END), 0) AS unidades_pos,
                ISNULL(SUM(CASE WHEN s.CANTIDAD < 0
                                 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                            THEN s.CANTIDAD ELSE 0 END) * -1, 0) AS cambios,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
              {$sfS} {$sfRS}
            GROUP BY s.COD_VENDED, s.{$cv}
        ", array_merge([$desde, $hasta], $pS, $rub));

        // Tickets por vendedora
        $rowsTickets = $this->query("
            SELECT
                t.COD_VENDED,
                COUNT(DISTINCT t.N_COMP) AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_ticket,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 1 THEN t.N_COMP END) AS tickets_2do,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 2 THEN t.N_COMP END) AS tickets_3ro
            FROM BI_SALES_TOTAL_TICKETS t
            LEFT JOIN BI_SALES_TICKETS tk ON t.N_COMP = tk.N_COMP
                AND tk.FECHA >= ? AND tk.FECHA < DATEADD(day,1,CAST(? AS DATE))
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT}
            GROUP BY t.COD_VENDED
        ", array_merge([$desde, $hasta, $desde, $hasta], $pT));

        // Incremental por vendedora
        $rowsIncr = $this->query("
            SELECT
                p.COD_VENDED,
                ISNULL(SUM(p.CAMBIO), 0) AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0) AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day,1,CAST(? AS DATE))
              {$sfP}
            GROUP BY p.COD_VENDED
        ", array_merge([$desde, $hasta], $pP));

        // Presencialidad por vendedora (con filtros)
        $sfH = "";
        $pH = [$desde, $hasta];
        if ($sucursal !== null) {
            $sfH .= " AND m.nro_sucursal = ?";
            $pH[] = $sucursal;
        }
        if ($grupo !== null && $this->origen === 'argentina') {
            $sfH .= " AND m.nro_sucursal IN (SELECT g.NRO_SUCURS FROM BI_DIM_SUCURSALES_GRUPO g WHERE g.GRUPO = ?)";
            $pH[] = $grupo;
        }
        if ($tipoTienda !== null && $this->origen === 'argentina') {
            $sfH .= " AND m.nro_sucursal IN (SELECT sl.NRO_SUCURSAL FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WHERE sl.TIPO_TIENDA = ?)";
            $pH[] = $tipoTienda;
        }
        if (!empty($canal) && $this->origen === 'argentina') {
            if ($canal === 'PROPIOS') $sfH .= " AND m.nro_sucursal NOT IN (1, 9)";
            elseif ($canal === 'ECOMMERCE') $sfH .= " AND m.nro_sucursal IN (1, 9)";
        }
        $rowsPresence = [];
        try {
            $rowsPresence = $this->query("
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
            ", $pH);
        } catch (Throwable $_) {}

        $tickMap = [];
        foreach ($rowsTickets as $t) {
            $tickMap[$t['COD_VENDED']] = $t;
        }
        $incrMap = [];
        foreach ($rowsIncr as $i) {
            $incrMap[$i['COD_VENDED']] = $i;
        }
        $presenceMap = [];
        foreach ($rowsPresence as $pr) {
            $presenceMap[(string)$pr['COD_VENDED']] = $pr;
        }

        // Consolidar por nombre (varios COD_VENDED pueden ser misma persona)
        $agg = [];
        foreach ($rowsVentas as $v) {
            $cod = $v['COD_VENDED'];
            $key = $v['nombre'] ?? $cod;
            if (!isset($agg[$key])) {
                $agg[$key] = ['label' => $key, 'unidades' => 0, 'unidades_pos' => 0, 'cambios' => 0, 'facturacion' => 0, '_codes' => []];
            }
            $agg[$key]['unidades']    += (float)$v['unidades'];
            $agg[$key]['unidades_pos'] += (float)$v['unidades_pos'];
            $agg[$key]['cambios']     += (float)$v['cambios'];
            $agg[$key]['facturacion'] += (float)$v['facturacion'];
            $agg[$key]['_codes'][]     = $cod;
        }

        $result = [];
        foreach ($agg as $key => $a) {
            $ticks = 0; $sumaT = 0; $t2 = 0; $t3 = 0; $cambI = 0; $devol = 0;
            $totHrs = 0.0; $ausHrs = 0.0;
            foreach ($a['_codes'] as $cod) {
                $tk = $tickMap[$cod] ?? [];
                $in = $incrMap[$cod] ?? [];
                $pr = $presenceMap[(string)$cod] ?? [];
                $ticks += (int)($tk['tickets']     ?? 0);
                $sumaT += (float)($tk['suma_ticket'] ?? 0);
                $t2    += (int)($tk['tickets_2do']  ?? 0);
                $t3    += (int)($tk['tickets_3ro']  ?? 0);
                $cambI += (float)($in['cambios_incr'] ?? 0);
                $devol += (float)($in['devoluciones'] ?? 0);
                $totHrs += (float)($pr['total_horas'] ?? 0);
                $ausHrs += (float)($pr['horas_ausencia'] ?? 0);
            }
            $porcPresencia = $totHrs > 0 ? ($totHrs - $ausHrs) / $totHrs : null;

            $result[] = [
                'vendedora'        => $a['label'],
                'facturacion'      => $a['facturacion'],
                'unidades'         => $a['unidades'],
                'tickets'          => $ticks,
                'ticket_promedio'  => $ticks > 0 ? $sumaT / $ticks : 0,
                'porc_2do'         => $ticks > 0 ? $t2 / $ticks : 0,
                'porc_3ro'         => $ticks > 0 ? $t3 / $ticks : 0,
                'porc_cambios'     => $a['unidades_pos'] > 0 ? $a['cambios'] / $a['unidades_pos'] : 0,
                'porc_incremental' => $devol != 0 ? ($cambI - $devol) / $devol : 0,
                'porc_presencia'   => $porcPresencia,
            ];
        }

        usort($result, fn($a, $b) => $b['facturacion'] <=> $a['facturacion']);
        return $result;
    }

    /**
     * Top 10 vendedoras por métrica especificada.
     * $metrica: 'facturacion'|'unidades'|'tickets'|'ticket_promedio'|'porc_2do'
     */
    public function getTop10(
        string $desde, string $hasta, string $metrica = 'facturacion',
        ?int $sucursal = null, ?string $grupo = null, ?string $tipoTienda = null, ?string $canal = null
    ): array {
        $all = $this->getKPIsVendedoras($desde, $hasta, $sucursal, '%', $grupo, $tipoTienda, $canal);
        usort($all, fn($a, $b) => $b[$metrica] <=> $a[$metrica]);
        return array_slice($all, 0, 10);
    }

    /**
     * Datos de dos vendedoras para el versus.
     */
    public function getVersusVendedoras(
        string $desde, string $hasta,
        string $vendedora_a, string $vendedora_b,
        ?int $sucursal = null, ?string $grupo = null, ?string $tipoTienda = null, ?string $canal = null
    ): array {
        $all = $this->getKPIsVendedoras($desde, $hasta, $sucursal, '%', $grupo, $tipoTienda, $canal);
        $map = [];
        foreach ($all as $v) {
            $map[$v['vendedora']] = $v;
        }
        return [
            'a' => $map[$vendedora_a] ?? null,
            'b' => $map[$vendedora_b] ?? null,
        ];
    }

    /**
     * Lista de vendedoras disponibles para el selector del versus.
     */
    public function getListaVendedoras(
        string $desde, string $hasta,
        ?int $sucursal = null, ?string $grupo = null, ?string $tipoTienda = null, ?string $canal = null
    ): array {
        $cv = $this->campoVendedor;
        [$sfS, $pS] = $this->buildFilter($sucursal, $grupo, $tipoTienda, 's', $canal);

        return $this->query("
            SELECT DISTINCT s.{$cv} AS nombre
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
              {$sfS}
            ORDER BY s.{$cv}
        ", array_merge([$desde, $hasta], $pS));
    }
}
