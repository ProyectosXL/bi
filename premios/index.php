<?php
/**
 * /bi/premios/index.php
 * Dashboard Premios Comercial.
 * Migración del tablero de Power BI "Premios Comercial".
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Última actualización REAL de los datos de origen (no la hora del servidor web): se
// consulta cuándo se modificó por última vez la tabla de ventas en SQL Server. A diferencia
// de sales/global (SP diario), acá el SP corre 1 vez al mes — ver `PremiosDB::esDesactualizado()`.
require_once __DIR__ . '/class/PremiosDB.php';
$isOutdated = false;
$ultimaAct  = 'No disponible';
try {
    $hoy = date('Y-m-d');
    $dbEstado = new PremiosDB($hoy, $hoy, $hoy, $hoy);
    $ultimaActRaw = $dbEstado->getUltimaActualizacion();
    if ($ultimaActRaw) {
        $dtUpdate = new DateTime($ultimaActRaw);
        $ultimaAct = $dtUpdate->format('d/m/Y H:i:s');

        $isOutdated = PremiosDB::esDesactualizado($dtUpdate);
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Premios Comercial</title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">
    <link rel="stylesheet" href="/bi/css/base.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/base.css') ?>">
    <link rel="stylesheet" href="/bi/css/components.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/components.css') ?>">
    <link rel="stylesheet" href="/bi/premios/css/premios.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/premios/css/premios.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
<div class="dash-wrap">

    <!-- ══ TOPBAR ══════════════════════════════════════════════════════ -->
    <header class="topbar">
        <div class="logo-box">XL</div>
        <div class="topbar-info">
            <div class="topbar-title">PREMIOS COMERCIAL — <span id="topbar-vista-actual">PREMIOS SUPERVISORAS</span></div>
            <div class="topbar-sub">
                <span id="periodo-label">—</span><br>
                <span id="periodo-previo-label">—</span>
            </div>
        </div>
        <div class="topbar-right">
            <button class="btn-topbar-accion" id="btn-orden-supervisoras" style="display:none;" title="Reordenar y mostrar/ocultar supervisoras">
                <i class="bi bi-arrow-down-up"></i> Configuración de supervisoras
            </button>
            <div class="topbar-meta">
                Última actualización<br>
                <strong id="ultima-actualizacion"><?= $ultimaAct ?></strong>
                <span class="badge-outdated" id="badge-desactualizado" title="Los datos tienen más de un día de retraso" <?= !$isOutdated ? 'style="display: none;"' : '' ?>>
                    <i class="bi bi-exclamation-triangle-fill"></i> DESACTUALIZADO
                </span>
            </div>
        </div>
    </header>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════════════ -->
    <div class="toolbar">

        <label for="sel-supervisora">Supervisora</label>
        <select id="sel-supervisora">
            <option value="">Todas</option>
        </select>

        <label for="sel-periodo">Período</label>
        <select id="sel-periodo">
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
    </div>

    <!-- ══ NAVEGACIÓN DE PESTAÑAS ══════════════════════════════════════ -->
    <nav class="tab-nav" role="tablist">
        <button class="tab-btn active" id="tab-btn-resumen" role="tab" aria-controls="tab-resumen" aria-selected="true" data-titulo="PREMIOS SUPERVISORAS">
            <i class="bi bi-trophy-fill"></i>&nbsp; Premios Supervisoras
        </button>
        <button class="tab-btn" id="tab-btn-propios" role="tab" aria-controls="tab-propios" aria-selected="false" data-titulo="LOCALES PROPIOS">
            <i class="bi bi-shop"></i>&nbsp; Locales Propios
        </button>
        <button class="tab-btn" id="tab-btn-franquicias" role="tab" aria-controls="tab-franquicias" aria-selected="false" data-titulo="FRANQUICIAS">
            <i class="bi bi-building"></i>&nbsp; Franquicias
        </button>
        <div class="tab-nav-acciones">
            <button class="tab-reload-btn" id="btn-info-carga" title="¿Cuándo se actualizan los datos?">
                <i class="bi bi-question-circle"></i>
            </button>
            <button class="tab-reload-btn" id="btn-reload-tab" title="Recargar pestaña">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
        </div>
    </nav>

    <!-- ══ PESTAÑA: PREMIOS SUPERVISORAS ═══════════════════════════════ -->
    <div id="tab-resumen" class="tab-pane active" role="tabpanel" aria-labelledby="tab-btn-resumen">
        <main class="dash-content">

            <div class="premio-hero-grid" id="hero-cards-wrap">
                <div class="premios-loading">Cargando…</div>
            </div>

            <div class="premios-card">
                <div class="premios-section-header">
                    <i class="bi bi-award"></i>&nbsp; Premios por Supervisora
                    <button class="btn-export-excel" id="btn-resumen-mensual" style="display:none;">
                        <i class="bi bi-envelope-paper"></i> Enviar resumen mensual
                    </button>
                    <button class="btn-export-excel" id="btn-export-resumen">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                </div>
                <div class="table-wrap" id="tabla-resumen-wrap">
                    <div class="premios-loading">Cargando…</div>
                </div>
            </div>

        </main>
    </div>
    <!-- /tab-resumen -->

    <!-- ══ PESTAÑA: LOCALES PROPIOS ═════════════════════════════════════ -->
    <div id="tab-propios" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-propios">
        <main class="dash-content">

            <div class="kpi-grid" id="kpi-propios-wrap">
                <div class="premios-loading">Cargando…</div>
            </div>

            <div class="premios-card">
                <div class="premios-section-header">
                    <i class="bi bi-table"></i>&nbsp; Facturación vs. Objetivos por Sucursales
                    <button class="btn-export-excel" id="btn-export-propios-tabla">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                </div>
                <div class="table-wrap" id="tabla-propios-wrap">
                    <div class="premios-loading">Cargando…</div>
                </div>
            </div>

        </main>
    </div>
    <!-- /tab-propios -->

    <!-- ══ PESTAÑA: FRANQUICIAS ═════════════════════════════════════════ -->
    <div id="tab-franquicias" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-franquicias">
        <main class="dash-content">

            <div class="kpi-grid" id="kpi-franquicias-wrap">
                <div class="premios-loading">Cargando…</div>
            </div>

            <div class="premios-card">
                <div class="premios-section-header">
                    <i class="bi bi-table"></i>&nbsp; Facturación vs. Objetivos por Sucursales
                    <button class="btn-export-excel" id="btn-export-franquicias-tabla">
                        <i class="bi bi-file-earmark-excel"></i> Excel
                    </button>
                </div>
                <div class="table-wrap" id="tabla-franquicias-wrap">
                    <div class="premios-loading">Cargando…</div>
                </div>
            </div>

        </main>
    </div>
    <!-- /tab-franquicias -->

</div><!-- /dash-wrap -->

<!-- SheetJS (Excel export) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="/bi/js/utils.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/js/utils.js') ?>"></script>

<?php
$jsFiles = [
    '/bi/premios/components/ExcelExporter.js',
    '/bi/premios/js/premios.js',
    '/bi/premios/js/supervisoras.js',
    '/bi/premios/js/propios.js',
    '/bi/premios/js/franquicias.js',
];
foreach ($jsFiles as $f):
    $v = @filemtime($_SERVER['DOCUMENT_ROOT'] . $f) ?: 1;
?>
<script src="<?= $f ?>?v=<?= $v ?>"></script>
<?php endforeach; ?>

<script>
/**
 * Spinner bloqueante (copiado del patrón de sales/index.php — no se
 * extrae a un archivo compartido, así se hace en el resto del repo).
 */
