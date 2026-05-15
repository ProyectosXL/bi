<?php
class MayoristaDB
{
    private $conn;

    public function __construct()
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';
        $cid = new Conexion();
        $this->conn = $cid->conectar('power');
        if (!$this->conn) throw new RuntimeException('No se pudo conectar.');
    }

    private function query(string $sql, array $params = []): array
    {
        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) { $err = sqlsrv_errors(); throw new RuntimeException('SQL error: ' . ($err[0]['message'] ?? 'unknown')); }
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) { $rows[] = $row; }
        sqlsrv_free_stmt($stmt);
        return $rows;
    }

    private function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }

    private function buildFilter(string $alias, ?string $vendedor=null, ?string $cliente=null, ?string $rubro=null, ?string $categoria=null, ?string $region=null, ?string $provincia=null): array
    {
        $clauses = []; $params = [];
        if (!empty($vendedor))  { $clauses[] = "{$alias}.VENDEDOR = ?";  $params[] = $vendedor; }
        if (!empty($cliente))   { $clauses[] = "{$alias}.CLIENTE = ?";   $params[] = $cliente; }
        if (!empty($rubro))     { $clauses[] = "{$alias}.RUBRO = ?";     $params[] = $rubro; }
        if (!empty($categoria)) { $clauses[] = "{$alias}.CATEGORIA = ?"; $params[] = $categoria; }
        if (!empty($region))    { $clauses[] = "{$alias}.REGION = ?";    $params[] = $region; }
        if (!empty($provincia)) { $clauses[] = "{$alias}.PROVINCIA = ?"; $params[] = $provincia; }
        $sql = $clauses ? 'AND ' . implode(' AND ', $clauses) : '';
        return [$sql, $params];
    }

    private function variacion(float $actual, float $previo): ?float
    {
        if ($previo == 0) return null;
        return ($actual - $previo) / abs($previo);
    }

    public function getKPIs(string $desde, string $hasta, string $desdePrev, string $hastaPrev, ?string $vendedor=null, ?string $cliente=null, ?string $rubro=null, ?string $categoria=null, ?string $region=null, ?string $provincia=null): array
    {
        [$sf, $p] = $this->buildFilter('m', $vendedor, $cliente, $rubro, $categoria, $region, $provincia);
        $fin = date('Y-m-d', strtotime($hasta . ' +1 day'));
        $finPrev = date('Y-m-d', strtotime($hastaPrev . ' +1 day'));
        $row = $this->queryOne("SELECT ISNULL(SUM(CASE WHEN m.FECHA >= ? AND m.FECHA < ? THEN m.CANT_FACTURADA ELSE 0 END),0) AS unidades_act, ISNULL(SUM(CASE WHEN m.FECHA >= ? AND m.FECHA < ? THEN m.CANT_FACTURADA ELSE 0 END),0) AS unidades_prev, COUNT(DISTINCT CASE WHEN m.FECHA >= ? AND m.FECHA < ? THEN m.CLIENTE END) AS clientes_act, COUNT(DISTINCT CASE WHEN m.FECHA >= ? AND m.FECHA < ? THEN m.CLIENTE END) AS clientes_prev, COUNT(DISTINCT CASE WHEN m.FECHA >= ? AND m.FECHA < ? THEN m.RUBRO END) AS rubros_act, COUNT(DISTINCT CASE WHEN m.FECHA >= ? AND m.FECHA < ? THEN m.RUBRO END) AS rubros_prev FROM BI_REPORTE_VENTAS_MAYORISTA m WHERE ((m.FECHA >= ? AND m.FECHA < ?) OR (m.FECHA >= ? AND m.FECHA < ?)) {$sf} OPTION (RECOMPILE)", array_merge([$desde,$fin,$desdePrev,$finPrev],[$desde,$fin,$desdePrev,$finPrev],[$desde,$fin,$desdePrev,$finPrev],[$desde,$fin,$desdePrev,$finPrev],$p));
        if (!$row) return ['unidades_act'=>0,'unidades_prev'=>0,'clientes_act'=>0,'clientes_prev'=>0,'rubros_act'=>0,'rubros_prev'=>0,'prom_act'=>0,'prom_prev'=>0,'var_unidades'=>null,'var_clientes'=>null,'var_prom'=>null,'var_rubros'=>null];
        $row['prom_act']  = ($row['clientes_act']  > 0) ? $row['unidades_act']  / $row['clientes_act']  : 0;
        $row['prom_prev'] = ($row['clientes_prev'] > 0) ? $row['unidades_prev'] / $row['clientes_prev'] : 0;
        $row['var_unidades'] = $this->variacion((float)$row['unidades_act'], (float)$row['unidades_prev']);
        $row['var_clientes'] = $this->variacion((float)$row['clientes_act'], (float)$row['clientes_prev']);
        $row['var_prom']     = $this->variacion($row['prom_act'], $row['prom_prev']);
        $row['var_rubros']   = $this->variacion((float)$row['rubros_act'], (float)$row['rubros_prev']);
        return $row;
    }

    public function getTablaClientes(string $desde, string $hasta, ?string $vendedor=null, ?string $cliente=null, ?string $rubro=null, ?string $categoria=null, ?string $region=null, ?string $provincia=null): array
    {
        [$sf, $p] = $this->buildFilter('m', $vendedor, $cliente, $rubro, $categoria, $region, $provincia);
        $fin = date('Y-m-d', strtotime($hasta . ' +1 day'));
        return $this->query("SELECT TOP 500 m.CLIENTE, m.VENDEDOR, m.REGION, m.PROVINCIA, ISNULL(SUM(m.CANT_FACTURADA),0) AS unidades, COUNT(DISTINCT m.RUBRO) AS rubros_distintos, COUNT(DISTINCT m.FECHA) AS dias_activo, CAST(100.0*SUM(m.CANT_FACTURADA)/NULLIF(SUM(SUM(m.CANT_FACTURADA)) OVER (),0) AS DECIMAL(8,2)) AS porc_part FROM BI_REPORTE_VENTAS_MAYORISTA m WHERE m.FECHA >= ? AND m.FECHA < ? {$sf} GROUP BY m.CLIENTE, m.VENDEDOR, m.REGION, m.PROVINCIA ORDER BY unidades DESC OPTION (RECOMPILE)", array_merge([$desde,$fin],$p));
    }

    public function getComparativa(string $desde, string $hasta, string $desdePrev, string $hastaPrev, ?string $vendedor=null, ?string $cliente=null, ?string $rubro=null, ?string $categoria=null, ?string $region=null, ?string $provincia=null): array
    {
        [$sf, $p] = $this->buildFilter('m', $vendedor, $cliente, $rubro, $categoria, $region, $provincia);
        $fin = date('Y-m-d', strtotime($hasta . ' +1 day'));
        $finPrev = date('Y-m-d', strtotime($hastaPrev . ' +1 day'));
        $rows = $this->query("SELECT m.CLIENTE, m.VENDEDOR, ISNULL(SUM(CASE WHEN m.FECHA>=? AND m.FECHA<? THEN m.CANT_FACTURADA ELSE 0 END),0) AS unidades_act, ISNULL(SUM(CASE WHEN m.FECHA>=? AND m.FECHA<? THEN m.CANT_FACTURADA ELSE 0 END),0) AS unidades_prev FROM BI_REPORTE_VENTAS_MAYORISTA m WHERE ((m.FECHA>=? AND m.FECHA<?) OR (m.FECHA>=? AND m.FECHA<?)) {$sf} GROUP BY m.CLIENTE,m.VENDEDOR HAVING SUM(CASE WHEN m.FECHA>=? AND m.FECHA<? THEN m.CANT_FACTURADA ELSE 0 END)>0 OR SUM(CASE WHEN m.FECHA>=? AND m.FECHA<? THEN m.CANT_FACTURADA ELSE 0 END)>0 ORDER BY unidades_act DESC OPTION (RECOMPILE)", array_merge([$desde,$fin,$desdePrev,$finPrev],[$desde,$fin,$desdePrev,$finPrev],$p,[$desde,$fin,$desdePrev,$finPrev]));
        foreach ($rows as &$row) { $a=(float)$row['unidades_act']; $b=(float)$row['unidades_prev']; $row['var_unidades']=($b!=0)?($a-$b)/abs($b):null; }
        unset($row);
        return $rows;
    }

    public function getEvolucion(?string $vendedor=null, ?string $cliente=null, ?string $rubro=null, ?string $categoria=null, ?string $region=null, ?string $provincia=null, int $anios=3): array
    {
        [$sf, $p] = $this->buildFilter('m', $vendedor, $cliente, $rubro, $categoria, $region, $provincia);
        $maxAnio = $this->queryOne("SELECT MAX(YEAR(FECHA)) AS max_anio FROM BI_REPORTE_VENTAS_MAYORISTA");
        $anioMax = (int)($maxAnio['max_anio'] ?? date('Y'));
        $anioMin = $anioMax - $anios + 1;
        return $this->query("SELECT YEAR(m.FECHA) AS anio, MONTH(m.FECHA) AS mes, ISNULL(SUM(m.CANT_FACTURADA),0) AS unidades FROM BI_REPORTE_VENTAS_MAYORISTA m WHERE m.FECHA >= CAST(CAST(? AS VARCHAR(4))+'-01-01' AS DATE) AND m.FECHA < CAST(CAST(? AS VARCHAR(4))+'-12-31 23:59:59' AS DATETIME) {$sf} GROUP BY YEAR(m.FECHA),MONTH(m.FECHA) ORDER BY anio,mes OPTION (RECOMPILE)", array_merge([$anioMin,$anioMax],$p));
    }

    public function getParticipacionRubro(string $desde, string $hasta, ?string $vendedor=null, ?string $cliente=null, ?string $categoria=null, ?string $region=null, ?string $provincia=null): array
    {
        [$sf, $p] = $this->buildFilter('m', $vendedor, $cliente, null, $categoria, $region, $provincia);
        $fin = date('Y-m-d', strtotime($hasta . ' +1 day'));
        return $this->query("SELECT m.RUBRO, ISNULL(SUM(m.CANT_FACTURADA),0) AS unidades, CAST(100.0*SUM(m.CANT_FACTURADA)/NULLIF(SUM(SUM(m.CANT_FACTURADA)) OVER(),0) AS DECIMAL(8,2)) AS porc_unidades FROM BI_REPORTE_VENTAS_MAYORISTA m WHERE m.FECHA>=? AND m.FECHA<? {$sf} GROUP BY m.RUBRO ORDER BY unidades DESC OPTION (RECOMPILE)", array_merge([$desde,$fin],$p));
    }

    public function getComparativaRubro(string $desde, string $hasta, string $desdePrev, string $hastaPrev, ?string $vendedor=null, ?string $cliente=null, ?string $rubro=null, ?string $categoria=null, ?string $region=null, ?string $provincia=null): array
    {
        [$sf, $p] = $this->buildFilter('m', $vendedor, $cliente, $rubro, $categoria, $region, $provincia);
        $fin=$date=date('Y-m-d',strtotime($hasta.' +1 day')); $finPrev=date('Y-m-d',strtotime($hastaPrev.' +1 day'));
        $desde2=date('Y-m-d',strtotime($desde.' -2 years')); $fin2=date('Y-m-d',strtotime($fin.' -2 years'));
        $rows=$this->query("SELECT m.RUBRO, ISNULL(SUM(CASE WHEN m.FECHA>=? AND m.FECHA<? THEN m.CANT_FACTURADA ELSE 0 END),0) AS unidades_act, ISNULL(SUM(CASE WHEN m.FECHA>=? AND m.FECHA<? THEN m.CANT_FACTURADA ELSE 0 END),0) AS unidades_prev, ISNULL(SUM(CASE WHEN m.FECHA>=? AND m.FECHA<? THEN m.CANT_FACTURADA ELSE 0 END),0) AS unidades_prev2 FROM BI_REPORTE_VENTAS_MAYORISTA m WHERE ((m.FECHA>=? AND m.FECHA<?) OR (m.FECHA>=? AND m.FECHA<?) OR (m.FECHA>=? AND m.FECHA<?)) {$sf} GROUP BY m.RUBRO HAVING SUM(m.CANT_FACTURADA)>0 ORDER BY unidades_act DESC OPTION (RECOMPILE)",array_merge([$desde,$fin,$desdePrev,$finPrev,$desde2,$fin2],[$desde,$fin,$desdePrev,$finPrev,$desde2,$fin2],$p));
        foreach($rows as &$row){$a=(float)$row['unidades_act'];$p1=(float)$row['unidades_prev'];$p2=(float)$row['unidades_prev2'];$row['var_vs_prev']=($p1!=0)?($a-$p1)/abs($p1):null;$row['var_vs_prev2']=($p2!=0)?($a-$p2)/abs($p2):null;}
        unset($row); return $rows;
    }

    public function getEvolucionPorRubro(int $anio, ?string $vendedor=null, ?string $cliente=null, ?string $rubro=null, ?string $categoria=null, ?string $region=null, ?string $provincia=null): array
    {
        [$sf, $p] = $this->buildFilter('m', $vendedor, $cliente, $rubro, $categoria, $region, $provincia);
        $inicio=$anio.'-01-01'; $fin=($anio+1).'-01-01';
        return $this->query("SELECT m.RUBRO, MONTH(m.FECHA) AS mes, ISNULL(SUM(m.CANT_FACTURADA),0) AS unidades FROM BI_REPORTE_VENTAS_MAYORISTA m WHERE m.FECHA>=? AND m.FECHA<? {$sf} GROUP BY m.RUBRO,MONTH(m.FECHA) ORDER BY m.RUBRO,mes OPTION (RECOMPILE)",array_merge([$inicio,$fin],$p));
    }

    public function getMatrizClienteRubro(string $desde, string $hasta, ?string $vendedor=null, ?string $categoria=null, ?string $region=null, ?string $provincia=null, int $topClientes=20): array
    {
        [$sf, $p] = $this->buildFilter('m', $vendedor, null, null, $categoria, $region, $provincia);
        $fin=date('Y-m-d',strtotime($hasta.' +1 day'));
        $topRows=$this->query("SELECT TOP {$topClientes} m.CLIENTE, ISNULL(SUM(m.CANT_FACTURADA),0) AS total FROM BI_REPORTE_VENTAS_MAYORISTA m WHERE m.FECHA>=? AND m.FECHA<? {$sf} GROUP BY m.CLIENTE ORDER BY total DESC OPTION (RECOMPILE)",array_merge([$desde,$fin],$p));
        if(!$topRows) return ['clientes'=>[],'totales_cliente'=>[],'filas'=>[]];
        $clientes=array_column($topRows,'CLIENTE'); $totalesPorCliente=array_column($topRows,'total','CLIENTE'); $ph=implode(',',array_fill(0,count($clientes),'?'));
        $rows=$this->query("SELECT m.RUBRO, m.CLIENTE, ISNULL(SUM(m.CANT_FACTURADA),0) AS unidades, CAST(100.0*SUM(m.CANT_FACTURADA)/NULLIF(SUM(SUM(m.CANT_FACTURADA)) OVER(PARTITION BY m.CLIENTE),0) AS DECIMAL(8,2)) AS porc_en_cliente FROM BI_REPORTE_VENTAS_MAYORISTA m WHERE m.FECHA>=? AND m.FECHA<? {$sf} AND m.CLIENTE IN ({$ph}) GROUP BY m.RUBRO,m.CLIENTE OPTION (RECOMPILE)",array_merge([$desde,$fin],$p,$clientes));
        $rubroMap=[]; $rubroTot=[];
        foreach($rows as $row){$r=$row['RUBRO'];$c=$row['CLIENTE'];if(!isset($rubroMap[$r])){$rubroMap[$r]=[];$rubroTot[$r]=0;}$rubroMap[$r][$c]=['unidades'=>(float)$row['unidades'],'porc'=>(float)$row['porc_en_cliente']];$rubroTot[$r]+=(float)$row['unidades'];}
        arsort($rubroTot); $filas=[];
        foreach($rubroTot as $r=>$tot){$filas[]=['rubro'=>$r,'total'=>$tot,'clientes'=>$rubroMap[$r]];}
        return ['clientes'=>$clientes,'totales_cliente'=>$totalesPorCliente,'filas'=>$filas];
    }

    public function getVendedores(): array { return $this->query("SELECT DISTINCT VENDEDOR FROM BI_REPORTE_VENTAS_MAYORISTA WHERE VENDEDOR IS NOT NULL AND LTRIM(RTRIM(VENDEDOR)) <> '' ORDER BY VENDEDOR"); }
    public function getRubros(): array { return $this->query("SELECT DISTINCT RUBRO FROM BI_REPORTE_VENTAS_MAYORISTA WHERE RUBRO IS NOT NULL AND LTRIM(RTRIM(RUBRO)) <> '' ORDER BY RUBRO"); }
    public function getCategorias(): array { return $this->query("SELECT DISTINCT CATEGORIA FROM BI_REPORTE_VENTAS_MAYORISTA WHERE CATEGORIA IS NOT NULL AND LTRIM(RTRIM(CATEGORIA)) <> '' ORDER BY CATEGORIA"); }
    public function getRegiones(): array { return $this->query("SELECT DISTINCT REGION FROM BI_REPORTE_VENTAS_MAYORISTA WHERE REGION IS NOT NULL AND LTRIM(RTRIM(REGION)) <> '' ORDER BY REGION"); }

    public function getProvincias(?string $region=null): array
    {
        $sf=''; $p=[];
        if(!empty($region)){$sf='AND REGION=?';$p=[$region];}
        return $this->query("SELECT DISTINCT PROVINCIA FROM BI_REPORTE_VENTAS_MAYORISTA WHERE PROVINCIA IS NOT NULL AND LTRIM(RTRIM(PROVINCIA)) <> '' {$sf} ORDER BY PROVINCIA",$p);
    }

    public function getClientes(): array { return $this->query("SELECT DISTINCT CLIENTE FROM BI_REPORTE_VENTAS_MAYORISTA WHERE CLIENTE IS NOT NULL AND LTRIM(RTRIM(CLIENTE)) <> '' ORDER BY CLIENTE"); }

    public function getCategoriasPorRubro(): array { return $this->query("SELECT DISTINCT RUBRO, CATEGORIA FROM BI_REPORTE_VENTAS_MAYORISTA WHERE RUBRO IS NOT NULL AND LTRIM(RTRIM(RUBRO)) <> '' AND CATEGORIA IS NOT NULL AND LTRIM(RTRIM(CATEGORIA)) <> '' ORDER BY RUBRO, CATEGORIA"); }

    public function getTablaVendedores(string $desde, string $hasta, ?string $vendedor=null, ?string $cliente=null, ?string $rubro=null, ?string $categoria=null, ?string $region=null, ?string $provincia=null): array
    {
        [$sf, $p] = $this->buildFilter('m', $vendedor, $cliente, $rubro, $categoria, $region, $provincia);
        $fin = date('Y-m-d', strtotime($hasta . ' +1 day'));
        return $this->query("SELECT m.VENDEDOR, ISNULL(SUM(m.CANT_FACTURADA),0) AS unidades, COUNT(DISTINCT m.CLIENTE) AS clientes, COUNT(DISTINCT m.RUBRO) AS rubros_distintos, COUNT(DISTINCT m.FECHA) AS dias_activo, CAST(100.0*SUM(m.CANT_FACTURADA)/NULLIF(SUM(SUM(m.CANT_FACTURADA)) OVER(),0) AS DECIMAL(8,2)) AS porc_part FROM BI_REPORTE_VENTAS_MAYORISTA m WHERE m.FECHA >= ? AND m.FECHA < ? {$sf} GROUP BY m.VENDEDOR ORDER BY unidades DESC OPTION (RECOMPILE)", array_merge([$desde, $fin], $p));
    }
}