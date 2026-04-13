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
set_time_limit(300);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/GlobalDashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) {
        throw new RuntimeException('No autenticado');
    }
    $tipoSesion = $_SESSION['tipo'] ?? '';
    if (!in_array($tipoSesion, ['GERENCIA', 'SUPERVISION', 'GRUPO'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $isGrupo = ($tipoSesion === 'GRUPO');

    // ── Endpoint auxiliar: cotización dólar ────────
    if (($_GET['action'] ?? '') === 'cotizacion') {
        $db  = new GlobalDashboardDB('argentina');
        $cot = $db->getCotizacionDolar();
        ob_clean();
        echo json_encode(['ok' => true, 'tcc' => $cot['tcc'], 'fecha' => $cot['fecha']],
            JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    $origen      = $isGrupo ? 'franquicias' : ($_GET['origen'] ?? 'argentina');
    $periodo     = $_GET['periodo']     ?? 'mes_actual';
    $vendedor    = (isset($_GET['vendedor']) && $_GET['vendedor'] !== '') ? $_GET['vendedor'] : '%';
    $rubro       = (isset($_GET['rubro'])    && $_GET['rubro']    !== '') ? $_GET['rubro']    : '%';
    $sucursal    = isset($_GET['sucursal']) && $_GET['sucursal'] !== '' ? (int)$_GET['sucursal'] : null;
    $grupo       = isset($_GET['grupo']) && $_GET['grupo'] !== '' ? $_GET['grupo'] : null;
    $tipoTienda  = isset($_GET['tipo_tienda']) && $_GET['tipo_tienda'] !== '' ? $_GET['tipo_tienda'] : null;
    $soloActivas = !$isGrupo && isset($_GET['solo_activas']) && $_GET['solo_activas'] === '1';

    // GRUPO: fijar origen y limpiar filtros que no aplican.
    // $sucursal se permite pasar — grupoFiltro() garantiza que solo vean sus sucursales.
    if ($isGrupo) {
        $origen     = 'franquicias';
        $grupo      = null;
        $tipoTienda = null;
    }

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
    if ($soloActivas) $db->setSoloActivas(true);

    // Instancia benchmark: cadena completa franquicias, sin filtro de grupo
    $dbBench = new GlobalDashboardDB($origen);
    if ($isGrupo) $dbBench->setIgnorarFiltroGrupo(true);

    // ── Endpoint: serie para sparklines (carga diferida desde JS) ──────────
    if (($_GET['action'] ?? '') === 'serie') {
        $serie_act  = [];
        $serie_prev = [];
        $serie_cumpl = [];
        try {
            $serie_act  = $db->getSerieFacturacion($desde_act, $hasta_act, $sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
            $serie_prev = $db->getSerieFacturacionSimple($desde_prev, $hasta_prev, $sucursal, $vendedor, $rubro, $grupo, $tipoTienda);
            $obj_diario = $db->getSerieObjetivo($desde_act, $hasta_act, $grupo, $tipoTienda);
            $fact_map   = array_column($serie_act, 'facturacion', 'fecha');
            $cum_fact   = 0.0; $cum_obj = 0.0;
            $cursor     = new DateTime($desde_act);
            $end        = new DateTime($hasta_act);
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
        } catch (Throwable $_) {}
        ob_clean();
        echo json_encode([
            'ok'    => true,
            'serie' => ['actual' => $serie_act, 'previo' => $serie_prev, 'cumplimiento' => $serie_cumpl],
        ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    // Cotización dólar (siempre desde argentina, no depende del origen)
    $cotizacion = (new GlobalDashboardDB('argentina'))->getCotizacionDolar();

    // ── Períodos actual + previo en 7 queries (bulk) vs 14 separadas ───────
    $bulk = $db->getKPIsBulk(
        $desde_act, $hasta_act, $desde_prev, $hasta_prev,
        $sucursal, $vendedor, $rubro, $grupo, $tipoTienda
    );
    $full_act  = $bulk['actual'];
    $full_prev = $bulk['previo'];

    // Rango para objetivos: mes completo para mes_actual, rango exacto para el resto
    if ($periodo === 'mes_actual') {
        $primerDiaMes = date('Y-m-01', strtotime($desde_act));
        $ultimoDiaMes = date('Y-m-t',  strtotime($desde_act));
    } else {
        $primerDiaMes = $desde_act;
        $ultimoDiaMes = $hasta_act;
    }

    // ── Benchmark (cadena completa sin filtros de sucursal/grupo/tipoTienda) ──
    $noBench = ['ticket_promedio' => 0, 'ticket_promedio_2do' => 0, 'porc_2do' => 0, 'porc_3ro' => 0, 'porc_cambios' => 0, 'porc_incremental' => 0];
    // Benchmark: no aplica para GRUPO (solo ven su grupo, no la cadena completa)
    if ($isGrupo) {
        $full_bench = $noBench;
    } else {
        try {
            $full_bench = $dbBench->getKPIsCompletos($desde_act, $hasta_act, null, '%', '%', null, null);
        } catch (Throwable $_) {
            $full_bench = $noBench;
        }
    }

    // ── Conversión (puede no existir para todos los orígenes) ──────
    $noConv = ['ingresos' => 0, 'tickets' => 0, 'conversion' => 0];
    try {
        $conv_act  = $db->getConversion($desde_act,  $hasta_act,  $sucursal, $grupo, $tipoTienda);
        $conv_prev = $db->getConversion($desde_prev, $hasta_prev, $sucursal, $grupo, $tipoTienda);
    } catch (Throwable $_) {
        $conv_act  = $noConv;
        $conv_prev = $noConv;
    }

    // Serie cargada de forma diferida desde JS (?action=serie)
    $serie_act   = [];
    $serie_prev  = [];
    $serie_cumpl = [];

    // ── Tabla Facturación vs Objetivos por sucursal ─
    try {
        $factPorSuc = $db->getFacturacionPorSucursal($desde_act, $hasta_act, $desde_prev, $hasta_prev, $grupo, $tipoTienda, $sucursal);
    } catch (Throwable $_) {
        $factPorSuc = [];
    }
    try {
        $objPorSuc = $db->getObjetivosPorSucursal($desde_act, $hasta_act, $primerDiaMes, $ultimoDiaMes, $grupo, $tipoTienda, $sucursal);
    } catch (Throwable $_) {
        $objPorSuc = [];
    }

    // Nombres de sucursales (para mostrar en tabla)
    $sucNombres = [];
    try {
        $lista = $isGrupo
            ? $db->getSucursalesPorIds($_SESSION['sucursalesGrupo'] ?? [])
            : $db->getSucursalesLista();
        foreach ($lista as $s) {
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
        'ok'             => true,
        'cotizacion_dolar' => $cotizacion,
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
            'facturacion'        => $full_act['facturacion'],
            'unidades'           => $full_act['unidades'],
            'tickets'            => $full_act['tickets'],
            'ticket_promedio'    => $full_act['ticket_promedio'],
            'porc_cambios'       => $full_act['porc_cambios'],
            'objetivo'           => $obj_fecha_kpi,
            'objetivo_total'     => $obj_total,
            'porc_2do'           => $full_act['porc_2do'],
            'porc_3ro'           => $full_act['porc_3ro'],
            'porc_incremental'   => $full_act['porc_incremental'],
            'ingresos'           => $conv_act['ingresos'],
            'conversion'         => $conv_act['conversion'],
            'ticket_promedio_2do' => $full_act['ticket_promedio_2do'],
            'tickets_con_2do'    => $full_act['tickets_con_2do'],
            'mails'              => $full_act['mails'],
        ],
        'previo' => [
            'facturacion'        => $full_prev['facturacion'],
            'unidades'           => $full_prev['unidades'],
            'tickets'            => $full_prev['tickets'],
            'ticket_promedio'    => $full_prev['ticket_promedio'],
            'porc_cambios'       => $full_prev['porc_cambios'],
            'objetivo'           => $full_prev['objetivo'],
            'porc_2do'           => $full_prev['porc_2do'],
            'porc_3ro'           => $full_prev['porc_3ro'],
            'porc_incremental'   => $full_prev['porc_incremental'],
            'ingresos'           => $conv_prev['ingresos'],
            'conversion'         => $conv_prev['conversion'],
            'ticket_promedio_2do' => $full_prev['ticket_promedio_2do'],
            'mails'              => $full_prev['mails'],
        ],
        'variacion' => [
            'facturacion'        => $var($full_act['facturacion'],        $full_prev['facturacion']),
            'unidades'           => $var($full_act['unidades'],           $full_prev['unidades']),
            'tickets'            => $var($full_act['tickets'],            $full_prev['tickets']),
            'ticket_promedio'    => $var($full_act['ticket_promedio'],    $full_prev['ticket_promedio']),
            'objetivo'           => $obj_fecha_kpi != 0 ? ($full_act['facturacion'] - $obj_fecha_kpi) / $obj_fecha_kpi : 0,
            'porc_2do'           => $full_act['porc_2do'] - $full_prev['porc_2do'],
            'porc_3ro'           => $full_act['porc_3ro'] - $full_prev['porc_3ro'],
            'porc_cambios'       => $full_act['porc_cambios'] - $full_prev['porc_cambios'],
            'porc_incremental'   => $full_act['porc_incremental'] - $full_prev['porc_incremental'],
            'conversion'         => $var($conv_act['conversion'], $conv_prev['conversion']),
            'ticket_promedio_2do' => $var($full_act['ticket_promedio_2do'], $full_prev['ticket_promedio_2do']),
            'mails'              => $var($full_act['mails'], $full_prev['mails']),
        ],
        'benchmark' => [
            'ticket_promedio'     => $full_bench['ticket_promedio'],
            'ticket_promedio_2do' => $full_bench['ticket_promedio_2do'],
            'porc_2do'            => $full_bench['porc_2do'],
            'porc_3ro'            => $full_bench['porc_3ro'],
            'porc_cambios'        => $full_bench['porc_cambios'],
            'porc_incremental'    => $full_bench['porc_incremental'],
        ],
        'serie' => [
            'actual'       => $serie_act,
            'previo'       => $serie_prev,
            'cumplimiento' => $serie_cumpl,
        ],
        'tabla_sucursales' => $tablaSucursales,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
