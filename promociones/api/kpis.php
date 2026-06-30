<?php
/**
 * /bi/promociones/api/kpis.php
 * KPIs principales del dashboard de Promociones.
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/PromocionesDB.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/global/class/GlobalDashboardDB.php';

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

    $isGrupo          = ($tipoSesion === 'GRUPO');
    $origenPermitidos = ['argentina', 'franquicias', 'uruguay'];
    $origen           = $isGrupo ? 'franquicias'
        : (in_array($_GET['origen'] ?? '', $origenPermitidos, true) ? $_GET['origen'] : 'argentina');
    $periodo = $_GET['periodo'] ?? 'mes_actual';

    // Filtros de usuario
    $fp = [
        'banco'               => isset($_GET['banco'])               && $_GET['banco']               !== '' ? $_GET['banco']                : null,
        'sucursal'            => isset($_GET['sucursal'])            && $_GET['sucursal']            !== '' ? (int)$_GET['sucursal']        : null,
        'promocion'           => isset($_GET['promocion'])           && $_GET['promocion']           !== '' ? $_GET['promocion']             : null,
        'excluir_promociones' => isset($_GET['excluir_promociones']) && $_GET['excluir_promociones'] !== '' ? $_GET['excluir_promociones']   : null,
    ];

    // GRUPO: validar sucursal
    if ($isGrupo && $fp['sucursal'] !== null) {
        if (!in_array($fp['sucursal'], $_SESSION['sucursalesGrupo'] ?? [], true)) {
            $fp['sucursal'] = null;
        }
    }

    if ($periodo === 'custom') {
        $da = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        $comp_mode = $_GET['comp_mode'] ?? 'year_ago';
        if ($comp_mode === 'custom') {
            $dp = $_GET['desde_comp'] ?? (new DateTime($da))->modify('-1 year')->format('Y-m-d');
            $hp = $_GET['hasta_comp'] ?? (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        } else {
            $dp = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
            $hp = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        }
    } else {
        [$da, $ha, $dp, $hp] = PromocionesDB::calcularPeriodo($periodo);
    }

    $db   = new PromocionesDB($origen);
    $bulk = $db->getKPIsBulk($da, $ha, $dp, $hp, $fp);

    $act  = $bulk['actual'];
    $prev = $bulk['previo'];

    $var = fn($a, $p) => $p != 0 ? ($a - $p) / $p : ($a > 0 ? 1 : 0);
    $pp  = fn($a, $p) => $a - $p; // diferencia en puntos porcentuales

    $ratio = fn($n, $d) => $d > 0 ? $n / $d : 0;

    // Cotización — siempre desde argentina
    $cotizacion = null;
    try {
        if ($origen === 'uruguay') {
            // RO_V_DOLAR_OFICIAL_BCRA no existe en power_uy; tomar tcc_actual de cotizaciones UY
            $cot = (new GlobalDashboardDB('uruguay'))->getCotizacionesMensuales($da, $ha);
            $cotizacion = ['tcc' => $cot['tcc_actual'], 'fecha' => ''];
        } else {
            $cotizacion = (new GlobalDashboardDB('argentina'))->getCotizacionDolar();
        }
    } catch (Throwable $_) {}

    $dias_act  = (new DateTime($da))->diff(new DateTime($ha))->days + 1;
    $dias_prev = (new DateTime($dp))->diff(new DateTime($hp))->days + 1;

    ob_clean();
    echo json_encode([
        'ok'              => true,
        'cotizacion_dolar' => $cotizacion,
        'periodo' => [
            'tipo'       => $periodo,
            'desde_act'  => $da,
            'hasta_act'  => $ha,
            'desde_prev' => $dp,
            'hasta_prev' => $hp,
            'dias_act'   => $dias_act,
            'dias_prev'  => $dias_prev,
        ],
        'actual' => [
            'facturacion_total'   => $act['facturacion_total'],
            'facturacion_cpromo'  => $act['facturacion_cpromo'],
            'costo_promo'         => $act['costo_promo'],
            'costo_promo_banc'    => $act['costo_promo_banc'],
            'costo_promo_ventas'  => $act['costo_promo_ventas'],
            'tickets_cpromo'      => $act['tickets_cpromo'],
            'pct_promo_fac'       => $ratio($act['facturacion_cpromo'], $act['facturacion_total']),
            'pct_costo_cpromo'    => $ratio($act['costo_promo'], $act['facturacion_cpromo']),
            'pct_costo_total'     => $ratio($act['costo_promo'], $act['facturacion_total']),
            'pct_costo_banc_fac'  => $ratio($act['costo_promo_banc'], $act['facturacion_total']),
            'pct_costo_ventas_fac'=> $ratio($act['costo_promo_ventas'], $act['facturacion_total']),
        ],
        'previo' => [
            'facturacion_total'   => $prev['facturacion_total'],
            'facturacion_cpromo'  => $prev['facturacion_cpromo'],
            'costo_promo'         => $prev['costo_promo'],
            'costo_promo_banc'    => $prev['costo_promo_banc'],
            'costo_promo_ventas'  => $prev['costo_promo_ventas'],
            'tickets_cpromo'      => $prev['tickets_cpromo'],
            'pct_promo_fac'       => $ratio($prev['facturacion_cpromo'], $prev['facturacion_total']),
            'pct_costo_cpromo'    => $ratio($prev['costo_promo'], $prev['facturacion_cpromo']),
            'pct_costo_total'     => $ratio($prev['costo_promo'], $prev['facturacion_total']),
            'pct_costo_banc_fac'  => $ratio($prev['costo_promo_banc'], $prev['facturacion_total']),
            'pct_costo_ventas_fac'=> $ratio($prev['costo_promo_ventas'], $prev['facturacion_total']),
        ],
        'variacion' => [
            'facturacion_cpromo'  => $var($act['facturacion_cpromo'],  $prev['facturacion_cpromo']),
            'tickets_cpromo'      => $var($act['tickets_cpromo'],      $prev['tickets_cpromo']),
            // ratios: variación en puntos porcentuales
            'pct_promo_fac'       => $pp(
                $ratio($act['facturacion_cpromo'], $act['facturacion_total']),
                $ratio($prev['facturacion_cpromo'], $prev['facturacion_total'])
            ),
            'pct_costo_total'     => $pp(
                $ratio($act['costo_promo'], $act['facturacion_total']),
                $ratio($prev['costo_promo'], $prev['facturacion_total'])
            ),
            'pct_costo_cpromo'    => $pp(
                $ratio($act['costo_promo'], $act['facturacion_cpromo']),
                $ratio($prev['costo_promo'], $prev['facturacion_cpromo'])
            ),
            'pct_costo_banc_fac'  => $pp(
                $ratio($act['costo_promo_banc'], $act['facturacion_total']),
                $ratio($prev['costo_promo_banc'], $prev['facturacion_total'])
            ),
            'pct_costo_ventas_fac'=> $pp(
                $ratio($act['costo_promo_ventas'], $act['facturacion_total']),
                $ratio($prev['costo_promo_ventas'], $prev['facturacion_total'])
            ),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
