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
    public function getProductividadFact(string $desde, string $hasta, ?string $usuario): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_PRODUCTIVIDAD_FACTURACION ?,?,?',
            [$desde, $hasta, $usuario]
        );
        return [
            'kpis'     => $sets[0][0] ?? [],
            'evolucion'=> $sets[1] ?? [],
            'usuarios' => $sets[2] ?? [],
            'ultimos7' => $sets[3] ?? [],
            'horas'    => $sets[4] ?? [],
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
    public function getDemandaDespacho(string $desde, string $hasta): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_DEMANDA_DESPACHO ?,?', [$desde, $hasta]);
        $kpi  = $sets[0][0] ?? [];
        $totUnid  = (float)($kpi['UNID_TOTALES']    ?? 0);
        $pendUnid = (float)($kpi['UNID_PENDIENTES'] ?? 0);
        $kpi['PCT_UNID_CUMPLIDAS'] = $totUnid > 0 ? 1 - ($pendUnid / $totUnid) : null;
        return [
            'kpis'           => $kpi,
            'pendientes_hoy' => $sets[1] ?? [],
            'demorados'      => $sets[2] ?? [],
            'prox_entrega'   => $sets[3] ?? [],
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
}
