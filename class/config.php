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
 */
function getConfig(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $tipo = $_SESSION['tipo'] ?? 'LOCAL_PROPIO';

    switch ($tipo) {
        case 'FRANQUICIA':
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
