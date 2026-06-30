<?php
/**
 * /bi/promociones/api/mensual.php
 * Serie anual mensual — toda la historia, respeta Banco/Sucursal/Promoción.
 * El JS calcula ratios y columna "Año Anterior" a partir de la estructura cruda.
 */
session_start();
ob_start();
set_time_limit(120);
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

    $db   = new PromocionesDB($origen);
    $data = $db->getSerieAnualMensual($fp);

    ob_clean();
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
