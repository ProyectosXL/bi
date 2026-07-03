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

    // Paleta cíclica de 9 colores para los años (del más reciente al más antiguo)
    const EVOLUCION_COLORS = [
        { border: '#2563eb', bg: 'rgba(37,99,235,0.10)'   },  // 2026
        { border: '#16a34a', bg: 'rgba(22,163,74,0.10)'   },  // 2025
        { border: '#dc2626', bg: 'rgba(220,38,38,0.10)'   },  // 2024
        { border: '#d97706', bg: 'rgba(217,119,6,0.10)'   },  // 2023
        { border: '#7c3aed', bg: 'rgba(124,58,237,0.10)'  },  // 2022
        { border: '#0891b2', bg: 'rgba(8,145,178,0.10)'   },  // 2021
        { border: '#db2777', bg: 'rgba(219,39,119,0.10)'  },  // 2020
        { border: '#65a30d', bg: 'rgba(101,163,13,0.10)'  },  // 2019
        { border: '#9f1239', bg: 'rgba(159,18,57,0.10)'   },  // 2018
    ];

    // Cache de últimos datos de evolución por métrica (para el modal y re-render)
    const _lastEvolucionData = {};

    // Métrica actualmente mostrada en el gráfico de evolución
    let _evolucionMetrica = 'unidades';

    // Instancia del chart en el modal de evolución
    let _modalEvChart = null;

    /* ── Formato ─────────────────────────────── */
    function numFmt(n, dec = 0) {
        return n === null || n === undefined ? '—'
            : Number(n).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }
    function moneyFmt(n) {
        if (n === null || n === undefined) return '—';
        const v   = typeof Dashboard !== 'undefined' ? Dashboard.convertir(n) : n;
        const pfx = typeof Dashboard !== 'undefined' ? Dashboard.moneyPrefix() : '$\u00A0';
        return pfx + v.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    function moneyFmtK(n) {
        if (n === null || n === undefined) return '—';
        const v   = typeof Dashboard !== 'undefined' ? Dashboard.convertir(n) : n;
        const pfx = typeof Dashboard !== 'undefined' ? Dashboard.moneyPrefix() : '$\u00A0';
        if (Math.abs(v) >= 1_000_000) return pfx + (v / 1_000_000).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'M';
        if (Math.abs(v) >= 1_000)     return pfx + (v / 1_000).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + 'K';
        return pfx + v.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
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
    // tipo: 'unidades' | 'tickets' | 'facturacion'
    function evolucionFmt(tipo) {
        return tipo === 'facturacion' ? moneyFmtK : numFmt;
    }

    function evolucionTitulo(tipo) {
        if (tipo === 'tickets')     return 'Evolución Mensual — Tickets';
        if (tipo === 'facturacion') return 'Evolución Mensual — Facturación';
        return 'Evolución Mensual — Unidades';
    }

    /**
     * Convierte los valores de una serie mensual a USD si corresponde.
     * Cada punto (anio, mesIdx+1) se divide por la TCC de ese mes.
     */
    function convertirSerieEvolucion(series) {
        if (typeof Dashboard === 'undefined' || Dashboard.getMoneda() !== 'USD') return series;
        return series.map(s => ({
            ...s,
            valores: s.valores.map((v, mesIdx) => {
                if (v === null) return null;
                const mesKey = `${s.anio}-${String(mesIdx + 1).padStart(2, '0')}`;
                return Dashboard.convertirConFecha(v, mesKey + '-01');
            }),
        }));
    }

    /**
     * Asegura que _cotizaciones tenga datos para todos los años presentes en las series.
     * El rango de KPIs solo cubre el período actual+previo; el gráfico puede ir más atrás.
     */
    async function ensureCotizacionesParaEvolucion(series) {
        if (typeof Dashboard === 'undefined' || Dashboard.getMoneda() !== 'USD') return;
        if (!series?.length) return;
        const anios = series.map(s => s.anio).filter(Boolean);
        if (!anios.length) return;
        const minAnio = Math.min(...anios);
        const maxAnio = Math.max(...anios);
        const desde = `${minAnio}-01-01`;
        const hasta = `${maxAnio}-12-31`;
        try {
            const qs  = new URLSearchParams({ desde, hasta, desde_prev: desde, hasta_prev: hasta }).toString();
            const res = await fetch(`/bi/global/api/cotizacion.php?${qs}`);
            const d   = await res.json();
            if (d.ok && d.cotizaciones) {
                // Merge into Dashboard's internal map via getTCCParaMes (read-only),
                // so we patch through a temporary override on Dashboard if possible,
                // or store locally and shadow getTCCParaMes for the conversion.
                _cotizacionesEvolucion = d.cotizaciones;
            }
        } catch (e) {
            console.warn('[Analisis] cotizaciones evolución:', e);
        }
    }

    // Cotizaciones adicionales cargadas para cubrir el rango histórico de evolución
    let _cotizacionesEvolucion = {};

    /**
     * Convierte un valor de evolución usando cotizaciones históricas extendidas.
     * Prioriza _cotizacionesEvolucion (rango histórico) sobre Dashboard (solo período actual).
     */
    function convertirEvolucionValor(valor, mesKey) {
        if (typeof Dashboard === 'undefined' || Dashboard.getMoneda() !== 'USD') return valor ?? 0;
        const tcc = _cotizacionesEvolucion[mesKey] ?? Dashboard.getTCCParaMes(mesKey);
        return (valor ?? 0) / tcc;
    }

    function convertirSerieEvolucionHistorica(series) {
        if (typeof Dashboard === 'undefined' || Dashboard.getMoneda() !== 'USD') return series;
        return series.map(s => ({
            ...s,
            valores: s.valores.map((v, mesIdx) => {
                if (v === null) return null;
                const mesKey = `${s.anio}-${String(mesIdx + 1).padStart(2, '0')}`;
                return convertirEvolucionValor(v, mesKey);
            }),
        }));
    }

    function renderEvolucion(data, canvasId, tipo) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !data?.series?.length) return;
        if (_charts[canvasId]) { _charts[canvasId].destroy(); delete _charts[canvasId]; }

        // Guardar datos originales (en ARS) para re-render al cambiar moneda
        if (tipo) _lastEvolucionData[tipo] = data;

        // Actualizar título del card
        const tituloEl = document.getElementById('evolucion-titulo');
        if (tituloEl) tituloEl.textContent = evolucionTitulo(tipo);

        const fmtV  = evolucionFmt(tipo);
        const meses = data.meses ?? ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

        // Convertir por mes si está en USD (solo para facturación)
        const seriesConvertidas = tipo === 'facturacion'
            ? convertirSerieEvolucionHistorica(data.series)
            : data.series;

        const datasets = seriesConvertidas.map((s, idx) => {
            const color = EVOLUCION_COLORS[idx % EVOLUCION_COLORS.length];
            return {
                label          : String(s.anio),
                data           : s.valores,
                borderColor    : color.border,
                backgroundColor: color.bg,
                borderWidth    : 2,
                pointRadius    : 3,
                tension        : 0.3,
                fill           : false,
            };
        });

        _charts[canvasId] = new Chart(canvas, {
            type: 'line',
            data: { labels: meses, datasets },
            options: {
                responsive: true,
                plugins: {
                    legend    : { position: 'top', labels: { font: { size: 11 }, boxWidth: 14 } },
                    datalabels: { display: false },
                    tooltip   : {
                        mode       : 'index',
                        intersect  : false,
                        backgroundColor: '#1a2340',
                        titleColor : '#9ba8c8',
                        bodyColor  : '#ffffff',
                        padding    : 10,
                        cornerRadius: 6,
                        callbacks  : {
                            title     : items => items.length ? (meses[items[0].dataIndex] ?? '') : '',
                            afterTitle: () => '──────────────',
                            label     : ctx => {
                                const v = ctx.parsed.y;
                                return ` ${ctx.dataset.label}: ${v !== null && v !== undefined ? fmtV(v) : '—'}`;
                            },
                            labelTextColor: ctx => EVOLUCION_COLORS[ctx.datasetIndex % EVOLUCION_COLORS.length].border,
                        },
                    },
                },
                scales: { y: { ticks: { callback: v => fmtV(v) } } },
            }
        });
    }

    /* ── Modal de evolución ─────────────────── */
    function openEvolucionModal(tipo) {
        const resolvedTipo = tipo ?? _evolucionMetrica;
        const data = _lastEvolucionData[resolvedTipo];
        if (!data?.series?.length) return;

        const overlay = document.getElementById('evolucion-modal-overlay');
        if (!overlay) return;

        const titleEl = document.getElementById('evolucion-modal-title');
        const aniosEl = document.getElementById('evolucion-modal-anios');
        const canvas  = document.getElementById('evolucion-modal-canvas');
        if (!canvas) return;

        if (titleEl) titleEl.textContent = evolucionTitulo(resolvedTipo);

        const fmtV = evolucionFmt(resolvedTipo);

        const meses = data.meses ?? ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

        // Convertir por mes si está en USD (solo facturación)
        const seriesModal = resolvedTipo === 'facturacion'
            ? convertirSerieEvolucion(data.series)
            : data.series;

        const datasets = seriesModal.map((s, idx) => {
            const color = EVOLUCION_COLORS[idx % EVOLUCION_COLORS.length];
            return {
                label          : String(s.anio),
                data           : s.valores,
                borderColor    : color.border,
                backgroundColor: color.bg,
                borderWidth    : 2.5,
                pointRadius    : 4,
                tension        : 0.3,
                fill           : false,
                hidden         : false,
            };
        });

        // Chips de año
        if (aniosEl) {
            aniosEl.innerHTML = '';
            data.series.forEach((s, idx) => {
                const color = EVOLUCION_COLORS[idx % EVOLUCION_COLORS.length];
                const chip  = document.createElement('span');
                chip.className       = 'anio-chip';
                chip.textContent     = s.anio;
                chip.style.color     = color.border;
                chip.style.background = color.bg;
                chip.dataset.idx     = idx;
                chip.addEventListener('click', () => {
                    if (!_modalEvChart) return;
                    const i  = parseInt(chip.dataset.idx);
                    const ds = _modalEvChart.data.datasets[i];
                    if (!ds) return;
                    ds.hidden = !ds.hidden;
                    chip.classList.toggle('hidden-year', !!ds.hidden);
                    _modalEvChart.update();
                });
                aniosEl.appendChild(chip);
            });
        }

        if (_modalEvChart) { _modalEvChart.destroy(); _modalEvChart = null; }

        _modalEvChart = new Chart(canvas, {
            type: 'line',
            data: { labels: meses, datasets },
            options: {
                responsive         : true,
                maintainAspectRatio: false,
                plugins: {
                    legend    : { display: false },
                    datalabels: { display: false },
                    tooltip   : {
                        mode       : 'index',
                        intersect  : false,
                        backgroundColor: '#1a2340',
                        titleColor : '#9ba8c8',
                        bodyColor  : '#ffffff',
                        padding    : 10,
                        cornerRadius: 6,
                        callbacks  : {
                            title     : items => items.length ? (meses[items[0].dataIndex] ?? '') : '',
                            afterTitle: () => '──────────────',
                            label     : ctx => {
                                const v = ctx.parsed.y;
                                return ` ${ctx.dataset.label}: ${v !== null && v !== undefined ? fmtV(v) : '—'}`;
                            },
                            labelTextColor: ctx => EVOLUCION_COLORS[ctx.datasetIndex % EVOLUCION_COLORS.length].border,
                        },
                    },
                },
                scales: {
                    y: { ticks: { callback: v => fmtV(v) } },
                    x: { ticks: { font: { size: 11 } } },
                },
            },
        });

        overlay.style.display = 'flex';
    }

    function closeEvolucionModal() {
        if (_modalEvChart) { _modalEvChart.destroy(); _modalEvChart = null; }
        const overlay = document.getElementById('evolucion-modal-overlay');
        if (overlay) overlay.style.display = 'none';
    }

    // Listeners del modal y switch de métrica (se registran una sola vez)
    (function _initModalListeners() {
        document.addEventListener('click', e => {
            // Expand modal — usa la métrica activa
            const expandBtn = e.target.closest('#evolucion-expand-btn');
            if (expandBtn) { e.stopPropagation(); openEvolucionModal(_evolucionMetrica); return; }

            // Switch de métrica
            const evolBtn = e.target.closest('[data-evol-metrica]');
            if (evolBtn) {
                const metrica = evolBtn.dataset.evolMetrica;
                if (metrica === _evolucionMetrica) return;
                _evolucionMetrica = metrica;
                // Activar botón
                document.querySelectorAll('#evolucion-metric-switch .evol-btn').forEach(b =>
                    b.classList.toggle('active', b === evolBtn));
                // Render si ya está en caché, si no fetch
                const canvasId = 'chart-evolucion-unidades';
                if (_lastEvolucionData[metrica]) {
                    // Facturación: asegurar cotizaciones históricas antes de re-renderizar
                    if (metrica === 'facturacion') {
                        ensureCotizacionesParaEvolucion(_lastEvolucionData[metrica].series)
                            .then(() => renderEvolucion(_lastEvolucionData[metrica], canvasId, metrica));
                    } else {
                        renderEvolucion(_lastEvolucionData[metrica], canvasId, metrica);
                    }
                } else {
                    const action = metrica === 'facturacion' ? 'evolucion_facturacion'
                                 : metrica === 'tickets'     ? 'evolucion_tickets'
                                                             : 'evolucion_unidades';
                    apiFetch(action).then(async d => {
                        if (metrica === 'facturacion') {
                            await ensureCotizacionesParaEvolucion(d.evolucion?.series);
                        }
                        renderEvolucion(d.evolucion, canvasId, metrica);
                    }).catch(err => console.error('[Analisis] evolucion_' + metrica, err));
                }
                return;
            }

            const closeBtn = e.target.closest('#evolucion-modal-close');
            if (closeBtn) closeEvolucionModal();

            const overlay = document.getElementById('evolucion-modal-overlay');
            if (overlay && e.target === overlay) closeEvolucionModal();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeEvolucionModal();
        });
    })();

    /* ── Carga ranking unidades (usada también desde KPIs tab) ── */
    async function loadRankingUnidades() {
        const d = await apiFetch('ranking_rubros');
        const rubros = d.rubros ?? [];
        renderRanking('analisis-ranking-unidades', rubros, 'unidades', numFmt);
    }

    /* ── Carga principal ─────────────────────── */
    async function loadAll() {
        // Resetear switch a 'unidades' en cada carga
        _evolucionMetrica = 'unidades';
        Object.keys(_lastEvolucionData).forEach(k => delete _lastEvolucionData[k]);
        document.querySelectorAll('#evolucion-metric-switch .evol-btn').forEach(b =>
            b.classList.toggle('active', b.dataset.evolMetrica === 'unidades'));

        document.body.classList.add('is-loading');
        Spinner.show('Cargando análisis...');
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

                // Evolución (métrica activa al momento de la carga)
                apiFetch('evolucion_' + _evolucionMetrica)
                    .then(async d => {
                        // Para facturación: cargar cotizaciones históricas antes de convertir
                        if (_evolucionMetrica === 'facturacion') {
                            await ensureCotizacionesParaEvolucion(d.evolucion?.series);
                        }
                        renderEvolucion(d.evolucion, 'chart-evolucion-unidades', _evolucionMetrica);
                    })
                    .catch(e => console.error('[Analisis] evolucion_' + _evolucionMetrica, e)),

                // Cargar Donuts y Medios de Pago movidos a Análisis
                (async () => {
                    try { if (typeof Dashboard !== 'undefined') await Dashboard.loadDonuts(); } catch(e) {}
                    try { if (typeof Dashboard !== 'undefined') await Dashboard.loadMediosPago(); } catch(e) {}
                })(),
            ]);
        } finally {
            document.body.classList.remove('is-loading');
            Spinner.hide();
        }
    }

    return { loadAll, loadRankingUnidades };
})();
