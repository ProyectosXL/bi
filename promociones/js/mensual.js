/**
 * /bi/promociones/js/mensual.js
 * Tabla mensual anual + gráfico de evolución % Costo / Fact. total
 * Expone: PromoMensual.load(), PromoMensual.onMonedaChange()
 */
const PromoMensual = (() => {

    const $ = id => document.getElementById(id);

    const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

    /* Estado */
    let _lastData      = null;
    let _expandedSet   = new Set();
    let _selectedYears = new Set();
    let _evChart       = null;
    let _evChartModal  = null;

    const EVOLUCION_COLORS = [
        '#2563eb','#16a34a','#dc2626','#d97706','#7c3aed',
        '#0891b2','#db2777','#65a30d','#9f1239','#0d9488',
    ];

    /* ── helpers de formato delegados al módulo raíz ── */
    const fmt = () => Promociones.fmt;

    function moneyK(n) {
        const f = fmt();
        return f ? f.moneyK(n) : (n ?? '—');
    }
    function pct(n, d = 2) {
        if (n == null) return '—';
        const f = fmt();
        return f ? f.pct(n, d) : (n * 100).toFixed(d) + ' %';
    }
    function varPp(n) {
        if (n == null) return '—';
        const f = fmt();
        return f ? f.varPp(n) : ((n >= 0 ? '+' : '') + (n * 100).toFixed(2) + ' pp');
    }
    function varPct(n) {
        if (n == null) return '—';
        const f = fmt();
        return f ? f.varPct(n) : ((n >= 0 ? '+' : '') + (n * 100).toFixed(1) + ' %');
    }

    /* ── Carga de datos ── */
    async function load() {
        const wrap = $('mensual-tabla-wrap');
        // Solo mostrar cargando en la tabla — NO tocar el chartWrap para no destruir el canvas
        if (wrap) wrap.innerHTML = '<div class="promo-loading">Cargando datos mensuales…</div>';

        try {
            const data = await Promociones.apiFetch('mensual.php');

            if (!data.ok) throw new Error(data.error ?? 'Error en mensual.php');
            _lastData = data;
            _expandedSet.clear();

            // Auto-expandir año en curso
            const anioActual = new Date().getFullYear();
            if ((data.anios ?? []).includes(anioActual)) {
                _expandedSet.add(anioActual);
            }

            // Selector: últimos 3 años seleccionados por defecto
            // data.anios viene ordenado descendente (rsort en PHP), por eso slice(0,3)
            const todosAnios = data.anios ?? [];
            _selectedYears = new Set(todosAnios.slice(0, 3));

            renderTabla(data);
            buildYearFilter('mensual-year-filter', data);
            renderChart(data, 'mensual-chart-canvas', false);

        } catch (e) {
            console.error('[PromoMensual] load error:', e);
            if (wrap) wrap.innerHTML = `<div class="promo-loading" style="color:var(--red)">Error: ${e.message}</div>`;
        }
    }

    /* Reactualizar moneda sin nueva carga */
    function onMonedaChange() {
        if (!_lastData) return;
        renderTabla(_lastData);
        renderChart(_lastData, 'mensual-chart-canvas', false);
        // Si el modal está abierto también
        const modalOverlay = $('modal-evolucion');
        if (modalOverlay && !modalOverlay.hidden) {
            renderChart(_lastData, 'mensual-chart-modal-canvas', true);
        }
    }

    /* ── Render tabla ── */
    function renderTabla(data) {
        const wrap = $('mensual-tabla-wrap');
        if (!wrap) return;

        const anios  = data.anios  ?? [];
        const datos  = data.datos  ?? {};
        const totales = data.totales ?? {};   // totales[anio] = {fac_total, fac_cpromo, ...}

        if (!anios.length) {
            wrap.innerHTML = '<div class="promo-loading">Sin datos mensuales</div>';
            return;
        }

        const cols = [
            { key: 'fac_total',         label: 'Fact. Total',       fmt: moneyK },
            { key: 'fac_cpromo',        label: 'Fact. C/Promo',     fmt: moneyK },
            { key: 'tickets_cpromo',    label: 'Tickets C/Promo',   fmt: n => Promociones.fmt?.num(n) ?? n },
            { key: 'costo_total',       label: 'Costo Total',       fmt: moneyK },
            { key: 'pct_costo_total',   label: '% Costo/Fact',      fmt: n => pct(n, 2) },
            { key: 'pct_promo_fac',     label: '% Promo/FAC',       fmt: n => pct(n, 2) },
        ];

        let html = `<table class="promo-tabla-mensual">
            <thead><tr>
                <th>Año / Mes</th>
                ${cols.map(c => `<th>${c.label}</th>`).join('')}
                <th title="Variación % Costo/Fact vs año anterior">vs Año Ant.</th>
            </tr></thead>
            <tbody>`;

        // Calcular índice de año anterior para variación
        const aniosIdx = {};
        anios.forEach((a, i) => aniosIdx[a] = i);

        anios.forEach(anio => {
            const expanded = _expandedSet.has(anio);
            const tot      = totales[anio] ?? {};
            const prevAnio = anio - 1;
            const prevTot  = totales[prevAnio];
            const varAnio  = (prevTot && prevTot.pct_costo_total != null && tot.pct_costo_total != null)
                ? (tot.pct_costo_total - prevTot.pct_costo_total)
                : null;

            const varCls = varAnio == null ? '' : (varAnio <= 0 ? 'var-pos' : 'var-neg');
            const varStr = varAnio == null ? '—' : varPp(varAnio);

            html += `<tr class="row-anio" data-anio="${anio}">
                <td>
                    <span class="expand-toggle">${expanded ? '▼' : '▶'}</span>
                    <strong>${anio}</strong>
                </td>
                ${cols.map(c => `<td>${c.fmt(tot[c.key] ?? null)}</td>`).join('')}
                <td class="${varCls}">${varStr}</td>
            </tr>`;

            if (expanded) {
                const mesDatos = datos[anio] ?? {};
                for (let m = 1; m <= 12; m++) {
                    const md = mesDatos[m] ?? {};
                    const prevMes = datos[prevAnio]?.[m] ?? {};
                    const varMes = (prevMes.pct_costo_total != null && md.pct_costo_total != null)
                        ? (md.pct_costo_total - prevMes.pct_costo_total)
                        : null;
                    const vmCls = varMes == null ? '' : (varMes <= 0 ? 'var-pos' : 'var-neg');
                    const vmStr = varMes == null ? '—' : varPp(varMes);
                    const mesLabel = MESES[m - 1];
                    html += `<tr class="row-mes" data-anio="${anio}" data-mes="${m}">
                        <td style="padding-left:32px">${mesLabel}</td>
                        ${cols.map(c => `<td>${md[c.key] != null ? c.fmt(md[c.key]) : '—'}</td>`).join('')}
                        <td class="${vmCls}">${vmStr}</td>
                    </tr>`;
                }
            }
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;

        // Bind click en filas año
        wrap.querySelectorAll('tr.row-anio').forEach(tr => {
            tr.style.cursor = 'pointer';
            tr.addEventListener('click', () => {
                const a = parseInt(tr.dataset.anio);
                if (_expandedSet.has(a)) _expandedSet.delete(a);
                else _expandedSet.add(a);
                renderTabla(_lastData);
            });
        });

        // Botón export
        const exportBtn = $('btn-export-mensual');
        if (exportBtn) {
            exportBtn.onclick = () => exportMensual(data);
        }
    }

    /* ── Export Excel tabla mensual ── */
    function exportMensual(data) {
        const anios  = data.anios  ?? [];
        const datos  = data.datos  ?? {};
        const totales = data.totales ?? {};

        const headers = ['Año', 'Mes', 'Fact. Total', 'Fact. C/Promo', 'Tickets C/Promo', 'Costo Total', '% Costo/Fact', '% Promo/FAC'];
        const rows = [];

        anios.forEach(anio => {
            const tot = totales[anio] ?? {};
            rows.push([anio, 'Total', tot.fac_total ?? 0, tot.fac_cpromo ?? 0, tot.tickets_cpromo ?? 0, tot.costo_total ?? 0, (tot.pct_costo_total ?? 0) * 100, (tot.pct_promo_fac ?? 0) * 100]);
            for (let m = 1; m <= 12; m++) {
                const md = datos[anio]?.[m] ?? {};
                rows.push([anio, MESES[m - 1], md.fac_total ?? 0, md.fac_cpromo ?? 0, md.tickets_cpromo ?? 0, md.costo_total ?? 0, (md.pct_costo_total ?? 0) * 100, (md.pct_promo_fac ?? 0) * 100]);
            }
        });

        if (typeof ExcelExporter !== 'undefined') {
            ExcelExporter.export({
                title   : 'Evolución Mensual - Promociones',
                headers,
                rows,
                filename: 'promociones_mensual',
                colFormats: [null, null, 'money', 'money', 'number', 'money', 'pct1', 'pct1'],
            });
        } else {
            console.warn('[PromoMensual] ExcelExporter no disponible');
        }
    }

    /* ── Selector de años para el gráfico ── */
    function buildYearFilter(containerId, data) {
        const container = $(containerId);
        if (!container) return;
        const anios = data.anios ?? [];
        let html = '<span class="year-filter-label">Años:</span>';
        anios.forEach(anio => {
            const checked = _selectedYears.has(anio) ? ' checked' : '';
            html += `<label class="year-filter-item">
                <input type="checkbox" data-anio="${anio}"${checked}> ${anio}
            </label>`;
        });
        container.innerHTML = html;
        container.querySelectorAll('input[type=checkbox]').forEach(cb => {
            cb.addEventListener('change', () => {
                const anio = parseInt(cb.dataset.anio);
                if (cb.checked) _selectedYears.add(anio);
                else            _selectedYears.delete(anio);
                // Sincronizar el otro filtro (modal ↔ principal)
                ['mensual-year-filter', 'mensual-year-filter-modal'].forEach(id => {
                    if (id !== containerId) buildYearFilter(id, data);
                });
                renderChart(_lastData, 'mensual-chart-canvas', false);
                const modalOverlay = $('modal-evolucion');
                if (modalOverlay && !modalOverlay.hidden) {
                    renderChart(_lastData, 'mensual-chart-modal-canvas', true);
                }
            });
        });
    }

    /* ── Render gráfico evolución % Costo / Fact. total ── */
    function renderChart(data, canvasId, isModal) {
        let canvas = $(canvasId);
        if (!canvas) {
            // El canvas puede haber sido destruido; lo recrea dentro de su wrap
            const wrapId = isModal ? null : 'mensual-chart-wrap';
            const wrap   = wrapId ? $(wrapId) : null;
            if (!wrap) return;
            wrap.innerHTML = '';
            canvas = document.createElement('canvas');
            canvas.id = canvasId;
            wrap.appendChild(canvas);
        }

        const chartRef = isModal ? { ref: _evChartModal } : { ref: _evChart };

        if (chartRef.ref) {
            chartRef.ref.destroy();
            chartRef.ref = null;
        }
        if (isModal) _evChartModal = null;
        else         _evChart = null;

        const aniosTodos = data.anios ?? [];
        const datos      = data.datos  ?? {};

        if (!aniosTodos.length) return;

        // Filtrar por años seleccionados (mantener orden y colores originales)
        const anios = aniosTodos.filter(a => _selectedYears.size === 0 || _selectedYears.has(a));

        if (!anios.length) return;

        const datasets = anios.map(anio => {
            const idx   = aniosTodos.indexOf(anio);   // color consistente independiente del filtro
            const color = EVOLUCION_COLORS[idx % EVOLUCION_COLORS.length];
            const meses = datos[anio] ?? {};
            return {
                label: String(anio),
                data: MESES.map((_, i) => {
                    const m  = i + 1;
                    const md = meses[m];
                    if (!md || md.pct_costo_total == null) return null;
                    return +(md.pct_costo_total * 100).toFixed(4);
                }),
                borderColor       : color,
                backgroundColor   : color + '22',
                pointRadius       : 3,
                pointHoverRadius  : 5,
                borderWidth       : 2,
                tension           : 0.25,
                spanGaps          : false,
            };
        });

        const chart = new Chart(canvas, {
            type: 'line',
            data: { labels: MESES, datasets },
            options: {
                responsive         : true,
                maintainAspectRatio: false,
                interaction        : { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position : 'bottom',
                        labels   : { font: { size: 11 }, boxWidth: 14, padding: 10 },
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                const anio  = ctx.dataset.label;
                                const mesIdx = ctx.dataIndex;
                                const m      = mesIdx + 1;
                                const md     = datos[parseInt(anio)]?.[m] ?? {};
                                const pctCosto = md.pct_costo_total != null ? pct(md.pct_costo_total, 2) : '—';
                                const pctPromo = md.pct_promo_fac   != null ? pct(md.pct_promo_fac,   2) : '—';
                                const pctBanc  = md.pct_costo_banc_fac  != null ? pct(md.pct_costo_banc_fac,  2) : '—';
                                const pctVtas  = md.pct_costo_ventas_fac != null ? pct(md.pct_costo_ventas_fac, 2) : '—';
                                return [
                                    `${anio}: % Costo/FAC ${pctCosto}`,
                                    `  Promo/FAC ${pctPromo} | Banc/FAC ${pctBanc} | Vtas/FAC ${pctVtas}`,
                                ];
                            }
                        }
                    },
                    datalabels: { display: false },
                },
                scales: {
                    x: {
                        grid  : { color: 'rgba(0,0,0,.05)' },
                        ticks : { font: { size: 11 } },
                    },
                    y: {
                        grid  : { color: 'rgba(0,0,0,.05)' },
                        ticks : {
                            font    : { size: 11 },
                            callback: v => v.toFixed(2) + ' %',
                        }
                    }
                }
            },
            plugins: [ChartDataLabels],
        });

        if (isModal) _evChartModal = chart;
        else         _evChart      = chart;
    }

    /* ── Modal expansión ── */
    function initModal() {
        const expandBtn    = $('btn-expand-evolucion');
        const modalOverlay = $('modal-evolucion');
        const closeBtn     = $('modal-evolucion-close');

        expandBtn?.addEventListener('click', () => {
            if (!_lastData) return;
            modalOverlay.hidden = false;
            requestAnimationFrame(() => {
                buildYearFilter('mensual-year-filter-modal', _lastData);
                renderChart(_lastData, 'mensual-chart-modal-canvas', true);
            });
        });
        closeBtn?.addEventListener('click', () => {
            modalOverlay.hidden = true;
            if (_evChartModal) { _evChartModal.destroy(); _evChartModal = null; }
        });
        modalOverlay?.addEventListener('click', e => {
            if (e.target === modalOverlay) {
                modalOverlay.hidden = true;
                if (_evChartModal) { _evChartModal.destroy(); _evChartModal = null; }
            }
        });
    }

    /* Init hooks (llamar desde index.php DOMContentLoaded) */
    function init() {
        initModal();
    }

    return { load, onMonedaChange, init };
})();
