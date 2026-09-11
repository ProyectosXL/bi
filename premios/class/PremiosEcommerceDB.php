<?php
/**
 * PremiosEcommerceDB
 * Cálculo de premios del personal del área de Ecommerce (pestaña "Premios Ecommerce"
 * de /bi/premios/). Reemplaza el Excel manual donde se liquidaban a mano.
 *
 * Fuentes reales (sqlsrv, ver `class/Conexion.php`, conexión 'power'):
 *   - POWER_BI_CONTROL.dbo.BI_SALES_SUCURSALES          facturación real por canal (DIARIA)
 *   - sistemas.dbo.FP_ObjetivosFinales                  objetivo de facturación (MENSUAL, cross-db)
 *   - POWER_BI_CONTROL.dbo.BI_T_PREMIOS_ECOM_PERSONAS   \
 *     POWER_BI_CONTROL.dbo.BI_T_PREMIOS_ECOM_CONCEPTOS   | quién cobra qué y con qué escala
 *     POWER_BI_CONTROL.dbo.BI_T_PREMIOS_ECOM_ESCALAS    /
 *   - POWER_BI_CONTROL.dbo.BI_T_PREMIOS_ECOM_KPIS       órdenes / sesiones / tasa de conversión
 *                                                        y objetivo de órdenes (CARGA MANUAL)
 *   Ver premios/sql/setup_premios_ecommerce.sql para el DDL y el seed.
 *
 * `sistemas` se consulta cross-database desde la conexión 'power' (mismo host XL-APPS),
 * igual que GlobalDashboardDB/CirculacionDB — no hace falta una segunda conexión.
 *
 * DIFERENCIAS IMPORTANTES CON PremiosDB (misma pestaña, otra lógica):
 *   - Las tablas de facturación son DIARIAS, no mensuales: se filtra por rango de fechas
 *     real, no por `FECHA IN (fines de mes)`. El badge de "desactualizado" usa por eso el
 *     criterio DIARIO (ver esDesactualizado()), no el mensual de PremiosDB.
 *   - El premio es un ESCALÓN FIJO (ver escalonFijo()): se paga el importe completo del
 *     tramo más alto alcanzado. NO aplica la tolerancia de negocio de -0,5 % de
 *     PremiosDB::TOLERANCIA_OBJ_VENTA.
 *   - `% de cumplimiento` acá es el RATIO `real / objetivo` (1,05 = 105 %), no el
 *     `real / objetivo - 1` de PremiosDB::cumplimientoObjVenta() — los umbrales de las
 *     escalas están expresados como ratios.
 *   - No hay comparativa interanual: $desdePrev/$hastaPrev no se usan.
 */
class PremiosEcommerceDB
{
    /** `1 ECOMMERCE VTEX` en sistemas.dbo.PuntosDeVenta (numero=99, idTango=9). */
    private const IDPOS_VTEX = '553AEDC5-AB01-4034-BB8C-B9A78165309E';
    /** `1 ECOMMERCE ML` en sistemas.dbo.PuntosDeVenta (numero=98, idTango=1). */
    private const IDPOS_ML   = 'C457D171-5EA2-43ED-96FC-FF233C406214';

    /**
     * ⚠ `NRO_SUCURS` SIGNIFICA COSAS DISTINTAS EN LAS DOS TABLAS QUE USA ESTA CLASE.
     * Es la confusión más fácil de cometer acá; verificado contra la base (2026-09-10/11):
     *
     *   BI_SALES_SUCURSALES (DIARIA) — la columna SUCURSAL trae el nombre literal:
     *       9 = 'ECOMMERCE VTEX'      1 = 'ECOMMERCE ML'
     *     (es la convención de class/Filters.php y global/class/CadenaDB.php, que definen
     *      `canal ECOMMERCE == NRO_SUCURS IN (1,9)`)
     *
     *   BI_T_ESTADISTICAS_VENTAS_PROPIOS (MENSUAL, el ETL de premios):
     *       9 = 'ECOMMERCE' — una sola fila CONSOLIDADA (VTEX + ML), SUPERVISORA='TODAS'
     *       1 = 'CENTRAL'   — fila administrativa, todo en cero, NO es Mercado Libre
     *
     * Que el 9 mensual sea el consolidado está probado por su IMP_OBJ: coincide al peso con
     * la SUMA de los objetivos de los dos idPOS en FP_ObjetivosFinales, en los tres meses
     * verificados (junio, julio y agosto 2026).
     */
    private const NRO_SUCURS_VTEX = 9;   // solo en BI_SALES_SUCURSALES
    private const NRO_SUCURS_ML   = 1;   // solo en BI_SALES_SUCURSALES
    private const NRO_SUCURS_ECOMMERCE_MENSUAL = 9;  // solo en BI_T_ESTADISTICAS_VENTAS_PROPIOS

    /** Canales de ecommerce, en el orden en que se muestran. */
    public const CANALES = ['VTEX', 'ML'];

    /**
     * TALON_PED de cada canal en RO_T_ESTADO_PEDIDOS_ECOMMERCE (base `central`), de donde
     * salen las órdenes. Confirmado en v:\ecommerce\Class\Control.php, que hace este mismo
     * mapeo en varias consultas: 99=VTEX, 98=MERCADO LIBRE, 80=ICBC. Coincide con la columna
     * `numero` de sistemas.dbo.PuntosDeVenta. ICBC no se mide, así que no está acá.
     *
     * OJO: TALON_PED es NUMÉRICO en la base (un CASE que lo devuelva junto a literales de
     * texto falla con "Conversion failed ... to data type int"), por eso se bindea como int.
     */
    private const TALON_PED = ['VTEX' => 99, 'ML' => 98];

    /**
     * Divisor de IVA aplicado a la facturación real. 1.0 = se toma tal cual sale de la base,
     * sin convertir nada.
     *
     * CONFIRMADO que 1.0 es lo correcto: se usa la columna `IMP_FACT` (C/IVA) de
     * BI_T_ESTADISTICAS_VENTAS_PROPIOS y se compara contra un objetivo que sale de esa misma
     * corrida del ETL (su `IMP_OBJ` coincide al peso con FP_ObjetivosFinales). Las dos puntas
     * están en la misma base, así que no hay nada que convertir. Queda la constante por si
     * alguna vez hace falta.
     */
    private const DIVISOR_IVA = 1.0;

