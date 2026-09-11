<?php
/**
 * /bi/premios/api/ecommerce_escalas.php
 * Escalas de premio de Ecommerce (las "PAUTAS": qué importe se paga al alcanzar cada tramo).
 *
 * GET  -> catálogo completo: personas -> conceptos -> tramos.
 * POST {conceptos:[{id, escalas:[{umbral, importe}]}]} -> reemplaza los tramos de esos
 *         conceptos (los que no vengan en el payload quedan intactos).
 *
 * Solo GERENCIA/SUPERVISION (isGlobalMode) — cambiar una escala cambia lo que se liquida.
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

try {
    // El período no importa para leer/escribir escalas (no dependen de fechas): se
    // instancia con el mes en curso solo porque el constructor lo pide.
    $hoy = date('Y-m-d');
    $db  = new PremiosEcommerceDB(date('Y-m-01'), $hoy, $hoy, $hoy);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $conceptosInput = $input['conceptos'] ?? null;
        if (!is_array($conceptosInput) || !$conceptosInput) {
            throw new InvalidArgumentException('Falta el detalle de conceptos');
        }

        $idsValidos = $db->idsConceptos();
        $tramosPorConcepto = [];

        foreach ($conceptosInput as $c) {
            $id = (int) ($c['id'] ?? 0);
            if (!in_array($id, $idsValidos, true)) {
                throw new InvalidArgumentException("Concepto desconocido o inactivo: $id");
            }
            if (isset($tramosPorConcepto[$id])) {
                throw new InvalidArgumentException("El concepto $id viene repetido");
            }

            $escalas = $c['escalas'] ?? null;
            if (!is_array($escalas) || !$escalas) {
                // Dejar un concepto sin tramos lo volvería impagable en silencio: mejor
                // que el usuario lo desactive explícitamente en la tabla si es lo que quiere.
                throw new InvalidArgumentException("El concepto $id debe tener al menos un tramo");
            }

            $tramos = [];
            $umbralesVistos = [];
            foreach ($escalas as $e) {
                $umbral  = $e['umbral']  ?? null;
                $importe = $e['importe'] ?? null;
                if (!is_numeric($umbral) || !is_numeric($importe)) {
                    throw new InvalidArgumentException("Tramo inválido en el concepto $id: umbral e importe deben ser numéricos");
                }
                $umbral  = (float) $umbral;
                $importe = (float) $importe;
                if ($umbral <= 0) {
                    throw new InvalidArgumentException("Los umbrales del concepto $id deben ser mayores a 0");
                }
                if ($importe < 0) {
                    throw new InvalidArgumentException("Los importes del concepto $id no pueden ser negativos");
                }
                // El UNIQUE (ID_CONCEPTO, UMBRAL) de la tabla también lo impide, pero acá el
                // mensaje de error es entendible en el modal.
                $clave = (string) round($umbral, 4);
                if (isset($umbralesVistos[$clave])) {
                    throw new InvalidArgumentException("El concepto $id tiene dos tramos con el mismo umbral ($clave)");
                }
                $umbralesVistos[$clave] = true;

                $tramos[] = ['umbral' => $umbral, 'importe' => $importe];
            }
            $tramosPorConcepto[$id] = $tramos;
        }

        $db->guardarEscalas($tramosPorConcepto, $_SESSION['username']);
        ob_clean();
        echo json_encode(['ok' => true]);
        exit;
    }

    ob_clean();
    echo json_encode(['ok' => true, 'personas' => $db->catalogo()], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
