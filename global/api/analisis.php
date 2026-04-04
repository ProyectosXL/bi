<?php
/**
 * /global/api/analisis.php
 * Análisis global (jerarquía, ranking rubros, evolución, vendedores).
 * Delega en AnalisisDB del módulo sucursales con configuración por origen.
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/sucursales/class/AnalisisDB.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) throw new RuntimeException('No autenticado');
    if (!in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    // Override session tipo para que AnalisisDB use la db del origen seleccionado
    $origen = $_GET['origen'] ?? 'argentina';
    $origenToTipo = [
        'argentina'   => 'LOCAL_PROPIO',
        'uruguay'     => 'LOCAL_PROPIO_UY',
        'franquicias' => 'FRANQUICIA',
    ];
    // Inyectar temporalmente el tipo para que getConfig() resuelva la DB correcta
    $tipoOriginal        = $_SESSION['tipo'];
    $_SESSION['tipo']    = $origenToTipo[$origen] ?? 'LOCAL_PROPIO';
    // No filtrar por sucursal (global: null = todas)
    $numsucOriginal      = $_SESSION['numsuc'] ?? null;
    $sucursal = isset($_GET['sucursal']) && $_GET['sucursal'] !== '' ? (int)$_GET['sucursal'] : null;
    $_SESSION['numsuc']  = $sucursal ?? null; // null = sin filtro

    $action   = $_GET['action']   ?? 'ranking_rubros';
    $periodo  = $_GET['periodo']  ?? 'mes_actual';
    $vendedor = (isset($_GET['vendedor']) && $_GET['vendedor'] !== '') ? $_GET['vendedor'] : '%';
    $rubro    = (isset($_GET['rubro'])    && $_GET['rubro']    !== '') ? $_GET['rubro']    : '%';

    require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/sucursales/class/DashboardDB.php';
    if ($periodo === 'custom') {
        $da        = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha        = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        $comp_mode = $_GET['comp_mode'] ?? 'year_ago';
        $da_p = $comp_mode === 'custom' ? ($_GET['desde_comp'] ?? (new DateTime($da))->modify('-1 year')->format('Y-m-d'))
                                        : (new DateTime($da))->modify('-1 year')->format('Y-m-d');
        $ha_p = $comp_mode === 'custom' ? ($_GET['hasta_comp'] ?? (new DateTime($ha))->modify('-1 year')->format('Y-m-d'))
                                        : (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = [$da, $ha, $da_p, $ha_p];
    } else {
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = DashboardDB::calcularPeriodo($periodo);
    }

    $db = new AnalisisDB();

    $response = ['ok' => true];
    switch ($action) {
        case 'jerarquia':
            $act  = $db->getJerarquiaDestinoRubroCategoria($desde_act, $hasta_act, $sucursal, $vendedor, $rubro);
            $prev = $db->getJerarquiaDestinoRubroCategoria($desde_prev, $hasta_prev, $sucursal, $vendedor, $rubro);
            $response['jerarquia'] = $db->mergeJerarquias($act, $prev);
            break;

        case 'vendedores':
            $act  = $db->getVendedoresAnalisis($desde_act, $hasta_act, $sucursal, $vendedor, $rubro);
            $prev = $db->getVendedoresAnalisis($desde_prev, $hasta_prev, $sucursal, $vendedor, $rubro);
            $response['vendedores'] = $db->mergeVendedores($act, $prev);
            break;

        case 'cards_rubros':
            $act  = $db->getRubrosCards($desde_act, $hasta_act, $sucursal, $vendedor);
            $prev = $db->getRubrosCards($desde_prev, $hasta_prev, $sucursal, $vendedor);
            $response['cards'] = $db->mergeCards($act, $prev);
            break;

        case 'ranking_rubros':
            $rubrosAct  = $db->getRankingRubros($desde_act,  $hasta_act,  $sucursal, $vendedor);
            $rubrosPrev = $db->getRankingRubros($desde_prev, $hasta_prev, $sucursal, $vendedor);
            // Merge prev period data into act by RUBRO
            $prevIdx = [];
            foreach ($rubrosPrev as $r) { $prevIdx[$r['RUBRO']] = $r; }
            foreach ($rubrosAct as &$r) {
                $p = $prevIdx[$r['RUBRO']] ?? null;
                $r['unidades_prev']    = $p ? (float)$p['unidades']    : 0;
                $r['facturacion_prev'] = $p ? (float)$p['facturacion'] : 0;
                $r['var_unidades']     = ($r['unidades_prev'] ?? 0) > 0 ? ((float)$r['unidades'] - $r['unidades_prev']) / $r['unidades_prev'] : null;
                $r['var_facturacion']  = ($r['facturacion_prev'] ?? 0) > 0 ? ((float)$r['facturacion'] - $r['facturacion_prev']) / $r['facturacion_prev'] : null;
            }
            unset($r);
            $response['rubros'] = $rubrosAct;
            break;

        case 'ranking_categorias':
            $rubroFiltro = $_GET['rubro_filter'] ?? '';
            $response['categorias'] = $db->getRankingCategorias($desde_act, $hasta_act, $sucursal, $vendedor, $rubroFiltro);
            break;

        case 'evolucion_unidades':
            $response['evolucion'] = $db->getEvolucionMensual($sucursal, $vendedor, $rubro, 'unidades');
            break;

        case 'evolucion_tickets':
            $response['evolucion'] = $db->getEvolucionMensual($sucursal, $vendedor, $rubro, 'tickets');
            break;

        default:
            $response = ['ok' => false, 'error' => "Acción desconocida: {$action}"];
    }

    // Restaurar sesión
    $_SESSION['tipo']   = $tipoOriginal;
    $_SESSION['numsuc'] = $numsucOriginal;

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    // Restaurar sesión en caso de error
    if (isset($tipoOriginal))    $_SESSION['tipo']   = $tipoOriginal;
    if (isset($numsucOriginal))  $_SESSION['numsuc'] = $numsucOriginal;

    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