    /**
     * Tolerancia SOLO para ruido de punto flotante al comparar contra un umbral (ej. que un
     * 1.0000000001 no quede afuera del tramo de 100 %). NO es una tolerancia de negocio:
     * un 99,6 % de cumplimiento NO paga el tramo de 100 %.
     */
    private const EPS = 1e-6;

    /** @var resource Conexión a XL-APPS/POWER_BI_CONTROL (+ cross-db a `sistemas`). */
    private $connPower;

    /**
     * @var resource|null Conexión a XL-TANGO/LAKER_SA ('central'), donde vive la tabla de
     * órdenes. Es OTRO servidor, así que no alcanza con un cross-database. Se abre en forma
     * perezosa (ver central()): los endpoints de configuración instancian esta clase sin
     * llegar a consultar órdenes, y no tiene sentido pagarles una conexión de más.
     */
    private $connCentral = null;

    /** @var string[] Fines de mes (Y-m-d) cubiertos por el período actual. */
    private array $mesesActual;

    /** @var array|null Caché de catalogo() para esta instancia. */
    private ?array $catalogoCache = null;

    /** @var array|null Caché de kpisManuales() para esta instancia. */
    private ?array $kpisCache = null;

    /** @var array|null Caché de objetivosFacturacion() para esta instancia. */
    private ?array $objetivosCache = null;

    /** @var array|null Caché de realesFacturacion() para esta instancia. */
    private ?array $realesCache = null;

    /** @var array|null Caché de ordenesReales() para esta instancia. */
    private ?array $ordenesCache = null;

    public function __construct(
        private readonly string $desde,
        private readonly string $hasta,
        private readonly string $desdePrev,
        private readonly string $hastaPrev,
    ) {
        require_once __DIR__ . '/../../class/classEnv.php';
        require_once __DIR__ . '/../../class/Conexion.php';
        require_once __DIR__ . '/PremiosDB.php';

        $this->connPower = (new Conexion())->conectar('power');
        if (!$this->connPower) {
            throw new RuntimeException('No se pudo conectar a XL-APPS/POWER_BI_CONTROL');
        }

        // Los fines de mes se usan para las tablas MENSUALES (objetivos, KPIs manuales).
        // La facturación real, que es diaria, usa $desde/$hasta tal cual.
        $this->mesesActual = PremiosDB::finesDeMes($desde, $hasta);
    }

    /* ─────────────────────────────────────────────────────────
     * Período
     * ───────────────────────────────────────────────────────── */

    /** @return string[] Fines de mes (Y-m-d) que cubre el período. */
    public function mesesPeriodo(): array
    {
        return $this->mesesActual;
    }

    /** Fin de mes (Y-m-d) si el período es un mes puntual, null si abarca varios. */
    public function mesUnico(): ?string
    {
        return count($this->mesesActual) === 1 ? $this->mesesActual[0] : null;
    }

    /**
     * true si el rango elegido NO cubre meses completos (ej. "Mes actual", que va del 1 al
     * día de ayer). Importa porque el objetivo de FP_ObjetivosFinales es siempre del mes
     * COMPLETO: comparar contra un real parcial hace que todo dé "no cumple". El dashboard
     * muestra un banner de aviso cuando esto es true.
     */
    public function periodoParcial(): bool
    {
        $arrancaEnPrimero = date('Y-m-01', strtotime($this->desde)) === $this->desde;
        $terminaEnUltimo  = date('Y-m-t', strtotime($this->hasta)) === $this->hasta;
        return !($arrancaEnPrimero && $terminaEnUltimo);
    }

    /** @return array<int,array{mes:int,anio:int}> Pares mes/año del período, para FP_ObjetivosFinales. */
    private function mesesAnio(): array
    {
        return array_map(
            fn(string $finDeMes) => ['mes' => (int) date('n', strtotime($finDeMes)), 'anio' => (int) date('Y', strtotime($finDeMes))],
            $this->mesesActual
        );
    }

    /* ─────────────────────────────────────────────────────────
     * Datos: facturación (objetivo y real)
     * ───────────────────────────────────────────────────────── */

