/* /bi/logistica/assets/logistica_uy.js
   Dashboard Logística UY — IIFE jQuery
   Requiere logistica_core.js (window.LogiCore) cargado antes.
   Stack: jQuery 3.7, Chart.js 4.4
   ============================================================ */
;(function ($) {
    'use strict';

    // ── Aliases desde LogiCore ───────────────────────────────────────────
    const fmt           = LogiCore.fmt;
    const setVar        = LogiCore.setVar;
    const escapeHtml    = LogiCore.escapeHtml;
    const chartOptions  = LogiCore.chartOptions;
    const PALETTE       = LogiCore.PALETTE;
    const showOverlay   = LogiCore.showOverlay;
    const hideOverlay   = LogiCore.hideOverlay;
    const setReload     = LogiCore.setReload;
    const initInfoPopover = LogiCore.initInfoPopover;
    const addInfoButton   = LogiCore.addInfoButton;

    // ── Estado global ────────────────────────────────────────────────────
    const State = {
        desde       : '',
        hasta       : '',
        activeTab   : 'eficiencia-uy',
        canal       : '',
        rubro       : '',
        deposito    : '',
        forceRefresh: false,
    };

    const Cache = {};
    let filtrosPromise = null;

    let chartEfiRubro   = null;
    let chartEvolucion  = null;
    let chartEfiSemanal = null;
    let chartStockComp  = null;
    let chartStockDif   = null;
    let chartDifRubro82 = null;
    let chartDifRubro83 = null;

    // Filas originales de la tabla sobrantes (para el buscador)
    let sobrantesRows = [];

    const BASE = '/bi/logistica/ajax/uy/';

    const TAB_SLICERS = {
        'eficiencia-uy': ['wrap-canal', 'wrap-rubro'],
        'stock-uy'     : ['wrap-rubro', 'wrap-deposito'],
    };

    const HELP = {
        kpis: {
            'kv-uy-efi'          : ['Eficiencia de facturación', ['Unidades facturadas sobre unidades pedidas en el período.', 'Meta: 95%. Verde si se alcanza, rojo si está por debajo.']],
            'kv-uy-unid-ped'     : ['Unidades pedidas', ['Total de unidades solicitadas en los pedidos del período (excluye CANCELADO).']],
            'kv-uy-unid-fact'    : ['Unidades facturadas', ['Unidades efectivamente facturadas dentro del período y filtros activos.']],
            'kv-uy-unid-pend'    : ['Unidades no cumplidas', ['Unidades pendientes de facturación en el período. Equivale a Pedidas − Facturadas.']],
            'kv-uy-perdida-uyu'  : ['Pérdida ($UY)', ['Importe pendiente de facturar en pesos uruguayos.', 'El porcentaje muestra el peso de la pérdida sobre el importe pedido total.']],
            'kv-uy-perdida-usd'  : ['Pérdida (U$S)', ['Importe pendiente en dólares, calculado con la última cotización UYU/USD registrada.', 'La cotización aplicada se muestra debajo del valor.']],
            'kv-uy-pedidos'      : ['Pedidos totales', ['Cantidad de pedidos distintos incluidos en el período y filtros.']],
            'kv-uy-stock-tango'  : ['Stock Tango', ['Total de unidades registradas en el sistema Central (referencia) para los rubros activos.']],
            'kv-uy-stock-wms'    : ['Stock Jauser', ['Total de unidades registradas en el sistema Jauser (WMS) para los rubros activos.']],
            'kv-uy-dif-neta'     : ['Diferencia neta', ['Suma de Stock Jauser − Stock Tango por rubro.', 'Positivo: hay más en Jauser que en Tango. Negativo: Tango tiene más que Jauser.']],
            'kv-uy-precision'    : ['Precisión de inventario', ['Proporción del stock que coincide entre ambos sistemas: 1 − dif.abs / Stock Tango.', 'Meta ideal: 99% o superior.']],
        },
        sections: {
            'chart-uy-efi-semanal': ['Eficiencia semanal', ['Eficiencia de facturación (unidades facturadas / pedidas) agrupada por semana ISO en las últimas 12 semanas.', 'La línea roja punteada marca la meta del 95%.']],
            'chart-uy-evolucion'  : ['Eficiencia mensual', ['Eficiencia mensual de facturación desde el mismo mes del año anterior hasta el mes actual.', 'La línea roja punteada marca la meta del 95%.']],
            'chart-uy-efi-rubro'  : ['Eficiencia por rubro', ['Barras horizontales: unidades pedidas vs. facturadas por rubro, ordenadas por volumen.', 'Verde ≥ 95%, amarillo ≥ 85%, rojo < 85%.']],
            'chart-uy-stock-comp' : ['Stock Central vs WMS por rubro', ['Barras agrupadas: azul = Stock Central, verde = Stock WMS. Diferencias entre pares indican desvíos.']],
            'chart-uy-stock-dif'  : ['Diferencia absoluta por rubro', ['Magnitud del desvío (|WMS − Central|) por rubro. Mayor barra = mayor urgencia de auditoría.']],
            'tabla-uy-efi-rubro'  : ['Detalle de eficiencia por rubro', ['Unidades pedidas, facturadas y porcentaje de eficiencia por rubro.']],
            'tabla-uy-stock'      : ['Detalle de stock por rubro', ['Stock Central, WMS, diferencia neta, porcentual, diferencia absoluta y precisión por rubro.']],
            'tabla-uy-sobrantes'  : ['Top 10 artículos sobrantes', ['Artículos con mayor stock en WMS respecto a Central (DIFERENCIA > 0).', 'Usá el buscador para filtrar por código o descripción.']],
            'tabla-uy-faltantes-82': ['Top 10 faltantes — Depósito 82 (Central)', ['Artículos donde Jauser WMS registra menos unidades que el sistema Central para el depósito 82 (depósito propio de XL).']],
            'tabla-uy-faltantes-83': ['Top 10 faltantes — Depósito 83 (Depósito Fiscal)', ['Artículos donde Jauser WMS registra menos unidades que el sistema Central para el depósito 83 (Depósito Fiscal).']],
        },
        tableHeaders: {
            'tabla-uy-efi-rubro': [
                'Rubro logístico.',
                'Unidades pedidas en el período.',
                'Unidades facturadas en el período.',
                'Eficiencia (facturadas / pedidas).',
            ],
            'tabla-uy-stock': [
                'Rubro del artículo.',
                'Unidades registradas en el sistema Central (referencia).',
                'Unidades registradas en WMS.',
                'Diferencia neta: WMS − Central. Positivo = más en WMS.',
                'Diferencia neta como porcentaje sobre el stock Central.',
                'Diferencia absoluta: |WMS − Central|.',
                'Precisión: 1 − dif.abs / Central.',
            ],
            'tabla-uy-sobrantes': [
                'Código de artículo.',
                'Descripción del artículo.',
                'Rubro.',
                'Depósito.',
                'Stock en sistema Central.',
                'Stock en WMS.',
                'Diferencia (WMS − Central).',
            ],
            'tabla-uy-faltantes-82': [
                'Código de artículo.',
                'Descripción del artículo.',
                'Rubro.',
                'Stock en sistema Central.',
                'Stock en WMS.',
                'Diferencia (WMS − Central, negativa = faltante).',
            ],
            'tabla-uy-faltantes-83': [
                'Código de artículo.',
                'Descripción del artículo.',
                'Rubro.',
                'Stock en sistema Central.',
                'Stock en WMS.',
                'Diferencia (WMS − Central, negativa = faltante).',
            ],
        },
    };

    // ── AJAX helpers ─────────────────────────────────────────────────────
    function buildQS(extra = {}) {
        if (State.forceRefresh) extra._nocache = Date.now();
        return $.param({ desde: State.desde, hasta: State.hasta, ...extra });
    }

    async function apiFetch(endpoint, extra = {}) {
        const url = BASE + endpoint + '.php?' + buildQS(extra);
        const res = await fetch(url);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Error en el servidor');
        return json.data;
    }

    // ── Filtros ───────────────────────────────────────────────────────────
    function fetchFiltros() {
        if (!filtrosPromise) {
            filtrosPromise = fetch(BASE + 'filtros.php').then(res => res.json());
        }
        return filtrosPromise;
    }

    async function initFiltros() {
        try {
            const json = await fetchFiltros();
            if (!json.ok) return;
            poblarSelect('#sel-canal',    json.canales.filter(c => c.toUpperCase() !== 'DESCONOCIDO'), 'Todos');
            poblarSelect('#sel-rubro',    json.rubros,    'Todos');
            poblarSelect('#sel-deposito', json.depositos, 'Todos');
        } catch (e) { console.error('Filtros UY:', e); }
    }

    function poblarSelect(sel, items, placeholder) {
        const $s = $(sel).empty().append($('<option>').val('').text(placeholder));
        (items || []).forEach(v => $s.append($('<option>').val(v).text(v)));
    }

    // ── Slicers por tab ───────────────────────────────────────────────────
    function updateSlicers(tab) {
        $('#wrap-canal, #wrap-rubro, #wrap-deposito').hide();
        (TAB_SLICERS[tab] || []).forEach(id => $('#' + id).show());
    }

    // ── Tabs ──────────────────────────────────────────────────────────────
    function switchTab(tab) {
        State.activeTab = tab;
        $('.tab-btn').removeClass('active');
        $(`.tab-btn[data-tab="${tab}"]`).addClass('active');
        $('.tab-pane').removeClass('active');
        $(`#tab-${tab}`).addClass('active');
        updateSlicers(tab);
        loadTab(tab);
    }

    async function loadTab(tab) {
        if (Cache[tab]) return;
        showOverlay();
        setReload(true);
        try {
            switch (tab) {
                case 'eficiencia-uy': await loadEficiencia(); break;
                case 'stock-uy':      await loadStock();      break;
            }
            Cache[tab] = true;
        } catch (e) {
            mostrarError(tab, e.message);
        } finally {
            setReload(false);
            hideOverlay();
        }
    }

    function invalidarCache() {
        Object.keys(Cache).forEach(k => delete Cache[k]);
    }

    function mostrarError(tab, msg) {
        const $pane = $(`#tab-${tab} .dash-content`);
        $pane.find('.error-state').remove();
        $pane.prepend(`<div class="error-state"><i class="bi bi-exclamation-triangle-fill"></i> ${msg}</div>`);
    }

    // ── Pestaña 1: Eficiencia UY ─────────────────────────────────────────
    async function loadEficiencia() {
        const extra = { canal: State.canal, rubro: State.rubro };
        const data = await apiFetch('eficiencia', extra);
        const k = data.kpis || {};

        // KPIs
        $('#kv-uy-efi').text(fmt.pct(k.EFI_UNIDADES));
        setVar('#kvar-uy-efi', fmt.varLabel(k.EFI_UNIDADES, k.META_EFICIENCIA));

        $('#kv-uy-unid-ped').text(fmt.num(k.UNID_PEDIDAS));
        $('#kv-uy-unid-fact').text(fmt.num(k.UNID_FACTURADAS));
        $('#kv-uy-unid-pend').text(fmt.num(k.UNID_PENDIENTES));

        $('#kv-uy-perdida-uyu').text(fmt.moneyUyu(k.PERDIDA_UYU));
        $('#kvar-uy-perdida-pct').text(fmt.pct(k.PCT_PERDIDA))
            .removeClass('pos neg neu').addClass('kpi-var ' + (parseFloat(k.PCT_PERDIDA || 0) > 0.05 ? 'neg' : 'neu'));

        $('#kv-uy-perdida-usd').text(fmt.moneyUsd(k.PERDIDA_USD));

        const cotizActiva = parseFloat(k.COTIZACION_USD || 0);
        if (cotizActiva > 0) {
            const cotizTxt = 'U$S 1 = $U ' + fmt.num(cotizActiva, 2);
            $('#kvar-uy-cotizacion')
                .text(cotizTxt)
                .attr('title', 'Cotización obtenida de RO_T_COTIZACION_UYU_USD (última ≤ fecha hasta)');
        } else {
            $('#kvar-uy-cotizacion').text('Sin cotización cargada');
        }

        $('#kv-uy-pedidos').text(fmt.num(k.PEDIDOS_TOTAL));

        // Gráfico semanal
        const semanal = data.semanal || [];
        const labSem = semanal.map(r => 'S' + r.SEMANA + ' ' + String(r.ANIO).slice(2));
        const efiSem = semanal.map(r => {
            const ped = parseFloat(r.UNID_PEDIDAS_SEM) || 0;
            const fct = parseFloat(r.UNID_FACTURADAS_SEM) || 0;
            return ped > 0 ? parseFloat((fct / ped * 100).toFixed(1)) : null;
        });
        if (chartEfiSemanal) chartEfiSemanal.destroy();
        chartEfiSemanal = new Chart($('#chart-uy-efi-semanal')[0], {
            type: 'line',
            data: {
                labels: labSem,
                datasets: [{
                    label          : 'Eficiencia (%)',
                    data           : efiSem,
                    borderColor    : PALETTE[0],
                    backgroundColor: 'rgba(0,168,120,.08)',
                    borderWidth    : 2,
                    pointRadius    : 4,
                    tension        : 0.3,
                    fill           : true,
                }, {
                    label      : 'Meta (95%)',
                    data       : labSem.map(() => 95),
                    borderColor: '#dc2626',
                    borderWidth: 1.5,
                    borderDash : [6, 4],
                    pointRadius: 0,
                    fill       : false,
                }],
            },
            options: chartOptions('Eficiencia (%)', { min: 0, max: 100, suggestedMax: 105 }, { pct: true }),
        });

        // Gráfico mensual (ex "Evolución anual")
        const evol = data.evolucion || [];
        const multiAnio = evol.length > 1 && evol[0].ANIO !== evol[evol.length - 1].ANIO;
        const labMes = evol.map(r => multiAnio ? r.NOMBRE_MES.substring(0,3) + ' ' + r.ANIO : r.NOMBRE_MES);
        const efiMes = evol.map(r => {
            const ped = parseFloat(r.UNID_PEDIDAS_MES) || 0;
            const fct = parseFloat(r.UNID_FACTURADAS_MES) || 0;
            return ped > 0 ? parseFloat((fct / ped * 100).toFixed(1)) : null;
        });
        if (chartEvolucion) chartEvolucion.destroy();
        chartEvolucion = new Chart($('#chart-uy-evolucion')[0], {
            type: 'line',
            data: {
                labels: labMes,
                datasets: [{
                    label          : 'Eficiencia (%)',
                    data           : efiMes,
                    borderColor    : PALETTE[0],
                    backgroundColor: 'rgba(0,168,120,.08)',
                    borderWidth    : 2,
                    pointRadius    : 4,
                    tension        : 0.3,
                    fill           : true,
                }, {
                    label      : 'Meta (95%)',
                    data       : labMes.map(() => 95),
                    borderColor: '#dc2626',
                    borderWidth: 1.5,
                    borderDash : [6, 4],
                    pointRadius: 0,
                    fill       : false,
                }],
            },
            options: chartOptions('Eficiencia (%)', { min: 0, max: 100, suggestedMax: 105 }, { pct: true }),
        });

        // Gráfico barras horizontales — eficiencia por rubro
        const porRubro = (data.por_rubro || []).slice(0, 20);
        if (chartEfiRubro) chartEfiRubro.destroy();
        chartEfiRubro = new Chart($('#chart-uy-efi-rubro')[0], {
            type: 'bar',
            data: {
                labels  : porRubro.map(r => r.RUBRO),
                datasets: [
                    {
                        label          : 'Pedidas',
                        data           : porRubro.map(r => r.UNID_PEDIDAS),
                        backgroundColor: 'rgba(37,99,235,.55)',
                        borderRadius   : 3,
                    },
                    {
                        label          : 'Facturadas',
                        data           : porRubro.map(r => r.UNID_FACTURADAS),
                        backgroundColor: 'rgba(0,168,120,.7)',
                        borderRadius   : 3,
                    },
                ],
            },
            options: {
                ...chartOptions('Unidades', {}, { integer: true }),
                indexAxis: 'y',
                plugins: { legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } } },
                scales: {
                    x: { beginAtZero: true, ticks: { font: { size: 11 } } },
                    y: { ticks: { font: { size: 11 } } },
                },
            },
        });

        // Tabla eficiencia por rubro
        const $tbody = $('#tbody-uy-efi-rubro').empty();
        if (!porRubro.length) {
            $tbody.html('<tr><td colspan="4"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
        } else {
            $tbody.html(porRubro.map(r => {
                const efi = parseFloat(r.EFI || 0);
                const cls = efi >= 0.95 ? 'var-pos' : efi >= 0.85 ? '' : 'var-neg';
                return `<tr>
                    <td>${escapeHtml(r.RUBRO)}</td>
                    <td class="col-num">${fmt.num(r.UNID_PEDIDAS)}</td>
                    <td class="col-num">${fmt.num(r.UNID_FACTURADAS)}</td>
                    <td class="col-num ${cls}">${fmt.pct(efi)}</td>
                </tr>`;
            }).join(''));
        }
    }

    // ── Pestaña 2: Stock UY ───────────────────────────────────────────────
    async function loadStock() {
        const data = await apiFetch('stock', { rubro: State.rubro, deposito: State.deposito });
        const k = data.kpis || {};

        // KPIs
        $('#kv-uy-stock-tango').text(fmt.num(k.STOCK_TANGO));
        $('#kv-uy-stock-wms').text(fmt.num(k.STOCK_WMS));
        $('#kv-uy-dif-neta').text(fmt.num(k.DIFERENCIA));
        const difPct = parseFloat(k.DIF_PCT || 0);
        setVar('#kvar-uy-dif-neta-pct', {
            text: fmt.pct(k.DIF_PCT),
            cls : Math.abs(difPct) < 0.01 ? 'pos' : 'neg',
        });
        $('#kv-uy-precision').text(fmt.pct(k.PRECISION_INVENTARIO));

        // Rubro-level: top 15 con stock
        const rubros = (data.rubros || [])
            .filter(r => parseFloat(r.STOCK_TANGO || 0) > 0 || parseFloat(r.STOCK_WMS || 0) > 0)
            .slice(0, 15);
        const limpiarRubro = r => (r.RUBRO || '').replace(/\s*nacional\s*/gi, '').trim();

        // Gráfico A: Central vs WMS agrupado
        if (chartStockComp) chartStockComp.destroy();
        chartStockComp = new Chart($('#chart-uy-stock-comp')[0], {
            type: 'bar',
            data: {
                labels  : rubros.map(limpiarRubro),
                datasets: [
                    { label: 'Stock Central', data: rubros.map(r => r.STOCK_TANGO), backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 4 },
                    { label: 'Stock WMS',     data: rubros.map(r => r.STOCK_WMS),   backgroundColor: 'rgba(0,168,120,.65)', borderRadius: 4 },
                ],
            },
            options: chartOptions('Unidades', {}, { integer: true }),
        });

        // Gráfico B: DIFERENCIA_ABS por rubro
        if (chartStockDif) chartStockDif.destroy();
        chartStockDif = new Chart($('#chart-uy-stock-dif')[0], {
            type: 'bar',
            data: {
                labels  : rubros.map(limpiarRubro),
                datasets: [{
                    label          : 'Diferencia absoluta',
                    data           : rubros.map(r => r.DIFERENCIA_ABS),
                    backgroundColor: 'rgba(239,68,68,.65)',
                    borderRadius   : 4,
                }],
            },
            options: { ...chartOptions('Unidades', {}, { integer: true }), plugins: { legend: { display: false } } },
        });

        // Tabla detalle por rubro
        const $tbody = $('#tbody-uy-stock').empty();
        if (!rubros.length) {
            $tbody.html('<tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
        } else {
            $tbody.html(rubros.map(r => {
                const dif  = parseFloat(r.DIFERENCIA || 0);
                const prec = parseFloat(r.PRECISION  || 0);
                return `<tr>
                    <td>${escapeHtml(limpiarRubro(r))}</td>
                    <td class="col-num">${fmt.num(r.STOCK_TANGO)}</td>
                    <td class="col-num">${fmt.num(r.STOCK_WMS)}</td>
                    <td class="col-num ${dif !== 0 ? (dif < 0 ? 'var-neg' : 'var-pos') : ''}">${fmt.num(dif)}</td>
                    <td class="col-num ${dif !== 0 ? 'var-neg' : ''}">${fmt.pct(r.DIF_PCT)}</td>
                    <td class="col-num var-neg">${fmt.num(r.DIFERENCIA_ABS)}</td>
                    <td class="col-num ${prec >= 0.99 ? 'var-pos' : 'var-neg'}">${fmt.pct(prec)}</td>
                </tr>`;
            }).join(''));
        }

        // Top 10 Sobrantes
        renderTablaArticulos('#tbody-uy-sobrantes', data.sobrantes || [], 7, true);
        sobrantesRows = data.sobrantes || [];

        // Top 10 Faltantes Depo 82 + gráfico
        renderTablaArticulos('#tbody-uy-faltantes-82', data.faltantes_82 || [], 6, false);
        chartDifRubro82 = renderGraficoDifRubro('chart-uy-dif-rubro-82', chartDifRubro82, data.dif_rubro_82 || [], 'Depósito 82');

        // Top 10 Faltantes Depo 83 + gráfico
        renderTablaArticulos('#tbody-uy-faltantes-83', data.faltantes_83 || [], 6, false);
        chartDifRubro83 = renderGraficoDifRubro('chart-uy-dif-rubro-83', chartDifRubro83, data.dif_rubro_83 || [], 'Depósito Fiscal');
    }

    // Renderiza tabla de artículos (sobrantes o faltantes)
    // cols: 7 = con Depósito, 6 = sin Depósito
    function renderTablaArticulos(sel, rows, cols, conDeposito) {
        const $tbody = $(sel).empty();
        if (!rows.length) {
            $tbody.html(`<tr><td colspan="${cols}"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>`);
            return;
        }
        $tbody.html(rows.map(r => {
            const dif = parseFloat(r.DIFERENCIA || 0);
            const depCol = conDeposito ? `<td>${escapeHtml(r.DEPOSITO || '—')}</td>` : '';
            return `<tr>
                <td>${escapeHtml(r.COD_ARTICU || '—')}</td>
                <td>${escapeHtml(r.DESCRIPCION || '—')}</td>
                <td>${escapeHtml(r.RUBRO || '—')}</td>
                ${depCol}
                <td class="col-num">${fmt.num(r.STOCK_TANGO)}</td>
                <td class="col-num">${fmt.num(r.STOCK_WMS)}</td>
                <td class="col-num ${dif > 0 ? 'var-pos' : dif < 0 ? 'var-neg' : ''}">${fmt.num(dif)}</td>
            </tr>`;
        }).join(''));
    }

    // Renderiza gráfico de barras horizontales de diferencia por rubro. Retorna la instancia.
    function renderGraficoDifRubro(canvasId, chartInst, rows, titulo) {
        if (chartInst) chartInst.destroy();
        const top = rows.slice(0, 15);
        return new Chart(document.getElementById(canvasId), {
            type: 'bar',
            data: {
                labels  : top.map(r => r.RUBRO),
                datasets: [{
                    label          : 'Dif. Absoluta',
                    data           : top.map(r => r.DIFERENCIA_ABS),
                    backgroundColor: 'rgba(239,68,68,.65)',
                    borderRadius   : 3,
                }],
            },
            options: {
                ...chartOptions('Unidades', {}, { integer: true }),
                indexAxis: 'y',
                plugins  : { legend: { display: false } },
                scales   : {
                    x: { beginAtZero: true, ticks: { font: { size: 11 } } },
                    y: { ticks: { font: { size: 10 } } },
                },
            },
        });
    }

    // ── Buscador de artículos sobrantes ──────────────────────────────────
    function filtrarSobrantes(q) {
        const term = (q || '').toLowerCase().trim();
        const filtrados = term
            ? sobrantesRows.filter(r =>
                (r.COD_ARTICU  || '').toLowerCase().includes(term) ||
                (r.DESCRIPCION || '').toLowerCase().includes(term)
              )
            : sobrantesRows;
        renderTablaArticulos('#tbody-uy-sobrantes', filtrados, 7, true);
    }

    // ── Label período topbar ──────────────────────────────────────────────
    function updatePeriodLabel() {
        const d = State.desde, h = State.hasta;
        if (d && h) $('#periodo-label').text(fmt.date(d) + ' — ' + fmt.date(h));
    }

    // ── Tooltips estáticos ────────────────────────────────────────────────
    function initStaticTooltips() {
        initInfoPopover();

        $('#inp-desde').attr('title', 'Fecha inicial del período de análisis.');
        $('#inp-hasta').attr('title', 'Fecha final del período de análisis.');
        $('#sel-canal').attr('title', 'Filtra los datos por canal.');
        $('#sel-rubro').attr('title', 'Filtra los datos por rubro.');
        $('#sel-deposito').attr('title', 'Filtra los datos de stock por depósito.');
        $('#btn-aplicar').attr({ title: 'Aplicar fechas y filtros seleccionados.', 'aria-label': 'Aplicar filtros' });
        $('#btn-reload').attr({ title: 'Recargar la pestaña activa.', 'aria-label': 'Recargar pestaña activa' });

        $('.tab-btn').each(function () {
            const label = $(this).text().trim().replace(/\s+/g, ' ');
            $(this).attr({ title: 'Ver ' + label, 'aria-label': 'Ver ' + label });
        });

        Object.entries(HELP.kpis).forEach(([id, cfg]) => {
            const $label = $('#' + id).closest('.kpi-body').find('.kpi-label').first();
            $label.contents().filter(function () { return this.nodeType === 3; })
                  .wrap('<span class="kpi-label-txt"></span>');
            $label.attr('title', cfg[1][0]);
            addInfoButton($label, cfg[0], cfg[1]);
        });

        Object.entries(HELP.sections).forEach(([id, cfg]) => {
            const $section = $('#' + id).closest('.analisis-card').find('.analisis-section-header').first();
            addInfoButton($section, cfg[0], cfg[1]);
        });

        Object.entries(HELP.tableHeaders).forEach(([tableId, tips]) => {
            $('#' + tableId + ' thead th').each(function (i) {
                if (tips[i]) $(this).attr('title', tips[i]);
            });
        });
    }

    // ── Bootstrap ─────────────────────────────────────────────────────────
    function init() {
        State.desde = $('#inp-desde').val() || new Date().toISOString().substring(0,7) + '-01';
        State.hasta = $('#inp-hasta').val() || new Date().toISOString().substring(0,10);
        updatePeriodLabel();
        initStaticTooltips();
        initFiltros();
        updateSlicers('eficiencia-uy');
        loadTab('eficiencia-uy');

        // Tab click
        $('.tab-btn').on('click', function () {
            const tab = $(this).data('tab');
            if (tab) switchTab(tab);
        });

        // Aplicar fechas/filtros
        $('#btn-aplicar').on('click', function () {
            const d = $('#inp-desde').val();
            const h = $('#inp-hasta').val();
            if (!d || !h) return;
            if (d > h) { alert('La fecha Desde no puede ser mayor que Hasta.'); return; }
            State.desde    = d;
            State.hasta    = h;
            State.canal    = $('#sel-canal').val()    || '';
            State.rubro    = $('#sel-rubro').val()    || '';
            State.deposito = $('#sel-deposito').val() || '';
            invalidarCache();
            updatePeriodLabel();
            loadTab(State.activeTab);
        });

        // Recargar pestaña
        $('#btn-reload').on('click', function () {
            delete Cache[State.activeTab];
            State.forceRefresh = true;
            loadTab(State.activeTab).finally(() => { State.forceRefresh = false; });
        });

        // Cambio slicer canal/rubro/deposito
        $('#sel-canal, #sel-rubro, #sel-deposito').on('change', function () {
            State.canal    = $('#sel-canal').val()    || '';
            State.rubro    = $('#sel-rubro').val()    || '';
            State.deposito = $('#sel-deposito').val() || '';
            delete Cache[State.activeTab];
            loadTab(State.activeTab);
        });

        // Buscador de artículos sobrantes (client-side)
        $('#inp-buscar-art').on('input', function () {
            filtrarSobrantes($(this).val());
        });
    }

    $(document).ready(init);

})(jQuery);
