<?php /* Contenido de las 7 pestañas AR — incluido desde index.php */ ?>

<!-- ─────────────────── TAB 1: EFICIENCIA ─────────────────────────────── -->
<div class="tab-pane active" id="tab-eficiencia">
    <div class="dash-content">

        <div class="kpi-grid" id="kpis-eficiencia">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="bi bi-percent"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Eficiencia facturación</div>
                    <div class="kpi-value" id="kv-efi">—</div>
                    <div class="kpi-var" id="kvar-efi"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.1);color:var(--accent2)"><i class="bi bi-box-seam"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades pedidas</div>
                    <div class="kpi-value" id="kv-unid-ped">—</div>
                    <div class="kpi-var" id="kvar-unid-ped"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-check-circle"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades facturadas</div>
                    <div class="kpi-value" id="kv-unid-fact">—</div>
                    <div class="kpi-var neu" id="kvar-unid-fact"></div>
                </div>
            </div>
            <div class="kpi-card kpi-flip" id="card-perdida">
                <div class="kpi-flip-inner">
                    <!-- Frente: KPI -->
                    <div class="kpi-flip-face kpi-flip-front">
                        <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-exclamation-triangle"></i></div>
                        <div class="kpi-body">
                            <div class="kpi-label">Pérdida facturación</div>
                            <div class="kpi-value" id="kv-perdida">—</div>
                            <div class="kpi-foot">
                                <div class="kpi-var" id="kvar-perdida"></div>
                                <button type="button" class="kpi-flip-front-btn" data-flip
                                        title="Ver evolución (últ. 12 meses)" aria-label="Ver gráfico de pérdida">
                                    <i class="bi bi-graph-down-arrow"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <!-- Dorso: mini-gráfico -->
                    <div class="kpi-flip-face kpi-flip-back">
                        <div class="kpi-flip-back-head">
                            <span class="kpi-flip-back-title">Pérdida — últ. 12 meses</span>
                            <span class="kpi-flip-actions">
                                <button type="button" class="kpi-flip-btn" id="btn-perdida-expand"
                                        title="Ampliar" aria-label="Ampliar gráfico de pérdida">
                                    <i class="bi bi-arrows-fullscreen"></i>
                                </button>
                                <button type="button" class="kpi-flip-btn" data-flip-back
                                        title="Volver" aria-label="Volver al indicador">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                            </span>
                        </div>
                        <div class="kpi-flip-chart"><canvas id="spark-perdida"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-currency-dollar"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Importe facturado</div>
                    <div class="kpi-value" id="kv-importe">—</div>
                    <div class="kpi-var neu" id="kvar-importe"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-file-earmark-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pedidos totales</div>
                    <div class="kpi-value" id="kv-pedidos">—</div>
                    <div class="kpi-var" id="kvar-pedidos"></div>
                </div>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-sliders2"></i> Eficiencia por Canal
            </div>
            <div class="gauges-wrap" id="gauges-canal"></div>
        </div>

        <div class="resumen-row">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-people"></i> % Eficiencia unidades por cliente (Peores 10)
                </div>
                <div class="table-wrap">
                    <table id="tabla-efi-unid-cliente">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th class="col-num">Unid. pedidas</th>
                                <th class="col-num">Unid. facturadas</th>
                                <th class="col-num">% Eficiencia</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-efi-unid-cliente"></tbody>
                    </table>
                </div>
            </div>
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-tags"></i> % Eficiencia unidades (Rubros)
                </div>
                <div class="table-wrap" style="max-height:360px;overflow-y:auto">
                    <table id="tabla-efi-unid-rubro">
                        <thead>
                            <tr>
                                <th>Rubro</th>
                                <th class="col-num">Unid. pedidas</th>
                                <th class="col-num">Unid. facturadas</th>
                                <th class="col-num">% Eficiencia</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-efi-unid-rubro"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-card-list"></i> % Eficiencia (Pedidos por cliente)
                <span class="header-sub">Clic en un cliente para ver sus pedidos · clic en un pedido para el detalle</span>
            </div>
            <div class="table-wrap" style="max-height:440px;overflow-y:auto">
                <table id="tabla-efi-pedidos" class="tabla-drill">
                    <thead>
                        <tr>
                            <th>Cliente / N° pedido</th>
                            <th>Fecha</th>
                            <th class="col-num">Unid. pedidas</th>
                            <th class="col-num">Unid. facturadas</th>
                            <th class="col-num">% Eficiencia</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-efi-pedidos"></tbody>
                </table>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-graph-up"></i> Evolución mensual — Eficiencia de facturación
            </div>
            <div class="chart-wrap">
                <canvas id="chart-eficiencia" height="260"></canvas>
            </div>
        </div>

    </div>
