<?php
/**
 * Filters
 * Centraliza la construcción dinámica de cláusulas WHERE y sus parámetros
 * para filtros de sucursal, grupo, tipo_tienda, vendedor y rubro.
 *
 * Uso:
 *   [$sql, $params] = Filters::sucursal($sucursal, $grupo, $tipoTienda, 's', $origen);
 *   [$sql, $params] = Filters::vendedor($vendedor, 's', $campoVendedor);
 *   [$sql, $params] = Filters::rubro($rubro, 's');
 *
 *   // O todo de una vez:
 *   [$sql, $params] = Filters::build([
 *       'sucursal'    => $sucursal,
 *       'grupo'       => $grupo,
 *       'tipo_tienda' => $tipoTienda,
 *       'vendedor'    => $vendedor,
 *       'rubro'       => $rubro,
 *   ], 's', 'DESC_VENDEDOR', 'argentina');
 */
class Filters
{
    /**
     * Fragmento WHERE para filtros de localización:
     *   - sucursal (NRO_SUCURS o columna custom)
     *   - grupo    (solo argentina, subquery BI_DIM_SUCURSALES_GRUPO)
     *   - tipo_tienda (solo argentina, subquery SUCURSALES_LAKERS)
     *
     * @param int|null    $sucursal    Número de sucursal; null = sin filtro
     * @param string|null $grupo       Nombre de grupo; null = sin filtro
     * @param string|null $tipoTienda  Tipo de tienda; null = sin filtro
     * @param string      $alias       Alias de tabla en la query (e.g. 's', 't')
     * @param string      $origen      'argentina'|'uruguay'|'franquicias'
     * @param string      $sucursalCol Nombre de columna para sucursal (default NRO_SUCURS)
     * @return array{0:string, 1:array} [$sqlFragment, $params]
     */
    public static function sucursal(
        ?int $sucursal,
        ?string $grupo,
        ?string $tipoTienda,
        string $alias,
        string $origen = 'argentina',
        string $sucursalCol = 'NRO_SUCURS'
    ): array {
        $clauses = [];
        $params  = [];

        if ($sucursal !== null) {
            $clauses[] = "{$alias}.{$sucursalCol} = ?";
            $params[]  = $sucursal;
        }

        if ($grupo !== null && $origen === 'argentina') {
            $clauses[] = "{$alias}.{$sucursalCol} IN (
                SELECT g.NRO_SUCURS FROM BI_DIM_SUCURSALES_GRUPO g WHERE g.GRUPO = ?)";
            $params[] = $grupo;
        }

