<?php
require_once __DIR__ . '/LogisticaDBBase.php';

class LogisticaDB_UY extends LogisticaDBBase
{
    public function __construct()
    {
        parent::__construct('power_uy');
    }

    // ── Eficiencia logística UY ──────────────────────────────────────────
    public function getEficienciaUy(
        string  $desde,
        string  $hasta,
        ?string $canal,
        ?string $rubro,
        ?float  $cotizacionUsd
    ): array {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_EFICIENCIA_LOGISTICA_UY ?,?,?,?,?',
            [$desde, $hasta, $canal, $rubro, $cotizacionUsd]
        );
        $kpi = $sets[0][0] ?? [];
        // Calcular ratios derivados en PHP
        $pedidas    = (float)($kpi['UNID_PEDIDAS']        ?? 0);
        $facturadas = (float)($kpi['UNID_FACTURADAS']     ?? 0);
        $importePed = (float)($kpi['IMPORTE_PEDIDO_TOTAL'] ?? 0);
        $perdida    = (float)($kpi['PERDIDA_UYU']         ?? 0);
        $kpi['EFI_UNIDADES'] = $pedidas > 0 ? $facturadas / $pedidas : null;
        $kpi['PCT_PERDIDA']  = $importePed > 0 ? $perdida / $importePed : null;
        return [
            'kpis'      => $kpi,
            'por_rubro' => $sets[1] ?? [],
            'evolucion' => $sets[2] ?? [],
            'canales'   => array_column($sets[3] ?? [], 'CANAL'),
        ];
    }

    // ── Stock WMS vs Tango UY ────────────────────────────────────────────
    public function getStockUy(?string $rubro): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_STOCK_WMS_TANGO_UY ?', [$rubro]);
        return [
            'kpis'         => $sets[0][0] ?? [],
            'rubros'       => $sets[1] ?? [],
            'lista_rubros' => array_column($sets[2] ?? [], 'RUBRO'),
        ];
    }

    // ── Filtros dinámicos UY ─────────────────────────────────────────────
    public function getCanalesUy(): array
    {
        return array_column(
            $this->query("SELECT DISTINCT CANAL FROM dbo.BI_T_EFICIENCIA_LOGISTICA_UY
                          WHERE CANAL IS NOT NULL AND LTRIM(RTRIM(CANAL)) <> ''
                          ORDER BY CANAL"),
            'CANAL'
        );
    }

    public function getRubrosUy(): array
    {
        return array_column(
            $this->query("SELECT DISTINCT RUBRO FROM dbo.BI_T_EFICIENCIA_LOGISTICA_UY
                          WHERE RUBRO IS NOT NULL AND LTRIM(RTRIM(RUBRO)) <> ''
                          ORDER BY RUBRO"),
            'RUBRO'
        );
    }

    // Devuelve la última cotización UYU/USD con FECHA <= $hasta, o null si no hay.
    public function getUltimaCotizacion(string $hasta): ?float
    {
        $row = $this->queryOne(
            "SELECT TOP 1 COTIZACION FROM dbo.RO_T_COTIZACION_UYU_USD
             WHERE FECHA <= ? ORDER BY FECHA DESC",
            [$hasta]
        );
        return $row ? (float)$row['COTIZACION'] : null;
    }
}
