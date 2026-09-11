<?php
/**
 * /bi/premios/api/ecommerce.php
 * Vista "Premios Ecommerce": premios del personal del área, persona por persona y
 * concepto por concepto, según el cumplimiento de sus objetivos.
 *
 * Ignora el filtro de supervisora del toolbar (no aplica a esta pestaña).
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

require_once __DIR__ . '/../../class/PeriodHelper.php';
require_once __DIR__ . '/../../class/config.php';
require_once __DIR__ . '/../class/PremiosEcommerceDB.php';

try {
    [$da, $ha, $dp, $hp] = PeriodHelper::fromRequest($_GET);

    $db  = new PremiosEcommerceDB($da, $ha, $dp, $hp);
    $res = $db->calcular();

    $ultimaActFormatted = null;
    $isOutdated = false;
    $ultimaActRaw = $db->getUltimaActualizacion();
    if ($ultimaActRaw) {
        $dtUpdate = new DateTime($ultimaActRaw);
        $ultimaActFormatted = $dtUpdate->format('d/m/Y H:i:s');
        $isOutdated = PremiosEcommerceDB::esDesactualizado($dtUpdate);
    }

    ob_clean();
    echo json_encode([
        'ok'      => true,
        'periodo' => ['desde' => $da, 'hasta' => $ha, 'desde_prev' => $dp, 'hasta_prev' => $hp],
        // El objetivo de facturación es SIEMPRE del mes completo (FP_ObjetivosFinales es
        // mensual): con un rango parcial, el % de cumplimiento no es comparable y el front
        // muestra un banner de aviso.
        'periodo_parcial' => $db->periodoParcial(),
        'personas'        => $res['personas'],
        'total_general'   => $res['total_general'],
        'objetivos'       => $res['objetivos'],
        'reales'          => $res['reales'],
        'sesiones'        => $res['sesiones'],
        'tasa_estimada'   => $res['tasa_estimada'],
        'kpis_faltantes'  => $db->kpisFaltantes(),
        'carga_manual'    => $db->ultimaCargaManual(),
        'puede_gestionar' => isGlobalMode(),
        'ultima_actualizacion' => $ultimaActFormatted,
        'is_outdated' => $isOutdated,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
