<?php
/**
 * dashboard/index.php
 * Dashboard de Sales — XL Extra Large
 * v2: Pestañas KPIs + Análisis
 */
session_start();
if (!isset($_SESSION['username'])) {
    header('Location: ../sistemas/login.php');
    exit;
}
require_once __DIR__ . '/../class/config.php';
$config     = getConfig();
$showGrupos = $config['features']['grupos'];
date_default_timezone_set('America/Argentina/Buenos_Aires');
$ultimaAct  = date('d/m/Y H:i:s');
$descLocal  = isset($_SESSION['descLocal']) ? $_SESSION['descLocal'] : 'Abasto';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Sales — <?= htmlspecialchars($descLocal) ?></title>
    <link rel="icon" type="image/jpg" href="../assets/css/images/icono.jpg">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/analisis.css">
    <link rel="stylesheet" href="css/grupos.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Chart.js 4.x + DataLabels Plugin -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
</head>
<body>
<div class="dash-wrap">
<div id="loading-bar"></div>
<div id="spark-modal-root"></div>

    <!-- ══ TOPBAR (compartido) ══════════════════════════════════════ -->
    <header class="topbar">
        <div class="logo-box">XL</div>
        <div>
            <div class="topbar-title">SALES DASHBOARD — <?= htmlspecialchars($descLocal) ?></div>
            <div class="topbar-sub">
                <span id="periodo-label">—</span> <span id="periodo-previo-label">—</span>
            </div>
        </div>
        <div class="topbar-meta">
            Última actualización<br>
            <strong id="ultima-actualizacion"><?= $ultimaAct ?></strong>
        </div>
    </header>

    <!-- ══ TOOLBAR (compartido) ════════════════════════════════════ -->
    <div class="toolbar">
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
        <select id="sel-rubro">
            <option value="%">Todos</option>
        </select>

        <button id="btn-aplicar" class="btn-aplicar">
            <i class="bi bi-check2"></i> Aplicar
        </button>
    </div>

    <!-- ══ NAVEGACIÓN DE PESTAÑAS ══════════════════════════════════ -->
    <nav class="tab-nav" role="tablist">
        <button class="tab-btn active" id="tab-btn-kpis" role="tab" aria-controls="tab-kpis" aria-selected="true">
            <i class="bi bi-speedometer2"></i>&nbsp; KPIs
        </button>
        <button class="tab-btn" id="tab-btn-analisis" role="tab" aria-controls="tab-analisis" aria-selected="false">
            <i class="bi bi-graph-up-arrow"></i>&nbsp; Análisis
        </button>
        <?php if ($showGrupos): ?>
        <button class="tab-btn" id="tab-btn-grupos" role="tab" aria-controls="tab-grupos" aria-selected="false">
            <i class="bi bi-diagram-2-fill"></i>&nbsp; Grupos
        </button>
        <?php endif; ?>
        <button class="tab-reload-btn" id="btn-reload-tab" title="Recargar pestaña">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </nav>

    <!-- ══ PESTAÑA: KPIs ═══════════════════════════════════════════ -->
    <div id="tab-kpis" class="tab-pane active" role="tabpanel" aria-labelledby="tab-btn-kpis">
        <main class="dash-content">

            <!-- ── RESUMEN PRINCIPAL ──────────────────────────────── -->
            <div class="summary-row">

                <!-- Facturación -->
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
                        </div>
                    </div>
                </div>

                <!-- Objetivo -->
                <div class="summary-card obj">
                    <div class="summary-icon">🎯</div>
                    <div class="summary-body">
                        <div class="summary-label">Objetivo a la fecha</div>
                        <div class="summary-main">
                            <div class="summary-left">
                                <div class="summary-value" id="obj-act">—</div>
                                <div class="summary-var" id="obj-var">—</div>
                            </div>
                            <div class="summary-right">
                                <div class="summary-prev-label">Objetivo Total</div>
                                <div class="summary-prev-value" id="obj-total">—</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Unidades -->
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
                        </div>
                    </div>
                </div>

                <!-- Tickets -->
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
                        <div class="summary-bench">Benchmark: <span id="tickets-bench">—</span></div>
                        <div class="summary-sparkline">
                            <canvas id="spark-tickets-main" width="120" height="45"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Personas + Conversión -->
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
                    </div>
                    <div class="summary-side">
                        <div class="summary-prev-label">Período previo</div>
                        <div class="summary-prev-value" id="conv-prev">—</div>
                        <div class="summary-sparkline">
                            <canvas id="spark-conv" width="120" height="45"></canvas>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ── KPI GRID ───────────────────────────────────────── -->
            <div class="kpi-grid">

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">Tickets 2do. Producto</span>
                        <div><span class="kpi-bench-label">Benchmark</span><span class="kpi-bench-val" id="card-t2do-bench">—</span></div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-t2do-val">—</div><div class="kpi-var" id="card-t2do-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-t2do" width="90" height="36"></canvas></div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">% Cambios</span>
                        <div><span class="kpi-bench-label">Benchmark</span><span class="kpi-bench-val" id="card-cambios-bench">—</span></div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-cambios-val">—</div><div class="kpi-var" id="card-cambios-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-cambios" width="90" height="36"></canvas></div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">Ticket Promedio</span>
                        <div><span class="kpi-bench-label">Benchmark</span><span class="kpi-bench-val" id="card-tprom-bench">—</span></div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-tprom-val">—</div><div class="kpi-var" id="card-tprom-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-tprom" width="90" height="36"></canvas></div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">Tickets 3er. Producto</span>
                        <div><span class="kpi-bench-label">Benchmark</span><span class="kpi-bench-val" id="card-t3ro-bench">—</span></div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-t3ro-val">—</div><div class="kpi-var" id="card-t3ro-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-t3ro" width="90" height="36"></canvas></div>
                    </div>
                </div>

                <div class="kpi-card">
                    <div class="kpi-card-header">
                        <span class="kpi-title">% Incremental</span>
                        <div><span class="kpi-bench-label">Benchmark</span><span class="kpi-bench-val" id="card-incr-bench">—</span></div>
                    </div>
                    <div class="kpi-card-body">
                        <div><div class="kpi-val" id="card-incr-val">—</div><div class="kpi-var" id="card-incr-var">—</div></div>
                        <div class="kpi-spark"><canvas id="spark-incr" width="90" height="36"></canvas></div>
                    </div>
                </div>

            </div>

            <!-- ── SECCIÓN INFERIOR ───────────────────────────────── -->
            <div class="bottom-section">

                <!-- Tabla vendedores -->
                <div class="table-card">
                    <div class="table-card-header">
                        <i class="bi bi-people-fill"></i>&nbsp; KPIs Vendedores
                    </div>
                    <div class="table-wrap">
                        <table id="tabla-vendedores">
                            <thead>
                                <tr>
                                    <th>Vendedor</th><th>Unidades</th><th>Facturación</th><th>Tickets</th>
                                    <th>Ticket Prom.</th><th>2do. Prod.&nbsp;%</th><th>3er. Prod.&nbsp;%</th>
                                    <th>%&nbsp;Cambios</th><th>%&nbsp;Incremental</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="9" style="text-align:center;padding:24px;color:var(--text-3);">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Donuts -->
                <div class="donuts-section">
                    <div class="donut-card">
                        <div class="donut-card-header">
                            <i class="bi bi-pie-chart-fill"></i>&nbsp; Participación Unidades
                            <button class="info-btn"
                                data-info-title="Participación Unidades"
                                data-info-tips="Hacé clic en un segmento para ver el detalle por categorías de ese rubro|Usá el botón ← del gráfico o doble clic para volver al total general|Pasá el cursor sobre cada segmento para ver porcentaje y unidades">
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
                                data-info-tips="Hacé clic en un segmento para ver el detalle por categorías de ese rubro|Usá el botón ← del gráfico o doble clic para volver al total general|Pasá el cursor sobre cada segmento para ver porcentaje e importe">
                                <i class="bi bi-info-circle"></i>
                            </button>
                        </div>
                        <div id="donut-facturacion-wrap" class="donut-container"></div>
                    </div>
                </div>

            </div>

        </main>
    </div>
    <!-- /tab-kpis -->

    <!-- ══ PESTAÑA: ANÁLISIS ═══════════════════════════════════════ -->
    <div id="tab-analisis" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-analisis">
        <div class="analisis-content">

            <!-- ── 1) CARDS RUBROS ESPECÍFICOS ──────────────────── -->
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

            <!-- ── 2) RANKING RUBROS ─────────────────────────────── -->
            <div class="ranking-analisis-wrap">
                <div class="analisis-card">
                    <div class="analisis-section-header">
                        <i class="bi bi-bar-chart-fill"></i> Ranking Rubros — Unidades
                    </div>
                    <div id="analisis-ranking-unidades" style="padding:8px 12px;max-height:300px;overflow-y:auto">
                        <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
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
            </div>

            <!-- ── 3 & 4) JERARQUÍA + VENDEDORES ────────────────── -->
            <div class="analisis-tablas-row">

                <!-- Jerarquía Destino → Rubro → Categoría -->
                <div class="analisis-card">
                    <div class="analisis-section-header">
                        <i class="bi bi-diagram-3-fill"></i> Jerarquía Destino → Rubro → Categoría
                        <span id="jerarquia-periodo-sub" style="margin-left:12px;font-size:.72rem;opacity:.75;font-family:var(--font-body);font-weight:400"></span>
                    </div>
                    <div class="jerarquia-wrap">
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

                <!-- Análisis por Vendedor -->
                <div class="analisis-card">
                    <div class="analisis-section-header">
                        <i class="bi bi-person-lines-fill"></i> Análisis por Vendedor
                    </div>
                    <div style="overflow-x:auto;max-height:480px;overflow-y:auto">
                        <table class="tabla-vendedores-analisis" id="tabla-vendedores-analisis">
                            <thead>
                                <tr>
                                    <th style="min-width:160px">Vendedor</th>
                                    <th style="min-width:110px;text-align:right">Unid. Actual</th>
                                    <th style="min-width:110px;text-align:right">Unid. Anterior</th>
                                    <th style="min-width:90px;text-align:right">Var. Unid.</th>
                                    <th style="min-width:130px;text-align:right">Fact. Actual</th>
                                    <th style="min-width:130px;text-align:right">Fact. Anterior</th>
                                    <th style="min-width:90px;text-align:right">Var. Fact.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7" class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- ── 5 & 6) GRÁFICOS DE EVOLUCIÓN ──────────────────── -->
            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-card-header">
                        <i class="bi bi-graph-up"></i> Evolución Mensual — Unidades (3 años)
                    </div>
                    <div class="chart-canvas-wrap">
                        <canvas id="chart-evolucion-unidades"></canvas>
                    </div>
                </div>
                <div class="chart-card">
                    <div class="chart-card-header">
                        <i class="bi bi-receipt"></i> Evolución Mensual — Tickets (3 años)
                    </div>
                    <div class="chart-canvas-wrap">
                        <canvas id="chart-evolucion-tickets"></canvas>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <!-- /tab-analisis -->

    <!-- ══ PESTAÑA: GRUPOS ═══════════════════════════════════════ -->
    <?php if ($showGrupos): ?>
    <div id="tab-grupos" class="tab-pane" role="tabpanel" aria-labelledby="tab-btn-grupos">
        <div class="grupos-content">

            <!-- ── 1) VERSUS DE SUCURSALES ──────────────────── -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-arrow-left-right"></i> Versus de Sucursales
                </div>
                <div class="versus-toolbar">
                    <span class="versus-toolbar-label">Comparar</span>
                    <strong class="versus-suc-a-name" id="versus-suc-a-name">—</strong>
                    <span class="versus-vs-sep">VS</span>
                    <select class="versus-suc-select" id="sel-suc-versus">
                        <option value="">Cargando...</option>
                    </select>
                </div>
                <div id="versus-content">
                    <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                </div>
            </div>

            <!-- ── 2) DESGLOSE POR GRUPO Y SUCURSAL ─────────── -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-table"></i> KPIs por Grupo y Sucursal
                    <button class="info-btn" style="margin-left:auto"
                        data-info-title="KPIs por Grupo y Sucursal"
                        data-info-tips="Hacé clic en cualquier columna para ordenar|Hacé clic nuevamente para invertir el orden|★ indica la sucursal actual">
                        <i class="bi bi-info-circle"></i>
                    </button>
                </div>
                <div style="overflow-x:auto;max-height:500px;overflow-y:auto">
                    <table class="grupos-tabla" id="tabla-grupos-desglose">
                        <thead>
                            <tr>
                                <th style="min-width:180px;text-align:left">Grupo / Sucursal</th>
                                <th style="min-width:90px;text-align:right">Unidades</th>
                                <th style="min-width:110px;text-align:right">% Facturación</th>
                                <th style="min-width:80px;text-align:right">Tickets</th>
                                <th style="min-width:100px;text-align:right">Var. Tickets</th>
                                <th style="min-width:120px;text-align:right">Ticket Prom.</th>
                                <th style="min-width:90px;text-align:right">% 2do Prod.</th>
                                <th style="min-width:90px;text-align:right">% 3er Prod.</th>
                                <th style="min-width:90px;text-align:right">% Cambios</th>
                                <th style="min-width:100px;text-align:right">% Incremental</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="10" class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── 3) TOP RUBROS % PARTICIPACIÓN (PIVOT) ─────── -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-grid-3x3-gap-fill"></i> % Participación por Rubro y Sucursal
                    <button class="info-btn" style="margin-left:auto"
                        data-info-title="% Participación por Rubro y Sucursal"
                        data-info-tips="Hacé clic en cualquier columna para ordenar|Hacé clic nuevamente para invertir el orden|La intensidad del color indica mayor participación relativa en ese rubro|★ indica la sucursal actual">
                        <i class="bi bi-info-circle"></i>
                    </button>
                </div>
                <div class="rubros-pivot-wrap" id="grupos-rubros-wrap">
                    <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                </div>
            </div>

        </div>
    </div>
    <!-- /tab-grupos -->
    <?php endif; ?>

