<?php
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/MayoristaDB.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';

try {
    $anio = isset($_GET['anio']) && (int)$_GET['anio'] > 2000 ? (int)$_GET['anio'] : null;
    if (!$anio) {
        [$desde] = PeriodHelper::fromRequest($_GET);
        $anio    = $desde ? (int)substr($desde, 0, 4) : (int)date('Y');
    }

    $vendedor  = isset($_GET['vendedor'])  && $_GET['vendedor']  !== '' ? $_GET['vendedor']  : null;
    $cliente   = isset($_GET['cliente'])   && $_GET['cliente']   !== '' ? $_GET['cliente']   : null;
    $rubro     = isset($_GET['rubro'])     && $_GET['rubro']     !== '' ? $_GET['rubro']     : null;
    $categoria = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? $_GET['categoria'] : null;
    $region    = isset($_GET['region'])    && $_GET['region']    !== '' ? $_GET['region']    : null;
    $provincia = isset($_GET['provincia']) && $_GET['provincia'] !== '' ? $_GET['provincia'] : null;

    $db   = new MayoristaDB();
    $rows = $db->getEvolucionPorRubro($anio, $vendedor, $cliente, $rubro, $categoria, $region, $provincia);

    // Top 8 rubros por total de unidades en el año (el resto se descarta)
    $rubroTotals = [];
    foreach ($rows as $row) {
        $r = $row['RUBRO'];
        $rubroTotals[$r] = ($rubroTotals[$r] ?? 0) + (float)$row['unidades'];
    }
    arsort($rubroTotals);
    $topRubroNames = array_slice(array_keys($rubroTotals), 0, 8);
    $rows = array_values(array_filter($rows, fn($r) => in_array($r['RUBRO'], $topRubroNames)));

    // Restructurar: { anio, rubros: [], meses: [{mes, label, RUBRO: x, ...}] }
    $rubrosSet = [];
    $byMes     = [];

    foreach ($rows as $row) {
        $r = $row['RUBRO'];
        $m = (int)$row['mes'];
        $rubrosSet[$r] = true;
        if (!isset($byMes[$m])) $byMes[$m] = ['mes' => $m];
        $byMes[$m][$r] = (float)$row['unidades'];
    }

    ksort($byMes);

    $meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    foreach ($byMes as &$m) {
        $m['label'] = $meses[$m['mes']];
    }
    unset($m);

    // Rubros ordenados por total (mayor primero) para que el gráfico muestre los más importantes arriba
    $rubrosOrdenados = $topRubroNames;

    ob_clean();
    echo json_encode([
        'ok'    => true,
        'anio'  => $anio,
        'rubros' => $rubrosOrdenados,
        'meses'  => array_values($byMes),
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
