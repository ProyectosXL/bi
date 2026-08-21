<?php
/**
 * /bi/premios/api/marcar_controlado.php
 * Marca/desmarca el estado "Controlado" de una supervisora para el mes del período elegido.
 * Solo tiene sentido para un período de un solo mes — ver PremiosDB::mesUnico().
 *
 * Disparo automático del resumen mensual: si esta marcación hace que TODAS las
 * supervisoras activas queden "Controlado" para ese mes (y antes de esta marcación no lo
 * estaban todas), se manda el resumen mensual a RRHH (mismo envío que el botón manual de
 * "Enviar resumen mensual", ver MailPremios::enviarResumenMensual()). Se detecta por
 * TRANSICIÓN (antes: no todas / después: todas) en vez de con una bandera de "ya enviado
 * este mes", para que si alguien reabre una supervisora y luego la vuelve a cerrar, el
 * resumen se vuelva a mandar — probablemente cambiaron números y RRHH debe verlos.
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
    $supervisora = trim($input['supervisora'] ?? '');
    $controlado  = (bool) ($input['controlado'] ?? false);
    if ($supervisora === '') {
        throw new InvalidArgumentException('Falta la supervisora');
    }

    [$da, $ha, $dp, $hp] = PeriodHelper::fromRequest($input);
    $db = new PremiosDB($da, $ha, $dp, $hp);

    $mes = $db->mesUnico();
    if (!$mes) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'El estado "Controlado" solo aplica a un período de un solo mes']);
        exit;
    }

    $todasControladasAntes = $db->todasSupervisorasControladas($mes);
    $estado = $db->marcarControlado($mes, $supervisora, $controlado, $_SESSION['username']);
    $todasControladasDespues = $db->todasSupervisorasControladas($mes);

    $resumenAutoEnviado = false;
    $resumenAutoError = null;
    if (!$todasControladasAntes && $todasControladasDespues) {
        try {
            MailPremios::enviarResumenMensual($db, $da, $ha);
            $resumenAutoEnviado = true;
        } catch (Throwable $e) {
            // No se aborta la respuesta por esto: el estado "Controlado" ya quedó grabado
            // correctamente. Se informa el error para que la UI avise y quede el botón
            // manual de "Enviar resumen mensual" como respaldo.
            $resumenAutoError = $e->getMessage();
        }
    }

    ob_clean();
    echo json_encode([
        'ok' => true,
        'supervisora' => $supervisora,
        'mes' => $mes,
        'estado' => $estado,
        'resumen_auto_enviado' => $resumenAutoEnviado,
        'resumen_auto_error' => $resumenAutoError,
    ]);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
