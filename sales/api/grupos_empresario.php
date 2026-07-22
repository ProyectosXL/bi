<?php
/**
 * /bi/sales/api/grupos_empresario.php
 * Obtiene la lista de grupos empresarios para franquicias.
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
    $db = new SalesDB();
    $grupos = $db->getGruposEmpresario();
    echo json_encode(['ok' => true, 'grupos' => $grupos], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
