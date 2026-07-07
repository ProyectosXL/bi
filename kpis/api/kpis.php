<?php
/**
 * API de KPIs del tablero bi/kpis — Completados vs Plan y % Cumplimiento SLA.
 * Endpoint aislado: no modifica adminProyectos/api/estadisticas.php.
 * A diferencia de ese endpoint (año + mes único), este acepta un rango de
 * períodos por mes (fecha_desde / fecha_hasta en formato YYYY-MM).
 */

header('Content-Type: application/json; charset=UTF-8');

ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/../../class/conexion.php';

    $conexion = new Conexion();
    $cid = $conexion->conectar('central');
    if (!$cid) {
        throw new Exception('Error de conexión: ' . print_r(sqlsrv_errors(), true));
    }

    // RO_T_TICKETS_PROYECTOS vive en el servidor 'apps' (base 'sistemas'), no en 'central'.
    $cidApps = $conexion->conectar('apps');
    if (!$cidApps) {
        throw new Exception('Error de conexión (apps): ' . print_r(sqlsrv_errors(), true));
    }
    asegurarTablaTickets($cidApps);

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    list($fechaDesde, $fechaHastaExclusivo) = obtenerRangoFechas(
        isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '',
        isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : ''
    );

    switch ($action) {
        case 'kpi-resumen':
            $resumenProyectos = obtenerKPIResumenDatos($cid, $fechaDesde, $fechaHastaExclusivo);
            $resumenTickets   = obtenerKPIResumenTickets($cidApps, $fechaDesde, $fechaHastaExclusivo);
            $resumenProcesos  = obtenerKPIResumenProcesos($cidApps, $fechaDesde, $fechaHastaExclusivo);
            echo json_encode(array_merge(array('success' => true), $resumenProyectos, $resumenTickets, $resumenProcesos));
            break;

        case 'kpi-detalle':
            $tipo = isset($_GET['tipo']) ? $_GET['tipo'] : '';
            if ($tipo === 'tickets-sla') {
                obtenerKPIDetalleTickets($cidApps, $fechaDesde, $fechaHastaExclusivo);
            } elseif ($tipo === 'procesos') {
                obtenerKPIDetalleProcesos($cidApps, $fechaDesde, $fechaHastaExclusivo);
            } else {
                obtenerKPIDetalle($cid, $tipo, $fechaDesde, $fechaHastaExclusivo);
            }
            break;

        case 'importar-tickets':
            $body = json_decode(file_get_contents('php://input'), true);
            if (!is_array($body)) {
                http_response_code(400);
                echo json_encode(array('success' => false, 'error' => 'JSON inválido o vacío'));
                break;
            }
            importarTickets($cidApps, $body);
            break;

        default:
            http_response_code(400);
            echo json_encode(array('error' => 'Acción no válida'));
    }
} catch (Exception $e) {
    error_log('Error en bi/kpis/api/kpis.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(array('error' => $e->getMessage()));
}

/**
 * Convierte fecha_desde/fecha_hasta (YYYY-MM) en un rango [inicio, finExclusivo).
 * Ante un valor faltante o inválido, usa el mes actual.
 */
function obtenerRangoFechas($fechaDesde, $fechaHasta) {
    $mesActual = date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $fechaDesde)) $fechaDesde = $mesActual;
    if (!preg_match('/^\d{4}-\d{2}$/', $fechaHasta))  $fechaHasta  = $mesActual;

    $desde          = $fechaDesde . '-01';
    $hastaExclusivo = date('Y-m-d', strtotime($fechaHasta . '-01 +1 month'));

    return array($desde, $hastaExclusivo);
}

/**
 * KPI 1 (Completados vs Plan) y KPI 2 (% Cumplimiento SLA), calcados de
 * obtenerKPIResumen() en adminProyectos/api/estadisticas.php, con el filtro
 * de año/mes reemplazado por un rango de fechas.
 */
