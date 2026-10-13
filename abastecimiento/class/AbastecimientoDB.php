<?php
/**
 * /bi/abastecimiento/class/AbastecimientoDB.php
 * Capa de acceso a datos para el Dashboard de Abastecimiento.
 * Conexiones a:
 *  - 'central' (XL-TANGO / LAKER_SA): STA14, STA20, STOCK_CENTRAL, SOF_MAESTRO_ARTICULOS_RUBRO_CATEGORIA
 *  - 'power'   (XL-APPS / POWER_BI_CONTROL): RO_VENTAS_COMERCIAL, BI_STOCK_LOCALES
 *  - 'locales' (XL-LAKERBIS / locales_lakers): BI_ROTACION_VENTAS
 */

require_once __DIR__ . '/../../class/conexion.php';

class AbastecimientoDB
{
    private $conexion;
    private $timeoutSeconds = 60;
    private static $cacheDir;

    public function __construct()
    {
        $this->conexion = new Conexion();
        self::$cacheDir = __DIR__ . '/../../scratch';
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0777, true);
        }
    }

    public function setTimeoutSeconds(int $sec): void
    {
        $this->timeoutSeconds = $sec;
    }

    private function getConn(string $server)
    {
        $conn = $this->conexion->conectar($server);
        if (!$conn) {
            throw new RuntimeException("Error al conectar con el servidor '$server'");
        }
        return $conn;
    }

    /**
     * Construye cláusulas WHERE a partir de array de filtros
     * Excluye estrictamente PACKAGING en todos los contextos.
     */
    private function buildFilterConditions(array $filters, string $artField, string $rubField, string $catField, string $tempField, string $provField, string $canalField = null, string $sucField = null, string $dateField = null): array
    {
        $where = [];
        $params = [];

        // Excluir PACKAGING siempre
        $where[] = "($rubField IS NULL OR ($rubField <> 'PACKAGING' AND $rubField NOT LIKE '%PACKAGING%'))";

        if (!empty($filters['proveedor'])) {
            $provs = is_array($filters['proveedor']) ? $filters['proveedor'] : [$filters['proveedor']];
            $provs = array_filter(array_map('trim', $provs));
            if (!empty($provs)) {
                $ph = implode(',', array_fill(0, count($provs), '?'));
                $where[] = "$provField IN ($ph)";
                $params = array_merge($params, $provs);
            }
        }

        if (!empty($filters['rubro'])) {
            $rubs = is_array($filters['rubro']) ? $filters['rubro'] : [$filters['rubro']];
            $rubs = array_filter(array_map('trim', $rubs));
            if (!empty($rubs)) {
                $ph = implode(',', array_fill(0, count($rubs), '?'));
                $where[] = "$rubField IN ($ph)";
                $params = array_merge($params, $rubs);
            }
        }

        if (!empty($filters['categoria'])) {
            $cats = is_array($filters['categoria']) ? $filters['categoria'] : [$filters['categoria']];
            $cats = array_filter(array_map('trim', $cats));
            if (!empty($cats)) {
                $ph = implode(',', array_fill(0, count($cats), '?'));
                $where[] = "$catField IN ($ph)";
                $params = array_merge($params, $cats);
            }
        }

        if (!empty($filters['temporada'])) {
            $temps = is_array($filters['temporada']) ? $filters['temporada'] : [$filters['temporada']];
            $temps = array_filter(array_map('trim', $temps));
            if (!empty($temps)) {
                $ph = implode(',', array_fill(0, count($temps), '?'));
                $where[] = "$tempField IN ($ph)";
                $params = array_merge($params, $temps);
            }
        }

        if ($canalField && !empty($filters['canal'])) {
            $canals = is_array($filters['canal']) ? $filters['canal'] : [$filters['canal']];
            $canals = array_filter(array_map('trim', $canals));
            if (!empty($canals)) {
                $ph = implode(',', array_fill(0, count($canals), '?'));
                $where[] = "$canalField IN ($ph)";
                $params = array_merge($params, $canals);
            }
        }

        if ($sucField && !empty($filters['sucursal'])) {
            $sucs = is_array($filters['sucursal']) ? $filters['sucursal'] : [$filters['sucursal']];
            $sucs = array_filter(array_map('trim', $sucs));
            if (!empty($sucs)) {
                $ph = implode(',', array_fill(0, count($sucs), '?'));
                $where[] = "$sucField IN ($ph)";
                $params = array_merge($params, $sucs);
            }
        }

        if (!empty($filters['articulo'])) {
            $art = trim($filters['articulo']);
            $where[] = "$artField LIKE ?";
            $params[] = "%$art%";
        }

        if (!empty($filters['descripcion'])) {
            $desc = trim($filters['descripcion']);
            $where[] = "($artField LIKE ? OR $rubField LIKE ? OR $catField LIKE ?)";
            $params[] = "%$desc%";
            $params[] = "%$desc%";
            $params[] = "%$desc%";
        }

        if ($dateField) {
            $fechaDesde = !empty($filters['fecha_desde']) ? $filters['fecha_desde'] : date('Y-01-01');
            $where[] = "$dateField >= ?";
            $params[] = $fechaDesde;

            if (!empty($filters['fecha_hasta'])) {
                $where[] = "$dateField <= ?";
                $params[] = $filters['fecha_hasta'];
            }
        }

        return ['sql' => !empty($where) ? ' AND ' . implode(' AND ', $where) : '', 'params' => $params];
    }

    /**
     * Obtener listas para filtros con exclusión de PACKAGING
     */
    public function getFiltros(bool $forceRefresh = false): array
    {
        $cacheFile = self::$cacheDir . '/abast_filtros_cache_v2.json';
        if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (!empty($cached)) {
                return $cached;
            }
        }

        $connCentral = $this->getConn('central');
        $connPower   = $this->getConn('power');

        // Proveedores, Rubros, Categorías, Temporadas de Tango (sin PACKAGING)
        $sqlMaestro = "
            SELECT DISTINCT
                NULLIF(LTRIM(RTRIM(PROVEEDOR)), '') AS PROVEEDOR,
                NULLIF(LTRIM(RTRIM(RUBRO)), '') AS RUBRO,
                NULLIF(LTRIM(RTRIM(CATEGORIA)), '') AS CATEGORIA,
                NULLIF(LTRIM(RTRIM(TEMPORADA)), '') AS TEMPORADA
            FROM SOF_MAESTRO_ARTICULOS_RUBRO_CATEGORIA WITH (NOLOCK)
            WHERE RUBRO IS NULL OR (RUBRO <> 'PACKAGING' AND RUBRO NOT LIKE '%PACKAGING%')
        ";
        $stmtM = sqlsrv_query($connCentral, $sqlMaestro);
        $proveedores = [];
        $rubros = [];
        $categorias = [];
        $temporadas = [];

        if ($stmtM) {
            while ($row = sqlsrv_fetch_array($stmtM, SQLSRV_FETCH_ASSOC)) {
                if ($row['PROVEEDOR'])  $proveedores[$row['PROVEEDOR']] = true;
                if ($row['RUBRO'])      $rubros[$row['RUBRO']] = true;
                if ($row['CATEGORIA'])  $categorias[$row['CATEGORIA']] = true;
                if ($row['TEMPORADA'])  $temporadas[$row['TEMPORADA']] = true;
            }
            sqlsrv_free_stmt($stmtM);
        }

        $canales = ['CENTRAL' => true, 'FRANQUICIAS' => true, 'FRANQUICIAS GA' => true, 'LOCALES PROPIOS' => true, 'ECOMMERCE' => true, 'MAYORISTAS' => true];

        // Sucursales desde BI_STOCK_LOCALES (sin PACKAGING)
        $sucursales = [];
        $sqlSucs = "
            SELECT DISTINCT NULLIF(LTRIM(RTRIM(SUCURSAL)), '') AS SUCURSAL 
            FROM BI_STOCK_LOCALES WITH (NOLOCK)
            WHERE SUCURSAL IS NOT NULL
              AND (RUBRO IS NULL OR RUBRO <> 'PACKAGING')
        ";
        $stmtS = sqlsrv_query($connPower, $sqlSucs);
        if ($stmtS) {
            while ($row = sqlsrv_fetch_array($stmtS, SQLSRV_FETCH_ASSOC)) {
                if ($row['SUCURSAL']) $sucursales[$row['SUCURSAL']] = true;
            }
            sqlsrv_free_stmt($stmtS);
        }

        ksort($proveedores);
        ksort($rubros);
        ksort($categorias);
        ksort($temporadas);
        ksort($canales);
        ksort($sucursales);

        $res = [
            'proveedores' => array_keys($proveedores),
            'rubros'      => array_keys($rubros),
            'categorias'  => array_keys($categorias),
            'temporadas'  => array_keys($temporadas),
            'canales'     => array_keys($canales),
            'sucursales'  => array_keys($sucursales),
        ];

        @file_put_contents($cacheFile, json_encode($res, JSON_UNESCAPED_UNICODE));
        return $res;
    }

    /**
     * Totales KPI (sin PACKAGING)
     */
    public function getKPIs(array $filters = []): array
    {
        $connCentral = $this->getConn('central');
        $connPower   = $this->getConn('power');

        // 1. Ingresos
        $fIng = $this->buildFilterConditions($filters, 'B.COD_ARTICU', 'M.RUBRO', 'M.CATEGORIA', 'M.TEMPORADA', 'A.COD_PRO_CL', null, null, 'A.FECHA_MOV');
        $sqlIngresos = "
            SELECT 
                ISNULL(SUM(CAST(B.CANTIDAD AS FLOAT)), 0) AS total_ingresos,
                COUNT(DISTINCT A.N_COMP) AS cant_comprobantes
            FROM STA14 A WITH (NOLOCK)
            INNER JOIN STA20 B WITH (NOLOCK) 
                ON A.NCOMP_IN_S = B.NCOMP_IN_S AND A.TCOMP_IN_S = B.TCOMP_IN_S
            LEFT JOIN SOF_MAESTRO_ARTICULOS_RUBRO_CATEGORIA M WITH (NOLOCK)
                ON B.COD_ARTICU = M.COD_ARTICU
            WHERE A.TALONARIO = '200'
              {$fIng['sql']}
        ";
        $stmtIng = sqlsrv_query($connCentral, $sqlIngresos, $fIng['params']);
        $rowIng = $stmtIng ? sqlsrv_fetch_array($stmtIng, SQLSRV_FETCH_ASSOC) : ['total_ingresos' => 0, 'cant_comprobantes' => 0];
        if ($stmtIng) sqlsrv_free_stmt($stmtIng);

        // 2. Ventas
        $fVta = $this->buildFilterConditions($filters, 'V.ARTICULO', 'V.RUBRO', 'V.CATEGORIA', 'V.TEMPORADA', 'V.COD_CLIENT', 'V.CANAL', 'V.DESC_SUCURSAL', 'V.FECHA_VENTA');
        $sqlVentas = "
            SELECT 
                ISNULL(SUM(CAST(V.CANT_VEND AS FLOAT)), 0) AS total_ventas,
                -- Venta Central a Mayoristas: CANAL CENTRAL y DESC_SUCURSAL MAYORISTAS
                ISNULL(SUM(CASE WHEN (V.CANAL = 'CENTRAL' OR V.CANAL LIKE '%CEN%') AND V.DESC_SUCURSAL LIKE '%MAYOR%' THEN CAST(V.CANT_VEND AS FLOAT) ELSE 0 END), 0) AS venta_cen_my,
                -- Venta Central a Franquicias: CANAL CENTRAL y DESC_SUCURSAL FRANQUICIAS
                ISNULL(SUM(CASE WHEN (V.CANAL = 'CENTRAL' OR V.CANAL LIKE '%CEN%') AND V.DESC_SUCURSAL LIKE '%FRANQ%' THEN CAST(V.CANT_VEND AS FLOAT) ELSE 0 END), 0) AS venta_cen_fq,
                -- Venta Propios: CANAL PP
                ISNULL(SUM(CASE WHEN (V.CANAL = 'PP' OR V.CANAL LIKE '%PROPIO%') THEN CAST(V.CANT_VEND AS FLOAT) ELSE 0 END), 0) AS venta_pp,
                -- Venta Franquicias: CANAL FQ
                ISNULL(SUM(CASE WHEN (V.CANAL = 'FQ' OR V.CANAL LIKE '%FRANQ%') AND (V.CANAL <> 'CENTRAL' AND V.CANAL NOT LIKE '%CEN%') THEN CAST(V.CANT_VEND AS FLOAT) ELSE 0 END), 0) AS venta_fq
            FROM RO_VENTAS_COMERCIAL V WITH (NOLOCK)
            WHERE 1=1
              {$fVta['sql']}
        ";
        $stmtVta = sqlsrv_query($connPower, $sqlVentas, $fVta['params']);
        $rowVta = $stmtVta ? sqlsrv_fetch_array($stmtVta, SQLSRV_FETCH_ASSOC) : ['total_ventas' => 0, 'venta_cen_my' => 0, 'venta_cen_fq' => 0, 'venta_pp' => 0, 'venta_fq' => 0];
        if ($stmtVta) sqlsrv_free_stmt($stmtVta);

        // 3. Stock Sucursales
        $fStkLoc = $this->buildFilterConditions($filters, 'S.COD_ARTICULO', 'S.RUBRO', 'S.CATEGORIA', 'S.RUBRO', 'S.PROVEEDOR', null, 'S.SUCURSAL');
        $sqlStockLoc = "
            SELECT ISNULL(SUM(CAST(S.CANT_STOCK AS FLOAT)), 0) AS stock_sucursal
            FROM BI_STOCK_LOCALES S WITH (NOLOCK)
            WHERE 1=1
              {$fStkLoc['sql']}
        ";
        $stmtStkLoc = sqlsrv_query($connPower, $sqlStockLoc, $fStkLoc['params']);
        $rowStkLoc = $stmtStkLoc ? sqlsrv_fetch_array($stmtStkLoc, SQLSRV_FETCH_ASSOC) : ['stock_sucursal' => 0];
        if ($stmtStkLoc) sqlsrv_free_stmt($stmtStkLoc);

        // 4. Stock Central
        $fStkCen = $this->buildFilterConditions($filters, 'C.COD_ARTICU', 'C.RUBRO', 'C.CATEGORIA', 'C.TEMPORADA', 'C.RUBRO');
        $sqlStockCen = "
            SELECT 
                ISNULL(SUM(CAST(C.STOCK_DISPONIBLE AS FLOAT)), 0) AS stock_central_disponible,
                ISNULL(SUM(CAST(C.STOCK_TOTAL AS FLOAT)), 0) AS stock_central_total,
                ISNULL(SUM(CAST(C.CANT_COMP AS FLOAT)), 0) AS pedidos_abiertos
            FROM STOCK_CENTRAL C WITH (NOLOCK)
            WHERE 1=1
              {$fStkCen['sql']}
        ";
        $stmtStkCen = sqlsrv_query($connCentral, $sqlStockCen, $fStkCen['params']);
        $rowStkCen = $stmtStkCen ? sqlsrv_fetch_array($stmtStkCen, SQLSRV_FETCH_ASSOC) : ['stock_central_disponible' => 0, 'stock_central_total' => 0, 'pedidos_abiertos' => 0];
        if ($stmtStkCen) sqlsrv_free_stmt($stmtStkCen);

        return [
            'total_ingresos'           => (float)$rowIng['total_ingresos'],
            'cant_comprobantes'        => (int)$rowIng['cant_comprobantes'],
            'total_ventas'             => (float)$rowVta['total_ventas'],
            'venta_cen_my'             => (float)$rowVta['venta_cen_my'],
            'venta_cen_fq'             => (float)$rowVta['venta_cen_fq'],
            'venta_pp'                 => (float)$rowVta['venta_pp'],
            'venta_fq'                 => (float)$rowVta['venta_fq'],
            'stock_sucursal'           => (float)$rowStkLoc['stock_sucursal'],
            'stock_central_disponible' => (float)$rowStkCen['stock_central_disponible'],
            'stock_central_total'      => (float)$rowStkCen['stock_central_total'],
            'pedidos_abiertos'         => (float)$rowStkCen['pedidos_abiertos'],
        ];
    }

    /**
     * Detalle de Ingresos (Columna 1) - Ampliado a 500 registros
     */
    public function getIngresos(array $filters = [], int $limit = 500): array
    {
        $conn = $this->getConn('central');
        $f = $this->buildFilterConditions($filters, 'B.COD_ARTICU', 'M.RUBRO', 'M.CATEGORIA', 'M.TEMPORADA', 'A.COD_PRO_CL', null, null, 'A.FECHA_MOV');

        $sql = "
            SELECT TOP ($limit)
                CONVERT(varchar(10), CAST(A.FECHA_MOV AS DATE), 103) AS FECHA_MOV_FMT,
                A.COD_PRO_CL,
                A.N_COMP,
                B.COD_ARTICU,
                ISNULL(M.DESCRIPCION, '') AS DESCRIPCION,
                ISNULL(M.COLOR, '') AS COLOR,
                ISNULL(M.PROVEEDOR, A.COD_PRO_CL) AS PROVEEDOR,
                CAST(B.CANTIDAD AS FLOAT) AS CANTIDAD,
                ISNULL(M.MODELO, '') AS MODELO
            FROM STA14 A WITH (NOLOCK)
            INNER JOIN STA20 B WITH (NOLOCK) 
                ON A.NCOMP_IN_S = B.NCOMP_IN_S AND A.TCOMP_IN_S = B.TCOMP_IN_S
            LEFT JOIN SOF_MAESTRO_ARTICULOS_RUBRO_CATEGORIA M WITH (NOLOCK) 
                ON B.COD_ARTICU = M.COD_ARTICU
            WHERE A.TALONARIO = '200'
              {$f['sql']}
            ORDER BY A.FECHA_MOV DESC
        ";

        $stmt = sqlsrv_query($conn, $sql, $f['params']);
        $rows = [];
        if ($stmt) {
            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = [
                    'fecha_mov'   => $r['FECHA_MOV_FMT'],
                    'cod_articu'  => trim($r['COD_ARTICU']),
                    'descripcion' => trim($r['DESCRIPCION']),
                    'color'       => trim($r['COLOR']),
                    'cod_pro_cl'  => trim($r['COD_PRO_CL']),
                    'proveedor'   => trim($r['PROVEEDOR']),
                    'cantidad'    => (float)$r['CANTIDAD'],
                    'n_comp'      => trim($r['N_COMP']),
                    'modelo'      => trim($r['MODELO']),
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
        return $rows;
    }

    /**
     * Matriz de Ventas x Artículo y Mes/Canal - Ampliado a 300 artículos
     */
    public function getVentasMatriz(array $filters = [], int $limit = 300): array
    {
        $conn = $this->getConn('power');
        $f = $this->buildFilterConditions($filters, 'V.ARTICULO', 'V.RUBRO', 'V.CATEGORIA', 'V.TEMPORADA', 'V.COD_CLIENT', 'V.CANAL', 'V.DESC_SUCURSAL', 'V.FECHA_VENTA');

        $sql = "
            SELECT TOP 10000
                V.ARTICULO,
                YEAR(V.FECHA_VENTA) AS ANIO,
                MONTH(V.FECHA_VENTA) AS MES,
                CASE 
                    WHEN (V.CANAL = 'CENTRAL' OR V.CANAL LIKE '%CEN%') THEN 'CEN'
                    WHEN V.CANAL LIKE '%PROPIO%' OR V.CANAL = 'PP' THEN 'PP'
                    WHEN V.CANAL LIKE '%FRANQ%' OR V.CANAL = 'FQ' THEN 'FQ'
                    ELSE ISNULL(V.CANAL, 'OTRO')
                END AS CANAL,
                CASE 
                    WHEN (V.CANAL = 'CENTRAL' OR V.CANAL LIKE '%CEN%') AND V.DESC_SUCURSAL LIKE '%MAYOR%' THEN 'MY'
                    WHEN (V.CANAL = 'CENTRAL' OR V.CANAL LIKE '%CEN%') AND V.DESC_SUCURSAL LIKE '%FRANQ%' THEN 'FQ'
                    WHEN (V.CANAL = 'CENTRAL' OR V.CANAL LIKE '%CEN%') THEN 'OTRO'
                    ELSE ''
                END AS SUBCANAL,
                SUM(CAST(V.CANT_VEND AS FLOAT)) AS CANTIDAD
            FROM RO_VENTAS_COMERCIAL V WITH (NOLOCK)
            WHERE 1=1
              {$f['sql']}
            GROUP BY 
                V.ARTICULO, 
                YEAR(V.FECHA_VENTA), 
                MONTH(V.FECHA_VENTA), 
                CASE 
                    WHEN (V.CANAL = 'CENTRAL' OR V.CANAL LIKE '%CEN%') THEN 'CEN'
                    WHEN V.CANAL LIKE '%PROPIO%' OR V.CANAL = 'PP' THEN 'PP'
                    WHEN V.CANAL LIKE '%FRANQ%' OR V.CANAL = 'FQ' THEN 'FQ'
                    ELSE ISNULL(V.CANAL, 'OTRO')
                END,
                CASE 
                    WHEN (V.CANAL = 'CENTRAL' OR V.CANAL LIKE '%CEN%') AND V.DESC_SUCURSAL LIKE '%MAYOR%' THEN 'MY'
                    WHEN (V.CANAL = 'CENTRAL' OR V.CANAL LIKE '%CEN%') AND V.DESC_SUCURSAL LIKE '%FRANQ%' THEN 'FQ'
                    WHEN (V.CANAL = 'CENTRAL' OR V.CANAL LIKE '%CEN%') THEN 'OTRO'
                    ELSE ''
                END
            ORDER BY CANTIDAD DESC
        ";

        $stmt = sqlsrv_query($conn, $sql, $f['params']);
        $matrix = [];
        $hierarchy = [];

        $nombresMes = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
            7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];

        if ($stmt) {
            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $art      = trim($r['ARTICULO']);
                $anio     = (int)$r['ANIO'];
                $mes      = (int)$r['MES'];
                $canal    = trim($r['CANAL']);
                $subcanal = trim($r['SUBCANAL']);
                $cant     = (float)$r['CANTIDAD'];

                if (!isset($hierarchy[$anio])) {
                    $hierarchy[$anio] = ['anio' => $anio, 'meses' => []];
                }
                if (!isset($hierarchy[$anio]['meses'][$mes])) {
                    $hierarchy[$anio]['meses'][$mes] = [
                        'mes'    => $mes,
                        'nombre' => $nombresMes[$mes] ?? (string)$mes,
                        'canales'=> []
                    ];
                }
                if (!isset($hierarchy[$anio]['meses'][$mes]['canales'][$canal])) {
                    $hierarchy[$anio]['meses'][$mes]['canales'][$canal] = [
                        'canal'      => $canal,
                        'subcanales' => []
                    ];
                }
                if ($subcanal && !in_array($subcanal, $hierarchy[$anio]['meses'][$mes]['canales'][$canal]['subcanales'], true)) {
                    $hierarchy[$anio]['meses'][$mes]['canales'][$canal]['subcanales'][] = $subcanal;
                }

                $key = "{$anio}_{$mes}_{$canal}" . ($subcanal ? "_{$subcanal}" : "");
                if (!isset($matrix[$art])) {
                    $matrix[$art] = ['articulo' => $art, 'total' => 0, 'data' => []];
                }
                $matrix[$art]['data'][$key] = ($matrix[$art]['data'][$key] ?? 0) + $cant;
                // Sumar también a nivel canal directo
                $keyCanal = "{$anio}_{$mes}_{$canal}";
                if ($subcanal) {
                    $matrix[$art]['data'][$keyCanal] = ($matrix[$art]['data'][$keyCanal] ?? 0) + $cant;
                }
                $matrix[$art]['total'] += $cant;
            }
            sqlsrv_free_stmt($stmt);
        }

        uasort($matrix, fn($a, $b) => $b['total'] <=> $a['total']);
        $matrix = array_slice($matrix, 0, $limit, true);

        // Ordenar jerarquía de años, meses y canales
        ksort($hierarchy);
        foreach ($hierarchy as &$y) {
            ksort($y['meses']);
            foreach ($y['meses'] as &$m) {
                ksort($m['canales']);
                foreach ($m['canales'] as &$c) {
                    sort($c['subcanales']);
                }
                $m['canales'] = array_values($m['canales']);
            }
        }

        return [
            'hierarchy' => array_values($hierarchy),
            'filas'     => array_values($matrix)
        ];
    }

    /**
     * Stock por Sucursal - Ampliado a 500 registros
     */
    public function getStockSucursales(array $filters = [], int $limit = 500): array
    {
        $conn = $this->getConn('power');
        $f = $this->buildFilterConditions($filters, 'S.COD_ARTICULO', 'S.RUBRO', 'S.CATEGORIA', 'S.RUBRO', 'S.PROVEEDOR', null, 'S.SUCURSAL');

        $sql = "
            SELECT TOP ($limit)
                S.COD_ARTICULO AS ARTICULO,
                ISNULL(S.SUCURSAL, '') AS SUCURSAL,
                ISNULL(S.COLOR, '') AS COLOR,
                CAST(S.CANT_STOCK AS FLOAT) AS STOCK_SUCURSAL
            FROM BI_STOCK_LOCALES S WITH (NOLOCK)
            WHERE S.CANT_STOCK > 0
              {$f['sql']}
            ORDER BY S.COD_ARTICULO ASC, S.CANT_STOCK DESC
        ";

        $stmt = sqlsrv_query($conn, $sql, $f['params']);
        $rows = [];
        if ($stmt) {
            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = [
                    'articulo'       => trim($r['ARTICULO']),
                    'sucursal'       => trim($r['SUCURSAL']),
                    'color'          => trim($r['COLOR']),
                    'stock_sucursal' => (float)$r['STOCK_SUCURSAL'],
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
        return $rows;
    }

    /**
     * Stock Central - Ampliado a 500 registros
     */
    public function getStockCentral(array $filters = [], int $limit = 500): array
    {
        $conn = $this->getConn('central');
        $f = $this->buildFilterConditions($filters, 'C.COD_ARTICU', 'C.RUBRO', 'C.CATEGORIA', 'C.TEMPORADA', 'C.RUBRO');

        $sql = "
            SELECT TOP ($limit)
                C.COD_ARTICU AS ARTICULO,
                ISNULL(C.COLOR, '') AS COLOR,
                CAST(C.STOCK_DISPONIBLE AS FLOAT) AS DISPONIBLE,
                CAST(C.CANT_RESERVA AS FLOAT) AS TEMPORAL
            FROM STOCK_CENTRAL C WITH (NOLOCK)
            WHERE (C.STOCK_DISPONIBLE > 0 OR C.CANT_RESERVA > 0)
              {$f['sql']}
            ORDER BY C.STOCK_DISPONIBLE DESC
        ";

        $stmt = sqlsrv_query($conn, $sql, $f['params']);
        $rows = [];
        if ($stmt) {
            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $rows[] = [
                    'articulo'   => trim($r['ARTICULO']),
                    'color'      => trim($r['COLOR']),
                    'disponible' => (float)$r['DISPONIBLE'],
                    'temporal'   => (float)$r['TEMPORAL'],
                ];
            }
            sqlsrv_free_stmt($stmt);
        }
        return $rows;
    }

    /**
     * Resuelve y localiza la foto real del artículo en W:\Imagenes
     */
    public function getImagenPath(string $codArticu): ?string
    {
        $codArticu = trim($codArticu);
        if (empty($codArticu)) return null;

        $baseImgPath = 'W:\\Imagenes\\';
        $codSanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $codArticu);
        
        // Candidatos directos
        $candidates = [
            $codArticu . '.jpg',
            $codArticu . '.png',
            $codSanitized . '.jpg',
            $codSanitized . '.png',
            str_replace(' ', '', $codArticu) . '.jpg',
            str_replace(' ', '-', $codArticu) . '.jpg',
        ];

        // Quitar posibles sufijos de talle/color (L, M, S, XL, U) al final si mide más de 12 chars
        if (strlen($codSanitized) >= 12 && preg_match('/^(.*[0-9]{2,4})[A-Z]{1,2}$/', $codSanitized, $m)) {
            $candidates[] = $m[1] . '.jpg';
            $candidates[] = $m[1] . '.png';
        }

        foreach ($candidates as $cand) {
            if (file_exists($baseImgPath . $cand)) {
                return $baseImgPath . $cand;
            }
        }

        // Regex para variación de temporada/letra de colección (ej: XV6SDC03E0701 -> XV6SDC03C0701)
        if (preg_match('/^([A-Z0-9]{8})[A-Z0-9]([0-9]{4})$/i', $codSanitized, $m)) {
            $glob = glob($baseImgPath . $m[1] . '*' . $m[2] . '*.jpg');
            if (!empty($glob)) {
                return $glob[0];
            }
        }

        // Búsqueda flexible por prefijo de modelo (ej: XC6SDC06, XV6SDC03)
        for ($len = min(strlen($codSanitized), 11); $len >= 7; $len--) {
            $prefix = substr($codSanitized, 0, $len);
            $glob = glob($baseImgPath . $prefix . '*.jpg');
            if (!empty($glob)) {
                return $glob[0];
            }
        }

        // Buscar en maestro de artículos si existe un MODELO definido para buscar por él
        try {
            $connCentral = $this->getConn('central');
            $stmtM = sqlsrv_query($connCentral, "SELECT TOP 1 MODELO FROM SOF_MAESTRO_ARTICULOS_RUBRO_CATEGORIA WITH (NOLOCK) WHERE COD_ARTICU = ?", [$codArticu]);
            if ($stmtM && ($row = sqlsrv_fetch_array($stmtM, SQLSRV_FETCH_ASSOC))) {
                $modelo = trim($row['MODELO'] ?? '');
                if (!empty($modelo) && $modelo !== $codArticu) {
                    $modSan = preg_replace('/[^a-zA-Z0-9_-]/', '', $modelo);
                    if (file_exists($baseImgPath . $modSan . '.jpg')) {
                        sqlsrv_free_stmt($stmtM);
                        return $baseImgPath . $modSan . '.jpg';
                    }
                    if (preg_match('/^([A-Z0-9]{8})[A-Z0-9]([0-9]{4})$/i', $modSan, $mm)) {
                        $glob = glob($baseImgPath . $mm[1] . '*' . $mm[2] . '*.jpg');
                        if (!empty($glob)) {
                            sqlsrv_free_stmt($stmtM);
                            return $glob[0];
                        }
                    }
                    $glob = glob($baseImgPath . substr($modSan, 0, 8) . '*.jpg');
                    if (!empty($glob)) {
                        sqlsrv_free_stmt($stmtM);
                        return $glob[0];
                    }
                }
                sqlsrv_free_stmt($stmtM);
            }
        } catch (Throwable $_) {}

        return null;
    }
}
