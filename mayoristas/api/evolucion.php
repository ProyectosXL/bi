<?php
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/MayoristaDB.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';

try {
    $vendedor  = isset($_GET['vendedor'])  && $_GET['vendedor']  !== '' ? $_GET['vendedor']  : null;
    $cliente   = isset($_GET['cliente'])   && $_GET['cliente']   !== '' ? $_GET['cliente']   : null;
    $rubro     = isset($_GET['rubro'])     && $_GET['rubro']     !== '' ? $_GET['rubro']     : null;
    $categoria = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? $_GET['categoria'] : null;
    $region    = isset($_GET['region'])    && $_GET['region']    !== '' ? $_GET['region']    : null;
    $provincia = isset($_GET['provincia']) && $_GET['provincia'] !== '' ? $_GET['provincia'] : null;
    $anios     = isset($_GET['anios'])     ? max(2, min(5, (int)$_GET['anios'])) : 3;

    $db   = new MayoristaDB();
    $rows = $db->getEvolucion($vendedor, $cliente, $rubro, $categoria, $region, $provincia, $anios);

    // Restructurar: { anios: [], meses: [{mes, label, unidades_ANIO, ...}] }
    $aniosSet = [];
    $byMes    = [];

    foreach ($rows as $row) {
        $a = (int)$row['anio'];
        $m = (int)$row['mes'];
        $aniosSet[$a] = true;
        if (!isset($byMes[$m])) $byMes[$m] = ['mes' => $m];
        $byMes[$m]['unidades_' . $a] = (float)$row['unidades'];
    }

    ksort($aniosSet);
    ksort($byMes);

    $meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    foreach ($byMes as &$m) {
        $m['label'] = $meses[$m['mes']];
    }
    unset($m);

    // Participación por rubro para el período seleccionado
    [$desde, $hasta] = PeriodHelper::fromRequest($_GET);
    $rubros = $db->getParticipacionRubro($desde, $hasta, $vendedor, $cliente, $categoria, $region, $provincia);

    ob_clean();
    echo json_encode([
        'ok'     => true,
        'anios'  => array_keys($aniosSet),
        'meses'  => array_values($byMes),
        'rubros' => $rubros,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
