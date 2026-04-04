<?php
/**
 * /bi/index.php — Router principal
 *
 * Dirige al tablero correcto según el perfil de sesión:
 *   GERENCIA / SUPERVISION → /global/index.php  (dashboard multi-sucursal)
 *   Cualquier otro         → /sucursales/index.php (dashboard por sucursal)
 *
 * Si no hay sesión activa redirige al login.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    header('Location: sistemas/login.php');
    exit;
}

$tipo = $_SESSION['tipo'] ?? '';

if (in_array($tipo, ['GERENCIA', 'SUPERVISION'], true)) {
    require __DIR__ . '/global/index.php';
} else {
    require __DIR__ . '/sucursales/index.php';
}
