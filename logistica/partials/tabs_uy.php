<?php /* Contenido de las 2 pestañas UY — incluido desde index.php */ ?>

<!-- ─────────────────── TAB UY-1: EFICIENCIA ──────────────────────────── -->
<div class="tab-pane active" id="tab-eficiencia-uy">
    <div class="dash-content">

        <!-- KPIs principales -->
        <div class="kpi-grid" id="kpis-eficiencia-uy">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="bi bi-percent"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Eficiencia facturación</div>
                    <div class="kpi-value" id="kv-uy-efi">—</div>
                    <div class="kpi-var" id="kvar-uy-efi"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.1);color:var(--accent2)"><i class="bi bi-box-seam"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades pedidas</div>
                    <div class="kpi-value" id="kv-uy-unid-ped">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-check-circle"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades facturadas</div>
                    <div class="kpi-value" id="kv-uy-unid-fact">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-hourglass-split"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Unidades no cumplidas</div>
                    <div class="kpi-value" id="kv-uy-unid-pend">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-exclamation-triangle"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pérdida ($UY)</div>
                    <div class="kpi-value" id="kv-uy-perdida-uyu">—</div>
                    <div class="kpi-var" id="kvar-uy-perdida-pct"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.1);color:var(--accent3)"><i class="bi bi-currency-dollar"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pérdida (U$S)</div>
                    <div class="kpi-value" id="kv-uy-perdida-usd">—</div>
                    <div class="kpi-var neu" id="kvar-uy-cotizacion"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-file-earmark-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Pedidos totales</div>
                    <div class="kpi-value" id="kv-uy-pedidos">—</div>
                </div>
            </div>
        </div>

        <!-- Eficiencia semanal -->
        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-graph-up-arrow"></i> Eficiencia Semanal — últimas 12 semanas
            </div>
            <div class="chart-wrap">
                <canvas id="chart-uy-efi-semanal" height="220"></canvas>
            </div>
        </div>

        <!-- Eficiencia mensual (ex "Evolución anual") -->
        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-graph-up"></i> Eficiencia Mensual — mismo mes año anterior
            </div>
            <div class="chart-wrap">
                <canvas id="chart-uy-evolucion" height="220"></canvas>
            </div>
        </div>

        <!-- % Eficiencia pedidos por cliente -->
        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-card-list"></i> % Eficiencia (Pedidos por cliente)
                <span class="header-sub">Clic en un cliente para ver sus pedidos</span>
            </div>
            <div class="table-wrap" style="max-height:440px;overflow-y:auto">
                <table id="tabla-uy-efi-pedidos" class="tabla-drill">
                    <thead>
                        <tr>
                            <th>Cliente / N° pedido</th>
                            <th>Fecha</th>
                            <th class="col-num">Unid. pedidas</th>
                            <th class="col-num">Unid. facturadas</th>
                            <th class="col-num">% Eficiencia</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-uy-efi-pedidos"></tbody>
                </table>
            </div>
        </div>

        <!-- Eficiencia por rubro: gráfico horizontal + tabla -->
        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-bar-chart-steps"></i> Eficiencia por Rubro
            </div>
            <div class="table-wrap" style="max-height:340px;overflow-y:auto">
                <table id="tabla-uy-efi-rubro">
                    <thead>
                        <tr>
                            <th>Rubro</th>
                            <th class="col-num">U. pedidas</th>
                            <th class="col-num">U. facturadas</th>
                            <th class="col-num">Eficiencia</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-uy-efi-rubro"></tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- ─────────────────── TAB UY-2: STOCK TANGO VS WMS ─────────────────── -->
<div class="tab-pane" id="tab-stock-uy">
    <div class="dash-content">

        <!-- KPIs stock -->
        <div class="kpi-grid" id="kpis-stock-uy">
            <div class="kpi-card">
                <div class="kpi-icon"><i class="bi bi-database"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Stock Tango</div>
                    <div class="kpi-value" id="kv-uy-stock-tango">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(37,99,235,.08);color:var(--accent2)"><i class="bi bi-boxes"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Stock Jauser</div>
                    <div class="kpi-value" id="kv-uy-stock-wms">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(220,38,38,.08);color:var(--neg)"><i class="bi bi-arrow-left-right"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Diferencia neta</div>
                    <div class="kpi-value" id="kv-uy-dif-neta">—</div>
                    <div class="kpi-var" id="kvar-uy-dif-neta-pct"></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(245,158,11,.08);color:var(--accent3)"><i class="bi bi-rulers"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Diferencia absoluta</div>
                    <div class="kpi-value" id="kv-uy-dif-abs">—</div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon" style="background:rgba(22,163,74,.1);color:var(--pos)"><i class="bi bi-shield-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Precisión inventario</div>
                    <div class="kpi-value" id="kv-uy-precision">—</div>
                </div>
            </div>
        </div>

        <!-- Gráfico A: Central vs Jauser agrupado por rubro -->
        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-bar-chart"></i> Stock Central vs Jauser por Rubro
            </div>
            <div class="chart-wrap">
                <canvas id="chart-uy-stock-comp" height="280"></canvas>
            </div>
        </div>

        <!-- Gráfico B: Diferencia absoluta por rubro -->
        <div class="analisis-card">
            <div class="analisis-section-header">
                <i class="bi bi-bar-chart-steps"></i> Diferencia absoluta por Rubro
            </div>
            <div class="chart-wrap">
                <canvas id="chart-uy-stock-dif" height="260"></canvas>
            </div>
        </div>

        <!-- Tabla detalle por rubro -->
        <div class="analisis-card">
            <div class="analisis-section-header" id="hdr-uy-stock-detalle">
                <i class="bi bi-table"></i> Detalle por Rubro
                <span class="header-sub">Clic en un rubro para ver los artículos con diferencias</span>
            </div>
            <div class="table-wrap">
                <table id="tabla-uy-stock" class="tabla-drill">
                    <thead>
                        <tr>
                            <th>Rubro</th>
                            <th class="col-num">Stock Tango</th>
                            <th class="col-num">Stock WMS</th>
                            <th class="col-num">Diferencia</th>
                            <th class="col-num">Dif. %</th>
                            <th class="col-num">Dif. Absoluta</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-uy-stock"></tbody>
                </table>
            </div>
        </div>

        <!-- Top 10 Artículos con diferencia (sobrantes / faltantes) -->
        <div class="analisis-card">
            <div class="analisis-section-header" id="hdr-uy-top-arts">
                <i class="bi bi-arrow-left-right"></i> Top 10 Artículos con diferencia
                <span class="header-sub" id="lbl-top-arts-sub">Stock Jauser &gt; Stock Central</span>
            </div>
            <div class="top-arts-toolbar">
                <div class="seg-control" id="seg-top-arts">
                    <button class="seg-btn active" data-tipo="sobrantes"><i class="bi bi-arrow-up-circle"></i> Sobrantes</button>
                    <button class="seg-btn" data-tipo="faltantes"><i class="bi bi-arrow-down-circle"></i> Faltantes</button>
                </div>
                <input type="text" id="inp-buscar-art" placeholder="Buscar artículo..." class="inp-buscar-art">
            </div>
            <div class="table-wrap">
                <table id="tabla-uy-top-arts">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Descripción</th>
                            <th>Rubro</th>
                            <th class="col-num">Stock Central</th>
                            <th class="col-num">Stock WMS</th>
                            <th class="col-num">Diferencia</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-uy-top-arts"></tbody>
                </table>
            </div>
        </div>

    </div>
</div>
