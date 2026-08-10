<?php
/**
 * /bi/global/index.php
 * Dashboard global consolidado — GERENCIA / SUPERVISIÓN
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
$esGrupo         = (bool)($_SESSION['esGrupo'] ?? false);
$sucursalesGrupo = $isGrupo ? ($_SESSION['sucursalesGrupo'] ?? []) : [];
if ($isGrupo) {
    $descLabel = $_SESSION['descLocal'] ?? 'GRUPO';
} else {
    $descLabel = $tipoSesion === 'GERENCIA' ? 'GERENCIA' : 'SUPERVISIÓN';
}
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Orígenes con sensor de tráfico (merodeo/ingresos) habilitado.
// Cuando Franquicias tenga el sensor instalado, alcanza con sumar 'franquicias' acá.
const ORIGENES_CON_CIRCULACION = ['argentina'];
$mostrarTabCirculacion = !empty(ORIGENES_CON_CIRCULACION) && (!$isGrupo || in_array('franquicias', ORIGENES_CON_CIRCULACION, true));

require_once __DIR__ . '/class/GlobalDashboardDB.php';
$isOutdated = false;
$ultimaAct = 'No disponible';
try {
    $defaultDb = new GlobalDashboardDB('argentina');
    $ultimaActRaw = $defaultDb->getUltimaActualizacion();
    if ($ultimaActRaw) {
        $dtUpdate = new DateTime($ultimaActRaw);
        $ultimaAct = $dtUpdate->format('d/m/Y H:i:s');
        
        $dtYesterday = new DateTime('yesterday 00:00:00');
        $isOutdated = ($dtUpdate < $dtYesterday);
    }
} catch (Throwable $_) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard — <?= htmlspecialchars($descLabel) ?></title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">
    <!-- Shared base styles -->
    <link rel="stylesheet" href="/bi/css/base.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/base.css') ?>">
    <link rel="stylesheet" href="/bi/css/components.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/components.css') ?>">
    <!-- Global-specific styles -->
    <link rel="stylesheet" href="/bi/global/css/global.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/global/css/global.css') ?>">
    <link rel="stylesheet" href="/bi/global/css/cadena.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/global/css/cadena.css') ?>">
    <link rel="stylesheet" href="/bi/global/css/participacion.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/global/css/participacion.css') ?>">
    <link rel="stylesheet" href="/bi/global/css/vendedoras.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/global/css/vendedoras.css') ?>">
    <link rel="stylesheet" href="/bi/global/css/producto.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/global/css/producto.css') ?>">
    <link rel="stylesheet" href="/bi/global/css/ranking.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/global/css/ranking.css') ?>">
    <link rel="stylesheet" href="/bi/global/css/circulacion.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/global/css/circulacion.css') ?>">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Chart.js 4.x + DataLabels Plugin -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
</head>
<body<?= !$isGrupo ? ' class="has-origen"' : '' ?>>
<div class="dash-wrap">
<div id="loading-bar"></div>
<div id="loading-overlay"><div class="loading-spinner"></div></div>
<div id="spark-modal-root"></div>

    <!-- ══ TOPBAR ══════════════════════════════════════════════════════ -->
    <header class="topbar">
        <div class="logo-box">XL</div>
        <div class="topbar-info">
            <div class="topbar-title">DASHBOARD SALES GLOBAL — <?= htmlspecialchars($descLabel) ?></div>
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
            <span class="badge-outdated" id="badge-desactualizado" title="Los datos tienen más de un día de retraso" <?= !$isOutdated ? 'style="display: none;"' : '' ?>>
                <i class="bi bi-exclamation-triangle-fill"></i> DESACTUALIZADO
            </span>
        </div>
    </header>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════════════ -->
    <div class="toolbar">
        <label for="sel-sucursal">Sucursal</label>
        <select id="sel-sucursal" multiple>
            <option value="">Todas</option>
        </select>

        <?php if (!$isGrupo): ?>
        <div class="ar-only-wrap" id="grupo-wrap">
            <label for="sel-grupo">Grupo</label>
            <select id="sel-grupo">
                <option value="">Todos</option>
            </select>
        </div>

        <div class="ar-only-wrap" id="tipo-tienda-wrap">
            <label for="sel-tipo-tienda">Tipo tienda</label>
            <select id="sel-tipo-tienda">
                <option value="">Todos</option>
            </select>
        </div>

        <div class="ar-only-wrap" id="canal-wrap">
            <label for="sel-canal">Canal</label>
            <select id="sel-canal">
                <option value="">Todos</option>
                <option value="PROPIOS">Locales propios</option>
                <option value="ECOMMERCE">Ecommerce</option>
            </select>
        </div>

        <div class="fran-only-wrap" id="tipo-local-wrap" style="display: none;">
            <label for="sel-tipo-local">Tipo local</label>
            <select id="sel-tipo-local">
                <option value="">Todos</option>
            </select>
        </div>

        <div class="fran-only-wrap" id="zona-wrap" style="display: none;">
            <label for="sel-zona">Zona</label>
            <select id="sel-zona">
                <option value="">Todos</option>
            </select>
        </div>

        <div class="fran-only-wrap" id="grupo-empresario-wrap" style="display: none;">
            <label for="sel-grupo-empresario">Grupo Empresario</label>
            <select id="sel-grupo-empresario">
                <option value="">Todos</option>
            </select>
        </div>
        <?php endif; ?>

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

        <label for="sel-vendedor">Vendedor</label>
        <select id="sel-vendedor">
            <option value="%">Todos</option>
        </select>

        <label for="sel-rubro">Rubro</label>
        <select id="sel-rubro" multiple>
            <option value="%">Todos</option>
        </select>

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
        <button class="tab-btn active" id="tab-btn-kpis" role="tab" aria-controls="tab-kpis" aria-selected="true">
            <i class="bi bi-speedometer2"></i>&nbsp; KPIs
        </button>
        <button class="tab-btn" id="tab-btn-analisis" role="tab" aria-controls="tab-analisis" aria-selected="false">
            <i class="bi bi-graph-up-arrow"></i>&nbsp; Análisis
        </button>
        <?php if ($mostrarTabCirculacion): ?>
        <button class="tab-btn" id="tab-btn-circulacion" role="tab" aria-controls="tab-circulacion" aria-selected="false">
            <i class="bi bi-person-walking"></i>&nbsp; Circulación
        </button>
        <?php endif; ?>
        <button class="tab-btn" id="tab-btn-producto" role="tab" aria-controls="tab-producto" aria-selected="false">
            <i class="bi bi-box-seam"></i>&nbsp; Producto
        </button>
        <?php if (!$isGrupo || $esGrupo): ?>
        <button class="tab-btn" id="tab-btn-cadena" role="tab" aria-controls="tab-cadena" aria-selected="false">
            <i class="bi bi-diagram-3"></i>&nbsp; Cadena
        </button>
        <button class="tab-btn" id="tab-btn-participacion" role="tab" aria-controls="tab-participacion" aria-selected="false">
            <i class="bi bi-grid-3x3-gap-fill"></i>&nbsp; Participación
        </button>
        <?php endif; ?>
        <button class="tab-btn" id="tab-btn-vendedoras" role="tab" aria-controls="tab-vendedoras" aria-selected="false">
            <i class="bi bi-people-fill"></i>&nbsp; Vendedoras
        </button>
        <?php if (!$isGrupo || $esGrupo): ?>
        <button class="tab-btn" id="tab-btn-ranking" role="tab" aria-controls="tab-ranking" aria-selected="false">
            <i class="bi bi-trophy"></i>&nbsp; Ranking
        </button>
        <?php endif; ?>
        <button class="tab-btn" id="tab-btn-liquidacion" role="tab" aria-controls="tab-liquidacion" aria-selected="false">
            <i class="bi bi-percent"></i>&nbsp; Liquidación
        </button>
        <button class="tab-btn" id="tab-btn-franquicias-detalle" role="tab" aria-controls="tab-franquicias-detalle" aria-selected="false">
            <i class="bi bi-calendar3"></i>&nbsp; Venta día por día
        </button>
        <button class="tab-btn" id="tab-btn-franquicias-resumen" role="tab" aria-controls="tab-franquicias-resumen" aria-selected="false">
            <i class="bi bi-grid-3x3-gap"></i>&nbsp; Resumen Anual Anterior
        </button>
        <button class="tab-reload-btn" id="btn-reload-tab" title="Recargar pestaña">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </nav>

    <!-- ══ PESTAÑA: KPIs ═══════════════════════════════════════════════ -->
    <div id="tab-kpis" class="tab-pane active" role="tabpanel" aria-labelledby="tab-btn-kpis">
        <main class="dash-content">

            <div class="kpis-toolbar">
                <button class="eye-toggle-btn" id="btn-toggle-importes" title="Ocultar importes">
                    <i class="bi bi-eye"></i>
                </button>
            </div>

            <!-- ── RESUMEN PRINCIPAL ──────────────────────────────── -->
            <div class="summary-row">

                <div class="summary-card fact">
                    <div class="summary-icon">💰</div>
                    <div class="summary-body">
                        <div class="summary-label">Ventas período actual</div>
                        <div class="summary-value" id="fact-act">—</div>
                        <div class="summary-var" id="fact-var">—</div>
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Período previo</div>
                        <div class="summary-prev-value" id="fact-prev">—</div>
                        <div class="summary-sparkline">
                            <canvas id="spark-fact" width="120" height="45"></canvas>
                            <button class="spark-expand-btn" data-spark="spark-fact" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button>
                        </div>
                    </div>
                </div>

                <div class="summary-card obj">
                    <div class="summary-icon">🎯</div>
                    <div class="summary-body">
                        <div class="summary-label">Objetivo a la fecha</div>
                        <div class="summary-value" id="obj-act">—</div>
                        <div class="summary-var" id="obj-var">—</div>
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Objetivo Total</div>
                        <div class="summary-prev-value" id="obj-total">—</div>
                        <div class="summary-sparkline">
                            <canvas id="spark-obj" width="120" height="45"></canvas>
                            <button class="spark-expand-btn" data-spark="spark-obj" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button>
                        </div>
                    </div>
                </div>

                <div class="summary-card unid">
                    <div class="summary-icon">📦</div>
                    <div class="summary-body">
                        <div class="summary-label">Unidades período actual</div>
                        <div class="summary-value" id="unid-act">—</div>
                        <div class="summary-var" id="unid-var">—</div>
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Período previo</div>
                        <div class="summary-prev-value" id="unid-prev">—</div>
                        <div class="summary-sparkline">
                            <canvas id="spark-unid" width="120" height="45"></canvas>
                            <button class="spark-expand-btn" data-spark="spark-unid" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button>
                        </div>
                    </div>
                </div>

                <div class="summary-card tickets">
                    <div class="summary-icon">🎟️</div>
                    <div class="summary-body">
                        <div class="summary-label">Tickets período actual</div>
                        <div class="summary-value" id="tickets-act">—</div>
                        <div class="summary-var" id="tickets-var">—</div>
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Período previo</div>
                        <div class="summary-prev-value" id="tickets-prev">—</div>
                        <div class="summary-sparkline">
                            <canvas id="spark-tickets-main" width="120" height="45"></canvas>
                            <button class="spark-expand-btn" data-spark="spark-tickets-main" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button>
                        </div>
                    </div>
                </div>

                <div class="summary-card conv">
                    <div class="summary-icon">🚶</div>
                    <div class="summary-body">
                        <div class="summary-label">Tasa de Conversión período actual</div>
                        <div class="summary-value" id="conv-act">—</div>
                        <div class="summary-var" id="conv-var">—</div>
                        <div class="summary-ingresados">
                            <i class="bi bi-people-fill"></i>
                            <span class="ingresados-val" id="conv-ingresos">—</span>
                            <span class="ingresados-label">ingresos</span>
                        </div>
                        <div class="summary-ingresados">
                            <i class="bi bi-person-walking"></i>
                            <span class="ingresados-val" id="conv-merodeo">—</span>
                            <span class="ingresados-label">merodeo</span>
                            <span class="ingresados-val" id="conv-atraccion" style="margin-left:6px">—</span>
                            <span class="ingresados-label">atracción</span>
                        </div>
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Período previo</div>
                        <div class="summary-prev-value" id="conv-prev">—</div>
                        <div class="summary-sparkline">
                            <canvas id="spark-conv" width="120" height="45"></canvas>
                            <button class="spark-expand-btn" data-spark="spark-conv" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button>
                        </div>
                    </div>
                </div>

            </div>
            <!-- /summary-row -->

            <!-- ── KPI GRID ───────────────────────────────────────── -->
            <div class="kpi-grid-header">
                <span class="kpi-section-label">KPIs de Performance</span>
                <button class="info-btn"
                    data-info-title="KPIs de Performance"
                    data-info-tips="El Benchmark refleja el promedio de toda la cadena para el mismo período seleccionado|Sirve como referencia para saber si el conjunto está por encima o por debajo de la media|La variación muestra el cambio respecto al período anterior seleccionado|Pasá el cursor sobre la variación para ver el valor del período anterior|Los gráficos muestran la evolución diaria del indicador — hacé clic en el ícono para ampliar">
                    <i class="bi bi-info-circle"></i>
                </button>
            </div>
            <div class="kpi-grid">

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">Ticket Promedio</span>
                        <div class="kpi-bench">
                            <span class="kpi-bench-label">Benchmark</span>
                            <span class="kpi-bench-val" id="card-tprom-bench">—</span>
                        </div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-tprom-val">—</div><div class="kpi-var" id="card-tprom-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-tprom" width="90" height="36"></canvas><button class="spark-expand-btn" data-spark="spark-tprom" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button></div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">T. Prom. 2do Prod.</span>
                        <div class="kpi-bench">
                            <span class="kpi-bench-label">Benchmark</span>
                            <span class="kpi-bench-val" id="card-tp2do-bench">—</span>
                        </div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-tp2do-val">—</div><div class="kpi-var" id="card-tp2do-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-tp2do" width="90" height="36"></canvas><button class="spark-expand-btn" data-spark="spark-tp2do" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button></div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">Tickets 2do. Producto</span>
                        <div class="kpi-bench">
                            <span class="kpi-bench-label">Benchmark</span>
                            <span class="kpi-bench-val" id="card-t2do-bench">—</span>
                        </div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-t2do-val">—</div><div class="kpi-var" id="card-t2do-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-t2do" width="90" height="36"></canvas><button class="spark-expand-btn" data-spark="spark-t2do" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button></div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">Tickets 3er. Producto</span>
                        <div class="kpi-bench">
                            <span class="kpi-bench-label">Benchmark</span>
                            <span class="kpi-bench-val" id="card-t3ro-bench">—</span>
                        </div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-t3ro-val">—</div><div class="kpi-var" id="card-t3ro-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-t3ro" width="90" height="36"></canvas><button class="spark-expand-btn" data-spark="spark-t3ro" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button></div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">% Cambios</span>
                        <div class="kpi-bench">
                            <span class="kpi-bench-label">Benchmark</span>
                            <span class="kpi-bench-val" id="card-cambios-bench">—</span>
                        </div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-cambios-val">—</div><div class="kpi-var" id="card-cambios-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-cambios" width="90" height="36"></canvas><button class="spark-expand-btn" data-spark="spark-cambios" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button></div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">% Incremental</span>
                        <div class="kpi-bench">
                            <span class="kpi-bench-label">Benchmark</span>
                            <span class="kpi-bench-val" id="card-incr-bench">—</span>
                        </div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-incr-val">—</div><div class="kpi-var" id="card-incr-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-incr" width="90" height="36"></canvas><button class="spark-expand-btn" data-spark="spark-incr" title="Ver detalle"><i class="bi bi-arrows-angle-expand"></i></button></div>
                    </div>
                </div>

            </div>
            <!-- /kpi-grid -->

            <!-- ── TABLA + RANKING UNIDADES ──────────────────────── -->
            <div class="bottom-section">

                <div class="table-card">
                    <div class="table-card-header">
                        <i class="bi bi-table"></i>&nbsp; Facturación vs Objetivos por Sucursal
                    </div>
                    <div class="table-wrap">
                        <table id="tabla-sucursales">
                            <thead>
                                <tr>
                                    <th>Sucursal</th>
                                    <th style="text-align:right">Facturación</th>
                                    <th style="text-align:right">Fact. Año Ant.</th>
                                    <th style="text-align:right">Var. Fact.</th>
                                    <th style="text-align:right">Objetivo Total</th>
                                    <th style="text-align:right">Objetivo Fecha</th>
                                    <th style="text-align:right">Desvío</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-3);">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </main>
    </div>
    <!-- /tab-kpis -->

    <!-- ══ PESTAÑA: ANÁLISIS ═══════════════════════════════════════════ -->
    <div id="tab-analisis" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-analisis">
        <div class="analisis-content dash-content">

            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-boxes"></i> Rubros Clave — Unidades
                </div>
                <div style="padding:14px">
                    <div class="rubros-cards-grid" id="analisis-rubros-cards">
                        <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                    </div>
                </div>
            </div>

            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-bar-chart-fill"></i> Ranking Rubros — Facturación
                </div>
                <div id="analisis-ranking-facturacion" style="padding:8px 12px;max-height:300px;overflow-y:auto">
                    <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                </div>
            </div>

            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-bar-chart-fill"></i> Ranking Rubros — Unidades
                </div>
                <div id="analisis-ranking-unidades" style="padding:8px 12px;max-height:300px;overflow-y:auto">
                    <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                </div>
            </div>

            <div class="analisis-tablas-row">
                <div class="analisis-card">
                    <div class="analisis-section-header">
                        <i class="bi bi-diagram-3-fill"></i> Jerarquía Destino → Rubro → Categoría
                        <span id="jerarquia-periodo-sub" style="margin-left:12px;font-size:.72rem;opacity:.75;font-family:var(--font-body);font-weight:400"></span>
                    </div>
                    <div class="jerarquia-wrap" style="overflow-x:auto;max-height:480px;overflow-y:auto">
                        <table class="tabla-jerarquia" id="tabla-jerarquia">
                            <thead>
                                <tr>
                                    <th style="min-width:220px">Destino / Rubro / Categoría</th>
                                    <th style="min-width:110px;text-align:right">Unid. Actual</th>
                                    <th style="min-width:110px;text-align:right">Unid. Anterior</th>
                                    <th style="min-width:90px;text-align:right">Var. %</th>
                                    <th style="min-width:130px;text-align:right">Facturación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="5" class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="analisis-card">
                    <div class="analisis-section-header">
                        <i class="bi bi-person-lines-fill"></i> Análisis por Vendedor
                    </div>
                    <div style="overflow-x:auto;max-height:480px;overflow-y:auto">
                        <table class="tabla-vendedores-analisis" id="tabla-vendedores-analisis">
                            <thead>
                                <tr>
                                    <th style="min-width:160px">Vendedor</th>
                                    <th style="min-width:130px;text-align:right">Facturación</th>
                                    <th style="min-width:130px;text-align:right">Fact. Ant.</th>
                                    <th style="min-width:90px;text-align:right">Var. Fact.</th>
                                    <th style="min-width:100px;text-align:right">Unid. Act.</th>
                                    <th style="min-width:100px;text-align:right">Unid. Ant.</th>
                                    <th style="min-width:90px;text-align:right">Var. Unid.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7" class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <i class="bi bi-graph-up"></i> <span id="evolucion-titulo">Evolución Mensual — Unidades</span>
                        <div class="evolucion-metric-switch" id="evolucion-metric-switch">
                            <button class="evol-btn active" data-evol-metrica="unidades">Unidades</button>
                            <button class="evol-btn" data-evol-metrica="tickets">Tickets</button>
                            <button class="evol-btn" data-evol-metrica="facturacion">Facturación</button>
                        </div>
                        <button class="tab-reload-btn" data-evolucion-tipo="unidades"
                                id="evolucion-expand-btn"
                                title="Ampliar gráfico">
                            <i class="bi bi-arrows-angle-expand"></i>
                        </button>
                    </div>
                    <div class="chart-canvas-wrap">
                        <canvas id="chart-evolucion-unidades"></canvas>
                    </div>
                </div>
            </div>

            <div class="donuts-row" style="margin-top:20px">
                <div class="donut-card">
                    <div class="donut-card-header">
                        <i class="bi bi-pie-chart-fill"></i>&nbsp; Participación Unidades
                        <button class="info-btn"
                            data-info-title="Participación Unidades"
                            data-info-tips="Hacé clic en un segmento para ver el detalle|Pasá el cursor sobre cada segmento para ver porcentaje y unidades">
                            <i class="bi bi-info-circle"></i>
                        </button>
                    </div>
                    <div id="donut-unidades-wrap" class="donut-container"></div>
                </div>

                <div class="donut-card">
                    <div class="donut-card-header">
                        <i class="bi bi-pie-chart-fill"></i>&nbsp; Participación Facturación
                        <button class="info-btn"
                            data-info-title="Participación Facturación"
                            data-info-tips="Hacé clic en un segmento para ver el detalle|Pasá el cursor sobre cada segmento para ver porcentaje e importe">
                            <i class="bi bi-info-circle"></i>
                        </button>
                    </div>
                    <div id="donut-facturacion-wrap" class="donut-container"></div>
                </div>

                <div class="donut-card">
                    <div class="donut-card-header">
                        <i class="bi bi-credit-card-fill"></i>&nbsp; Medio de Pago
                        <span style="margin-left:6px;font-size:.70rem;opacity:.8;font-weight:400">clic TARJETA → cuotas</span>
                    </div>
                    <div id="medios-pago-wrap" class="donut-container" style="flex-direction:row;align-items:stretch;padding:8px">
                        <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <!-- /tab-analisis -->

    <?php if ($mostrarTabCirculacion): ?>
    <!-- ══ PESTAÑA: CIRCULACIÓN ═════════════════════════════════════════ -->
    <div id="tab-circulacion" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-circulacion">
        <main class="dash-content">

            <!-- Chip de cobertura -->
            <div class="circ-chip" id="circ-cobertura-chip"><i class="bi bi-broadcast"></i> — de — sucursales con sensor de tráfico en el período</div>

            <!-- KPI cards -->
            <div class="kpi-grid" id="circ-kpi-row">
                <div class="kpi-card">
                    <div class="kpi-card-header"><span class="kpi-title">Merodeo</span></div>
                    <div class="kpi-card-body"><div><div class="kpi-val" id="circ-merodeo-val">—</div><div class="kpi-var" id="circ-merodeo-var">—</div></div></div>
                    <div class="kpi-prev-label">Período previo: <span id="circ-merodeo-prev">—</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-card-header"><span class="kpi-title">Ingresos</span></div>
                    <div class="kpi-card-body"><div><div class="kpi-val" id="circ-ingresos-val">—</div><div class="kpi-var" id="circ-ingresos-var">—</div></div></div>
                    <div class="kpi-prev-label">Período previo: <span id="circ-ingresos-prev">—</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-card-header"><span class="kpi-title">Tasa de Atracción</span></div>
                    <div class="kpi-card-body"><div><div class="kpi-val" id="circ-atraccion-val">—</div><div class="kpi-var" id="circ-atraccion-var">—</div></div></div>
                    <div class="kpi-prev-label">Período previo: <span id="circ-atraccion-prev">—</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-card-header"><span class="kpi-title">Tickets</span></div>
                    <div class="kpi-card-body"><div><div class="kpi-val" id="circ-tickets-val">—</div><div class="kpi-var" id="circ-tickets-var">—</div></div></div>
                    <div class="kpi-prev-label">Período previo: <span id="circ-tickets-prev">—</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-card-header"><span class="kpi-title">Tasa de Conversión</span></div>
                    <div class="kpi-card-body"><div><div class="kpi-val" id="circ-conversion-val">—</div><div class="kpi-var" id="circ-conversion-var">—</div></div></div>
                    <div class="kpi-prev-label">Período previo: <span id="circ-conversion-prev">—</span></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-card-header"><span class="kpi-title">Venta por Visitante</span></div>
                    <div class="kpi-card-body"><div><div class="kpi-val" id="circ-vpv-val">—</div><div class="kpi-var" id="circ-vpv-var">—</div></div></div>
                    <div class="kpi-prev-label">Período previo: <span id="circ-vpv-prev">—</span></div>
                </div>
            </div>

            <!-- Embudo -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-filter-circle-fill"></i> Embudo de Circulación
                </div>
                <div class="funnel-row" id="circ-funnel"></div>
            </div>

            <!-- Evolución mensual -->
            <div class="analisis-card" style="margin-bottom: 20px; min-height: 380px; display:flex; flex-direction:column;">
                <div class="analisis-section-header">
                    <i class="bi bi-graph-up"></i> Evolución Mensual
                    <select class="circ-meses-select" id="sel-circulacion-meses">
                        <option value="12">Últimos 12 meses</option>
                        <option value="24">Últimos 24 meses</option>
                    </select>
                </div>
                <div style="flex:1; padding:12px; position:relative; min-height: 300px;">
                    <canvas id="chart-circulacion-evol"></canvas>
                </div>
            </div>

            <!-- Matriz de cuadrantes -->
            <div class="analisis-card" style="margin-bottom: 20px; min-height: 420px; display:flex; flex-direction:column;">
                <div class="analisis-section-header">
                    <i class="bi bi-grid-3x3-gap-fill"></i> Matriz de Cuadrantes — Atracción vs. Conversión
                </div>
                <div style="flex:1; padding:12px; position:relative; min-height: 300px;">
                    <canvas id="chart-circulacion-quadrant"></canvas>
                </div>
                <div class="circ-quadrant-legend">
                    <div class="qd-item"><span class="qd-dot" style="background:#22c55e">1</span><span><strong>Atracción alta + Conversión alta:</strong> desempeño destacado en ambas puntas del embudo.</span></div>
                    <div class="qd-item"><span class="qd-dot" style="background:#f97316">2</span><span><strong>Atracción alta + Conversión baja:</strong> problema de atención en salón — entran pero no compran, desperdiciando el tráfico logrado.</span></div>
                    <div class="qd-item"><span class="qd-dot" style="background:#eab308">3</span><span><strong>Atracción baja + Conversión alta:</strong> problema de vidriera — pasan pero no entran, aunque el que entra compra.</span></div>
                    <div class="qd-item"><span class="qd-dot" style="background:#dc2626">4</span><span><strong>Atracción baja + Conversión baja:</strong> oportunidad de mejora integral (vidriera y salón).</span></div>
                </div>
                <div class="circ-quadrant-legend-note">
                    <i class="bi bi-info-circle"></i> El número y color de cada punto identifican su cuadrante (1 mejor → 4 peor). El <strong>tamaño</strong> respeta esa misma jerarquía — un punto del cuadrante 1 siempre se ve más grande que uno de cuadrante 2, 3 o 4 — y dentro de un mismo cuadrante, cuanto mayor la eficiencia real (de cada 100 personas que pasan, cuántas terminan comprando), más grande el punto.
                </div>
            </div>

            <!-- Tabla por sucursal -->
            <div class="table-card">
                <div class="table-card-header" id="circulacion-tabla-header">
                    <i class="bi bi-table"></i>&nbsp; Circulación por Sucursal
                    <div style="margin-left:auto;display:flex;align-items:center;gap:10px"></div>
                </div>
                <div class="table-wrap" style="overflow-x:auto">
                    <table id="table-circulacion-sucursales" style="width:100%;border-collapse:collapse;font-size:.82rem">
                        <thead>
                            <tr style="background:var(--bg-header);color:#fff">
                                <th data-col="desc_sucursal" style="padding:7px 10px;text-align:left">Sucursal</th>
                                <th data-col="merodeo"             style="padding:7px 10px;text-align:right">Merodeo</th>
                                <th data-col="ingresos"            style="padding:7px 10px;text-align:right">Ingresos</th>
                                <th data-col="tickets"             style="padding:7px 10px;text-align:right">Tickets</th>
                                <th data-col="facturacion"         style="padding:7px 10px;text-align:right">Facturación</th>
                                <th data-col="atraccion"           style="padding:7px 10px;text-align:right">Atracción %</th>
                                <th data-col="conversion"          style="padding:7px 10px;text-align:right">Conversión %</th>
                                <th data-col="ticket_promedio"     style="padding:7px 10px;text-align:right">Ticket prom.</th>
                                <th data-col="venta_por_visitante" style="padding:7px 10px;text-align:right">Venta / visitante</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-circulacion-sucursales">
                            <tr><td colspan="9" class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>
    <!-- /tab-circulacion -->
    <?php endif; ?>

    <!-- ══ PESTAÑA: PRODUCTO ════════════════════════════════════════════ -->
    <div id="tab-producto" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-producto">
        <div class="dash-content">

            <!-- Filtro local de categoría -->
            <div class="producto-filter-bar">
                <label for="sel-prod-categoria">Categoría</label>
                <select id="sel-prod-categoria">
                    <option value="">Todas</option>
                </select>
                <button id="btn-prod-aplicar"><i class="bi bi-check2"></i> Aplicar</button>
            </div>

            <!-- Fila superior: Rubros/Categorías + Colores -->
            <div class="producto-top">

                <!-- Árbol RUBRO → CATEGORÍA -->
                <div class="analisis-card" style="margin:0;min-width:0">
                    <div class="analisis-section-header">
                        <i class="bi bi-diagram-2"></i> Rubro / Categoría
                    </div>
                    <div class="prod-table-scroll" id="prod-rubros-wrap">
                        <div class="prod-loading"><i class="bi bi-arrow-repeat"></i> Cargando…</div>
                    </div>
                </div>

                <!-- Tabla de colores -->
                <div class="analisis-card" style="margin:0;min-width:0">
                    <div class="analisis-section-header">
                        <i class="bi bi-palette"></i> Colores
                    </div>
                    <div class="prod-table-scroll" id="prod-colores-wrap">
                        <div class="prod-loading"><i class="bi bi-arrow-repeat"></i> Cargando…</div>
                    </div>
                </div>

            </div>
            <!-- /producto-top -->

            <!-- Fila inferior: Sucursales | Top Categorías | Donut colores -->
            <div class="producto-mid">

                <!-- Col 1: Ranking sucursales -->
                <div class="analisis-card" style="margin:0">
                    <div class="analisis-section-header">
                        <i class="bi bi-building"></i> Unidades por Sucursal
                    </div>
                    <div id="prod-suc-wrap" style="padding:10px 14px;max-height:480px;overflow-y:auto">
                        <div class="prod-loading"><i class="bi bi-arrow-repeat"></i> Cargando…</div>
                    </div>
                </div>

                <!-- Col 2: Top 10 categorías -->
                <div class="analisis-card" style="margin:0">
                    <div class="analisis-section-header">
                        <i class="bi bi-bar-chart-steps"></i> Top Categorías
                    </div>
                    <div id="prod-top-wrap" class="prod-top-wrap">
                        <div class="prod-loading"><i class="bi bi-arrow-repeat"></i> Cargando…</div>
                    </div>
                </div>

                <!-- Col 3: Donut colores -->
                <div class="analisis-card" style="margin:0">
                    <div class="analisis-section-header">
                        <i class="bi bi-pie-chart"></i> Participación Colores
                    </div>
                    <div class="prod-chart-wrap" style="height:260px;padding:10px">
                        <canvas id="prod-donut-colores"></canvas>
                    </div>
                </div>

            </div>
            <!-- /producto-mid -->

        </div>
    </div>
    <!-- /tab-producto -->

    <!-- ══ PESTAÑA: CADENA ══════════════════════════════════════════════ -->
    <?php if (!$isGrupo || $esGrupo): ?>
    <div id="tab-cadena" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-cadena">
        <div class="dash-content">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-diagram-3"></i> KPIs por Sucursal — Cadena completa
                    <button class="info-btn" style="margin-left:auto"
                        data-info-title="Cadena completa"
                        data-info-tips="Hacé clic en un encabezado para ordenar|Los colores indican posición relativa dentro de la cadena|Scroll horizontal para ver todas las métricas">
                        <i class="bi bi-info-circle"></i>
                    </button>
                </div>
                <div id="cadena-wrap" style="overflow-x:auto">
                    <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                </div>
            </div>
        </div>
    </div>
    <!-- /tab-cadena -->
    <?php endif; ?>

    <!-- ══ PESTAÑA: PARTICIPACIÓN ════════════════════════════════════════ -->
    <?php if (!$isGrupo || $esGrupo): ?>
    <div id="tab-participacion" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-participacion">
        <div class="dash-content">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-grid-3x3-gap-fill"></i> Participación por Rubro y Sucursal
                    <div style="margin-left:auto;display:flex;align-items:center;gap:10px">
                        <span style="font-size:.73rem;color:var(--text-3)">Top 5 rubros · % Facturación / % Unidades</span>
                        <button class="info-btn"
                            data-info-title="Participación por Rubro"
                            data-info-tips="Filas = sucursales agrupadas por grupo|Columnas = top 5 rubros por facturación|Cada celda muestra % Facturación (arriba) y % Unidades (abajo)|La intensidad del color compara sucursales dentro del mismo rubro">
                            <i class="bi bi-info-circle"></i>
                        </button>
                    </div>
                </div>
                <div id="participacion-wrap" style="overflow-x:auto;overflow-y:auto;max-height:600px">
                    <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                </div>
            </div>
        </div>
    </div>
    <!-- /tab-participacion -->
    <?php endif; ?>

    <!-- ══ PESTAÑA: VENDEDORAS ═══════════════════════════════════════════ -->
    <div id="tab-vendedoras" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-vendedoras">
        <div class="dash-content">

            <!-- Top 10 -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-trophy-fill"></i> Top Ten Vendedoras
                </div>
                <div style="padding:14px 16px 10px">
                    <div id="top10-wrap">
                        <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                    </div>
                </div>
            </div>

            <!-- KPIs tabla + Versus en la misma fila -->
            <div class="vendedoras-main-grid">

                <!-- KPIs tabla completa -->
                <div class="analisis-card" style="margin:0">
                    <div class="analisis-section-header">
                        <i class="bi bi-table"></i> KPIs por Vendedora
                    </div>
                    <div style="overflow-x:auto;max-height:420px;overflow-y:auto">
                        <table id="tabla-kpis-vendedoras" class="tabla-jerarquia">
                            <thead>
                                <tr>
                                    <th style="min-width:140px">Vendedora</th>
                                    <th style="min-width:110px;text-align:right">Facturación</th>
                                    <th style="min-width:75px;text-align:right">Unidades</th>
                                    <th style="min-width:65px;text-align:right">Tickets</th>
                                    <th style="min-width:105px;text-align:right">Ticket Prom.</th>
                                    <th style="min-width:85px;text-align:right">% 2do Prod.</th>
                                    <th style="min-width:85px;text-align:right">% 3er Prod.</th>
                                    <th style="min-width:85px;text-align:right">% Cambios</th>
                                    <th style="min-width:95px;text-align:right">% Incremental</th>
                                    <th style="min-width:95px;text-align:right">% Presencialidad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="10" style="text-align:center;padding:24px;color:var(--text-3);">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Versus -->
                <div class="analisis-card" style="margin:0">
                    <div class="analisis-section-header">
                        <i class="bi bi-arrow-left-right"></i> Versus
                    </div>
                    <div style="padding:10px 12px;border-bottom:1px solid var(--border);display:flex;flex-direction:column;gap:8px">
                        <select id="sel-versus-a" style="font-size:.82rem;padding:4px 10px;border-radius:4px;border:1px solid var(--border);width:100%">
                            <option value="">— Vendedora A —</option>
                        </select>
                        <select id="sel-versus-b" style="font-size:.82rem;padding:4px 10px;border-radius:4px;border:1px solid var(--border);width:100%">
                            <option value="">— Vendedora B —</option>
                        </select>
                        <button id="btn-versus" class="btn-aplicar" style="padding:5px 14px;font-size:.82rem;width:100%">
                            <i class="bi bi-arrow-left-right"></i> Comparar
                        </button>
                    </div>
                    <div id="versus-wrap" style="padding:12px">
                        <div style="color:var(--text-3);font-size:.85rem">Seleccioná dos vendedoras para comparar.</div>
                    </div>
                </div>

            </div>

        </div>
    </div>
    <!-- /tab-vendedoras -->

    <!-- ══ PESTAÑA: RANKING ══════════════════════════════════════════════ -->
    <?php if (!$isGrupo || $esGrupo): ?>
    <div id="tab-ranking" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-ranking">
        <main class="dash-content">

            <div class="ranking-toolbar">
                <h2 class="section-title"><i class="bi bi-trophy" style="color:#eab308"></i>&nbsp; Ranking de Sucursales</h2>
            </div>

            <!-- Tabla + sidebar -->
            <div class="ranking-main-layout">

                <!-- Tabla principal -->
                <div class="ranking-table-wrap" id="ranking-table-wrap">
                    <table class="ranking-table" id="ranking-table">
                        <thead>
                            <tr>
                                <th data-col="rank">#</th>
                                <th data-col="nombre">Sucursal</th>
                                <th data-col="score">Score</th>
                                <th data-col="facturacion">Ventas</th>
                                <th data-col="cumplimiento">Cumpl. Obj.</th>
                                <th data-col="delta_var_fact">&#916; Var. Ventas</th>
                                <th data-col="ticket_promedio">T. Promedio</th>
                                <th data-col="delta_var_tickets">&#916; Var. Tick.</th>
                                <th data-col="delta_var_unidades">&#916; Var. Unid.</th>
                                <th data-col="porc_2do">% 2do</th>
                                <th data-col="porc_3ro">% 3er</th>
                                <th data-col="porc_incremental">% Increm.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="12" style="text-align:center;padding:24px;color:var(--text-3)">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Cómo se calcula (sidebar) -->
                <div class="ranking-info-box">
                    <h4><i class="bi bi-info-circle"></i>&nbsp; ¿Cómo se calcula?</h4>

                    <p class="rk-info-lead">El score mide qué tan bien le fue a una sucursal <strong>en comparación con el promedio de toda la cadena</strong>. No importa si las ventas fueron altas o bajas en términos absolutos — lo que importa es si la sucursal estuvo por encima o por debajo del promedio en cada indicador.</p>

                    <div class="rk-steps">
                        <div class="rk-step">
                            <span class="rk-step-num">1</span>
                            <div>
                                <strong>Se calculan 8 KPIs</strong> por sucursal (ventas, tickets, ticket promedio, etc.) y se obtiene el promedio de la cadena para cada uno.
                            </div>
                        </div>
                        <div class="rk-step">
                            <span class="rk-step-num">2</span>
                            <div>
                                <strong>Se normaliza</strong> cada KPI dividiendo el valor de la sucursal por el promedio. Si el resultado es 1.0, está en el promedio; 1.2 significa 20% mejor; 0.8 significa 20% peor.
                                <p style="font-size:.71rem;color:#7b8fc0;margin-top:6px;font-style:italic">
                                    Las variaciones (Ventas, Unidades, Tickets) miden la <strong>mejora del ritmo</strong>: cuánto creció este año vs cuánto había crecido el año pasado, expresado en puntos porcentuales (pp). Una sucursal con la misma tendencia que el año anterior tiene delta = 0pp.
                                </p>
                            </div>
                        </div>
                        <div class="rk-step">
                            <span class="rk-step-num">3</span>
                            <div>
                                <strong>Se pondera</strong> cada KPI según su importancia y se suman. El resultado se multiplica por 100.
                            </div>
                        </div>
                    </div>

                    <div class="rk-example">
                        <div class="rk-example-title"><i class="bi bi-calculator"></i> Ejemplo — Delta Var. Ventas (Portal Rosario)</div>
                        <p style="font-size:.72rem;color:#7b8fc0;margin:0 0 8px">Este año creció <strong style="color:#c9d4f0">+51,1%</strong> vs año anterior; el año pasado había caído <strong style="color:#c9d4f0">-25,6%</strong> vs dos años atrás:</p>
                        <div class="rk-example-calc">
                            <div class="rk-calc-row">
                                <span>Var. actual</span><strong style="color:#22c55e">+51,1%</strong>
                            </div>
                            <div class="rk-calc-row">
                                <span>Var. anterior</span><strong style="color:#ef4444">-25,6%</strong>
                            </div>
                            <div class="rk-calc-row">
                                <span>Delta (pp)</span><strong>51,1 − (−25,6) = <span style="color:#22c55e">+76,7 pp</span></strong>
                            </div>
                            <div class="rk-calc-row">
                                <span>Norm</span><strong>1 + 0,767 = <span style="color:#22c55e">1,77</span></strong>
                            </div>
                            <div class="rk-calc-row">
                                <span>Peso del KPI</span><strong>15%</strong>
                            </div>
                            <div class="rk-calc-row rk-calc-result">
                                <span>Aporte al score</span><strong>1,77 × 15 = <span style="color:#22c55e">26,5 pts</span></strong>
                            </div>
                        </div>
                        <p style="font-size:.71rem;color:#7b8fc0;margin:8px 0 0">Una sucursal nueva (sin datos de dos años atrás) tiene delta = 0pp → norm = 1,0 → aporte neutro de 15 pts.</p>
                    </div>

                    <div class="rk-legend">
                        <div class="rk-legend-item"><span class="rk-dot dot-green"></span><strong>≥ 110</strong> — Por encima del promedio</div>
                        <div class="rk-legend-item"><span class="rk-dot dot-yellow"></span><strong>95 – 110</strong> — En línea con el promedio</div>
                        <div class="rk-legend-item"><span class="rk-dot dot-red"></span><strong>&lt; 95</strong> — Por debajo del promedio</div>
                    </div>

                    <details class="rk-weights-detail">
                        <summary>Ver pesos por KPI</summary>
                        <table class="weight-table">
                            <thead><tr><th>KPI</th><th>Peso</th></tr></thead>
                            <tbody>
                                <tr><td>Cumpl. Objetivo</td><td>30%</td></tr>
                                <tr><td>&#916; Var. Ventas</td><td>15%</td></tr>
                                <tr><td>Ticket Promedio</td><td>15%</td></tr>
                                <tr><td>&#916; Var. Tickets</td><td>10%</td></tr>
                                <tr><td>&#916; Var. Unidades</td><td>10%</td></tr>
                                <tr><td>% 2do Producto</td><td>10%</td></tr>
                                <tr><td>% 3er Producto</td><td>5%</td></tr>
                                <tr><td>% Incremental</td><td>5%</td></tr>
                            </tbody>
                        </table>
                    </details>

                    <p style="margin-top:10px;font-size:.72rem;color:#8e96ae">Hacé clic en una fila para ver el detalle de cada KPI.</p>
                </div>

            </div><!-- /ranking-main-layout -->

        </main>
    </div>
    <!-- /tab-ranking -->
    <?php endif; ?>

    <!-- ══ PESTAÑA: FRANQUICIAS DETALLE (Venta día por día) ════════════ -->
    <div id="tab-franquicias-detalle" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-franquicias-detalle">
        <main class="dash-content">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-calendar3"></i>&nbsp;<span id="title-franquicias-detalle">Venta Día por Día — Locales Propios</span>
                    <div style="margin-left:auto; display:flex; gap:10px;">
                        <button id="btn-export-franquicias-detalle" class="btn-volver" style="background:#16a34a">
                            <i class="bi bi-file-earmark-excel"></i> Exportar
                        </button>
                    </div>
                </div>
                <div id="franquicias-detalle-wrap" style="overflow:auto; max-height:calc(100vh - 220px); padding:12px">
                    <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                </div>
            </div>
        </main>
    </div>

    <!-- ══ PESTAÑA: FRANQUICIAS RESUMEN (Resumen Anual Anterior) ════════════ -->
    <div id="tab-franquicias-resumen" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-franquicias-resumen">
        <main class="dash-content">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-grid-3x3-gap"></i>&nbsp;<span id="title-franquicias-resumen">Resumen Anual Anterior — Locales Propios</span>
                    <div style="margin-left:auto; display:flex; gap:10px;">
                        <button id="btn-export-franquicias-resumen" class="btn-volver" style="background:#16a34a">
                            <i class="bi bi-file-earmark-excel"></i> Exportar
                        </button>
                    </div>
                </div>
                <div id="franquicias-resumen-wrap" style="overflow:auto; max-height:calc(100vh - 220px); padding:12px">
                    <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                </div>
            </div>
        </main>
    </div>

    <!-- ══ PESTAÑA: LIQUIDACIÓN ════════════════════════════════════════ -->
    <div id="tab-liquidacion" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-liquidacion">
        <main class="dash-content">
            <div style="background: rgba(0, 168, 120, 0.1); border-left: 4px solid #00a878; padding: 12px; margin-bottom: 20px; border-radius: 4px; color: #fff; display: flex; align-items: center; gap: 10px;">
                <i class="bi bi-info-circle-fill" style="color: #00a878; font-size: 1.2rem;"></i>
                <div>
                    <strong>Período de Liquidación:</strong> Análisis fijo del <strong>01/07 al 16/08</strong>.
                    El año anterior por el momento no filtra por artículos específicos (luego se incorporará según la definición de artículos correspondientes).
                </div>
            </div>

            <!-- Active Filters Bar -->
            <div id="liq-active-filters-bar" style="display: none; background: rgba(59, 130, 246, 0.1); border-left: 4px solid #3b82f6; padding: 12px; margin-bottom: 20px; border-radius: 4px; color: #fff; align-items: center; justify-content: space-between;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="bi bi-funnel-fill" style="color: #3b82f6; font-size: 1.2rem;"></i>
                    <span id="liq-active-filters-text">Filtros activos: ninguno</span>
                </div>
                <button id="liq-btn-clear-all-filters" style="padding: 4px 10px; font-size: 0.8rem; background: #ef4444 !important; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">
                    Limpiar Filtros <i class="bi bi-x-circle"></i>
                </button>
            </div>

            <!-- KPIs exactly matching summary-row -->
            <div class="summary-row" style="margin-bottom: 20px;">
                <div class="summary-card fact">
                    <div class="summary-icon">💰</div>
                    <div class="summary-body">
                        <div class="summary-label">Facturación liquidación</div>
                        <div class="summary-value" id="liq-kpi-facturacion">—</div>
                        <div class="summary-var" id="liq-kpi-facturacion-var" style="display:none">—</div>
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Año anterior</div>
                        <div class="summary-prev-value" id="liq-kpi-facturacion-prev">—</div>
                    </div>
                </div>

                <div class="summary-card unid">
                    <div class="summary-icon">📦</div>
                    <div class="summary-body">
                        <div class="summary-label">Unidades liquidación</div>
                        <div class="summary-value" id="liq-kpi-unidades">—</div>
                        <div class="summary-var" id="liq-kpi-unidades-var" style="display:none">—</div>
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Año anterior</div>
                        <div class="summary-prev-value" id="liq-kpi-unidades-prev">—</div>
                    </div>
                </div>

                <div class="summary-card tickets">
                    <div class="summary-icon">🎟️</div>
                    <div class="summary-body">
                        <div class="summary-label">Tickets liquidación</div>
                        <div class="summary-value" id="liq-kpi-tickets">—</div>
                        <div class="summary-var" id="liq-kpi-tickets-var" style="display:none">—</div>
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Año anterior</div>
                        <div class="summary-prev-value" id="liq-kpi-tickets-prev">—</div>
                    </div>
                </div>

                <div class="summary-card obj">
                    <div class="summary-icon">🏷️</div>
                    <div class="summary-body">
                        <div class="summary-label">Referencias (SKUs) liq.</div>
                        <div class="summary-value" id="liq-kpi-referencias">—</div>
                        <div class="summary-var" id="liq-kpi-referencias-var" style="display:none">—</div>
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Año anterior</div>
                        <div class="summary-prev-value" id="liq-kpi-referencias-prev">—</div>
                    </div>
                </div>

                <div class="summary-card obj">
                    <div class="summary-icon">💵</div>
                    <div class="summary-body">
                        <div class="summary-label">Ticket promedio liq.</div>
                        <div class="summary-value" id="liq-kpi-promedio">—</div>
                        <div class="summary-var" id="liq-kpi-promedio-var" style="display:none">—</div>
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Año anterior</div>
                        <div class="summary-prev-value" id="liq-kpi-promedio-prev">—</div>
                    </div>
                </div>
            </div> <!-- Close summary-row -->

            <!-- Timeline Chart Card -->
            <div class="analisis-card" style="margin-bottom: 20px; min-height: 380px; display:flex; flex-direction:column;">
                <div class="analisis-section-header">
                    <i class="bi bi-graph-up"></i> Evolución Diaria de Ventas — Liquidación vs Normal vs Año Anterior (Total)
                </div>
                <div style="flex:1; padding:12px; position:relative; min-height: 300px;">
                    <canvas id="liq-chart-timeline"></canvas>
                </div>
            </div>

            <!-- Donut Charts Row -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px; align-items: start;">
                <!-- Donut Chart Facturación -->
                <div class="analisis-card" style="min-height: 380px; display:flex; flex-direction:column;">
                    <div class="analisis-section-header">
                        <i class="bi bi-pie-chart"></i> Participación Facturación — Liquidación vs Normal
                    </div>
                    <div style="flex:1; padding:12px; position:relative; min-height: 300px; display:flex; flex-direction:column;">
                        <div id="liq-donut-fact-breadcrumb" style="display:none; margin-bottom:8px;">
                            <button id="liq-btn-back-donut-fact" style="padding: 4px 8px; font-size: 0.8rem; background: var(--accent2) !important; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
                                <i class="bi bi-arrow-left"></i> Volver
                            </button>
                        </div>
                        <div style="flex:1; position:relative;">
                            <canvas id="liq-donut-facturacion"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Donut Chart Unidades -->
                <div class="analisis-card" style="min-height: 380px; display:flex; flex-direction:column;">
                    <div class="analisis-section-header">
                        <i class="bi bi-pie-chart"></i> Participación Unidades — Liquidación vs Normal
                    </div>
                    <div style="flex:1; padding:12px; position:relative; min-height: 300px; display:flex; flex-direction:column;">
                        <div id="liq-donut-unid-breadcrumb" style="display:none; margin-bottom:8px;">
                            <button id="liq-btn-back-donut-unid" style="padding: 4px 8px; font-size: 0.8rem; background: var(--accent2) !important; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
                                <i class="bi bi-arrow-left"></i> Volver
                            </button>
                        </div>
                        <div style="flex:1; position:relative;">
                            <canvas id="liq-donut-unidades"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Donut Chart Referencias (SKUs) -->
                <div class="analisis-card" style="min-height: 380px; display:flex; flex-direction:column;">
                    <div class="analisis-section-header">
                        <i class="bi bi-pie-chart"></i> Participación Referencias (SKUs) — Liquidación vs Normal
                    </div>
                    <div style="flex:1; padding:12px; position:relative; min-height: 300px; display:flex; flex-direction:column;">
                        <div id="liq-donut-ref-breadcrumb" style="display:none; margin-bottom:8px;">
                            <button id="liq-btn-back-donut-ref" style="padding: 4px 8px; font-size: 0.8rem; background: var(--accent2) !important; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
                                <i class="bi bi-arrow-left"></i> Volver
                            </button>
                        </div>
                        <div style="flex:1; position:relative;">
                            <canvas id="liq-donut-referencias"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Middle Row: Chart & Hierarchical Table -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; align-items: start;">
                <!-- Chart Rubros comparison -->
                <div class="analisis-card" style="min-height: 480px; display:flex; flex-direction:column;">
                    <div class="analisis-section-header">
                        <i class="bi bi-bar-chart-steps"></i> Participación Liquidación por Rubro (Venta 2026)
                    </div>
                    <div style="flex:1; padding:12px; position:relative; min-height: 400px; display:flex; flex-direction:column;">
                        <div id="liq-chart-breadcrumb" style="display:none; margin-bottom:8px;">
                            <button id="liq-btn-back-chart" style="padding: 4px 8px; font-size: 0.8rem; background: var(--accent2) !important; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
                                <i class="bi bi-arrow-left"></i> Volver a Rubros
                            </button>
                        </div>
                        <div style="flex:1; position:relative;">
                            <canvas id="liq-chart-rubros"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Progressive hierarchy -->
                <div class="analisis-card" style="min-height: 480px;">
                    <div class="analisis-section-header">
                        <i class="bi bi-diagram-3"></i> Apertura por Rubro / Categoría
                    </div>
                    <div style="max-height: 430px; overflow: auto; padding: 0 12px 12px;">
                        <table class="ranking-table" id="liq-table-jerarquia">
                            <thead>
                                <tr>
                                    <th>Rubro / Categoría</th>
                                    <th style="text-align: right;">Fact. Liq.</th>
                                    <th style="text-align: right;">Fact. Normal</th>
                                    <th style="text-align: right;">Unid. Liq.</th>
                                    <th style="text-align: right;">Unid. Normal</th>
                                    <th style="text-align: right;">Ref. Liq. (SKU)</th>
                                    <th style="text-align: right;">Ref. Normal (SKU)</th>
                                    <th style="text-align: right;">% Ref. Liq.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="8" style="text-align: center; padding: 20px;">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Detail Split Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start;">
                <!-- Sucursales -->
                <div class="analisis-card">
                    <div class="analisis-section-header">
                        <i class="bi bi-shop"></i> Ventas por Sucursal (Liquidación)
                    </div>
                    <div style="max-height: 500px; overflow: auto; padding: 0 12px 12px;">
                        <table class="ranking-table" id="liq-table-sucursales">
                            <thead>
                                <tr>
                                    <th>Sucursal</th>
                                    <th style="text-align: right;">Facturación</th>
                                    <th style="text-align: right;">Unidades</th>
                                    <th style="text-align: right;">Referencias (SKUs)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="4" style="text-align: center; padding: 20px;">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Articles -->
                <div class="analisis-card">
                    <div class="analisis-section-header">
                        <i class="bi bi-box-seam"></i> Artículos más Vendidos en Liquidación
                    </div>
                    <div style="max-height: 500px; overflow: auto; padding: 0 12px 12px;">
                        <table class="ranking-table" id="liq-table-productos">
                            <thead>
                                <tr>
                                    <th>Referencia (SKU)</th>
                                    <th>Descripción</th>
                                    <th>Temporada</th>
                                    <th style="text-align: right;">Cant</th>
                                    <th style="text-align: right;">Total $</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="5" style="text-align: center; padding: 20px;">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ══ MODAL EVOLUCIÓN MENSUAL ══════════════════════════════════════ -->
    <div id="evolucion-modal-overlay" style="display:none" class="spark-modal-overlay">
        <div class="spark-modal" style="width:min(900px,96vw);max-height:92vh">
            <div class="spark-modal-header">
                <span class="spark-modal-title" id="evolucion-modal-title">—</span>
                <div id="evolucion-modal-anios" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-left:16px"></div>
                <button class="spark-modal-close" id="evolucion-modal-close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="spark-modal-chart-wrap" style="min-height:400px">
                <canvas id="evolucion-modal-canvas"></canvas>
            </div>
        </div>
    </div>

    <!-- Modal de detalle de sucursal -->
    <div id="ranking-modal-overlay" class="ranking-modal-overlay" style="display:none">
        <div class="ranking-modal">
            <button class="ranking-modal-close" id="ranking-modal-close" title="Cerrar">&times;</button>
            <div class="ranking-modal-title">—</div>
            <div class="ranking-modal-subtitle">—</div>
            <div class="modal-score-display">
                <div>
                    <div class="modal-score-number">—</div>
                    <div class="modal-score-label">Score (100 = promedio)</div>
                </div>
                <div class="modal-score-rank">
                    <div class="rank-num">—</div>
                    <div class="rank-label">Posición</div>
                </div>
            </div>
            <div class="contrib-chart-wrap">
                <div class="contrib-chart-title">Contribución por KPI</div>
                <canvas id="contrib-chart" height="220"></canvas>
            </div>
            <div class="contrib-chart-title" style="margin-bottom:10px">Detalle por KPI</div>
            <div class="kpi-detail-grid"></div>
        </div>
    </div>

</div><!-- /dash-wrap -->

<!-- SheetJS (Excel export) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<!-- Shared BIUtils -->
<?php
$jsFiles = [
    '/bi/js/utils.js',
    '/bi/global/components/ExcelExporter.js',
    '/bi/global/js/dashboard.js',
    '/bi/global/js/analisis.js',
    '/bi/global/js/producto.js',
    '/bi/global/js/cadena.js',
    '/bi/global/js/participacion.js',
    '/bi/global/js/circulacion.js',
    '/bi/global/js/vendedoras.js',
    '/bi/global/js/ranking.js',
    '/bi/global/js/franquicias_detalle.js',
    '/bi/global/js/franquicias_resumen.js',
    '/bi/global/js/liquidacion.js',
];
foreach ($jsFiles as $f):
    $v = @filemtime($_SERVER['DOCUMENT_ROOT'] . $f) ?: 1;
?>
<script src="<?= $f ?>?v=<?= $v ?>"></script>
<?php endforeach; ?>

<script>
window.BI_CONFIG = {
    isGrupo:         <?= $isGrupo ? 'true' : 'false' ?>,
    esGrupo:         <?= $esGrupo ? 'true' : 'false' ?>,
    sucursalesGrupo: <?= json_encode($sucursalesGrupo) ?>,
    origenesConCirculacion: <?= json_encode(ORIGENES_CON_CIRCULACION) ?>
};
</script>

<script>
/**
 * Orquestador: tabs + aplicar + reload.
 * Lee los filtros del DOM y coordina los módulos.
 */
