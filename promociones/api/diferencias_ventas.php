<?php
/**
 * /bi/promociones/api/diferencias_ventas.php
 * Devuelve el estado de diferencias de ventas de franquicias
 * consultando la tabla RO_T_COMPARA_VENTAS_FRANQ (misma fuente que controlGestion/compararVentas).
 *
 * GET ?desde=Y-m-d&hasta=Y-m-d[&sucursales=908,915,...]
 * Response: { ok: true, diferencias: { "908": true, "915": false, ... } }
 */
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/PromocionesDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) {
        throw new RuntimeException('No autenticado');
    }
    if (!in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $desde = $_GET['desde'] ?? null;
    $hasta = $_GET['hasta'] ?? null;

    if (!$desde || !$hasta) {
        throw new RuntimeException('Faltan parámetros desde/hasta');
    }

    // Validar formato de fecha
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        throw new RuntimeException('Formato de fecha inválido, usar Y-m-d');
    }

    $nrosSucursal = null;
    if (!empty($_GET['sucursales'])) {
        $nrosSucursal = array_filter(array_map('intval', explode(',', $_GET['sucursales'])));
        $nrosSucursal = array_values($nrosSucursal);
    }

    // Siempre usar conexión franquicias para esta tabla
    $db           = new PromocionesDB('franquicias');
    $diferencias  = $db->getDiferenciaVentas($desde, $hasta, $nrosSucursal ?: null);

    ob_clean();
    echo json_encode([
        'ok'          => true,
        'diferencias' => $diferencias,
        'desde'       => $desde,
        'hasta'       => $hasta,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
