<?php
/**
 * /bi/velocidad/ajax/filtros.php
 * GET: origen=ar|uy  -> listas para los filtros (rubros, categorías, colores, líneas, modelos)
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../class/VelocidadVentasDB.php';

try {
    $origen = vv_txt($_GET['origen'] ?? 'ar', 10);
    if (!VelocidadVentasDB::origenValido($origen)) {
        vv_json(['ok' => false, 'error' => 'Origen inválido'], 400);
    }
    $db = new VelocidadVentasDB();
    vv_json(['ok' => true, 'data' => $db->getFiltros($origen)]);
} catch (Throwable $e) {
    vv_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
