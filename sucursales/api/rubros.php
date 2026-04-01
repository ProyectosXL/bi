<?php
/**
 * /api/rubros.php
 * Ranking y participación por rubro.
 */
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/DashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');
    $nroSucurs   = isset($_SESSION['numsuc']) ? (int)$_SESSION['numsuc'] : 7;
    $periodo     = $_GET['periodo'] ?? 'mes_actual';
    $rubroFilter = $_GET['rubro']   ?? null;
    $vendedor    = $_GET['vendedor'] ?? '%';

    if ($periodo === 'custom') {
        $desde_act = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $hasta_act = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        $comp_mode = $_GET['comp_mode']  ?? 'year_ago';

        if ($comp_mode === 'custom') {
            $desde_prev = $_GET['desde_comp'] ?? date('Y-m-d', strtotime($desde_act . ' -1 year'));
            $hasta_prev = $_GET['hasta_comp'] ?? date('Y-m-d', strtotime($hasta_act . ' -1 year'));
        } else {
            $desde_prev = date('Y-m-d', strtotime($desde_act . ' -1 year'));
            $hasta_prev = date('Y-m-d', strtotime($hasta_act . ' -1 year'));
        }
    } else {
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = DashboardDB::calcularPeriodo($periodo);
    }

    $db = new DashboardDB();
    
    // Obtener datos del período actual
    if ($rubroFilter && $rubroFilter !== '%') {
        $rubros      = $db->getCategorias($desde_act, $hasta_act, $nroSucurs, $rubroFilter, $vendedor);
        $rubros_prev = $db->getCategorias($desde_prev, $hasta_prev, $nroSucurs, $rubroFilter, $vendedor);
    } else {
        $rubros      = $db->getRubros($desde_act, $hasta_act, $nroSucurs, $vendedor);
        $rubros_prev = $db->getRubros($desde_prev, $hasta_prev, $nroSucurs, $vendedor);
    }
    
    // Mapear período anterior por rubro
    $prevMap = [];
    foreach ($rubros_prev as $rp) {
        $prevMap[$rp['RUBRO']] = $rp['unidades'];
    }

    $total_unid = array_sum(array_column($rubros, 'unidades'));
    $total_fact = array_sum(array_column($rubros, 'facturacion'));

    foreach ($rubros as &$r) {
        $r['porc_unidades']    = $total_unid > 0 ? $r['unidades'] / $total_unid : 0;
        $r['porc_facturacion'] = $total_fact > 0 ? $r['facturacion'] / $total_fact : 0;
        $r['unidades_prev']    = $prevMap[$r['RUBRO']] ?? 0;
        $r['variacion']        = $r['unidades_prev'] > 0 
            ? ($r['unidades'] - $r['unidades_prev']) / $r['unidades_prev'] 
            : 0;
    }

    ob_clean();
    echo json_encode([
        'ok'     => true,
        'rubros' => $rubros,
        'totales' => [
            'unidades'    => $total_unid,
            'facturacion' => $total_fact,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