</div><!-- /dash-wrap -->

<script src="js/dashboard.js"></script>
<script src="js/analisis.js"></script>
<?php if ($showGrupos): ?>
<script src="js/grupos.js"></script>
<?php endif; ?>
<script>
/**
 * Tab navigation — KPIs / Análisis / Grupos (Grupos solo si showGrupos === true)
 */
(function () {
    const SHOW_GRUPOS = <?= $showGrupos ? 'true' : 'false' ?>;

    const TABS = [
        { btn: 'tab-btn-kpis',     pane: 'tab-kpis'    },
        { btn: 'tab-btn-analisis', pane: 'tab-analisis' },
    ];
    if (SHOW_GRUPOS) {
        TABS.push({ btn: 'tab-btn-grupos', pane: 'tab-grupos' });
    }

    let analisisLoaded = false;
    let gruposLoaded   = false;

    function activateTab(paneId) {
        TABS.forEach(t => {
            const active = t.pane === paneId;
            document.getElementById(t.btn).classList.toggle('active', active);
            document.getElementById(t.pane).classList.toggle('active', active);
            document.getElementById(t.btn).setAttribute('aria-selected', active ? 'true' : 'false');
        });

        if (paneId === 'tab-analisis' && !analisisLoaded) {
            analisisLoaded = true;
            Analisis.loadAll();
        }
        if (SHOW_GRUPOS && paneId === 'tab-grupos' && !gruposLoaded) {
            gruposLoaded = true;
            Grupos.loadAll();
        }
    }

    TABS.forEach(t => {
        document.getElementById(t.btn).addEventListener('click', () => activateTab(t.pane));
    });

    // Btn Aplicar: forzar recarga en la pestaña activa
    document.getElementById('btn-aplicar').addEventListener('click', () => {
        analisisLoaded = false;
        gruposLoaded   = false;
        const activePane = document.querySelector('.tab-pane.active');
        if (activePane?.id === 'tab-analisis') {
            Analisis.loadAll();
            analisisLoaded = true;
        } else if (SHOW_GRUPOS && activePane?.id === 'tab-grupos') {
            Grupos.loadAll();
            gruposLoaded = true;
        }
    });

    // Btn Recargar pestaña activa
    const btnReload = document.getElementById('btn-reload-tab');
    function reloadActiveTab() {
        const activePane = document.querySelector('.tab-pane.active');
        btnReload.classList.add('spinning');
        const stop = () => btnReload.classList.remove('spinning');

        if (!activePane || activePane.id === 'tab-kpis') {
            Dashboard.loadAll().finally(stop);
        } else if (activePane.id === 'tab-analisis') {
            analisisLoaded = true;
            Analisis.loadAll().finally(stop);
        } else if (SHOW_GRUPOS && activePane.id === 'tab-grupos') {
            gruposLoaded = true;
            Grupos.loadAll().finally(stop);
        } else {
            stop();
        }
    }
    btnReload.addEventListener('click', reloadActiveTab);
})();
</script>
<script>
/* ── Info Popover — compartido entre pestañas ── */
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
        if (btn) {
            e.stopPropagation();
            activeBtn === btn ? hide() : show(btn);
            return;
        }
        if (activeBtn) hide();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') hide();
    });
})();
</script>
</body>
</html>