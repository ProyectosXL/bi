<?php
/**
 * /bi/sales/index.php
 * Dashboard Sales $ Neto — Migración de Power BI a Web.
 * Fuente de datos: dbo.BI_SALES_LAKERS en XL-APPS / POWER_BI_CONTROL.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['username'])) {
    header('Location: ../../sistemas/login.php');
    exit;
}
require_once __DIR__ . '/../class/config.php';
$tipoSesion = $_SESSION['tipo'] ?? '';
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Pre-cargar lista de canales y rubros para los filtros
require_once __DIR__ . '/class/SalesDB.php';
$isOutdated = false;
$ultimaAct = 'No disponible';
try {
    $salesDb = new SalesDB();
    $canalesDisp = $salesDb->getCanales();
    $rubrosDisp  = $salesDb->getRubros();
    
    // Obtener última actualización real de los datos
    $ultimaActRaw = $salesDb->getUltimaActualizacion();
    if ($ultimaActRaw) {
        $dtUpdate = new DateTime($ultimaActRaw);
        $ultimaAct = $dtUpdate->format('d/m/Y H:i:s');
        
        // Determinar si los datos son menores al día anterior (ayer 00:00:00)
        $dtYesterday = new DateTime('yesterday 00:00:00');
        $isOutdated = ($dtUpdate < $dtYesterday);
    }
} catch (Throwable $e) {
    $canalesDisp = [];
    $rubrosDisp  = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Sales Gcia</title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">
    <!-- Shared base styles -->
    <link rel="stylesheet" href="/bi/css/base.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/base.css') ?>">
    <link rel="stylesheet" href="/bi/css/components.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/components.css') ?>">
    <!-- Sales-specific styles -->
    <link rel="stylesheet" href="/bi/sales/css/sales.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/sales/css/sales.css') ?>">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Chart.js 4.x + DataLabels -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
</head>
<body>
<div class="dash-wrap">

<!-- ══ TOPBAR ══════════════════════════════════════════════════════ -->
<header class="topbar">
    <div class="logo-box">XL</div>
    <div class="topbar-info">
        <div class="topbar-title">DASHBOARD SALES $ NETO</div>
        <div class="topbar-sub">
            <span id="periodo-label">—</span>
        </div>
    </div>
    <div class="topbar-meta">
        Última actualización<br>
        <strong><?= $ultimaAct ?></strong>
        <?php if ($isOutdated): ?>
            <span class="badge-outdated" title="Los datos tienen más de un día de retraso">
                <i class="bi bi-exclamation-triangle-fill"></i> DESACTUALIZADO
            </span>
        <?php endif; ?>
    </div>
</header>

<!-- ══ TOOLBAR ══════════════════════════════════════════════════════ -->
<div class="toolbar">
    <label for="sel-periodo">Período</label>
    <select id="sel-periodo">
        <option value="mes_actual">Mes actual vs año anterior</option>
        <option value="mes_pasado" selected>Mes pasado vs año anterior</option>
        <option value="año_actual">Año actual vs año anterior</option>
        <option value="año_pasado">Año pasado vs anterior</option>
        <option value="30">Últimos 30 días</option>
        <option value="custom">Personalizado</option>
    </select>

    <div class="custom-dates" id="custom-dates">
        <label for="input-desde">Desde</label>
        <input type="date" id="input-desde" value="<?= date('Y-01-01') ?>">
        <label for="input-hasta">Hasta</label>
        <input type="date" id="input-hasta" value="<?= date('Y-m-d', strtotime('-1 day')) ?>">
    </div>

    <label for="sel-canal">Canal</label>
    <select id="sel-canal">
        <option value="">Todos</option>
        <?php foreach ($canalesDisp as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="sel-rubro">Rubro</label>
    <select id="sel-rubro">
        <option value="">Todos</option>
        <?php foreach ($rubrosDisp as $r): ?>
        <option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option>
        <?php endforeach; ?>
    </select>

    <button id="btn-aplicar" class="btn-aplicar">
        <i class="bi bi-check2"></i> Aplicar
    </button>
</div>

<!-- ══ NAVEGACIÓN DE PESTAÑAS ══════════════════════════════════════ -->
<nav class="tab-nav" role="tablist">
    <button class="tab-btn active" id="tab-btn-general"    role="tab" data-tab="general">
        <i class="bi bi-speedometer2"></i>&nbsp; General
    </button>
    <button class="tab-btn" id="tab-btn-evol-fact"         role="tab" data-tab="evol-fact">
        <i class="bi bi-graph-up-arrow"></i>&nbsp; Evolución Facturación
    </button>
    <button class="tab-btn" id="tab-btn-evol-unid"         role="tab" data-tab="evol-unid">
        <i class="bi bi-box-seam"></i>&nbsp; Evolución Unidades
    </button>
    <button class="tab-btn" id="tab-btn-variacion"         role="tab" data-tab="variacion">
        <i class="bi bi-bar-chart-line"></i>&nbsp; Variación Unidades
    </button>
    <button class="tab-btn" id="tab-btn-unidades"          role="tab" data-tab="unidades">
        <i class="bi bi-table"></i>&nbsp; Unidades
    </button>
    <button class="tab-reload-btn" id="btn-reload-tab" title="Recargar pestaña">
        <i class="bi bi-arrow-clockwise"></i>
    </button>
</nav>

<!-- ══════════════════════════════════════════════════════════════════
     PESTAÑA: GENERAL
═══════════════════════════════════════════════════════════════════ -->
<div id="tab-general" class="tab-pane active" role="tabpanel">
    <main class="dash-content">

        <!-- KPIs principales -->
        <div class="summary-row" id="general-kpis">
            <div class="summary-card fact">
                <div class="summary-card-content">
                    <div class="summary-label">Facturación $ Período Actual</div>
                    <div class="summary-value" id="kpi-fact-act">—</div>
                    <div class="summary-var"  id="kpi-fact-var">—</div>
                    <div class="summary-sub"  id="kpi-fact-prev">—</div>
                </div>
                <div class="summary-sparkline">
                    <button class="spark-expand-btn" onclick="abrirDetalleSpark('facturacion', 'Facturación Diaria')"><i class="bi bi-arrows-angle-expand"></i></button>
                    <canvas id="spark-fact"></canvas>
                </div>
            </div>
            <div class="summary-card unid">
                <div class="summary-card-content">
                    <div class="summary-label">Unidades Período Actual</div>
                    <div class="summary-value" id="kpi-unid-act">—</div>
                    <div class="summary-var"  id="kpi-unid-var">—</div>
                    <div class="summary-sub"  id="kpi-unid-prev">—</div>
                </div>
                <div class="summary-sparkline">
                    <button class="spark-expand-btn" onclick="abrirDetalleSpark('unidades', 'Unidades Diarias')"><i class="bi bi-arrows-angle-expand"></i></button>
                    <canvas id="spark-unid"></canvas>
                </div>
            </div>
        </div>

        <!-- Desglose por canal -->
        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-bar-chart-fill"></i> Desglose por Canal — Facturación $
            </div>
            <div style="padding:12px">
                <div class="canal-breakdown-row">
                    <div class="canal-grid" id="canal-fact-grid">
                        <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                    </div>
                    <div class="canal-chart-container">
                        <div class="canal-chart-wrap">
                            <canvas id="chart-donut-fact"></canvas>
                        </div>
                        <div class="canal-chart-legend" id="chart-donut-fact-legend"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-boxes"></i> Desglose por Canal — Unidades
            </div>
            <div style="padding:12px">
                <div class="canal-breakdown-row">
                    <div class="canal-grid" id="canal-unid-grid">
                        <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                    </div>
                    <div class="canal-chart-container">
                        <div class="canal-chart-wrap">
                            <canvas id="chart-donut-unid"></canvas>
                        </div>
                        <div class="canal-chart-legend" id="chart-donut-unid-legend"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bloque de Temporadas (Unidades y Facturación $) -->
        <div class="temporadas-block-row">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-calendar-event"></i> Unidades por Temporada
                </div>
                <div class="temporada-card-stack" id="temporadas-unidades-stack">
                    <div style="padding:10px; text-align:center; color:var(--text-3)">Cargando temporadas...</div>
                </div>
            </div>
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-calendar-event"></i> Facturación $ por Temporada
                </div>
                <div class="temporada-card-stack" id="temporadas-facturacion-stack">
                    <div style="padding:10px; text-align:center; color:var(--text-3)">Cargando temporadas...</div>
                </div>
            </div>
        </div>

        <!-- Tabla mensual acumulada por canal -->
        <div class="table-card">
            <div class="table-card-header">
                <i class="bi bi-table"></i>&nbsp; Acumulado año actual vs mismo período año anterior
            </div>
            <div class="table-wrap" id="tabla-mensual-wrap">
                <div class="analisis-loading" style="padding:20px"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
            </div>
        </div>

    </main>
</div>
<!-- /tab-general -->

<!-- ══════════════════════════════════════════════════════════════════
     PESTAÑA: EVOLUCIÓN FACTURACIÓN
═══════════════════════════════════════════════════════════════════ -->
<div id="tab-evol-fact" class="tab-pane" role="tabpanel">
    <main class="dash-content">

        <div class="general-two-col">
            <!-- Tabla de Rubros -->
            <div class="table-card">
                <div class="table-card-header">
                    <i class="bi bi-list-ul"></i>&nbsp; Facturación por Rubro
                </div>
                <div class="rubro-table-wrap">
                    <table id="tabla-rubros-fact" style="width:100%;border-collapse:collapse;font-size:.82rem">
                        <thead>
                            <tr>
                                <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:left;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;font-size:.82rem;white-space:nowrap">Rubro</th>
                                <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;font-size:.82rem;white-space:nowrap">Facturación $</th>
                                <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;font-size:.82rem;white-space:nowrap">$ Año Ant.</th>
                                <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;font-size:.82rem;white-space:nowrap">Var %</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-rubros-fact">
                            <tr><td colspan="4" class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Gráfico evolución mensual -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="bi bi-graph-up"></i> Evolución Facturación $ por Año
                </div>
                <div class="chart-canvas-wrap">
                    <canvas id="chart-evol-fact"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabla mensual por canal -->
        <div class="table-card">
            <div class="table-card-header">
                <i class="bi bi-calendar3"></i>&nbsp; Facturación Mensual por Canal — Año Actual
            </div>
            <div class="table-wrap" id="tabla-fact-mensual-wrap">
                <div class="analisis-loading" style="padding:20px"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
            </div>
        </div>

    </main>
</div>
<!-- /tab-evol-fact -->

<!-- ══════════════════════════════════════════════════════════════════
     PESTAÑA: EVOLUCIÓN UNIDADES
═══════════════════════════════════════════════════════════════════ -->
<div id="tab-evol-unid" class="tab-pane" role="tabpanel">
    <main class="dash-content">

        <div class="general-two-col">
            <!-- Tabla de Rubros -->
            <div class="table-card">
                <div class="table-card-header">
                    <i class="bi bi-list-ul"></i>&nbsp; Unidades por Rubro
                </div>
                <div class="rubro-table-wrap">
                    <table id="tabla-rubros-unid" style="width:100%;border-collapse:collapse;font-size:.82rem">
                        <thead>
                            <tr>
                                <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:left;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;font-size:.82rem;white-space:nowrap">Rubro</th>
                                <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;font-size:.82rem;white-space:nowrap">Unidades</th>
                                <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;font-size:.82rem;white-space:nowrap">Unid. Año Ant.</th>
                                <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;font-size:.82rem;white-space:nowrap">Var %</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-rubros-unid">
                            <tr><td colspan="4" class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Gráfico evolución mensual -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="bi bi-graph-up"></i> Evolución Unidades por Rubro y Año
                </div>
                <div class="chart-canvas-wrap">
                    <canvas id="chart-evol-unid"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabla mensual por canal -->
        <div class="table-card">
            <div class="table-card-header">
                <i class="bi bi-calendar3"></i>&nbsp; Unidades Mensuales por Canal — Año Actual
            </div>
            <div class="table-wrap" id="tabla-unid-mensual-wrap">
                <div class="analisis-loading" style="padding:20px"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
            </div>
        </div>

    </main>
</div>
<!-- /tab-evol-unid -->

<!-- ══════════════════════════════════════════════════════════════════
     PESTAÑA: VARIACIÓN UNIDADES
═══════════════════════════════════════════════════════════════════ -->
<div id="tab-variacion" class="tab-pane" role="tabpanel">
    <main class="dash-content">

        <div class="variacion-row">
            <!-- % Variación vs año anterior -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="bi bi-percent"></i> % Variación Unidades (Período actual vs año anterior)
                </div>
                <div class="chart-canvas-wrap" style="min-height:320px">
                    <canvas id="chart-variacion"></canvas>
                </div>
            </div>

            <!-- % Participación por canal y año -->
            <div class="chart-card">
                <div class="chart-card-header">
                    <i class="bi bi-pie-chart-fill"></i> % Participación por Canal y Año
                </div>
                <div class="chart-canvas-wrap" style="min-height:320px">
                    <canvas id="chart-participacion"></canvas>
                </div>
            </div>
        </div>

    </main>
</div>
<!-- /tab-variacion -->

<!-- ══════════════════════════════════════════════════════════════════
     PESTAÑA: UNIDADES
═══════════════════════════════════════════════════════════════════ -->
<div id="tab-unidades" class="tab-pane" role="tabpanel">
    <main class="dash-content">

        <div class="table-card">
            <div class="table-card-header">
                <i class="bi bi-table"></i>&nbsp; Unidades por Rubro — Período Actual vs Año Anterior
            </div>
            <div class="table-wrap">
                <table id="tabla-unidades" style="width:100%;border-collapse:collapse;font-size:.82rem">
                    <thead>
                        <tr>
                            <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:left;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;white-space:nowrap;cursor:pointer" onclick="sortTablaUnidades(0)">Rubro ↕</th>
                            <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;white-space:nowrap;cursor:pointer" onclick="sortTablaUnidades(1)">Unidades ↕</th>
                            <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;white-space:nowrap;cursor:pointer" onclick="sortTablaUnidades(2)">Unid. Año Ant. ↕</th>
                            <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;white-space:nowrap;cursor:pointer" onclick="sortTablaUnidades(3)">Var % ↕</th>
                            <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;letter-spacing:.4px;white-space:nowrap;cursor:pointer" onclick="sortTablaUnidades(4)">Facturación $ ↕</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-unidades">
                        <tr><td colspan="5" class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>
<!-- /tab-unidades -->

</div><!-- /dash-wrap -->

<script>
/* ================================================================
   Dashboard Sales $ Neto — JavaScript
   ================================================================ */