const Spinner = (() => {
    let overlay = null;
    function show(msg = 'Cargando datos...') {
        if (overlay) {
            const textDiv = overlay.querySelector('div:last-child');
            if (textDiv) textDiv.textContent = msg;
            return;
        }
        overlay = document.createElement('div');
        overlay.id = 'bi-spinner-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(26,35,64,0.6);backdrop-filter:blur(2px);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;pointer-events:all';
        if (!document.getElementById('bi-spin-style')) {
            const style = document.createElement('style');
            style.id = 'bi-spin-style';
            style.textContent = '@keyframes bi-spin{to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }
        overlay.innerHTML = `
            <div style="width:52px;height:52px;border:4px solid rgba(255,255,255,0.2);border-top-color:#00a878;border-radius:50%;animation:bi-spin 0.75s linear infinite;"></div>
            <div style="color:rgba(255,255,255,0.92);font-family:'Barlow Condensed',sans-serif;font-size:1.1rem;font-weight:600;letter-spacing:0.5px;">${msg}</div>`;
        document.body.appendChild(overlay);
    }
    function hide() {
        if (!overlay) return;
        overlay.style.opacity = '0';
        overlay.style.transition = 'opacity 0.2s ease';
        setTimeout(() => { overlay?.remove(); overlay = null; }, 200);
    }
    return { show, hide };
})();

/**
 * Orquestador del dashboard de Premios Comercial.
 */
(function () {

    const TABS = [
        { btn: 'tab-btn-resumen',     pane: 'tab-resumen',     name: 'resumen'     },
        { btn: 'tab-btn-propios',     pane: 'tab-propios',     name: 'propios'     },
        { btn: 'tab-btn-franquicias', pane: 'tab-franquicias', name: 'franquicias' },
    ];

    const loaded = { resumen: false, propios: false, franquicias: false };

    const SPINNER_MSGS = {
        resumen    : 'Cargando Premios Supervisoras…',
        propios    : 'Cargando Locales Propios…',
        franquicias: 'Cargando Franquicias…',
    };

    const loaders = {
        resumen    : () => PremiosSupervisoras.load(),
        propios    : () => PremiosPropios.load(),
        franquicias: () => PremiosFranquicias.load(),
    };

    function loadTab(name) {
        if (loaded[name]) return Promise.resolve();
        loaded[name] = true;
        Spinner.show(SPINNER_MSGS[name] ?? 'Cargando…');
        return (loaders[name]?.() ?? Promise.resolve())
            .finally(() => Spinner.hide());
    }

    function activateTab(paneId) {
        TABS.forEach(t => {
            const active = t.pane === paneId;
            document.getElementById(t.btn).classList.toggle('active', active);
            document.getElementById(t.pane).classList.toggle('active', active);
            document.getElementById(t.btn).setAttribute('aria-selected', active ? 'true' : 'false');
        });
        const tab = TABS.find(t => t.pane === paneId);
        if (tab) {
            document.getElementById('topbar-vista-actual').textContent =
                document.getElementById(tab.btn).dataset.titulo ?? '';
            loadTab(tab.name);
        }
    }

    TABS.forEach(t => {
        document.getElementById(t.btn).addEventListener('click', () => activateTab(t.pane));
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

    function recargarTodo() {
        Object.keys(loaded).forEach(k => loaded[k] = false);
        const activePane = document.querySelector('.tab-pane.active');
        const tab = TABS.find(t => t.pane === activePane?.id) ?? TABS[0];
        return loadTab(tab.name);
    }

    document.getElementById('btn-aplicar').addEventListener('click', recargarTodo);
    document.getElementById('sel-supervisora').addEventListener('change', recargarTodo);

    // Reload pestaña activa
    const btnReload = document.getElementById('btn-reload-tab');
    btnReload.addEventListener('click', () => {
        const activePane = document.querySelector('.tab-pane.active');
        const tab = TABS.find(t => t.pane === activePane?.id) ?? TABS[0];
        btnReload.classList.add('spinning');
        loaded[tab.name] = false;
        loadTab(tab.name).finally(() => btnReload.classList.remove('spinning'));
    });

    // Botón de ayuda (ⓘ): frecuencia de carga de datos + qué es el "Avance 15 días"
    document.getElementById('btn-info-carga').addEventListener('click', () => {
        Premios.alertModal(`
            <ul style="margin:0;padding-left:18px;line-height:1.55;">
                <li>Los datos de <strong>Locales Propios</strong> y <strong>Franquicias</strong> se
                    cargan una sola vez por mes, el día 1 — hasta entonces vas a seguir viendo los
                    datos del mes anterior (ver "Última actualización" arriba).</li>
                <li>El botón <strong>"Avance 15 días"</strong> (en Premios Supervisoras) es
                    distinto: muestra un avance <strong>parcial</strong> de venta de los primeros
                    días del mes en curso — no es el cierre del mes ni un premio calculado.</li>
            </ul>`,
            { tono: 'info', titulo: '¿Cuándo se actualizan los datos?' }
        );
    });

    // Carga inicial: filtros (supervisoras) + pestaña Resumen
    PremiosSupervisoras.loadFiltros()
        .then(() => loadTab('resumen'))
        .catch(err => console.error('[Premios] Error en carga inicial:', err));

})();
</script>
</body>
</html>
