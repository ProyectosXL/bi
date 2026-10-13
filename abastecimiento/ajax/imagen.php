<?php
/**
 * /bi/abastecimiento/ajax/imagen.php
 * Endpoint y proxy para visualización directa de imágenes de productos
 */
require_once __DIR__ . '/../class/AbastecimientoDB.php';

$articulo = trim($_GET['articulo'] ?? '');
$db = new AbastecimientoDB();

if (isset($_GET['show'])) {
    $path = $db->getImagenPath($articulo);
    if ($path && file_exists($path)) {
        $mime = (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png') ? 'image/png' : 'image/jpeg';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    } else {
        $fallback = __DIR__ . '/../../images/icono.jpg';
        if (file_exists($fallback)) {
            header('Content-Type: image/jpeg');
            readfile($fallback);
        }
        exit;
    }
}

// Retornar JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$path = $db->getImagenPath($articulo);
echo json_encode([
    'ok'    => true,
    'found' => ($path !== null),
    'url'   => '/bi/abastecimiento/ajax/imagen.php?show=1&articulo=' . urlencode($articulo)
], JSON_UNESCAPED_UNICODE);
