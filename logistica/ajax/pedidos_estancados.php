<?php
ini_set('display_errors', '0');
ob_start();
session_start();
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/LogisticaDB.php';

try {
    $dias  = (int)($_GET['dias'] ?? 90);
    if (!in_array($dias, [30, 60, 90, 180], true)) $dias = 90;
    $canal = isset($_GET['canal']) && $_GET['canal'] !== '' ? $_GET['canal'] : null;

    $db   = new LogisticaDB();
    $data = $db->getPedidosEstancados($dias, $canal);

    ob_clean();
    // Sin JSON_NUMERIC_CHECK: conserva los ceros a la izquierda de NRO_PEDIDO
    // (fmt.num del front acepta números como string).
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
