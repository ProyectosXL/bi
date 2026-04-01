<?php
/**
 * /api/grupos.php
 * Endpoint para la pestaña Grupos.
 *
 * GET params:
 *   action     = sucursales_grupo | versus | desglose | rubros_pivot
 *   periodo    = ayer|7|30|90|180|mes_actual|mes_pasado|año_actual|año_pasado|custom
 *   nro_suc_b  = NRO_SUCURS de la sucursal a comparar (solo para action=versus)
 */

session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/DashboardDB.php';
require_once __DIR__ . '/../class/GruposDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');
    $nroSucurs = isset($_SESSION['numsuc']) ? (int)$_SESSION['numsuc'] : 7;
    $action    = $_GET['action']  ?? 'sucursales_grupo';
    $periodo   = $_GET['periodo'] ?? 'mes_actual';

    $db     = new GruposDB();
    $result = ['ok' => true];

    // Calcular períodos para todas las acciones excepto sucursales_grupo
    if ($action !== 'sucursales_grupo') {
        if ($periodo === 'custom') {
            $da        = $_GET['desde']      ?? date('Y-m-01');
            $ha        = $_GET['hasta']      ?? date('Y-m-d', strtotime('-1 day'));
            $comp_mode = $_GET['comp_mode']  ?? 'year_ago';
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
    }

    switch ($action) {

        case 'sucursales_grupo':
            $sucursales = $db->getSucursalesGrupo($nroSucurs);
            $result['sucursales']       = $sucursales;
            $result['nro_sucurs_base']  = $nroSucurs;
            break;

        case 'versus':
            $nroSucB = isset($_GET['nro_suc_b']) ? (int)$_GET['nro_suc_b'] : 0;
            if ($nroSucB === 0) throw new InvalidArgumentException('nro_suc_b requerido');

            // Obtener nombres de sucursales del grupo
            $sucursales = $db->getSucursalesGrupo($nroSucurs);
            $nameMap = [];
            foreach ($sucursales as $s) {
                $nameMap[(int)$s['NRO_SUCURS']] = $s['DESC_SUCURSAL'];
            }

            $result['suc_a'] = [
                'nro_sucurs' => $nroSucurs,
                'desc'       => $nameMap[$nroSucurs] ?? 'Sucursal A',
                'kpis'       => $db->getKPIsSucursal($desde_act, $hasta_act, $nroSucurs),
            ];
            $result['suc_b'] = [
                'nro_sucurs' => $nroSucB,
                'desc'       => $nameMap[$nroSucB] ?? 'Sucursal B',
                'kpis'       => $db->getKPIsSucursal($desde_act, $hasta_act, $nroSucB),
            ];
            break;

        case 'desglose':
            $sucursales = $db->getSucursalesGrupo($nroSucurs);
            $result['grupos'] = $db->getDesglosePorSucursal(
                $desde_act, $hasta_act,
                $desde_prev, $hasta_prev,
                $sucursales,
                $nroSucurs
            );
            $result['periodo'] = [
                'desde_act'  => $desde_act,
                'hasta_act'  => $hasta_act,
                'desde_prev' => $desde_prev,
                'hasta_prev' => $hasta_prev,
            ];
            break;

        case 'rubros_pivot':
            $sucursales = $db->getSucursalesGrupo($nroSucurs);
            $result['data'] = $db->getTopRubrosPivot(
                $desde_act, $hasta_act,
                $sucursales,
                $nroSucurs
            );
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