function obtenerKPIResumenDatos($cid, $desde, $hastaExclusivo) {
    $sql = "SELECT
                COUNT(*) AS total_plan,
                SUM(CASE WHEN Estado = 'Terminado' AND FinalizacionReal IS NOT NULL
                         THEN 1 ELSE 0 END) AS completados,
                SUM(CASE WHEN Estado = 'Terminado' AND FinalizacionReal IS NOT NULL
                              AND FinalizacionReal <= FechaLimite
                         THEN 1 ELSE 0 END) AS a_tiempo
            FROM HistorialProyectos
            WHERE FechaLimite >= ? AND FechaLimite < ?
              AND (Estado IS NULL OR Estado <> 'Pausado')";
    $params = array($desde, $hastaExclusivo);

    $stmt = sqlsrv_query($cid, $sql, $params);
    if ($stmt === false) throw new Exception('Error KPI: ' . print_r(sqlsrv_errors(), true));

    $row         = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $totalPlan   = intval($row['total_plan']);
    $completados = intval($row['completados']);
    $aTiempo     = intval($row['a_tiempo']);

    $pctCompletados = $totalPlan   > 0 ? round(($completados / $totalPlan)   * 100, 1) : null;
    $pctSLA         = $completados > 0 ? round(($aTiempo     / $completados) * 100, 1) : null;

    return array(
        'total_plan'      => $totalPlan,
        'completados'     => $completados,
        'a_tiempo'        => $aTiempo,
        'pct_completados' => $pctCompletados,
        'pct_sla'         => $pctSLA
    );
}

/**
 * Crea RO_T_TICKETS_PROYECTOS si todavía no existe (base 'apps'/'sistemas').
 * Mismo patrón idempotente que RO_T_DEBUG_TIMING en global/api/kpis.php.
 */
function asegurarTablaTickets($cidApps) {
    $sql = "IF OBJECT_ID('dbo.RO_T_TICKETS_PROYECTOS','U') IS NULL
            CREATE TABLE dbo.RO_T_TICKETS_PROYECTOS
            (
                ID                   INT IDENTITY(1,1) PRIMARY KEY,
                FECHA_TAREA          DATETIME     NULL,
                TICKET               INT          NOT NULL,
                FECHA_CIERRE         DATETIME     NULL,
                CERRADO              BIT          NOT NULL,
                AREA                 VARCHAR(100) NULL,
                USUARIO_ASIGNADO     VARCHAR(150) NULL,
                CONTACTOS            VARCHAR(255) NULL,
                TIPO                 VARCHAR(100) NULL,
                FECHA_ACTUALIZACION  DATETIME     NOT NULL DEFAULT(GETDATE())
            )";
    $stmt = sqlsrv_query($cidApps, $sql);
    if ($stmt === false) {
        throw new Exception('No se pudo verificar/crear RO_T_TICKETS_PROYECTOS: ' . print_r(sqlsrv_errors(), true));
    }
}

/**
 * Fragmento SQL compartido: horas hábiles entre FECHA_TAREA y FECHA_CIERRE,
 * excluyendo fines de semana y feriados vía RO_T_CALENDARIO (base POWER_BI_CONTROL,
 * mismo servidor 'apps'; DIA_LABORAL=0 en sábados/domingos/feriados). Para el día de
 * inicio y el de cierre solo cuenta la porción de ese día dentro del intervalo; los
 * días intermedios no laborales aportan 0 horas.
 */
