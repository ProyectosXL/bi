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

require_once __DIR__ . '/../../class/PeriodHelper.php';
require_once __DIR__ . '/../class/PremiosDB.php';

try {
    [$da, $ha, $dp, $hp] = PeriodHelper::fromRequest($_GET);
    $supervisoraFiltro = ($_GET['supervisora'] ?? '') !== '' ? $_GET['supervisora'] : null;

    $db = new PremiosDB($da, $ha, $dp, $hp);

    // Benchmarks de marca: siempre sobre el universo completo, sin filtro de supervisora.
    // datosPropios(null) excluye la fila sintética "TODAS"; se pide aparte para el caso
    // especial de Carolina Commendatore (ver PremiosDB::premiosVentaCrecimiento()).
    $propiosTodos     = $db->datosPropios(null);
    $filasTodas       = $db->datosPropios('TODAS');
    $franquiciasTodos = $db->datosFranquicias();

    $benchmarks = [
        'var_marca'    => $db->benchmarkVarMarca($propiosTodos),
        'ticket_marca' => $db->ticketPromedioMarca($propiosTodos),
        'pct2_marca'   => $db->pctTicketProductoMarca($propiosTodos, 'tickets_2do_prod'),
        'pct3_marca'   => $db->pctTicketProductoMarca($propiosTodos, 'tickets_3er_prod'),
    ];

    // Franquicias: la "cantidad" que cumple objetivo/crecimiento es a nivel empresa
    // (no existe relación sucursal-franquicia → supervisora, ver premios/README.md).
    // Solo el importe del premio varía por supervisora.
    $conteosFranquiciaEmpresa = $db->conteosFranquiciaEmpresa($franquiciasTodos);
    $importesFranquiciaPorSup = $db->importesFranquiciaPorSupervisora();

    $supervisoras = $db->getSupervisoras();
    if ($supervisoraFiltro) {
        $supervisoras = array_values(array_filter($supervisoras, fn($s) => $s === $supervisoraFiltro));
    }

    $out = [];
    $filasPropiasTodasLasSup = [];
    foreach ($supervisoras as $sup) {
        // OJO: no filtrar $propiosTodos por supervisora acá — NRO_SUCURS=1 "CENTRAL"
        // tiene una fila real POR CADA supervisora que la recibe (Elina, Natalia), y al
        // traerlas sin filtro quedan agrupadas por NRO_SUCURS (se pierde la de alguna).
        // datosPropios($sup) filtra por supervisora ANTES del dedup y las trae bien.
        $filasPropias = $db->datosPropios($sup);
        $filasPropiasTodasLasSup = array_merge($filasPropiasTodasLasSup, $filasPropias);

        $propios     = $db->premiosPropiosSupervisora($sup, $filasPropias, $propiosTodos, $filasTodas, $benchmarks);
        $franquicias = $db->premiosFranquiciasSupervisora($conteosFranquiciaEmpresa, $importesFranquiciaPorSup[$sup] ?? []);

        $out[] = [
            'supervisora'             => $sup,
            'total_premios'           => $propios['total'] + $franquicias['total'],
            'propios'                 => $propios,
            'franquicias'             => $franquicias,
            'pct_cumplimiento_cadena' => $db->pctCumplimientoCadenaVenta($filasPropias),
        ];
    }
    $pctCumplimientoCadenaTotal = $db->pctCumplimientoCadenaVenta($filasPropiasTodasLasSup);

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
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
