<?php
/**
 * CirculacionDB
 * Merodeo, ingresos, tasa de atracción y tasa de conversión por sucursal.
 * Fuente: BI_T_INGRESOS_SUCURSALES (FECHA, FECHA_HORA, NRO_SUCURS, INGRESOS, MERODEO).
 *
 * MERODEO incluye a las personas que después ingresan (INGRESOS es subconjunto de MERODEO
 * en términos conceptuales, aunque se cargan en la misma fila del sensor).
 * Por eso el filtro de merodeo (MERODEO > 0) nunca reutiliza el filtro de ingresos (INGRESOS > 0):
 * reutilizarlo descartaría días con merodeo registrado y cero ingresos.
 *
 * El sensor todavía no está implementado en todas las sucursales, ni en Uruguay/Franquicias.
 */
class CirculacionDB
{
    private $conn;
    private string $campoVendedor;
    private string $origen;

    public function __construct(string $origen = 'argentina')
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Filters.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/ConversionHelper.php';

        $cfg = getConfigForOrigen($origen);
        $this->origen        = $origen;
        $this->campoVendedor = $cfg['campo_vendedor'];

        $cid        = new Conexion();
        $this->conn = $cid->conectar($cfg['db']);
        if ($this->conn === false) {
            $errors = sqlsrv_errors() ?? [];
            $msg    = $errors[0]['message'] ?? 'sin detalle';
            throw new RuntimeException('No se pudo conectar a [' . $cfg['db'] . ']: ' . $msg);
        }
        sqlsrv_query($this->conn, 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
    }

    /* ──────────────────────────────────────────────
     *  HELPERS
     * ────────────────────────────────────────────── */

