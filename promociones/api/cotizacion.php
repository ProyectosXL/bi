<?php
/**
 * /bi/promociones/api/cotizacion.php
 * Cotización mensual USD — reutiliza GlobalDashboardDB('argentina').
 * Tanto Argentina como Franquicias liquidan en ARS, por eso la cotización
 * siempre sale del origen 'argentina' (BCRA).
 */
session_start();
ob_start();
set_time_limit(30);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/global/class/GlobalDashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) {
        throw new RuntimeException('No autenticado');
    }
    if (!in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION', 'GRUPO'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $desde     = $_GET['desde']      ?? '';
    $hasta     = $_GET['hasta']      ?? '';
    $desdePrev = $_GET['desde_prev'] ?? '';
    $hastaPrev = $_GET['hasta_prev'] ?? '';

    $fechas = array_filter([$desde, $hasta, $desdePrev, $hastaPrev]);
    if (!$fechas) {
        throw new RuntimeException('Parámetros de fecha requeridos');
    }
    $rangoDesde = min($fechas);
    $rangoHasta = max($fechas);

    $origenPermitidos = ['argentina', 'franquicias', 'uruguay'];
    $origen = in_array($_GET['origen'] ?? '', $origenPermitidos, true) ? $_GET['origen'] : 'argentina';
    // Franquicias liquida en ARS (misma cotización que Argentina)
    $origenCot = ($origen === 'uruguay') ? 'uruguay' : 'argentina';

    $db     = new GlobalDashboardDB($origenCot);
    $result = $db->getCotizacionesMensuales($rangoDesde, $rangoHasta);

    ob_clean();
    echo json_encode([
        'ok'           => true,
        'cotizaciones' => $result['cotizaciones'],
        'tcc_actual'   => $result['tcc_actual'],
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
