/**
 * /bi/global/js/ranking.js
 * Módulo de Ranking (scoring ponderado de sucursales) para el dashboard global.
 * Expone: Ranking.loadAll()
 */

const Ranking = (() => {

    const $ = id => document.getElementById(id);

    /* ── Formato ──────────────────────────────────────────── */
    const fmt = (typeof BIUtils !== 'undefined') ? BIUtils.fmt : (() => {
        const f = n => (n === null || n === undefined) ? '—' : n;
        return {
            money  : (n, d = 0) => f(n) === '—' ? '—' : '$\u00A0' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }),
            moneyK : n => {
                if (n === null || n === undefined) return '—';
                if (Math.abs(n) >= 1_000_000) return '$\u00A0' + (n / 1_000_000).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'M';
                if (Math.abs(n) >= 1_000)     return '$\u00A0' + (n / 1_000).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + 'K';
                return '$\u00A0' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
            },
            pct    : (n, d = 1) => (n === null || n === undefined) ? '—' : (n * 100).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }) + '\u00A0%',
            varPct : (n, d = 1) => { if (n === null || n === undefined) return '—'; const s = n >= 0 ? '+' : ''; return s + (n * 100).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }) + '\u00A0%'; },
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
        ['grupo', 'tipo_tienda'].forEach(k => { if (!p[k]) delete p[k]; });
        return new URLSearchParams(p).toString();
    }

    /* ── Estado de ordenamiento ───────────────────────────── */
    let _sortCol = 'score';
    let _sortDir = 'desc';
    let _lastData = [];

    /* ── KPI meta: etiqueta, formato y tipo de normalización ─ */
    const KPI_META = {
        cumplimiento        : { label: 'Cumpl. Objetivo', fmt: v => fmt.pct(v), type: 'normal'  },
        var_fact            : { label: 'Var. Ventas',      fmt: v => fmt.varPct(v), type: 'index' },
        ticket_promedio     : { label: 'Ticket Promedio',  fmt: v => fmt.money(v), type: 'normal'  },
        unidades            : { label: 'Unidades',         fmt: v => fmt.num(v),   type: 'normal'  },
        porc_2do            : { label: '% 2do Prod.',      fmt: v => fmt.pct(v),   type: 'normal'  },
        tickets             : { label: 'Tickets',          fmt: v => fmt.num(v),   type: 'normal'  },
        ticket_promedio_2do : { label: 'T.P. 2do Prod.',   fmt: v => fmt.money(v), type: 'normal'  },
        porc_3ro            : { label: '% 3er Prod.',      fmt: v => fmt.pct(v),   type: 'normal'  },
        porc_cambios        : { label: '% Cambios',        fmt: v => fmt.pct(v),   type: 'inverse' },
        porc_incremental    : { label: '% Incremental',    fmt: v => fmt.pct(v),   type: 'index'   },
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
            const sc = scoreClass(s.score);
            const cumpl = (s.detalle?.cumplimiento?.valor ?? 0);
            const varF  = (s.detalle?.var_fact?.valor ?? 0);
            return `<tr data-nro="${s.nro_sucurs}">
                <td>${rankBadgeHTML(s.rank)}</td>
                <td class="td-nombre">${s.nombre ?? ('Suc. ' + s.nro_sucurs)}</td>
                <td>
                    <div class="score-gauge-wrap">
                        <span class="score-pill ${sc}">${s.score.toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}</span>
                        ${miniBarHTML(s.score, Math.max(maxScore, 110), sc === 'score-high' ? '#16a34a' : sc === 'score-mid' ? '#b45309' : '#dc2626')}
                    </div>
                </td>
                <td>${fmt.moneyK(s.facturacion)}</td>
                <td class="${cumpl >= 1 ? 'text-green' : cumpl >= 0.9 ? 'text-yellow' : 'text-red'}">${fmt.pct(cumpl)}</td>
                <td class="${varF >= 0 ? 'text-green' : 'text-red'}">${fmt.varPct(varF)}</td>
                <td>${fmt.money(s.ticket_promedio)}</td>
                <td>${fmt.pct(s.porc_2do)}</td>
                <td>${fmt.pct(s.porc_3ro)}</td>
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
                return `<div class="kpi-detail-card">
                    <div class="kpi-detail-name">${meta?.label ?? k}</div>
                    <div class="kpi-detail-row"><span>Valor</span><strong>${meta?.fmt(d.valor) ?? d.valor}</strong></div>
                    <div class="kpi-detail-row"><span>Promedio</span><strong>${meta?.fmt(d.promedio) ?? d.promedio}</strong></div>
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
        const wrap = $('ranking-table-wrap');
        const tbody = document.querySelector('#ranking-table tbody');
        if (!tbody) return;

        tbody.innerHTML = `<tr><td colspan="9">
            <div class="ranking-loading"><div class="spinner"></div><br>Calculando ranking…</div>
        </td></tr>`;

        attachListeners();

        try {
            const res  = await fetch(`/bi/global/api/score.php?${buildQS()}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error ?? 'Error en score');

            _lastData = data.scores ?? [];

            if (_lastData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="9">
                    <div class="ranking-empty">Sin datos para el período seleccionado.</div>
                </td></tr>`;
                return;
            }

            renderTable(_lastData);

        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:24px;color:#ef4444">
                Error: ${err.message}
            </td></tr>`;
        }
    }

    return { loadAll };

})();
