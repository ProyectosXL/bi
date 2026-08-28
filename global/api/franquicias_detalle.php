<?php
/**
 * /bi/global/api/franquicias_detalle.php
 * Endpoint para obtener la venta día por día por sucursal de Franquicias.
 */

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Filters.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';

session_start();

try {
    $origen   = $_GET['origen'] ?? 'franquicias';
    $cfg      = getConfigForOrigen($origen);
    $cid      = new Conexion();
    $conn     = $cid->conectar($cfg['db']);

    if (!$conn) throw new Error("No se pudo conectar a la base de datos.");

    // Optimización: Lectura sin bloqueos
    sqlsrv_query($conn, 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');

    // Pre-materialización de dimensiones remotas para evitar cruzamientos lentos en los loops de queries (evita 504 Timeout)
    if ($origen === 'franquicias') {
        $dropSl = sqlsrv_query($conn, "IF OBJECT_ID('tempdb..#sl_st') IS NOT NULL DROP TABLE #sl_st");
        if ($dropSl !== false) sqlsrv_free_stmt($dropSl);
        $stmtSl = sqlsrv_query($conn, "SELECT NRO_SUCURSAL, TANGO, DESC_SUCURSAL INTO #sl_st FROM OPENQUERY([XL-LAKERBIS], 'SELECT NRO_SUCURSAL, TANGO, DESC_SUCURSAL FROM LOCALES_LAKERS.DBO.SUCURSALES_LAKERS WHERE HABILITADO = 1')");
        if ($stmtSl === false) throw new Error("Error al materializar #sl_st: " . print_r(sqlsrv_errors(), true));
        sqlsrv_free_stmt($stmtSl);
    }

    // 1. Período
    [$da, $ha, $dp, $hp] = PeriodHelper::fromRequest($_GET);

    // SQL Server DATEADD para incluir el día hasta completo
    $haX = (new DateTime($ha))->modify('+1 day')->format('Y-m-d');
    $hpX = (new DateTime($hp))->modify('+1 day')->format('Y-m-d');

    // 2. Filtros
    $f = Filters::fromRequest($_GET, $origen);
    $f['solo_activas'] = $_GET['solo_activas'] ?? '0';
    
    // Filtro de grupo si el usuario es de tipo GRUPO
    $sfG = ''; $pG = [];
    if (($_SESSION['tipo'] ?? '') === 'GRUPO') {
        $sucursalesGrupo = $_SESSION['sucursalesGrupo'] ?? [];
        if (!empty($sucursalesGrupo)) {
            $placeholders = implode(',', array_fill(0, count($sucursalesGrupo), '?'));
            $sfG = "AND s.NRO_SUCURS IN ({$placeholders})";
            $pG  = array_values($sucursalesGrupo);
        } else {
            throw new Error("No tienes sucursales asignadas.");
        }
    }

    // Filtros estándar (vendedor, rubro, etc.)
    [$sfS, $pS] = Filters::build($f, 's', $cfg['campo_vendedor'], $origen, true, true);

    // 3. Obtener nombres de sucursales (usamos la tabla maestra para asegurar todas las columnas)
    // Pero solo las que tengan ventas en el período actual o previo.
    if ($origen === 'franquicias') {
        $sqlSuc = "
            SELECT DISTINCT sl.NRO_SUCURSAL as nro, sl.DESC_SUCURSAL as nombre
            FROM #sl_st sl
            WHERE sl.NRO_SUCURSAL IN (
                SELECT DISTINCT NRO_SUCURS FROM BI_SALES_SUCURSALES WITH (NOLOCK) 
                WHERE (FECHA >= ? AND FECHA < ?) OR (FECHA >= ? AND FECHA < ?)
                UNION ALL
                SELECT DISTINCT pv.idTango AS NRO_SUCURS
                FROM sistemas.dbo.FP_ObjetivosFinalesDetalle fd WITH (NOLOCK)
                INNER JOIN sistemas.dbo.PuntosDeVenta pv WITH (NOLOCK) ON fd.idPOS = pv.id
                INNER JOIN #sl_st sl2 ON pv.idTango = sl2.NRO_SUCURSAL
                WHERE (sl2.TANGO IS NULL OR sl2.TANGO <> 1)
                  AND ((fd.fecha >= ? AND fd.fecha < ?) OR (fd.fecha >= ? AND fd.fecha < ?))
            )
            ORDER BY sl.DESC_SUCURSAL
        ";
        $pSuc = [$da, $haX, $dp, $hpX, $da, $haX, $dp, $hpX];
    } else {
        $sqlSuc = "
            SELECT DISTINCT sl.NRO_SUCURSAL as nro, sl.DESC_SUCURSAL as nombre
            FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
            WHERE sl.HABILITADO = 1
            AND sl.NRO_SUCURSAL IN (
                SELECT DISTINCT NRO_SUCURS FROM BI_SALES_SUCURSALES WITH (NOLOCK) 
                WHERE (FECHA >= ? AND FECHA < ?) OR (FECHA >= ? AND FECHA < ?)
            )
            ORDER BY sl.DESC_SUCURSAL
        ";
        $pSuc = [$da, $haX, $dp, $hpX];
    }
    
    $stmtSuc = sqlsrv_query($conn, $sqlSuc, $pSuc);
    if ($stmtSuc === false) throw new Error(print_r(sqlsrv_errors(), true));
    
    $sucursales = [];
    $sucIds = [];
    while ($r = sqlsrv_fetch_array($stmtSuc, SQLSRV_FETCH_ASSOC)) {
        $sucursales[] = [
            'nro'    => (int)$r['nro'],
            'nombre' => trim($r['nombre'])
        ];
        $sucIds[] = (int)$r['nro'];
    }

    if (empty($sucIds)) {
        echo json_encode([
            'ok'         => true,
            'dias'       => [],
            'sucursales' => [],
            'data'       => [],
            'prevTotals' => [],
            'periodo'    => [
                'act'  => ['desde' => $da, 'hasta' => $ha],
                'prev' => ['desde' => $dp, 'hasta' => $hp]
            ]
        ]);
        exit;
    }

    $placeholdersSuc = implode(',', array_fill(0, count($sucIds), '?'));

    // 4. Query unificada de ventas (Actual + Previo)
    // Usamos UNION ALL dentro de la subquery para que los índices de FECHA se usen correctamente
    if ($origen === 'franquicias') {
        $sqlVentas = "
            SELECT 
                NRO_SUCURS, 
                dia, 
                ISNULL(SUM(CASE WHEN is_a = 1 THEN IMPORTE ELSE 0 END), 0) as total_act,
                ISNULL(SUM(CASE WHEN is_p = 1 THEN IMPORTE ELSE 0 END), 0) as total_prev
            FROM (
                SELECT NRO_SUCURS, DAY(FECHA) as dia, IMPORTE, 1 as is_a, 0 as is_p
                FROM BI_SALES_SUCURSALES s WITH (NOLOCK)
                WHERE s.FECHA >= ? AND s.FECHA < ? AND s.NRO_SUCURS IN ({$placeholdersSuc}) {$sfS} {$sfG}
                UNION ALL
                SELECT pv.idTango AS NRO_SUCURS, DAY(fd.fecha) as dia, fd.importeVentaReal AS IMPORTE, 1 as is_a, 0 as is_p
                FROM sistemas.dbo.FP_ObjetivosFinalesDetalle fd WITH (NOLOCK)
                INNER JOIN sistemas.dbo.PuntosDeVenta pv WITH (NOLOCK) ON fd.idPOS = pv.id
                INNER JOIN #sl_st sl ON pv.idTango = sl.NRO_SUCURSAL
                WHERE (sl.TANGO IS NULL OR sl.TANGO <> 1)
                  AND fd.fecha >= ? AND fd.fecha < ? AND pv.idTango IN ({$placeholdersSuc})
                UNION ALL
                SELECT NRO_SUCURS, NULL as dia, IMPORTE, 0 as is_a, 1 as is_p
                FROM BI_SALES_SUCURSALES s WITH (NOLOCK)
                WHERE s.FECHA >= ? AND s.FECHA < ? AND s.NRO_SUCURS IN ({$placeholdersSuc}) {$sfS} {$sfG}
                UNION ALL
                SELECT pv.idTango AS NRO_SUCURS, NULL as dia, fd.importeVentaReal AS IMPORTE, 0 as is_a, 1 as is_p
                FROM sistemas.dbo.FP_ObjetivosFinalesDetalle fd WITH (NOLOCK)
                INNER JOIN sistemas.dbo.PuntosDeVenta pv WITH (NOLOCK) ON fd.idPOS = pv.id
                INNER JOIN #sl_st sl ON pv.idTango = sl.NRO_SUCURSAL
                WHERE (sl.TANGO IS NULL OR sl.TANGO <> 1)
                  AND fd.fecha >= ? AND fd.fecha < ? AND pv.idTango IN ({$placeholdersSuc})
            ) t
            GROUP BY NRO_SUCURS, dia
        ";

        $pAll = array_merge(
            [$da, $haX], $sucIds, $pS, $pG,
            [$da, $haX], $sucIds,
            [$dp, $hpX], $sucIds, $pS, $pG,
            [$dp, $hpX], $sucIds
        );
    } else {
        $sqlVentas = "
            SELECT 
                NRO_SUCURS, 
                dia, 
                ISNULL(SUM(CASE WHEN is_a = 1 THEN IMPORTE ELSE 0 END), 0) as total_act,
                ISNULL(SUM(CASE WHEN is_p = 1 THEN IMPORTE ELSE 0 END), 0) as total_prev
            FROM (
                SELECT NRO_SUCURS, DAY(FECHA) as dia, IMPORTE, 1 as is_a, 0 as is_p
                FROM BI_SALES_SUCURSALES s WITH (NOLOCK)
                WHERE s.FECHA >= ? AND s.FECHA < ? {$sfS} {$sfG}
                UNION ALL
                SELECT NRO_SUCURS, NULL as dia, IMPORTE, 0 as is_a, 1 as is_p
                FROM BI_SALES_SUCURSALES s WITH (NOLOCK)
                WHERE s.FECHA >= ? AND s.FECHA < ? {$sfS} {$sfG}
            ) t
            GROUP BY NRO_SUCURS, dia
        ";

        $pAll = array_merge(
            [$da, $haX], $pS, $pG,
            [$dp, $hpX], $pS, $pG
        );
    }

    $stmtVentas = sqlsrv_query($conn, $sqlVentas, $pAll);
    if ($stmtVentas === false) throw new Error(print_r(sqlsrv_errors(), true));

    $data       = [];
    $prevTotals = [];
    while ($r = sqlsrv_fetch_array($stmtVentas, SQLSRV_FETCH_ASSOC)) {
        $nro = (int)$r['NRO_SUCURS'];
        $dia = $r['dia'];
        
        if ($dia !== null) {
            if (!isset($data[$nro])) $data[$nro] = [];
            $data[$nro][$dia] = (float)$r['total_act'];
        }
        
        if (!isset($prevTotals[$nro])) $prevTotals[$nro] = 0;
        $prevTotals[$nro] += (float)$r['total_prev'];
    }

    // 5. Generar lista de días para el eje de la tabla
    $dias = [];
    $start = new DateTime($da);
    $end   = new DateTime($ha);
    $interval = new DateInterval('P1D');
    $range = new DatePeriod($start, $interval, $end->modify('+1 day'));

    foreach ($range as $date) {
        $dias[] = [
            'dia'   => (int)$date->format('d'),
            'label' => $date->format('d/m')
        ];
    }

    echo json_encode([
        'ok'         => true,
        'dias'       => $dias,
        'sucursales' => $sucursales,
        'data'       => $data,
        'prevTotals' => $prevTotals,
        'periodo'    => [
            'act'  => ['desde' => $da, 'hasta' => $ha],
            'prev' => ['desde' => $dp, 'hasta' => $hp]
        ]
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}
