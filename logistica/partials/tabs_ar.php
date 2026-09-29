<?php /* Contenido de las 7 pestañas AR — incluido desde index.php */ ?>

<!-- ─────────────────── TAB 1: EFICIENCIA ─────────────────────────────── -->
<div class="tab-pane active" id="tab-eficiencia">
    <div class="dash-content">

        <div class="kpi-grid" id="kpis-eficiencia">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="bi bi-percent"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Eficiencia remisión</div>
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
                    <div class="kpi-label">Unidades remitidas</div>
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
                            <div class="kpi-label">Pérdida remisión</div>
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
                    <div class="kpi-label">Importe remitido</div>
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
                                <th class="col-num">Unid. remitidas</th>
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
                                <th class="col-num">Unid. remitidas</th>
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
                            <th class="col-num">Unid. remitidas</th>
                            <th class="col-num">% Eficiencia</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-efi-pedidos"></tbody>
                </table>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-graph-up"></i> Evolución mensual — Eficiencia de remisión
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
                    <div class="kpi-label">Comprobantes remitidos</div>
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
                            <th class="col-num">Unidades remitidas</th>
                            <th class="col-num">% Unidades remitidas</th>
                            <th class="col-num">Pico remisión</th>
                            <th class="col-num">Tendencia remisión</th>
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
                <i class="bi bi-bar-chart"></i> Unidades remitidas por día
            </div>
            <div class="chart-wrap">
                <canvas id="chart-prod-fact" height="260"></canvas>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-calendar-week"></i> Unidades remitidas por usuario (Últ. 7 días)
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
                <div class="kpi-icon" style="background:rgba(0,168,120,.1);color:var(--accent)"><i class="bi bi-stopwatch"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Promedio tiempo prod. (hs.)</div>
                    <div class="kpi-value" id="kv-pp-prom-hs">—</div>
                </div>
            </div>
        </div>

        <!-- WIP: picking en curso ahora (tareas iniciadas y no terminadas) -->
        <div class="analisis-card">
            <div class="analisis-section-header" id="hdr-wip-picking">
                <i class="bi bi-activity"></i> WIP · picking en curso
                <span class="header-sub" id="wip-hora">Situación actual · no depende del rango de fechas</span>
                <button type="button" class="fr-ultimo" id="wip-actualizar" title="Actualizar el WIP" aria-label="Actualizar el WIP"><i class="bi bi-arrow-clockwise"></i> Actualizar</button>
            </div>
            <div class="fr-ing-kpis">
                <div class="fr-ing-kpi"><span>Tareas en curso</span><b id="kv-wip-tareas">—</b></div>
                <div class="fr-ing-kpi"><span>Pickers activos</span><b id="kv-wip-pickers">—</b></div>
                <div class="fr-ing-kpi"><span>Unid. asignadas</span><b id="kv-wip-asig">—</b></div>
                <div class="fr-ing-kpi"><span>Unid. pickeadas</span><b id="kv-wip-pick">—</b></div>
                <div class="fr-ing-kpi"><span>Unid. faltantes</span><b id="kv-wip-falt">—</b></div>
                <div class="fr-ing-kpi"><span>% Avance</span><b id="kv-wip-avance">—</b></div>
                <div class="fr-ing-kpi"><span>Demoradas (+2 h)</span><b id="kv-wip-dem">—</b></div>
            </div>
            <div class="table-wrap" style="max-height:300px;overflow-y:auto">
                <table id="tabla-wip-picking">
                    <thead>
                        <tr>
                            <th>Picker</th>
                            <th>Inicio</th>
                            <th class="col-num">Abierta</th>
                            <th class="col-num">Asignadas</th>
                            <th class="col-num">Pickeadas</th>
                            <th class="col-num">Faltantes</th>
                            <th class="col-num">% Avance</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-wip-picking"></tbody>
                </table>
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
        <div class="plan-ventanas-header">
            <span class="plan-ventanas-title"><i class="bi bi-calendar-week"></i> Ventanas de entrega</span>
            <span class="plan-wip-badge" id="pl-wip-badge">
                <i class="bi bi-stack"></i>
                Pendiente a despachar: <strong id="kv-pl-wip">—</strong> unid. en <strong id="kv-pl-wip-ped">—</strong> pedidos
                <span class="plan-wip-venc" id="kv-pl-venc"></span>
            </span>
            <span class="plan-prom-badge">
                <i class="bi bi-person-lines-fill"></i>
                Promedio picking / día (últ. 7d):
                <strong id="kv-pl-prom-dia">—</strong> unid.
            </span>
        </div>
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

        <!-- Pendiente a despachar: unidades pendientes por día de entrega, de hoy en adelante (el WIP real está en Prod. Picking) -->
        <div class="analisis-card">
            <div class="analisis-section-header" id="hdr-plan-wip">
                <i class="bi bi-stack"></i> Pendiente a despachar por día de entrega
                <span class="header-sub">Unidades pendientes de hoy en adelante · próximos 10 días hábiles</span>
            </div>
            <div class="chart-wrap">
                <canvas id="chart-plan-wip" height="220"></canvas>
            </div>
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
                    <div class="kpi-label">Eficacia total (incluye demorados)</div>
                    <div class="kpi-value" id="kv-dsp-efi">—</div>
                    <div class="kpi-var semaforo" id="kvar-dsp-efi"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-calendar2-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Despachado en término</div>
                    <div class="kpi-value" id="kv-dsp-efi-desp">—</div>
                    <div class="kpi-var semaforo" id="kvar-dsp-efi-desp"></div>
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
                    <div class="kpi-label">Unidades remitidas</div>
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

