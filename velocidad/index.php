<?php
/**
 * /bi/velocidad/index.php
 * Tablero "Velocidad de Ventas" — réplica web del informe de Power BI.
 * Pestañas: Ranking Argentina · Ranking Uruguay · Ranking Negativo (50 con menos venta a 15 días).
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
// Es el mismo que tiene guardado el slicer "Fecha" del informe de Power BI.
$DESDE_DEFAULT = '2026-08-01';
$HASTA_DEFAULT = date('Y') . '-12-31';

$v = function (string $rel): int {
    $f = __DIR__ . '/' . $rel;
    return is_file($f) ? filemtime($f) : 0;
};
$h = function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
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
<body class="vv" data-mode="ar">
<div id="loading-bar"></div>

<!-- ══ BARRA SUPERIOR ═══════════════════════════════════════════ -->
<header class="vv-top">
    <div class="vv-top-in">
        <a class="vv-back" href="/bi/index.php" title="Volver al menú de BI" aria-label="Volver al menú de BI">
            <i class="bi bi-chevron-left"></i>
        </a>
        <div class="vv-brand">
            <span class="vv-brand-mark" aria-hidden="true"><i class="bi bi-speedometer2"></i></span>
            <div>
                <h1 class="vv-brand-title">Velocidad de Ventas</h1>
                <div class="vv-brand-sub"><i class="bi bi-arrow-repeat"></i> Datos al <span id="vv-updated">—</span></div>
            </div>
        </div>

        <nav class="vv-tabs" role="tablist" aria-label="Vistas del tablero">
            <button type="button" role="tab" class="vv-tab is-active" data-mode="ar" aria-selected="true">
                <span class="vv-tab-flag" aria-hidden="true">AR</span> Ranking Argentina
            </button>
            <button type="button" role="tab" class="vv-tab" data-mode="uy" aria-selected="false">
                <span class="vv-tab-flag" aria-hidden="true">UY</span> Ranking Uruguay
            </button>
            <button type="button" role="tab" class="vv-tab" data-mode="neg" aria-selected="false">
                <i class="bi bi-graph-down-arrow" aria-hidden="true"></i> Ranking Negativo
            </button>
        </nav>

        <div class="vv-logo" aria-label="XL Extra Large">
            <span class="vv-logo-xl">XL</span><span class="vv-logo-sub">EXTRA LARGE</span>
        </div>
    </div>
</header>

<div class="vv-shell">

    <!-- ══ FILTROS ═════════════════════════════════════════════ -->
    <section class="vv-filters" aria-label="Filtros">
        <div class="vv-field vv-field-dates">
            <label for="f-desde">Fecha de ingreso a locales</label>
            <div class="vv-dates">
                <input type="date" id="f-desde" value="<?= $h($DESDE_DEFAULT) ?>" data-default="<?= $h($DESDE_DEFAULT) ?>" aria-label="Desde">
                <span class="vv-dates-sep" aria-hidden="true">→</span>
                <input type="date" id="f-hasta" value="<?= $h($HASTA_DEFAULT) ?>" data-default="<?= $h($HASTA_DEFAULT) ?>" aria-label="Hasta">
            </div>
        </div>
        <div class="vv-field">
            <label for="f-linea">Línea</label>
            <input id="f-linea" list="dl-linea" placeholder="Todas" autocomplete="off">
            <datalist id="dl-linea"></datalist>
        </div>
        <div class="vv-field">
            <label for="f-rubro">Rubro</label>
            <select id="f-rubro"><option value="">Todos</option></select>
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
            <select id="f-color"><option value="">Todos</option></select>
        </div>
        <button type="button" class="vv-btn vv-btn-ghost" id="btn-clear" title="Volver a los filtros iniciales">
            <i class="bi bi-arrow-counterclockwise"></i><span>Limpiar</span>
        </button>
    </section>

    <div class="vv-error" id="vv-error" role="alert" hidden></div>

    <!-- ══ INDICADORES ═════════════════════════════════════════ -->
    <section class="vv-kpis" aria-label="Resumen de la selección">
        <article class="vv-kpi">
            <div class="vv-kpi-label">Modelos</div>
            <div class="vv-kpi-value" id="k-modelos">—</div>
            <div class="vv-kpi-foot" id="k-modelos-foot">&nbsp;</div>
        </article>
        <article class="vv-kpi">
            <div class="vv-kpi-label">Venta 15 días</div>
            <div class="vv-kpi-value" id="k-vta15">—</div>
            <div class="vv-kpi-foot" id="k-vta15-foot">&nbsp;</div>
        </article>
        <article class="vv-kpi">
            <div class="vv-kpi-label">Venta / ingreso 15 días</div>
            <div class="vv-kpi-value" id="k-pct15">—</div>
            <div class="vv-kpi-foot" id="k-pct15-foot">&nbsp;</div>
        </article>
        <article class="vv-kpi">
            <div class="vv-kpi-label">Días de stock</div>
            <div class="vv-kpi-value" id="k-dias">—</div>
            <div class="vv-kpi-foot">al ritmo de venta de 15 días</div>
        </article>
        <article class="vv-kpi vv-kpi-sem">
            <div class="vv-kpi-label">Semáforo <span class="vv-kpi-hint">· tocá un color para filtrar</span></div>
            <div class="vv-sembar" id="k-sembar" aria-hidden="true"></div>
            <div class="vv-semchips" id="k-semchips" role="group" aria-label="Filtrar por semáforo"></div>
        </article>
    </section>

    <!-- ══ CUERPO ══════════════════════════════════════════════ -->
    <main class="vv-main">

        <section class="vv-card vv-table-card">
            <header class="vv-card-head">
                <div>
                    <h2 class="vv-card-title" id="vv-title">Ranking ventas Argentina · 15 días</h2>
                    <div class="vv-card-sub" id="vv-caption"></div>
                </div>
                <div class="vv-card-tools">
                    <span class="vv-count" id="vv-count"></span>
                    <button type="button" class="vv-btn vv-btn-ghost vv-btn-sm" id="btn-csv" title="Descargar la tabla como CSV">
                        <i class="bi bi-download"></i><span>CSV</span>
                    </button>
                </div>
            </header>

            <div class="vv-podium" id="vv-podium" aria-label="Destacados"></div>

            <div class="vv-table-wrap" id="vv-table-wrap">
                <table class="vv-table" id="vv-table">
                    <thead></thead>
                    <tbody></tbody>
                </table>
                <div class="vv-overlay" id="vv-overlay" hidden>
                    <div class="vv-spinner"></div><span>Cargando…</span>
                </div>
            </div>

            <footer class="vv-legend" id="vv-legend" aria-label="Referencia del semáforo"></footer>
        </section>

        <aside class="vv-card vv-detail" id="vv-detail" aria-live="polite">
            <div class="vv-detail-img" id="vv-viz" role="button" tabindex="0" title="Ver detalle y ampliar la foto">
                <img id="vv-viz-img" class="vv-img" alt="" hidden>
                <span class="vv-detail-zoom" aria-hidden="true"><i class="bi bi-arrows-fullscreen"></i></span>
                <div class="vv-detail-empty" id="vv-viz-empty"><i class="bi bi-image"></i><span>Elegí un modelo de la tabla</span></div>
            </div>
            <div class="vv-detail-body">
                <div class="vv-detail-name" id="d-name">—</div>
                <div class="vv-detail-code" id="d-code"></div>
                <dl class="vv-detail-stats" id="d-stats"></dl>

                <div class="vv-stock" id="vv-stock">
                    <div class="vv-stock-head">
                        <span>Stock por sucursal</span>
                        <span class="vv-stock-total" id="vv-stock-total"></span>
                    </div>
                    <ul class="vv-stock-list" id="vv-stock-list"></ul>
                </div>
                <p class="vv-stock-na" id="vv-stock-na" hidden>El stock por sucursal está disponible solo para Argentina.</p>
            </div>
        </aside>

    </main>
</div>

<dialog class="vv-modal" id="vv-modal" aria-labelledby="vv-modal-title">
    <div class="vv-modal-in">
        <div class="vv-modal-viewer" id="vv-modal-viewer">
            <img id="vv-modal-img" class="vv-img" alt="" draggable="false">
            <div class="vv-modal-tools">
                <button type="button" data-zoom="out" title="Alejar"><i class="bi bi-zoom-out"></i></button>
                <span id="vv-modal-zl">100 %</span>
                <button type="button" data-zoom="in" title="Acercar"><i class="bi bi-zoom-in"></i></button>
                <button type="button" data-zoom="reset" title="Ajustar a la ventana"><i class="bi bi-aspect-ratio"></i></button>
            </div>
            <p class="vv-modal-hint">Rueda o doble click para ampliar · arrastrá para mover</p>
            <div class="vv-modal-thumbs" id="vv-modal-thumbs" hidden></div>
            <a class="vv-modal-shop" id="vv-modal-shop" target="_blank" rel="noopener" hidden><i class="bi bi-bag"></i> Ver en la tienda</a>
        </div>
        <div class="vv-modal-body vv-detail-body" id="vv-modal-body"></div>
        <button type="button" class="vv-modal-close" id="vv-modal-close" title="Cerrar (Esc)" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button>
    </div>
</dialog>

<script>
    window.VV_CONFIG = {
        base: '/bi/velocidad/ajax/',
        // Misma URL de foto que arma el informe de Power BI (tabla MODELOS, columna URL).
        imgBase: 'https://app.xl.com.ar/Imagenes/'
    };
</script>
<script src="/bi/velocidad/js/velocidad.js?v=<?= $v('js/velocidad.js') ?>"></script>
</body>
</html>
