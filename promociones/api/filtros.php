<?php
/**
 * /bi/promociones/api/filtros.php
 * Listas para los selectores del dashboard de Promociones:
 * bancos, promociones, sucursales.
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
    if (!in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION', 'GRUPO'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $isGrupo          = (($_SESSION['tipo'] ?? '') === 'GRUPO');
    $origenPermitidos = ['argentina', 'franquicias', 'uruguay'];
    $origen           = $isGrupo ? 'franquicias'
        : (in_array($_GET['origen'] ?? '', $origenPermitidos, true) ? $_GET['origen'] : 'argentina');

    $db = new PromocionesDB($origen);

    // Calcular fechas si se pasa período
    $desde = $_GET['desde'] ?? null;
    $hasta = $_GET['hasta'] ?? null;
    if (isset($_GET['periodo']) && !$desde) {
        [$da, $ha] = PromocionesDB::calcularPeriodo($_GET['periodo']);
        $desde = $da;
        $hasta = $ha;
    }

    $obtenPromociones = function() use ($db, $desde, $hasta) {
        return $db->getPromocionesLista($desde, $hasta);
    };

    $tryCall = function(callable $fn) {
        try { return $fn(); } catch (Throwable $e) {
            error_log('[promociones/filtros.php] ' . $e->getMessage());
            return [];
        }
    };

    ob_clean();
    echo json_encode([
        'ok'                => true,
        'bancos'            => $tryCall(fn() => $db->getBancosLista()),
        'promociones'       => $tryCall($obtenPromociones),
        'sucursales'        => $tryCall(fn() => $db->getSucursalesLista()),
        'sucursales_activas'=> $isGrupo ? [] : $tryCall(fn() => $db->getSucursalesActivasIds()),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