    private function query(string $sql, array $params = []): array
    {
        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            throw new RuntimeException('SQL error: ' . ($err[0]['message'] ?? 'unknown'));
        }
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $rows;
    }

    private function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }

    private function grupoFiltro(string $alias, string $col = 'NRO_SUCURS'): array
    {
        if (($_SESSION['tipo'] ?? '') !== 'GRUPO') return ['', []];
        $suc = $_SESSION['sucursalesGrupo'] ?? [];
        return Filters::sucursalesGrupo($suc, $alias, $col);
    }

    /** Construye el array de params de filtro normalizado para Filters::build(). */
    private function fp(?int $sucursal, ?string $grupo, ?string $tipoTienda, ?string $canal, ?array $activasIds): array
    {
        return [
            'sucursal'    => $sucursal,
            'grupo'       => $grupo,
            'tipo_tienda' => $tipoTienda,
            'canal'       => $canal,
            'solo_activas' => $activasIds !== null,
            'activas_ids'  => $activasIds,
            'tipo_local'   => ($this->origen === 'franquicias') ? ($_GET['tipo_local'] ?? null) : null,
            'zona'         => ($this->origen === 'franquicias') ? ($_GET['zona'] ?? null) : null,
            'grupo_empresario' => ($this->origen === 'franquicias') ? ($_GET['grupo_empresario'] ?? null) : null,
        ];
    }

    /**
     * Origen de las ventas (BI_SALES_SUCURSALES o UNION con franquicias sin Tango).
     * Referencia: GlobalDashboardDB::fromVentasSucursales().
     */
    private function fromVentas(string $desde, string $hasta): string
    {
        if ($this->origen !== 'franquicias') {
            return 'BI_SALES_SUCURSALES s WITH (NOLOCK)';
        }
        return "(
            SELECT NRO_SUCURS, FECHA, IMPORTE, CANTIDAD, RUBRO COLLATE DATABASE_DEFAULT AS RUBRO
            FROM BI_SALES_SUCURSALES WITH (NOLOCK)
            UNION ALL
            SELECT pv.idTango AS NRO_SUCURS, fd.fecha AS FECHA, fd.importeVentaReal AS IMPORTE, 0 AS CANTIDAD, CAST('FRANQUICIA_ST' AS VARCHAR(50)) COLLATE DATABASE_DEFAULT AS RUBRO
            FROM sistemas.dbo.FP_ObjetivosFinalesDetalle fd WITH (NOLOCK)
            INNER JOIN sistemas.dbo.PuntosDeVenta pv WITH (NOLOCK) ON fd.idPOS = pv.id
            INNER JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WITH (NOLOCK) ON pv.idTango = sl.NRO_SUCURSAL
            WHERE (sl.TANGO IS NULL OR sl.TANGO <> 1) AND sl.HABILITADO = 1
              AND fd.fecha >= '{$desde}' AND fd.fecha <= '{$hasta}'
        ) s";
    }

    /** Condición de FECHA_HORA (no aplica a Uruguay). */
    private function condFechaHora(string $alias): string
    {
        return $this->origen === 'uruguay' ? '' : "AND {$alias}.FECHA_HORA IS NOT NULL";
    }

    /**
     * Universo de sucursales en alcance: las que tuvieron venta o algún registro
     * de tráfico (con o sin sensor) en el período, respetando los filtros activos.
     * @return int[] NRO_SUCURS
     */
    private function getUniversoNros(string $desde, string $hasta, array $fp): array
    {
        [$sfS,  $pS]  = Filters::build($fp, 's', $this->campoVendedor, $this->origen, false, false);
        [$sfGS, $pGS] = $this->grupoFiltro('s');
        $rowsV = $this->query("
            SELECT DISTINCT s.NRO_SUCURS
            FROM {$this->fromVentas($desde, $hasta)}
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') {$sfS} {$sfGS}
        ", array_merge([$desde, $hasta], $pS, $pGS));

        [$sfI,  $pI]  = Filters::build($fp, 'i', $this->campoVendedor, $this->origen, false, false, 'NRO_SUCURS', false);
        [$sfGI, $pGI] = $this->grupoFiltro('i');
        $rowsI = $this->query("
            SELECT DISTINCT i.NRO_SUCURS
            FROM BI_T_INGRESOS_SUCURSALES i WITH (NOLOCK)
            WHERE i.FECHA >= ? AND i.FECHA < DATEADD(day,1,CAST(? AS DATE)) {$sfI} {$sfGI}
        ", array_merge([$desde, $hasta], $pI, $pGI));

        $set = [];
        foreach ($rowsV as $r) $set[(int)$r['NRO_SUCURS']] = true;
        foreach ($rowsI as $r) $set[(int)$r['NRO_SUCURS']] = true;
        return array_keys($set);
    }

    /* ──────────────────────────────────────────────
     *  RESUMEN (KPIs de la cadena / universo filtrado)
     * ────────────────────────────────────────────── */

    public function getResumen(
        string $desde, string $hasta,
        ?int $sucursal = null, ?string $grupo = null, ?string $tipoTienda = null, ?string $canal = null,
        ?array $activasIds = null
    ): array {
        $fp = $this->fp($sucursal, $grupo, $tipoTienda, $canal, $activasIds);

        [$sfI, $pI]   = Filters::build($fp, 'i', $this->campoVendedor, $this->origen, false, false, 'NRO_SUCURS', false);
        [$sfGI, $pGI] = $this->grupoFiltro('i');
        $rowI = $this->queryOne("
            SELECT ISNULL(SUM(i.INGRESOS), 0) AS ingresos
            FROM BI_T_INGRESOS_SUCURSALES i WITH (NOLOCK)
            WHERE i.FECHA >= ? AND i.FECHA < DATEADD(day,1,CAST(? AS DATE))
              {$this->condFechaHora('i')} AND i.INGRESOS > 0 {$sfI} {$sfGI}
        ", array_merge([$desde, $hasta], $pI, $pGI));

        [$sfM, $pM]   = Filters::build($fp, 'm', $this->campoVendedor, $this->origen, false, false, 'NRO_SUCURS', false);
        [$sfGM, $pGM] = $this->grupoFiltro('m');
        $rowM = $this->queryOne("
            SELECT ISNULL(SUM(m.MERODEO), 0) AS merodeo
            FROM BI_T_INGRESOS_SUCURSALES m WITH (NOLOCK)
            WHERE m.FECHA >= ? AND m.FECHA < DATEADD(day,1,CAST(? AS DATE))
              {$this->condFechaHora('m')} AND m.MERODEO > 0 {$sfM} {$sfGM}
        ", array_merge([$desde, $hasta], $pM, $pGM));

        // Sucursales "con sensor": cualquiera con merodeo o ingresos registrados en el período
        // (mismo criterio que tiene_sensor en getPorSucursal). No exige las dos columnas a la vez:
        // una sucursal con ingresos pero sin merodeo por un error de carga igual cuenta, porque
        // alcanza para calcular su tasa de conversión.
        [$sfSen, $pSen]   = Filters::build($fp, 'sen', $this->campoVendedor, $this->origen, false, false, 'NRO_SUCURS', false);
        [$sfGSen, $pGSen] = $this->grupoFiltro('sen');
        $rowSensor = $this->queryOne("
            SELECT COUNT(DISTINCT sen.NRO_SUCURS) AS suc_con_sensor
            FROM BI_T_INGRESOS_SUCURSALES sen WITH (NOLOCK)
            WHERE sen.FECHA >= ? AND sen.FECHA < DATEADD(day,1,CAST(? AS DATE))
              {$this->condFechaHora('sen')} AND (sen.MERODEO > 0 OR sen.INGRESOS > 0) {$sfSen} {$sfGSen}
        ", array_merge([$desde, $hasta], $pSen, $pGSen));

        [$sfT, $pT]   = Filters::build($fp, 't', $this->campoVendedor, $this->origen, false, false);
        [$sfGT, $pGT] = $this->grupoFiltro('t');
        $rowT = $this->queryOne("
            SELECT COUNT(DISTINCT t.N_COMP) AS tickets
            FROM BI_SALES_TOTAL_TICKETS t WITH (NOLOCK)
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT} {$sfGT}
              AND EXISTS (
                  SELECT 1 FROM BI_T_INGRESOS_SUCURSALES i2 WITH (NOLOCK)
                  WHERE i2.NRO_SUCURS = t.NRO_SUCURS
                    AND CAST(i2.FECHA AS DATE) = CAST(t.FECHA AS DATE)
                    AND i2.FECHA >= ? AND i2.FECHA < DATEADD(day,1,CAST(? AS DATE))
                    {$this->condFechaHora('i2')} AND i2.INGRESOS > 0
              )
        ", array_merge([$desde, $hasta], $pT, $pGT, [$desde, $hasta]));

        [$sfS, $pS]   = Filters::build($fp, 's', $this->campoVendedor, $this->origen, false, false);
        [$sfGS, $pGS] = $this->grupoFiltro('s');
        $rowF = $this->queryOne("
            SELECT ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM {$this->fromVentas($desde, $hasta)}
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') {$sfS} {$sfGS}
        ", array_merge([$desde, $hasta], $pS, $pGS));

        $universo = $this->getUniversoNros($desde, $hasta, $fp);

        $ingresos    = (int)($rowI['ingresos']    ?? 0);
        $merodeo     = (int)($rowM['merodeo']     ?? 0);
        $tickets     = (int)($rowT['tickets']     ?? 0);
        $facturacion = (float)($rowF['facturacion'] ?? 0);

        return [
            'merodeo'              => $merodeo,
            'ingresos'             => $ingresos,
            'tickets'              => $tickets,
            'facturacion'          => $facturacion,
            'atraccion'            => $merodeo  > 0 ? $ingresos / $merodeo  : 0,
            'conversion'           => ConversionHelper::rate($tickets, $ingresos),
            'venta_por_visitante'  => $ingresos > 0 ? $facturacion / $ingresos : 0,
            'venta_por_merodeador' => $merodeo  > 0 ? $facturacion / $merodeo  : 0,
            'sucursales_con_sensor' => (int)($rowSensor['suc_con_sensor'] ?? 0),
            'sucursales_total'      => count($universo),
        ];
    }

    /* ──────────────────────────────────────────────
     *  DETALLE POR SUCURSAL
     * ────────────────────────────────────────────── */

    public function getPorSucursal(
        string $desde, string $hasta,
        ?int $sucursal = null, ?string $grupo = null, ?string $tipoTienda = null, ?string $canal = null,
        ?array $activasIds = null
    ): array {
        $fp   = $this->fp($sucursal, $grupo, $tipoTienda, $canal, $activasIds);
        $nros = $this->getUniversoNros($desde, $hasta, $fp);
        if (empty($nros)) return [];

        $ph = implode(',', array_fill(0, count($nros), '?'));

        // Merodeo + ingresos por sucursal (sin cruzar los dos filtros entre sí)
        $rowsIM = $this->query("
            SELECT i.NRO_SUCURS,
                   ISNULL(SUM(CASE WHEN i.MERODEO  > 0 THEN i.MERODEO  ELSE 0 END), 0) AS merodeo,
                   ISNULL(SUM(CASE WHEN i.INGRESOS > 0 THEN i.INGRESOS ELSE 0 END), 0) AS ingresos
            FROM BI_T_INGRESOS_SUCURSALES i WITH (NOLOCK)
            WHERE i.FECHA >= ? AND i.FECHA < DATEADD(day,1,CAST(? AS DATE))
              {$this->condFechaHora('i')}
              AND i.NRO_SUCURS IN ({$ph})
            GROUP BY i.NRO_SUCURS
        ", array_merge([$desde, $hasta], $nros));
        $mapIM = [];
        foreach ($rowsIM as $r) {
            $mapIM[(int)$r['NRO_SUCURS']] = ['merodeo' => (int)$r['merodeo'], 'ingresos' => (int)$r['ingresos']];
        }

        // Tickets por sucursal — mismo criterio que getConversion (días con ingresos registrados)
        $rowsT = $this->query("
            SELECT t.NRO_SUCURS, COUNT(DISTINCT t.N_COMP) AS tickets, ISNULL(SUM(t.IMP_TOTAL_TICKET), 0) AS suma_tickets
            FROM BI_SALES_TOTAL_TICKETS t WITH (NOLOCK)
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC'
              AND t.NRO_SUCURS IN ({$ph})
              AND EXISTS (
                  SELECT 1 FROM BI_T_INGRESOS_SUCURSALES i2 WITH (NOLOCK)
                  WHERE i2.NRO_SUCURS = t.NRO_SUCURS
                    AND CAST(i2.FECHA AS DATE) = CAST(t.FECHA AS DATE)
                    AND i2.FECHA >= ? AND i2.FECHA < DATEADD(day,1,CAST(? AS DATE))
                    {$this->condFechaHora('i2')} AND i2.INGRESOS > 0
              )
            GROUP BY t.NRO_SUCURS
        ", array_merge([$desde, $hasta], $nros, [$desde, $hasta]));
        $mapT = [];
        foreach ($rowsT as $r) {
            $mapT[(int)$r['NRO_SUCURS']] = ['tickets' => (int)$r['tickets'], 'suma_tickets' => (float)$r['suma_tickets']];
        }

        // Facturación por sucursal
        $rowsF = $this->query("
            SELECT s.NRO_SUCURS, ISNULL(SUM(s.IMPORTE), 0) AS facturacion
            FROM {$this->fromVentas($desde, $hasta)}
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
              AND s.NRO_SUCURS IN ({$ph})
            GROUP BY s.NRO_SUCURS
        ", array_merge([$desde, $hasta], $nros));
        $mapF = [];
        foreach ($rowsF as $r) $mapF[(int)$r['NRO_SUCURS']] = (float)$r['facturacion'];

        // Nombres de sucursal
        $rowsDesc = $this->query("
            SELECT NRO_SUCURSAL, DESC_SUCURSAL
            FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS WITH (NOLOCK)
            WHERE NRO_SUCURSAL IN ({$ph})
        ", $nros);
        $descMap = [];
        foreach ($rowsDesc as $r) $descMap[(int)$r['NRO_SUCURSAL']] = $r['DESC_SUCURSAL'];

        $result = [];
        foreach ($nros as $nro) {
            $im = $mapIM[$nro] ?? ['merodeo' => 0, 'ingresos' => 0];
            $tk = $mapT[$nro]  ?? ['tickets' => 0, 'suma_tickets' => 0];
            $fact = $mapF[$nro] ?? 0.0;
            $tieneSensor = $im['merodeo'] > 0 || $im['ingresos'] > 0;

            $result[] = [
                'nro_sucurs'          => $nro,
                'desc_sucursal'       => $descMap[$nro] ?? ('Suc. ' . $nro),
                'tiene_sensor'        => $tieneSensor,
                'merodeo'             => $tieneSensor ? $im['merodeo']  : null,
                'ingresos'            => $tieneSensor ? $im['ingresos'] : null,
                'tickets'             => $tk['tickets'],
                'facturacion'         => $fact,
                'atraccion'           => ($tieneSensor && $im['merodeo']  > 0) ? $im['ingresos'] / $im['merodeo']  : null,
                'conversion'          => $tieneSensor ? ConversionHelper::rate($tk['tickets'], $im['ingresos']) : null,
                'ticket_promedio'     => $tk['tickets'] > 0 ? $tk['suma_tickets'] / $tk['tickets'] : 0,
                'venta_por_visitante' => ($tieneSensor && $im['ingresos'] > 0) ? $fact / $im['ingresos'] : null,
            ];
        }

        usort($result, fn($a, $b) => $b['facturacion'] <=> $a['facturacion']);
        return $result;
    }

    /* ──────────────────────────────────────────────
     *  EVOLUCIÓN MENSUAL
     * ────────────────────────────────────────────── */

    public function getEvolucionMensual(
        int $meses = 13,
        ?int $sucursal = null, ?string $grupo = null, ?string $tipoTienda = null, ?string $canal = null,
        ?array $activasIds = null
    ): array {
        $meses = max(1, min($meses, 36));
        $hasta = date('Y-m-d', strtotime('-1 day'));
        $desde = (new DateTime($hasta))
            ->modify('first day of this month')
            ->modify('-' . ($meses - 1) . ' months')
            ->format('Y-m-d');

        $fp = $this->fp($sucursal, $grupo, $tipoTienda, $canal, $activasIds);

        [$sfI, $pI]   = Filters::build($fp, 'i', $this->campoVendedor, $this->origen, false, false, 'NRO_SUCURS', false);
        [$sfGI, $pGI] = $this->grupoFiltro('i');
        $rowsIM = $this->query("
            SELECT YEAR(i.FECHA) AS anio, MONTH(i.FECHA) AS mes,
                   ISNULL(SUM(CASE WHEN i.MERODEO  > 0 THEN i.MERODEO  ELSE 0 END), 0) AS merodeo,
                   ISNULL(SUM(CASE WHEN i.INGRESOS > 0 THEN i.INGRESOS ELSE 0 END), 0) AS ingresos
            FROM BI_T_INGRESOS_SUCURSALES i WITH (NOLOCK)
            WHERE i.FECHA >= ? AND i.FECHA < DATEADD(day,1,CAST(? AS DATE))
              {$this->condFechaHora('i')} {$sfI} {$sfGI}
            GROUP BY YEAR(i.FECHA), MONTH(i.FECHA)
        ", array_merge([$desde, $hasta], $pI, $pGI));
        $mapIM = [];
        foreach ($rowsIM as $r) {
            $key = sprintf('%04d-%02d', (int)$r['anio'], (int)$r['mes']);
            $mapIM[$key] = ['merodeo' => (int)$r['merodeo'], 'ingresos' => (int)$r['ingresos']];
        }

        [$sfT, $pT]   = Filters::build($fp, 't', $this->campoVendedor, $this->origen, false, false);
        [$sfGT, $pGT] = $this->grupoFiltro('t');
        $rowsT = $this->query("
            SELECT YEAR(t.FECHA) AS anio, MONTH(t.FECHA) AS mes, COUNT(DISTINCT t.N_COMP) AS tickets
            FROM BI_SALES_TOTAL_TICKETS t WITH (NOLOCK)
            WHERE t.FECHA >= ? AND t.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND t.T_COMP = 'FAC' {$sfT} {$sfGT}
              AND EXISTS (
                  SELECT 1 FROM BI_T_INGRESOS_SUCURSALES i2 WITH (NOLOCK)
                  WHERE i2.NRO_SUCURS = t.NRO_SUCURS
                    AND CAST(i2.FECHA AS DATE) = CAST(t.FECHA AS DATE)
                    AND i2.FECHA >= ? AND i2.FECHA < DATEADD(day,1,CAST(? AS DATE))
                    {$this->condFechaHora('i2')} AND i2.INGRESOS > 0
              )
            GROUP BY YEAR(t.FECHA), MONTH(t.FECHA)
        ", array_merge([$desde, $hasta], $pT, $pGT, [$desde, $hasta]));
        $mapT = [];
        foreach ($rowsT as $r) {
            $key = sprintf('%04d-%02d', (int)$r['anio'], (int)$r['mes']);
            $mapT[$key] = (int)$r['tickets'];
        }

        $result = [];
        $cursor = new DateTime($desde);
        for ($i = 0; $i < $meses; $i++) {
            $key = $cursor->format('Y-m');
            $im  = $mapIM[$key] ?? ['merodeo' => 0, 'ingresos' => 0];
            $tk  = $mapT[$key]  ?? 0;
            $result[] = [
                'anio'       => (int)$cursor->format('Y'),
                'mes'        => (int)$cursor->format('n'),
                'periodo'    => $key,
                'merodeo'    => $im['merodeo'],
                'ingresos'   => $im['ingresos'],
                'tickets'    => $tk,
                'atraccion'  => $im['merodeo']  > 0 ? $im['ingresos'] / $im['merodeo']  : 0,
                'conversion' => ConversionHelper::rate($tk, $im['ingresos']),
            ];
            $cursor->modify('+1 month');
        }
        return $result;
    }
}
