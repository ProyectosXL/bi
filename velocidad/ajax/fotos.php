<?php
/**
 * /bi/velocidad/ajax/fotos.php
 * GET: modelo=<COD_ARTICU>  -> fotos en alta del modelo, tomadas del catálogo público de la tienda (VTEX, cuenta xlshop).
 *
 * La foto de app.xl.com.ar/Imagenes es de 300x300; la tienda tiene la original (≈800x1000) y otros ángulos.
 * El COD_ARTICU coincide con el RefId del SKU en VTEX. La API del catálogo no tiene CORS: por eso pasa por acá.
 * Si el modelo no está publicado o no hay salida a internet, devuelve una lista vacía y el tablero
 * sigue usando la foto chica de siempre.
 */
require __DIR__ . '/_bootstrap.php';

const VV_VTEX_SEARCH = 'https://www.xlshop.com.ar/api/catalog_system/pub/products/search?fq=alternateIds_RefId:';
const VV_FOTOS_TTL   = 43200;   // 12 h (también se cachean los "no encontrado")

$modelo = preg_replace('/[^A-Za-z0-9_-]/', '', vv_txt($_GET['modelo'] ?? '', 60));
if ($modelo === '') {
    vv_json(['ok' => false, 'error' => 'Falta el modelo'], 400);
}

$cacheDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vv_fotos';
$cacheFile = $cacheDir . DIRECTORY_SEPARATOR . strtoupper($modelo) . '.json';
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < VV_FOTOS_TTL) {
    $cached = json_decode((string)file_get_contents($cacheFile), true);
    if (is_array($cached)) {
        vv_json(['ok' => true, 'data' => $cached]);
    }
}

function vv_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
    }
    $ctx  = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

$body = vv_http_get(VV_VTEX_SEARCH . rawurlencode($modelo));
if ($body === null) {
    // Sin respuesta de la tienda: no se cachea, para reintentar la próxima vez.
    vv_json(['ok' => true, 'data' => ['fotos' => [], 'link' => null]]);
}

$fotos = [];
$link  = null;
$prods = json_decode($body, true);
if (is_array($prods) && !empty($prods[0]['items'])) {
    $link  = $prods[0]['link'] ?? null;
    $items = $prods[0]['items'];
    // El SKU cuyo RefId es el modelo pedido (un producto puede tener varios colores).
    $item = $items[0];
    foreach ($items as $it) {
        foreach (($it['referenceId'] ?? []) as $ref) {
            if (strcasecmp((string)($ref['Value'] ?? ''), $modelo) === 0) {
                $item = $it;
                break 2;
            }
        }
    }
    foreach (($item['images'] ?? []) as $img) {
        $u = (string)($img['imageUrl'] ?? '');
        if (preg_match('#^https://[a-z0-9.-]+\.vteximg\.com\.br/arquivos/ids/\d+/#i', $u)) {
            $fotos[] = $u;
        }
    }
}

$data = ['fotos' => $fotos, 'link' => $link];
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
@file_put_contents($cacheFile, json_encode($data));
vv_json(['ok' => true, 'data' => $data]);
