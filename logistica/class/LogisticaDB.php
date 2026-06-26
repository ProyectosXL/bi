<?php
require_once __DIR__ . '/LogisticaDBBase.php';

class LogisticaDB extends LogisticaDBBase
{
    public function __construct()
    {
        parent::__construct('power');
    }

    // ── Área 1: Eficiencia logística ─────────────────────────────────────
    public function getEficiencia(string $desde, string $hasta, ?string $canal): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_EFICIENCIA_LOGISTICA ?,?,?',
            [$desde, $hasta, $canal]
        );
        $kpi = $sets[0][0] ?? [];
        $pedidas    = (float)($kpi['UNID_PEDIDAS']            ?? 0);
        $facturadas = (float)($kpi['UNID_FACTURADAS']         ?? 0);
        $importePed = (float)($kpi['IMPORTE_PEDIDO_FILTRADO'] ?? 0);
        $perdida    = (float)($kpi['PERDIDA_FACT']            ?? 0);
        $kpi['EFI_UNIDADES']   = $pedidas  > 0 ? $facturadas / $pedidas : null;
        $kpi['PCT_PERDIDA']    = $importePed > 0 ? $perdida / $importePed : null;
        $pedidasAA = (float)($kpi['UNID_PEDIDAS_AA']    ?? 0);
        $factAA    = (float)($kpi['UNID_FACTURADAS_AA'] ?? 0);
        $kpi['EFI_UNIDADES_AA'] = $pedidasAA > 0 ? $factAA / $pedidasAA : null;
        return [
            'kpis'             => $kpi,
            'evolucion'        => $sets[1] ?? [],
            'canales'          => array_column($sets[2] ?? [], 'CANAL'),
            'eficiencia_canal' => $sets[3] ?? [],
        ];
    }

    // ── Área 2: Lead time facturación ────────────────────────────────────
    public function getLeadTime(string $desde, string $hasta): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_LEADTIME_FACTURACION ?,?', [$desde, $hasta]);
        $kpi  = $sets[0][0] ?? [];
        $total    = (int)($kpi['COMP_FACTURADOS'] ?? 0);
        $demorados = (int)($kpi['COMP_DEMORADOS']  ?? 0);
        $kpi['PCT_DEMORADOS'] = $total > 0 ? $demorados / $total : null;
        return [
            'kpis'       => $kpi,
            'histograma' => $sets[1] ?? [],
            'evolucion'  => $sets[2] ?? [],
        ];
    }

    // ── Área 3: Stock WMS vs Tango ───────────────────────────────────────
    public function getStock(?string $rubro): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_STOCK_WMS_TANGO ?', [$rubro]);
        return [
            'kpis'         => $sets[0][0] ?? [],
            'rubros'       => $sets[1] ?? [],
            'lista_rubros' => array_column($sets[2] ?? [], 'RUBRO'),
            'detalle_articulos' => $sets[3] ?? [],   // drill-down del detalle por rubro
        ];
    }

    // ── Área 4: Productividad facturación ────────────────────────────────
    public function getProductividadFact(string $desde, string $hasta, ?string $tipo, ?string $rubro): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_PRODUCTIVIDAD_FACTURACION ?,?,?,?',
            [$desde, $hasta, $tipo, $rubro]
        );
        return [
            'kpis'     => $sets[0][0] ?? [],
            'evolucion'=> $sets[1] ?? [],
            'usuarios' => $sets[2] ?? [],
            'ultimos7' => $sets[3] ?? [],
        ];
    }

    // ── Área 5: Productividad picking ────────────────────────────────────
    public function getProductividadPicking(string $desde, string $hasta, ?string $usuario): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_PRODUCTIVIDAD_PICKING ?,?,?',
            [$desde, $hasta, $usuario]
        );
        return [
            'kpis'     => $sets[0][0] ?? [],
            'evolucion'=> $sets[1] ?? [],
            'usuarios' => $sets[2] ?? [],
            'ultimos7' => $sets[3] ?? [],
        ];
    }

    // ── Área 6: Demanda y despacho ───────────────────────────────────────
    // ── Área 6a: Planificación de despacho ───────────────────────────────
    public function getPlanificacion(?string $canal): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_PLANIFICACION ?', [$canal]);
        // Result set 1 (ventanas) re-indexado por VENTANA para acceso directo.
        $ventanas = [];
        foreach (($sets[1] ?? []) as $row) {
            $ventanas[$row['VENTANA']] = $row;
        }
        return [
            'kpis'       => $sets[0][0] ?? [],
            'ventanas'   => $ventanas,
            'pendientes' => $sets[2] ?? [],
            'demorados'  => $sets[3] ?? [],
        ];
    }

    // ── Área 6b: Eficacia de despacho ────────────────────────────────────
    public function getDespacho(string $desde, string $hasta, ?string $canal, ?string $cliente): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_DESPACHO ?,?,?,?',
            [$desde, $hasta, $canal, $cliente]
        );
        return [
            'kpis'             => $sets[0][0] ?? [],
            'canal'            => $sets[1] ?? [],
            'eficacia_cliente' => $sets[2] ?? [],
            'eficacia_pedido'  => $sets[3] ?? [],
            'demorados_cliente'=> $sets[4] ?? [],
            'demorados_pedido' => $sets[5] ?? [],
            'evolucion'        => $sets[6] ?? [],
        ];
    }

    // ── Área 7: Pedidos consolidados ─────────────────────────────────────
    public function getPedidosConsolidados(string $desde, string $hasta, ?string $canal): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_PEDIDOS_CONSOLIDADOS ?,?,?',
            [$desde, $hasta, $canal]
        );
        return [
            'kpis'     => $sets[0][0] ?? [],
            'evolucion'=> $sets[1] ?? [],
            'tabla'    => $sets[2] ?? [],
            'canales'  => array_column($sets[3] ?? [], 'CANAL'),
        ];
    }

    // ── Detalle de un pedido (eficiencia por rubro) ──────────────────────
    public function getPedidoDetalle(string $pedido): array
    {
        // El front recibe NRO_PEDIDO ya "numerizado" por JSON_NUMERIC_CHECK
        // (se pierden el espacio inicial y los ceros a la izquierda). El
        // formato canónico almacenado es ' ' + 13 dígitos (14 chars). Se
        // reconstruyen las variantes posibles para que el match use el índice.
        $digits = preg_replace('/\D/', '', $pedido);
        $params = [];
        if ($digits !== '') {
            $pad13 = str_pad($digits, 13, '0', STR_PAD_LEFT);
            $params[] = ' ' . $pad13;   // canónico (espacio + 13 dígitos)
            $params[] = $pad13;         // sin espacio
            $params[] = $digits;        // crudo
        }
        $raw = trim($pedido);
        if ($raw !== '' && !in_array($raw, $params, true)) {
            $params[] = $raw;
        }
        if (!$params) {
            return ['header' => null, 'lineas' => []];
        }
        $in = implode(',', array_fill(0, count($params), '?'));

        $header = $this->queryOne(
            "SELECT TOP 1 LTRIM(RTRIM(NRO_PEDIDO)) AS NRO_PEDIDO, CLIENTE, CANAL,
                    FECHA_PEDI, TALON_PED
             FROM dbo.BI_EFICIENCIA_LOGISTICA
             WHERE NRO_PEDIDO IN ($in)",
            $params
        );

        // Detalle agrupado por RUBRO: unidades pedidas, facturadas y eficiencia.
        $rubros = $this->query(
            "SELECT ISNULL(NULLIF(LTRIM(RTRIM(RUBRO)), ''), 'SIN RUBRO') AS RUBRO,
                    CAST(SUM(CANT_PEDID)     AS DECIMAL(18,2)) AS CANT_PEDID,
                    CAST(SUM(CANT_FACTURADA) AS DECIMAL(18,2)) AS CANT_FACT
             FROM dbo.BI_EFICIENCIA_LOGISTICA
             WHERE NRO_PEDIDO IN ($in)
             GROUP BY ISNULL(NULLIF(LTRIM(RTRIM(RUBRO)), ''), 'SIN RUBRO')
             ORDER BY RUBRO",
            $params
        );

        // Totales + eficiencia por rubro (calculada en PHP para evitar /0).
        $totPed = 0.0; $totFac = 0.0;
        foreach ($rubros as &$r) {
            $ped = (float)$r['CANT_PEDID'];
            $fac = (float)$r['CANT_FACT'];
            $r['EFICIENCIA'] = $ped > 0 ? $fac / $ped : null;
            $totPed += $ped; $totFac += $fac;
        }
        unset($r);

        return [
            'header' => $header,
            'rubros' => $rubros,
            'totales' => [
                'CANT_PEDID'  => $totPed,
                'CANT_FACT'   => $totFac,
                'EFICIENCIA'  => $totPed > 0 ? $totFac / $totPed : null,
            ],
        ];
    }

    // ── Filtros dinámicos ────────────────────────────────────────────────
    public function getUsuariosFact(): array
    {
        return array_column(
            $this->query("SELECT DISTINCT USUARIO FROM dbo.BI_FACTURACION_LOGISTICA
                          WHERE USUARIO IS NOT NULL AND LTRIM(RTRIM(USUARIO)) <> ''
                          ORDER BY USUARIO"),
            'USUARIO'
        );
    }

    public function getUsuariosPicking(): array
    {
        return array_column(
            $this->query("SELECT DISTINCT USUARIO FROM dbo.BI_T_TRACKING_PICKING
                          WHERE USUARIO IS NOT NULL AND LTRIM(RTRIM(USUARIO)) <> ''
                          ORDER BY USUARIO"),
            'USUARIO'
        );
    }

    public function getRubrosStock(): array
    {
        return array_column(
            $this->query("SELECT DISTINCT RUBRO FROM dbo.BI_STOCK_WMS_TANGO
                          WHERE RUBRO IS NOT NULL AND LTRIM(RTRIM(RUBRO)) <> ''
                          ORDER BY RUBRO"),
            'RUBRO'
        );
    }

    public function getCanalesEficiencia(): array
    {
        return array_column(
            $this->query("SELECT DISTINCT CANAL FROM dbo.BI_EFICIENCIA_LOGISTICA
                          WHERE CANAL IS NOT NULL AND LTRIM(RTRIM(CANAL)) <> ''
                          ORDER BY CANAL"),
            'CANAL'
        );
    }

    public function getTiposFact(): array
    {
        return array_column(
            $this->query("SELECT DISTINCT TIPO_FACTURACION FROM dbo.BI_FACTURACION_LOGISTICA
                          WHERE TIPO_FACTURACION IS NOT NULL AND LTRIM(RTRIM(TIPO_FACTURACION)) <> ''
                          ORDER BY TIPO_FACTURACION"),
            'TIPO_FACTURACION'
        );
    }

    public function getRubrosFact(): array
    {
        return array_column(
            $this->query("SELECT DISTINCT RUBRO FROM dbo.BI_FACTURACION_LOGISTICA
                          WHERE RUBRO IS NOT NULL AND LTRIM(RTRIM(RUBRO)) <> ''
                          ORDER BY RUBRO"),
            'RUBRO'
        );
    }

    public function getClientesDespacho(): array
    {
        return array_column(
            $this->query("SELECT DISTINCT CLIENTE FROM dbo.RO_T_DESPACHO_PEDIDOS
                          WHERE CLIENTE IS NOT NULL AND LTRIM(RTRIM(CLIENTE)) <> ''
                          ORDER BY CLIENTE"),
            'CLIENTE'
        );
    }
}
