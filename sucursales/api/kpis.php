<?php
/**
 * /api/kpis.php
 * Devuelve KPIs del período actual vs período previo + benchmark global.
 *
 * GET params:
 *   periodo   = ayer|7|30|90|180|mes_actual|mes_pasado|año_actual|año_pasado|custom
 *   desde     = Y-m-d  (solo si periodo=custom)
 *   hasta     = Y-m-d  (solo si periodo=custom)
 *   vendedor  = % | DESC_VENDEDOR
 *   rubro     = % | RUBRO
 */

session_start();
ob_start();
set_time_limit(300);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/DashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');
    if (!isset($_SESSION['numsuc'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Sesión inválida. Volvé a iniciar sesión.']);
        exit;
    }
    $nroSucurs = (int)$_SESSION['numsuc'];
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

    if (($_GET['action'] ?? '') === 'conversion_horas') {
        $db = new DashboardDB();
        $horas = $db->getConversionPorHora($desde_act, $hasta_act, $nroSucurs);
        ob_clean();
        echo json_encode(['ok' => true, 'horas' => $horas], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    $db = new DashboardDB();

    // ── KPIs actual + previo + benchmark en 5 queries (vs 9+ antes) ──
    $bulk      = $db->getKPIsBulk($desde_act, $hasta_act, $desde_prev, $hasta_prev, $nroSucurs, $vendedor, $rubro);
    $kpi_act   = $bulk['actual'];
    $kpi_prev  = $bulk['previo'];
    $bench     = $bulk['benchmark'];

    // Objetivo total del mes (si es mes actual) — mes completo sin corte a ayer
    $obj_total = $kpi_act['objetivo'];
    if ($periodo === 'mes_actual') {
        $obj_total = $db->getObjetivo(date('Y-m-01'), date('Y-m-t'), $nroSucurs);
    }

    // ── Última fecha con datos ───────────────────────────────────────
    $ultima_fecha = $db->getUltimaFecha($nroSucurs);

    // ── Conversión (ingresos físicos vs tickets) — 3 queries vs 4 ────
    $conv      = $db->getConversionBoth($desde_act, $hasta_act, $desde_prev, $hasta_prev, $nroSucurs);
    $conv_act  = $conv['actual'];
    $conv_prev = $conv['previo'];

    // ── Mini-chart serie ────────────────────────────
    $serie_act  = $db->getSerieFacturacion($desde_act, $hasta_act, $nroSucurs, $vendedor, $rubro);

    // ── Serie cumplimiento objetivo diario acumulado ──
    $obj_diario  = $db->getSerieObjetivo($desde_act, $hasta_act, $nroSucurs);
    $fact_map    = array_column($serie_act, 'facturacion', 'fecha');
    $cum_fact    = 0.0;
    $cum_obj     = 0.0;
    $serie_cumpl = [];
    $cursor      = new DateTime($desde_act);
    $end         = new DateTime($hasta_act);
    while ($cursor <= $end) {
        $f = $cursor->format('Y-m-d');
        $cum_fact += (float)($fact_map[$f]   ?? 0);
        $cum_obj  += (float)($obj_diario[$f] ?? 0);
        $serie_cumpl[] = [
            'fecha'        => $f,
            'cumplimiento' => $cum_obj > 0 ? ($cum_fact - $cum_obj) / $cum_obj : 0,
        ];
        $cursor->modify('+1 day');
    }

    // ── Helpers ─────────────────────────────────────
    $var = fn($act, $prev) => $prev != 0 ? ($act - $prev) / $prev : ($act > 0 ? 1 : 0);
    $dias_act  = (new DateTime($desde_act))->diff(new DateTime($hasta_act))->days + 1;
    $dias_prev = (new DateTime($desde_prev))->diff(new DateTime($hasta_prev))->days + 1;

    $ultimaActRaw = null;
    $isOutdated = false;
    $ultimaActFormatted = 'No disponible';
    try {
        $ultimaActRaw = $db->getUltimaActualizacion();
        if ($ultimaActRaw) {
            $dtUpdate = new DateTime($ultimaActRaw);
            $ultimaActFormatted = $dtUpdate->format('d/m/Y H:i:s');
            
            $dtYesterday = new DateTime('yesterday 00:00:00');
            $isOutdated = ($dtUpdate < $dtYesterday);
        }
    } catch (Throwable $_) {}

    ob_clean();
    echo json_encode([
        'ok'           => true,
        'ultima_actualizacion' => $ultimaActFormatted,
        'is_outdated' => $isOutdated,
        'ultima_fecha' => $ultima_fecha,
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
            'porc_2do'         => $kpi_act['porc_2do'],
            'porc_3ro'         => $kpi_act['porc_3ro'],
            'porc_incremental' => $kpi_act['porc_incremental'],
            'porc_presencia'   => $kpi_act['porc_presencia'],
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
            'porc_2do'         => $kpi_prev['porc_2do'],
            'porc_3ro'         => $kpi_prev['porc_3ro'],
            'porc_incremental' => $kpi_prev['porc_incremental'],
            'porc_presencia'   => $kpi_prev['porc_presencia'],
            'ingresos'         => $conv_prev['ingresos'],
            'conversion'       => $conv_prev['conversion'],
        ],
        'variacion' => [
            'facturacion'      => $var($kpi_act['facturacion'],     $kpi_prev['facturacion']),
            'unidades'         => $var($kpi_act['unidades'],        $kpi_prev['unidades']),
            'tickets'          => $var($kpi_act['tickets'],         $kpi_prev['tickets']),
            'ticket_promedio'  => $var($kpi_act['ticket_promedio'], $kpi_prev['ticket_promedio']),
            'objetivo'         => $kpi_act['objetivo'] != 0 ? ($kpi_act['facturacion'] - $kpi_act['objetivo']) / $kpi_act['objetivo'] : 0,
            'porc_2do'         => $kpi_act['porc_2do']         - $kpi_prev['porc_2do'],
            'porc_3ro'         => $kpi_act['porc_3ro']         - $kpi_prev['porc_3ro'],
            'porc_cambios'     => $kpi_act['porc_cambios']     - $kpi_prev['porc_cambios'],
            'porc_incremental' => $kpi_act['porc_incremental'] - $kpi_prev['porc_incremental'],
            'porc_presencia'   => $kpi_act['porc_presencia']   - $kpi_prev['porc_presencia'],
            'conversion'       => ($conv_act['conversion'] === null || $conv_prev['conversion'] === null)
                                    ? null
                                    : $var($conv_act['conversion'], $conv_prev['conversion']),
        ],
        'benchmark' => [
            'tickets_var'      => $var($bench['tickets'],       $kpi_prev['tickets']),
            'ticket_promedio'  => $bench['ticket_promedio'],
            'porc_2do'         => $bench['porc_2do'],
            'porc_3ro'         => $bench['porc_3ro'],
            'porc_cambios'     => $bench['porc_cambios'],
            'porc_incremental' => $bench['porc_incremental'],
            'porc_presencia'   => $bench['porc_presencia'],
        ],
        'serie' => [
            'actual'       => $serie_act,
            'cumplimiento' => $serie_cumpl,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
