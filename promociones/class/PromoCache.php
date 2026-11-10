<?php
/**
 * PromoCache
 * Caché en disco (JSON) para las respuestas del dashboard de Promociones.
 *
 * BI_PROMOCIONES se carga por ETL, no cambia minuto a minuto: guardar el
 * resultado unos minutos evita repetir los mismos scans cada vez que un
 * director abre el tablero o cambia de pestaña y vuelve.
 *
 * La clave incluye el perfil (tipo + sucursales del GRUPO) para que un
 * usuario GRUPO nunca reciba datos cacheados de otro perfil.
 * Si el directorio no es escribible, simplemente no cachea.
 */
class PromoCache
{
    private static function dir(): string
    {
        return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'bi_promociones_cache';
    }

    /** Clave estable a partir de un nombre lógico + parámetros + perfil de sesión. */
    public static function key(string $name, array $params): string
    {
        unset($params['_fresh']);
        ksort($params);
        $perfil = [
            $_SESSION['tipo'] ?? '',
            $_SESSION['sucursalesGrupo'] ?? [],
        ];
        return $name . '_' . md5(json_encode([$params, $perfil]));
    }

    /**
     * Devuelve el valor cacheado o ejecuta $fn y lo guarda.
     * Con ?_fresh=1 (botón "Recargar pestaña") ignora la caché y la renueva.
     */
    public static function remember(string $key, int $ttl, callable $fn)
    {
        $file  = self::dir() . DIRECTORY_SEPARATOR . $key . '.json';
        $fresh = !empty($_GET['_fresh']);

        if (!$fresh && is_file($file) && (time() - @filemtime($file)) < $ttl) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if ($data !== null) return $data;
            }
        }

        $value = $fn();

        try {
            if (!is_dir(self::dir())) @mkdir(self::dir(), 0775, true);
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($value, JSON_UNESCAPED_UNICODE)) !== false) {
                @rename($tmp, $file);
            }
        } catch (Throwable $_) {}

        return $value;
    }
}