</div>

<!-- ─────────────────── TAB 2: LEAD TIME ──────────────────────────────── -->
<div class="tab-pane" id="tab-leadtime">
    <div class="dash-content">

        <div class="kpi-grid" id="kpis-leadtime">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="bi bi-file-earmark-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Comprobantes facturados</div>
                    <div class="kpi-value" id="kv-lt-total">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-clock"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Fact. demorada (&gt;5 días)</div>
                    <div class="kpi-value" id="kv-lt-dem">—</div>
                    <div class="kpi-var" id="kvar-lt-dem"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-hourglass-split"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Lead time promedio (días)</div>
                    <div class="kpi-value" id="kv-lt-prom">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-folder2-open"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pedidos abiertos</div>
                    <div class="kpi-value" id="kv-lt-abiertos">—</div>
                </div>
            </div>
        </div>

        <div class="resumen-row">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-bar-chart"></i> Distribución de lead times (días)
                </div>
                <div class="chart-wrap">
                    <canvas id="chart-leadtime-hist" height="260"></canvas>
                </div>
            </div>
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-graph-up"></i> Evolución mensual — % fact. demorada
                </div>
                <div class="chart-wrap">
                    <canvas id="chart-leadtime-evol" height="260"></canvas>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ─────────────────── TAB 3: STOCK WMS ──────────────────────────────── -->
<div class="tab-pane" id="tab-stock">
    <div class="dash-content">

        <div class="kpi-grid" id="kpis-stock">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="bi bi-database"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Stock Tango</div>
                    <div class="kpi-value" id="kv-stock-tango">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-boxes"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Stock WMS</div>
                    <div class="kpi-value" id="kv-stock-wms">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-arrow-left-right"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Diferencia neta</div>
                    <div class="kpi-value" id="kv-stock-dif">—</div>
                    <div class="kpi-var" id="kvar-stock-dif"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-rulers"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Diferencia absoluta</div>
                    <div class="kpi-value" id="kv-stock-dif-abs">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-shield-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Precisión inventario</div>
                    <div class="kpi-value" id="kv-stock-prec">—</div>
                </div>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-bar-chart-steps"></i> Diferencia WMS vs Tango por Rubro
            </div>
            <div class="chart-wrap">
                <canvas id="chart-stock" height="280"></canvas>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header" id="hdr-stock-detalle">
                <i class="bi bi-table"></i> Detalle por Rubro
                <span class="header-sub">Clic en un rubro para ver los artículos con diferencias</span>
            </div>
            <div class="table-wrap">
                <table id="tabla-stock" class="tabla-drill">
                    <thead>
                        <tr>
                            <th>Rubro</th>
                            <th class="col-num">Stock Tango</th>
                            <th class="col-num">Stock WMS</th>
                            <th class="col-num">Diferencia</th>
                            <th class="col-num">Dif. %</th>
                            <th class="col-num">Precisión</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-stock"></tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- ─────────────────── TAB 4: PRODUCTIVIDAD FACTURACIÓN ──────────────── -->