<!-- ─────────────────── TAB 8: EVOLUCIÓN TIPO DE REMISIÓN ─────────────── -->
<div class="tab-pane" id="tab-evolucion-remision">
    <div class="dash-content">

        <!-- Barra de controles / Métricas y Filtros -->
        <div class="remision-controls-bar">
            <div class="remision-metric-toggle" role="group" aria-label="Seleccionar métrica">
                <span class="remision-toggle-label"><i class="bi bi-sliders"></i> Métrica visualizada:</span>
                <button type="button" class="rem-toggle-btn active" data-metric="unidades">
                    <i class="bi bi-box-seam"></i> Unidades Remitidas
                </button>
                <button type="button" class="rem-toggle-btn" data-metric="pedidos">
                    <i class="bi bi-card-checklist"></i> Cantidad de Pedidos
                </button>
            </div>
            <div class="remision-filter-status" id="remision-filter-status" style="display:none">
                <span class="filter-badge">
                    <i class="bi bi-funnel-fill"></i> Filtrado por Mes: <strong id="rem-filter-mes-txt">—</strong>
                </span>
                <button type="button" class="btn-clear-rem-filter" id="btn-clear-rem-filter" title="Quitar filtro de mes">
                    <i class="bi bi-x-circle-fill"></i> Ver todos los meses
                </button>
            </div>
            <div class="remision-actions">
                <button type="button" class="btn-export-excel" id="btn-export-remision-excel" title="Exportar tabla a Excel">
                    <i class="bi bi-file-earmark-excel-fill"></i> Exportar Excel
                </button>
            </div>
        </div>

        <!-- KPI Cards Resumen -->
        <div class="kpi-grid" id="kpis-evolucion-remision">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.1);color:var(--accent2)"><i class="bi bi-truck"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Distribución Total</div>
                    <div class="kpi-value" id="kv-rem-dist-total">—</div>
                    <div class="kpi-var" id="kvar-rem-dist-pct">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(0,168,120,.1);color:var(--accent)"><i class="bi bi-arrow-repeat"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Reposición Total</div>
                    <div class="kpi-value" id="kv-rem-repo-total">—</div>
                    <div class="kpi-var" id="kvar-rem-repo-pct">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-shop"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Locales Propios</div>
                    <div class="kpi-value" id="kv-rem-propios-total">—</div>
                    <div class="kpi-var" id="kvar-rem-propios-pct">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(124,58,237,.1);color:#7c3aed"><i class="bi bi-building"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Franquicias</div>
                    <div class="kpi-value" id="kv-rem-franq-total">—</div>
                    <div class="kpi-var" id="kvar-rem-franq-pct">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-check2-circle"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total General</div>
                    <div class="kpi-value" id="kv-rem-gran-total">—</div>
                    <div class="kpi-var neu" id="kvar-rem-pedidos-total">—</div>
                </div>
            </div>
        </div>

        <!-- Fila 1 de Gráficos: Universos Distribución y Reposición (Franquicias vs Propios) -->
        <div class="resumen-row">
            <!-- Gráfica 1: Universo Todas las Distribuciones -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-pie-chart"></i> 1. Universo: Todas las Distribuciones
                    <span class="header-sub">% Franquicias vs % Propios · clic en una barra para filtrar</span>
                </div>
                <div class="chart-wrap">
                    <canvas id="chart-rem-distribuciones" height="270"></canvas>
                </div>
            </div>

            <!-- Gráfica 2: Universo Todas las Reposiciones -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-pie-chart-fill"></i> 2. Universo: Todas las Reposiciones
                    <span class="header-sub">% Franquicias vs % Propios · clic en una barra para filtrar</span>
                </div>
                <div class="chart-wrap">
                    <canvas id="chart-rem-reposiciones" height="270"></canvas>
                </div>
            </div>
        </div>

        <!-- Fila 2 de Gráficos: Universos Propios y Franquicias (Distribución vs Reposición) -->
        <div class="resumen-row">
            <!-- Gráfica 3: Universo Propios -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-shop-window"></i> 3. Universo: Pedidos de Locales Propios
                    <span class="header-sub">% Distribución vs % Reposición · clic en una barra para filtrar</span>
                </div>
                <div class="chart-wrap">
                    <canvas id="chart-rem-propios" height="270"></canvas>
                </div>
            </div>

            <!-- Gráfica 4: Universo Franquicias -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-building-check"></i> 4. Universo: Pedidos de Franquicias
                    <span class="header-sub">% Distribución vs % Reposición · clic en una barra para filtrar</span>
                </div>
                <div class="chart-wrap">
                    <canvas id="chart-rem-franquicias" height="270"></canvas>
                </div>
            </div>
        </div>

        <!-- Fila 3 de Gráfico: Universo Total General -->
        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-diagram-3-fill"></i> 5. Universo Total General (Franquicias + Propios)
                <span class="header-sub">Distribución vs Reposición acumulado mensual · clic en una barra para filtrar</span>
            </div>
            <div class="chart-wrap">
                <canvas id="chart-rem-total-general" height="250"></canvas>
            </div>
        </div>

        <!-- Tabla de Detalle Mensual e Interactivo -->
        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-table"></i> Detalle Mensual por Canal y Tipo de Remisión
                <span class="header-sub">Incluye cantidad de locales y promedio por punto de venta · filtrable por mes</span>
            </div>
            <div class="table-wrap" style="max-height:450px;overflow-y:auto">
                <table id="tabla-remision-detalle" class="tabla-drill">
                    <thead>
                        <tr>
                            <th>Mes / Período</th>
                            <th>Canal</th>
                            <th>Tipo de Remisión</th>
                            <th class="col-num">Cant. Locales</th>
                            <th class="col-num">Cant. Pedidos</th>
                            <th class="col-num">Prom. Ped./Loc.</th>
                            <th class="col-num">% Pedidos</th>
