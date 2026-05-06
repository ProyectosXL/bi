<?php
/**
 * /global/api/vendedoras.php
 * KPIs completos por vendedora para la pestaña "Vendedoras".
 *
 * GET params:
 *   origen, periodo, desde, hasta, comp_mode, desde_comp, hasta_comp
 *   sucursal, grupo, tipo_tienda, rubro
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/VendedorasDB.php';
require_once __DIR__ . '/../class/GlobalDashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) throw new RuntimeException('No autenticado');
    $tipoSesion = $_SESSION['tipo'] ?? '';
    if (!in_array($tipoSesion, ['GERENCIA', 'SUPERVISION', 'GRUPO'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $isGrupo    = ($tipoSesion === 'GRUPO');
    $origen     = $isGrupo ? 'franquicias' : ($_GET['origen'] ?? 'argentina');
    $periodo    = $_GET['periodo']     ?? 'mes_actual';
    $rubro      = $_GET['rubro']       ?? '%';
    $sucursal   = isset($_GET['sucursal'])   && $_GET['sucursal']   !== '' ? (int)$_GET['sucursal']   : null;
    $grupo      = isset($_GET['grupo'])      && $_GET['grupo']      !== '' ? $_GET['grupo']      : null;
    $tipoTienda = isset($_GET['tipo_tienda']) && $_GET['tipo_tienda'] !== '' ? $_GET['tipo_tienda'] : null;
    $canal      = isset($_GET['canal'])      && $_GET['canal']      !== '' ? $_GET['canal']      : null;

    if ($isGrupo || $origen !== 'argentina') {
        $grupo      = null;
        $tipoTienda = null;
        $canal      = null;
    }

    // GRUPO: validar sucursal solicitada
    if ($isGrupo && $sucursal !== null) {
        if (!in_array($sucursal, $_SESSION['sucursalesGrupo'] ?? [], true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sucursal no autorizada']);
            exit;
        }
    }

    if ($periodo === 'custom') {
        $da        = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha        = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        $comp_mode = $_GET['comp_mode'] ?? 'year_ago';
        $da_p = $comp_mode === 'custom'
            ? ($_GET['desde_comp'] ?? (new DateTime($da))->modify('-1 year')->format('Y-m-d'))
            : (new DateTime($da))->modify('-1 year')->format('Y-m-d');
        $ha_p = $comp_mode === 'custom'
            ? ($_GET['hasta_comp'] ?? (new DateTime($ha))->modify('-1 year')->format('Y-m-d'))
            : (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = [$da, $ha, $da_p, $ha_p];
    } else {
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = GlobalDashboardDB::calcularPeriodo($periodo);
    }

    $db = new VendedorasDB($origen);

    $act  = $db->getKPIsVendedoras($desde_act,  $hasta_act,  $sucursal, $rubro, $grupo, $tipoTienda, $canal);
    $prev = $db->getKPIsVendedoras($desde_prev, $hasta_prev, $sucursal, $rubro, $grupo, $tipoTienda, $canal);

    // Merge prev period fields into act rows
    $prevIdx = [];
    foreach ($prev as $r) { $prevIdx[$r['vendedora']] = $r; }
    $vendedoras = array_map(function($r) use ($prevIdx) {
        $p = $prevIdx[$r['vendedora']] ?? null;
        $r['facturacion_prev']    = $p ? $p['facturacion']     : 0;
        $r['unidades_prev']       = $p ? $p['unidades']        : 0;
        $r['tickets_prev']        = $p ? $p['tickets']         : 0;
        $r['ticket_promedio_prev']= $p ? $p['ticket_promedio'] : 0;
        $r['porc_2do_prev']       = $p ? $p['porc_2do']        : 0;
        return $r;
    }, $act);

    ob_clean();
    echo json_encode([
        'ok'         => true,
        'desde_act'  => $desde_act,
        'hasta_act'  => $hasta_act,
        'vendedoras' => $vendedoras,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
