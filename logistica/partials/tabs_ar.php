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
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-exclamation-triangle"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pérdida facturación</div>
                    <div class="kpi-value" id="kv-perdida">—</div>
                    <div class="kpi-var" id="kvar-perdida"></div>
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
            <div class="analisis-section-header">
                <i class="bi bi-table"></i> Detalle por Rubro
            </div>
            <div class="table-wrap">
                <table id="tabla-stock">
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
                <div class="kpi-icon"><i class="bi bi-receipt"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades facturadas</div>
                    <div class="kpi-value" id="kv-pf-unid">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-calendar3"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Días productivos</div>
                    <div class="kpi-value" id="kv-pf-dias">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(0,168,120,.1);color:var(--accent)"><i class="bi bi-calculator"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Promedio u/día</div>
                    <div class="kpi-value" id="kv-pf-prom">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-activity"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Mediana u/día</div>
                    <div class="kpi-value" id="kv-pf-mediana">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Máximo u/día</div>
                    <div class="kpi-value" id="kv-pf-moda">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-calendar-week"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades últ. 30 días</div>
                    <div class="kpi-value" id="kv-pf-ult30">—</div>
                </div>
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
                <i class="bi bi-people"></i> Productividad por Usuario
            </div>
            <div class="table-wrap">
                <table id="tabla-usuarios-fact">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th class="col-num">Unidades</th>
                            <th class="col-num">Días productivos</th>
                            <th class="col-num">Promedio/día</th>
                            <th class="col-num">Mediana/día</th>
                            <th class="col-num">Máximo/día</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-usuarios-fact"></tbody>
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
                <table id="tabla-picking-ult7">
                    <thead id="thead-picking-ult7"></thead>
                    <tbody id="tbody-picking-ult7"></tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- ─────────────────── TAB 6: DEMANDA Y DESPACHO ─────────────────────── -->
<div class="tab-pane" id="tab-despacho">
    <div class="dash-content">

        <div class="kpi-grid" id="kpis-despacho">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="bi bi-clipboard-data"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pedidos totales</div>
                    <div class="kpi-value" id="kv-dd-ped-tot">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-hourglass"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pedidos pendientes</div>
                    <div class="kpi-value" id="kv-dd-ped-pend">—</div>
                    <div class="kpi-var" id="kvar-dd-ped-pend"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-percent"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Cumplimiento unidades</div>
                    <div class="kpi-value" id="kv-dd-cumpl">—</div>
                    <div class="kpi-var semaforo" id="kvar-dd-cumpl"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-check2-all"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Eficacia despacho</div>
                    <div class="kpi-value" id="kv-dd-eficacia">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(0,168,120,.1);color:var(--accent)"><i class="bi bi-calendar-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Próximo día hábil</div>
                    <div class="kpi-value" id="kv-dd-prox-habil">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-people"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pickers necesarios</div>
                    <div class="kpi-value" id="kv-dd-pickers">—</div>
                </div>
            </div>
        </div>

        <div class="resumen-row">
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-hourglass-split"></i> Pendientes hoy
                </div>
                <div class="table-wrap" style="max-height:320px;overflow-y:auto">
                    <table id="tabla-pend-hoy">
                        <thead>
                            <tr>
                                <th>Pedido</th>
                                <th>Cliente</th>
                                <th class="col-num">Unidades</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-pend-hoy"></tbody>
                    </table>
                </div>
            </div>
            <div class="analisis-card">
                <div class="analisis-section-header">
                    <i class="bi bi-exclamation-triangle"></i> Demorados
                </div>
                <div class="table-wrap" style="max-height:320px;overflow-y:auto">
                    <table id="tabla-demorados">
                        <thead>
                            <tr>
                                <th>Pedido</th>
                                <th>Cliente</th>
                                <th>Estado</th>
                                <th class="col-num">Unidades</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-demorados"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-truck"></i> Cola de pedidos pendientes
                <span class="header-sub" id="prox-habil-label"></span>
            </div>
            <div class="table-wrap">
                <table id="tabla-prox-entrega">
                    <thead>
                        <tr>
                            <th>Pedido</th>
                            <th>Cod. Cliente</th>
                            <th>Nombre</th>
                            <th>Fecha pedido</th>
                            <th class="col-num">Unidades</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-prox-entrega"></tbody>
                </table>
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
