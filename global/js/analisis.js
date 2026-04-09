/* global Chart */
/**
 * /bi/global/js/analisis.js
 * Pestaña Análisis del dashboard global.
 * Formatos de retorno de AnalisisDB:
 *   ranking_rubros  → d.rubros  : [{RUBRO, unidades, facturacion}]
 *   jerarquia       → d.jerarquia: [{label, totals:{unidades,unidades_prev,...}, rubros:[{label,totals,categorias}]}]
 *   vendedores      → d.vendedores: [{vendedor, unidades, unidades_prev, facturacion, facturacion_prev, var_unidades, var_facturacion}]
 *   evolucion_*     → d.evolucion: {anios, meses, series:[{anio, valores:[12]}]}
 */

const Analisis = (() => {

    const _charts = {};

    const EVOLUCION_COLORS = [
        { border: '#2563eb', bg: 'rgba(37,99,235,0.12)'  },
        { border: '#f59e0b', bg: 'rgba(245,158,11,0.12)' },
        { border: '#00a878', bg: 'rgba(0,168,120,0.15)'  },
    ];

    /* ── Formato ─────────────────────────────── */
    function numFmt(n, dec = 0) {
        return n === null || n === undefined ? '—'
            : Number(n).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }
    function moneyFmt(n) {
        if (n === null || n === undefined) return '—';
        return '$\u00A0' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    function varFmt(v) {
        if (v === null || v === undefined) return '';
        const cls  = v >= 0 ? 'pos' : 'neg';
        const icon = v >= 0 ? '▲' : '▼';
        return `<span class="${cls}">${icon} ${(Math.abs(v) * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}\u00A0%</span>`;
    }

    /* ── Fetch ───────────────────────────────── */
    async function apiFetch(action, extra = {}) {
        const qs  = Dashboard.buildQS({ action, ...extra });
        const res = await fetch(`/bi/global/api/analisis.php?${qs}`);
        if (!res.ok) throw new Error(`Error ${res.status} en analisis/${action}`);
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || `Error en ${action}`);
        return data;
    }

    /* ── Render: cards rubros ────────────────── */
    const RUBROS_CLAVE = [
        'BILLETERAS DE VINILICO',
        'CALZADOS',
        'CAMPERAS',
        'CARTERAS DE CUERO',
        'CARTERAS DE VINILICO',
    ];

    function renderCardsRubros(rubros) {
        const wrap = document.getElementById('analisis-rubros-cards');
        if (!wrap) return;
        if (!rubros?.length) { wrap.innerHTML = '<div style="color:var(--text-3);font-size:.85rem">Sin datos</div>'; return; }
        // Mostrar solo los 5 rubros clave en el orden predefinido
        const top = RUBROS_CLAVE
            .map(nombre => rubros.find(r => (r.RUBRO ?? r.rubro ?? '') === nombre))
            .filter(Boolean);
        wrap.innerHTML = top.map(r => {
            const val  = r.unidades ?? 0;
            const prev = r.unidades_prev ?? 0;
            const varV = r.var_unidades;
            const varHtml = varV != null
                ? `<span class="${varV >= 0 ? 'pos' : 'neg'}" style="font-size:.78rem;font-weight:600">${varV >= 0 ? '▲' : '▼'} ${(Math.abs(varV) * 100).toLocaleString('es-AR', {minimumFractionDigits:1, maximumFractionDigits:1})}\u00A0%</span>`
                : '';
            return `<div class="rubro-card">
                <div class="rubro-card-header" style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px">
                    <div class="rubro-card-name" style="flex:1">${r.RUBRO ?? r.rubro ?? '—'}</div>
                    <div style="text-align:right;flex-shrink:0">
                        <div style="font-size:.60rem;color:var(--text-3);text-transform:uppercase">Per. prev.</div>
                        <div style="font-size:.85rem;font-weight:600;color:var(--text-2)">${numFmt(prev)}</div>
                    </div>
                </div>
                <div class="rubro-card-val">${numFmt(val)}</div>
                <div class="rubro-card-var">${varHtml}<span style="font-size:.72rem;color:var(--text-3);margin-left:4px">unidades</span></div>
            </div>`;
        }).join('');
    }

    /* ── Render: ranking bars con drilldown ──── */
    // ranking_rubros → d.rubros: [{RUBRO, unidades, facturacion}]
    const _rankingCache  = {};  // containerId → { originalRows, valKey, fmtFn }
    const _rankingLevel  = {};  // containerId → 1|2

    function renderRankingRows(rows, labelKey, valKey, fmtFn, isDD) {
        const sorted  = [...rows].sort((a, b) => (b[valKey] ?? 0) - (a[valKey] ?? 0)).slice(0, 20);
        const max     = Math.max(...sorted.map(r => r[valKey] ?? 0), 1);
        const prevKey = valKey === 'unidades'    ? 'unidades_prev'    : (valKey === 'facturacion' ? 'facturacion_prev' : null);
        const varKey  = valKey === 'unidades'    ? 'var_unidades'     : (valKey === 'facturacion' ? 'var_facturacion'  : null);

        const html = sorted.map(r => {
            const val     = r[valKey] ?? 0;
            const pct     = (val / max * 100).toFixed(1);
            const lbl     = r[labelKey] ?? '—';
            const varV    = varKey ? (r[varKey] ?? null) : null;
            const prevVal = prevKey ? (r[prevKey] ?? 0) : 0;
            const prevPct = prevKey && max > 0 ? Math.min((prevVal / max * 100), 100).toFixed(1) : null;
            const barCls  = varV == null ? 'rubro-bar-green' : (varV >= 0 ? 'rubro-bar-green' : 'rubro-bar-red');
            const prevMrk = prevPct != null && prevVal > 0 ? `<div class="rubro-target-line" style="left:${prevPct}%"></div>` : '';
            const drillCls = isDD ? 'rubro-row' : 'rubro-row rubro-row-drillable';
            return `<div class="${drillCls}" style="cursor:${isDD ? 'default' : 'pointer'}" data-lbl="${encodeURIComponent(lbl)}" data-val="${val}" data-prev="${prevVal}" data-var="${varV ?? ''}">
                <span class="rubro-nombre" title="${lbl}">${lbl}</span>
                <div class="rubro-bar-wrap"><div class="rubro-bar ${barCls}" style="width:${pct}%"></div>${prevMrk}</div>
                <span class="rubro-val">${fmtFn(val)}</span>
            </div>`;
        }).join('');

        return html;
    }

    /* ── Ranking tooltip ────────────────────── */
    function attachRankingTooltips(container, fmtFn, isDD) {
        let tip = document.getElementById('analisis-ranking-tip');
        if (!tip) {
            tip = document.createElement('div');
            tip.id = 'analisis-ranking-tip';
            tip.className = 'ranking-tooltip';
            document.body.appendChild(tip);
        }
        container.querySelectorAll('.rubro-row').forEach(div => {
            div.addEventListener('mouseenter', () => {
                const lbl  = decodeURIComponent(div.dataset.lbl ?? '');
                const val  = parseFloat(div.dataset.val ?? 0);
                const prev = parseFloat(div.dataset.prev ?? 0);
                const varV = div.dataset.var !== '' ? parseFloat(div.dataset.var) : null;
                const rect = div.getBoundingClientRect();
                tip.innerHTML = `
                    <div class="tooltip-rubro">${lbl}</div>
                    <div class="tooltip-row"><span>Actual:</span><strong>${fmtFn(val)}</strong></div>
                    <div class="tooltip-row"><span>Anterior:</span><strong>${fmtFn(prev)}</strong></div>
                    <div class="tooltip-row"><span>Var.:</span><strong>${varV != null ? (varV >= 0 ? '▲ ' : '▼ ') + (Math.abs(varV)*100).toLocaleString('es-AR',{minimumFractionDigits:1,maximumFractionDigits:1})+'%' : '—'}</strong></div>
                    ${!isDD ? '<div class="tooltip-hint">Click para ver categorías</div>' : ''}
                `;
                tip.style.left    = `${rect.left + rect.width / 2}px`;
                tip.style.top     = `${rect.top - 10}px`;
                tip.style.display = 'block';
            });
            div.addEventListener('mouseleave', () => { tip.style.display = 'none'; });
        });
    }

    function renderRanking(containerId, rows, valKey, fmtFn) {
        const wrap = document.getElementById(containerId);
        if (!wrap) return;
        if (!rows?.length) { wrap.innerHTML = '<div style="color:var(--text-3);font-size:.85rem;padding:8px">Sin datos</div>'; return; }

        _rankingCache[containerId] = { originalRows: rows, valKey, fmtFn };
        _rankingLevel[containerId] = 1;

        const bc = `<div class="ranking-breadcrumb" id="bc-${containerId}" style="display:none">
            <button class="btn-volver-ranking" data-target="${containerId}">← Rubros</button>
        </div>`;

        wrap.innerHTML = bc + renderRankingRows(rows, 'RUBRO', valKey, fmtFn, false);

        wrap.querySelector(`#bc-${containerId} .btn-volver-ranking`)?.addEventListener('click', () => {
            _rankingLevel[containerId] = 1;
            document.getElementById(`bc-${containerId}`).style.display = 'none';
            const cached = _rankingCache[containerId];
            wrap.querySelectorAll('.rubro-row').forEach(el => el.remove());
            wrap.insertAdjacentHTML('beforeend', renderRankingRows(cached.originalRows, 'RUBRO', cached.valKey, cached.fmtFn, false));
            attachRankingClicks(wrap, containerId, cached.valKey, cached.fmtFn);
            attachRankingTooltips(wrap, cached.fmtFn, false);
        });

        attachRankingClicks(wrap, containerId, valKey, fmtFn);
        attachRankingTooltips(wrap, fmtFn, false);
    }

    function attachRankingClicks(wrap, containerId, valKey, fmtFn) {
        wrap.querySelectorAll('.rubro-row-drillable').forEach(row => {
            row.addEventListener('click', async () => {
                if (_rankingLevel[containerId] !== 1) return;
                const lbl = decodeURIComponent(row.dataset.lbl ?? '');
                if (!lbl) return;
                _rankingLevel[containerId] = 2;

                const bc = document.getElementById(`bc-${containerId}`);
                if (bc) bc.style.display = 'block';

                wrap.querySelectorAll('.rubro-row').forEach(el => el.remove());
                wrap.insertAdjacentHTML('beforeend', '<div class="analisis-loading" id="rank-loading"><i class="bi bi-arrow-repeat"></i> Cargando categorías</div>');

                try {
                    const qs  = Dashboard.buildQS({ action: 'ranking_categorias', rubro_filter: lbl });
                    const res = await fetch(`/bi/global/api/analisis.php?${qs}`);
                    const d   = await res.json();
                    wrap.querySelector('#rank-loading')?.remove();
                    const cats = d.categorias ?? [];
                    if (!cats.length) {
                        wrap.insertAdjacentHTML('beforeend', '<div style="color:var(--text-3);font-size:.85rem;padding:8px">Sin categorías</div>');
                        return;
                    }
                    wrap.insertAdjacentHTML('beforeend', renderRankingRows(cats, 'CATEGORIA', valKey, fmtFn, true));
                    attachRankingTooltips(wrap, fmtFn, true);
                } catch(e) {
                    wrap.querySelector('#rank-loading')?.remove();
                    console.error('[Analisis] ranking drilldown:', e);
                }
            });
        });
    }

    /* -- Render: jerarquia progresiva (DESTINO -> RUBRO -> CATEGORIA) -- */
    function renderJerarquia(tree) {
        const tbody = document.querySelector("#tabla-jerarquia tbody");
        if (!tbody) return;
        if (!tree?.length) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:16px;color:var(--text-3)">Sin datos</td></tr>`;
            return;
        }

        let html = "";
        tree.forEach((dest, di) => {
            const dt = dest.totals ?? {};
            html += `<tr class="row-destino jer-expandable" data-di="${di}" style="cursor:pointer">
                <td><span class="jer-icon">▶</span> <strong>${dest.label}</strong></td>
                <td style="text-align:right">${numFmt(dt.unidades)}</td>
                <td style="text-align:right">${numFmt(dt.unidades_prev)}</td>
                <td style="text-align:right">${varFmt(dt.var_unidades)}</td>
                <td style="text-align:right">${moneyFmt(dt.facturacion)}</td>
            </tr>`;

            (dest.rubros ?? []).forEach((rub, ri) => {
                const rt = rub.totals ?? {};
                const hasCats = (rub.categorias ?? []).length > 0;
                html += `<tr class="row-rubro jer-expandable" data-di="${di}" data-ri="${ri}" style="display:none;cursor:${hasCats ? "pointer" : "default"}">
                    <td style="padding-left:22px"><span class="jer-icon">${hasCats ? '▶' : '\u00a0'}</span> <strong>${rub.label}</strong></td>
                    <td style="text-align:right">${numFmt(rt.unidades)}</td>
                    <td style="text-align:right">${numFmt(rt.unidades_prev)}</td>
                    <td style="text-align:right">${varFmt(rt.var_unidades)}</td>
                    <td style="text-align:right">${moneyFmt(rt.facturacion)}</td>
                </tr>`;

                (rub.categorias ?? []).forEach(cat => {
                    html += `<tr class="row-cat" data-di="${di}" data-ri="${ri}" style="display:none">
                        <td style="padding-left:44px;color:var(--text-2)">${cat.label ?? '—'}</td>
                        <td style="text-align:right">${numFmt(cat.unidades)}</td>
                        <td style="text-align:right">${numFmt(cat.unidades_prev)}</td>
                        <td style="text-align:right">${varFmt(cat.var_unidades)}</td>
                        <td style="text-align:right">${moneyFmt(cat.facturacion)}</td>
                    </tr>`;
                });
            });
        });

        tbody.innerHTML = html;

        // Clic en DESTINO -> expande/colapsa solo sus RUBROS
        tbody.querySelectorAll(".row-destino").forEach(tr => {
            tr.addEventListener("click", () => {
                const di   = tr.dataset.di;
                const open = !tr.classList.contains("expanded");
                tr.classList.toggle("expanded", open);
                const icon = tr.querySelector(".jer-icon");
                if (icon) icon.textContent = open ? '▼' : '▶';
                tbody.querySelectorAll(".row-rubro[data-di=\"" + di + "\"]").forEach(rr => {
                    rr.style.display = open ? "" : "none";
                    if (!open) {
                        rr.classList.remove("expanded");
                        const ri2 = rr.dataset.ri;
                        const rIcon = rr.querySelector(".jer-icon");
                        if (rIcon) rIcon.textContent = '▶';
                        tbody.querySelectorAll(".row-cat[data-di=\"" + di + "\"][data-ri=\"" + ri2 + "\"]").forEach(c => c.style.display = "none");
                    }
                });
            });
        });

        // Clic en RUBRO -> expande/colapsa solo sus CATEGORIAS
        tbody.querySelectorAll(".row-rubro").forEach(tr => {
            tr.addEventListener("click", e => {
                e.stopPropagation();
                const di   = tr.dataset.di, ri = tr.dataset.ri;
                const cats = tbody.querySelectorAll(".row-cat[data-di=\"" + di + "\"][data-ri=\"" + ri + "\"]");
                if (!cats.length) return;
                const open = !tr.classList.contains("expanded");
                tr.classList.toggle("expanded", open);
                const icon = tr.querySelector(".jer-icon");
                if (icon) icon.textContent = open ? '▼' : '▶';
                cats.forEach(c => c.style.display = open ? "" : "none");
            });
        });
    }

    /* ── Render: vendedores ──────────────────── */
    // mergeVendedores → [{vendedor, unidades, unidades_prev, facturacion, facturacion_prev, var_unidades, var_facturacion}]
    function renderVendedores(rows) {
        const tbody = document.querySelector('#tabla-vendedores-analisis tbody');
        if (!tbody) return;
        if (!rows?.length) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:16px;color:var(--text-3)">Sin datos</td></tr>`;
            return;
        }
        // Heatmap por facturación
        const maxFact = Math.max(...rows.map(r => r.facturacion ?? 0), 1);
        const minFact = Math.min(...rows.map(r => r.facturacion ?? 0), 0);
        const heatFact = v => {
            const ratio = maxFact > minFact ? (v - minFact) / (maxFact - minFact) : 0.5;
            if (ratio < 0.33) return `rgba(220,38,38,${0.08 + ratio * 0.30})`;
            if (ratio < 0.66) return `rgba(245,158,11,${0.08 + ratio * 0.20})`;
            return `rgba(22,163,74,${0.08 + ratio * 0.25})`;
        };
        tbody.innerHTML = rows.map(r => {
            const bg = heatFact(r.facturacion ?? 0);
            return `<tr>
                <td>${r.vendedor}</td>
                <td style="text-align:right;background:${bg}">${moneyFmt(r.facturacion)}</td>
                <td style="text-align:right">${moneyFmt(r.facturacion_prev)}</td>
                <td style="text-align:right">${varFmt(r.var_facturacion)}</td>
                <td style="text-align:right">${numFmt(r.unidades)}</td>
                <td style="text-align:right">${numFmt(r.unidades_prev)}</td>
                <td style="text-align:right">${varFmt(r.var_unidades)}</td>
            </tr>`;
        }).join('');
    }

    /* ── Render: evolución ───────────────────── */
    // getEvolucionMensual → {anios:[...], meses:[...], series:[{anio, valores:[12]}]}
    function renderEvolucion(data, canvasId) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !data?.series?.length) return;
        if (_charts[canvasId]) { _charts[canvasId].destroy(); delete _charts[canvasId]; }

        const meses    = data.meses ?? ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        const datasets = data.series.map((s, idx) => {
            const color = EVOLUCION_COLORS[Math.min(idx, EVOLUCION_COLORS.length - 1)];
            return {
                label          : String(s.anio),
                data           : s.valores,
                borderColor    : color.border,
                backgroundColor: color.bg,
                borderWidth    : 2,
                pointRadius    : 3,
                tension        : 0.3,
                fill           : idx === data.series.length - 1,
            };
        });

        _charts[canvasId] = new Chart(canvas, {
            type: 'line',
            data: { labels: meses, datasets },
            options: {
                responsive: true,
                plugins   : { legend: { position: 'top', labels: { font: { size: 11 } } }, datalabels: { display: false } },
                scales    : { y: { ticks: { callback: v => numFmt(v) } } },
            }
        });
    }

    /* ── Carga ranking unidades (usada también desde KPIs tab) ── */
    async function loadRankingUnidades() {
        const d = await apiFetch('ranking_rubros');
        const rubros = d.rubros ?? [];
        renderRanking('analisis-ranking-unidades', rubros, 'unidades', numFmt);
    }

    /* ── Carga principal ─────────────────────── */
    async function loadAll() {
        document.body.classList.add('is-loading');
        try {
            await Promise.all([
                // Ranking de rubros (también alimenta las cards)
                apiFetch('ranking_rubros').then(d => {
                    const rubros = d.rubros ?? [];
                    renderCardsRubros(rubros);
                    renderRanking('analisis-ranking-unidades',    rubros, 'unidades',    numFmt);
                    renderRanking('analisis-ranking-facturacion', rubros, 'facturacion', moneyFmt);
                }).catch(e => console.error('[Analisis] ranking_rubros:', e)),

                // Jerarquía
                apiFetch('jerarquia').then(d => renderJerarquia(d.jerarquia)).catch(e => console.error('[Analisis] jerarquia:', e)),

                // Vendedores
                apiFetch('vendedores').then(d => renderVendedores(d.vendedores)).catch(e => console.error('[Analisis] vendedores:', e)),

                // Evolución unidades
                apiFetch('evolucion_unidades').then(d => renderEvolucion(d.evolucion, 'chart-evolucion-unidades')).catch(e => console.error('[Analisis] evolucion_unidades:', e)),

                // Evolución tickets
                apiFetch('evolucion_tickets').then(d => renderEvolucion(d.evolucion, 'chart-evolucion-tickets')).catch(e => console.error('[Analisis] evolucion_tickets:', e)),
            ]);
        } finally {
            document.body.classList.remove('is-loading');
        }
    }

    return { loadAll, loadRankingUnidades };
})();