<<<<<<< HEAD
                            <th class="col-num">Unid. Remitidas</th>
=======
                            <th class="col-num">Unid. Facturadas</th>
                            <th class="col-num">Prom. Unid./Loc.</th>
>>>>>>> b14411c3bd81a6c33b1016861ee18c9cb519d2bf
                            <th class="col-num">% Unid. Fact.</th>
                            <th class="col-num">Unid. Pedidas</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-remision-detalle"></tbody>
                    <tfoot id="tfoot-remision-detalle"></tfoot>
                </table>
            </div>
        </div>

    </div>
</div>


<!-- ─────────────────── TAB 9: PEDIDOS ESTANCADOS ─────────────────────── -->
<!-- Vista de supply chain: cuánto hay trabado, por qué (¿hay stock?),     -->
<!-- desde cuándo se acumula y qué acción corresponde con cada pedido.     -->
<div class="tab-pane" id="tab-estancados">
    <div class="dash-content">

        <!-- Umbral de antigüedad -->
        <div class="plan-ventanas-header">
            <span class="plan-ventanas-title"><i class="bi bi-hourglass-bottom"></i> Pedidos del año con unidades pendientes, sin cancelar</span>
            <div class="filter-pills" id="est-umbral" role="group" aria-label="Antigüedad mínima">
                <button type="button" class="pill" data-d="30">+30 días</button>
                <button type="button" class="pill" data-d="60">+60 días</button>
                <button type="button" class="pill active" data-d="90">+90 días</button>
                <button type="button" class="pill" data-d="180">+180 días</button>
            </div>
            <span class="header-sub" id="est-rango">Situación actual · no depende del rango de fechas</span>
        </div>

        <!-- 1. ¿Cuánto hay trabado? -->
        <div class="kpi-grid" id="kpis-estancados">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-hourglass-bottom"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pedidos estancados</div>
                    <div class="kpi-value" id="kv-est-ped">—</div>
                    <div class="kpi-var neu" id="kvar-est-ped"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-box-seam"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades pendientes</div>
                    <div class="kpi-value" id="kv-est-unid">—</div>
                    <div class="kpi-var neu" id="kvar-est-unid"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-cash-stack"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Importe pendiente</div>
                    <div class="kpi-value" id="kv-est-imp">—</div>
                    <div class="kpi-var neu" id="kvar-est-imp"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(124,58,237,.08);color:#7c3aed"><i class="bi bi-calendar-x"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Antigüedad promedio</div>
                    <div class="kpi-value" id="kv-est-dias">—</div>
                    <div class="kpi-var neu" id="kvar-est-dias"></div>
                </div>
            </div>
        </div>

        <!-- 2. ¿Hay stock para lo pendiente? (análisis, sin acciones sugeridas) -->
        <div class="analisis-card">
            <div class="analisis-section-header" id="hdr-est-cobertura">
                <i class="bi bi-box-seam"></i> ¿Hay stock para lo pendiente?
                <span class="header-sub">Stock Tango del depósito 01 · clic en un grupo para filtrar el listado</span>
            </div>
            <div class="est-stock" id="est-stock">
                <div class="est-stock-txt" id="est-stock-txt">—</div>
                <div class="est-stock-bar" aria-hidden="true">
                    <div class="est-stock-con" id="est-stock-con" style="width:0%"></div>
                </div>
                <div class="est-stock-ley">
                    <span><i class="est-dot con"></i> Unidades pendientes con stock disponible</span>
                    <span><i class="est-dot sin"></i> Unidades pendientes sin stock</span>
                </div>
            </div>
            <div class="est-acciones" id="est-cobertura"></div>
        </div>

        <div class="resumen-row">
            <!-- 4. ¿Desde cuándo se acumula? -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-calendar3"></i> ¿Desde cuándo se acumula?
                    <span class="header-sub">Por mes de carga del pedido</span>
                </div>
                <div class="table-wrap">
                    <table id="tabla-est-meses">
                        <thead>
                            <tr>
                                <th>Mes de carga</th>
                                <th class="col-num">Pedidos</th>
                                <th class="col-num">Unidades pendientes</th>
                                <th class="col-num">Importe pendiente</th>
                                <th class="fr-bar-th">Peso en importe</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-est-meses"></tbody>
                    </table>
                </div>
            </div>
            <!-- 5. ¿Por dónde empezar? -->
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-people"></i> ¿Por dónde empezar?
                    <span class="header-sub">Top 30 clientes por importe · clic para filtrar el listado</span>
                </div>
                <div class="table-wrap" style="max-height:360px;overflow-y:auto">
                    <table id="tabla-est-clientes">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Canal</th>
                                <th class="col-num">Pedidos</th>
                                <th class="col-num">Unidades pendientes</th>
                                <th class="col-num">Importe pendiente</th>
                                <th class="col-num">Pedidos con stock</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-est-clientes"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Listado de pedidos estancados -->
        <div class="analisis-card">
            <div class="analisis-section-header" id="hdr-est-pedidos">
                <i class="bi bi-list-ul"></i> Pedidos estancados
                <span class="header-sub" id="est-count">Clic en un pedido para ver el detalle</span>
                <div class="filter-pills" id="est-cob-filtro" role="group" aria-label="Filtrar por cobertura de stock">
                    <button type="button" class="pill active" data-a="ALL">Todos</button>
                    <button type="button" class="pill" data-a="CON STOCK">Con stock</button>
                    <button type="button" class="pill" data-a="STOCK PARCIAL">Stock parcial</button>
                    <button type="button" class="pill" data-a="SIN STOCK">Sin stock</button>
                </div>
                <input type="search" class="est-busca" id="est-busca" placeholder="Buscar pedido o cliente…" aria-label="Buscar pedido o cliente">
            </div>
            <div class="est-cliente-activo" id="est-cliente-activo" hidden></div>
            <div class="table-wrap" style="max-height:480px;overflow-y:auto">
                <table id="tabla-est-pedidos">
                    <thead>
                        <tr>
                            <th>Pedido</th>
                            <th>Fecha pedido</th>
                            <th class="col-num">Días</th>
                            <th>Canal</th>
                            <th>Cliente</th>
                            <th>Estado Tango</th>
                            <th>Situación</th>
                            <th class="col-num">Unidades pedidas</th>
                            <th class="col-num">Unidades pendientes</th>
                            <th class="col-num">Pendientes con stock</th>
                            <th class="col-num">Importe pendiente</th>
                            <th>Stock para el saldo</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-est-pedidos"></tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- ─────────────────── TAB 10: FILL RATE ─────────────────────────────── -->
