<?php
/**
 * /bi/sales/api/evolucion_facturacion.php
 * Datos para la pestaÃ±a EvoluciÃ³n FacturaciÃ³n.
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
    $grupo_empresario = $_GET['grupo_empresario'] ?? null;
    $periodo = $_GET['periodo'] ?? 'mes_pasado';

    if ($periodo === 'custom') {
        $da = $_GET['desde'] ?? date('Y-01-01');
        $ha = $_GET['hasta'] ?? date('Y-m-d', strtotime('-1 day'));
        $dp = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
        $hp = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
    } else {
        [$da, $ha, $dp, $hp] = SalesDB::calcularPeriodo($periodo);
    }

    $db   = new SalesDB();
    $anio = (int)date('Y');

    // Tabla de rubros (comparativo)
    $rubros = $db->getEvolucionRubrosFacturacion($da, $ha, $dp, $hp, $canal, $cliente, $grupo_empresario);

    // Evolución mensual multi-año para el gráfico
    $evolucion = $db->getEvolucionMensualFacturacion($canal, $rubro, 4, $cliente, $grupo_empresario);

    // Tabla mensual por canal
    $tablaMensual = $db->getTablaFacturacionCanalMes($anio, $canal, $rubro, $cliente, $grupo_empresario);

    ob_clean();
    echo json_encode([
        'ok'           => true,
        'periodo'      => ['desde' => $da, 'hasta' => $ha, 'desde_prev' => $dp, 'hasta_prev' => $hp],
        'rubros'       => $rubros,
        'evolucion'    => $evolucion,
        'tabla_mensual'=> $tablaMensual,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
