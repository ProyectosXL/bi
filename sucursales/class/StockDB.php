<?php
/**
 * StockDB
 * Acceso a datos para la pestaña de Stock del local.
 *
 * Fuente: POWER_BI_CONTROL_FRANQUICIAS.dbo.BI_STOCK_FRANQUICIAS
 *   - Es una FOTO del día (no tiene columna de fecha), recargada de madrugada.
 *   - El grano es ARTICULO = MODELO + talle. Ver talleExpr().
 *   - La tabla tiene PRECIO (de venta), pero la pestaña no valoriza: sólo unidades.
 *
 * Reglas de negocio fijas, aplicadas en whereStock() y por lo tanto imposibles de
 * saltear desde afuera:
 *   - Aislamiento por NRO_SUCURSAL (la sucursal sale de la sesión, nunca del querystring).
 *   - Se excluye RUBRO = 'PACKAGING' (bolsas, tarjetas OH GIFT: no es mercadería
 *     vendible y tiene PRECIO 0), igual que el resto del dashboard.
 *   - Se excluyen las filas con CANT_STOCK = 0.
 */
class StockDB
{
    private $conn;
    private string $tabla;

    public function __construct()
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/Class/Conexion.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/bi/class/config.php';

        $config = getConfig();

        // La tabla se deriva de la BASE, no de $_SESSION['tipo']: getConfig() manda
        // tanto FRANQUICIA como GRUPO a power_franquicias.
        if ($config['db'] !== 'power_franquicias') {
            throw new RuntimeException(
                'El stock por local sólo está disponible para franquicias: ' .
                'la vista BI_STOCK_LOCALES no tiene NRO_SUCURSAL y no permite aislar por sucursal.'
            );
        }
        $this->tabla = 'BI_STOCK_FRANQUICIAS';

