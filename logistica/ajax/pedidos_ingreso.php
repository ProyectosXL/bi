<?php
ini_set('display_errors', '0');
ob_start();
session_start();
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/LogisticaDB.php';

try {
    // fecha: Y-m-d válida y <= hoy; si no, null (el SP usa ayer)
    $fecha = $_GET['fecha'] ?? '';
    $dt    = DateTime::createFromFormat('!Y-m-d', $fecha);
    if (!$dt || $dt->format('Y-m-d') !== $fecha || $fecha > date('Y-m-d')) $fecha = null;

    $canal = isset($_GET['canal']) && $_GET['canal'] !== '' ? $_GET['canal'] : null;

    $db   = new LogisticaDB();
    $data = $db->getPedidosIngreso($fecha, $canal, LogisticaDB::tipoParam());

    ob_clean();
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
