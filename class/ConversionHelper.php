<?php
/**
 * ConversionHelper
 * Cálculo centralizado de la Tasa de Conversión (Tickets / Ingresos).
 * Fuente única de verdad — reemplaza la lógica duplicada en DashboardDB,
 * GlobalDashboardDB, CirculacionDB y CadenaDB.
 *
 * Solo es válida cuando existen datos reales de ambos indicadores
 * (tickets > 0 e ingresos > 0). En cualquier otro caso no hay dato.
 */
class ConversionHelper
{
    /**
     * @return float|null null cuando no hay dato válido (tickets o ingresos
     *                     son null, cero o negativos)
     */
    public static function rate(?float $tickets, ?float $ingresos): ?float
    {
        if ($tickets === null || $ingresos === null) return null;
        if ($tickets <= 0 || $ingresos <= 0) return null;
        return $tickets / $ingresos;
    }
}
