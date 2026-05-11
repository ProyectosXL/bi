<?php
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/MayoristaDB.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';

try {
    [$desde, $hasta] = PeriodHelper::fromRequest($_GET);

    $vendedor  = isset($_GET['vendedor'])  && $_GET['vendedor']  !== '' ? $_GET['vendedor']  : null;
    $cliente   = isset($_GET['cliente'])   && $_GET['cliente']   !== '' ? $_GET['cliente']   : null;
    $rubro     = isset($_GET['rubro'])     && $_GET['rubro']     !== '' ? $_GET['rubro']     : null;
    $categoria = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? $_GET['categoria'] : null;
    $region    = isset($_GET['region'])    && $_GET['region']    !== '' ? $_GET['region']    : null;
    $provincia = isset($_GET['provincia']) && $_GET['provincia'] !== '' ? $_GET['provincia'] : null;

    $db   = new MayoristaDB();
    $rows = $db->getTablaClientes(
        $desde, $hasta,
        $vendedor, $cliente, $rubro, $categoria, $region, $provincia
    );

    $totUnidades = array_sum(array_column($rows, 'unidades'));

    ob_clean();
    echo json_encode([
        'ok'      => true,
        'data'    => $rows,
        'totales' => [
            'unidades' => $totUnidades,
            'clientes' => count($rows),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
