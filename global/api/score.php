<?php
/**
 * /global/api/score.php
 * Scoring ponderado de sucursales para el período seleccionado.
 *
 * GET params:
 *   origen     = argentina|uruguay|franquicias
 *   periodo    = ayer|7|30|90|180|mes_actual|mes_pasado|año_actual|año_pasado|custom
 *   desde, hasta, comp_mode, desde_comp, hasta_comp (si periodo=custom)
 *   grupo      = GRUPO (opcional, solo argentina)
 *   tipo_tienda = TIPO_TIENDA (opcional, solo argentina)
 */
session_start();
ob_start();
set_time_limit(180);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/GlobalDashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) {
        throw new RuntimeException('No autenticado');
    }
    $tipoSesion = $_SESSION['tipo'] ?? '';
    if (!in_array($tipoSesion, ['GERENCIA', 'SUPERVISION'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $origen     = $_GET['origen']  ?? 'argentina';
    $periodo    = $_GET['periodo'] ?? 'mes_actual';
    $grupo      = isset($_GET['grupo'])       && $_GET['grupo']       !== '' ? $_GET['grupo']       : null;
    $tipoTienda = isset($_GET['tipo_tienda']) && $_GET['tipo_tienda'] !== '' ? $_GET['tipo_tienda'] : null;

    if ($origen !== 'argentina') { $grupo = null; $tipoTienda = null; }

    if ($periodo === 'custom') {
        $da        = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha        = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        $comp_mode = $_GET['comp_mode'] ?? 'year_ago';
        if ($comp_mode === 'custom') {
            $da_p = $_GET['desde_comp'] ?? (new DateTime($da))->modify('-1 year')->format('Y-m-d');
            $ha_p = $_GET['hasta_comp'] ?? (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        } else {
            $da_p = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
            $ha_p = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        }
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = [$da, $ha, $da_p, $ha_p];
    } else {
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = GlobalDashboardDB::calcularPeriodo($periodo);
    }

    $primerDiaMes = date('Y-m-01', strtotime($desde_act));
    $ultimoDiaMes = date('Y-m-t',  strtotime($desde_act));

    $db = new GlobalDashboardDB($origen);

    $scores = $db->getScorePorSucursal(
        $desde_act, $hasta_act,
        $desde_prev, $hasta_prev,
        $primerDiaMes, $ultimoDiaMes,
        $grupo, $tipoTienda
    );

    // Nombres de sucursales
    $sucNombres = [];
    try {
        foreach ($db->getSucursalesLista() as $s) {
            $sucNombres[(int)$s['NRO_SUCURS']] = $s['DESC_SUCURSAL'] ?? ('Suc. ' . $s['NRO_SUCURS']);
        }
    } catch (Throwable $_) {}

    foreach ($scores as &$s) {
        $s['nombre'] = $sucNombres[$s['nro_sucurs']] ?? ('Suc. ' . $s['nro_sucurs']);
    }
    unset($s);

    ob_clean();
    echo json_encode([
        'ok'     => true,
        'periodo' => [
            'tipo'       => $periodo,
            'desde_act'  => $desde_act,
            'hasta_act'  => $hasta_act,
            'desde_prev' => $desde_prev,
            'hasta_prev' => $hasta_prev,
        ],
        'scores' => $scores,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