// ── Spinner bloqueante idéntico al Dashboard principal ────────────
const Spinner = (() => {
    let overlay = null;

    function show(msg = 'Cargando datos...') {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.id = 'bi-spinner-overlay';
        overlay.style.cssText = [
            'position:fixed',
            'inset:0',
            'z-index:99999',
            'background:rgba(26,35,64,0.6)',
            'backdrop-filter:blur(2px)',
            'display:flex',
            'flex-direction:column',
            'align-items:center',
            'justify-content:center',
            'gap:16px',
            'pointer-events:all'
        ].join(';');

        if (!document.getElementById('bi-spin-style')) {
            const style = document.createElement('style');
            style.id = 'bi-spin-style';
            style.textContent = '@keyframes bi-spin{to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }

        overlay.innerHTML = `
            <div style="
                width:52px;height:52px;
                border:4px solid rgba(255,255,255,0.2);
                border-top-color:#00a878;
                border-radius:50%;
                animation:bi-spin 0.75s linear infinite;
            "></div>
            <div style="
                color:rgba(255,255,255,0.92);
                font-family:'Barlow Condensed',sans-serif;
                font-size:1.1rem;
                font-weight:600;
                letter-spacing:0.5px;
            ">${msg}</div>
        `;
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

// ── Paleta de colores por año ─────────────────────────────────────
const COLORES_ANIO = {
    2022: { border: '#9333ea', bg: 'rgba(147,51,234,.12)' },
    2023: { border: '#ef4444', bg: 'rgba(239,68,68,.12)' },
    2024: { border: '#f59e0b', bg: 'rgba(245,158,11,.12)' },
    2025: { border: '#3b82f6', bg: 'rgba(59,130,246,.12)' },
    2026: { border: '#10b981', bg: 'rgba(16,185,129,.18)' },
};

const COLORES_CANAL = {
    'LOCALES PROPIOS': { border: '#2563eb', bg: 'rgba(37,99,235,.15)' },
    'FRANQUICIAS'    : { border: '#f59e0b', bg: 'rgba(245,158,11,.15)' },
    'MAYORISTAS'     : { border: '#10b981', bg: 'rgba(16,185,129,.15)' },
    'ECOMMERCE'      : { border: '#ec4899', bg: 'rgba(236,72,153,.15)' },
};

const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

// ── Instancias de Chart.js ────────────────────────────────────────
let chartEvolFact = null;
let chartEvolUnid = null;
let chartVariacion = null;
let chartParticipacion = null;
let chartDonutFact = null;
let chartDonutUnid = null;
let sparkFact = null;
let sparkUnid = null;
let chartModalDetalle = null;

// ── Estado actual ─────────────────────────────────────────────────
let tabActiva = 'general';
let tabsIniciadas = new Set();
let datosSerieActiva = [];

// ── Helpers de formato ────────────────────────────────────────────
function fmtM(v) {
    if (v === null || v === undefined) return '—';
    const abs = Math.abs(v);
    let s;
    if      (abs >= 1e9) s = (v/1e9).toFixed(2) + ' mil mill.';
    else if (abs >= 1e6) s = (v/1e6).toFixed(1) + 'M';
    else if (abs >= 1e3) s = (v/1e3).toFixed(0) + 'K';
    else                 s = v.toFixed(0);
    return '$' + s;
}

function fmtN(v) {
    if (v === null || v === undefined) return '—';
    const abs = Math.abs(v);
    if      (abs >= 1e6) return (v/1e6).toFixed(2) + 'M';
    else if (abs >= 1e3) return (v/1e3).toFixed(1) + 'K';
    return Math.round(v).toLocaleString('es-AR');
}

function fmtPct(v) {
    if (v === null || v === undefined) return '—';
    return (v > 0 ? '+' : '') + v.toFixed(1) + '%';
}

function varClass(v) {
    if (v === null || v === undefined) return '';
    return v >= 0 ? 'pos' : 'neg';
}

function loading(msg) {
    Spinner.show(msg);
}

function doneLoading() {
    Spinner.hide();
}

// ── Parámetros actuales del toolbar ──────────────────────────────
function getParams() {
    const periodo = document.getElementById('sel-periodo').value;
    const canal   = document.getElementById('sel-canal').value;
    const rubro   = document.getElementById('sel-rubro').value;
    const p = new URLSearchParams({ periodo, canal, rubro });
    if (periodo === 'custom') {
        p.set('desde', document.getElementById('input-desde').value);
        p.set('hasta', document.getElementById('input-hasta').value);
    }
    return p;
}

// ── PESTAÑA: GENERAL ─────────────────────────────────────────────
async function cargarGeneral(force = false) {
    if (!force && tabsIniciadas.has('general')) return;
    tabsIniciadas.add('general');
    loading('Cargando KPIs...');
    try {
        const resp = await fetch('/bi/sales/api/general.php?' + getParams());
        if (!resp.ok) {
            throw new Error(`Error de servidor (HTTP ${resp.status}). Por favor, reintente.`);
        }
        const data = await resp.json().catch(() => {
            throw new Error("Respuesta no válida del servidor. Posible tiempo de espera agotado.");
        });
        if (!data.ok) throw new Error(data.error);

        const k = data.kpis;

        // Actualizar etiqueta de período
        document.getElementById('periodo-label').textContent =
            `Desde ${data.periodo.desde} al ${data.periodo.hasta} (vs ${data.periodo.desde_prev} – ${data.periodo.hasta_prev})`;

        // KPIs
        document.getElementById('kpi-fact-act').textContent  = fmtM(k.fact_act);
        document.getElementById('kpi-fact-prev').textContent = 'Año ant.: ' + fmtM(k.fact_prev);
        const fv = document.getElementById('kpi-fact-var');
        fv.textContent  = fmtPct(k.var_fact);
        fv.className    = 'summary-var ' + varClass(k.var_fact);

        document.getElementById('kpi-unid-act').textContent  = fmtN(k.unid_act);
        document.getElementById('kpi-unid-prev').textContent = 'Año ant.: ' + fmtN(k.unid_prev);
        const uv = document.getElementById('kpi-unid-var');
        uv.textContent  = fmtPct(k.var_unid);
        uv.className    = 'summary-var ' + varClass(k.var_unid);

        // Desglose por canal — facturación
        const cFact = document.getElementById('canal-fact-grid');
        cFact.innerHTML = data.canales.map(c => `
            <div class="canal-card">
                <div class="canal-name">${c.canal}</div>
                <div class="canal-val">${fmtM(c.fact_act)}</div>
                <div class="canal-var ${varClass(c.var_fact)}">${fmtPct(c.var_fact)}</div>
            </div>
        `).join('');

        // Desglose por canal — unidades
        const cUnid = document.getElementById('canal-unid-grid');
        cUnid.innerHTML = data.canales.map(c => `
            <div class="canal-card">
                <div class="canal-name">${c.canal}</div>
                <div class="canal-val">${fmtN(c.unid_act)}</div>
                <div class="canal-var ${varClass(c.var_unid)}">${fmtPct(c.var_unid)}</div>
            </div>
        `).join('');

        // Tabla resumen comparativo por canal (igual al Power BI)
        renderTablaResumenCanal(data.canales, 'tabla-mensual-wrap');

        // Generar gráficos Donut
        buildDonutChart('chart-donut-fact', data.canales, 'fact_act', fmtM, 'chartDonutFact');
        buildDonutChart('chart-donut-unid', data.canales, 'unid_act', fmtN, 'chartDonutUnid');

        // Generar gráficos de Sparkline en las tarjetas superiores
        buildSparklineChart('spark-fact', data.serie_tiempo, 'facturacion', '#10b981', 'sparkFact');
        buildSparklineChart('spark-unid', data.serie_tiempo, 'unidades', '#3b82f6', 'sparkUnid');

        // Guardar serie en memoria para la apertura de modales de detalle
        datosSerieActiva = data.serie_tiempo;

        // Renderizar Temporadas — Unidades y Facturación $
        if (data.temporadas && data.temporadas.length > 0) {
            const tempLimit = data.temporadas.slice(0, 4); // Mostrar las 4 más recientes

            // Unidades
            const tUnidStack = document.getElementById('temporadas-unidades-stack');
            tUnidStack.innerHTML = tempLimit.map(t => {
                const varText = t.var_unid !== null ? fmtPct(t.var_unid) : '—';
                const varClassVal = t.var_unid !== null ? varClass(t.var_unid) : '';
                const arrow = t.var_unid !== null ? (t.var_unid >= 0 ? '▲' : '▼') : '';
                const tempLabelAnt = t.nombre + ' - ' + (t.anio - 1);
                return `
                    <div class="temporada-item-card">
                        <div class="temporada-item-label">
                            <span>${t.label}</span>
                            <span class="sub">vs ${tempLabelAnt}</span>
                        </div>
                        <div class="temporada-item-values">
                            <span class="temporada-item-val">${fmtN(t.unid)}</span>
                            <span class="temporada-item-var ${varClassVal}">${varText} ${arrow}</span>
                        </div>
                    </div>
                `;
            }).join('');

            // Facturación $
            const tFactStack = document.getElementById('temporadas-facturacion-stack');
            tFactStack.innerHTML = tempLimit.map(t => {
                const varText = t.var_fact !== null ? fmtPct(t.var_fact) : '—';
                const varClassVal = t.var_fact !== null ? varClass(t.var_fact) : '';
                const arrow = t.var_fact !== null ? (t.var_fact >= 0 ? '▲' : '▼') : '';
                const tempLabelAnt = t.nombre + ' - ' + (t.anio - 1);
                return `
                    <div class="temporada-item-card">
                        <div class="temporada-item-label">
                            <span>${t.label}</span>
                            <span class="sub">vs ${tempLabelAnt}</span>
                        </div>
                        <div class="temporada-item-values">
                            <span class="temporada-item-val">${fmtM(t.fact)}</span>
                            <span class="temporada-item-var ${varClassVal}">${varText} ${arrow}</span>
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            document.getElementById('temporadas-unidades-stack').innerHTML = '<div style="padding:10px; text-align:center; color:var(--text-3)">Sin información disponible</div>';
            document.getElementById('temporadas-facturacion-stack').innerHTML = '<div style="padding:10px; text-align:center; color:var(--text-3)">Sin información disponible</div>';
        }

    } catch(e) {
        console.error('Error general:', e);
        alert('Ocurrió un inconveniente al cargar el tablero: ' + e.message);
    } finally {
        doneLoading();
    }
}

// ── Tabla resumen comparativo por canal (estilo Power BI) ──────────
function renderTablaResumenCanal(canales, wrapId) {
    const th = s => `<th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:right;font-family:var(--font-display);font-weight:600;font-size:.8rem;white-space:nowrap">${s}</th>`;
    const thL = s => `<th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:7px 10px;text-align:left;font-family:var(--font-display);font-weight:600;font-size:.8rem;white-space:nowrap">${s}</th>`;
    const td  = (v, cls='') => `<td style="padding:6px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap" class="${cls}">${v}</td>`;
    const tdL = v => `<td style="padding:6px 10px;border-bottom:1px solid var(--border);white-space:nowrap;font-weight:600">${v}</td>`;
    const varArrow = v => {
        if (v === null || v === undefined) return '';
        return v >= 0
            ? `<span style="color:var(--pos);margin-left:4px">&#9650;</span>`
            : `<span style="color:var(--neg);margin-left:4px">&#9660;</span>`;
    };

    let totFa=0, totFp=0, totUa=0, totUp=0, totPv=0;

    let rows = canales.map(c => {
        totFa += c.fact_act  || 0;
        totFp += c.fact_prev || 0;
        totUa += c.unid_act  || 0;
        totUp += c.unid_prev || 0;
        totPv += c.puntos_venta || 0;
        const vf = c.var_fact;
        const vu = c.var_unid;
        return `<tr>
            ${tdL(c.canal)}
            ${td(fmtM(c.fact_act))}
            ${td(fmtM(c.fact_prev), 'text-muted')}
            ${td(`<span class="${varClass(vf)}">${fmtPct(vf)}${varArrow(vf)}</span>`)}
            ${td(fmtN(c.unid_act))}
            ${td(fmtN(c.unid_prev), 'text-muted')}
            ${td(`<span class="${varClass(vu)}">${fmtPct(vu)}${varArrow(vu)}</span>`)}
            ${td(c.puntos_venta ?? '—')}
        </tr>`;
    }).join('');

    const varFaTot = totFp !== 0 ? ((totFa-totFp)/Math.abs(totFp)*100) : null;
    const varUaTot = totUp !== 0 ? ((totUa-totUp)/Math.abs(totUp)*100) : null;

    const html = `
    <table style="width:100%;border-collapse:collapse;font-size:.82rem">
        <thead><tr>
            ${thL('Canal')}
            ${th('Facturación $')}
            ${th('Fact. Per. Previo')}
            ${th('Facturación Var %')}
            ${th('Unidades')}
            ${th('Unidades Per. Previo')}
            ${th('Unidades Var %')}
            ${th('Puntos de Venta')}
        </tr></thead>
        <tbody>
            ${rows}
            <tr style="background:var(--bg-card2);font-weight:700">
                <td style="padding:6px 10px;border-top:2px solid var(--border)">Total</td>
                <td style="padding:6px 10px;border-top:2px solid var(--border);text-align:right;white-space:nowrap">${fmtM(totFa)}</td>
                <td style="padding:6px 10px;border-top:2px solid var(--border);text-align:right;white-space:nowrap;color:var(--text-3)">${fmtM(totFp)}</td>
                <td style="padding:6px 10px;border-top:2px solid var(--border);text-align:right;white-space:nowrap" class="${varClass(varFaTot)}">${fmtPct(varFaTot)}${varArrow(varFaTot)}</td>
                <td style="padding:6px 10px;border-top:2px solid var(--border);text-align:right;white-space:nowrap">${fmtN(totUa)}</td>
                <td style="padding:6px 10px;border-top:2px solid var(--border);text-align:right;white-space:nowrap;color:var(--text-3)">${fmtN(totUp)}</td>
                <td style="padding:6px 10px;border-top:2px solid var(--border);text-align:right;white-space:nowrap" class="${varClass(varUaTot)}">${fmtPct(varUaTot)}${varArrow(varUaTot)}</td>
                <td style="padding:6px 10px;border-top:2px solid var(--border);text-align:right;white-space:nowrap">${totPv}</td>
            </tr>
        </tbody>
    </table>`;

    document.getElementById(wrapId).innerHTML = html;
}

// ── Tabla mensual por canal (usada en Evolución Facturación / Unidades) ──
function renderTablaMensualCanal(rows, metrica, wrapId) {
    const meses_usados = [...new Set(rows.map(r => r.mes))].sort((a,b)=>a-b);
    const canalesMap = {};
    rows.forEach(r => {
        const key = r.CANAL || r.canal;
        if (!canalesMap[key]) canalesMap[key] = {};
        canalesMap[key][r.mes] = metrica === 'facturacion' ? r.facturacion : r.unidades;
    });
    const fmt = metrica === 'facturacion' ? fmtM : fmtN;
    const totalesMes = {};
    meses_usados.forEach(m => {
        totalesMes[m] = Object.values(canalesMap).reduce((s, c) => s + (c[m] || 0), 0);
    });
    let html = `<table style="width:100%;border-collapse:collapse;font-size:.8rem"><thead><tr>
        <th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:6px 10px;text-align:left;font-family:var(--font-display);font-weight:600;font-size:.8rem;white-space:nowrap">Canal</th>`;
    meses_usados.forEach(m => {
        html += `<th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:6px 8px;text-align:right;font-family:var(--font-display);font-weight:600;font-size:.8rem;white-space:nowrap">${MESES[m-1]}</th>`;
    });
    html += `<th style="background:var(--bg-header);color:rgba(255,255,255,.9);padding:6px 10px;text-align:right;font-family:var(--font-display);font-weight:600;font-size:.8rem;white-space:nowrap">Total</th></tr></thead><tbody>`;
    Object.entries(canalesMap).forEach(([canal, mesesData]) => {
        const total = Object.values(mesesData).reduce((s,v)=>s+v,0);
        html += `<tr><td style="padding:5px 10px;border-bottom:1px solid var(--border);white-space:nowrap;font-weight:600">${canal}</td>`;
        meses_usados.forEach(m => {
            html += `<td style="padding:5px 8px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap">${fmt(mesesData[m] || 0)}</td>`;
        });
        html += `<td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;font-weight:700;white-space:nowrap">${fmt(total)}</td></tr>`;
    });
    const totalGral = Object.values(totalesMes).reduce((s,v)=>s+v,0);
    html += `<tr style="background:var(--bg-card2)"><td style="padding:6px 10px;font-weight:700;border-top:2px solid var(--border)">Total</td>`;
    meses_usados.forEach(m => {
        html += `<td style="padding:6px 8px;text-align:right;font-weight:700;border-top:2px solid var(--border);white-space:nowrap">${fmt(totalesMes[m])}</td>`;
    });
    html += `<td style="padding:6px 10px;text-align:right;font-weight:700;border-top:2px solid var(--border);white-space:nowrap">${fmt(totalGral)}</td></tr></tbody></table>`;
    document.getElementById(wrapId).innerHTML = html;
}

// ── PESTAÑA: EVOLUCIÓN FACTURACIÓN ───────────────────────────────
async function cargarEvolFact(force = false) {
    if (!force && tabsIniciadas.has('evol-fact')) return;
    tabsIniciadas.add('evol-fact');
    loading('Cargando Facturación...');
    try {
        const resp = await fetch('/bi/sales/api/evolucion_facturacion.php?' + getParams());
        const data = await resp.json();
        if (!data.ok) throw new Error(data.error);

        // Tabla de rubros
        const tbody = document.getElementById('tbody-rubros-fact');
        if (data.rubros.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="padding:16px;text-align:center;color:var(--text-3)">Sin datos</td></tr>';
        } else {
            tbody.innerHTML = data.rubros.map(r => `
                <tr>
                    <td style="padding:5px 10px;border-bottom:1px solid var(--border);white-space:nowrap">${r.rubro}</td>
                    <td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap">${fmtM(r.fact_act)}</td>
                    <td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;color:var(--text-3)">${fmtM(r.fact_prev)}</td>
                    <td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap" class="${varClass(r.var)}">${fmtPct(r.var)}</td>
                </tr>
            `).join('');
        }

        // Gráfico multi-año
        buildLineChart('chart-evol-fact', data.evolucion, 'facturacion', fmtM);

        // Tabla mensual
        renderTablaMensualCanal(data.tabla_mensual, 'facturacion', 'tabla-fact-mensual-wrap');

    } catch(e) {
        console.error('Error evol-fact:', e);
    } finally {
        doneLoading();
    }
}

// ── PESTAÑA: EVOLUCIÓN UNIDADES ──────────────────────────────────
async function cargarEvolUnid(force = false) {
    if (!force && tabsIniciadas.has('evol-unid')) return;
    tabsIniciadas.add('evol-unid');
    loading('Cargando Unidades...');
    try {
        const resp = await fetch('/bi/sales/api/evolucion_unidades.php?' + getParams());
        const data = await resp.json();
        if (!data.ok) throw new Error(data.error);

        // Tabla de rubros
        const tbody = document.getElementById('tbody-rubros-unid');
        if (data.rubros.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="padding:16px;text-align:center;color:var(--text-3)">Sin datos</td></tr>';
        } else {
            tbody.innerHTML = data.rubros.map(r => `
                <tr>
                    <td style="padding:5px 10px;border-bottom:1px solid var(--border);white-space:nowrap">${r.rubro}</td>
                    <td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap">${fmtN(r.unid_act)}</td>
                    <td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;color:var(--text-3)">${fmtN(r.unid_prev)}</td>
                    <td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap" class="${varClass(r.var)}">${fmtPct(r.var)}</td>
                </tr>
            `).join('');
        }

        // Gráfico multi-año
        buildLineChart('chart-evol-unid', data.evolucion, 'unidades', fmtN);

        // Tabla mensual
        renderTablaMensualCanal(data.tabla_mensual, 'unidades', 'tabla-unid-mensual-wrap');

    } catch(e) {
        console.error('Error evol-unid:', e);
    } finally {
        doneLoading();
    }
}

// ── PESTAÑA: VARIACIÓN UNIDADES ──────────────────────────────────
async function cargarVariacion(force = false) {
    if (!force && tabsIniciadas.has('variacion')) return;
    tabsIniciadas.add('variacion');
    loading('Cargando Variación...');
    try {
        const p = new URLSearchParams({ canal: document.getElementById('sel-canal').value });
        const resp = await fetch('/bi/sales/api/variacion_unidades.php?' + p);
        const data = await resp.json();
        if (!data.ok) throw new Error(data.error);

        // Gráfico variación % mensual
        buildVariacionChart(data.variacion);

        // Gráfico participación apilado
        buildParticipacionChart(data.participacion);

    } catch(e) {
        console.error('Error variacion:', e);
    } finally {
        doneLoading();
    }
}

// ── PESTAÑA: UNIDADES ────────────────────────────────────────────
let _unidadesData = [];

async function cargarUnidades(force = false) {
    if (!force && tabsIniciadas.has('unidades')) return;
    tabsIniciadas.add('unidades');
    loading('Cargando Tabla de Unidades...');
    try {
        const resp = await fetch('/bi/sales/api/unidades.php?' + getParams());
        const data = await resp.json();
        if (!data.ok) throw new Error(data.error);

        _unidadesData = data.tabla;
        renderTablaUnidades(_unidadesData);

    } catch(e) {
        console.error('Error unidades:', e);
    } finally {
        doneLoading();
    }
}

function renderTablaUnidades(rows) {
    const tbody = document.getElementById('tbody-unidades');
    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="padding:16px;text-align:center;color:var(--text-3)">Sin datos</td></tr>';
        return;
    }

    let totalUnid = 0, totalPrev = 0, totalFact = 0;
    let html = rows.map(r => {
        totalUnid += r.unid_act || 0;
        totalPrev += r.unid_prev || 0;
        totalFact += r.fact_act || 0;
        return `<tr>
            <td style="padding:5px 10px;border-bottom:1px solid var(--border);white-space:nowrap">${r.rubro}</td>
            <td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;font-weight:600;white-space:nowrap">${fmtN(r.unid_act)}</td>
            <td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;color:var(--text-3)">${fmtN(r.unid_prev)}</td>
            <td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap" class="${varClass(r.var)}">${fmtPct(r.var)}</td>
            <td style="padding:5px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap">${fmtM(r.fact_act)}</td>
        </tr>`;
    }).join('');

    const varTotal = totalPrev !== 0 ? ((totalUnid - totalPrev) / Math.abs(totalPrev) * 100) : null;
    html += `<tr style="background:var(--bg-card2)">
        <td style="padding:6px 10px;font-weight:700;border-top:2px solid var(--border)">Total</td>
        <td style="padding:6px 10px;text-align:right;font-weight:700;border-top:2px solid var(--border);white-space:nowrap">${fmtN(totalUnid)}</td>
        <td style="padding:6px 10px;text-align:right;font-weight:700;border-top:2px solid var(--border);white-space:nowrap;color:var(--text-3)">${fmtN(totalPrev)}</td>
        <td style="padding:6px 10px;text-align:right;font-weight:700;border-top:2px solid var(--border);white-space:nowrap" class="${varClass(varTotal)}">${fmtPct(varTotal)}</td>
        <td style="padding:6px 10px;text-align:right;font-weight:700;border-top:2px solid var(--border);white-space:nowrap">${fmtM(totalFact)}</td>
    </tr>`;

    tbody.innerHTML = html;
}

let _sortCol = 1, _sortDir = -1;
function sortTablaUnidades(col) {
    if (_sortCol === col) _sortDir *= -1;
    else { _sortCol = col; _sortDir = -1; }
    const keys = ['rubro','unid_act','unid_prev','var','fact_act'];
    _unidadesData.sort((a,b) => {
        const av = a[keys[col]] ?? 0;
        const bv = b[keys[col]] ?? 0;
        return typeof av === 'string' ? av.localeCompare(bv) * _sortDir : (av - bv) * _sortDir;
    });
    renderTablaUnidades(_unidadesData);
}

// ── Helpers de gráficos ──────────────────────────────────────────
function buildLineChart(canvasId, rows, metrica, fmtFn) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    // Agrupar por año
    const porAnio = {};
    rows.forEach(r => {
        if (!porAnio[r.anio]) porAnio[r.anio] = {};
        porAnio[r.anio][r.mes] = metrica === 'facturacion' ? r.facturacion : r.unidades;
    });

    const datasets = Object.entries(porAnio).sort(([a],[b])=>a-b).map(([anio, mesesData]) => {
        const color = COLORES_ANIO[anio] || { border: '#94a3b8', bg: 'rgba(148,163,184,.12)' };
        return {
            label: anio,
            data: MESES.map((_, i) => mesesData[i+1] || null),
            borderColor: color.border,
            backgroundColor: color.bg,
            borderWidth: anio == new Date().getFullYear() ? 2.5 : 1.5,
            pointRadius: 3,
            tension: 0.3,
            fill: false,
            spanGaps: false,
        };
    });

    const existingChart = canvasId === 'chart-evol-fact' ? chartEvolFact : chartEvolUnid;
    if (existingChart) existingChart.destroy();

    const newChart = new Chart(canvas, {
        type: 'line',
        data: { labels: MESES, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 20 } },
                datalabels: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.dataset.label}: ${fmtFn(ctx.raw)}`
                    }
                }
            },
            scales: {
                y: {
                    ticks: {
                        callback: v => fmtFn(v),
                        font: { size: 10 }
                    },
                    grid: { color: 'rgba(0,0,0,.06)' }
                },
                x: { grid: { color: 'rgba(0,0,0,.04)' } }
            }
        }
    });

    if (canvasId === 'chart-evol-fact') chartEvolFact = newChart;
    else chartEvolUnid = newChart;
}

function buildVariacionChart(rows) {
    const canvas = document.getElementById('chart-variacion');
    if (!canvas) return;
    if (chartVariacion) chartVariacion.destroy();

    const labels = rows.map(r => MESES[r.mes - 1]);
    const values = rows.map(r => r.var);

    chartVariacion = new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: '% Variación',
                data: values,
                backgroundColor: values.map(v => v >= 0 ? 'rgba(16,185,129,.7)' : 'rgba(239,68,68,.7)'),
                borderColor   : values.map(v => v >= 0 ? '#10b981' : '#ef4444'),
                borderWidth: 1,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false },
                datalabels: {
                    display: true,
                    color: ctx => ctx.dataset.data[ctx.dataIndex] >= 0 ? '#065f46' : '#991b1b',
                    anchor: ctx => ctx.dataset.data[ctx.dataIndex] >= 0 ? 'end' : 'start',
                    align: ctx => ctx.dataset.data[ctx.dataIndex] >= 0 ? 'top' : 'bottom',
                    formatter: v => v !== null ? fmtPct(v) : '',
                    font: { size: 10, weight: '600' }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => `Variación: ${fmtPct(ctx.raw)}`
                    }
                }
            },
            scales: {
                y: {
                    ticks: { callback: v => fmtPct(v), font: { size: 10 } },
                    grid: { color: 'rgba(0,0,0,.06)' }
                },
                x: { grid: { display: false } }
            }
        },
        plugins: [ChartDataLabels]
    });
}

function buildParticipacionChart(rows) {
    const canvas = document.getElementById('chart-participacion');
    if (!canvas) return;
    if (chartParticipacion) chartParticipacion.destroy();

    // Agrupar: anio → canal → unidades
    const porAnio = {};
    const canalesSet = new Set();
    rows.forEach(r => {
        if (!porAnio[r.anio]) porAnio[r.anio] = {};
        porAnio[r.anio][r.CANAL] = r.unidades;
        canalesSet.add(r.CANAL);
    });

    const anios   = Object.keys(porAnio).sort();
    const canales = [...canalesSet].sort();

    // Calcular totales por año para hacer el 100%
    const totales = {};
    anios.forEach(a => {
        totales[a] = canales.reduce((s, c) => s + (porAnio[a][c] || 0), 0);
    });

    const datasets = canales.map(c => {
        const color = COLORES_CANAL[c] || { border: '#94a3b8', bg: 'rgba(148,163,184,.5)' };
        return {
            label: c,
            data: anios.map(a => totales[a] !== 0 ? Math.round((porAnio[a][c] || 0) / totales[a] * 1000) / 10 : 0),
            backgroundColor: color.bg,
            borderColor: color.border,
            borderWidth: 1,
        };
    });

    chartParticipacion = new Chart(canvas, {
        type: 'bar',
        data: { labels: anios, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 }, boxWidth: 16 } },
                datalabels: {
                    display: ctx => ctx.dataset.data[ctx.dataIndex] > 5,
                    color: '#fff',
                    font: { size: 10, weight: '600' },
                    formatter: v => v.toFixed(1) + '%',
                },
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.dataset.label}: ${ctx.raw.toFixed(1)}%`
                    }
                }
            },
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: {
                    stacked: true,
                    max: 100,
                    ticks: { callback: v => v + '%', font: { size: 10 } },
                    grid: { color: 'rgba(0,0,0,.06)' }
                }
            }
        },
        plugins: [ChartDataLabels]
    });
}

