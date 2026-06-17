<?php
class Pais
{
    private static array $validos = ['AR', 'UY'];

    public static function resolver(): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $raw  = strtoupper(trim($_GET['pais'] ?? $_SESSION['logistica_pais'] ?? 'AR'));
        $pais = in_array($raw, self::$validos, true) ? $raw : 'AR';
        $_SESSION['logistica_pais'] = $pais;
        return $pais;
    }
}