    /**
     * Objetivo de facturación por canal, sumado sobre los meses del período.
     *
     * FP_ObjetivosFinales es mensual por (idPOS, mes, anio) — no por fecha. Se deduplica
     * con ROW_NUMBER() quedándose con la carga MÁS RECIENTE (`fecha_finalizacion DESC`) de
     * cada idPOS+mes+año. Al 2026-09-10 NO hay duplicados para estos dos idPOS (verificado
     * mes por mes: una sola fila por idPOS+mes+anio), así que hoy el ROW_NUMBER es un no-op;
     * queda como red de seguridad porque la tabla admite recargas del mismo mes y sumarlas
     * ciegamente duplicaría el objetivo, llevando todos los premios a 0. Mismo parche
     * defensivo que PremiosDB::datosPropios().
     *
     * @return array{VTEX:float,ML:float}
     */
    public function objetivosFacturacion(): array
    {
        if ($this->objetivosCache !== null) return $this->objetivosCache;

        $out = ['VTEX' => 0.0, 'ML' => 0.0];
        $mesesAnio = $this->mesesAnio();
        if (!$mesesAnio) return $this->objetivosCache = $out;

        $condMeses = implode(' OR ', array_fill(0, count($mesesAnio), '(o.mes = ? AND o.anio = ?)'));
        $params = [self::IDPOS_VTEX, self::IDPOS_ML];
        foreach ($mesesAnio as $ma) {
            $params[] = $ma['mes'];
            $params[] = $ma['anio'];
        }

        $sql = "SELECT d.idPOS, SUM(d.importeObjetivo) AS OBJETIVO
                FROM (
                    SELECT o.idPOS, o.importeObjetivo,
                           ROW_NUMBER() OVER (PARTITION BY o.idPOS, o.anio, o.mes
                                              ORDER BY o.fecha_finalizacion DESC) AS RN
                    FROM sistemas.dbo.FP_ObjetivosFinales o WITH (NOLOCK)
                    WHERE o.idPOS IN (?, ?) AND ($condMeses)
                ) d
                WHERE d.RN = 1
                GROUP BY d.idPOS";

        $stmt = sqlsrv_query($this->connPower, $sql, $params);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando FP_ObjetivosFinales: ' . print_r(sqlsrv_errors(), true));
        }
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $canal = strcasecmp((string) $r['idPOS'], self::IDPOS_VTEX) === 0 ? 'VTEX' : 'ML';
            $out[$canal] = (float) $r['OBJETIVO'];
        }
        sqlsrv_free_stmt($stmt);
        return $this->objetivosCache = $out;
    }

    /**
     * Facturación real de ecommerce del período. Cada concepto se mide con la fuente que
     * efectivamente mide lo que ese concepto premia — **ningún importe es estimado**, porque
     * de estos números depende lo que cobra una persona.
     *
     *   TOTAL del canal  → BI_T_ESTADISTICAS_VENTAS_PROPIOS (mensual), fila ECOMMERCE.
     *                      Es la fuente contra la que liquidan las otras tres pestañas y la
     *                      que ya alimenta la fila ECOMMERCE de "Locales Propios".
     *   VTEX y ML        → BI_SALES_SUCURSALES (diaria), que es la ÚNICA que los separa.
     *
     * ⚠ CONSECUENCIA CONOCIDA Y ACEPTADA: las partes no suman el total. Julio 2026 muestra
     * $389,3M en el concepto combinado de Agustina y $224,1M + $125,0M = $349,1M en los dos
     * de Vanesa — un 11,5 % de diferencia entre dos tablas del mismo BI, todavía sin
     * explicar (¿ICBC? ¿envío? ¿otro conjunto de rubros?). Eso es un problema de las fuentes,
     * no de este cálculo, y se prefiere mostrarlo antes que taparlo: la alternativa que se
     * descartó (2026-09-11) era prorratear el total mensual con la proporción de la diaria,
     * que daba una tabla que cerraba pero liquidaba premios sobre importes estimados.
     *
     * El arreglo de fondo es que el SP que carga la tabla mensual emita dos filas en vez de
     * una, como ya hace la diaria. El día que pase, los tres importes salen de ahí y cierran.
     *
     * @return array{TOTAL:float,VTEX:float,ML:float}
     */
    public function realesFacturacion(): array
    {
        if ($this->realesCache !== null) return $this->realesCache;

        $porCanal = $this->facturacionDiariaPorCanal();

        return $this->realesCache = [
            'TOTAL' => $this->facturacionMensualEcommerce(),
            'VTEX'  => $porCanal['VTEX'],
            'ML'    => $porCanal['ML'],
        ];
    }

    /**
     * Facturación C/IVA del canal ecommerce, sumada sobre los meses del período, desde la
     * tabla mensual del ETL de premios.
     *
     * Se usa `IMP_FACT` (C/IVA) y no `IMP_FACT_S_IVA`: esa columna viene en CERO para la fila
     * ECOMMERCE (el ETL no la puebla ahí), verificado en junio, julio y agosto 2026.
     *
     * Dedup con ROW_NUMBER por el mismo motivo que `PremiosDB::datosPropios()`: la tabla
     * origen tiene filas repetidas — de hecho CENTRAL aparece cuadruplicada por supervisora.
     * Hoy la fila ECOMMERCE viene una sola vez por mes, así que es defensivo.
     */
    private function facturacionMensualEcommerce(): float
    {
        if (!$this->mesesActual) return 0.0;

        $placeholders = implode(',', array_fill(0, count($this->mesesActual), '?'));
        $sql = "SELECT SUM(d.IMP_FACT) AS IMP_FACT
                FROM (
                    SELECT IMP_FACT,
                           ROW_NUMBER() OVER (PARTITION BY NRO_SUCURS, FECHA ORDER BY (SELECT NULL)) AS RN
                    FROM dbo.BI_T_ESTADISTICAS_VENTAS_PROPIOS WITH (NOLOCK)
                    WHERE FECHA IN ($placeholders) AND NRO_SUCURS = ?
                ) d
                WHERE d.RN = 1";

        $params = array_merge($this->mesesActual, [self::NRO_SUCURS_ECOMMERCE_MENSUAL]);
        $stmt = sqlsrv_query($this->connPower, $sql, $params);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_T_ESTADISTICAS_VENTAS_PROPIOS: ' . print_r(sqlsrv_errors(), true));
        }
        $total = 0.0;
        if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $total = (float) ($row['IMP_FACT'] ?? 0) / self::DIVISOR_IVA;
        }
        sqlsrv_free_stmt($stmt);
        return $total;
    }

    /**
     * Facturación por canal desde la tabla DIARIA, la única que separa VTEX de ML
     * (`NRO_SUCURS` 9 y 1, con el nombre literal en su columna SUCURSAL).
     *
     * Es diaria, así que se filtra por el rango real (no por fines de mes), con el mismo
     * patrón de límite superior que el resto del repo (`FECHA < DATEADD(day,1,hasta)`), así
     * un `hasta` con hora igual entra completo.
     *
     * @return array{VTEX:float,ML:float}
     */
    private function facturacionDiariaPorCanal(): array
    {
        $porCanal = ['VTEX' => 0.0, 'ML' => 0.0];

        $sql = "SELECT s.NRO_SUCURS, SUM(s.IMPORTE) AS IMP
                FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
                WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day, 1, CAST(? AS DATE))
                  AND s.NRO_SUCURS IN (?, ?)
                GROUP BY s.NRO_SUCURS";

        $stmt = sqlsrv_query($this->connPower, $sql, [
            $this->desde, $this->hasta, self::NRO_SUCURS_VTEX, self::NRO_SUCURS_ML,
        ]);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_SALES_SUCURSALES: ' . print_r(sqlsrv_errors(), true));
        }
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $canal = ((int) $r['NRO_SUCURS'] === self::NRO_SUCURS_VTEX) ? 'VTEX' : 'ML';
            $porCanal[$canal] = (float) $r['IMP'] / self::DIVISOR_IVA;
        }
        sqlsrv_free_stmt($stmt);
        return $porCanal;
    }

    /* ─────────────────────────────────────────────────────────
     * Datos: órdenes (Tango)
     * ───────────────────────────────────────────────────────── */

    /** Abre (una sola vez) la conexión a 'central', donde vive la tabla de órdenes. */
    private function central()
    {
        if ($this->connCentral === null) {
            $this->connCentral = (new Conexion())->conectar('central');
            if (!$this->connCentral) {
                throw new RuntimeException('No se pudo conectar a XL-TANGO/LAKER_SA para leer las órdenes de ecommerce');
            }
        }
        return $this->connCentral;
    }

    /**
     * Cantidad de órdenes por canal en el período, desde
     * `RO_T_ESTADO_PEDIDOS_ECOMMERCE` (base 'central').
     *
     * Criterios, decididos el 2026-09-11 (cambiarlos es cambiar solo esta consulta):
     *   - **FECHA_PEDI** (fecha del pedido), no FECHA_SINCRONIZADO ni FECHA_FACTURADO: el
     *     premio mide actividad comercial del mes, y es la misma base temporal que usa una
     *     tasa de conversión (pedidos generados sobre visitas del período).
     *   - **COUNT(DISTINCT ORDER_ID)**: la tabla tiene más de una fila por orden (julio 2026:
     *     2.634 filas para 2.602 órdenes), así que un COUNT(*) la sobrecontaría.
     *   - **CANCELADO IS NULL**: una orden cancelada no es una venta. Es el mismo criterio con
     *     el que se toma la facturación (solo ventas reales) y evita premiar cancelaciones.
     *
     * Tener presente: Tango cuenta ~9,6 % menos órdenes que el panel de VTEX (julio 2026:
     * 2.504 acá contra 2.894 en la planilla), la misma brecha que ya se midió en pesos. El
     * OBJETIVO de órdenes se carga a mano, así que debe fijarse sobre esta misma base para
     * que el % de cumplimiento sea comparable — ver README.
     *
     * @return array<string,int> Canal => cantidad de órdenes (0 si no hubo).
     */
    public function ordenesReales(): array
    {
        if ($this->ordenesCache !== null) return $this->ordenesCache;

        $out = array_fill_keys(self::CANALES, 0);

        $sql = "SELECT p.TALON_PED, COUNT(DISTINCT p.ORDER_ID) AS ORDENES
                FROM RO_T_ESTADO_PEDIDOS_ECOMMERCE p WITH (NOLOCK)
                WHERE p.TALON_PED IN (?, ?)
                  AND p.CANCELADO IS NULL
                  AND p.FECHA_PEDI >= ? AND p.FECHA_PEDI < DATEADD(day, 1, CAST(? AS DATE))
                GROUP BY p.TALON_PED";

        $stmt = sqlsrv_query($this->central(), $sql, [
            self::TALON_PED['VTEX'], self::TALON_PED['ML'], $this->desde, $this->hasta,
        ]);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando RO_T_ESTADO_PEDIDOS_ECOMMERCE: ' . print_r(sqlsrv_errors(), true));
        }
        $porTalon = array_flip(self::TALON_PED); // 99 => 'VTEX', 98 => 'ML'
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $canal = $porTalon[(int) $r['TALON_PED']] ?? null;
            if ($canal !== null) $out[$canal] = (int) $r['ORDENES'];
        }
        sqlsrv_free_stmt($stmt);
        return $this->ordenesCache = $out;
    }

    /* ─────────────────────────────────────────────────────────
     * Datos: KPIs de carga manual (sesiones / conversión)
     * ───────────────────────────────────────────────────────── */

    /** @return array<int,array> Filas crudas de BI_T_PREMIOS_ECOM_KPIS para los meses del período. */
    private function filasKpis(): array
    {
        if (!$this->mesesActual) return [];
        $placeholders = implode(',', array_fill(0, count($this->mesesActual), '?'));
        $sql = "SELECT MES, CANAL, SESIONES, TASA_CONVERSION, OBJETIVO_ORDENES,
                       ACTUALIZADO_POR, FECHA_ACTUALIZACION
                FROM BI_T_PREMIOS_ECOM_KPIS
                WHERE MES IN ($placeholders)";
        $stmt = sqlsrv_query($this->connPower, $sql, $this->mesesActual);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_T_PREMIOS_ECOM_KPIS: ' . print_r(sqlsrv_errors(), true));
        }
        $filas = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $filas[] = [
                'mes'                 => $r['MES'] instanceof DateTimeInterface ? $r['MES']->format('Y-m-d') : (string) $r['MES'],
                'canal'               => (string) $r['CANAL'],
                'sesiones'            => $r['SESIONES'] === null ? null : (int) $r['SESIONES'],
                'tasa_conversion'     => $r['TASA_CONVERSION'] === null ? null : (float) $r['TASA_CONVERSION'],
                'objetivo_ordenes'    => $r['OBJETIVO_ORDENES'] === null ? null : (float) $r['OBJETIVO_ORDENES'],
                'actualizado_por'     => $r['ACTUALIZADO_POR'],
                'fecha_actualizacion' => $r['FECHA_ACTUALIZACION'] instanceof DateTimeInterface
                    ? $r['FECHA_ACTUALIZACION']->format('Y-m-d H:i:s') : $r['FECHA_ACTUALIZACION'],
            ];
        }
        sqlsrv_free_stmt($stmt);
        return $filas;
    }

    /**
     * KPIs manuales agregados sobre el período, por canal.
     *
     * Solo quedan dos datos de carga manual: la TASA DE CONVERSIÓN (se toma del panel de
     * VTEX; no existe en ningún sistema propio porque nadie tiene el dato de sesiones) y el
     * OBJETIVO de órdenes (no está en FP_ObjetivosFinales). Las órdenes reales ya NO se
     * cargan: salen de Tango, ver ordenesReales().
     *
     * El objetivo de órdenes SUMA los meses del rango. La tasa de conversión no se puede
     * sumar ni promediar sin más:
     *   - un solo mes → se usa su TASA_CONVERSION tal cual;
     *   - varios meses CON sesiones cargadas → promedio PONDERADO por sesiones, que es la
     *     forma correcta de promediar tasas cuando se conoce el tráfico de cada mes;
     *   - varios meses SIN sesiones → promedio simple, marcado `tasa_estimada = true`.
     *
     * La ponderación usa tasa y sesiones, ambas de VTEX: deliberadamente NO se recalcula la
     * tasa como órdenes/sesiones, porque las órdenes son de Tango y cuentan ~9,6 % menos que
     * VTEX — mezclarlas bajaría la tasa y, como la escala de conversión es de valores
     * ABSOLUTOS, haría perder un tramo entero sin que cambiara el desempeño real.
     *
     * Los flags `*_completo` dicen si el dato está cargado en TODOS los meses del período:
     * un concepto que dependa de un dato incompleto se muestra como "falta carga"
     * (`sin_dato`) en vez de como "no cumplió".
     *
     * @return array<string,array{sesiones:?int,tasa_conversion:?float,objetivo_ordenes:?float,
     *   objetivo_ordenes_completo:bool,tasa_completo:bool,tasa_estimada:bool,meses_cargados:int}>
     */
    private function kpisManuales(): array
    {
        if ($this->kpisCache !== null) return $this->kpisCache;

        $filas    = $this->filasKpis();
        $cantMes  = count($this->mesesActual);
        $out      = [];

        foreach (self::CANALES as $canal) {
            $delCanal = array_values(array_filter($filas, fn($f) => strcasecmp($f['canal'], $canal) === 0));

            $sesiones = array_values(array_filter(array_column($delCanal, 'sesiones'), fn($v) => $v !== null));
            $tasas    = array_values(array_filter(array_column($delCanal, 'tasa_conversion'), fn($v) => $v !== null));
            $objOrd   = array_values(array_filter(array_column($delCanal, 'objetivo_ordenes'), fn($v) => $v !== null));

            $sumSesiones = $sesiones ? (int) array_sum($sesiones) : null;

            // Filas que tienen tasa Y sesiones — las únicas ponderables.
            $ponderables = array_values(array_filter(
                $delCanal,
                fn($f) => $f['tasa_conversion'] !== null && $f['sesiones'] !== null && $f['sesiones'] > 0
            ));

            $tasaEstimada = false;
            if (count($tasas) === 1) {
                $tasa = $tasas[0];
            } elseif (count($ponderables) === count($tasas) && $tasas) {
                $pesoTotal = array_sum(array_column($ponderables, 'sesiones'));
                $tasa = array_sum(array_map(
                    fn($f) => $f['tasa_conversion'] * $f['sesiones'],
                    $ponderables
                )) / $pesoTotal;
            } elseif ($tasas) {
                $tasa = array_sum($tasas) / count($tasas);
                $tasaEstimada = true;
            } else {
                $tasa = null;
            }

            $out[$canal] = [
                'sesiones'                  => $sumSesiones,
                'tasa_conversion'           => $tasa,
                'objetivo_ordenes'          => $objOrd ? (float) array_sum($objOrd) : null,
                'objetivo_ordenes_completo' => count($objOrd) === $cantMes && $cantMes > 0,
                'tasa_completo'             => count($tasas)  === $cantMes && $cantMes > 0,
                'tasa_estimada'             => $tasaEstimada,
                'meses_cargados'            => count($delCanal),
            ];
        }

        return $this->kpisCache = $out;
    }

    /**
     * Meses/canales del período a los que les falta algún KPI manual que ALGÚN concepto
     * activo necesita — no todos los canales necesitan todo (a ML hoy no se le miden ni
     * órdenes ni conversión, así que no se reporta como faltante).
     *
     * @return array<int,array{mes:string,canal:string,campos:string[]}>
     */
    public function kpisFaltantes(): array
    {
        // Qué campos de CARGA MANUAL necesita cada canal, según los conceptos activos. El
        // canal sale del CÓDIGO de origen (que es canal-específico) y no de la columna CANAL
        // del concepto: es lo que efectivamente lee origenes(), así que un CANAL mal cargado
        // no puede hacer que se reclame la carga de un mes/canal que nadie usa.
        // ORD_VTEX no figura acá: las órdenes salen de Tango, no se cargan.
        $campoPorOrigen = [
            'CONV_VTEX'    => ['VTEX', 'tasa_conversion'],
            'OBJ_ORD_VTEX' => ['VTEX', 'objetivo_ordenes'],
        ];

        $necesarios = [];
        foreach ($this->catalogo() as $persona) {
            foreach ($persona['conceptos'] as $c) {
                foreach ([$c['origen_real'], $c['origen_objetivo']] as $origen) {
                    if (!isset($campoPorOrigen[$origen])) continue;
                    [$canal, $campo] = $campoPorOrigen[$origen];
                    $necesarios[$canal][$campo] = true;
                }
            }
        }
        if (!$necesarios) return [];

        // Indexar lo cargado por mes+canal para saber qué falta fila por fila.
        $cargado = [];
        foreach ($this->filasKpis() as $f) {
            $cargado[$f['mes'] . '|' . strtoupper($f['canal'])] = $f;
        }

        $faltantes = [];
        foreach ($this->mesesActual as $mes) {
            foreach ($necesarios as $canal => $campos) {
                $fila = $cargado[$mes . '|' . strtoupper($canal)] ?? null;
                $faltan = [];
                foreach (array_keys($campos) as $campo) {
                    if ($fila === null || $fila[$campo] === null) $faltan[] = $campo;
                }
                if ($faltan) {
                    $faltantes[] = ['mes' => $mes, 'canal' => $canal, 'campos' => $faltan];
                }
            }
        }
        return $faltantes;
    }

    /**
     * Filas de carga manual tal como están guardadas, para los meses del período. Lo usa el
     * modal de carga, que necesita el valor CRUDO de cada mes/canal para poder editarlo —
     * no el agregado del rango que devuelve kpisManuales().
     *
     * @return array<int,array{mes:string,canal:string,sesiones:?int,
     *   tasa_conversion:?float,objetivo_ordenes:?float,actualizado_por:?string,
     *   fecha_actualizacion:?string}>
     */
    public function kpisGuardados(): array
    {
        return $this->filasKpis();
    }

    /**
     * Último guardado manual de KPIs dentro del período consultado (para el pie del
     * dashboard: "órdenes y conversión cargadas el X por Y").
     *
     * @return array{fecha:?string,usuario:?string}
     */
    public function ultimaCargaManual(): array
    {
        $ultima = ['fecha' => null, 'usuario' => null];
        foreach ($this->filasKpis() as $f) {
            if (!$f['fecha_actualizacion']) continue;
            if ($ultima['fecha'] === null || $f['fecha_actualizacion'] > $ultima['fecha']) {
                $ultima = ['fecha' => $f['fecha_actualizacion'], 'usuario' => $f['actualizado_por']];
            }
        }
        return $ultima;
    }

    /* ─────────────────────────────────────────────────────────
     * Catálogo: personas → conceptos → escalas
     * ───────────────────────────────────────────────────────── */

    /**
     * Quién cobra, qué se le mide y con qué escala. Es la fuente del cálculo Y del modal
     * "Escalas de premios". Las escalas vienen ordenadas de mayor a menor umbral, que es el
     * orden que necesita escalonFijo().
     *
     * @return array<int,array{id:int,nombre:string,conceptos:array<int,array{
     *   id:int,canal:?string,etiqueta:string,metrica:string,tipo_umbral:string,
     *   origen_real:string,origen_objetivo:string,
     *   escalas:array<int,array{umbral:float,importe:float}>}>}>
     */
    public function catalogo(): array
    {
        if ($this->catalogoCache !== null) return $this->catalogoCache;

        $sqlEscalas = "SELECT ID_CONCEPTO, UMBRAL, IMPORTE
                       FROM BI_T_PREMIOS_ECOM_ESCALAS
                       ORDER BY ID_CONCEPTO, UMBRAL DESC";
        $stmt = sqlsrv_query($this->connPower, $sqlEscalas);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando BI_T_PREMIOS_ECOM_ESCALAS: ' . print_r(sqlsrv_errors(), true));
        }
        $escalasPorConcepto = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $escalasPorConcepto[(int) $r['ID_CONCEPTO']][] = [
                'umbral'  => (float) $r['UMBRAL'],
                'importe' => (float) $r['IMPORTE'],
            ];
        }
        sqlsrv_free_stmt($stmt);

        $sql = "SELECT p.ID AS P_ID, p.NOMBRE, c.ID AS C_ID, c.CANAL, c.ETIQUETA, c.METRICA,
                       c.TIPO_UMBRAL, c.ORIGEN_REAL, c.ORIGEN_OBJETIVO
                FROM BI_T_PREMIOS_ECOM_PERSONAS p
                LEFT JOIN BI_T_PREMIOS_ECOM_CONCEPTOS c
                       ON c.ID_PERSONA = p.ID AND c.ACTIVO = 1
                WHERE p.ACTIVO = 1
                ORDER BY p.ORDEN, p.NOMBRE, c.ORDEN, c.ETIQUETA";
        $stmt = sqlsrv_query($this->connPower, $sql);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando el catálogo de premios ecommerce: ' . print_r(sqlsrv_errors(), true));
        }

        $personas = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $pid = (int) $r['P_ID'];
            if (!isset($personas[$pid])) {
                $personas[$pid] = ['id' => $pid, 'nombre' => (string) $r['NOMBRE'], 'conceptos' => []];
            }
            if ($r['C_ID'] === null) continue; // persona activa sin conceptos asignados
            $cid = (int) $r['C_ID'];
            $personas[$pid]['conceptos'][] = [
                'id'              => $cid,
                'canal'           => $r['CANAL'] !== null ? (string) $r['CANAL'] : null,
                'etiqueta'        => (string) $r['ETIQUETA'],
                'metrica'         => (string) $r['METRICA'],
                'tipo_umbral'     => (string) $r['TIPO_UMBRAL'],
                'origen_real'     => (string) $r['ORIGEN_REAL'],
                'origen_objetivo' => (string) $r['ORIGEN_OBJETIVO'],
                'escalas'         => $escalasPorConcepto[$cid] ?? [],
            ];
        }
        sqlsrv_free_stmt($stmt);

        return $this->catalogoCache = array_values($personas);
    }

    /* ─────────────────────────────────────────────────────────
     * Motor de premios
     * ───────────────────────────────────────────────────────── */

    /**
     * ESCALÓN FIJO: busca el tramo más alto cuyo umbral alcanza $valor y devuelve ese
     * importe COMPLETO, sin prorratear por el % de cumplimiento. Si $valor no llega ni al
     * umbral más bajo (o no hay dato), el premio es 0 y no hay tramo.
     *
     * @param array<int,array{umbral:float,importe:float}> $escalas En cualquier orden.
     * @return array{umbral:?float,importe:float}
     */
    public static function escalonFijo(?float $valor, array $escalas): array
    {
        if ($valor === null || !$escalas) return ['umbral' => null, 'importe' => 0.0];

        usort($escalas, fn($a, $b) => $b['umbral'] <=> $a['umbral']);
        foreach ($escalas as $tramo) {
            if ($valor + self::EPS >= $tramo['umbral']) {
                return ['umbral' => (float) $tramo['umbral'], 'importe' => (float) $tramo['importe']];
            }
        }
        return ['umbral' => null, 'importe' => 0.0];
    }

    /**
     * Resuelve los códigos ORIGEN_REAL / ORIGEN_OBJETIVO de BI_T_PREMIOS_ECOM_CONCEPTOS a
     * valores concretos del período. Los arrays de acá SON el conjunto cerrado de códigos
     * admitidos: viven en la tabla (y no hardcodeados por persona) para poder reasignar un
     * concepto de canal sin deploy, pero agregar un código NUEVO requiere tocar este método.
     * Un código desconocido deja al concepto en `sin_dato` en vez de reventar el tablero.
     *
     * @return array{reales:array<string,?float>,objetivos:array<string,?float>,completos:array<string,bool>}
     */
    private function origenes(): array
    {
        $obj     = $this->objetivosFacturacion();
        $real    = $this->realesFacturacion();
        $ordenes = $this->ordenesReales();
        $kpis    = $this->kpisManuales();

        $reales = [
            'FACT_VTEX'    => $real['VTEX'],
            'FACT_ML'      => $real['ML'],
            // El combinado NO es la suma de los dos de arriba: es el total mensual del que
            // esos dos se derivan. Numéricamente da igual (el reparto es exacto), pero el
            // que manda es este — si algún día la mensual separa los canales, se invierte.
            'FACT_VTEX_ML' => $real['TOTAL'],
            'ORD_VTEX'     => (float) $ordenes['VTEX'],
            'CONV_VTEX'    => $kpis['VTEX']['tasa_conversion'],
        ];
        $objetivos = [
            'OBJ_VTEX'     => $obj['VTEX'],
            'OBJ_ML'       => $obj['ML'],
            'OBJ_VTEX_ML'  => $obj['VTEX'] + $obj['ML'],
            'OBJ_ORD_VTEX' => $kpis['VTEX']['objetivo_ordenes'],
            'NINGUNO'      => null,
        ];
        // Lo que sale de una tabla que se carga sola (facturación, órdenes) siempre está
        // "completo"; los dos datos de carga manual, solo si están en TODOS los meses del
        // período — si no, el concepto queda como "falta carga" en vez de "no cumplió".
        $completos = [
            'FACT_VTEX'    => true,
            'FACT_ML'      => true,
            'FACT_VTEX_ML' => true,
            'ORD_VTEX'     => true,
            'CONV_VTEX'    => $kpis['VTEX']['tasa_completo'],
            'OBJ_VTEX'     => true,
            'OBJ_ML'       => true,
            'OBJ_VTEX_ML'  => true,
            'OBJ_ORD_VTEX' => $kpis['VTEX']['objetivo_ordenes_completo'],
            'NINGUNO'      => true,
        ];

        return ['reales' => $reales, 'objetivos' => $objetivos, 'completos' => $completos];
    }

    /**
     * Premios del período, persona por persona y concepto por concepto.
     *
     * Un concepto queda `sin_dato` (premio 0, pero mostrado como "falta carga" y no como
     * "no cumplió") cuando le falta el real, cuando el umbral es de % de cumplimiento y no
     * hay objetivo cargado, o cuando depende de un KPI manual incompleto en el período.
     *
     * @return array{personas:array<int,array>,total_general:float,
     *   objetivos:array{VTEX:float,ML:float},reales:array{TOTAL:float,VTEX:float,ML:float},
     *   ordenes:array<string,int>,tasa_estimada:bool}
     */
    public function calcular(): array
    {
        $og = $this->origenes();
        $kpis = $this->kpisManuales();

        $personas = [];
        $totalGeneral = 0.0;

        foreach ($this->catalogo() as $p) {
            $conceptos = [];
            $totalPersona = 0.0;

            foreach ($p['conceptos'] as $c) {
                $real     = $og['reales'][$c['origen_real']] ?? null;
                $objetivo = $og['objetivos'][$c['origen_objetivo']] ?? null;
                $esPct    = $c['tipo_umbral'] === 'PCT_CUMPLIMIENTO';

                $completo = ($og['completos'][$c['origen_real']] ?? false)
                         && ($og['completos'][$c['origen_objetivo']] ?? false);

                $sinDato = $real === null || !$completo || ($esPct && (!$objetivo || $objetivo <= 0));

                $pct = ($esPct && !$sinDato) ? $real / $objetivo : null;
                // El valor que se compara contra la escala: el ratio de cumplimiento si el
                // umbral es porcentual, el valor real crudo si es absoluto (tasa de conversión).
                $valorEvaluado = $esPct ? $pct : $real;

                $tramo = $sinDato
                    ? ['umbral' => null, 'importe' => 0.0]
                    : self::escalonFijo($valorEvaluado, $c['escalas']);

                $totalPersona += $tramo['importe'];

                $conceptos[] = [
                    'canal'            => $c['canal'],
                    'concepto'         => $c['etiqueta'],
                    'metrica'          => $c['metrica'],
                    'tipo_umbral'      => $c['tipo_umbral'],
                    'objetivo'         => $esPct ? $objetivo : null,
                    'real'             => $real,
                    'pct_cumplimiento' => $pct,
                    'tramo_umbral'     => $tramo['umbral'],
                    'premio'           => $tramo['importe'],
                    'sin_dato'         => $sinDato,
                ];
            }

            $totalGeneral += $totalPersona;
            $personas[] = [
                'nombre'       => $p['nombre'],
                'total_premio' => $totalPersona,
                'conceptos'    => $conceptos,
            ];
        }

        return [
            'personas'      => $personas,
            'total_general' => $totalGeneral,
            'objetivos'     => $this->objetivosFacturacion(),
            'reales'        => $this->realesFacturacion(),
            'ordenes'       => $this->ordenesReales(),
            // Las sesiones no entran en ningún cálculo de premio (solo ponderan la tasa en
            // períodos de varios meses), pero se exponen para poder mostrarlas: si alguien
            // se tomó el trabajo de cargarlas, tiene que poder verlas reflejadas.
            'sesiones'      => array_map(fn(array $k) => $k['sesiones'], $kpis),
            'tasa_estimada' => (bool) ($kpis['VTEX']['tasa_estimada'] ?? false),
        ];
    }

    /* ─────────────────────────────────────────────────────────
     * Escritura (configurador del dashboard)
     * ───────────────────────────────────────────────────────── */

    /**
     * Guarda (inserta o actualiza) los KPIs manuales de un mes, para uno o más canales.
     * MERGE por (MES, CANAL) en una transacción: si falla un canal no queda el mes a medio
     * cargar. Los valores null se guardan como null (borrar un dato es válido).
     *
     * @param string $mes Fin de mes (Y-m-d) — validado por el endpoint.
     * @param array<int,array{canal:string,sesiones:?int,tasa_conversion:?float,objetivo_ordenes:?float}> $porCanal
     */
    public function guardarKpisMes(string $mes, array $porCanal, string $usuario): void
    {
        // Los CAST del USING son necesarios: sin ellos SQL Server no puede inferir el tipo
        // de los parámetros de la fuente del MERGE y falla al compilar.
        $sql = "MERGE BI_T_PREMIOS_ECOM_KPIS AS t
                USING (SELECT CAST(? AS DATE) AS MES, CAST(? AS VARCHAR(10)) AS CANAL) AS s
                   ON t.MES = s.MES AND t.CANAL = s.CANAL
                WHEN MATCHED THEN UPDATE SET
                    SESIONES = ?, TASA_CONVERSION = ?, OBJETIVO_ORDENES = ?,
                    ACTUALIZADO_POR = ?, FECHA_ACTUALIZACION = GETDATE()
                WHEN NOT MATCHED THEN INSERT
                    (MES, CANAL, SESIONES, TASA_CONVERSION, OBJETIVO_ORDENES,
                     ACTUALIZADO_POR, FECHA_ACTUALIZACION)
                    VALUES (s.MES, s.CANAL, ?, ?, ?, ?, GETDATE());";

        if (!sqlsrv_begin_transaction($this->connPower)) {
            throw new RuntimeException('No se pudo iniciar la transacción: ' . print_r(sqlsrv_errors(), true));
        }
        try {
            foreach ($porCanal as $c) {
                $valores = [$c['sesiones'], $c['tasa_conversion'], $c['objetivo_ordenes'], $usuario];
                $params = array_merge([$mes, $c['canal']], $valores, $valores);
                if (sqlsrv_query($this->connPower, $sql, $params) === false) {
                    throw new RuntimeException('Error guardando BI_T_PREMIOS_ECOM_KPIS: ' . print_r(sqlsrv_errors(), true));
                }
            }
            sqlsrv_commit($this->connPower);
        } catch (Throwable $e) {
            sqlsrv_rollback($this->connPower);
            throw $e;
        }
        $this->kpisCache = null;
    }

    /**
     * Reemplaza los tramos de los conceptos recibidos. Se borra y reinserta por concepto
     * dentro de una transacción (mismo criterio que
     * PremiosDB::guardarConfiguracionSupervisoras): la tabla es chica y así no queda una
     * escala mitad vieja / mitad nueva si el guardado se corta.
     *
     * Solo toca los conceptos incluidos en $tramosPorConcepto — un concepto que no venga
     * en el payload queda intacto.
     *
     * @param array<int,array<int,array{umbral:float,importe:float}>> $tramosPorConcepto id_concepto => tramos
     */
    public function guardarEscalas(array $tramosPorConcepto, string $usuario): void
    {
        if (!$tramosPorConcepto) return;

        if (!sqlsrv_begin_transaction($this->connPower)) {
            throw new RuntimeException('No se pudo iniciar la transacción: ' . print_r(sqlsrv_errors(), true));
        }
        try {
            $sqlInsert = "INSERT INTO BI_T_PREMIOS_ECOM_ESCALAS
                              (ID_CONCEPTO, UMBRAL, IMPORTE, ACTUALIZADO_POR, FECHA_ACTUALIZACION)
                          VALUES (?, ?, ?, ?, GETDATE())";
            foreach ($tramosPorConcepto as $idConcepto => $tramos) {
                $del = sqlsrv_query($this->connPower,
                    'DELETE FROM BI_T_PREMIOS_ECOM_ESCALAS WHERE ID_CONCEPTO = ?', [(int) $idConcepto]);
                if ($del === false) {
                    throw new RuntimeException('Error limpiando la escala del concepto ' . $idConcepto . ': ' . print_r(sqlsrv_errors(), true));
                }
                foreach ($tramos as $t) {
                    $ins = sqlsrv_query($this->connPower, $sqlInsert,
                        [(int) $idConcepto, $t['umbral'], $t['importe'], $usuario]);
                    if ($ins === false) {
                        throw new RuntimeException('Error insertando un tramo del concepto ' . $idConcepto . ': ' . print_r(sqlsrv_errors(), true));
                    }
                }
            }
            sqlsrv_commit($this->connPower);
        } catch (Throwable $e) {
            sqlsrv_rollback($this->connPower);
            throw $e;
        }
        $this->catalogoCache = null;
    }

    /** @return int[] IDs de conceptos activos — para validar el payload del configurador. */
    public function idsConceptos(): array
    {
        $ids = [];
        foreach ($this->catalogo() as $p) {
            foreach ($p['conceptos'] as $c) $ids[] = $c['id'];
        }
        return $ids;
    }

    /* ─────────────────────────────────────────────────────────
     * Estado de los datos (badge del topbar)
     * ───────────────────────────────────────────────────────── */

    /**
     * Última escritura real sobre la tabla de la que sale la facturación. Mismo patrón que
     * SalesDB/PremiosDB: `sys.dm_db_index_usage_stats`, con fallback a `MAX(FECHA)` si no
     * hay estadísticas (ej. tras un reinicio del motor).
     *
     * Se mira BI_T_ESTADISTICAS_VENTAS_PROPIOS y no la tabla diaria porque la facturación
     * —el dato que domina el cálculo— sale de ahí, y es la que se carga una vez por mes: es
     * el eslabón lento. Las órdenes (diarias) y la tasa (carga manual) no mueven el badge.
     */
    public function getUltimaActualizacion(): ?string
    {
        $sql = "SELECT MAX(last_user_update) AS last_update
                FROM sys.dm_db_index_usage_stats
                WHERE database_id = DB_ID()
                  AND object_id = OBJECT_ID('dbo.BI_T_ESTADISTICAS_VENTAS_PROPIOS')";
        $res = null;
        $stmt = sqlsrv_query($this->connPower, $sql);
        if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if (!empty($row['last_update'])) {
                $res = is_object($row['last_update']) ? $row['last_update']->format('Y-m-d H:i:s') : $row['last_update'];
            }
            sqlsrv_free_stmt($stmt);
        }
        if (!$res) {
            $stmt = sqlsrv_query($this->connPower, 'SELECT MAX(FECHA) AS last_update FROM dbo.BI_T_ESTADISTICAS_VENTAS_PROPIOS');
            if ($stmt !== false && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                if (!empty($row['last_update'])) {
                    $res = is_object($row['last_update']) ? $row['last_update']->format('Y-m-d H:i:s') : $row['last_update'];
                }
                sqlsrv_free_stmt($stmt);
            }
        }
        return $res;
    }

    /**
     * Criterio MENSUAL, delegado en PremiosDB: ahora que la facturación sale de la misma
     * tabla que las otras tres pestañas (se carga el día 1 y deja el mes recién cerrado), el
     * badge tiene que usar exactamente el mismo criterio que ellas — si no, esta pestaña
     * mostraría "DESACTUALIZADO" todo el mes mientras las otras no.
     */
    public static function esDesactualizado(DateTime $dtUpdate, ?DateTime $ahora = null): bool
    {
        return PremiosDB::esDesactualizado($dtUpdate, $ahora);
    }
}
