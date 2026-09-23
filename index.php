<?php
/**
 * /bi/index.php — Router principal
 *
 * Dirige al tablero correcto según el perfil de sesión:
 *   GERENCIA / SUPERVISION / ANALISTAS / COMERCIAL → /global/index.php (dashboard multi-sucursal)
 *   Cualquier sucursal con número de local       → /sucursales/index.php (dashboard por sucursal)
 *
 * Si no hay sesión activa redirige al login.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username']) && empty($_SESSION['fp_auth_user']['username'])) {
    header('Location: sistemas/login.php');
    exit;
}

$tipo       = strtoupper(trim($_SESSION['tipo'] ?? ''));
$rolNombre  = strtoupper(trim($_SESSION['fp_auth_user']['rol_nombre'] ?? $_SESSION['rol_nombre'] ?? ''));
$rolCodigo  = strtoupper(trim($_SESSION['fp_auth_user']['rol_codigo'] ?? $_SESSION['rol'] ?? ''));
$puesto     = strtoupper(trim($_SESSION['fp_auth_user']['puesto'] ?? $_SESSION['puesto'] ?? ''));
$esAdmin    = !empty($_SESSION['fp_auth_user']['es_admin']) || !empty($_SESSION['es_admin']);
$sinSucursal = empty($_SESSION['numsuc']);

$esGlobal = in_array($tipo, ['GERENCIA', 'SUPERVISION', 'GRUPO', 'ANALISTA', 'ANALISTAS', 'ANALISTA COMERCIAL', 'ANALISTAS COMERCIAL', 'COMERCIAL', 'ADMIN', 'ADMINISTRADOR', 'SISTEMAS'], true)
    || strpos($tipo, 'ANALISTA') !== false
    || strpos($rolNombre, 'ANALISTA') !== false
    || strpos($rolCodigo, 'ANALISTA') !== false
    || strpos($puesto, 'ANALISTA') !== false
    || strpos($rolNombre, 'COMERCIAL') !== false
    || $esAdmin
    || $sinSucursal;

if ($esGlobal) {
    if (!in_array($tipo, ['GERENCIA', 'SUPERVISION', 'GRUPO'], true)) {
        $_SESSION['tipo'] = 'GERENCIA';
    }
    require __DIR__ . '/global/index.php';
} else {
    require __DIR__ . '/sucursales/index.php';
}

