<?php
/**
 * DEBUG TEMPORAL — eliminar tras diagnosticar el perfil GRUPO.
 * Acceder como usuario GRUPO: /bi/global/api/debug_grupo.php
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

// Solo accesible si hay sesión activa
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Sin sesión']);
    exit;
}

echo json_encode([
    'username'        => $_SESSION['username']        ?? null,
    'tipo'            => $_SESSION['tipo']            ?? null,
    'codClient'       => $_SESSION['codClient']       ?? null,
    'esGrupo'         => $_SESSION['esGrupo']         ?? null,
    'sucursalesGrupo' => $_SESSION['sucursalesGrupo'] ?? null,
    'numsuc'          => $_SESSION['numsuc']          ?? null,
    'descLocal'       => $_SESSION['descLocal']       ?? null,
    'permisos'        => $_SESSION['permisos']        ?? null,
    'session_id'      => session_id(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
