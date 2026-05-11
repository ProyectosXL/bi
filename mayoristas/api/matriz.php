<?php
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/MayoristaDB.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';

try {
    [$desde, $hasta] = PeriodHelper::fromRequest($_GET);

    $cliente     = isset($_GET['cliente'])   && $_GET['cliente']   !== '' ? $_GET['cliente']   : null;
    $vendedor    = isset($_GET['vendedor'])  && $_GET['vendedor']  !== '' ? $_GET['vendedor']  : null;
    $categoria   = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? $_GET['categoria'] : null;
    $region      = isset($_GET['region'])    && $_GET['region']    !== '' ? $_GET['region']    : null;
    $provincia   = isset($_GET['provincia']) && $_GET['provincia'] !== '' ? $_GET['provincia'] : null;
    $topClientes = isset($_GET['top'])       ? max(5, min(20, (int)$_GET['top'])) : 10;

    $db = new MayoristaDB();

    if ($cliente !== null) {
        // Vista de un cliente específico: desglose por rubro
        $rubros = $db->getParticipacionRubro($desde, $hasta, $vendedor, $cliente, $categoria, $region, $provincia);
        $total  = array_sum(array_column($rubros, 'unidades'));

        ob_clean();
        echo json_encode([
            'ok'     => true,
            'mode'   => 'cliente',
            'cliente' => $cliente,
            'total'  => $total,
            'rubros' => $rubros,
        ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    } else {
        // Vista matricial: top N clientes × rubros
        $result = $db->getMatrizClienteRubro($desde, $hasta, $vendedor, $categoria, $region, $provincia, $topClientes);

        ob_clean();
        echo json_encode(
            ['ok' => true, 'mode' => 'matriz'] + $result,
            JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK
        );
    }
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
