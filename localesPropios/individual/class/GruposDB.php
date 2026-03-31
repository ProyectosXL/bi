<?php
/**
 * GruposDB
 * Acceso a datos para la pestaña Grupos.
 */
class GruposDB
{
    private $conn;

    public function __construct()
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/comercial/Class/Conexion.php';
        $cid        = new Conexion();
        $this->conn = $cid->conectar('power');
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

    /** Indexa resultado por campo clave (entero). */
    private function indexBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r[$key]] = $r;
        }
        return $out;
    }

    /* ──────────────────────────────────────────────
     *  SUCURSALES DEL GRUPO
     * ────────────────────────────────────────────── */

    /**
     * Devuelve todas las sucursales del mismo grupo que la dada.
     */
    public function getSucursalesGrupo(int $nroSucurs): array
    {
        $sql = "
            SELECT g2.NRO_SUCURS, sl.DESC_SUCURSAL, g2.GRUPO
            FROM BI_DIM_SUCURSALES_GRUPO g1
            JOIN BI_DIM_SUCURSALES_GRUPO g2 ON g2.GRUPO = g1.GRUPO
            JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
              ON sl.NRO_SUCURSAL = g2.NRO_SUCURS
            WHERE g1.NRO_SUCURS = ? AND sl.HABILITADO = 1
            ORDER BY sl.DESC_SUCURSAL
        ";
        return $this->query($sql, [$nroSucurs]);
    }

    /* ──────────────────────────────────────────────
     *  KPIs INDIVIDUALES (para Versus)
     * ────────────────────────────────────────────── */

    /**
     * Todos los KPIs de una sucursal en un período dado.
     */
    public function getKPIsSucursal(string $desde, string $hasta, int $nroSucurs): array
    {
        $rowV = $this->queryOne("
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
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.NRO_SUCURS = ?
        ", [$desde, $hasta, $nroSucurs]);

        $rowT = $this->queryOne("
            SELECT
                COUNT(DISTINCT t.N_COMP) AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_tickets
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE CAST(t.FECHA AS DATE) BETWEEN ? AND ?
              AND t.T_COMP = 'FAC'
              AND t.NRO_SUCURS = ?
        ", [$desde, $hasta, $nroSucurs]);

        $rowTP = $this->queryOne("
            SELECT
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 1 THEN tk.N_COMP END) AS tickets_2do,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 2 THEN tk.N_COMP END) AS tickets_3ro
            FROM BI_SALES_TICKETS tk
            WHERE CAST(tk.FECHA AS DATE) BETWEEN ? AND ?
              AND tk.NRO_SUCURS = ?
        ", [$desde, $hasta, $nroSucurs]);

        $rowI = $this->queryOne("
            SELECT
                ISNULL(SUM(p.CAMBIO), 0)       AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0) AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE CAST(p.FECHA_MOV AS DATE) BETWEEN ? AND ?
              AND p.NRO_SUCURS = ?
        ", [$desde, $hasta, $nroSucurs]);

        $unidPos     = (float)($rowV['unidades_positivas'] ?? 0);
        $cambios     = (float)($rowV['cambios']            ?? 0);
        $tickets     = (int)($rowT['tickets']              ?? 0);
        $sumaT       = (float)($rowT['suma_tickets']       ?? 0);
        $t2do        = (int)($rowTP['tickets_2do']         ?? 0);
        $t3ro        = (int)($rowTP['tickets_3ro']         ?? 0);
        $cambiosIncr = (float)($rowI['cambios_incr']       ?? 0);
        $devol       = (float)($rowI['devoluciones']       ?? 0);

        return [
            'facturacion'      => (float)($rowV['facturacion'] ?? 0),
            'unidades'         => (float)($rowV['unidades']    ?? 0),
            'tickets'          => $tickets,
            'ticket_promedio'  => $tickets > 0 ? $sumaT / $tickets : 0,
            'porc_2do'         => $tickets > 0 ? $t2do / $tickets : 0,
            'porc_3ro'         => $tickets > 0 ? $t3ro / $tickets : 0,
            'porc_cambios'     => $unidPos > 0 ? $cambios / $unidPos : 0,
            'porc_incremental' => $devol != 0  ? ($cambiosIncr - $devol) / $devol : 0,
        ];
    }

    /* ──────────────────────────────────────────────
     *  DESGLOSE GRUPO → SUCURSAL
     * ────────────────────────────────────────────── */

    /**
     * KPIs por sucursal (período actual vs. anterior) agrupados por GRUPO.
     * Eficiente: 8 consultas para todo el grupo, no N consultas por sucursal.
     */
    public function getDesglosePorSucursal(
        string $desde_act,  string $hasta_act,
        string $desde_prev, string $hasta_prev,
        array  $sucursales,
        int    $nroSucursBase
    ): array {
        if (empty($sucursales)) return [];

        $nros = array_map('intval', array_column($sucursales, 'NRO_SUCURS'));
        $ph   = implode(',', array_fill(0, count($nros), '?'));

        // ── Ventas act ───────────────────────────────────────────────────
        $ventasAct = $this->indexBy($this->query("
            SELECT s.NRO_SUCURS,
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
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.NRO_SUCURS IN ({$ph})
            GROUP BY s.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $nros)), 'NRO_SUCURS');

        // ── Ventas prev (solo facturación para % participación) ──────────
        $ventasPrev = $this->indexBy($this->query("
            SELECT s.NRO_SUCURS,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.NRO_SUCURS IN ({$ph})
            GROUP BY s.NRO_SUCURS
        ", array_merge([$desde_prev, $hasta_prev], $nros)), 'NRO_SUCURS');

        // ── Tickets act ──────────────────────────────────────────────────
        $ticketsAct = $this->indexBy($this->query("
            SELECT t.NRO_SUCURS,
                COUNT(DISTINCT t.N_COMP) AS tickets,
                ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_tickets
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE CAST(t.FECHA AS DATE) BETWEEN ? AND ?
              AND t.T_COMP = 'FAC'
              AND t.NRO_SUCURS IN ({$ph})
            GROUP BY t.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $nros)), 'NRO_SUCURS');

        // ── Tickets prev ─────────────────────────────────────────────────
        $ticketsPrev = $this->indexBy($this->query("
            SELECT t.NRO_SUCURS,
                COUNT(DISTINCT t.N_COMP) AS tickets
            FROM BI_SALES_TOTAL_TICKETS t
            WHERE CAST(t.FECHA AS DATE) BETWEEN ? AND ?
              AND t.T_COMP = 'FAC'
              AND t.NRO_SUCURS IN ({$ph})
            GROUP BY t.NRO_SUCURS
        ", array_merge([$desde_prev, $hasta_prev], $nros)), 'NRO_SUCURS');

        // ── Tickets 2do / 3er act ────────────────────────────────────────
        $tpAct = $this->indexBy($this->query("
            SELECT tk.NRO_SUCURS,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 1 THEN tk.N_COMP END) AS tickets_2do,
                COUNT(DISTINCT CASE WHEN tk.CANTIDAD > 2 THEN tk.N_COMP END) AS tickets_3ro
            FROM BI_SALES_TICKETS tk
            WHERE CAST(tk.FECHA AS DATE) BETWEEN ? AND ?
              AND tk.NRO_SUCURS IN ({$ph})
            GROUP BY tk.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $nros)), 'NRO_SUCURS');

        // ── Incremental act ──────────────────────────────────────────────
        $incrAct = $this->indexBy($this->query("
            SELECT p.NRO_SUCURS,
                ISNULL(SUM(p.CAMBIO), 0)       AS cambios_incr,
                ISNULL(SUM(p.DEVOLUCIONES), 0) AS devoluciones
            FROM BI_SALES_PORC_INCREMENTAL p
            WHERE CAST(p.FECHA_MOV AS DATE) BETWEEN ? AND ?
              AND p.NRO_SUCURS IN ({$ph})
            GROUP BY p.NRO_SUCURS
        ", array_merge([$desde_act, $hasta_act], $nros)), 'NRO_SUCURS');

        // Total facturación del grupo (denominador para % participación)
        $totalFactGrupo = 0;
        foreach ($ventasAct as $v) {
            $totalFactGrupo += (float)($v['facturacion'] ?? 0);
        }

        // ── Construir árbol GRUPO → SUCURSAL ────────────────────────────
        $grupoMap = [];
        foreach ($sucursales as $s) {
            $grupo = $s['GRUPO'];
            $nro   = (int)$s['NRO_SUCURS'];

            if (!isset($grupoMap[$grupo])) {
                $grupoMap[$grupo] = [
                    'grupo'      => $grupo,
                    'sucursales' => [],
                    '_acc'       => [
                        'facturacion'        => 0,
                        'unidades'           => 0,
                        'tickets_act'        => 0,
                        'tickets_prev'       => 0,
                        'suma_tickets'       => 0,
                        't2do'               => 0,
                        't3ro'               => 0,
                        'unidades_positivas' => 0,
                        'cambios'            => 0,
                        'cambios_incr'       => 0,
                        'devoluciones'       => 0,
                    ],
                ];
            }

            $vA    = $ventasAct[$nro]  ?? [];
            $tA    = $ticketsAct[$nro] ?? [];
            $tP    = $ticketsPrev[$nro] ?? [];
            $tp    = $tpAct[$nro]      ?? [];
            $iA    = $incrAct[$nro]    ?? [];

            $facAct  = (float)($vA['facturacion']        ?? 0);
            $unidAct = (float)($vA['unidades']           ?? 0);
            $unidPos = (float)($vA['unidades_positivas'] ?? 0);
            $cambios = (float)($vA['cambios']            ?? 0);
            $tickAct = (int)($tA['tickets']              ?? 0);
            $tickPrev= (int)($tP['tickets']              ?? 0);
            $sumaT   = (float)($tA['suma_tickets']       ?? 0);
            $t2do    = (int)($tp['tickets_2do']          ?? 0);
            $t3ro    = (int)($tp['tickets_3ro']          ?? 0);
            $cambIncr= (float)($iA['cambios_incr']       ?? 0);
            $devol   = (float)($iA['devoluciones']       ?? 0);

            $grupoMap[$grupo]['sucursales'][] = [
                'nro_sucurs'       => $nro,
                'desc_sucursal'    => $s['DESC_SUCURSAL'],
                'is_current'       => ($nro === $nroSucursBase),
                'facturacion'      => $facAct,
                'porc_facturacion' => $totalFactGrupo > 0 ? $facAct / $totalFactGrupo : 0,
                'unidades'         => $unidAct,
                'tickets'          => $tickAct,
                'var_tickets'      => $tickPrev > 0 ? ($tickAct - $tickPrev) / $tickPrev : null,
                'ticket_promedio'  => $tickAct > 0 ? $sumaT / $tickAct : 0,
                'porc_2do'         => $tickAct > 0 ? $t2do / $tickAct : 0,
                'porc_3ro'         => $tickAct > 0 ? $t3ro / $tickAct : 0,
                'porc_cambios'     => $unidPos > 0 ? $cambios / $unidPos : 0,
                'porc_incremental' => $devol != 0  ? ($cambIncr - $devol) / $devol : 0,
            ];

            $acc = &$grupoMap[$grupo]['_acc'];
            $acc['facturacion']        += $facAct;
            $acc['unidades']           += $unidAct;
            $acc['tickets_act']        += $tickAct;
            $acc['tickets_prev']       += $tickPrev;
            $acc['suma_tickets']       += $sumaT;
            $acc['t2do']               += $t2do;
            $acc['t3ro']               += $t3ro;
            $acc['unidades_positivas'] += $unidPos;
            $acc['cambios']            += $cambios;
            $acc['cambios_incr']       += $cambIncr;
            $acc['devoluciones']       += $devol;
            unset($acc);
        }

        // ── Calcular totales por grupo y limpiar _acc ───────────────────
        $result = [];
        foreach ($grupoMap as &$g) {
            $a = $g['_acc'];
            $g['totales'] = [
                'facturacion'      => $a['facturacion'],
                'porc_facturacion' => 1.0,
                'unidades'         => $a['unidades'],
                'tickets'          => $a['tickets_act'],
                'var_tickets'      => $a['tickets_prev'] > 0
                    ? ($a['tickets_act'] - $a['tickets_prev']) / $a['tickets_prev']
                    : null,
                'ticket_promedio'  => $a['tickets_act'] > 0 ? $a['suma_tickets'] / $a['tickets_act'] : 0,
                'porc_2do'         => $a['tickets_act'] > 0 ? $a['t2do'] / $a['tickets_act'] : 0,
                'porc_3ro'         => $a['tickets_act'] > 0 ? $a['t3ro'] / $a['tickets_act'] : 0,
                'porc_cambios'     => $a['unidades_positivas'] > 0 ? $a['cambios'] / $a['unidades_positivas'] : 0,
                'porc_incremental' => $a['devoluciones'] != 0
                    ? ($a['cambios_incr'] - $a['devoluciones']) / $a['devoluciones']
                    : 0,
            ];
            unset($g['_acc']);
            $result[] = $g;
        }

        return $result;
    }

    /* ──────────────────────────────────────────────
     *  TOP RUBROS PIVOT (% participación)
     * ────────────────────────────────────────────── */

    /**
     * Top N rubros por facturación y % de participación por sucursal.
     */
    public function getTopRubrosPivot(
        string $desde,
        string $hasta,
        array  $sucursales,
        int    $nroSucursBase,
        int    $topN = 8
    ): array {
        if (empty($sucursales)) return ['top_rubros' => [], 'grupos' => []];

        $nros = array_map('intval', array_column($sucursales, 'NRO_SUCURS'));
        $ph   = implode(',', array_fill(0, count($nros), '?'));

        // Top N rubros por facturación total del grupo
        $topRows = $this->query("
            SELECT TOP {$topN} s.RUBRO, ISNULL(SUM(s.IMPORTE), 0) AS fact_total
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.NRO_SUCURS IN ({$ph})
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
            GROUP BY s.RUBRO
            ORDER BY fact_total DESC
        ", array_merge([$desde, $hasta], $nros));

        $rubroNames = array_column($topRows, 'RUBRO');
        if (empty($rubroNames)) return ['top_rubros' => [], 'grupos' => []];

        $phR = implode(',', array_fill(0, count($rubroNames), '?'));

        // Facturación por sucursal y rubro
        $rubroRows = $this->query("
            SELECT s.NRO_SUCURS, s.RUBRO, ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.NRO_SUCURS IN ({$ph})
              AND s.RUBRO IN ({$phR})
            GROUP BY s.NRO_SUCURS, s.RUBRO
        ", array_merge([$desde, $hasta], $nros, $rubroNames));

        // Facturación total por sucursal
        $totRows = $this->query("
            SELECT s.NRO_SUCURS, ISNULL(SUM(s.IMPORTE), 0) AS fact_total
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.NRO_SUCURS IN ({$ph})
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
            GROUP BY s.NRO_SUCURS
        ", array_merge([$desde, $hasta], $nros));

        $factByRubro = [];
        foreach ($rubroRows as $r) {
            $factByRubro[(int)$r['NRO_SUCURS']][$r['RUBRO']] = (float)$r['facturacion'];
        }
        $factTotal = [];
        foreach ($totRows as $r) {
            $factTotal[(int)$r['NRO_SUCURS']] = (float)$r['fact_total'];
        }

        // Construir árbol GRUPO → SUCURSAL
        $grupoMap = [];
        foreach ($sucursales as $s) {
            $grupo = $s['GRUPO'];
            $nro   = (int)$s['NRO_SUCURS'];
            if (!isset($grupoMap[$grupo])) {
                $grupoMap[$grupo] = ['grupo' => $grupo, 'sucursales' => []];
            }
            $totFact = $factTotal[$nro] ?? 0;
            $rubros  = [];
            foreach ($rubroNames as $rub) {
                $f = $factByRubro[$nro][$rub] ?? 0;
                $rubros[$rub] = $totFact > 0 ? $f / $totFact : 0;
            }
            $grupoMap[$grupo]['sucursales'][] = [
                'nro_sucurs'    => $nro,
                'desc_sucursal' => $s['DESC_SUCURSAL'],
                'is_current'    => ($nro === $nroSucursBase),
                'fact_total'    => $totFact,
                'rubros'        => $rubros,
            ];
        }

        return [
            'top_rubros' => $rubroNames,
            'grupos'     => array_values($grupoMap),
        ];
    }
}
