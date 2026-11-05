<?php
/**
 * KPI "Ahorro / Beneficio Generado" — funciones de ahorros, parámetros e
 * historial. Las incluye api/kpis.php, que ya autorizó la acción
 * (AuthKpis::exigirAccion) antes de llamar a cualquiera de estas funciones.
 *
 * Bases:
 *   $cidApps (apps/sistemas): RO_T_KPI_* y FP_SECTORES.
 *   $cid     (central):       CPA04/CPA01/CPA21 (Tango) y HistorialProyectos.
 *
 * Meses: se trabajan como índice entero (año * 12 + mes - 1) para sumar y
 * comparar sin fechas; 'YYYY-MM' solo para mostrar.
 */

if (!defined('KPIS_API')) {
    http_response_code(404);
    exit;
}

/* ════════════════════════════════════════════════════════════════════════
   Helpers
   ════════════════════════════════════════════════════════════════════════ */

function ahorrosQuery($cid, $sql, $params = array(), $contexto = 'consulta') {
    $stmt = sqlsrv_query($cid, $sql, $params);
    if ($stmt === false) {
        throw new Exception('Error en ' . $contexto . ': ' . print_r(sqlsrv_errors(), true));
    }
    return $stmt;
}

function ahorrosFilas($cid, $sql, $params = array(), $contexto = 'consulta') {
    $stmt = ahorrosQuery($cid, $sql, $params, $contexto);
    $filas = array();
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $filas[] = $row;
    }
    return $filas;
}

/** 'YYYY-MM-DD' (o 'YYYY-MM') -> índice de mes. */
function mesIdx($fecha) {
    return intval(substr($fecha, 0, 4)) * 12 + intval(substr($fecha, 5, 2)) - 1;
}

function mesTexto($idx) {
    return sprintf('%04d-%02d', intdiv($idx, 12), $idx % 12 + 1);
}

function fechaValida($valor) {
    if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) return false;
    list($a, $m, $d) = array_map('intval', explode('-', $valor));
    return checkdate($m, $d, $a);
}

/** Respuesta de validación: 422 con la lista de lo que falta o está mal. */
function responderErroresValidacion($errores) {
    http_response_code(422);
    echo json_encode(array(
        'success' => false,
        'error'   => 'Revisá los datos: ' . implode(' ', $errores),
        'errores' => $errores
    ));
}

function leerCuerpoJson() {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'error' => 'El pedido llegó vacío o con un formato inválido.'));
        return null;
    }
    return $body;
}

/* ════════════════════════════════════════════════════════════════════════
   Parámetros
   ════════════════════════════════════════════════════════════════════════ */

/** Parámetros activos como CLAVE => VALOR (string). */
function obtenerParametros($cidApps) {
    $filas = ahorrosFilas($cidApps,
        "SELECT CLAVE, VALOR FROM dbo.RO_T_KPI_PARAMETROS WHERE ACTIVO = 1", array(), 'parámetros');
    $out = array();
    foreach ($filas as $f) $out[$f['CLAVE']] = $f['VALOR'];
    return $out;
}

function parametroNumero($params, $clave) {
    return isset($params[$clave]) && is_numeric($params[$clave]) ? floatval($params[$clave]) : 0.0;
}

/** Lista de T_COMP excluidos del cálculo de baja de proveedor (TANGO_TCOMP_EXCLUIDOS). */
function tcompExcluidos($params) {
    $lista = isset($params['TANGO_TCOMP_EXCLUIDOS']) ? explode(',', $params['TANGO_TCOMP_EXCLUIDOS']) : array();
    return array_values(array_filter(array_map(function ($t) { return strtoupper(trim($t)); }, $lista), 'strlen'));
}

/* ════════════════════════════════════════════════════════════════════════
   Cálculo "Baja de proveedor" (CPA04 + CPA01 + CPA21, base central)

   Comprobantes: clases F / NC / ND de CPA21, salvo los T_COMP de
   TANGO_TCOMP_EXCLUIDOS. El signo sale de CPA21.CRE_DEB (C resta). Las O/P no
   están en CPA21. Se excluye ESTADO = 'ANU' (Tango borra los comprobantes
   anulados de CPA04; el filtro es una defensa).

   Importe en pesos:
     COND_IVA = 'RS'  -> IMPORTE_TO (ya en pesos aun en moneda extranjera).
     otro             -> (IMPORTE_NE + IMPORTE_EX), sin IVA ni percepciones,
                         x COTIZACION si MON_CTE = 0 (esos importes están en la
                         moneda del comprobante).
   ════════════════════════════════════════════════════════════════════════ */

/**
 * Calcula el ahorro de un proveedor dado de baja.
 *
 * @param resource $cid       conexión central
 * @param string   $codProvee COD_PROVEE de Tango
 * @param string   $fechaBaja 'YYYY-MM-DD'
 * @param array    $excluidos T_COMP excluidos
 * @return array
 */
