<?php
/**
 * /global/api/liquidacion.php
 * Datos para la solapa de Liquidación (período fijo 1/7 al 16/8 vs año anterior).
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
    if (!in_array($tipoSesion, ['GERENCIA', 'SUPERVISION', 'GRUPO'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $isGrupo = ($tipoSesion === 'GRUPO');
    $origen  = $isGrupo ? 'franquicias' : ($_GET['origen'] ?? 'argentina');
    $sucursal    = isset($_GET['sucursal']) && $_GET['sucursal'] !== '' ? (int)$_GET['sucursal'] : null;
    $grupo       = isset($_GET['grupo']) && $_GET['grupo'] !== '' ? $_GET['grupo'] : null;
    $tipoTienda  = isset($_GET['tipo_tienda']) && $_GET['tipo_tienda'] !== '' ? $_GET['tipo_tienda'] : null;
    $canal       = isset($_GET['canal']) && $_GET['canal'] !== '' ? $_GET['canal'] : null;
    $soloActivas = !$isGrupo && isset($_GET['solo_activas']) && $_GET['solo_activas'] === '1';

    if ($isGrupo || $origen !== 'argentina') {
        $grupo      = null;
        $tipoTienda = null;
        $canal      = null;
    }

    // Fechas fijas para Liquidación: 1/7 al 16/8 del 2026 vs anterior
    $desde_act  = '2026-07-01';
    $hasta_act  = '2026-08-16';
    $desde_prev = '2025-07-01';
    $hasta_prev = '2025-08-16';

    $liqTipo      = isset($_GET['liq_tipo']) && $_GET['liq_tipo'] !== '' ? $_GET['liq_tipo'] : null;
    $liqRubro     = isset($_GET['liq_rubro']) && $_GET['liq_rubro'] !== '' ? $_GET['liq_rubro'] : null;
    $liqCategoria = isset($_GET['liq_categoria']) && $_GET['liq_categoria'] !== '' ? $_GET['liq_categoria'] : null;

    $db = new GlobalDashboardDB($origen);
    if ($soloActivas) {
        $db->setSoloActivas(true);
    }

    // 1) KPIs
    $kpis = $db->getKPIsLiquidacion($desde_act, $hasta_act, $desde_prev, $hasta_prev, $sucursal, null, null, $grupo, $tipoTienda, $canal, $liqTipo, $liqRubro, $liqCategoria);

    // 2) Facturación por Sucursal
    $sucursales = $db->getFacturacionPorSucursalLiquidacion($desde_act, $hasta_act, $desde_prev, $hasta_prev, $grupo, $tipoTienda, $sucursal, $canal, $liqTipo, $liqRubro, $liqCategoria);

    // 3) Productos más vendidos
    $productos = $db->getProductosLiquidacion($desde_act, $hasta_act, $grupo, $tipoTienda, $sucursal, $canal, $liqTipo, $liqRubro, $liqCategoria);

    // 4) Jerarquía de Liquidación vs Normal
    $rawJer = $db->getJerarquiaLiquidacion($desde_act, $hasta_act, $sucursal, null, null, $grupo, $tipoTienda, $canal, $liqRubro, $liqCategoria);
    $jerarquia = [];
    foreach ($rawJer as $r) {
        $rub = $r['RUBRO'] ?? 'SIN RUBRO';
        $cat = $r['CATEGORIA'] ?? 'SIN CATEGORIA';
        
        if (!isset($jerarquia[$rub])) {
            $jerarquia[$rub] = [
                'label' => $rub,
                'fact_liq' => 0.0,
                'fact_norm' => 0.0,
                'unid_liq' => 0.0,
                'unid_norm' => 0.0,
                'ref_liq' => 0,
                'ref_norm' => 0,
                'categorias' => []
            ];
        }
        
        $jerarquia[$rub]['fact_liq'] += (float)$r['fact_liq'];
        $jerarquia[$rub]['fact_norm'] += (float)$r['fact_norm'];
        $jerarquia[$rub]['unid_liq'] += (float)$r['unid_liq'];
        $jerarquia[$rub]['unid_norm'] += (float)$r['unid_norm'];
        $jerarquia[$rub]['ref_liq'] += (int)$r['ref_liq'];
        $jerarquia[$rub]['ref_norm'] += (int)$r['ref_norm'];
        
        $jerarquia[$rub]['categorias'][] = [
            'label' => $cat,
            'fact_liq' => (float)$r['fact_liq'],
            'fact_norm' => (float)$r['fact_norm'],
            'unid_liq' => (float)$r['unid_liq'],
            'unid_norm' => (float)$r['unid_norm'],
            'ref_liq' => (int)$r['ref_liq'],
            'ref_norm' => (int)$r['ref_norm']
        ];
    }
    $jerarquia = array_values($jerarquia);

    // 5) Timeline / Serie temporal
    $timeline = $db->getTimelineLiquidacion($desde_act, $hasta_act, $desde_prev, $hasta_prev, $sucursal, null, null, $grupo, $tipoTienda, $canal, $liqTipo, $liqRubro, $liqCategoria);

    ob_clean();
    echo json_encode([
        'ok'         => true,
        'kpis'       => $kpis,
        'sucursales' => $sucursales,
        'productos'  => $productos,
        'jerarquia'  => $jerarquia,
        'timeline'   => $timeline,
        'fechas'     => [
            'desde_act'  => $desde_act,
            'hasta_act'  => $hasta_act,
            'desde_prev' => $desde_prev,
            'hasta_prev' => $hasta_prev
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
