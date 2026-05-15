<?php
/**
 * /bi/mayoristas/index.php
 * Dashboard de Ventas Mayoristas
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['username'])) {
    header('Location: ../../sistemas/login.php');
    exit;
}
date_default_timezone_set('America/Argentina/Buenos_Aires');
$ultimaAct = date('d/m/Y H:i:s');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Mayoristas — XL Extra Large</title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">
    <link rel="stylesheet" href="/bi/css/base.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/base.css') ?>">
    <link rel="stylesheet" href="/bi/css/components.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/components.css') ?>">
    <link rel="stylesheet" href="/bi/mayoristas/css/mayoristas.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/mayoristas/css/mayoristas.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>
<body>
<div class="dash-wrap">
<div id="loading-bar"></div>

<!-- ══ OVERLAY BLOQUEANTE ════════════════════════════════════════════════ -->
<div id="loading-overlay" hidden>
    <div class="ov-spinner">
        <i class="bi bi-arrow-repeat"></i>
        <span>Cargando datos<span class="loading-text"></span></span>
    </div>
</div>

<!-- ══ TOPBAR ════════════════════════════════════════════════════════════ -->
<header class="topbar">
    <div class="logo-box">XL</div>
    <div>
        <div class="topbar-title">VENTAS MAYORISTAS</div>
        <div class="topbar-sub" id="periodo-label">—</div>
    </div>
    <div class="topbar-meta">
        Última actualización<br>
        <strong><?= $ultimaAct ?></strong>
    </div>
</header>

<!-- ══ TOOLBAR ═══════════════════════════════════════════════════════════ -->
<div class="toolbar">
    <label for="sel-periodo">Período</label>
    <select id="sel-periodo">
        <option value="mes_actual">Mes actual</option>
        <option value="mes_pasado">Mes pasado</option>
        <option value="año_actual" selected>Año actual</option>
        <option value="año_pasado">Año pasado</option>
        <option value="30">Últimos 30 días</option>
        <option value="90">Últimos 90 días</option>
        <option value="180">Últimos 180 días</option>
        <option value="custom">Personalizado</option>
    </select>

    <span class="custom-dates" id="custom-dates">
        <label for="inp-desde">Desde</label>
        <input type="date" id="inp-desde">
        <label for="inp-hasta">Hasta</label>
        <input type="date" id="inp-hasta">

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
            <label for="inp-comp-desde">vs Desde</label>
            <input type="date" id="inp-comp-desde" value="<?= date('Y-m-01', strtotime('-1 year')) ?>">
            <label for="inp-comp-hasta">Hasta</label>
            <input type="date" id="inp-comp-hasta" value="<?= date('Y-m-d', strtotime('-1 year')) ?>">
        </div>
    </span>

    <label for="sel-vendedor">Vendedor</label>
    <select id="sel-vendedor"><option value="">Todos</option></select>

    <label for="sel-rubro">Rubro</label>
    <select id="sel-rubro"><option value="">Todos</option></select>

    <label for="cat-input">Categoría</label>
    <div class="searchable-wrap" id="cat-wrap">
        <input type="text" id="cat-input" placeholder="Todas" autocomplete="off" spellcheck="false">
        <input type="hidden" id="sel-categoria" value="">
        <ul class="searchable-dropdown" id="cat-dropdown" hidden></ul>
    </div>

    <label for="sel-region">Región</label>
    <select id="sel-region"><option value="">Todas</option></select>

    <label for="sel-provincia">Provincia</label>
    <select id="sel-provincia"><option value="">Todas</option></select>

    <label for="sel-cliente">Cliente</label>
    <select id="sel-cliente"><option value="">Todos</option></select>

    <button class="btn-aplicar" id="btn-aplicar">
        <i class="bi bi-play-fill"></i> Aplicar
    </button>
</div>

<!-- ══ TAB NAV ═══════════════════════════════════════════════════════════ -->
<nav class="tab-nav">
    <button class="tab-btn active" data-tab="kpis">
        <i class="bi bi-speedometer2"></i> Resumen
    </button>
    <button class="tab-btn" data-tab="clientes">
        <i class="bi bi-people"></i> Clientes
    </button>
    <button class="tab-btn" data-tab="matriz">
        <i class="bi bi-grid-3x3"></i> Clientes × Rubro
    </button>
    <button class="tab-btn" data-tab="evolucion">
        <i class="bi bi-graph-up"></i> Evolución
    </button>
    <button class="tab-btn" data-tab="vendedores">
        <i class="bi bi-person-badge"></i> Vendedores
    </button>
    <button class="tab-reload-btn" id="btn-reload" title="Recargar pestaña">
        <i class="bi bi-arrow-clockwise"></i>
    </button>
</nav>

<!-- ══ CONTENIDO ═════════════════════════════════════════════════════════ -->
<div class="main-content">

    <!-- ── TAB: RESUMEN KPIs ─────────────────────────────────────────── -->
    <div class="tab-pane active" id="tab-kpis">
        <div class="dash-content">
            <div class="kpi-grid" id="kpi-grid">
                <div class="kpi-card skeleton">
                    <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
                    <div class="kpi-body">
                        <div class="kpi-label">Unidades totales</div>
                        <div class="kpi-value" id="kv-unidades">—</div>
                        <div class="kpi-var" id="kvar-unidades"></div>
                    </div>
                </div>
                <div class="kpi-card skeleton">
                    <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                    <div class="kpi-body">
                        <div class="kpi-label">Clientes activos</div>
                        <div class="kpi-value" id="kv-clientes">—</div>
                        <div class="kpi-var" id="kvar-clientes"></div>
                    </div>
                </div>
                <div class="kpi-card skeleton">
                    <div class="kpi-icon"><i class="bi bi-graph-up-arrow"></i></div>
                    <div class="kpi-body">
                        <div class="kpi-label">Promedio u/cliente</div>
                        <div class="kpi-value" id="kv-prom">—</div>
                        <div class="kpi-var" id="kvar-prom"></div>
                    </div>
                </div>
                <div class="kpi-card skeleton">
                    <div class="kpi-icon"><i class="bi bi-tags-fill"></i></div>
                    <div class="kpi-body">
                        <div class="kpi-label">Rubros activos</div>
                        <div class="kpi-value" id="kv-rubros">—</div>
                        <div class="kpi-var" id="kvar-rubros"></div>
                    </div>
                </div>
            </div>

            <div class="resumen-row">

            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-pie-chart"></i> Participación por Rubro
                </div>
                <div class="rubros-chart-wrap" id="rubros-chart-wrap">
                    <canvas id="chart-rubros-pie" height="260"></canvas>
                    <div class="rubros-legend" id="rubros-legend"></div>
                </div>
            </div>

            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-bar-chart-steps"></i>
                    <span id="rubros-header-title">Comparativa por Rubro</span>
                    <button class="btn-export ms-auto" id="btn-export-rubros" title="Exportar Excel">
                        <i class="bi bi-file-earmark-excel"></i> Exportar
                    </button>
                </div>
                <div class="table-wrap" id="wrap-tabla-rubros-comp">
                    <div class="analisis-loading">
                        <i class="bi bi-arrow-repeat"></i>
                        <span>Cargando<span class="loading-text"></span></span>
                    </div>
                </div>
            </div>

            </div><!-- /resumen-row -->
        </div>
    </div>

    <!-- ── TAB: CLIENTES ─────────────────────────────────────────────── -->
    <div class="tab-pane" id="tab-clientes">
        <div class="dash-content">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-table"></i> Top Clientes
                    <button class="btn-export ms-auto" id="btn-export-clientes" title="Exportar Excel">
                        <i class="bi bi-file-earmark-excel"></i> Exportar
                    </button>
                </div>
                <div class="table-wrap" id="wrap-tabla-clientes">
                    <div class="analisis-loading">
                        <i class="bi bi-arrow-repeat"></i>
                        <span>Cargando<span class="loading-text"></span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: MATRIZ CLIENTE × RUBRO ───────────────────────────────── -->
    <div class="tab-pane" id="tab-matriz">
        <div class="dash-content">

            <span class="evol-label" id="matriz-cliente-info" style="display:none"></span>

            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-grid-3x3"></i>
                    <span id="matriz-header-title">Unidades facturadas y participación por rubro</span>
                    <button class="btn-export ms-auto" id="btn-export-matriz" title="Exportar Excel">
                        <i class="bi bi-file-earmark-excel"></i> Exportar
                    </button>
                </div>
                <div class="table-wrap matriz-wrap" id="wrap-matriz">
                    <div class="analisis-loading">
                        <i class="bi bi-arrow-repeat"></i>
                        <span>Cargando<span class="loading-text"></span></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TAB: EVOLUCIÓN ────────────────────────────────────────────── -->
    <div class="tab-pane" id="tab-evolucion">
        <div class="dash-content">

            <!-- Toggle Por Año / Por Rubro -->
            <div class="evol-toolbar">
                <span class="evol-label">Vista</span>
                <div class="evol-toggle" id="evol-mode-toggle">
                    <button class="evol-btn active" data-mode="anio">Por Año</button>
                    <button class="evol-btn" data-mode="rubro">Por Rubro</button>
                </div>
                <!-- Selector de año (solo en modo Por Rubro) -->
                <span id="evol-anio-wrap" style="display:none">
                    <label for="sel-evol-anio" class="evol-label" style="margin-left:12px">Año</label>
                    <select id="sel-evol-anio" class="toolbar-select-sm"></select>
                </span>
            </div>

            <!-- Gráfico multiaño -->
            <div class="analisis-card" id="card-evol-anio">
                <div class="analisis-section-header">
                    <i class="bi bi-graph-up-arrow"></i> Evolución Mensual por Año
                </div>
                <div class="chart-wrap" id="evol-chart-wrap">
                    <canvas id="chart-evolucion" height="320"></canvas>
                </div>
            </div>

            <!-- Gráfico por rubro -->
            <div class="analisis-card" id="card-evol-rubro" style="display:none">
                <div class="analisis-section-header">
                    <i class="bi bi-graph-up"></i> Evolución por Rubro — <span id="evol-rubro-anio-label"></span>
                </div>
                <div class="chart-wrap" id="evol-rubro-chart-wrap">
                    <canvas id="chart-evolucion-rubro" height="320"></canvas>
                </div>
            </div>

        </div>
    </div>

    <!-- ── TAB: VENDEDORES ──────────────────────────────────────── -->
    <div class="tab-pane" id="tab-vendedores">
        <div class="dash-content">

            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-person-badge"></i> Vendedores
                    <button class="btn-export ms-auto" id="btn-export-vendedores" title="Exportar Excel">
                        <i class="bi bi-file-earmark-excel"></i> Exportar
                    </button>
                </div>
                <div class="table-wrap" id="wrap-tabla-vendedores">
                    <div class="analisis-loading">
                        <i class="bi bi-arrow-repeat"></i>
                        <span>Cargando<span class="loading-text"></span></span>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div><!-- /main-content -->
</div><!-- /dash-wrap -->

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="/bi/mayoristas/js/mayoristas.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/mayoristas/js/mayoristas.js') ?>"></script>
</body>
</html>
