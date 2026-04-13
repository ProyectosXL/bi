<?php
/**
 * /api/vendedores.php
 * Tabla KPIs por vendedor.
 */
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/DashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');
    if (!isset($_SESSION['numsuc'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Sesión inválida. Volvé a iniciar sesión.']);
        exit;
    }
    $nroSucurs = (int)$_SESSION['numsuc'];
    $periodo  = $_GET['periodo'] ?? 'mes_actual';
    $vendedor = $_GET['vendedor'] ?? '%';
    $rubro    = $_GET['rubro']    ?? '%';

    if ($periodo === 'custom') {
        $da   = $_GET['desde'] ?? date('Y-m-01');
        $ha   = $_GET['hasta'] ?? date('Y-m-d');
        $diff = (new DateTime($da))->diff(new DateTime($ha))->days;
        $ha_p = (new DateTime($da))->modify('-1 day')->format('Y-m-d');
        $da_p = (new DateTime($ha_p))->modify('-' . $diff . ' days')->format('Y-m-d');
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = [$da, $ha, $da_p, $ha_p];
    } else {
        [$desde_act, $hasta_act] = array_slice(DashboardDB::calcularPeriodo($periodo), 0, 2);
    }

    $db = new DashboardDB();

    ob_clean();
    echo json_encode([
        'ok'         => true,
        'vendedores' => $db->getVendedores($desde_act, $hasta_act, $nroSucurs, $vendedor, $rubro),
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
