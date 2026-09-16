<?php
/**
 * /bi/sales/api/variacion_unidades.php
 * Datos para la pestaña Variación Unidades.
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
    $rubro   = $_GET['rubro']   ?? null;
    $cliente = $_GET['cliente'] ?? null;
    $db = new SalesDB();
    if (isset($_GET['solo_activas'])) {
        $db->setSoloActivas($_GET['solo_activas'] === '1' || $_GET['solo_activas'] === 'true');
    }

    // Variación mensual % (año actual vs año anterior)
    $variacion = $db->getVariacionMensualUnidades($canal, $rubro, $cliente, $grupo_empresario);

    // Participación por canal por año (apilado 100%)
    $participacion = $db->getParticipacionCanalAnual($rubro, $cliente, $grupo_empresario, 4);

    ob_clean();
    echo json_encode([
        'ok'           => true,
        'variacion'    => $variacion,
        'participacion'=> $participacion,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
