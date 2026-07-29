<?php
/**
 * /bi/sales/api/stock.php
 * Endpoint para obtener la información de la solapa Stock (Inventario Lakers)
 * en base a SJ_STOCK_LOCALES y BI_SALES_LAKERS.
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

// LOGIN DESACTIVADO TEMPORALMENTE — restaurar este bloque para volver a exigir sesión.
// if (!isset($_SESSION['username'])) {
//     http_response_code(401);
//     echo json_encode(['ok' => false, 'error' => 'No autenticado']);
//     exit;
// }

require_once __DIR__ . '/../../class/classEnv.php';
require_once __DIR__ . '/../../class/Conexion.php';
require_once __DIR__ . '/../class/SalesDB.php';

try {
    $canal = $_GET['canal'] ?? null;
    $rubro = $_GET['rubro'] ?? null;
    
    // Conectamos a power
    $conn = (new Conexion())->conectar('power');
    if (!$conn) {
        throw new RuntimeException("No se pudo conectar a POWER_BI_CONTROL");
    }

    // 1. Obtener la fecha de ayer (coincidiendo con TODAY() - 1 del Power BI)
    $sqlMaxFecha = "
        SELECT CONVERT(varchar(10), DATEADD(day, -1, GETDATE()), 23) as ultima_fecha
    ";
    $stmtMax = sqlsrv_query($conn, $sqlMaxFecha);
    $rowMax = sqlsrv_fetch_array($stmtMax, SQLSRV_FETCH_ASSOC);
    $ultimaFechaStr = $rowMax['ultima_fecha'] ?? date('Y-m-d', strtotime('-1 day'));
    $ultimaFechaObj = new DateTime($ultimaFechaStr);
    
    // 2. Filtros dinámicos para Stock (canal/rubro)
    // El canal en stock se puede mapear si corresponde, pero el stock es por local físico (sucursal).
    // Si canal = 'LOCALES PROPIOS' o 'FRANQUICIAS' o 'MAYORISTAS' o 'ECOMMERCE',
    // mapeamos según corresponda a las sucursales o dejamos general.
    // La tabla SJ_STOCK_LOCALES contiene sucursales.
    $whereStock = " WHERE FECHA = ? ";
    $paramsStock = [$ultimaFechaStr];
    
    // Si viene rubro, filtramos en stock
    if ($rubro && $rubro !== '%') {
        $whereStock .= " AND RUBRO = ? ";
        $paramsStock[] = $rubro;
    }
    
    // Si viene canal, se puede filtrar por sucursal si corresponde o ignorar para el stock físico si no aplica.
    // Generalmente para el stock físico se mantiene general o según sucursal física.
    
    // 3. Totales actuales de Stock (Último Día)
    // Central Valorización: NO filtra PACKAGING.
    // Locales Valorización: SÍ filtra PACKAGING (RUBRO <> 'PACKAGING').
    // Ambas Unidades (CANT_STOCK): SÍ filtran PACKAGING.
    $sqlTotales = "
        SELECT 
            SUM(CASE WHEN DESC_SUCURSAL = 'CENTRAL' AND (RUBRO IS NULL OR RUBRO <> 'PACKAGING') THEN CANT_STOCK ELSE 0 END) as stock_central,
            SUM(CASE WHEN DESC_SUCURSAL = 'CENTRAL' THEN VALORIZACION ELSE 0 END) as val_central,
            
            SUM(CASE WHEN DESC_SUCURSAL <> 'CENTRAL' AND (RUBRO IS NULL OR RUBRO <> 'PACKAGING') THEN CANT_STOCK ELSE 0 END) as stock_locales,
            SUM(CASE WHEN DESC_SUCURSAL <> 'CENTRAL' AND (RUBRO IS NULL OR RUBRO <> 'PACKAGING') THEN VALORIZACION ELSE 0 END) as val_locales
        FROM SJ_STOCK_LOCALES
        $whereStock
    ";
    
    $stmtTot = sqlsrv_query($conn, $sqlTotales, $paramsStock);
    $totales = sqlsrv_fetch_array($stmtTot, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtTot);

    // Ajustar totales sumados en memoria
    $totales['stock_total'] = $totales['stock_central'] + $totales['stock_locales'];
    $totales['val_total']   = $totales['val_central'] + $totales['val_locales'];

    // 4. Facturación $ y Unidades Facturadas del Mes en Curso (de la última fecha disponible)
    // Buscamos los datos en BI_SALES_LAKERS correspondientes al mes de esa última fecha de stock
    $anioMax = (int)$ultimaFechaObj->format('Y');
    $mesMax = (int)$ultimaFechaObj->format('m');
    
    // Filtros de canal y rubro para facturación
    $wCanal = ""; $pCanal = [];
    if ($canal && $canal !== '%') {
        $map = ['PROPIOS'=>'LOCALES PROPIOS', 'FRANQUICIAS'=>'FRANQUICIAS', 'MAYORISTAS'=>'MAYORISTAS', 'ECOMMERCE'=>'ECOMMERCE'];
        $cVal = $map[strtoupper($canal)] ?? $canal;
        $wCanal = " AND CANAL = ? ";
        $pCanal[] = $cVal;
    }
    $wRubro = ""; $pRubro = [];
    if ($rubro && $rubro !== '%') {
        $wRubro = " AND RUBRO = ? ";
        $pRubro[] = $rubro;
    }
    
    // La facturación de temporada y general lleva filtro canal locales propios si es el caso? No, facturación del mes general.
    $sqlFactMes = "
        SELECT 
            SUM(IMPORTE) as fact_mes,
            SUM(CASE WHEN RUBRO NOT IN ('CONCEPTO', 'PACKAGING') THEN CANTIDAD ELSE 0 END) as unid_mes
        FROM dbo.BI_SALES_LAKERS
        WHERE YEAR(FECHA) = ? AND MONTH(FECHA) = ? $wCanal $wRubro
    ";
    $paramsFact = array_merge([$anioMax, $mesMax], $pCanal, $pRubro);
    $stmtFact = sqlsrv_query($conn, $sqlFactMes, $paramsFact);
    $factData = sqlsrv_fetch_array($stmtFact, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtFact);

    // 5. Tabla: Valorización Stock Sucursales (Último Día)
    // Para la lista de sucursales, aplicamos el filtro de PACKAGING en unidades, y condicional en valorizacion (Central vs Locales)
    $sqlSucs = "
        SELECT 
            DESC_SUCURSAL as sucursal,
            SUM(CASE WHEN RUBRO IS NULL OR RUBRO <> 'PACKAGING' THEN CANT_STOCK ELSE 0 END) as stock,
            SUM(CASE WHEN DESC_SUCURSAL = 'CENTRAL' OR (RUBRO IS NULL OR RUBRO <> 'PACKAGING') THEN VALORIZACION ELSE 0 END) as valorizacion
        FROM SJ_STOCK_LOCALES
        $whereStock
        GROUP BY DESC_SUCURSAL
        ORDER BY stock DESC
    ";
    $stmtSucs = sqlsrv_query($conn, $sqlSucs, $paramsStock);
    $tablaSucursales = [];
    while ($row = sqlsrv_fetch_array($stmtSucs, SQLSRV_FETCH_ASSOC)) {
        $tablaSucursales[] = [
            'sucursal' => $row['sucursal'],
            'stock' => (int)$row['stock'],
            'valorizacion' => (float)$row['valorizacion']
        ];
    }
    sqlsrv_free_stmt($stmtSucs);

    // 6. Evolución Mensual Stock & Facturación (Últimos 12 meses finalizados en el mes de la última fecha)
    $fechaInicio = (clone $ultimaFechaObj)->modify('-11 months')->modify('first day of this month');
    $fechaInicioStr = $fechaInicio->format('Y-m-d');
    
    // a. Stock mensual (tomamos la última foto/fecha de cada mes)
    $whereEvolStock = " WHERE FECHA >= ? AND FECHA <= ? ";
    $paramsEvolStock = [$fechaInicioStr, $ultimaFechaStr];
    if ($rubro && $rubro !== '%') {
        $whereEvolStock .= " AND RUBRO = ? ";
        $paramsEvolStock[] = $rubro;
    }
    
    $sqlEvolStock = "
        WITH UltimaFechaMes AS (
            SELECT 
                YEAR(FECHA) as anio,
                MONTH(FECHA) as mes,
                MAX(FECHA) as max_fecha
            FROM SJ_STOCK_LOCALES
            $whereEvolStock
            GROUP BY YEAR(FECHA), MONTH(FECHA)
        )
        SELECT 
            u.anio,
            u.mes,
            SUM(CASE WHEN s.RUBRO IS NULL OR s.RUBRO <> 'PACKAGING' THEN s.CANT_STOCK ELSE 0 END) as stock,
            SUM(CASE WHEN s.DESC_SUCURSAL = 'CENTRAL' OR (s.RUBRO IS NULL OR s.RUBRO <> 'PACKAGING') THEN s.VALORIZACION ELSE 0 END) as valorizacion
        FROM SJ_STOCK_LOCALES s
        INNER JOIN UltimaFechaMes u ON s.FECHA = u.max_fecha
        GROUP BY u.anio, u.mes
        ORDER BY u.anio ASC, u.mes ASC
    ";
    
    $stmtEvolSt = sqlsrv_query($conn, $sqlEvolStock, $paramsEvolStock);
    $evolStockMap = [];
    while ($row = sqlsrv_fetch_array($stmtEvolSt, SQLSRV_FETCH_ASSOC)) {
        $key = $row['anio'] . '-' . str_pad($row['mes'], 2, '0', STR_PAD_LEFT);
        $evolStockMap[$key] = [
            'stock' => (int)$row['stock'],
            'valorizacion' => (float)$row['valorizacion']
        ];
    }
    sqlsrv_free_stmt($stmtEvolSt);

    // b. Ventas mensuales (Facturacion $ y Unidades) para el mismo rango
    $sqlEvolVentas = "
        SELECT 
            YEAR(FECHA) as anio,
            MONTH(FECHA) as mes,
            SUM(IMPORTE) as facturacion,
            SUM(CASE WHEN RUBRO NOT IN ('CONCEPTO', 'PACKAGING') THEN CANTIDAD ELSE 0 END) as unidades
        FROM dbo.BI_SALES_LAKERS
        WHERE FECHA >= ? AND FECHA <= ? $wCanal $wRubro
        GROUP BY YEAR(FECHA), MONTH(FECHA)
        ORDER BY anio ASC, mes ASC
    ";
    $paramsEvolVentas = array_merge([$fechaInicioStr, $ultimaFechaStr], $pCanal, $pRubro);
    $stmtEvolVts = sqlsrv_query($conn, $sqlEvolVentas, $paramsEvolVentas);
    
    $evolFinal = [];
    while ($row = sqlsrv_fetch_array($stmtEvolVts, SQLSRV_FETCH_ASSOC)) {
        $anio = (int)$row['anio'];
        $mes = (int)$row['mes'];
        $key = $anio . '-' . str_pad($mes, 2, '0', STR_PAD_LEFT);
        
        $stockData = $evolStockMap[$key] ?? ['stock' => 0, 'valorizacion' => 0];
        
        // Calculamos la variación % de la valorización del stock vs el mes anterior
        $prevDate = (new DateTime("$anio-$mes-01"))->modify('-1 month');
        $prevKey = $prevDate->format('Y-m');
        $prevStockVal = $evolStockMap[$prevKey]['valorizacion'] ?? 0;
        
        $varStockVal = null;
        if ($prevStockVal > 0) {
            $varStockVal = (($stockData['valorizacion'] - $prevStockVal) / $prevStockVal) * 100;
        }

        $evolFinal[] = [
            'anio' => $anio,
            'mes' => $mes,
            'mes_nombre' => $key,
            'facturacion' => (float)$row['facturacion'],
            'unidades' => (float)$row['unidades'],
            'stock' => $stockData['stock'],
            'valorizacion' => $stockData['valorizacion'],
            'var_stock_val' => $varStockVal
        ];
    }
    sqlsrv_free_stmt($stmtEvolVts);

    ob_clean();
    echo json_encode([
        'ok' => true,
        'fecha' => $ultimaFechaStr,
        'totales' => [
            'stock_central' => (int)($totales['stock_central'] ?? 0),
            'val_central' => (float)($totales['val_central'] ?? 0),
            'stock_locales' => (int)($totales['stock_locales'] ?? 0),
            'val_locales' => (float)($totales['val_locales'] ?? 0),
            'stock_total' => (int)($totales['stock_total'] ?? 0),
            'val_total' => (float)($totales['val_total'] ?? 0),
            'fact_mes' => (float)($factData['fact_mes'] ?? 0),
            'unid_mes' => (float)($factData['unid_mes'] ?? 0)
        ],
        'tabla_sucursales' => $tablaSucursales,
        'evolucion' => $evolFinal
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

