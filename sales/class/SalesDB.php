<?php
/**
 * SalesDB — Acceso a datos del Dashboard Sales $ Neto.
 * Fuente principal: dbo.BI_SALES_LAKERS en XL-APPS / POWER_BI_CONTROL.
 */
class SalesDB
{
    private $conn;

    /** QueryTimeout (segundos) para todas las llamadas sqlsrv_query de esta instancia. */
    private array $queryTimeoutOpt = ['QueryTimeout' => 30];

    /** true cuando #cliente_grupo ya fue materializada en este request. */
    private bool $clienteGrupoReady = false;

    public function __construct()
    {
        require_once __DIR__ . '/../../class/classEnv.php';
        require_once __DIR__ . '/../../class/Conexion.php';
        $this->conn = (new Conexion())->conectar('power');
        if (!$this->conn) {
            throw new RuntimeException('No se pudo conectar a XL-APPS/POWER_BI_CONTROL');
        }
    }

    /**
     * Sobrescribe el QueryTimeout por defecto (30s) para esta instancia.
     * Útil para llamadas síncronas antes del render (ej. index.php) donde
     * conviene fallar rápido en vez de esperar el timeout completo.
     */
    public function setQueryTimeoutSeconds(int $seconds): void
    {
        $this->queryTimeoutOpt = ['QueryTimeout' => $seconds];
    }

    /* ─────────────────────────────────────────────────────────
     * Helpers internos
     * ───────────────────────────────────────────────────────── */

