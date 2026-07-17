<?php
/**
 * /bi/sales/api/general.php
 * KPIs y datos para la pestaÃ±a General del Dashboard Sales.
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../class/SalesDB.php';

try {
    $periodo = $_GET['periodo'] ?? 'mes_actual';
    $canal   = $_GET['canal']   ?? null;
    $rubro   = $_GET['rubro']   ?? null;

    if ($periodo === 'custom') {
        $da = $_GET['desde'] ?? date('Y-01-01');
        $ha = $_GET['hasta'] ?? date('Y-m-d', strtotime('-1 day'));
        $dp = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
        $hp = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
    } else {
        [$da, $ha, $dp, $hp] = SalesDB::calcularPeriodo($periodo);
    }

    $db = new SalesDB();

    // 1) KPIs globales (sí usan el período seleccionado arriba)
    $kpis = $db->getKpisGenerales($da, $ha, $dp, $hp, $canal, $rubro);

    // 2) Acumulado año actual vs mismo período año anterior (para la Tabla Acumulado)
    // Debe ser independiente del filtro de arriba. Usamos 'año_actual'
    [$daYtd, $haYtd, $dpYtd, $hpYtd] = SalesDB::calcularPeriodo('año_actual');
    $canalesYtd = $db->getDesgloseCanalKpis($daYtd, $haYtd, $dpYtd, $hpYtd, $rubro);

    // 3) Desglose por canal de la cabecera (sí usa el período seleccionado arriba)
    $canales = $db->getDesgloseCanalKpis($da, $ha, $dp, $hp, $rubro);

    // 4) Serie temporal para los Sparkcharts
    $serie = $db->getVentasSerieTiempo($da, $ha, $canal, $rubro);

    // 5) KPIs de Temporadas
    $temporadas = $db->getKpisTemporadas($canal, $rubro);

    $mensualCanal = [];

    ob_clean();
    echo json_encode([
        'ok'           => true,
        'periodo'      => ['desde' => $da, 'hasta' => $ha, 'desde_prev' => $dp, 'hasta_prev' => $hp],
        'kpis'         => $kpis,
        'canales'      => $canales,
        'canales_ytd'  => $canalesYtd,
        'mensual_canal'=> $mensualCanal,
        'serie_tiempo' => $serie,
        'temporadas'   => $temporadas,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
