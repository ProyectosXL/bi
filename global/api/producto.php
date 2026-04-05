<?php
/**
 * /global/api/producto.php
 * Análisis por producto (RUBRO → CATEGORÍA, colores, sucursales, top categorías, stock).
 */
session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/sucursales/class/AnalisisDB.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) throw new RuntimeException('No autenticado');
    if (!in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $origen  = $_GET['origen']  ?? 'argentina';
    $action  = $_GET['action']  ?? 'rubros_categorias';
    $periodo = $_GET['periodo'] ?? 'mes_actual';

    $origenToTipo = [
        'argentina'   => 'LOCAL_PROPIO',
        'uruguay'     => 'LOCAL_PROPIO_UY',
        'franquicias' => 'FRANQUICIA',
    ];

    $tipoOriginal     = $_SESSION['tipo'];
    $_SESSION['tipo'] = $origenToTipo[$origen] ?? 'LOCAL_PROPIO';

    $sucursalRaw = isset($_GET['sucursal']) && $_GET['sucursal'] !== '' ? (int)$_GET['sucursal'] : null;
    $numsucOrig  = $_SESSION['numsuc'] ?? null;
    $_SESSION['numsuc'] = $sucursalRaw;

    $vendedor  = (isset($_GET['vendedor'])  && $_GET['vendedor']  !== '') ? $_GET['vendedor']  : '%';
    $rubro     = (isset($_GET['rubro'])     && $_GET['rubro']     !== '') ? $_GET['rubro']     : '%';
    $categoria = (isset($_GET['categoria']) && $_GET['categoria'] !== '') ? $_GET['categoria'] : '%';

    require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/sucursales/class/DashboardDB.php';

    if ($periodo === 'custom') {
        $da        = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha        = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        $comp_mode = $_GET['comp_mode'] ?? 'year_ago';
        $da_p = $comp_mode === 'custom'
            ? ($_GET['desde_comp'] ?? (new DateTime($da))->modify('-1 year')->format('Y-m-d'))
            : (new DateTime($da))->modify('-1 year')->format('Y-m-d');
        $ha_p = $comp_mode === 'custom'
            ? ($_GET['hasta_comp'] ?? (new DateTime($ha))->modify('-1 year')->format('Y-m-d'))
            : (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = [$da, $ha, $da_p, $ha_p];
    } else {
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = DashboardDB::calcularPeriodo($periodo);
    }

    // Días del período (para cálculo de rotación)
    $diasPeriodo = max(1, (int)round((strtotime($hasta_act) - strtotime($desde_act)) / 86400) + 1);

    $db       = new AnalisisDB();
    $response = ['ok' => true];

    switch ($action) {

        /* ── RUBRO → CATEGORÍA (con stock) ───────────────── */
        case 'rubros_categorias':
            $act  = $db->getRubrosProducto($desde_act,  $hasta_act,  $sucursalRaw, $vendedor, $rubro, $categoria);
            $prev = $db->getRubrosProducto($desde_prev, $hasta_prev, $sucursalRaw, $vendedor, $rubro, $categoria);

            // Índice previo
            $prevIdx = [];
            foreach ($prev as $r) {
                $prevIdx[$r['RUBRO'] . '|' . $r['CATEGORIA']] = $r;
            }

            // Índice stock local (RUBRO|CATEGORIA → stock_local)
            $stockLocalRows = $db->getStockLocalesProducto($rubro, $categoria);
            $stockLocalIdx  = [];
            foreach ($stockLocalRows as $r) {
                $stockLocalIdx[$r['RUBRO'] . '|' . $r['CATEGORIA']] = (float)$r['stock_local'];
            }

            // Índice stock central
            $stockCentralRows = $db->getStockCentralProducto($rubro, $categoria);
            $stockCentralIdx  = [];
            foreach ($stockCentralRows as $r) {
                $stockCentralIdx[$r['RUBRO'] . '|' . $r['CATEGORIA']] = (float)$r['stock_central'];
            }

            // Función helper para días de stock
            $calcDias = function(float $stock, float $unidades) use ($diasPeriodo): ?float {
                if ($stock <= 0 || $unidades <= 0) return null;
                $promDiario = $unidades / $diasPeriodo;
                return round($stock / $promDiario, 1);
            };

            // Construir árbol RUBRO → CATEGORIA
            $tree = [];
            foreach ($act as $r) {
                $key      = $r['RUBRO'] . '|' . $r['CATEGORIA'];
                $p        = $prevIdx[$key] ?? null;
                $unidAct  = (float)$r['unidades'];
                $unidPrev = $p ? (float)$p['unidades']    : 0;
                $factAct  = (float)$r['facturacion'];
                $stkLocal = $stockLocalIdx[$key]   ?? 0;
                $stkCent  = $stockCentralIdx[$key] ?? 0;
                $varU     = $unidPrev != 0 ? ($unidAct - $unidPrev) / abs($unidPrev) : null;

                $rubroKey = $r['RUBRO'];
                if (!isset($tree[$rubroKey])) {
                    $tree[$rubroKey] = [
                        'rubro'         => $rubroKey,
                        'unidades'      => 0, 'unidades_prev'  => 0,
                        'facturacion'   => 0,
                        'stock_local'   => 0, 'stock_central'  => 0,
                        'categorias'    => [],
                    ];
                }
                $tree[$rubroKey]['categorias'][] = [
                    'categoria'       => $r['CATEGORIA'],
                    'unidades'        => $unidAct,
                    'unidades_prev'   => $unidPrev,
                    'facturacion'     => $factAct,
                    'var_unidades'    => $varU,
                    'stock_local'     => $stkLocal,
                    'stock_central'   => $stkCent,
                    'dias_stock'      => $calcDias($stkLocal, $unidAct),
                    'dias_stock_meta' => $calcDias($stkLocal, $unidPrev),
                ];
                $tree[$rubroKey]['unidades']     += $unidAct;
                $tree[$rubroKey]['unidades_prev'] += $unidPrev;
                $tree[$rubroKey]['facturacion']   += $factAct;
                $tree[$rubroKey]['stock_local']   += $stkLocal;
                $tree[$rubroKey]['stock_central'] += $stkCent;
            }

            // Calcular variación y días a nivel rubro, ordenar
            $result = [];
            foreach ($tree as &$rb) {
                $rb['var_unidades']    = $rb['unidades_prev'] != 0
                    ? ($rb['unidades'] - $rb['unidades_prev']) / abs($rb['unidades_prev'])
                    : null;
                $rb['dias_stock']      = $calcDias($rb['stock_local'], $rb['unidades']);
                $rb['dias_stock_meta'] = $calcDias($rb['stock_local'], $rb['unidades_prev']);
                usort($rb['categorias'], fn($a, $b) => $b['unidades'] <=> $a['unidades']);
                $result[] = $rb;
            }
            unset($rb);
            usort($result, fn($a, $b) => $b['unidades'] <=> $a['unidades']);
            $response['rubros'] = $result;
            break;

        /* ── COLORES (con stock) ──────────────────────────── */
        case 'colores':
            $act  = $db->getColoresProducto($desde_act,  $hasta_act,  $sucursalRaw, $vendedor, $rubro, $categoria);
            $prev = $db->getColoresProducto($desde_prev, $hasta_prev, $sucursalRaw, $vendedor, $rubro, $categoria);

            $prevIdx = [];
            foreach ($prev as $r) { $prevIdx[$r['COLOR']] = $r; }

            $stockColorRows = $db->getStockLocalesColores($rubro, $categoria);
            $stockColorIdx  = [];
            foreach ($stockColorRows as $r) { $stockColorIdx[$r['COLOR']] = (float)$r['stock_local']; }

            $result = [];
            foreach ($act as $r) {
                $p        = $prevIdx[$r['COLOR']] ?? null;
                $unidAct  = (float)$r['unidades'];
                $unidPrev = $p ? (float)$p['unidades'] : 0;
                $result[] = [
                    'color'         => $r['COLOR'],
                    'unidades'      => $unidAct,
                    'unidades_prev' => $unidPrev,
                    'facturacion'   => (float)$r['facturacion'],
                    'var_unidades'  => $unidPrev != 0 ? ($unidAct - $unidPrev) / abs($unidPrev) : null,
                    'stock_local'   => $stockColorIdx[$r['COLOR']] ?? 0,
                ];
            }
            $response['colores'] = $result;
            break;

        /* ── SUCURSALES (con período anterior) ───────────── */
        case 'sucursales':
            $act  = $db->getSucursalesProducto($desde_act,  $hasta_act,  $vendedor, $rubro, $categoria);
            $prev = $db->getSucursalesProducto($desde_prev, $hasta_prev, $vendedor, $rubro, $categoria);
            $prevIdx = [];
            foreach ($prev as $r) { $prevIdx[(int)$r['NRO_SUCURS']] = $r; }
            $result = [];
            foreach ($act as $r) {
                $nro  = (int)$r['NRO_SUCURS'];
                $p    = $prevIdx[$nro] ?? null;
                $uAct = (float)$r['unidades'];
                $uPrv = $p ? (float)$p['unidades'] : 0;
                $result[] = [
                    'NRO_SUCURS'    => $nro,
                    'unidades'      => $uAct,
                    'unidades_prev' => $uPrv,
                    'facturacion'   => (float)$r['facturacion'],
                    'var_unidades'  => $uPrv != 0 ? ($uAct - $uPrv) / abs($uPrv) : null,
                ];
            }
            $response['sucursales'] = $result;
            break;

        /* ── TOP CATEGORÍAS ──────────────────────────────── */
        case 'top_categorias':
            $act  = $db->getTopCategoriasProducto($desde_act,  $hasta_act,  $sucursalRaw, $vendedor, $rubro);
            $prev = $db->getTopCategoriasProducto($desde_prev, $hasta_prev, $sucursalRaw, $vendedor, $rubro);
            $prevIdx = [];
            foreach ($prev as $r) { $prevIdx[$r['RUBRO'] . '|' . $r['CATEGORIA']] = $r; }
            $result = [];
            foreach ($act as $r) {
                $key  = $r['RUBRO'] . '|' . $r['CATEGORIA'];
                $p    = $prevIdx[$key] ?? null;
                $uAct = (float)$r['unidades'];
                $uPrv = $p ? (float)$p['unidades'] : 0;
                $result[] = [
                    'rubro'         => $r['RUBRO'],
                    'categoria'     => $r['CATEGORIA'],
                    'unidades'      => $uAct,
                    'unidades_prev' => $uPrv,
                    'facturacion'   => (float)$r['facturacion'],
                    'var_unidades'  => $uPrv != 0 ? ($uAct - $uPrv) / abs($uPrv) : null,
                ];
            }
            $response['top'] = $result;
            break;

        default:
            $response = ['ok' => false, 'error' => "Accion desconocida: {$action}"];
    }

    $_SESSION['tipo']   = $tipoOriginal;
    $_SESSION['numsuc'] = $numsucOrig;

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    if (isset($tipoOriginal))  $_SESSION['tipo']   = $tipoOriginal;
    if (isset($numsucOrig))    $_SESSION['numsuc'] = $numsucOrig;
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