function sqlHorasHabilesTickets() {
    return "SELECT
                t.TICKET, t.FECHA_TAREA, t.FECHA_CIERRE,
                t.AREA, t.USUARIO_ASIGNADO, t.CONTACTOS, t.TIPO,
                SUM(CASE WHEN cal.DIA_LABORAL = 1 THEN
                        DATEDIFF(SECOND,
                            CASE WHEN cal.FECHA = CAST(t.FECHA_TAREA AS DATE) THEN t.FECHA_TAREA ELSE CAST(cal.FECHA AS DATETIME) END,
                            CASE WHEN cal.FECHA = CAST(t.FECHA_CIERRE AS DATE) THEN t.FECHA_CIERRE ELSE DATEADD(DAY, 1, CAST(cal.FECHA AS DATETIME)) END
                        )
                    ELSE 0 END) / 3600.0 AS HorasHabiles
            FROM dbo.RO_T_TICKETS_PROYECTOS t
            CROSS APPLY (
                SELECT FECHA, DIA_LABORAL
                FROM [POWER_BI_CONTROL].dbo.RO_T_CALENDARIO
                WHERE FECHA BETWEEN CAST(t.FECHA_TAREA AS DATE) AND CAST(t.FECHA_CIERRE AS DATE)
            ) cal
            WHERE t.CERRADO = 1 AND t.FECHA_TAREA >= ? AND t.FECHA_TAREA < ?
            GROUP BY t.TICKET, t.FECHA_TAREA, t.FECHA_CIERRE, t.AREA, t.USUARIO_ASIGNADO, t.CONTACTOS, t.TIPO";
}

/**
 * KPI 3 (% Tickets dentro de SLA) — tickets cerrados cuya Fecha Tarea cae en
 * el rango seleccionado. SLA fijo de 48 horas hábiles (excluyendo fines de semana
 * y feriados) entre FECHA_TAREA y FECHA_CIERRE, igual para todos los TIPO.
 * No se filtra por AREA (la tabla es solo de Innovación).
 */
function obtenerKPIResumenTickets($cidApps, $desde, $hastaExclusivo) {
    $sql = "WITH TicketsHoras AS (" . sqlHorasHabilesTickets() . ")
            SELECT
                COUNT(*) AS total_tickets,
                SUM(CASE WHEN HorasHabiles <= 48 THEN 1 ELSE 0 END) AS tickets_ok
            FROM TicketsHoras";
    $params = array($desde, $hastaExclusivo);

    $stmt = sqlsrv_query($cidApps, $sql, $params);
    if ($stmt === false) throw new Exception('Error KPI tickets: ' . print_r(sqlsrv_errors(), true));

    $row          = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $totalTickets = intval($row['total_tickets']);
    $ticketsOk    = intval($row['tickets_ok']);
    $pctTickets   = $totalTickets > 0 ? round(($ticketsOk / $totalTickets) * 100, 1) : null;

    return array(
        'total_tickets'   => $totalTickets,
        'tickets_ok'      => $ticketsOk,
        'pct_tickets_sla' => $pctTickets
    );
}

/**
 * Detalle de tickets para el drill-down "Ver tickets" del KPI 3.
 */
function obtenerKPIDetalleTickets($cidApps, $desde, $hastaExclusivo) {
    $sql = "WITH TicketsHoras AS (" . sqlHorasHabilesTickets() . ")
            SELECT
                TICKET,
                CONVERT(varchar(19), FECHA_TAREA, 120)  AS FechaTareaStr,
                CONVERT(varchar(19), FECHA_CIERRE, 120) AS FechaCierreStr,
                ROUND(HorasHabiles, 1) AS HorasHabiles,
                AREA, USUARIO_ASIGNADO, CONTACTOS, TIPO,
                CASE WHEN HorasHabiles <= 48 THEN 1 ELSE 0 END AS CumpleSLA
            FROM TicketsHoras
            ORDER BY FECHA_TAREA ASC";
    $params = array($desde, $hastaExclusivo);

    $stmt = sqlsrv_query($cidApps, $sql, $params);
    if ($stmt === false) throw new Exception('Error detalle tickets: ' . print_r(sqlsrv_errors(), true));

    $tickets = array();
    $cumplen = 0;

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $cumple = intval($row['CumpleSLA']);
        if ($cumple) $cumplen++;

        $tickets[] = array(
            'ticket'           => intval($row['TICKET']),
            'fecha_tarea'      => $row['FechaTareaStr'],
            'fecha_cierre'     => $row['FechaCierreStr'],
            'horas'            => $row['HorasHabiles'] !== null ? floatval($row['HorasHabiles']) : null,
            'area'             => $row['AREA'],
            'usuario_asignado' => $row['USUARIO_ASIGNADO'],
            'contactos'        => $row['CONTACTOS'],
            'tipo'             => $row['TIPO'],
            'cumple_sla'       => $cumple
        );
    }

    echo json_encode(array(
        'success' => true,
        'tipo'    => 'tickets-sla',
        'tickets' => $tickets,
        'total'   => count($tickets),
        'cumplen' => $cumplen
    ));
}

