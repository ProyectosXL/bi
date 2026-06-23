<?php
/**
 * /bi/promociones/index.php
 * Dashboard Promociones — GERENCIA / SUPERVISIÓN / GRUPO
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['username'])) {
    header('Location: ../../sistemas/login.php');
    exit;
}
require_once __DIR__ . '/../class/config.php';
$tipoSesion = $_SESSION['tipo'] ?? '';
if (!in_array($tipoSesion, ['GERENCIA', 'SUPERVISION', 'GRUPO'], true)) {
    header('Location: ../');
    exit;
}
$isGrupo         = ($tipoSesion === 'GRUPO');
$sucursalesGrupo = $isGrupo ? ($_SESSION['sucursalesGrupo'] ?? []) : [];
if ($isGrupo) {
    $descLabel = $_SESSION['descLocal'] ?? 'GRUPO';
} else {
    $descLabel = $tipoSesion === 'GERENCIA' ? 'GERENCIA' : 'SUPERVISIÓN';
}
date_default_timezone_set('America/Argentina/Buenos_Aires');
$ultimaAct = date('d/m/Y H:i:s');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Promociones — <?= htmlspecialchars($descLabel) ?></title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">
    <link rel="stylesheet" href="/bi/css/base.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/base.css') ?>">
    <link rel="stylesheet" href="/bi/css/components.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/components.css') ?>">
    <link rel="stylesheet" href="/bi/promociones/css/promociones.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/promociones/css/promociones.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
</head>
<body<?= !$isGrupo ? ' class="has-origen"' : '' ?>>
<div class="dash-wrap">

    <!-- ══ TOPBAR ══════════════════════════════════════════════════════ -->
    <header class="topbar">
        <div class="logo-box">XL</div>
        <div class="topbar-info">
            <div class="topbar-title">DASHBOARD PROMOCIONES — <?= htmlspecialchars($descLabel) ?></div>
            <div class="topbar-sub">
                <span id="periodo-label">—</span> <span id="periodo-previo-label"></span>
            </div>
        </div>

        <?php if (!$isGrupo): ?>
        <div class="origen-toggle" id="origen-toggle">
            <button class="origen-btn active" data-origen="argentina">Argentina</button>
            <button class="origen-btn" data-origen="uruguay">Uruguay</button>
            <button class="origen-btn" data-origen="franquicias">Franquicias</button>
        </div>
        <?php endif; ?>

        <div class="moneda-toggle" id="moneda-toggle">
            <button class="moneda-btn active" data-moneda="ARS">ARS</button>
            <button class="moneda-btn" data-moneda="USD">USD</button>
        </div>
        <button class="moneda-tcc-label" id="moneda-tcc-label" title="Ver cotizaciones del período" hidden></button>

        <!-- Modal cotizaciones -->
        <div class="tcc-modal-overlay" id="tcc-modal-overlay" hidden>
            <div class="tcc-modal">
                <div class="tcc-modal-header">
                    <div>
                        <span class="tcc-modal-title">Cotizaciones USD — Período</span>
                        <div class="tcc-modal-subtitle">Último día disponible de cada mes (BCRA)</div>
                    </div>
                    <button class="tcc-modal-close" id="tcc-modal-close"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="tcc-modal-body" id="tcc-modal-body"></div>
            </div>
        </div>

        <div class="topbar-meta">
            Última actualización<br>
            <strong id="ultima-actualizacion"><?= $ultimaAct ?></strong>
        </div>
    </header>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════════════ -->
    <div class="toolbar">

        <label for="sel-sucursal">Sucursal</label>
        <select id="sel-sucursal">
            <option value="">Todas</option>
        </select>

        <label for="sel-banco">Banco</label>
        <select id="sel-banco">
            <option value="">Todos</option>
        </select>

        <label for="sel-promocion">Promoción</label>
        <select id="sel-promocion">
            <option value="">Todas</option>
        </select>

        <label for="sel-periodo">Período</label>
        <select id="sel-periodo">
            <option value="ayer">Ayer vs anteayer</option>
            <option value="7">Últimos 7 días vs 7 previos</option>
            <option value="30">Últimos 30 días vs 30 previos</option>
            <option value="90">Últimos 90 días vs 90 previos</option>
            <option value="180">Últimos 180 días vs 180 previos</option>
            <option value="mes_actual" selected>Mes actual vs mismo mes año anterior</option>
            <option value="mes_pasado">Mes pasado vs mismo mes año anterior</option>
            <option value="año_actual">Año actual vs año anterior</option>
            <option value="año_pasado">Año pasado vs año anterior</option>
            <option value="custom">Personalizado</option>
        </select>

        <div class="custom-dates" id="custom-dates">
            <label for="input-desde">Desde</label>
            <input type="date" id="input-desde" value="<?= date('Y-m-01') ?>">
            <label for="input-hasta">Hasta</label>
            <input type="date" id="input-hasta" value="<?= date('Y-m-d', strtotime('-1 day')) ?>">

            <div class="comp-mode-wrap">
                <label class="comp-mode-label">Comparar vs</label>
                <label class="comp-radio-label">
                    <input type="radio" name="comp-mode" id="comp-year" value="year_ago" checked>
                    Mismo período año anterior
                </label>
                <label class="comp-radio-label">
                    <input type="radio" name="comp-mode" id="comp-custom" value="custom">
                    Rango personalizado
                </label>
            </div>

            <div class="custom-comp-dates" id="custom-comp-dates">
                <label for="input-comp-desde">vs Desde</label>
                <input type="date" id="input-comp-desde" value="<?= date('Y-m-01', strtotime('-1 year')) ?>">
                <label for="input-comp-hasta">Hasta</label>
                <input type="date" id="input-comp-hasta" value="<?= date('Y-m-d', strtotime('-1 year')) ?>">
            </div>
        </div>

        <button id="btn-aplicar" class="btn-aplicar">
            <i class="bi bi-check2"></i> Aplicar
        </button>

        <?php if (!$isGrupo): ?>
        <label class="comp-radio-label" style="margin-left:8px;white-space:nowrap" id="wrap-solo-activas">
            <input type="checkbox" id="chk-solo-activas" checked>
            Solo activas
        </label>
        <?php endif; ?>
    </div>

    <!-- ══ NAVEGACIÓN DE PESTAÑAS ══════════════════════════════════════ -->
    <nav class="tab-nav" role="tablist">
        <button class="tab-btn active" id="tab-btn-resumen" role="tab" aria-controls="tab-resumen" aria-selected="true">
            <i class="bi bi-speedometer2"></i>&nbsp; Resumen
        </button>
        <button class="tab-btn" id="tab-btn-mensual" role="tab" aria-controls="tab-mensual" aria-selected="false">
            <i class="bi bi-graph-up-arrow"></i>&nbsp; Evolución Mensual
        </button>
        <button class="tab-btn" id="tab-btn-detalle" role="tab" aria-controls="tab-detalle" aria-selected="false">
            <i class="bi bi-table"></i>&nbsp; Detalle
        </button>
        <button class="tab-reload-btn" id="btn-reload-tab" title="Recargar pestaña">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </nav>

    <!-- ══ PESTAÑA: RESUMEN ════════════════════════════════════════════ -->
    <div id="tab-resumen" class="tab-pane active" role="tabpanel" aria-labelledby="tab-btn-resumen">
        <main class="dash-content">

            <div id="promo-load-error" class="promo-load-error" hidden></div>

            <!-- ── KPI CARDS (6 cards en una grilla unificada) ── -->
            <div class="promo-summary-row">

                <div class="promo-kpi-card highlight" id="kpi-fact-cpromo">
                    <div class="promo-kpi-label">Fact. con Promo</div>
                    <div class="promo-kpi-value">—</div>
                    <div class="promo-kpi-meta">
                        <span class="promo-kpi-var">—</span>
                        <span class="promo-kpi-prev">prev: —</span>
                    </div>
                </div>

                <div class="promo-kpi-card" id="kpi-tickets-cpromo">
                    <div class="promo-kpi-label">Tickets con Promo</div>
                    <div class="promo-kpi-value">—</div>
                    <div class="promo-kpi-meta">
                        <span class="promo-kpi-var">—</span>
                        <span class="promo-kpi-prev">prev: —</span>
                    </div>
                </div>

                <div class="promo-kpi-card highlight" id="kpi-pct-costo-total">
                    <div class="promo-kpi-label">% Costo / Fact. Total</div>
                    <div class="promo-kpi-value">—</div>
                    <div class="promo-kpi-meta">
                        <span class="promo-kpi-var">—</span>
                        <span class="promo-kpi-prev">prev: —</span>
                    </div>
                </div>

                <div class="promo-kpi-card" id="kpi-pct-promo-fac">
                    <div class="promo-kpi-label">% $ Promo / FAC</div>
                    <div class="promo-kpi-value">—</div>
                    <div class="promo-kpi-meta">
                        <span class="promo-kpi-var">—</span>
                        <span class="promo-kpi-prev">prev: —</span>
                    </div>
                </div>

                <div class="promo-kpi-card" id="kpi-mini-banc">
                    <div class="promo-kpi-label">% Costo Banco / FAC</div>
                    <div class="promo-kpi-value">—</div>
                    <div class="promo-kpi-meta">
                        <span class="promo-kpi-var">—</span>
                        <span class="promo-kpi-prev">prev: —</span>
                    </div>
                </div>

                <div class="promo-kpi-card" id="kpi-mini-ventas">
                    <div class="promo-kpi-label">% Costo Ventas / FAC</div>
                    <div class="promo-kpi-value">—</div>
                    <div class="promo-kpi-meta">
                        <span class="promo-kpi-var">—</span>
                        <span class="promo-kpi-prev">prev: —</span>
                    </div>
                </div>

            </div>

            <!-- ── CARDS POR BANCO ───────────────────────────────── -->
            <div class="promo-section-header">
                <i class="bi bi-credit-card-fill"></i>&nbsp; Por Banco
            </div>
            <div class="banco-cards-grid" id="banco-cards-wrap">
                <div class="promo-loading">Cargando…</div>
            </div>

            <!-- ── DONUTS ────────────────────────────────────────── -->
            <div class="promo-section-header">
                <i class="bi bi-pie-chart-fill"></i>&nbsp; Distribución
            </div>
            <div class="promo-donuts-row">

                <div class="promo-donut-card">
                    <div class="promo-donut-title">
                        <span><i class="bi bi-building"></i>&nbsp; Desglose por Facturación</span>
                        <button class="promo-expand-btn donut-expand-btn" data-donut="fact" title="Ampliar"><i class="bi bi-arrows-angle-expand"></i></button>
                    </div>
                    <div class="promo-donut-wrap">
                        <div class="promo-donut-canvas-wrap">
                            <canvas id="donut-fact-canvas"></canvas>
                        </div>
                        <div class="promo-donut-legend" id="donut-fact-legend"></div>
                    </div>
                </div>

                <div class="promo-donut-card">
                    <div class="promo-donut-title">
                        <span><i class="bi bi-tag-fill"></i>&nbsp; Desglose por Promociones</span>
                        <button class="promo-expand-btn donut-expand-btn" data-donut="promo" title="Ampliar"><i class="bi bi-arrows-angle-expand"></i></button>
                    </div>
                    <div class="promo-donut-wrap">
                        <div class="promo-donut-canvas-wrap">
                            <canvas id="donut-promo-canvas"></canvas>
                        </div>
                        <div class="promo-donut-legend" id="donut-promo-legend"></div>
                    </div>
                </div>

                <div class="promo-donut-card">
                    <div class="promo-donut-title">
                        <span><i class="bi bi-bank2"></i>&nbsp; Desglose por Banco</span>
                        <button class="promo-expand-btn donut-expand-btn" data-donut="banco" title="Ampliar"><i class="bi bi-arrows-angle-expand"></i></button>
                    </div>
                    <div class="promo-donut-wrap">
                        <div class="promo-donut-canvas-wrap">
                            <canvas id="donut-banco-canvas"></canvas>
                        </div>
                        <div class="promo-donut-legend" id="donut-banco-legend"></div>
                    </div>
                </div>

            </div>

        </main>
    </div>
    <!-- /tab-resumen -->

    <!-- ══ PESTAÑA: EVOLUCIÓN MENSUAL ═════════════════════════════════ -->
    <div id="tab-mensual" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-mensual">
        <main class="dash-content">

            <!-- Tabla anual expandible -->
            <div class="promo-section-header">
                <i class="bi bi-table"></i>&nbsp; Evolución por Año / Mes
                <button class="btn-export-excel" id="btn-export-mensual">
                    <i class="bi bi-file-earmark-excel"></i> Excel
                </button>
            </div>
            <div class="promo-tabla-mensual-wrap" id="mensual-tabla-wrap">
                <div class="promo-loading">Cargando…</div>
            </div>

            <!-- Gráfico evolución % Costo / FAC -->
            <div class="promo-chart-card">
                <div class="promo-chart-header">
                    <span class="promo-chart-title">Evolución % Costo / Fact. Total — Multi-anual</span>
                    <button class="promo-expand-btn" id="btn-expand-evolucion" title="Ampliar">
                        <i class="bi bi-arrows-angle-expand"></i>
                    </button>
                </div>
                <div class="mensual-year-filter" id="mensual-year-filter"></div>
                <div class="promo-chart-canvas-wrap" id="mensual-chart-wrap">
                    <canvas id="mensual-chart-canvas"></canvas>
                </div>
            </div>

        </main>
    </div>
    <!-- /tab-mensual -->

    <!-- ══ PESTAÑA: DETALLE ════════════════════════════════════════════ -->
    <div id="tab-detalle" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-detalle">
        <main class="dash-content">

            <!-- Tabla por Sucursal -->
            <div class="promo-section-header">
                <i class="bi bi-building"></i>&nbsp; Detalle por Sucursal
                <button class="btn-export-excel" id="btn-export-suc">
                    <i class="bi bi-file-earmark-excel"></i> Excel
                </button>
            </div>
            <div class="promo-detalle-wrap" id="detalle-suc-wrap">
                <div class="promo-loading">Cargando…</div>
            </div>

            <!-- Tabla por Promoción -->
            <div class="promo-section-header" style="margin-top:24px">
                <i class="bi bi-tag-fill"></i>&nbsp; Detalle por Promoción
                <button class="btn-export-excel" id="btn-export-prom">
                    <i class="bi bi-file-earmark-excel"></i> Excel
                </button>
            </div>
            <div class="promo-detalle-wrap" id="detalle-prom-wrap">
                <div class="promo-loading">Cargando…</div>
            </div>

        </main>
    </div>
    <!-- /tab-detalle -->

    <!-- ══ MODAL DONUT (ampliar gráfico) ════════════════════════════════ -->
    <div id="modal-donut" class="promo-modal-overlay" hidden>
        <div class="promo-modal promo-modal-donut">
            <div class="promo-modal-header">
                <span class="promo-modal-title" id="modal-donut-title">—</span>
                <button class="promo-modal-close" id="modal-donut-close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="promo-modal-body">
                <div class="donut-modal-inner">
                    <div class="donut-modal-canvas-wrap">
                        <canvas id="donut-modal-canvas"></canvas>
                    </div>
                    <div class="promo-donut-legend donut-modal-legend" id="donut-modal-legend"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ MODAL EVOLUCIÓN (ampliar gráfico) ══════════════════════════ -->
    <div id="modal-evolucion" class="promo-modal-overlay" hidden>
        <div class="promo-modal">
            <div class="promo-modal-header">
                <span class="promo-modal-title">Evolución % Costo / Fact. Total — Multi-anual</span>
                <button class="promo-modal-close" id="modal-evolucion-close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="promo-modal-body">
                <div class="mensual-year-filter" id="mensual-year-filter-modal"></div>
                <div style="position:relative;height:460px">
                    <canvas id="mensual-chart-modal-canvas"></canvas>
                </div>
            </div>
        </div>
    </div>

</div><!-- /dash-wrap -->

<!-- SheetJS (Excel export) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<?php
$jsFiles = [
    '/bi/promociones/components/ExcelExporter.js',
    '/bi/promociones/js/promociones.js',
    '/bi/promociones/js/mensual.js',
    '/bi/promociones/js/detalle.js',
];
foreach ($jsFiles as $f):
    $v = @filemtime($_SERVER['DOCUMENT_ROOT'] . $f) ?: 1;
?>
<script src="<?= $f ?>?v=<?= $v ?>"></script>
<?php endforeach; ?>

<script>
window.BI_CONFIG = {
    isGrupo:         <?= $isGrupo ? 'true' : 'false' ?>,
    sucursalesGrupo: <?= json_encode($sucursalesGrupo) ?>
};
</script>

<script>
/**
 * Orquestador del dashboard de Promociones.
 */
