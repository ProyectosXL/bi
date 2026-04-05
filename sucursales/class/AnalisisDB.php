<?php
/**
 * AnalisisDB
 * Acceso a datos para la pestaña de Análisis avanzado.
 * La base de datos y el campo de vendedor se resuelven desde config.php
 * según $_SESSION['tipo'] (LOCAL_PROPIO / LOCAL_PROPIO_UY / FRANQUICIA).
 */
class AnalisisDB
{
    private $conn;
    private $campoVendedor;

    public function __construct()
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
        $config = getConfig();
        $cid        = new Conexion();
        $this->conn = $cid->conectar($config['db']);
        $this->campoVendedor = $config['campo_vendedor'];
    }

    /* ──────────────────────────────────────────────
     *  HELPER QUERY
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

    /* ──────────────────────────────────────────────
     *  JERARQUÍA DESTINO → RUBRO → CATEGORÍA
     * ────────────────────────────────────────────── */

    /**
     * Devuelve unidades y facturación agrupadas por DESTINO / RUBRO / CATEGORIA.
     * Si la tabla no tiene columna DESTINO se usa 'SIN DESTINO' como fallback.
     */
    public function getJerarquiaDestinoRubroCategoria(
        string $desde,
        string $hasta,
        ?int   $nroSucurs = null,
        string $vendedor  = '%',
        string $rubro     = '%'
    ): array {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor  !== '%'  ? "AND s.{$cv} = ?" : "";
        $sfRS = $rubro     !== '%'  ? "AND s.RUBRO = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor  !== '%'  ? [$vendedor]  : [];
        $rub  = $rubro     !== '%'  ? [$rubro]     : [];

        // Intentar con columna DESTINO; si no existe, usar constante
        $sql = "
            SELECT
                ISNULL(s.DESTINO, 'SIN DESTINO') AS destino,
                s.RUBRO,
                s.CATEGORIA,
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfS} {$sfVS} {$sfRS}
            GROUP BY
                ISNULL(s.DESTINO, 'SIN DESTINO'),
                s.RUBRO,
                s.CATEGORIA
            ORDER BY 1, 2, 3
        ";

        try {
            return $this->query($sql, array_merge([$desde, $hasta], $suc, $vend, $rub));
        } catch (RuntimeException $e) {
            // Fallback si DESTINO no existe como columna
            $sqlFallback = "
                SELECT
                    'SIN DESTINO' AS destino,
                    s.RUBRO,
                    s.CATEGORIA,
                    ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                    THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                    ISNULL(SUM(s.IMPORTE), 0) AS facturacion
                FROM BI_SALES_SUCURSALES s
                WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
                  AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                  {$sfS} {$sfVS} {$sfRS}
                GROUP BY s.RUBRO, s.CATEGORIA
                ORDER BY 2, 3
            ";
            return $this->query($sqlFallback, array_merge([$desde, $hasta], $suc, $vend, $rub));
        }
    }

    /**
     * Fusiona datos actuales y anteriores en estructura jerárquica.
     */
    public function mergeJerarquias(array $actual, array $previo): array
    {
        // Indexar previo por destino|rubro|categoria
        $prevIdx = [];
        foreach ($previo as $row) {
            $key = $row['destino'] . '||' . $row['RUBRO'] . '||' . $row['CATEGORIA'];
            $prevIdx[$key] = $row;
        }

        // Construir árbol
        $tree = [];
        foreach ($actual as $row) {
            $destino   = $row['destino'];
            $rubroNm   = $row['RUBRO'];
            $categNm   = $row['CATEGORIA'] ?? 'SIN CATEGORÍA';
            $key       = $destino . '||' . $rubroNm . '||' . $categNm;
            $prev      = $prevIdx[$key] ?? null;
            $unidPrev  = $prev ? (float)$prev['unidades']    : 0;
            $factPrev  = $prev ? (float)$prev['facturacion'] : 0;
            $unidAct   = (float)$row['unidades'];
            $factAct   = (float)$row['facturacion'];
            $varUnid   = $unidPrev != 0 ? ($unidAct - $unidPrev) / abs($unidPrev) : null;
            $varFact   = $factPrev != 0 ? ($factAct - $factPrev) / abs($factPrev) : null;

            if (!isset($tree[$destino])) {
                $tree[$destino] = ['label' => $destino, 'rubros' => [], 'totals' => ['unidades'=>0,'unidades_prev'=>0,'facturacion'=>0,'facturacion_prev'=>0]];
            }
            if (!isset($tree[$destino]['rubros'][$rubroNm])) {
                $tree[$destino]['rubros'][$rubroNm] = ['label' => $rubroNm, 'categorias' => [], 'totals' => ['unidades'=>0,'unidades_prev'=>0,'facturacion'=>0,'facturacion_prev'=>0]];
            }

            $tree[$destino]['rubros'][$rubroNm]['categorias'][] = [
                'label'          => $categNm,
                'unidades'       => $unidAct,
                'unidades_prev'  => $unidPrev,
                'facturacion'    => $factAct,
                'facturacion_prev' => $factPrev,
                'var_unidades'   => $varUnid,
                'var_facturacion'=> $varFact,
            ];

            // Acumular en rubro
            $tree[$destino]['rubros'][$rubroNm]['totals']['unidades']       += $unidAct;
            $tree[$destino]['rubros'][$rubroNm]['totals']['unidades_prev']  += $unidPrev;
            $tree[$destino]['rubros'][$rubroNm]['totals']['facturacion']    += $factAct;
            $tree[$destino]['rubros'][$rubroNm]['totals']['facturacion_prev'] += $factPrev;

            // Acumular en destino
            $tree[$destino]['totals']['unidades']       += $unidAct;
            $tree[$destino]['totals']['unidades_prev']  += $unidPrev;
            $tree[$destino]['totals']['facturacion']    += $factAct;
            $tree[$destino]['totals']['facturacion_prev'] += $factPrev;
        }

        // Calcular variaciones para rubros y destinos + aplanar a array
        $result = [];
        foreach ($tree as &$dest) {
            $dT = &$dest['totals'];
            $dT['var_unidades']    = $dT['unidades_prev']    != 0 ? ($dT['unidades']    - $dT['unidades_prev'])    / abs($dT['unidades_prev'])    : null;
            $dT['var_facturacion'] = $dT['facturacion_prev'] != 0 ? ($dT['facturacion'] - $dT['facturacion_prev']) / abs($dT['facturacion_prev']) : null;

            $rubrosArr = [];
            foreach ($dest['rubros'] as &$rub) {
                $rT = &$rub['totals'];
                $rT['var_unidades']    = $rT['unidades_prev']    != 0 ? ($rT['unidades']    - $rT['unidades_prev'])    / abs($rT['unidades_prev'])    : null;
                $rT['var_facturacion'] = $rT['facturacion_prev'] != 0 ? ($rT['facturacion'] - $rT['facturacion_prev']) / abs($rT['facturacion_prev']) : null;
                $rubrosArr[] = [
                    'label'      => $rub['label'],
                    'totals'     => $rub['totals'],
                    'categorias' => $rub['categorias'],
                ];
            }
            $result[] = [
                'label'  => $dest['label'],
                'totals' => $dest['totals'],
                'rubros' => $rubrosArr,
            ];
        }

        return $result;
    }

    /* ──────────────────────────────────────────────
     *  VENDEDORES ANÁLISIS
     * ────────────────────────────────────────────── */

    public function getVendedoresAnalisis(
        string $desde,
        string $hasta,
        ?int   $nroSucurs = null,
        string $vendedor  = '%',
        string $rubro     = '%'
    ): array {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor  !== '%'  ? "AND s.{$cv} = ?" : "";
        $sfRS = $rubro     !== '%'  ? "AND s.RUBRO = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor  !== '%'  ? [$vendedor]  : [];
        $rub  = $rubro     !== '%'  ? [$rubro]     : [];

        // Etiqueta del vendedor: nombre completo si está disponible, código en caso contrario
        $selectVendLabel = $cv === 'DESC_VENDEDOR'
            ? "MAX(s.DESC_VENDEDOR) AS vendedor"
            : "s.COD_VENDED AS vendedor";

        $sql = "
            SELECT
                s.COD_VENDED,
                {$selectVendLabel},
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                THEN s.CANTIDAD ELSE 0 END), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfS} {$sfVS} {$sfRS}
            GROUP BY s.COD_VENDED
            ORDER BY facturacion DESC
        ";
        return $this->query($sql, array_merge([$desde, $hasta], $suc, $vend, $rub));
    }

    public function mergeVendedores(array $actual, array $previo): array
    {
        // Relacionar por COD_VENDED
        $prevIdx = [];
        foreach ($previo as $row) {
            $prevIdx[$row['COD_VENDED']] = $row;
        }

        $result    = [];
        $actCodigos = [];
        foreach ($actual as $row) {
            $cod      = $row['COD_VENDED'];
            $prev     = $prevIdx[$cod] ?? null;
            $unidPrev = $prev ? (float)$prev['unidades']    : 0;
            $factPrev = $prev ? (float)$prev['facturacion'] : 0;
            $unidAct  = (float)$row['unidades'];
            $factAct  = (float)$row['facturacion'];

            $actCodigos[] = $cod;
            $result[] = [
                'vendedor'        => $row['vendedor'],   // nombre del período actual
                'unidades'        => $unidAct,
                'unidades_prev'   => $unidPrev,
                'facturacion'     => $factAct,
                'facturacion_prev'=> $factPrev,
                'var_unidades'    => $unidPrev != 0 ? ($unidAct - $unidPrev) / abs($unidPrev) : null,
                'var_facturacion' => $factPrev != 0 ? ($factAct - $factPrev) / abs($factPrev) : null,
            ];
        }

        // Vendedores que solo existen en el período anterior
        foreach ($previo as $row) {
            if (!in_array($row['COD_VENDED'], $actCodigos)) {
                $result[] = [
                    'vendedor'        => $row['vendedor'],
                    'unidades'        => 0,
                    'unidades_prev'   => (float)$row['unidades'],
                    'facturacion'     => 0,
                    'facturacion_prev'=> (float)$row['facturacion'],
                    'var_unidades'    => -1,
                    'var_facturacion' => -1,
                ];
            }
        }

        usort($result, fn($a, $b) => $b['facturacion'] <=> $a['facturacion']);
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  CARDS DE RUBROS ESPECÍFICOS
     * ────────────────────────────────────────────── */

    public function getRubrosCards(
        string $desde,
        string $hasta,
        ?int   $nroSucurs = null,
        array  $targetRubros = [],
        string $vendedor  = '%'
    ): array {
        if (empty($targetRubros)) return [];

        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor  !== '%'  ? "AND s.{$cv} = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor  !== '%'  ? [$vendedor]  : [];

        $placeholders = implode(',', array_fill(0, count($targetRubros), '?'));

        $sql = "
            SELECT
                s.RUBRO,
                ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                THEN s.CANTIDAD ELSE 0 END), 0) AS unidades
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.RUBRO IN ({$placeholders})
              {$sfS} {$sfVS}
            GROUP BY s.RUBRO
        ";
        return $this->query($sql, array_merge([$desde, $hasta], $targetRubros, $suc, $vend));
    }

    public function mergeCards(array $actual, array $previo, array $targetRubros): array
    {
        $actIdx  = array_column($actual, null, 'RUBRO');
        $prevIdx = array_column($previo, null, 'RUBRO');

        $result = [];
        foreach ($targetRubros as $rubro) {
            $unidAct  = isset($actIdx[$rubro])  ? (float)$actIdx[$rubro]['unidades']  : 0;
            $unidPrev = isset($prevIdx[$rubro]) ? (float)$prevIdx[$rubro]['unidades'] : 0;
            $result[] = [
                'rubro'        => $rubro,
                'unidades'     => $unidAct,
                'unidades_prev'=> $unidPrev,
                'variacion'    => $unidPrev != 0 ? ($unidAct - $unidPrev) / abs($unidPrev) : null,
            ];
        }
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  EVOLUCIÓN MENSUAL (UNIDADES O TICKETS) — 3 años dinámicos
     * ────────────────────────────────────────────── */

    /**
     * Devuelve evolución mensual de los últimos 3 años completos.
     * Los años se calculan a partir del año máximo disponible en los datos.
     *
     * @param string $tipo 'unidades' | 'tickets'
     * @return array ['años' => [...], 'meses' => [...], 'series' => [año => [12 valores]]]
     */
    public function getEvolucionMensual(
        ?int   $nroSucurs = null,
        string $vendedor  = '%',
        string $rubro     = '%',
        string $tipo      = 'unidades'
    ): array {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor  !== '%'  ? "AND s.{$cv} = ?" : "";
        $sfRS = $rubro     !== '%'  ? "AND s.RUBRO = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor  !== '%'  ? [$vendedor]  : [];
        $rub  = $rubro     !== '%'  ? [$rubro]     : [];

        if ($tipo === 'tickets') {
            // Tickets FAC
            $sfT  = $nroSucurs !== null ? "AND t.NRO_SUCURS = ?" : "";
            $sfVT = $vendedor  !== '%'  ? "AND t.{$cv} = ?" : "";
            $suc2  = $nroSucurs !== null ? [$nroSucurs] : [];
            $vend2 = $vendedor  !== '%'  ? [$vendedor]  : [];

            $sqlMaxYear = "
                SELECT MAX(YEAR(CAST(t.FECHA AS DATE))) AS max_year
                FROM BI_SALES_TOTAL_TICKETS t
                WHERE t.T_COMP = 'FAC'
                  {$sfT}
            ";
            $rowMax = $this->queryOne($sqlMaxYear, array_merge($suc2));

            $maxYear = (int)($rowMax['max_year'] ?? date('Y'));
            $minYear = $maxYear - 2;

            $sql = "
                SELECT
                    YEAR(CAST(t.FECHA AS DATE))  AS anio,
                    MONTH(CAST(t.FECHA AS DATE)) AS mes,
                    COUNT(DISTINCT t.N_COMP)     AS valor
                FROM BI_SALES_TOTAL_TICKETS t
                WHERE t.T_COMP = 'FAC'
                  AND YEAR(CAST(t.FECHA AS DATE)) BETWEEN ? AND ?
                  {$sfT} {$sfVT}
                GROUP BY YEAR(CAST(t.FECHA AS DATE)), MONTH(CAST(t.FECHA AS DATE))
                ORDER BY 1, 2
            ";
            $rows = $this->query($sql, array_merge([$minYear, $maxYear], $suc2, $vend2));
        } else {
            // Unidades
            $sqlMaxYear = "
                SELECT MAX(YEAR(CAST(s.FECHA AS DATE))) AS max_year
                FROM BI_SALES_SUCURSALES s
                WHERE s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                  {$sfS}
            ";
            $rowMax = $this->queryOne($sqlMaxYear, $suc);

            $maxYear = (int)($rowMax['max_year'] ?? date('Y'));
            $minYear = $maxYear - 2;

            $sql = "
                SELECT
                    YEAR(CAST(s.FECHA AS DATE))  AS anio,
                    MONTH(CAST(s.FECHA AS DATE)) AS mes,
                    ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                                    THEN s.CANTIDAD ELSE 0 END), 0) AS valor
                FROM BI_SALES_SUCURSALES s
                WHERE s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                  AND YEAR(CAST(s.FECHA AS DATE)) BETWEEN ? AND ?
                  {$sfS} {$sfVS} {$sfRS}
                GROUP BY YEAR(CAST(s.FECHA AS DATE)), MONTH(CAST(s.FECHA AS DATE))
                ORDER BY 1, 2
            ";
            $rows = $this->query($sql, array_merge([$minYear, $maxYear], $suc, $vend, $rub));
        }

        // Construir series
        $anios  = range($minYear, $maxYear);
        $series = [];
        foreach ($anios as $y) {
            $series[$y] = array_fill(1, 12, null);
        }
        foreach ($rows as $row) {
            $y = (int)$row['anio'];
            $m = (int)$row['mes'];
            if (isset($series[$y])) {
                $series[$y][$m] = (float)$row['valor'];
            }
        }

        // Convertir a arrays indexados 0-11
        $seriesOut = [];
        foreach ($series as $y => $meses) {
            $seriesOut[] = [
                'anio'    => $y,
                'valores' => array_values($meses),
            ];
        }

        return [
            'anios'  => $anios,
            'meses'  => ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'],
            'series' => $seriesOut,
        ];
    }

    /* ──────────────────────────────────────────────
     *  RANKING RUBROS
     * ────────────────────────────────────────────── */

    public function getRankingRubros(
        string $desde,
        string $hasta,
        ?int   $nroSucurs = null,
        string $vendedor  = '%'
    ): array {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor  !== '%'  ? "AND s.{$cv} = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor  !== '%'  ? [$vendedor]  : [];

        $sql = "
            SELECT
                s.RUBRO,
                ISNULL(SUM(s.CANTIDAD), 0)  AS unidades,
                ISNULL(SUM(s.IMPORTE), 0)   AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfS} {$sfVS}
            GROUP BY s.RUBRO
            ORDER BY unidades DESC
        ";
        return $this->query($sql, array_merge([$desde, $hasta], $suc, $vend));
    }

    /* ──────────────────────────────────────────────
     *  RANKING CATEGORÍAS (drilldown desde ranking rubros)
     * ────────────────────────────────────────────── */

    public function getRankingCategorias(
        string $desde,
        string $hasta,
        ?int   $nroSucurs = null,
        string $vendedor  = '%',
        string $rubro     = ''
    ): array {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfVS = $vendedor  !== '%'  ? "AND s.{$cv} = ?" : "";
        $suc  = $nroSucurs !== null ? [$nroSucurs] : [];
        $vend = $vendedor  !== '%'  ? [$vendedor]  : [];

        $sql = "
            SELECT
                ISNULL(s.CATEGORIA, 'SIN CATEGORÍA') AS CATEGORIA,
                ISNULL(SUM(s.CANTIDAD), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE), 0)  AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.RUBRO = ?
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfS} {$sfVS}
            GROUP BY ISNULL(s.CATEGORIA, 'SIN CATEGORÍA')
            ORDER BY unidades DESC
        ";
        return $this->query($sql, array_merge([$desde, $hasta, $rubro], $suc, $vend));
    }

    /* ──────────────────────────────────────────────
     *  PRODUCTO — RUBRO → CATEGORÍA
     * ────────────────────────────────────────────── */

    public function getRubrosProducto(
        string $desde, string $hasta,
        ?int $nroSucurs = null, string $vendedor = '%',
        string $rubro = '%', string $categoria = '%'
    ): array {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfV  = $vendedor  !== '%'  ? "AND s.{$cv} = ?" : "";
        $sfR  = $rubro     !== '%'  ? "AND s.RUBRO = ?" : "";
        $sfC  = $categoria !== '%'  ? "AND s.CATEGORIA = ?" : "";
        $pS   = $nroSucurs !== null ? [$nroSucurs] : [];
        $pV   = $vendedor  !== '%'  ? [$vendedor]  : [];
        $pR   = $rubro     !== '%'  ? [$rubro]     : [];
        $pC   = $categoria !== '%'  ? [$categoria] : [];

        return $this->query("
            SELECT
                s.RUBRO,
                ISNULL(s.CATEGORIA, 'SIN CATEGORÍA') AS CATEGORIA,
                ISNULL(SUM(s.CANTIDAD), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE),  0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfS} {$sfV} {$sfR} {$sfC}
            GROUP BY s.RUBRO, ISNULL(s.CATEGORIA, 'SIN CATEGORÍA')
            ORDER BY s.RUBRO, ISNULL(s.CATEGORIA, 'SIN CATEGORÍA')
        ", array_merge([$desde, $hasta], $pS, $pV, $pR, $pC));
    }

    public function getColoresProducto(
        string $desde, string $hasta,
        ?int $nroSucurs = null, string $vendedor = '%',
        string $rubro = '%', string $categoria = '%'
    ): array {
        $cv   = $this->campoVendedor;
        $sfS  = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfV  = $vendedor  !== '%'  ? "AND s.{$cv} = ?" : "";
        $sfR  = $rubro     !== '%'  ? "AND s.RUBRO = ?" : "";
        $sfC  = $categoria !== '%'  ? "AND s.CATEGORIA = ?" : "";
        $pS   = $nroSucurs !== null ? [$nroSucurs] : [];
        $pV   = $vendedor  !== '%'  ? [$vendedor]  : [];
        $pR   = $rubro     !== '%'  ? [$rubro]     : [];
        $pC   = $categoria !== '%'  ? [$categoria] : [];

        try {
            return $this->query("
                SELECT
                    ISNULL(s.COLOR, 'SIN COLOR') AS COLOR,
                    ISNULL(SUM(s.CANTIDAD), 0) AS unidades,
                    ISNULL(SUM(s.IMPORTE),  0) AS facturacion
                FROM BI_SALES_SUCURSALES s
                WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
                  AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
                  {$sfS} {$sfV} {$sfR} {$sfC}
                GROUP BY ISNULL(s.COLOR, 'SIN COLOR')
                ORDER BY unidades DESC
            ", array_merge([$desde, $hasta], $pS, $pV, $pR, $pC));
        } catch (\Throwable $_) {
            return [];   // COLOR column may not exist in all origins
        }
    }

    public function getSucursalesProducto(
        string $desde, string $hasta,
        string $vendedor = '%', string $rubro = '%', string $categoria = '%'
    ): array {
        $cv  = $this->campoVendedor;
        $sfV = $vendedor  !== '%' ? "AND s.{$cv} = ?" : "";
        $sfR = $rubro     !== '%' ? "AND s.RUBRO = ?" : "";
        $sfC = $categoria !== '%' ? "AND s.CATEGORIA = ?" : "";
        $pV  = $vendedor  !== '%' ? [$vendedor]  : [];
        $pR  = $rubro     !== '%' ? [$rubro]     : [];
        $pC  = $categoria !== '%' ? [$categoria] : [];

        return $this->query("
            SELECT
                s.NRO_SUCURS,
                ISNULL(SUM(s.CANTIDAD), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE),  0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfV} {$sfR} {$sfC}
            GROUP BY s.NRO_SUCURS
            ORDER BY unidades DESC
        ", array_merge([$desde, $hasta], $pV, $pR, $pC));
    }

    public function getTopCategoriasProducto(
        string $desde, string $hasta,
        ?int $nroSucurs = null, string $vendedor = '%', string $rubro = '%'
    ): array {
        $cv  = $this->campoVendedor;
        $sfS = $nroSucurs !== null ? "AND s.NRO_SUCURS = ?" : "";
        $sfV = $vendedor  !== '%'  ? "AND s.{$cv} = ?" : "";
        $sfR = $rubro     !== '%'  ? "AND s.RUBRO = ?" : "";
        $pS  = $nroSucurs !== null ? [$nroSucurs] : [];
        $pV  = $vendedor  !== '%'  ? [$vendedor]  : [];
        $pR  = $rubro     !== '%'  ? [$rubro]     : [];

        return $this->query("
            SELECT TOP 10
                s.RUBRO,
                ISNULL(s.CATEGORIA, 'SIN CATEGORÍA') AS CATEGORIA,
                ISNULL(SUM(s.CANTIDAD), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE),  0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE CAST(s.FECHA AS DATE) BETWEEN ? AND ?
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
              {$sfS} {$sfV} {$sfR}
            GROUP BY s.RUBRO, ISNULL(s.CATEGORIA, 'SIN CATEGORÍA')
            ORDER BY unidades DESC
        ", array_merge([$desde, $hasta], $pS, $pV, $pR));
    }

    /* ──────────────────────────────────────────────
     *  PRODUCTO — STOCK
     * ────────────────────────────────────────────── */

    /**
     * Stock de locales/franquicias agrupado por RUBRO + CATEGORIA.
     * Usa BI_STOCK_FRANQUICIAS para tipo FRANQUICIA, BI_STOCK_LOCALES para el resto.
     */
    public function getStockLocalesProducto(
        string $rubro = '%', string $categoria = '%'
    ): array {
        $tipo  = $_SESSION['tipo'] ?? 'LOCAL_PROPIO';
        $tabla = ($tipo === 'FRANQUICIA') ? 'BI_STOCK_FRANQUICIAS' : 'BI_STOCK_LOCALES';
        $sfR   = $rubro     !== '%' ? "AND ISNULL(RUBRO,'SIN RUBRO') = ?" : "";
        $sfC   = $categoria !== '%' ? "AND ISNULL(CATEGORIA,'SIN CATEGORÍA') = ?" : "";
        $pR    = $rubro     !== '%' ? [$rubro]     : [];
        $pC    = $categoria !== '%' ? [$categoria] : [];
        try {
            return $this->query("
                SELECT
                    ISNULL(RUBRO, 'SIN RUBRO')          AS RUBRO,
                    ISNULL(CATEGORIA, 'SIN CATEGORÍA')  AS CATEGORIA,
                    ISNULL(SUM(CANT_STOCK), 0)          AS stock_local
                FROM {$tabla}
                WHERE 1=1 {$sfR} {$sfC}
                GROUP BY ISNULL(RUBRO,'SIN RUBRO'), ISNULL(CATEGORIA,'SIN CATEGORÍA')
            ", array_merge($pR, $pC));
        } catch (\Throwable $e) { return []; }
    }

    /**
     * Stock central agrupado por RUBRO + CATEGORIA (usa STOCK_DISPONIBLE).
     */
    public function getStockCentralProducto(
        string $rubro = '%', string $categoria = '%'
    ): array {
        $sfR = $rubro     !== '%' ? "AND ISNULL(RUBRO,'SIN RUBRO') = ?" : "";
        $sfC = $categoria !== '%' ? "AND ISNULL(CATEGORIA,'SIN CATEGORÍA') = ?" : "";
        $pR  = $rubro     !== '%' ? [$rubro]     : [];
        $pC  = $categoria !== '%' ? [$categoria] : [];
        try {
            return $this->query("
                SELECT
                    ISNULL(RUBRO, 'SIN RUBRO')          AS RUBRO,
                    ISNULL(CATEGORIA, 'SIN CATEGORÍA')  AS CATEGORIA,
                    ISNULL(SUM(STOCK_DISPONIBLE), 0)    AS stock_central
                FROM BI_STOCK_CENTRAL
                WHERE 1=1 {$sfR} {$sfC}
                GROUP BY ISNULL(RUBRO,'SIN RUBRO'), ISNULL(CATEGORIA,'SIN CATEGORÍA')
            ", array_merge($pR, $pC));
        } catch (\Throwable $e) { return []; }
    }

    /**
     * Stock de locales/franquicias agrupado por COLOR.
     */
    public function getStockLocalesColores(
        string $rubro = '%', string $categoria = '%'
    ): array {
        $tipo  = $_SESSION['tipo'] ?? 'LOCAL_PROPIO';
        $tabla = ($tipo === 'FRANQUICIA') ? 'BI_STOCK_FRANQUICIAS' : 'BI_STOCK_LOCALES';
        $sfR   = $rubro     !== '%' ? "AND ISNULL(RUBRO,'SIN RUBRO') = ?" : "";
        $sfC   = $categoria !== '%' ? "AND ISNULL(CATEGORIA,'SIN CATEGORÍA') = ?" : "";
        $pR    = $rubro     !== '%' ? [$rubro]     : [];
        $pC    = $categoria !== '%' ? [$categoria] : [];
        try {
            return $this->query("
                SELECT
                    ISNULL(COLOR, 'SIN COLOR') AS COLOR,
                    ISNULL(SUM(CANT_STOCK), 0) AS stock_local
                FROM {$tabla}
                WHERE 1=1 {$sfR} {$sfC}
                GROUP BY ISNULL(COLOR, 'SIN COLOR')
                ORDER BY stock_local DESC
            ", array_merge($pR, $pC));
        } catch (\Throwable $e) { return []; }
    }

    /* ──────────────────────────────────────────────
     *  PRIVADOS
     * ────────────────────────────────────────────── */

    private function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }
}