/**
 * Cantidad de meses cubiertos por [desde, hastaExclusivo). Ambos vienen como
 * fechas con día 01 (ver obtenerRangoFechas), así que la diferencia en meses
 * entre ellas da exactamente la cantidad de meses del rango seleccionado.
 */
function mesesEnRango($desde, $hastaExclusivo) {
    $d1 = new DateTime($desde);
    $d2 = new DateTime($hastaExclusivo);
    $diff = $d1->diff($d2);
    return max(1, $diff->y * 12 + $diff->m);
}

/**
 * KPI 4 (% Procesos Relevados vs Plan) — procesos con score_actual >= 2.0
 * (ya "Gestionado"/documentado, no informal) cuya Fecha de Relevado
 * (created_at) cae en el rango seleccionado. Plan fijo: 2 procesos/mes.
 * No se filtra por estado ni por área/responsable (toda la tabla es del
 * trabajo de relevamiento de Innovación).
 */
function obtenerKPIResumenProcesos($cidApps, $desde, $hastaExclusivo) {
    $sql = "SELECT COUNT(*) AS total_procesos
            FROM dbo.FP_PROCESSES
            WHERE score_actual >= 2.0 AND created_at >= ? AND created_at < ?";
    $params = array($desde, $hastaExclusivo);

    $stmt = sqlsrv_query($cidApps, $sql, $params);
    if ($stmt === false) throw new Exception('Error KPI procesos: ' . print_r(sqlsrv_errors(), true));

    $row           = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    $totalProcesos = intval($row['total_procesos']);
    $mesesRango    = mesesEnRango($desde, $hastaExclusivo);
    $objetivo      = 2 * $mesesRango;
    $pctProcesos   = $objetivo > 0 ? round(($totalProcesos / $objetivo) * 100, 1) : null;

    return array(
        'total_procesos'    => $totalProcesos,
        'meses_rango'       => $mesesRango,
        'objetivo_procesos' => $objetivo,
        'pct_procesos'      => $pctProcesos
    );
}

/**
 * Detalle de procesos para el drill-down "Ver procesos" del KPI 4. A diferencia
 * del resumen, acá se listan TODOS los procesos creados en el rango (sin filtrar
 * por score_actual) para dar contexto completo; cada fila indica si cuenta o no
 * para el KPI (relevado = score_actual >= 2.0).
 */
function obtenerKPIDetalleProcesos($cidApps, $desde, $hastaExclusivo) {
    $sql = "SELECT
                codigo, nombre, area, subarea, categoria, criticidad, score_actual,
                CONVERT(varchar(19), created_at, 120) AS CreatedAtStr
            FROM dbo.FP_PROCESSES
            WHERE created_at >= ? AND created_at < ?
            ORDER BY created_at ASC";
    $params = array($desde, $hastaExclusivo);

    $stmt = sqlsrv_query($cidApps, $sql, $params);
    if ($stmt === false) throw new Exception('Error detalle procesos: ' . print_r(sqlsrv_errors(), true));

    $procesos = array();
    $cumplen  = 0;

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $score     = $row['score_actual'] !== null ? floatval($row['score_actual']) : null;
        $relevado  = ($score !== null && $score >= 2.0) ? 1 : 0;
        if ($relevado) $cumplen++;

        $procesos[] = array(
            'codigo'         => $row['codigo'],
            'nombre'         => $row['nombre'],
            'area'           => $row['area'],
            'subarea'        => $row['subarea'],
            'categoria'      => $row['categoria'],
            'criticidad'     => $row['criticidad'] !== null ? intval($row['criticidad']) : null,
            'score_actual'   => $score,
            'created_at'     => $row['CreatedAtStr'],
            'relevado'       => $relevado
        );
    }

    echo json_encode(array(
        'success'  => true,
        'tipo'     => 'procesos',
        'procesos' => $procesos,
        'total'    => count($procesos),
        'cumplen'  => $cumplen
    ));
}

