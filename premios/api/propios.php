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

function filaVista(PremiosDB $db, array $f): array
{
    $cumpl = $f['sin_datos'] ? -1.0 : $db->cumplimientoObjVenta($f['imp_fact'], $f['imp_obj']);
    $var   = $f['sin_datos'] ? null  : $db->facturacionVarPct($f['imp_fact'], $f['imp_fact_ant']);
    return [
        'nro_sucurs'        => $f['nro_sucurs'],
        'sucursal'          => $f['sucursal'],
        'casa_central'      => $f['casa_central'],
        'sin_datos'         => $f['sin_datos'],
        'facturacion_s_iva' => $f['imp_fact_s_iva'],
        'facturacion_c_iva' => $f['imp_fact'],
        'objetivo_total'    => $f['imp_obj'],
        'cumplimiento_obj'  => $cumpl,
        'facturacion_var'   => $var,
        'ticket_promedio'   => $f['sin_datos'] ? 0.0 : $db->ticketPromedioEst($f['imp_fact'], $f['tickets']),
        'pct_ticket_2do'    => ($f['sin_datos'] || $f['tickets'] <= 0) ? 0.0 : $f['tickets_2do_prod'] / $f['tickets'],
        'pct_ticket_3er'    => ($f['sin_datos'] || $f['tickets'] <= 0) ? 0.0 : $f['tickets_3er_prod'] / $f['tickets'],
    ];
}

try {
    [$da, $ha, $dp, $hp] = PeriodHelper::fromRequest($_GET);
    $supervisoraFiltro = ($_GET['supervisora'] ?? '') !== '' ? $_GET['supervisora'] : null;

    $db = new PremiosDB($da, $ha, $dp, $hp);

    $todos = $db->datosPropios(null);

    $kpis = [
        // El KPI "Facturación Var % Marca" muestra el BENCHMARK (agregado +10pp), no el
        // agregado crudo — confirmado contra el KPI real (302,07 % = 292,07 % + 10pp).
        'facturacion_var_marca' => $db->benchmarkVarMarca($todos),
        'ticket_promedio_marca' => $db->ticketPromedioMarca($todos),
        'pct_ticket_2do_marca'  => $db->pctTicketProductoMarca($todos, 'tickets_2do_prod'),
        'pct_ticket_3er_marca'  => $db->pctTicketProductoMarca($todos, 'tickets_3er_prod'),
    ];

    // Agrupar por supervisora, en el mismo orden que getSupervisoras(). Se pide cada grupo
    // con datosPropios($sup) en vez de filtrar $todos: NRO_SUCURS=1 "CENTRAL" tiene una fila
    // real POR CADA supervisora que la recibe (Elina, Natalia), y $todos (sin filtro) las
    // agrupa por NRO_SUCURS perdiendo la de alguna — datosPropios($sup) filtra antes del dedup.
    // Totales acumulados a partir de las mismas filas que se muestran agrupadas por
    // supervisora (NO de datosPropios(null), que ahora incluye la fila sintética "TODAS"/
    // ECOMMERCE — necesaria para los benchmarks de arriba, pero que no es una sucursal de
    // ninguna supervisora y no debe sumarse a la fila "Total" de esta tabla).
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

        $sucursales = array_map(fn($f) => filaVista($db, $f), $filasSup);
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
                'objetivo_total'          => $sumObj,
                'cumplimiento_obj'        => $db->cumplimientoObjVenta($sumFactCIva, $sumObj),
                'facturacion_var'         => $db->facturacionVarPct($sumFactCIva, $sumFactAnt),
                'ticket_promedio'         => $db->ticketPromedioEst($sumFactCIva, $sumTickets),
                'pct_ticket_2do'          => $sumTickets > 0 ? $sumT2 / $sumTickets : 0.0,
                'pct_ticket_3er'          => $sumTickets > 0 ? $sumT3 / $sumTickets : 0.0,
                'pct_cumplimiento_cadena' => $db->pctCumplimientoCadenaVenta($filasSup),
            ],
        ];
    }

    // La fila "ECOMMERCE" (SUPERVISORA='TODAS' en la BD) no pertenece a ninguna supervisora
    // real. Se muestra como su propio grupo: una fila "Todas" (resaltada igual que una
    // supervisora, ya que agrupa a "todas" — en este caso, un solo elemento) con "Ecommerce"
    // como su única fila hija, igual que una sucursal bajo su supervisora. Como el grupo
    // tiene un solo miembro, el subtotal "Todas" es numéricamente igual a la fila "Ecommerce".
    // Igual que los demás grupos, SÍ suma al total general de la tabla.
    $todasRow = null;
    if (!$supervisoraFiltro) {
        $filasTodas = $db->datosPropios('TODAS');
        if ($filasTodas) {
            $todasRow = filaVista($db, $filasTodas[0]);
            $totFactSIva += $filasTodas[0]['imp_fact_s_iva'];
            $totFactCIva += $filasTodas[0]['imp_fact'];
            $totObj      += $filasTodas[0]['imp_obj'];
            $totFactAnt  += $filasTodas[0]['imp_fact_ant'];
            $totTickets  += $filasTodas[0]['tickets'];
            $filasParaTotal[] = $filasTodas[0];
        }
    }

    $total = [
        'facturacion_s_iva'       => $totFactSIva,
        'facturacion_c_iva'       => $totFactCIva,
        'objetivo_total'          => $totObj,
        'cumplimiento_obj'        => $db->cumplimientoObjVenta($totFactCIva, $totObj),
        'facturacion_var'         => $db->facturacionVarPct($totFactCIva, $totFactAnt),
        'ticket_promedio'         => $db->ticketPromedioEst($totFactCIva, $totTickets),
        'pct_ticket_2do'          => $db->pctTicketProductoMarca($filasParaTotal, 'tickets_2do_prod'),
        'pct_ticket_3er'          => $db->pctTicketProductoMarca($filasParaTotal, 'tickets_3er_prod'),
        'pct_cumplimiento_cadena' => $db->pctCumplimientoCadenaVenta($filasParaTotal),
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
        'todas'   => $todasRow,
        'total'   => $total,
        'ultima_actualizacion' => $ultimaActFormatted,
        'is_outdated' => $isOutdated,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
