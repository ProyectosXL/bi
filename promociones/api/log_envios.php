<?php
/**
 * /bi/promociones/api/log_envios.php
 * Devuelve qué sucursales ya recibieron comunicado (email) en un período dado.
 *
 * GET ?desde=Y-m-d&hasta=Y-m-d[&sucursales=908,915,...]
 * Response: { ok: true, comunicados: { "908": true, "915": false, ... } }
 *
 * POST { nro_sucursal, desde, hasta, nombre }
 *   Registra un envío exitoso en el log.
 */
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$logFile = __DIR__ . '/../log_envios.json';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) {
        throw new RuntimeException('No autenticado');
    }
    if (!in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── Leer log ──────────────────────────────────────────────────────────
    $log = [];
    if (file_exists($logFile)) {
        $log = json_decode(file_get_contents($logFile), true) ?? [];
    }

    if ($method === 'POST') {
        // Registrar envío exitoso
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['nro_sucursal'], $data['desde'], $data['hasta'])) {
            throw new RuntimeException('Datos inválidos para registro de envío');
        }

        $nro    = (string)$data['nro_sucursal'];
        $desde  = $data['desde'];
        $hasta  = $data['hasta'];
        $period = $desde . '|' . $hasta;

        if (!isset($log[$period])) $log[$period] = [];
        $log[$period][$nro] = [
            'enviado_at' => date('Y-m-d H:i:s'),
            'nombre'     => $data['nombre'] ?? '',
            'usuario'    => $_SESSION['username'] ?? '',
        ];

        file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        ob_clean();
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── GET: consultar comunicados para un período ─────────────────────────
    $desde = $_GET['desde'] ?? null;
    $hasta = $_GET['hasta'] ?? null;

    if (!$desde || !$hasta) {
        throw new RuntimeException('Faltan parámetros desde/hasta');
    }

    $period = $desde . '|' . $hasta;
    $periodoLog = $log[$period] ?? [];

    // Filtrar por sucursales si se especificaron
    $nrosFiltro = null;
    if (!empty($_GET['sucursales'])) {
        $nrosFiltro = array_flip(array_filter(explode(',', $_GET['sucursales'])));
    }

    $comunicados = [];
    foreach ($periodoLog as $nro => $entry) {
        if ($nrosFiltro !== null && !isset($nrosFiltro[$nro])) continue;
        $comunicados[$nro] = true;
    }

    // Para las sucursales pedidas que NO están en el log → false
    if ($nrosFiltro !== null) {
        foreach (array_keys($nrosFiltro) as $nro) {
            if (!isset($comunicados[$nro])) $comunicados[$nro] = false;
        }
    }

    ob_clean();
    echo json_encode([
        'ok'         => true,
        'comunicados'=> $comunicados,
        'desde'      => $desde,
        'hasta'      => $hasta,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