function buildDonutChart(canvasId, canales, metricaKey, fmtFn, globalInstanceName) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    // Destruir instancia anterior si existe
    if (window[globalInstanceName]) {
        window[globalInstanceName].destroy();
        window[globalInstanceName] = null;
    }

    // Filtrar canales válidos y con valor > 0
    const validData = canales.filter(c => c[metricaKey] > 0);
    const labels = validData.map(c => c.canal);
    const dataValues = validData.map(c => c[metricaKey]);
    
    const total = dataValues.reduce((s, v) => s + v, 0);

    const bgColors = labels.map(l => {
        const c = COLORES_CANAL[l] || { bg: 'rgba(148,163,184,.7)' };
        return c.bg.replace('.15', '.8').replace('.5', '.8');
    });
    const borderColors = labels.map(l => (COLORES_CANAL[l] || { border: '#94a3b8' }).border);

    // Inyectar leyenda HTML personalizada al costado
    const legendWrap = document.getElementById(canvasId + '-legend');
    if (legendWrap) {
        legendWrap.innerHTML = validData.map((c, idx) => {
            const pct = total > 0 ? (c[metricaKey] / total * 100).toFixed(1) : 0;
            const color = borderColors[idx];
            return `
                <div class="legend-item">
                    <span class="legend-color" style="background:${color}"></span>
                    <span class="legend-label" title="${c.canal}">${c.canal}</span>
                    <span class="legend-pct">${pct}%</span>
                </div>
            `;
        }).join('');
    }

    window[globalInstanceName] = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: dataValues,
                backgroundColor: bgColors,
                borderColor: borderColors,
                borderWidth: 1.5,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            layout: {
                padding: 6 // Margen interno para dar espacio al tooltip flotante
            },
            interaction: {
                mode: 'nearest',
                intersect: true
            },
            plugins: {
                legend: {
                    display: false
                },
                datalabels: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(26, 35, 64, 0.95)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    borderColor: 'rgba(255,255,255,0.1)',
                    borderWidth: 1,
                    padding: 6,
                    cornerRadius: 4,
                    displayColors: false,
                    bodyFont: { size: 9 },
                    titleFont: { size: 9 },
                    callbacks: {
                        label: ctx => `${ctx.label}: ${fmtFn(ctx.raw)} (${(ctx.raw / total * 100).toFixed(1)}%)`
                    }
                }
            }
        }
    });
}

