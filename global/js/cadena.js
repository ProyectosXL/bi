/**
 * /bi/global/js/cadena.js
 * Pestaña Cadena: KPIs por sucursal con scroll horizontal.
 * Depende de Dashboard.buildQS()
 */

const Cadena = (() => {

    let _lastData    = null;
    let _chainTotals = null;
    let _sortCol     = null;
    let _sortAsc     = true;

    /* ── Formato ─────────────────────────────── */
    function money(n, d = 0) {
        if (n === null || n === undefined) return '—';
        const v   = typeof Dashboard !== 'undefined' ? Dashboard.convertir(n) : n;
        const pfx = typeof Dashboard !== 'undefined' ? Dashboard.moneyPrefix() : '$\u00A0';
        return pfx + v.toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d });
    }
    function moneyK(n) {
        if (n === null || n === undefined) return '—';
        const v   = typeof Dashboard !== 'undefined' ? Dashboard.convertir(n) : n;
        const pfx = typeof Dashboard !== 'undefined' ? Dashboard.moneyPrefix() : '$\u00A0';
        if (Math.abs(v) >= 1_000_000) return pfx + (v / 1_000_000).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'M';
        if (Math.abs(v) >= 1_000)     return pfx + (v / 1_000).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + 'K';
        return pfx + v.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    function numFmt(n, dec = 0) {
        return n === null || n === undefined ? '—' : Number(n).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }
    function pctFmt(n, dec = 1) {
        return n === null || n === undefined ? '—' : (n * 100).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + '\u00A0%';
    }
    function varFmt(n, dec = 1) {
        if (n === null || n === undefined) return '—';
        const s = n >= 0 ? '+' : '';
        return s + (n * 100).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + '\u00A0%';
    }

    function _conv(v) {
        return typeof Dashboard !== 'undefined' && v != null ? Dashboard.convertir(v) : v;
    }

    /* ── Columnas de la tabla ────────────────── */
    const COLS = [
        { key: 'nro_sucurs',            label: 'Sucursal',
          fmt: r => r._isTotal ? 'TOTAL' : (typeof Dashboard !== 'undefined' ? Dashboard.getSucNombre(r.nro_sucurs) : 'Suc. ' + r.nro_sucurs),
          val: r => r._isTotal ? 'TOTAL' : (typeof Dashboard !== 'undefined' ? Dashboard.getSucNombre(r.nro_sucurs) : 'Suc. ' + r.nro_sucurs),
          xlFmt: 'text',  align: 'left',  sortKey: 'nro_sucurs' },
        { key: 'objetivo_total',        label: 'Objetivo Mes',
          fmt: r => money(r.objetivo_total),
          val: r => r.objetivo_total != null ? _conv(r.objetivo_total) : null,
          xlFmt: 'money', align: 'right', sortKey: 'objetivo_total' },
        { key: 'facturacion',           label: 'Facturación',
          fmt: r => money(r.facturacion),
          val: r => r.facturacion != null ? _conv(r.facturacion) : null,
          xlFmt: 'money', align: 'right', sortKey: 'facturacion' },
        { key: 'var_facturacion',       label: 'Var. Fact.',
          fmt: r => r.var_facturacion != null ? varFmt(r.var_facturacion) : '—',
          val: r => r.var_facturacion ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'var_facturacion', varCls: true },
        { key: 'porc_cumplimiento',     label: '% Cumpl.',
          fmt: r => r.porc_cumplimiento != null ? pctFmt(r.porc_cumplimiento) : '—',
          val: r => r.porc_cumplimiento ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'porc_cumplimiento', cumpl: true },
        { key: 'unidades',              label: 'Unidades',
          fmt: r => numFmt(r.unidades),
          val: r => r.unidades ?? null,
          xlFmt: 'num',   align: 'right', sortKey: 'unidades' },
        { key: 'var_unidades',          label: 'Var. Unid.',
          fmt: r => r.var_unidades != null ? varFmt(r.var_unidades) : '—',
          val: r => r.var_unidades ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'var_unidades', varCls: true },
        { key: 'porc_part_facturacion', label: '% Part. Fact.',
          fmt: r => r.porc_part_facturacion != null ? pctFmt(r.porc_part_facturacion) : '—',
          val: r => r.porc_part_facturacion ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'porc_part_facturacion', heatmap: true },
        { key: 'tickets',               label: 'Tickets',
          fmt: r => numFmt(r.tickets),
          val: r => r.tickets ?? null,
          xlFmt: 'num',   align: 'right', sortKey: 'tickets' },
        { key: 'var_tickets',           label: 'Var. Tick.',
          fmt: r => r.var_tickets != null ? varFmt(r.var_tickets) : '—',
          val: r => r.var_tickets ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'var_tickets', varCls: true },
        { key: 'ticket_promedio',       label: 'T. Prom.',
          fmt: r => money(r.ticket_promedio),
          val: r => r.ticket_promedio != null ? _conv(r.ticket_promedio) : null,
          xlFmt: 'money', align: 'right', sortKey: 'ticket_promedio' },
        { key: 'porc_2do',              label: 'Tick 2do%',
          fmt: r => r.porc_2do != null ? pctFmt(r.porc_2do) : '—',
          val: r => r.porc_2do ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'porc_2do',    heatmap: true },
        { key: 'porc_3ro',              label: 'Tick 3ro%',
          fmt: r => r.porc_3ro != null ? pctFmt(r.porc_3ro) : '—',
          val: r => r.porc_3ro ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'porc_3ro',    heatmap: true },
        { key: 'porc_cambios',          label: '% Cambios',
          fmt: r => r.porc_cambios != null ? pctFmt(r.porc_cambios) : '—',
          val: r => r.porc_cambios ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'porc_cambios',   heatmap: true, inverse: true },
        { key: 'porc_incremental',      label: '% Increm.',
          fmt: r => r.porc_incremental != null ? pctFmt(r.porc_incremental) : '—',
          val: r => r.porc_incremental ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'porc_incremental', heatmap: true },
        { key: 'mails_pct',             label: '% Mails',
          fmt: r => r.mails_pct != null ? pctFmt(r.mails_pct) : '—',
          val: r => r.mails_pct ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'mails_pct', heatmap: true },
        { key: 'conversion',            label: 'Conv.%',
          fmt: r => r.conversion != null ? pctFmt(r.conversion) : '—',
          val: r => r.conversion ?? null,
          xlFmt: 'pct',   align: 'right', sortKey: 'conversion', heatmap: true },
    ];

    /* ── Heatmap color ───────────────────────── */
    function heatColor(ratio, inverse = false) {
        const r = inverse ? (1 - (ratio ?? 0)) : (ratio ?? 0);
        if (r < 0.33) return `rgba(220,38,38,${0.12 + r * 0.35})`;
        if (r < 0.66) return `rgba(245,158,11,${0.12 + r * 0.25})`;
        return `rgba(22,163,74,${0.12 + r * 0.30})`;
    }

    /* ── Exportar a Excel ────────────────────── */
    function exportarCadena() {
        if (!_lastData?.length || typeof ExcelExporter === 'undefined') return;
        let rows = _lastData;
        if (typeof Dashboard !== 'undefined' && Dashboard.isSoloActivas?.()) {
            const ids = Dashboard.getSucursalesActivasIds?.();
            if (ids?.size) rows = rows.filter(r => ids.has(+r.nro_sucurs));
        }

        // Construir fila de totales igual que en renderTable
        const totObj      = rows.reduce((s, r) => s + (r.objetivo_total   ?? 0), 0);
        const totFact     = rows.reduce((s, r) => s + (r.facturacion      ?? 0), 0);
        const totUnid     = rows.reduce((s, r) => s + (r.unidades         ?? 0), 0);
        const totTick     = rows.reduce((s, r) => s + (r.tickets          ?? 0), 0);
        const totTP       = totTick > 0 ? totFact / totTick : 0;
        const tot2doRaw   = rows.reduce((s, r) => s + (r.porc_2do         ?? 0) * (r.tickets  ?? 0), 0);
        const tot3roRaw   = rows.reduce((s, r) => s + (r.porc_3ro         ?? 0) * (r.tickets  ?? 0), 0);
        const totCambRaw  = rows.reduce((s, r) => s + (r.porc_cambios     ?? 0) * (r.unidades ?? 0), 0);
        const totIncrRaw  = rows.reduce((s, r) => s + (r.porc_incremental ?? 0) * (r.unidades ?? 0), 0);
        const totMailsRaw = rows.reduce((s, r) => s + (r.mails_pct        ?? 0) * (r.tickets  ?? 0), 0);
        const totIngresos = rows.reduce((s, r) => s + ((r.ingresos ?? 0) > 0 ? r.ingresos : 0), 0);
        const totTickConv = rows.reduce((s, r) => (r.ingresos ?? 0) > 0 ? s + ((r.conversion ?? 0) * r.ingresos) : s, 0);
        // Variaciones: usar totales de cadena (incluye sucursales cerradas del período previo)
        const cFactAct  = _chainTotals?.facturacion      ?? totFact;
        const cFactPrev = _chainTotals?.facturacion_prev ?? 0;
        const cUnidAct  = _chainTotals?.unidades         ?? totUnid;
        const cUnidPrev = _chainTotals?.unidades_prev    ?? 0;
        const cTickAct  = _chainTotals?.tickets          ?? totTick;
        const cTickPrev = _chainTotals?.tickets_prev     ?? 0;
        const totRow = {
            _isTotal: true, nro_sucurs: -1,
            objetivo_total: totObj, facturacion: totFact,
            var_facturacion: cFactPrev > 0 ? (cFactAct - cFactPrev) / cFactPrev : null,
            porc_cumplimiento: totObj > 0 ? totFact / totObj : null,
            unidades: totUnid,
            var_unidades: cUnidPrev > 0 ? (cUnidAct - cUnidPrev) / cUnidPrev : null,
            porc_part_facturacion: null,
            tickets: totTick,
            var_tickets: cTickPrev > 0 ? (cTickAct - cTickPrev) / cTickPrev : null,
            ticket_promedio: totTP,
            porc_2do:         totTick > 0 ? tot2doRaw  / totTick : null,
            porc_3ro:         totTick > 0 ? tot3roRaw  / totTick : null,
            porc_cambios:     totUnid > 0 ? totCambRaw / totUnid : null,
            porc_incremental: totUnid > 0 ? totIncrRaw / totUnid : null,
            mails_pct:        totTick > 0 ? totMailsRaw / totTick : null,
            conversion:       totIngresos > 0 ? totTickConv / totIngresos : null,
        };

        ExcelExporter.export({
            title      : 'KPIs por Sucursal — Cadena completa',
            headers    : COLS.map(c => c.label),
            rows       : rows.map(r => COLS.map(c => c.val(r))),
            totalsRow  : COLS.map(c => c.val(totRow)),
            colFormats : COLS.map(c => c.xlFmt),
            filename   : 'kpis_cadena',
        });
    }

    /* ── Render tabla ────────────────────────── */
    function renderTable(rows) {
        // Filtro "solo activas" (client-side)
        if (typeof Dashboard !== 'undefined' && Dashboard.isSoloActivas?.()) {
            const ids = Dashboard.getSucursalesActivasIds?.();
            if (ids?.size) rows = (rows ?? []).filter(r => ids.has(+r.nro_sucurs));
        }

        const wrap = document.getElementById('cadena-wrap');
        if (!wrap) return;
        if (!rows?.length) {
            wrap.innerHTML = '<div style="padding:24px;color:var(--text-3);font-size:.85rem">Sin datos para este período y filtros.</div>';
            return;
        }

        // Calcular rangos para heatmap por columna
        const ranges = {};
        COLS.filter(c => c.heatmap).forEach(c => {
            const vals = rows.map(r => r[c.sortKey] ?? 0).filter(v => v !== null);
            ranges[c.sortKey] = { min: Math.min(...vals), max: Math.max(...vals) };
        });

        // Ordenar si hay sortCol
        let sorted = [...rows];
        if (_sortCol) {
            sorted.sort((a, b) => {
                const av = a[_sortCol] ?? 0, bv = b[_sortCol] ?? 0;
                return _sortAsc ? av - bv : bv - av;
            });
        }

        const thHtml = COLS.map(c => {
            const arrow = _sortCol === c.sortKey ? (_sortAsc ? ' ▲' : ' ▼') : '';
            return `<th style="text-align:${c.align};cursor:pointer;white-space:nowrap" data-sort="${c.sortKey}">${c.label}${arrow}</th>`;
        }).join('');

        const tdRows = sorted.map(r => {
            const cells = COLS.map(c => {
                let style = `text-align:${c.align};`;
                let cls   = '';
                const val = r[c.sortKey] ?? 0;

                if (!r._isTotal && c.cumpl && r[c.sortKey] != null) {
                    style += `background:${val >= 1 ? '#3CB371' : '#FF4500'};color:#fff;font-weight:600;`;
                } else if (!r._isTotal && c.heatmap && r[c.sortKey] != null) {
                    const { min, max } = ranges[c.sortKey] || {};
                    const ratio = max > min ? (val - min) / (max - min) : 0.5;
                    style += `background:${heatColor(ratio, c.inverse)};`;
                } else if (c.varCls && r[c.sortKey] != null) {
                    cls = val >= 0 ? 'pos' : 'neg';
                }
                return `<td style="${style}" class="${cls}">${c.fmt(r)}</td>`;
            }).join('');
            const rowStyle = r._isTotal ? 'font-weight:700;border-top:2px solid var(--border);background:var(--surface-1,var(--bg-card2))' : '';
            return `<tr style="${rowStyle}">${cells}</tr>`;
        }).join('');

        // Fila totales
        const totObj      = rows.reduce((s, r) => s + (r.objetivo_total    ?? 0), 0);
        const totFact     = rows.reduce((s, r) => s + (r.facturacion       ?? 0), 0);
        const totUnid     = rows.reduce((s, r) => s + (r.unidades          ?? 0), 0);
        const totTick     = rows.reduce((s, r) => s + (r.tickets           ?? 0), 0);
        const totTP       = totTick > 0 ? totFact / totTick : 0;

        // Promedios ponderados para KPIs de %
        const tot2doRaw   = rows.reduce((s, r) => s + (r.porc_2do        ?? 0) * (r.tickets  ?? 0), 0);
        const tot3roRaw   = rows.reduce((s, r) => s + (r.porc_3ro        ?? 0) * (r.tickets  ?? 0), 0);
        const totCambRaw  = rows.reduce((s, r) => s + (r.porc_cambios    ?? 0) * (r.unidades ?? 0), 0);
        const totIncrRaw  = rows.reduce((s, r) => s + (r.porc_incremental?? 0) * (r.unidades ?? 0), 0);
        const totMailsRaw = rows.reduce((s, r) => s + (r.mails_pct       ?? 0) * (r.tickets  ?? 0), 0);

        // Conversión: solo sucursales con ingresos para no diluir la tasa
        const totIngresos = rows.reduce((s, r) => s + ((r.ingresos ?? 0) > 0 ? r.ingresos : 0), 0);
        const totTickConv = rows.reduce((s, r) => (r.ingresos ?? 0) > 0 ? s + ((r.conversion ?? 0) * r.ingresos) : s, 0);

        // Variaciones: usar totales de cadena (incluye sucursales cerradas del período previo)
        const cFactAct  = _chainTotals?.facturacion      ?? totFact;
        const cFactPrev = _chainTotals?.facturacion_prev ?? 0;
        const cUnidAct  = _chainTotals?.unidades         ?? totUnid;
        const cUnidPrev = _chainTotals?.unidades_prev    ?? 0;
        const cTickAct  = _chainTotals?.tickets          ?? totTick;
        const cTickPrev = _chainTotals?.tickets_prev     ?? 0;

        const totRow  = {
            _isTotal: true,
            nro_sucurs: -1,
            objetivo_total: totObj,
            objetivo_fecha: totObj,
            facturacion: totFact,
            var_facturacion: cFactPrev > 0 ? (cFactAct - cFactPrev) / cFactPrev : null,
            porc_cumplimiento: totObj > 0 ? totFact / totObj : null,
            unidades: totUnid,
            var_unidades: cUnidPrev > 0 ? (cUnidAct - cUnidPrev) / cUnidPrev : null,
            porc_part_facturacion: null,
            tickets: totTick,
            var_tickets: cTickPrev > 0 ? (cTickAct - cTickPrev) / cTickPrev : null,
            ticket_promedio: totTP,
            ticket_prom_prev: null,
            porc_2do:         totTick > 0 ? tot2doRaw  / totTick : null,
            porc_3ro:         totTick > 0 ? tot3roRaw  / totTick : null,
            porc_cambios:     totUnid > 0 ? totCambRaw / totUnid : null,
            porc_incremental: totUnid > 0 ? totIncrRaw / totUnid : null,
            mails_pct:        totTick > 0 ? totMailsRaw / totTick : null,
            conversion:       totIngresos > 0 ? totTickConv / totIngresos : null,
        };
        const totCells = COLS.map(c => {
            const style = `text-align:${c.align};`;
            return `<td style="${style}">${c.fmt(totRow)}</td>`;
        }).join('');
        const totRowHtml = `<tr style="font-weight:700;border-top:2px solid var(--border);background:var(--surface-1,var(--bg-card2))">${totCells}</tr>`;

        wrap.innerHTML = `
            <table class="cadena-table">
                <thead><tr>${thHtml}</tr></thead>
                <tbody>${tdRows}${totRowHtml}</tbody>
            </table>`;

        // Ordenar al hacer clic en cabecera
        wrap.querySelectorAll('th[data-sort]').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.sort;
                if (_sortCol === col) _sortAsc = !_sortAsc;
                else { _sortCol = col; _sortAsc = false; }
                renderTable(_lastData);
            });
        });
    }

    /* ── Carga ───────────────────────────────── */
    async function loadAll() {
        const wrap = document.getElementById('cadena-wrap');
        if (wrap) wrap.innerHTML = '<div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>';
        document.body.classList.add('is-loading');
        Spinner.show('Cargando cadena...');

        try {
            const qs  = Dashboard.buildQS();
            const res = await fetch(`/bi/global/api/cadena.php?${qs}`);
            if (!res.ok) throw new Error(`Error ${res.status}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Error en cadena');

            _lastData    = data.sucursales    ?? [];
            _chainTotals = data.totales_cadena ?? null;
            renderTable(_lastData);

            // Botón de exportación (una sola vez en el header)
            if (typeof ExcelExporter !== 'undefined') {
                const headerEl = document.querySelector('#tab-cadena .analisis-section-header');
                ExcelExporter.addExportButton(headerEl, exportarCadena);
            }
        } catch(e) {
            const wrap = document.getElementById('cadena-wrap');
            if (wrap) wrap.innerHTML = `<div style="padding:20px;color:var(--neg);font-size:.85rem"><i class="bi bi-exclamation-triangle"></i> ${e.message}</div>`;
            console.error('[Cadena]', e);
        } finally {
            document.body.classList.remove('is-loading');
            Spinner.hide();
        }
    }

    return { loadAll };
})();
