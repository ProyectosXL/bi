<?php
/**
 * /bi/velocidad/ajax/stock.php
 * GET: modelo=<COD_ARTICU>  -> stock por sucursal (BI_STOCK_LOCALES, solo Argentina)
 */
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../class/VelocidadVentasDB.php';

try {
    $modelo = vv_txt($_GET['modelo'] ?? '', 60);
    if ($modelo === '') {
        vv_json(['ok' => false, 'error' => 'Falta el modelo'], 400);
    }
    $db = new VelocidadVentasDB();
    vv_json(['ok' => true, 'data' => $db->getStock($modelo)]);
} catch (Throwable $e) {
    vv_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
