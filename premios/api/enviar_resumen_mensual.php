<?php
/**
 * /bi/premios/api/enviar_resumen_mensual.php
 * Envía el resumen de premios (supervisora + importe total) a los destinatarios
 * configurados en adminNotificaciones bajo TIPO_NOTIFICACION='PREMIOS_RESUMEN_MENSUAL'
 * (ver premios/sql/setup_control_mail.sql).
 */
session_start();
ob_start();
set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../../class/config.php';
require_once __DIR__ . '/../../class/PeriodHelper.php';
require_once __DIR__ . '/../class/PremiosDB.php';
require_once __DIR__ . '/../class/MailPremios.php';

if (!isGlobalMode()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tenés permiso para esta acción']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    [$da, $ha, $dp, $hp] = PeriodHelper::fromRequest($input);
    $db = new PremiosDB($da, $ha, $dp, $hp);

    $destinatarios = MailPremios::enviarResumenMensual($db, $da, $ha);

    ob_clean();
    echo json_encode(['ok' => true, 'destinatarios' => $destinatarios]);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
