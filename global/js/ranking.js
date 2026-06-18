/**
 * /bi/global/js/ranking.js
 * Módulo de Ranking (scoring ponderado de sucursales) para el dashboard global.
 * Expone: Ranking.loadAll()
 */

const Ranking = (() => {

    const $ = id => document.getElementById(id);

    /* ── Formato ──────────────────────────────────────────── */
    const fmt = (() => {
        const f = n => (n === null || n === undefined) ? '—' : n;
        const conv = n => typeof Dashboard !== 'undefined' ? Dashboard.convertir(n) : n;
        const pfx  = ()  => typeof Dashboard !== 'undefined' ? Dashboard.moneyPrefix() : '$\u00A0';
        return {
            money  : (n, d = 0) => f(n) === '—' ? '—' : pfx() + conv(n).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }),
            moneyK : n => {
                if (n === null || n === undefined) return '—';
                const v = conv(n);
                const p = pfx();
                if (Math.abs(v) >= 1_000_000) return p + (v / 1_000_000).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'M';
                if (Math.abs(v) >= 1_000)     return p + (v / 1_000).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + 'K';
                return p + v.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
            },
            pct    : (n, d = 1) => (n === null || n === undefined) ? '—' : (n * 100).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }) + '\u00A0%',
            varPct : (n, d = 1) => { if (n === null || n === undefined) return '—'; const s = n >= 0 ? '+' : ''; return s + (n * 100).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }) + '\u00A0%'; },
            pp     : (n, d = 1) => { if (n === null || n === undefined) return '—'; const s = n >= 0 ? '+' : ''; return s + (n * 100).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }) + ' pp'; },
            num    : (n, d = 0) => (n === null || n === undefined) ? '—' : Number(n).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }),
        };
    })();

    /* ── Leer parámetros del DOM ──────────────────────────── */
    function buildQS() {
        const p = {
            origen     : (document.querySelector('.origen-btn.active')?.dataset.origen ?? 'argentina'),
            periodo    : ($('sel-periodo')?.value    ?? 'mes_actual'),
            grupo      : ($('sel-grupo')?.value      ?? ''),
            tipo_tienda: ($('sel-tipo-tienda')?.value ?? ''),
            tipo_local : ($('sel-tipo-local')?.value ?? ''),
            zona       : ($('sel-zona')?.value       ?? ''),
            grupo_empresario: ($('sel-grupo-empresario')?.value ?? ''),
        };
        if (p.periodo === 'custom') {
            p.desde     = $('input-desde')?.value     ?? '';
            p.hasta     = $('input-hasta')?.value     ?? '';
            p.comp_mode = document.querySelector('input[name="comp-mode"]:checked')?.value ?? 'year_ago';
            if (p.comp_mode === 'custom') {
                p.desde_comp = $('input-comp-desde')?.value ?? '';
                p.hasta_comp = $('input-comp-hasta')?.value ?? '';
            }
        }
        ['grupo', 'tipo_tienda', 'tipo_local', 'zona', 'grupo_empresario'].forEach(k => { if (!p[k]) delete p[k]; });
        return new URLSearchParams(p).toString();
    }

    /* ── Estado de ordenamiento ───────────────────────────── */
    let _sortCol = 'score';
    let _sortDir = 'desc';
    let _lastData = [];

    /* ── Exportar ranking a Excel ─────────────────────────── */
    function exportarRanking() {
        if (!_lastData?.length || typeof ExcelExporter === 'undefined') return;
        let rows = _lastData;
        if (typeof Dashboard !== 'undefined' && Dashboard.isSoloActivas?.()) {
            const ids = Dashboard.getSucursalesActivasIds?.();
            if (ids?.size) rows = rows.filter(r => ids.has(+r.nro_sucurs));
        }
        const _conv = n => typeof Dashboard !== 'undefined' && n != null ? Dashboard.convertir(n) : n;
        ExcelExporter.export({
            title     : 'Ranking de Sucursales',
            headers   : ['#', 'Sucursal', 'Score', 'Ventas', 'Cumpl. Obj.',
                         'Δ Var. Ventas', 'T. Promedio', 'Δ Var. Tick.', 'Δ Var. Unid.',
                         '% 2do', '% 3er', '% Increm.'],
            rows      : rows.map(r => [
                r.rank,
                r.nombre ?? ('Suc. ' + r.nro_sucurs),
                r.score  ?? null,
                r.facturacion     != null ? _conv(r.facturacion)     : null,
                r.detalle?.cumplimiento?.valor ?? null,
                r.delta_var_fact     ?? null,
                r.ticket_promedio != null ? _conv(r.ticket_promedio) : null,
                r.delta_var_tickets  ?? null,
                r.delta_var_unidades ?? null,
                r.porc_2do           ?? null,
                r.porc_3ro           ?? null,
                r.porc_incremental   ?? null,
            ]),
            colFormats: ['num', 'text', 'num1', 'money', 'pct', 'pct', 'money', 'pct', 'pct', 'pct', 'pct', 'pct'],
            filename  : 'ranking_sucursales',
        });
    }

    /* ── KPI meta: etiqueta, formato y tipo de normalización ─ */
    const KPI_META = {
        cumplimiento     : { label: 'Cumpl. Objetivo',  fmt: v => fmt.pct(v),    type: 'normal' },
        delta_var_fact   : {
            label: 'Delta Var. Ventas', fmt: v => fmt.pp(v), type: 'growth',
            actualKey: 'var_fact_actual',    anteriorKey: 'var_fact_anterior',
            actualFmt: v => fmt.varPct(v),   anteriorFmt: v => fmt.varPct(v),
        },
        ticket_promedio  : { label: 'Ticket Promedio',  fmt: v => fmt.money(v),  type: 'normal' },
        delta_var_tickets: {
            label: 'Delta Var. Tickets', fmt: v => fmt.pp(v), type: 'growth',
            actualKey: 'var_tickets_actual',    anteriorKey: 'var_tickets_anterior',
            actualFmt: v => fmt.varPct(v),      anteriorFmt: v => fmt.varPct(v),
        },
        delta_var_unidades: {
            label: 'Delta Var. Unidades', fmt: v => fmt.pp(v), type: 'growth',
            actualKey: 'var_unidades_actual',    anteriorKey: 'var_unidades_anterior',
            actualFmt: v => fmt.varPct(v),       anteriorFmt: v => fmt.varPct(v),
        },
        porc_2do         : { label: '% 2do Prod.',      fmt: v => fmt.pct(v),    type: 'normal' },
        porc_3ro         : { label: '% 3er Prod.',      fmt: v => fmt.pct(v),    type: 'normal' },
        porc_incremental : { label: '% Incremental',    fmt: v => fmt.pct(v),    type: 'index'  },
    };

    /* ── Score chip ───────────────────────────────────────── */
    function scoreClass(s) {
        if (s >= 110) return 'score-high';
        if (s >= 95)  return 'score-mid';
        return 'score-low';
    }

    function rankBadgeHTML(rank) {
        const cls = rank <= 3 ? ` rank-${rank}` : '';
        return `<span class="rank-badge${cls}">${rank}</span>`;
    }

    /* ── Mini bar ─────────────────────────────────────────── */
    function miniBarHTML(value, max, color = '#3b82f6') {
        const pct = max > 0 ? Math.min(100, (value / max) * 100) : 0;
        return `<div class="mini-bar-wrap">
            <div class="mini-bar"><div class="mini-bar-fill" style="width:${pct.toFixed(1)}%;background:${color}"></div></div>
        </div>`;
    }

    /* ── Render tabla ─────────────────────────────────────── */
    function renderTable(data) {
        const tbody = document.querySelector('#ranking-table tbody');
        if (!tbody) return;

        // Filtro "solo activas" (client-side)
        if (typeof Dashboard !== 'undefined' && Dashboard.isSoloActivas?.()) {
            const ids = Dashboard.getSucursalesActivasIds?.();
            if (ids?.size) data = data.filter(r => ids.has(+r.nro_sucurs));
        }

        const maxScore = data.length > 0 ? data[0].score : 100;

        // Ordenar
        const sorted = [...data].sort((a, b) => {
            const va = a[_sortCol] ?? 0;
            const vb = b[_sortCol] ?? 0;
            if (typeof va === 'string') {
                const cmp = va.localeCompare(vb, 'es');
                return _sortDir === 'asc' ? cmp : -cmp;
            }
            return _sortDir === 'asc' ? va - vb : vb - va;
        });

        tbody.innerHTML = sorted.map(s => {
            const sc       = scoreClass(s.score);
            const cumpl    = (s.detalle?.cumplimiento?.valor   ?? 0);
            const deltaF   = (s.delta_var_fact     ?? 0);
            const deltaTk  = (s.delta_var_tickets  ?? 0);
            const deltaUn  = (s.delta_var_unidades ?? 0);
            return `<tr data-nro="${s.nro_sucurs}">
                <td>${rankBadgeHTML(s.rank)}</td>
                <td class="td-nombre">${s.nombre ?? ('Suc. ' + s.nro_sucurs)}</td>
                <td>
                    <div class="score-gauge-wrap">
                        <span class="score-pill ${sc}">${s.score.toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}</span>
                        ${miniBarHTML(s.score, Math.max(maxScore, 110), sc === 'score-high' ? '#16a34a' : sc === 'score-mid' ? '#b45309' : '#dc2626')}
                    </div>
                </td>
                <td>${fmt.money(s.facturacion)}</td>
                <td class="${cumpl >= 1 ? 'text-green' : cumpl >= 0.9 ? 'text-yellow' : 'text-red'}">${fmt.pct(cumpl)}</td>
                <td class="${deltaF  >= 0 ? 'text-green' : 'text-red'}">${fmt.pp(deltaF)}</td>
                <td>${fmt.money(s.ticket_promedio)}</td>
                <td class="${deltaTk >= 0 ? 'text-green' : 'text-red'}">${fmt.pp(deltaTk)}</td>
                <td class="${deltaUn >= 0 ? 'text-green' : 'text-red'}">${fmt.pp(deltaUn)}</td>
                <td>${fmt.pct(s.porc_2do)}</td>
                <td>${fmt.pct(s.porc_3ro)}</td>
                <td>${fmt.pct(s.porc_incremental)}</td>
            </tr>`;
        }).join('');

        // Click para ver detalle
        tbody.querySelectorAll('tr').forEach(tr => {
            tr.addEventListener('click', () => {
                const nro  = parseInt(tr.dataset.nro);
                const suc  = data.find(s => s.nro_sucurs === nro);
                if (suc) openModal(suc);
            });
        });

        // Headers: actualizar indicadores de orden
        document.querySelectorAll('#ranking-table thead th[data-col]').forEach(th => {
            th.classList.remove('sort-asc', 'sort-desc');
            if (th.dataset.col === _sortCol) {
                th.classList.add(_sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
            }
        });
    }

    /* ── Modal de detalle ─────────────────────────────────── */
    let _contribChart = null;

    function openModal(suc) {
        const overlay = document.getElementById('ranking-modal-overlay');
        if (!overlay) return;

        const sc = scoreClass(suc.score);
        const scoreColor = sc === 'score-high' ? '#22c55e' : sc === 'score-mid' ? '#eab308' : '#ef4444';

        overlay.querySelector('.ranking-modal-title').textContent   = suc.nombre ?? ('Suc. ' + suc.nro_sucurs);
        overlay.querySelector('.ranking-modal-subtitle').textContent = `Posición #${suc.rank} en el ranking`;
        overlay.querySelector('.modal-score-number').textContent    = suc.score.toLocaleString('es-AR', { minimumFractionDigits: 1 });
        overlay.querySelector('.modal-score-number').style.color    = scoreColor;
        overlay.querySelector('.modal-score-rank .rank-num').textContent = `#${suc.rank}`;

        // Contribution chart
        if (_contribChart) { _contribChart.destroy(); _contribChart = null; }
        const canvas = document.getElementById('contrib-chart');
        if (canvas && suc.detalle) {
            const kpis   = Object.keys(suc.detalle);
            const labels = kpis.map(k => KPI_META[k]?.label ?? k);
            const contribs = kpis.map(k => suc.detalle[k].contrib);
            const colors   = kpis.map(k => {
                const n = suc.detalle[k].norm;
                if (n >= 1.1) return 'rgba(22,163,74,.75)';
                if (n >= 0.9) return 'rgba(180,83,9,.55)';
                return 'rgba(220,38,38,.70)';
            });

            _contribChart = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        data           : contribs,
                        backgroundColor: colors,
                        borderRadius   : 4,
                        borderSkipped  : false,
                    }],
                },
                options: {
                    indexAxis  : 'y',
                    responsive : true,
                    animation  : { duration: 350 },
                    plugins: {
                        legend    : { display: false },
                        datalabels: { display: false },
                        tooltip   : {
                            backgroundColor: '#1a2340',
                            titleColor     : 'rgba(255,255,255,.7)',
                            bodyColor      : '#ffffff',
                            padding        : 10,
                            cornerRadius   : 6,
                            displayColors  : false,
                            callbacks: {
                                label: ctx => {
                                    const k    = kpis[ctx.dataIndex];
                                    const d    = suc.detalle[k];
                                    const meta = KPI_META[k];
                                    if (meta?.type === 'growth') {
                                        const vAct = suc[meta.actualKey];
                                        const vAnt = suc[meta.anteriorKey];
                                        return [
                                            `Contribución: ${ctx.parsed.x.toLocaleString('es-AR', { minimumFractionDigits: 1 })} pts`,
                                            `Var. actual: ${meta.actualFmt(vAct)}`,
                                            `Var. anterior: ${meta.anteriorFmt(vAnt)}`,
                                            `Delta: ${meta.fmt(d.valor)}`,
                                            `Norm: ×${d.norm.toFixed(2)} | Peso: ${(d.peso * 100).toFixed(0)}%`,
                                        ];
                                    }
                                    return [
                                        `Contribución: ${ctx.parsed.x.toLocaleString('es-AR', { minimumFractionDigits: 1 })} pts`,
                                        `Valor: ${meta?.fmt(d.valor) ?? d.valor}`,
                                        `Promedio: ${meta?.fmt(d.promedio) ?? d.promedio}`,
                                        `Norm: ×${d.norm.toFixed(2)} | Peso: ${(d.peso * 100).toFixed(0)}%`,
                                    ];
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid : { color: 'rgba(255,255,255,.06)' },
                            ticks: { color: '#7b8fc0', font: { size: 11 } },
                            title: { display: true, text: 'Puntos de contribución', color: '#7b8fc0', font: { size: 11 } },
                        },
                        y: { ticks: { color: '#c9d4f0', font: { size: 11 } }, grid: { display: false } },
                    },
                },
            });
        }

        // KPI detail cards
        const grid = overlay.querySelector('.kpi-detail-grid');
        if (grid && suc.detalle) {
            const maxContrib = Math.max(...Object.values(suc.detalle).map(d => d.contrib));
            grid.innerHTML = Object.entries(suc.detalle).map(([k, d]) => {
                const meta = KPI_META[k];
                const norm = d.norm;
                const normColor = norm >= 1.1 ? '#16a34a' : norm >= 0.9 ? '#b45309' : '#dc2626';
                const barPct = maxContrib > 0 ? Math.min(100, (d.contrib / maxContrib) * 100) : 0;
                const growthRows = meta?.type === 'growth'
                    ? `<div class="kpi-detail-row"><span>Var. actual</span><strong>${meta.actualFmt(suc[meta.actualKey])}</strong></div>
                    <div class="kpi-detail-row"><span>Var. anterior</span><strong>${meta.anteriorFmt(suc[meta.anteriorKey])}</strong></div>
                    <div class="kpi-detail-row"><span>Delta</span><strong>${meta.fmt(d.valor)}</strong></div>`
                    : `<div class="kpi-detail-row"><span>Valor</span><strong>${meta?.fmt(d.valor) ?? d.valor}</strong></div>
                    <div class="kpi-detail-row"><span>Promedio</span><strong>${meta?.fmt(d.promedio) ?? d.promedio}</strong></div>`;
                return `<div class="kpi-detail-card">
                    <div class="kpi-detail-name">${meta?.label ?? k}</div>
                    ${growthRows}
                    <div class="kpi-detail-row"><span>Norm.</span><strong style="color:${normColor}">×${d.norm.toFixed(2)}</strong></div>
                    <div class="kpi-detail-row"><span>Peso</span><strong>${(d.peso * 100).toFixed(0)}%</strong></div>
                    <div class="kpi-detail-contrib">
                        <div class="contrib-bar"><div class="contrib-bar-fill" style="width:${barPct.toFixed(1)}%;background:${normColor}"></div></div>
                        <span class="contrib-val" style="color:${normColor}">${d.contrib.toFixed(1)}</span>
                    </div>
                </div>`;
            }).join('');
        }

        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        const overlay = document.getElementById('ranking-modal-overlay');
        if (overlay) overlay.style.display = 'none';
        document.body.style.overflow = '';
    }

    /* ── Init event listeners (una sola vez) ──────────────── */
    let _listenersAttached = false;

    function attachListeners() {
        if (_listenersAttached) return;
        _listenersAttached = true;

        // Header sort
        document.querySelectorAll('#ranking-table thead th[data-col]').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.col;
                if (_sortCol === col) {
                    _sortDir = _sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    _sortCol = col;
                    _sortDir = 'desc';
                }
                renderTable(_lastData);
            });
        });

        // Close modal
        const closeBtn = document.getElementById('ranking-modal-close');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);

        const overlay = document.getElementById('ranking-modal-overlay');
        if (overlay) {
            overlay.addEventListener('click', e => {
                if (e.target === overlay) closeModal();
            });
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeModal();
        });
    }

    /* ── loadAll ──────────────────────────────────────────── */
    async function loadAll() {
        const tbody = document.querySelector('#ranking-table tbody');
        if (!tbody) return;

        tbody.innerHTML = `<tr><td colspan="12">
            <div class="ranking-loading"><div class="spinner"></div><br>Calculando ranking…</div>
        </td></tr>`;
        document.body.classList.add('is-loading');
        Spinner.show('Calculando ranking...');

        attachListeners();

        try {
            const res  = await fetch(`/bi/global/api/score.php?${buildQS()}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error ?? 'Error en score');

            _lastData = data.scores ?? [];

            // Botón de exportación (una sola vez)
            if (typeof ExcelExporter !== 'undefined') {
                const headerEl = document.querySelector('.ranking-toolbar');
                ExcelExporter.addExportButton(headerEl, exportarRanking);
            }

            if (_lastData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="12">
                    <div class="ranking-empty">Sin datos para el período seleccionado.</div>
                </td></tr>`;
                return;
            }

            renderTable(_lastData);

        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="12" style="text-align:center;padding:24px;color:#ef4444">
                Error: ${err.message}
            </td></tr>`;
        } finally {
            document.body.classList.remove('is-loading');
            Spinner.hide();
        }
    }

    return { loadAll };

})();
