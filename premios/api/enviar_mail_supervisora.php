<?php
/**
 * /bi/premios/api/enviar_mail_supervisora.php
 * Envía por mail el detalle de premios (Premios Supervisoras + Locales Propios) de UNA
 * supervisora para el período elegido. Requiere que tenga un mail cargado en
 * RO_T_SUPERVISORAS_COMERCIAL.MAIL — ver PremiosDB::getEmailSupervisora().
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
    $supervisora = trim($input['supervisora'] ?? '');
    if ($supervisora === '') {
        throw new InvalidArgumentException('Falta la supervisora');
    }

    [$da, $ha, $dp, $hp] = PeriodHelper::fromRequest($input);
    $db = new PremiosDB($da, $ha, $dp, $hp);

    $email = $db->getEmailSupervisora($supervisora);
    if (!$email) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => "No hay un mail configurado para $supervisora en RO_T_SUPERVISORAS_COMERCIAL. Pedí que lo agreguen ahí antes de reintentar."]);
        exit;
    }

    // Misma construcción que usa api/resumen.php + api/propios.php, para UNA supervisora.
    $propiosTodos     = $db->datosPropios(null);
    $filasTodas       = $db->datosPropios('TODAS');
    $franquiciasTodos = $db->datosFranquicias();
    $benchmarks = [
        'var_marca'    => $db->benchmarkVarMarca($propiosTodos),
        'ticket_marca' => $db->ticketPromedioMarca($propiosTodos),
        'pct2_marca'   => $db->pctTicketProductoMarca($propiosTodos, 'tickets_2do_prod'),
        'pct3_marca'   => $db->pctTicketProductoMarca($propiosTodos, 'tickets_3er_prod'),
    ];
    $conteosFranquiciaEmpresa = $db->conteosFranquiciaEmpresa($franquiciasTodos);
    $importesFranquiciaPorSup = $db->importesFranquiciaPorSupervisora();

    $filasSup    = $db->datosPropios($supervisora);
    $propios     = $db->premiosPropiosSupervisora($supervisora, $filasSup, $propiosTodos, $filasTodas, $benchmarks);
    $franquicias = $db->premiosFranquiciasSupervisora($supervisora, $conteosFranquiciaEmpresa, $importesFranquiciaPorSup[$supervisora] ?? []);
    $totalPremios = $propios['total'] + $franquicias['total'];

    // Fila "Total" del mail = total de TODA la cadena (todas las supervisoras + Ecommerce),
    // no el propio de esta supervisora — ver PremiosDB::totalGeneralPropios().
    $benchmarksCadena = [
        'ticket_marca' => $benchmarks['ticket_marca'],
        'pct2_marca'   => $benchmarks['pct2_marca'],
        'pct3_marca'   => $benchmarks['pct3_marca'],
    ];
    $totalGeneral = $db->totalGeneralPropios($db->getSupervisoras(), $benchmarks['var_marca'], $benchmarksCadena);

    $html = MailPremios::renderDetalleSupervisora($db, $supervisora, $filasSup, $totalGeneral, $benchmarks, $totalPremios, $da, $ha);

    $asunto = "Detalle de Premios - $supervisora - " . date('d/m/Y', strtotime($ha));
    // Johanna en copia en el mail individual a cada supervisora, a pedido del cliente.
    (new MailPremios())->enviar([$email], $asunto, $html, MailPremios::PROFILE_DEFAULT, ['johanna.bolig@xl.com.ar']);

    ob_clean();
    echo json_encode(['ok' => true, 'email' => $email]);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
