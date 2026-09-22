<?php
/**
 * /api/stock.php
 * Endpoint unificado para la pestaña de Stock del local.
 *
 * GET params:
 *   action    = filtros | resumen | arbol | detalle | buscar
 *   rubro     = % | RUBRO
 *   categoria = % | CATEGORIA
 *   temporada = % | TEMPORADA
 *   destino   = % | DESTINO
 *   q         = texto de búsqueda (solo action=buscar, mínimo 2 caracteres)
 *   top       = tope de filas (solo action=buscar, default 200)
 *
 * La sucursal NO es un parámetro: sale de $_SESSION['numsuc'].
 */

session_start();
ob_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../class/StockDB.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');
    if (!isset($_SESSION['numsuc'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Sesión inválida. Volvé a iniciar sesión.']);
        exit;
    }
    $nroSucurs = (int)$_SESSION['numsuc'];
    $action    = $_GET['action'] ?? 'arbol';

    $filtros = [
        'rubro'     => ($_GET['rubro']     ?? '%') !== '' ? ($_GET['rubro']     ?? '%') : '%',
        'categoria' => ($_GET['categoria'] ?? '%') !== '' ? ($_GET['categoria'] ?? '%') : '%',
        'temporada' => ($_GET['temporada'] ?? '%') !== '' ? ($_GET['temporada'] ?? '%') : '%',
        'destino'   => ($_GET['destino']   ?? '%') !== '' ? ($_GET['destino']   ?? '%') : '%',
    ];

    $db     = new StockDB();
    $result = ['ok' => true];

    // meta viaja sólo en las acciones que pintan encabezado, para que el front muestre
    // la frescura del dato y la nota de exclusión sin hardcodearlas. Se arma bajo
    // demanda porque getUltimaCarga() es una query extra que detalle/buscar no necesitan.
    $meta = fn() => [
        'tabla'       => $db->getTabla(),
        'fecha_carga' => $db->getUltimaCarga(),
    ];

    switch ($action) {

        case 'filtros':
            $result = array_merge($result, ['meta' => $meta()], $db->getFiltros($nroSucurs));
            break;

        case 'resumen':
            $result['meta']    = $meta();
            $result['resumen'] = $db->getResumen($nroSucurs, $filtros);
            break;

        case 'arbol':
            $arbol = $db->getArbol($nroSucurs, $filtros);
            $result['meta']    = $meta();
            $result['arbol']   = $arbol;
            $result['totales'] = [
                'unidades' => array_sum(array_column($arbol, 'unidades')),
            ];
            break;

        case 'detalle':
            $rubro     = $_GET['rubro']     ?? '';
            $categoria = $_GET['categoria'] ?? '';
            if ($rubro === '' || $rubro === '%')         throw new InvalidArgumentException('rubro requerido');
            if ($categoria === '' || $categoria === '%') throw new InvalidArgumentException('categoria requerida');

            $result['rubro']     = $rubro;
            $result['categoria'] = $categoria;
            $result['filas']     = $db->getDetalle($nroSucurs, $rubro, $categoria, $filtros);
            break;

        case 'buscar':
            $q = trim($_GET['q'] ?? '');
            if (mb_strlen($q) < 2) throw new InvalidArgumentException('q requerido (mínimo 2 caracteres)');
            $top = (int)($_GET['top'] ?? 200);
            if ($top < 1 || $top > 1000) $top = 200;

            // La búsqueda ignora rubro/categoría a propósito: se busca en todo el
            // stock del local, no dentro del nodo que esté abierto.
            $filtrosBusqueda = ['temporada' => $filtros['temporada'], 'destino' => $filtros['destino']];

            $filas = $db->buscar($nroSucurs, $q, $filtrosBusqueda, $top);
            $result['q']        = $q;
            $result['filas']    = $filas;
            $result['total']    = count($filas);
            $result['truncado'] = count($filas) >= $top;
            break;

        default:
            throw new InvalidArgumentException("Acción desconocida: $action");
    }

    ob_clean();
    // Sin JSON_NUMERIC_CHECK: convertiría un ARTICULO como "0012345" en 12345 y le
    // mostraría al franquiciado un código que no existe en Tango. Los numéricos ya
    // vienen casteados desde StockDB.
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
exit;