<div class="tab-pane" id="tab-prod-fact">
    <div class="dash-content">

        <div class="kpi-grid" id="kpis-prod-fact">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="bi bi-calculator"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Promedio por día</div>
                    <div class="kpi-value" id="kv-pf-prom-dia">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pico x día</div>
                    <div class="kpi-value" id="kv-pf-pico-dia">—</div>
                    <div class="kpi-var neu" id="kv-pf-pico-dia-fecha"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-trophy"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pico x usuario</div>
                    <div class="kpi-value" id="kv-pf-pico-user">—</div>
                    <div class="kpi-var neu" id="kv-pf-pico-user-nombre"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(0,168,120,.1);color:var(--accent)"><i class="bi bi-activity"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Tendencia x día x usuario</div>
                    <div class="kpi-value" id="kv-pf-tendencia">—</div>
                </div>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-people"></i> Indicadores por usuario
            </div>
            <div class="table-wrap">
                <table id="tabla-usuarios-fact">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th class="col-num">Unidades facturadas</th>
                            <th class="col-num">% Unidades facturadas</th>
                            <th class="col-num">Pico facturación</th>
                            <th class="col-num">Tendencia facturación</th>
                            <th class="col-num">Días productivos</th>
                            <th class="col-num">Unid. fact. últ. 30 días</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-usuarios-fact"></tbody>
                </table>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-bar-chart"></i> Unidades facturadas por día
            </div>
            <div class="chart-wrap">
                <canvas id="chart-prod-fact" height="260"></canvas>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-calendar-week"></i> Unidades facturadas por usuario (Últ. 7 días)
                <span class="header-sub">Clic en un día para ver el gráfico</span>
            </div>
            <div class="table-wrap picking-ult7-wrap">
                <table id="tabla-fact-ult7" class="tabla-ult7">
                    <thead id="thead-fact-ult7"></thead>
                    <tbody id="tbody-fact-ult7"></tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- ─────────────────── TAB 5: PRODUCTIVIDAD PICKING ──────────────────── -->
<div class="tab-pane" id="tab-prod-picking">
    <div class="dash-content">

        <div class="kpi-grid" id="kpis-prod-picking">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="bi bi-clipboard-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Promedio unid. por día</div>
                    <div class="kpi-value" id="kv-pp-prom-dia">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pico preparación x día</div>
                    <div class="kpi-value" id="kv-pp-pico-dia">—</div>
                    <div class="kpi-var neu" id="kv-pp-pico-dia-fecha"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-trophy"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pico preparación x usuario</div>
                    <div class="kpi-value" id="kv-pp-pico-usuario">—</div>
                    <div class="kpi-var neu" id="kv-pp-pico-usuario-nombre"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(0,168,120,.1);color:var(--accent)"><i class="bi bi-speedometer2"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Promedio Picking</div>
                    <div class="kpi-value" id="kv-pp-prom">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-clock"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Promedio unid. x hora</div>
                    <div class="kpi-value" id="kv-pp-u-hora">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-bar-chart-line"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Tiempo productivo (hs.)</div>
                    <div class="kpi-value" id="kv-pp-hs">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(0,168,120,.1);color:var(--accent)"><i class="bi bi-stopwatch"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Promedio tiempo prod. (hs.)</div>
                    <div class="kpi-value" id="kv-pp-prom-hs">—</div>
                </div>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-bar-chart"></i> Unidades pickeadas por día
            </div>
            <div class="chart-wrap">
                <canvas id="chart-prod-picking" height="260"></canvas>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-people"></i> Indicadores por usuario
            </div>
            <div class="table-wrap">
                <table id="tabla-usuarios-picking">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th class="col-num">Unidades pickeadas</th>
                            <th class="col-num">% Unidades</th>
                            <th class="col-num">Pico picking</th>
                            <th class="col-num">Mediana picking</th>
                            <th class="col-num">Tiempo productivo (Hs.)</th>
                            <th class="col-num">Prom. unid. x hora</th>
                            <th class="col-num">Unid. últ. 30 días</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-usuarios-picking"></tbody>
                </table>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-calendar-week"></i> Productividad picking por usuario (Últ. 7 días)
            </div>
            <div class="table-wrap picking-ult7-wrap">
                <table id="tabla-picking-ult7" class="tabla-ult7">
                    <thead id="thead-picking-ult7"></thead>
                    <tbody id="tbody-picking-ult7"></tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- ─────────────────── TAB 6: PLANIFICACIÓN ──────────────────────────── -->
