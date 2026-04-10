<?php
/**
 * /global/api/filtros.php
 * Devuelve listas para poblar los selectores del dashboard global:
 *   - sucursales, grupos, tipos de tienda (según origen)
 *   - vendedores, rubros
 */
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/GlobalDashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) {
        throw new RuntimeException('No autenticado');
    }
    $tipoSesion = $_SESSION['tipo'] ?? '';
    if (!in_array($tipoSesion, ['GERENCIA', 'SUPERVISION'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $origen    = $_GET['origen']   ?? 'argentina';
    $periodo   = $_GET['periodo']  ?? 'mes_actual';
    $sucursal  = isset($_GET['sucursal']) && $_GET['sucursal'] !== '' ? (int)$_GET['sucursal'] : null;

    [$desde_act, $hasta_act] = array_slice(GlobalDashboardDB::calcularPeriodo($periodo), 0, 2);

    $db = new GlobalDashboardDB($origen);

    $soloArg = ($origen === 'argentina');

    $tryCall = function(callable $fn) {
        try { return $fn(); } catch (Throwable $e) {
            error_log('[filtros.php] ' . $e->getMessage());
            return [];
        }
    };

    ob_clean();
    echo json_encode([
        'ok'                 => true,
        'campo_vendedor'     => 'DESC_VENDEDOR',
        'sucursales'         => $tryCall(fn() => $db->getSucursalesLista()),
        'grupos'             => $soloArg ? $tryCall(fn() => $db->getGruposLista()) : [],
        'tipos_tienda'       => $soloArg ? $tryCall(fn() => $db->getTiposTiendaLista()) : [],
        'vendedores'         => $tryCall(fn() => $db->getVendedoresFiltro($desde_act, $hasta_act, $sucursal)),
        'rubros'             => $tryCall(fn() => $db->getRubrosFiltro($desde_act, $hasta_act, $sucursal)),
        'sucursales_activas' => $tryCall(fn() => $db->getSucursalesActivasIds()),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
