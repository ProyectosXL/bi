/**
 * /bi/global/js/liquidacion.js
 * Módulo para la pestaña "Liquidación" con soporte Power BI (filtros cruzados interactivos).
 */
const Liquidacion = (function () {
    let _state = null;
    let _chartInstance = null;
    let _timelineChartInstance = null;
    let _donutFactInstance = null;
    let _donutUnidInstance = null;
    let _donutRefInstance = null;

    // Filtros activos globales (Power BI style)
    let _activeFilters = {
        tipo: null,       // 'LIQUIDACION' | 'NORMAL'
        rubro: null,      // string
        categoria: null   // string
    };

    // Niveles de drilldown para los gráficos de dona (0 = Tipo, 1 = Rubro, 2 = Categoría)
    let _donutFactLevel = 0;
    let _donutFactRubro = null;
    let _donutUnidLevel = 0;
    let _donutUnidRubro = null;
    let _donutRefLevel = 0;
    let _donutRefRubro = null;

    // Filtro temporal para el gráfico de barras por rubro
    let _currentRubroFilter = null; 

    async function loadAll() {
        const pane = document.getElementById('tab-liquidacion');
        if (!pane) return;

        if (typeof Dashboard !== 'undefined' && typeof Dashboard.setLoading === 'function') {
            Dashboard.setLoading(true, 'Cargando liquidación...');
        }

        // Destruir gráficos anteriores
        destroyCharts();

        pane.querySelectorAll('.ranking-table tbody').forEach(tbody => {
            tbody.innerHTML = '<tr><td colspan="100%" style="text-align: center; padding: 20px;"><i class="bi bi-arrow-repeat spin"></i> Cargando datos de liquidación...</td></tr>';
        });

        if (!document.getElementById('liq-spin-style')) {
            const style = document.createElement('style');
            style.id = 'liq-spin-style';
            style.textContent = `
                .spin { display: inline-block; animation: liq-spin 1s linear infinite; }
                @keyframes liq-spin { to { transform: rotate(360deg); } }
                .row-rubro-liq { background: rgba(255,255,255,0.02); cursor: pointer; }
                .row-rubro-liq:hover { background: rgba(255,255,255,0.05); }
                .row-cat-liq { background: rgba(0,0,0,0.15); display: none; }
                .liq-icon { display: inline-block; width: 12px; margin-right: 6px; transition: transform 0.2s; }
                .expanded .liq-icon { transform: rotate(90deg); }
                .liq-row-filtered { background: rgba(59, 130, 246, 0.15) !important; }
            `;
            document.head.appendChild(style);
        }

        // Configurar botones de volver de donas
        const backFactBtn = document.getElementById('liq-btn-back-donut-fact');
        if (backFactBtn) {
            backFactBtn.onclick = () => {
                if (_donutFactLevel === 2) {
                    _donutFactLevel = 1;
                    _activeFilters.categoria = null;
                } else if (_donutFactLevel === 1) {
                    _donutFactLevel = 0;
                    _donutFactRubro = null;
                    _activeFilters.rubro = null;
                }
                applyFilters();
            };
        }

        const backUnidBtn = document.getElementById('liq-btn-back-donut-unid');
        if (backUnidBtn) {
            backUnidBtn.onclick = () => {
                if (_donutUnidLevel === 2) {
                    _donutUnidLevel = 1;
                    _activeFilters.categoria = null;
                } else if (_donutUnidLevel === 1) {
                    _donutUnidLevel = 0;
                    _donutUnidRubro = null;
                    _activeFilters.rubro = null;
                }
                applyFilters();
            };
        }

        const backRefBtn = document.getElementById('liq-btn-back-donut-ref');
        if (backRefBtn) {
            backRefBtn.onclick = () => {
                if (_donutRefLevel === 2) {
                    _donutRefLevel = 1;
                    _activeFilters.categoria = null;
                } else if (_donutRefLevel === 1) {
                    _donutRefLevel = 0;
                    _donutRefRubro = null;
                    _activeFilters.rubro = null;
                }
                applyFilters();
            };
        }

        // Botón volver de gráfico de barras principal
        const backBtn = document.getElementById('liq-btn-back-chart');
        if (backBtn) {
            backBtn.onclick = () => {
                _currentRubroFilter = null;
                renderGraficoRubros();
            };
        }

        // Botón limpiar todos los filtros del banner
        const clearBtn = document.getElementById('liq-btn-clear-all-filters');
        if (clearBtn) {
            clearBtn.onclick = () => {
                clearAllFilters();
            };
        }

        try {
            const params = Dashboard.getParams();
            // Inyectar filtros activos
            if (_activeFilters.tipo) params.liq_tipo = _activeFilters.tipo;
            if (_activeFilters.rubro) params.liq_rubro = _activeFilters.rubro;
            if (_activeFilters.categoria) params.liq_categoria = _activeFilters.categoria;

            const res = await Dashboard.apiFetch('liquidacion.php', params);
            if (!res.ok) throw new Error(res.error || 'Error al obtener datos de liquidación');

            _state = res;
            renderAll();
            renderActiveFiltersBar();
        } catch (err) {
            console.error('[Liquidacion] Error:', err);
            pane.querySelectorAll('.ranking-table tbody').forEach(tbody => {
                tbody.innerHTML = `<tr><td colspan="100%" style="text-align: center; color: var(--accent); padding: 20px;"><i class="bi bi-exclamation-triangle-fill"></i> ${err.message}</td></tr>`;
            });
        } finally {
            if (typeof Dashboard !== 'undefined' && typeof Dashboard.setLoading === 'function') {
                Dashboard.setLoading(false);
            }
        }
    }

    function destroyCharts() {
        if (_chartInstance) { _chartInstance.destroy(); _chartInstance = null; }
        if (_timelineChartInstance) { _timelineChartInstance.destroy(); _timelineChartInstance = null; }
        if (_donutFactInstance) { _donutFactInstance.destroy(); _donutFactInstance = null; }
        if (_donutUnidInstance) { _donutUnidInstance.destroy(); _donutUnidInstance = null; }
        if (_donutRefInstance) { _donutRefInstance.destroy(); _donutRefInstance = null; }
    }

    function clearAllFilters() {
        _activeFilters.tipo = null;
        _activeFilters.rubro = null;
        _activeFilters.categoria = null;
        _donutFactLevel = 0;
        _donutFactRubro = null;
        _donutUnidLevel = 0;
        _donutUnidRubro = null;
        _donutRefLevel = 0;
        _donutRefRubro = null;
        _currentRubroFilter = null;
        loadAll();
    }

    function applyFilters() {
        loadAll();
    }

    function renderActiveFiltersBar() {
        const bar = document.getElementById('liq-active-filters-bar');
        const text = document.getElementById('liq-active-filters-text');
        if (!bar || !text) return;

        const filters = [];
        if (_activeFilters.tipo) filters.push(`Tipo: <strong>${_activeFilters.tipo}</strong>`);
        if (_activeFilters.rubro) filters.push(`Rubro: <strong>${_activeFilters.rubro}</strong>`);
        if (_activeFilters.categoria) filters.push(`Categoría: <strong>${_activeFilters.categoria}</strong>`);

        if (filters.length > 0) {
            text.innerHTML = 'Filtros aplicados: ' + filters.join(' | ');
            bar.style.display = 'flex';
        } else {
            bar.style.display = 'none';
        }
    }

    function getSucursalName(nro) {
        const sel = document.getElementById('sel-sucursal');
        if (sel) {
            const opt = sel.querySelector(`option[value="${nro}"]`);
            if (opt) return opt.textContent;
        }
        return `Sucursal ${nro}`;
    }

    function renderAll() {
        if (!_state) return;

        const k = _state.kpis;
        
        const fmtMoney = (val) => {
            if (val === null || val === undefined || isNaN(val)) return '—';
            return Dashboard.fmt.money(val);
        };
        const fmtNum = (val) => {
            if (val === null || val === undefined || isNaN(val)) return '—';
            return Dashboard.fmt.num(val);
        };

        // Renderizar KPIs
        document.getElementById('liq-kpi-facturacion').textContent = fmtMoney(k.fact_act);
        document.getElementById('liq-kpi-facturacion-prev').textContent = fmtMoney(k.fact_prev);

        document.getElementById('liq-kpi-unidades').textContent = fmtNum(k.unid_act);
        document.getElementById('liq-kpi-unidades-prev').textContent = fmtNum(k.unid_prev);

        document.getElementById('liq-kpi-tickets').textContent = fmtNum(k.tickets_act);
        document.getElementById('liq-kpi-tickets-prev').textContent = fmtNum(k.tickets_prev);

        const elRef = document.getElementById('liq-kpi-referencias');
        if (elRef) elRef.textContent = fmtNum(k.ref_act);
        const elRefPrev = document.getElementById('liq-kpi-referencias-prev');
        if (elRefPrev) elRefPrev.textContent = fmtNum(k.ref_prev);

        document.getElementById('liq-kpi-promedio').textContent = fmtMoney(k.ticket_prom_act);
        document.getElementById('liq-kpi-promedio-prev').textContent = fmtMoney(k.ticket_prom_prev);

        // Renderizar Tabla de Sucursales
        const sTableBody = document.querySelector('#liq-table-sucursales tbody');
        sTableBody.innerHTML = '';
        
        const sucursalesKeys = Object.keys(_state.sucursales);
        let totalFact = 0;
        let totalUnid = 0;
        let totalRef = 0;
        if (sucursalesKeys.length === 0) {
            sTableBody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px;">Sin ventas en el período</td></tr>';
        } else {
            const sortedSucs = sucursalesKeys.map(k => _state.sucursales[k]).sort((a, b) => b.facturacion - a.facturacion);
            sortedSucs.forEach(s => {
                totalFact += s.facturacion;
                totalUnid += s.unidades;
                totalRef += (s.referencias || 0);
                const tr = document.createElement('tr');
                const name = getSucursalName(s.nro_sucurs);
                tr.innerHTML = `
                    <td style="font-weight:600">${name}</td>
                    <td style="text-align:right">${fmtMoney(s.facturacion)}</td>
                    <td style="text-align:right">${fmtNum(s.unidades)}</td>
                    <td style="text-align:right; font-weight:600; color:#3b82f6;">${fmtNum(s.referencias || 0)}</td>
                `;
                sTableBody.appendChild(tr);
            });
        }

        // Totales en el tfoot de Sucursales
        let sTable = document.getElementById('liq-table-sucursales');
        if (sTable) {
            let tfoot = sTable.querySelector('tfoot');
            if (!tfoot) {
                tfoot = document.createElement('tfoot');
                sTable.appendChild(tfoot);
            }
            if (sucursalesKeys.length > 0) {
                tfoot.innerHTML = `
                    <tr style="font-weight:700; border-top:2px solid rgba(0,0,0,0.1); background:#f8fafc; position:sticky; bottom:0; z-index:10;">
                        <td style="padding:10px 12px; color:#1e293b;">TOTAL</td>
                        <td style="text-align:right; padding:10px 12px; color:#1e293b;">${fmtMoney(totalFact)}</td>
                        <td style="text-align:right; padding:10px 12px; color:#1e293b;">${fmtNum(totalUnid)}</td>
                        <td style="text-align:right; padding:10px 12px; color:#2563eb;">${fmtNum(k.ref_act)}</td>
                    </tr>
                `;
            } else {
                tfoot.innerHTML = '';
            }
        }

        // Renderizar Tabla de Productos
        const pTableBody = document.querySelector('#liq-table-productos tbody');
        pTableBody.innerHTML = '';
        let totalProdQty = 0;
        let totalProdAmt = 0;
        if (_state.productos.length === 0) {
            pTableBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px;">Sin artículos vendidos en el período</td></tr>';
        } else {
            _state.productos.forEach(p => {
                totalProdQty += p.cantidad;
                totalProdAmt += p.importe;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight:600">${p.COD_ARTICU}</td>
                    <td>${p.DESCRIPCION || '—'}</td>
                    <td>${p.TEMPORADA || '—'}</td>
                    <td style="text-align:right">${fmtNum(p.cantidad)}</td>
                    <td style="text-align:right">${fmtMoney(p.importe)}</td>
                `;
                pTableBody.appendChild(tr);
            });
        }

        // Totales en el tfoot de Productos
        let pTable = document.getElementById('liq-table-productos');
        if (pTable) {
            let tfoot = pTable.querySelector('tfoot');
            if (!tfoot) {
                tfoot = document.createElement('tfoot');
                pTable.appendChild(tfoot);
            }
            if (_state.productos.length > 0) {
                tfoot.innerHTML = `
                    <tr style="font-weight:700; border-top:2px solid rgba(0,0,0,0.1); background:#f8fafc; position:sticky; bottom:0; z-index:10;">
                        <td style="padding:10px 12px; color:#1e293b;" colspan="3">TOTAL (Top ${_state.productos.length} SKUs / Referencias)</td>
                        <td style="text-align:right; padding:10px 12px; color:#1e293b;">${fmtNum(totalProdQty)}</td>
                        <td style="text-align:right; padding:10px 12px; color:#1e293b;">${fmtMoney(totalProdAmt)}</td>
                    </tr>
                `;
            } else {
                tfoot.innerHTML = '';
            }
        }

        renderJerarquiaTable();
        renderGraficoRubros();
        renderTimelineChart();
        renderDonuts();

        if (typeof ExcelExporter !== 'undefined') {
            const addExport = (tableId, callback) => {
                const table = document.getElementById(tableId);
                if (!table) return;
                const card = table.closest('.analisis-card');
                if (!card) return;
                const header = card.querySelector('.analisis-section-header');
                if (!header) return;
                ExcelExporter.addExportButton(header, callback);
            };
            addExport('liq-table-jerarquia', exportarJerarquia);
            addExport('liq-table-sucursales', exportarSucursales);
            addExport('liq-table-productos', exportarProductos);
        }
    }

    function renderJerarquiaTable() {
        const tbody = document.querySelector('#liq-table-jerarquia tbody');
        if (!tbody) return;

        if (!_state.jerarquia || _state.jerarquia.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:20px;">Sin datos en el período</td></tr>';
            return;
        }

        let html = '';
        const sortedRubros = [..._state.jerarquia].sort((a, b) => b.fact_liq - a.fact_liq);

        sortedRubros.forEach((rub, ri) => {
            const totalRef = (rub.ref_liq || 0) + (rub.ref_norm || 0);
            const mixRef = totalRef > 0 ? rub.ref_liq / totalRef : 0;
            const hasCats = rub.categorias && rub.categorias.length > 0;
            const isFilteredRub = _activeFilters.rubro === rub.label;

            html += `
                <tr class="row-rubro-liq ${isFilteredRub ? 'liq-row-filtered' : ''}" data-ri="${ri}" data-rubro="${encodeURIComponent(rub.label)}">
                    <td style="font-weight:600;">
                        <span class="liq-icon">${hasCats ? '▶' : ''}</span>
                        ${rub.label}
                    </td>
                    <td style="text-align:right; font-weight:600;">${Dashboard.fmt.money(rub.fact_liq)}</td>
                    <td style="text-align:right; color:var(--text-3);">${Dashboard.fmt.money(rub.fact_norm)}</td>
                    <td style="text-align:right;">${Dashboard.fmt.num(rub.unid_liq)}</td>
                    <td style="text-align:right; color:var(--text-3);">${Dashboard.fmt.num(rub.unid_norm)}</td>
                    <td style="text-align:right; font-weight:600; color:#3b82f6;">${Dashboard.fmt.num(rub.ref_liq || 0)}</td>
                    <td style="text-align:right; color:var(--text-3);">${Dashboard.fmt.num(rub.ref_norm || 0)}</td>
                    <td style="text-align:right; font-weight:600; color:#00a878;">${fmtPct(mixRef)}</td>
                </tr>
            `;

            if (hasCats) {
                const sortedCats = [...rub.categorias].sort((a, b) => b.fact_liq - a.fact_liq);
                sortedCats.forEach(cat => {
                    const catTotalRef = (cat.ref_liq || 0) + (cat.ref_norm || 0);
                    const catMixRef = catTotalRef > 0 ? cat.ref_liq / catTotalRef : 0;
                    const isFilteredCat = _activeFilters.categoria === cat.label;
                    html += `
                        <tr class="row-cat-liq ${isFilteredCat ? 'liq-row-filtered' : ''}" data-ri="${ri}">
                            <td style="padding-left:24px; color:var(--text-2);">${cat.label}</td>
                            <td style="text-align:right;">${Dashboard.fmt.money(cat.fact_liq)}</td>
                            <td style="text-align:right; color:var(--text-3);">${Dashboard.fmt.money(cat.fact_norm)}</td>
                            <td style="text-align:right;">${Dashboard.fmt.num(cat.unid_liq)}</td>
                            <td style="text-align:right; color:var(--text-3);">${Dashboard.fmt.num(cat.unid_norm)}</td>
                            <td style="text-align:right; color:#3b82f6;">${Dashboard.fmt.num(cat.ref_liq || 0)}</td>
                            <td style="text-align:right; color:var(--text-3);">${Dashboard.fmt.num(cat.ref_norm || 0)}</td>
                            <td style="text-align:right; color:#00a878;">${fmtPct(catMixRef)}</td>
                        </tr>
                    `;
                });
            }
        });

        tbody.innerHTML = html;

        tbody.querySelectorAll('.row-rubro-liq').forEach(tr => {
            tr.addEventListener('click', () => {
                const ri = tr.dataset.ri;
                const rubroLabel = decodeURIComponent(tr.dataset.rubro);
                const open = !tr.classList.contains('expanded');
                tr.classList.toggle('expanded', open);
                
                const icon = tr.querySelector('.liq-icon');
                if (icon) {
                    icon.textContent = open ? '▼' : '▶';
                }

                tbody.querySelectorAll(`.row-cat-liq[data-ri="${ri}"]`).forEach(cr => {
                    cr.style.display = open ? 'table-row' : 'none';
                });

                // Al hacer clic en una fila, actualizamos el filtro activo de Rubro y recargamos
                _activeFilters.rubro = rubroLabel;
                _activeFilters.categoria = null;
                _donutFactLevel = 1;
                _donutFactRubro = rubroLabel;
                _donutUnidLevel = 1;
                _donutUnidRubro = rubroLabel;
                _donutRefLevel = 1;
                _donutRefRubro = rubroLabel;
                _currentRubroFilter = rubroLabel;
                applyFilters();
            });
        });

        // Calcular Totales para la Jerarquía
        let totalLiqFact = 0;
        let totalNormFact = 0;
        let totalLiqUnid = 0;
        let totalNormUnid = 0;
        let totalLiqRef = 0;
        let totalNormRef = 0;

        _state.jerarquia.forEach(rub => {
            totalLiqFact += rub.fact_liq;
            totalNormFact += rub.fact_norm;
            totalLiqUnid += rub.unid_liq;
            totalNormUnid += rub.unid_norm;
            totalLiqRef += (rub.ref_liq || 0);
            totalNormRef += (rub.ref_norm || 0);
        });

        const totalRefGen = totalLiqRef + totalNormRef;
        const mixRefGen = totalRefGen > 0 ? totalLiqRef / totalRefGen : 0;

        let jTable = document.getElementById('liq-table-jerarquia');
        if (jTable) {
            let tfoot = jTable.querySelector('tfoot');
            if (!tfoot) {
                tfoot = document.createElement('tfoot');
                jTable.appendChild(tfoot);
            }
            if (_state.jerarquia.length > 0) {
                tfoot.innerHTML = `
                    <tr style="font-weight:700; border-top:2px solid rgba(0,0,0,0.1); background:#f8fafc; position:sticky; bottom:0; z-index:10;">
                        <td style="padding:10px 12px; color:#1e293b;">TOTAL</td>
                        <td style="text-align:right; padding:10px 12px; color:#1e293b;">${Dashboard.fmt.money(totalLiqFact)}</td>
                        <td style="text-align:right; padding:10px 12px; color:#64748b;">${Dashboard.fmt.money(totalNormFact)}</td>
                        <td style="text-align:right; padding:10px 12px; color:#1e293b;">${Dashboard.fmt.num(totalLiqUnid)}</td>
                        <td style="text-align:right; padding:10px 12px; color:#64748b;">${Dashboard.fmt.num(totalNormUnid)}</td>
                        <td style="text-align:right; padding:10px 12px; color:#2563eb;">${Dashboard.fmt.num(totalLiqRef)}</td>
                        <td style="text-align:right; padding:10px 12px; color:#64748b;">${Dashboard.fmt.num(totalNormRef)}</td>
                        <td style="text-align:right; padding:10px 12px; color:#00a878;">${fmtPct(mixRefGen)}</td>
                    </tr>
                `;
            } else {
                tfoot.innerHTML = '';
            }
        }
    }

    function renderGraficoRubros() {
        const ctx = document.getElementById('liq-chart-rubros');
        if (!ctx) return;

        if (_chartInstance) {
            _chartInstance.destroy();
            _chartInstance = null;
        }

        const bc = document.getElementById('liq-chart-breadcrumb');
        let labels = [];
        let dataLiq = [];
        let dataNorm = [];

        if (!_currentRubroFilter) {
            if (bc) bc.style.display = 'none';
            const sortedRubros = [..._state.jerarquia].sort((a, b) => b.fact_liq - a.fact_liq).slice(0, 10);
            labels = sortedRubros.map(r => r.label);
            dataLiq = sortedRubros.map(r => Dashboard.convertir(r.fact_liq));
            dataNorm = sortedRubros.map(r => Dashboard.convertir(r.fact_norm));
        } else {
            if (bc) bc.style.display = 'block';
            const rub = _state.jerarquia.find(r => r.label === _currentRubroFilter);
            if (rub && rub.categorias) {
                const sortedCats = [...rub.categorias].sort((a, b) => b.fact_liq - a.fact_liq).slice(0, 10);
                labels = sortedCats.map(c => c.label);
                dataLiq = sortedCats.map(c => Dashboard.convertir(c.fact_liq));
                dataNorm = sortedCats.map(c => Dashboard.convertir(c.fact_norm));
            }
        }

        _chartInstance = new Chart(ctx, {
            type: 'bar',
            plugins: [ChartDataLabels],
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Liquidación',
                        data: dataLiq,
                        backgroundColor: '#00a878',
                        borderColor: '#00a878',
                        borderWidth: 1,
                        datalabels: {
                            display: false
                        }
                    },
                    {
                        label: 'Normal',
                        data: dataNorm,
                        backgroundColor: 'rgba(156, 163, 175, 0.25)',
                        borderColor: 'rgba(156, 163, 175, 0.4)',
                        borderWidth: 1,
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'right',
                            color: '#4b5563', // Gris visible
                            font: { size: 9, weight: 'bold' },
                            formatter: function(value, context) {
                                const dataIndex = context.dataIndex;
                                const valLiq = context.chart.data.datasets[0].data[dataIndex] || 0;
                                const valNorm = value || 0;
                                const total = valLiq + valNorm;
                                if (total === 0) return '';
                                
                                const pctLiq = Math.round(valLiq / total * 100);
                                const pctNorm = Math.round(valNorm / total * 100);
                                const pfx = Dashboard.moneyPrefix();
                                
                                const formatVal = (v) => {
                                    if (v >= 1000000) return pfx + (v / 1000000).toFixed(1) + 'M';
                                    if (v >= 1000) return pfx + (v / 1000).toFixed(0) + 'K';
                                    return pfx + Math.round(v);
                                };

                                if (valLiq === 0) return `Reg: ${formatVal(valNorm)} (${pctNorm}%)`;
                                if (valNorm === 0) return `Liq: ${formatVal(valLiq)} (${pctLiq}%)`;
                                return `Liq: ${formatVal(valLiq)} (${pctLiq}%) | Reg: ${formatVal(valNorm)} (${pctNorm}%)`;
                            }
                        }
                    }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        right: 180 // Padding a la derecha para que los textos no se corten
                    }
                },
                onClick: (evt, activeElements) => {
                    if (activeElements && activeElements.length > 0 && !_currentRubroFilter) {
                        const index = activeElements[0].index;
                        const clickedLabel = labels[index];
                        _currentRubroFilter = clickedLabel;
                        
                        // Impacta filtros globales
                        _activeFilters.rubro = clickedLabel;
                        _activeFilters.categoria = null;
                        _donutFactLevel = 1;
                        _donutFactRubro = clickedLabel;
                        _donutUnidLevel = 1;
                        _donutUnidRubro = clickedLabel;
                        applyFilters();
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: {
                            color: '#9ca3af',
                            callback: function(value) {
                                return Dashboard.moneyPrefix() + (value / 1000000).toFixed(0) + 'M';
                            }
                        }
                    },
                    y: {
                        stacked: true,
                        grid: { display: false },
                        ticks: { color: '#9ca3af', font: { size: 10, weight: 'bold' } }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { color: '#9ca3af', font: { size: 11, weight: 'bold' }, boxWidth: 12 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) label += ': ';
                                if (context.raw !== null) {
                                    label += Dashboard.moneyPrefix() + context.raw.toLocaleString('es-AR', {maximumFractionDigits:0});
                                }
                                return label;
                            }
                        }
                    },
                    datalabels: {
                        // Desactivamos datalabels globales de opciones ya que están configurados a nivel dataset
                        display: false
                    }
                }
            }
        });
    }

    function renderTimelineChart() {
        const ctx = document.getElementById('liq-chart-timeline');
        if (!ctx) return;

        if (_timelineChartInstance) {
            _timelineChartInstance.destroy();
            _timelineChartInstance = null;
        }

        const actualRaw = _state.timeline?.actual || [];
        const previoRaw = _state.timeline?.previo || [];

        const daysMap = {};
        const chronologicalDates = [];
        
        let start = new Date('2026-07-01T00:00:00');
        const end = new Date('2026-08-16T23:59:59');
        
        while (start <= end) {
            const dateStr = start.toISOString().substring(0, 10);
            const label = dateStr.substring(8, 10) + '/' + dateStr.substring(5, 7); // DD/MM
            chronologicalDates.push(dateStr);
            daysMap[dateStr] = {
                label: label,
                fact_liq: 0.0,
                fact_norm: 0.0,
                fact_prev_total: 0.0
            };
            start.setDate(start.getDate() + 1);
        }

        actualRaw.forEach(r => {
            const dateObj = r.fecha && typeof r.fecha === 'object' && r.fecha.date ? new Date(r.fecha.date) : new Date(r.fecha);
            const dateStr = dateObj.toISOString().substring(0, 10);
            if (daysMap[dateStr]) {
                daysMap[dateStr].fact_liq = Number(r.fact_liq ?? 0);
                daysMap[dateStr].fact_norm = Number(r.fact_norm ?? 0);
            }
        });

        previoRaw.forEach((r, idx) => {
            if (idx < chronologicalDates.length) {
                const dateStr = chronologicalDates[idx];
                daysMap[dateStr].fact_prev_total = Number(r.fact_total ?? 0);
            }
        });

        const labels = chronologicalDates.map(d => daysMap[d].label);
        const liqData = chronologicalDates.map(d => Dashboard.convertir(daysMap[d].fact_liq));
        const normData = chronologicalDates.map(d => Dashboard.convertir(daysMap[d].fact_norm));
        const prevTotalData = chronologicalDates.map(d => Dashboard.convertir(daysMap[d].fact_prev_total));

        _timelineChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Liq. 2026',
                        data: liqData,
                        borderColor: '#00a878',
                        backgroundColor: 'rgba(0, 168, 120, 0.1)',
                        fill: true,
                        tension: 0.2,
                        borderWidth: 2.5
                    },
                    {
                        label: 'Normal 2026',
                        data: normData,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.05)',
                        fill: true,
                        tension: 0.2,
                        borderWidth: 2.5
                    },
                    {
                        label: 'Total 2025 (Previo)',
                        data: prevTotalData,
                        borderColor: '#9ca3af',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.2,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(255,255,255,0.03)' },
                        ticks: { color: '#9ca3af', font: { size: 9 } }
                    },
                    y: {
                        grid: { color: 'rgba(255,255,255,0.05)' },
                        ticks: {
                            color: '#9ca3af',
                            callback: function(value) {
                                return Dashboard.moneyPrefix() + (value / 1000000).toFixed(1) + 'M';
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { color: '#9ca3af', font: { size: 10, weight: 'bold' } }
                    },
                    datalabels: { display: false },
                    tooltip: {
                        backgroundColor: '#1a2340',
                        titleColor: '#9ba8c8',
                        bodyColor: '#ffffff',
                        padding: 10,
                        cornerRadius: 6,
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) label += ': ';
                                if (context.raw !== null) {
                                    label += Dashboard.moneyPrefix() + context.raw.toLocaleString('es-AR', {maximumFractionDigits:0});
                                }
                                return label;
                            },
                            footer: function(tooltipItems) {
                                let totalAct = 0;
                                let totalPrev = 0;
                                
                                tooltipItems.forEach(item => {
                                    if (item.datasetIndex === 0 || item.datasetIndex === 1) {
                                        totalAct += item.raw;
                                    } else if (item.datasetIndex === 2) {
                                        totalPrev = item.raw;
                                    }
                                });
                                
                                if (totalPrev > 0) {
                                    const diff = ((totalAct - totalPrev) / totalPrev * 100).toFixed(1);
                                    const sign = diff >= 0 ? '+' : '';
                                    return `────────────────\nTotal 2026: ${Dashboard.moneyPrefix() + totalAct.toLocaleString('es-AR', {maximumFractionDigits:0})}\nVar. vs 2025: ${sign + diff}%`;
                                }
                                return '';
                            }
                        }
                    }
                }
            }
        });
    }

    function renderDonuts() {
        renderDonutChart('facturacion');
        renderDonutChart('unidades');
        renderDonutChart('referencias');
    }

    function renderDonutChart(tipoMetrica) {
        const isFact = tipoMetrica === 'facturacion';
        const isRef = tipoMetrica === 'referencias';

        let canvasId = 'liq-donut-facturacion';
        let breadcrumbId = 'liq-donut-fact-breadcrumb';
        if (tipoMetrica === 'unidades') {
            canvasId = 'liq-donut-unidades';
            breadcrumbId = 'liq-donut-unid-breadcrumb';
        } else if (tipoMetrica === 'referencias') {
            canvasId = 'liq-donut-referencias';
            breadcrumbId = 'liq-donut-ref-breadcrumb';
        }

        const ctx = document.getElementById(canvasId);
        if (!ctx) return;

        let level = _donutFactLevel;
        let currentRubro = _donutFactRubro;
        if (tipoMetrica === 'unidades') {
            level = _donutUnidLevel;
            currentRubro = _donutUnidRubro;
        } else if (tipoMetrica === 'referencias') {
            level = _donutRefLevel;
            currentRubro = _donutRefRubro;
        }

        const bc = document.getElementById(breadcrumbId);
        if (bc) bc.style.display = level > 0 ? 'block' : 'none';

        let labels = [];
        let rawData = [];
        let colors = [];

        // Generar colores agradables y dinámicos para rubros / categorías
        const generateColors = (n) => {
            const baseColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6', '#6366f1', '#a855f7', '#f43f5e'];
            return Array.from({length: n}, (_, i) => baseColors[i % baseColors.length]);
        };

        if (level === 0) {
            // Nivel 0: Liquidación vs Normal
            labels = ['Liquidación', 'Normal'];
            colors = ['#00a878', 'rgba(156, 163, 175, 0.4)'];
            
            let totalLiq = 0;
            let totalNorm = 0;
            _state.jerarquia.forEach(r => {
                if (isFact) {
                    totalLiq += r.fact_liq;
                    totalNorm += r.fact_norm;
                } else if (isRef) {
                    totalLiq += (r.ref_liq || 0);
                    totalNorm += (r.ref_norm || 0);
                } else {
                    totalLiq += r.unid_liq;
                    totalNorm += r.unid_norm;
                }
            });
            rawData = [
                isFact ? Dashboard.convertir(totalLiq) : totalLiq,
                isFact ? Dashboard.convertir(totalNorm) : totalNorm
            ];
        } else if (level === 1) {
            // Nivel 1: Desglose por Rubros
            const targetType = _activeFilters.tipo || 'LIQUIDACION';
            const sortedRubros = [..._state.jerarquia]
                .map(r => ({
                    label: r.label,
                    val: isFact 
                        ? Dashboard.convertir(targetType === 'LIQUIDACION' ? r.fact_liq : r.fact_norm)
                        : (isRef
                            ? (targetType === 'LIQUIDACION' ? (r.ref_liq || 0) : (r.ref_norm || 0))
                            : (targetType === 'LIQUIDACION' ? r.unid_liq : r.unid_norm))
                }))
                .filter(x => x.val > 0)
                .sort((a, b) => b.val - a.val)
                .slice(0, 8);

            labels = sortedRubros.map(x => x.label);
            rawData = sortedRubros.map(x => x.val);
            colors = generateColors(labels.length);
        } else if (level === 2) {
            // Nivel 2: Desglose por Categorías de un Rubro
            const targetType = _activeFilters.tipo || 'LIQUIDACION';
            const rub = _state.jerarquia.find(r => r.label === currentRubro);
            if (rub && rub.categorias) {
                const sortedCats = [...rub.categorias]
                    .map(c => ({
                        label: c.label,
                        val: isFact
                            ? Dashboard.convertir(targetType === 'LIQUIDACION' ? c.fact_liq : c.fact_norm)
                            : (isRef
                                ? (targetType === 'LIQUIDACION' ? (c.ref_liq || 0) : (c.ref_norm || 0))
                                : (targetType === 'LIQUIDACION' ? c.unid_liq : c.unid_norm))
                    }))
                    .filter(x => x.val > 0)
                    .sort((a, b) => b.val - a.val)
                    .slice(0, 8);

                labels = sortedCats.map(x => x.label);
                rawData = sortedCats.map(x => x.val);
                colors = generateColors(labels.length);
            }
        }

        const instance = new Chart(ctx, {
            type: 'doughnut',
            plugins: [ChartDataLabels],
            data: {
                labels: labels,
                datasets: [{
                    data: rawData,
                    backgroundColor: colors,
                    borderWidth: 1,
                    borderColor: 'rgba(255, 255, 255, 0.1)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onClick: (evt, activeElements) => {
                    if (activeElements && activeElements.length > 0) {
                        const index = activeElements[0].index;
                        const clickedLabel = labels[index];

                        if (level === 0) {
                            // Clic en Liquidación o Normal
                            const filterVal = clickedLabel === 'Liquidación' ? 'LIQUIDACION' : 'NORMAL';
                            _activeFilters.tipo = filterVal;
                            
                            if (isFact) {
                                _donutFactLevel = 1;
                            } else if (isRef) {
                                _donutRefLevel = 1;
                            } else {
                                _donutUnidLevel = 1;
                            }
                            applyFilters();
                        } else if (level === 1) {
                            // Clic en un Rubro
                            _activeFilters.rubro = clickedLabel;
                            _activeFilters.categoria = null;
                            _currentRubroFilter = clickedLabel;
                            
                            if (isFact) {
                                _donutFactLevel = 2;
                                _donutFactRubro = clickedLabel;
                            } else if (isRef) {
                                _donutRefLevel = 2;
                                _donutRefRubro = clickedLabel;
                            } else {
                                _donutUnidLevel = 2;
                                _donutUnidRubro = clickedLabel;
                            }
                            applyFilters();
                        } else if (level === 2) {
                            // Clic en Categoría
                            _activeFilters.categoria = clickedLabel;
                            applyFilters();
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: '#9ca3af',
                            font: { size: 10, weight: 'bold' },
                            boxWidth: 10
                        }
                    },
                    datalabels: {
                        color: '#000000',
                        font: { size: 9, weight: 'bold' },
                        textAlign: 'center',
                        formatter: (value, context) => {
                            if (value <= 0) return '';
                            const sum = context.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = sum > 0 ? Math.round(value / sum * 100) + '%' : '';
                            let fmtVal = '';
                            if (isFact) {
                                if (value >= 1e6) {
                                    fmtVal = Dashboard.moneyPrefix() + (value / 1e6).toFixed(1) + 'M';
                                } else {
                                    fmtVal = Dashboard.moneyPrefix() + Math.round(value).toLocaleString('es-AR');
                                }
                            } else {
                                fmtVal = Math.round(value).toLocaleString('es-AR');
                            }
                            return `${fmtVal}\n(${pct})`;
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const val = context.raw;
                                const sum = context.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = sum > 0 ? (val / sum * 100).toFixed(1) + '%' : '';
                                const label = context.label || '';
                                const fmtVal = isFact 
                                    ? Dashboard.moneyPrefix() + val.toLocaleString('es-AR', {maximumFractionDigits:0})
                                    : val.toLocaleString('es-AR', {maximumFractionDigits:0});
                                return ` ${label}: ${fmtVal} (${pct})`;
                            }
                        }
                    }
                }
            }
        });

        if (isFact) {
            _donutFactInstance = instance;
        } else if (isRef) {
            _donutRefInstance = instance;
        } else {
            _donutUnidInstance = instance;
        }
    }

    function fmtPct(val) {
        if (val === null || val === undefined || isNaN(val)) return '—';
        return (val * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
    }

    function exportarJerarquia() {
        const table = document.getElementById('liq-table-jerarquia');
        if (!table || typeof ExcelExporter === 'undefined') return;
        const filename = `Liquidacion_Apertura_Rubro_Categoria`;
        ExcelExporter.exportTable(table, filename);
    }

    function exportarSucursales() {
        const table = document.getElementById('liq-table-sucursales');
        if (!table || typeof ExcelExporter === 'undefined') return;
        const filename = `Liquidacion_Ventas_Por_Sucursal`;
        ExcelExporter.exportTable(table, filename);
    }

    function exportarProductos() {
        const table = document.getElementById('liq-table-productos');
        if (!table || typeof ExcelExporter === 'undefined') return;
        const filename = `Liquidacion_Articulos_Mas_Vendidos`;
        ExcelExporter.exportTable(table, filename);
    }

    return {
        loadAll
    };
})();
