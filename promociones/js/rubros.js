/**
 * /bi/promociones/js/rubros.js
 * Unidades x Rubro — penetración de las promociones sobre las unidades vendidas.
 *
 * Reutiliza el render genérico de PromoDetalle (tabla ordenable + fila Total +
 * export a Excel); acá sólo se declaran las columnas y se hace el fetch.
 *
 * Expone: PromoRubros.load(), PromoRubros.onMonedaChange()
 */
const PromoRubros = (() => {

    const $ = id => document.getElementById(id);

    /* Estado */
    let _lastRubros = null;
    let _sort       = { key: 'unid_cpromo', dir: -1 };

    /* ── Helpers de formato delegados ── */
    function moneyInt(n) { return Promociones.fmt?.money(n, 0) ?? '—'; }
    function pct(n, d=1) { return n == null ? '—' : Promociones.fmt?.pct(n, d) ?? (n * 100).toFixed(d) + ' %'; }
    function num(n)      { return n == null ? '—' : Promociones.fmt?.num(n)   ?? Number(n).toLocaleString('es-AR'); }
    function varPct(n)   { return Promociones.fmt?.varPct(n) ?? '—'; }

    /* ── COLUMNAS ──
     * pct_penetracion y var_unid_cp llevan recalcTotal porque son ratios:
     * la fila Total no puede sumarlos, tiene que recalcularlos sobre los
     * subtotales ya acumulados.
     */
    const COLS_RUBRO = [
        { key: 'rubro',            label: 'Rubro',          align: 'left',  fmt: v => v ?? '—', xlFmt: null,     sortKey: 'rubro',
          tip: 'Rubro del artículo vendido. Se excluyen CONCEPTO y PACKAGING, que no son unidades de producto.' },
        { key: 'unid_total',       label: 'Unid. Totales',  align: 'right', fmt: num,           xlFmt: 'number', sortKey: 'unid_total',
          tip: 'Unidades vendidas del rubro en el período, con y sin promoción.' },
        { key: 'unid_cpromo',      label: 'Unid. C/Promo',  align: 'right', fmt: num,           xlFmt: 'number', sortKey: 'unid_cpromo',
          tip: 'Unidades vendidas en tickets que llevaron promoción. La promo aplica al TICKET, no al artículo: cuentan todas las unidades de ese ticket, no sólo las promocionadas.' },
        {
            key: 'pct_penetracion', label: '% C/Promo',     align: 'right', fmt: n => pct(n, 1), xlFmt: 'pct1',  sortKey: 'pct_penetracion',
            tip: 'Unid. C/Promo sobre Unid. Totales: qué parte del rubro se vendió en tickets con promoción.',
            recalcTotal: t => (t.unid_total > 0 ? t.unid_cpromo / t.unid_total : 0),
        },
        { key: 'fact_cpromo',      label: 'Fact. C/Promo',  align: 'right', fmt: moneyInt,      xlFmt: 'money',  sortKey: 'fact_cpromo',
          tip: 'Facturación de esas unidades. No coincide exacto con el KPI del Resumen: acá se suman líneas de venta y allá totales de ticket.' },
        { key: 'unid_cpromo_prev', label: 'Unid. C/P prev', align: 'right', fmt: num,           xlFmt: 'number', sortKey: 'unid_cpromo_prev',
          tip: 'Unid. C/Promo en el mismo período del año anterior.' },
        {
            key: 'var_unid_cp',     label: 'Var. Unid.',    align: 'right', fmt: varPct,        xlFmt: 'pct1',   sortKey: 'var_unid_cp',
            tip: 'Crecimiento de las unidades con promo contra el año anterior. Un +100% suele significar que la promoción filtrada no existía con ese nombre.',
            recalcTotal: t => (t.unid_cpromo_prev > 0
                ? (t.unid_cpromo - t.unid_cpromo_prev) / t.unid_cpromo_prev
                : (t.unid_cpromo > 0 ? 1 : 0)),
        },
    ];

    /* ── Carga ── */
    async function load() {
        const wrap = $('rubros-wrap');
        if (wrap) wrap.innerHTML = '<div class="promo-loading">Cargando…</div>';

        try {
            const data = await fetch(`/bi/promociones/api/rubros.php?${Promociones.buildQS()}`).then(r => r.json());
            if (!data.ok) throw new Error(data.error ?? 'Error en unidades por rubro');

            _lastRubros = data.rubros ?? [];
            render();
        } catch (e) {
            console.error('[PromoRubros] load:', e);
            if (wrap) wrap.innerHTML = `<div class="promo-loading" style="color:var(--red)">Error: ${e.message}</div>`;
        }
    }

    function render() {
        PromoDetalle.renderTabla(
            'rubros-wrap',
            _lastRubros,
            COLS_RUBRO,
            _sort,
            key => { _sort = { key, dir: _sort.key === key ? -_sort.dir : -1 }; render(); },
            exportRubros,
            true,
        );
        PromoDetalle.bindExportBtn('btn-export-rubros', exportRubros);
    }

    function exportRubros() {
        if (!_lastRubros?.length) return;
        const headers = COLS_RUBRO.map(c => c.label);
        const colFmts = COLS_RUBRO.map(c => c.xlFmt);
        const rows    = _lastRubros.map(r => COLS_RUBRO.map(c => r[c.key] ?? null));
        PromoDetalle.exportarExcel('Unidades por Rubro - Promociones', headers, rows, colFmts, 'promo_unidades_rubro');
    }

    /* Reactualizar moneda sin nueva carga */
    function onMonedaChange() {
        if (_lastRubros) render();
    }

    return {
        load,
        onMonedaChange,
    };
})();
