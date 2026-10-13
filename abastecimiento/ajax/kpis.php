<?php
/**
 * /bi/abastecimiento/ajax/kpis.php
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
        'canal'       => $_GET['canal'] ?? null,
        'sucursal'    => $_GET['sucursal'] ?? null,
        'articulo'    => $_GET['articulo'] ?? null,
        'descripcion' => $_GET['descripcion'] ?? null,
        'fecha_desde' => $_GET['fecha_desde'] ?? null,
        'fecha_hasta' => $_GET['fecha_hasta'] ?? null,
    ];

    $db = new AbastecimientoDB();
    $kpis = $db->getKPIs($filters);
    echo json_encode(['ok' => true, 'kpis' => $kpis], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
