<?php
/**
 * /bi/velocidad/index.php
 * Tablero "Velocidad de Ventas" — réplica web del informe de Power BI.
 * Pestañas: Ranking Argentina · Ranking Uruguay · Ranking Negativo (50 inferiores).
 * Extra Large (XL)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['username']) && empty($_SESSION['fp_auth_user']['username'])) {
    header('Location: /bi/sistemas/login.php');
    exit;
}
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Rango de fechas inicial (filtro sobre la fecha de INGRESO a locales).
// Es el mismo que tiene guardado el informe de Power BI; se puede cambiar desde la pantalla.
$DESDE_DEFAULT = '2026-08-01';
$HASTA_DEFAULT = date('Y') . '-12-31';

$v = function (string $rel): int {
    $f = __DIR__ . '/' . $rel;
    return is_file($f) ? filemtime($f) : 0;
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Velocidad de Ventas — XL Extra Large</title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">
    <link rel="stylesheet" href="/bi/css/base.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/bi/velocidad/css/velocidad.css?v=<?= $v('css/velocidad.css') ?>">
</head>
<body class="vv" data-theme="teal">

<div class="vv-shell">

    <!-- ══ CABECERA ═══════════════════════════════════════════════ -->
    <header class="vv-head">
        <div class="vv-brand">
            <i class="bi bi-graph-up-arrow vv-brand-icon"></i>
            <div>
                <div class="vv-brand-title">VELOCIDAD DE VENTAS</div>
                <div class="vv-brand-sub">Última consulta: <strong id="vv-updated">—</strong></div>
            </div>
        </div>

        <h1 class="vv-title" id="vv-title">RANKING VENTAS ARGENTINA (15 DÍAS)</h1>

        <div class="vv-field vv-linea">
            <label for="f-linea">Línea</label>
            <input id="f-linea" list="dl-linea" placeholder="Todas" autocomplete="off">
            <datalist id="dl-linea"></datalist>
        </div>

        <a class="vv-logo" href="/bi/index.php" title="Volver al menú principal de BI">
            <span class="vv-logo-xl">XL</span>
            <span class="vv-logo-sub">EXTRA LARGE</span>
        </a>

        <div class="vv-field vv-fecha">
            <label for="f-desde">Fecha de ingreso</label>
            <div class="vv-dates">
                <input type="date" id="f-desde" value="<?= htmlspecialchars($DESDE_DEFAULT) ?>" data-default="<?= htmlspecialchars($DESDE_DEFAULT) ?>">
                <input type="date" id="f-hasta" value="<?= htmlspecialchars($HASTA_DEFAULT) ?>" data-default="<?= htmlspecialchars($HASTA_DEFAULT) ?>">
            </div>
        </div>
    </header>

    <!-- ══ PESTAÑAS ═══════════════════════════════════════════════ -->
    <nav class="vv-tabs" role="tablist" aria-label="Vistas del tablero">
        <button type="button" role="tab" class="vv-tab is-active" data-mode="ar" aria-selected="true">Ranking Argentina</button>
        <button type="button" role="tab" class="vv-tab" data-mode="uy" aria-selected="false">Ranking Uruguay</button>
        <button type="button" role="tab" class="vv-tab" data-mode="neg" aria-selected="false">Ranking Negativo</button>
    </nav>

    <div class="vv-error" id="vv-error" role="alert" hidden></div>

    <!-- ══ CUERPO ═════════════════════════════════════════════════ -->
    <main class="vv-main">

        <section class="vv-content">
            <div class="vv-card vv-table-card">
                <div class="vv-caption">
                    <span id="vv-caption">Ranking ventas (15 días)</span>
                    <span class="vv-count" id="vv-count"></span>
                </div>
                <div class="vv-table-wrap" id="vv-table-wrap">
                    <table class="vv-table" id="vv-table">
                        <thead></thead>
                        <tbody></tbody>
                    </table>
                    <div class="vv-overlay" id="vv-overlay" hidden>
                        <div class="vv-spinner"></div><span>Cargando…</span>
                    </div>
                </div>
            </div>

            <div class="vv-lower">
                <section class="vv-card vv-stock-card" id="vv-stock-card">
                    <div class="vv-card-title">
                        Stock Locales <span class="vv-card-sub" id="vv-stock-sub"></span>
                    </div>
                    <div class="vv-stock-wrap">
                        <table class="vv-table vv-table-stock">
                            <thead><tr><th>Sucursal</th><th class="num">Stock por local</th></tr></thead>
                            <tbody id="vv-stock-body">
                                <tr><td colspan="2" class="vv-empty">Elegí un modelo de la tabla para ver su stock por sucursal.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="vv-viz">
                    <div class="vv-viz-title">VISUALIZADOR</div>
                    <div class="vv-viz-box">
                        <img id="vv-viz-img" alt="Foto del modelo seleccionado" hidden>
                        <div class="vv-viz-empty" id="vv-viz-empty">Sin modelo seleccionado</div>
                    </div>
                    <div class="vv-viz-label" id="vv-viz-label"></div>
                    <ul class="vv-legend" id="vv-legend"></ul>
                </section>
            </div>
        </section>

        <aside class="vv-side">
            <div class="vv-field">
                <label for="f-rubro">Rubro</label>
                <select id="f-rubro"><option value="">Todas</option></select>
            </div>
            <div class="vv-field">
                <label for="f-categoria">Categoría</label>
                <select id="f-categoria"><option value="">Todas</option></select>
            </div>
            <div class="vv-field">
                <label for="f-modelo">Modelo</label>
                <input id="f-modelo" list="dl-modelo" placeholder="Todos" autocomplete="off">
                <datalist id="dl-modelo"></datalist>
            </div>
            <div class="vv-field">
                <label for="f-color">Color</label>
                <select id="f-color"><option value="">Todas</option></select>
            </div>
            <button type="button" class="vv-btn" id="btn-clear"><i class="bi bi-x-circle"></i> Limpiar filtros</button>

            <div class="vv-thumbs" id="vv-thumbs" aria-label="Primeros modelos del ranking"></div>
        </aside>

    </main>
</div>

<script>
    window.VV_CONFIG = {
        base: '/bi/velocidad/ajax/'
    };
</script>
<script src="/bi/velocidad/js/velocidad.js?v=<?= $v('js/velocidad.js') ?>"></script>
</body>
</html>
