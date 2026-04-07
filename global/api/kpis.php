<?php
/**
 * /global/api/kpis.php
 * KPIs globales consolidados + tabla Facturación vs Objetivos por sucursal.
 *
 * GET params:
 *   origen     = argentina|uruguay|franquicias
 *   periodo    = ayer|7|30|90|180|mes_actual|mes_pasado|año_actual|año_pasado|custom
 *   desde, hasta, comp_mode, desde_comp, hasta_comp (si periodo=custom)
 *   sucursal   = NRO_SUCURS (opcional)
 *   grupo      = GRUPO (opcional, solo argentina)
 *   tipo_tienda = TIPO_TIENDA (opcional, solo argentina)
 *   vendedor   = DESC_VENDEDOR (opcional)
 *   rubro      = RUBRO (opcional)
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/GlobalDashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) {
        throw new RuntimeException('No autenticado');
    }
    $tipoSesion = $_SESSION['tipo'] ?? '';
    if (!in_array($tipoSesion, ['GERENCIA', 'SUPERVISION'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $origen     = $_GET['origen']      ?? 'argentina';
    $periodo    = $_GET['periodo']     ?? 'mes_actual';
    $vendedor   = (isset($_GET['vendedor']) && $_GET['vendedor'] !== '') ? $_GET['vendedor'] : '%';
    $rubro      = (isset($_GET['rubro'])    && $_GET['rubro']    !== '') ? $_GET['rubro']    : '%';
    $sucursal   = isset($_GET['sucursal']) && $_GET['sucursal'] !== '' ? (int)$_GET['sucursal'] : null;
    $grupo      = isset($_GET['grupo']) && $_GET['grupo'] !== '' ? $_GET['grupo'] : null;
    $tipoTienda = isset($_GET['tipo_tienda']) && $_GET['tipo_tienda'] !== '' ? $_GET['tipo_tienda'] : null;

    // Solo argentina soporta grupo/tipoTienda
    if ($origen !== 'argentina') {
        $grupo = null;
        $tipoTienda = null;
    }

    if ($periodo === 'custom') {
        $da        = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha        = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        $comp_mode = $_GET['comp_mode'] ?? 'year_ago';
        if ($comp_mode === 'custom') {
            $da_p = $_GET['desde_comp'] ?? (new DateTime($da))->modify('-1 year')->format('Y-m-d');
            $ha_p = $_GET['hasta_comp'] ?? (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        } else {
            $da_p = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
            $ha_p = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        }
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = [$da, $ha, $da_p, $ha_p];
    } else {
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = GlobalDashboardDB::calcularPeriodo($periodo);
    }

    $db = new GlobalDashboardDB($origen);

    // ── Período actual ──────────────────────────────
    $kpi_act  = $db->getKPIs($desde_act, $hasta_act, $sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
    $tick_act = $db->getTicketsProductos($desde_act, $hasta_act, $sucursal, $vendedor, $grupo, $tipoTienda);
    $tp2_act  = $db->getTicketPromedio2do($desde_act, $hasta_act, $sucursal, $vendedor, $grupo, $tipoTienda);
    $incr_act = $db->getIncremental($desde_act, $hasta_act, $sucursal, $vendedor, $grupo, $tipoTienda);
    // Mails
    $mails_act  = $db->getMails($desde_act,  $hasta_act,  $sucursal, $grupo, $tipoTienda);

    // Fechas del mes completo que contiene $desde_act
    $primerDiaMes = date('Y-m-01', strtotime($desde_act));
    $ultimoDiaMes = date('Y-m-t',  strtotime($desde_act));

    // ── Período previo ──────────────────────────────
    $kpi_prev  = $db->getKPIs($desde_prev, $hasta_prev, $sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
    $tick_prev = $db->getTicketsProductos($desde_prev, $hasta_prev, $sucursal, $vendedor, $grupo, $tipoTienda);
    $tp2_prev  = $db->getTicketPromedio2do($desde_prev, $hasta_prev, $sucursal, $vendedor, $grupo, $tipoTienda);
    $incr_prev = $db->getIncremental($desde_prev, $hasta_prev, $sucursal, $vendedor, $grupo, $tipoTienda);
    $mails_prev = $db->getMails($desde_prev, $hasta_prev, $sucursal, $grupo, $tipoTienda);

    // ── Benchmark (cadena completa sin filtros de sucursal/grupo/tipoTienda) ──
    $bench_kpi  = $db->getKPIs($desde_act, $hasta_act, null, '%', '%', null, null);
    $bench_tick = $db->getTicketsProductos($desde_act, $hasta_act, null, '%', null, null);
    $bench_tp2  = $db->getTicketPromedio2do($desde_act, $hasta_act, null, '%', null, null);
    $bench_incr = $db->getIncremental($desde_act, $hasta_act, null, '%', null, null);

    // ── Conversión (puede no existir para todos los orígenes) ──────
    $noConv = ['ingresos' => 0, 'tickets' => 0, 'conversion' => 0];
    try {
        $conv_act  = $db->getConversion($desde_act,  $hasta_act,  $sucursal, $grupo, $tipoTienda);
        $conv_prev = $db->getConversion($desde_prev, $hasta_prev, $sucursal, $grupo, $tipoTienda);
    } catch (Throwable $_) {
        $conv_act  = $noConv;
        $conv_prev = $noConv;
    }

    // ── Serie para sparklines (no crítica — no cancela el resto) ───
    try {
        $serie_act  = $db->getSerieFacturacion($desde_act,  $hasta_act,  $sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
        $serie_prev = $db->getSerieFacturacion($desde_prev, $hasta_prev, $sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
    } catch (Throwable $_) {
        $serie_act  = [];
        $serie_prev = [];
    }

    // ── Tabla Facturación vs Objetivos por sucursal ─
    $factPorSuc = $db->getFacturacionPorSucursal($desde_act, $hasta_act, $desde_prev, $hasta_prev, $grupo, $tipoTienda);
    $objPorSuc  = $db->getObjetivosPorSucursal($desde_act, $hasta_act, $primerDiaMes, $ultimoDiaMes, $grupo, $tipoTienda);

    // Nombres de sucursales (para mostrar en tabla)
    $sucNombres = [];
    try {
        foreach ($db->getSucursalesLista() as $s) {
            $sucNombres[(int)$s['NRO_SUCURS']] = $s['DESC_SUCURSAL'] ?? ('Suc. ' . $s['NRO_SUCURS']);
        }
    } catch (Throwable $_) {}

    // Combinar facturación + objetivos — solo sucursales con ventas en el origen seleccionado
    $tablaSucursales = [];
    foreach (array_keys($factPorSuc) as $nro) {
        $f = $factPorSuc[$nro];
        $o = $objPorSuc[$nro]  ?? ['objetivo_fecha' => 0, 'objetivo_total' => 0];
        $desvio = $o['objetivo_fecha'] > 0 ? ($f['facturacion'] - $o['objetivo_fecha']) / $o['objetivo_fecha'] : null;
        $tablaSucursales[] = [
            'nro_sucurs'      => $nro,
            'nombre'          => $sucNombres[$nro] ?? ('Suc. ' . $nro),
            'facturacion'     => $f['facturacion'],
            'var_facturacion' => $f['var_fact'],
            'objetivo_total'  => $o['objetivo_total'],
            'objetivo_fecha'  => $o['objetivo_fecha'],
            'desvio'          => $desvio,
        ];
    }
    usort($tablaSucursales, fn($a, $b) => $b['facturacion'] <=> $a['facturacion']);

    // KPI objetivo (pro-rated a la fecha) y objetivo_total (mes completo):
    // ambos restringidos a sucursales con ventas — mismo scope que la tabla
    $obj_fecha_kpi = 0;
    $obj_total     = 0;
    foreach (array_keys($factPorSuc) as $nro) {
        $obj_fecha_kpi += $objPorSuc[$nro]['objetivo_fecha'] ?? 0;
        $obj_total     += $objPorSuc[$nro]['objetivo_total'] ?? 0;
    }

    $var = fn($act, $prev) => $prev != 0 ? ($act - $prev) / $prev : ($act > 0 ? 1 : 0);
    $dias_act  = (new DateTime($desde_act))->diff(new DateTime($hasta_act))->days + 1;
    $dias_prev = (new DateTime($desde_prev))->diff(new DateTime($hasta_prev))->days + 1;

    ob_clean();
    echo json_encode([
        'ok'      => true,
        'periodo' => [
            'tipo'       => $periodo,
            'desde_act'  => $desde_act,
            'hasta_act'  => $hasta_act,
            'desde_prev' => $desde_prev,
            'hasta_prev' => $hasta_prev,
            'dias_act'   => $dias_act,
            'dias_prev'  => $dias_prev,
        ],
        'actual' => [
            'facturacion'        => $kpi_act['facturacion'],
            'unidades'           => $kpi_act['unidades'],
            'tickets'            => $kpi_act['tickets'],
            'ticket_promedio'    => $kpi_act['ticket_promedio'],
            'porc_cambios'       => $kpi_act['porc_cambios'],
            'objetivo'           => $obj_fecha_kpi,
            'objetivo_total'     => $obj_total,
            'porc_2do'           => $tick_act['porc_2do'],
            'porc_3ro'           => $tick_act['porc_3ro'],
            'porc_incremental'   => $incr_act['porc_incremental'],
            'ingresos'           => $conv_act['ingresos'],
            'conversion'         => $conv_act['conversion'],
            'ticket_promedio_2do' => $tp2_act['ticket_promedio_2do'],
            'tickets_con_2do'    => $tp2_act['tickets_con_2do'],
            'mails'              => $mails_act['mails'],
        ],
        'previo' => [
            'facturacion'        => $kpi_prev['facturacion'],
            'unidades'           => $kpi_prev['unidades'],
            'tickets'            => $kpi_prev['tickets'],
            'ticket_promedio'    => $kpi_prev['ticket_promedio'],
            'porc_cambios'       => $kpi_prev['porc_cambios'],
            'objetivo'           => $kpi_prev['objetivo'],
            'porc_2do'           => $tick_prev['porc_2do'],
            'porc_3ro'           => $tick_prev['porc_3ro'],
            'porc_incremental'   => $incr_prev['porc_incremental'],
            'ingresos'           => $conv_prev['ingresos'],
            'conversion'         => $conv_prev['conversion'],
            'ticket_promedio_2do' => $tp2_prev['ticket_promedio_2do'],
            'mails'              => $mails_prev['mails'],
        ],
        'variacion' => [
            'facturacion'        => $var($kpi_act['facturacion'],        $kpi_prev['facturacion']),
            'unidades'           => $var($kpi_act['unidades'],           $kpi_prev['unidades']),
            'tickets'            => $var($kpi_act['tickets'],            $kpi_prev['tickets']),
            'ticket_promedio'    => $var($kpi_act['ticket_promedio'],    $kpi_prev['ticket_promedio']),
            'objetivo'           => $obj_fecha_kpi != 0 ? ($kpi_act['facturacion'] - $obj_fecha_kpi) / $obj_fecha_kpi : 0,
            'porc_2do'           => $tick_act['porc_2do'] - $tick_prev['porc_2do'],
            'porc_3ro'           => $tick_act['porc_3ro'] - $tick_prev['porc_3ro'],
            'porc_cambios'       => $kpi_act['porc_cambios'] - $kpi_prev['porc_cambios'],
            'porc_incremental'   => $incr_act['porc_incremental'] - $incr_prev['porc_incremental'],
            'conversion'         => $var($conv_act['conversion'], $conv_prev['conversion']),
            'ticket_promedio_2do' => $var($tp2_act['ticket_promedio_2do'], $tp2_prev['ticket_promedio_2do']),
            'mails'              => $var($mails_act['mails'], $mails_prev['mails']),
        ],
        'benchmark' => [
            'ticket_promedio'     => $bench_kpi['ticket_promedio'],
            'ticket_promedio_2do' => $bench_tp2['ticket_promedio_2do'],
            'porc_2do'            => $bench_tick['porc_2do'],
            'porc_3ro'            => $bench_tick['porc_3ro'],
            'porc_cambios'        => $bench_kpi['porc_cambios'],
            'porc_incremental'    => $bench_incr['porc_incremental'],
        ],
        'serie' => [
            'actual' => $serie_act,
            'previo' => $serie_prev,
        ],
        'tabla_sucursales' => $tablaSucursales,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
