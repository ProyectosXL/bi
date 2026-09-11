<?php
/**
 * /bi/premios/api/ecommerce_kpis.php
 * Carga manual de los dos KPIs de ecommerce que no existen en ningún sistema propio:
 * la tasa de conversión (se toma del panel de VTEX) y el objetivo de órdenes del mes.
 * Las sesiones son opcionales, para ponderar la tasa en rangos de varios meses.
 *
 * Las ÓRDENES REALES no se cargan: salen de Tango (ver PremiosEcommerceDB::ordenesReales).
 * El GET igual las devuelve por mes/canal, de solo lectura, para que quien carga la tasa
 * tenga a la vista cuántas órdenes contó Tango ese mes.
 *
 * GET  ?mes=YYYY-MM-DD -> lo guardado para ese mes, por canal, + los meses elegibles.
 * POST {mes, canales[]} -> guarda (MERGE por mes+canal).
 *
 * Solo GERENCIA/SUPERVISION (isGlobalMode), igual que el resto de las acciones de gestión
 * del módulo.
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
require_once __DIR__ . '/../../class/PeriodHelper.php';
require_once __DIR__ . '/../class/PremiosEcommerceDB.php';

if (!isGlobalMode()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tenés permiso para esta acción']);
    exit;
}

/** Fin de mes válido (Y-m-d que es efectivamente el último día de su mes). */
function esFinDeMes(string $mes): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mes)) return false;
    $ts = strtotime($mes);
    return $ts !== false && date('Y-m-t', $ts) === $mes;
}

/**
 * Meses elegibles en el <select> del modal: los últimos 12 cerrados, de más nuevo a más
 * viejo. Se ofrece el mes en curso también (se puede ir cargando el avance), pero el
 * objetivo de facturación con el que se compara es del mes completo — ver el banner de
 * "período parcial" del dashboard.
 */
function mesesElegibles(int $cantidad = 12): array
{
    $meses = [];
    $cursor = new DateTime(date('Y-m-01'));
    for ($i = 0; $i < $cantidad; $i++) {
        $meses[] = $cursor->format('Y-m-t');
        $cursor->modify('first day of last month');
    }
    return $meses;
}

/** '' / null -> null; si no, float validado. */
function numeroOpcional($v, string $campo, ?float $max = null): ?float
{
    if ($v === null || $v === '' ) return null;
    if (!is_numeric($v)) {
        throw new InvalidArgumentException("El campo \"$campo\" no es un número válido");
    }
    $n = (float) $v;
    if ($n < 0) {
        throw new InvalidArgumentException("El campo \"$campo\" no puede ser negativo");
    }
    if ($max !== null && $n > $max) {
        throw new InvalidArgumentException("El campo \"$campo\" no puede ser mayor a $max");
    }
    return $n;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $mes = (string) ($input['mes'] ?? '');
        if (!esFinDeMes($mes)) {
            throw new InvalidArgumentException('El mes debe ser el último día del mes (YYYY-MM-DD)');
        }
        $canalesInput = $input['canales'] ?? null;
        if (!is_array($canalesInput) || !$canalesInput) {
            throw new InvalidArgumentException('Falta el detalle por canal');
        }

        $porCanal = [];
        $vistos = [];
        foreach ($canalesInput as $c) {
            $canal = strtoupper(trim((string) ($c['canal'] ?? '')));
            if (!in_array($canal, PremiosEcommerceDB::CANALES, true)) {
                throw new InvalidArgumentException("Canal desconocido: \"$canal\"");
            }
            if (isset($vistos[$canal])) {
                throw new InvalidArgumentException("El canal $canal viene repetido");
            }
            $vistos[$canal] = true;

            $sesiones = numeroOpcional($c['sesiones'] ?? null, "Sesiones ($canal)");
            // La tasa está en PUNTOS DE PORCENTAJE (0,83 = 0,83 %) — el tope de 100 es lo
            // que atrapa el error clásico de cargar "83" queriendo decir 0,83 %.
            $tasa     = numeroOpcional($c['tasa_conversion'] ?? null, "Tasa de conversión ($canal)", 100);
            $objOrd   = numeroOpcional($c['objetivo_ordenes'] ?? null, "Objetivo de órdenes ($canal)");

            $porCanal[] = [
                'canal'            => $canal,
                'sesiones'         => $sesiones === null ? null : (int) round($sesiones),
                'tasa_conversion'  => $tasa,
                'objetivo_ordenes' => $objOrd,
            ];
        }

        // Instancia acotada al mes que se está guardando (el constructor solo la usa para
        // resolver el período; el guardado es por mes+canal explícito).
        $db = new PremiosEcommerceDB(date('Y-m-01', strtotime($mes)), $mes, $mes, $mes);
        $db->guardarKpisMes($mes, $porCanal, $_SESSION['username']);

        ob_clean();
        echo json_encode(['ok' => true]);
        exit;
    }

    $elegibles = mesesElegibles();
    $mes = (string) ($_GET['mes'] ?? '');
    if (!esFinDeMes($mes)) {
        // Default: el ÚLTIMO MES DEL PERÍODO QUE SE ESTÁ VIENDO, no el mes en curso.
        // Si no, alguien parado en "Mes pasado" abre el modal, carga sin mirar el selector
        // y el dato termina guardado en el mes actual — la pestaña no cambia y parece que
        // no se guardó nada. El modal manda los filtros del toolbar justamente para esto.
        [, $ha] = PeriodHelper::fromRequest($_GET);
        $mes = date('Y-m-t', strtotime($ha));
        if (!in_array($mes, $elegibles, true)) {
            $mes = $elegibles[0];
        }
    }

    $db = new PremiosEcommerceDB(date('Y-m-01', strtotime($mes)), $mes, $mes, $mes);
    $guardado = [];
    foreach ($db->kpisGuardados() as $f) {
        $guardado[strtoupper($f['canal'])] = $f;
    }
    // Solo de lectura: cuántas órdenes contó Tango ese mes. Se muestra al lado de los
    // campos editables para que quien carga la tasa tenga la referencia a la vista.
    $ordenesTango = $db->ordenesReales();

    // Una entrada por canal, siempre — así el modal puede pintar el form completo aunque
    // todavía no haya nada cargado para ese mes.
    $canales = array_map(function (string $canal) use ($guardado, $ordenesTango) {
        $f = $guardado[$canal] ?? null;
        return [
            'canal'               => $canal,
            'ordenes_tango'       => $ordenesTango[$canal] ?? 0,
            'sesiones'            => $f['sesiones'] ?? null,
            'tasa_conversion'     => $f['tasa_conversion'] ?? null,
            'objetivo_ordenes'    => $f['objetivo_ordenes'] ?? null,
            'actualizado_por'     => $f['actualizado_por'] ?? null,
            'fecha_actualizacion' => $f['fecha_actualizacion'] ?? null,
        ];
    }, PremiosEcommerceDB::CANALES);

    ob_clean();
    echo json_encode([
        'ok'                => true,
        'mes'               => $mes,
        'meses_disponibles' => $elegibles,
        'canales'           => $canales,
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