function buildSparklineChart(canvasId, serieTiempo, metricaKey, color, globalInstanceName) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;

    if (window[globalInstanceName]) {
        window[globalInstanceName].destroy();
        window[globalInstanceName] = null;
    }

    const dataValues = serieTiempo.map(s => s[metricaKey]);
    const labels = serieTiempo.map(s => s.label);

    const makeAreaGrad = (ctx, chartArea) => {
        if (!chartArea) return 'transparent';
        const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        g.addColorStop(0, color + '50');
        g.addColorStop(1, color + '00');
        return g;
    };

    window[globalInstanceName] = new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                data: dataValues,
                borderColor: color,
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                backgroundColor: ctxObj => {
                    const chart = ctxObj.chart;
                    return makeAreaGrad(chart.ctx, chart.chartArea);
                },
                pointRadius: 0,
                pointHoverRadius: 4,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                datalabels: { display: false },
                tooltip: { enabled: true }
            },
            scales: {
                x: { display: false },
                y: { display: false }
            },
            layout: {
                padding: { top: 4, bottom: 4, left: 4, right: 4 }
            }
        }
    });
}

// ── Navegación de pestañas ───────────────────────────────────────
function activarTab(tab) {
    tabActiva = tab;

    // Actualizar botones
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById(`tab-btn-${tab}`).classList.add('active');

    // Mostrar pestaña
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.getElementById(`tab-${tab}`).classList.add('active');

    // Cargar datos si no están cargados
    cargarTab(tab);
}

