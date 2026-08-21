<?php
/**
 * /bi/premios/api/resumen.php
 * Vista "Premios Supervisoras": total de premios por supervisora
 * (Locales Propios + Franquicias) para las cards y las 2 tablas de detalle.
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/../../class/config.php';
require_once __DIR__ . '/../../class/PeriodHelper.php';
require_once __DIR__ . '/../class/PremiosDB.php';

try {
    [$da, $ha, $dp, $hp] = PeriodHelper::fromRequest($_GET);
    $supervisoraFiltro = ($_GET['supervisora'] ?? '') !== '' ? $_GET['supervisora'] : null;

    $db = new PremiosDB($da, $ha, $dp, $hp);

    $supervisoras = $db->getSupervisoras();
    if ($supervisoraFiltro) {
        $supervisoras = array_values(array_filter($supervisoras, fn($s) => $s === $supervisoraFiltro));
    }

    $resumen = $db->resumenPorSupervisora($supervisoras);
    $out = $resumen['filas'];
    $pctCumplimientoCadenaTotal = $resumen['pct_cumplimiento_cadena_total'];

    // Estado "Controlado": solo tiene sentido para un período de un solo mes (ver
    // PremiosDB::mesUnico()) — en año/rango multi-mes queda null y la UI no debe ofrecer el botón.
    $mesUnico = $db->mesUnico();
    if ($mesUnico) {
        $controlEstado = $db->getControladoBulk($mesUnico, $supervisoras);
        foreach ($out as &$fila) {
            $fila['controlado'] = $controlEstado[$fila['supervisora']] ?? ['controlado' => false, 'usuario' => null, 'fecha_control' => null];
        }
        unset($fila);
    } else {
        foreach ($out as &$fila) {
            $fila['controlado'] = null;
        }
        unset($fila);
    }

    $ultimaActFormatted = null;
    $isOutdated = false;
    $ultimaActRaw = $db->getUltimaActualizacion();
    if ($ultimaActRaw) {
        $dtUpdate = new DateTime($ultimaActRaw);
        $ultimaActFormatted = $dtUpdate->format('d/m/Y H:i:s');
        $isOutdated = PremiosDB::esDesactualizado($dtUpdate);
    }

    ob_clean();
    echo json_encode([
        'ok'      => true,
        'periodo' => ['desde' => $da, 'hasta' => $ha, 'desde_prev' => $dp, 'hasta_prev' => $hp],
        'supervisoras' => $out,
        'pct_cumplimiento_cadena_total' => $pctCumplimientoCadenaTotal,
        'ultima_actualizacion' => $ultimaActFormatted,
        'is_outdated' => $isOutdated,
        'puede_gestionar' => isGlobalMode(),
        'mes_unico' => $mesUnico,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
