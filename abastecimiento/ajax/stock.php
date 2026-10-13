<?php
/**
 * /bi/abastecimiento/ajax/stock.php
 */
session_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../class/AbastecimientoDB.php';

try {
    $filters = [
        'proveedor'   => $_GET['proveedor'] ?? null,
        'rubro'       => $_GET['rubro'] ?? null,
        'categoria'   => $_GET['categoria'] ?? null,
        'temporada'   => $_GET['temporada'] ?? null,
        'sucursal'    => $_GET['sucursal'] ?? null,
        'articulo'    => $_GET['articulo'] ?? null,
        'descripcion' => $_GET['descripcion'] ?? null,
    ];

    $db = new AbastecimientoDB();
    $stockSucursal = $db->getStockSucursales($filters, 200);
    $stockCentral  = $db->getStockCentral($filters, 200);

    echo json_encode([
        'ok'             => true,
        'stock_sucursal' => $stockSucursal,
        'stock_central'  => $stockCentral
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
