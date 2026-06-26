<?php
ini_set('display_errors', '0');
ob_start();
session_start();
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/LogisticaDB.php';

try {
    $pedido = isset($_GET['pedido']) ? trim((string)$_GET['pedido']) : '';
    if ($pedido === '') {
        throw new InvalidArgumentException('Falta el número de pedido.');
    }

    $db   = new LogisticaDB();
    $data = $db->getPedidoDetalle($pedido);

    ob_clean();
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
