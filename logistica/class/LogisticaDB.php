<?php
require_once __DIR__ . '/LogisticaDBBase.php';

class LogisticaDB extends LogisticaDBBase
{
    public function __construct()
    {
        parent::__construct('power');
    }

    // Tipo de remisión desde el querystring: solo valores conocidos; '' u otro = todos (null).
    public static function tipoParam(): ?string
    {
        $tipo = $_GET['tipo'] ?? '';
        return in_array($tipo, ['REPOSICION', 'DIST. INICIAL'], true) ? $tipo : null;
    }

    // ── Área 1: Eficiencia logística ─────────────────────────────────────
    public function getEficiencia(string $desde, string $hasta, ?string $canal, ?string $tipo = 'REPOSICION'): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_EFICIENCIA_LOGISTICA ?,?,?,?',
            [$desde, $hasta, $canal, $tipo]
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
        // Pérdida año anterior — % sobre importe pedido del año anterior.
        $perdidaAA    = (float)($kpi['PERDIDA_FACT_AA']  ?? 0);
        $importePedAA = (float)($kpi['IMPORTE_PEDIDO_AA'] ?? 0);
        $kpi['PCT_PERDIDA_AA'] = $importePedAA > 0 ? $perdidaAA / $importePedAA : null;
        return [
            'kpis'             => $kpi,
            'evolucion'        => $sets[1] ?? [],
            'canales'          => array_column($sets[2] ?? [], 'CANAL'),
            'eficiencia_canal' => $sets[3] ?? [],
            'efi_cliente'      => $sets[4] ?? [],   // peores 10 por cliente
            'efi_rubro'        => $sets[5] ?? [],   // por rubro
            'efi_pedidos'      => $sets[6] ?? [],   // por pedido y cliente (drill)
            'perdida_12m'      => $sets[7] ?? [],   // proporción e importe pérdida 12m
        ];
    }

    // ── Área 2: Lead time facturación ────────────────────────────────────
    public function getLeadTime(string $desde, string $hasta, ?string $canal = null, ?string $tipo = null): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_LEADTIME_FACTURACION ?,?,?,?', [$desde, $hasta, $canal, $tipo]);
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
    public function getStock(?string $rubro, ?string $deposito = null): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_STOCK_WMS_TANGO ?,?', [$rubro, $deposito]);
        return [
            'kpis'         => $sets[0][0] ?? [],
            'rubros'       => $sets[1] ?? [],
            'lista_rubros' => array_column($sets[2] ?? [], 'RUBRO'),
            'detalle_articulos' => $sets[3] ?? [],   // drill-down del detalle por rubro
        ];
    }

    // ── Área 4: Productividad facturación ────────────────────────────────
    public function getProductividadFact(string $desde, string $hasta, ?string $tipo, ?string $rubro, ?string $canal = null): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_PRODUCTIVIDAD_FACTURACION ?,?,?,?,?',
            [$desde, $hasta, $tipo, $rubro, $canal]
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

    // ── Área 5c: unidades pickeadas por canal (WMS de Tango) ─────────────
    public function getPickingCanal(string $desde, string $hasta, ?string $usuario, ?string $tipo = null): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_PICKING_CANAL ?,?,?,?',
            [$desde, $hasta, $usuario, $tipo]
        );
        return [
            'ok'     => (int)($sets[0][0]['OK'] ?? 0) === 1,
            'canales'=> $sets[1] ?? [],
        ];
    }

    // ── Área 5b: WIP de picking (tareas abiertas, situación actual) ──────
    public function getWipPicking(?string $usuario): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_WIP_PICKING ?', [$usuario]);
        return [
            'kpis'   => $sets[0][0] ?? [],
            'tareas' => $sets[1] ?? [],
        ];
    }

    // ── Área 6: Demanda y despacho ───────────────────────────────────────
    // ── Área 6a: Planificación de despacho ───────────────────────────────
    public function getPlanificacion(?string $canal, ?string $tipo = null): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_PLANIFICACION ?,?', [$canal, $tipo]);
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
            'wip'        => $sets[4] ?? [],   // WIP por día de entrega
        ];
    }

    // ── Área 6b: Eficacia de despacho ────────────────────────────────────
    public function getDespacho(string $desde, string $hasta, ?string $canal, ?string $cliente, ?string $tipo = null): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_DESPACHO ?,?,?,?,?',
            [$desde, $hasta, $canal, $cliente, $tipo]
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
    public function getPedidosConsolidados(string $desde, string $hasta, ?string $canal, ?string $tipo = null): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_PEDIDOS_CONSOLIDADOS ?,?,?,?',
            [$desde, $hasta, $canal, $tipo]
        );
        return [
            'kpis'     => $sets[0][0] ?? [],
            'evolucion'=> $sets[1] ?? [],
            'tabla'    => $sets[2] ?? [],
            'canales'  => array_column($sets[3] ?? [], 'CANAL'),
        ];
    }

    // ── Área 9: Pedidos estancados (situación actual) ────────────────────
    public function getPedidosEstancados(int $dias, ?string $canal, ?string $tipo = null): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_PEDIDOS_ESTANCADOS ?,?,?', [$dias, $canal, $tipo]);
        // El caché de execSP guarda con JSON_NUMERIC_CHECK y le quita los ceros
        // a la izquierda a NRO_PEDIDO: se restituye el formato de 13 dígitos.
        $pedidos = array_map(function ($r) {
            $r['NRO_PEDIDO'] = str_pad(trim((string)$r['NRO_PEDIDO']), 13, '0', STR_PAD_LEFT);
            return $r;
        }, $sets[4] ?? []);
        return [
            'kpis'     => $sets[0][0] ?? [],
            'cobertura' => $sets[1] ?? [],   // cobertura de stock del saldo
            'meses'    => $sets[2] ?? [],   // ¿desde cuándo se acumula?
            'clientes' => $sets[3] ?? [],
            'pedidos'  => $pedidos,
        ];
    }

    // ── Área 10: Fill Rate por remito (un día) ───────────────────────────
    public function getFillRate(?string $fecha, ?string $canal, ?string $tipo): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_FILL_RATE ?,?,?', [$fecha, $canal, $tipo]);
        // Mismo motivo que en getPedidosEstancados: el caché le quita los ceros.
        $detalle = array_map(function ($r) {
            $nro = trim((string)($r['NRO_PEDIDO'] ?? ''));
            $r['NRO_PEDIDO'] = $nro === '' ? null : str_pad($nro, 13, '0', STR_PAD_LEFT);
            return $r;
        }, $sets[2] ?? []);
        return [
            'kpis'     => $sets[0][0] ?? [],
            'apertura' => $sets[1] ?? [],
            'detalle'  => $detalle,
        ];
    }

    // ── Pedidos cargados en un día (pestaña Pedidos) ─────────────────────
    public function getPedidosIngreso(?string $fecha, ?string $canal, ?string $tipo): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_PEDIDOS_INGRESO ?,?,?', [$fecha, $canal, $tipo]);
        return [
            'ingreso'        => $sets[0][0] ?? [],
            'ingreso_canal'  => $sets[1] ?? [],
            'ingreso_plazos' => $sets[2] ?? [],      // ¿cuándo hay que entregarlo?
            'ingreso_riesgo' => array_map(function ($r) {   // pedidos a atender primero
                // El caché le quita los ceros a la izquierda: se restituyen.
                $r['NRO_PEDIDO'] = str_pad(trim((string)$r['NRO_PEDIDO']), 13, '0', STR_PAD_LEFT);
                return $r;
            }, $sets[3] ?? []),
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

    public function getDepositosStock(): array
    {
        $rows = $this->query(
            "SELECT DISTINCT DEPOSITO FROM dbo.BI_STOCK_WMS_TANGO
             WHERE DEPOSITO IS NOT NULL AND LTRIM(RTRIM(DEPOSITO)) <> ''
             ORDER BY DEPOSITO"
        );
        // Mostrar el número de depósito con cero a la izquierda (01, 02, …)
        // cuando es numérico de un solo dígito. El valor padded igual matchea
        // en el SP: contra columna int, '01' se convierte a 1.
        return array_map(static function ($r) {
            $d = trim((string)($r['DEPOSITO'] ?? ''));
            return ctype_digit($d) ? str_pad($d, 2, '0', STR_PAD_LEFT) : $d;
        }, $rows);
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

    // ── Área 8: Evolución Tipo de Remisión ──────────────────────────────
    public function getEvolucionRemision(string $desde, string $hasta, ?string $rubro = null): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_EVOLUCION_REMISION ?,?,?',
            [$desde, $hasta, $rubro]
        );
        return [
            'evolucion' => $sets[0] ?? [],
            'kpis'      => $sets[1][0] ?? [],
            'detalle'   => $sets[2] ?? [],
        ];
    }
}