/**
 * Importa/actualiza tickets desde el Excel parseado en el cliente (upsert por TICKET).
 * $filas: array de objetos con claves FECHA_TAREA, TICKET, FECHA_CIERRE, CERRADO,
 * AREA, USUARIO_ASIGNADO, CONTACTOS, TIPO (ya normalizadas por kpis.js).
 */
function importarTickets($cidApps, $filas) {
    $validas = array();
    $errores = array();

    foreach ($filas as $i => $fila) {
        $nroFila = $i + 1;
        $ticket  = isset($fila['TICKET']) ? $fila['TICKET'] : null;
        if ($ticket === null || $ticket === '' || !is_numeric($ticket)) {
            $errores[] = array('fila' => $nroFila, 'ticket' => $ticket, 'motivo' => 'TICKET vacío o no numérico');
            continue;
        }
        $validas[] = array(
            'ticket'           => intval($ticket),
            'fecha_tarea'      => (isset($fila['FECHA_TAREA'])  && $fila['FECHA_TAREA']  !== '') ? $fila['FECHA_TAREA']  : null,
            'fecha_cierre'     => (isset($fila['FECHA_CIERRE']) && $fila['FECHA_CIERRE'] !== '') ? $fila['FECHA_CIERRE'] : null,
            'cerrado'          => isset($fila['CERRADO']) ? intval($fila['CERRADO']) : 0,
            'area'             => isset($fila['AREA']) ? $fila['AREA'] : null,
            'usuario_asignado' => isset($fila['USUARIO_ASIGNADO']) ? $fila['USUARIO_ASIGNADO'] : null,
            'contactos'        => isset($fila['CONTACTOS']) ? $fila['CONTACTOS'] : null,
            'tipo'             => isset($fila['TIPO']) ? $fila['TIPO'] : null,
        );
    }

    $insertados   = 0;
    $actualizados = 0;

    if (count($validas) > 0) {
        if (!sqlsrv_begin_transaction($cidApps)) {
            throw new Exception('No se pudo iniciar la transacción: ' . print_r(sqlsrv_errors(), true));
        }
        try {
            foreach ($validas as $v) {
                $stmtSel = sqlsrv_query($cidApps, "SELECT ID FROM dbo.RO_T_TICKETS_PROYECTOS WHERE TICKET = ?", array($v['ticket']));
                if ($stmtSel === false) {
                    throw new Exception('Error verificando TICKET ' . $v['ticket'] . ': ' . print_r(sqlsrv_errors(), true));
                }
                $existe = sqlsrv_fetch_array($stmtSel, SQLSRV_FETCH_ASSOC);

                if ($existe) {
                    $stmt = sqlsrv_query($cidApps,
                        "UPDATE dbo.RO_T_TICKETS_PROYECTOS
                            SET FECHA_TAREA = ?, FECHA_CIERRE = ?, CERRADO = ?, AREA = ?,
                                USUARIO_ASIGNADO = ?, CONTACTOS = ?, TIPO = ?, FECHA_ACTUALIZACION = GETDATE()
                          WHERE TICKET = ?",
                        array($v['fecha_tarea'], $v['fecha_cierre'], $v['cerrado'], $v['area'],
                              $v['usuario_asignado'], $v['contactos'], $v['tipo'], $v['ticket'])
                    );
                    if ($stmt === false) {
                        throw new Exception('Error actualizando TICKET ' . $v['ticket'] . ': ' . print_r(sqlsrv_errors(), true));
                    }
                    $actualizados++;
                } else {
                    $stmt = sqlsrv_query($cidApps,
                        "INSERT INTO dbo.RO_T_TICKETS_PROYECTOS
                            (FECHA_TAREA, TICKET, FECHA_CIERRE, CERRADO, AREA, USUARIO_ASIGNADO, CONTACTOS, TIPO, FECHA_ACTUALIZACION)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, GETDATE())",
                        array($v['fecha_tarea'], $v['ticket'], $v['fecha_cierre'], $v['cerrado'], $v['area'],
                              $v['usuario_asignado'], $v['contactos'], $v['tipo'])
                    );
                    if ($stmt === false) {
                        throw new Exception('Error insertando TICKET ' . $v['ticket'] . ': ' . print_r(sqlsrv_errors(), true));
                    }
                    $insertados++;
                }
            }
            sqlsrv_commit($cidApps);
        } catch (Exception $e) {
            sqlsrv_rollback($cidApps);
            throw $e;
        }
    }

    echo json_encode(array(
        'success'      => true,
        'total_filas'  => count($filas),
        'insertados'   => $insertados,
        'actualizados' => $actualizados,
        'omitidos'     => count($errores),
        'errores'      => $errores
    ));
}

