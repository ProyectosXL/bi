<?php
/**
 * /api/filtros.php
 * Devuelve listas de vendedores y rubros disponibles para poblar los selects.
 */
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/DashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');
    $nroSucurs = isset($_SESSION['numsuc']) ? (int)$_SESSION['numsuc'] : 7;
    $periodo = $_GET['periodo'] ?? 'mes_actual';
    [$desde_act, $hasta_act] = array_slice(DashboardDB::calcularPeriodo($periodo), 0, 2);

    $db = new DashboardDB();

    ob_clean();
    echo json_encode([
        'ok'        => true,
        'vendedores' => $db->getVendedoresFiltro($desde_act, $hasta_act, $nroSucurs),
        'rubros'     => $db->getRubrosFiltro($desde_act, $hasta_act, $nroSucurs),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
