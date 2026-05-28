<?php
ini_set('display_errors', '0');
ob_start();
session_start();
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/LogisticaDB.php';

try {
    $rubro = isset($_GET['rubro']) && $_GET['rubro'] !== '' ? $_GET['rubro'] : null;

    $db   = new LogisticaDB();
    $data = $db->getStock($rubro);

    ob_clean();
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
