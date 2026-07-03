<?php
/**
 * /bi/sales/api/variacion_unidades.php
 * Datos para la pestaña Variación Unidades.
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../class/SalesDB.php';

try {
    $canal = $_GET['canal'] ?? null;

    $db = new SalesDB();

    // Variación mensual % (año actual vs año anterior)
    $variacion = $db->getVariacionMensualUnidades($canal);

    // Participación por canal por año (apilado 100%)
    $participacion = $db->getParticipacionCanalAnual(4);

    ob_clean();
    echo json_encode([
        'ok'           => true,
        'variacion'    => $variacion,
        'participacion'=> $participacion,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
