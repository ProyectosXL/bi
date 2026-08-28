<?php
/**
 * /bi/premios/api/avance_quincenal.php
 * GET ?supervisora=X -> avance de venta (facturación acumulada de los primeros 15 días
 * del mes en curso vs. el objetivo REAL del mes completo) para UNA supervisora, para
 * pintar el modal "Avance 15 días" antes de que Johanna lo mande (ver enviar_avance_quincenal.php).
 * Datos de AvanceQuincenalDB — tablas diarias, nunca las mensuales de premios.
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
require_once __DIR__ . '/../class/AvanceQuincenalDB.php';

if (!isGlobalMode()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tenés permiso para esta acción']);
    exit;
}

try {
    $supervisora = trim($_GET['supervisora'] ?? '');
    if ($supervisora === '') {
        throw new InvalidArgumentException('Falta la supervisora');
    }

    $hoy = date('Y-m-d');
    $premiosDb = new PremiosDB($hoy, $hoy, $hoy, $hoy);
    if (!in_array($supervisora, $premiosDb->getSupervisoras(), true)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => "Supervisora no encontrada o no visible: $supervisora"]);
        exit;
    }

    $hoyDt = new DateTime('today');
    $desde = $hoyDt->format('Y-m-01');
    $hasta = min($hoyDt->format('Y-m-15'), (clone $hoyDt)->modify('-1 day')->format('Y-m-d'));
    if ($hasta < $desde) $hasta = $desde;

    // Se le pasa el listado COMPLETO de supervisoras activas, no solo $supervisora: los
    // benchmarks de marca (ticket promedio, % ticket 2do/3er producto) para "% Cumpl.
    // Cadena" tienen que salir de TODA la cadena, no solo de las sucursales de esta
    // supervisora — mismo criterio que PremiosDB::resumenPorSupervisora().
    $avanceDb = new AvanceQuincenalDB($desde, $hasta);
    $activas = $premiosDb->getSupervisoras();
    $filas = $avanceDb->avancePorSupervisora($activas);
    $fila = $filas[array_search($supervisora, $activas, true)];

    ob_clean();
    echo json_encode(['ok' => true, 'desde' => $desde, 'hasta' => $hasta] + $fila, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
