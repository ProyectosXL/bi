<?php
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/MayoristaDB.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';

try {
    [$desde, $hasta, $desdePrev, $hastaPrev] = PeriodHelper::fromRequest($_GET);

    $vendedor  = isset($_GET['vendedor'])  && $_GET['vendedor']  !== '' ? $_GET['vendedor']  : null;
    $cliente   = isset($_GET['cliente'])   && $_GET['cliente']   !== '' ? $_GET['cliente']   : null;
    $rubro     = isset($_GET['rubro'])     && $_GET['rubro']     !== '' ? $_GET['rubro']     : null;
    $categoria = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? $_GET['categoria'] : null;
    $region    = isset($_GET['region'])    && $_GET['region']    !== '' ? $_GET['region']    : null;
    $provincia = isset($_GET['provincia']) && $_GET['provincia'] !== '' ? $_GET['provincia'] : null;

    $db     = new MayoristaDB();
    $result = $db->getKPIs(
        $desde, $hasta, $desdePrev, $hastaPrev,
        $vendedor, $cliente, $rubro, $categoria, $region, $provincia
    );

    $result['periodo'] = [
        'desde'      => $desde,
        'hasta'      => $hasta,
        'desde_prev' => $desdePrev,
        'hasta_prev' => $hastaPrev,
    ];

    ob_clean();
    echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
