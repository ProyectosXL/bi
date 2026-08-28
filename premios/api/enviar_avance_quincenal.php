<?php
/**
 * /bi/premios/api/enviar_avance_quincenal.php
 * Manda el avance de venta de los primeros 15 días del mes en curso, con el comentario
 * que Johanna escribió en el modal, a la supervisora + a los destinatarios de copia
 * configurados en adminNotificaciones bajo TIPO_NOTIFICACION_AVANCE_QUINCENAL (hoy solo
 * Johanna — ver premios/sql/setup_control_mail.sql). El comentario NO se persiste en
 * ningún lado, solo viaja en el cuerpo de este mail.
 *
 * Recalcula el avance server-side (no confía en lo que mandó el navegador) — mismo dato
 * que ya vio Johanna en el modal vía GET avance_quincenal.php.
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
require_once __DIR__ . '/../class/PremiosDB.php';
require_once __DIR__ . '/../class/AvanceQuincenalDB.php';
require_once __DIR__ . '/../class/MailPremios.php';

if (!isGlobalMode()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tenés permiso para esta acción']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $supervisora = trim($input['supervisora'] ?? '');
    $comentario  = trim($input['comentario'] ?? '');
    if ($supervisora === '') {
        throw new InvalidArgumentException('Falta la supervisora');
    }

    $premiosDb = new PremiosDB(date('Y-m-d'), date('Y-m-d'), date('Y-m-d'), date('Y-m-d'));
    if (!in_array($supervisora, $premiosDb->getSupervisoras(), true)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => "Supervisora no encontrada o no visible: $supervisora"]);
        exit;
    }

    $email = $premiosDb->getEmailSupervisora($supervisora);
    if (!$email) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => "No hay un mail configurado para $supervisora en RO_T_SUPERVISORAS_COMERCIAL. Pedí que lo agreguen ahí antes de reintentar."]);
        exit;
    }

    $mail = new MailPremios();
    $cfg = $mail->obtenerDestinatariosYConfig(MailPremios::TIPO_NOTIFICACION_AVANCE_QUINCENAL);
    if (!$cfg['emails']) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'No hay destinatarios de copia configurados para PREMIOS_AVANCE_QUINCENAL en el admin de notificaciones — Johanna debe recibir copia siempre, así que no se manda nada hasta configurarlo.']);
        exit;
    }

    $hoyDt = new DateTime('today');
    $desde = $hoyDt->format('Y-m-01');
    $hasta = min($hoyDt->format('Y-m-15'), (clone $hoyDt)->modify('-1 day')->format('Y-m-d'));
    if ($hasta < $desde) $hasta = $desde;

    // Se le pasa el listado COMPLETO de supervisoras activas, no solo $supervisora — ver
    // mismo comentario en api/avance_quincenal.php (benchmarks de marca para "% Cumpl. Cadena").
    $avanceDb = new AvanceQuincenalDB($desde, $hasta);
    $activas = $premiosDb->getSupervisoras();
    $filas = $avanceDb->avancePorSupervisora($activas);
    $fila = $filas[array_search($supervisora, $activas, true)];

    $html = MailPremios::renderAvanceSupervisora(
        $supervisora,
        $fila['sucursales'],
        $fila['facturacion_total'],
        $fila['objetivo_total'],
        $fila['pct_cumplimiento'],
        $desde,
        $hasta,
        $comentario !== '' ? $comentario : null,
        $fila['pct_var'],
        $fila['ticket_promedio'],
        $fila['pct_ticket_2do'],
        $fila['pct_ticket_3er'],
        $fila['pct_cumplimiento_cadena'],
        $fila['benchmarks']
    );

    $destinatarios = array_values(array_unique(array_merge([$email], $cfg['emails'])));
    $asunto = "Avance de Venta - $supervisora - " . date('d/m/Y', strtotime($hasta));
    $mail->enviar($destinatarios, $asunto, $html, $cfg['profile']);

    ob_clean();
    echo json_encode(['ok' => true, 'destinatarios' => $destinatarios]);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