(function () {

    const TABS = [
        { btn: 'tab-btn-kpis',          pane: 'tab-kpis',          name: 'kpis'          },
        { btn: 'tab-btn-analisis',       pane: 'tab-analisis',       name: 'analisis'      },
        { btn: 'tab-btn-circulacion',    pane: 'tab-circulacion',    name: 'circulacion'   },
        { btn: 'tab-btn-producto',       pane: 'tab-producto',       name: 'producto'      },
        { btn: 'tab-btn-cadena',         pane: 'tab-cadena',         name: 'cadena'        },
        { btn: 'tab-btn-participacion',  pane: 'tab-participacion',  name: 'participacion' },
        { btn: 'tab-btn-vendedoras',     pane: 'tab-vendedoras',     name: 'vendedoras'    },
        { btn: 'tab-btn-ranking',        pane: 'tab-ranking',        name: 'ranking'       },
        { btn: 'tab-btn-liquidacion',    pane: 'tab-liquidacion',    name: 'liquidacion'   },
        { btn: 'tab-btn-franquicias-detalle', pane: 'tab-franquicias-detalle', name: 'franquiciasDetalle' },
        { btn: 'tab-btn-franquicias-resumen', pane: 'tab-franquicias-resumen', name: 'franquiciasResumen' },
    ].filter(t => document.getElementById(t.btn) && document.getElementById(t.pane));

    const loaded = { kpis: false, analisis: false, circulacion: false, producto: false, cadena: false, participacion: false, vendedoras: false, ranking: false, liquidacion: false, franquiciasDetalle: false, franquiciasResumen: false };

    const loaders = {
        kpis         : () => Dashboard.loadAll(),
        analisis     : () => Analisis.loadAll(),
        circulacion  : () => Circulacion.loadAll(),
        producto     : () => Producto.loadAll(),
        cadena       : () => Cadena.loadAll(),
        participacion: () => Participacion.loadAll(),
        vendedoras   : () => Vendedoras.loadAll(),
        ranking      : () => Ranking.loadAll(),
        liquidacion  : () => Liquidacion.loadAll(),
        franquiciasDetalle: () => FranquiciasDetalle.loadAll(),
        franquiciasResumen: () => FranquiciasResumen.loadAll(),
    };

    function loadTab(name) {
        // Si cargamos una pestaña de detalle, ocultamos el spinner global para que no bloquee la interfaz
        if (name !== 'kpis') {
            Dashboard.setLoading(false);
        }
        if (loaded[name]) return Promise.resolve();
        loaded[name] = true;
        return loaders[name]?.() ?? Promise.resolve();
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

    // Origen toggle buttons (not rendered for GRUPO)
    function toggleArFilters() {
        const activeBtn = document.querySelector('.origen-btn.active');
        const isAr = (activeBtn?.dataset.origen ?? 'argentina') === 'argentina';
        ['grupo-wrap', 'tipo-tienda-wrap', 'canal-wrap'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = isAr ? '' : 'none';
        });
        const isFran = (activeBtn?.dataset.origen ?? '') === 'franquicias';
        ['tipo-local-wrap', 'zona-wrap', 'grupo-empresario-wrap'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = isFran ? '' : 'none';
        });

        const origenLabel = isFran ? 'Franquicias' : 'Locales Propios';
        const elDet = document.getElementById('title-franquicias-detalle');
        if (elDet) elDet.textContent = `Venta Día por Día — ${origenLabel}`;
        const elRes = document.getElementById('title-franquicias-resumen');
        if (elRes) elRes.textContent = `Resumen Anual Anterior — ${origenLabel}`;

        // Tab Circulación: solo visible para los orígenes habilitados (ORIGENES_CON_CIRCULACION)
        const btnCirculacion = document.getElementById('tab-btn-circulacion');
        if (btnCirculacion) {
            const origenActual = activeBtn?.dataset.origen ?? 'argentina';
            const habilitado = (window.BI_CONFIG?.origenesConCirculacion ?? ['argentina']).includes(origenActual);
            btnCirculacion.style.display = habilitado ? '' : 'none';
            if (!habilitado && btnCirculacion.classList.contains('active')) {
                activateTab('tab-kpis');
            }
        }
    }
    document.querySelectorAll('.origen-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.origen-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            toggleArFilters();
            Object.keys(loaded).forEach(k => loaded[k] = false);
            Dashboard.loadFilters().then(() => {
                const activePane = document.querySelector('.tab-pane.active');
                const tab = TABS.find(t => t.pane === activePane?.id) ?? TABS[0];
                loadTab(tab.name);
            });
        });
    });
    toggleArFilters();

    // Período custom
    const selPeriodo   = document.getElementById('sel-periodo');
    const customDates  = document.getElementById('custom-dates');
    const compYear     = document.getElementById('comp-year');
    const compCustom   = document.getElementById('comp-custom');
    const customComp   = document.getElementById('custom-comp-dates');

    selPeriodo.addEventListener('change', () => {
        customDates.classList.toggle('visible', selPeriodo.value === 'custom');
    });
    [compYear, compCustom].forEach(r => {
        r.addEventListener('change', () => {
            customComp.classList.toggle('visible', compCustom.checked);
        });
    });

    // Aplicar
    document.getElementById('btn-aplicar').addEventListener('click', () => {
        // Actualizar leyenda de período inmediatamente desde el selector
        const periodoSel = document.getElementById('sel-periodo');
        const periodoLabel = document.getElementById('periodo-label');
        if (periodoLabel && periodoSel) {
            const txt = periodoSel.options[periodoSel.selectedIndex]?.text ?? '';
            periodoLabel.textContent = txt;
            const prevLab = document.getElementById('periodo-previo-label');
            if (prevLab) prevLab.textContent = '(calculando…)';
        }
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

    // Toggle "Solo activas"
    const chkSoloActivas = document.getElementById('chk-solo-activas');
    if (chkSoloActivas) {
        chkSoloActivas.addEventListener('change', () => {
            Object.keys(loaded).forEach(k => loaded[k] = false);
            const activePane = document.querySelector('.tab-pane.active');
            const tab = TABS.find(t => t.pane === activePane?.id) ?? TABS[0];
            loadTab(tab.name);
        });
    }

    // Carga inicial
    Dashboard.loadFilters()
        .then(() => loadTab('kpis'))
        .catch(err => console.error('[Dashboard] Error en carga inicial:', err));

})();
</script>

