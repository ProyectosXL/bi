<?php
/**
 * /global/api/analisis.php
 * Análisis global (jerarquía, ranking rubros, evolución, vendedores).
 * Delega en AnalisisDB del módulo sucursales con configuración por origen.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/sucursales/class/AnalisisDB.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Filters.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/sucursales/class/DashboardDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) throw new RuntimeException('No autenticado');
    $tipoSesion = $_SESSION['tipo'] ?? '';
    if (!in_array($tipoSesion, ['GERENCIA', 'SUPERVISION', 'GRUPO'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $isGrupo = ($tipoSesion === 'GRUPO');
    $sucursalesGrupo = $_SESSION['sucursalesGrupo'] ?? [];

    $origen = $isGrupo ? 'franquicias' : ($_GET['origen'] ?? 'argentina');
    $sucursal = isset($_GET['sucursal']) && $_GET['sucursal'] !== '' ? $_GET['sucursal'] : null;

    // GRUPO: validar sucursal solicitada
    if ($isGrupo && $sucursal !== null) {
        if (!in_array($sucursal, $sucursalesGrupo, true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sucursal no autorizada']);
            exit;
        }
    }

    // Liberar lock de sesión para permitir peticiones paralelas concurrentes
    session_write_close();

    $action   = $_GET['action']   ?? 'ranking_rubros';
    $periodo  = $_GET['periodo']  ?? 'mes_actual';
    $vendedor = (isset($_GET['vendedor']) && $_GET['vendedor'] !== '') ? $_GET['vendedor'] : '%';
    $rubro    = (isset($_GET['rubro'])    && $_GET['rubro']    !== '') ? $_GET['rubro']    : '%';

    if ($periodo === 'custom') {
        $da        = (isset($_GET['desde']) && $_GET['desde'] !== '') ? $_GET['desde'] : date('Y-m-01');
        $ha        = (isset($_GET['hasta']) && $_GET['hasta'] !== '') ? $_GET['hasta'] : date('Y-m-d', strtotime('-1 day'));
        $comp_mode = $_GET['comp_mode'] ?? 'year_ago';
        $da_p = $comp_mode === 'custom' ? ($_GET['desde_comp'] ?? (new DateTime($da))->modify('-1 year')->format('Y-m-d'))
                                        : (new DateTime($da))->modify('-1 year')->format('Y-m-d');
        $ha_p = $comp_mode === 'custom' ? ($_GET['hasta_comp'] ?? (new DateTime($ha))->modify('-1 year')->format('Y-m-d'))
                                        : (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = [$da, $ha, $da_p, $ha_p];
    } else {
        [$desde_act, $hasta_act, $desde_prev, $hasta_prev] = DashboardDB::calcularPeriodo($periodo);
    }

    $soloActivas = (!empty($_GET['solo_activas']) && $_GET['solo_activas'] === '1');
    $filtros = Filters::fromRequest($_GET, $origen);

    $db = new AnalisisDB($origen);
    $db->setSoloActivas($soloActivas);
    $db->setFiltros($filtros);
    if ($isGrupo && !empty($sucursalesGrupo)) {
        $db->setGrupoSucursales($sucursalesGrupo);
    }

    $response = ['ok' => true];
    switch ($action) {
        case 'jerarquia':
            $act  = $db->getJerarquiaDestinoRubroCategoria($desde_act, $hasta_act, $sucursal, $vendedor, $rubro);
            $prev = $db->getJerarquiaDestinoRubroCategoria($desde_prev, $hasta_prev, $sucursal, $vendedor, $rubro);
            $response['jerarquia'] = $db->mergeJerarquias($act, $prev);
            break;

        case 'vendedores':
            $act  = $db->getVendedoresAnalisis($desde_act, $hasta_act, $sucursal, $vendedor, $rubro);
            $prev = $db->getVendedoresAnalisis($desde_prev, $hasta_prev, $sucursal, $vendedor, $rubro);
            $response['vendedores'] = $db->mergeVendedores($act, $prev);
            break;

        case 'cards_rubros':
            $targetRubros = [
                'BILLETERAS DE VINILICO',
                'CALZADOS',
                'CAMPERAS',
                'CARTERAS DE CUERO',
                'CARTERAS DE VINILICO',
            ];
            $act  = $db->getRubrosCards($desde_act, $hasta_act, $sucursal, $targetRubros, $vendedor);
            $prev = $db->getRubrosCards($desde_prev, $hasta_prev, $sucursal, $targetRubros, $vendedor);
            $response['cards'] = $db->mergeCards($act, $prev, $targetRubros);
            break;

        case 'ranking_rubros':
            $rubrosAct  = $db->getRankingRubros($desde_act,  $hasta_act,  $sucursal, $vendedor);
            $rubrosPrev = $db->getRankingRubros($desde_prev, $hasta_prev, $sucursal, $vendedor);
            // Merge prev period data into act by RUBRO
            $prevIdx = [];
            foreach ($rubrosPrev as $r) { $prevIdx[$r['RUBRO']] = $r; }
            foreach ($rubrosAct as &$r) {
                $p = $prevIdx[$r['RUBRO']] ?? null;
                $r['unidades_prev']    = $p ? (float)$p['unidades']    : 0;
                $r['facturacion_prev'] = $p ? (float)$p['facturacion'] : 0;
                $r['var_unidades']     = ($r['unidades_prev'] ?? 0) > 0 ? ((float)$r['unidades'] - $r['unidades_prev']) / $r['unidades_prev'] : null;
                $r['var_facturacion']  = ($r['facturacion_prev'] ?? 0) > 0 ? ((float)$r['facturacion'] - $r['facturacion_prev']) / $r['facturacion_prev'] : null;
            }
            unset($r);
            $response['rubros'] = $rubrosAct;
            break;

        case 'ranking_categorias':
            $rubroFiltro = $_GET['rubro_filter'] ?? '';
            $response['categorias'] = $db->getRankingCategorias($desde_act, $hasta_act, $sucursal, $vendedor, $rubroFiltro);
            break;

        case 'evolucion_unidades':
        case 'evolucion_tickets':
        case 'evolucion_facturacion': {
            // Usa GlobalDashboardDB para soporte multi-origen, filtros completos y
            // UNION ALL con tablas BK históricas.
            require_once __DIR__ . '/../class/GlobalDashboardDB.php';
            $grupo_evo      = (!$isGrupo && isset($_GET['grupo'])      && $_GET['grupo']      !== '') ? $_GET['grupo']      : null;
            $tipoTienda_evo = (!$isGrupo && isset($_GET['tipo_tienda']) && $_GET['tipo_tienda'] !== '') ? $_GET['tipo_tienda'] : null;
            $canal_evo      = (!$isGrupo && $origen === 'argentina' && isset($_GET['canal']) && $_GET['canal'] !== '') ? $_GET['canal'] : null;
            $dbGlobal       = new GlobalDashboardDB($origen);
            $dbGlobal->setSoloActivas($soloActivas);
            if ($isGrupo && !empty($sucursalesGrupo)) {
                $dbGlobal->setGrupoSucursales($sucursalesGrupo);
            }
            if ($action === 'evolucion_facturacion') {
                $response['evolucion'] = $dbGlobal->getEvolucionMensualFacturacion(
                    $sucursal, $vendedor, $rubro, $grupo_evo, $tipoTienda_evo, $canal_evo
                );
            } else {
                $tipoEvo = ($action === 'evolucion_unidades') ? 'unidades' : 'tickets';
                $response['evolucion'] = $dbGlobal->getEvolucionMensual(
                    $tipoEvo, $sucursal, $vendedor, $rubro, $grupo_evo, $tipoTienda_evo, $canal_evo
                );
            }
            break;
        }

        default:
            $response = ['ok' => false, 'error' => "Acción desconocida: {$action}"];
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
