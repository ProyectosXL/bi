<?php
/**
 * /bi/sales/api/clientes.php
 * Obtiene lista de clientes/locales/franquicias para el filtro dinámico.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../class/SalesDB.php';

try {
    $canal   = isset($_GET['canal']) && $_GET['canal'] !== '' ? $_GET['canal'] : null;
    $periodo = $_GET['periodo'] ?? 'mes_actual';

    if ($periodo === 'custom') {
        $da = $_GET['desde'] ?? date('Y-01-01');
        $ha = $_GET['hasta'] ?? date('Y-m-d', strtotime('-1 day'));
    } else {
        [$da, $ha] = array_slice(SalesDB::calcularPeriodo($periodo), 0, 2);
    }

    $db = new SalesDB();
    $clientes = $db->getClientes($canal, $da, $ha);
    echo json_encode(['ok' => true, 'clientes' => $clientes], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
