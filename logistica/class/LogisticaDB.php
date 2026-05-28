<?php
class LogisticaDB
{
    private $conn;
    private int $cacheTtl = 300;

    public function __construct()
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Conexion.php';
        $cid = new Conexion();
        $this->conn = $cid->conectar('power');
        if (!$this->conn) throw new RuntimeException('No se pudo conectar a POWER_BI_CONTROL.');
    }

    // sqlsrv devuelve DATE/DATETIME como objetos PHP DateTime.
    // json_encode los serializa como {"date":"...","timezone_type":3,...},
    // que en JS resulta en [object Object]. Se convierten a 'Y-m-d' aquí.
    private function normalizeRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTime) {
                $row[$k] = $v->format('Y-m-d');
            }
        }
        return $row;
    }

    private function query(string $sql, array $params = []): array
    {
        $cacheKey = $this->cacheKey($sql, $params);
        if (empty($_GET['_nocache'])) {
            $cached = $this->cacheGet($cacheKey);
            if ($cached !== null) return $cached;
        }

        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            throw new RuntimeException('SQL error: ' . ($err[0]['message'] ?? 'unknown'));
        }
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $rows[] = $this->normalizeRow($row); }
        sqlsrv_free_stmt($stmt);
        $this->cacheSet($cacheKey, $rows);
        return $rows;
    }

    private function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }

    // Ejecuta un SP y devuelve todos los result sets como array indexado
    private function execSP(string $spCall, array $params = []): array
    {
        $cacheKey = $this->cacheKey($spCall, $params);
        if (empty($_GET['_nocache'])) {
            $cached = $this->cacheGet($cacheKey);
            if ($cached !== null) return $cached;
        }

        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $stmt = sqlsrv_query($this->conn, $spCall, $params);
        if ($stmt === false) {
            $err = sqlsrv_errors();
            throw new RuntimeException('SP error: ' . ($err[0]['message'] ?? 'unknown'));
        }
        $sets = [];
        do {
            $rows = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $rows[] = $this->normalizeRow($row); }
            $sets[] = $rows;
        } while (sqlsrv_next_result($stmt));
        sqlsrv_free_stmt($stmt);
        $this->cacheSet($cacheKey, $sets);
        return $sets;
    }

    private function cacheKey(string $spCall, array $params): string
    {
        return sha1($spCall . '|' . json_encode($params, JSON_UNESCAPED_UNICODE));
    }

    private function cachePath(string $key): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bi_logistica_' . $key . '.json';
    }

    private function cacheGet(string $key): ?array
    {
        $path = $this->cachePath($key);
        if (!is_file($path)) return null;
        if (time() - filemtime($path) > $this->cacheTtl) return null;
        $json = @file_get_contents($path);
        if ($json === false || $json === '') return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function cacheSet(string $key, array $data): void
    {
        @file_put_contents($this->cachePath($key), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK), LOCK_EX);
    }

    // ── Área 1: Eficiencia logística ─────────────────────────────────────
    public function getEficiencia(string $desde, string $hasta, ?string $canal): array
    {
        $sets = $this->execSP(
            'EXEC dbo.RO_SP_EFICIENCIA_LOGISTICA ?,?,?',
            [$desde, $hasta, $canal]
        );
        $kpi = $sets[0][0] ?? [];
        // Calcular ratios derivados en PHP
        $pedidas   = (float)($kpi['UNID_PEDIDAS']   ?? 0);
        $facturadas= (float)($kpi['UNID_FACTURADAS'] ?? 0);
        $importePed= (float)($kpi['IMPORTE_PEDIDO_FILTRADO'] ?? 0);
        $perdida   = (float)($kpi['PERDIDA_FACT']    ?? 0);
        $kpi['EFI_UNIDADES']   = $pedidas  > 0 ? $facturadas / $pedidas : null;
        $kpi['PCT_PERDIDA']    = $importePed > 0 ? $perdida / $importePed : null;
        $pedidasAA = (float)($kpi['UNID_PEDIDAS_AA']    ?? 0);
        $factAA    = (float)($kpi['UNID_FACTURADAS_AA'] ?? 0);
        $kpi['EFI_UNIDADES_AA']= $pedidasAA > 0 ? $factAA / $pedidasAA : null;
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
        $kpi = $sets[0][0] ?? [];
        $total   = (int)($kpi['COMP_FACTURADOS'] ?? 0);
        $demorados= (int)($kpi['COMP_DEMORADOS']  ?? 0);
        $kpi['PCT_DEMORADOS'] = $total > 0 ? $demorados / $total : null;
        return [
            'kpis'      => $kpi,
            'histograma'=> $sets[1] ?? [],
            'evolucion' => $sets[2] ?? [],
        ];
    }

    // ── Área 3: Stock WMS vs Tango ───────────────────────────────────────
    public function getStock(?string $rubro): array
    {
        $sets = $this->execSP('EXEC dbo.RO_SP_STOCK_WMS_TANGO ?', [$rubro]);
        return [
            'kpis'  => $sets[0][0] ?? [],
            'rubros'=> $sets[1] ?? [],
            'lista_rubros' => array_column($sets[2] ?? [], 'RUBRO'),
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
            'kpis'    => $sets[0][0] ?? [],
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
        // Calcular PCT_CUMPLIMIENTO en PHP
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
