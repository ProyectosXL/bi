<?php
ini_set('display_errors', '0');
ob_start();
session_start();
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/LogisticaDB.php';

try {
    // fecha: Y-m-d válida y <= hoy; si no, null (el SP usa el último día con datos)
    $fecha = $_GET['fecha'] ?? '';
    $dt    = DateTime::createFromFormat('!Y-m-d', $fecha);
    if (!$dt || $dt->format('Y-m-d') !== $fecha || $fecha > date('Y-m-d')) $fecha = null;

    $canal = isset($_GET['canal']) && $_GET['canal'] !== '' ? $_GET['canal'] : null;
    $tipo  = LogisticaDB::tipoParam();

    $db   = new LogisticaDB();
    $data = $db->getFillRate($fecha, $canal, $tipo);

    ob_clean();
    // Sin JSON_NUMERIC_CHECK: conserva los ceros a la izquierda de NRO_PEDIDO.
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