<div class="tab-pane" id="tab-planificacion">
    <div class="dash-content">

        <!-- Ventanas de entrega: HOY / PRÓXIMA / +1 -->
        <div class="plan-ventanas">
            <?php
            $ventanas = [
                'hoy' => ['t' => 'Hoy',                 'i' => 'bi-calendar-check', 'dem' => true],
                'prox'=> ['t' => 'Próxima entrega',     'i' => 'bi-calendar-event', 'dem' => false],
                'mas' => ['t' => 'Próxima entrega +1',  'i' => 'bi-calendar-plus',  'dem' => false],
            ];
            foreach ($ventanas as $k => $v): ?>
            <div class="plan-card" id="plan-<?= $k ?>">
                <div class="plan-card-head">
                    <span class="plan-card-title"><i class="bi <?= $v['i'] ?>"></i> <?= $v['t'] ?></span>
                    <span class="plan-card-date" id="pl-<?= $k ?>-fecha">—</span>
                </div>
                <div class="plan-gauge">
                    <div class="gauge-wrap">
                        <canvas id="gauge-plan-<?= $k ?>" class="gauge-canvas"></canvas>
                        <div class="gauge-pct" id="pl-<?= $k ?>-pct">—</div>
                    </div>
                    <div class="plan-gauge-cap">% unidades pickeadas</div>
                </div>
                <div class="plan-stats">
                    <div class="plan-stat-group">
                        <div class="plan-stat-h">Pedidos</div>
                        <div class="plan-stat-row"><span>Total</span><b id="pl-<?= $k ?>-ped-tot">—</b></div>
                        <div class="plan-stat-row"><span>Pendiente</span><b class="warn" id="pl-<?= $k ?>-ped-pend">—</b></div>
                    </div>
                    <div class="plan-stat-group">
                        <div class="plan-stat-h">Unidades</div>
                        <div class="plan-stat-row"><span>Total</span><b id="pl-<?= $k ?>-unid-tot">—</b></div>
                        <div class="plan-stat-row"><span>Pendiente</span><b class="warn" id="pl-<?= $k ?>-unid-pend">—</b></div>
                    </div>
                </div>
                <div class="plan-foot">
                    <i class="bi bi-people-fill"></i>
                    <b id="pl-<?= $k ?>-pickers">—</b>&nbsp;pickers para unidades pendientes
                </div>
                <?php if ($v['dem']): ?>
                <div class="plan-foot plan-foot-warn">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <b id="pl-<?= $k ?>-dem">—</b>&nbsp;pedidos demorados
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pedidos pendientes (filtrable por ventana) -->
        <div class="analisis-card">
            <div class="analisis-section-header" id="hdr-pend">
                <i class="bi bi-hourglass-split"></i> Pedidos pendientes
                <span class="header-sub">Clic en un pedido para ver el detalle</span>
                <div class="filter-pills" id="pend-filtros" role="group" aria-label="Filtrar por ventana de entrega">
                    <button type="button" class="pill active" data-f="HOY">Hoy</button>
                    <button type="button" class="pill" data-f="PROX">Próxima entrega</button>
                    <button type="button" class="pill" data-f="MAS_UNO">Próxima entrega +1</button>
                    <button type="button" class="pill" data-f="ALL">Todos</button>
                </div>
            </div>
            <div class="table-wrap" style="max-height:360px;overflow-y:auto">
                <table id="tabla-pend">
                    <thead>
                        <tr>
                            <th>Pedido</th>
                            <th>Cód. cliente</th>
                            <th>Cliente</th>
                            <th>Canal</th>
                            <th>Fecha entrega</th>
                            <th class="col-num">Unidades</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-pend"></tbody>
                </table>
            </div>
        </div>

        <!-- Pedidos demorados -->
        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-exclamation-triangle"></i> Pedidos demorados
                <span class="header-sub">Vencidos sin despachar — últimos 30 días · clic para ver detalle</span>
            </div>
            <div class="table-wrap" style="max-height:360px;overflow-y:auto">
                <table id="tabla-plan-demorados">
                    <thead>
                        <tr>
                            <th>Pedido</th>
                            <th>Cliente</th>
                            <th>Canal</th>
                            <th>Fecha entrega</th>
                            <th class="col-num">Días</th>
                            <th class="col-num">Unidades</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-plan-demorados"></tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- ─────────────────── TAB 6b: DESPACHO ──────────────────────────────── -->
