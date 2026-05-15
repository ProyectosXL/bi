
<?php
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/MayoristaDB.php';

try {
    $db = new MayoristaDB();

    $region = isset($_GET['region']) && $_GET['region'] !== '' ? $_GET['region'] : null;

    $catPorRubroRows = $db->getCategoriasPorRubro();
    $catPorRubroMap  = [];
    foreach ($catPorRubroRows as $row) {
        $catPorRubroMap[$row['RUBRO']][] = $row['CATEGORIA'];
    }

    ob_clean();
    echo json_encode([
        'ok'                 => true,
        'vendedores'         => array_column($db->getVendedores(),  'VENDEDOR'),
        'rubros'             => array_column($db->getRubros(),      'RUBRO'),
        'categorias'         => array_column($db->getCategorias(),  'CATEGORIA'),
        'categorias_x_rubro' => $catPorRubroMap,
        'regiones'           => array_column($db->getRegiones(),    'REGION'),
        'provincias'         => array_column($db->getProvincias($region), 'PROVINCIA'),
        'clientes'           => array_column($db->getClientes(),    'CLIENTE'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
