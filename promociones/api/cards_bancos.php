<?php
/**
 * /bi/promociones/api/cards_bancos.php
 * Facturación y tickets C/Promo por banco (TOP 6).
 */
session_start();
ob_start();
set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/PromocionesDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) {
        throw new RuntimeException('No autenticado');
    }
    if (!in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION', 'GRUPO'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $isGrupo          = (($_SESSION['tipo'] ?? '') === 'GRUPO');
    $origenPermitidos = ['argentina', 'franquicias', 'uruguay'];
    $origen           = $isGrupo ? 'franquicias'
        : (in_array($_GET['origen'] ?? '', $origenPermitidos, true) ? $_GET['origen'] : 'argentina');
    $periodo = $_GET['periodo'] ?? 'mes_actual';

    $fp = [
        'banco'     => isset($_GET['banco'])     && $_GET['banco']     !== '' ? $_GET['banco']          : null,
        'sucursal'  => isset($_GET['sucursal'])  && $_GET['sucursal']  !== '' ? (int)$_GET['sucursal']  : null,
        'promocion' => isset($_GET['promocion']) && $_GET['promocion'] !== '' ? $_GET['promocion']       : null,
    ];

    if ($isGrupo && $fp['sucursal'] !== null) {
        if (!in_array($fp['sucursal'], $_SESSION['sucursalesGrupo'] ?? [], true)) {
            $fp['sucursal'] = null;
        }
    }

    if ($periodo === 'custom') {
        $da = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        $comp_mode = $_GET['comp_mode'] ?? 'year_ago';
        if ($comp_mode === 'custom') {
            $dp = $_GET['desde_comp'] ?? (new DateTime($da))->modify('-1 year')->format('Y-m-d');
            $hp = $_GET['hasta_comp'] ?? (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        } else {
            $dp = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
            $hp = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        }
    } else {
        [$da, $ha, $dp, $hp] = PromocionesDB::calcularPeriodo($periodo);
    }

    $db    = new PromocionesDB($origen);
    $cards = $db->getCardsBancos($da, $ha, $dp, $hp, $fp, 6);

    ob_clean();
    echo json_encode(['ok' => true, 'bancos' => $cards], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
