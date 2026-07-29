<?php
/**
 * /bi/sales/api/unidades.php
 * Datos para la pestaña Unidades.
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

// LOGIN DESACTIVADO TEMPORALMENTE — restaurar este bloque para volver a exigir sesión.
// if (!isset($_SESSION['username'])) {
//     http_response_code(401);
//     echo json_encode(['ok' => false, 'error' => 'No autenticado']);
//     exit;
// }

require_once __DIR__ . '/../class/SalesDB.php';

try {
    $canal   = $_GET['canal']   ?? null;
    $cliente = $_GET['cliente'] ?? null;
    $grupo_empresario = $_GET['grupo_empresario'] ?? null;
    $periodo = $_GET['periodo'] ?? 'año_actual';

    if ($periodo === 'custom') {
        $da = $_GET['desde'] ?? date('Y-01-01');
        $ha = $_GET['hasta'] ?? date('Y-m-d', strtotime('-1 day'));
        $dp = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
        $hp = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
    } else {
        [$da, $ha, $dp, $hp] = SalesDB::calcularPeriodo($periodo);
    }

    $db = new SalesDB();

    // Tabla de unidades por rubro (actual vs año anterior)
    $tabla = $db->getTablaUnidades($da, $ha, $dp, $hp, $canal, $cliente, $grupo_empresario);

    // Tabla mensual de unidades por rubro del año en curso
    $anioActual = (int)date('Y');
    $tablaMensual = $db->getTablaUnidadesMensualRubro($anioActual, $canal, $cliente, $grupo_empresario);

    ob_clean();
    echo json_encode([
        'ok'           => true,
        'periodo'      => ['desde' => $da, 'hasta' => $ha, 'desde_prev' => $dp, 'hasta_prev' => $hp],
        'tabla'        => $tabla,
        'tabla_mensual'=> $tablaMensual,
        'anio_actual'  => $anioActual,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
