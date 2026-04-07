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
if (!in_array($tipoSesion, ['GERENCIA', 'SUPERVISION'], true)) {
    header('Location: ../');
    exit;
}
$descLabel = $tipoSesion === 'GERENCIA' ? 'GERENCIA' : 'SUPERVISIÓN';
date_default_timezone_set('America/Argentina/Buenos_Aires');
$ultimaAct = date('d/m/Y H:i:s');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard — <?= htmlspecialchars($descLabel) ?></title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">
    <!-- Shared base styles -->
    <link rel="stylesheet" href="/bi/css/base.css">
    <link rel="stylesheet" href="/bi/css/components.css">
    <!-- Global-specific styles -->
    <link rel="stylesheet" href="/bi/global/css/global.css">
    <link rel="stylesheet" href="/bi/global/css/cadena.css">
    <link rel="stylesheet" href="/bi/global/css/participacion.css">
    <link rel="stylesheet" href="/bi/global/css/vendedoras.css">
    <link rel="stylesheet" href="/bi/global/css/producto.css">
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

    <!-- ══ TOPBAR ══════════════════════════════════════════════════════ -->
    <header class="topbar">
        <div class="logo-box">XL</div>
        <div>
            <div class="topbar-title">DASHBOARD SALES GLOBAL — <?= htmlspecialchars($descLabel) ?></div>
            <div class="topbar-sub">
                <span id="periodo-label">—</span> <span id="periodo-previo-label"></span>
            </div>
        </div>
        <div class="topbar-meta">
            Última actualización<br>
            <strong id="ultima-actualizacion"><?= $ultimaAct ?></strong>
        </div>
    </header>

    <!-- ══ TOOLBAR ══════════════════════════════════════════════════════ -->
    <div class="toolbar">
        <label for="sel-origen">Origen</label>
        <select id="sel-origen">
            <option value="argentina" selected>Argentina</option>
            <option value="uruguay">Uruguay</option>
            <option value="franquicias">Franquicias</option>
        </select>

        <label for="sel-sucursal">Sucursal</label>
        <select id="sel-sucursal">
            <option value="">Todas</option>
        </select>

        <span class="ar-only-wrap" id="grupo-wrap">
            <label for="sel-grupo">Grupo</label>
            <select id="sel-grupo">
                <option value="">Todos</option>
            </select>
        </span>

        <span class="ar-only-wrap" id="tipo-tienda-wrap">
            <label for="sel-tipo-tienda">Tipo tienda</label>
            <select id="sel-tipo-tienda">
                <option value="">Todos</option>
            </select>
        </span>

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

    <!-- ══ NAVEGACIÓN DE PESTAÑAS ══════════════════════════════════════ -->
    <nav class="tab-nav" role="tablist">
        <button class="tab-btn active" id="tab-btn-kpis" role="tab" aria-controls="tab-kpis" aria-selected="true">
            <i class="bi bi-speedometer2"></i>&nbsp; KPIs
        </button>
        <button class="tab-btn" id="tab-btn-analisis" role="tab" aria-controls="tab-analisis" aria-selected="false">
            <i class="bi bi-graph-up-arrow"></i>&nbsp; Análisis
        </button>
        <button class="tab-btn" id="tab-btn-producto" role="tab" aria-controls="tab-producto" aria-selected="false">
            <i class="bi bi-box-seam"></i>&nbsp; Producto
        </button>
        <button class="tab-btn" id="tab-btn-cadena" role="tab" aria-controls="tab-cadena" aria-selected="false">
            <i class="bi bi-diagram-3"></i>&nbsp; Cadena
        </button>
        <button class="tab-btn" id="tab-btn-participacion" role="tab" aria-controls="tab-participacion" aria-selected="false">
            <i class="bi bi-grid-3x3-gap-fill"></i>&nbsp; Participación
        </button>
        <button class="tab-btn" id="tab-btn-vendedoras" role="tab" aria-controls="tab-vendedoras" aria-selected="false">
            <i class="bi bi-people-fill"></i>&nbsp; Vendedoras
        </button>
        <button class="tab-reload-btn" id="btn-reload-tab" title="Recargar pestaña">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </nav>

    <!-- ══ PESTAÑA: KPIs ═══════════════════════════════════════════════ -->
    <div id="tab-kpis" class="tab-pane active" role="tabpanel" aria-labelledby="tab-btn-kpis">
        <main class="dash-content">

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

            <!-- ── TABLA FACTURACIÓN VS OBJETIVOS + DONUTS ────────── -->
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
                                    <th style="text-align:right">Var. Fact.</th>
                                    <th style="text-align:right">Objetivo Total</th>
                                    <th style="text-align:right">Objetivo Fecha</th>
                                    <th style="text-align:right">Desvío</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-3);">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="donuts-section">
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
                        <div id="medios-pago-wrap" class="donut-container" style="flex-direction:column;align-items:stretch;padding:8px">
                            <div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>
                        </div>
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

    <!-- ══ PESTAÑA: PARTICIPACIÓN ════════════════════════════════════════ -->
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
            <div style="display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:16px;align-items:start">

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
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="9" style="text-align:center;padding:24px;color:var(--text-3);">Cargando...</td></tr>
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

</div><!-- /dash-wrap -->

<!-- Shared BIUtils -->
<script src="/bi/js/utils.js"></script>
<!-- Tab JS -->
<script src="/bi/global/js/dashboard.js"></script>
<script src="/bi/global/js/analisis.js"></script>
<script src="/bi/global/js/producto.js"></script>
<script src="/bi/global/js/cadena.js"></script>
<script src="/bi/global/js/participacion.js"></script>
<script src="/bi/global/js/vendedoras.js"></script>

<script>
/**
 * Orquestador: tabs + aplicar + reload.
 * Lee los filtros del DOM y coordina los módulos.
 */
(function () {

    const TABS = [
        { btn: 'tab-btn-kpis',          pane: 'tab-kpis',          name: 'kpis'          },
        { btn: 'tab-btn-analisis',       pane: 'tab-analisis',       name: 'analisis'      },
        { btn: 'tab-btn-producto',       pane: 'tab-producto',       name: 'producto'      },
        { btn: 'tab-btn-cadena',         pane: 'tab-cadena',         name: 'cadena'        },
        { btn: 'tab-btn-participacion',  pane: 'tab-participacion',  name: 'participacion' },
        { btn: 'tab-btn-vendedoras',     pane: 'tab-vendedoras',     name: 'vendedoras'    },
    ];

    const loaded = { kpis: false, analisis: false, producto: false, cadena: false, participacion: false, vendedoras: false };

    const loaders = {
        kpis         : () => Dashboard.loadAll(),
        analisis     : () => Analisis.loadAll(),
        producto     : () => Producto.loadAll(),
        cadena       : () => Cadena.loadAll(),
        participacion: () => Participacion.loadAll(),
        vendedoras   : () => Vendedoras.loadAll(),
    };

    function loadTab(name) {
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

    // Mostrar/ocultar filtros de Argentina
    const origenSel = document.getElementById('sel-origen');
    function toggleArFilters() {
        const isAr = origenSel.value === 'argentina';
        document.getElementById('grupo-wrap').style.display     = isAr ? '' : 'none';
        document.getElementById('tipo-tienda-wrap').style.display = isAr ? '' : 'none';
    }
    origenSel.addEventListener('change', () => {
        toggleArFilters();
        // Al cambiar origen, recargar filtros dependientes
        Dashboard.loadFilters();
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

    // Carga inicial
    Dashboard.loadFilters().then(() => loadTab('kpis'));

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