<!-- Vista por remito: qué se remitió en el día, a qué pedido corresponde -->
<!-- y cómo quedó el cumplimiento (acumulado actual) de ese pedido.       -->
<div class="tab-pane" id="tab-fill-rate">
    <div class="dash-content">

        <!-- Selector de día + tipo -->
        <div class="plan-ventanas-header fr-header">
            <span class="plan-ventanas-title"><i class="bi bi-calendar-day"></i> Remitos emitidos en el día</span>
            <div class="fr-dia" role="group" aria-label="Día de remitos">
                <button type="button" class="fr-nav" id="fr-prev" title="Día anterior" aria-label="Día anterior"><i class="bi bi-chevron-left"></i></button>
                <input type="date" id="fr-fecha" class="fr-fecha" aria-label="Fecha de remitos">
                <button type="button" class="fr-nav" id="fr-next" title="Día siguiente" aria-label="Día siguiente"><i class="bi bi-chevron-right"></i></button>
                <button type="button" class="fr-ultimo" id="fr-ultimo">Último día</button>
            </div>
            <div class="filter-pills" id="fr-tipo" role="group" aria-label="Tipo de remisión">
                <button type="button" class="pill active" data-t="">Todos</button>
                <button type="button" class="pill" data-t="REPOSICION">Reposición</button>
                <button type="button" class="pill" data-t="DIST. INICIAL">Dist. Inicial</button>
            </div>
            <span class="header-sub" id="fr-carga">Qué se remitió en el día y cómo quedaron esos pedidos</span>
        </div>

        <div class="kpi-grid" id="kpis-fill-rate">
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-box-seam"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades remitidas en el día</div>
                    <div class="kpi-value" id="kv-fr-unid">—</div>
                    <div class="kpi-var neu" id="kvar-fr-unid"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(0,168,120,.08);color:var(--accent)"><i class="bi bi-receipt"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Remitos emitidos</div>
                    <div class="kpi-value" id="kv-fr-remitos">—</div>
                    <div class="kpi-var neu" id="kvar-fr-remitos"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(124,58,237,.08);color:#7c3aed"><i class="bi bi-card-list"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pedidos con remito en el día</div>
                    <div class="kpi-value" id="kv-fr-pedidos">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-speedometer2"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Fill rate (% de unidades cumplidas)</div>
                    <div class="kpi-value" id="kv-fr-rate">—</div>
                    <div class="kpi-var semaforo" id="kvar-fr-rate"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-check2-all"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pedidos completos (sin pendientes)</div>
                    <div class="kpi-value" id="kv-fr-completos">—</div>
                    <div class="kpi-var neu" id="kvar-fr-completos"></div>
                </div>
            </div>
        </div>

        <!-- Pedidos cargados el día anterior (D−1): vista de supply chain.
             Cuatro preguntas: cuánto entró vs lo normal, cuándo hay que entregarlo,
             si se está cumpliendo en plazo y qué está en riesgo. -->
        <div class="analisis-card">
            <div class="analisis-section-header" id="hdr-fr-ingreso">
                <i class="bi bi-inbox"></i> Pedidos cargados el día anterior
                <span class="header-sub" id="fr-ing-fecha">Qué entró, cuándo hay que entregarlo y qué está en riesgo</span>
            </div>

            <div class="fr-ing-bloque">
                <div class="fr-ing-pregunta">1. ¿Cuánto entró comparado con un día normal?</div>
                <div class="fr-ing-kpis">
                    <div class="fr-ing-kpi"><span>Pedidos cargados</span><b id="kv-fr-ing-ped">—</b><small id="kvar-fr-ing-ped"></small></div>
                    <div class="fr-ing-kpi"><span>Unidades pedidas</span><b id="kv-fr-ing-unid">—</b><small id="kvar-fr-ing-unid"></small></div>
                    <div class="fr-ing-kpi"><span>Importe pedido</span><b id="kv-fr-ing-imp">—</b><small id="kvar-fr-ing-imp"></small></div>
                </div>
            </div>

            <div class="fr-ing-bloque">
                <div class="fr-ing-pregunta">2. ¿Se está cumpliendo en plazo? <em>(pedidos)</em></div>
                <div class="fr-ing-kpis">
                    <div class="fr-ing-kpi"><span>% de pedidos remitidos a tiempo</span><b id="kv-fr-ing-pct">—</b><small id="kvar-fr-ing-pct"></small></div>
                    <div class="fr-ing-kpi"><span>Remitidos a tiempo</span><b id="kv-fr-ing-atiempo">—</b><small>pedidos</small></div>
                    <div class="fr-ing-kpi"><span>Remitidos tarde</span><b id="kv-fr-ing-tarde">—</b><small>pedidos</small></div>
                    <div class="fr-ing-kpi fr-ing-alerta"><span>Vencidos sin completar</span><b id="kv-fr-ing-venc">—</b><small id="kvar-fr-ing-venc"></small></div>
                    <div class="fr-ing-kpi fr-ing-aviso"><span>En riesgo (vencen hoy o el próximo día hábil)</span><b id="kv-fr-ing-riesgo">—</b><small id="kvar-fr-ing-riesgo"></small></div>
                    <div class="fr-ing-kpi"><span>En plazo (todavía no vencen)</span><b id="kv-fr-ing-plazo">—</b><small id="kvar-fr-ing-plazo"></small></div>
                </div>
                <div class="fr-ing-nota" id="fr-ing-nota"></div>
            </div>

            <div class="resumen-row fr-ing-tablas">
                <div>
                    <div class="fr-ing-pregunta">3. ¿Cuándo hay que entregarlo? <em>(días desde la carga hasta la entrega comprometida)</em></div>
                    <div class="table-wrap">
                        <table id="tabla-fr-plazos">
                            <thead>
                                <tr>
                                    <th>Plazo de entrega</th>
                                    <th class="col-num">Pedidos</th>
                                    <th class="col-num">Unidades pedidas</th>
                                    <th class="col-num">Unidades pendientes</th>
                                    <th class="fr-bar-th">Peso en unidades</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-fr-plazos"></tbody>
                        </table>
                    </div>
                </div>
                <div>
                    <div class="fr-ing-pregunta">Por canal</div>
                    <div class="table-wrap">
                        <table id="tabla-fr-ingreso">
                            <thead>
                                <tr>
                                    <th>Canal</th>
                                    <th class="col-num">Pedidos</th>
                                    <th class="col-num">Unidades pedidas</th>
                                    <th class="col-num">Unidades pendientes</th>
                                    <th class="col-num">% pedidos a tiempo</th>
                                    <th class="col-num">Pedidos a atender</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-fr-ingreso"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="fr-ing-bloque">
                <div class="fr-ing-pregunta">4. ¿Qué hay que atender primero? <em>(pedidos vencidos o que vencen hoy / el próximo día hábil, con unidades pendientes)</em></div>
                <div class="table-wrap" style="max-height:320px;overflow-y:auto">
                    <table id="tabla-fr-riesgo">
                        <thead>
                            <tr>
                                <th>Pedido</th>
                                <th>Cliente</th>
                                <th>Canal</th>
                                <th>Entrega comprometida</th>
                                <th class="col-num">Unidades pedidas</th>
                                <th class="col-num">Unidades pendientes</th>
                                <th>Situación</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-fr-riesgo"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-diagram-3"></i> Remitos del día por canal y tipo
            </div>
            <div class="table-wrap">
                <table id="tabla-fr-apertura">
                    <thead>
                        <tr>
                            <th>Canal</th>
                            <th>Tipo</th>
                            <th class="col-num">Unidades remitidas</th>
                            <th class="col-num">Remitos emitidos</th>
                            <th class="col-num">Pedidos</th>
                            <th class="col-num">Pedidos completos</th>
                            <th class="col-num">Fill rate (% unidades)</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-fr-apertura"></tbody>
                </table>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header" id="hdr-fr-detalle">
                <i class="bi bi-list-ul"></i> Detalle: cada remito y su pedido
                <span class="header-sub" id="fr-count">Clic en un pedido para ver el detalle</span>
                <div class="filter-pills" id="fr-filtro" role="group" aria-label="Filtrar por estado del pedido">
                    <button type="button" class="pill active" data-f="ALL">Todos</button>
                    <button type="button" class="pill" data-f="COMPLETO">Pedidos completos</button>
                    <button type="button" class="pill" data-f="PARCIAL">Pedidos parciales</button>
                    <button type="button" class="pill" data-f="SIN PEDIDO">Remitos sin pedido</button>
                </div>
                <input type="search" class="est-busca" id="fr-busca" placeholder="Buscar remito, pedido o cliente…" aria-label="Buscar remito, pedido o cliente">
            </div>
            <div class="table-wrap" style="max-height:520px;overflow-y:auto">
                <table id="tabla-fr-detalle">
                    <thead>
                        <tr>
                            <th>Remito</th>
                            <th>Pedido</th>
                            <th>Fecha pedido</th>
                            <th>Cliente</th>
                            <th>Canal</th>
                            <th>Tipo</th>
                            <th class="col-num">Unidades en este remito</th>
                            <th class="col-num">Unidades pedidas</th>
                            <th class="col-num">Unidades remitidas (a hoy)</th>
                            <th class="col-num">Unidades pendientes</th>
                            <th class="col-num">% Unidades cumplidas</th>
                            <th>Estado del pedido</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-fr-detalle"></tbody>
                </table>
            </div>
        </div>

    </div>
</div>
