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

    $tipo = $_SESSION['tipo'] ?? 'LOCAL_PROPIO';

    switch ($tipo) {
        case 'FRANQUICIA':
        case 'GRUPO':
            return [
                'db'              => 'power_franquicias',
                'campo_vendedor'  => 'DESC_VENDEDOR',
                'tabla_objetivos' => '[SERVIDORTESTING].dbXLSales.DBO.BI_OBJETIVOS_FRANQUICIAS',
                'features'        => ['grupos' => false],
            ];

        case 'LOCAL_PROPIO_UY':
            return [
                'db'              => 'power_uy',
                'campo_vendedor'  => 'DESC_VENDEDOR',
                'tabla_objetivos' => '[SERVIDORTESTING].dbXLSales.DBO.BI_OBJETIVOS_SUCURSALES',
                'features'        => ['grupos' => false],
            ];

        case 'GERENCIA':
        case 'SUPERVISION':
            return [
                'db'              => 'power',       // default; puede sobreescribirse por origen
                'campo_vendedor'  => 'DESC_VENDEDOR',
                'tabla_objetivos' => '[SERVIDORTESTING].dbXLSales.DBO.BI_OBJETIVOS_SUCURSALES',
                'features'        => ['grupos' => false],
                'modo'            => 'GLOBAL',
                'multi_origen'    => true,
                'origenes'        => [
                    'argentina'   => 'power',
                    'uruguay'     => 'power_uy',
                    'franquicias' => 'power_franquicias',
                ],
                'tablas_objetivos' => [
                    'argentina'   => '[SERVIDORTESTING].dbXLSales.DBO.BI_OBJETIVOS_SUCURSALES',
                    'uruguay'     => '[SERVIDORTESTING].dbXLSales.DBO.BI_OBJETIVOS_SUCURSALES',
                    'franquicias' => '[SERVIDORTESTING].dbXLSales.DBO.BI_OBJETIVOS_FRANQUICIAS',
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
                'tabla_objetivos' => '[SERVIDORTESTING].dbXLSales.DBO.BI_OBJETIVOS_SUCURSALES',
                'features'        => ['grupos' => true],
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
            'tabla_objetivos' => '[SERVIDORTESTING].dbXLSales.DBO.BI_OBJETIVOS_SUCURSALES',
        ],
        'uruguay'     => [
            'db'              => 'power_uy',
            'campo_vendedor'  => 'DESC_VENDEDOR',
            'tabla_objetivos' => '[SERVIDORTESTING].dbXLSales.DBO.BI_OBJETIVOS_SUCURSALES',
        ],
        'franquicias' => [
            'db'              => 'power_franquicias',
            'campo_vendedor'  => 'DESC_VENDEDOR',
            'tabla_objetivos' => '[SERVIDORTESTING].dbXLSales.DBO.BI_OBJETIVOS_FRANQUICIAS',
        ],
    ];
    return $map[$origen] ?? $map['argentina'];
}
