<?php
/**
 * /bi/velocidad/ajax/ranking.php
 * GET: origen=ar|uy, modo=top|bottom, desde, hasta, rubro, categoria, color, linea, modelo, limit
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../class/VelocidadVentasDB.php';

set_time_limit(120);

try {
    $origen = vv_txt($_GET['origen'] ?? 'ar', 10);
    if (!VelocidadVentasDB::origenValido($origen)) {
        vv_json(['ok' => false, 'error' => 'Origen inválido'], 400);
    }
    $modo  = (vv_txt($_GET['modo'] ?? 'top', 10) === 'bottom') ? 'bottom' : 'top';
    $limit = (int)($_GET['limit'] ?? 1000);

    $db   = new VelocidadVentasDB();
    $rows = $db->getRanking($origen, $modo, vv_filtros_desde_get(), $limit);

    vv_json([
        'ok'          => true,
        'origen'      => $origen,
        'modo'        => $modo,
        'total'       => count($rows),
        'actualizado' => $db->getUltimaActualizacion($origen),
        'data'        => $rows,
    ]);
} catch (Throwable $e) {
    vv_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