<div class="tab-pane" id="tab-despacho">
    <div class="dash-content">

        <div class="kpi-grid" id="kpis-despacho">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-check2-all"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Eficacia despacho total</div>
                    <div class="kpi-value" id="kv-dsp-efi">—</div>
                    <div class="kpi-var semaforo" id="kvar-dsp-efi"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-clock-history"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Prom. días pedidos demorados</div>
                    <div class="kpi-value" id="kv-dsp-dem-dias">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-truck"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Prom. días guía vs despacho</div>
                    <div class="kpi-value" id="kv-dsp-guia-dias">—</div>
                </div>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-sliders2"></i> Eficacia de despacho por canal
            </div>
            <div class="gauges-wrap" id="gauges-despacho-canal"></div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-graph-up"></i> Evolución eficacia de despacho
            </div>
            <div class="chart-wrap">
                <canvas id="chart-despacho-evol" height="260"></canvas>
            </div>
        </div>

        <div class="resumen-row">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-people"></i> % Eficacia despacho — por cliente
                </div>
                <div class="table-wrap" style="max-height:360px;overflow-y:auto">
                    <table id="tabla-efi-cliente">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th class="col-num">% Eficacia</th>
                                <th class="col-num">Desvío prom. (días)</th>
                                <th class="col-num">Comprob.</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-efi-cliente"></tbody>
                    </table>
                </div>
            </div>
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-list-ul"></i> Eficacia — desglose por pedido
                    <span class="header-sub">Fuera de plazo · clic para ver detalle</span>
                </div>
                <div class="table-wrap" style="max-height:360px;overflow-y:auto">
                    <table id="tabla-efi-pedido">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Pedido</th>
                                <th>Comp.</th>
                                <th>Prox. desp.</th>
                                <th>Fecha guía</th>
                                <th class="col-num">Desvío</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-efi-pedido"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="resumen-row">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-person-x"></i> Pedidos demorados promedio — por cliente
                </div>
                <div class="table-wrap" style="max-height:360px;overflow-y:auto">
                    <table id="tabla-dem-cliente">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th class="col-num">Días prom.</th>
                                <th class="col-num">Pedidos</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-dem-cliente"></tbody>
                    </table>
                </div>
            </div>
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-list-ul"></i> Pedidos demorados — desglose por pedido
                    <span class="header-sub">Clic para ver detalle</span>
                </div>
                <div class="table-wrap" style="max-height:360px;overflow-y:auto">
                    <table id="tabla-dem-pedido">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Pedido</th>
                                <th>Comp.</th>
                                <th>Prox. desp.</th>
                                <th>Estado</th>
                                <th class="col-num">Días</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-dem-pedido"></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ─────────────────── TAB 7: PEDIDOS CONSOLIDADOS ───────────────────── -->
<div class="tab-pane" id="tab-pedidos">
    <div class="dash-content">

        <div class="kpi-grid" id="kpis-pedidos">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="bi bi-card-list"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pedidos período</div>
                    <div class="kpi-value" id="kv-pc-ped">—</div>
                    <div class="kpi-var" id="kvar-pc-ped"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-calendar2-minus"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pedidos año anterior</div>
                    <div class="kpi-value" id="kv-pc-ped-aa">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(0,168,120,.1);color:var(--accent)"><i class="bi bi-arrow-up-down"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Variación vs año ant.</div>
                    <div class="kpi-value" id="kv-pc-var">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-box-seam"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades pedidas</div>
                    <div class="kpi-value" id="kv-pc-unid-ped">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-check-circle"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades facturadas</div>
                    <div class="kpi-value" id="kv-pc-unid-fact">—</div>
                </div>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-graph-up"></i> Evolución mensual de pedidos
            </div>
            <div class="chart-wrap">
                <canvas id="chart-pedidos-evol" height="260"></canvas>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-table"></i> Pedidos consolidados
                <span class="header-sub">Clic en un pedido para ver el detalle</span>
            </div>
            <div class="table-wrap">
                <table id="tabla-pedidos">
                    <thead>
                        <tr>
                            <th>Nro. Pedido</th>
                            <th>Canal</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                            <th>Talón</th>
                            <th class="col-num">U. pedidas</th>
                            <th class="col-num">U. pend.</th>
                            <th class="col-num">U. fact.</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-pedidos"></tbody>
                </table>
            </div>
        </div>

    </div>
</div>
