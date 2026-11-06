<?php
/**
 * /bi/velocidad/ajax/_bootstrap.php
 * Arranque común de los endpoints: guard de sesión, helpers de entrada y salida JSON.
 * (Mismo criterio de sesión que /bi/index.php.)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username']) && empty($_SESSION['fp_auth_user']['username'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Sesión expirada. Volvé a iniciar sesión.']);
    exit;
}
// Las consultas pueden tardar: liberar el lock de sesión para no bloquear otras requests en paralelo.
session_write_close();

function vv_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
    exit;
}

/** Texto acotado y sin espacios en los extremos ('' si no viene). */
function vv_txt($v, int $max = 120): string
{
    if (!is_string($v)) {
        return '';
    }
    return mb_substr(trim($v), 0, $max);
}

/** Fecha 'YYYY-MM-DD' válida o null. */
function vv_fecha($v): ?string
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return null;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $y) ? $v : null;
}

/** Filtros del tablero a partir de $_GET (todos opcionales). */
function vv_filtros_desde_get(): array
{
    return [
        'desde'     => vv_fecha($_GET['desde'] ?? ''),
        'hasta'     => vv_fecha($_GET['hasta'] ?? ''),
        'rubro'     => vv_txt($_GET['rubro'] ?? ''),
        'categoria' => vv_txt($_GET['categoria'] ?? ''),
        'color'     => vv_txt($_GET['color'] ?? ''),
        'linea'     => vv_txt($_GET['linea'] ?? ''),
        'modelo'    => vv_txt($_GET['modelo'] ?? ''),
    ];
}
