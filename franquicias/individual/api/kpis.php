<?php
/**
 * /api/kpis.php
 * Devuelve KPIs del período actual vs período previo + benchmark global.
 *
 * GET params:
 *   periodo   = ayer|7|30|90|180|mes_actual|mes_pasado|año_actual|año_pasado|custom
 *   desde     = Y-m-d  (solo si periodo=custom)
 *   hasta     = Y-m-d  (solo si periodo=custom)
 *   vendedor  = % | COD_VENDED
 *   rubro     = % | RUBRO
 */

session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/DashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');
    $nroSucurs = isset($_SESSION['numsuc']) ? (int)$_SESSION['numsuc'] : 7;
    $periodo  = $_GET['periodo']  ?? 'mes_actual';
    $vendedor = $_GET['vendedor'] ?? '%';
    $rubro    = $_GET['rubro']    ?? '%';

    if ($periodo === 'custom') {
        $da        = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha        = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        $comp_mode = $_GET['comp_mode']  ?? 'year_ago';

        if ($comp_mode === 'custom') {
            $da_p = $_GET['desde_comp'] ?? (new DateTime($da))->modify('-1 year')->format('Y-m-d');
            $ha_p = $_GET['hasta_comp'] ?? (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        } else {
            // Mismo período, año anterior
            $da_p = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
            $ha_p = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        }
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = [$da, $ha, $da_p, $ha_p];
    } else {
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = DashboardDB::calcularPeriodo($periodo);
    }

    $db = new DashboardDB();

    // ── Período actual ──────────────────────────────
    $kpi_act  = $db->getKPIs($desde_act, $hasta_act, $nroSucurs, $vendedor, $rubro);
    $tick_act = $db->getTicketsProductos($desde_act, $hasta_act, $nroSucurs, $vendedor);
    $incr_act = $db->getIncremental($desde_act, $hasta_act, $nroSucurs, $vendedor);
    
    // Objetivo total del mes (si es mes actual) — sin filtros de vendedor/rubro
    $obj_total = $kpi_act['objetivo'];
    if ($periodo === 'mes_actual') {
        $primerDia = date('Y-m-01');
        $ultimoDia = date('Y-m-t');
        $kpi_total = $db->getKPIs($primerDia, $ultimoDia, $nroSucurs);
        $obj_total = $kpi_total['objetivo'];
    }

    // ── Período previo ──────────────────────────────
    $kpi_prev  = $db->getKPIs($desde_prev, $hasta_prev, $nroSucurs, $vendedor, $rubro);
    $tick_prev = $db->getTicketsProductos($desde_prev, $hasta_prev, $nroSucurs, $vendedor);
    $incr_prev = $db->getIncremental($desde_prev, $hasta_prev, $nroSucurs, $vendedor);

    // ── Benchmark (sin filtro de sucursal ni vendedor/rubro) ──────────
    $bench_kpi  = $db->getKPIs($desde_act, $hasta_act);
    $bench_tick = $db->getTicketsProductos($desde_act, $hasta_act);
    $bench_incr = $db->getIncremental($desde_act, $hasta_act);

    // ── Conversión (ingresos físicos vs tickets) ─────────────────────
    $conv_act  = $db->getConversion($desde_act, $hasta_act, $nroSucurs);
    $conv_prev = $db->getConversion($desde_prev, $hasta_prev, $nroSucurs);

    // ── Mini-chart serie ────────────────────────────
    $serie_act  = $db->getSerieFacturacion($desde_act, $hasta_act, $nroSucurs, $vendedor, $rubro);
    $serie_prev = $db->getSerieFacturacion($desde_prev, $hasta_prev, $nroSucurs, $vendedor, $rubro);

    // ── Helpers ─────────────────────────────────────
    $var = fn($act, $prev) => $prev != 0 ? ($act - $prev) / $prev : ($act > 0 ? 1 : 0);
    $dias_act  = (new DateTime($desde_act))->diff(new DateTime($hasta_act))->days + 1;
    $dias_prev = (new DateTime($desde_prev))->diff(new DateTime($hasta_prev))->days + 1;

    ob_clean();
    echo json_encode([
        'ok'      => true,
        'periodo' => [
            'tipo'        => $periodo,
            'desde_act'   => $desde_act,
            'hasta_act'   => $hasta_act,
            'desde_prev'  => $desde_prev,
            'hasta_prev'  => $hasta_prev,
            'dias_act'    => $dias_act,
            'dias_prev'   => $dias_prev,
        ],
        'actual' => [
            'facturacion'      => $kpi_act['facturacion'],
            'unidades'         => $kpi_act['unidades'],
            'tickets'          => $kpi_act['tickets'],
            'ticket_promedio'  => $kpi_act['ticket_promedio'],
            'porc_cambios'     => $kpi_act['porc_cambios'],
            'objetivo'         => $kpi_act['objetivo'],
            'objetivo_total'   => $obj_total,
            'porc_2do'         => $tick_act['porc_2do'],
            'porc_3ro'         => $tick_act['porc_3ro'],
            'porc_incremental' => $incr_act['porc_incremental'],
            'ingresos'         => $conv_act['ingresos'],
            'conversion'       => $conv_act['conversion'],
        ],
        'previo' => [
            'facturacion'      => $kpi_prev['facturacion'],
            'unidades'         => $kpi_prev['unidades'],
            'tickets'          => $kpi_prev['tickets'],
            'ticket_promedio'  => $kpi_prev['ticket_promedio'],
            'porc_cambios'     => $kpi_prev['porc_cambios'],
            'objetivo'         => $kpi_prev['objetivo'],
            'porc_2do'         => $tick_prev['porc_2do'],
            'porc_3ro'         => $tick_prev['porc_3ro'],
            'porc_incremental' => $incr_prev['porc_incremental'],
            'ingresos'         => $conv_prev['ingresos'],
            'conversion'       => $conv_prev['conversion'],
        ],
        'variacion' => [
            'facturacion'      => $var($kpi_act['facturacion'],     $kpi_prev['facturacion']),
            'unidades'         => $var($kpi_act['unidades'],        $kpi_prev['unidades']),
            'tickets'          => $var($kpi_act['tickets'],         $kpi_prev['tickets']),
            'ticket_promedio'  => $var($kpi_act['ticket_promedio'], $kpi_prev['ticket_promedio']),
            'objetivo'         => $kpi_act['objetivo'] != 0 ? ($kpi_act['facturacion'] - $kpi_act['objetivo']) / $kpi_act['objetivo'] : 0,
            'porc_2do'         => $tick_act['porc_2do'] - $tick_prev['porc_2do'],
            'porc_3ro'         => $tick_act['porc_3ro'] - $tick_prev['porc_3ro'],
            'porc_cambios'     => $kpi_act['porc_cambios'] - $kpi_prev['porc_cambios'],
            'porc_incremental' => $incr_act['porc_incremental'] - $incr_prev['porc_incremental'],
            'conversion'       => $var($conv_act['conversion'], $conv_prev['conversion']),
        ],
        'benchmark' => [
            'tickets_var'      => $var($bench_kpi['tickets'],         $kpi_prev['tickets']),
            'ticket_promedio'  => $bench_kpi['ticket_promedio'],
            'porc_2do'         => $bench_tick['porc_2do'],
            'porc_3ro'         => $bench_tick['porc_3ro'],
            'porc_cambios'     => $bench_kpi['porc_cambios'],
            'porc_incremental' => $bench_incr['porc_incremental'],
        ],
        'serie' => [
            'actual'  => $serie_act,
            'previo'  => $serie_prev,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
