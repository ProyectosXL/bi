<?php
/**
 * /bi/global/api/franquicias_resumen.php
 * Endpoint para obtener el Resumen Anual Anterior por sucursal de Franquicias.
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

    // 1. Período: 12 meses hacia atrás desde el mes actual
    $endObj = new DateTime('last day of this month');
    $startObj = (clone $endObj)->modify('-11 months')->modify('first day of this month');

    $da = $startObj->format('Y-m-d');
    $ha = $endObj->format('Y-m-d');

    $startPrevObj = (clone $startObj)->modify('-1 year');
    $endPrevObj = (clone $endObj)->modify('-1 year');

    $dp = $startPrevObj->format('Y-m-d');
    $hp = $endPrevObj->format('Y-m-d');

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
            'meses'      => [],
            'sucursales' => [],
            'data'       => [],
            'periodo'    => [
                'act'  => ['desde' => $da, 'hasta' => $ha],
                'prev' => ['desde' => $dp, 'hasta' => $hp]
            ]
        ]);
        exit;
    }

    $placeholdersSuc = implode(',', array_fill(0, count($sucIds), '?'));

    // 4. Query de ventas - Año Actual (Agrupado por Año y Mes)
    if ($origen === 'franquicias') {
        $sqlVentasAct = "
            SELECT 
                NRO_SUCURS, 
                YEAR(FECHA) as anio,
                MONTH(FECHA) as mes,
                ISNULL(SUM(IMPORTE), 0) as total
            FROM (
                SELECT NRO_SUCURS, FECHA, IMPORTE
                FROM BI_SALES_SUCURSALES s WITH (NOLOCK)
                WHERE s.FECHA >= ? AND s.FECHA < ? AND s.NRO_SUCURS IN ({$placeholdersSuc}) {$sfS} {$sfG}
                UNION ALL
                SELECT pv.idTango AS NRO_SUCURS, fd.fecha AS FECHA, fd.importeVentaReal AS IMPORTE
                FROM sistemas.dbo.FP_ObjetivosFinalesDetalle fd WITH (NOLOCK)
                INNER JOIN sistemas.dbo.PuntosDeVenta pv WITH (NOLOCK) ON fd.idPOS = pv.id
                INNER JOIN #sl_st sl ON pv.idTango = sl.NRO_SUCURSAL
                WHERE (sl.TANGO IS NULL OR sl.TANGO <> 1)
                  AND fd.fecha >= ? AND fd.fecha < ? AND pv.idTango IN ({$placeholdersSuc})
            ) s
            GROUP BY NRO_SUCURS, YEAR(FECHA), MONTH(FECHA)
        ";

        $pAct = array_merge(
            [$da, $haX], $sucIds, $pS, $pG,
            [$da, $haX], $sucIds
        );
    } else {
        $sqlVentasAct = "
            SELECT 
                NRO_SUCURS, 
                YEAR(FECHA) as anio,
                MONTH(FECHA) as mes,
                ISNULL(SUM(IMPORTE), 0) as total
            FROM BI_SALES_SUCURSALES s WITH (NOLOCK)
            WHERE s.FECHA >= ? AND s.FECHA < ? {$sfS} {$sfG}
              AND s.NRO_SUCURS IN ({$placeholdersSuc})
            GROUP BY NRO_SUCURS, YEAR(FECHA), MONTH(FECHA)
        ";

        $pAct = array_merge(
            [$da, $haX], $pS, $pG,
            $sucIds
        );
    }

    $stmtVentasAct = sqlsrv_query($conn, $sqlVentasAct, $pAct);
    if ($stmtVentasAct === false) throw new Error(print_r(sqlsrv_errors(), true));

    $data = []; // keyed by [nro][anio_mes] = ['act' => X, 'prev' => Y, 'obj' => Z]
    while ($r = sqlsrv_fetch_array($stmtVentasAct, SQLSRV_FETCH_ASSOC)) {
        $nro = (int)$r['NRO_SUCURS'];
        $anio = (int)$r['anio'];
        $mes = (int)$r['mes'];
        $key = sprintf("%04d-%02d", $anio, $mes);
        
        if (!isset($data[$nro])) $data[$nro] = [];
        if (!isset($data[$nro][$key])) $data[$nro][$key] = ['act' => 0.0, 'prev' => 0.0, 'obj' => 0.0];
        $data[$nro][$key]['act'] = (float)$r['total'];
    }

    // 5. Query de ventas - Año Previo (Agrupado por Año y Mes)
    if ($origen === 'franquicias') {
        $sqlVentasPrev = "
            SELECT 
                NRO_SUCURS, 
                YEAR(FECHA) as anio,
                MONTH(FECHA) as mes,
                ISNULL(SUM(IMPORTE), 0) as total
            FROM (
                SELECT NRO_SUCURS, FECHA, IMPORTE
                FROM BI_SALES_SUCURSALES s WITH (NOLOCK)
                WHERE s.FECHA >= ? AND s.FECHA < ? AND s.NRO_SUCURS IN ({$placeholdersSuc}) {$sfS} {$sfG}
                UNION ALL
                SELECT pv.idTango AS NRO_SUCURS, fd.fecha AS FECHA, fd.importeVentaReal AS IMPORTE
                FROM sistemas.dbo.FP_ObjetivosFinalesDetalle fd WITH (NOLOCK)
                INNER JOIN sistemas.dbo.PuntosDeVenta pv WITH (NOLOCK) ON fd.idPOS = pv.id
                INNER JOIN #sl_st sl ON pv.idTango = sl.NRO_SUCURSAL
                WHERE (sl.TANGO IS NULL OR sl.TANGO <> 1)
                  AND fd.fecha >= ? AND fd.fecha < ? AND pv.idTango IN ({$placeholdersSuc})
            ) s
            GROUP BY NRO_SUCURS, YEAR(FECHA), MONTH(FECHA)
        ";

        $pPrev = array_merge(
            [$dp, $hpX], $sucIds, $pS, $pG,
            [$dp, $hpX], $sucIds
        );
    } else {
        $sqlVentasPrev = "
            SELECT 
                NRO_SUCURS, 
                YEAR(FECHA) as anio,
                MONTH(FECHA) as mes,
                ISNULL(SUM(IMPORTE), 0) as total
            FROM BI_SALES_SUCURSALES s WITH (NOLOCK)
            WHERE s.FECHA >= ? AND s.FECHA < ? {$sfS} {$sfG}
              AND s.NRO_SUCURS IN ({$placeholdersSuc})
            GROUP BY NRO_SUCURS, YEAR(FECHA), MONTH(FECHA)
        ";

        $pPrev = array_merge(
            [$dp, $hpX], $pS, $pG,
            $sucIds
        );
    }

    $stmtVentasPrev = sqlsrv_query($conn, $sqlVentasPrev, $pPrev);
    if ($stmtVentasPrev === false) throw new Error(print_r(sqlsrv_errors(), true));

    while ($r = sqlsrv_fetch_array($stmtVentasPrev, SQLSRV_FETCH_ASSOC)) {
        $nro = (int)$r['NRO_SUCURS'];
        $anio = (int)$r['anio'];
        $mes = (int)$r['mes'];
        
        // El año previo lo guardamos usando la key del año actual equivalente (sumando 1 año)
        $keyEquiv = sprintf("%04d-%02d", $anio + 1, $mes);
        
        if (!isset($data[$nro])) $data[$nro] = [];
        if (!isset($data[$nro][$keyEquiv])) $data[$nro][$keyEquiv] = ['act' => 0.0, 'prev' => 0.0, 'obj' => 0.0];
        $data[$nro][$keyEquiv]['prev'] = (float)$r['total'];
    }

    // 6. Query de Objetivos - Año Actual (Agrupado por Año y Mes)
    if ($origen === 'franquicias') {
        $sqlObjetivos = "
            SELECT 
                pv.idTango as NRO_SUCURS,
                o.anio as anio,
                o.mes as mes,
                ISNULL(SUM(o.importeObjetivo), 0) as total_obj
            FROM sistemas.dbo.FP_ObjetivosFinales o WITH (NOLOCK)
            INNER JOIN sistemas.dbo.PuntosDeVenta pv WITH (NOLOCK) ON o.idPOS = pv.id
            WHERE DATEFROMPARTS(o.anio, o.mes, 1) >= ? AND DATEFROMPARTS(o.anio, o.mes, 1) < ?
              AND pv.idTango IN ({$placeholdersSuc})
            GROUP BY pv.idTango, o.anio, o.mes
        ";
    } else {
        $sqlObjetivos = "
            SELECT 
                o.NRO_SUCURSAL as NRO_SUCURS,
                YEAR(o.FECHA) as anio,
                MONTH(o.FECHA) as mes,
                ISNULL(SUM(o.IMPORTE_OBJ), 0) as total_obj
            FROM {$cfg['tabla_objetivos']} o WITH (NOLOCK)
            WHERE o.FECHA >= ? AND o.FECHA < ?
              AND o.NRO_SUCURSAL IN ({$placeholdersSuc})
            GROUP BY o.NRO_SUCURSAL, YEAR(o.FECHA), MONTH(o.FECHA)
        ";
    }

    $pObj = array_merge(
        [$da, $haX],
        $sucIds
    );

    $stmtObjetivos = sqlsrv_query($conn, $sqlObjetivos, $pObj);
    if ($stmtObjetivos === false) throw new Error(print_r(sqlsrv_errors(), true));

    while ($r = sqlsrv_fetch_array($stmtObjetivos, SQLSRV_FETCH_ASSOC)) {
        $nro = (int)$r['NRO_SUCURS'];
        $anio = (int)$r['anio'];
        $mes = (int)$r['mes'];
        $key = sprintf("%04d-%02d", $anio, $mes);
        
        if (!isset($data[$nro])) $data[$nro] = [];
        if (!isset($data[$nro][$key])) $data[$nro][$key] = ['act' => 0.0, 'prev' => 0.0, 'obj' => 0.0];
        $data[$nro][$key]['obj'] = (float)$r['total_obj'];
    }

    // 7. Generar lista de meses para el reporte dentro del rango del período actual (de mayor a menor)
    $meses = [];
    $start = new DateTime($da);
    $end   = new DateTime($ha);
    
    // Iteramos mes por mes
    $current = clone $end;
    $current->modify('first day of this month');
    $limit = clone $start;
    $limit->modify('first day of this month');

    $mesesNombres = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];

    while ($current >= $limit) {
        $anioVal = (int)$current->format('Y');
        $mesVal  = (int)$current->format('n');
        $keyVal  = $current->format('Y-m');
        
        $meses[] = [
            'key'   => $keyVal,
            'label' => sprintf("%02d. %s %04d", $mesVal, $mesesNombres[$mesVal], $anioVal)
        ];
        
        $current->modify('-1 month');
    }

    echo json_encode([
        'ok'         => true,
        'meses'      => $meses,
        'sucursales' => $sucursales,
        'data'       => $data,
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
