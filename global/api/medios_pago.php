<?php
/**
 * /global/api/medios_pago.php
 * Facturación por Medio de Pago (+ drilldown TARJETA → por Cuotas).
 *
 * GET params:
 *   origen, periodo, sucursal, grupo, tipo_tienda, vendedor, rubro
 *   action = '' (medios) | 'cuotas' (solo TARJETA)
 */
session_start();
ob_start();
set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/GlobalDashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) throw new RuntimeException('No autenticado');
    if (!in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $origen     = $_GET['origen']      ?? 'argentina';
    $periodo    = $_GET['periodo']     ?? 'mes_actual';
    $action     = $_GET['action']      ?? '';
    $sucursal   = isset($_GET['sucursal'])   && $_GET['sucursal']   !== '' ? (int)$_GET['sucursal']   : null;
    $grupo      = isset($_GET['grupo'])      && $_GET['grupo']      !== '' ? $_GET['grupo']      : null;
    $tipoTienda = isset($_GET['tipo_tienda']) && $_GET['tipo_tienda'] !== '' ? $_GET['tipo_tienda'] : null;

    if ($origen !== 'argentina') { $grupo = null; $tipoTienda = null; }

    if ($periodo === 'custom') {
        $desde = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $hasta = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
    } else {
        [$desde, $hasta] = array_slice(GlobalDashboardDB::calcularPeriodo($periodo), 0, 2);
    }

    require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Filters.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';

    $cfg  = getConfigForOrigen($origen);
    $cid  = new Conexion();
    $conn = $cid->conectar($cfg['db']);
    $cv   = $cfg['campo_vendedor'];

    $fp = [
        'sucursal'    => $sucursal,
        'grupo'       => $grupo,
        'tipo_tienda' => $tipoTienda,
        'vendedor'    => '%',
        'rubro'       => '%',
    ];
    [$sfS, $pS] = Filters::build($fp, 's', $cv, $origen, false, false);

    sqlsrv_configure('WarningsReturnAsErrors', 0);

    if ($action === 'cuotas') {
        // Drilldown: TARJETA → por cuotas
        $stmt = sqlsrv_query($conn,
            "SELECT
                ISNULL(s.CUOTAS, 0) AS CUOTAS,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND s.MEDIO_DE_PAGO = 'TARJETA'
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') {$sfS}
            GROUP BY ISNULL(s.CUOTAS, 0)
            ORDER BY CUOTAS ASC",
            array_merge([$desde, $hasta], $pS)
        );
        if ($stmt === false) throw new RuntimeException('SQL error cuotas: ' . (sqlsrv_errors()[0]['message'] ?? ''));
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = ['CUOTAS' => (int)$row['CUOTAS'], 'facturacion' => (float)$row['facturacion']];
        }
        sqlsrv_free_stmt($stmt);

        ob_clean();
        echo json_encode(['ok' => true, 'cuotas' => $rows], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    } else {
        // Medios de pago
        $stmt = sqlsrv_query($conn,
            "SELECT
                ISNULL(s.MEDIO_DE_PAGO, 'Sin especificar') AS MEDIO_DE_PAGO,
                ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') {$sfS}
            GROUP BY ISNULL(s.MEDIO_DE_PAGO, 'Sin especificar')
            ORDER BY facturacion DESC",
            array_merge([$desde, $hasta], $pS)
        );
        if ($stmt === false) throw new RuntimeException('SQL error medios: ' . (sqlsrv_errors()[0]['message'] ?? ''));
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = ['MEDIO_DE_PAGO' => $row['MEDIO_DE_PAGO'], 'facturacion' => (float)$row['facturacion']];
        }
        sqlsrv_free_stmt($stmt);

        ob_clean();
        echo json_encode(['ok' => true, 'medios' => $rows], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    }

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