function calcularBajaProveedor($cid, $codProvee, $fechaBaja, $excluidos) {
    static $cache = array();
    $claveCache = $codProvee . '|' . $fechaBaja . '|' . implode(',', $excluidos);
    if (isset($cache[$claveCache])) return $cache[$claveCache];

    $anio        = intval(substr($fechaBaja, 0, 4));
    $inicioAnio  = sprintf('%04d-01-01', $anio);

    $filtroExcl = '';
    $params = array($codProvee, $inicioAnio);
    if (count($excluidos) > 0) {
        $filtroExcl = ' AND C.T_COMP NOT IN (' . implode(',', array_fill(0, count($excluidos), '?')) . ')';
        $params = array_merge($params, $excluidos);
    }

    // Todos los comprobantes del año de la baja en adelante: los anteriores a la
    // baja forman el universo; los posteriores son el aviso "Facturó después".
    $sql = "SELECT
                C.T_COMP, T.DESC_COMP, RTRIM(C.N_COMP) AS N_COMP,
                CONVERT(varchar(10), C.FECHA_EMIS, 23) AS FECHA_EMIS,
                C.MON_CTE, C.COTIZACION, T.CRE_DEB, PR.COND_IVA,
                (CASE WHEN PR.COND_IVA = 'RS' THEN C.IMPORTE_TO
                      ELSE (ISNULL(C.IMPORTE_NE, 0) + ISNULL(C.IMPORTE_EX, 0))
                           * CASE WHEN C.MON_CTE = 0 THEN C.COTIZACION ELSE 1 END
                 END) * CASE WHEN T.CRE_DEB = 'C' THEN -1 ELSE 1 END AS IMPORTE_PESOS
            FROM dbo.CPA04 C
            JOIN dbo.CPA21 T  ON T.T_COMP = C.T_COMP
            JOIN dbo.CPA01 PR ON PR.COD_PROVEE = C.COD_PROVEE
            WHERE C.COD_PROVEE = ?
              AND C.FECHA_EMIS >= ?
              AND T.CLASE_OPERACION IN ('F', 'NC', 'ND')
              AND ISNULL(C.ESTADO, '') <> 'ANU'" . $filtroExcl . "
            ORDER BY C.FECHA_EMIS, C.T_COMP, C.N_COMP";
    $filas = ahorrosFilas($cid, $sql, $params, 'comprobantes de ' . $codProvee);

    $prov = ahorrosFilas($cid, "SELECT RTRIM(COD_PROVEE) AS COD_PROVEE, RTRIM(NOM_PROVEE) AS NOM_PROVEE, COND_IVA
                                FROM dbo.CPA01 WHERE COD_PROVEE = ?", array($codProvee), 'proveedor');
    $nombre  = $prov ? $prov[0]['NOM_PROVEE'] : null;
    $condIva = $prov ? $prov[0]['COND_IVA'] : null;

    $total       = 0.0;
    $cantidad    = 0;
    $ultimaFecha = null;
    $posteriores = array();

    foreach ($filas as $f) {
        $importe = round(floatval($f['IMPORTE_PESOS']), 2);
        $comp = array(
            't_comp'      => $f['T_COMP'],
            'descripcion' => $f['DESC_COMP'],
            'n_comp'      => $f['N_COMP'],
            'fecha'       => $f['FECHA_EMIS'],
            'moneda_ext'  => intval($f['MON_CTE']) === 0,
            'cotizacion'  => floatval($f['COTIZACION']),
            'importe'     => $importe
        );
        if ($f['FECHA_EMIS'] < $fechaBaja) {
            $total += $importe;
            $cantidad++;
            $ultimaFecha = $f['FECHA_EMIS'];   // vienen ordenados por fecha
        } else {
            $posteriores[] = $comp;
        }
    }

    $avisos = array();
    if ($cantidad === 0) {
        $avisos[] = 'Sin facturación en el año previa a la baja';
        $resultado = array(
            'cod_provee'          => $codProvee,
            'nombre'              => $nombre,
            'cond_iva'            => $condIva,
            'con_iva'             => $condIva === 'RS',
            'fecha_baja'          => $fechaBaja,
            'existe'              => (bool) $prov,
            'comprobantes'        => 0,
            'total_neto'          => 0.0,
            'meses_considerados'  => 0,
            'ultimo_comprobante'  => null,
            'promedio_mensual'    => 0.0,
            'inicio_ahorro'       => null,
            'inicio_ahorro_idx'   => null,
            'meses_ahorro_anio'   => 0,
            'ahorro_proyectado_anio' => 0.0
        );
    } else {
        $idxUltimo  = mesIdx($ultimaFecha);
        $meses      = $idxUltimo - $anio * 12 + 1;          // enero..mes del último, inclusive
        $promedio   = round($total / $meses, 2);
        $idxInicio  = $idxUltimo + 1;                        // mes siguiente al último comprobante
        $mesesAhorroAnio = max(0, ($anio * 12 + 11) - $idxInicio + 1);
        $resultado = array(
            'cod_provee'          => $codProvee,
            'nombre'              => $nombre,
            'cond_iva'            => $condIva,
            'con_iva'             => $condIva === 'RS',
            'fecha_baja'          => $fechaBaja,
            'existe'              => (bool) $prov,
            'comprobantes'        => $cantidad,
            'total_neto'          => round($total, 2),
            'meses_considerados'  => $meses,
            'ultimo_comprobante'  => $ultimaFecha,
            'promedio_mensual'    => $promedio,
            'inicio_ahorro'       => mesTexto($idxInicio),
            'inicio_ahorro_idx'   => $idxInicio,
            'meses_ahorro_anio'   => $mesesAhorroAnio,
            'ahorro_proyectado_anio' => round($promedio * $mesesAhorroAnio, 2)
        );
    }

    if (count($posteriores) > 0) {
        $avisos[] = 'Facturó después de la baja';
    }
    $resultado['avisos'] = $avisos;
    $resultado['posteriores'] = $posteriores;

    $cache[$claveCache] = $resultado;
    return $resultado;
}

/* ════════════════════════════════════════════════════════════════════════
   Devengado por mes
   ════════════════════════════════════════════════════════════════════════ */

/**
 * Importe que aporta un ahorro en un mes (índice).
 *   UNICO:   el monto entero en el mes de FECHA_DESDE.
 *   MENSUAL: el monto mensual en cada mes entre desde y hasta (inclusive);
 *            en PROVEEDOR, cada proveedor desde su mes de inicio del ahorro.
 */
function importeAhorroEnMes($ahorro, $idx) {
    $idxDesde = mesIdx($ahorro['fecha_desde']);
    $idxHasta = mesIdx($ahorro['fecha_hasta']);

    if ($ahorro['periodicidad'] === 'UNICO') {
        return $idx === $idxDesde ? $ahorro['monto'] : 0.0;
    }
    if ($idx < $idxDesde || $idx > $idxHasta) return 0.0;

    if ($ahorro['modo'] === 'PROVEEDOR') {
        $suma = 0.0;
        foreach ($ahorro['proveedores'] as $p) {
            $calc = $p['calculo'];
            if ($calc['inicio_ahorro_idx'] !== null && $idx >= $calc['inicio_ahorro_idx']) {
                $suma += $calc['promedio_mensual'];
            }
        }
        return $suma;
    }
    return $ahorro['monto'];
}

function sumaAhorroMeses($ahorro, $idxDesde, $idxHasta) {
    $suma = 0.0;
    for ($i = $idxDesde; $i <= $idxHasta; $i++) $suma += importeAhorroEnMes($ahorro, $i);
    return round($suma, 2);
}

/* ════════════════════════════════════════════════════════════════════════
   Lectura de ahorros
   ════════════════════════════════════════════════════════════════════════ */

/**
 * Ahorros (activos, o también los dados de baja) con sus proveedores y el
 * cálculo de cada uno. Los montos de PROVEEDOR se calculan en cada consulta.
 */
function cargarAhorros($cidApps, $cid, $incluirBajas, $soloId = null) {
    $params = obtenerParametros($cidApps);
    $excluidos = tcompExcluidos($params);

    $where = $incluirBajas ? '1 = 1' : 'A.ACTIVO = 1';
    $sqlParams = array();
    if ($soloId !== null) {
        $where .= ' AND A.ID = ?';
        $sqlParams[] = $soloId;
    }

    $filas = ahorrosFilas($cidApps,
        "SELECT A.ID, A.DESCRIPCION, A.TIPO_ID, T.CODIGO AS TIPO_CODIGO, T.NOMBRE AS TIPO_NOMBRE, T.MODO_CALCULO,
                A.SECTOR_ID, S.nombre AS SECTOR_NOMBRE, A.PROYECTO_ID, A.PERIODICIDAD,
                CONVERT(varchar(10), A.FECHA_DESDE, 23) AS FECHA_DESDE,
                CONVERT(varchar(10), A.FECHA_HASTA, 23) AS FECHA_HASTA,
                A.MONTO, A.HORAS_MES, A.COSTO_HORA, A.OBSERVACIONES, A.ACTIVO,
                A.USUARIO_ALTA, CONVERT(varchar(19), A.FECHA_ALTA, 120) AS FECHA_ALTA,
                A.USUARIO_MODIF, CONVERT(varchar(19), A.FECHA_MODIF, 120) AS FECHA_MODIF,
                A.USUARIO_BAJA, CONVERT(varchar(19), A.FECHA_BAJA, 120) AS FECHA_BAJA
         FROM dbo.RO_T_KPI_AHORROS A
         JOIN dbo.RO_T_KPI_AHORRO_TIPOS T ON T.ID = A.TIPO_ID
         LEFT JOIN dbo.FP_SECTORES S ON S.id = A.SECTOR_ID
         WHERE $where
         ORDER BY A.ACTIVO DESC, A.FECHA_DESDE DESC, A.ID DESC", $sqlParams, 'ahorros');

    if (!$filas) return array();

    $ids = array_map(function ($f) { return intval($f['ID']); }, $filas);
    $provFilas = ahorrosFilas($cidApps,
        "SELECT AHORRO_ID, RTRIM(COD_PROVEE) AS COD_PROVEE,
                CONVERT(varchar(10), FECHA_BAJA_PROVEEDOR, 23) AS FECHA_BAJA_PROVEEDOR
         FROM dbo.RO_T_KPI_AHORROS_PROVEEDORES
         WHERE ACTIVO = 1 AND AHORRO_ID IN (" . implode(',', $ids) . ")
         ORDER BY FECHA_BAJA_PROVEEDOR, COD_PROVEE", array(), 'proveedores de ahorros');
    $provPorAhorro = array();
    foreach ($provFilas as $p) $provPorAhorro[intval($p['AHORRO_ID'])][] = $p;

    // Nombres de proyectos (HistorialProyectos, en central) en una sola consulta.
    $proyIds = array_values(array_unique(array_filter(array_map(function ($f) {
        return $f['PROYECTO_ID'] !== null ? intval($f['PROYECTO_ID']) : null;
    }, $filas))));
    $proyectos = array();
    if ($proyIds) {
        foreach (ahorrosFilas($cid, "SELECT ID, Desarrollo FROM HistorialProyectos WHERE ID IN (" . implode(',', $proyIds) . ")",
                 array(), 'proyectos') as $p) {
            $proyectos[intval($p['ID'])] = $p['Desarrollo'];
        }
    }

    $ahorros = array();
    foreach ($filas as $f) {
        $id = intval($f['ID']);
        $a = array(
            'id'             => $id,
            'descripcion'    => $f['DESCRIPCION'],
            'tipo_id'        => intval($f['TIPO_ID']),
            'tipo_codigo'    => $f['TIPO_CODIGO'],
            'tipo_nombre'    => $f['TIPO_NOMBRE'],
            'modo'           => $f['MODO_CALCULO'],
            'sector_id'      => intval($f['SECTOR_ID']),
            'sector_nombre'  => $f['SECTOR_NOMBRE'],
            'proyecto_id'    => $f['PROYECTO_ID'] !== null ? intval($f['PROYECTO_ID']) : null,
            'proyecto_nombre'=> $f['PROYECTO_ID'] !== null ? ($proyectos[intval($f['PROYECTO_ID'])] ?? null) : null,
            'periodicidad'   => $f['PERIODICIDAD'],
            'fecha_desde'    => $f['FECHA_DESDE'],
            'fecha_hasta'    => $f['FECHA_HASTA'],
            'monto'          => $f['MONTO'] !== null ? floatval($f['MONTO']) : 0.0,
            'horas_mes'      => $f['HORAS_MES'] !== null ? floatval($f['HORAS_MES']) : null,
            'costo_hora'     => $f['COSTO_HORA'] !== null ? floatval($f['COSTO_HORA']) : null,
            'observaciones'  => $f['OBSERVACIONES'],
            'activo'         => intval($f['ACTIVO']) === 1,
            'usuario_alta'   => $f['USUARIO_ALTA'],
            'fecha_alta'     => $f['FECHA_ALTA'],
            'usuario_modif'  => $f['USUARIO_MODIF'],
            'fecha_modif'    => $f['FECHA_MODIF'],
            'usuario_baja'   => $f['USUARIO_BAJA'],
            'fecha_baja'     => $f['FECHA_BAJA'],
            'proveedores'    => array(),
            'avisos'         => array()
        );

        if ($a['modo'] === 'PROVEEDOR') {
            $montoMensual = 0.0;
            foreach ($provPorAhorro[$id] ?? array() as $p) {
                $calc = calcularBajaProveedor($cid, $p['COD_PROVEE'], $p['FECHA_BAJA_PROVEEDOR'], $excluidos);
                $a['proveedores'][] = array(
                    'cod_provee' => $p['COD_PROVEE'],
                    'fecha_baja' => $p['FECHA_BAJA_PROVEEDOR'],
                    'calculo'    => $calc
                );
                $montoMensual += $calc['promedio_mensual'];
                foreach ($calc['avisos'] as $av) $a['avisos'][] = $p['COD_PROVEE'] . ': ' . $av;
            }
            $a['monto'] = round($montoMensual, 2);
        }
        $ahorros[] = $a;
    }
    return $ahorros;
}

/**
 * Resumen del KPI para la tarjeta y el Cumplimiento General (se suma a la
 * respuesta de kpi-resumen). Si algo falla, devuelve ahorro_error y los
 * valores en null para no tirar abajo las otras tarjetas.
 */
function obtenerResumenAhorros($cidApps, $cid, $desde, $hastaExclusivo, $ahorros = null) {
    try {
        $params = obtenerParametros($cidApps);
        if ($ahorros === null) $ahorros = cargarAhorros($cidApps, $cid, false);

        $idxDesde  = mesIdx($desde);
        $idxHasta  = mesIdx($hastaExclusivo) - 1;
        $anioHasta = intdiv($idxHasta, 12);
        $idxAnioIni = $anioHasta * 12;
        $idxAnioFin = $anioHasta * 12 + 11;

        $totalRango = 0.0;
        $totalAnio  = 0.0;
        $porTipo    = array();
        foreach ($ahorros as $a) {
            if (!$a['activo']) continue;
            $r = sumaAhorroMeses($a, $idxDesde, $idxHasta);
            $p = sumaAhorroMeses($a, $idxAnioIni, $idxAnioFin);
            $totalRango += $r;
            $totalAnio  += $p;
            if (!isset($porTipo[$a['tipo_codigo']])) {
                $porTipo[$a['tipo_codigo']] = array('tipo' => $a['tipo_nombre'], 'rango' => 0.0, 'proyeccion' => 0.0, 'cantidad' => 0);
            }
            $porTipo[$a['tipo_codigo']]['rango']      += $r;
            $porTipo[$a['tipo_codigo']]['proyeccion'] += $p;
            $porTipo[$a['tipo_codigo']]['cantidad']++;
        }

        $mesesRango    = mesesEnRango($desde, $hastaExclusivo);
        $objetivoAnual = parametroNumero($params, 'OBJETIVO_AHORRO_ANUAL');
        $objetivoRango = $objetivoAnual > 0 ? round($objetivoAnual / 12 * $mesesRango, 2) : 0.0;

        return array(
            'ahorro_rango'          => round($totalRango, 2),
            'ahorro_proyeccion'     => round($totalAnio, 2),
            'ahorro_anio_proyeccion'=> $anioHasta,
            'ahorro_objetivo_anual' => $objetivoAnual,
            'ahorro_objetivo_rango' => $objetivoRango,
            'ahorro_meses_rango'    => $mesesRango,
            'pct_ahorro'            => $objetivoRango > 0 ? round($totalRango / $objetivoRango * 100, 1) : null,
            'ahorro_por_tipo'       => array_values($porTipo),
            'ahorro_error'          => null
        );
    } catch (Exception $e) {
        error_log('KPIs ahorros: ' . $e->getMessage());
        return array(
            'ahorro_rango' => null, 'ahorro_proyeccion' => null, 'ahorro_objetivo_anual' => null,
            'ahorro_objetivo_rango' => null, 'pct_ahorro' => null, 'ahorro_por_tipo' => array(),
            'ahorro_error' => 'No se pudo calcular el ahorro: ' . $e->getMessage()
        );
    }
}

/** Acción ahorros-listado: ahorros con su aporte al rango y al año, más el resumen. */
function accionAhorrosListado($cidApps, $cid, $desde, $hastaExclusivo, $incluirBajas) {
    $ahorros = cargarAhorros($cidApps, $cid, $incluirBajas);
    $idxDesde  = mesIdx($desde);
    $idxHasta  = mesIdx($hastaExclusivo) - 1;
    $anioHasta = intdiv($idxHasta, 12);

    foreach ($ahorros as &$a) {
        $a['ahorro_rango']      = $a['activo'] ? sumaAhorroMeses($a, $idxDesde, $idxHasta) : 0.0;
        $a['proyeccion_anio']   = $a['activo'] ? sumaAhorroMeses($a, $anioHasta * 12, $anioHasta * 12 + 11) : 0.0;
    }
    unset($a);

    echo json_encode(array_merge(array(
        'success' => true,
        'ahorros' => $ahorros
    ), obtenerResumenAhorros($cidApps, $cid, $desde, $hastaExclusivo,
        array_values(array_filter($ahorros, function ($a) { return $a['activo']; })))));
}

/** Acción ahorros-catalogos: tipos, sectores y parámetros editables. */
function accionAhorrosCatalogos($cidApps) {
    $tipos = array_map(function ($t) {
        return array('id' => intval($t['ID']), 'codigo' => $t['CODIGO'], 'nombre' => $t['NOMBRE'], 'modo' => $t['MODO_CALCULO']);
    }, ahorrosFilas($cidApps,
        "SELECT ID, CODIGO, NOMBRE, MODO_CALCULO FROM dbo.RO_T_KPI_AHORRO_TIPOS WHERE ACTIVO = 1 ORDER BY ORDEN, NOMBRE",
        array(), 'tipos de ahorro'));

    $sectores = array_map(function ($s) {
        return array('id' => intval($s['id']), 'codigo' => $s['codigo'], 'nombre' => $s['nombre']);
    }, ahorrosFilas($cidApps, "SELECT id, codigo, nombre FROM dbo.FP_SECTORES WHERE activo = 1 ORDER BY nombre",
        array(), 'sectores'));

    $params = obtenerParametros($cidApps);
    echo json_encode(array(
        'success'    => true,
        'tipos'      => $tipos,
        'sectores'   => $sectores,
        'parametros' => array(
            'objetivo_ahorro_anual' => parametroNumero($params, 'OBJETIVO_AHORRO_ANUAL'),
            'costo_hora_default'    => parametroNumero($params, 'COSTO_HORA_DEFAULT')
        )
    ));
}

/** Acción buscar-proveedores: CPA01 por código o nombre. */
function accionBuscarProveedores($cid, $q) {
    $q = trim((string) $q);
    if (mb_strlen($q) < 2) {
        echo json_encode(array('success' => true, 'proveedores' => array()));
        return;
    }
    $like = '%' . str_replace(array('[', '%', '_'), array('[[]', '[%]', '[_]'), $q) . '%';
    $filas = ahorrosFilas($cid,
        "SELECT TOP 20 RTRIM(COD_PROVEE) AS COD_PROVEE, RTRIM(NOM_PROVEE) AS NOM_PROVEE, COND_IVA
         FROM dbo.CPA01
         WHERE COD_PROVEE LIKE ? OR NOM_PROVEE LIKE ?
         ORDER BY CASE WHEN COD_PROVEE = ? THEN 0 ELSE 1 END, NOM_PROVEE",
        array($like, $like, strtoupper($q)), 'búsqueda de proveedores');
    echo json_encode(array('success' => true, 'proveedores' => array_map(function ($p) {
        return array('cod_provee' => $p['COD_PROVEE'], 'nombre' => $p['NOM_PROVEE'], 'cond_iva' => $p['COND_IVA']);
    }, $filas)));
}

/** Normaliza un nombre de sector para compararlo (mayúsculas, sin acentos ni símbolos). */
function normalizarSector($texto) {
    $t = mb_strtoupper(trim((string) $texto), 'UTF-8');
    $t = strtr($t, array('Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N'));
    return preg_replace('/[^A-Z0-9]/', '', $t);
}

/**
 * Acción buscar-proyectos: HistorialProyectos por ID o Desarrollo. Cada
 * proyecto trae sector_id_sugerido: el FP_SECTORES cuyo código o nombre
 * coincide con HistorialProyectos.Sector (normalizados), o null si no hay
 * equivalente y hay que elegirlo a mano.
 */
function accionBuscarProyectos($cidApps, $cid, $q) {
    $q = trim((string) $q);
    if ($q === '') {
        echo json_encode(array('success' => true, 'proyectos' => array()));
        return;
    }
    $like = '%' . str_replace(array('[', '%', '_'), array('[[]', '[%]', '[_]'), $q) . '%';
    $filas = ahorrosFilas($cid,
        "SELECT TOP 20 ID, Desarrollo, Sector, Estado
         FROM HistorialProyectos
         WHERE CAST(ID AS varchar(20)) = ? OR Desarrollo LIKE ?
         ORDER BY CASE WHEN CAST(ID AS varchar(20)) = ? THEN 0 ELSE 1 END, ID DESC",
        array($q, $like, $q), 'búsqueda de proyectos');

    $sectores = ahorrosFilas($cidApps, "SELECT id, codigo, nombre FROM dbo.FP_SECTORES WHERE activo = 1", array(), 'sectores');
    $mapa = array();
    foreach ($sectores as $s) {
        $mapa[normalizarSector($s['codigo'])] = intval($s['id']);
        $mapa[normalizarSector($s['nombre'])] = intval($s['id']);
    }

    echo json_encode(array('success' => true, 'proyectos' => array_map(function ($p) use ($mapa) {
        $norm = normalizarSector($p['Sector']);
        return array(
            'id'                 => intval($p['ID']),
            'desarrollo'         => $p['Desarrollo'],
            'sector'             => $p['Sector'],
            'estado'             => $p['Estado'],
            'sector_id_sugerido' => ($norm !== '' && isset($mapa[$norm])) ? $mapa[$norm] : null
        );
    }, $filas)));
}

/** Acción ahorro-preview-proveedores: el cálculo de 2.4 por proveedor, antes de guardar. */
function accionPreviewProveedores($cidApps, $cid, $body) {
    $proveedores = isset($body['proveedores']) && is_array($body['proveedores']) ? $body['proveedores'] : array();
    $errores = array();
    foreach ($proveedores as $i => $p) {
        if (empty($p['cod_provee'])) $errores[] = 'Falta el proveedor de la fila ' . ($i + 1) . '.';
        if (!fechaValida($p['fecha_baja'] ?? null)) $errores[] = 'Falta la fecha de baja de ' . ($p['cod_provee'] ?? 'la fila ' . ($i + 1)) . '.';
    }
    if (!$proveedores) $errores[] = 'Agregá al menos un proveedor.';
    if ($errores) { responderErroresValidacion($errores); return; }

    $excluidos = tcompExcluidos(obtenerParametros($cidApps));
    $out = array();
    foreach ($proveedores as $p) {
        $out[] = calcularBajaProveedor($cid, strtoupper(trim($p['cod_provee'])), $p['fecha_baja'], $excluidos);
    }
    echo json_encode(array('success' => true, 'proveedores' => $out));
}

/* ════════════════════════════════════════════════════════════════════════
   Escritura de ahorros
   ════════════════════════════════════════════════════════════════════════ */

/**
 * Valida y normaliza el cuerpo de un alta/edición. Devuelve array($datos, $errores).
 * Los errores dicen qué falta, en el orden del formulario.
 */
function validarAhorro($cidApps, $cid, $body) {
    $errores = array();
    $d = array();

    $d['descripcion'] = trim((string) ($body['descripcion'] ?? ''));
    if ($d['descripcion'] === '') $errores[] = 'Falta la descripción.';
    elseif (mb_strlen($d['descripcion']) > 300) $errores[] = 'La descripción no puede superar los 300 caracteres.';

    $d['tipo_id'] = intval($body['tipo_id'] ?? 0);
    $tipo = null;
    if ($d['tipo_id'] > 0) {
        $t = ahorrosFilas($cidApps, "SELECT ID, CODIGO, MODO_CALCULO FROM dbo.RO_T_KPI_AHORRO_TIPOS WHERE ID = ? AND ACTIVO = 1",
            array($d['tipo_id']), 'tipo');
        $tipo = $t ? $t[0] : null;
    }
    if (!$tipo) $errores[] = 'Falta el tipo de ahorro.';
    $modo = $tipo ? $tipo['MODO_CALCULO'] : null;

    $d['sector_id'] = intval($body['sector_id'] ?? 0);
    $sectorOk = $d['sector_id'] > 0 && ahorrosFilas($cidApps, "SELECT 1 AS X FROM dbo.FP_SECTORES WHERE id = ? AND activo = 1",
        array($d['sector_id']), 'sector');
    if (!$sectorOk) $errores[] = 'Falta el sector.';

    $d['proyecto_id'] = null;
    if (isset($body['proyecto_id']) && $body['proyecto_id'] !== '' && $body['proyecto_id'] !== null) {
        $d['proyecto_id'] = intval($body['proyecto_id']);
        if (!ahorrosFilas($cid, "SELECT 1 AS X FROM HistorialProyectos WHERE ID = ?", array($d['proyecto_id']), 'proyecto')) {
            $errores[] = 'El proyecto asociado no existe.';
        }
    }

    $d['periodicidad'] = strtoupper(trim((string) ($body['periodicidad'] ?? '')));
    if ($modo === 'PROVEEDOR') $d['periodicidad'] = 'MENSUAL';    // fija en baja de proveedor
    if (!in_array($d['periodicidad'], array('MENSUAL', 'UNICO'), true)) $errores[] = 'Falta la periodicidad.';

    $d['fecha_desde'] = (string) ($body['fecha_desde'] ?? '');
    $d['fecha_hasta'] = (string) ($body['fecha_hasta'] ?? '');
    $desdeOk = fechaValida($d['fecha_desde']);
    $hastaOk = fechaValida($d['fecha_hasta']);
    if (!$desdeOk) $errores[] = 'Falta la fecha desde.';
    if (!$hastaOk) $errores[] = 'Falta la fecha hasta.';
    if ($desdeOk && $hastaOk && $d['fecha_hasta'] < $d['fecha_desde']) {
        $errores[] = 'La fecha hasta no puede ser anterior a la fecha desde.';
    }

    $d['monto'] = null;
    $d['horas_mes'] = null;
    $d['costo_hora'] = null;
    $d['proveedores'] = array();

    if ($modo === 'MONTO') {
        $monto = $body['monto'] ?? null;
        if (!is_numeric($monto) || floatval($monto) <= 0) $errores[] = 'El monto tiene que ser mayor que 0.';
        else $d['monto'] = round(floatval($monto), 2);
    } elseif ($modo === 'HORAS') {
        $horas = $body['horas_mes'] ?? null;
        $costo = $body['costo_hora'] ?? null;
        if (!is_numeric($horas) || floatval($horas) <= 0) $errores[] = 'Las horas por mes tienen que ser mayores que 0.';
        if (!is_numeric($costo) || floatval($costo) <= 0) $errores[] = 'El costo hora tiene que ser mayor que 0.';
        if (is_numeric($horas) && is_numeric($costo) && floatval($horas) > 0 && floatval($costo) > 0) {
            $d['horas_mes']  = round(floatval($horas), 2);
            $d['costo_hora'] = round(floatval($costo), 2);
            $d['monto']      = round($d['horas_mes'] * $d['costo_hora'], 2);
        }
    } elseif ($modo === 'PROVEEDOR') {
        $lista = isset($body['proveedores']) && is_array($body['proveedores']) ? $body['proveedores'] : array();
        $vistos = array();
        foreach ($lista as $i => $p) {
            $cod = strtoupper(trim((string) ($p['cod_provee'] ?? '')));
            $fb  = (string) ($p['fecha_baja'] ?? '');
            if ($cod === '') { $errores[] = 'Falta el proveedor de la fila ' . ($i + 1) . '.'; continue; }
            if (isset($vistos[$cod])) { $errores[] = 'El proveedor ' . $cod . ' está repetido.'; continue; }
            $vistos[$cod] = true;
            if (!ahorrosFilas($cid, "SELECT 1 AS X FROM dbo.CPA01 WHERE COD_PROVEE = ?", array($cod), 'proveedor')) {
                $errores[] = 'El proveedor ' . $cod . ' no existe en Tango.';
            }
            if (!fechaValida($fb)) { $errores[] = 'Falta la fecha de baja de ' . $cod . '.'; continue; }
            $d['proveedores'][] = array('cod_provee' => $cod, 'fecha_baja' => $fb);
        }
        if (!$lista) $errores[] = 'Agregá al menos un proveedor con su fecha de baja.';
    }

    $obs = trim((string) ($body['observaciones'] ?? ''));
    $d['observaciones'] = $obs === '' ? null : $obs;
    if ($obs !== '' && mb_strlen($obs) > 1000) $errores[] = 'Las observaciones no pueden superar los 1000 caracteres.';

    $d['modo'] = $modo;
    $d['tipo_codigo'] = $tipo ? $tipo['CODIGO'] : null;
    return array($d, $errores);
}

/** Lo que se guarda del ahorro en el historial (VALOR_ANTERIOR / VALOR_NUEVO). */
function snapshotAhorro($a) {
    return array(
        'descripcion'   => $a['descripcion'],
        'tipo_id'       => $a['tipo_id'],
        'sector_id'     => $a['sector_id'],
        'proyecto_id'   => $a['proyecto_id'],
        'periodicidad'  => $a['periodicidad'],
        'fecha_desde'   => $a['fecha_desde'],
        'fecha_hasta'   => $a['fecha_hasta'],
        'monto'         => $a['modo'] === 'PROVEEDOR' ? null : $a['monto'],
        'horas_mes'     => $a['horas_mes'],
        'costo_hora'    => $a['costo_hora'],
        'observaciones' => $a['observaciones'],
        'proveedores'   => array_map(function ($p) {
            return array('cod_provee' => $p['cod_provee'], 'fecha_baja' => $p['fecha_baja']);
        }, $a['proveedores'])
    );
}

function registrarHistorial($cidApps, $entidad, $entidadId, $accion, $anterior, $nuevo, $motivo, $usuario) {
    ahorrosQuery($cidApps,
        "INSERT INTO dbo.RO_T_KPI_AHORROS_HISTORIAL (ENTIDAD, ENTIDAD_ID, ACCION, VALOR_ANTERIOR, VALOR_NUEVO, MOTIVO, USUARIO)
         VALUES (?, ?, ?, ?, ?, ?, ?)",
        array($entidad, (string) $entidadId, $accion,
              $anterior === null ? null : json_encode($anterior, JSON_UNESCAPED_UNICODE),
              $nuevo === null ? null : json_encode($nuevo, JSON_UNESCAPED_UNICODE),
              $motivo, $usuario),
        'historial');
}

/**
 * Acción ahorro-guardar: alta (sin id) o edición (con id y motivo).
 * Proveedores: los que se quitan se dan de baja lógica; los nuevos se dan de
 * alta; si cambia la fecha de baja se modifica la fila.
 */
function accionGuardarAhorro($cidApps, $cid, $body, $usuario) {
    $usuario = AuthKpis::usuarioDeEscritura($usuario);
    $id = isset($body['id']) && $body['id'] !== '' && $body['id'] !== null ? intval($body['id']) : null;
    $motivo = trim((string) ($body['motivo'] ?? ''));

    list($d, $errores) = validarAhorro($cidApps, $cid, $body);

    $anterior = null;
    if ($id !== null) {
        $previos = cargarAhorros($cidApps, $cid, true, $id);
        if (!$previos) { $errores[] = 'El ahorro que querés editar no existe.'; }
        elseif (!$previos[0]['activo']) { $errores[] = 'El ahorro está dado de baja: no se puede editar.'; }
        else { $anterior = $previos[0]; }
        if ($motivo === '') $errores[] = 'Para editar tenés que indicar el motivo.';
    }
    if (mb_strlen($motivo) > 500) $errores[] = 'El motivo no puede superar los 500 caracteres.';
    if ($errores) { responderErroresValidacion($errores); return; }

    if (!sqlsrv_begin_transaction($cidApps)) {
        throw new Exception('No se pudo iniciar la transacción: ' . print_r(sqlsrv_errors(), true));
    }
    try {
        $campos = array($d['descripcion'], $d['tipo_id'], $d['sector_id'], $d['proyecto_id'], $d['periodicidad'],
                        $d['fecha_desde'], $d['fecha_hasta'], $d['monto'], $d['horas_mes'], $d['costo_hora'],
                        $d['observaciones']);
        if ($id === null) {
            $stmt = ahorrosQuery($cidApps,
                "INSERT INTO dbo.RO_T_KPI_AHORROS
                    (DESCRIPCION, TIPO_ID, SECTOR_ID, PROYECTO_ID, PERIODICIDAD, FECHA_DESDE, FECHA_HASTA,
                     MONTO, HORAS_MES, COSTO_HORA, OBSERVACIONES, USUARIO_ALTA)
                 OUTPUT INSERTED.ID
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                array_merge($campos, array($usuario)), 'alta de ahorro');
            $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            $id = intval($row['ID']);
        } else {
            ahorrosQuery($cidApps,
                "UPDATE dbo.RO_T_KPI_AHORROS
                 SET DESCRIPCION = ?, TIPO_ID = ?, SECTOR_ID = ?, PROYECTO_ID = ?, PERIODICIDAD = ?,
                     FECHA_DESDE = ?, FECHA_HASTA = ?, MONTO = ?, HORAS_MES = ?, COSTO_HORA = ?, OBSERVACIONES = ?,
                     USUARIO_MODIF = ?, FECHA_MODIF = GETDATE()
                 WHERE ID = ? AND ACTIVO = 1",
                array_merge($campos, array($usuario, $id)), 'edición de ahorro');
        }

        // Proveedores: alta, cambio de fecha y baja lógica de los que se quitan.
        $actuales = array();
        foreach (ahorrosFilas($cidApps,
                 "SELECT ID, RTRIM(COD_PROVEE) AS COD_PROVEE, CONVERT(varchar(10), FECHA_BAJA_PROVEEDOR, 23) AS FB
                  FROM dbo.RO_T_KPI_AHORROS_PROVEEDORES WHERE AHORRO_ID = ? AND ACTIVO = 1", array($id), 'proveedores') as $p) {
            $actuales[$p['COD_PROVEE']] = $p;
        }
        $nuevos = array();
        foreach ($d['proveedores'] as $p) $nuevos[$p['cod_provee']] = $p;

        foreach ($actuales as $cod => $p) {
            if (!isset($nuevos[$cod])) {
                ahorrosQuery($cidApps,
                    "UPDATE dbo.RO_T_KPI_AHORROS_PROVEEDORES SET ACTIVO = 0, USUARIO_BAJA = ?, FECHA_BAJA = GETDATE() WHERE ID = ?",
                    array($usuario, $p['ID']), 'baja de proveedor');
            } elseif ($nuevos[$cod]['fecha_baja'] !== $p['FB']) {
                ahorrosQuery($cidApps,
                    "UPDATE dbo.RO_T_KPI_AHORROS_PROVEEDORES
                     SET FECHA_BAJA_PROVEEDOR = ?, USUARIO_MODIF = ?, FECHA_MODIF = GETDATE() WHERE ID = ?",
                    array($nuevos[$cod]['fecha_baja'], $usuario, $p['ID']), 'edición de proveedor');
            }
        }
        foreach ($nuevos as $cod => $p) {
            if (!isset($actuales[$cod])) {
                ahorrosQuery($cidApps,
                    "INSERT INTO dbo.RO_T_KPI_AHORROS_PROVEEDORES (AHORRO_ID, COD_PROVEE, FECHA_BAJA_PROVEEDOR, USUARIO_ALTA)
                     VALUES (?, ?, ?, ?)",
                    array($id, $cod, $p['fecha_baja'], $usuario), 'alta de proveedor');
            }
        }

        // Historial. En el alta de BAJA_PROVEEDOR va un snapshot informativo del cálculo.
        $nuevoSnap = snapshotAhorro($d);
        if ($anterior === null && $d['modo'] === 'PROVEEDOR') {
            $excluidos = tcompExcluidos(obtenerParametros($cidApps));
            $nuevoSnap['calculo_al_alta'] = array_map(function ($p) use ($cid, $excluidos) {
                $c = calcularBajaProveedor($cid, $p['cod_provee'], $p['fecha_baja'], $excluidos);
                unset($c['posteriores'], $c['inicio_ahorro_idx']);
                return $c;
            }, $d['proveedores']);
        }
        registrarHistorial($cidApps, 'AHORRO', $id, $anterior === null ? 'ALTA' : 'EDICION',
            $anterior === null ? null : snapshotAhorro($anterior), $nuevoSnap,
            $motivo === '' ? null : $motivo, $usuario);

        sqlsrv_commit($cidApps);
    } catch (Exception $e) {
        sqlsrv_rollback($cidApps);
        throw $e;
    }

    echo json_encode(array('success' => true, 'id' => $id, 'accion' => $anterior === null ? 'ALTA' : 'EDICION'));
}

/** Acción ahorro-baja: baja lógica del ahorro (y de sus proveedores), con motivo. */
function accionBajaAhorro($cidApps, $cid, $body, $usuario) {
    $usuario = AuthKpis::usuarioDeEscritura($usuario);
    $id = intval($body['id'] ?? 0);
    $motivo = trim((string) ($body['motivo'] ?? ''));

    $errores = array();
    $previos = $id > 0 ? cargarAhorros($cidApps, $cid, true, $id) : array();
    if (!$previos) $errores[] = 'El ahorro que querés dar de baja no existe.';
    elseif (!$previos[0]['activo']) $errores[] = 'El ahorro ya está dado de baja.';
    if ($motivo === '') $errores[] = 'Para dar de baja tenés que indicar el motivo.';
    if (mb_strlen($motivo) > 500) $errores[] = 'El motivo no puede superar los 500 caracteres.';
    if ($errores) { responderErroresValidacion($errores); return; }

    if (!sqlsrv_begin_transaction($cidApps)) {
        throw new Exception('No se pudo iniciar la transacción: ' . print_r(sqlsrv_errors(), true));
    }
    try {
        ahorrosQuery($cidApps,
            "UPDATE dbo.RO_T_KPI_AHORROS SET ACTIVO = 0, USUARIO_BAJA = ?, FECHA_BAJA = GETDATE() WHERE ID = ? AND ACTIVO = 1",
            array($usuario, $id), 'baja de ahorro');
        ahorrosQuery($cidApps,
            "UPDATE dbo.RO_T_KPI_AHORROS_PROVEEDORES SET ACTIVO = 0, USUARIO_BAJA = ?, FECHA_BAJA = GETDATE()
             WHERE AHORRO_ID = ? AND ACTIVO = 1",
            array($usuario, $id), 'baja de proveedores del ahorro');
        registrarHistorial($cidApps, 'AHORRO', $id, 'BAJA', snapshotAhorro($previos[0]), null, $motivo, $usuario);
        sqlsrv_commit($cidApps);
    } catch (Exception $e) {
        sqlsrv_rollback($cidApps);
        throw $e;
    }
    echo json_encode(array('success' => true, 'id' => $id));
}

/* ════════════════════════════════════════════════════════════════════════
   Parámetros editables desde la pantalla
   ════════════════════════════════════════════════════════════════════════ */

/** Los parámetros que el modal Parámetros puede editar: clave del body => clave de la tabla. */
function parametrosEditables() {
    return array(
        'objetivo_ahorro_anual' => 'OBJETIVO_AHORRO_ANUAL',
        'costo_hora_default'    => 'COSTO_HORA_DEFAULT'
    );
}

/** Acción parametros-guardar: objetivo anual y costo hora por defecto, con motivo. */
function accionGuardarParametros($cidApps, $body, $usuario) {
    $usuario = AuthKpis::usuarioDeEscritura($usuario);
    $motivo = trim((string) ($body['motivo'] ?? ''));
    $actuales = obtenerParametros($cidApps);

    $errores = array();
    $cambios = array();
    $etiquetas = array('objetivo_ahorro_anual' => 'El objetivo anual', 'costo_hora_default' => 'El costo hora por defecto');
    foreach (parametrosEditables() as $campo => $clave) {
        if (!array_key_exists($campo, $body)) continue;
        $v = $body[$campo];
        if (!is_numeric($v) || floatval($v) < 0) { $errores[] = $etiquetas[$campo] . ' tiene que ser un número mayor o igual que 0.'; continue; }
        $nuevo = (string) round(floatval($v), 2);
        if (!array_key_exists($clave, $actuales)) { $errores[] = 'Falta el parámetro ' . $clave . ': corré sql/kpis_ahorros.sql.'; continue; }
        if (floatval($actuales[$clave]) !== floatval($nuevo)) $cambios[$clave] = array($actuales[$clave], $nuevo);
    }
    if ($motivo === '') $errores[] = 'Tenés que indicar el motivo del cambio.';
    if (mb_strlen($motivo) > 500) $errores[] = 'El motivo no puede superar los 500 caracteres.';
    if (!$errores && !$cambios) $errores[] = 'No hay cambios para guardar.';
    if ($errores) { responderErroresValidacion($errores); return; }

    if (!sqlsrv_begin_transaction($cidApps)) {
        throw new Exception('No se pudo iniciar la transacción: ' . print_r(sqlsrv_errors(), true));
    }
    try {
        foreach ($cambios as $clave => list($antes, $despues)) {
            ahorrosQuery($cidApps,
                "UPDATE dbo.RO_T_KPI_PARAMETROS SET VALOR = ?, USUARIO_MODIF = ?, FECHA_MODIF = GETDATE() WHERE CLAVE = ?",
                array($despues, $usuario, $clave), 'parámetro ' . $clave);
            registrarHistorial($cidApps, 'PARAMETRO', $clave, 'EDICION',
                array('valor' => floatval($antes)), array('valor' => floatval($despues)), $motivo, $usuario);
        }
        sqlsrv_commit($cidApps);
    } catch (Exception $e) {
        sqlsrv_rollback($cidApps);
        throw $e;
    }
    echo json_encode(array('success' => true, 'cambios' => count($cambios)));
}

/* ════════════════════════════════════════════════════════════════════════
   Historial
   ════════════════════════════════════════════════════════════════════════ */

/**
 * Acción ahorros-historial: cambios de un ahorro (entidad AHORRO + id) o de
 * los parámetros (entidad PARAMETRO, todos). Cada cambio trae la lista de
 * campos que cambiaron, con su valor anterior y nuevo.
 */
function accionHistorial($cidApps, $entidad, $entidadId) {
    $entidad = strtoupper((string) $entidad);
    if (!in_array($entidad, array('AHORRO', 'PARAMETRO'), true)) {
        http_response_code(400);
        echo json_encode(array('success' => false, 'error' => 'Entidad de historial inválida.'));
        return;
    }
    $sql = "SELECT ID, ENTIDAD_ID, ACCION, VALOR_ANTERIOR, VALOR_NUEVO, MOTIVO, USUARIO,
                   CONVERT(varchar(19), FECHA, 120) AS FECHA
            FROM dbo.RO_T_KPI_AHORROS_HISTORIAL WHERE ENTIDAD = ?";
    $params = array($entidad);
    if ($entidad === 'AHORRO') {
        $sql .= " AND ENTIDAD_ID = ?";
        $params[] = (string) intval($entidadId);
    }
    $sql .= " ORDER BY FECHA DESC, ID DESC";

    // Nombres legibles de tipos y sectores para el detalle de cambios.
    $tipos = array();
    foreach (ahorrosFilas($cidApps, "SELECT ID, NOMBRE FROM dbo.RO_T_KPI_AHORRO_TIPOS", array(), 'tipos') as $t) $tipos[intval($t['ID'])] = $t['NOMBRE'];
    $sectores = array();
    foreach (ahorrosFilas($cidApps, "SELECT id, nombre FROM dbo.FP_SECTORES", array(), 'sectores') as $s) $sectores[intval($s['id'])] = $s['nombre'];

    $etiquetas = array(
        'descripcion' => 'Descripción', 'tipo_id' => 'Tipo', 'sector_id' => 'Sector', 'proyecto_id' => 'Proyecto',
        'periodicidad' => 'Periodicidad', 'fecha_desde' => 'Desde', 'fecha_hasta' => 'Hasta', 'monto' => 'Monto',
        'horas_mes' => 'Horas por mes', 'costo_hora' => 'Costo hora', 'observaciones' => 'Observaciones',
        'proveedores' => 'Proveedores', 'valor' => 'Valor'
    );
    $legible = function ($campo, $v) use ($tipos, $sectores) {
        if ($v === null || $v === '') return '—';
        if ($campo === 'tipo_id') return $tipos[intval($v)] ?? $v;
        if ($campo === 'sector_id') return $sectores[intval($v)] ?? $v;
        if ($campo === 'proveedores') {
            return $v ? implode(', ', array_map(function ($p) { return $p['cod_provee'] . ' (baja ' . $p['fecha_baja'] . ')'; }, $v)) : '—';
        }
        return $v;
    };

    $out = array();
    foreach (ahorrosFilas($cidApps, $sql, $params, 'historial') as $h) {
        $ant = $h['VALOR_ANTERIOR'] !== null ? json_decode($h['VALOR_ANTERIOR'], true) : array();
        $nue = $h['VALOR_NUEVO'] !== null ? json_decode($h['VALOR_NUEVO'], true) : array();
        $cambios = array();
        if ($h['ACCION'] === 'EDICION') {
            foreach ($etiquetas as $campo => $etiqueta) {
                $a = $ant[$campo] ?? null;
                $n = $nue[$campo] ?? null;
                if (json_encode($a) !== json_encode($n) && !(is_numeric($a) && is_numeric($n) && floatval($a) === floatval($n))) {
                    $cambios[] = array('campo' => $etiqueta, 'antes' => $legible($campo, $a), 'despues' => $legible($campo, $n));
                }
            }
        }
        $out[] = array(
            'entidad_id' => $h['ENTIDAD_ID'],
            'accion'     => $h['ACCION'],
            'motivo'     => $h['MOTIVO'],
            'usuario'    => $h['USUARIO'],
            'fecha'      => $h['FECHA'],
            'cambios'    => $cambios
        );
    }
    echo json_encode(array('success' => true, 'historial' => $out));
}
