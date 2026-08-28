<?php
/**
 * PremiosDB
 * Cálculo de premios comerciales por supervisora — Locales Propios y Franquicias.
 *
 * Fuentes reales (sqlsrv, ver `class/Conexion.php`):
 *   - POWER_BI_CONTROL.dbo.BI_T_ESTADISTICAS_VENTAS_PROPIOS       (conexión 'power')
 *   - POWER_BI_CONTROL_FRANQUICIAS.dbo.BI_T_ESTADISTICAS_VENTAS_FRANQUICIAS,
 *     BI_T_PREMIOS_SUPERVISION_FRANQUICIAS                        (conexión 'power_franquicias')
 *   - [XL-LAKERBIS].locales_lakers.dbo.RO_T_SUPERVISORAS_COMERCIAL (linked server desde 'power',
 *     mismo patrón que usa class/Filters.php para SUCURSALES_LAKERS)
 *
 * Granularidad: las tablas de hechos son MENSUALES (una fila por sucursal por mes,
 * FECHA = último día del mes). Un rango de fechas arbitrario se resuelve a la lista de
 * fines de mes que toca, y se agrega (SUM) sobre esos meses.
 *
 * Calidad de dato conocida: existen filas duplicadas exactas (mismo NRO_SUCURS+FECHA+
 * valores) en varias tablas de origen. Se deduplica con SELECT DISTINCT antes de
 * agregar — es un parche defensivo, no una corrección del dato en origen.
 */
class PremiosDB
{
    /** Tolerancia general: se considera "objetivo cumplido" si no cae más de 0.5% por debajo. */
    private const TOLERANCIA_OBJ_VENTA = -0.005;

    /** Puntos que debe superar una sucursal por encima del promedio de marca para el premio de crecimiento. */
    private const BENCHMARK_PLUS = 0.10;

    /** NRO_SUCURS=1 ("CENTRAL") es una fila sin ventas reales — se excluye de todo conteo de premios. */
    private const CASA_CENTRAL_NRO = 1;

    /**
     * @var array<int,array{nombre:string,mail:?string}>|null Caché del catálogo COMPLETO de
     * supervisoras activas (RO_T_SUPERVISORAS_COMERCIAL) para esta instancia — ver
     * catalogoSupervisoras(). Incluye TODAS las activas, sin filtrar por visibilidad —
     * ver BI_T_PREMIOS_SUPERVISORAS_ORDEN (columnas ORDEN + VISIBLE) para qué se
     * muestra/oculta y en qué orden en ESTE dashboard, editable desde el configurador
     * ("Configuración de supervisoras" en el topbar). Antes había una constante
     * hardcodeada SUPERVISORAS_EXCLUIDAS con ['Julieta Dalmeida'] — se reemplazó por el
     * flag VISIBLE=0 de esa tabla, seedeado en premios/sql/setup_control_mail.sql.
     */
    private ?array $catalogoSupervisorasCache = null;

    /** @var resource Conexión a XL-APPS/POWER_BI_CONTROL (+ linked server LAKERBIS) */
    private $connPower;

    /** @var resource Conexión a XL-APPS/POWER_BI_CONTROL_FRANQUICIAS */
    private $connFranquicias;

    /** @var string[] Fines de mes (Y-m-d) cubiertos por el período actual. */
    private array $mesesActual;

    public function __construct(
        private readonly string $desde,
        private readonly string $hasta,
        private readonly string $desdePrev,
        private readonly string $hastaPrev,
    ) {
        require_once __DIR__ . '/../../class/classEnv.php';
        require_once __DIR__ . '/../../class/Conexion.php';

        $this->connPower = (new Conexion())->conectar('power');
        if (!$this->connPower) {
            throw new RuntimeException('No se pudo conectar a XL-APPS/POWER_BI_CONTROL');
        }
        $this->connFranquicias = (new Conexion())->conectar('power_franquicias');
        if (!$this->connFranquicias) {
            throw new RuntimeException('No se pudo conectar a XL-APPS/POWER_BI_CONTROL_FRANQUICIAS');
        }

        $this->mesesActual = self::finesDeMes($desde, $hasta);
        // Nota: $desdePrev/$hastaPrev no se usan para consultar filas del período previo:
        // las tablas de hechos ya traen IMP_FACT_ANT (mismo mes, año anterior) precalculado
        // por el ETL para cada fila, así que la comparación interanual sale de la misma
        // consulta. Si el usuario elige "Rango personalizado" con un período de comparación
        // que NO es el mismo rango un año atrás, esta columna no lo reflejará — limitación
        // conocida del origen de datos, no de este código.
    }

    /** Lista de fines de mes (Y-m-d) que cubre un rango [desde,hasta]. */
    private static function finesDeMes(string $desde, string $hasta): array
    {
        $fechas = [];
        $cursor = new DateTime(date('Y-m-01', strtotime($desde)));
        $fin    = new DateTime($hasta);
        while ($cursor <= $fin) {
            $fechas[] = $cursor->format('Y-m-t'); // 't' = último día del mes
            $cursor->modify('first day of next month');
        }
        return $fechas;
    }