        $cid        = new Conexion();
        $this->conn = $cid->conectar($config['db']);
        if (!$this->conn) {
            throw new RuntimeException('No se pudo conectar a POWER_BI_CONTROL_FRANQUICIAS');
        }
    }

    public function getTabla(): string
    {
        return $this->tabla;
    }

    /* ──────────────────────────────────────────────
     *  CONSULTAS
     * ────────────────────────────────────────────── */

    /**
     * Totales del local para los filtros activos.
     */
    public function getResumen(int $nroSucurs, array $f = []): array
    {
        [$w, $p] = $this->whereStock($nroSucurs, $f);

        $rows = $this->query("
            SELECT
                ISNULL(SUM(st.CANT_STOCK), 0)                          AS unidades,
                COUNT(DISTINCT ISNULL(st.RUBRO, 'SIN RUBRO'))          AS rubros,
                COUNT(DISTINCT ISNULL(st.CATEGORIA, 'SIN CATEGORÍA'))  AS categorias,
                COUNT(DISTINCT st.MODELO)                              AS modelos,
                COUNT(*)                                               AS articulos
            FROM dbo.{$this->tabla} st WITH (NOLOCK)
            WHERE 1=1 {$w}
        ", $p);

        $r = $rows[0] ?? [];
        return [
            'unidades'   => (float)($r['unidades']   ?? 0),
            'rubros'     => (int)  ($r['rubros']     ?? 0),
            'categorias' => (int)  ($r['categorias'] ?? 0),
            'modelos'    => (int)  ($r['modelos']    ?? 0),
            'articulos'  => (int)  ($r['articulos']  ?? 0),
        ];
    }

    /**
     * Niveles 1 y 2 del árbol: RUBRO → CATEGORÍA, agregado.
     * No baja a artículo: eso lo trae getDetalle() bajo demanda.
     */
    public function getArbol(int $nroSucurs, array $f = []): array
    {
        [$w, $p] = $this->whereStock($nroSucurs, $f);

        $rows = $this->query("
            SELECT
                ISNULL(st.RUBRO, 'SIN RUBRO')                          AS rubro,
                ISNULL(st.CATEGORIA, 'SIN CATEGORÍA')                  AS categoria,
                ISNULL(SUM(st.CANT_STOCK), 0)                          AS unidades,
                COUNT(DISTINCT st.MODELO)                              AS modelos,
                COUNT(*)                                               AS articulos
            FROM dbo.{$this->tabla} st WITH (NOLOCK)
            WHERE 1=1 {$w}
            GROUP BY ISNULL(st.RUBRO, 'SIN RUBRO'), ISNULL(st.CATEGORIA, 'SIN CATEGORÍA')
            ORDER BY ISNULL(st.RUBRO, 'SIN RUBRO'), SUM(st.CANT_STOCK) DESC
        ", $p);

        return $this->anidarArbol($rows);
    }

    /**
     * Filas a nivel ARTICULO de una categoría. Alimenta los niveles 3 y 4 del
     * árbol en una sola request: el JS agrupa por MODELO y los talles quedan
     * como hijos sin pedir nada más.
     */
    public function getDetalle(int $nroSucurs, string $rubro, string $categoria, array $f = []): array
    {
        $f['rubro']     = $rubro;
        $f['categoria'] = $categoria;
        [$w, $p] = $this->whereStock($nroSucurs, $f);

        $talle = $this->talleExpr('st');

        $rows = $this->query("
            SELECT
                st.ARTICULO                                            AS articulo,
                RTRIM(st.MODELO)                                       AS modelo,
                ISNULL(st.DESCRIPCION, '')                             AS descripcion,
                ISNULL(st.TEMPORADA, '')                               AS temporada,
                ISNULL(st.DESTINO, '')                                 AS destino,
                {$talle}                                               AS talle,
                ISNULL(SUM(st.CANT_STOCK), 0)                          AS unidades
            FROM dbo.{$this->tabla} st WITH (NOLOCK)
            WHERE 1=1 {$w}
            GROUP BY st.ARTICULO, RTRIM(st.MODELO), ISNULL(st.DESCRIPCION, ''),
                     ISNULL(st.TEMPORADA, ''), ISNULL(st.DESTINO, ''), {$talle}
            ORDER BY RTRIM(st.MODELO), {$talle}
        ", $p);

        return array_map([$this, 'castFila'], $rows);
    }

    /**
     * Búsqueda libre por código, modelo o descripción, sobre todo el stock del local.
     */
    public function buscar(int $nroSucurs, string $q, array $f = [], int $top = 200): array
    {
        [$w, $p] = $this->whereStock($nroSucurs, $f);

        $talle = $this->talleExpr('st');
        $like  = '%' . $q . '%';

        // TOP interpolado, no parametrizado: el driver sqlsrv no siempre acepta
        // TOP (?). Es seguro porque $top se acota a un entero acá mismo.
        $top    = max(1, min(1000, (int)$top));
        $params = array_merge($p, [$like, $like, $like]);

        $rows = $this->query("
            SELECT TOP {$top}
                st.ARTICULO                                            AS articulo,
                RTRIM(st.MODELO)                                       AS modelo,
                ISNULL(st.DESCRIPCION, '')                             AS descripcion,
                ISNULL(st.TEMPORADA, '')                               AS temporada,
                ISNULL(st.DESTINO, '')                                 AS destino,
                ISNULL(st.RUBRO, 'SIN RUBRO')                          AS rubro,
                ISNULL(st.CATEGORIA, 'SIN CATEGORÍA')                  AS categoria,
                {$talle}                                               AS talle,
                ISNULL(SUM(st.CANT_STOCK), 0)                          AS unidades
            FROM dbo.{$this->tabla} st WITH (NOLOCK)
            WHERE 1=1 {$w}
              AND (st.ARTICULO LIKE ? OR st.MODELO LIKE ? OR st.DESCRIPCION LIKE ?)
            GROUP BY st.ARTICULO, RTRIM(st.MODELO), ISNULL(st.DESCRIPCION, ''),
                     ISNULL(st.TEMPORADA, ''), ISNULL(st.DESTINO, ''),
                     ISNULL(st.RUBRO, 'SIN RUBRO'), ISNULL(st.CATEGORIA, 'SIN CATEGORÍA'), {$talle}
            ORDER BY SUM(st.CANT_STOCK) DESC
        ", $params);

        return array_map([$this, 'castFila'], $rows);
    }

    /**
     * Valores para los selectores. No aplica los filtros activos: si los aplicara,
     * elegir un rubro haría desaparecer del combo a todos los demás.
     */
    public function getFiltros(int $nroSucurs): array
    {
        [$w, $p] = $this->whereStock($nroSucurs, []);

        $rubrosCats = $this->query("
            SELECT
                ISNULL(st.RUBRO, 'SIN RUBRO')          AS rubro,
                ISNULL(st.CATEGORIA, 'SIN CATEGORÍA')  AS categoria,
                ISNULL(SUM(st.CANT_STOCK), 0)          AS unidades
            FROM dbo.{$this->tabla} st WITH (NOLOCK)
            WHERE 1=1 {$w}
            GROUP BY ISNULL(st.RUBRO, 'SIN RUBRO'), ISNULL(st.CATEGORIA, 'SIN CATEGORÍA')
            ORDER BY ISNULL(st.RUBRO, 'SIN RUBRO'), ISNULL(st.CATEGORIA, 'SIN CATEGORÍA')
        ", $p);

        $temporadas = $this->query("
            SELECT ISNULL(st.TEMPORADA, 'SIN TEMPORADA') AS valor,
                   ISNULL(SUM(st.CANT_STOCK), 0)         AS unidades
            FROM dbo.{$this->tabla} st WITH (NOLOCK)
            WHERE 1=1 {$w}
            GROUP BY ISNULL(st.TEMPORADA, 'SIN TEMPORADA')
            ORDER BY SUM(st.CANT_STOCK) DESC
        ", $p);

        $destinos = $this->query("
            SELECT ISNULL(st.DESTINO, 'SIN DESTINO') AS valor,
                   ISNULL(SUM(st.CANT_STOCK), 0)     AS unidades
            FROM dbo.{$this->tabla} st WITH (NOLOCK)
            WHERE 1=1 {$w}
            GROUP BY ISNULL(st.DESTINO, 'SIN DESTINO')
            ORDER BY SUM(st.CANT_STOCK) DESC
        ", $p);

        // Rubros únicos, con sus categorías, para la cascada del selector.
        $rubros = [];
        $cats   = [];
        foreach ($rubrosCats as $r) {
            $rubros[$r['rubro']] = ($rubros[$r['rubro']] ?? 0) + (float)$r['unidades'];
            $cats[] = ['rubro' => $r['rubro'], 'categoria' => $r['categoria']];
        }
        $listaRubros = [];
        foreach ($rubros as $nombre => $unid) {
            $listaRubros[] = ['rubro' => $nombre, 'unidades' => $unid];
        }

        return [
            'rubros'     => $listaRubros,
            'categorias' => $cats,
            'temporadas' => array_map(fn($r) => $r['valor'], $temporadas),
            'destinos'   => array_map(fn($r) => $r['valor'], $destinos),
        ];
    }

    /**
     * Momento de la última escritura de la tabla, como proxy de la frescura del dato
     * (la tabla no tiene columna de fecha). Mismo recurso que SalesDB::getUltimaActualizacion().
     * Devuelve null si el contador se reseteó o falta el permiso VIEW DATABASE STATE:
     * es metadato accesorio, no puede tumbar la pestaña.
     */
    public function getUltimaCarga(): ?string
    {
        try {
            $rows = $this->query("
                SELECT MAX(s.last_user_update) AS ultima
                FROM sys.dm_db_index_usage_stats s
                WHERE s.database_id = DB_ID()
                  AND s.object_id   = OBJECT_ID(?)
            ", ['dbo.' . $this->tabla]);
        } catch (Throwable $e) {
            return null;
        }

        $val = $rows[0]['ultima'] ?? null;
        if ($val instanceof DateTime) return $val->format('Y-m-d H:i:s');
        return $val !== null ? (string)$val : null;
    }

    /* ──────────────────────────────────────────────
     *  PRIVADOS
     * ────────────────────────────────────────────── */

    /**
     * Fragmento WHERE común. El filtro por sucursal NO es opcional: por construcción
     * no se puede emitir una query de stock sin aislamiento por local.
     *
     * @return array [$sql, $params]
     */
    private function whereStock(int $nroSucurs, array $f, string $a = 'st'): array
    {
        if ($nroSucurs <= 0) {
            throw new RuntimeException('Sucursal inválida: el stock no puede consultarse sin aislamiento por local.');
        }

        $sql    = " AND {$a}.NRO_SUCURSAL = ?"
                . " AND ISNULL({$a}.RUBRO, '') <> 'PACKAGING'"
                . " AND {$a}.CANT_STOCK <> 0";
        $params = [$nroSucurs];

        $map = [
            'rubro'     => "ISNULL({$a}.RUBRO, 'SIN RUBRO')",
            'categoria' => "ISNULL({$a}.CATEGORIA, 'SIN CATEGORÍA')",
            'temporada' => "ISNULL({$a}.TEMPORADA, 'SIN TEMPORADA')",
            'destino'   => "ISNULL({$a}.DESTINO, 'SIN DESTINO')",
        ];

        foreach ($map as $clave => $expr) {
            $val = $f[$clave] ?? '%';
            if ($val !== null && $val !== '' && $val !== '%') {
                $sql     .= " AND {$expr} = ?";
                $params[] = $val;
            }
        }

        return [$sql, $params];
    }

    /**
     * Talle = lo que sigue al MODELO dentro del ARTICULO.
     * Ej: MODELO 'XV4SWE05J0119' + ARTICULO 'XV4SWE05J0119L' → talle 'L'
     *     MODELO 'OC3WSU03Z0107' + ARTICULO 'OC3WSU03Z010740' → talle '40'
     *
     * Ambas columnas son Latin1_General_BIN, así que se comparan sin COLLATE.
     * Se valida el prefijo antes de cortar: un código que no lo respete cae en
     * 'ÚNICO' en lugar de producir un talle basura.
     */
    private function talleExpr(string $a = 'st'): string
    {
        return "CASE
                    WHEN {$a}.MODELO IS NOT NULL
                     AND LEN({$a}.ARTICULO) > LEN(RTRIM({$a}.MODELO))
                     AND LEFT({$a}.ARTICULO, LEN(RTRIM({$a}.MODELO))) = RTRIM({$a}.MODELO)
                    THEN SUBSTRING({$a}.ARTICULO, LEN(RTRIM({$a}.MODELO)) + 1, 10)
                    ELSE 'ÚNICO'
                END";
    }

    /**
     * Convierte las filas planas de getArbol() en RUBRO → [CATEGORÍAS].
     */
    private function anidarArbol(array $rows): array
    {
        $tree = [];
        foreach ($rows as $r) {
            $rubro = $r['rubro'];
            if (!isset($tree[$rubro])) {
                $tree[$rubro] = [
                    'rubro'      => $rubro,
                    'unidades'   => 0.0,
                    'modelos'    => 0,
                    'articulos'  => 0,
                    'categorias' => [],
                ];
            }
            $tree[$rubro]['categorias'][] = [
                'categoria' => $r['categoria'],
                'unidades'  => (float)$r['unidades'],
                'modelos'   => (int)$r['modelos'],
                'articulos' => (int)$r['articulos'],
            ];
            $tree[$rubro]['unidades'] += (float)$r['unidades'];
            // modelos/articulos del rubro se suman por categoría: un mismo modelo no
            // se reparte entre categorías, así que la suma no duplica.
            $tree[$rubro]['modelos']   += (int)$r['modelos'];
            $tree[$rubro]['articulos'] += (int)$r['articulos'];
        }

        $out = array_values($tree);
        usort($out, fn($a, $b) => $b['unidades'] <=> $a['unidades']);
        return $out;
    }

    private function castFila(array $r): array
    {
        return [
            'articulo'     => (string)$r['articulo'],
            'modelo'       => (string)$r['modelo'],
            'descripcion'  => (string)$r['descripcion'],
            'temporada'    => (string)$r['temporada'],
            'destino'      => (string)$r['destino'],
            'talle'        => (string)$r['talle'],
            'rubro'        => isset($r['rubro'])     ? (string)$r['rubro']     : null,
            'categoria'    => isset($r['categoria']) ? (string)$r['categoria'] : null,
            'unidades'     => (float)$r['unidades'],
        ];
    }

    /**
     * Sin try/catch que se trague el error: un problema de datos tiene que verse
     * como un error, no como "no hay stock".
     */
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
}
