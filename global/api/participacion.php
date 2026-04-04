<?php
/**
 * /global/api/participacion.php
 * Tabla pivot de participación de rubros por sucursal.
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/ParticipacionDB.php';
require_once __DIR__ . '/../class/GlobalDashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) throw new RuntimeException('No autenticado');
    if (!in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $origen     = $_GET['origen']      ?? 'argentina';
    $periodo    = $_GET['periodo']     ?? 'mes_actual';
    $topRubros  = (int)($_GET['top_rubros'] ?? 15);
    $grupo      = isset($_GET['grupo']) && $_GET['grupo'] !== '' ? $_GET['grupo'] : null;
    $tipoTienda = isset($_GET['tipo_tienda']) && $_GET['tipo_tienda'] !== '' ? $_GET['tipo_tienda'] : null;

    if ($origen !== 'argentina') { $grupo = null; $tipoTienda = null; }

    if ($periodo === 'custom') {
        $da = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        [$desde_act, $hasta_act] = [$da, $ha];
    } else {
        [$desde_act, $hasta_act] = array_slice(GlobalDashboardDB::calcularPeriodo($periodo), 0, 2);
    }

    $db   = new ParticipacionDB($origen);
    $data = $db->getPivot($desde_act, $hasta_act, $topRubros, $grupo, $tipoTienda);

    ob_clean();
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
