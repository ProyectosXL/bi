<?php
/**
 * /bi/global/api/campanas.php
 * Backend para la solapa "Campañas De Ventas".
 * Solo Argentina (power).
 */
session_start();
ob_start();
set_time_limit(180);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Filters.php';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) {
        throw new RuntimeException('No autenticado');
    }
    $tipoSesion = $_SESSION['tipo'] ?? '';
    if (!in_array($tipoSesion, ['GERENCIA', 'SUPERVISION', 'GRUPO', 'ANALISTA', 'ANALISTAS', 'ADMIN', 'COMERCIAL'], true)
        && strpos($tipoSesion, 'ANALISTA') === false) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $cid = new Conexion();
    $conn = $cid->conectar('power');
    if (!$conn) {
        throw new RuntimeException('Error de conexión a la base de datos de Argentina');
    }
    sqlsrv_query($conn, 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');

    $action   = $_GET['action'] ?? 'all';
    $campana  = $_GET['campana'] ?? 'madre'; // 'madre' | 'navidad'
    $anioSel  = isset($_GET['anio']) && $_GET['anio'] !== '' ? (int)$_GET['anio'] : 2026;
    $evento   = $_GET['evento'] ?? 'todos'; // 'todos' | 'campana' | 'regular'
    $sucursal = isset($_GET['sucursal']) && $_GET['sucursal'] !== '' ? $_GET['sucursal'] : null;
    $rubro    = isset($_GET['rubro']) && $_GET['rubro'] !== '' ? $_GET['rubro'] : '%';
    $canal    = isset($_GET['canal']) && $_GET['canal'] !== '' ? $_GET['canal'] : null;

    // Helper para ejecutar queries de forma limpia
    $query = function(string $sql, array $params = []) use ($conn): array {
        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            throw new RuntimeException('SQL error: ' . ($err[0]['message'] ?? 'desconocido'));
        }
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $rows;
    };

    // Configuración de campañas: mes y rango del día especial
    // Semana del Día de la Madre: 12 al 18 de octubre (mes 10)
    // Semana de Navidad: 21 al 27 de diciembre (mes 12)
    // Configuración de campañas: mes y rango del día especial
    $campanaConfigs = [
        'madre' => [
            'nombre'     => 'Semana del Día de la Madre',
            'mes'        => 10,
            'dia_inicio' => 12,
            'dia_fin'    => 18,
            'mes_nombre' => 'octubre',
            'dias_mes'   => 31
        ],
        'navidad' => [
            'nombre'     => 'Semana de Navidad',
            'mes'        => 12,
            'dia_inicio' => 21,
            'dia_fin'    => 27,
            'mes_nombre' => 'diciembre',
            'dias_mes'   => 31
        ]
    ];

    $cfg = $campanaConfigs[$campana] ?? $campanaConfigs['madre'];
    $mes = $cfg['mes'];
    $diaInicio = $cfg['dia_inicio'];
    $diaFin = $cfg['dia_fin'];

    if ($campana === 'madre' || $mes === 10) {
        // En Argentina: El Día de la Madre es el 3er domingo de Octubre
        $get3erDom = function($y) {
            $dt = new DateTime("$y-10-01");
            $doms = 0;
            while ((int)$dt->format('m') === 10) {
                if ((int)$dt->format('N') === 7) {
                    $doms++;
                    if ($doms === 3) return (int)$dt->format('j');
                }
                $dt->modify('+1 day');
            }
            return 18;
        };
        $domMadre = $get3erDom($anioSel);
        $diaInicio = $domMadre - 6;
        $diaFin = $domMadre;
        $cfg['dia_inicio'] = $diaInicio;
        $cfg['dia_fin'] = $diaFin;
    }

    // Función para mapear días homólogos entre año seleccionado y año previo
    $getMapaHomologo = function($mes, $anio) {
        $mes = (int)$mes;
        $anio = (int)$anio;
        $anioAnt = $anio - 1;
        $diasMes = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
        $diasMesAnt = cal_days_in_month(CAL_GREGORIAN, $mes, $anioAnt);
        $map = [];

        if ($mes === 10) {
            $get3erDom = function($y) {
                $dt = new DateTime("$y-10-01");
                $doms = 0;
                while ((int)$dt->format('m') === 10) {
                    if ((int)$dt->format('N') === 7) {
                        $doms++;
                        if ($doms === 3) return (int)$dt->format('j');
                    }
                    $dt->modify('+1 day');
                }
                return 18;
            };
            $domAct = $get3erDom($anio);
            $domAnt = $get3erDom($anioAnt);

            // 1. Semana de Campaña (D-6 a D-0)
            for ($offset = -6; $offset <= 0; $offset++) {
                $dAct = $domAct + $offset;
                $dAnt = $domAnt + $offset;
                if ($dAct >= 1 && $dAct <= $diasMes && $dAnt >= 1 && $dAnt <= $diasMesAnt) {
                    $map[$dAct] = $dAnt;
                }
            }

            // 2. Días pre-campaña
            for ($d = 1; $d < ($domAct - 6); $d++) {
                $dtAct = new DateTime("$anio-10-$d");
                $dwAct = (int)$dtAct->format('N');
                $bestD = null;
                $minDiff = 999;
                for ($dp = 1; $dp < ($domAnt - 6); $dp++) {
                    $dtAnt = new DateTime("$anioAnt-10-$dp");
                    if ((int)$dtAnt->format('N') === $dwAct) {
                        $diff = abs($dp - $d);
                        if ($diff < $minDiff) {
                            $minDiff = $diff;
                            $bestD = $dp;
                        }
                    }
                }
                $map[$d] = $bestD ?: $d;
            }

            // 3. Días post-campaña
            for ($d = ($domAct + 1); $d <= $diasMes; $d++) {
                $dtAct = new DateTime("$anio-10-$d");
                $dwAct = (int)$dtAct->format('N');
                $bestD = null;
                $minDiff = 999;
                for ($dp = ($domAnt + 1); $dp <= $diasMesAnt; $dp++) {
                    $dtAnt = new DateTime("$anioAnt-10-$dp");
                    if ((int)$dtAnt->format('N') === $dwAct) {
                        $diff = abs($dp - $d);
                        if ($diff < $minDiff) {
                            $minDiff = $diff;
                            $bestD = $dp;
                        }
                    }
                }
                if (!$bestD) {
                    for ($dp = 1; $dp < ($domAnt - 6); $dp++) {
                        $dtAnt = new DateTime("$anioAnt-10-$dp");
                        if ((int)$dtAnt->format('N') === $dwAct) {
                            $bestD = $dp;
                            break;
                        }
                    }
                }
                $map[$d] = $bestD ?: $d;
            }

            // Ajuste específico de feriados para Octubre 2026 vs 2025:
            if ($anio === 2026) {
                $map[9] = 3;   // Viernes 09/10/26 -> Viernes 03/10/25 regular
                $map[12] = 10; // Lunes 12/10/26 (Feriado '26) -> Viernes 10/10/25 (Feriado '25)
            }
        } elseif ($mes === 12) {
            for ($d = 1; $d <= $diasMes; $d++) {
                $dtAct = new DateTime("$anio-12-$d");
                $dwAct = (int)$dtAct->format('N');
                if ($d >= 18 && $d <= 24) {
                    $bestD = null;
                    $minDiff = 999;
                    for ($dp = 18; $dp <= 24; $dp++) {
                        $dtAnt = new DateTime("$anioAnt-12-$dp");
                        if ((int)$dtAnt->format('N') === $dwAct) {
                            $diff = abs($dp - $d);
                            if ($diff < $minDiff) {
                                $minDiff = $diff;
                                $bestD = $dp;
                            }
                        }
                    }
                    $map[$d] = $bestD ?: $d;
                } else {
                    $bestD = null;
                    $minDiff = 999;
                    for ($dp = 1; $dp <= $diasMesAnt; $dp++) {
                        $dtAnt = new DateTime("$anioAnt-12-$dp");
                        if ((int)$dtAnt->format('N') === $dwAct) {
                            $diff = abs($dp - $d);
                            if ($diff < $minDiff) {
                                $minDiff = $diff;
                                $bestD = $dp;
                            }
                        }
                    }
                    $map[$d] = $bestD ?: $d;
                }
            }
        } else {
            for ($d = 1; $d <= $diasMes; $d++) {
                $dtAct = new DateTime("$anio-$mes-$d");
                $dwAct = (int)$dtAct->format('N');
                $bestD = null;
                $minDiff = 999;
                for ($dp = 1; $dp <= $diasMesAnt; $dp++) {
                    $dtAnt = new DateTime("$anioAnt-$mes-$dp");
                    if ((int)$dtAnt->format('N') === $dwAct) {
                        $diff = abs($dp - $d);
                        if ($diff < $minDiff) {
                            $minDiff = $diff;
                            $bestD = $dp;
                        }
                    }
                }
                $map[$d] = $bestD ?: $d;
            }
        }
        ksort($map);
        return $map;
    };

    $mapaHomologo = $getMapaHomologo($mes, $anioSel);

    // Filtros de sucursales (solo locales propios o según canal/sucursal elegida)
    $sucursalClause = '';
    $sucursalParams = [];
    if ($sucursal !== null && $sucursal !== '' && $sucursal !== '%' && $sucursal !== 'Todas') {
        if (strpos($sucursal, ',') !== false) {
            $parts = array_map('intval', explode(',', $sucursal));
            $ph = implode(',', array_fill(0, count($parts), '?'));
            $sucursalClause = "AND s.NRO_SUCURS IN ($ph)";
            $sucursalParams = $parts;
        } else {
            $sucursalClause = "AND s.NRO_SUCURS = ?";
            $sucursalParams = [(int)$sucursal];
        }
    }

    $rubroClause = '';
    $rubroParams = [];
    if ($rubro !== '%' && $rubro !== '') {
        if (strpos($rubro, ',') !== false) {
            $parts = array_map('trim', explode(',', $rubro));
            $ph = implode(',', array_fill(0, count($parts), '?'));
            $rubroClause = "AND s.RUBRO IN ($ph)";
            $rubroParams = $parts;
        } else {
            $rubroClause = "AND s.RUBRO = ?";
            $rubroParams = [$rubro];
        }
    }

    $canalClause = '';
    if ($canal === 'PROPIOS') {
        $canalClause = "AND s.CANAL = 'LOCALES PROPIOS'";
    } elseif ($canal === 'ECOMMERCE') {
        $canalClause = "AND s.CANAL = 'ECOMMERCE'";
    }

    // Años a analizar: año actual seleccionado y los 2 anteriores
    // Por ejemplo para 2026: 2024, 2025, 2026. Si eligen 2025: 2023, 2024, 2025.
    $anios = [$anioSel - 2, $anioSel - 1, $anioSel];
    $anioPrevio = $anioSel - 1;
    $anioPrevio2 = $anioSel - 2;

    $response = [
        'ok' => true,
        'campana' => $campana,
        'config'  => $cfg,
        'anios'   => $anios,
        'anio_seleccionado' => $anioSel
    ];

    // =========================================================================
    // Condición de Evento Especial (semana de campaña, día regular o todos los días)
    $eventoDiaCondition = "";
    if ($evento === 'campana') {
        $eventoDiaCondition = "AND DAY(FECHA) >= $diaInicio AND DAY(FECHA) <= $diaFin";
    } elseif ($evento === 'regular') {
        $eventoDiaCondition = "AND (DAY(FECHA) < $diaInicio OR DAY(FECHA) > $diaFin)";
    }

    $eventoDiaConditionS = "";
    if ($evento === 'campana') {
        $eventoDiaConditionS = "AND DAY(s.FECHA) >= $diaInicio AND DAY(s.FECHA) <= $diaFin";
    } elseif ($evento === 'regular') {
        $eventoDiaConditionS = "AND (DAY(s.FECHA) < $diaInicio OR DAY(s.FECHA) > $diaFin)";
    }

    $eventoDiaConditionT = "";
    if ($evento === 'campana') {
        $eventoDiaConditionT = "AND DAY(t.FECHA) >= $diaInicio AND DAY(t.FECHA) <= $diaFin";
    } elseif ($evento === 'regular') {
        $eventoDiaConditionT = "AND (DAY(t.FECHA) < $diaInicio OR DAY(t.FECHA) > $diaFin)";
    }

    // =========================================================================
    // 1. DÍAS DEL MES (Serie diaria con marca de Campaña vs Día Regular)
    // =========================================================================
    // Traemos las unidades y montos por día para el año seleccionado y año anterior
    $sqlDias = "
        SELECT 
            YEAR(s.FECHA) as anio,
            DAY(s.FECHA) as dia,
            ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) as unidades,
            ISNULL(SUM(s.IMPORTE), 0) as facturacion
        FROM BI_SALES_SUCURSALES s
        WHERE MONTH(s.FECHA) = ? 
          AND YEAR(s.FECHA) IN (?, ?)
          {$sucursalClause} {$rubroClause} {$canalClause}
        GROUP BY YEAR(s.FECHA), DAY(s.FECHA)
        ORDER BY anio, dia
    ";
    $paramsDias = array_merge([$mes, $anioPrevio, $anioSel], $sucursalParams, $rubroParams);
    $rowsDias = $query($sqlDias, $paramsDias);

    $diasMes = [];
    for ($d = 1; $d <= $cfg['dias_mes']; $d++) {
        $esCampana = ($d >= $diaInicio && $d <= $diaFin);
        // Si hay filtro de evento especial, omitir días fuera del filtro
        if ($evento === 'campana' && !$esCampana) continue;
        if ($evento === 'regular' && $esCampana) continue;

        $diasMes[$d] = [
            'dia' => $d,
            'tipo' => $esCampana ? $cfg['nombre'] : 'Día Regular',
            'es_campana' => $esCampana,
            'cant_act' => 0,
            'fact_act' => 0,
            'cant_prev' => 0,
            'fact_prev' => 0
        ];
    }

    $rawDiasPrev = [];
    foreach ($rowsDias as $r) {
        $d = (int)$r['dia'];
        $a = (int)$r['anio'];
        if ($a === $anioSel) {
            if (isset($diasMes[$d])) {
                $diasMes[$d]['cant_act'] = (float)$r['unidades'];
                $diasMes[$d]['fact_act'] = (float)$r['facturacion'];
            }
        } elseif ($a === $anioPrevio) {
            $rawDiasPrev[$d] = [
                'unidades' => (float)$r['unidades'],
                'facturacion' => (float)$r['facturacion']
            ];
        }
    }

    foreach ($diasMes as $d => &$dInfo) {
        $dPrev = $mapaHomologo[$d] ?? $d;
        if (isset($rawDiasPrev[$dPrev])) {
            $dInfo['cant_prev'] = $rawDiasPrev[$dPrev]['unidades'];
            $dInfo['fact_prev'] = $rawDiasPrev[$dPrev]['facturacion'];
        }
    }
    unset($dInfo);

    $response['serie_dias'] = array_values($diasMes);

    // =========================================================================
    // 2. PARTICIPACIÓN POR RUBROS EN EL MES (Para los gráficos circulares/donas)
    // =========================================================================
    $sqlRubros = "
        SELECT 
            YEAR(s.FECHA) as anio,
            s.RUBRO,
            ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) as unidades,
            ISNULL(SUM(s.IMPORTE), 0) as facturacion
        FROM BI_SALES_SUCURSALES s
        WHERE MONTH(s.FECHA) = ? 
          AND YEAR(s.FECHA) IN (?, ?)
          AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
          {$eventoDiaConditionS}
          {$sucursalClause} {$rubroClause} {$canalClause}
        GROUP BY YEAR(s.FECHA), s.RUBRO
        ORDER BY unidades DESC
    ";
    $paramsRubros = array_merge([$mes, $anioPrevio, $anioSel], $sucursalParams, $rubroParams);
    $rowsRubros = $query($sqlRubros, $paramsRubros);

    $rubrosAct = [];
    $rubrosPrev = [];
    foreach ($rowsRubros as $r) {
        $item = [
            'rubro'       => $r['RUBRO'],
            'unidades'    => (float)$r['unidades'],
            'facturacion' => (float)$r['facturacion']
        ];
        if ((int)$r['anio'] === $anioSel) {
            $rubrosAct[] = $item;
        } else {
            $rubrosPrev[] = $item;
        }
    }
    $response['rubros_mes_act'] = $rubrosAct;
    $response['rubros_mes_prev'] = $rubrosPrev;

    // =========================================================================
    // 3. APERTURA DIARIA POR SUCURSAL (Día x Día: Facturación y Objetivo)
    // =========================================================================
    // Obtener maestro de sucursales activas (solo PROPIOS y CENTRAL/ECOMMERCE)
    $sqlSucMaestro = "
        SELECT 
            sl.NRO_SUCURSAL as nro, 
            sl.DESC_SUCURSAL as nombre,
            sl.CANAL as canal,
            ISNULL(sl.HABILITADO, 1) as habilitado
        FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
        WHERE sl.CANAL IN ('PROPIOS', 'CENTRAL')
          AND sl.HABILITADO = 1
          AND sl.DESC_SUCURSAL NOT LIKE '%MONTEVIDEO%' 
          AND sl.DESC_SUCURSAL NOT LIKE '%NUEVOCENTRO%' 
          AND sl.DESC_SUCURSAL NOT LIKE '%TRES CRUCES%'
          AND sl.DESC_SUCURSAL NOT LIKE '%TASKY%'
          AND sl.DESC_SUCURSAL NOT LIKE '%CASA CENTRAL%'
          AND sl.DESC_SUCURSAL NOT LIKE '%CONS. FINAL%'
          AND sl.DESC_SUCURSAL NOT LIKE '%RURAL%'
          AND sl.DESC_SUCURSAL NOT LIKE '%HAEDO%'
    ";
    $maestroRows = $query($sqlSucMaestro);
    $sucMap = [];
    $deshabilitadas = [];
    $uyNros = [];

    foreach ($maestroRows as $mr) {
        $nro = (int)$mr['nro'];
        $nom = trim($mr['nombre'] ?? '');
        $hab = (int)($mr['habilitado'] ?? 1);

        if (stripos($nom, 'MONTEVIDEO') !== false || stripos($nom, 'NUEVOCENTRO') !== false || stripos($nom, 'TRES CRUCES') !== false) {
            $uyNros[$nro] = true;
            continue;
        }

        if ($hab === 0 || stripos($nom, 'FLORES 2') !== false || stripos($nom, 'PALMAS DEL PILAR') !== false) {
            $deshabilitadas[$nro] = true;
            continue;
        }

        if ($nom !== '') {
            if ($nro === 1 || stripos($nom, 'DAFITI') !== false) {
                $nom = 'ECOMMERCE ML';
            }
            $sucMap[$nro] = $nom;
        }
    }

    // Facturación día a día para el año actual en el mes
    $sqlFactDiaSuc = "
        SELECT 
            s.NRO_SUCURS as nro,
            DAY(s.FECHA) as dia,
            ISNULL(SUM(s.IMPORTE), 0) as facturacion
        FROM BI_SALES_SUCURSALES s
        WHERE MONTH(s.FECHA) = ? AND YEAR(s.FECHA) = ?
          {$sucursalClause} {$rubroClause} {$canalClause}
        GROUP BY s.NRO_SUCURS, DAY(s.FECHA)
    ";
    $rowsFactDia = $query($sqlFactDiaSuc, array_merge([$mes, $anioSel], $sucursalParams, $rubroParams));

    // Facturación día a día para el año anterior en el mes
    $rowsFactDiaPrev = $query($sqlFactDiaSuc, array_merge([$mes, $anioPrevio], $sucursalParams, $rubroParams));

    // Objetivos día a día para el año actual en el mes
    $objSucClause = "";
    $paramsObjDia = [$mes, $anioSel];
    if ($sucursal !== null && $sucursal !== '' && $sucursal !== '%' && $sucursal !== 'Todas') {
        if (strpos($sucursal, ',') !== false) {
            $parts = array_map('intval', explode(',', $sucursal));
            $ph = implode(',', array_fill(0, count($parts), '?'));
            $objSucClause = "AND o.NRO_SUCURS IN ($ph)";
            $paramsObjDia = array_merge($paramsObjDia, $parts);
        } else {
            $objSucClause = "AND o.NRO_SUCURS = ?";
            $paramsObjDia[] = (int)$sucursal;
        }
    }
    $sqlObjDiaSuc = "
        SELECT 
            o.NRO_SUCURS as nro,
            DAY(o.FECHA) as dia,
            ISNULL(SUM(o.IMPORTE_OBJ), 0) as objetivo
        FROM dbo.BI_T_VENTAS_VS_OBJETIVOS_PROPIOS o
        WHERE MONTH(o.FECHA) = ? AND YEAR(o.FECHA) = ?
          {$objSucClause}
        GROUP BY o.NRO_SUCURS, DAY(o.FECHA)
    ";
    $rowsObjDia = $query($sqlObjDiaSuc, $paramsObjDia);

    // Indexar por sucursal y día
    $matrizDiaSuc = [];
    $factPrevBySucDia = [];
    foreach ($rowsFactDiaPrev as $r) {
        $nro = (int)$r['nro'];
        if (isset($uyNros[$nro]) || !isset($sucMap[$nro])) continue;
        $dia = (int)$r['dia'];
        $factPrevBySucDia[$nro][$dia] = (float)$r['facturacion'];
    }

    foreach ($rowsFactDia as $r) {
        $nro = (int)$r['nro'];
        if (isset($uyNros[$nro]) || !isset($sucMap[$nro])) continue;
        $dia = (int)$r['dia'];
        if (!isset($matrizDiaSuc[$nro])) $matrizDiaSuc[$nro] = [];
        if (!isset($matrizDiaSuc[$nro][$dia])) $matrizDiaSuc[$nro][$dia] = ['fact' => 0, 'obj' => 0, 'fact_prev' => 0];
        $matrizDiaSuc[$nro][$dia]['fact'] = (float)$r['facturacion'];
    }

    foreach ($rowsObjDia as $r) {
        $nro = (int)$r['nro'];
        if (isset($uyNros[$nro]) || !isset($sucMap[$nro])) continue;
        $dia = (int)$r['dia'];
        if (!isset($matrizDiaSuc[$nro])) $matrizDiaSuc[$nro] = [];
        if (!isset($matrizDiaSuc[$nro][$dia])) $matrizDiaSuc[$nro][$dia] = ['fact' => 0, 'obj' => 0, 'fact_prev' => 0];
        $matrizDiaSuc[$nro][$dia]['obj'] = (float)$r['objetivo'];
    }

    // Completar fact_prev mapeado con el día homólogo del año anterior
    foreach ($matrizDiaSuc as $nro => &$diasObj) {
        foreach ($diasObj as $diaAct => &$vals) {
            $diaPrev = $mapaHomologo[$diaAct] ?? $diaAct;
            $vals['fact_prev'] = $factPrevBySucDia[$nro][$diaPrev] ?? 0;
        }
    }
    unset($diasObj, $vals);

    // Función de ordenamiento: ECOMMERCE siempre primero, luego orden alfabético
    $sortEcommerceFirst = function($a, $b) {
        $nomA = is_array($a) ? ($a['nombre'] ?? '') : '';
        $nomB = is_array($b) ? ($b['nombre'] ?? '') : '';
        $isEcomA = (stripos($nomA, 'ECOMMERCE') !== false || stripos($nomA, 'ML FULL') !== false) ? 1 : 0;
        $isEcomB = (stripos($nomB, 'ECOMMERCE') !== false || stripos($nomB, 'ML FULL') !== false) ? 1 : 0;
        if ($isEcomA !== $isEcomB) {
            return $isEcomB <=> $isEcomA; // ECOMMERCE primero (1 antes que 0)
        }
        return strcmp($nomA, $nomB);
    };

    // Lista de sucursales que tuvieron ventas u objetivos
    $sucsList = [];
    foreach ($matrizDiaSuc as $nro => $diasData) {
        if (isset($uyNros[$nro])) continue;
        $sucsList[] = [
            'nro'    => $nro,
            'nombre' => $sucMap[$nro] ?? ('Sucursal ' . $nro)
        ];
    }
    usort($sucsList, $sortEcommerceFirst);

    $response['sucursales_lista'] = $sucsList;
    $response['matriz_diaria'] = $matrizDiaSuc;

    // =========================================================================
    // 4. COMPARATIVA MULTI-AÑO (Facturación, Unidades y Tickets según Evento Especial)
    // =========================================================================
    // Venta por Sucursal y por Rubro según el evento seleccionado
    $sqlCampanaVentas = "
        SELECT 
            YEAR(s.FECHA) as anio,
            s.NRO_SUCURS as nro,
            s.RUBRO,
            ISNULL(SUM(s.IMPORTE), 0) as facturacion,
            ISNULL(SUM(CASE WHEN s.RUBRO NOT IN ('CONCEPTO','PACKAGING') THEN s.CANTIDAD ELSE 0 END), 0) as unidades
        FROM BI_SALES_SUCURSALES s
        WHERE MONTH(s.FECHA) = ? 
          AND YEAR(s.FECHA) IN (?, ?, ?)
          {$eventoDiaConditionS}
          {$sucursalClause} {$rubroClause} {$canalClause}
        GROUP BY YEAR(s.FECHA), s.NRO_SUCURS, s.RUBRO
    ";
    $paramsCamp = array_merge([$mes, $anios[0], $anios[1], $anios[2]], $sucursalParams, $rubroParams);
    $rowsCampVentas = $query($sqlCampanaVentas, $paramsCamp);

    // Stock actual de locales (por sucursal y rubro)
    $sqlStock = "
        SELECT 
            s.NRO_SUCURSAL as nro,
            s.RUBRO,
            ISNULL(SUM(s.CANT_STOCK), 0) as stock
        FROM SJ_BI_STOCK_LOCALES s
        WHERE s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
        GROUP BY s.NRO_SUCURSAL, s.RUBRO
    ";
    $rowsStock = $query($sqlStock);
    $stockIndex = [];
    $stockTotalSuc = [];
    foreach ($rowsStock as $st) {
        $n = (int)$st['nro'];
        if (isset($uyNros[$n])) continue;
        $ru = trim($st['RUBRO']);
        $k = $n . '|' . $ru;
        $stockIndex[$k] = (float)$st['stock'];
        $stockTotalSuc[$n] = ($stockTotalSuc[$n] ?? 0) + (float)$st['stock'];
    }

    // Objetivo del mes completo por sucursal
    $sqlObjMes = "
        SELECT 
            o.NRO_SUCURS as nro,
            ISNULL(SUM(o.IMPORTE_OBJ), 0) as obj_mes
        FROM dbo.BI_T_VENTAS_VS_OBJETIVOS_PROPIOS o
        WHERE MONTH(o.FECHA) = ? AND YEAR(o.FECHA) = ?
        GROUP BY o.NRO_SUCURS
    ";
    $rowsObjMes = $query($sqlObjMes, [$mes, $anioSel]);
    $objMesIndex = [];
    foreach ($rowsObjMes as $om) {
        $n = (int)$om['nro'];
        if (isset($uyNros[$n])) continue;
        $objMesIndex[$n] = (float)$om['obj_mes'];
    }

    // Armado estructurado de Sucursal -> Totales y Rubros
    $sucursalComparativa = [];
    foreach ($rowsCampVentas as $r) {
        $a = (int)$r['anio'];
        $n = (int)$r['nro'];
        if (isset($uyNros[$n]) || !isset($sucMap[$n])) continue;
        $ru = trim($r['RUBRO']);
        $fact = (float)$r['facturacion'];
        $unid = (float)$r['unidades'];

        if (!isset($sucursalComparativa[$n])) {
            $sucursalComparativa[$n] = [
                'nro' => $n,
                'nombre' => $sucMap[$n] ?? ('Suc. ' . $n),
                'obj_octubre' => $objMesIndex[$n] ?? 0,
                'stock_actual' => $stockTotalSuc[$n] ?? 0,
                'fact_anios' => [$anios[0] => 0, $anios[1] => 0, $anios[2] => 0],
                'unid_anios' => [$anios[0] => 0, $anios[1] => 0, $anios[2] => 0],
                'rubros' => []
            ];
        }

        $sucursalComparativa[$n]['fact_anios'][$a] += $fact;
        $sucursalComparativa[$n]['unid_anios'][$a] += $unid;

        if (!isset($sucursalComparativa[$n]['rubros'][$ru])) {
            $sucursalComparativa[$n]['rubros'][$ru] = [
                'rubro' => $ru,
                'stock' => $stockIndex[$n . '|' . $ru] ?? 0,
                'fact_anios' => [$anios[0] => 0, $anios[1] => 0, $anios[2] => 0],
                'unid_anios' => [$anios[0] => 0, $anios[1] => 0, $anios[2] => 0]
            ];
        }
        $sucursalComparativa[$n]['rubros'][$ru]['fact_anios'][$a] += $fact;
        $sucursalComparativa[$n]['rubros'][$ru]['unid_anios'][$a] += $unid;
    }

    // Tickets por sucursal según evento por año
    $ticketSucClause = "";
    $ticketSucParams = [];
    if ($sucursal !== null && $sucursal !== '' && $sucursal !== '%' && $sucursal !== 'Todas') {
        if (strpos($sucursal, ',') !== false) {
            $parts = array_map('intval', explode(',', $sucursal));
            $ph = implode(',', array_fill(0, count($parts), '?'));
            $ticketSucClause = "AND t.NRO_SUCURS IN ($ph)";
            $ticketSucParams = $parts;
        } else {
            $ticketSucClause = "AND t.NRO_SUCURS = ?";
            $ticketSucParams = [(int)$sucursal];
        }
    }

    $sqlTickets = "
        SELECT 
            YEAR(t.FECHA) as anio,
            t.NRO_SUCURS as nro,
            COUNT(DISTINCT t.N_COMP) as tickets
        FROM BI_SALES_TOTAL_TICKETS t
        WHERE MONTH(t.FECHA) = ? 
          AND YEAR(t.FECHA) IN (?, ?, ?)
          AND t.T_COMP = 'FAC'
          {$eventoDiaConditionT}
          {$ticketSucClause}
        GROUP BY YEAR(t.FECHA), t.NRO_SUCURS
    ";
    $paramsTickets = array_merge([$mes, $anios[0], $anios[1], $anios[2]], $ticketSucParams);
    $rowsTickets = $query($sqlTickets, $paramsTickets);
    $ticketsIndex = [];
    foreach ($rowsTickets as $t) {
        $a = (int)$t['anio'];
        $n = (int)$t['nro'];
        if (isset($uyNros[$n]) || !isset($sucMap[$n])) continue;
        if (!isset($ticketsIndex[$n])) {
            $ticketsIndex[$n] = [$anios[0] => 0, $anios[1] => 0, $anios[2] => 0];
        }
        $ticketsIndex[$n][$a] = (int)$t['tickets'];
    }

    $compList = array_values($sucursalComparativa);
    usort($compList, $sortEcommerceFirst);

    $response['comparativa_sucursales'] = $compList;
    $response['tickets_sucursales'] = $ticketsIndex;

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
