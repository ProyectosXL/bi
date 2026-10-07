<?php
/**
 * config.php
 * Configuración central por tipo de sucursal.
 *
 * Consume $_SESSION['tipo'] para devolver la configuración adecuada.
 * Tipos soportados:
 *   LOCAL_PROPIO     → power           / DESC_VENDEDOR / grupos: true
 *   LOCAL_PROPIO_UY  → power_uy        / DESC_VENDEDOR / grupos: true
 *   FRANQUICIA       → power_franquicias / COD_VENDED  / grupos: false
 *   GERENCIA         → modo GLOBAL / multi-origen
 *   SUPERVISION      → modo GLOBAL / multi-origen
 */
function getConfig(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $tipo = strtoupper(trim($_SESSION['tipo'] ?? 'LOCAL_PROPIO'));

    if (strpos($tipo, 'FRANQUICIA') !== false || $tipo === 'GRUPO') {
        return [
            'db'              => 'power_franquicias',
            'campo_vendedor'  => 'DESC_VENDEDOR',
            'tabla_objetivos' => 'dbo.BI_OBJETIVOS_FRANQUICIAS',
            // stock: BI_STOCK_FRANQUICIAS tiene NRO_SUCURSAL, ARTICULO y PRECIO,
            // así que permite aislar por local y valorizar.
            'features'        => ['grupos' => false, 'stock' => true],
        ];
    }

    switch ($tipo) {

        case 'LOCAL_PROPIO_UY':
            return [
                'db'              => 'power_uy',
                'campo_vendedor'  => 'DESC_VENDEDOR',
                // Cross-DB: power_uy (POWER_BI_CONTROL_URUGUAY) y power (POWER_BI_CONTROL) están en el mismo servidor XL-APPS
                'tabla_objetivos' => 'POWER_BI_CONTROL.dbo.BI_OBJETIVOS_SUCURSALES',
                'features'        => ['grupos' => false, 'stock' => false],
            ];

        case 'GERENCIA':
        case 'SUPERVISION':
            return [
                'db'              => 'power',       // default; puede sobreescribirse por origen
                'campo_vendedor'  => 'DESC_VENDEDOR',
                'tabla_objetivos' => 'dbo.BI_OBJETIVOS_SUCURSALES',
                'features'        => ['grupos' => false, 'stock' => false],
                'modo'            => 'GLOBAL',
                'multi_origen'    => true,
                'origenes'        => [
                    'argentina'   => 'power',
                    'uruguay'     => 'power_uy',
                    'franquicias' => 'power_franquicias',
                ],
                'tablas_objetivos' => [
                    'argentina'   => 'dbo.BI_OBJETIVOS_SUCURSALES',
                    // Cross-DB: power_uy (POWER_BI_CONTROL_URUGUAY) y power (POWER_BI_CONTROL) en el mismo servidor XL-APPS
                    'uruguay'     => 'POWER_BI_CONTROL.dbo.BI_OBJETIVOS_SUCURSALES',
                    'franquicias' => 'dbo.BI_OBJETIVOS_FRANQUICIAS',
                ],
                'filtros'         => [
                    'grupo'       => true,     // solo Argentina
                    'tipo_tienda' => true,     // solo Argentina
                ],
            ];

        case 'LOCAL_PROPIO':
        default:
            return [
                'db'              => 'power',
                'campo_vendedor'  => 'DESC_VENDEDOR',
                'tabla_objetivos' => 'dbo.BI_OBJETIVOS_SUCURSALES',
                // stock: BI_STOCK_LOCALES es una vista sin NRO_SUCURSAL ni PRECIO,
                // no permite aislar por local ni valorizar. Pendiente emparejar el esquema.
                'features'        => ['grupos' => true, 'stock' => false],
            ];
    }
}

/**
 * Devuelve true si el usuario en sesión tiene perfil global (GERENCIA o SUPERVISION).
 */
function isGlobalMode(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION'], true);
}

/**
 * Resuelve la base de datos y tabla de objetivos para un origen dado.
 * $origen: 'argentina' | 'uruguay' | 'franquicias'
 */
function getConfigForOrigen(string $origen): array
{
    $map = [
        'argentina'   => [
            'db'              => 'power',
            'campo_vendedor'  => 'DESC_VENDEDOR',
            'tabla_objetivos' => 'dbo.BI_OBJETIVOS_SUCURSALES',
        ],
        'uruguay'     => [
            'db'              => 'power_uy',
            'campo_vendedor'  => 'DESC_VENDEDOR',
            // Cross-DB: power_uy (POWER_BI_CONTROL_URUGUAY) y power (POWER_BI_CONTROL) en el mismo servidor XL-APPS
            'tabla_objetivos' => 'POWER_BI_CONTROL.dbo.BI_OBJETIVOS_SUCURSALES',
        ],
        'franquicias' => [
            'db'              => 'power_franquicias',
            'campo_vendedor'  => 'DESC_VENDEDOR',
            'tabla_objetivos' => 'dbo.BI_OBJETIVOS_FRANQUICIAS',
        ],
    ];
    return $map[$origen] ?? $map['argentina'];
}