<script>
/* ── Info Popover compartido ── */
(function () {
    const popover = document.createElement('div');
    popover.className = 'info-popover';
    document.body.appendChild(popover);

    let activeBtn = null;

    function show(btn) {
        if (activeBtn) activeBtn.classList.remove('active');
        activeBtn = btn;
        btn.classList.add('active');
        const title = btn.dataset.infoTitle || '';
        const tips  = (btn.dataset.infoTips || '').split('|').filter(Boolean);
        popover.innerHTML =
            `<div class="info-popover-title"><i class="bi bi-info-circle-fill"></i>${title}</div>` +
            tips.map(t =>
                `<div class="info-popover-tip">
                    <i class="bi bi-lightbulb-fill info-popover-tip-icon"></i>
                    <span>${t}</span>
                 </div>`
            ).join('');
        popover.style.display = 'block';
        position(btn);
    }

    function hide() {
        popover.style.display = 'none';
        if (activeBtn) activeBtn.classList.remove('active');
        activeBtn = null;
    }

    function position(btn) {
        const r  = btn.getBoundingClientRect();
        const pw = popover.offsetWidth;
        const ph = popover.offsetHeight;
        let left = r.right - pw;
        let top  = r.bottom + 8;
        if (left < 8) left = 8;
        if (top + ph > window.innerHeight - 8) top = r.top - ph - 8;
        popover.style.left = left + 'px';
        popover.style.top  = top  + 'px';
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.info-btn');
        if (btn) { e.stopPropagation(); activeBtn === btn ? hide() : show(btn); return; }
        if (activeBtn) hide();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') hide(); });
})();
</script>
</body>
</html>
