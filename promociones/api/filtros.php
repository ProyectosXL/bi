<?php
/**
 * /bi/promociones/api/filtros.php
 * Listas para los selectores del dashboard de Promociones:
 * bancos, promociones, sucursales.
 *
 * Las listas se cachean (PromoCache): bancos y sucursales recorren toda la
 * tabla y casi no cambian, así que se guardan 1 hora; la lista de
 * promociones depende del período y se guarda 30 minutos.
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/PromocionesDB.php';
require_once __DIR__ . '/../class/PromoCache.php';
// La sesión sólo se lee: liberar el lock para que las demás llamadas del
// tablero (kpis, bancos, donuts) corran en paralelo y no en fila.
session_write_close();

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

    // Calcular fechas si se pasa período (hasta inclusivo)
    $desde = $_GET['desde'] ?? null;
    $hasta = $_GET['hasta'] ?? null;
    if (isset($_GET['periodo']) && !$desde) {
        [$da, $ha] = array_slice(PromocionesDB::calcularPeriodo($_GET['periodo']), 0, 2) + [null, null];
        $desde = $da;
        $hasta = $ha;
    }

    // La conexión se abre sólo si alguna lista no está en caché.
    $db = null;
    $getDb = function() use (&$db, $origen) {
        return $db ??= new PromocionesDB($origen);
    };

    $tryCall = function(string $nombre, int $ttl, array $keyParams, callable $fn) {
        try {
            return PromoCache::remember(PromoCache::key("filtros_{$nombre}", $keyParams), $ttl, $fn);
        } catch (Throwable $e) {
            error_log('[promociones/filtros.php] ' . $nombre . ': ' . $e->getMessage());
            return [];
        }
    };

    $kOrigen = ['origen' => $origen];

    $bancos      = $tryCall('bancos',      3600, $kOrigen, fn() => $getDb()->getBancosLista());
    $promociones = $tryCall('promociones', 1800, $kOrigen + ['desde' => $desde, 'hasta' => $hasta],
                            fn() => $getDb()->getPromocionesLista($desde, $hasta));
    $sucursales  = $tryCall('sucursales',  3600, $kOrigen, fn() => $getDb()->getSucursalesLista());
    $activas     = $isGrupo ? [] : $tryCall('activas', 3600, $kOrigen, fn() => $getDb()->getSucursalesActivasIds());

    ob_clean();
    echo json_encode([
        'ok'                => true,
        'bancos'            => $bancos,
        'promociones'       => $promociones,
        'sucursales'        => $sucursales,
        'sucursales_activas'=> $activas,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