    /**
     * Calcula el período actual y el año anterior para comparación.
     * Devuelve [desde_act, hasta_act, desde_prev, hasta_prev]
     */
    public static function calcularPeriodo(string $periodo): array
    {
        $hoy  = new DateTime();
        $ayer = (clone $hoy)->modify('-1 day');

        switch ($periodo) {
            case 'mes_actual':
                $da = $hoy->format('Y-m-01');
                $ha = $ayer->format('Y-m-d');
                $dp = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
                $hp = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case 'mes_pasado':
                $da = $hoy->format('Y-m-01');
                $prev = (clone $hoy)->modify('first day of last month');
                $da = $prev->format('Y-m-01');
                $ha = $prev->format('Y-m-t');
                $dp = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
                $hp = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case 'año_actual':
                $da = $hoy->format('Y-01-01');
                $ha = $ayer->format('Y-m-d');
                $dp = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
                $hp = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case 'año_pasado':
                $anio = (int)$hoy->format('Y') - 1;
                $da   = "$anio-01-01";
                $ha   = "$anio-12-31";
                $dp   = ($anio - 1) . '-01-01';
                $hp   = ($anio - 1) . '-12-31';
                return [$da, $ha, $dp, $hp];

            case '7':
                $da = (clone $hoy)->modify('-7 days')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                $dp = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
                $hp = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case '30':
            default:
                $da = (clone $hoy)->modify('-30 days')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                $dp = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
                $hp = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];
        }
    }

    /** Fragmento WHERE para CANAL (null = todos) */
    private function whereCanalFrag(?string $canal, string $alias = 's'): array
    {
        if (!$canal || $canal === '%') return ['', []];
        $map = [
            'PROPIOS'     => 'LOCALES PROPIOS',
            'FRANQUICIAS' => 'FRANQUICIAS',
            'MAYORISTAS'  => 'MAYORISTAS',
            'ECOMMERCE'   => 'ECOMMERCE',
        ];
        $v = $map[strtoupper($canal)] ?? $canal;
        return [" AND {$alias}.CANAL = ?", [$v]];
    }

    /** Lista de grupos empresarios disponibles */
    public function getGruposEmpresario(): array
    {
        $sql = "SELECT DISTINCT GRUPO_EMPRESARIO 
                FROM POWER_BI_CONTROL_FRANQUICIAS.dbo.BI_SALES_SUCURSALES 
                WHERE GRUPO_EMPRESARIO IS NOT NULL AND GRUPO_EMPRESARIO <> '' 
                ORDER BY GRUPO_EMPRESARIO";
        $stmt = sqlsrv_query($this->conn, $sql, [], $this->queryTimeoutOpt);
        $rows = [];
        if ($stmt) {
            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $val = trim($r['GRUPO_EMPRESARIO'] ?? '');
                if ($val !== '') {
                    $rows[] = $val;
                }
            }
            sqlsrv_free_stmt($stmt);
        }
        return $rows;
    }

    /**
     * Materializa el mapeo CLIENTE ↔ GRUPO_EMPRESARIO en #cliente_grupo una sola vez
     * por request. Evita repetir el JOIN LIKE no sargable entre BI_SALES_LAKERS y
     * POWER_BI_CONTROL_FRANQUICIAS.dbo.BI_SALES_SUCURSALES en cada query que filtra
     * por grupo empresario (hasta ~20 veces por request).
     */
    private function initTempClienteGrupo(): void
    {
        if ($this->clienteGrupoReady) return;

        $drop = sqlsrv_query($this->conn, "IF OBJECT_ID('tempdb..#cliente_grupo') IS NOT NULL DROP TABLE #cliente_grupo", [], $this->queryTimeoutOpt);
        if ($drop !== false) sqlsrv_free_stmt($drop);

        $stmt = sqlsrv_query($this->conn, "
            SELECT DISTINCT c.CLIENTE, sub.GRUPO_EMPRESARIO
            INTO #cliente_grupo
            FROM dbo.BI_SALES_LAKERS c
            INNER JOIN POWER_BI_CONTROL_FRANQUICIAS.dbo.BI_SALES_SUCURSALES sub
               ON c.CANAL = 'FRANQUICIAS'
              AND (c.CLIENTE LIKE '%' + sub.SUCURSAL COLLATE Modern_Spanish_CI_AI + '%'
                   OR sub.SUCURSAL COLLATE Modern_Spanish_CI_AI LIKE '%' + c.CLIENTE + '%')
            WHERE sub.GRUPO_EMPRESARIO IS NOT NULL AND sub.GRUPO_EMPRESARIO <> ''
        ", [], $this->queryTimeoutOpt);
        if ($stmt === false) {
            throw new RuntimeException('initTempClienteGrupo: ' . (sqlsrv_errors()[0]['message'] ?? 'error'));
        }
        sqlsrv_free_stmt($stmt);
        $this->clienteGrupoReady = true;
    }

    /** Fragmento WHERE para GRUPO_EMPRESARIO */
    private function whereGrupoEmpresarioFrag($grupoEmpresario, string $alias = 's'): array
    {
        if (!$grupoEmpresario) return ['', []];
        if (is_string($grupoEmpresario)) {
            $grupoEmpresario = array_filter(array_map('trim', explode(',', $grupoEmpresario)));
        }
        if (!is_array($grupoEmpresario) || empty($grupoEmpresario)) return ['', []];

        $this->initTempClienteGrupo();

        $placeholders = implode(',', array_fill(0, count($grupoEmpresario), '?'));

        $sql = " AND {$alias}.CLIENTE IN (
            SELECT DISTINCT cg.CLIENTE
            FROM #cliente_grupo cg
            WHERE cg.GRUPO_EMPRESARIO IN ({$placeholders})
        )";

        return [$sql, array_values($grupoEmpresario)];
    }

    /** Fragmento WHERE para CLIENTE (null = todos) */
    private function whereClienteFrag($cliente, string $alias = 's'): array
    {
        if (!$cliente || $cliente === '%') return ['', []];
        if (is_string($cliente)) {
            $cliente = array_filter(array_map('trim', explode(',', $cliente)));
        }
        if (!is_array($cliente) || empty($cliente)) return ['', []];

        $placeholders = implode(',', array_fill(0, count($cliente), '?'));
        return [" AND {$alias}.CLIENTE IN ({$placeholders})", array_values($cliente)];
    }

    /** Fragmento WHERE para RUBRO (null = todos) */
    private function whereRubroFrag(?string $rubro, string $alias = 's'): array
    {
        if (!$rubro || $rubro === '%') return ['', []];
        return [" AND {$alias}.RUBRO = ?", [$rubro]];
    }

    /**
     * Fragmento WHERE para excluir rubros que no se contabilizan en Unidades.
     * Equivale a: BI_SALES_LAKERS[RUBRO] <> "CONCEPTO" && <> "PACKAGING"
     */
    private function whereExcluirRubrosUnid(string $alias = 's'): string
    {
        return " AND {$alias}.RUBRO NOT IN ('CONCEPTO', 'PACKAGING')";
    }

    private function fetchAll($stmt): array
    {
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $rows;
    }

    /* ─────────────────────────────────────────────────────────
     * PESTAÑA GENERAL
     * ───────────────────────────────────────────────────────── */

    /**
     * KPIs Generales: facturación y unidades actuales vs. año anterior.
     * Retorna: fact_act, fact_prev, var_fact, unid_act, unid_prev, var_unid
     */
    public function getKpisGenerales(string $da, string $ha, string $dp, string $hp,
                                     ?string $canal = null, ?string $rubro = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal]   = $this->whereCanalFrag($canal);
        [$wRubro, $pRubro]   = $this->whereRubroFrag($rubro);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);

        // 1) Consultar periodo actual (Suma condicional para unidades)
        $sqlAct = "
            SELECT 
                SUM(IMPORTE) AS fact, 
                SUM(CASE WHEN RUBRO NOT IN ('CONCEPTO', 'PACKAGING') THEN CANTIDAD ELSE 0 END) AS unid
            FROM dbo.BI_SALES_LAKERS s
            WHERE FECHA >= ? AND FECHA <= ? $wCanal $wRubro $wCliente $wGrupo
        ";
        $paramsAct = array_merge([$da, $ha], $pCanal, $pRubro, $pCliente, $pGrupo);
        $stmtAct   = sqlsrv_query($this->conn, $sqlAct, $paramsAct, $this->queryTimeoutOpt);
        $rowAct    = sqlsrv_fetch_array($stmtAct, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtAct);

        // 2) Consultar periodo previo (Suma condicional para unidades)
        $sqlPrev = "
            SELECT 
                SUM(IMPORTE) AS fact, 
                SUM(CASE WHEN RUBRO NOT IN ('CONCEPTO', 'PACKAGING') THEN CANTIDAD ELSE 0 END) AS unid
            FROM dbo.BI_SALES_LAKERS s
            WHERE FECHA >= ? AND FECHA <= ? $wCanal $wRubro $wCliente $wGrupo
        ";
        $paramsPrev = array_merge([$dp, $hp], $pCanal, $pRubro, $pCliente, $pGrupo);
        $stmtPrev   = sqlsrv_query($this->conn, $sqlPrev, $paramsPrev, $this->queryTimeoutOpt);
        $rowPrev    = sqlsrv_fetch_array($stmtPrev, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmtPrev);

        $fa = (float)($rowAct['fact']  ?? 0);
        $fp = (float)($rowPrev['fact'] ?? 0);
        $ua = (float)($rowAct['unid']  ?? 0);
        $up = (float)($rowPrev['unid'] ?? 0);

        return [
            'fact_act'  => $fa,
            'fact_prev' => $fp,
            'var_fact'  => $fp != 0 ? round(($fa - $fp) / abs($fp) * 100, 1) : null,
            'unid_act'  => $ua,
            'unid_prev' => $up,
            'var_unid'  => $up != 0 ? round(($ua - $up) / abs($up) * 100, 1) : null,
        ];
    }

    public function getDesgloseCanalKpis(string $da, string $ha, string $dp, string $hp,
                                          ?string $rubro = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wRubro, $pRubro]     = $this->whereRubroFrag($rubro);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);

        $canales = ['LOCALES PROPIOS', 'FRANQUICIAS', 'MAYORISTAS', 'ECOMMERCE'];

        // 1) Periodo actual — una sola query agrupada por canal en vez de 1 por canal
        $sqlAct = "
            SELECT
                CANAL,
                SUM(IMPORTE) AS fact,
                SUM(CASE WHEN RUBRO NOT IN ('CONCEPTO', 'PACKAGING') THEN CANTIDAD ELSE 0 END) AS unid,
                COUNT(DISTINCT CLIENTE) AS puntos_venta
            FROM dbo.BI_SALES_LAKERS s
            WHERE FECHA >= ? AND FECHA <= ? $wRubro $wCliente $wGrupo
            GROUP BY CANAL
        ";
        $paramsAct = array_merge([$da, $ha], $pRubro, $pCliente, $pGrupo);
        $stmtAct   = sqlsrv_query($this->conn, $sqlAct, $paramsAct, $this->queryTimeoutOpt);
        $actByCanal = [];
        foreach ($this->fetchAll($stmtAct) as $r) {
            $actByCanal[$r['CANAL']] = $r;
        }

        // 2) Periodo previo — idem, agrupado por canal
        $sqlPrev = "
            SELECT
                CANAL,
                SUM(IMPORTE) AS fact,
                SUM(CASE WHEN RUBRO NOT IN ('CONCEPTO', 'PACKAGING') THEN CANTIDAD ELSE 0 END) AS unid
            FROM dbo.BI_SALES_LAKERS s
            WHERE FECHA >= ? AND FECHA <= ? $wRubro $wCliente $wGrupo
            GROUP BY CANAL
        ";
        $paramsPrev = array_merge([$dp, $hp], $pRubro, $pCliente, $pGrupo);
        $stmtPrev   = sqlsrv_query($this->conn, $sqlPrev, $paramsPrev, $this->queryTimeoutOpt);
        $prevByCanal = [];
        foreach ($this->fetchAll($stmtPrev) as $r) {
            $prevByCanal[$r['CANAL']] = $r;
        }

        $result = [];
        foreach ($canales as $canal) {
            $rowAct  = $actByCanal[$canal]  ?? null;
            $rowPrev = $prevByCanal[$canal] ?? null;

            $fa = (float)($rowAct['fact'] ?? 0.0);
            $ua = (float)($rowAct['unid'] ?? 0.0);
            $pv = (int)($rowAct['puntos_venta'] ?? 0);
            $fp = (float)($rowPrev['fact'] ?? 0.0);
            $up = (float)($rowPrev['unid'] ?? 0.0);

            $result[] = [
                'canal'       => $canal,
                'fact_act'    => $fa,
                'fact_prev'   => $fp,
                'var_fact'    => $fp != 0 ? round(($fa - $fp) / abs($fp) * 100, 1) : null,
                'unid_act'    => $ua,
                'unid_prev'   => $up,
                'var_unid'    => $up != 0 ? round(($ua - $up) / abs($up) * 100, 1) : null,
                'puntos_venta'=> $pv,
            ];
        }

        // Ordenar por facturación actual desc para mantener la consistencia
        usort($result, function($a, $b) {
            return $b['fact_act'] <=> $a['fact_act'];
        });

        return $result;
    }

    /**
     * Devuelve la serie temporal del período seleccionado para los micro gráficos (Sparkcharts).
     * Si el rango de días es <= 31 se agrupa por Día (FECHA), sino por Mes (FECHA).
     */
    public function getVentasSerieTiempo(string $da, string $ha, ?string $canal = null, ?string $rubro = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal]   = $this->whereCanalFrag($canal);
        [$wRubro, $pRubro]   = $this->whereRubroFrag($rubro);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);

        $days = (strtotime($ha) - strtotime($da)) / 86400;

        if ($days <= 31) {
            // Agrupación diaria
            $sql = "
                SELECT 
                    FECHA AS etiqueta,
                    SUM(IMPORTE) AS facturacion,
                    SUM(CASE WHEN RUBRO NOT IN ('CONCEPTO', 'PACKAGING') THEN CANTIDAD ELSE 0 END) AS unidades
                FROM dbo.BI_SALES_LAKERS s
                WHERE FECHA >= ? AND FECHA <= ? $wCanal $wRubro $wCliente $wGrupo
                GROUP BY FECHA
                ORDER BY FECHA
            ";
        } else {
            // Agrupación mensual nativa
            $sql = "
                SELECT 
                    YEAR(FECHA) AS anio,
                    MONTH(FECHA) AS mes,
                    SUM(IMPORTE) AS facturacion,
                    SUM(CASE WHEN RUBRO NOT IN ('CONCEPTO', 'PACKAGING') THEN CANTIDAD ELSE 0 END) AS unidades
                FROM dbo.BI_SALES_LAKERS s
                WHERE FECHA >= ? AND FECHA <= ? $wCanal $wRubro $wCliente $wGrupo
                GROUP BY YEAR(FECHA), MONTH(FECHA)
                ORDER BY YEAR(FECHA), MONTH(FECHA)
            ";
        }

        $params = array_merge([$da, $ha], $pCanal, $pRubro, $pCliente, $pGrupo);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        $rows   = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                if ($days <= 31) {
                    $lbl = $row['etiqueta'] instanceof DateTime ? $row['etiqueta']->format('Y-m-d') : (string)$row['etiqueta'];
                } else {
                    $lbl = $row['anio'] . '-' . str_pad($row['mes'], 2, '0', STR_PAD_LEFT) . '-01';
                }
                $rows[] = [
                    'label' => $lbl,
                    'facturacion' => (float)$row['facturacion'],
                    'unidades' => (float)$row['unidades'],
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
        return $rows;
    }

    /**
     * Tabla mensual acumulada por canal (YTD o período seleccionado).
     * Columnas = meses del período actual.
     */
    public function getTablaMensualCanal(int $anio, ?string $canal = null, ?string $rubro = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal]   = $this->whereCanalFrag($canal);
        [$wRubro, $pRubro]   = $this->whereRubroFrag($rubro);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);

        $sql = "
            SELECT
                s.CANAL,
                MONTH(s.FECHA) AS mes,
                SUM(s.IMPORTE)  AS facturacion,
                SUM(CASE WHEN RUBRO NOT IN ('CONCEPTO', 'PACKAGING') THEN CANTIDAD ELSE 0 END) AS unidades
            FROM dbo.BI_SALES_LAKERS s
            WHERE YEAR(s.FECHA) = ? $wCanal $wRubro $wCliente $wGrupo
            GROUP BY s.CANAL, MONTH(s.FECHA)
            ORDER BY s.CANAL, mes
        ";
        $params = array_merge([$anio], $pCanal, $pRubro, $pCliente, $pGrupo);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        return $this->fetchAll($stmt);
    }

    /* ─────────────────────────────────────────────────────────
     * PESTAÑA EVOLUCIÓN FACTURACIÓN
     * ───────────────────────────────────────────────────────── */

    /**
     * Tabla de rubros: facturación actual vs. año anterior.
     */
    public function getEvolucionRubrosFacturacion(string $da, string $ha, string $dp, string $hp,
                                                   ?string $canal = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal] = $this->whereCanalFrag($canal);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);

        $sql = "
            SELECT
                s.RUBRO,
                SUM(CASE WHEN s.FECHA >= ? AND s.FECHA <= ? THEN s.IMPORTE ELSE 0 END) AS fact_act,
                SUM(CASE WHEN s.FECHA >= ? AND s.FECHA <= ? THEN s.IMPORTE ELSE 0 END) AS fact_prev
            FROM dbo.BI_SALES_LAKERS s
            WHERE 1=1 $wCanal $wCliente $wGrupo
            GROUP BY s.RUBRO
            ORDER BY fact_act DESC
        ";
        $params = array_merge([$da, $ha, $dp, $hp], $pCanal, $pCliente, $pGrupo);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        $rows   = $this->fetchAll($stmt);

        return array_map(function($r) {
            $fa = (float)$r['fact_act'];
            $fp = (float)$r['fact_prev'];
            return [
                'rubro'     => $r['RUBRO'],
                'fact_act'  => $fa,
                'fact_prev' => $fp,
                'var'       => $fp != 0 ? round(($fa - $fp) / abs($fp) * 100, 1) : null,
            ];
        }, $rows);
    }

    /**
     * Evolución mensual de facturación por año (para el gráfico multi-serie).
     * Retorna filas: [anio, mes, facturacion]
     */
    public function getEvolucionMensualFacturacion(?string $canal = null, ?string $rubro = null,
                                                    int $aniosAtras = 4, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal]   = $this->whereCanalFrag($canal);
        [$wRubro, $pRubro]   = $this->whereRubroFrag($rubro);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);
        
        // Excluir año 2022 para atrás en toda la app
        $anioDesde = max((int)date('Y') - $aniosAtras, 2023);

        $sql = "
            SELECT
                YEAR(s.FECHA)  AS anio,
                MONTH(s.FECHA) AS mes,
                SUM(s.IMPORTE) AS facturacion
            FROM dbo.BI_SALES_LAKERS s
            WHERE YEAR(s.FECHA) >= ? $wCanal $wRubro $wCliente $wGrupo
            GROUP BY YEAR(s.FECHA), MONTH(s.FECHA)
            ORDER BY anio, mes
        ";
        $params = array_merge([$anioDesde], $pCanal, $pRubro, $pCliente, $pGrupo);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        return $this->fetchAll($stmt);
    }

    /**
     * Tabla mensual por canal para la pestaña Evolución Facturación.
     */
    public function getTablaFacturacionCanalMes(int $anio, ?string $canal = null, ?string $rubro = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal]   = $this->whereCanalFrag($canal);
        [$wRubro, $pRubro]   = $this->whereRubroFrag($rubro);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);

        $sql = "
            SELECT CANAL, MONTH(FECHA) AS mes, SUM(IMPORTE) AS facturacion
            FROM dbo.BI_SALES_LAKERS s
            WHERE YEAR(FECHA) = ? $wCanal $wRubro $wCliente $wGrupo
            GROUP BY CANAL, MONTH(FECHA)
            ORDER BY CANAL, mes
        ";
        $params = array_merge([$anio], $pCanal, $pRubro, $pCliente, $pGrupo);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        return $this->fetchAll($stmt);
    }

    /* ─────────────────────────────────────────────────────────
     * PESTAÑA EVOLUCIÓN UNIDADES
     * ───────────────────────────────────────────────────────── */

    /**
     * Tabla de rubros: unidades actual vs. año anterior.
     */
    public function getEvolucionRubrosUnidades(string $da, string $ha, string $dp, string $hp,
                                                ?string $canal = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal] = $this->whereCanalFrag($canal);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);

        $sql = "
            SELECT
                s.RUBRO,
                SUM(CASE WHEN s.FECHA >= ? AND s.FECHA <= ? THEN s.CANTIDAD ELSE 0 END) AS unid_act,
                SUM(CASE WHEN s.FECHA >= ? AND s.FECHA <= ? THEN s.CANTIDAD ELSE 0 END) AS unid_prev
            FROM dbo.BI_SALES_LAKERS s
            WHERE 1=1 $wCanal $wCliente $wGrupo
            " . $this->whereExcluirRubrosUnid() . "
            GROUP BY s.RUBRO
            ORDER BY unid_act DESC
        ";
        $params = array_merge([$da, $ha, $dp, $hp], $pCanal, $pCliente, $pGrupo);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        $rows   = $this->fetchAll($stmt);

        return array_map(function($r) {
            $ua = (float)$r['unid_act'];
            $up = (float)$r['unid_prev'];
            return [
                'rubro'     => $r['RUBRO'],
                'unid_act'  => $ua,
                'unid_prev' => $up,
                'var'       => $up != 0 ? round(($ua - $up) / abs($up) * 100, 1) : null,
            ];
        }, $rows);
    }

    /**
     * Evolución mensual de unidades por año (multi-serie).
     */
    public function getEvolucionMensualUnidades(?string $canal = null, ?string $rubro = null,
                                                 int $aniosAtras = 4, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal]   = $this->whereCanalFrag($canal);
        [$wRubro, $pRubro]   = $this->whereRubroFrag($rubro);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);
        
        // Excluir año 2022 para atrás en toda la app
        $anioDesde = max((int)date('Y') - $aniosAtras, 2023);

        $sql = "
            SELECT
                YEAR(s.FECHA)   AS anio,
                MONTH(s.FECHA)  AS mes,
                SUM(s.CANTIDAD) AS unidades
            FROM dbo.BI_SALES_LAKERS s
            WHERE YEAR(s.FECHA) >= ? $wCanal $wRubro $wCliente $wGrupo
            " . $this->whereExcluirRubrosUnid() . "
            GROUP BY YEAR(s.FECHA), MONTH(s.FECHA)
            ORDER BY anio, mes
        ";
        $params = array_merge([$anioDesde], $pCanal, $pRubro, $pCliente, $pGrupo);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        return $this->fetchAll($stmt);
    }

    /**
     * Tabla mensual por canal para la pestaña Evolución Unidades.
     */
    public function getTablaUnidadesCanalMes(int $anio, ?string $canal = null, ?string $rubro = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal]   = $this->whereCanalFrag($canal);
        [$wRubro, $pRubro]   = $this->whereRubroFrag($rubro);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);

        $sql = "
            SELECT CANAL, MONTH(FECHA) AS mes, SUM(CANTIDAD) AS unidades
            FROM dbo.BI_SALES_LAKERS s
            WHERE YEAR(FECHA) = ? $wCanal $wRubro
            " . $this->whereExcluirRubrosUnid() . "
            GROUP BY CANAL, MONTH(FECHA)
            ORDER BY CANAL, mes
        ";
        $params = array_merge([$anio], $pCanal, $pRubro, $pCliente, $pGrupo);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        return $this->fetchAll($stmt);
    }

    /* ─────────────────────────────────────────────────────────
     * PESTAÑA VARIACIÓN UNIDADES
     * ───────────────────────────────────────────────────────── */

    /**
     * % Variación mensual de unidades (actual vs. año anterior) por canal.
     * Para el gráfico de línea de variación.
     */
    public function getVariacionMensualUnidades(?string $canal = null, ?string $rubro = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal] = $this->whereCanalFrag($canal);
        $anioAct  = (int)date('Y');
        $anioPrev = $anioAct - 1;

        $sql = "
            SELECT
                MONTH(s.FECHA) AS mes,
                SUM(CASE WHEN YEAR(s.FECHA) = ? THEN s.CANTIDAD ELSE 0 END) AS unid_act,
                SUM(CASE WHEN YEAR(s.FECHA) = ? THEN s.CANTIDAD ELSE 0 END) AS unid_prev
            FROM dbo.BI_SALES_LAKERS s
            WHERE YEAR(s.FECHA) IN (?, ?) $wCanal
            " . $this->whereExcluirRubrosUnid() . "
            GROUP BY MONTH(s.FECHA)
            ORDER BY mes
        ";
        $params = array_merge([$anioAct, $anioPrev, $anioAct, $anioPrev], $pCanal);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        $rows   = $this->fetchAll($stmt);

        return array_map(function($r) {
            $ua = (float)$r['unid_act'];
            $up = (float)$r['unid_prev'];
            return [
                'mes'      => (int)$r['mes'],
                'unid_act' => $ua,
                'unid_prev'=> $up,
                'var'      => $up != 0 ? round(($ua - $up) / abs($up) * 100, 1) : null,
            ];
        }, $rows);
    }

    /**
     * Participación % por canal por año (apilado 100%).
     */
    public function getParticipacionCanalAnual(int $aniosAtras = 4): array
    {
        // Excluir año 2022 para atrás en toda la app
        $anioDesde = max((int)date('Y') - $aniosAtras, 2023);

        $sql = "
            SELECT
                YEAR(FECHA) AS anio,
                CANAL,
                SUM(CANTIDAD) AS unidades
            FROM dbo.BI_SALES_LAKERS
            WHERE YEAR(FECHA) >= ?
              AND RUBRO NOT IN ('CONCEPTO', 'PACKAGING')
            GROUP BY YEAR(FECHA), CANAL
            ORDER BY anio, CANAL
        ";
        $stmt = sqlsrv_query($this->conn, $sql, [$anioDesde], $this->queryTimeoutOpt);
        return $this->fetchAll($stmt);
    }

    /* ─────────────────────────────────────────────────────────
     * PESTAÑA UNIDADES
     * ───────────────────────────────────────────────────────── */

    /**
     * Tabla completa de unidades por rubro con variación.
     */
    public function getTablaUnidades(string $da, string $ha, string $dp, string $hp,
                                      ?string $canal = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal] = $this->whereCanalFrag($canal);

        $sql = "
            SELECT
                s.RUBRO,
                SUM(CASE WHEN s.FECHA >= ? AND s.FECHA <= ? THEN s.CANTIDAD ELSE 0 END) AS unid_act,
                SUM(CASE WHEN s.FECHA >= ? AND s.FECHA <= ? THEN s.CANTIDAD ELSE 0 END) AS unid_prev,
                SUM(CASE WHEN s.FECHA >= ? AND s.FECHA <= ? THEN s.IMPORTE  ELSE 0 END) AS fact_act
            FROM dbo.BI_SALES_LAKERS s
            WHERE 1=1 $wCanal
            " . $this->whereExcluirRubrosUnid() . "
            GROUP BY s.RUBRO
            ORDER BY unid_act DESC
        ";
        $params = array_merge([$da, $ha, $dp, $hp, $da, $ha], $pCanal);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        $rows   = $this->fetchAll($stmt);

        return array_map(function($r) {
            $ua = (float)$r['unid_act'];
            $up = (float)$r['unid_prev'];
            return [
                'rubro'    => $r['RUBRO'],
                'unid_act' => $ua,
                'unid_prev'=> $up,
                'var'      => $up != 0 ? round(($ua - $up) / abs($up) * 100, 1) : null,
                'fact_act' => (float)$r['fact_act'],
            ];
        }, $rows);
    }

    /**
     * Calcula la facturación y unidades agrupadas por Temporada.
     * Invierno: Feb a Jul (año X). Verano: Ago (año X) a Ene (año X+1).
     */
    public function getKpisTemporadas(?string $canal = null, ?string $rubro = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal]   = $this->whereCanalFrag($canal);
        [$wRubro, $pRubro]   = $this->whereRubroFrag($rubro);
        [$wCliente, $pCliente] = $this->whereClienteFrag($cliente);
        [$wGrupo, $pGrupo]     = $this->whereGrupoEmpresarioFrag($grupo_empresario);

        // Agrupación nativa por temporada usando la lógica de meses de Power BI:
        // SWITCH(TRUE(), Month in (8,9,10,11,12,1) -> Verano, (2,3,4,5,6,7) -> Invierno)
        $sql = "
            SELECT 
                CASE WHEN MONTH(FECHA) IN (8,9,10,11,12,1) THEN 'Verano' ELSE 'Invierno' END AS temp_nombre,
                CASE WHEN MONTH(FECHA) = 1 THEN YEAR(FECHA) - 1 ELSE YEAR(FECHA) END AS temp_anio,
                SUM(IMPORTE) AS fact,
                SUM(CASE WHEN RUBRO NOT IN ('CONCEPTO', 'PACKAGING') THEN CANTIDAD ELSE 0 END) AS unid
            FROM dbo.BI_SALES_LAKERS s
            WHERE FECHA >= '2023-01-01' $wCanal $wRubro $wCliente $wGrupo
            GROUP BY 
                CASE WHEN MONTH(FECHA) IN (8,9,10,11,12,1) THEN 'Verano' ELSE 'Invierno' END,
                CASE WHEN MONTH(FECHA) = 1 THEN YEAR(FECHA) - 1 ELSE YEAR(FECHA) END
            ORDER BY temp_anio DESC, temp_nombre DESC
        ";
        $params = array_merge($pCanal, $pRubro, $pCliente, $pGrupo);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        $rows   = $this->fetchAll($stmt);

        // Procesar variaciones comparando contra la misma temporada del año anterior
        $temporadas = [];
        foreach ($rows as $r) {
            $name = $r['temp_nombre'] . ' - ' . $r['temp_anio'];
            $temporadas[$name] = [
                'label' => $name,
                'nombre' => $r['temp_nombre'],
                'anio' => (int)$r['temp_anio'],
                'fact' => (float)$r['fact'],
                'unid' => (float)$r['unid'],
                'var_fact' => null,
                'var_unid' => null,
            ];
        }

        // Calcular variaciones vs misma temporada año anterior
        foreach ($temporadas as $key => &$t) {
            $prevKey = $t['nombre'] . ' - ' . ($t['anio'] - 1);
            if (isset($temporadas[$prevKey])) {
                $prev = $temporadas[$prevKey];
                if ($prev['fact'] > 0) {
                    $t['var_fact'] = round(($t['fact'] - $prev['fact']) / $prev['fact'] * 100, 1);
                }
                if ($prev['unid'] > 0) {
                    $t['var_unid'] = round(($t['unid'] - $prev['unid']) / $prev['unid'] * 100, 1);
                }
            }
        }

        // Retornar la lista ordenada de temporadas más recientes (de mayor a menor)
        return array_values($temporadas);
    }

    /* ─────────────────────────────────────────────────────────
     * UTILIDADES
     * ───────────────────────────────────────────────────────── */

    /** Lista de clientes / locales / franquicias disponibles por canal y rango de fecha */
    public function getClientes(?string $canal = null, ?string $desde = null, ?string $hasta = null): array
    {
        [$wCanal, $pCanal] = $this->whereCanalFrag($canal);
        $wDate = "";
        $pDate = [];
        if ($desde && $hasta) {
            $wDate = " AND s.FECHA >= ? AND s.FECHA <= ? ";
            $pDate = [$desde, $hasta];
        }
        $sql = "SELECT DISTINCT s.CLIENTE FROM dbo.BI_SALES_LAKERS s WHERE s.CLIENTE IS NOT NULL $wDate $wCanal ORDER BY s.CLIENTE";
        $params = array_merge($pDate, $pCanal);
        $stmt = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        $rows = [];
        if ($stmt) {
            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $val = trim($r['CLIENTE'] ?? '');
                if ($val !== '') {
                    $rows[] = $val;
                }
            }
            sqlsrv_free_stmt($stmt);
        }
        return $rows;
    }

    /** Lista de canales disponibles */
    public function getCanales(): array
    {
        $stmt = sqlsrv_query($this->conn,
            "SELECT DISTINCT CANAL FROM dbo.BI_SALES_LAKERS WHERE CANAL IS NOT NULL ORDER BY CANAL", [], $this->queryTimeoutOpt);
        $rows = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $r['CANAL'];
        sqlsrv_free_stmt($stmt);
        return $rows;
    }

    /** Lista de rubros disponibles */
    public function getRubros(): array
    {
        $stmt = sqlsrv_query($this->conn,
            "SELECT DISTINCT RUBRO FROM dbo.BI_SALES_LAKERS WHERE RUBRO IS NOT NULL ORDER BY RUBRO", [], $this->queryTimeoutOpt);
        $rows = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) $rows[] = $r['RUBRO'];
        sqlsrv_free_stmt($stmt);
        return $rows;
    }

    /** Obtiene la última fecha/hora de actualización de los datos */
    public function getUltimaActualizacion(): ?string
    {
        $sql = "SELECT MAX(last_user_update) as last_update
                FROM sys.dm_db_index_usage_stats
                WHERE database_id = DB_ID('POWER_BI_CONTROL')
                  AND object_id = OBJECT_ID('dbo.BI_SALES_LAKERS')";
        $stmt = sqlsrv_query($this->conn, $sql, [], $this->queryTimeoutOpt);
        $res = null;
        if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (!empty($row['last_update'])) {
                $res = is_object($row['last_update']) ? $row['last_update']->format('Y-m-d H:i:s') : $row['last_update'];
            }
            sqlsrv_free_stmt($stmt);
        }

        // Fallback: si por alguna razón no tenemos permisos o estadísticas, usamos el max(FECHA)
        if (!$res) {
            $sqlFallback = "SELECT MAX(FECHA) as last_update FROM dbo.BI_SALES_LAKERS";
            $stmtFallback = sqlsrv_query($this->conn, $sqlFallback, [], $this->queryTimeoutOpt);
            if ($stmtFallback !== false && $rowFallback = sqlsrv_fetch_array($stmtFallback, SQLSRV_FETCH_ASSOC)) {
                if (!empty($rowFallback['last_update'])) {
                    $res = is_object($rowFallback['last_update']) ? $rowFallback['last_update']->format('Y-m-d H:i:s') : $rowFallback['last_update'];
                }
                sqlsrv_free_stmt($stmtFallback);
            }
        }
        return $res;
    }

    /**
     * Tabla de unidades por RUBRO y MES del año en curso.
     * Retorna filas con: rubro, mes (1-12), unidades.
     */
    public function getTablaUnidadesMensualRubro(int $anio, ?string $canal = null, $cliente = null, $grupo_empresario = null): array
    {
        [$wCanal, $pCanal] = $this->whereCanalFrag($canal);

        $sql = "
            SELECT
                s.RUBRO,
                MONTH(s.FECHA) AS mes,
                SUM(s.CANTIDAD) AS unidades
            FROM dbo.BI_SALES_LAKERS s
            WHERE YEAR(s.FECHA) = ? $wCanal
            " . $this->whereExcluirRubrosUnid() . "
            GROUP BY s.RUBRO, MONTH(s.FECHA)
            ORDER BY s.RUBRO, mes
        ";
        $params = array_merge([$anio], $pCanal);
        $stmt   = sqlsrv_query($this->conn, $sql, $params, $this->queryTimeoutOpt);
        return $this->fetchAll($stmt);
    }

    public function __destruct()
    {
        if ($this->conn) sqlsrv_close($this->conn);
    }
}
