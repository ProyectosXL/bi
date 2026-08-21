<?php
/**
 * /bi/premios/api/supervisoras_orden.php
 * GET  -> TODAS las supervisoras activas del catálogo (incluidas las ocultas), con su mail
 *         (RO_T_SUPERVISORAS_COMERCIAL.MAIL, de solo lectura — se edita fuera del dashboard,
 *         directo en esa tabla) y si están visibles en ESTE dashboard.
 * POST -> guarda un nuevo orden + qué supervisoras mostrar/ocultar (modal "Configuración de
 *         supervisoras" — arrastrar para reordenar, tilde para mostrar/ocultar).
 */
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../../class/config.php';
require_once __DIR__ . '/../class/PremiosDB.php';

if (!isGlobalMode()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tenés permiso para esta acción']);
    exit;
}

try {
    $hoy = date('Y-m-d');
    $db = new PremiosDB($hoy, $hoy, $hoy, $hoy); // instancia liviana, solo para las conexiones/getters

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $itemsPropuestos = $input['items'] ?? null;
        if (!is_array($itemsPropuestos) || !$itemsPropuestos) {
            throw new InvalidArgumentException('Falta la configuración propuesta');
        }

        // Debe incluir exactamente TODAS las supervisoras activas del catálogo (visibles y
        // ocultas) — si faltara alguna, se perdería su fila (orden/visibilidad) al guardar.
        $activasCatalogo = array_column($db->catalogoConfigSupervisoras(), 'nombre');
        $nombresPropuestos = array_column($itemsPropuestos, 'nombre');
        if (count(array_intersect($nombresPropuestos, $activasCatalogo)) !== count($activasCatalogo)
            || count($nombresPropuestos) !== count($activasCatalogo)
        ) {
            throw new InvalidArgumentException('La configuración propuesta no coincide con las supervisoras activas del catálogo');
        }

        $itemsValidados = array_map(
            fn($it) => ['nombre' => (string) $it['nombre'], 'visible' => (bool) ($it['visible'] ?? true)],
            $itemsPropuestos
        );

        $db->guardarConfiguracionSupervisoras($itemsValidados, $_SESSION['username']);
        ob_clean();
        echo json_encode(['ok' => true]);
        exit;
    }

    ob_clean();
    echo json_encode(['ok' => true, 'supervisoras' => $db->catalogoConfigSupervisoras()]);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
