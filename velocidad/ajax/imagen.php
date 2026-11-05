<?php
/**
 * /bi/velocidad/ajax/imagen.php
 * GET: articulo=<COD_ARTICU>  -> foto del modelo (proxy a W:\Imagenes).
 * Reutiliza el resolvedor de abastecimiento (maneja variaciones de temporada/color en el código).
 * Si no hay foto, devuelve el ícono de XL.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['username']) && empty($_SESSION['fp_auth_user']['username'])) {
    http_response_code(403);
    exit;
}
session_write_close();

require_once __DIR__ . '/../../abastecimiento/class/AbastecimientoDB.php';

$articulo = mb_substr(trim((string)($_GET['articulo'] ?? '')), 0, 60);

try {
    $db   = new AbastecimientoDB();
    $path = $articulo !== '' ? $db->getImagenPath($articulo) : null;
} catch (Throwable $e) {
    $path = null;
}

if ($path && is_file($path)) {
    $mime = (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png') ? 'image/png' : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

$fallback = __DIR__ . '/../../images/icono.jpg';
if (is_file($fallback)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=300');
    readfile($fallback);
}
