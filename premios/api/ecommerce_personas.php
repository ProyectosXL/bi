<?php
/**
 * /bi/premios/api/ecommerce_personas.php
 * Modal "Configuración" de Premios Ecommerce: alta, edición y baja de las personas del
 * área, con su mail (para api/enviar_mail_ecommerce.php) y su PUESTO (la plantilla de
 * conceptos y tramos que se le copia en el alta — ver PremiosEcommerceDB::crearPersona()).
 *
 * GET  -> {personas: [...], puestos: [...]}
 * POST {accion:'crear', nombre, email, id_puesto}
 *      {accion:'editar', id, nombre, email}
 *      {accion:'baja'|'reactivar', id}
 *
 * Solo GERENCIA/SUPERVISION (isGlobalMode) — dar de alta a alguien cambia lo que se liquida.
 */
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../../class/config.php';
require_once __DIR__ . '/../class/PremiosEcommerceDB.php';

if (!isGlobalMode()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tenés permiso para esta acción']);
    exit;
}

/** Nombre obligatorio, sin espacios de más; el largo es el de la columna NOMBRE. */
function nombreValido($v): string
{
    $nombre = trim(preg_replace('/\s+/', ' ', (string) $v));
    if ($nombre === '') throw new InvalidArgumentException('Falta el nombre');
    if (mb_strlen($nombre) > 100) throw new InvalidArgumentException('El nombre no puede superar los 100 caracteres');
    return $nombre;
}

/** Mail opcional (vacío = sin mail), pero si viene tiene que ser válido. */
function emailValido($v): ?string
{
    $email = trim((string) $v);
    if ($email === '') return null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        throw new InvalidArgumentException("El mail \"$email\" no es válido");
    }
    return $email;
}

try {
    // El período no importa acá: se instancia con el mes en curso solo porque el
    // constructor lo pide (mismo criterio que api/ecommerce_escalas.php).
    $hoy = date('Y-m-d');
    $db  = new PremiosEcommerceDB(date('Y-m-01'), $hoy, $hoy, $hoy);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input   = json_decode(file_get_contents('php://input'), true) ?? [];
        $usuario = $_SESSION['username'];
        $id      = (int) ($input['id'] ?? 0);

        switch ($input['accion'] ?? '') {
            case 'crear':
                $idPuesto = (int) ($input['id_puesto'] ?? 0);
                if ($idPuesto <= 0) throw new InvalidArgumentException('Elegí el puesto');
                $id = $db->crearPersona(nombreValido($input['nombre'] ?? ''), emailValido($input['email'] ?? ''), $idPuesto, $usuario);
                break;
            case 'editar':
                if ($id <= 0) throw new InvalidArgumentException('Falta la persona');
                $db->editarPersona($id, nombreValido($input['nombre'] ?? ''), emailValido($input['email'] ?? ''), $usuario);
                break;
            case 'baja':
            case 'reactivar':
                if ($id <= 0) throw new InvalidArgumentException('Falta la persona');
                $db->cambiarActivoPersona($id, $input['accion'] === 'reactivar', $usuario);
                break;
            default:
                throw new InvalidArgumentException('Acción desconocida');
        }

        ob_clean();
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    ob_clean();
    echo json_encode([
        'ok'       => true,
        'personas' => $db->personasConfig(),
        'puestos'  => $db->puestos(),
    ], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
