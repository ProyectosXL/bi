/* /bi/logistica/assets/logistica_ar.js
   Dashboard Logística AR — IIFE jQuery
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
        desde    : '',
        hasta    : '',
        activeTab: 'eficiencia',
        canal    : '',
        rubro    : '',
        deposito : '01',   // Inventario: depósito por defecto
        rubroFact: '',
        usuario  : '',
        cliente  : '',
        tipo     : 'REPOSICION',   // Facturación arranca filtrando por Reposición (como el tablero viejo)
        pendFiltro : 'HOY',
        pendientes : [],
        forceRefresh: false,
    };

    const Cache = {};
    let filtrosPromise = null;

    let chartEfi         = null;
    let chartsCanalEfi   = [];
    let chartPerdidaSpark = null;   // mini-gráfico dentro del KPI de pérdida
    let chartPerdidaModal = null;   // versión ampliada (modal)
    let perdida12mData    = [];     // serie proporción/importe pérdida (últ. 12 meses)
    let efiPedidosGroups  = [];     // pedidos agrupados por cliente (tabla drill)
    let chartLtHist      = null;
    let chartLtEvol      = null;
    let chartStock       = null;
    let chartProdFact    = null;
    let chartProdPicking = null;
    let chartPedidos     = null;
    let chartDespEvol    = null;
    let chartsPlanGauges = [];
    let chartsDespCanal  = [];
    let chartPickingDia  = null;
    const ult7Data       = {};   // base ('picking-ult7'|'fact-ult7') -> { rows, title }

    // Detalle de artículos con diferencia (drill-down del detalle por rubro)
    let stockDetalleArticulos = [];
    let stockExportInit = false;

    const BASE = '/bi/logistica/ajax/';

    const TAB_SLICERS = {
        'eficiencia'   : ['wrap-canal'],
        'leadtime'     : [],
        'stock'        : ['wrap-rubro', 'wrap-deposito'],
        'prod-fact'    : ['wrap-tipo', 'wrap-rubro'],
        'prod-picking' : ['wrap-usuario'],
        'planificacion': ['wrap-canal'],
        'despacho'     : ['wrap-canal', 'wrap-cliente'],
        'pedidos'      : ['wrap-canal'],
    };

    const HELP = {
        kpis: {
            'kv-efi'            : ['Eficiencia de facturación', ['Unidades facturadas sobre unidades pedidas en el período.', 'Meta: 95%. Verde si se alcanza, rojo si está por debajo.']],
            'kv-unid-ped'       : ['Unidades pedidas', ['Total de unidades solicitadas en los pedidos del período.', 'La variación compara contra el mismo período del año anterior.']],
            'kv-unid-fact'      : ['Unidades facturadas', ['Unidades efectivamente facturadas dentro del período y filtros activos.']],
            'kv-perdida'        : ['Pérdida de facturación', ['Importe de los pedidos que no se llegó a facturar (importe pendiente).', '% Pérdida: peso sobre el importe pedido. "prev": mismo período del año anterior.', 'Tocá el ícono de gráfico para dar vuelta la tarjeta y ver la evolución de los últimos 12 meses (ampliable).']],
            'kv-importe'        : ['Importe facturado', ['Importe total facturado en el período y filtros activos, expresado en pesos.']],
            'kv-pedidos'        : ['Pedidos totales', ['Cantidad de pedidos incluidos en el cálculo de eficiencia.', 'La variación compara contra el mismo período del año anterior.']],
            'kv-lt-total'       : ['Comprobantes facturados', ['Total de comprobantes de facturación emitidos en el período seleccionado.']],
            'kv-lt-dem'         : ['Facturación demorada', ['Comprobantes con lead time mayor a 5 días hábiles desde el pedido.', 'El porcentaje se calcula sobre el total de comprobantes del período.']],
            'kv-lt-prom'        : ['Lead time promedio', ['Promedio de días corridos entre la fecha del pedido y la fecha de facturación.']],
            'kv-lt-abiertos'    : ['Pedidos abiertos', ['Pedidos con al menos una unidad sin facturar al cierre del período.']],
            'kv-stock-tango'    : ['Stock Tango', ['Stock registrado en el sistema Tango para los rubros filtrados.']],
            'kv-stock-wms'      : ['Stock WMS', ['Stock registrado en el sistema WMS para los rubros filtrados.']],
            'kv-stock-dif'      : ['Diferencia neta', ['Diferencia entre stock WMS y stock Tango (WMS − Tango).', 'Desviaciones distintas de cero requieren revisión de inventario.']],
            'kv-stock-dif-abs'  : ['Diferencia absoluta', ['Suma del valor absoluto de las diferencias por artículo (|WMS − Tango|).', 'Mide la magnitud total del desvío sin que los positivos y negativos se compensen.']],
            'kv-stock-prec'     : ['Precisión de inventario', ['Porcentaje de artículos donde WMS y Tango coinciden exactamente.', 'Meta ideal: 99% o superior.']],
            'kv-pf-prom-dia'    : ['Promedio por día', ['Promedio de unidades facturadas por día en el período, según el tipo de facturación y rubro seleccionados.']],
            'kv-pf-pico-dia'    : ['Pico x día', ['Mayor cantidad de unidades facturadas en un único día del período (respeta los filtros).']],
            'kv-pf-pico-user'   : ['Pico x usuario', ['Mayor cantidad de unidades facturadas por un usuario en un solo día. No depende del filtro de tipo de facturación.']],
            'kv-pf-tendencia'   : ['Tendencia x día x usuario', ['Mediana de las unidades por usuario y día (considerando días con más de 200 unidades). Representa el rendimiento diario típico.']],
            'kv-pp-prom-dia'    : ['Promedio unid./día', ['Promedio de unidades pickeadas por día productivo en el período.']],
            'kv-pp-pico-dia'    : ['Pico por día', ['Mayor cantidad de unidades preparadas en un único día del período.']],
            'kv-pp-pico-usuario': ['Pico por usuario', ['Mayor cantidad de unidades preparadas por un único picker en un solo día del período.']],
            'kv-pp-prom'        : ['Promedio por picker', ['Promedio de unidades por día, considerando solo pickers con más de 3 horas productivas registradas.']],
            'kv-pp-u-hora'      : ['Promedio unid./hora', ['Unidades pickeadas por hora productiva registrada en el período.']],
            'kv-pp-hs'          : ['Tiempo productivo', ['Total de horas de picking registradas en el período y filtros activos.']],
            'kv-pp-prom-hs'     : ['Promedio tiempo productivo', ['Promedio de horas productivas por picker y día, considerando solo días con más de 3 horas registradas.']],
            'kv-dsp-efi'        : ['Eficacia de despacho total', ['Comprobantes despachados en término sobre el total de comprobantes despachados en el período.', 'Meta: 95%. Verde si se alcanza, rojo por debajo.']],
            'kv-dsp-dem-dias'   : ['Promedio días pedidos demorados', ['Promedio de días de demora de los pedidos demorados (vencidos) del período. Negativo = atraso respecto a la fecha comprometida.']],
            'kv-dsp-guia-dias'  : ['Promedio días guía vs despacho', ['Promedio de días entre la fecha de guía y la fecha comprometida de despacho. Negativo = despachado después de lo comprometido.']],
            'kv-pc-ped'         : ['Pedidos del período', ['Cantidad de pedidos consolidados en el rango de fechas y canal seleccionados.', 'La variación compara contra el mismo período del año anterior.']],
            'kv-pc-ped-aa'      : ['Pedidos año anterior', ['Pedidos del mismo rango de fechas en el año anterior, para comparación directa.']],
            'kv-pc-var'         : ['Variación absoluta', ['Diferencia en cantidad de pedidos entre el período actual y el año anterior.', 'Positivo indica más pedidos que el año anterior.']],
            'kv-pc-unid-ped'    : ['Unidades pedidas', ['Unidades solicitadas en los pedidos consolidados del período filtrado.']],
            'kv-pc-unid-fact'   : ['Unidades facturadas', ['Unidades ya facturadas correspondientes a los pedidos consolidados del período.']],
        },
        sections: {
            'gauges-canal'          : ['Eficiencia por canal', ['Cada gauge muestra la eficiencia (unidades facturadas / unidades pedidas) por canal.', 'La marca negra indica la meta del 95%. Verde ≥ 95%, amarillo ≥ 85%, rojo < 85%.']],
            'chart-eficiencia'      : ['Evolución mensual de eficiencia', ['Eficiencia mensual de facturación: una línea por año (año actual vs. año anterior).', 'Bandas de color = umbrales: verde ≥ 95%, amarillo 85–95%, rojo < 85%.', 'La línea roja punteada marca la meta del 95%.']],
            'tabla-efi-unid-cliente': ['% Eficiencia unidades por cliente', ['Los 10 clientes con menor eficiencia (unidades facturadas / pedidas) en el período.']],
            'tabla-efi-unid-rubro'  : ['% Eficiencia unidades por rubro', ['Eficiencia de unidades por rubro, ordenada de menor a mayor.']],
            'tabla-efi-pedidos'     : ['% Eficiencia por pedido y cliente', ['Eficiencia por pedido, agrupada por cliente. Clic en un cliente para desplegar sus pedidos.', 'Clic en un pedido para ver el detalle por rubro.']],
            'chart-leadtime-hist'   : ['Distribución de lead times', ['Cantidad de comprobantes agrupados por días de demora entre pedido y facturación.', 'Barras rojas: más de 5 días (demorados). Barras verdes: en término.']],
            'chart-leadtime-evol'   : ['Evolución de facturación demorada', ['Porcentaje mensual de comprobantes con lead time mayor a 5 días.', 'Muestra la tendencia de demoras en los últimos 12 meses.']],
            'chart-stock'           : ['Diferencia de stock por rubro', ['Compara stock Tango vs. WMS en los principales rubros logísticos.', 'Diferencias significativas entre barras indican desvíos de inventario.']],
            'tabla-stock'           : ['Detalle de stock por rubro', ['Tabla con stock Tango, WMS, diferencia neta, diferencia porcentual y precisión de inventario por rubro.']],
            'chart-prod-fact'       : ['Facturación diaria', ['Unidades facturadas por día en el período seleccionado.']],
            'tabla-usuarios-fact'   : ['Indicadores por usuario', ['Unidades facturadas, participación, pico y tendencia por usuario. Pico y días productivos no dependen del filtro de tipo de facturación.']],
            'tabla-fact-ult7'       : ['Unidades facturadas por usuario (últimos 7 días)', ['Unidades facturadas por día y usuario en los últimos 7 días. Clic en una fecha para ver el gráfico de ese día.']],
            'chart-prod-picking'    : ['Picking diario', ['Barras azules: unidades pickeadas por día. Línea naranja: promedio de unidades por hora productiva.']],
            'tabla-usuarios-picking': ['Productividad por picker', ['Resumen de unidades, porcentaje del equipo, pico, mediana, horas y promedio por hora para cada picker.']],
            'tabla-picking-ult7'    : ['Últimos 7 días por picker', ['Unidades pickeadas por día y picker en los últimos 7 días con actividad.', 'Las columnas de totales suman unidades y horas del período completo.']],
            'gauges-despacho-canal' : ['Eficacia de despacho por canal', ['Cada gauge muestra la eficacia de despacho (comprobantes en término / despachados) por canal.', 'La marca negra indica la meta del 95%. Verde ≥ 95%, amarillo ≥ 85%, rojo < 85%.']],
            'chart-despacho-evol'   : ['Evolución eficacia de despacho', ['Eficacia de despacho mensual: una línea por año (actual vs. anterior).', 'Bandas de color = umbrales: verde ≥ 95%, amarillo 85–95%, rojo < 85%.', 'La línea roja punteada marca la meta del 95%.']],
            'tabla-pend'            : ['Pedidos pendientes', ['Pedidos con unidades pendientes de despacho, filtrables por ventana de entrega: Hoy, Próxima entrega, Próxima entrega +1 día o Todos.']],
            'tabla-plan-demorados'  : ['Pedidos demorados', ['Pedidos pendientes cuya fecha de entrega comprometida ya venció, dentro de los últimos 30 días, ordenados por fecha de entrega.']],
            'tabla-efi-cliente'     : ['% Eficacia despacho por cliente', ['Eficacia de despacho y desvío promedio de días por cliente, ordenado de menor a mayor eficacia.']],
            'tabla-efi-pedido'      : ['Eficacia — desglose por pedido', ['Pedidos despachados fuera de plazo, con desvío en días entre fecha de guía y despacho comprometido.']],
            'tabla-dem-cliente'     : ['Pedidos demorados promedio por cliente', ['Promedio de días de demora y cantidad de pedidos demorados por cliente en el período.']],
            'tabla-dem-pedido'      : ['Pedidos demorados — desglose por pedido', ['Detalle de pedidos demorados con estado, fecha comprometida y días de demora.']],
            'chart-pedidos-evol'    : ['Evolución mensual de pedidos', ['Cantidad de pedidos consolidados por mes en los últimos 12 meses.']],
            'tabla-pedidos'         : ['Pedidos consolidados', ['Detalle de pedidos con estado, canal, talón y unidades pedidas, pendientes y facturadas.']],
        },
        tableHeaders: {
            'tabla-efi-unid-cliente': [
                'Cliente.',
                'Unidades pedidas en el período.',
                'Unidades facturadas en el período.',
                'Eficiencia: facturadas / pedidas. Verde ≥ 95%, amarillo 85–95%, rojo < 85%.',
            ],
            'tabla-efi-unid-rubro': [
                'Rubro.',
                'Unidades pedidas en el período.',
                'Unidades facturadas en el período.',
                'Eficiencia: facturadas / pedidas. Verde ≥ 95%, amarillo 85–95%, rojo < 85%.',
            ],
            'tabla-efi-pedidos': [
                'Cliente (agrupado) / número de pedido al desplegar.',
                'Fecha del pedido.',
                'Unidades pedidas.',
                'Unidades facturadas.',
                'Eficiencia: facturadas / pedidas.',
            ],
            'tabla-stock': [
                'Rubro logístico.',
                'Stock registrado en Tango.',
                'Stock registrado en WMS.',
                'Diferencia neta (WMS − Tango).',
                'Diferencia porcentual sobre Tango.',
                'Precisión: coincidencia exacta entre WMS y Tango.',
            ],
            'tabla-usuarios-fact': [
                'Usuario de facturación (agrupado sin distinguir mayúsculas).',
                'Unidades facturadas en el período (según tipo y rubro).',
                'Participación sobre el total facturado del período.',
                'Pico: máximo de unidades en un solo día (no depende del tipo).',
                'Tendencia: mediana de unidades por día del usuario.',
                'Días productivos (no depende del tipo de facturación).',
                'Unidades facturadas en los últimos 30 días.',
            ],
            'tabla-usuarios-picking': [
                'Usuario picker.',
                'Unidades pickeadas en el período.',
                'Participación sobre el total del equipo.',
                'Pico: máximo de unidades en un solo día.',
                'Mediana de unidades por día.',
                'Horas productivas registradas.',
                'Promedio de unidades por hora productiva.',
                'Unidades pickeadas en los últimos 30 días.',
            ],
            'tabla-pend': [
                'Número de pedido.',
                'Código de cliente.',
                'Nombre del cliente.',
                'Canal del pedido.',
                'Fecha comprometida de entrega.',
                'Unidades pendientes de despacho.',
            ],
            'tabla-plan-demorados': [
                'Número de pedido.',
                'Nombre del cliente.',
                'Canal del pedido.',
                'Fecha de entrega comprometida (ya vencida).',
                'Días de atraso (negativo = vencido hace N días).',
                'Unidades pendientes de despacho.',
            ],
            'tabla-efi-cliente': [
                'Nombre del cliente.',
                'Eficacia de despacho (en término / despachados).',
                'Desvío promedio en días (negativo = atraso).',
                'Comprobantes despachados.',
            ],
            'tabla-efi-pedido': [
                'Nombre del cliente.',
                'Número de pedido.',
                'Número de comprobante.',
                'Fecha comprometida de despacho.',
                'Fecha de guía (despacho real).',
                'Desvío en días (negativo = tarde).',
            ],
            'tabla-dem-cliente': [
                'Nombre del cliente.',
                'Promedio de días de demora.',
                'Cantidad de pedidos demorados.',
            ],
            'tabla-dem-pedido': [
                'Nombre del cliente.',
                'Número de pedido.',
                'Número de comprobante.',
                'Fecha comprometida de despacho.',
                'Estado de despacho.',
                'Días de demora (negativo = vencido).',
            ],
            'tabla-pedidos': [
                'Número de pedido.',
                'Canal del pedido.',
                'Estado actual del pedido.',
                'Fecha de ingreso del pedido.',
                'Talón asociado.',
                'Unidades pedidas.',
                'Unidades pendientes de facturar.',
                'Unidades ya facturadas.',
            ],
        },
    };

    // ── Fetch AJAX ────────────────────────────────────────────────────────
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

    // ── Inicialización de selectores ──────────────────────────────────────
    async function initFiltros() {
        try {
            const json = await fetchFiltros();
            if (!json.ok) return;
            poblarSelect('#sel-canal',   json.canales.filter(c => c.toUpperCase() !== 'DESCONOCIDO'),  'Todos');
            poblarSelect('#sel-rubro',   json.rubros_stock,     'Todos');
            poblarSelect('#sel-usuario', json.usuarios_fact,    'Todos');
            poblarSelect('#sel-cliente', json.clientes_despacho, 'Todos');
            poblarSelect('#sel-tipo',    json.tipos_fact,        'Todas');
        } catch (e) { console.error('Filtros:', e); }
    }

    function poblarSelect(sel, items, placeholder) {
        const $s = $(sel).empty().append($('<option>').val('').text(placeholder));
        (items || []).forEach(v => $s.append($('<option>').val(v).text(v)));
    }

    function fetchFiltros() {
        if (!filtrosPromise) {
            filtrosPromise = fetch(BASE + 'filtros.php').then(res => res.json());
        }
        return filtrosPromise;
    }

    // ── Slicers por tab ───────────────────────────────────────────────────
    function updateSlicers(tab) {
        $('#wrap-canal, #wrap-rubro, #wrap-deposito, #wrap-usuario, #wrap-cliente, #wrap-tipo').hide();
        (TAB_SLICERS[tab] || []).forEach(id => $('#' + id).show());
        if (tab === 'prod-picking') {
            fetchFiltros()
                .then(json => { if (json.ok) poblarSelect('#sel-usuario', json.usuarios_picking, 'Todos'); })
                .catch(() => {});
        } else if (tab === 'prod-fact') {
            // En Facturación el slicer Rubro usa los rubros de facturación.
            fetchFiltros()
                .then(json => {
                    if (!json.ok) return;
                    poblarSelect('#sel-tipo',  json.tipos_fact,  'Todas');
                    poblarSelect('#sel-rubro', json.rubros_fact, 'Todas');
                    $('#sel-tipo').val(State.tipo || '');
                    $('#sel-rubro').val(State.rubroFact || '');
                })
                .catch(() => {});
        } else if (tab === 'stock') {
            // Inventario usa los rubros y depósitos de stock.
            fetchFiltros()
                .then(json => {
                    if (!json.ok) return;
                    poblarSelect('#sel-rubro', json.rubros_stock, 'Todos');
                    $('#sel-rubro').val(State.rubro || '');
                    poblarSelect('#sel-deposito', json.depositos_stock, 'Todos');
                    $('#sel-deposito').val(State.deposito || '');
                })
                .catch(() => {});
        }
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
                case 'eficiencia':    await loadEficiencia(); break;
                case 'leadtime':      await loadLeadTime();   break;
                case 'stock':         await loadStock();       break;
                case 'prod-fact':     await loadProdFact();   break;
                case 'prod-picking':  await loadProdPicking();break;
                case 'planificacion': await loadPlanificacion(); break;
                case 'despacho':      await loadDespacho();   break;
                case 'pedidos':       await loadPedidos();    break;
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

    // ── Área 1: Eficiencia ────────────────────────────────────────────────
    async function loadEficiencia() {
        const data = await apiFetch('eficiencia', { canal: State.canal });
        const k = data.kpis || {};

        $('#kv-efi').text(fmt.pct(k.EFI_UNIDADES));
        setVar('#kvar-efi', fmt.varLabel(k.EFI_UNIDADES, k.META_EFICIENCIA));

        $('#kv-unid-ped').text(fmt.num(k.UNID_PEDIDAS));
        setVar('#kvar-unid-ped', fmt.varPct(k.UNID_PEDIDAS, k.UNID_PEDIDAS_AA));

        $('#kv-unid-fact').text(fmt.num(k.UNID_FACTURADAS));

        // Monto sin centavos (el importe es grande) para que no se amontone.
        $('#kv-perdida').text(
            (k.PERDIDA_FACT == null || isNaN(k.PERDIDA_FACT)) ? '—' : '$ ' + fmt.num(k.PERDIDA_FACT, 0)
        );
        $('#kvar-perdida')
            .html('% Pérd.: ' + fmt.pct(k.PCT_PERDIDA) +
                  '<span class="kpi-var-sub">prev. ' + fmt.pct(k.PCT_PERDIDA_AA) + '</span>')
            .removeClass('pos neg neu')
            .addClass('kpi-var ' + (k.PCT_PERDIDA > 0.05 ? 'neg' : 'neu'));

        $('#kv-importe').text(fmt.money(k.IMPORTE_FACTURADO));

        $('#kv-pedidos').text(fmt.num(k.PEDIDOS_TOTAL));
        setVar('#kvar-pedidos', fmt.varPct(k.PEDIDOS_TOTAL, k.PEDIDOS_AA));

        // Gauges por canal
        const canalEfi = (data.eficiencia_canal || []).filter(c => {
            const n = (c.CANAL || '').trim().toUpperCase();
            return n !== '' && n !== 'DESCONOCIDO';
        });
        const $gw = $('#gauges-canal').empty();
        chartsCanalEfi.forEach(c => c.destroy());
        chartsCanalEfi = [];

        const metaGaugeLine = {
            id: 'metaGaugeLine',
            afterDraw(chart) {
                const arc = chart.getDatasetMeta(0).data[0];
                if (!arc) return;
                const { ctx } = chart;
                const { x: cx, y: cy, innerRadius, outerRadius } = arc;
                const angle = -Math.PI + 0.95 * Math.PI;
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(cx + (innerRadius - 4) * Math.cos(angle), cy + (innerRadius - 4) * Math.sin(angle));
                ctx.lineTo(cx + (outerRadius + 4) * Math.cos(angle), cy + (outerRadius + 4) * Math.sin(angle));
                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 2.5;
                ctx.lineCap = 'round';
                ctx.stroke();
                ctx.restore();
            },
        };

        if (canalEfi.length) {
            canalEfi.forEach((c, i) => {
                const ped   = parseFloat(c.UNID_PEDIDAS)    || 0;
                const fact  = parseFloat(c.UNID_FACTURADAS) || 0;
                const efi   = ped > 0 ? fact / ped : 0;
                const color = efi >= 0.95 ? '#16a34a' : efi >= 0.85 ? '#f59e0b' : '#dc2626';
                const id    = 'gauge-canal-' + i;
                $gw.append(`<div class="gauge-card" title="${escapeHtml(c.CANAL)}: ${fmt.pct(efi)} de eficiencia">
                    <div class="gauge-wrap">
                        <canvas id="${id}" class="gauge-canvas"></canvas>
                        <div class="gauge-pct" style="color:${color}">${fmt.pct(efi)}</div>
                    </div>
                    <div class="gauge-label">${c.CANAL}</div>
                </div>`);
                chartsCanalEfi.push(new Chart(document.getElementById(id), {
                    type: 'doughnut',
                    plugins: [metaGaugeLine],
                    data: {
                        datasets: [{
                            data: [efi * 100, 100 - efi * 100],
                            backgroundColor: [color, '#e5e7eb'],
                            borderWidth: 0,
                            hoverOffset: 0,
                        }],
                    },
                    options: {
                        rotation: -90,
                        circumference: 180,
                        cutout: '72%',
                        responsive: true,
                        maintainAspectRatio: true,
                        aspectRatio: 2,
                        animation: { duration: 600 },
                        plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    },
                }));
            });
        } else {
            $gw.html('<div class="empty-state"><i class="bi bi-inbox"></i>Sin datos de canal</div>');
        }

        // Tablas de eficiencia por unidades
        renderEfiUnidTabla('#tbody-efi-unid-cliente', data.efi_cliente || [], 'CLIENTE');
        renderEfiUnidTabla('#tbody-efi-unid-rubro',   data.efi_rubro   || [], 'RUBRO');
        renderEfiPedidos(data.efi_pedidos || []);

        // Mini-gráfico de pérdida (últ. 12 meses) dentro del KPI
        perdida12mData = data.perdida_12m || [];
        buildPerdidaSpark(perdida12mData);

        // Gráfico evolución interanual + umbrales
        renderEvolucionEfi(data.evolucion || []);
    }

    // Etiqueta corta de mes ("Ene 25") para series de 12 / 24 meses.
    function mesAbbr(r) {
        return String(r.NOMBRE_MES || '').substring(0, 3) + ' ' + String(r.ANIO || '').slice(-2);
    }

    // Plugin: bandas de umbral (verde ≥95, amarillo 85–95, rojo <85) en el área del gráfico.
    const umbralesBands = {
        id: 'umbralesBands',
        beforeDatasetsDraw(chart) {
            const y = chart.scales.y;
            const area = chart.chartArea;
            if (!y || !area) return;
            const bands = [
                { from: 0,  to: 85,  color: 'rgba(220,38,38,0.06)' },
                { from: 85, to: 95,  color: 'rgba(245,158,11,0.07)' },
                { from: 95, to: 100, color: 'rgba(22,163,74,0.08)' },
            ];
            const { ctx } = chart;
            ctx.save();
            bands.forEach(b => {
                const yTop = y.getPixelForValue(Math.min(b.to, y.max));
                const yBot = y.getPixelForValue(Math.max(b.from, y.min));
                ctx.fillStyle = b.color;
                ctx.fillRect(area.left, yTop, area.right - area.left, yBot - yTop);
            });
            ctx.restore();
        },
    };

    // Evolución mensual interanual de eficiencia (una línea por año) + umbrales.
    function renderEvolucionEfi(evol) {
        const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        const byYear = {};
        (evol || []).forEach(r => {
            const y   = String(r.ANIO);
            const m   = parseInt(r.MES, 10);
            const ped = parseFloat(r.UNID_PEDIDAS_MES) || 0;
            const fct = parseFloat(r.UNID_FACTURADAS_MES) || 0;
            (byYear[y] = byYear[y] || {})[m] = ped > 0 ? parseFloat((fct / ped * 100).toFixed(1)) : null;
        });
        const years = Object.keys(byYear).sort();
        const yearColors = { prev: '#2563eb', curr: '#f59e0b' };
        const datasets = years.map((y, idx) => {
            const isCurrent = idx === years.length - 1;
            return {
                label          : y,
                data           : MESES.map((_, i) => byYear[y][i + 1] ?? null),
                borderColor    : isCurrent ? yearColors.curr : yearColors.prev,
                backgroundColor : isCurrent ? 'rgba(245,158,11,.10)' : 'rgba(37,99,235,.05)',
                borderWidth    : isCurrent ? 2.5 : 2,
                pointRadius    : 3,
                tension        : 0.3,
                fill           : isCurrent,
                spanGaps       : true,
            };
        });
        datasets.push({
            label: 'Meta (95%)', data: MESES.map(() => 95),
            borderColor: '#dc2626', borderWidth: 1.5, borderDash: [6, 4], pointRadius: 0, fill: false,
        });

        // Eje Y con zoom dinámico al rango real (las líneas suelen estar 90–99%).
        // Piso redondeado 5 pts por debajo del mínimo, pero nunca por encima de
        // 85% para conservar el contexto de los umbrales y la meta.
        const valores = datasets
            .filter(d => d.label !== 'Meta (95%)')
            .flatMap(d => d.data)
            .filter(v => v != null && isFinite(v));
        const dataMin = valores.length ? Math.min(...valores) : 0;
        const yMin = valores.length
            ? Math.max(0, Math.min(85, Math.floor((dataMin - 5) / 5) * 5))
            : 0;

        // Tooltip agrupado: al pasar el mouse por un mes, una sola tarjeta con
        // el valor de todas las líneas (años) de ese punto.
        const opts = chartOptions('Eficiencia (%)', { min: yMin, max: 100 }, { pct: true });
        opts.interaction = { mode: 'index', intersect: false };
        opts.plugins.tooltip.mode = 'index';
        opts.plugins.tooltip.intersect = false;
        opts.plugins.tooltip.filter = item => item.dataset.label !== 'Meta (95%)' && item.parsed.y != null;

        if (chartEfi) chartEfi.destroy();
        chartEfi = new Chart($('#chart-eficiencia')[0], {
            type: 'line',
            plugins: [umbralesBands],
            data: { labels: MESES, datasets },
            options: opts,
        });
    }

    // ── Tablas de eficiencia por unidades (cliente / rubro) ───────────────
    function renderEfiUnidTabla(sel, rows, nameKey) {
        const $tb = $(sel).empty();
        if (!rows || !rows.length) {
            $tb.append('<tr><td colspan="4"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }
        $tb.html(rows.map(r => {
            const ped = parseFloat(r.UNID_PEDIDAS)     || 0;
            const fac = parseFloat(r.UNID_FACTURADAS)  || 0;
            const efi = ped > 0 ? fac / ped : null;
            return `<tr><td title="${escapeHtml(r[nameKey] || '')}">${escapeHtml(r[nameKey] || '—')}</td>` +
                   `<td class="col-num">${fmt.num(ped)}</td>` +
                   `<td class="col-num">${fmt.num(fac)}</td>` +
                   `<td class="col-num">${efiCell(efi)}</td></tr>`;
        }).join(''));
    }

    // ── Tabla drill: % Eficiencia por pedido, agrupada por cliente ────────
    function renderEfiPedidos(rows) {
        const map = new Map();
        (rows || []).forEach(r => {
            const cli = (r.CLIENTE || '—');
            let g = map.get(cli);
            if (!g) { g = { cliente: cli, ped: 0, fact: 0, pedidos: [] }; map.set(cli, g); }
            const ped = parseFloat(r.UNID_PEDIDAS)    || 0;
            const fac = parseFloat(r.UNID_FACTURADAS) || 0;
            g.ped += ped; g.fact += fac;
            g.pedidos.push({ nro: r.NRO_PEDIDO, fecha: r.FECHA_PEDI, ped, fac });
        });
        efiPedidosGroups = [...map.values()]
            .map(g => ({ ...g, efi: g.ped > 0 ? g.fact / g.ped : null }))
            .sort((a, b) => (a.efi == null ? 99 : a.efi) - (b.efi == null ? 99 : b.efi));

        const $tb = $('#tbody-efi-pedidos').empty();
        if (!efiPedidosGroups.length) {
            $tb.append('<tr><td colspan="5"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }
        $tb.html(efiPedidosGroups.map((g, i) =>
            `<tr class="rubro-row expandible efi-cli-row" data-cli="${i}">
                <td><i class="bi bi-chevron-right caret"></i> ${escapeHtml(g.cliente)} <span class="badge-arts">${g.pedidos.length}</span></td>
                <td>—</td>
                <td class="col-num">${fmt.num(g.ped)}</td>
                <td class="col-num">${fmt.num(g.fact)}</td>
                <td class="col-num">${efiCell(g.efi)}</td>
            </tr>`
        ).join(''));
    }

    function toggleEfiPedidos($row) {
        const idx   = parseInt($row.data('cli'), 10);
        const $next = $row.next('.detalle-row');
        if ($next.length) { $next.remove(); $row.removeClass('abierto'); return; }
        const g = efiPedidosGroups[idx];
        if (!g) return;
        const filas = g.pedidos
            .map(p => ({ ...p, efi: p.ped > 0 ? p.fac / p.ped : null }))
            .sort((a, b) => (a.efi == null ? 99 : a.efi) - (b.efi == null ? 99 : b.efi))
            .map(p => `<tr class="pedido-row" data-pedido="${escapeHtml(String(p.nro).trim())}">
                <td class="efi-ped-nro">${escapeHtml(String(p.nro).trim())}</td>
                <td>${fmt.date(p.fecha)}</td>
                <td class="col-num">${fmt.num(p.ped)}</td>
                <td class="col-num">${fmt.num(p.fac)}</td>
                <td class="col-num">${efiCell(p.efi)}</td>
            </tr>`).join('');
        const sub = `<tr class="detalle-row"><td colspan="5">
            <table class="tabla-sub">
                <tbody>${filas}</tbody>
            </table>
        </td></tr>`;
        $row.addClass('abierto').after(sub);
    }

    // ── Mini-gráfico de pérdida (sparkline dentro del KPI) ────────────────
    function buildPerdidaSpark(rows) {
        if (chartPerdidaSpark) { chartPerdidaSpark.destroy(); chartPerdidaSpark = null; }
        const el = document.getElementById('spark-perdida');
        if (!el || !rows || !rows.length) return;
        const imp  = rows.map(r => parseFloat(r.PERDIDA_FACT) || 0);
        const prop = rows.map(r => {
            const ped = parseFloat(r.IMPORTE_PEDIDO) || 0;
            return ped > 0 ? (parseFloat(r.PERDIDA_FACT) || 0) / ped * 100 : null;
        });
        chartPerdidaSpark = new Chart(el, {
            type: 'line',
            data: {
                labels: rows.map(mesAbbr),
                datasets: [
                    { data: imp,  borderColor: '#f59e0b', borderWidth: 1.5, pointRadius: 0, tension: .35, yAxisID: 'y',  spanGaps: true },
                    { data: prop, borderColor: '#2563eb', borderWidth: 1.5, pointRadius: 0, tension: .35, yAxisID: 'y2', spanGaps: true },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false }, y2: { display: false } },
            },
        });
    }

    // ── Modal: proporción e importe de pérdida fact. (últ. 12 meses) ──────
    function openPerdidaModal() {
        const rows = perdida12mData || [];
        $('#perdida-12m-modal').removeAttr('hidden');
        $('body').addClass('modal-open');

        const totImp = rows.reduce((s, r) => s + (parseFloat(r.PERDIDA_FACT)  || 0), 0);
        const totPed = rows.reduce((s, r) => s + (parseFloat(r.IMPORTE_PEDIDO) || 0), 0);
        $('#perdida12-meta').html(
            pmetaItem('Meses', fmt.num(rows.length)) +
            pmetaItem('Pérdida total', fmt.money(totImp)) +
            pmetaItem('% Pérdida prom.', totPed > 0 ? fmt.pct(totImp / totPed) : '—')
        );

        if (chartPerdidaModal) { chartPerdidaModal.destroy(); chartPerdidaModal = null; }
        const el = document.getElementById('chart-perdida-12m');
        if (!el || !rows.length) {
            if (!rows.length) $('#perdida12-meta').append('<div class="pmodal-note">Sin datos en los últimos 12 meses.</div>');
            return;
        }
        const imp  = rows.map(r => parseFloat(r.PERDIDA_FACT) || 0);
        const prop = rows.map(r => {
            const ped = parseFloat(r.IMPORTE_PEDIDO) || 0;
            return ped > 0 ? parseFloat(((parseFloat(r.PERDIDA_FACT) || 0) / ped * 100).toFixed(2)) : null;
        });
        chartPerdidaModal = new Chart(el, {
            data: {
                labels: rows.map(mesAbbr),
                datasets: [
                    { type: 'bar',  label: 'Importe pérdida ($)', data: imp,  backgroundColor: 'rgba(245,158,11,.55)', borderRadius: 4, yAxisID: 'y',  order: 2 },
                    { type: 'line', label: '% Pérdida',           data: prop, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.10)', borderWidth: 2, pointRadius: 3, tension: .3, yAxisID: 'y2', order: 1, spanGaps: true },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 14, font: { size: 12 } } },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.92)', titleColor: '#f8fafc', bodyColor: '#cbd5e1',
                        callbacks: {
                            label(ctx) {
                                const v = ctx.parsed.y;
                                if (v == null) return null;
                                return ctx.dataset.yAxisID === 'y2'
                                    ? ' ' + ctx.dataset.label + ': ' + fmt.num(v, 1) + '%'
                                    : ' ' + ctx.dataset.label + ': ' + fmt.money(v);
                            },
                        },
                    },
                },
                scales: {
                    x:  { ticks: { font: { size: 11 } } },
                    y:  { position: 'left',  beginAtZero: true, title: { display: true, text: 'Importe ($)' }, ticks: { callback: v => fmt.num(v, 0) } },
                    y2: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: '% Pérdida' }, ticks: { callback: v => fmt.num(v, 0) + '%' } },
                },
            },
        });
    }
    function closePerdidaModal() {
        $('#perdida-12m-modal').attr('hidden', '');
        if (chartPerdidaModal) { chartPerdidaModal.destroy(); chartPerdidaModal = null; }
        if (!$('.pmodal:not([hidden])').length) $('body').removeClass('modal-open');
    }

    // ── Área 2: Lead Time ─────────────────────────────────────────────────
    async function loadLeadTime() {
        const data = await apiFetch('leadtime');
        const k = data.kpis || {};

        $('#kv-lt-total').text(fmt.num(k.COMP_FACTURADOS));
        $('#kv-lt-dem').text(fmt.num(k.COMP_DEMORADOS));
        const pctDem = k.PCT_DEMORADOS;
        setVar('#kvar-lt-dem', { text: fmt.pct(pctDem), cls: pctDem > 0.1 ? 'neg' : 'pos' });
        $('#kv-lt-prom').text(fmt.num(k.LEAD_TIME_PROMEDIO, 1));
        $('#kv-lt-abiertos').text(fmt.num(k.PEDIDOS_ABIERTOS));

        const hist = data.histograma || [];
        if (chartLtHist) chartLtHist.destroy();
        chartLtHist = new Chart($('#chart-leadtime-hist')[0], {
            type: 'bar',
            data: {
                labels  : hist.map(r => r.DIAS + ' d'),
                datasets: [{
                    label          : 'Comprobantes',
                    data           : hist.map(r => r.CANTIDAD),
                    backgroundColor: hist.map(r => parseInt(r.DIAS) > 5 ? 'rgba(220,38,38,.7)' : 'rgba(0,168,120,.7)'),
                    borderRadius   : 4,
                }],
            },
            options: { ...chartOptions('Comprobantes', {}, { integer: true }), plugins: { legend: { display: false } } },
        });

        const evol = data.evolucion || [];
        if (chartLtEvol) chartLtEvol.destroy();
        chartLtEvol = new Chart($('#chart-leadtime-evol')[0], {
            type: 'line',
            data: {
                labels  : evol.map(r => r.NOMBRE_MES),
                datasets: [{
                    label     : '% Fact. demorada',
                    data      : evol.map(r => {
                        const tot = parseInt(r.TOTAL_MES) || 0;
                        const dem = parseInt(r.DEMORADOS_MES) || 0;
                        return tot > 0 ? parseFloat((dem / tot * 100).toFixed(1)) : null;
                    }),
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239,68,68,.08)',
                    borderWidth: 2,
                    pointRadius: 4,
                    tension    : 0.3,
                    fill       : true,
                }],
            },
            options: chartOptions('% Fact. demorada', { min: 0 }, { pct: true }),
        });
    }

    // ── Área 3: Stock WMS vs Tango ────────────────────────────────────────
    async function loadStock() {
        const data = await apiFetch('stock', { rubro: State.rubro, deposito: State.deposito });
        const k = data.kpis || {};

        $('#kv-stock-tango').text(fmt.num(k.STOCK_TANGO));
        $('#kv-stock-wms').text(fmt.num(k.STOCK_WMS));
        $('#kv-stock-dif').text(fmt.num(k.DIFERENCIA));
        setVar('#kvar-stock-dif', {
            text: fmt.pct(k.DIF_PCT),
            cls : Math.abs(parseFloat(k.DIF_PCT || 0)) < 0.01 ? 'pos' : 'neg',
        });
        $('#kv-stock-dif-abs').text(fmt.num(k.DIFERENCIA_ABS));
        $('#kv-stock-prec').text(fmt.pct(k.PRECISION_INVENTARIO));

        const rubros = (data.rubros || []).slice(0, 15);
        if (chartStock) chartStock.destroy();
        chartStock = new Chart($('#chart-stock')[0], {
            type: 'bar',
            data: {
                labels  : rubros.map(r => r.RUBRO),
                datasets: [
                    { label: 'Stock Tango', data: rubros.map(r => r.STOCK_TANGO), backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 4 },
                    { label: 'Stock WMS',   data: rubros.map(r => r.STOCK_WMS),   backgroundColor: 'rgba(0,168,120,.65)', borderRadius: 4 },
                ],
            },
            options: chartOptions('Unidades', {}, { integer: true }),
        });

        stockDetalleArticulos = data.detalle_articulos || [];
        const $tbody = $('#tbody-stock').empty();
        if (!rubros.length) {
            $tbody.append('<tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            initStockExport();
            return;
        }
        $tbody.html(rubros.map(r => {
            const dif    = parseFloat(r.DIFERENCIA || 0);
            const nArts  = stockDetalleArticulos.filter(a => a.RUBRO === r.RUBRO).length;
            const expand = nArts > 0;
            return `<tr class="rubro-row${expand ? ' expandible' : ''}" data-rubro="${escapeHtml(r.RUBRO || '')}">
                <td>${expand ? '<i class="bi bi-chevron-right caret"></i> ' : ''}${escapeHtml(r.RUBRO || '')}${expand ? ` <span class="badge-arts">${nArts}</span>` : ''}</td>
                <td class="col-num">${fmt.num(r.STOCK_TANGO)}</td>
                <td class="col-num">${fmt.num(r.STOCK_WMS)}</td>
                <td class="col-num ${dif !== 0 ? (dif < 0 ? 'var-neg' : 'var-pos') : ''}">${fmt.num(dif)}</td>
                <td class="col-num ${dif !== 0 ? 'var-neg' : ''}">${fmt.pct(r.DIF_PCT)}</td>
                <td class="col-num ${parseFloat(r.PRECISION||0) >= 0.99 ? 'var-pos' : 'var-neg'}">${fmt.pct(r.PRECISION)}</td>
            </tr>`;
        }).join(''));

        // Botón "Exportar a Excel" del detalle por rubro (se inserta una sola vez)
        initStockExport();
    }

    // ── Drill-down: artículos con diferencia de un rubro ──────────────────
    function toggleDrillRubro($row) {
        const rubro = $row.data('rubro');
        const $next = $row.next('.detalle-row');
        if ($next.length) { $next.remove(); $row.removeClass('abierto'); return; }

        const arts = stockDetalleArticulos
            .filter(a => a.RUBRO === rubro)
            .sort((a, b) => Math.abs(parseFloat(b.DIFERENCIA || 0)) - Math.abs(parseFloat(a.DIFERENCIA || 0)));

        const filas = arts.map(a => {
            const dif = parseFloat(a.DIFERENCIA || 0);
            return `<tr>
                <td>${escapeHtml(a.COD_ARTICU || '—')}</td>
                <td>${escapeHtml(a.DESCRIPCION || '—')}</td>
                <td class="col-num">${fmt.num(a.STOCK_TANGO)}</td>
                <td class="col-num">${fmt.num(a.STOCK_WMS)}</td>
                <td class="col-num ${dif < 0 ? 'var-neg' : 'var-pos'}">${fmt.num(dif)}</td>
            </tr>`;
        }).join('');

        const sub = `<tr class="detalle-row"><td colspan="6">
            <table class="tabla-sub">
                <thead><tr>
                    <th>Código</th><th>Descripción</th>
                    <th class="col-num">Stock Tango</th>
                    <th class="col-num">Stock WMS</th>
                    <th class="col-num">Diferencia</th>
                </tr></thead>
                <tbody>${filas}</tbody>
            </table>
        </td></tr>`;

        $row.addClass('abierto').after(sub);
    }

    // ── Exportar a Excel: detalle de artículos con diferencias ────────────
    function initStockExport() {
        if (stockExportInit || typeof ExcelExporter === 'undefined') return;
        const hdr = document.getElementById('hdr-stock-detalle');
        if (!hdr) return;
        ExcelExporter.addExportButton(hdr, exportDetalleArticulos);
        stockExportInit = true;
    }

    function exportDetalleArticulos() {
        if (!stockDetalleArticulos.length) {
            alert('No hay artículos con diferencias para exportar.');
            return;
        }
        const rows = stockDetalleArticulos
            .slice()
            .sort((a, b) =>
                (a.RUBRO || '').localeCompare(b.RUBRO || '') ||
                Math.abs(parseFloat(b.DIFERENCIA || 0)) - Math.abs(parseFloat(a.DIFERENCIA || 0)))
            .map(a => [
                a.RUBRO || '',
                a.COD_ARTICU || '',
                a.DESCRIPCION || '',
                parseFloat(a.STOCK_TANGO || 0),
                parseFloat(a.STOCK_WMS   || 0),
                parseFloat(a.DIFERENCIA  || 0),
            ]);
        ExcelExporter.export({
            title     : 'Stock Tango vs WMS — Artículos con diferencias',
            headers   : ['Rubro', 'Código', 'Descripción', 'Stock Tango', 'Stock WMS', 'Diferencia'],
            rows,
            colFormats: ['text', 'text', 'text', 'num', 'num', 'num'],
            filename  : 'stock_articulos_con_diferencias',
        });
    }

    // ── Área 4: Productividad Facturación ─────────────────────────────────
    async function loadProdFact() {
        const data = await apiFetch('productividad_fact', { tipo: State.tipo, rubro: State.rubroFact });
        const k = data.kpis || {};

        $('#kv-pf-prom-dia').text(fmt.num(k.PROMEDIO_DIA));
        $('#kv-pf-pico-dia').text(fmt.num(k.PICO_DIA));
        $('#kv-pf-pico-dia-fecha').text(fmt.date(k.PICO_DIA_FECHA));
        $('#kv-pf-pico-user').text(fmt.num(k.PICO_USUARIO));
        $('#kv-pf-pico-user-nombre').text(k.PICO_USUARIO_NOMBRE || '—');
        $('#kv-pf-tendencia').text(fmt.num(k.TENDENCIA_GLOBAL));

        // Gráfico doble serie: total del día (barras) + mejor usuario del día (línea)
        const evol = data.evolucion || [];
        if (chartProdFact) chartProdFact.destroy();
        chartProdFact = new Chart($('#chart-prod-fact')[0], {
            type: 'bar',
            data: {
                labels  : evol.map(r => fmt.date(r.FECHA_COMP)),
                datasets: [{
                    label: 'Unidades fact. x día', data: evol.map(r => r.UNIDADES_DIA),
                    backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 3, yAxisID: 'y',
                }, {
                    label: 'Pico fact. x usuario', data: evol.map(r => r.PICO_USER),
                    type: 'line', borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.15)',
                    borderWidth: 2, pointRadius: 3, tension: 0.3, fill: false, yAxisID: 'y1',
                }],
            },
            options: {
                ...chartOptions('Unidades', {}, { integer: true }),
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } },
                    tooltip: {
                        mode: 'index', intersect: false,
                        backgroundColor: 'rgba(15,23,42,0.92)', titleColor: '#f8fafc',
                        bodyColor: '#cbd5e1', borderColor: 'rgba(255,255,255,0.12)', borderWidth: 1, padding: 10,
                        callbacks: {
                            label(ctx) {
                                const v = ctx.parsed.y;
                                if (v == null) return null;
                                return ' ' + ctx.dataset.label + ': ' + Number(v).toLocaleString('es-AR', { maximumFractionDigits: 0 });
                            },
                        },
                    },
                },
                scales: {
                    x : { ticks: { maxRotation: 45, font: { size: 10 } } },
                    y : { beginAtZero: true, title: { display: true, text: 'Unidades fact. x día' } },
                    y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Pico fact. x usuario' }, grid: { drawOnChartArea: false } },
                },
            },
        });

        const $tbody = $('#tbody-usuarios-fact').empty();
        const usuarios = data.usuarios || [];
        if (!usuarios.length) {
            $tbody.append('<tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
        } else {
            $tbody.html(usuarios.map(u => `<tr>
                <td>${u.USUARIO || '—'}</td>
                <td class="col-num">${u.UNIDADES_FACT == null ? '' : fmt.num(u.UNIDADES_FACT)}</td>
                <td class="col-num">${u.PCT_UNIDADES == null ? '' : fmt.pct(u.PCT_UNIDADES, 2)}</td>
                <td class="col-num">${fmt.num(u.PICO_FACT)}</td>
                <td class="col-num">${u.TENDENCIA == null ? '' : fmt.num(u.TENDENCIA)}</td>
                <td class="col-num">${fmt.num(u.DIAS_PRODUCTIVOS)}</td>
                <td class="col-num">${u.UNIDADES_ULT30 ? fmt.num(u.UNIDADES_ULT30) : ''}</td>
            </tr>`).join(''));
        }

        renderUlt7('fact-ult7', data.ultimos7 || [], { hours: false, title: 'Facturación del' });
    }

    // ── Área 5: Productividad Picking ─────────────────────────────────────
    async function loadProdPicking() {
        const data = await apiFetch('productividad_picking', { usuario: State.usuario });
        const k = data.kpis || {};

        $('#kv-pp-prom-dia').text(fmt.num(k.PROM_UNID_DIA));
        $('#kv-pp-pico-dia').text(fmt.num(k.PICO_DIA_UNIDADES));
        $('#kv-pp-pico-dia-fecha').text(fmt.date(k.PICO_DIA_FECHA));
        $('#kv-pp-pico-usuario').text(fmt.num(k.PICO_USUARIO_UNIDADES));
        $('#kv-pp-pico-usuario-nombre').text(k.PICO_USUARIO || '—');
        $('#kv-pp-hs').text(fmt.num(k.TIEMPO_PRODUCTIVO_HS, 1));
        $('#kv-pp-prom-hs').text(fmt.num(k.PROM_TIEMPO_PROD_HS, 2));
        $('#kv-pp-u-hora').text(fmt.num(k.PROM_UNID_HORA, 1));
        $('#kv-pp-prom').text(fmt.num(k.PROM_UNID_PICKERS, 1));

        const evol = data.evolucion || [];
        const canvasPP = $('#chart-prod-picking')[0];
        if (chartProdPicking) { chartProdPicking.destroy(); chartProdPicking = null; }
        if (canvasPP) chartProdPicking = new Chart(canvasPP, {
            type: 'bar',
            data: {
                labels  : evol.map(r => fmt.date(r.FECHA_INI_PICKING)),
                datasets: [{
                    label: 'Unidades pickeadas', data: evol.map(r => r.UNIDADES_DIA),
                    backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 3, yAxisID: 'y',
                }, {
                    label: 'Promedio unid. x hora', data: evol.map(r => r.PROM_UNID_HORA),
                    type: 'line', borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.15)',
                    borderWidth: 2, pointRadius: 3, tension: 0.3, fill: false, yAxisID: 'y1',
                }],
            },
            options: {
                ...chartOptions('Unidades pickeadas', {}, { integer: true }),
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } },
                    tooltip: {
                        mode: 'index', intersect: false,
                        backgroundColor: 'rgba(15,23,42,0.92)', titleColor: '#f8fafc',
                        bodyColor: '#cbd5e1', borderColor: 'rgba(255,255,255,0.12)',
                        borderWidth: 1, padding: 10,
                        callbacks: {
                            label(ctx) {
                                const v = ctx.parsed.y;
                                if (v == null) return null;
                                if (ctx.datasetIndex === 0)
                                    return ' ' + ctx.dataset.label + ': ' + Number(v).toLocaleString('es-AR', { maximumFractionDigits: 0 });
                                return ' ' + ctx.dataset.label + ': ' + Number(v).toLocaleString('es-AR', { maximumFractionDigits: 1 }) + ' unid./h';
                            },
                        },
                    },
                },
                scales: {
                    x : { ticks: { maxRotation: 45, font: { size: 10 } } },
                    y : { beginAtZero: true, title: { display: true, text: 'Unidades' } },
                    y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Unid./hora' }, grid: { drawOnChartArea: false } },
                },
            },
        });

        const $tbody = $('#tbody-usuarios-picking').empty();
        const usuarios = data.usuarios || [];
        if (!usuarios.length) {
            $tbody.html('<tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
        } else {
            $tbody.html(usuarios.map(u => `<tr>
                <td>${u.USUARIO || '—'}</td>
                <td class="col-num">${fmt.num(u.UNIDADES)}</td>
                <td class="col-num">${fmt.pct(u.PCT_UNIDADES)}</td>
                <td class="col-num">${fmt.num(u.PICO_PICKING)}</td>
                <td class="col-num">${fmt.num(u.MEDIANA_PICKING, 1)}</td>
                <td class="col-num">${fmt.num(u.HORAS, 1)}</td>
                <td class="col-num">${fmt.num(u.PROM_UNID_HORA, 1)}</td>
                <td class="col-num">${fmt.num(u.UNIDADES_ULT30)}</td>
            </tr>`).join(''));
        }

        renderUlt7('picking-ult7', data.ultimos7 || [], { hours: true, title: 'Picking del' });
    }

    // Tabla "Últimos 7 días" genérica (picking y facturación).
    // base = sufijo de ids (#thead-<base>, #tbody-<base>); opts.hours añade
    // columna de horas; opts.title prefija el modal del mini-gráfico.
    function renderUlt7(base, rows, opts) {
        opts = opts || {};
        const hasHoras = !!opts.hours;
        rows = rows || [];
        ult7Data[base] = { rows: rows, title: opts.title || '' };
        const $thead = $('#thead-' + base).empty();
        const $tbody = $('#tbody-' + base).empty();
        const fechas   = [...new Set(rows.map(r => r.FECHA_PICK))].sort();
        const usuarios = [...new Set(rows.map(r => r.USUARIO || '—'))];

        if (!fechas.length || !usuarios.length) {
            $thead.html('<tr><th>Usuario</th></tr>');
            $tbody.html('<tr><td><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }

        $thead.html(
            '<tr>' +
            '<th>Usuario</th>' +
            fechas.map(f => `<th class="col-num pick-day-th" data-base="${base}" data-fecha="${f}" title="Ver gráfico del día ${fmt.date(f)}">` +
                `${fmt.date(f)} <i class="bi bi-bar-chart-line pick-day-ico"></i><br><span class="pick-sub">Unid.</span></th>`).join('') +
            '<th class="col-num col-total">Total<br><span class="pick-sub">Unid.</span></th>' +
            (hasHoras ? '<th class="col-num col-total">Total<br><span class="pick-sub">Hs. prod.</span></th>' : '') +
            '</tr>'
        );

        const byKey = new Map(rows.map(r => [(r.USUARIO || '—') + '|' + r.FECHA_PICK, r]));
        const totUnidFecha = Object.fromEntries(fechas.map(f => [f, 0]));
        let totUnidGlobal = 0, totHsGlobal = 0;

        $tbody.html(usuarios.map(usuario => {
            let sumUnid = 0, sumHoras = 0;
            const cols = fechas.map(fecha => {
                const r = byKey.get(usuario + '|' + fecha) || {};
                const unid = parseFloat(r.UNIDADES || 0);
                const hs   = parseFloat(r.HORAS    || 0);
                sumUnid += unid;
                sumHoras += hs;
                totUnidFecha[fecha] += unid;
                return `<td class="col-num">${unid > 0 ? fmt.num(unid) : ''}</td>`;
            }).join('');
            totUnidGlobal += sumUnid;
            totHsGlobal   += sumHoras;
            return `<tr><td>${usuario}</td>` + cols +
                `<td class="col-num pick-subtotal">${sumUnid > 0 ? fmt.num(sumUnid) : ''}</td>` +
                (hasHoras ? `<td class="col-num pick-subtotal">${sumHoras > 0 ? fmt.num(sumHoras, 1) : ''}</td>` : '') + '</tr>';
        }).join(''));

        const totalFechaCols = fechas.map(f =>
            `<td class="col-num">${totUnidFecha[f] > 0 ? fmt.num(totUnidFecha[f]) : ''}</td>`
        ).join('');
        $tbody.append(
            `<tr class="pick-total"><td>Total</td>` + totalFechaCols +
            `<td class="col-num">${totUnidGlobal > 0 ? fmt.num(totUnidGlobal) : ''}</td>` +
            (hasHoras ? `<td class="col-num">${totHsGlobal > 0 ? fmt.num(totHsGlobal, 1) : ''}</td>` : '') + '</tr>'
        );
    }

    // Mini-gráfico: unidades por usuario en un día (clic en columna de fecha).
    function openDiaChart(base, fecha) {
        if (!fecha) return;
        const cfg  = ult7Data[base] || { rows: [], title: '' };
        const rows = cfg.rows
            .filter(r => r.FECHA_PICK === fecha && (parseFloat(r.UNIDADES) || 0) > 0)
            .map(r => ({ u: r.USUARIO || '—', unid: parseFloat(r.UNIDADES) || 0, hs: parseFloat(r.HORAS) || 0 }))
            .sort((a, b) => b.unid - a.unid);

        $('#pdia-title').html(`<i class="bi bi-bar-chart-line"></i> ${cfg.title} ${fmt.date(fecha)}`);
        const totU = rows.reduce((s, r) => s + r.unid, 0);
        const hasHoras = cfg.rows.some(r => r.HORAS != null && r.HORAS !== '');
        let meta = pmetaItem('Fecha', fmt.date(fecha)) +
                   pmetaItem('Usuarios', fmt.num(rows.length)) +
                   pmetaItem('Unidades', fmt.num(totU));
        if (hasHoras) meta += pmetaItem('Hs. productivas', fmt.num(rows.reduce((s, r) => s + r.hs, 0), 1));
        $('#pdia-meta').html(meta);

        $('#picking-dia-modal').removeAttr('hidden');
        $('body').addClass('modal-open');

        if (chartPickingDia) { chartPickingDia.destroy(); chartPickingDia = null; }
        const el = document.getElementById('chart-picking-dia');
        if (!el || !rows.length) {
            if (!rows.length) $('#pdia-meta').append('<div class="pmodal-note">Sin actividad ese día.</div>');
            return;
        }
        chartPickingDia = new Chart(el, {
            type: 'bar',
            data: {
                labels: rows.map(r => r.u),
                datasets: [{ label: 'Unidades', data: rows.map(r => r.unid),
                             backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 3 }],
            },
            options: { ...chartOptions('Unidades', {}, { integer: true }), plugins: { legend: { display: false } } },
        });
    }
    function closePickingDia() {
        $('#picking-dia-modal').attr('hidden', '');
        if (chartPickingDia) { chartPickingDia.destroy(); chartPickingDia = null; }
        if (!$('.pmodal:not([hidden])').length) $('body').removeClass('modal-open');
    }

    // ── Gauge reutilizable (semicírculo con marca de meta) ────────────────
    function destroyGauges(arr) {
        arr.forEach(c => { try { c.destroy(); } catch (e) {} });
        arr.length = 0;
    }

    function gaugeColor(pct, meta) {
        if (pct == null) return '#e5e7eb';
        return pct >= meta ? '#16a34a' : pct >= 0.85 ? '#f59e0b' : '#dc2626';
    }

    function renderGauge(canvasId, pct, color, meta) {
        const el = document.getElementById(canvasId);
        if (!el) return null;
        const p = Math.max(0, Math.min(1, pct || 0)) * 100;
        const metaLine = {
            id: 'metaGaugeLine_' + canvasId,
            afterDraw(chart) {
                const arc = chart.getDatasetMeta(0).data[0];
                if (!arc) return;
                const { ctx } = chart;
                const { x: cx, y: cy, innerRadius, outerRadius } = arc;
                const angle = -Math.PI + (meta || 0) * Math.PI;
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(cx + (innerRadius - 4) * Math.cos(angle), cy + (innerRadius - 4) * Math.sin(angle));
                ctx.lineTo(cx + (outerRadius + 4) * Math.cos(angle), cy + (outerRadius + 4) * Math.sin(angle));
                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 2.5;
                ctx.lineCap = 'round';
                ctx.stroke();
                ctx.restore();
            },
        };
        return new Chart(el, {
            type: 'doughnut',
            plugins: [metaLine],
            data: { datasets: [{ data: [p, 100 - p], backgroundColor: [color, '#e5e7eb'], borderWidth: 0, hoverOffset: 0 }] },
            options: {
                rotation: -90, circumference: 180, cutout: '72%',
                responsive: true, maintainAspectRatio: true, aspectRatio: 2,
                animation: { duration: 600 },
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
            },
        });
    }

    // Formatea días con signo (negativo = demora) y color
    function fmtDias(v) {
        if (v == null || v === '' || isNaN(v)) return '—';
        return fmt.num(parseFloat(v), 1) + ' días';
    }

    // Celda de % eficacia con color semáforo (meta 95%)
    function efiCell(pct) {
        if (pct == null) return '—';
        const p = parseFloat(pct);
        const cls = p >= 0.95 ? 'pos' : p >= 0.85 ? '' : 'neg';
        const color = cls === 'pos' ? 'var(--pos)' : cls === 'neg' ? 'var(--neg)' : 'var(--accent3)';
        return `<span style="color:${color};font-weight:600">${fmt.pct(p, 0)}</span>`;
    }

    // ── Área 6a: Planificación ────────────────────────────────────────────
    async function loadPlanificacion() {
        const data = await apiFetch('planificacion', { canal: State.canal });
        const k = data.kpis || {};
        const v = data.ventanas || {};

        destroyGauges(chartsPlanGauges);
        const winMap = { hoy: 'HOY', prox: 'PROX', mas: 'MAS_UNO' };
        Object.entries(winMap).forEach(([dom, key]) => {
            const w    = v[key] || {};
            const pct  = (w.PCT_PICK == null || w.PCT_PICK === '') ? null : parseFloat(w.PCT_PICK);
            const meta = parseFloat(w.META) || 0.97;
            const color = gaugeColor(pct, meta);
            $(`#pl-${dom}-fecha`).text(fmt.date(w.FECHA));
            $(`#pl-${dom}-ped-tot`).text(fmt.num(w.PED_TOTAL));
            $(`#pl-${dom}-ped-pend`).text(fmt.num(w.PED_PEND));
            $(`#pl-${dom}-unid-tot`).text(fmt.num(w.UNID_TOTAL));
            $(`#pl-${dom}-unid-pend`).text(fmt.num(w.UNID_PEND));
            $(`#pl-${dom}-pickers`).text(fmt.num(w.PICKERS));
            $(`#pl-${dom}-pct`).text(pct == null ? '—' : fmt.pct(pct, 0)).css('color', color);
            chartsPlanGauges.push(renderGauge(`gauge-plan-${dom}`, pct || 0, color, meta));
        });
        $('#pl-hoy-dem').text(fmt.num(k.PED_DEMORADOS));

        State.pendientes = data.pendientes || [];
        renderPendientes();

        renderTablaSimple('#tbody-plan-demorados', data.demorados || [],
            r => `<td>${r.NRO_PEDIDO}</td><td title="${escapeHtml(r.NOMBRE_CLIENTE || '')}">${r.NOMBRE_CLIENTE || '—'}</td>` +
                 `<td>${r.CANAL || '—'}</td><td>${fmt.date(r.FECHA_ENTREGA)}</td>` +
                 `<td class="col-num">${fmt.num(r.DIAS)}</td><td class="col-num">${fmt.num(r.UNIDADES)}</td>`,
            r => r.NRO_PEDIDO
        );
    }

    function renderPendientes() {
        const f    = State.pendFiltro || 'HOY';
        const rows = (State.pendientes || []).filter(r => f === 'ALL' ? true : r.VENTANA === f);
        renderTablaSimple('#tbody-pend', rows,
            r => `<td>${r.NRO_PEDIDO}</td><td>${r.COD_CLIENT || '—'}</td>` +
                 `<td title="${escapeHtml(r.NOMBRE_CLIENTE || '')}">${r.NOMBRE_CLIENTE || '—'}</td>` +
                 `<td>${r.CANAL || '—'}</td><td>${fmt.date(r.FECHA_ENTREGA)}</td>` +
                 `<td class="col-num">${fmt.num(r.UNIDADES)}</td>`,
            r => r.NRO_PEDIDO
        );
    }

    // ── Área 6b: Despacho ─────────────────────────────────────────────────
    async function loadDespacho() {
        const data = await apiFetch('despacho', { canal: State.canal, cliente: State.cliente });
        const k = data.kpis || {};

        $('#kv-dsp-efi').text(fmt.pct(k.EFICACIA_TOTAL, 0));
        setVar('#kvar-dsp-efi', fmt.varLabel(k.EFICACIA_TOTAL, k.META));
        const demD = k.DESVIO_PROM_DEMORADOS, guiaD = k.DESVIO_PROM_GUIA;
        $('#kv-dsp-dem-dias').text(fmtDias(demD)).css('color', parseFloat(demD) < 0 ? 'var(--neg)' : 'var(--text-1)');
        $('#kv-dsp-guia-dias').text(fmtDias(guiaD)).css('color', parseFloat(guiaD) < 0 ? 'var(--neg)' : 'var(--text-1)');

        // Gauges por canal
        destroyGauges(chartsDespCanal);
        const $gw = $('#gauges-despacho-canal').empty();
        const canal = data.canal || [];
        if (!canal.length) {
            $gw.html('<div class="empty-state"><i class="bi bi-inbox"></i>Sin datos de canal</div>');
        } else {
            canal.forEach((c, i) => {
                const efi   = c.EFICACIA == null ? 0 : parseFloat(c.EFICACIA);
                const meta  = 0.95;
                const color = gaugeColor(efi, meta);
                const id    = 'gauge-dsp-canal-' + i;
                $gw.append(`<div class="gauge-card" title="${escapeHtml(c.CANAL)}: ${fmt.pct(efi, 0)} de eficacia">
                    <div class="gauge-wrap">
                        <canvas id="${id}" class="gauge-canvas"></canvas>
                        <div class="gauge-pct" style="color:${color}">${fmt.pct(efi, 0)}</div>
                    </div>
                    <div class="gauge-label">${escapeHtml(c.CANAL)}</div>
                </div>`);
                chartsDespCanal.push(renderGauge(id, efi, color, meta));
            });
        }

        // Evolución (una línea por año, eje X = meses) + umbrales
        const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        const evol  = data.evolucion || [];
        const years = [...new Set(evol.map(r => parseInt(r.ANIO)))].sort();
        const yearColors = { prev: '#2563eb', curr: '#f59e0b' };
        const datasets = years.map((y, idx) => {
            const isCurrent = idx === years.length - 1;
            const arr = Array(12).fill(null);
            evol.filter(r => parseInt(r.ANIO) === y).forEach(r => {
                arr[parseInt(r.MES) - 1] = r.EFICACIA == null ? null : parseFloat((parseFloat(r.EFICACIA) * 100).toFixed(1));
            });
            return {
                label          : String(y),
                data           : arr,
                borderColor    : isCurrent ? yearColors.curr : yearColors.prev,
                backgroundColor : isCurrent ? 'rgba(245,158,11,.10)' : 'rgba(37,99,235,.05)',
                borderWidth    : isCurrent ? 2.5 : 2,
                pointRadius    : 3,
                tension        : 0.3,
                fill           : isCurrent,
                spanGaps       : true,
            };
        });
        datasets.push({ label: 'Meta (95%)', data: Array(12).fill(95), borderColor: '#dc2626',
                        borderWidth: 1.5, borderDash: [6, 4], pointRadius: 0, fill: false });

        // Eje Y con zoom dinámico (piso 5 pts bajo el mínimo, tope 85%)
        const valoresDsp = datasets
            .filter(d => d.label !== 'Meta (95%)')
            .flatMap(d => d.data)
            .filter(v => v != null && isFinite(v));
        const yMinDsp = valoresDsp.length
            ? Math.max(0, Math.min(85, Math.floor((Math.min(...valoresDsp) - 5) / 5) * 5))
            : 0;

        const optsDsp = chartOptions('Eficacia (%)', { min: yMinDsp, max: 100 }, { pct: true });
        optsDsp.interaction = { mode: 'index', intersect: false };
        optsDsp.plugins.tooltip.mode = 'index';
        optsDsp.plugins.tooltip.intersect = false;
        optsDsp.plugins.tooltip.filter = item => item.dataset.label !== 'Meta (95%)' && item.parsed.y != null;

        if (chartDespEvol) chartDespEvol.destroy();
        chartDespEvol = new Chart($('#chart-despacho-evol')[0], {
            type: 'line',
            plugins: [umbralesBands],
            data: { labels: MESES, datasets },
            options: optsDsp,
        });

        // Tablas
        renderTablaSimple('#tbody-efi-cliente', data.eficacia_cliente || [],
            r => `<td title="${escapeHtml(r.CLIENTE || '')}">${r.CLIENTE || '—'}</td>` +
                 `<td class="col-num">${efiCell(r.EFICACIA)}</td>` +
                 `<td class="col-num">${fmt.num(r.DESVIO_PROM, 1)}</td>` +
                 `<td class="col-num">${fmt.num(r.TOTAL)}</td>`
        );
        renderTablaSimple('#tbody-efi-pedido', data.eficacia_pedido || [],
            r => `<td title="${escapeHtml(r.CLIENTE || '')}">${r.CLIENTE || '—'}</td>` +
                 `<td>${r.NRO_PEDIDO}</td><td>${r.N_COMP || '—'}</td>` +
                 `<td>${fmt.date(r.PROX_DESPACHO)}</td><td>${fmt.date(r.FECHA_GUIA)}</td>` +
                 `<td class="col-num">${fmt.num(r.DESVIO)}</td>`,
            r => r.NRO_PEDIDO
        );
        renderTablaSimple('#tbody-dem-cliente', data.demorados_cliente || [],
            r => `<td title="${escapeHtml(r.CLIENTE || '')}">${r.CLIENTE || '—'}</td>` +
                 `<td class="col-num">${fmt.num(r.DIAS_PROM, 1)}</td>` +
                 `<td class="col-num">${fmt.num(r.PEDIDOS)}</td>`
        );
        renderTablaSimple('#tbody-dem-pedido', data.demorados_pedido || [],
            r => `<td title="${escapeHtml(r.CLIENTE || '')}">${r.CLIENTE || '—'}</td>` +
                 `<td>${r.NRO_PEDIDO}</td><td>${r.N_COMP || '—'}</td>` +
                 `<td>${fmt.date(r.PROX_DESPACHO)}</td><td>${badgeEstado(r.ESTADO_DESPACHO)}</td>` +
                 `<td class="col-num">${fmt.num(r.DIAS)}</td>`,
            r => r.NRO_PEDIDO
        );
    }

    // ── Área 7: Pedidos Consolidados ──────────────────────────────────────
    async function loadPedidos() {
        const data = await apiFetch('pedidos_consolidados', { canal: State.canal });
        const k = data.kpis || {};

        $('#kv-pc-ped').text(fmt.num(k.PEDIDOS));
        setVar('#kvar-pc-ped', fmt.varPct(k.PEDIDOS, k.PEDIDOS_AA));
        $('#kv-pc-ped-aa').text(fmt.num(k.PEDIDOS_AA));
        const varAbs = parseInt(k.PEDIDOS_VAR || 0);
        $('#kv-pc-var').text((varAbs >= 0 ? '+' : '') + fmt.num(varAbs))
            .css('color', varAbs >= 0 ? 'var(--pos)' : 'var(--neg)');
        $('#kv-pc-unid-ped').text(fmt.num(k.UNID_PEDIDO));
        $('#kv-pc-unid-fact').text(fmt.num(k.UNID_FACT));

        const evol = data.evolucion || [];
        const multiAnioPC = evol.length > 1 && evol[0].ANIO !== evol[evol.length - 1].ANIO;
        if (chartPedidos) chartPedidos.destroy();
        chartPedidos = new Chart($('#chart-pedidos-evol')[0], {
            type: 'bar',
            data: {
                labels  : evol.map(r => multiAnioPC ? r.NOMBRE_MES.substring(0, 3) + ' ' + r.ANIO : r.NOMBRE_MES),
                datasets: [{ label: 'Pedidos', data: evol.map(r => r.PEDIDOS_MES), backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 4 }],
            },
            options: { ...chartOptions('Pedidos', {}, { integer: true }), plugins: { legend: { display: false } } },
        });

        const $tbody = $('#tbody-pedidos').empty();
        const tabla  = data.tabla || [];
        if (!tabla.length) {
            $tbody.append('<tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }
        $tbody.html(tabla.map(r => `<tr class="pedido-row" data-pedido="${escapeHtml(String(r.NRO_PEDIDO).trim())}">
            <td>${r.NRO_PEDIDO}</td>
            <td>${r.CANAL || '—'}</td>
            <td>${badgeEstado(r.ESTADO)}</td>
            <td>${fmt.date(r.FECHA_PEDI)}</td>
            <td>${r.TALON_PED || '—'}</td>
            <td class="col-num">${fmt.num(r.UNID_PEDIDO)}</td>
            <td class="col-num">${fmt.num(r.UNID_PENDIENTES)}</td>
            <td class="col-num">${fmt.num(r.UNID_FACTURADAS)}</td>
        </tr>`).join(''));
    }

    // ── Helpers de render ─────────────────────────────────────────────────
    function renderTablaSimple(sel, rows, rowFn, pedidoFn) {
        const $tbody = $(sel).empty();
        if (!rows.length) {
            $tbody.append(`<tr><td colspan="10"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>`);
            return;
        }
        $tbody.html(rows.map(r => {
            const ped  = pedidoFn ? pedidoFn(r) : null;
            const attr = ped ? ` class="pedido-row" data-pedido="${escapeHtml(String(ped).trim())}"` : '';
            return `<tr${attr}>` + rowFn(r) + '</tr>';
        }).join(''));
    }

    function badgeEstado(estado) {
        if (!estado) return '—';
        const map = {
            'PENDIENTE'     : 'badge-pendiente',
            'SIN FACTURAR'  : 'badge-pendiente',
            'FACTURADO'     : 'badge-facturado',
            'CANCELADO'     : 'badge-cancelado',
            'DEMORADO'      : 'badge-demorado',
            'FUERA DE PLAZO': 'badge-fuera',
            'EN TERMINO'    : 'badge-facturado',
        };
        const cls = map[estado.toUpperCase()] || '';
        return `<span class="badge-estado ${cls}" title="Estado: ${escapeHtml(estado)}">${estado}</span>`;
    }

    // ── Modal: detalle de pedido (líneas por artículo) ────────────────────
    function pmetaItem(label, val) {
        return `<div class="pmeta-item"><span class="pmeta-label">${label}</span>` +
               `<span class="pmeta-val">${escapeHtml(String(val))}</span></div>`;
    }
    function openModal()  { $('#pedido-modal').removeAttr('hidden'); $('body').addClass('modal-open'); }
    function closeModal() {
        $('#pedido-modal').attr('hidden', '');
        if (!$('.pmodal:not([hidden])').length) $('body').removeClass('modal-open');
    }

    async function openPedidoDetalle(nroPedido) {
        if (!nroPedido) return;
        const $meta = $('#pmodal-meta');
        $('#pmodal-title').html(`<i class="bi bi-receipt"></i> Detalle del pedido ${escapeHtml(nroPedido)}`);
        $meta.html('<div class="pmodal-loading"><i class="bi bi-arrow-repeat"></i> Cargando detalle…</div>');
        $('#tbody-pedido-detalle').empty();
        $('#tfoot-pedido-detalle').empty();
        openModal();
        try {
            const data   = await apiFetch('pedido_detalle', { pedido: nroPedido });
            const h      = data.header   || {};
            const rubros = data.rubros   || [];
            const tot    = data.totales  || {};
            const efi    = tot.EFICIENCIA == null ? null : parseFloat(tot.EFICIENCIA);

            $meta.html(
                pmetaItem('Cliente', h.CLIENTE || '—') +
                pmetaItem('Canal', h.CANAL || '—') +
                pmetaItem('Fecha pedido', fmt.date(h.FECHA_PEDI)) +
                pmetaItem('Talón', h.TALON_PED != null ? h.TALON_PED : '—') +
                pmetaItem('U. pedidas', fmt.num(tot.CANT_PEDID)) +
                pmetaItem('U. facturadas', fmt.num(tot.CANT_FACT)) +
                `<div class="pmeta-item pmeta-efi"><span class="pmeta-label">% Eficiencia</span>` +
                `<span class="pmeta-val" style="color:${efiColor(efi)}">${efi == null ? '—' : fmt.pct(efi, 0)}</span></div>`
            );

            renderTablaSimple('#tbody-pedido-detalle', rubros,
                r => `<td title="${escapeHtml(r.RUBRO || '')}">${r.RUBRO || '—'}</td>` +
                     `<td class="col-num">${fmt.num(r.CANT_PEDID)}</td>` +
                     `<td class="col-num">${fmt.num(r.CANT_FACT)}</td>` +
                     `<td class="col-num">${efiCell(r.EFICIENCIA)}</td>`
            );

            if (rubros.length) {
                $('#tfoot-pedido-detalle').html(
                    `<tr><td>Total</td>` +
                    `<td class="col-num">${fmt.num(tot.CANT_PEDID)}</td>` +
                    `<td class="col-num">${fmt.num(tot.CANT_FACT)}</td>` +
                    `<td class="col-num">${efiCell(efi)}</td></tr>`
                );
            } else {
                $meta.append('<div class="pmodal-note">Sin detalle disponible para este pedido.</div>');
            }
        } catch (e) {
            $meta.html(`<div class="error-state"><i class="bi bi-exclamation-triangle-fill"></i> ${escapeHtml(e.message)}</div>`);
        }
    }

    function efiColor(pct) {
        if (pct == null) return 'var(--text-1)';
        return pct >= 0.95 ? 'var(--pos)' : pct >= 0.85 ? 'var(--accent3)' : 'var(--neg)';
    }

    // ── Label período en topbar ───────────────────────────────────────────
    function updatePeriodLabel() {
        const d = State.desde, h = State.hasta;
        if (d && h) $('#periodo-label').text(fmt.date(d) + ' — ' + fmt.date(h));
    }

    // ── Tooltips estáticos ────────────────────────────────────────────────
    function initStaticTooltips() {
        initInfoPopover();

        $('#inp-desde').attr('title', 'Fecha inicial del periodo de analisis.');
        $('#inp-hasta').attr('title', 'Fecha final del periodo de analisis.');
        $('#sel-canal').attr('title', 'Filtra los datos por canal.');
        $('#sel-rubro').attr('title', 'Filtra los datos por rubro.');
        $('#sel-deposito').attr('title', 'Filtra el inventario por depósito.');
        $('#sel-usuario').attr('title', 'Filtra los datos por usuario.');
        $('#sel-cliente').attr('title', 'Filtra los datos por cliente.');
        $('#sel-tipo').attr('title', 'Filtra por tipo de facturación.');
        $('#btn-aplicar').attr({ title: 'Aplicar fechas y filtros seleccionados.', 'aria-label': 'Aplicar filtros' });
        $('#btn-reload').attr({ title: 'Recargar la pestana activa con los filtros actuales.', 'aria-label': 'Recargar pestana activa' });

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
        updateSlicers('eficiencia');
        loadTab('eficiencia');

        $('.tab-btn').on('click', function () {
            const tab = $(this).data('tab');
            if (tab) switchTab(tab);
        });

        $('#btn-aplicar').on('click', function () {
            const d = $('#inp-desde').val();
            const h = $('#inp-hasta').val();
            if (!d || !h) return;
            if (d > h) { alert('La fecha Desde no puede ser mayor que Hasta.'); return; }
            State.desde   = d;
            State.hasta   = h;
            State.canal   = $('#sel-canal').val()   || '';
            State.usuario = $('#sel-usuario').val() || '';
            State.cliente = $('#sel-cliente').val() || '';
            State.tipo    = $('#sel-tipo').val()    || '';
            // El slicer Rubro es compartido: Facturación y Stock usan listas distintas.
            if (State.activeTab === 'prod-fact') State.rubroFact = $('#sel-rubro').val() || '';
            else                                 State.rubro     = $('#sel-rubro').val() || '';
            if (State.activeTab === 'stock')     State.deposito  = $('#sel-deposito').val() || '';
            invalidarCache();
            updatePeriodLabel();
            loadTab(State.activeTab);
        });

        $('#btn-reload').on('click', function () {
            delete Cache[State.activeTab];
            State.forceRefresh = true;
            loadTab(State.activeTab).finally(() => { State.forceRefresh = false; });
        });

        $('#sel-canal, #sel-rubro, #sel-deposito, #sel-usuario, #sel-cliente, #sel-tipo').on('change', function () {
            State.canal   = $('#sel-canal').val()   || '';
            State.usuario = $('#sel-usuario').val() || '';
            State.cliente = $('#sel-cliente').val() || '';
            State.tipo    = $('#sel-tipo').val()    || '';
            if (State.activeTab === 'prod-fact') State.rubroFact = $('#sel-rubro').val() || '';
            else                                 State.rubro     = $('#sel-rubro').val() || '';
            if (State.activeTab === 'stock')     State.deposito  = $('#sel-deposito').val() || '';
            delete Cache[State.activeTab];
            loadTab(State.activeTab);
        });

        // Filtro de ventana para "Pedidos pendientes" (Planificación)
        $('#pend-filtros').on('click', '.pill', function () {
            $('#pend-filtros .pill').removeClass('active');
            $(this).addClass('active');
            State.pendFiltro = $(this).data('f');
            renderPendientes();
        });

        // Drill-down del detalle por rubro (delegado: el tbody se re-renderiza)
        $('#tbody-stock').on('click', 'tr.rubro-row.expandible', function () {
            toggleDrillRubro($(this));
        });

        // Modal de detalle de pedido (delegado: cualquier fila con data-pedido)
        $(document).on('click', 'tr.pedido-row', function () {
            openPedidoDetalle($(this).attr('data-pedido'));
        });
        $('#pedido-modal').on('click', '[data-close]', closeModal);

        // Mini-gráfico por día (clic en columna de fecha en tablas Últ. 7 días)
        $(document).on('click', 'th.pick-day-th', function () {
            openDiaChart($(this).attr('data-base'), $(this).attr('data-fecha'));
        });
        $('#picking-dia-modal').on('click', '[data-close]', closePickingDia);

        // Drill de % Eficiencia por cliente (delegado: el tbody se re-renderiza)
        $('#tbody-efi-pedidos').on('click', 'tr.efi-cli-row.expandible', function () {
            toggleEfiPedidos($(this));
        });

        // Card de pérdida: flip (frente KPI ⇄ dorso mini-gráfico) + ampliar.
        // Toda la cara delantera da vuelta la tarjeta (salvo el botón de ayuda).
        $('#card-perdida').on('click', '.kpi-flip-front', function (e) {
            if ($(e.target).closest('.info-btn').length) return;
            $('#card-perdida').addClass('flipped');
        });
        $('#card-perdida').on('click', '[data-flip-back]', function (e) {
            e.stopPropagation();
            $('#card-perdida').removeClass('flipped');
        });
        $('#btn-perdida-expand').on('click', function (e) { e.stopPropagation(); openPerdidaModal(); });
        $('#perdida-12m-modal').on('click', '[data-close]', closePerdidaModal);

        $(document).on('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (!$('#pedido-modal').attr('hidden'))      closeModal();
            if (!$('#picking-dia-modal').attr('hidden')) closePickingDia();
            if (!$('#perdida-12m-modal').attr('hidden')) closePerdidaModal();
        });
    }

    $(document).ready(init);

})(jQuery);