        if ($tipoTienda !== null && $origen === 'argentina') {
            $clauses[] = "{$alias}.{$sucursalCol} IN (
                SELECT sl.NRO_SUCURSAL
                FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
                WHERE sl.TIPO_TIENDA = ?)";
            $params[] = $tipoTienda;
        }

        $sql = $clauses ? 'AND ' . implode(' AND ', $clauses) : '';
        return [$sql, $params];
    }

    /**
     * Fragmento WHERE para filtro de vendedor.
     * Si $vendedor === '%' no genera filtro.
     */
    public static function vendedor(
        string $vendedor,
        string $alias,
        string $campo = 'DESC_VENDEDOR'
    ): array {
        if ($vendedor === '%') return ['', []];
        return ["AND {$alias}.{$campo} = ?", [$vendedor]];
    }

    /**
     * Fragmento WHERE para filtro de rubro.
     * Si $rubro === '%' no genera filtro.
     */
    public static function rubro(string $rubro, string $alias): array
    {
        if ($rubro === '%') return ['', []];
        return ["AND {$alias}.RUBRO = ?", [$rubro]];
    }

    /**
     * Combina todos los filtros en un único fragmento WHERE + params.
     *
     * @param array  $p             Claves opcionales: sucursal, grupo, tipo_tienda, vendedor, rubro, canal
     * @param string $alias         Alias de tabla principal
     * @param string $campoVendedor Nombre del campo vendedor (default DESC_VENDEDOR)
     * @param string $origen        Origen de datos
     * @param bool   $incluirVendedor  Incluir filtro vendedor en este alias
     * @param bool   $incluirRubro    Incluir filtro rubro en este alias
     * @param string $sucursalCol   Columna de sucursal (default NRO_SUCURS)
     * @param bool   $tieneCanal    La tabla tiene campo CANAL; si false usa fallback por NRO_SUCURS
     * @return array{0:string, 1:array} [$sqlFragment, $params]
     */
    public static function build(
        array $p,
        string $alias,
        string $campoVendedor = 'DESC_VENDEDOR',
        string $origen = 'argentina',
        bool $incluirVendedor = true,
        bool $incluirRubro = false,
        string $sucursalCol = 'NRO_SUCURS',
        bool $tieneCanal = true
    ): array {
        $sqls   = [];
        $params = [];

        // Sucursal / Grupo / TipoTienda
        [$s, $ps] = self::sucursal(
            $p['sucursal']    ?? null,
            $p['grupo']       ?? null,
            $p['tipo_tienda'] ?? null,
            $alias,
            $origen,
            $sucursalCol
        );
        if ($s) { $sqls[] = $s; $params = array_merge($params, $ps); }

        // Vendedor
        if ($incluirVendedor && isset($p['vendedor'])) {
            [$sv, $pv] = self::vendedor($p['vendedor'], $alias, $campoVendedor);
            if ($sv) { $sqls[] = $sv; $params = array_merge($params, $pv); }
        }

        // Rubro
        if ($incluirRubro && isset($p['rubro'])) {
            [$sr, $pr] = self::rubro($p['rubro'], $alias);
            if ($sr) { $sqls[] = $sr; $params = array_merge($params, $pr); }
        }

        // Solo activas: excluir sucursales con HABILITADO=0 en SUCURSALES_LAKERS
        if (!empty($p['solo_activas']) && empty($p['sucursal'])) {
            $sqls[] = "AND {$alias}.{$sucursalCol} IN (
                SELECT NRO_SUCURSAL
                FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS
                WHERE HABILITADO = 1)";
        }

        // Canal (solo Argentina; vacío = sin filtro)
        $canal = $p['canal'] ?? null;
        if (!empty($canal)) {
            if ($tieneCanal) {
                if ($canal === 'PROPIOS') {
                    $sqls[] = "AND {$alias}.CANAL = 'LOCALES PROPIOS'";
                } elseif ($canal === 'ECOMMERCE') {
                    $sqls[] = "AND {$alias}.CANAL = 'ECOMMERCE'";
                }
            } else {
                if ($canal === 'PROPIOS') {
                    $sqls[] = "AND {$alias}.{$sucursalCol} NOT IN (1, 9)";
                } elseif ($canal === 'ECOMMERCE') {
                    $sqls[] = "AND {$alias}.{$sucursalCol} IN (1, 9)";
                }
            }
        }

        return [implode(' ', $sqls), $params];
    }

    /**
     * Fragmento WHERE para restringir por una lista de sucursales (perfil GRUPO).
     * Si el array está vacío retorna una condición imposible ('AND 1=0') como
     * medida de seguridad — nunca debe devolver todos los registros.
     *
     * @param  int[]  $nroSucursales   IDs de sucursal permitidas
     * @param  string $alias           Alias de tabla en la query
     * @param  string $col             Columna de sucursal (default NRO_SUCURS)
     * @return array{0:string, 1:array} [$sqlFragment, $params]
     */
    public static function sucursalesGrupo(array $nroSucursales, string $alias, string $col = 'NRO_SUCURS'): array
    {
        if (empty($nroSucursales)) {
            return ['AND 1=0', []];
        }
        $placeholders = implode(',', array_fill(0, count($nroSucursales), '?'));
        return ["AND {$alias}.{$col} IN ({$placeholders})", array_values($nroSucursales)];
    }

    /**
     * Normaliza los filtros venidos de $_GET para uso en APIs.
     * Devuelve array limpio y seguro.
     */
    public static function fromRequest(array $get, string $origen = 'argentina'): array
    {
        $soloArg = ($origen === 'argentina');
        return [
            'sucursal'    => isset($get['sucursal']) && $get['sucursal'] !== ''
                             ? (int)$get['sucursal'] : null,
            'grupo'       => ($soloArg && isset($get['grupo']) && $get['grupo'] !== '')
                             ? $get['grupo'] : null,
            'tipo_tienda' => ($soloArg && isset($get['tipo_tienda']) && $get['tipo_tienda'] !== '')
                             ? $get['tipo_tienda'] : null,
            'vendedor'    => $get['vendedor'] ?? '%',
            'rubro'       => $get['rubro']    ?? '%',
            'canal'       => ($soloArg && isset($get['canal']) && $get['canal'] !== '')
                             ? $get['canal'] : null,
        ];
    }
}
