<?php
/**
 * /bi/premios/api/filtros.php
 * Lista de supervisoras para el selector del toolbar.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../class/PremiosDB.php';

try {
    $hoy = date('Y-m-d');
    $db  = new PremiosDB($hoy, $hoy, $hoy, $hoy);
    echo json_encode(['ok' => true, 'supervisoras' => $db->getSupervisoras()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
