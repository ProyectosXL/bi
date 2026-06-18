<?php
/**
 * CadenaDB
 * KPIs consolidados por sucursal para la pestaña "Cadena".
 */
class CadenaDB
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

        $cfg = getConfigForOrigen($origen);
        $this->origen         = $origen;
        $this->campoVendedor  = $cfg['campo_vendedor'];
        $this->tablaObjetivos = $cfg['tabla_objetivos'];

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

    private function grupoFiltro(string $alias, string $col = 'NRO_SUCURS'): array
    {
        if (($_SESSION['tipo'] ?? '') !== 'GRUPO') return ['', []];
        $suc = $_SESSION['sucursalesGrupo'] ?? [];
        return Filters::sucursalesGrupo($suc, $alias, $col);
    }

    private function buildGrupoTipoFilter(string $alias, ?string $grupo, ?string $tipoTienda, ?string $canal = null, bool $tieneCanal = true): array
    {
        $clauses = [];
        $params  = [];
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
        if ($this->origen === 'franquicias') {
            $tipoLocal = $_GET['tipo_local'] ?? null;
            if ($tipoLocal !== null && $tipoLocal !== '') {
                $clauses[] = "{$alias}.NRO_SUCURS IN (SELECT sl.NRO_SUCURSAL FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WHERE sl.TIPO_LOCAL = ?)";
                $params[]  = $tipoLocal;
            }
            $zona = $_GET['zona'] ?? null;
            if ($zona !== null && $zona !== '') {
                $clauses[] = "{$alias}.NRO_SUCURS IN (SELECT DISTINCT s_z.NRO_SUCURS FROM BI_SALES_SUCURSALES s_z WHERE s_z.ZONA = ?)";
                $params[]  = $zona;
            }
            $grupoEmpresario = $_GET['grupo_empresario'] ?? null;
            if ($grupoEmpresario !== null && $grupoEmpresario !== '') {
                $clauses[] = "{$alias}.NRO_SUCURS IN (SELECT DISTINCT s_g.NRO_SUCURS FROM BI_SALES_SUCURSALES s_g WHERE s_g.GRUPO_EMPRESARIO = ?)";
                $params[]  = $grupoEmpresario;
            }
        }
        $sql = $clauses ? 'AND ' . implode(' AND ', $clauses) : '';
        return [$sql, $params];
    }

    /**
     * Devuelve KPIs completos por sucursal para el período actual y previo.
     * Incluye: facturación, % cumplimiento objetivo, unidades, tickets,
     * ticket promedio, % cambios, % incremental, tickets 2do, 3er producto.
     */
    public function getKPIsPorSucursal(
        string $desde_act, string $hasta_act,
        string $desde_prev, string $hasta_prev,
        ?string $grupo = null, ?string $tipoTienda = null, ?string $canal = null
    ): array {
        [$sfG, $pG]   = $this->buildGrupoTipoFilter('s', $grupo, $tipoTienda, $canal);
        [$sfGS, $pGS] = $this->grupoFiltro('s');
        $sfG .= ' ' . $sfGS;
        $pG   = array_merge($pG, $pGS);

        // Facturación y unidades por sucursal (actual y previo)
        $rowsVentas = $this->query("
            SELECT
                s.NRO_SUCURS,
                ISNULL(SUM(CASE WHEN s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                THEN s.IMPORTE ELSE 0 END), 0) AS fact_act,
                ISNULL(SUM(CASE WHEN s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                THEN s.IMPORTE ELSE 0 END), 0) AS fact_prev,
                ISNULL(SUM(CASE WHEN s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                THEN s.CANTIDAD ELSE 0 END), 0) AS unid_act,
                ISNULL(SUM(CASE WHEN s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                THEN s.CANTIDAD ELSE 0 END), 0) AS unid_prev,
                ISNULL(SUM(CASE WHEN s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                 AND s.CANTIDAD > 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                THEN s.CANTIDAD ELSE 0 END), 0) AS unid_pos_act,
                ISNULL(SUM(CASE WHEN s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                 AND s.CANTIDAD < 0 AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                THEN s.CANTIDAD ELSE 0 END) * -1, 0) AS cambios_act
            FROM BI_SALES_SUCURSALES s
            WHERE (
                (s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)))
                OR (s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE)))
            ) {$sfG}
            GROUP BY s.NRO_SUCURS
        ", array_merge(
            [$desde_act, $hasta_act, $desde_prev, $hasta_prev,
             $desde_act, $hasta_act, $desde_prev, $hasta_prev,
             $desde_act, $hasta_act, $desde_act, $hasta_act,
             $desde_act, $hasta_act, $desde_prev, $hasta_prev],
            $pG
        ));

        // Tickets por sucursal
        [$sfGT, $pGT]   = $this->buildGrupoTipoFilter('t', $grupo, $tipoTienda, $canal);
        [$sfGGT, $pGGT] = $this->grupoFiltro('t');
        $sfGT .= ' ' . $sfGGT;
        $pGT   = array_merge($pGT, $pGGT);
        $rowsTickets = $this->query("
            SELECT
                t.NRO_SUCURS,
                COUNT(DISTINCT CASE WHEN t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                     AND t.T_COMP = 'FAC' THEN t.N_COMP END) AS tick_act,
                COUNT(DISTINCT CASE WHEN t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                     AND t.T_COMP = 'FAC' THEN t.N_COMP END) AS tick_prev,
                ISNULL(SUM(CASE WHEN t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                 AND t.T_COMP = 'FAC' THEN t.IMP_TOTAL_TICKET ELSE 0 END), 0) AS suma_tick_act,
                ISNULL(SUM(CASE WHEN t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                 AND t.T_COMP = 'FAC' THEN t.IMP_TOTAL_TICKET ELSE 0 END), 0) AS suma_tick_prev
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE (
                (t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE)))
                OR (t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE)))
            ) {$sfGT}
            GROUP BY t.NRO_SUCURS
        ", array_merge(
            [$desde_act, $hasta_act, $desde_prev, $hasta_prev,
             $desde_act, $hasta_act, $desde_prev, $hasta_prev,
             $desde_act, $hasta_act, $desde_prev, $hasta_prev],
            $pGT
        ));

        // Tickets 2do y 3er producto por sucursal
        [$sfGTk, $pGTk]   = $this->buildGrupoTipoFilter('tk', $grupo, $tipoTienda, $canal);
        [$sfGGTk, $pGGTk] = $this->grupoFiltro('tk');
        $sfGTk .= ' ' . $sfGGTk;
        $pGTk   = array_merge($pGTk, $pGGTk);
        $rowsTick2do = $this->query("
            SELECT
                tk.NRO_SUCURS,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 1 THEN tk.N_COMP END) AS t2do,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 2 THEN tk.N_COMP END) AS t3ro,
                COUNT(DISTINCT tk.N_COMP) AS total
            FROM BI_SALES_TICKETS tk
            WHERE tk.FECHA >= ? AND tk.FECHA < DATEADD(day,1,CAST(? AS DATE))
              {$sfGTk}
            GROUP BY tk.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pGTk));

        // Incremental por sucursal
        [$sfGP, $pGP]   = $this->buildGrupoTipoFilter('p', $grupo, $tipoTienda, $canal, false);
        [$sfGGP, $pGGP] = $this->grupoFiltro('p');
        $sfGP .= ' ' . $sfGGP;
        $pGP   = array_merge($pGP, $pGGP);
        $rowsIncr = $this->query("
            SELECT
                p.NRO_SUCURS,
                ISNULL(SUM(p.CAMBIO), 0) AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0) AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE p.FECHA_MOV >= ? AND p.FECHA_MOV < DATEADD(day,1,CAST(? AS DATE))
              {$sfGP}
            GROUP BY p.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pGP));

        // Objetivos por sucursal
        $sfGO = '';
        $pGO  = [];
        if ($grupo !== null && $this->origen === 'argentina') {
            $sfGO  = "AND o.NRO_SUCURSAL IN (SELECT g.NRO_SUCURS FROM BI_DIM_SUCURSALES_GRUPO g WHERE g.GRUPO = ?)";
            $pGO[] = $grupo;
        }
        if ($tipoTienda !== null && $this->origen === 'argentina') {
            $sfGO .= " AND o.NRO_SUCURSAL IN (SELECT sl.NRO_SUCURSAL FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WHERE sl.TIPO_TIENDA = ?)";
            $pGO[] = $tipoTienda;
        }
        if (!empty($canal) && $this->origen === 'argentina') {
            if ($canal === 'PROPIOS') $sfGO .= " AND o.NRO_SUCURSAL NOT IN (1, 9)";
            elseif ($canal === 'ECOMMERCE') $sfGO .= " AND o.NRO_SUCURSAL IN (1, 9)";
        }
        [$sfGGO, $pGGO] = $this->grupoFiltro('o', 'NRO_SUCURSAL');
        $sfGO .= ' ' . $sfGGO;
        $pGO   = array_merge($pGO, $pGGO);
        $rowsObj = $this->query("
            SELECT o.NRO_SUCURSAL,
                ISNULL(SUM(CASE WHEN o.FECHA >= ? AND o.FECHA < DATEADD(day,1,CAST(? AS DATE))
                                THEN o.IMPORTE_OBJ ELSE 0 END), 0) AS objetivo_fecha,
                ISNULL(SUM(CASE WHEN YEAR(o.FECHA) = YEAR(CAST(? AS DATE))
                                 AND MONTH(o.FECHA) = MONTH(CAST(? AS DATE))
                                THEN o.IMPORTE_OBJ ELSE 0 END), 0) AS objetivo_total
            FROM {$this->tablaObjetivos} o
            WHERE (
                (o.FECHA >= ? AND o.FECHA < DATEADD(day,1,CAST(? AS DATE)))
                OR (YEAR(o.FECHA) = YEAR(CAST(? AS DATE)) AND MONTH(o.FECHA) = MONTH(CAST(? AS DATE)))
            ) {$sfGO}
            GROUP BY o.NRO_SUCURSAL
        ", array_merge([$desde_act, $hasta_act, $desde_act, $desde_act, $desde_act, $hasta_act, $desde_act, $desde_act], $pGO));

        // Ingresos por sucursal (para conversión)
        [$sfGI, $pGI]   = $this->buildGrupoTipoFilter('i', $grupo, $tipoTienda, $canal, false);
        [$sfGGI, $pGGI] = $this->grupoFiltro('i');
        $sfGI .= ' ' . $sfGGI;
        $pGI   = array_merge($pGI, $pGGI);
        $rowsIngresos = $this->query("
            SELECT i.NRO_SUCURS, ISNULL(SUM(i.INGRESOS), 0) AS ingresos
            FROM BI_T_INGRESOS_SUCURSALES i
            WHERE i.FECHA >= ? AND i.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND i.INGRESOS > 0 {$sfGI}
            GROUP BY i.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pGI));

        // Tickets con ingreso (para tasa de conversión)
        [$sfGTC, $pGTC]   = $this->buildGrupoTipoFilter('tc', $grupo, $tipoTienda, $canal);
        [$sfGGTC, $pGGTC] = $this->grupoFiltro('tc');
        $sfGTC .= ' ' . $sfGGTC;
        $pGTC   = array_merge($pGTC, $pGGTC);
        $rowsTicketsConv = $this->query("
            SELECT tc.NRO_SUCURS, COUNT(DISTINCT tc.N_COMP) AS tickets_conv
            FROM BI_SALES_TOTAL_TICKETS tc
            WHERE tc.FECHA >= ? AND tc.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND tc.T_COMP = 'FAC' {$sfGTC}
              AND EXISTS (
                  SELECT 1 FROM BI_T_INGRESOS_SUCURSALES ic
                  WHERE ic.NRO_SUCURS = tc.NRO_SUCURS
                    AND CAST(ic.FECHA AS DATE) = CAST(tc.FECHA AS DATE)
                    AND ic.FECHA >= ? AND ic.FECHA < DATEADD(day,1,CAST(? AS DATE))
                    AND ic.INGRESOS > 0
              )
            GROUP BY tc.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $pGTC, [$desde_act, $hasta_act]));

        // Mails por sucursal
        [$sfGMail, $pGMail]   = $this->buildGrupoTipoFilter('tm', $grupo, $tipoTienda, $canal);
        [$sfGGMail, $pGGMail] = $this->grupoFiltro('tm');
        $sfGMail .= ' ' . $sfGGMail;
        $pGMail   = array_merge($pGMail, $pGGMail);
        $rowsMails = [];
        try {
            $rowsMails = $this->query("
                SELECT
                    tm.NRO_SUCURS,
                    COUNT(DISTINCT CASE WHEN ISNULL(tm.EMAIL,'') <> '' AND ISNULL(tm.EMAIL,'') <> 'invalido'
                                         AND tm.T_COMP = 'FAC' THEN tm.N_COMP END) AS mails
                FROM BI_SALES_TOTAL_TICKETS tm
                WHERE tm.FECHA >= ? AND tm.FECHA < DATEADD(day,1,CAST(? AS DATE))
                  AND tm.T_COMP = 'FAC' {$sfGMail}
                GROUP BY tm.NRO_SUCURS
            ", array_merge([$desde_act, $hasta_act], $pGMail));
        } catch (Throwable $_) {}

        // Presencialidad por sucursal
        $rowsPresence = [];
        try {
            $rowsPresence = $this->query("
                SELECT
                    m.nro_sucursal,
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
                WHERE h.FECHA >= ? AND h.FECHA < DATEADD(day,1,CAST(? AS DATE))
                GROUP BY m.nro_sucursal
            ", [$desde_act, $hasta_act]);
        } catch (Throwable $_) {}

        // Descripción de sucursales
        $rowsDesc = $this->query("
            SELECT sl.NRO_SUCURSAL, sl.DESC_SUCURSAL
            FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
        ");

        // Consolidar por NRO_SUCURS
        $tickMap  = [];
        foreach ($rowsTickets as $r) {
            $tickMap[(int)$r['NRO_SUCURS']] = $r;
        }
        $tick2Map = [];
        foreach ($rowsTick2do as $r) {
            $tick2Map[(int)$r['NRO_SUCURS']] = $r;
        }
        $incrMap = [];
        foreach ($rowsIncr as $r) {
            $incrMap[(int)$r['NRO_SUCURS']] = $r;
        }
        $objMap      = [];
        $objTotalMap = [];
        foreach ($rowsObj as $r) {
            $objMap[(int)$r['NRO_SUCURSAL']]      = (float)$r['objetivo_fecha'];
            $objTotalMap[(int)$r['NRO_SUCURSAL']] = (float)$r['objetivo_total'];
        }
        $descMap = [];
        foreach ($rowsDesc as $r) {
            $descMap[(int)$r['NRO_SUCURSAL']] = $r['DESC_SUCURSAL'];
        }
        $mailsMap = [];
        foreach ($rowsMails as $r) {
            $mailsMap[(int)$r['NRO_SUCURS']] = (int)$r['mails'];
        }
        $ingresosMap = [];
        foreach ($rowsIngresos as $r) {
            $ingresosMap[(int)$r['NRO_SUCURS']] = (int)$r['ingresos'];
        }
        $ticketsConvMap = [];
        foreach ($rowsTicketsConv as $r) {
            $ticketsConvMap[(int)$r['NRO_SUCURS']] = (int)$r['tickets_conv'];
        }
        $presenceMap = [];
        foreach ($rowsPresence as $r) {
            if ($r['nro_sucursal'] !== null) {
                $presenceMap[(int)$r['nro_sucursal']] = $r;
            }
        }

        // Totales verdaderos de cadena (todas las sucursales de ambos períodos,
        // antes del filtro de actividad) para calcular variaciones agregadas correctas.
        $cFactAct = 0.0; $cFactPrev = 0.0;
        $cUnidAct = 0.0; $cUnidPrev = 0.0;
        foreach ($rowsVentas as $v) {
            $cFactAct  += (float)$v['fact_act'];
            $cFactPrev += (float)$v['fact_prev'];
            $cUnidAct  += (float)$v['unid_act'];
            $cUnidPrev += (float)$v['unid_prev'];
        }
        $cTickAct = 0; $cTickPrev = 0;
        foreach ($rowsTickets as $r) {
            $cTickAct  += (int)$r['tick_act'];
            $cTickPrev += (int)$r['tick_prev'];
        }

        $result = [];
        foreach ($rowsVentas as $v) {
            $nro     = (int)$v['NRO_SUCURS'];
            $factAct = (float)$v['fact_act'];
            // Omitir sucursales sin ningún dato en el período actual
            if ($factAct == 0 && (float)$v['unid_act'] == 0) continue;
            $factPrev = (float)$v['fact_prev'];
            $unidAct = (float)$v['unid_act'];
            $unidPrev = (float)$v['unid_prev'];
            $unidPos = (float)$v['unid_pos_act'];
            $cambios = (float)$v['cambios_act'];

            $t     = $tickMap[$nro] ?? [];
            $tk2   = $tick2Map[$nro] ?? [];
            $incr  = $incrMap[$nro] ?? [];
            $pres  = $presenceMap[$nro] ?? [];

            $tickAct  = (int)($t['tick_act']  ?? 0);
            $tickPrev = (int)($t['tick_prev'] ?? 0);
            $sumaT    = (float)($t['suma_tick_act'] ?? 0);
            $sumaTp   = (float)($t['suma_tick_prev'] ?? 0);

            $total2do = (int)($tk2['total'] ?? 0);
            $t2do     = (int)($tk2['t2do']  ?? 0);
            $t3ro     = (int)($tk2['t3ro']  ?? 0);

            $cambI  = (float)($incr['cambios_incr'] ?? 0);
            $devol  = (float)($incr['devoluciones'] ?? 0);

            $totHrs = (float)($pres['total_horas'] ?? 0);
            $ausHrs = (float)($pres['horas_ausencia'] ?? 0);
            $porcPres = $totHrs > 0 ? ($totHrs - $ausHrs) / $totHrs : null;

            $objFecha  = $objMap[$nro]      ?? 0.0;
            $objTotal  = $objTotalMap[$nro] ?? 0.0;

            $result[] = [
                'nro_sucurs'       => $nro,
                'desc_sucursal'    => $descMap[$nro] ?? "Suc {$nro}",
                'facturacion'      => $factAct,
                'facturacion_prev' => $factPrev,
                'var_facturacion'  => $factPrev > 0 ? ($factAct - $factPrev) / $factPrev : null, // null = sin período previo → muestra guion
                'objetivo_fecha'   => $objFecha,
                'objetivo_total'   => $objTotal,
                'porc_cumplimiento' => $objTotal > 0 ? $factAct / $objTotal : null,
                'unidades'         => $unidAct,
                'unidades_prev'    => $unidPrev,
                'tickets'          => $tickAct,
                'tickets_prev'     => $tickPrev,
                'var_tickets'      => $tickPrev > 0 ? ($tickAct - $tickPrev) / $tickPrev : 0,
                'ticket_promedio'  => $tickAct > 0 ? $sumaT / $tickAct : 0,
                'ticket_prom_prev' => $tickPrev > 0 ? $sumaTp / $tickPrev : 0,
                'porc_cambios'     => $unidPos > 0 ? $cambios / $unidPos : 0,
                'porc_incremental' => $devol != 0 ? ($cambI - $devol) / $devol : 0,
                'porc_2do'         => $total2do > 0 ? $t2do / $total2do : 0,
                'porc_3ro'         => $total2do > 0 ? $t3ro / $total2do : 0,
                'var_unidades'     => $unidPrev > 0 ? ($unidAct - $unidPrev) / $unidPrev : 0,
                'mails'            => $mailsMap[$nro] ?? 0,
                'mails_pct'        => $tickAct > 0 ? ($mailsMap[$nro] ?? 0) / $tickAct : 0,
                'ingresos'         => $ingresosMap[$nro] ?? 0,
                'conversion'       => ($ingresosMap[$nro] ?? 0) > 0
                                        ? ($ticketsConvMap[$nro] ?? 0) / $ingresosMap[$nro]
                                        : null,
                'porc_presencia'   => $porcPres,
            ];
        }

        // Franquicias sin Tango: agregar filas de las 11 sucursales sin Tango
        if ($this->origen === 'franquicias') {
            $existentes = array_flip(array_column($result, 'nro_sucurs'));
            $rowsST = $this->query("
                SELECT
                    NRO_SUCURS,
                    ISNULL(SUM(CASE WHEN FECHA >= ? AND FECHA < DATEADD(day,1,CAST(? AS DATE))
                                    THEN IMPORTE ELSE 0 END), 0) AS fact_act,
                    ISNULL(SUM(CASE WHEN FECHA >= ? AND FECHA < DATEADD(day,1,CAST(? AS DATE))
                                    THEN IMPORTE ELSE 0 END), 0) AS fact_prev
                FROM (
                    SELECT pv.idTango AS NRO_SUCURS, fd.fecha AS FECHA, fd.importeVentaReal AS IMPORTE
                    FROM sistemas.dbo.FP_ObjetivosFinalesDetalle fd WITH (NOLOCK)
                    INNER JOIN [SERVIDORTESTING].dbXLSales.dbo.PuntosDeVenta pv WITH (NOLOCK) ON fd.idPOS = pv.id
                    INNER JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WITH (NOLOCK) ON pv.idTango = sl.NRO_SUCURSAL
                    WHERE (sl.TANGO IS NULL OR sl.TANGO <> 1)
                ) s
                WHERE (
                    (FECHA >= ? AND FECHA < DATEADD(day,1,CAST(? AS DATE)))
                    OR (FECHA >= ? AND FECHA < DATEADD(day,1,CAST(? AS DATE)))
                )
                GROUP BY NRO_SUCURS
            ", [$desde_act, $hasta_act, $desde_prev, $hasta_prev,
                $desde_act, $hasta_act, $desde_prev, $hasta_prev]);

            foreach ($rowsST as $v) {
                $nro     = (int)$v['NRO_SUCURS'];
                $factAct = (float)$v['fact_act'];
                if ($factAct == 0) continue;
                if (isset($existentes[$nro])) continue;

                $factPrev = (float)$v['fact_prev'];
                $objFecha = $objMap[$nro]      ?? 0.0;
                $objTotal = $objTotalMap[$nro] ?? 0.0;

                $result[] = [
                    'nro_sucurs'            => $nro,
                    'desc_sucursal'         => $descMap[$nro] ?? "Suc {$nro}",
                    'facturacion'           => $factAct,
                    'facturacion_prev'      => $factPrev,
                    'var_facturacion'       => $factPrev > 0 ? ($factAct - $factPrev) / $factPrev : null, // null = sin período previo → muestra guion
                    'objetivo_fecha'        => $objFecha,
                    'objetivo_total'        => $objTotal,
                    'porc_cumplimiento'     => $objTotal > 0 ? $factAct / $objTotal : null,
                    'unidades'              => 0.0,
                    'unidades_prev'         => 0.0,
                    'tickets'               => 0,
                    'tickets_prev'          => 0,
                    'var_tickets'           => 0,
                    'ticket_promedio'       => 0,
                    'ticket_prom_prev'      => 0,
                    'porc_cambios'          => 0,
                    'porc_incremental'      => 0,
                    'porc_2do'              => 0,
                    'porc_3ro'              => 0,
                    'var_unidades'          => 0,
                    'mails'                 => 0,
                    'mails_pct'             => 0,
                    'ingresos'              => 0,
                    'conversion'            => null,
                    'porc_part_facturacion' => 0,
                    'porc_presencia'        => null,
                ];
                $cFactAct  += $factAct;
                $cFactPrev += $factPrev;
            }
        }

        // Calcular participación de facturación por sucursal sobre el total
        $totalFact = array_sum(array_column($result, 'facturacion'));
        foreach ($result as &$row) {
            $row['porc_part_facturacion'] = $totalFact > 0 ? $row['facturacion'] / $totalFact : 0;
        }
        unset($row);

        usort($result, fn($a, $b) => $b['facturacion'] <=> $a['facturacion']);
        return [
            'sucursales' => $result,
            'totales' => [
                'facturacion'      => $cFactAct,
                'facturacion_prev' => $cFactPrev,
                'unidades'         => $cUnidAct,
                'unidades_prev'    => $cUnidPrev,
                'tickets'          => $cTickAct,
                'tickets_prev'     => $cTickPrev,
            ],
        ];
    }
}
