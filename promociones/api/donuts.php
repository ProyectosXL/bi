<?php
/**
 * /bi/promociones/api/donuts.php
 * Tres desgloses: Facturación, Promociones, Bancos.
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
        'banco'               => isset($_GET['banco'])               && $_GET['banco']               !== '' ? $_GET['banco']                : null,
        'sucursal'            => isset($_GET['sucursal'])            && $_GET['sucursal']            !== '' ? (int)$_GET['sucursal']        : null,
        'promocion'           => isset($_GET['promocion'])           && $_GET['promocion']           !== '' ? $_GET['promocion']             : null,
        'excluir_promociones' => isset($_GET['excluir_promociones']) && $_GET['excluir_promociones'] !== '' ? $_GET['excluir_promociones']   : null,
    ];

    if ($isGrupo && $fp['sucursal'] !== null) {
        if (!in_array($fp['sucursal'], $_SESSION['sucursalesGrupo'] ?? [], true)) {
            $fp['sucursal'] = null;
        }
    }

    if ($periodo === 'custom') {
        $da = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
    } else {
        [$da, $ha] = array_slice(PromocionesDB::calcularPeriodo($periodo), 0, 2);
    }

    $db     = new PromocionesDB($origen);
    $donuts = $db->getDonutDesglose($da, $ha, $fp);

    ob_clean();
    echo json_encode(['ok' => true, 'donuts' => $donuts], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
