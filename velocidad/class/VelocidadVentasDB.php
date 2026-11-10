<?php
/**
 * /bi/velocidad/class/VelocidadVentasDB.php
 * Capa de datos del tablero "Velocidad de Ventas" (réplica web del informe de Power BI).
 *
 * Réplica de las medidas DAX del modelo (todas agregadas por COD_ARTICU):
 *   % Venta / ingreso locales = SUM(VENTA_TOTAL)  / SUM(INGRESO_LOCALES)
 *   % Venta 15 dias           = SUM(15_DIAS)      / SUM(CANT_PRIMER_INGRESO)   <- el que lleva semáforo
 *   Días de stock             = SUM(INGRESO_LOCALES) / (SUM(15_DIAS) / 15)
 *   % Venta 30 / 60 dias      = SUM(30_DIAS | 60_DIAS) / SUM(CANT_INGRESO_30 | CANT_INGRESO_60)
 *     (misma lógica que la de 15 días con su propia ventana. Reemplaza al "Crecimiento 1 / 2" del
 *      informe, (30_DIAS − 15_DIAS) / 15_DIAS, que tenía el mismo título pero no era venta sobre ingreso.
 *      Si la tabla todavía no tiene las columnas CANT_INGRESO_30/60, por ejemplo en UY, el valor queda vacío.)
 *
 * Relaciones del modelo que se respetan en los filtros:
 *   Fecha  -> INGRESO (CALENDARIO[FECHA])      Modelo -> COD_ARTICU (MODELOS[MODELOS])
 *   Línea  -> NOMBRE  (LINEAS[NOMBRE])         Rubro / Categoría / Color -> columnas homónimas
 */

require_once __DIR__ . '/../../class/conexion.php';

class VelocidadVentasDB
{
    /**
     * Dónde vive cada tabla. 'conn' es el nombre que entiende Conexion::conectar():
     *   'power' (XL-APPS / POWER_BI_CONTROL), 'power_uy' (POWER_BI_CONTROL_URUGUAY),
     *   'locales' (XL-LAKERBIS / locales_lakers), etc.
     *
     * >>> VERIFICAR / AJUSTAR acá si BI_ROTACION_VENTAS (o la _UY) está en otra base. <<<
     * Si la tabla no está donde se indica, el endpoint devuelve "Invalid object name ...".
     * El stock por sucursal (BI_STOCK_LOCALES) existe solo para Argentina.
     */
    private const ORIGENES = [
        'ar' => [
            'conn'       => 'power',
            'rotacion'   => 'dbo.BI_ROTACION_VENTAS',
            'stock_conn' => 'power',
            'stock'      => 'dbo.BI_STOCK_LOCALES',
        ],
        'uy' => [
            'conn'       => 'power_uy',
            'rotacion'   => 'dbo.BI_ROTACION_VENTAS_UY',
            'stock_conn' => null,
            'stock'      => null,
        ],
    ];

    /** Cantidad de filas del "Ranking 50 inferiores". */
    private const TOP_NEGATIVO = 50;

    /** Columnas de texto: no se convierten a número aunque "parezcan" numéricas. */
    private const COLS_TEXTO = ['modelo', 'descripcion', 'color', 'sucursal', 'rubro', 'categoria', 'linea'];

    private $conexion;
    private $conns = [];
    private $timeoutSeconds = 60;

    public function __construct()
    {
        $this->conexion = new Conexion();
    }

    public static function origenValido(string $origen): bool
    {
        return isset(self::ORIGENES[$origen]);
    }

    public static function tieneStock(string $origen): bool
    {
        return isset(self::ORIGENES[$origen]) && self::ORIGENES[$origen]['stock'] !== null;
    }

    // ─────────────────────────────────────────────────────────────
    // Infraestructura
    // ─────────────────────────────────────────────────────────────

    private function conn(string $server)
    {
        if (!isset($this->conns[$server])) {
            $c = $this->conexion->conectar($server);
            if (!$c) {
                throw new RuntimeException("Error al conectar con el servidor '$server'");
            }
            $this->conns[$server] = $c;
        }
        return $this->conns[$server];
    }

    private function cfg(string $origen): array
    {
        if (!isset(self::ORIGENES[$origen])) {
            throw new InvalidArgumentException("Origen inválido: $origen");
        }
        return self::ORIGENES[$origen];
    }