function cargarTab(tab, force = false) {
    switch(tab) {
        case 'general':   cargarGeneral(force);   break;
        case 'evol-fact': cargarEvolFact(force);  break;
        case 'evol-unid': cargarEvolUnid(force);  break;
        case 'variacion': cargarVariacion(force); break;
        case 'unidades':  cargarUnidades(force);  break;
    }
}

// ── Event Listeners ──────────────────────────────────────────────
// ── Sparkline modal detallado (estilo global) ──────────────────────
function abrirDetalleSpark(metrica, titulo) {
    if (!datosSerieActiva || datosSerieActiva.length === 0) return;

    const values = datosSerieActiva.map(d => d[metrica]);
    const labels = datosSerieActiva.map(d => d.label);
    const fmt = metrica === 'facturacion' ? fmtM : fmtN;
    const color = metrica === 'facturacion' ? '#10b981' : '#3b82f6';

    const n = values.length;
    const ultimo = values[n - 1] || 0;
    const primero = values[0] || 1; // evitar division por 0
    const maximo = Math.max(...values);
    const minimo = Math.min(...values);
    const promedio = values.reduce((s,v)=>s+v,0) / n;
    
    // Tendencia calculada como la variación porcentual entre el inicio y fin del periodo
    const varTendencia = ((ultimo - primero) / primero) * 100;
    const arrow = varTendencia >= 0 ? '&#9650;' : '&#9660;';
    const trendClass = varTendencia >= 0 ? 'pos' : 'neg';

    // Formatear fechas para mostrar en las tarjetas estadísticas
    const fechaUlt = labels[n - 1];
    const fechaMax = labels[values.indexOf(maximo)];
    const fechaMin = labels[values.indexOf(minimo)];

    const modalHtml = `
        <div class="spark-modal-overlay" id="spark-modal-overlay" onclick="if(event.target === this) cerrarDetalleSpark()">
            <div class="spark-modal">
                <div class="spark-modal-header">
                    <span class="spark-modal-title">${titulo}</span>
                    <button class="spark-modal-close" onclick="cerrarDetalleSpark()"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="spark-modal-stats">
                    <div class="spark-modal-stat">
                        <span class="spark-modal-stat-label">Último</span>
                        <span class="spark-modal-stat-val">${fmt(ultimo)}</span>
                        <span class="stat-date">${fechaUlt}</span>
                    </div>
                    <div class="spark-modal-stat">
                        <span class="spark-modal-stat-label">Promedio</span>
                        <span class="spark-modal-stat-val">${fmt(promedio)}</span>
                    </div>
                    <div class="spark-modal-stat">
                        <span class="spark-modal-stat-label">Máximo</span>
                        <span class="spark-modal-stat-val">${fmt(maximo)}</span>
                        <span class="stat-date">${fechaMax}</span>
                    </div>
                    <div class="spark-modal-stat">
                        <span class="spark-modal-stat-label">Mínimo</span>
                        <span class="spark-modal-stat-val">${fmt(minimo)}</span>
                        <span class="stat-date">${fechaMin}</span>
                    </div>
                    <div class="spark-modal-stat stat-secondary">
                        <span class="spark-modal-stat-label">Tendencia</span>
                        <div style="margin-top:2px">
                            <span class="trend-badge ${trendClass}">${fmtPct(varTendencia)} ${arrow}</span>
                        </div>
                        <span class="stat-date">vs inicio periodo</span>
                    </div>
                </div>
                <div class="spark-modal-chart-wrap">
                    <canvas id="chart-modal-canvas"></canvas>
                </div>
            </div>
        </div>
    `;

    document.getElementById('spark-modal-root').innerHTML = modalHtml;

    // Crear el gráfico de línea detallado
    const canvas = document.getElementById('chart-modal-canvas');
    const ctx = canvas.getContext('2d');
    
    // Gradiente de fondo
    const grad = ctx.createLinearGradient(0, 0, 0, 240);
    grad.addColorStop(0, color + '50');
    grad.addColorStop(1, color + '00');

    if (chartModalDetalle) chartModalDetalle.destroy();

    chartModalDetalle = new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: titulo,
                data: values,
                borderColor: color,
                borderWidth: 2.5,
                tension: 0.35,
                fill: true,
                backgroundColor: grad,
                pointRadius: n > 31 ? 0 : 3,
                pointHoverRadius: 5,
                pointBackgroundColor: color,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                datalabels: { display: false }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 9 } }
                },
                y: {
                    grid: { color: 'rgba(0,0,0,.06)' },
                    ticks: {
                        callback: v => fmt(v),
                        font: { size: 9 }
                    }
                }
            }
        }
    });
}

function cerrarDetalleSpark() {
    if (chartModalDetalle) {
        chartModalDetalle.destroy();
        chartModalDetalle = null;
    }
    document.getElementById('spark-modal-root').innerHTML = '';
}

// ── Event Listeners ──────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => activarTab(btn.dataset.tab));
});

document.getElementById('btn-aplicar').addEventListener('click', () => {
    tabsIniciadas.clear();
    cargarTab(tabActiva, true);
});

document.getElementById('sel-periodo').addEventListener('change', function() {
    const custom = document.getElementById('custom-dates');
    this.value === 'custom' ? custom.classList.add('visible') : custom.classList.remove('visible');
});

document.getElementById('btn-reload-tab').addEventListener('click', () => {
    tabsIniciadas.delete(tabActiva);
    cargarTab(tabActiva, true);
    const btn = document.getElementById('btn-reload-tab');
    btn.classList.add('spinning');
    setTimeout(() => btn.classList.remove('spinning'), 800);
});

// ── Inicio ───────────────────────────────────────────────────────
cargarGeneral();
</script>

<div id="spark-modal-root"></div>

</body>
</html>
