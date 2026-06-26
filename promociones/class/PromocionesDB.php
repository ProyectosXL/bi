<?php
/**
 * PromocionesDB
 * Acceso a datos para el dashboard de Promociones.
 * Fuente: tabla BI_PROMOCIONES (mismas columnas en Argentina y Franquicias).
 * Soporta multi-origen vía getConfigForOrigen($origen).
 */
class PromocionesDB
{
    private $conn;
    private string $origen;

    public function __construct(string $origen = 'argentina')
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/Filters.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';

        $cfg         = getConfigForOrigen($origen);
        $this->origen = $origen;

        $cid        = new Conexion();
        $this->conn = $cid->conectar($cfg['db']);
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

    /**
     * Fragmento WHERE + params para los filtros de Banco, Sucursal y (opcionalmente) Promoción.
     *
     * $incluyePromo=true  → agrega DESC_PROMOCION_TARJETA = ? si $fp['promocion'] está seteado.
     *                        Usar para el denominador "c/promo" cuando el filtro recorta el numerador.
     * $incluyePromo=false → solo Banco y Sucursal; la Promoción queda fuera del WHERE
     *                        (se aplica vía flag cp en CASE WHEN para que el denominador no se recorte).
     */
    private function buildFiltros(array $fp, string $alias, bool $incluyePromo): array
    {
        $sqls   = [];
        $params = [];

        if (!empty($fp['banco'])) {
            $sqls[]   = "AND {$alias}.BANCO = ?";
            $params[] = $fp['banco'];
        }
        if (!empty($fp['sucursal'])) {
            $sqls[]   = "AND {$alias}.NRO_SUCURSAL = ?";
            $params[] = (int)$fp['sucursal'];
        }
        // NOTA: si querés que el filtro Promoción también recorte el denominador
        // (haciendo que % $ Promo/FAC siempre sea 100%), mové esta cláusula fuera
        // del bloque condicional y eliminá el flag $incluyePromo de las llamadas.
        if ($incluyePromo && !empty($fp['promocion'])) {
            $sqls[]   = "AND {$alias}.DESC_PROMOCION_TARJETA = ?";
            $params[] = $fp['promocion'];
        }

        return [implode(' ', $sqls), $params];
    }

    /**
     * Restricción NRO_SUCURSAL IN (...) para perfil GRUPO.
     * Retorna ['', []] cuando no aplica.
     */
    private function grupoFiltro(string $alias): array
    {
        if (($_SESSION['tipo'] ?? '') !== 'GRUPO') return ['', []];
        $suc = $_SESSION['sucursalesGrupo'] ?? [];
        return Filters::sucursalesGrupo($suc, $alias, 'NRO_SUCURSAL');
    }

    /**
     * Expresión CASE WHEN para el flag "c/promo" (cp).
     * Sin filtro de promo: excluye filas 'SIN PROMO'.
     * Con filtro de promo: usa igualdad exacta (ya implica no-SIN PROMO porque
     * getPromocionesLista() solo devuelve promos que no son 'SIN PROMO').
     *
     * @return array{0:string, 1:array} [$cpExpr, $cpParams]
     */
    private function cpExpr(array $fp, string $alias): array
    {
        if (!empty($fp['promocion'])) {
            return ["CASE WHEN {$alias}.DESC_PROMOCION_TARJETA = ? THEN 1 ELSE 0 END", [$fp['promocion']]];
        }
        return ["CASE WHEN {$alias}.DESC_PROMOCION_TARJETA <> 'SIN PROMO' THEN 1 ELSE 0 END", []];
    }

    /* ──────────────────────────────────────────────
     *  PERÍODO — delega a PeriodHelper
     * ────────────────────────────────────────────── */

