<?php
/**
 * /bi/global/api/cotizacion.php
 * Retorna la cotización del último día disponible de cada mes
 * dentro del rango que cubre los períodos actual y previo.
 *
 * GET params: desde, hasta, desde_prev, hasta_prev
 *
 * Response:
 * {
 *   "ok": true,
 *   "cotizaciones": { "2024-01": 834.15, "2024-02": 843.00, ... },
 *   "tcc_actual": 1050.25
 * }
 */
session_start();
ob_start();
set_time_limit(30);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/GlobalDashboardDB.php';

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

    $desde      = $_GET['desde']      ?? '';
    $hasta      = $_GET['hasta']      ?? '';
    $desdePrev  = $_GET['desde_prev'] ?? '';
    $hastaPrev  = $_GET['hasta_prev'] ?? '';
    $origen     = in_array($_GET['origen'] ?? '', ['argentina', 'uruguay', 'franquicias'], true)
                    ? $_GET['origen']
                    : 'argentina';

    // Rango total: desde el mínimo hasta el máximo de ambos períodos
    $fechas = array_filter([$desde, $hasta, $desdePrev, $hastaPrev]);
    if (!$fechas) {
        throw new RuntimeException('Parámetros de fecha requeridos');
    }
    $rangoDesde = min($fechas);
    $rangoHasta = max($fechas);

    $db = new GlobalDashboardDB($origen);
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
