<?php
ini_set('display_errors', '0');
ob_start();
session_start();
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../../class/LogisticaDB_UY.php';

try {
    $db = new LogisticaDB_UY();
    ob_clean();
    echo json_encode([
        'ok'     => true,
        'canales'=> $db->getCanalesUy(),
        'rubros' => $db->getRubrosUy(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