    public static function calcularPeriodo(string $tipo): array
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/PeriodHelper.php';
        return PeriodHelper::calcularPeriodo($tipo);
    }

    /* ──────────────────────────────────────────────
     *  KPIs BULK (un solo scan)
     * ────────────────────────────────────────────── */

    /**
     * Un único scan de BI_PROMOCIONES devuelve, para actual y previo:
     *   facturacion_total   — respeta Banco+Sucursal; ignora filtro de Promoción (denominador)
     *   facturacion_cpromo  — respeta todos los filtros + excluye SIN PROMO
     *   costo_promo         — ídem anterior sobre columna COSTO
     *   costo_promo_banc    — SUM(COSTO_PROMO_BANCARIA), sin filtro de promo (son 0 en SIN PROMO)
     *   costo_promo_ventas  — SUM(COSTO_PROMO_VENTAS), ídem
     *   tickets_cpromo      — COUNT DISTINCT N_COMP con T_COMP='FAC' c/promo
     */
    public function getKPIsBulk(
        string $da, string $ha,
        string $dp, string $hp,
        array  $fp
    ): array {
        $haX = (new DateTime($ha))->modify('+1 day')->format('Y-m-d');
        $hpX = (new DateTime($hp))->modify('+1 day')->format('Y-m-d');

        [$cpSql, $cpParams] = $this->cpExpr($fp, 's');
        [$sfF,   $pF]       = $this->buildFiltros($fp, 's', false); // solo Banco+Sucursal en WHERE
        [$sfG,   $pG]       = $this->grupoFiltro('s');

        $params = array_merge(
            $cpParams,                        // cp CASE expression
            [$da, $haX, $dp, $hpX],          // is_a, is_p flags
            [$da, $haX, $dp, $hpX],          // WHERE dates (actual OR previo)
            $pF,                              // Banco + Sucursal
            $pG                               // Grupo
        );

        // suc_cp_act/prev: 1 si la sucursal tiene al menos 1 fila c/promo en ese período.
        // fact_total solo suma facturación de sucursales con promo para no distorsionar los % /FAC.
        $row = $this->queryOne("
            SELECT
                ISNULL(SUM(CASE WHEN is_a=1 AND suc_cp_act=1  THEN ft  ELSE 0 END),0) AS fact_total_act,
                ISNULL(SUM(CASE WHEN is_a=1 AND cp=1           THEN ft  ELSE 0 END),0) AS fact_cpromo_act,
                ISNULL(SUM(CASE WHEN is_a=1 AND cp=1           THEN cto ELSE 0 END),0) AS costo_promo_act,
                ISNULL(SUM(CASE WHEN is_a=1 AND suc_cp_act=1   THEN cpb ELSE 0 END),0) AS costo_banc_act,
                ISNULL(SUM(CASE WHEN is_a=1 AND suc_cp_act=1   THEN cpv ELSE 0 END),0) AS costo_ventas_act,
                COUNT(DISTINCT CASE WHEN is_a=1 AND cp=1 AND tc=1 THEN nc END)         AS tickets_cpromo_act,
                ISNULL(SUM(CASE WHEN is_p=1 AND suc_cp_prev=1 THEN ft  ELSE 0 END),0)  AS fact_total_prev,
                ISNULL(SUM(CASE WHEN is_p=1 AND cp=1           THEN ft  ELSE 0 END),0) AS fact_cpromo_prev,
                ISNULL(SUM(CASE WHEN is_p=1 AND cp=1           THEN cto ELSE 0 END),0) AS costo_promo_prev,
                ISNULL(SUM(CASE WHEN is_p=1 AND suc_cp_prev=1  THEN cpb ELSE 0 END),0) AS costo_banc_prev,
                ISNULL(SUM(CASE WHEN is_p=1 AND suc_cp_prev=1  THEN cpv ELSE 0 END),0) AS costo_ventas_prev,
                COUNT(DISTINCT CASE WHEN is_p=1 AND cp=1 AND tc=1 THEN nc END)         AS tickets_cpromo_prev
            FROM (
                SELECT *,
                    MAX(cp * is_a) OVER (PARTITION BY nro_suc) AS suc_cp_act,
                    MAX(cp * is_p) OVER (PARTITION BY nro_suc) AS suc_cp_prev
                FROM (
                    SELECT
                        s.NRO_SUCURSAL            AS nro_suc,
                        s.IMPORTE_TO              AS ft,
                        ISNULL(s.COSTO, 0)        AS cto,
                        ISNULL(s.COSTO_PROMO_BANCARIA, 0) AS cpb,
                        ISNULL(s.COSTO_PROMO_VENTAS,   0) AS cpv,
                        s.N_COMP                  AS nc,
                        CASE WHEN s.T_COMP COLLATE Latin1_General_BIN = 'FAC' THEN 1 ELSE 0 END AS tc,
                        {$cpSql}                  AS cp,
                        CASE WHEN s.FECHA >= ? AND s.FECHA < ? THEN 1 ELSE 0 END AS is_a,
                        CASE WHEN s.FECHA >= ? AND s.FECHA < ? THEN 1 ELSE 0 END AS is_p
                    FROM BI_PROMOCIONES s WITH (NOLOCK)
                    WHERE (
                        (s.FECHA >= ? AND s.FECHA < ?)
                        OR (s.FECHA >= ? AND s.FECHA < ?)
                    ) {$sfF} {$sfG}
                ) inner_t
            ) t
        ", $params) ?? [];

        $mkPeriod = function(string $sfx) use ($row): array {
            return [
                'facturacion_total'   => (float)($row["fact_total_{$sfx}"]   ?? 0),
                'facturacion_cpromo'  => (float)($row["fact_cpromo_{$sfx}"]  ?? 0),
                'costo_promo'         => (float)($row["costo_promo_{$sfx}"]  ?? 0),
                'costo_promo_banc'    => (float)($row["costo_banc_{$sfx}"]   ?? 0),
                'costo_promo_ventas'  => (float)($row["costo_ventas_{$sfx}"] ?? 0),
                'tickets_cpromo'      => (int)($row["tickets_cpromo_{$sfx}"] ?? 0),
            ];
        };

        return ['actual' => $mkPeriod('act'), 'previo' => $mkPeriod('prev')];
    }

    /* ──────────────────────────────────────────────
     *  CARDS POR BANCO (TOP N)
     * ────────────────────────────────────────────── */

    public function getCardsBancos(
        string $da, string $ha,
        string $dp, string $hp,
        array  $fp,
        int    $topN = 6
    ): array {
        $haX = (new DateTime($ha))->modify('+1 day')->format('Y-m-d');
        $hpX = (new DateTime($hp))->modify('+1 day')->format('Y-m-d');

        [$cpSql, $cpParams] = $this->cpExpr($fp, 's');
        [$sfF,   $pF]       = $this->buildFiltros($fp, 's', false);
        [$sfG,   $pG]       = $this->grupoFiltro('s');

        $params = array_merge(
            $cpParams,
            [$da, $haX, $dp, $hpX],
            [$da, $haX, $dp, $hpX],
            $pF, $pG
        );

        $rows = $this->query("
            SELECT TOP {$topN}
                banco,
                ISNULL(SUM(CASE WHEN is_a=1 THEN ft  ELSE 0 END),0) AS fact_act,
                ISNULL(SUM(CASE WHEN is_p=1 THEN ft  ELSE 0 END),0) AS fact_prev,
                COUNT(DISTINCT CASE WHEN is_a=1 AND tc=1 THEN nc END) AS tick_act,
                COUNT(DISTINCT CASE WHEN is_p=1 AND tc=1 THEN nc END) AS tick_prev
            FROM (
                SELECT
                    s.BANCO                   AS banco,
                    s.IMPORTE_TO              AS ft,
                    s.N_COMP                  AS nc,
                    CASE WHEN s.T_COMP COLLATE Latin1_General_BIN = 'FAC' THEN 1 ELSE 0 END AS tc,
                    {$cpSql}                  AS cp,
                    CASE WHEN s.FECHA >= ? AND s.FECHA < ? THEN 1 ELSE 0 END AS is_a,
                    CASE WHEN s.FECHA >= ? AND s.FECHA < ? THEN 1 ELSE 0 END AS is_p
                FROM BI_PROMOCIONES s WITH (NOLOCK)
                WHERE (
                    (s.FECHA >= ? AND s.FECHA < ?)
                    OR (s.FECHA >= ? AND s.FECHA < ?)
                ) {$sfF} {$sfG}
                  AND s.DESC_PROMOCION_TARJETA <> 'SIN PROMO'
                  AND s.BANCO <> 'DESCONOCIDO'
            ) t
            WHERE cp = 1
            GROUP BY banco
            ORDER BY SUM(CASE WHEN is_a=1 THEN ft ELSE 0 END) DESC
        ", $params);

        $var = fn($a, $p) => $p != 0 ? ($a - $p) / $p : ($a > 0 ? 1 : 0);

        return array_map(function($r) use ($var): array {
            $fa = (float)$r['fact_act'];
            $fp = (float)$r['fact_prev'];
            $ta = (int)$r['tick_act'];
            $tp = (int)$r['tick_prev'];
            return [
                'banco'            => $r['banco'],
                'facturacion_act'  => $fa,
                'facturacion_prev' => $fp,
                'var_facturacion'  => $var($fa, $fp),
                'tickets_act'      => $ta,
                'tickets_prev'     => $tp,
                'var_tickets'      => $var($ta, $tp),
            ];
        }, $rows);
    }

    /* ──────────────────────────────────────────────
     *  SERIE ANUAL MENSUAL (tabla + gráfico evolución)
     *  Toda la historia; respeta Banco/Sucursal/Promoción.
     * ────────────────────────────────────────────── */

    public function getSerieAnualMensual(array $fp): array
    {
        [$cpSql, $cpParams] = $this->cpExpr($fp, 's');
        [$sfF,   $pF]       = $this->buildFiltros($fp, 's', false);
        [$sfG,   $pG]       = $this->grupoFiltro('s');

        $params = array_merge($cpParams, $pF, $pG);

        $rows = $this->query("
            SELECT
                YEAR(s.FECHA)  AS anio,
                MONTH(s.FECHA) AS mes,
                ISNULL(SUM(s.IMPORTE_TO),0)                                                            AS fact_total,
                ISNULL(SUM(CASE WHEN cp=1 THEN s.IMPORTE_TO ELSE 0 END),0)                            AS fact_cpromo,
                COUNT(DISTINCT CASE WHEN cp=1 AND s.T_COMP COLLATE Latin1_General_BIN = 'FAC'
                                    THEN s.N_COMP END)                                                 AS tickets_cpromo,
                ISNULL(SUM(CASE WHEN cp=1 THEN ISNULL(s.COSTO,0) ELSE 0 END),0)                      AS costo_total,
                ISNULL(SUM(ISNULL(s.COSTO_PROMO_BANCARIA,0)),0)                                       AS costo_banc,
                ISNULL(SUM(ISNULL(s.COSTO_PROMO_VENTAS,0)),0)                                         AS costo_ventas
            FROM (
                SELECT s.*,
                    {$cpSql} AS cp
                FROM BI_PROMOCIONES s WITH (NOLOCK)
                WHERE s.FECHA IS NOT NULL
                  {$sfF} {$sfG}
            ) s
            GROUP BY YEAR(s.FECHA), MONTH(s.FECHA)
            ORDER BY YEAR(s.FECHA), MONTH(s.FECHA)
        ", $params);

        $byAnioMes  = [];
        $aniosSet   = [];
        $totalesPer = [];

        foreach ($rows as $r) {
            $anio  = (int)$r['anio'];
            $mes   = (int)$r['mes'];
            $ft    = (float)$r['fact_total'];
            $fc    = (float)$r['fact_cpromo'];
            $tk    = (int)$r['tickets_cpromo'];
            $cto   = (float)$r['costo_total'];
            $cbanc = (float)$r['costo_banc'];
            $cvtas = (float)$r['costo_ventas'];

            $byAnioMes[$anio][$mes] = [
                'fac_total'            => $ft,
                'fac_cpromo'           => $fc,
                'tickets_cpromo'       => $tk,
                'costo_total'          => $cto,
                'costo_banc'           => $cbanc,
                'costo_ventas'         => $cvtas,
                'pct_costo_total'      => $ft > 0 ? $cto   / $ft : 0,
                'pct_promo_fac'        => $ft > 0 ? $fc    / $ft : 0,
                'pct_costo_banc_fac'   => $ft > 0 ? $cbanc / $ft : 0,
                'pct_costo_ventas_fac' => $ft > 0 ? $cvtas / $ft : 0,
            ];

            if (!isset($totalesPer[$anio])) {
                $totalesPer[$anio] = ['fac_total' => 0, 'fac_cpromo' => 0, 'tickets_cpromo' => 0,
                                      'costo_total' => 0, 'costo_banc' => 0, 'costo_ventas' => 0];
            }
            $totalesPer[$anio]['fac_total']      += $ft;
            $totalesPer[$anio]['fac_cpromo']     += $fc;
            $totalesPer[$anio]['tickets_cpromo'] += $tk;
            $totalesPer[$anio]['costo_total']    += $cto;
            $totalesPer[$anio]['costo_banc']     += $cbanc;
            $totalesPer[$anio]['costo_ventas']   += $cvtas;

            $aniosSet[$anio] = true;
        }

        $anios = array_keys($aniosSet);
        rsort($anios);

        $totales = [];
        foreach ($totalesPer as $anio => $t) {
            $ft = $t['fac_total'];
            $totales[$anio] = [
                'fac_total'            => $ft,
                'fac_cpromo'           => $t['fac_cpromo'],
                'tickets_cpromo'       => $t['tickets_cpromo'],
                'costo_total'          => $t['costo_total'],
                'costo_banc'           => $t['costo_banc'],
                'costo_ventas'         => $t['costo_ventas'],
                'pct_costo_total'      => $ft > 0 ? $t['costo_total']  / $ft : 0,
                'pct_promo_fac'        => $ft > 0 ? $t['fac_cpromo']   / $ft : 0,
                'pct_costo_banc_fac'   => $ft > 0 ? $t['costo_banc']   / $ft : 0,
                'pct_costo_ventas_fac' => $ft > 0 ? $t['costo_ventas'] / $ft : 0,
            ];
        }

        return ['anios' => $anios, 'datos' => $byAnioMes, 'totales' => $totales];
    }

    /* ──────────────────────────────────────────────
     *  DONUTS (tres desgloses en una sola query)
     * ────────────────────────────────────────────── */

    /**
     * Devuelve los tres desgloses para los donuts:
     *   facturacion  → por DESC_PROMOCION_TARJETA incluyendo SIN PROMO
     *   promociones  → por DESC_PROMOCION_TARJETA excluyendo SIN PROMO
     *   bancos       → por BANCO excluyendo SIN PROMO
     */
    public function getDonutDesglose(string $da, string $ha, array $fp): array
    {
        $haX = (new DateTime($ha))->modify('+1 day')->format('Y-m-d');

        [$sfF, $pF] = $this->buildFiltros($fp, 's', false);
        [$sfG, $pG] = $this->grupoFiltro('s');

        $baseParams = array_merge([$da, $haX], $pF, $pG);

        // Desglose Facturación: todas las promos (incluye SIN PROMO)
        $rowsFact = $this->query("
            SELECT s.DESC_PROMOCION_TARJETA AS etiqueta,
                   ISNULL(SUM(s.IMPORTE_TO),0) AS valor,
                   ISNULL(SUM(s.COSTO),0)       AS costo_promo
            FROM BI_PROMOCIONES s WITH (NOLOCK)
            WHERE s.FECHA >= ? AND s.FECHA < ? {$sfF} {$sfG}
            GROUP BY s.DESC_PROMOCION_TARJETA
            ORDER BY valor DESC
        ", $baseParams);

        // Desglose Promociones: excluye SIN PROMO
        $rowsPromo = $this->query("
            SELECT s.DESC_PROMOCION_TARJETA AS etiqueta,
                   ISNULL(SUM(s.IMPORTE_TO),0) AS valor,
                   ISNULL(SUM(s.COSTO),0)       AS costo_promo
            FROM BI_PROMOCIONES s WITH (NOLOCK)
            WHERE s.FECHA >= ? AND s.FECHA < ?
              AND s.DESC_PROMOCION_TARJETA <> 'SIN PROMO'
              {$sfF} {$sfG}
            GROUP BY s.DESC_PROMOCION_TARJETA
            ORDER BY valor DESC
        ", $baseParams);

        // Desglose Bancos: excluye SIN PROMO y DESCONOCIDO
        $rowsBancos = $this->query("
            SELECT s.BANCO AS etiqueta,
                   ISNULL(SUM(s.IMPORTE_TO),0) AS valor,
                   ISNULL(SUM(s.COSTO),0)       AS costo_promo
            FROM BI_PROMOCIONES s WITH (NOLOCK)
            WHERE s.FECHA >= ? AND s.FECHA < ?
              AND s.DESC_PROMOCION_TARJETA <> 'SIN PROMO'
              AND s.BANCO <> 'DESCONOCIDO'
              {$sfF} {$sfG}
            GROUP BY s.BANCO
            ORDER BY valor DESC
        ", $baseParams);

        $toArr = fn(array $rows): array => array_map(fn($r) => [
            'etiqueta'   => $r['etiqueta'],
            'valor'      => (float)$r['valor'],
            'costo_promo'=> (float)$r['costo_promo'],
        ], $rows);

        return [
            'facturacion' => $toArr($rowsFact),
            'promociones' => $toArr($rowsPromo),
            'bancos'      => $toArr($rowsBancos),
        ];
    }

    /* ──────────────────────────────────────────────
     *  DETALLE POR SUCURSAL
     * ────────────────────────────────────────────── */

    public function getDetalleSucursales(string $da, string $ha, array $fp): array
    {
        $haX = (new DateTime($ha))->modify('+1 day')->format('Y-m-d');

        [$cpSql, $cpParams] = $this->cpExpr($fp, 's');
        [$sfF,   $pF]       = $this->buildFiltros($fp, 's', false);
        [$sfG,   $pG]       = $this->grupoFiltro('s');

        $params = array_merge($cpParams, [$da, $haX], $pF, $pG);

        $rows = $this->query("
            SELECT
                s.NRO_SUCURSAL,
                MAX(s.SUCURSAL) COLLATE Modern_Spanish_CI_AI AS sucursal_nombre,
                ISNULL(SUM(s.IMPORTE_TO),0) AS fact_total,
                COUNT(DISTINCT CASE WHEN s.T_COMP COLLATE Latin1_General_BIN = 'FAC' THEN s.N_COMP END) AS tickets,
                ISNULL(SUM(CASE WHEN cp=1 THEN s.IMPORTE_TO ELSE 0 END),0) AS fact_cpromo,
                ISNULL(SUM(CASE WHEN cp=1 THEN ISNULL(s.COSTO,0) ELSE 0 END),0) AS costo_promo,
                ISNULL(SUM(ISNULL(s.COSTO_PROMO_BANCARIA,0)),0) AS costo_banc,
                ISNULL(SUM(ISNULL(s.COSTO_PROMO_VENTAS,0)),0)   AS costo_ventas
            FROM (
                SELECT s.*,
                    {$cpSql} AS cp
                FROM BI_PROMOCIONES s WITH (NOLOCK)
                WHERE s.FECHA >= ? AND s.FECHA < ? {$sfF} {$sfG}
            ) s
            GROUP BY s.NRO_SUCURSAL
            HAVING SUM(CASE WHEN cp=1 THEN s.IMPORTE_TO ELSE 0 END) > 0
            ORDER BY fact_total DESC
        ", $params);

        return array_map(function($r): array {
            $ft  = (float)$r['fact_total'];
            $fc  = (float)$r['fact_cpromo'];
            $cto = (float)$r['costo_promo'];
            return [
                'nro_sucursal'    => (int)$r['NRO_SUCURSAL'],
                'sucursal'        => $r['sucursal_nombre'],
                'fac_total'       => $ft,
                'fac_cpromo'      => $fc,
                'tickets_cpromo'  => (int)$r['tickets'],
                'costo_total'     => $cto,
                'costo_banc'      => (float)$r['costo_banc'],
                'costo_ventas'    => (float)$r['costo_ventas'],
                'pct_costo_total' => $ft > 0 ? $cto / $ft : 0,
                'pct_promo_fac'   => $ft > 0 ? $fc  / $ft : 0,
            ];
        }, $rows);
    }

    /* ──────────────────────────────────────────────
     *  DETALLE POR PROMOCIÓN
     * ────────────────────────────────────────────── */

    public function getDetallePromociones(
        string $da, string $ha,
        string $dp, string $hp,
        array  $fp
    ): array {
        $haX = (new DateTime($ha))->modify('+1 day')->format('Y-m-d');
        $hpX = (new DateTime($hp))->modify('+1 day')->format('Y-m-d');

        [$sfF, $pF] = $this->buildFiltros($fp, 's', false);
        [$sfG, $pG] = $this->grupoFiltro('s');

        // Para detallePromociones el filtro de Promoción del usuario va en el WHERE
        // (aquí la promo ya excluye SIN PROMO por la tabla misma)
        $promoWhere = '';
        $promoParams = [];
        if (!empty($fp['promocion'])) {
            $promoWhere    = "AND s.DESC_PROMOCION_TARJETA = ?";
            $promoParams[] = $fp['promocion'];
        }

        $params = array_merge(
            [$da, $haX, $dp, $hpX, $da, $haX, $dp, $hpX],
            $pF, $pG, $promoParams
        );

        $rows = $this->query("
            SELECT
                s.DESC_PROMOCION_TARJETA AS promocion,
                s.BANCO                  AS banco,
                ISNULL(SUM(CASE WHEN is_a=1 THEN s.IMPORTE_TO ELSE 0 END),0)                           AS fact_act,
                ISNULL(SUM(CASE WHEN is_p=1 THEN s.IMPORTE_TO ELSE 0 END),0)                           AS fact_prev,
                COUNT(DISTINCT CASE WHEN is_a=1 AND s.T_COMP COLLATE Latin1_General_BIN = 'FAC'
                                    THEN s.N_COMP END)                                                  AS tick_act,
                ISNULL(SUM(CASE WHEN is_a=1 THEN ISNULL(s.COSTO,0) ELSE 0 END),0)                     AS costo_promo,
                ISNULL(SUM(CASE WHEN is_a=1 THEN ISNULL(s.COSTO_PROMO_BANCARIA,0) ELSE 0 END),0)      AS costo_banc,
                ISNULL(SUM(CASE WHEN is_a=1 THEN ISNULL(s.COSTO_PROMO_VENTAS,0) ELSE 0 END),0)        AS costo_ventas
            FROM (
                SELECT s.*,
                    CASE WHEN s.FECHA >= ? AND s.FECHA < ? THEN 1 ELSE 0 END AS is_a,
                    CASE WHEN s.FECHA >= ? AND s.FECHA < ? THEN 1 ELSE 0 END AS is_p
                FROM BI_PROMOCIONES s WITH (NOLOCK)
                WHERE (
                    (s.FECHA >= ? AND s.FECHA < ?)
                    OR (s.FECHA >= ? AND s.FECHA < ?)
                )
                  AND s.DESC_PROMOCION_TARJETA <> 'SIN PROMO'
                  {$sfF} {$sfG}
                  {$promoWhere}
            ) s
            GROUP BY s.DESC_PROMOCION_TARJETA, s.BANCO
            ORDER BY fact_act DESC
        ", $params);

        $varFn = fn($a, $p) => $p != 0 ? ($a - $p) / $p : ($a > 0 ? 1 : 0);

        return array_map(function($r) use ($varFn): array {
            $fa  = (float)$r['fact_act'];
            $fp  = (float)$r['fact_prev'];
            $cto = (float)$r['costo_promo'];
            return [
                'promocion'       => $r['promocion'],
                'banco'           => $r['banco'],
                'fac_cpromo'      => $fa,
                'fac_prev'        => $fp,
                'var_fac'         => $varFn($fa, $fp),
                'tickets_cpromo'  => (int)$r['tick_act'],
                'costo_total'     => $cto,
                'costo_banc'      => (float)$r['costo_banc'],
                'costo_ventas'    => (float)$r['costo_ventas'],
                'pct_costo_total' => $fa > 0 ? $cto / $fa : 0,
            ];
        }, $rows);
    }

    /* ──────────────────────────────────────────────
     *  LISTAS PARA FILTROS
     * ────────────────────────────────────────────── */

    public function getBancosLista(): array
    {
        [$sfG, $pG] = $this->grupoFiltro('s');
        return $this->query("
            SELECT DISTINCT s.BANCO
            FROM BI_PROMOCIONES s WITH (NOLOCK)
            WHERE s.BANCO IS NOT NULL AND s.BANCO <> ''
              AND s.BANCO <> 'DESCONOCIDO'
              AND s.DESC_PROMOCION_TARJETA <> 'SIN PROMO'
              {$sfG}
            ORDER BY s.BANCO
        ", $pG);
    }

    public function getPromocionesLista(): array
    {
        [$sfG, $pG] = $this->grupoFiltro('s');
        return $this->query("
            SELECT DISTINCT s.DESC_PROMOCION_TARJETA AS promocion
            FROM BI_PROMOCIONES s WITH (NOLOCK)
            WHERE s.DESC_PROMOCION_TARJETA IS NOT NULL
              AND s.DESC_PROMOCION_TARJETA <> 'SIN PROMO'
              {$sfG}
            ORDER BY s.DESC_PROMOCION_TARJETA
        ", $pG);
    }

    public function getSucursalesLista(): array
    {
        [$sfG, $pG] = $this->grupoFiltro('s');
        return $this->query("
            SELECT DISTINCT s.NRO_SUCURSAL, s.SUCURSAL COLLATE Modern_Spanish_CI_AI AS SUCURSAL
            FROM BI_PROMOCIONES s WITH (NOLOCK)
            INNER JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WITH (NOLOCK)
                ON sl.NRO_SUCURSAL = s.NRO_SUCURSAL AND sl.HABILITADO = 1 AND sl.NRO_SUC_MADRE IS NULL
            WHERE s.NRO_SUCURSAL IS NOT NULL
              {$sfG}
            ORDER BY s.SUCURSAL
        ", $pG);
    }

    public function getSucursalesActivasIds(): array
    {
        $rows = $this->query("
            SELECT NRO_SUCURSAL
            FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS
            WHERE HABILITADO = 1
        ");
        return array_values(array_map(fn($r) => (int)$r['NRO_SUCURSAL'], $rows));
    }
}
