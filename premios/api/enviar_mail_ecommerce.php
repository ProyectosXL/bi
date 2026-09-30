<?php
/**
 * /bi/premios/api/enviar_mail_ecommerce.php
 * Envía por mail el detalle de premios de UNA persona del área Ecommerce para el período
 * elegido — el equivalente de enviar_mail_supervisora.php para la pestaña Premios Ecommerce.
 * Requiere que tenga un mail cargado en BI_T_PREMIOS_ECOM_PERSONAS.EMAIL — ver
 * PremiosEcommerceDB::getEmailPersona().
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../../class/config.php';
require_once __DIR__ . '/../../class/PeriodHelper.php';
require_once __DIR__ . '/../class/PremiosEcommerceDB.php';
require_once __DIR__ . '/../class/MailPremios.php';

if (!isGlobalMode()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tenés permiso para esta acción']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $idPersona = (int) ($input['id_persona'] ?? 0);
    if ($idPersona <= 0) {
        throw new InvalidArgumentException('Falta la persona');
    }

    [$da, $ha, $dp, $hp] = PeriodHelper::fromRequest($input);
    $db = new PremiosEcommerceDB($da, $ha, $dp, $hp);

    // Mismo cálculo que api/ecommerce.php, así el mail coincide con lo que se ve en pantalla.
    $personas = $db->calcular()['personas'];
    $persona = null;
    foreach ($personas as $p) {
        if ($p['id'] === $idPersona) { $persona = $p; break; }
    }
    if (!$persona) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'La persona no existe o no está activa en BI_T_PREMIOS_ECOM_PERSONAS']);
        exit;
    }

    $email = $db->getEmailPersona($idPersona);
    if (!$email) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => "No hay un mail configurado para {$persona['nombre']}. Cargalo desde el botón \"Configuración\" de la pestaña y reintentá."]);
        exit;
    }

    $html = MailPremios::renderDetalleEcommerce($persona, $db->periodoParcial(), $da, $ha);

    $asunto = "Detalle de Premios Ecommerce - {$persona['nombre']} - " . date('d/m/Y', strtotime($ha));
    (new MailPremios())->enviar([$email], $asunto, $html, MailPremios::PROFILE_DEFAULT);

    ob_clean();
    echo json_encode(['ok' => true, 'email' => $email]);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
