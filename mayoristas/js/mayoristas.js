/* /bi/mayoristas/js/mayoristas.js
   Módulo IIFE — Dashboard Ventas Mayoristas
   Stack: jQuery 3.7, Chart.js 4.4, SheetJS 0.18
   ============================================================ */
;(function ($) {
    'use strict';

    // ── Estado global ────────────────────────────────────────────
    const State = {
        periodo   : 'año_actual',
        desde     : '',
        hasta     : '',
        compMode  : 'year_ago',  // 'year_ago' | 'custom'
        compDesde : '',
        compHasta : '',
        vendedor  : '',
        rubro     : '',
        categoria : '',
        region    : '',
        provincia : '',
        activeTab : 'kpis',
        evolMode  : 'anio',      // 'anio' | 'rubro'
        evolAnio  : null,        // año seleccionado en modo rubro
    };

    const Cache = {
        kpis         : null,
        clientes     : null,
        rubros_comp  : null,
        evolucion    : null,
        evolucion_rub: null,
        matriz       : null,
    };

    let chartRubrosPie  = null;
    let chartEvolucion  = null;
    let chartEvolRubro  = null;

    const PALETTE = [
        '#00a878','#2563eb','#f59e0b','#ef4444','#8b5cf6',
        '#06b6d4','#f97316','#10b981','#6366f1','#ec4899',
        '#14b8a6','#a855f7','#84cc16','#f43f5e','#0ea5e9',
    ];

    // ── Formato numérico español rioplatense ─────────────────────
    const fmt = {
        num(v, dec = 0) {
            if (v == null || isNaN(v) || !isFinite(v)) return '—';
            return Number(v).toLocaleString('es-AR', {
                minimumFractionDigits: dec,
                maximumFractionDigits: dec,
            });
        },
        pct(v, dec = 1) {
            if (v == null || isNaN(v) || !isFinite(v)) return '—';
            return Number(v).toLocaleString('es-AR', {
                minimumFractionDigits: dec,
                maximumFractionDigits: dec,
            }) + '%';
        },
        var(v) {
            if (v == null || isNaN(v) || !isFinite(v)) return { text: '—', cls: 'neu' };
            const pct = v * 100;
            if (pct > 0.05)  return { text: '▲ ' + fmt.num(pct, 1) + '%',           cls: 'pos' };
            if (pct < -0.05) return { text: '▼ ' + fmt.num(Math.abs(pct), 1) + '%', cls: 'neg' };
            return { text: '0,0%', cls: 'neu' };
        },
    };

    // ── Overlay ───────────────────────────────────────────────────
    function showOverlay() { $('#loading-overlay').removeAttr('hidden'); $('body').addClass('is-loading'); }
    function hideOverlay() { $('#loading-overlay').attr('hidden', '');   $('body').removeClass('is-loading'); }
    function setReloadSpin(on) { $('#btn-reload').toggleClass('spinning', on); }

    // ── Fetch helpers ─────────────────────────────────────────────
    function buildQS(extra = {}) {
        const p = {
            periodo  : State.periodo,
            vendedor : State.vendedor,
            rubro    : State.rubro,
            categoria: State.categoria,
            region   : State.region,
            provincia: State.provincia,
            cliente  : State.cliente || '',
            ...extra,
        };
        if (State.periodo === 'custom') {
            p.desde     = State.desde;
            p.hasta     = State.hasta;
            p.comp_mode = State.compMode;
            if (State.compMode === 'custom') {
                p.desde_comp = State.compDesde;
                p.hasta_comp = State.compHasta;
            }
        }
        return $.param(p);
    }

    async function apiFetch(endpoint, extra = {}) {
        const url  = '/bi/mayoristas/api/' + endpoint + '.php?' + buildQS(extra);
        const res  = await fetch(url);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Error desconocido');
        return json;
    }

    // ── Selectores de filtros ─────────────────────────────────────
    async function initFiltros() {
        try {
            const res  = await fetch('/bi/mayoristas/api/filtros.php');
            const json = await res.json();
            if (!json.ok) return;
            poblarSelect('#sel-vendedor',  json.vendedores, 'Todos');
            poblarSelect('#sel-rubro',     json.rubros,     'Todos');
            const catSelect = initSearchableSelect(json.categorias);
            const catXRubro = json.categorias_x_rubro || {};
            $('#sel-rubro').on('change', function() {
                const rubro = $(this).val();
                catSelect.update(rubro && catXRubro[rubro] ? catXRubro[rubro] : json.categorias);
            });
            poblarSelect('#sel-region',    json.regiones,   'Todas');
            poblarSelect('#sel-provincia', json.provincias, 'Todas');
            poblarSelect('#sel-cliente',   json.clientes,   'Todos');
        } catch (e) { console.error('Error filtros:', e); }
    }

    function poblarSelect(sel, items, ph) {
        const $s = $(sel).empty().append($('<option>').val('').text(ph));
        (items || []).forEach(v => $s.append($('<option>').val(v).text(v)));
    }

    function initSearchableSelect(initialItems) {
        const $input    = $('#cat-input');
        const $hidden   = $('#sel-categoria');
        const $dropdown = $('#cat-dropdown');
        let currentItems  = initialItems || [];
        let selectedVal   = '';
        let selectedLabel = '';

        function render(filter) {
            $dropdown.empty();
            const f = (filter || '').toLowerCase();
            const opts = [{ val: '', label: 'Todas' }]
                .concat(currentItems.map(v => ({ val: v, label: v })))
                .filter(o => o.val === '' || o.label.toLowerCase().includes(f));
            if (!opts.length) {
                $dropdown.append($('<li>').addClass('no-results').text('Sin resultados'));
            } else {
                opts.forEach(o => {
                    $dropdown.append(
                        $('<li>').text(o.label).attr('data-val', o.val)
                            .toggleClass('selected', o.val === selectedVal)
                    );
                });
            }
        }

        function applySelection(val, label) {
            selectedVal   = val;
            selectedLabel = label;
            $hidden.val(val);
            $input.val(val ? label : '').attr('placeholder', val ? '' : 'Todas');
            $dropdown.prop('hidden', true);
        }

        $input.on('focus', function() {
            render($(this).val());
            $dropdown.prop('hidden', false);
        }).on('input', function() {
            render($(this).val());
            $dropdown.prop('hidden', false);
        });

        $dropdown.on('mousedown', 'li', function(e) {
            e.preventDefault();
            if (!$(this).hasClass('no-results')) {
                applySelection($(this).attr('data-val'), $(this).text());
            }
        });

        $(document).off('click.catSearch').on('click.catSearch', function(e) {
            if (!$(e.target).closest('#cat-wrap').length) {
                $dropdown.prop('hidden', true);
                $input.val(selectedVal ? selectedLabel : '').attr('placeholder', selectedVal ? '' : 'Todas');
            }
        });

        return {
            update(newItems) {
                currentItems = newItems || [];
                // Si la selección actual ya no existe en la nueva lista, limpiar
                if (selectedVal && !currentItems.includes(selectedVal)) {
                    applySelection('', '');
                }
                $input.val('').attr('placeholder', selectedVal ? selectedLabel : 'Todas');
                $dropdown.prop('hidden', true);
            }
        };
    }

    async function reloadProvincias(region) {
        try {
            const json = await (await fetch('/bi/mayoristas/api/filtros.php?' + $.param({ region }))).json();
            if (json.ok) poblarSelect('#sel-provincia', json.provincias, 'Todas');
        } catch (e) { /* silencioso */ }
    }

    // ── Label período topbar ──────────────────────────────────────
    function updatePeriodoLabel(periodo) {
        if (!periodo) return;
        const f = d => d ? d.split('-').reverse().join('/') : '—';
        $('#periodo-label').text(
            f(periodo.desde) + ' – ' + f(periodo.hasta) +
            '  ·  vs ' + f(periodo.desde_prev) + ' – ' + f(periodo.hasta_prev)
        );
    }

    // ── TAB: Resumen — KPIs ───────────────────────────────────────
    async function loadKPIs() {
        if (Cache.kpis) { renderKPIs(Cache.kpis); return; }
        try {
            Cache.kpis = await apiFetch('kpis');
            renderKPIs(Cache.kpis);
        } catch (e) { $('#kpi-grid').html(errorHTML(e.message)); }
    }

    function renderKPIs(res) {
        const d = res.data;
        updatePeriodoLabel(d.periodo || res.periodo);
        setKPI('#kv-unidades', '#kvar-unidades', d.unidades_act, d.var_unidades, v => fmt.num(v));
        setKPI('#kv-clientes', '#kvar-clientes', d.clientes_act, d.var_clientes, v => fmt.num(v));
        setKPI('#kv-prom',     '#kvar-prom',     d.prom_act,     d.var_prom,     v => fmt.num(v, 1));
        setKPI('#kv-rubros',   '#kvar-rubros',   d.rubros_act,   d.var_rubros,   v => fmt.num(v));
        $('.kpi-card').removeClass('skeleton');
    }

    function setKPI(vSel, varSel, val, varV, fmtFn) {
        $(vSel).text(fmtFn(val));
        const { text, cls } = fmt.var(varV);
        $(varSel).text(text).attr('class', 'kpi-var ' + cls);
    }

    // ── Pie de rubros (Resumen) ───────────────────────────────────
    async function loadRubrosPie() {
        try {
            const res = Cache.evolucion || await apiFetch('evolucion');
            Cache.evolucion = res;
            renderRubrosPie(res.rubros);
            populateEvolAnioSelect(res.anios);
        } catch (e) { $('#rubros-chart-wrap').html(errorHTML(e.message)); }
    }

    function renderRubrosPie(rubros) {
        if (!rubros || !rubros.length) {
            if (chartRubrosPie) { chartRubrosPie.destroy(); chartRubrosPie = null; }
            $('#rubros-chart-wrap').html(emptyState('Sin datos de rubros para el período'));
            return;
        }
        if (chartRubrosPie) { chartRubrosPie.destroy(); chartRubrosPie = null; }
        $('#rubros-chart-wrap').html(
            '<div class="rubros-pie-container"><canvas id="chart-rubros-pie"></canvas></div>' +
            '<div class="rubros-legend" id="rubros-legend"></div>'
        );

        const labels = rubros.map(r => r.RUBRO);
        const datos  = rubros.map(r => parseFloat(r.porc_unidades) || 0);
        const colors = labels.map((_, i) => PALETTE[i % PALETTE.length]);

        chartRubrosPie = new Chart(
            document.getElementById('chart-rubros-pie').getContext('2d'),
            {
                type: 'doughnut',
                data: { labels, datasets: [{ data: datos, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '60%',
                    plugins: {
                        legend : { display: false },
                        tooltip: { callbacks: { label: ctx => ' ' + fmt.pct(ctx.parsed) } },
                    },
                },
            }
        );

        const $leg = $('#rubros-legend').empty();
        rubros.forEach((r, i) => {
            $leg.append(
                $('<div class="rubros-legend-item">').append(
                    $('<div class="rubros-legend-dot">').css('background', colors[i]),
                    $('<span class="rubros-legend-name">').text(r.RUBRO),
                    $('<span class="rubros-legend-pct">').text(fmt.pct(r.porc_unidades)),
                    $('<span class="rubros-legend-importe">').text(fmt.num(r.unidades) + ' u.'),
                )
            );
        });
    }

    // ── TAB: Rubros — Comparativa 3 años ─────────────────────────
    async function loadComparativaRubros() {
        if (Cache.rubros_comp) { renderComparativaRubros(Cache.rubros_comp); return; }
        $('#wrap-tabla-rubros-comp').html(loadingHTML());
        try {
            Cache.rubros_comp = await apiFetch('comparativa');
            renderComparativaRubros(Cache.rubros_comp);
        } catch (e) { $('#wrap-tabla-rubros-comp').html(errorHTML(e.message)); }
    }

    function renderComparativaRubros(res) {
        const rows = res.data    || [];
        const p    = res.periodo || {};
        const $wrap = $('#wrap-tabla-rubros-comp');

        if (!rows.length) { $wrap.html(emptyState('Sin datos para los filtros seleccionados')); return; }

        const f    = d => d ? d.split('-').reverse().join('/') : '—';
        const anio1  = p.desde       ? p.desde.substring(0,4)       : '—';
        const anio2  = p.desde_prev  ? p.desde_prev.substring(0,4)  : '—';
        const anio3  = p.desde_prev2 ? p.desde_prev2.substring(0,4) : '—';

        // Actualizar header del card
        $('#rubros-header-title').text(
            'Comparativa por Rubro — ' + anio1 + ' vs ' + anio2 + ' vs ' + anio3
        );

        const $tbl = $('<table>');
        $tbl.append($('<thead>').append(
            $('<tr>').append(
                th('Rubro',                  true),
                th('Unid. ' + anio1,        true,  'col-num'),
                th('Unid. ' + anio2,        true,  'col-num'),
                th('% Var. vs ' + anio2,    false, 'col-num'),
                th('Unid. ' + anio3,        true,  'col-num'),
                th('% Var. vs ' + anio3,    false, 'col-num'),
            )
        ));

        const $tbody = $('<tbody>');
        let totA = 0, totP1 = 0, totP2 = 0;

        rows.forEach(r => {
            const ua  = parseFloat(r.unidades_act)   || 0;
            const up1 = parseFloat(r.unidades_prev)  || 0;
            const up2 = parseFloat(r.unidades_prev2) || 0;
            totA += ua; totP1 += up1; totP2 += up2;

            const v1 = fmt.var(r.var_vs_prev);
            const v2 = fmt.var(r.var_vs_prev2);

            $tbody.append($('<tr>').append(
                td(r.RUBRO),
                td(fmt.num(ua),  'col-num'),
                td(fmt.num(up1), 'col-num'),
                $('<td>').text(v1.text).addClass('col-num var-' + v1.cls),
                td(fmt.num(up2), 'col-num'),
                $('<td>').text(v2.text).addClass('col-num var-' + v2.cls),
            ));
        });
        $tbl.append($tbody);

        const vt1 = totP1 ? (totA - totP1) / Math.abs(totP1) : null;
        const vt2 = totP2 ? (totA - totP2) / Math.abs(totP2) : null;
        const vT1 = fmt.var(vt1), vT2 = fmt.var(vt2);

        $tbl.append($('<tfoot>').append(
            $('<tr>').append(
                $('<td>').text('TOTAL'),
                td(fmt.num(totA),  'col-num'),
                td(fmt.num(totP1), 'col-num'),
                $('<td>').text(vT1.text).addClass('col-num var-' + vT1.cls),
                td(fmt.num(totP2), 'col-num'),
                $('<td>').text(vT2.text).addClass('col-num var-' + vT2.cls),
            )
        ));

        $wrap.html('').append($tbl);
        initSort($tbl);
    }

    // ── TAB: Clientes ─────────────────────────────────────────────
    async function loadTablaClientes() {
        if (Cache.clientes) { renderTablaClientes(Cache.clientes); return; }
        $('#wrap-tabla-clientes').html(loadingHTML());
        try {
            Cache.clientes = await apiFetch('tabla_clientes');
            renderTablaClientes(Cache.clientes);
        } catch (e) { $('#wrap-tabla-clientes').html(errorHTML(e.message)); }
    }

    function renderTablaClientes(res) {
        const rows = res.data    || [];
        const tot  = res.totales || {};
        const $wrap = $('#wrap-tabla-clientes');

        if (!rows.length) { $wrap.html(emptyState('Sin datos para los filtros seleccionados')); return; }

        const $tbl = $('<table>');
        $tbl.append($('<thead>').append(
            $('<tr>').append(
                th('#',                false, 'col-num'),
                th('Cliente',         true),
                th('Vendedor',        true),
                th('Región',          true),
                th('Provincia',       true),
                th('Unidades',        true,  'col-num'),
                th('Rubros',          true,  'col-num'),
                th('Días activo',     true,  'col-num'),
                th('% Participación', false, 'col-num'),
            )
        ));

        const $tbody = $('<tbody>');
        rows.forEach((r, i) => {
            $tbody.append($('<tr>').append(
                td(i + 1,                          'col-num'),
                td(r.CLIENTE),
                td(r.VENDEDOR),
                td(r.REGION),
                td(r.PROVINCIA),
                td(fmt.num(r.unidades),            'col-num'),
                td(fmt.num(r.rubros_distintos),    'col-num'),
                td(fmt.num(r.dias_activo),         'col-num'),
                tdBar(r.porc_part),
            ));
        });
        $tbl.append($tbody);

        $tbl.append($('<tfoot>').append(
            $('<tr>').append(
                $('<td colspan="5">').text('TOTAL (' + rows.length + ' clientes)'),
                td(fmt.num(tot.unidades), 'col-num'),
                $('<td colspan="3">'),
            )
        ));

        $wrap.html('').append($tbl);
        initSort($tbl);
    }

    // ── TAB: Vendedores ─────────────────────────────────────────
    async function loadTablaVendedores() {
        if (Cache.vendedores) { renderTablaVendedores(Cache.vendedores); return; }
        $('#wrap-tabla-vendedores').html(loadingHTML());
        try {
            Cache.vendedores = await apiFetch('vendedores');
            renderTablaVendedores(Cache.vendedores);
        } catch (e) { $('#wrap-tabla-vendedores').html(errorHTML(e.message)); }
    }

    function renderTablaVendedores(res) {
        const rows = res.data    || [];
        const tot  = res.totales || {};
        const $wrap = $('#wrap-tabla-vendedores');

        if (!rows.length) { $wrap.html(emptyState('Sin datos para los filtros seleccionados')); return; }

        const $tbl = $('<table>');
        $tbl.append($('<thead>').append(
            $('<tr>').append(
                th('#',                false, 'col-num'),
                th('Vendedor',        true),
                th('Unidades',        true,  'col-num'),
                th('Clientes',        true,  'col-num'),
                th('Rubros',          true,  'col-num'),
                th('Días activo',     true,  'col-num'),
                th('% Participación', false, 'col-num'),
            )
        ));

        const $tbody = $('<tbody>');
        rows.forEach((r, i) => {
            $tbody.append($('<tr>').append(
                td(i + 1,                       'col-num'),
                td(r.VENDEDOR),
                td(fmt.num(r.unidades),         'col-num'),
                td(fmt.num(r.clientes),         'col-num'),
                td(fmt.num(r.rubros_distintos), 'col-num'),
                td(fmt.num(r.dias_activo),      'col-num'),
                tdBar(r.porc_part),
            ));
        });
        $tbl.append($tbody);

        $tbl.append($('<tfoot>').append(
            $('<tr>').append(
                $('<td colspan="2">').text('TOTAL (' + rows.length + ' vendedores)'),
                td(fmt.num(tot.unidades), 'col-num'),
                td(fmt.num(tot.clientes), 'col-num'),
                $('<td colspan="3">'),
            )
        ));

        $wrap.html('').append($tbl);
        initSort($tbl);
    }

    // ── TAB: Matriz CLIENTE × RUBRO ───────────────────────────────
    async function loadMatriz() {
        const cliente  = State.cliente || '';
        const cacheKey = cliente ? 'mat_' + cliente : 'matriz';
        if (Cache[cacheKey]) { renderMatriz(Cache[cacheKey]); return; }
        $('#wrap-matriz').html(loadingHTML());
        try {
            const extra = cliente ? { cliente } : {};
            const res   = await apiFetch('matriz', extra);
            Cache[cacheKey] = res;
            renderMatriz(res);
        } catch (e) { $('#wrap-matriz').html(errorHTML(e.message)); }
    }

    function renderMatriz(res) {
        const $wrap = $('#wrap-matriz');

        if (res.mode === 'cliente') {
            const rubros = res.rubros || [];
            if (!rubros.length) { $wrap.html(emptyState('Sin datos para el cliente seleccionado')); return; }
            $('#matriz-cliente-info').text(res.cliente + ' — ' + fmt.num(res.total) + ' u. totales').show();
            const $tbl = $('<table>');
            $tbl.append($('<thead>').append(
                $('<tr>').append(
                    th('Rubro', true),
                    th('Unidades', true,  'col-num'),
                    th('% Participación', false, 'col-num'),
                )
            ));
            const $tbody2 = $('<tbody>');
            rubros.forEach(r => {
                $tbody2.append($('<tr>').append(
                    td(r.RUBRO),
                    td(fmt.num(r.unidades), 'col-num'),
                    tdBar(r.porc_unidades),
                ));
            });
            $tbl.append($tbody2);
            $tbl.append($('<tfoot>').append(
                $('<tr>').append(
                    $('<td>').text('TOTAL'),
                    td(fmt.num(res.total), 'col-num'),
                    $('<td>'),
                )
            ));
            $wrap.html('').append($tbl);
            initSort($tbl);
            return;
        }

        // mode === 'matriz'
        $('#matriz-cliente-info').hide();
        const clientes = res.clientes        || [];
        const totales  = res.totales_cliente || {};
        const filas    = res.filas           || [];

        if (!filas.length || !clientes.length) {
            $wrap.html(emptyState('Sin datos para los filtros seleccionados'));
            return;
        }

        // Populate client selector with actual clients from data
        // (selector interno eliminado — se usa el filtro global #sel-cliente)
        const current = State.cliente || '';

        const $tbl = $('<table class="tabla-matriz">', );

        // Header: RUBRO + clientes
        const $hRow = $('<tr>').append(
            $('<th class="th-rubro">').text('RUBRO'),
            $('<th class="th-total-rubro">').text('Total'),
        );
        clientes.forEach(c => {
            $hRow.append(
                $('<th class="th-cliente" colspan="2">').text(c)
            );
        });
        $tbl.append($('<thead>').append($hRow));

        // Sub-header: etiquetas de cada par Unid / %
        const $subRow = $('<tr class="subheader">').append(
            $('<th>'), $('<th>').text('Unid.'),
        );
        clientes.forEach(() => {
            $subRow.append(
                $('<th class="col-num">').text('Unid.'),
                $('<th class="col-pct">').text('%'),
            );
        });
        $tbl.append($('<thead>').append($subRow));

        // Body
        const $tbody = $('<tbody>');
        filas.forEach(fila => {
            const $tr = $('<tr>').append(
                $('<td class="td-rubro">').text(fila.rubro),
                $('<td class="col-num">').text(fmt.num(fila.total)),
            );
            clientes.forEach(c => {
                const cel = fila.clientes[c];
                if (cel) {
                    $tr.append(
                        $('<td class="col-num">').text(fmt.num(cel.unidades)),
                        $('<td class="col-pct">').text(fmt.pct(cel.porc)),
                    );
                } else {
                    $tr.append($('<td>').text('—'), $('<td>').text('—'));
                }
            });
            $tbody.append($tr);
        });
        $tbl.append($tbody);

        // Tfoot: totales por cliente
        const totRubros = filas.reduce((s, f) => s + f.total, 0);
        const $tfRow = $('<tr class="row-total">').append(
            $('<td>').text('TOTAL'),
            $('<td class="col-num">').text(fmt.num(totRubros)),
        );
        clientes.forEach(c => {
            const t = parseFloat(totales[c]) || 0;
            const pct = totRubros > 0 ? (t / totRubros * 100) : 0;
            $tfRow.append(
                $('<td class="col-num">').text(fmt.num(t)),
                $('<td class="col-pct">').text(fmt.pct(pct)),
            );
        });
        $tbl.append($('<tfoot>').append($tfRow));

        $wrap.html('').append($tbl);
    }

    // ── TAB: Evolución — multiaño ─────────────────────────────────
    async function loadEvolucion() {
        if (Cache.evolucion) { renderEvolucionAnio(Cache.evolucion); return; }
        $('#evol-chart-wrap').html(loadingHTML());
        try {
            Cache.evolucion = await apiFetch('evolucion');
            populateEvolAnioSelect(Cache.evolucion.anios);
            renderEvolucionAnio(Cache.evolucion);
        } catch (e) {
            $('#evol-chart-wrap').html(errorHTML(e.message));
        }
    }

    function populateEvolAnioSelect(anios) {
        if (!anios || !anios.length) return;
        const $sel = $('#sel-evol-anio').empty();
        [...anios].reverse().forEach(a => {
            $sel.append($('<option>').val(a).text(a));
        });
        if (!State.evolAnio) State.evolAnio = anios[anios.length - 1];
        $sel.val(State.evolAnio);
    }

    function renderEvolucionAnio(res) {
        if (!res.meses || !res.meses.length) {
            $('#evol-chart-wrap').html(emptyState('Sin datos de evolución'));
        } else {
            buildChartAnio(res.anios, res.meses);
        }
    }

    function buildChartAnio(anios, meses) {
        const labels   = meses.map(m => m.label);
        const datasets = anios.map((a, i) => ({
            label          : String(a),
            data           : meses.map(m => m['unidades_' + a] ?? null),
            borderColor    : PALETTE[i % PALETTE.length],
            backgroundColor: PALETTE[i % PALETTE.length] + '22',
            borderWidth    : 2.5,
            pointRadius    : 3,
            tension        : 0.35,
            fill           : false,
        }));

        if (chartEvolucion) { chartEvolucion.destroy(); chartEvolucion = null; }

        $('#evol-chart-wrap').html('<canvas id="chart-evolucion" height="320"></canvas>');
        chartEvolucion = new Chart(
            document.getElementById('chart-evolucion').getContext('2d'),
            {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive : true,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend : { position: 'top', labels: { font: { family: "'Barlow', sans-serif", size: 12 } } },
                        tooltip: {
                            itemSort: (a, b) => (b.raw ?? -Infinity) - (a.raw ?? -Infinity),
                            callbacks: { label: ctx => ctx.dataset.label + ': ' + (ctx.raw != null ? fmt.num(ctx.raw) + ' u.' : '—') },
                        },
                    },
                    scales: {
                        x: { grid: { color: 'rgba(0,0,0,.05)' } },
                        y: { grid: { color: 'rgba(0,0,0,.05)' }, ticks: { callback: v => v >= 1000 ? (v/1000).toFixed(0)+'K' : fmt.num(v) } },
                    },
                },
            }
        );
    }

    // ── TAB: Evolución — por Rubro ────────────────────────────────
    async function loadEvolucionRubro() {
        const anio = State.evolAnio || (new Date()).getFullYear();
        const cacheKey = 'evolucion_rub_' + anio;

        if (Cache[cacheKey]) { renderEvolucionRubro(Cache[cacheKey]); return; }

        $('#evol-rubro-chart-wrap').html(loadingHTML());
        try {
            const res = await apiFetch('evolucion_rubro', { anio });
            Cache[cacheKey] = res;
            renderEvolucionRubro(res);
        } catch (e) { $('#evol-rubro-chart-wrap').html(errorHTML(e.message)); }
    }

    function renderEvolucionRubro(res) {
        const anio   = res.anio    || State.evolAnio;
        const rubros = res.rubros  || [];
        const meses  = res.meses   || [];

        $('#evol-rubro-anio-label').text(anio);

        if (!meses.length) {
            $('#evol-rubro-chart-wrap').html(emptyState('Sin datos para el año ' + anio));
            return;
        }

        const labels   = meses.map(m => m.label);
        const datasets = rubros.map((r, i) => ({
            label          : r,
            data           : meses.map(m => m[r] ?? null),
            borderColor    : PALETTE[i % PALETTE.length],
            backgroundColor: PALETTE[i % PALETTE.length] + '22',
            borderWidth    : 2,
            pointRadius    : 3,
            tension        : 0.35,
            fill           : false,
        }));

        if (chartEvolRubro) { chartEvolRubro.destroy(); chartEvolRubro = null; }

        $('#evol-rubro-chart-wrap').html('<canvas id="chart-evolucion-rubro" height="320"></canvas>');
        chartEvolRubro = new Chart(
            document.getElementById('chart-evolucion-rubro').getContext('2d'),
            {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive : true,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend : { position: 'top', labels: { font: { family: "'Barlow', sans-serif", size: 11 }, boxWidth: 12 } },
                        tooltip: {
                            itemSort: (a, b) => (b.raw ?? -Infinity) - (a.raw ?? -Infinity),
                            callbacks: { label: ctx => ctx.dataset.label + ': ' + (ctx.raw != null ? fmt.num(ctx.raw) + ' u.' : '—') },
                        },
                    },
                    scales: {
                        x: { grid: { color: 'rgba(0,0,0,.05)' } },
                        y: { grid: { color: 'rgba(0,0,0,.05)' }, ticks: { callback: v => v >= 1000 ? (v/1000).toFixed(0)+'K' : fmt.num(v) } },
                    },
                },
            }
        );
    }

    // ── Tabla rubros participación ────────────────────────────────
    function renderTablaRubros(rubros) {
        const $wrap = $('#wrap-tabla-rubros');
        if (!rubros || !rubros.length) { $wrap.html(emptyState('Sin datos de rubros')); return; }

        const $tbl = $('<table>');
        $tbl.append($('<thead>').append(
            $('<tr>').append(
                th('Rubro',              true),
                th('Unidades',           true,  'col-num'),
                th('% Participación',    false, 'col-num'),
            )
        ));

        const $tbody = $('<tbody>');
        let totU = 0;
        rubros.forEach(r => {
            const u = parseFloat(r.unidades) || 0;
            totU += u;
            $tbody.append($('<tr>').append(
                td(r.RUBRO),
                td(fmt.num(u), 'col-num'),
                tdBar(r.porc_unidades),
            ));
        });
        $tbl.append($tbody);

        $tbl.append($('<tfoot>').append(
            $('<tr>').append(
                $('<td>').text('TOTAL'),
                td(fmt.num(totU), 'col-num'),
                $('<td>'),
            )
        ));

        $wrap.html('').append($tbl);
        initSort($tbl);
    }

    // ── Helpers DOM ───────────────────────────────────────────────
    function th(label, sortable = true, cls = '') {
        const $h = $('<th>').text(label);
        if (!sortable) $h.css('cursor', 'default');
        if (cls) $h.addClass(cls);
        return $h;
    }
    function td(val, cls = '') {
        return $('<td>').text(val != null ? val : '—').addClass(cls);
    }
    function tdBar(pct) {
        const v = parseFloat(pct) || 0;
        return $('<td class="part-bar-cell">').append(
            $('<div class="part-bar-wrap">').append(
                $('<div class="part-bar-bg">').append(
                    $('<div class="part-bar-fill">').css('width', Math.min(v, 100) + '%')
                ),
                $('<span class="part-bar-val">').text(fmt.pct(v))
            )
        );
    }
    function loadingHTML()  { return '<div class="analisis-loading"><i class="bi bi-arrow-repeat"></i><span>Cargando<span class="loading-text"></span></span></div>'; }
    function emptyState(m)  { return '<div class="empty-state"><i class="bi bi-inbox"></i><span>' + m + '</span></div>'; }
    function errorHTML(m)   { return '<div class="error-state"><i class="bi bi-exclamation-triangle-fill"></i><span>' + (m || 'Error al cargar datos') + '</span></div>'; }

    // ── Ordenamiento de tablas ────────────────────────────────────
    function initSort($tbl) {
        $tbl.find('thead th').not('[style*="cursor: default"]').not('.th-cliente').on('click', function () {
            const $th  = $(this);
            const isAsc = $th.hasClass('sort-asc');
            $tbl.find('thead th').removeClass('sort-asc sort-desc');
            $th.addClass(isAsc ? 'sort-desc' : 'sort-asc');
            const dir = isAsc ? -1 : 1;
            const idx = $th.index();
            const $tbody = $tbl.find('tbody');
            $tbody.append(
                $tbody.find('tr').toArray().sort((a, b) => {
                    const va = $(a).find('td').eq(idx).text().replace(/[\s\.]/g, '').replace(',', '.');
                    const vb = $(b).find('td').eq(idx).text().replace(/[\s\.]/g, '').replace(',', '.');
                    const na = parseFloat(va), nb = parseFloat(vb);
                    return (!isNaN(na) && !isNaN(nb)) ? (na - nb) * dir : va.localeCompare(vb, 'es') * dir;
                })
            );
        });
    }

    // ── Exportar Excel ────────────────────────────────────────────
    function exportToExcel(data, filename, sheet) {
        if (!data || !data.length) return;
        const ws = XLSX.utils.json_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, sheet || 'Datos');
        XLSX.writeFile(wb, filename + '.xlsx');
    }

    // ── Carga lazy por tab ────────────────────────────────────────
    function loadActiveTab() {
        switch (State.activeTab) {
            case 'kpis':       loadKPIs(); loadRubrosPie(); loadComparativaRubros(); break;
            case 'clientes':   loadTablaClientes();             break;
            case 'matriz':     loadMatriz();                    break;
            case 'vendedores': loadTablaVendedores();           break;
            case 'evolucion':
                if (State.evolMode === 'rubro') loadEvolucionRubro();
                else loadEvolucion();
                break;
        }
    }

    // ── Aplicar filtros ───────────────────────────────────────────
    function applyFilters() {
        State.periodo   = $('#sel-periodo').val();
        State.vendedor  = $('#sel-vendedor').val();
        State.rubro     = $('#sel-rubro').val();
        State.categoria = $('#sel-categoria').val();
        State.region    = $('#sel-region').val();
        State.provincia = $('#sel-provincia').val();
        State.cliente   = $('#sel-cliente').val();
        if (State.periodo === 'custom') {
            State.desde     = $('#inp-desde').val();
            State.hasta     = $('#inp-hasta').val();
            State.compMode  = $('input[name="comp-mode"]:checked').val() || 'year_ago';
            State.compDesde = $('#inp-comp-desde').val();
            State.compHasta = $('#inp-comp-hasta').val();
        }
        // Invalidar toda la cache
        Object.keys(Cache).forEach(k => { Cache[k] = null; });
        showOverlay();
        Promise.resolve(loadActiveTab()).finally(hideOverlay);
    }

    // ── Eventos ───────────────────────────────────────────────────
    function bindEvents() {
        // Tabs
        $('.tab-btn[data-tab]').on('click', function () {
            const tab = $(this).data('tab');
            if (tab === State.activeTab) return;
            State.activeTab = tab;
            $('.tab-btn').removeClass('active');
            $(this).addClass('active');
            $('.tab-pane').removeClass('active');
            $('#tab-' + tab).addClass('active');
            loadActiveTab();
        });

        // Período custom
        $('#sel-periodo').on('change', function () {
            $('#custom-dates').toggleClass('visible', $(this).val() === 'custom');
        });

        // Modo de comparación (radio)
        $('input[name="comp-mode"]').on('change', function () {
            State.compMode = $(this).val();
            $('#custom-comp-dates').toggleClass('visible', State.compMode === 'custom');
        });

        // Fechas de comparación personalizada
        $('#inp-comp-desde').on('change', function () { State.compDesde = $(this).val(); });
        $('#inp-comp-hasta').on('change', function () { State.compHasta = $(this).val(); });

        // Región → actualizar provincias
        $('#sel-region').on('change', function () {
            reloadProvincias($(this).val());
        });

        // Aplicar
        $('#btn-aplicar').on('click', applyFilters);

        // Reload pestaña activa
        $('#btn-reload').on('click', function () {
            Cache[State.activeTab] = null;
            if (State.activeTab === 'kpis') {
                Cache.rubros_comp = null;
            }
            if (State.activeTab === 'vendedores') {
                Cache.vendedores = null;
            }
            if (State.activeTab === 'evolucion') {
                const ck = 'evolucion_rub_' + (State.evolAnio || '');
                Cache[ck] = null;
            }
            if (State.activeTab === 'matriz') {
                Object.keys(Cache).filter(k => k.startsWith('mat_')).forEach(k => { Cache[k] = null; });
            }
            setReloadSpin(true);
            Promise.resolve(loadActiveTab()).finally(() => setReloadSpin(false));
        });

        // Toggle evolución: Por Año / Por Rubro
        $('#evol-mode-toggle').on('click', '.evol-btn', function () {
            State.evolMode = $(this).data('mode');
            $('#evol-mode-toggle .evol-btn').removeClass('active');
            $(this).addClass('active');

            const isRubro = (State.evolMode === 'rubro');
            $('#card-evol-anio').toggle(!isRubro);
            $('#card-evol-rubro').toggle(isRubro);
            $('#evol-anio-wrap').toggle(isRubro);

            if (isRubro) loadEvolucionRubro();
            else         loadEvolucion();
        });

        // Selector año en evolución por rubro
        $('#sel-evol-anio').on('change', function () {
            State.evolAnio = parseInt($(this).val(), 10);
            const ck = 'evolucion_rub_' + State.evolAnio;
            if (!Cache[ck]) loadEvolucionRubro();
            else renderEvolucionRubro(Cache[ck]);
        });

        // Exportar rubros comparativa
        $('#btn-export-rubros').on('click', function () {
            if (!Cache.rubros_comp?.data) return;
            const p = Cache.rubros_comp.periodo || {};
            const a1 = p.desde?.substring(0,4) || 'Act';
            const a2 = p.desde_prev?.substring(0,4) || 'Ant1';
            const a3 = p.desde_prev2?.substring(0,4) || 'Ant2';
            exportToExcel(
                Cache.rubros_comp.data.map(r => ({
                    'Rubro'              : r.RUBRO,
                    ['Unid. ' + a1]      : r.unidades_act,
                    ['Unid. ' + a2]      : r.unidades_prev,
                    ['Var vs ' + a2]     : r.var_vs_prev,
                    ['Unid. ' + a3]      : r.unidades_prev2,
                    ['Var vs ' + a3]     : r.var_vs_prev2,
                })),
                'mayoristas_rubros', 'Rubros'
            );
        });

        // Exportar clientes
        $('#btn-export-clientes').on('click', function () {
            if (!Cache.clientes?.data) return;
            exportToExcel(
                Cache.clientes.data.map(r => ({
                    'Cliente'        : r.CLIENTE,
                    'Vendedor'       : r.VENDEDOR,
                    'Región'         : r.REGION,
                    'Provincia'      : r.PROVINCIA,
                    'Unidades'       : r.unidades,
                    'Rubros'         : r.rubros_distintos,
                    'Días activo'    : r.dias_activo,
                    '% Participación': r.porc_part,
                })),
                'mayoristas_clientes', 'Clientes'
            );
        });

        // Exportar vendedores
        $('#btn-export-vendedores').on('click', function () {
            if (!Cache.vendedores?.data) return;
            exportToExcel(
                Cache.vendedores.data.map(r => ({
                    'Vendedor'       : r.VENDEDOR,
                    'Unidades'       : r.unidades,
                    'Clientes'       : r.clientes,
                    'Rubros'         : r.rubros_distintos,
                    'Días activo'    : r.dias_activo,
                    '% Participación': r.porc_part,
                })),
                'mayoristas_vendedores', 'Vendedores'
            );
        });

        // Exportar matriz
        $('#btn-export-matriz').on('click', function () {
            if (!Cache.matriz?.filas) return;
            const res      = Cache.matriz;
            const clientes = res.clientes || [];
            const rows     = res.filas.map(fila => {
                const row = { 'Rubro': fila.rubro, 'Total Unid.': fila.total };
                clientes.forEach(c => {
                    const cel = fila.clientes[c];
                    row[c + ' Unid.'] = cel ? cel.unidades : 0;
                    row[c + ' %']     = cel ? cel.porc     : 0;
                });
                return row;
            });
            exportToExcel(rows, 'mayoristas_matriz', 'Matriz');
        });
    }

    // ── Bootstrap ─────────────────────────────────────────────────
    $(function () {
        initFiltros().then(() => {
            bindEvents();
            showOverlay();
            Promise.all([loadKPIs(), loadRubrosPie(), loadComparativaRubros()]).finally(hideOverlay);
        });
    });

})(jQuery);
