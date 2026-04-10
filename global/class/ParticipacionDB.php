<?php
/**
 * ParticipacionDB
 * Tabla pivot de participación de rubros por sucursal.
 */
class ParticipacionDB
{
    private $conn;
    private string $origen;

    public function __construct(string $origen = 'argentina')
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Filters.php';

        $cfg = getConfigForOrigen($origen);
        $this->origen = $origen;

        $cid        = new Conexion();
        $this->conn = $cid->conectar($cfg['db']);
    }

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

    private function grupoFiltro(string $alias): array
    {
        if (($_SESSION['tipo'] ?? '') !== 'GRUPO') return ['', []];
        $suc = $_SESSION['sucursalesGrupo'] ?? [];
        return Filters::sucursalesGrupo($suc, $alias);
    }

    /**
     * Devuelve pivot: top rubros × sucursal.
     * Retorna estructura:
     * {
     *   rubros: ['RUBRO_A', 'RUBRO_B', ...],
     *   sucursales: [
     *     {
     *       nro_sucurs, desc_sucursal, grupo,
     *       rubros: { 'RUBRO_A': { porc_unidades, porc_facturacion }, ... },
     *       total_unidades, total_facturacion
     *     }, ...
     *   ]
     * }
     */
    public function getPivot(
        string $desde, string $hasta,
        int $topRubros = 15,
        ?string $grupo = null, ?string $tipoTienda = null
    ): array {
        $sfG = '';
        $pG  = [];
        if ($grupo !== null && $this->origen === 'argentina') {
            $sfG  = "AND s.NRO_SUCURS IN (SELECT g.NRO_SUCURS FROM BI_DIM_SUCURSALES_GRUPO g WHERE g.GRUPO = ?)";
            $pG[] = $grupo;
        }
        if ($tipoTienda !== null && $this->origen === 'argentina') {
            $sfG .= " AND s.NRO_SUCURS IN (SELECT sl.NRO_SUCURSAL FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WHERE sl.TIPO_TIENDA = ?)";
            $pG[] = $tipoTienda;
        }
        [$sfGS, $pGS] = $this->grupoFiltro('s');
        $sfG .= ' ' . $sfGS;
        $pG   = array_merge($pG, $pGS);

        // Top rubros por facturación global del período
        $topRows = $this->query("
            SELECT TOP {$topRubros}
                s.RUBRO,
                ISNULL(SUM(s.IMPORTE), 0) AS total_fact
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') {$sfG}
            GROUP BY s.RUBRO
            ORDER BY total_fact DESC
        ", array_merge([$desde, $hasta], $pG));

        $rubrosTop = array_column($topRows, 'RUBRO');
        if (empty($rubrosTop)) {
            return ['rubros' => [], 'sucursales' => []];
        }

        // Datos por sucursal × rubro
        $inRubros = implode(',', array_fill(0, count($rubrosTop), '?'));
        $rowsDetalle = $this->query("
            SELECT
                s.NRO_SUCURS,
                s.RUBRO,
                ISNULL(SUM(s.CANTIDAD), 0) AS unidades,
                ISNULL(SUM(s.IMPORTE), 0)  AS facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND s.RUBRO IN ({$inRubros})
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') {$sfG}
            GROUP BY s.NRO_SUCURS, s.RUBRO
        ", array_merge([$desde, $hasta], $rubrosTop, $pG));

        // Totales por sucursal (para calcular %)
        $rowsTotales = $this->query("
            SELECT
                s.NRO_SUCURS,
                ISNULL(SUM(s.CANTIDAD), 0) AS total_unidades,
                ISNULL(SUM(s.IMPORTE), 0)  AS total_facturacion
            FROM BI_SALES_SUCURSALES s
            WHERE s.FECHA >= ? AND s.FECHA < DATEADD(day,1,CAST(? AS DATE))
              AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING') {$sfG}
            GROUP BY s.NRO_SUCURS
        ", array_merge([$desde, $hasta], $pG));

        // Descripción de sucursales
        $rowsDesc = $this->query("
            SELECT sl.NRO_SUCURSAL, sl.DESC_SUCURSAL
            FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
        ");
        $descMap = [];
        foreach ($rowsDesc as $r) {
            $descMap[(int)$r['NRO_SUCURSAL']] = $r['DESC_SUCURSAL'];
        }

        // Grupo por sucursal (solo Argentina)
        $grupoMap = [];
        if ($this->origen === 'argentina') {
            $grupoRows = $this->query("
                SELECT NRO_SUCURS, GRUPO FROM BI_DIM_SUCURSALES_GRUPO
            ");
            foreach ($grupoRows as $r) {
                $grupoMap[(int)$r['NRO_SUCURS']] = $r['GRUPO'];
            }
        }

        // Indexar totales
        $totalMap = [];
        foreach ($rowsTotales as $r) {
            $totalMap[(int)$r['NRO_SUCURS']] = [
                'unidades'    => (float)$r['total_unidades'],
                'facturacion' => (float)$r['total_facturacion'],
            ];
        }

        // Agrupar detalles por sucursal
        $sucData = [];
        foreach ($rowsDetalle as $r) {
            $nro = (int)$r['NRO_SUCURS'];
            if (!isset($sucData[$nro])) {
                $sucData[$nro] = [];
            }
            $sucData[$nro][$r['RUBRO']] = [
                'unidades'    => (float)$r['unidades'],
                'facturacion' => (float)$r['facturacion'],
            ];
        }

        // Construir resultado por sucursal
        $sucursales = [];
        foreach ($totalMap as $nro => $totales) {
            $rubrosData = [];
            foreach ($rubrosTop as $rubro) {
                $det  = $sucData[$nro][$rubro] ?? ['unidades' => 0, 'facturacion' => 0];
                $rubrosData[$rubro] = [
                    'unidades'         => $det['unidades'],
                    'facturacion'      => $det['facturacion'],
                    'porc_unidades'    => $totales['unidades']    > 0 ? $det['unidades']    / $totales['unidades']    : 0,
                    'porc_facturacion' => $totales['facturacion'] > 0 ? $det['facturacion'] / $totales['facturacion'] : 0,
                ];
            }

            $sucursales[] = [
                'nro_sucurs'        => $nro,
                'desc_sucursal'     => $descMap[$nro] ?? "Suc {$nro}",
                'grupo'             => $grupoMap[$nro] ?? null,
                'rubros'            => $rubrosData,
                'total_unidades'    => $totales['unidades'],
                'total_facturacion' => $totales['facturacion'],
            ];
        }

        // Ordenar por facturación total desc
        usort($sucursales, fn($a, $b) => $b['total_facturacion'] <=> $a['total_facturacion']);

        // Agrupar por grupo si aplica
        if ($this->origen === 'argentina' && !empty($grupoMap)) {
            $grouped = [];
            foreach ($sucursales as $suc) {
                $g = $suc['grupo'] ?? 'Sin Grupo';
                $grouped[$g][] = $suc;
            }
            ksort($grouped);
            $final = [];
            foreach ($grouped as $g => $sucs) {
                $final[] = ['tipo' => 'grupo', 'nombre' => $g];
                foreach ($sucs as $suc) {
                    $final[] = array_merge($suc, ['tipo' => 'sucursal']);
                }
            }
            $sucursales = $final;
        } else {
            $sucursales = array_map(fn($s) => array_merge($s, ['tipo' => 'sucursal']), $sucursales);
        }

        return [
            'rubros'     => $rubrosTop,
            'sucursales' => $sucursales,
        ];
    }
}
