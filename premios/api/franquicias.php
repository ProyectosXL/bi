<?php
/**
 * /bi/premios/api/franquicias.php
 * Vista "Franquicias": KPIs + tabla de facturación vs objetivos por sucursal
 * franquicia, ordenada alfabéticamente, con fila de total.
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
    // $_GET['supervisora'] se ignora a propósito en esta vista: no existe relación
    // sucursal-franquicia → supervisora (ni en SQL ni en el modelo del .pbix original),
    // así que la tabla y los KPIs de Franquicias son siempre a nivel empresa.
    // Ver premios/README.md y class/PremiosDB.php.

    $db = new PremiosDB($da, $ha, $dp, $hp);

    $todos   = $db->datosFranquicias();
    $conteos = $db->conteosFranquiciaEmpresa($todos);

    $kpis = [
        'cant_cumplen_objetivo'  => $conteos['cant_venta'],
        'cant_objetivo_crec'     => $conteos['cant_crecimiento'],
        'facturacion_var_marca'  => $db->facturacionVarMarca($todos),
    ];

    $filas = $todos;
    usort($filas, fn($a, $b) => strcmp($a['sucursal'], $b['sucursal']));

    $sucursales = array_map(function ($f) use ($db) {
        $cumpl = $f['sin_datos'] ? -1.0 : $db->cumplimientoObjVenta($f['imp_fact'], $f['imp_obj']);
        $var   = $f['sin_datos'] ? null  : $db->facturacionVarPct($f['imp_fact'], $f['imp_fact_ant']);
        return [
            'nro_sucurs'         => $f['nro_sucurs'],
            'sucursal'           => $f['sucursal'],
            'sin_datos'          => $f['sin_datos'],
            'facturacion'        => $f['imp_fact'],
            'objetivo_total'     => $f['imp_obj'],
            'cumplimiento_obj'   => $cumpl,
            'facturacion_previa' => $f['imp_fact_ant'],
            'facturacion_var'    => $var,
        ];
    }, $filas);

    $totFact    = array_sum(array_column($filas, 'imp_fact'));
    $totObj     = array_sum(array_column($filas, 'imp_obj'));
    $totFactAnt = array_sum(array_column($filas, 'imp_fact_ant'));

    $total = [
        'facturacion'        => $totFact,
        'objetivo_total'     => $totObj,
        'cumplimiento_obj'   => $db->cumplimientoObjVenta($totFact, $totObj),
        'facturacion_previa' => $totFactAnt,
        'facturacion_var'    => $db->facturacionVarPct($totFact, $totFactAnt),
    ];

    ob_clean();
    echo json_encode([
        'ok'         => true,
        'periodo'    => ['desde' => $da, 'hasta' => $ha, 'desde_prev' => $dp, 'hasta_prev' => $hp],
        'kpis'       => $kpis,
        'sucursales' => $sucursales,
        'total'      => $total,
    ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
