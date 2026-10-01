<?php
/**
 * /bi/premios/api/propios.php
 * Vista "Locales Propios": KPIs de marca + tabla de facturación vs objetivos
 * por sucursal, agrupada por supervisora, con subtotales y total general.
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

function filaVista(PremiosDB $db, array $f, float $benchmarkVarMarca): array
{
    $cumpl = $f['sin_datos'] ? -1.0 : $db->cumplimientoObjVenta($f['imp_fact'], $f['imp_obj']);
    $var   = $f['sin_datos'] ? null  : $db->facturacionVarPct($f['imp_fact'], $f['imp_fact_ant']);
    return [
        'nro_sucurs'           => $f['nro_sucurs'],
        'sucursal'             => $f['sucursal'],
        'casa_central'         => $f['casa_central'],
        'sin_datos'            => $f['sin_datos'],
        'facturacion_s_iva'    => $f['imp_fact_s_iva'],
        'facturacion_c_iva'    => $f['imp_fact'],
        'facturacion_ant'      => $f['imp_fact_ant'],
        'objetivo_total'       => $f['imp_obj'],
        'objetivo_crecimiento' => $f['imp_fact_ant'] * (1 + $benchmarkVarMarca),
        'cumplimiento_obj'     => $cumpl,
        'facturacion_var'      => $var,
        'ticket_promedio'      => $f['sin_datos'] ? 0.0 : $db->ticketPromedioEst($f['imp_fact'], $f['tickets']),
        'pct_ticket_2do'       => ($f['sin_datos'] || $f['tickets'] <= 0) ? 0.0 : $f['tickets_2do_prod'] / $f['tickets'],
        'pct_ticket_3er'       => ($f['sin_datos'] || $f['tickets'] <= 0) ? 0.0 : $f['tickets_3er_prod'] / $f['tickets'],
    ];
}

try {
    [$da, $ha, $dp, $hp] = PeriodHelper::fromRequest($_GET);
    $supervisoraFiltro = ($_GET['supervisora'] ?? '') !== '' ? $_GET['supervisora'] : null;

    $db = new PremiosDB($da, $ha, $dp, $hp);

    $todos = $db->datosPropios(null);

    $kpis = [
        // Variación de facturación "cruda" (sin el +10pp) de los locales, sin Ecommerce (ver
        // PremiosDB::facturacionVarMarca). El KPI de al lado, "Facturación Var % Marca", es
        // exactamente este valor + BENCHMARK_PLUS.
        'facturacion_var_promedio' => $db->facturacionVarMarca($todos),
        // El KPI "Facturación Var % Marca" muestra el BENCHMARK (agregado +10pp), no el
        // agregado crudo.
        'facturacion_var_marca' => $db->benchmarkVarMarca($todos),
        'ticket_promedio_marca' => $db->ticketPromedioMarca($todos),
        'pct_ticket_2do_marca'  => $db->pctTicketProductoMarca($todos, 'tickets_2do_prod'),
        'pct_ticket_3er_marca'  => $db->pctTicketProductoMarca($todos, 'tickets_3er_prod'),
    ];
    // Mismos 3 benchmarks que $kpis, con las claves que espera pctCumplimientoCoach()
    // (mismo shape que el array $benchmarks de PremiosDB::resumenPorSupervisora()).
    $benchmarksCadena = [
        'ticket_marca' => $kpis['ticket_promedio_marca'],
        'pct2_marca'   => $kpis['pct_ticket_2do_marca'],
        'pct3_marca'   => $kpis['pct_ticket_3er_marca'],
    ];

    // Agrupar por supervisora, en el mismo orden que getSupervisoras(). Se pide cada grupo
    // con datosPropios($sup) en vez de filtrar $todos: NRO_SUCURS=1 "CENTRAL" tiene una fila
    // real POR CADA supervisora que la recibe (Elina, Natalia), y $todos (sin filtro) las
    // agrupa por NRO_SUCURS perdiendo la de alguna — datosPropios($sup) filtra antes del dedup.
    // Totales acumulados a partir de las mismas filas que se muestran agrupadas por
    // supervisora (NO de datosPropios(null), que incluye la fila sintética "TODAS"/
    // ECOMMERCE — necesaria para los benchmarks de arriba, pero que no se muestra en esta
    // tabla y no debe sumarse a su fila "Total").
    $totFactSIva = 0.0; $totFactCIva = 0.0; $totObj = 0.0; $totFactAnt = 0.0; $totTickets = 0;
    // Filas crudas acumuladas para el % tickets 2do/3er producto del Total — se recalcula con
    // pctTicketProductoMarca() (misma exclusión de CENTRAL/ECOMMERCE que el benchmark de marca,
    // ver PremiosDB::pctTicketProductoMarca) en vez de sumar tickets_2do_prod/tickets_3er_prod
    // a mano, para no reintroducir el mismo arrastre hacia abajo en la fila Total.
    $filasParaTotal = [];

    $sups = $supervisoraFiltro ? [$supervisoraFiltro] : $db->getSupervisoras();
    $grupos = [];
    foreach ($sups as $sup) {
        $filasSup = $db->datosPropios($sup);
        if (!$filasSup) continue;

        $sucursales = array_map(fn($f) => filaVista($db, $f, $kpis['facturacion_var_marca']), $filasSup);
        $filasParaTotal = array_merge($filasParaTotal, $filasSup);

        $sumFactSIva = array_sum(array_column($filasSup, 'imp_fact_s_iva'));
        $sumFactCIva = array_sum(array_column($filasSup, 'imp_fact'));
        $sumObj      = array_sum(array_column($filasSup, 'imp_obj'));
        $sumFactAnt  = array_sum(array_column($filasSup, 'imp_fact_ant'));
        $sumTickets  = array_sum(array_column($filasSup, 'tickets'));
        $sumT2       = array_sum(array_column($filasSup, 'tickets_2do_prod'));
        $sumT3       = array_sum(array_column($filasSup, 'tickets_3er_prod'));

        $totFactSIva += $sumFactSIva; $totFactCIva += $sumFactCIva; $totObj += $sumObj;
        $totFactAnt  += $sumFactAnt;  $totTickets  += $sumTickets;

        $grupos[] = [
            'supervisora' => $sup,
            'sucursales'  => $sucursales,
            'subtotal'    => [
                'facturacion_s_iva'       => $sumFactSIva,
                'facturacion_c_iva'       => $sumFactCIva,
                'facturacion_ant'         => $sumFactAnt,
                'objetivo_total'          => $sumObj,
                'objetivo_crecimiento'    => $sumFactAnt * (1 + $kpis['facturacion_var_marca']),
                'cumplimiento_obj'        => $db->cumplimientoObjVenta($sumFactCIva, $sumObj),
                'facturacion_var'         => $db->facturacionVarPct($sumFactCIva, $sumFactAnt),
                'ticket_promedio'         => $db->ticketPromedioEst($sumFactCIva, $sumTickets),
                'pct_ticket_2do'          => $sumTickets > 0 ? $sumT2 / $sumTickets : 0.0,
                'pct_ticket_3er'          => $sumTickets > 0 ? $sumT3 / $sumTickets : 0.0,
                'pct_cumplimiento_cadena' => $db->pctCumplimientoCoach($filasSup, $benchmarksCadena),
            ],
        ];
    }

    // La fila "ECOMMERCE" (SUPERVISORA='TODAS' en la BD) ya no se muestra en esta tabla ni
    // suma a su Total, a pedido del cliente (2026-10-01). Sigue entrando en los KPIs de marca
    // de arriba ($todos), que son los benchmarks contra los que se mide cada sucursal.

    $total = [
        'facturacion_s_iva'       => $totFactSIva,
        'facturacion_c_iva'       => $totFactCIva,
        'facturacion_ant'         => $totFactAnt,
        'objetivo_total'          => $totObj,
        'objetivo_crecimiento'    => $totFactAnt * (1 + $kpis['facturacion_var_marca']),
        'cumplimiento_obj'        => $db->cumplimientoObjVenta($totFactCIva, $totObj),
        'facturacion_var'         => $db->facturacionVarPct($totFactCIva, $totFactAnt),
        'ticket_promedio'         => $db->ticketPromedioEst($totFactCIva, $totTickets),
        'pct_ticket_2do'          => $db->pctTicketProductoMarca($filasParaTotal, 'tickets_2do_prod'),
        'pct_ticket_3er'          => $db->pctTicketProductoMarca($filasParaTotal, 'tickets_3er_prod'),
        'pct_cumplimiento_cadena' => $db->pctCumplimientoCoach($filasParaTotal, $benchmarksCadena),
    ];

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
        'kpis'    => $kpis,
        'grupos'  => $grupos,
        'total'   => $total,
        'ultima_actualizacion' => $ultimaActFormatted,
        'is_outdated' => $isOutdated,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
