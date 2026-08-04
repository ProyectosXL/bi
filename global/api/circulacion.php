<?php
/**
 * /global/api/circulacion.php
 * Merodeo, ingresos, tasa de atracción y tasa de conversión.
 *
 * GET params:
 *   action  = resumen (default) | sucursales | evolucion
 *   origen, periodo, desde, hasta, sucursal, grupo, tipo_tienda, canal, solo_activas
 *   meses   (solo action=evolucion; default 13)
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/CirculacionDB.php';
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
    $periodo     = $_GET['periodo'] ?? 'mes_actual';
    $sucursal    = isset($_GET['sucursal']) && $_GET['sucursal'] !== '' ? (int)$_GET['sucursal'] : null;
    $grupo       = isset($_GET['grupo']) && $_GET['grupo'] !== '' ? $_GET['grupo'] : null;
    $tipoTienda  = isset($_GET['tipo_tienda']) && $_GET['tipo_tienda'] !== '' ? $_GET['tipo_tienda'] : null;
    $canal       = isset($_GET['canal']) && $_GET['canal'] !== '' ? $_GET['canal'] : null;
    $soloActivas = !$isGrupo && isset($_GET['solo_activas']) && $_GET['solo_activas'] === '1';
    $action      = $_GET['action'] ?? 'resumen';

    if ($isGrupo || $origen !== 'argentina') { $grupo = null; $tipoTienda = null; $canal = null; }

    if ($periodo === 'custom') {
        $da = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        [$desde_act, $hasta_act] = [$da, $ha];
    } else {
        [$desde_act, $hasta_act] = array_slice(GlobalDashboardDB::calcularPeriodo($periodo), 0, 2);
    }

    $activasIds = null;
    if ($soloActivas) {
        try {
            $dbG = new GlobalDashboardDB($origen);
            $activasIds = $dbG->getSucursalesActivasIds();
        } catch (Throwable $_) {}
    }

    $db = new CirculacionDB($origen);

    if ($action === 'sucursales') {
        $data = $db->getPorSucursal($desde_act, $hasta_act, $sucursal, $grupo, $tipoTienda, $canal, $activasIds);
        ob_clean();
        echo json_encode(['ok' => true, 'sucursales' => $data], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    if ($action === 'evolucion') {
        $meses = (int)($_GET['meses'] ?? 13);
        $data  = $db->getEvolucionMensual($meses, $sucursal, $grupo, $tipoTienda, $canal, $activasIds);
        ob_clean();
        echo json_encode(['ok' => true, 'evolucion' => $data], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    // action=resumen (default)
    $resumenAct  = $db->getResumen($desde_act,  $hasta_act,  $sucursal, $grupo, $tipoTienda, $canal, $activasIds);
    [, , $desde_prev, $hasta_prev] = $periodo === 'custom'
        ? [$desde_act, $hasta_act,
           (new DateTime($desde_act))->modify('-1 year')->format('Y-m-d'),
           (new DateTime($hasta_act))->modify('-1 year')->format('Y-m-d')]
        : GlobalDashboardDB::calcularPeriodo($periodo);
    $resumenPrev = $db->getResumen($desde_prev, $hasta_prev, $sucursal, $grupo, $tipoTienda, $canal, $activasIds);

    $varFn = fn($a, $p) => $p != 0 ? ($a - $p) / $p : ($a > 0 ? 1 : 0);

    ob_clean();
    echo json_encode([
        'ok'       => true,
        'periodo'  => [
            'desde_act' => $desde_act, 'hasta_act' => $hasta_act,
            'desde_prev' => $desde_prev, 'hasta_prev' => $hasta_prev,
        ],
        'actual'   => $resumenAct,
        'previo'   => $resumenPrev,
        'variacion' => [
            'merodeo'             => $varFn($resumenAct['merodeo'],             $resumenPrev['merodeo']),
            'ingresos'            => $varFn($resumenAct['ingresos'],            $resumenPrev['ingresos']),
            'tickets'             => $varFn($resumenAct['tickets'],             $resumenPrev['tickets']),
            'atraccion'           => $varFn($resumenAct['atraccion'],           $resumenPrev['atraccion']),
            'conversion'          => ($resumenAct['conversion'] === null || $resumenPrev['conversion'] === null)
                                        ? null
                                        : $varFn($resumenAct['conversion'], $resumenPrev['conversion']),
            'venta_por_visitante' => $varFn($resumenAct['venta_por_visitante'], $resumenPrev['venta_por_visitante']),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