    /** Ejecuta una consulta parametrizada y devuelve todas las filas normalizadas. */
    private function query($conn, string $sql, array $params = []): array
    {
        $stmt = sqlsrv_query($conn, $sql, $params, ['QueryTimeout' => $this->timeoutSeconds]);
        if ($stmt === false) {
            $errs = sqlsrv_errors();
            error_log('VelocidadVentasDB: ' . print_r($errs, true));
            $msg = $errs ? ($errs[0]['message'] ?? 'Error SQL') : 'Error SQL';
            throw new RuntimeException($msg);
        }
        $rows = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $this->norm($r);
        }
        sqlsrv_free_stmt($stmt);
        return $rows;
    }

    /** Fechas -> 'Y-m-d', numéricos -> float, textos -> trim. */
    private function norm(array $r): array
    {
        foreach ($r as $k => $v) {
            if ($v instanceof DateTimeInterface) {
                $r[$k] = $v->format('Y-m-d');
            } elseif ($v === null) {
                continue;
            } elseif (in_array($k, self::COLS_TEXTO, true)) {
                $r[$k] = trim((string)$v);
            } elseif (is_numeric($v)) {
                $r[$k] = (float)$v;
            } elseif (is_string($v)) {
                $r[$k] = trim($v);
            }
        }
        return $r;
    }

    /** WHERE común a ranking y filtros (todo parametrizado). */
    private function where(array $f): array
    {
        $w = ['1=1'];
        $p = [];

        if (!empty($f['desde'])) {
            $w[] = 'INGRESO >= ?';
            $p[] = $f['desde'];
        }
        if (!empty($f['hasta'])) {
            // inclusive: hasta el final del día
            $w[] = 'INGRESO < DATEADD(DAY, 1, CAST(? AS date))';
            $p[] = $f['hasta'];
        }

        $mapa = [
            'rubro'     => 'RUBRO',
            'categoria' => 'CATEGORIA',
            'color'     => 'COLOR',
            'linea'     => 'NOMBRE',      // LINEAS[NOMBRE] <- BI_ROTACION_VENTAS[NOMBRE]
            'modelo'    => 'COD_ARTICU',  // MODELOS[MODELOS] <- BI_ROTACION_VENTAS[COD_ARTICU]
        ];
        foreach ($mapa as $key => $col) {
            if (isset($f[$key]) && $f[$key] !== '') {
                $w[] = "LTRIM(RTRIM($col)) = ?";
                $p[] = $f[$key];
            }
        }

        return ['sql' => implode(' AND ', $w), 'params' => $p];
    }

    // ─────────────────────────────────────────────────────────────
    // Consultas públicas
    // ─────────────────────────────────────────────────────────────

    /**
     * Listas para los filtros de la barra superior.
     * @return array{rubros:string[],categorias:array,colores:string[],lineas:string[],modelos:array}
     */
    public function getFiltros(string $origen): array
    {
        $cfg  = $this->cfg($origen);
        $conn = $this->conn($cfg['conn']);
        $t    = $cfg['rotacion'];

        $rc = $this->query($conn,
            "SELECT DISTINCT
                NULLIF(LTRIM(RTRIM(RUBRO)), '')     AS rubro,
                NULLIF(LTRIM(RTRIM(CATEGORIA)), '') AS categoria,
                NULLIF(LTRIM(RTRIM(COLOR)), '')     AS color
             FROM $t WITH (NOLOCK)");

        $rubros = $categorias = $colores = [];
        foreach ($rc as $r) {
            if (!empty($r['rubro'])) {
                $rubros[$r['rubro']] = true;
            }
            if (!empty($r['categoria'])) {
                $categorias[$r['rubro'] . '|' . $r['categoria']] = ['rubro' => $r['rubro'], 'categoria' => $r['categoria']];
            }
            if (!empty($r['color'])) {
                $colores[$r['color']] = true;
            }
        }
        $rubros  = array_keys($rubros);
        $colores = array_keys($colores);
        sort($rubros);
        sort($colores);
        $categorias = array_values($categorias);
        usort($categorias, function ($a, $b) {
            return strcmp((string)$a['categoria'], (string)$b['categoria']);
        });

        $lineas = array_column($this->query($conn,
            "SELECT DISTINCT LTRIM(RTRIM(NOMBRE)) AS linea
             FROM $t WITH (NOLOCK)
             WHERE NOMBRE IS NOT NULL AND LTRIM(RTRIM(NOMBRE)) <> ''
             ORDER BY linea"), 'linea');

        $modelos = $this->query($conn,
            "SELECT LTRIM(RTRIM(COD_ARTICU)) AS modelo, MAX(LTRIM(RTRIM(NOMBRE))) AS descripcion
             FROM $t WITH (NOLOCK)
             WHERE COD_ARTICU IS NOT NULL
             GROUP BY LTRIM(RTRIM(COD_ARTICU))
             ORDER BY modelo");

        return [
            'rubros'     => $rubros,
            'categorias' => $categorias,
            'colores'    => $colores,
            'lineas'     => $lineas,
            'modelos'    => $modelos,
            'tiene_stock' => self::tieneStock($origen),
        ];
    }

    /**
     * Ranking por modelo (validado celda por celda contra el modelo de Power BI, 06/11/2026).
     *   $modo = 'top'    -> todos los modelos, por ventas a 15 días (desc)        [Ranking Argentina / Uruguay]
     *   $modo = 'bottom' -> los 50 con MENOS ventas a 15 días, empates incluidos,
     *                       mostrados por Venta / ingreso 15 días (asc)          [Ranking Negativo]
     *     (filtro Top N del visual: Bottom 50 de MODELOS por [15 Dias]. TOPN de DAX incluye
     *      los empates del puesto 50, por eso puede devolver más de 50 filas.)
     *
     * Filtro de visual de Power BI "[Ingreso central] > 0": se aplica en Argentina y en el
     * Negativo. En Uruguay el visual lo tiene contra la medida de la tabla ARGENTINA, que no
     * filtra las filas de Uruguay, así que en la práctica no aplica y acá tampoco.
     *
     * @param array $f desde, hasta, rubro, categoria, color, linea, modelo
     */
    public function getRanking(string $origen, string $modo, array $f, int $limit = 1000): array
    {
        $cfg  = $this->cfg($origen);
        $conn = $this->conn($cfg['conn']);
        $t    = $cfg['rotacion'];
        $w    = $this->where($f);

        $soloConIngresoCentral = ($origen === 'ar') ? 'WHERE ingreso_central > 0' : '';

        // Denominadores de 30/60 días: columnas agregadas al ETL en 11/2026 (puede que no existan en UY).
        $tiene3060 = $this->tieneColumna($conn, $t, 'CANT_INGRESO_30') && $this->tieneColumna($conn, $t, 'CANT_INGRESO_60');
        $cant30 = $tiene3060 ? 'SUM(CANT_INGRESO_30)' : 'CAST(NULL AS float)';
        $cant60 = $tiene3060 ? 'SUM(CANT_INGRESO_60)' : 'CAST(NULL AS float)';

        if ($modo === 'bottom') {
            $select = "SELECT * FROM (
                    SELECT TOP " . self::TOP_NEGATIVO . " WITH TIES * FROM calc
                    WHERE vta_15 IS NOT NULL" . ($soloConIngresoCentral ? ' AND ingreso_central > 0' : '') . "
                    ORDER BY vta_15 ASC
                ) b
                ORDER BY CASE WHEN pct_venta_15 IS NULL THEN 1 ELSE 0 END, pct_venta_15 ASC, modelo ASC";
        } else {
            $top    = max(1, min($limit, 5000));
            $select = "SELECT TOP $top * FROM calc $soloConIngresoCentral ORDER BY vta_15 DESC, modelo ASC";
        }

        $sql =
            "WITH agg AS (
                SELECT
                    LTRIM(RTRIM(COD_ARTICU))   AS modelo,
                    MAX(LTRIM(RTRIM(NOMBRE)))  AS descripcion,
                    MAX(LTRIM(RTRIM(COLOR)))   AS color,
                    MIN(FECHA_ING_CENTRAL)     AS fecha_ing_central,
                    MIN(INGRESO)               AS fecha_ing_locales,
                    SUM(INGRESO_CENTRAL)       AS ingreso_central,
                    SUM(INGRESO_LOCALES)       AS ingreso_locales,
                    SUM(VENTA_TOTAL)           AS venta_total,
                    SUM(CANT_PRIMER_INGRESO)   AS cant_primer_ingreso,
                    SUM([15_DIAS])             AS vta_15,
                    SUM([30_DIAS])             AS vta_30,
                    SUM([60_DIAS])             AS vta_60,
                    $cant30                    AS cant_ingreso_30,
                    $cant60                    AS cant_ingreso_60,
                    SUM(STOCK_LOCALES)         AS stock_locales,
                    SUM(STOCK_CENTRAL)         AS stock_central
                FROM $t WITH (NOLOCK)
                WHERE " . $w['sql'] . "
                GROUP BY LTRIM(RTRIM(COD_ARTICU))
            ), calc AS (
                SELECT a.*,
                    CAST(venta_total AS float)  / NULLIF(CAST(ingreso_locales AS float), 0)       AS pct_venta_ingreso_locales,
                    CAST(vta_15 AS float)       / NULLIF(CAST(cant_primer_ingreso AS float), 0)   AS pct_venta_15,
                    CAST(ingreso_locales AS float) / NULLIF(CAST(vta_15 AS float) / 15.0, 0)      AS dias_stock,
                    CAST(vta_30 AS float)       / NULLIF(CAST(cant_ingreso_30 AS float), 0)       AS pct_venta_30,
                    CAST(vta_60 AS float)       / NULLIF(CAST(cant_ingreso_60 AS float), 0)       AS pct_venta_60
                FROM agg a
            )
            " . $select;

        return $this->query($conn, $sql, $w['params']);
    }

    /** true si la tabla tiene la columna (para convivir con tablas que aún no tienen columnas nuevas del ETL). */
    private function tieneColumna($conn, string $tabla, string $columna): bool
    {
        $r = $this->query($conn, 'SELECT COL_LENGTH(?, ?) AS l', [$tabla, $columna]);
        return !empty($r) && $r[0]['l'] !== null && $r[0]['l'] !== '';
    }

    /**
     * Fecha/hora de la última carga de la tabla de rotación (equivale a la tarjeta
     * "Última actualización" del informe). null si SQL Server no lo informa.
     */
    public function getUltimaActualizacion(string $origen): ?string
    {
        $cfg  = $this->cfg($origen);
        $conn = $this->conn($cfg['conn']);
        $stmt = sqlsrv_query($conn,
            "SELECT MAX(last_user_update) AS u FROM sys.dm_db_index_usage_stats
             WHERE database_id = DB_ID() AND object_id = OBJECT_ID(?)",
            [$cfg['rotacion']], ['QueryTimeout' => 15]);
        if ($stmt === false) {
            return null;
        }
        $r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        $u = $r['u'] ?? null;
        return ($u instanceof DateTimeInterface) ? $u->format('Y-m-d H:i') : null;
    }

    /**
     * Stock por sucursal de un modelo (solo Argentina: BI_STOCK_LOCALES).
     * Igual que el panel "Stock Locales" de Power BI: solo las sucursales donde la medida
     * [Stock por local] no da vacío, de mayor a menor.
     */
    public function getStock(string $modelo): array
    {
        $cfg = $this->cfg('ar');
        if ($cfg['stock'] === null) {
            return ['modelo' => $modelo, 'descripcion' => null, 'color' => null, 'total' => 0, 'sucursales' => []];
        }
        $conn = $this->conn($cfg['stock_conn']);
        $s    = $cfg['stock'];

        $suc = $this->query($conn,
            "SELECT LTRIM(RTRIM(SUCURSAL)) AS sucursal, SUM(CANT_STOCK) AS stock
             FROM $s WITH (NOLOCK)
             WHERE LTRIM(RTRIM(MODELO)) = ?
               AND SUCURSAL IS NOT NULL AND LTRIM(RTRIM(SUCURSAL)) <> ''
             GROUP BY LTRIM(RTRIM(SUCURSAL))
             HAVING SUM(CANT_STOCK) IS NOT NULL
             ORDER BY stock DESC, sucursal",
            [$modelo]);

        $meta = $this->query($conn,
            "SELECT TOP 1 LTRIM(RTRIM(DESCRIPCION)) AS descripcion, LTRIM(RTRIM(COLOR)) AS color
             FROM $s WITH (NOLOCK)
             WHERE LTRIM(RTRIM(MODELO)) = ?",
            [$modelo]);

        $total = 0;
        foreach ($suc as $r) {
            $total += (float)($r['stock'] ?? 0);
        }

        return [
            'modelo'      => $modelo,
            'descripcion' => $meta[0]['descripcion'] ?? null,
            'color'       => $meta[0]['color'] ?? null,
            'total'       => $total,
            'sucursales'  => $suc,
        ];
    }
}
