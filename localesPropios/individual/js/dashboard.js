/**
 * dashboard.js
 * Orquesta todas las llamadas a la API y actualiza el DOM.
 */

const Dashboard = (() => {

    /* ── Estado ──────────────────────────────────── */
    const state = {
        periodo      : 'mes_actual',
        desde        : '',
        hasta        : '',
        vendedor     : '%',
        rubro        : '%',
        loading      : false,
        currentParams: {},
        compMode     : 'year_ago',   // 'year_ago' | 'custom'
        compDesde    : '',
        compHasta    : '',
    };

    let _abortController = null;

    /* ── Utilidades de formato ───────────────────── */
    const fmt = {
        /** $ 1.234.567 */
        money: (n, dec = 0) => {
            if (n === null || n === undefined) return '—';
            return '$\u00A0' + Number(n).toLocaleString('es-AR', {
                minimumFractionDigits: dec,
                maximumFractionDigits: dec,
            });
        },
        /** $ 86.379 */
        moneyK: (n) => {
            if (Math.abs(n) >= 1_000_000)
                return '$\u00A0' + (n / 1_000_000).toLocaleString('es-AR', {minimumFractionDigits:1, maximumFractionDigits:1}) + 'M';
            if (Math.abs(n) >= 1_000)
                return '$\u00A0' + (n / 1_000).toLocaleString('es-AR', {minimumFractionDigits:0, maximumFractionDigits:0}) + 'K';
            return fmt.money(n);
        },
        /** 27,7 % */
        pct: (n, dec = 1) => (n === null || n === undefined) ? '—'
            : (n * 100).toLocaleString('es-AR', {minimumFractionDigits:dec, maximumFractionDigits:dec}) + '\u00A0%',
        /** +8,2 % o -8,2 % */
        varPct: (n, dec = 1) => {
            if (n === null || n === undefined) return '—';
            const sign = n >= 0 ? '+' : '';
            return sign + (n * 100).toLocaleString('es-AR', {minimumFractionDigits:dec, maximumFractionDigits:dec}) + '\u00A0%';
        },
        /** 1.118 */
        num: (n, dec = 0) => (n === null || n === undefined) ? '—'
            : Number(n).toLocaleString('es-AR', {minimumFractionDigits:dec, maximumFractionDigits:dec}),
    };

    /* ── Fetch helpers ───────────────────────────── */
    function buildQS(extra = {}) {
        const p = { periodo: state.periodo, vendedor: state.vendedor, rubro: state.rubro, ...extra };
        if (state.periodo === 'custom') {
            p.desde     = state.desde;
            p.hasta     = state.hasta;
            p.comp_mode = state.compMode;
            if (state.compMode === 'custom') {
                p.desde_comp = state.compDesde;
                p.hasta_comp = state.compHasta;
            }
        }
        return new URLSearchParams(p).toString();
    }

    async function apiFetch(endpoint, extra = {}) {
        const res  = await fetch(`api/${endpoint}?${buildQS(extra)}`, { signal: _abortController?.signal });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Error en API');
        return data;
    }

    /* ── SparkModal ──────────────────────────────── */
    const SparkModal = (() => {
        // Registro de series: canvasId → { values, dates, color, formatFn, title, secondary? }
        const registry = {};
        let _modalChart = null;

        function register(canvasId, values, dates, color, formatFn, title, secondary = null) {
            registry[canvasId] = { values, dates, color, formatFn, title, secondary };
        }

        function open(canvasId) {
            const entry = registry[canvasId];
            if (!entry) return;
            const { values, dates, color, formatFn, title, secondary } = entry;

            // Estadísticas rápidas (serie principal)
            const max    = Math.max(...values);
            const min    = Math.min(...values);
            const avg    = values.reduce((a, b) => a + b, 0) / values.length;
            const last   = values[values.length - 1];
            const first  = values[0];
            const trend  = first !== 0 ? (last - first) / Math.abs(first) : 0;
            const maxIdx  = values.indexOf(max);
            const minIdx  = values.indexOf(min);
            const lastIdx = values.length - 1;
            const fmtStatDate = ds => ds
                ? new Date(ds + 'T00:00:00').toLocaleDateString('es-AR', { weekday: 'short', day: '2-digit', month: '2-digit' })
                : '';

            const trendClass = trend >= 0 ? 'pos' : 'neg';
            const trendSign  = trend >= 0 ? '+' : '';

            // Estadístcas del eje secundario (si existe)
            const secArr = Array.isArray(secondary) ? secondary : (secondary ? [secondary] : []);
            const secStatsHtml = secArr.filter(sec => !sec.hideStats).map(sec => `
                <div class="spark-modal-stat-divider"></div>
                <div class="spark-modal-stat stat-secondary">
                    <span class="spark-modal-stat-label">${sec.label}<br>Total período</span>
                    <span class="spark-modal-stat-val">${fmt.num(sec.values.reduce((a, b) => a + b, 0))}</span>
                </div>
                <div class="spark-modal-stat stat-secondary">
                    <span class="spark-modal-stat-label">${sec.label}<br>Promedio diario</span>
                    <span class="spark-modal-stat-val">${fmt.num(Math.round(sec.values.reduce((a, b) => a + b, 0) / sec.values.length))}</span>
                </div>`).join('');

            const root = document.getElementById('spark-modal-root');
            root.innerHTML = `
                <div class="spark-modal-overlay" id="spark-overlay">
                    <div class="spark-modal">
                        <div class="spark-modal-header">
                            <span class="spark-modal-title">${title}</span>
                            <button class="spark-modal-close" id="spark-modal-close-btn"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="spark-modal-stats">
                            <div class="spark-modal-stat">
                                <span class="spark-modal-stat-label">Último</span>
                                <span class="spark-modal-stat-val">${formatFn(last)}</span>
                                <span class="stat-date">${fmtStatDate(dates[lastIdx])}</span>
                            </div>
                            <div class="spark-modal-stat">
                                <span class="spark-modal-stat-label">Promedio</span>
                                <span class="spark-modal-stat-val">${formatFn(avg)}</span>
                            </div>
                            <div class="spark-modal-stat">
                                <span class="spark-modal-stat-label">Máximo</span>
                                <span class="spark-modal-stat-val">${formatFn(max)}</span>
                                <span class="stat-date">${fmtStatDate(dates[maxIdx])}</span>
                            </div>
                            <div class="spark-modal-stat">
                                <span class="spark-modal-stat-label">Mínimo</span>
                                <span class="spark-modal-stat-val">${formatFn(min)}</span>
                                <span class="stat-date">${fmtStatDate(dates[minIdx])}</span>
                            </div>
                            <div class="spark-modal-stat">
                                <span class="spark-modal-stat-label">Tendencia</span>
                                <span class="spark-modal-stat-val ${trendClass}">${trendSign}${(trend * 100).toLocaleString('es-AR', {minimumFractionDigits:1, maximumFractionDigits:1})} %</span>
                            </div>
                            ${secStatsHtml}
                        </div>
                        <div class="spark-modal-canvas-wrap">
                            <canvas id="spark-modal-canvas"></canvas>
                        </div>
                    </div>
                </div>`;

            // Cerrar al hacer click en overlay o botón
            document.getElementById('spark-overlay').addEventListener('click', (e) => {
                if (e.target === e.currentTarget) close();
            });
            document.getElementById('spark-modal-close-btn').addEventListener('click', close);
            document.addEventListener('keydown', _onKeyDown);

            // ── Construir datasets ──
            if (_modalChart) { _modalChart.destroy(); _modalChart = null; }
            const labels = dates.map(d => new Date(d + 'T00:00:00').toLocaleDateString('es-AR', { day:'2-digit', month:'2-digit' }));

            const datasets = [
                {
                    label          : title,
                    data           : values,
                    borderColor    : color,
                    backgroundColor: color + '20',
                    borderWidth    : 2.5,
                    pointRadius    : values.length <= 31 ? 4 : 2,
                    pointHoverRadius: 6,
                    pointBackgroundColor: color,
                    fill           : true,
                    tension        : 0.35,
                    yAxisID        : 'y',
                    order          : 1,
                }
            ];

            const scales = {
                x: {
                    grid : { color: 'rgba(255,255,255,.05)' },
                    ticks: { color: '#9ba8c8', font: { size: 11 }, maxTicksLimit: 12 }
                },
                y: {
                    position: 'left',
                    grid    : { color: 'rgba(255,255,255,.07)' },
                    ticks   : { color, font: { size: 11 }, callback: v => formatFn(v) }
                }
            };

            // Eje secundario: barras (soporta array o objeto único)
            if (secArr.length) {
                secArr.forEach((sec, i) => {
                    const isInner = i === secArr.length - 1; // el último se dibuja encima (dentro)
                    datasets.push({
                        label             : sec.label,
                        data              : sec.values,
                        type              : 'bar',
                        backgroundColor   : sec.color + (isInner ? '80' : '35'),
                        borderColor       : sec.color + (isInner ? 'cc' : '70'),
                        borderWidth       : 1,
                        borderRadius      : 3,
                        yAxisID           : 'y2',
                        order             : 2 + (secArr.length - 1 - i), // último=2 (frente), primero=3+ (fondo)
                        grouped           : false,
                        barPercentage     : 1.0,
                        categoryPercentage: 0.85,
                    });
                });
                scales.y2 = {
                    position : 'right',
                    grid     : { drawOnChartArea: false },
                    ticks    : { color: secArr[0].color, font: { size: 11 }, callback: v => secArr[0].formatFn(v) },
                };
            }

            const tooltipCallbacks = secArr.length ? {
                title: items => {
                    const idx = items[0].dataIndex;
                    const d = new Date(dates[idx] + 'T00:00:00');
                    return d.toLocaleDateString('es-AR', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' });
                },
                label: ctx => {
                    if (ctx.dataset.yAxisID === 'y2') {
                        const sec = secArr.find(s => s.label === ctx.dataset.label);
                        return sec ? `${sec.label}: ${sec.formatFn(ctx.parsed.y)}` : ctx.dataset.label;
                    }
                    return `${title}: ${formatFn(ctx.parsed.y)}`;
                }
            } : {
                title: items => {
                    const idx = items[0].dataIndex;
                    const d = new Date(dates[idx] + 'T00:00:00');
                    return d.toLocaleDateString('es-AR', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' });
                },
                label: ctx => formatFn(ctx.parsed.y),
            };

            _modalChart = new Chart(
                document.getElementById('spark-modal-canvas').getContext('2d'),
                {
                    type: 'line',
                    data: { labels, datasets },
                    options: {
                        responsive : true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: secArr.length > 0, labels: { color: '#9ba8c8', font: { size: 11 }, boxWidth: 12 } },
                            tooltip: {
                                backgroundColor: '#1a2340',
                                titleColor: '#9ba8c8',
                                bodyColor: '#ffffff',
                                borderColor: color,
                                borderWidth: 1,
                                padding: 10,
                                callbacks: tooltipCallbacks,
                            },
                            datalabels: { display: false },
                        },
                        scales,
                    },
                    plugins: [ChartDataLabels]
                }
            );
        }

        function close() {
            if (_modalChart) { _modalChart.destroy(); _modalChart = null; }
            document.removeEventListener('keydown', _onKeyDown);
            const root = document.getElementById('spark-modal-root');
            if (root) root.innerHTML = '';
        }

        function _onKeyDown(e) { if (e.key === 'Escape') close(); }

        return { register, open };
    })();

    /* ── Mini sparkline con tooltip (Canvas API) ──── */
    function drawSparkline(canvasId, values, dates, color = '#4ade80', formatFn = fmt.money, secondary = null) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !values?.length) return;

        // Guardar serie para el modal
        const titleMap = {
            'spark-fact'         : 'Ventas',
            'spark-unid'         : 'Unidades',
            'spark-tickets-main' : 'Tickets',
            'spark-t2do'         : 'Tickets 2do. Producto',
            'spark-cambios'      : '% Cambios',
            'spark-tprom'        : 'Ticket Promedio',
            'spark-t3ro'         : 'Tickets 3er. Producto',
            'spark-incr'         : '% Incremental',
            'spark-conv'         : 'Conversión (Tickets / Ingresos)',
        };
        SparkModal.register(canvasId, values, dates, color, formatFn, titleMap[canvasId] || canvasId, secondary);

        // Botón expandir (se crea una vez por canvas)
        const wrap = canvas.parentElement;
        if (wrap && !wrap.querySelector('.spark-expand-btn')) {
            wrap.style.position = 'relative';
            const btn = document.createElement('button');
            btn.className = 'spark-expand-btn';
            btn.title = 'Ver gráfico ampliado';
            btn.innerHTML = '<i class="bi bi-arrows-fullscreen"></i>';
            btn.addEventListener('click', (e) => { e.stopPropagation(); SparkModal.open(canvasId); });
            wrap.appendChild(btn);
        }

        const ctx = canvas.getContext('2d');
        const W = canvas.width, H = canvas.height;
        ctx.clearRect(0, 0, W, H);
        const min = Math.min(...values);
        const max = Math.max(...values);
        const range = max - min || 1;
        const pts = values.map((v, i) => ({
            x: (i / (values.length - 1)) * W,
            y: H - ((v - min) / range) * (H - 4) - 2,
            value: v,
            date: dates?.[i] || ''
        }));
        
        // área rellena
        const grad = ctx.createLinearGradient(0, 0, 0, H);
        grad.addColorStop(0, color + '55');
        grad.addColorStop(1, color + '00');
        ctx.beginPath();
        ctx.moveTo(pts[0].x, H);
        pts.forEach(p => ctx.lineTo(p.x, p.y));
        ctx.lineTo(pts[pts.length-1].x, H);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();
        
        // línea
        ctx.beginPath();
        pts.forEach((p, i) => i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y));
        ctx.strokeStyle = color;
        ctx.lineWidth = 2;
        ctx.stroke();
        
        // Agregar interactividad con tooltip
        let tooltip = document.getElementById('sparkline-tooltip-' + canvasId);
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'sparkline-tooltip-' + canvasId;
            tooltip.className = 'sparkline-tooltip';
            tooltip.style.display = 'none';
            document.body.appendChild(tooltip);
        }
        
        // Evento de mouse
        canvas.onmousemove = (e) => {
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            
            // Encontrar punto más cercano
            let closest = pts[0];
            let minDist = Math.abs(x - pts[0].x);
            pts.forEach(p => {
                const dist = Math.abs(x - p.x);
                if (dist < minDist) {
                    minDist = dist;
                    closest = p;
                }
            });
            
            // Mostrar tooltip
            if (minDist < 20) {
                const formattedValue = formatFn(closest.value);
                const formattedDate = closest.date
                    ? new Date(closest.date + 'T00:00:00').toLocaleDateString('es-AR', { weekday: 'short', day: '2-digit', month: '2-digit' })
                    : '';
                tooltip.innerHTML = `<span class="tooltip-date">${formattedDate}</span><span class="tooltip-value">${formattedValue}</span>`;
                tooltip.style.display = 'block';
                tooltip.style.left = (e.clientX + 10) + 'px';
                tooltip.style.top = (e.clientY - 40) + 'px';
            } else {
                tooltip.style.display = 'none';
            }
        };
        
        canvas.onmouseleave = () => {
            tooltip.style.display = 'none';
        };
    }

    /* ── Renderizado de KPI card ─────────────────── */
    function setKpiCard(id, { valor, previo, variacion, benchmark, labelValor, labelBench, formatValor, formatVar }) {
        const el = document.getElementById(id);
        if (!el) return;
        el.querySelector('.kpi-value').textContent   = formatValor(valor);
        el.querySelector('.kpi-previo').textContent  = formatValor(previo);
        const varEl = el.querySelector('.kpi-variacion');
        varEl.textContent = fmt.varPct(variacion);
        varEl.className   = 'kpi-variacion ' + (variacion >= 0 ? 'pos' : 'neg');
        if (el.querySelector('.kpi-bench'))
            el.querySelector('.kpi-bench').textContent = formatValor(benchmark);
    }

    /* ── Renderizado tabla vendedores ────────────── */
    // Estado de sorting para tabla vendedores
    const sortState = {
        column: 'facturacion',
        ascending: false,
        vendedores: [],
        kpiSucursal: null
    };

    function renderVendedores(vendedores, kpiSucursal) {
        sortState.vendedores = vendedores;
        sortState.kpiSucursal = kpiSucursal;
        
        const tbody = document.querySelector('#tabla-vendedores tbody');
        if (!tbody) return;
        tbody.innerHTML = '';

        // Totales
        const tot = vendedores.reduce((acc, v) => {
            acc.unidades    += v.unidades;
            acc.facturacion += v.facturacion;
            acc.tickets     += v.tickets;
            return acc;
        }, { unidades: 0, facturacion: 0, tickets: 0 });

        // Formato condicional comparando con KPI de sucursal
        const colorKPI = (vendVal, sucVal) => {
            if (!kpiSucursal || sucVal === 0 || sucVal === null || sucVal === undefined || vendVal === null || vendVal === undefined) return '';
            const diff = (vendVal - sucVal) / sucVal;
            if (diff >= 0) return 'cell-green';
            if (diff >= -0.10) return 'cell-yellow';
            return 'cell-red';
        };

        vendedores.forEach(v => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="td-nombre">${v.vendedor}</td>
                <td class="td-num">${fmt.num(v.unidades)}</td>
                <td class="td-num">${fmt.money(v.facturacion)}</td>
                <td class="td-num">${fmt.num(v.tickets)}</td>
                <td class="td-num td-prom ${colorKPI(v.ticket_promedio, kpiSucursal.ticket_promedio)}">${fmt.money(v.ticket_promedio)}</td>
                <td class="td-num ${colorKPI(v.porc_2do, kpiSucursal.porc_2do)}">${fmt.pct(v.porc_2do)}</td>
                <td class="td-num ${colorKPI(v.porc_3ro, kpiSucursal.porc_3ro)}">${fmt.pct(v.porc_3ro)}</td>
                <td class="td-num ${colorKPI(v.porc_cambios, kpiSucursal.porc_cambios)}">${fmt.pct(v.porc_cambios)}</td>
                <td class="td-num ${colorKPI(v.porc_incremental, kpiSucursal.porc_incremental)}">${fmt.pct(v.porc_incremental)}</td>
            `;
            tbody.appendChild(tr);
        });

        // Fila total
        const trTot = document.createElement('tr');
        trTot.className = 'tr-total';
        trTot.innerHTML = `
            <td><strong>Total</strong></td>
            <td class="td-num"><strong>${fmt.num(tot.unidades)}</strong></td>
            <td class="td-num"><strong>${fmt.money(tot.facturacion)}</strong></td>
            <td class="td-num"><strong>${fmt.num(tot.tickets)}</strong></td>
            <td colspan="5"></td>
        `;
        tbody.appendChild(trTot);
    }

    /* ── Sorting Tabla Vendedores ────────────────── */
    function setupTableSorting() {
        const headers = document.querySelectorAll('#tabla-vendedores thead th');
        const columnMap = [
            'vendedor',
            'unidades',
            'facturacion',
            'tickets',
            'ticket_promedio',
            'porc_2do',
            'porc_3ro',
            'porc_cambios',
            'porc_incremental'
        ];

        headers.forEach((th, index) => {
            th.addEventListener('click', () => {
                const column = columnMap[index];
                if (!column) return;

                // Toggle orden si es la misma columna
                if (sortState.column === column) {
                    sortState.ascending = !sortState.ascending;
                } else {
                    sortState.column = column;
                    sortState.ascending = column === 'vendedor' ? true : false;
                }

                // Ordenar
                const sorted = [...sortState.vendedores].sort((a, b) => {
                    let valA = a[column];
                    let valB = b[column];

                    if (typeof valA === 'string') {
                        return sortState.ascending 
                            ? valA.localeCompare(valB)
                            : valB.localeCompare(valA);
                    }

                    return sortState.ascending 
                        ? valA - valB
                        : valB - valA;
                });

                // Re-renderizar
                renderVendedores(sorted, sortState.kpiSucursal);

                // Actualizar indicadores visuales
                headers.forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
                th.classList.add(sortState.ascending ? 'sort-asc' : 'sort-desc');
            });
        });
    }

    /* ── Ranking Rubros ──────────────────────────── */
    const RankingRubros = {
        cache: {},
        drilldownState: { level: 1, rubro: null },
        
        render(rubrosData) {
            const container = document.getElementById('ranking-rubros');
            if (!container) return;
            
            // Guardar datos originales en caché
            if (!this.cache.originalData) {
                this.cache.originalData = rubrosData;
            }
            
            // Preservar breadcrumb si existe
            const existingBreadcrumb = container.querySelector('.ranking-breadcrumb');
            
            // Reset breadcrumb visibility: visible solo en drilldown activo
            if (existingBreadcrumb) {
                existingBreadcrumb.style.display = this.drilldownState.level === 2 ? 'block' : 'none';
            }

            // Limpiar contenido excepto breadcrumb
            Array.from(container.children).forEach(child => {
                if (!child.classList.contains('ranking-breadcrumb')) {
                    child.remove();
                }
            });
            
            // Crear breadcrumb si no existe
            if (!existingBreadcrumb) {
                const breadcrumb = document.createElement('div');
                breadcrumb.className = 'ranking-breadcrumb';
                breadcrumb.style.display = 'none';
                breadcrumb.innerHTML = '<button class="btn-volver-ranking">← Volver</button>';
                breadcrumb.querySelector('.btn-volver-ranking').addEventListener('click', () => {
                    this.drilldownState.level = 1;
                    this.drilldownState.rubro = null;
                    breadcrumb.style.display = 'none';
                    this.render(this.cache.originalData);
                });
                container.insertBefore(breadcrumb, container.firstChild);
            }
            
            const rubros = rubrosData.rubros || rubrosData;
            const max = rubros[0]?.unidades || 1;

            rubros.forEach((r, i) => {
                const pct = (r.unidades / max) * 100;
                const target = r.unidades_prev || 0;
                const targetPct = target > 0 ? Math.min((target / max) * 100, 100) : 0;
                const variacion = r.variacion || 0;
                const colorClass = variacion >= 0 ? 'rubro-bar-green' : 'rubro-bar-red';
                
                const div = document.createElement('div');
                div.className = 'rubro-row';
                div.style.cursor = this.drilldownState.level === 1 ? 'pointer' : 'default';
                
                div.innerHTML = `
                    <span class="rubro-nombre">${r.RUBRO}</span>
                    <div class="rubro-bar-wrap">
                        <div class="rubro-bar ${colorClass}" style="width:${pct}%"></div>
                        ${targetPct > 0 ? `<div class="rubro-target-line" style="left:${targetPct}%"></div>` : ''}
                    </div>
                    <span class="rubro-val">${fmt.num(r.unidades)}</span>
                `;
                
                // Tooltip
                div.addEventListener('mouseenter', (e) => {
                    this.showTooltip(e, r, variacion);
                });
                
                div.addEventListener('mouseleave', () => {
                    this.hideTooltip();
                });
                
                // Drilldown click
                if (this.drilldownState.level === 1) {
                    div.addEventListener('click', () => {
                        this.drilldown(r.RUBRO);
                    });
                }
                
                container.appendChild(div);
            });
        },
        
        showTooltip(event, rubro, variacion) {
            this.hideTooltip();
            
            const tooltip = document.createElement('div');
            tooltip.className = 'ranking-tooltip';
            tooltip.innerHTML = `
                <div class="tooltip-rubro">${rubro.RUBRO}</div>
                <div class="tooltip-row">
                    <span>Actual:</span>
                    <strong>${fmt.num(rubro.unidades)}</strong>
                </div>
                <div class="tooltip-row">
                    <span>Año anterior:</span>
                    <strong>${fmt.num(rubro.unidades_prev || 0)}</strong>
                </div>
                <div class="tooltip-row">
                    <span>Variación:</span>
                    <strong class="${variacion >= 0 ? 'pos' : 'neg'}">${fmt.varPct(variacion)}</strong>
                </div>
            `;
            
            document.body.appendChild(tooltip);
            
            const rect = event.target.closest('.rubro-row').getBoundingClientRect();
            tooltip.style.left = `${rect.left + rect.width / 2}px`;
            tooltip.style.top = `${rect.top - 10}px`;
        },
        
        hideTooltip() {
            const existing = document.querySelector('.ranking-tooltip');
            if (existing) existing.remove();
        },
        
        async drilldown(rubroName) {
            this.drilldownState.level = 2;
            this.drilldownState.rubro = rubroName;
            
            // Mostrar breadcrumb
            const breadcrumb = document.querySelector('.ranking-breadcrumb');
            if (breadcrumb) breadcrumb.style.display = 'block';
            
            // Verificar caché
            const cacheKey = `ranking_${rubroName}`;
            if (this.cache[cacheKey]) {
                this.render(this.cache[cacheKey]);
                return;
            }
            
            // Fetch categorías
            try {
                const params = state.currentParams;
                const data = await apiFetch('rubros.php', { ...params, rubro: rubroName });
                this.cache[cacheKey] = data;
                this.render(data);
            } catch (err) {
                console.error('Error drilldown ranking:', err);
                showToast('Error al cargar categorías', 'error');
            }
        }
    };

    function renderRubros(rubros) {
        RankingRubros.render({ rubros });
    }

    /* ── Donut Charts con Chart.js y Drilldown ──── */
    const DonutCharts = {
        charts: {},
        drilldownState: {},
        cache: {},
        colors: ['#2563eb','#16a34a','#dc2626','#f59e0b','#7c3aed',
                 '#0891b2','#ea580c','#84cc16','#db2777','#64748b',
                 '#059669','#b91c1c','#d97706','#4f46e5','#0284c7'],

        create(containerId, title, dataKey, rubrosData) {
            const container = document.getElementById(containerId);
            if (!container) return;

            // Guardar datos originales en caché
            if (!this.cache[containerId]) {
                this.cache[containerId] = {
                    originalTitle: title,
                    originalData: rubrosData,
                    dataKey: dataKey
                };
            }

            this.drilldownState[containerId] = { level: 1, rubro: null };
            
            // Limpiar contenedor
            container.innerHTML = '';
            
            // Breadcrumb (oculto inicialmente)
            const breadcrumb = document.createElement('div');
            breadcrumb.className = 'donut-breadcrumb';
            breadcrumb.style.display = 'none';
            breadcrumb.innerHTML = '<button class="btn-volver">← Volver</button>';
            breadcrumb.querySelector('.btn-volver').addEventListener('click', () => {
                this.drilldownState[containerId].level = 1;
                this.drilldownState[containerId].rubro = null;
                breadcrumb.style.display = 'none';
                
                // Restaurar desde caché sin recargar todo
                const cached = this.cache[containerId];
                const titleEl = container.querySelector('.donut-title');
                if (titleEl) titleEl.textContent = cached.originalTitle;
                this.renderChart(containerId, cached.dataKey, cached.originalData);
            });
            container.appendChild(breadcrumb);
            
            // Título
            const titleEl = document.createElement('div');
            titleEl.className = 'donut-title';
            titleEl.textContent = title;
            container.appendChild(titleEl);
            
            // Canvas para el chart
            const chartWrapper = document.createElement('div');
            chartWrapper.className = 'donut-chart-wrapper';
            const canvas = document.createElement('canvas');
            canvas.id = `${containerId}-canvas`;
            chartWrapper.appendChild(canvas);
            container.appendChild(chartWrapper);
            
            // Leyenda personalizada
            const legend = document.createElement('div');
            legend.className = 'donut-legend';
            legend.id = `${containerId}-legend`;
            container.appendChild(legend);
            
            // Crear chart
            this.renderChart(containerId, dataKey, rubrosData);
        },

        renderChart(containerId, dataKey, rubrosData) {
            const canvas = document.getElementById(`${containerId}-canvas`);
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            
            // Ordenar rubros según el dataKey (unidades o facturacion)
            const sortedRubros = [...rubrosData.rubros].sort((a, b) => b[dataKey] - a[dataKey]);
            
            const labels = sortedRubros.map(r => r.RUBRO);
            const data = sortedRubros.map(r => r[dataKey]);
            const total = rubrosData.totales[dataKey];
            
            // Destruir chart anterior si existe
            if (this.charts[containerId]) {
                this.charts[containerId].destroy();
            }
            
            // Crear nuevo chart
            this.charts[containerId] = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: this.colors,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '55%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: true,
                            backgroundColor: '#ffffff',
                            titleColor: '#1a2340',
                            bodyColor: '#1a2340',
                            borderColor: '#e2e6f0',
                            borderWidth: 1,
                            padding: 12,
                            boxPadding: 6,
                            usePointStyle: true,
                            callbacks: {
                                title: (items) => items[0].label,
                                label: (context) => {
                                    const value = context.parsed;
                                    const pct = total > 0 ? (value / total * 100).toLocaleString('es-AR', {minimumFractionDigits:1, maximumFractionDigits:1}) : '0,0';
                                    if (dataKey === 'facturacion') {
                                        return `Facturación: $${value.toLocaleString('es-AR', {minimumFractionDigits:0, maximumFractionDigits:0})} (${pct}%)`;
                                    } else {
                                        return `Unidades: ${value.toLocaleString('es-AR', {minimumFractionDigits:0, maximumFractionDigits:0})} (${pct}%)`;
                                    }
                                }
                            }
                        },
                        datalabels: {
                            color: '#ffffff',
                            font: { weight: 'bold', size: 11 },
                            formatter: (value, ctx) => {
                                const pct = total > 0 ? (value / total * 100) : 0;
                                return pct >= 5 ? pct.toFixed(1) + '%' : '';
                            }
                        }
                    },
                    onClick: (event, elements) => {
                        if (elements.length > 0 && this.drilldownState[containerId].level === 1) {
                            const index = elements[0].index;
                            const rubroName = labels[index];
                            this.drilldown(containerId, dataKey, rubroName);
                        }
                    },
                    animation: { duration: 400 }
                },
                plugins: [ChartDataLabels]
            });
            
            // Renderizar leyenda personalizada
            this.renderLegend(containerId, labels, data, dataKey, total);
        },

        renderLegend(containerId, labels, data, dataKey, total) {
            const legendEl = document.getElementById(`${containerId}-legend`);
            if (!legendEl) return;
            
            legendEl.innerHTML = '';
            labels.forEach((label, i) => {
                const value = data[i];
                const pct = total > 0 ? (value / total * 100).toLocaleString('es-AR', {minimumFractionDigits:1, maximumFractionDigits:1}) : '0,0';
                const formattedValue = dataKey === 'facturacion' 
                    ? `$${value.toLocaleString('es-AR', {minimumFractionDigits:0, maximumFractionDigits:0})}`
                    : value.toLocaleString('es-AR', {minimumFractionDigits:0, maximumFractionDigits:0});
                
                const item = document.createElement('div');
                item.className = 'donut-legend-item';
                item.innerHTML = `
                    <span class="donut-legend-color" style="background-color: ${this.colors[i % this.colors.length]}"></span>
                    <span class="donut-legend-label">${label}</span>
                    <span class="donut-legend-value">${formattedValue} (${pct}%)</span>
                `;
                legendEl.appendChild(item);
            });
        },

        async drilldown(containerId, dataKey, rubroName) {
            this.drilldownState[containerId].level = 2;
            this.drilldownState[containerId].rubro = rubroName;
            
            // Mostrar breadcrumb
            const breadcrumb = document.querySelector(`#${containerId} .donut-breadcrumb`);
            if (breadcrumb) breadcrumb.style.display = 'block';
            
            // Actualizar título
            const titleEl = document.querySelector(`#${containerId} .donut-title`);
            if (titleEl) {
                titleEl.textContent = dataKey === 'facturacion' 
                    ? `% Participación $ por categoría - ${rubroName}`
                    : `% Participación Unid. por categoría - ${rubroName}`;
            }
            
            // Verificar caché de categorías
            const cacheKey = `${containerId}_${rubroName}`;
            if (this.cache[cacheKey]) {
                // Usar datos del caché
                this.renderChart(containerId, dataKey, this.cache[cacheKey]);
                return;
            }
            
            // Fetch datos de categorías y guardar en caché
            try {
                const data = await apiFetch('rubros.php', { rubro: rubroName });
                this.cache[cacheKey] = data;
                this.renderChart(containerId, dataKey, data);
            } catch (err) {
                console.error('Error drilldown:', err);
                showToast('Error al cargar categorías', 'error');
            }
        }
    };

    /* ── Carga principal ─────────────────────────── */
    async function loadAll() {
        // Cancelar request anterior si sigue en vuelo
        if (_abortController) _abortController.abort();
        _abortController = new AbortController();

        // Invalidar caches de drilldown (datos del filtro anterior ya no son válidos)
        DonutCharts.cache       = {};
        DonutCharts.drilldownState = {};
        RankingRubros.cache     = {};
        RankingRubros.drilldownState = { level: 1, rubro: null };

        state.loading = true;
        document.body.classList.add('is-loading');

        try {
            // Guardar parámetros actuales para drilldown
            state.currentParams = {
                periodo: state.periodo,
                vendedor: state.vendedor,
                rubro: state.rubro
            };
            if (state.periodo === 'custom') {
                state.currentParams.desde = state.desde;
                state.currentParams.hasta = state.hasta;
            }
            
            const [kpisData, vendsData, rubrosData] = await Promise.all([
                apiFetch('kpis.php'),
                apiFetch('vendedores.php'),
                apiFetch('rubros.php'),
            ]);

            const act  = kpisData.actual;
            const prev = kpisData.previo;
            const var_ = kpisData.variacion;
            const bench = kpisData.benchmark;
            const per  = kpisData.periodo;

            /* ── Header ── */
            document.getElementById('periodo-label').textContent =
                `${per.desde_act.split('-').reverse().join('/')} - ${per.hasta_act.split('-').reverse().join('/')} (${per.dias_act}d)`;
            document.getElementById('periodo-previo-label').textContent =
                `vs ${per.desde_prev.split('-').reverse().join('/')} - ${per.hasta_prev.split('-').reverse().join('/')} (${per.dias_prev}d)`;
            document.getElementById('ultima-actualizacion').textContent =
                new Date().toLocaleString('es-AR');

            /* ── KPI Summary ── */
            setEl('fact-act',   fmt.money(act.facturacion));
            setEl('fact-prev',  fmt.money(prev.facturacion));
            setEl('fact-var',   fmt.varPct(var_.facturacion), var_.facturacion);
            setEl('obj-act',    fmt.money(act.objetivo));
            setEl('obj-total',  fmt.money(act.objetivo_total));
            setEl('obj-var',    fmt.varPct(var_.objetivo), var_.objetivo);
            setEl('unid-act',   fmt.num(act.unidades));
            setEl('unid-prev',  fmt.num(prev.unidades));
            setEl('unid-var',   fmt.varPct(var_.unidades), var_.unidades);
            setEl('tickets-act',   fmt.num(act.tickets));
            setEl('tickets-prev',  fmt.num(prev.tickets));
            setEl('tickets-var',   fmt.varPct(var_.tickets), var_.tickets);
            setEl('tickets-bench', fmt.varPct(bench.tickets_var));
            setEl('conv-act',      fmt.pct(act.conversion));
            setEl('conv-prev',     fmt.pct(prev.conversion));
            setEl('conv-var',      fmt.varPct(var_.conversion), var_.conversion);
            setEl('conv-ingresos', fmt.num(act.ingresos));

            /* ── KPI Cards métricas ── */
            const cards = [
                { id:'card-t2do',      val: fmt.pct(act.porc_2do),         var: var_.porc_2do,         bench: fmt.pct(bench.porc_2do),          spark: 'spark-t2do' },
                { id:'card-cambios',   val: fmt.pct(act.porc_cambios),     var: var_.porc_cambios,     bench: fmt.pct(bench.porc_cambios),      spark: 'spark-cambios' },
                { id:'card-tprom',     val: fmt.money(act.ticket_promedio),var: var_.ticket_promedio,  bench: fmt.money(bench.ticket_promedio), spark: 'spark-tprom' },
                { id:'card-t3ro',      val: fmt.pct(act.porc_3ro),         var: var_.porc_3ro,         bench: fmt.pct(bench.porc_3ro),          spark: 'spark-t3ro' },
                { id:'card-incr',      val: fmt.pct(act.porc_incremental), var: var_.porc_incremental, bench: fmt.pct(bench.porc_incremental),  spark: 'spark-incr' },
            ];
            cards.forEach(c => {
                setEl(c.id + '-val',   c.val);
                const varEl = document.getElementById(c.id + '-var');
                if (varEl) {
                    varEl.textContent = fmt.varPct(c.var);
                    varEl.className = 'kpi-var ' + (c.var >= 0 ? 'pos' : 'neg');
                }
                setEl(c.id + '-bench', c.bench);
            });

            /* ── Sparklines ── */
            const serieAct  = kpisData.serie.actual;
            const serieDates = serieAct.map(r => r.fecha);
            const serieFacturacion = serieAct.map(r => r.facturacion);
            const serieUnidades = serieAct.map(r => r.unidades);
            const serieTickets = serieAct.map(r => r.tickets);
            const serieT2do = serieAct.map(r => r.porc_2do);
            const serieTprom = serieAct.map(r => r.ticket_promedio);
            const serieCambios = serieAct.map(r => r.porc_cambios);
            const serieT3ro = serieAct.map(r => r.porc_3ro);
            const serieIncr = serieAct.map(r => r.porc_incremental);
            
            const serieConv    = serieAct.map(r => r.conversion);
            const serieIngresos = serieAct.map(r => r.ingresos);
            
            // Main KPIs sparklines
            drawSparkline('spark-fact', serieFacturacion, serieDates, '#00a878', fmt.money);
            drawSparkline('spark-unid', serieUnidades, serieDates, '#f59e0b', fmt.num);
            drawSparkline('spark-tickets-main', serieTickets, serieDates, '#8b5cf6', fmt.num);
            drawSparkline('spark-conv', serieConv, serieDates, '#ec4899', fmt.pct, [
                { values: serieIngresos, color: '#38bdf8', label: 'Ingresos', formatFn: fmt.num },
                { values: serieTickets,  color: '#8b5cf6', label: 'Tickets',  formatFn: fmt.num, hideStats: true },
            ]);
            
            // Secondary KPIs sparklines
            drawSparkline('spark-t2do',    serieT2do, serieDates, '#60a5fa', fmt.pct);
            drawSparkline('spark-cambios', serieCambios, serieDates, '#fb923c', fmt.pct);
            drawSparkline('spark-tprom',   serieTprom, serieDates, '#a78bfa', fmt.money);
            drawSparkline('spark-t3ro',    serieT3ro, serieDates, '#34d399', fmt.pct);
            drawSparkline('spark-incr',    serieIncr, serieDates, '#f472b6', fmt.pct);

            /* ── Vendedores ── */
            renderVendedores(vendsData.vendedores, act);
            setupTableSorting();

            /* ── Rubros ── */
            renderRubros(rubrosData.rubros);

            /* ── Donut charts ── */
            DonutCharts.create('donut-unidades-wrap', '% Participación Unidades por Rubro', 'unidades', rubrosData);
            DonutCharts.create('donut-facturacion-wrap', '% Participación $ por Rubro', 'facturacion', rubrosData);

        } catch (err) {
            if (err.name === 'AbortError') return; // request supersedido, ignorar
            console.error('Dashboard error:', err);
            showToast('Error al cargar datos: ' + err.message, 'error');
        } finally {
            state.loading = false;
            document.body.classList.remove('is-loading');
        }
    }

    function setEl(id, text, varVal) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = text;
        if (varVal !== undefined) {
            el.className = el.className.replace(/\b(pos|neg)\b/g, '');
            el.classList.add(varVal >= 0 ? 'pos' : 'neg');
        }
    }

    function showToast(msg, type = 'info') {
        const t = document.createElement('div');
        t.className = `toast toast-${type}`;
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 4000);
    }

    /* ── Init ────────────────────────────────────── */
    async function init() {
        // Selector de período — solo muestra/oculta custom-dates, no dispara carga
        const selPeriodo = document.getElementById('sel-periodo');
        if (selPeriodo) {
            selPeriodo.addEventListener('change', () => {
                state.periodo = selPeriodo.value;
                const customRow = document.getElementById('custom-dates');
                if (customRow) customRow.style.display = state.periodo === 'custom' ? 'flex' : 'none';
            });
        }

        // Fechas custom — solo actualiza estado, no dispara carga
        ['input-desde','input-hasta'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', () => {
                state.desde = document.getElementById('input-desde')?.value || '';
                state.hasta = document.getElementById('input-hasta')?.value || '';
            });
        });

        // Modo de comparación
        document.querySelectorAll('input[name="comp-mode"]').forEach(radio => {
            radio.addEventListener('change', () => {
                state.compMode = radio.value;
                const compDates = document.getElementById('custom-comp-dates');
                if (compDates) compDates.style.display = state.compMode === 'custom' ? 'flex' : 'none';
            });
        });

        // Fechas de comparación personalizada — solo actualiza estado
        ['input-comp-desde','input-comp-hasta'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', () => {
                state.compDesde = document.getElementById('input-comp-desde')?.value || '';
                state.compHasta = document.getElementById('input-comp-hasta')?.value || '';
            });
        });

        // Cargar filtros de vendedor y rubro — solo actualiza estado
        try {
            const filtrosData = await apiFetch('filtros.php');

            const selVendedor = document.getElementById('sel-vendedor');
            if (selVendedor && filtrosData.vendedores) {
                filtrosData.vendedores.forEach(v => {
                    const opt = document.createElement('option');
                    opt.value = v.DESC_VENDEDOR;
                    opt.textContent = v.DESC_VENDEDOR;
                    selVendedor.appendChild(opt);
                });
                selVendedor.addEventListener('change', () => {
                    state.vendedor = selVendedor.value;
                });
            }

            const selRubro = document.getElementById('sel-rubro');
            if (selRubro && filtrosData.rubros) {
                filtrosData.rubros.forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.RUBRO;
                    opt.textContent = r.RUBRO;
                    selRubro.appendChild(opt);
                });
                selRubro.addEventListener('change', () => {
                    state.rubro = selRubro.value;
                });
            }
        } catch (err) {
            console.error('Error cargando filtros:', err);
        }

        // Botón Aplicar — dispara la carga
        const btnAplicar = document.getElementById('btn-aplicar');
        if (btnAplicar) {
            btnAplicar.addEventListener('click', () => loadAll());
        }

        // Inicializar estado desde valores actuales del DOM (para modo custom)
        state.desde     = document.getElementById('input-desde')?.value     || '';
        state.hasta     = document.getElementById('input-hasta')?.value     || '';
        state.compDesde = document.getElementById('input-comp-desde')?.value || '';
        state.compHasta = document.getElementById('input-comp-hasta')?.value || '';

        // Carga inicial
        loadAll();
    }

    return { init, loadAll, state, fmt };
})();

document.addEventListener('DOMContentLoaded', () => Dashboard.init());