(function () {

    const TABS = [
        { btn: 'tab-btn-resumen',  pane: 'tab-resumen',  name: 'resumen'  },
        { btn: 'tab-btn-mensual',  pane: 'tab-mensual',  name: 'mensual'  },
        { btn: 'tab-btn-detalle',  pane: 'tab-detalle',  name: 'detalle'  },
    ].filter(t => document.getElementById(t.btn) && document.getElementById(t.pane));

    const loaded = { resumen: false, mensual: false, detalle: false };

    const SPINNER_MSGS = {
        resumen : 'Cargando Resumen…',
        mensual : 'Cargando Evolución Mensual…',
        detalle : 'Cargando Detalle…',
    };

    const loaders = {
        resumen : () => Promociones.loadAll(),
        mensual : () => PromoMensual.load(),
        detalle : () => Promise.all([PromoDetalle.loadSucursales(), PromoDetalle.loadPromociones()]),
    };

    function loadTab(name) {
        if (loaded[name]) return Promise.resolve();
        loaded[name] = true;
        Promociones.setLoading(true, SPINNER_MSGS[name] ?? 'Cargando…');
        return (loaders[name]?.() ?? Promise.resolve())
            .finally(() => Promociones.setLoading(false));
    }

    function activateTab(paneId) {
        TABS.forEach(t => {
            const active = t.pane === paneId;
            document.getElementById(t.btn).classList.toggle('active', active);
            document.getElementById(t.pane).classList.toggle('active', active);
            document.getElementById(t.btn).setAttribute('aria-selected', active ? 'true' : 'false');
        });
        const tab = TABS.find(t => t.pane === paneId);
        if (tab) loadTab(tab.name);
    }

    TABS.forEach(t => {
        document.getElementById(t.btn).addEventListener('click', () => activateTab(t.pane));
    });

    // Origen toggle
    document.querySelectorAll('.origen-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.classList.contains('active')) return;
            document.querySelectorAll('.origen-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            Object.keys(loaded).forEach(k => loaded[k] = false);
            Promociones.setLoading(true, 'Cambiando entorno…');
            Promociones.loadFilters()
                .then(() => {
                    // Reset again: handles race where initial load's .then() fired during loadFilters
                    Object.keys(loaded).forEach(k => loaded[k] = false);
                    const activePane = document.querySelector('.tab-pane.active');
                    const tab = TABS.find(t => t.pane === activePane?.id) ?? TABS[0];
                    return loadTab(tab.name);
                })
                .catch(e => {
                    console.error('[Promociones] Origen toggle error:', e);
                    Promociones.setLoading(false);
                });
        });
    });

    // Período custom
    const selPeriodo  = document.getElementById('sel-periodo');
    const customDates = document.getElementById('custom-dates');
    const compCustom  = document.getElementById('comp-custom');
    const customComp  = document.getElementById('custom-comp-dates');

    selPeriodo.addEventListener('change', () => {
        customDates.classList.toggle('visible', selPeriodo.value === 'custom');
    });
    [document.getElementById('comp-year'), compCustom].forEach(r => {
        r?.addEventListener('change', () => {
            customComp.classList.toggle('visible', compCustom.checked);
        });
    });

    // Aplicar filtros
    document.getElementById('btn-aplicar').addEventListener('click', () => {
        Object.keys(loaded).forEach(k => loaded[k] = false);
        const activePane = document.querySelector('.tab-pane.active');
        const tab = TABS.find(t => t.pane === activePane?.id) ?? TABS[0];
        loadTab(tab.name);
    });

    // Reload pestaña activa
    const btnReload = document.getElementById('btn-reload-tab');
    btnReload.addEventListener('click', () => {
        const activePane = document.querySelector('.tab-pane.active');
        const tab = TABS.find(t => t.pane === activePane?.id) ?? TABS[0];
        btnReload.classList.add('spinning');
        loaded[tab.name] = false;
        loadTab(tab.name).finally(() => btnReload.classList.remove('spinning'));
    });

    // Solo activas
    const chkSoloActivas = document.getElementById('chk-solo-activas');
    if (chkSoloActivas) {
        chkSoloActivas.addEventListener('change', () => {
            Object.keys(loaded).forEach(k => loaded[k] = false);
            const activePane = document.querySelector('.tab-pane.active');
            const tab = TABS.find(t => t.pane === activePane?.id) ?? TABS[0];
            loadTab(tab.name);
        });
    }

    // Inicializar módulos
    Promociones.initMonedaToggle();
    Promociones.initDonutModals();
    PromoMensual.init();

    // Carga inicial: filtros + Resumen automático (igual que el tablero Global)
    // Mensual y Detalle cargan al seleccionar la pestaña.
    Promociones.loadFilters()
        .then(() => loadTab('resumen'))
        .catch(err => console.error('[Promociones] Error en carga inicial:', err));

})();
</script>
</body>
</html>
