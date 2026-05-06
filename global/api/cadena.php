<?php
/**
 * /global/api/cadena.php
 * KPIs por sucursal para la pestaña "Cadena".
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/CadenaDB.php';
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

    $isGrupo     = ($tipoSesion === 'GRUPO');
    $origen      = $isGrupo ? 'franquicias' : ($_GET['origen'] ?? 'argentina');
    $periodo     = $_GET['periodo']     ?? 'mes_actual';
    $grupo       = isset($_GET['grupo']) && $_GET['grupo'] !== '' ? $_GET['grupo'] : null;
    $tipoTienda  = isset($_GET['tipo_tienda']) && $_GET['tipo_tienda'] !== '' ? $_GET['tipo_tienda'] : null;
    $canal       = isset($_GET['canal']) && $_GET['canal'] !== '' ? $_GET['canal'] : null;
    $soloActivas = !$isGrupo && isset($_GET['solo_activas']) && $_GET['solo_activas'] === '1';

    if ($isGrupo || $origen !== 'argentina') { $grupo = null; $tipoTienda = null; $canal = null; }

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
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = GlobalDashboardDB::calcularPeriodo($periodo);
    }

    $db = new CadenaDB($origen);
    $cadenaData  = $db->getKPIsPorSucursal($desde_act, $hasta_act, $desde_prev, $hasta_prev, $grupo, $tipoTienda, $canal);
    $sucursales  = $cadenaData['sucursales'];
    $totalesCadena = $cadenaData['totales'];

    // Filtro "solo activas"
    if ($soloActivas) {
        require_once __DIR__ . '/../class/GlobalDashboardDB.php';
        try {
            $dbG = new GlobalDashboardDB($origen);
            $activasFlip = array_flip($dbG->getSucursalesActivasIds());
            $sucursales = array_values(array_filter(
                $sucursales,
                fn($s) => isset($activasFlip[$s['nro_sucurs']])
            ));
        } catch (Throwable $_) {}
    }

    ob_clean();
    echo json_encode([
        'ok'             => true,
        'desde_act'      => $desde_act,
        'hasta_act'      => $hasta_act,
        'desde_prev'     => $desde_prev,
        'hasta_prev'     => $hasta_prev,
        'sucursales'     => $sucursales,
        'totales_cadena' => $totalesCadena,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
