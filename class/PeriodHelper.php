<?php
/**
 * PeriodHelper
 * Cálculo centralizado de períodos de comparación para el dashboard.
 * Fuente única de verdad — reemplaza la lógica duplicada en DashboardDB
 * y GlobalDashboardDB.
 *
 * Retorna siempre: [desde_actual, hasta_actual, desde_previo, hasta_previo]
 */
class PeriodHelper
{
    private static string $tz = 'America/Argentina/Buenos_Aires';

    /**
     * Calcula el par de rangos (actual + previo) para un tipo de período.
     *
     * @param  string $tipo ayer|7|30|90|180|mes_actual|mes_pasado|año_actual|año_pasado
     * @return array{0:string,1:string,2:string,3:string}
     */
    public static function calcularPeriodo(string $tipo): array
    {
        $tz   = new DateTimeZone(self::$tz);
        $hoy  = new DateTime('today', $tz);
        $ayer = (clone $hoy)->modify('-1 day');

        switch ($tipo) {
            case 'ayer':
                $da = $ayer->format('Y-m-d');
                $hp = (clone $ayer)->modify('-1 day')->format('Y-m-d');
                return [$da, $da, $hp, $hp];

            case '7':
                $da = (clone $ayer)->modify('-6 days')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                $dp = (clone $ayer)->modify('-13 days')->format('Y-m-d');
                $hp = (clone $ayer)->modify('-7 days')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case '30':
                $da = (clone $ayer)->modify('-29 days')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                $dp = (clone $ayer)->modify('-59 days')->format('Y-m-d');
                $hp = (clone $ayer)->modify('-30 days')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case '90':
                $da = (clone $ayer)->modify('-89 days')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                $dp = (clone $ayer)->modify('-179 days')->format('Y-m-d');
                $hp = (clone $ayer)->modify('-90 days')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case '180':
                $da = (clone $ayer)->modify('-179 days')->format('Y-m-d');
                $ha = $ayer->format('Y-m-d');
                $dp = (clone $ayer)->modify('-359 days')->format('Y-m-d');
                $hp = (clone $ayer)->modify('-180 days')->format('Y-m-d');
                return [$da, $ha, $dp, $hp];

            case 'mes_actual':
                $da   = (clone $hoy)->modify('first day of this month')->format('Y-m-d');
                $ha   = $ayer->format('Y-m-d');
                if ($ha < $da) {
                    $ha = $da;
                }
                $da_p = (clone $hoy)->modify('first day of this month')->modify('-1 year')->format('Y-m-d');
                $ha_p = (clone $ayer)->modify('-1 year')->format('Y-m-d');
                if ($ha_p < $da_p) {
                    $ha_p = $da_p;
                }
                return [$da, $ha, $da_p, $ha_p];

            case 'mes_pasado':
                $primero  = (clone $hoy)->modify('first day of last month');
                $ultimo   = (clone $hoy)->modify('last day of last month');
                $dias     = (int)$ultimo->format('d');
                $da       = $primero->format('Y-m-d');
                $ha       = $ultimo->format('Y-m-d');
                $prim_p   = (clone $primero)->modify('-1 year');
                $da_p     = $prim_p->format('Y-m-d');
                $ha_p     = (clone $prim_p)->modify('+' . ($dias - 1) . ' days')->format('Y-m-d');
                return [$da, $ha, $da_p, $ha_p];

            case 'año_actual':
                $da   = (clone $hoy)->modify('first day of january this year')->format('Y-m-d');
                $ha   = $ayer->format('Y-m-d');
                if ($ha < $da) {
                    $ha = $da;
                }
                $da_p = (clone $hoy)->modify('first day of january last year')->format('Y-m-d');
                $ha_p = (clone $ayer)->modify('-1 year')->format('Y-m-d');
                if ($ha_p < $da_p) {
                    $ha_p = $da_p;
                }
                return [$da, $ha, $da_p, $ha_p];

            case 'año_pasado':
                $da   = (clone $hoy)->modify('first day of january last year')->format('Y-m-d');
                $ha   = (clone $hoy)->modify('last day of december last year')->format('Y-m-d');
                $da_p = (clone $hoy)->modify('first day of january')->modify('-2 years')->format('Y-m-d');
                $ha_p = (clone $hoy)->modify('last day of december')->modify('-2 years')->format('Y-m-d');
                return [$da, $ha, $da_p, $ha_p];

            default:
                // 'custom' — el caller debe proveer las fechas directamente
                return [];
        }
    }

    /**
     * Resuelve el par de rangos desde los parámetros de $_GET.
     * Si periodo=custom usa desde/hasta y comp_mode.
     * Retorna [desde_act, hasta_act, desde_prev, hasta_prev].
     */
    public static function fromRequest(array $get): array
    {
        $periodo = $get['periodo'] ?? 'mes_actual';

        if ($periodo === 'custom') {
            $da = (isset($get['desde']) && $get['desde'] !== '') ? $get['desde'] : date('Y-m-01');
            $ha = (isset($get['hasta']) && $get['hasta'] !== '') ? $get['hasta'] : date('Y-m-d', strtotime('-1 day'));

            $compMode = $get['comp_mode'] ?? 'year_ago';
            if ($compMode === 'custom') {
                $da_p = $get['desde_comp'] ?? (new DateTime($da))->modify('-1 year')->format('Y-m-d');
                $ha_p = $get['hasta_comp'] ?? (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
            } else {
                $da_p = (new DateTime($da))->modify('-1 year')->format('Y-m-d');
                $ha_p = (new DateTime($ha))->modify('-1 year')->format('Y-m-d');
            }
            return [$da, $ha, $da_p, $ha_p];
        }

        return self::calcularPeriodo($periodo);
    }
}