/**
 * Detalle de proyectos para el drill-down "Ver proyectos", calcado de
 * obtenerKPIDetalle() en adminProyectos/api/estadisticas.php.
 * tipo='completados': universo del rango, clasificado Completado/No completado.
 * tipo='sla': solo los completados del rango, clasificado A tiempo/Tarde.
 */
function obtenerKPIDetalle($cid, $tipo, $desde, $hastaExclusivo) {
    if ($tipo !== 'completados' && $tipo !== 'sla') {
        throw new Exception('Tipo de detalle inválido');
    }

    $sql = "SELECT
                ID,
                Desarrollo,
                Sector,
                Desarrollador,
                CONVERT(varchar(10), FechaLimite, 23)      AS FechaLimiteStr,
                CONVERT(varchar(10), FinalizacionReal, 23) AS FinalizacionRealStr,
                Estado,
                CASE WHEN Estado = 'Terminado' AND FinalizacionReal IS NOT NULL
                          AND FinalizacionReal <= FechaLimite THEN 1 ELSE 0 END AS aTiempo
            FROM HistorialProyectos
            WHERE FechaLimite >= ? AND FechaLimite < ?";
    $params = array($desde, $hastaExclusivo);

    if ($tipo === 'completados') {
        $sql .= " AND (Estado IS NULL OR Estado <> 'Pausado')";
    } else {
        $sql .= " AND Estado = 'Terminado' AND FinalizacionReal IS NOT NULL";
    }
    $sql .= " ORDER BY FechaLimite ASC";

    $stmt = sqlsrv_query($cid, $sql, $params);
    if ($stmt === false) throw new Exception('Error KPI detalle: ' . print_r(sqlsrv_errors(), true));

    $proyectos = array();
    $cumplen   = 0;

    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $fechaLim = $row['FechaLimiteStr'] ?: '-';
        $finReal  = $row['FinalizacionRealStr'] !== null ? $row['FinalizacionRealStr'] : '-';

        if ($tipo === 'completados') {
            $esComp        = ($row['Estado'] === 'Terminado' && $row['FinalizacionRealStr'] !== null && $row['FinalizacionRealStr'] !== '');
            $clasificacion = $esComp ? 'Completado' : 'No completado';
        } else {
            $clasificacion = intval($row['aTiempo']) ? 'A tiempo' : 'Tarde';
        }

        if ($clasificacion === 'Completado' || $clasificacion === 'A tiempo') $cumplen++;

        $proyectos[] = array(
            'id'                => intval($row['ID']),
            'desarrollo'        => $row['Desarrollo'],
            'sector'            => $row['Sector'],
            'desarrollador'     => $row['Desarrollador'],
            'fecha_limite'      => $fechaLim,
            'finalizacion_real' => $finReal,
            'estado'            => $row['Estado'],
            'clasificacion'     => $clasificacion
        );
    }

    echo json_encode(array(
        'success'   => true,
        'tipo'      => $tipo,
        'proyectos' => $proyectos,
        'total'     => count($proyectos),
        'cumplen'   => $cumplen
    ));
}
