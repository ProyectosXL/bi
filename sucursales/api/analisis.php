<?php
/**
 * /api/analisis.php
 * Endpoint unificado para la pestaña de Análisis.
 *
 * GET params:
 *   action    = jerarquia | vendedores | cards_rubros | evolucion_unidades | evolucion_tickets
 *   periodo   = ayer|7|30|90|180|mes_actual|mes_pasado|año_actual|año_pasado|custom
 *   desde     = Y-m-d  (solo si periodo=custom)
 *   hasta     = Y-m-d  (solo si periodo=custom)
 *   comp_mode = year_ago|custom (solo si periodo=custom)
 *   desde_comp= Y-m-d  (solo si comp_mode=custom)
 *   hasta_comp= Y-m-d  (solo si comp_mode=custom)
 *   vendedor  = % | DESC_VENDEDOR
 *   rubro     = % | RUBRO
 */

session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/DashboardDB.php';
require_once __DIR__ . '/../class/AnalisisDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');
    $nroSucurs = isset($_SESSION['numsuc']) ? (int)$_SESSION['numsuc'] : 7;
    $action   = $_GET['action']   ?? 'jerarquia';
    $periodo  = $_GET['periodo']  ?? 'mes_actual';
    $vendedor = $_GET['vendedor'] ?? '%';
    $rubro    = $_GET['rubro']    ?? '%';

    // Calcular períodos
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
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = DashboardDB::calcularPeriodo($periodo);
    }

    $db = new AnalisisDB();
    $result = ['ok' => true];

    switch ($action) {

        case 'jerarquia':
            $act  = $db->getJerarquiaDestinoRubroCategoria($desde_act,  $hasta_act,  $nroSucurs, $vendedor, $rubro);
            $prev = $db->getJerarquiaDestinoRubroCategoria($desde_prev, $hasta_prev, $nroSucurs, $vendedor, $rubro);
            $result['jerarquia'] = $db->mergeJerarquias($act, $prev);
            $result['periodo']   = [
                'desde_act'  => $desde_act,
                'hasta_act'  => $hasta_act,
                'desde_prev' => $desde_prev,
                'hasta_prev' => $hasta_prev,
            ];
            break;

        case 'vendedores':
            $act  = $db->getVendedoresAnalisis($desde_act,  $hasta_act,  $nroSucurs, $vendedor, $rubro);
            $prev = $db->getVendedoresAnalisis($desde_prev, $hasta_prev, $nroSucurs, $vendedor, $rubro);
            $result['vendedores'] = $db->mergeVendedores($act, $prev);
            break;

        case 'cards_rubros':
            $targetRubros = [
                'BILLETERAS DE VINILICO',
                'CALZADOS',
                'CAMPERAS',
                'CARTERAS DE CUERO',
                'CARTERAS DE VINILICO',
            ];
            $act  = $db->getRubrosCards($desde_act,  $hasta_act,  $nroSucurs, $targetRubros, $vendedor);
            $prev = $db->getRubrosCards($desde_prev, $hasta_prev, $nroSucurs, $targetRubros, $vendedor);
            $result['cards'] = $db->mergeCards($act, $prev, $targetRubros);
            break;

        case 'evolucion_unidades':
            $result['evolucion'] = $db->getEvolucionMensual($nroSucurs, $vendedor, $rubro, 'unidades');
            break;

        case 'evolucion_tickets':
            $result['evolucion'] = $db->getEvolucionMensual($nroSucurs, $vendedor, $rubro, 'tickets');
            break;

        case 'ranking_rubros':
            $rubrosAct  = $db->getRankingRubros($desde_act,  $hasta_act,  $nroSucurs, $vendedor);
            $rubrosPrev = $db->getRankingRubros($desde_prev, $hasta_prev, $nroSucurs, $vendedor);
            $prevMap = [];
            foreach ($rubrosPrev as $rp) {
                $prevMap[$rp['RUBRO']] = $rp;
            }
            $total_unid = array_sum(array_column($rubrosAct, 'unidades'));
            $total_fact = array_sum(array_column($rubrosAct, 'facturacion'));
            foreach ($rubrosAct as &$r) {
                $r['porc_unidades']    = $total_unid > 0 ? $r['unidades']    / $total_unid : 0;
                $r['porc_facturacion'] = $total_fact > 0 ? $r['facturacion'] / $total_fact : 0;
                $r['unidades_prev']    = $prevMap[$r['RUBRO']]['unidades']    ?? 0;
                $r['facturacion_prev'] = $prevMap[$r['RUBRO']]['facturacion'] ?? 0;
                $r['variacion']        = $r['unidades_prev'] > 0
                    ? ($r['unidades'] - $r['unidades_prev']) / $r['unidades_prev']
                    : 0;
            }
            $result['rubros']  = $rubrosAct;
            $result['totales'] = ['unidades' => $total_unid, 'facturacion' => $total_fact];
            $result['periodo'] = [
                'desde_act'  => $desde_act,
                'hasta_act'  => $hasta_act,
                'desde_prev' => $desde_prev,
                'hasta_prev' => $hasta_prev,
            ];
            break;

        case 'ranking_categorias':
            $rubroFilter = $_GET['rubro_filter'] ?? '';
            if ($rubroFilter === '') throw new InvalidArgumentException('rubro_filter requerido');
            $catAct  = $db->getRankingCategorias($desde_act,  $hasta_act,  $nroSucurs, $vendedor, $rubroFilter);
            $catPrev = $db->getRankingCategorias($desde_prev, $hasta_prev, $nroSucurs, $vendedor, $rubroFilter);
            $prevMap = [];
            foreach ($catPrev as $cp) {
                $prevMap[$cp['CATEGORIA']] = $cp;
            }
            foreach ($catAct as &$c) {
                $c['unidades_prev']    = (float)($prevMap[$c['CATEGORIA']]['unidades']    ?? 0);
                $c['facturacion_prev'] = (float)($prevMap[$c['CATEGORIA']]['facturacion'] ?? 0);
                $c['variacion']        = $c['unidades_prev'] > 0
                    ? ($c['unidades'] - $c['unidades_prev']) / $c['unidades_prev']
                    : 0;
            }
            unset($c);
            $result['categorias'] = $catAct;
            $result['rubro']      = $rubroFilter;
            break;

        default:
            throw new InvalidArgumentException("Acción desconocida: $action");
    }

    ob_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