    /**
     * 'CAROLINA COMMENDATORE' (como está en la base) → 'Carolina Commendatore' (como se muestra).
     * Public: también la usa AvanceQuincenalDB::mapeoSucursalSupervisora() para formatear el
     * nombre de SUPERVISORA leído de BI_T_ESTADISTICAS_VENTAS_PROPIOS con el mismo criterio.
     */
    public static function formatearNombre(string $nombre): string
    {
        return mb_convert_case(mb_strtolower(trim($nombre), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Última fecha/hora real de actualización de los datos (no la del período seleccionado
     * por el usuario) — mismo patrón que `SalesDB::getUltimaActualizacion()` /
     * `GlobalDashboardDB::getUltimaActualizacion()`: primero se consulta
     * `sys.dm_db_index_usage_stats` (último INSERT/UPDATE real sobre la tabla); si no hay
     * estadísticas (ej. tras un reinicio del motor), se usa como fallback `MAX(FECHA)`.
     * Acá hay DOS orígenes (propios y franquicias) — se devuelve la más antigua de las dos,
     * porque si cualquiera de los dos está desactualizado, el reporte completo lo está.
     */
    public function getUltimaActualizacion(): ?string
    {
        $fechaPropios = $this->ultimaActualizacionTabla($this->connPower, 'BI_T_ESTADISTICAS_VENTAS_PROPIOS');
        $fechaFranq   = $this->ultimaActualizacionTabla($this->connFranquicias, 'BI_T_ESTADISTICAS_VENTAS_FRANQUICIAS');

        $fechas = array_values(array_filter([$fechaPropios, $fechaFranq]));
        if (!$fechas) return null;
        sort($fechas);
        return $fechas[0];
    }

    /**
     * A diferencia de `sales/` y `global/` (SPs que corren a diario, donde "desactualizado"
     * = anterior a ayer), el SP que carga las tablas de Premios corre UNA VEZ AL MES (el
     * día 1) y deja cargado el mes recién cerrado — las tablas son mensuales, así que el
     * dato "más nuevo" SIEMPRE va a tener fecha de fin del mes anterior (ej. el 17/07 los
     * datos llegan hasta el 30/06), nunca "ayer". Por eso el límite de comparación tiene que
     * ser el inicio del MES DE DATOS esperado (un mes antes del mes en curso), no el inicio
     * del mes en curso — si no, cualquier valor válido queda siempre "antes" del límite.
     *
     * Regla: pasado el día 5 del mes en curso ya se espera que el SP haya corrido este mes,
     * o sea que el dato debe llegar como mínimo hasta el mes anterior (límite = 1° del mes
     * anterior). Hasta el día 5 alcanza con que el dato llegue hasta el mes ante-anterior
     * (límite = 1° del mes ante-anterior), porque la corrida de este mes puede no haber
     * ocurrido todavía sin que sea un problema real.
     */
    public static function esDesactualizado(DateTime $dtUpdate, ?DateTime $ahora = null): bool
    {
        $ahora  = $ahora ?? new DateTime();
        $limite = new DateTime($ahora->format('Y-m-01'));
        $limite->modify('-1 month');
        if ((int)$ahora->format('j') <= 5) {
            $limite->modify('-1 month');
        }
        return $dtUpdate < $limite;
    }

    /** @param resource $conn Conexión ya abierta a la base donde vive $tabla. */
    private function ultimaActualizacionTabla($conn, string $tabla): ?string
    {
        $sql = "SELECT MAX(last_update) as last_update FROM (
                    SELECT MAX(last_user_update) as last_update
                    FROM sys.dm_db_index_usage_stats
                    WHERE database_id = DB_ID()
                      AND object_id = OBJECT_ID('dbo.$tabla')
                ) t";
        $stmt = sqlsrv_query($conn, $sql);
        $res = null;
        if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (!empty($row['last_update'])) {
                $res = is_object($row['last_update']) ? $row['last_update']->format('Y-m-d H:i:s') : $row['last_update'];
            }
            sqlsrv_free_stmt($stmt);
        }

        // Fallback: si por alguna razón no tenemos estadísticas, usamos el MAX(FECHA) de la tabla.
        if (!$res) {
            $sqlFallback = "SELECT MAX(FECHA) as last_update FROM dbo.$tabla";
            $stmtFallback = sqlsrv_query($conn, $sqlFallback);
            if ($stmtFallback !== false && $rowFallback = sqlsrv_fetch_array($stmtFallback, SQLSRV_FETCH_ASSOC)) {
                if (!empty($rowFallback['last_update'])) {
                    $res = is_object($rowFallback['last_update']) ? $rowFallback['last_update']->format('Y-m-d H:i:s') : $rowFallback['last_update'];
                }
                sqlsrv_free_stmt($stmtFallback);
            }
        }
        return $res;
    }

    /**
     * Catálogo de supervisoras activas (RO_T_SUPERVISORAS_COMERCIAL, vía linked server
     * XL-LAKERBIS, igual patrón que usa class/Filters.php para SUCURSALES_LAKERS), con su
     * mail — columna agregada por el usuario directamente en esa tabla; premios la
     * consume de SOLO LECTURA (no hay precedente en el repo de escritura hacia una tabla
     * de XL-LAKERBIS a través de este linked server, así que la edición del mail queda
     * fuera del dashboard). En el orden crudo del catálogo (RO_T_SUPERVISORAS_COMERCIAL.ID)
     * — el orden de despliegue real lo resuelve getSupervisoras() por separado.
     *
     * @return array<int,array{nombre:string,mail:?string}>
     */
    private function catalogoSupervisoras(): array
    {
        if ($this->catalogoSupervisorasCache !== null) {
            return $this->catalogoSupervisorasCache;
        }

        $sql = "SELECT NOMBRE, MAIL FROM [XL-LAKERBIS].locales_lakers.dbo.RO_T_SUPERVISORAS_COMERCIAL
                WHERE ACTIVA = 1 ORDER BY ID";
        $stmt = sqlsrv_query($this->connPower, $sql);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando RO_T_SUPERVISORAS_COMERCIAL: ' . print_r(sqlsrv_errors(), true));
        }
        $out = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = ['nombre' => self::formatearNombre($r['NOMBRE']), 'mail' => $r['MAIL'] ?: null];
        }
        return $this->catalogoSupervisorasCache = $out;
    }

    /**
     * @return string[] Supervisoras activas y VISIBLES en este dashboard (ver
     * getVisibilidadSupervisoras()), en el orden guardado en
     * BI_T_PREMIOS_SUPERVISORAS_ORDEN (editable desde el configurador — ver
     * guardarConfiguracionSupervisoras()). Una supervisora sin orden guardado todavía se
     * agrega al final, en el orden del catálogo.
     */
    public function getSupervisoras(): array
    {
        $nombres = array_column($this->catalogoSupervisoras(), 'nombre');
        $visibilidad = $this->getVisibilidadSupervisoras();
        $nombres = array_values(array_filter($nombres, fn($n) => $visibilidad[$n] ?? true));

        $orden = $this->getOrdenSupervisoras();
        $posicionCatalogo = array_flip(array_column($this->catalogoSupervisoras(), 'nombre'));
        usort($nombres, function ($a, $b) use ($orden, $posicionCatalogo) {
            $posA = $orden[$a] ?? (1000 + $posicionCatalogo[$a]);
            $posB = $orden[$b] ?? (1000 + $posicionCatalogo[$b]);
            return $posA <=> $posB;
        });
        return $nombres;
    }

    /**
     * @return array<int,array{nombre:string,mail:?string,visible:bool}> TODAS las
     * supervisoras activas del catálogo (incluidas las ocultas, ej. Julieta Dalmeida),
     * en el mismo orden guardado que getSupervisoras() — para el configurador del
     * dashboard ("Configuración de supervisoras"), que necesita poder re-mostrar una
     * supervisora oculta.
     */
    public function catalogoConfigSupervisoras(): array
    {
        $catalogo = $this->catalogoSupervisoras();
        $orden = $this->getOrdenSupervisoras();
        $visibilidad = $this->getVisibilidadSupervisoras();
        $posicionCatalogo = array_flip(array_column($catalogo, 'nombre'));

        usort($catalogo, function ($a, $b) use ($orden, $posicionCatalogo) {
            $posA = $orden[$a['nombre']] ?? (1000 + $posicionCatalogo[$a['nombre']]);
            $posB = $orden[$b['nombre']] ?? (1000 + $posicionCatalogo[$b['nombre']]);
            return $posA <=> $posB;
        });

        return array_map(fn($s) => [
            'nombre'  => $s['nombre'],
            'mail'    => $s['mail'],
            'visible' => $visibilidad[$s['nombre']] ?? true,
        ], $catalogo);
    }

    /** Mail de una supervisora tal como está en RO_T_SUPERVISORAS_COMERCIAL.MAIL, o null si no tiene. */
    public function getEmailSupervisora(string $supervisora): ?string
    {
        foreach ($this->catalogoSupervisoras() as $s) {
            if ($s['nombre'] === $supervisora) {
                return $s['mail'];
            }
        }
        return null;
    }

    /* ─────────────────────────────────────────────────────────
     * Orden y visibilidad de supervisoras (BI_T_PREMIOS_SUPERVISORAS_ORDEN) — editables
     * desde el modal "Configuración de supervisoras" del dashboard (arrastrar para
     * reordenar, tilde para mostrar/ocultar). Vive en POWER_BI_CONTROL (conexión
     * 'power'), no en XL-LAKERBIS: es una preferencia de despliegue propia de premios,
     * no un dato del catálogo comercial compartido — el catálogo (activa/inactiva,
     * mail) sigue viviendo en RO_T_SUPERVISORAS_COMERCIAL.
     * ───────────────────────────────────────────────────────── */

    /** @return array<string,int> Mapa SUPERVISORA => ORDEN guardado. */
    public function getOrdenSupervisoras(): array
    {
        $sql = "SELECT SUPERVISORA, ORDEN FROM BI_T_PREMIOS_SUPERVISORAS_ORDEN";
        $stmt = sqlsrv_query($this->connPower, $sql);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_T_PREMIOS_SUPERVISORAS_ORDEN: ' . print_r(sqlsrv_errors(), true));
        }
        $out = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[$r['SUPERVISORA']] = (int) $r['ORDEN'];
        }
        return $out;
    }

    /**
     * @return array<string,bool> Mapa SUPERVISORA => VISIBLE guardado. Una supervisora
     * activa que todavía no tiene fila acá (ej. recién agregada al catálogo) se
     * considera visible por defecto — se muestra automáticamente sin que haga falta
     * tocar el configurador, y solo se oculta si alguien la desmarca explícitamente.
     */
    public function getVisibilidadSupervisoras(): array
    {
        $sql = "SELECT SUPERVISORA, VISIBLE FROM BI_T_PREMIOS_SUPERVISORAS_ORDEN";
        $stmt = sqlsrv_query($this->connPower, $sql);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_T_PREMIOS_SUPERVISORAS_ORDEN: ' . print_r(sqlsrv_errors(), true));
        }
        $out = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[$r['SUPERVISORA']] = (bool) $r['VISIBLE'];
        }
        return $out;
    }

    /**
     * Reemplaza la configuración guardada (orden + visibilidad) por la lista completa
     * recibida, ya en el orden deseado. Se borra y reinserta dentro de una transacción en
     * vez de upsert fila por fila — la tabla es chica (una fila por supervisora activa
     * del catálogo, visible u oculta) y así se evita dejarla en un estado mixto (mitad
     * vieja/mitad nueva) si el guardado se corta a la mitad.
     *
     * @param array<int,array{nombre:string,visible:bool}> $itemsEnOrden Debe incluir TODAS
     *        las supervisoras activas del catálogo (visibles y ocultas) — ver
     *        catalogoConfigSupervisoras() y la validación en api/supervisoras_orden.php.
     */
    public function guardarConfiguracionSupervisoras(array $itemsEnOrden, string $usuario): void
    {
        if (!sqlsrv_begin_transaction($this->connPower)) {
            throw new RuntimeException('No se pudo iniciar la transacción: ' . print_r(sqlsrv_errors(), true));
        }
        try {
            $stmtDelete = sqlsrv_query($this->connPower, 'DELETE FROM BI_T_PREMIOS_SUPERVISORAS_ORDEN');
            if ($stmtDelete === false) {
                throw new RuntimeException('Error limpiando BI_T_PREMIOS_SUPERVISORAS_ORDEN: ' . print_r(sqlsrv_errors(), true));
            }
            $sqlInsert = "INSERT INTO BI_T_PREMIOS_SUPERVISORAS_ORDEN (SUPERVISORA, ORDEN, VISIBLE, ACTUALIZADO_POR, FECHA_ACTUALIZACION)
                          VALUES (?, ?, ?, ?, GETDATE())";
            foreach (array_values($itemsEnOrden) as $i => $item) {
                $stmtInsert = sqlsrv_query($this->connPower, $sqlInsert, [$item['nombre'], $i + 1, $item['visible'], $usuario]);
                if ($stmtInsert === false) {
                    throw new RuntimeException('Error insertando en BI_T_PREMIOS_SUPERVISORAS_ORDEN: ' . print_r(sqlsrv_errors(), true));
                }
            }
            sqlsrv_commit($this->connPower);
        } catch (Throwable $e) {
            sqlsrv_rollback($this->connPower);
            throw $e;
        }
    }

    /* ─────────────────────────────────────────────────────────
     * Datos reales — Locales Propios / Franquicias
     * ───────────────────────────────────────────────────────── */

    /**
     * @return array<int,array> Filas agregadas por sucursal para el período actual.
     *   - $supervisora = null   → TODAS las filas, incluida la fila sintética "TODAS"
     *                             (ECOMMERCE). Necesario así: los benchmarks de "Marca"
     *                             (ticketPromedioMarca, facturacionVarMarca, etc.) SÍ
     *                             incluyen el canal ecommerce (confirmado: sin él, el
     *                             benchmark de ticket promedio da $328.000 en vez de los
     *                             $281.900 reales), y el conteo empresa-wide de Venta solo
     *                             excluye NRO_SUCURS=1 "CENTRAL", no el 9 "ECOMMERCE".
     *   - $supervisora = 'TODAS' → solo la fila sintética agregada (para el caso especial
     *                             de Carolina Commendatore).
     *   - $supervisora = '<nombre>' → solo las sucursales de esa supervisora (nunca incluye
     *                             "TODAS", que no pertenece a ninguna supervisora real).
     */
    public function datosPropios(?string $supervisora = null): array
    {
        if (!$this->mesesActual) return [];

        $placeholders = implode(',', array_fill(0, count($this->mesesActual), '?'));
        $params = $this->mesesActual;

        // El filtro de supervisora se aplica ANTES del ROW_NUMBER (no después): si no,
        // dos filas de un mismo NRO_SUCURS+FECHA con SUPERVISORA distinta (p.ej. una
        // "TODAS" y otra no) podrían pisarse entre sí en el dedup y perder datos reales.
        $condSup = '';
        if ($supervisora !== null) {
            $condSup = 'AND UPPER(SUPERVISORA) = UPPER(?)';
            $params[] = $supervisora;
        }

        // Deduplicar por (NRO_SUCURS, FECHA): la tabla origen tiene filas repetidas para
        // la misma sucursal+mes, y no siempre son idénticas en TODAS las columnas (se vio
        // un caso real con IMP_FACT_S_IVA distinto entre filas "duplicadas"), así que un
        // SELECT DISTINCT sobre la fila completa no alcanza para deduplicar de forma
        // confiable. Nos quedamos con una sola fila por sucursal+mes vía ROW_NUMBER().
        $sql = "
            SELECT NRO_SUCURS,
                   MAX(SUCURSAL)          AS SUCURSAL,
                   MAX(SUPERVISORA)       AS SUPERVISORA,
                   SUM(IMP_FACT)          AS IMP_FACT,
                   SUM(IMP_FACT_ANT)      AS IMP_FACT_ANT,
                   SUM(IMP_FACT_S_IVA)    AS IMP_FACT_S_IVA,
                   SUM(IMP_OBJ)           AS IMP_OBJ,
                   SUM(TICKETS)           AS TICKETS,
                   SUM(TICKETS_2DO_PROD)  AS TICKETS_2DO_PROD,
                   SUM(TICKETS_3ER_PROD)  AS TICKETS_3ER_PROD,
                   MAX(P_OBJ_VENTA)       AS P_OBJ_VENTA,
                   MAX(P_OBJ_CREC_VTA)    AS P_OBJ_CREC_VTA,
                   MAX(P_TICKET_PROM)     AS P_TICKET_PROM,
                   MAX(P_TICKET_2PROD)    AS P_TICKET_2PROD,
                   MAX(P_TICKET_3PROD)    AS P_TICKET_3PROD
            FROM (
                SELECT *, ROW_NUMBER() OVER (PARTITION BY NRO_SUCURS, FECHA ORDER BY (SELECT NULL)) AS RN
                FROM BI_T_ESTADISTICAS_VENTAS_PROPIOS
                WHERE FECHA IN ($placeholders)
                $condSup
            ) d
            WHERE d.RN = 1
            GROUP BY NRO_SUCURS
        ";
        $stmt = sqlsrv_query($this->connPower, $sql, $params);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_T_ESTADISTICAS_VENTAS_PROPIOS: ' . print_r(sqlsrv_errors(), true));
        }

        $out = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = $this->filaDesdeDB($r, 'IMP_FACT', 'TICKETS');
        }
        return $out;
    }

    /**
     * @return array<int,array> Filas agregadas por sucursal franquicia para el período actual,
     *                          SIN filtro de supervisora (no existe esa relación, ver README).
     */
    public function datosFranquicias(?string $supervisora = null): array
    {
        // $supervisora se ignora a propósito: no existe relación sucursal-franquicia → supervisora
        // (ni en SQL ni en el modelo del .pbix original). El desglose de premios de franquicias
        // se resuelve a nivel empresa + importe por supervisora, ver premiosFranquiciasEmpresa()
        // e importesFranquiciaPorSupervisora().
        if (!$this->mesesActual) return [];

        $placeholders = implode(',', array_fill(0, count($this->mesesActual), '?'));

        // Dedup vía ROW_NUMBER(), mismo criterio que datosPropios() — ver comentario ahí.
        $sql = "
            SELECT NRO_SUCURS,
                   MAX(SUCURSAL)     AS SUCURSAL,
                   SUM(IMP_FACT)     AS IMP_FACT,
                   SUM(IMP_FACT_ANT) AS IMP_FACT_ANT,
                   SUM(IMP_OBJ)      AS IMP_OBJ
            FROM (
                SELECT *, ROW_NUMBER() OVER (PARTITION BY NRO_SUCURS, FECHA ORDER BY (SELECT NULL)) AS RN
                FROM BI_T_ESTADISTICAS_VENTAS_FRANQUICIAS
                WHERE FECHA IN ($placeholders)
            ) d
            WHERE d.RN = 1
            GROUP BY NRO_SUCURS
        ";
        $stmt = sqlsrv_query($this->connFranquicias, $sql, $this->mesesActual);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_T_ESTADISTICAS_VENTAS_FRANQUICIAS: ' . print_r(sqlsrv_errors(), true));
        }

        $out = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[] = [
                'nro_sucurs'   => (int) $r['NRO_SUCURS'],
                'sucursal'     => $r['SUCURSAL'],
                'supervisora'  => null,
                'casa_central' => false,
                'sin_datos'    => (float) $r['IMP_FACT'] == 0.0 && (float) $r['IMP_OBJ'] == 0.0,
                'imp_fact'     => (float) $r['IMP_FACT'],
                'imp_fact_ant' => (float) $r['IMP_FACT_ANT'],
                'imp_obj'      => (float) $r['IMP_OBJ'],
            ];
        }
        return $out;
    }

    /** Arma la fila con el shape común a partir de una fila agregada de BD (locales propios). */
    private function filaDesdeDB(array $r, string $campoFact, string $campoTickets): array
    {
        $fact = (float) $r['IMP_FACT'];
        $obj  = (float) $r['IMP_OBJ'];
        return [
            'nro_sucurs'       => (int) $r['NRO_SUCURS'],
            'sucursal'         => $r['SUCURSAL'],
            'supervisora'      => $r['SUPERVISORA'] !== null ? self::formatearNombre($r['SUPERVISORA']) : null,
            'casa_central'     => (int) $r['NRO_SUCURS'] === self::CASA_CENTRAL_NRO,
            'sin_datos'        => $fact == 0.0 && $obj == 0.0,
            'imp_fact'         => $fact,
            'imp_fact_ant'     => (float) $r['IMP_FACT_ANT'],
            'imp_fact_s_iva'   => (float) $r['IMP_FACT_S_IVA'],
            'imp_obj'          => $obj,
            'tickets'          => (int) $r['TICKETS'],
            'tickets_2do_prod' => (int) $r['TICKETS_2DO_PROD'],
            'tickets_3er_prod' => (int) $r['TICKETS_3ER_PROD'],
            'p_obj_venta'      => (float) $r['P_OBJ_VENTA'],
            'p_obj_crec_vta'   => (float) $r['P_OBJ_CREC_VTA'],
            'p_ticket_prom'    => (float) $r['P_TICKET_PROM'],
            'p_ticket_2prod'   => (float) $r['P_TICKET_2PROD'],
            'p_ticket_3prod'   => (float) $r['P_TICKET_3PROD'],
        ];
    }

    /**
     * Importes de premio de franquicias por supervisora (P_OBJ_VENTA_F con MIN, P_OBJ_CREC_VTA_F
     * con MAX — así los define el DAX real: `Premio Obj. Venta Franq. (importe) = MIN(...)`,
     * `Premio Obj. Crecimiento Franq. (importe) = MAX(...)`. El MIN/MAX no es "el mínimo/máximo
     * de toda la empresa": la tabla SÍ filtra por SUPERVISORA, y se usa MIN/MAX únicamente para
     * colapsar en un solo valor las filas duplicadas que existen para la misma SUPERVISORA+mes.
     *
     * @return array<string,array{venta:float,crecimiento:float}> indexado por nombre de supervisora
     */
    public function importesFranquiciaPorSupervisora(): array
    {
        if (!$this->mesesActual) return [];

        $placeholders = implode(',', array_fill(0, count($this->mesesActual), '?'));
        $sql = "
            SELECT SUPERVISORA, MIN(P_OBJ_VENTA_F) AS P_OBJ_VENTA_F, MAX(P_OBJ_CREC_VTA_F) AS P_OBJ_CREC_VTA_F
            FROM (
                SELECT DISTINCT FECHA, SUPERVISORA, P_OBJ_VENTA_F, P_OBJ_CREC_VTA_F
                FROM BI_T_PREMIOS_SUPERVISION_FRANQUICIAS
                WHERE FECHA IN ($placeholders)
            ) d
            GROUP BY SUPERVISORA
        ";
        $stmt = sqlsrv_query($this->connFranquicias, $sql, $this->mesesActual);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_T_PREMIOS_SUPERVISION_FRANQUICIAS: ' . print_r(sqlsrv_errors(), true));
        }

        $out = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[self::formatearNombre($r['SUPERVISORA'])] = [
                'venta'       => (float) $r['P_OBJ_VENTA_F'],
                'crecimiento' => (float) $r['P_OBJ_CREC_VTA_F'],
            ];
        }
        return $out;
    }

    /**
     * Fecha (Y-m-d, fin de mes) del período actual si resuelve a UN SOLO mes, o null si el
     * rango cubre 0 o 2+ meses. El estado "Controlado" (ver marcarControlado()) solo tiene
     * sentido para un mes puntual — las tablas de origen son mensuales.
     */
    public function mesUnico(): ?string
    {
        return count($this->mesesActual) === 1 ? $this->mesesActual[0] : null;
    }

    /**
     * true si TODAS las supervisoras activas (getSupervisoras()) están marcadas
     * "Controlado" para ese mes. Usado para disparar el envío automático del resumen
     * mensual — ver MailPremios::enviarResumenMensual() y api/marcar_controlado.php.
     */
    public function todasSupervisorasControladas(string $mes): bool
    {
        $activas = $this->getSupervisoras();
        if (!$activas) return false;
        $estado = $this->getControladoBulk($mes, $activas);
        foreach ($activas as $sup) {
            if (!($estado[$sup]['controlado'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    /* ─────────────────────────────────────────────────────────
     * Estado "Controlado" (BI_T_PREMIOS_CONTROL) — cierre mensual por supervisora,
     * y mapeo supervisora→email (BI_T_PREMIOS_SUPERVISORAS_EMAIL) para el envío de mail
     * individual. Ambas tablas viven en POWER_BI_CONTROL (conexión 'power'), junto a las
     * tablas de hechos — ver premios/sql/setup_control_mail.sql para su creación.
     * ───────────────────────────────────────────────────────── */

    /**
     * @param string $mes Fin de mes (Y-m-d) — ver mesUnico().
     * @param string[] $supervisoras
     * @return array<string,array{controlado:bool,usuario:?string,fecha_control:?string}>
     */
    public function getControladoBulk(string $mes, array $supervisoras): array
    {
        $out = [];
        foreach ($supervisoras as $sup) {
            $out[$sup] = ['controlado' => false, 'usuario' => null, 'fecha_control' => null];
        }
        if (!$supervisoras) return $out;

        $placeholders = implode(',', array_fill(0, count($supervisoras), '?'));
        $sql = "SELECT SUPERVISORA, CONTROLADO, USUARIO, FECHA_CONTROL
                FROM BI_T_PREMIOS_CONTROL
                WHERE MES = ? AND SUPERVISORA IN ($placeholders)";
        $params = array_merge([$mes], $supervisoras);
        $stmt = sqlsrv_query($this->connPower, $sql, $params);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_T_PREMIOS_CONTROL: ' . print_r(sqlsrv_errors(), true));
        }
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $out[$r['SUPERVISORA']] = [
                'controlado'    => (bool) $r['CONTROLADO'],
                'usuario'       => $r['USUARIO'],
                'fecha_control' => $r['FECHA_CONTROL'] instanceof DateTime ? $r['FECHA_CONTROL']->format('d/m/Y H:i:s') : $r['FECHA_CONTROL'],
            ];
        }
        return $out;
    }

    /** Marca/desmarca "Controlado" para (mes, supervisora); registra usuario y fecha del cambio. */
    public function marcarControlado(string $mes, string $supervisora, bool $controlado, string $usuario): array
    {
        $sql = "MERGE BI_T_PREMIOS_CONTROL AS target
                USING (SELECT ? AS MES, ? AS SUPERVISORA) AS src
                ON target.MES = src.MES AND target.SUPERVISORA = src.SUPERVISORA
                WHEN MATCHED THEN
                    UPDATE SET CONTROLADO = ?, USUARIO = ?, FECHA_CONTROL = GETDATE()
                WHEN NOT MATCHED THEN
                    INSERT (MES, SUPERVISORA, CONTROLADO, USUARIO, FECHA_CONTROL)
                    VALUES (?, ?, ?, ?, GETDATE());";
        $params = [$mes, $supervisora, $controlado, $usuario, $mes, $supervisora, $controlado, $usuario];
        $stmt = sqlsrv_query($this->connPower, $sql, $params);
        if ($stmt === false) {
            throw new RuntimeException('Error al grabar BI_T_PREMIOS_CONTROL: ' . print_r(sqlsrv_errors(), true));
        }
        return $this->getControladoBulk($mes, [$supervisora])[$supervisora];
    }

    /**
     * Arma el resumen de premios (Propios+Franquicias) por supervisora — misma construcción
     * que usa api/resumen.php, extraída acá para reusarla también en el mail de resumen
     * mensual (MailPremios::renderResumenMensual()) sin duplicar la lógica.
     *
     * @param string[] $supervisoras
     * @return array{filas:array<int,array>, pct_cumplimiento_cadena_total:float}
     */
    public function resumenPorSupervisora(array $supervisoras): array
    {
        $propiosTodos     = $this->datosPropios(null);
        $filasTodas       = $this->datosPropios('TODAS');
        $franquiciasTodos = $this->datosFranquicias();

        $benchmarks = [
            'var_marca'    => $this->benchmarkVarMarca($propiosTodos),
            'ticket_marca' => $this->ticketPromedioMarca($propiosTodos),
            'pct2_marca'   => $this->pctTicketProductoMarca($propiosTodos, 'tickets_2do_prod'),
            'pct3_marca'   => $this->pctTicketProductoMarca($propiosTodos, 'tickets_3er_prod'),
        ];

        $conteosFranquiciaEmpresa = $this->conteosFranquiciaEmpresa($franquiciasTodos);
        $importesFranquiciaPorSup = $this->importesFranquiciaPorSupervisora();

        $out = [];
        $filasPropiasTodasLasSup = [];
        foreach ($supervisoras as $sup) {
            $filasPropias = $this->datosPropios($sup);
            $filasPropiasTodasLasSup = array_merge($filasPropiasTodasLasSup, $filasPropias);

            $propios     = $this->premiosPropiosSupervisora($sup, $filasPropias, $propiosTodos, $filasTodas, $benchmarks);
            $franquicias = $this->premiosFranquiciasSupervisora($conteosFranquiciaEmpresa, $importesFranquiciaPorSup[$sup] ?? []);

            $out[] = [
                'supervisora'             => $sup,
                'total_premios'           => $propios['total'] + $franquicias['total'],
                'propios'                 => $propios,
                'franquicias'             => $franquicias,
                'pct_cumplimiento_cadena' => $this->pctCumplimientoCadenaIndicadores($filasPropias, $benchmarks),
            ];
        }

        return [
            'filas' => $out,
            'pct_cumplimiento_cadena_total' => $this->pctCumplimientoCadenaIndicadores($filasPropiasTodasLasSup, $benchmarks),
        ];
    }

    /* ─────────────────────────────────────────────────────────
     * Reglas de negocio — fórmulas base
     * ───────────────────────────────────────────────────────── */

    public function facturacionVarPct(float $fact, float $factAnt): ?float
    {
        if ($factAnt <= 0) return $fact > 0 ? null : -1.0;
        return $fact / $factAnt - 1;
    }

    public function cumplimientoObjVenta(float $fact, float $obj): float
    {
        if ($obj <= 0) return -1.0;
        return $fact / $obj - 1;
    }

    /**
     * % de indicadores secundarios (Ticket Promedio + Ticket 2do Producto + Ticket 3er
     * Producto) que llegan a su benchmark de marca, sobre el TOTAL de indicadores posibles
     * de la cadena: cada local real (excluye NRO_SUCURS=1 "CENTRAL") aporta 3 indicadores,
     * así que una supervisora con 5 locales tiene 15 en la base del cálculo — no es un
     * promedio de 3 porcentajes, es cuántos de esos N×3 indicadores individuales cumplen.
     * Mismos umbrales que premioTicketPromedio()/premioTicketProducto() (cada indicador se
     * evalúa de forma independiente, igual que los 3 badges por fila que se ven en la UI —
     * sin relación con si la fila ganó o no el objetivo de venta).
     *
     * Un local "sin datos" en el período (fact=0 y obj=0) suma sus 3 indicadores al total
     * pero nunca como cumplidos — mismo criterio que ya usaba esta columna cuando medía
     * solo objetivo de venta, ahora aplicado indicador por indicador.
     *
     * @param array $benchmarksTicket ['ticket_marca'=>float,'pct2_marca'=>float,'pct3_marca'=>float]
     *                                (mismo array $benchmarks que ya se arma para los premios)
     */
    public function pctCumplimientoCadenaIndicadores(array $filasSupervisora, array $benchmarksTicket): float
    {
        $total = 0;
        $cumple = 0;
        foreach ($filasSupervisora as $f) {
            if ($f['casa_central']) continue;
            $total += 3;
            if ($f['sin_datos']) continue;

            if ($this->ticketPromedioEst($f['imp_fact'], $f['tickets']) > $benchmarksTicket['ticket_marca']) {
                $cumple++;
            }
            if ($f['tickets'] > 0 && ($f['tickets_2do_prod'] / $f['tickets']) > $benchmarksTicket['pct2_marca']) {
                $cumple++;
            }
            if ($f['tickets'] > 0 && ($f['tickets_3er_prod'] / $f['tickets']) > $benchmarksTicket['pct3_marca']) {
                $cumple++;
            }
        }
        return $total > 0 ? $cumple / $total : 0.0;
    }

    /** Ticket promedio estimado, redondeado hacia arriba a la centena (CEILING(fact/tickets,100)). */
    public function ticketPromedioEst(float $fact, int $tickets): float
    {
        if ($tickets <= 0) return 0.0;
        return ceil(($fact / $tickets) / 100) * 100;
    }

    /** Variación de facturación de marca (sin filtro de sucursal/supervisora), sin el +10 del benchmark. */
    public function facturacionVarMarca(array $filasSinFiltrar): float
    {
        $fact = 0.0; $factAnt = 0.0;
        foreach ($filasSinFiltrar as $f) {
            if ($f['sin_datos']) continue;
            $fact    += $f['imp_fact'];
            $factAnt += $f['imp_fact_ant'];
        }
        return $factAnt > 0 ? ($fact / $factAnt - 1) : 0.0;
    }

    /**
     * Benchmark de marca (Locales Propios) para variación de facturación, con el +10
     * puntos ADITIVO exigido para el premio de crecimiento. Confirmado por DAX:
     * `Facturación Var % All = CALCULATE([Facturación Var %], ALL(...))+0.1`.
     */
    public function benchmarkVarMarca(array $filasSinFiltrar): float
    {
        return $this->facturacionVarMarca($filasSinFiltrar) + self::BENCHMARK_PLUS;
    }

    /**
     * Benchmark de marca (Franquicias) para variación de facturación — a diferencia de
     * Locales Propios, acá el +10% es MULTIPLICATIVO, no aditivo. Confirmado por DAX:
     * `Facturación Var % All Franq. = CALCULATE([Facturación Var % Franq.], ALL(...)) * 1.1`
     * (con `[Facturación Var % Franq.]` en formato ratio, no delta — de ahí la diferencia
     * de fórmula respecto a Locales Propios). Ejemplo real: agregado +2,4842% de var% da
     * un benchmark de +12,7326% (no +12,4842% como daría la fórmula aditiva) — confirmado
     * contra el KPI real "Facturación Var % All Franq.: 112,73 %".
     */
    public function benchmarkVarMarcaFranquicias(array $filasSinFiltrar): float
    {
        $var = $this->facturacionVarMarca($filasSinFiltrar);
        return (1 + $var) * 1.1 - 1;
    }

    /** Ticket promedio de marca (sin filtro), para comparar contra cada sucursal. */
    public function ticketPromedioMarca(array $filasSinFiltrar): float
    {
        $fact = 0.0; $tickets = 0;
        foreach ($filasSinFiltrar as $f) {
            if ($f['sin_datos']) continue;
            $fact    += $f['imp_fact'];
            $tickets += $f['tickets'];
        }
        return $this->ticketPromedioEst($fact, $tickets);
    }

    /**
     * % ticket 2do/3er producto de marca. A diferencia de ticketPromedioMarca/
     * facturacionVarMarca (que sí incluyen ECOMMERCE y CENTRAL — ver datosPropios()), acá se
     * excluyen: ECOMMERCE no tiene venta cruzada de 2do/3er producto (tickets sin ese
     * atributo), y CENTRAL no tiene tickets propios — ambos solo arrastrarían el promedio
     * hacia abajo sin representar ventas reales de ningún local.
     */
    public function pctTicketProductoMarca(array $filasSinFiltrar, string $campo): float
    {
        $tickets = 0; $prod = 0;
        foreach ($filasSinFiltrar as $f) {
            if ($f['sin_datos'] || $f['casa_central'] || $f['supervisora'] === 'Todas') continue;
            $tickets += $f['tickets'];
            $prod    += $f[$campo];
        }
        return $tickets > 0 ? $prod / $tickets : 0.0;
    }

    /* ─────────────────────────────────────────────────────────
     * Premios — Locales Propios
     * ───────────────────────────────────────────────────────── */

    /**
     * Premio Objetivo Venta + Premio Objetivo Crecimiento (Locales Propios).
     *
     * Confirmado contra el DAX real del .pbix: para TODAS las supervisoras excepto
     * Carolina Commendatore, la CANTIDAD de sucursales que cumplen objetivo de venta y
     * de crecimiento es un número A NIVEL EMPRESA (measures `Premio Obj. Venta Cant.
     * Suc.` / `Premio Obj. Crecimiento` usan `ALL(BI_T_ESTADISTICAS_VENTAS_PROPIOS[SUPERVISORA])`),
     * igual para todas — solo el IMPORTE (P_OBJ_VENTA/P_OBJ_CREC_VTA) es el propio de
     * cada supervisora. Venta además excluye NRO_SUCURS=1 ("CENTRAL"); Crecimiento no la
     * excluye explícitamente, pero es inofensivo porque CENTRAL siempre tiene FACT=0.
     *
     * Carolina Commendatore usa medidas separadas (`...Caro`):
     *   - Venta: compara la facturación total agrupada de SUS sucursales + la fila
     *     sintética "TODAS" contra el objetivo total agrupado, SIN tolerancia (umbral en
     *     0 en vez de -0.5%) y sin excluir ninguna sucursal. Si esa comparación agregada
     *     da positiva, cuentan TODAS sus sucursales; si no, ninguna.
     *   - Crecimiento: sin `ALL()` — se evalúa solo sobre sus propias sucursales (el
     *     cálculo "normal", sin el atajo empresa-wide que reciben las demás).
     *
     * @param string $supervisora       Nombre de la supervisora (con mayúscula inicial)
     * @param array  $filasSupervisora  Sus propias sucursales (ya filtradas)
     * @param array  $todosLosPropios   Todas las sucursales propias con supervisora real
     *                                  (sin la fila "TODAS"), para el cálculo empresa-wide
     * @param array  $filasTodas        Filas con SUPERVISORA='TODAS' del período (datosPropios('TODAS'))
     * @param float  $benchmarkVarMarca Benchmark de marca (+10pp) para crecimiento
     */
    public function premiosVentaCrecimiento(
        string $supervisora,
        array $filasSupervisora,
        array $todosLosPropios,
        array $filasTodas,
        float $benchmarkVarMarca
    ): array {
        $esCarolina = strcasecmp($supervisora, 'Carolina Commendatore') === 0;

        // Importe: siempre el propio de la supervisora (ver importeDeFilas()).
        $importeVenta = $this->importeDeFilas($filasSupervisora, 'p_obj_venta');
        $importeCrec  = $this->importeDeFilas($filasSupervisora, 'p_obj_crec_vta');

        if ($esCarolina) {
            $cantVenta = $this->cantVentaCarolina($filasSupervisora, $filasTodas);
            $cantCrec  = $this->contarCrecimiento($filasSupervisora, $benchmarkVarMarca);
        } else {
            $cantVenta = $this->contarVentaEmpresa($todosLosPropios);
            $cantCrec  = $this->contarCrecimiento($todosLosPropios, $benchmarkVarMarca);
        }

        $venta = ['cant' => $cantVenta, 'importe' => $importeVenta, 'premio' => $cantVenta * $importeVenta];
        $crec  = ['cant' => $cantCrec,  'importe' => $importeCrec,  'premio' => $cantCrec * $importeCrec];
        return ['venta' => $venta, 'crecimiento' => $crec];
    }

    /** Cantidad de sucursales (a nivel empresa, todas las supervisoras) que cumplen objetivo de venta. */
    private function contarVentaEmpresa(array $todosLosPropios): int
    {
        $cant = 0;
        foreach ($todosLosPropios as $f) {
            if ($f['sin_datos'] || $f['casa_central']) continue;
            $cumpl = $this->cumplimientoObjVenta($f['imp_fact'], $f['imp_obj']);
            if ($cumpl > self::TOLERANCIA_OBJ_VENTA) $cant++;
        }
        return $cant;
    }

    /** Cantidad de sucursales del set dado que cumplen la condición de crecimiento "consuelo". */
    private function contarCrecimiento(array $filas, float $benchmarkVarMarca): int
    {
        $cant = 0;
        foreach ($filas as $f) {
            if ($f['sin_datos']) continue;
            $var   = $this->facturacionVarPct($f['imp_fact'], $f['imp_fact_ant']);
            $cumpl = $this->cumplimientoObjVenta($f['imp_fact'], $f['imp_obj']);
            if ($var !== null && $var > $benchmarkVarMarca && $cumpl < 0) $cant++;
        }
        return $cant;
    }

    /**
     * Caso especial Carolina Commendatore: si (fact de sus sucursales + "TODAS") supera
     * (objetivo de sus sucursales + "TODAS"), sin tolerancia, cuentan TODAS sus sucursales;
     * si no, ninguna.
     */
    private function cantVentaCarolina(array $filasCarolina, array $filasTodas): int
    {
        $factAgg = 0.0; $objAgg = 0.0;
        foreach (array_merge($filasCarolina, $filasTodas) as $f) {
            $factAgg += $f['imp_fact'];
            $objAgg  += $f['imp_obj'];
        }
        return $factAgg > $objAgg ? count($filasCarolina) : 0;
    }

    /**
     * Importe de premio de una supervisora: se toma de cualquier fila suya (incluidas
     * sin_datos/casa_central) porque P_OBJ_VENTA/P_TICKET_PROM/etc. es una tasa que viene
     * poblada aunque esa fila puntual no tenga facturación (ej. la fila sintética "CENTRAL"
     * de una supervisora sin sucursales propias reales en el período). El DAX real
     * (`MAX(P_TICKET_PROM)`, etc.) tampoco filtra por eso.
     */
    private function importeDeFilas(array $filas, string $campo): float
    {
        $importe = 0.0;
        foreach ($filas as $f) {
            $importe = $f[$campo];
        }
        return $importe;
    }

    public function premioTicketPromedio(array $filasSupervisora, float $ticketMarca): array
    {
        $cant = 0;
        foreach ($filasSupervisora as $f) {
            if ($f['sin_datos'] || $f['casa_central']) continue;
            if ($this->ticketPromedioEst($f['imp_fact'], $f['tickets']) > $ticketMarca) $cant++;
        }
        $importe = $this->importeDeFilas($filasSupervisora, 'p_ticket_prom');
        return ['cant' => $cant, 'importe' => $importe, 'premio' => $cant * $importe];
    }

    public function premioTicketProducto(array $filasSupervisora, string $campo, float $pctMarca, string $campoImporte): array
    {
        $cant = 0;
        foreach ($filasSupervisora as $f) {
            if ($f['sin_datos'] || $f['casa_central'] || $f['tickets'] <= 0) continue;
            $pct = $f[$campo] / $f['tickets'];
            if ($pct > $pctMarca) $cant++;
        }
        $importe = $this->importeDeFilas($filasSupervisora, $campoImporte);
        return ['cant' => $cant, 'importe' => $importe, 'premio' => $cant * $importe];
    }

    /**
     * Arma los 5 premios de Locales Propios para una supervisora + el total.
     * $benchmarks = ['var_marca'=>float, 'ticket_marca'=>float, 'pct2_marca'=>float, 'pct3_marca'=>float]
     * $todosLosPropios / $filasTodas: ver premiosVentaCrecimiento().
     */
    public function premiosPropiosSupervisora(
        string $supervisora,
        array $filasSupervisora,
        array $todosLosPropios,
        array $filasTodas,
        array $benchmarks
    ): array {
        $ventaCrec = $this->premiosVentaCrecimiento($supervisora, $filasSupervisora, $todosLosPropios, $filasTodas, $benchmarks['var_marca']);
        $ticket    = $this->premioTicketPromedio($filasSupervisora, $benchmarks['ticket_marca']);
        $t2        = $this->premioTicketProducto($filasSupervisora, 'tickets_2do_prod', $benchmarks['pct2_marca'], 'p_ticket_2prod');
        $t3        = $this->premioTicketProducto($filasSupervisora, 'tickets_3er_prod', $benchmarks['pct3_marca'], 'p_ticket_3prod');

        $total = $ventaCrec['venta']['premio'] + $ventaCrec['crecimiento']['premio']
               + $ticket['premio'] + $t2['premio'] + $t3['premio'];

        return [
            'venta' => $ventaCrec['venta'], 'crecimiento' => $ventaCrec['crecimiento'], 'ticket_promedio' => $ticket,
            'ticket_2do' => $t2, 'ticket_3er' => $t3, 'total' => $total,
        ];
    }

    /* ─────────────────────────────────────────────────────────
     * Premios — Franquicias
     *
     * Confirmado contra el DAX real del .pbix (no hay relación de modelo entre la tabla
     * de hechos de franquicias y Supervisora): la CANTIDAD de franquicias que cumplen
     * objetivo de venta/crecimiento es un número único A NIVEL EMPRESA, igual para todas
     * las supervisoras. Lo único que varía por supervisora es el IMPORTE del premio
     * (importesFranquiciaPorSupervisora). No existe ni hace falta un mapeo sucursal→supervisora.
     * ───────────────────────────────────────────────────────── */

    /**
     * Cantidad de franquicias (a nivel empresa, sin desglose por supervisora) que cumplen
     * objetivo de venta y objetivo de crecimiento.
     * DAX real: `Premio Obj. Venta Cant. Franq.` / `Premio Obj. Crecimiento Cant. Franq.`
     */
    public function conteosFranquiciaEmpresa(array $todasLasFranquicias): array
    {
        $benchmarkVarF = $this->benchmarkVarMarcaFranquicias($todasLasFranquicias);
        $cantVenta = 0; $cantCrecimiento = 0;
        foreach ($todasLasFranquicias as $f) {
            if ($f['sin_datos']) continue;
            if ($f['imp_fact'] > $f['imp_obj']) $cantVenta++;
            $var   = $this->facturacionVarPct($f['imp_fact'], $f['imp_fact_ant']);
            $cumpl = $this->cumplimientoObjVenta($f['imp_fact'], $f['imp_obj']);
            if ($var !== null && $var > $benchmarkVarF && $cumpl < 0) $cantCrecimiento++;
        }
        return ['cant_venta' => $cantVenta, 'cant_crecimiento' => $cantCrecimiento, 'benchmark_var' => $benchmarkVarF];
    }

    /**
     * Premios de franquicias para una supervisora: cantidad EMPRESA-WIDE (misma para todas)
     * × importe de ESA supervisora.
     */
    public function premiosFranquiciasSupervisora(array $conteosEmpresa, array $importesSupervisora): array
    {
        $importeVenta = $importesSupervisora['venta']       ?? 0.0;
        $importeCrec  = $importesSupervisora['crecimiento'] ?? 0.0;

        $venta = [
            'cant'    => $conteosEmpresa['cant_venta'],
            'importe' => $importeVenta,
            'premio'  => $conteosEmpresa['cant_venta'] * $importeVenta,
        ];
        $crec = [
            'cant'    => $conteosEmpresa['cant_crecimiento'],
            'importe' => $importeCrec,
            'premio'  => $conteosEmpresa['cant_crecimiento'] * $importeCrec,
        ];
        return ['venta' => $venta, 'crecimiento' => $crec, 'total' => $venta['premio'] + $crec['premio']];
    }
}
