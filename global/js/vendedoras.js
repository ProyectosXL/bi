/**
 * /bi/global/js/vendedoras.js
 * Pestaña Vendedoras: Top 10, KPIs tabla, Versus.
 * Depende de Dashboard.buildQS()
 */

const Vendedoras = (() => {

    let _kpisData     = null;
    let _charts       = {};

    /* ── Exportar KPIs vendedoras a Excel ────── */
    function exportarVendedoras() {
        if (!_kpisData?.length || typeof ExcelExporter === 'undefined') return;
        const rows   = _kpisData;
        const totFact = rows.reduce((s, r) => s + (r.facturacion ?? 0), 0);
        const totUnid = rows.reduce((s, r) => s + (r.unidades    ?? 0), 0);
        const totTick = rows.reduce((s, r) => s + (r.tickets     ?? 0), 0);
        const avgTProm = totTick > 0 ? totFact / totTick : 0;
        const avg2do   = totTick > 0 ? rows.reduce((s, r) => s + ((r.porc_2do   ?? 0) * (r.tickets ?? 0)), 0) / totTick : 0;
        const avg3ro   = totTick > 0 ? rows.reduce((s, r) => s + ((r.porc_3ro   ?? 0) * (r.tickets ?? 0)), 0) / totTick : 0;
        const avgCamb  = totTick > 0 ? rows.reduce((s, r) => s + ((r.porc_cambios ?? 0) * (r.tickets ?? 0)), 0) / totTick : 0;
        const avgIncr  = totTick > 0 ? rows.reduce((s, r) => s + ((r.porc_incremental ?? 0) * (r.tickets ?? 0)), 0) / totTick : 0;
        ExcelExporter.export({
            title    : 'KPIs por Vendedora',
            headers  : ['Vendedora', 'Facturación', 'Unidades', 'Tickets',
                        'Ticket Prom.', '% 2do Prod.', '% 3er Prod.', '% Cambios', '% Incremental'],
            rows     : rows.map(r => [
                r.vendedora,
                r.facturacion        ?? null,
                r.unidades           ?? null,
                r.tickets            ?? null,
                r.ticket_promedio    ?? null,
                r.porc_2do           ?? null,
                r.porc_3ro           ?? null,
                r.porc_cambios       ?? null,
                r.porc_incremental   ?? null,
            ]),
            totalsRow: ['PROMEDIO', totFact, totUnid, totTick,
                        avgTProm, avg2do, avg3ro, avgCamb, avgIncr],
            filename : 'kpis_vendedoras',
        });
    }

    /* ── Formato ─────────────────────────────── */
    function moneyK(n) {
        if (n === null || n === undefined) return '—';
        if (Math.abs(n) >= 1_000_000) return '$\u00A0' + (n / 1_000_000).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'M';
        if (Math.abs(n) >= 1_000)     return '$\u00A0' + (n / 1_000).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + 'K';
        return '$\u00A0' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    function numFmt(n) { return n === null || n === undefined ? '—' : Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); }
    function pctFmt(n) { return n === null || n === undefined ? '—' : (n * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '\u00A0%'; }

    /* ── Metrica display helpers ─────────────── */
    const METRICA_FMT = {
        facturacion  : v => moneyK(v),
        unidades     : v => numFmt(v),
        tickets      : v => numFmt(v),
        ticket_promedio: v => moneyK(v),
        porc_2do     : v => pctFmt(v),
    };
    const METRICA_LABEL = {
        facturacion    : 'Facturación',
        unidades       : 'Unidades',
        tickets        : 'Tickets',
        ticket_promedio: 'Ticket Promedio',
        porc_2do       : '% 2do Producto',
    };

    /* ── Filtro: excluir EMPRESA, XL y nombres de sucursal ─ */
    const TOP10_EXCLUDE = /\bEMPRESA\b|\bXL\b/i;

    /* ── Render Top 10 ───────────────────────── */
    function renderTop10() {
        if (!_kpisData?.length) return;
        const wrap = document.getElementById('top10-wrap');
        if (!wrap) return;

        // Destruir chart previo si existía (era bar chart)
        if (_charts['top10-chart']) { _charts['top10-chart'].destroy(); delete _charts['top10-chart']; }

        // Filtrar registros que no son vendedoras reales
        const vendedoras = _kpisData.filter(r => !TOP10_EXCLUDE.test(r.vendedora ?? ''));

        // 5 rankings lado a lado
        const RANKINGS = [
            { metrica: 'facturacion',     prevKey: 'facturacion_prev',     label: 'Facturación',    fmt: moneyK, tooltipExtra: r => `<div class="tooltip-row"><span>Tickets:</span><strong>${numFmt(r.tickets)}</strong></div>` },
            { metrica: 'unidades',        prevKey: 'unidades_prev',        label: 'Unidades',       fmt: numFmt,  tooltipExtra: r => `<div class="tooltip-row"><span>Tickets:</span><strong>${numFmt(r.tickets)}</strong></div>` },
            { metrica: 'tickets',         prevKey: 'tickets_prev',         label: 'Tickets',        fmt: numFmt,  tooltipExtra: r => `<div class="tooltip-row"><span>T.Prom:</span><strong>${moneyK(r.ticket_promedio)}</strong></div>` },
            { metrica: 'ticket_promedio', prevKey: 'ticket_promedio_prev', label: 'T. Promedio',    fmt: moneyK, tooltipExtra: r => `<div class="tooltip-row"><span>Tickets:</span><strong>${numFmt(r.tickets)}</strong></div>` },
            { metrica: 'porc_2do',        prevKey: 'porc_2do_prev',        label: '% 2do Producto', fmt: pctFmt, tooltipExtra: r => `<div class="tooltip-row"><span>Tickets:</span><strong>${numFmt(r.tickets)}</strong></div>` },
        ];

        wrap.innerHTML = `<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px"></div>`;
        const grid = wrap.querySelector('div');

        RANKINGS.forEach(({ metrica, prevKey, label, fmt, tooltipExtra }) => {
            const sorted = [...vendedoras].sort((a, b) => (b[metrica] ?? 0) - (a[metrica] ?? 0)).slice(0, 10);
            const max    = Math.max(...sorted.map(r => r[metrica] ?? 0), 1);

            const col = document.createElement('div');
            col.innerHTML = `<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-3);margin-bottom:6px;padding:0 4px">${label}</div>`;

            sorted.forEach(r => {
                const val     = r[metrica] ?? 0;
                const prev    = r[prevKey] ?? 0;
                const pct     = (val / max * 100).toFixed(1);
                const prevPct = prev > 0 ? Math.min((prev / max * 100), 100).toFixed(1) : null;
                const barCls  = val >= prev ? 'rubro-bar-green' : 'rubro-bar-red';
                const prevMrk = prevPct ? `<div class="rubro-target-line" style="left:${prevPct}%"></div>` : '';

                const div = document.createElement('div');
                div.className = 'rubro-row';
                div.dataset.vendedora = r.vendedora;
                div.dataset.val       = val;
                div.dataset.prev      = prev;
                div.dataset.metrica   = metrica;
                div.innerHTML = `
                    <span class="rubro-nombre" title="${r.vendedora}">${r.vendedora}</span>
                    <div class="rubro-bar-wrap"><div class="rubro-bar ${barCls}" style="width:${pct}%"></div>${prevMrk}</div>
                    <span class="rubro-val">${fmt(val)}</span>
                `;

                div.addEventListener('mouseenter', () => {
                    let tip = document.getElementById('top10-vend-tip');
                    if (!tip) {
                        tip = document.createElement('div');
                        tip.id = 'top10-vend-tip';
                        tip.className = 'ranking-tooltip';
                        document.body.appendChild(tip);
                    }
                    const rect = div.getBoundingClientRect();
                    tip.innerHTML = `
                        <div class="tooltip-rubro">${r.vendedora}</div>
                        <div class="tooltip-row"><span>Actual:</span><strong>${fmt(val)}</strong></div>
                        <div class="tooltip-row"><span>Anterior:</span><strong>${fmt(prev)}</strong></div>
                        ${tooltipExtra(r)}
                    `;
                    tip.style.left    = `${rect.left + rect.width / 2}px`;
                    tip.style.top     = `${rect.top - 10}px`;
                    tip.style.display = 'block';
                });
                div.addEventListener('mouseleave', () => {
                    const tip = document.getElementById('top10-vend-tip');
                    if (tip) tip.style.display = 'none';
                });

                col.appendChild(div);
            });

            grid.appendChild(col);
        });
    }

    /* ── money: monto completo sin abreviar ─── */
    function money(n) {
        if (n === null || n === undefined) return '—';
        return '$\u00A0' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    /* ── Semáforo vs promedio (ALL) ─────────── */
    function semaforoColor(v, avg, inverse = false) {
        if (v === null || v === undefined || avg === null || avg === undefined) return '';
        const variation = avg !== 0 ? (v - avg) / Math.abs(avg) : 0;
        if (!inverse) {
            if (v > avg)          return '#3CB371'; // verde
            if (variation > -0.10) return '#FFFF00'; // amarillo
            return '#FF4500';                        // rojo
        } else {
            // % Cambios: menor es mejor
            if (v < avg)          return '#3CB371';
            if (variation < 0.10)  return '#FFFF00';
            return '#FF4500';
        }
    }

    /* ── Render KPIs tabla con semáforo ─────── */
    function renderKPIsTabla(rows) {
        const tbody = document.querySelector('#tabla-kpis-vendedoras tbody');
        if (!tbody) return;
        if (!rows?.length) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:24px;color:var(--text-3)">Sin datos</td></tr>`;
            return;
        }

        // Promedios ponderados (ALL) — benchmark
        const totFact  = rows.reduce((s, r) => s + (r.facturacion ?? 0), 0);
        const totUnid  = rows.reduce((s, r) => s + (r.unidades    ?? 0), 0);
        const totTick  = rows.reduce((s, r) => s + (r.tickets     ?? 0), 0);
        const avgTProm = totTick > 0 ? totFact / totTick : 0;
        const avg2do   = totTick > 0 ? rows.reduce((s, r) => s + ((r.porc_2do ?? 0) * (r.tickets ?? 0)), 0) / totTick : 0;
        const avg3ro   = totTick > 0 ? rows.reduce((s, r) => s + ((r.porc_3ro ?? 0) * (r.tickets ?? 0)), 0) / totTick : 0;
        const avgCamb  = totTick > 0 ? rows.reduce((s, r) => s + ((r.porc_cambios ?? 0) * (r.tickets ?? 0)), 0) / totTick : 0;
        const avgIncr  = totTick > 0 ? rows.reduce((s, r) => s + ((r.porc_incremental ?? 0) * (r.tickets ?? 0)), 0) / totTick : 0;

        const border = 'border-bottom:1px solid var(--border)';

        const cellSem = (v, avg, fmt, inverse = false) => {
            const bg  = semaforoColor(v, avg, inverse);
            const txt = bg ? 'color:#000;font-weight:600;' : '';
            return `<td style="text-align:right;${border};background:${bg};${txt}">${fmt(v)}</td>`;
        };

        const dataRows = rows.map(r => `<tr style="${border}">
            <td style="${border}">${r.vendedora}</td>
            <td style="text-align:right;${border}">${money(r.facturacion)}</td>
            <td style="text-align:right;${border}">${numFmt(r.unidades)}</td>
            <td style="text-align:right;${border}">${numFmt(r.tickets)}</td>
            ${cellSem(r.ticket_promedio, avgTProm, money)}
            ${cellSem(r.porc_2do,        avg2do,   pctFmt)}
            ${cellSem(r.porc_3ro,        avg3ro,   pctFmt)}
            ${cellSem(r.porc_cambios,    avgCamb,  pctFmt, true)}
            ${cellSem(r.porc_incremental,avgIncr,  pctFmt)}
        </tr>`).join('');

        // Fila de totales/promedio (ALL) — sin semáforo
        const totTProm = avgTProm;
        const totalsRow = `<tr style="font-weight:700;border-top:2px solid var(--border);background:var(--surface-1)">
            <td>PROMEDIO</td>
            <td style="text-align:right">${money(totFact)}</td>
            <td style="text-align:right">${numFmt(totUnid)}</td>
            <td style="text-align:right">${numFmt(totTick)}</td>
            <td style="text-align:right">${money(totTProm)}</td>
            <td style="text-align:right">${pctFmt(avg2do)}</td>
            <td style="text-align:right">${pctFmt(avg3ro)}</td>
            <td style="text-align:right">${pctFmt(avgCamb)}</td>
            <td style="text-align:right">${pctFmt(avgIncr)}</td>
        </tr>`;

        tbody.innerHTML = dataRows + totalsRow;
    }

    /* ── Populate versus selects ─────────────── */
    function fillVersusSelects(rows) {
        ['sel-versus-a', 'sel-versus-b'].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            const cur = sel.value;
            sel.innerHTML = `<option value="">— Vendedora —</option>` +
                rows.map(r => `<option value="${r.vendedora}"${r.vendedora === cur ? ' selected' : ''}>${r.vendedora}</option>`).join('');
        });
    }

    /* ── Render versus ───────────────────────── */
    function renderVersus(a, b) {
        const wrap = document.getElementById('versus-wrap');
        if (!wrap) return;
        if (!a || !b) {
            wrap.innerHTML = '<div style="color:var(--text-3);font-size:.85rem">Seleccioná dos vendedoras para comparar.</div>';
            return;
        }

        const metricas = [
            { key: 'facturacion',   label: 'Facturación',    fmt: money },
            { key: 'unidades',      label: 'Unidades',       fmt: numFmt },
            { key: 'tickets',       label: 'Tickets',        fmt: numFmt },
            { key: 'ticket_promedio', label: 'T. Promedio',  fmt: money },
            { key: 'porc_2do',      label: '% 2do Prod.',    fmt: pctFmt },
            { key: 'porc_3ro',      label: '% 3er Prod.',    fmt: pctFmt },
            { key: 'porc_cambios',  label: '% Cambios',      fmt: pctFmt },
            { key: 'porc_incremental', label: '% Incremental', fmt: pctFmt },
        ];

        const rows = metricas.map(m => {
            const va = a[m.key] ?? 0, vb = b[m.key] ?? 0;
            const winA = va >= vb ? 'versus-win' : '';
            const winB = vb >= va ? 'versus-win' : '';
            return `<tr>
                <td class="versus-label-a ${winA}">${m.fmt(va)}</td>
                <td class="versus-metric">${m.label}</td>
                <td class="versus-label-b ${winB}">${m.fmt(vb)}</td>
            </tr>`;
        }).join('');

        wrap.innerHTML = `
            <div class="versus-names">
                <strong>${a.vendedora}</strong>
                <span style="color:var(--text-3);font-weight:700">VS</span>
                <strong>${b.vendedora}</strong>
            </div>
            <table class="versus-tabla">
                <tbody>${rows}</tbody>
            </table>`;
    }

    /* ── Cargar versus al hacer clic ─────────── */
    function setupVersus() {
        document.getElementById('btn-versus')?.addEventListener('click', () => {
            if (!_kpisData) return;
            const selA = document.getElementById('sel-versus-a')?.value;
            const selB = document.getElementById('sel-versus-b')?.value;
            if (!selA || !selB) return;
            const map  = Object.fromEntries(_kpisData.map(r => [r.vendedora, r]));
            renderVersus(map[selA] ?? null, map[selB] ?? null);
        });
    }

    /* ── Carga principal ─────────────────────── */
    async function loadAll() {
        ['top10-wrap', 'tabla-kpis-vendedoras tbody', 'versus-wrap'].forEach(sel => {
            const el = document.querySelector(sel.includes(' ') ? sel : '#' + sel);
            if (el) el.innerHTML = '<div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span>Cargando...</span></div>';
        });
        document.body.classList.add('is-loading');
        Spinner.show('Cargando vendedoras...');

        try {
            const qs  = Dashboard.buildQS();
            const res = await fetch(`/bi/global/api/vendedoras.php?${qs}`);
            if (!res.ok) throw new Error(`Error ${res.status}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Error en vendedoras');

            _kpisData = data.vendedoras ?? [];

            renderTop10();
            renderKPIsTabla(_kpisData);

            // Botón de exportación en el header de KPIs tabla (una sola vez)
            if (typeof ExcelExporter !== 'undefined') {
                const headerEl = document.getElementById('tabla-kpis-vendedoras')
                    ?.closest('.analisis-card')
                    ?.querySelector('.analisis-section-header');
                ExcelExporter.addExportButton(headerEl, exportarVendedoras);
            }
            fillVersusSelects(_kpisData);
            renderVersus(null, null);
            setupVersus();

        } catch(e) {
            ['top10-wrap','versus-wrap'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.innerHTML = `<div style="padding:16px;color:var(--neg);font-size:.85rem"><i class="bi bi-exclamation-triangle"></i> ${e.message}</div>`;
            });
            console.error('[Vendedoras]', e);
        } finally {
            document.body.classList.remove('is-loading');
            Spinner.hide();
        }
    }

    return { loadAll, renderTop10 };
})();
