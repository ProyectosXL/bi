/* /bi/logistica/assets/logistica.js
   Dashboard Logística — IIFE jQuery
   Stack: jQuery 3.7, Chart.js 4.4
   ============================================================ */
;(function ($) {
    'use strict';

    // ── Estado global ────────────────────────────────────────────────────
    const State = {
        desde    : '',
        hasta    : '',
        activeTab: 'eficiencia',
        canal    : '',
        rubro    : '',
        usuario  : '',
        forceRefresh: false,
    };

    // Caché por pestaña (se invalida al cambiar filtros)
    const Cache = {};
    let filtrosPromise = null;

    // Instancias Chart.js
    let chartEfi         = null;
    let chartsCanalEfi   = [];
    let chartLtHist      = null;
    let chartLtEvol      = null;
    let chartStock       = null;
    let chartProdFact    = null;
    let chartProdPicking = null;
    let chartPedidos     = null;

    const BASE = '/bi/logistica/ajax/';

    const PALETTE = [
        '#00a878','#2563eb','#f59e0b','#ef4444','#8b5cf6',
        '#06b6d4','#f97316','#10b981','#6366f1','#ec4899',
        '#14b8a6','#a855f7','#84cc16','#f43f5e','#0ea5e9',
    ];

    // Slicers visibles por tab
    const TAB_SLICERS = {
        'eficiencia'   : ['wrap-canal'],
        'leadtime'     : [],
        'stock'        : ['wrap-rubro'],
        'prod-fact'    : ['wrap-usuario'],
        'prod-picking' : ['wrap-usuario'],
        'despacho'     : [],
        'pedidos'      : ['wrap-canal'],
    };

    const HELP = {
        kpis: {
            'kv-efi'            : ['Eficiencia de facturación', ['Unidades facturadas sobre unidades pedidas en el período.', 'Meta: 95%. Verde si se alcanza, rojo si está por debajo.']],
            'kv-unid-ped'       : ['Unidades pedidas', ['Total de unidades solicitadas en los pedidos del período.', 'La variación compara contra el mismo período del año anterior.']],
            'kv-unid-fact'      : ['Unidades facturadas', ['Unidades efectivamente facturadas dentro del período y filtros activos.']],
            'kv-perdida'        : ['Pérdida de facturación', ['Unidades pedidas que no fueron facturadas.', 'El porcentaje muestra el peso de la pérdida sobre el importe pedido.']],
            'kv-importe'        : ['Importe facturado', ['Importe total facturado en el período y filtros activos, expresado en pesos.']],
            'kv-pedidos'        : ['Pedidos totales', ['Cantidad de pedidos incluidos en el cálculo de eficiencia.', 'La variación compara contra el mismo período del año anterior.']],
            'kv-lt-total'       : ['Comprobantes facturados', ['Total de comprobantes de facturación emitidos en el período seleccionado.']],
            'kv-lt-dem'         : ['Facturación demorada', ['Comprobantes con lead time mayor a 5 días hábiles desde el pedido.', 'El porcentaje se calcula sobre el total de comprobantes del período.']],
            'kv-lt-prom'        : ['Lead time promedio', ['Promedio de días corridos entre la fecha del pedido y la fecha de facturación.']],
            'kv-lt-abiertos'    : ['Pedidos abiertos', ['Pedidos con al menos una unidad sin facturar al cierre del período.']],
            'kv-stock-tango'    : ['Stock Tango', ['Stock registrado en el sistema Tango para los rubros filtrados.']],
            'kv-stock-wms'      : ['Stock WMS', ['Stock registrado en el sistema WMS para los rubros filtrados.']],
            'kv-stock-dif'      : ['Diferencia neta', ['Diferencia entre stock WMS y stock Tango (WMS − Tango).', 'Desviaciones distintas de cero requieren revisión de inventario.']],
            'kv-stock-prec'     : ['Precisión de inventario', ['Porcentaje de artículos donde WMS y Tango coinciden exactamente.', 'Meta ideal: 99% o superior.']],
            'kv-pf-unid'        : ['Unidades facturadas', ['Unidades facturadas por los usuarios en el período y filtros activos.']],
            'kv-pf-dias'        : ['Días productivos', ['Días con al menos una unidad facturada registrada en el período.']],
            'kv-pf-prom'        : ['Promedio unid./día', ['Unidades facturadas promedio por día productivo en el período.']],
            'kv-pf-mediana'     : ['Mediana unid./día', ['Valor central de la distribución de unidades por día. Menos sensible a días atípicos que el promedio.']],
            'kv-pf-moda'        : ['Máximo unid./día', ['Mayor cantidad de unidades facturadas registrada en un único día del período.']],
            'kv-pf-ult30'       : ['Unidades últimos 30 días', ['Unidades facturadas acumuladas en los últimos 30 días disponibles, independiente del período seleccionado.']],
            'kv-pp-prom-dia'    : ['Promedio unid./día', ['Promedio de unidades pickeadas por día productivo en el período.']],
            'kv-pp-pico-dia'    : ['Pico por día', ['Mayor cantidad de unidades preparadas en un único día del período.']],
            'kv-pp-pico-usuario': ['Pico por usuario', ['Mayor cantidad de unidades preparadas por un único picker en un solo día del período.']],
            'kv-pp-prom'        : ['Promedio por picker', ['Promedio de unidades por día, considerando solo pickers con más de 3 horas productivas registradas.']],
            'kv-pp-u-hora'      : ['Promedio unid./hora', ['Unidades pickeadas por hora productiva registrada en el período.']],
            'kv-pp-hs'          : ['Tiempo productivo', ['Total de horas de picking registradas en el período y filtros activos.']],
            'kv-dd-ped-tot'     : ['Pedidos totales', ['Total de pedidos considerados para el análisis de demanda y despacho.']],
            'kv-dd-ped-pend'    : ['Pedidos pendientes', ['Pedidos con al menos una unidad pendiente de despacho al momento del cálculo.']],
            'kv-dd-cumpl'       : ['Cumplimiento de unidades', ['Unidades despachadas sobre unidades totales pedidas.', 'Se compara contra la meta de despacho definida en el tablero.']],
            'kv-dd-eficacia'    : ['Eficacia de despacho', ['Relación entre lo despachado y la demanda comprometida en el período.']],
            'kv-dd-prox-habil'  : ['Próximo día hábil', ['Siguiente fecha operativa utilizada para planificar las entregas pendientes.']],
            'kv-dd-pickers'     : ['Pickers necesarios', ['Estimación de pickers requeridos para cubrir la demanda pendiente en el próximo día hábil.']],
            'kv-pc-ped'         : ['Pedidos del período', ['Cantidad de pedidos consolidados en el rango de fechas y canal seleccionados.', 'La variación compara contra el mismo período del año anterior.']],
            'kv-pc-ped-aa'      : ['Pedidos año anterior', ['Pedidos del mismo rango de fechas en el año anterior, para comparación directa.']],
            'kv-pc-var'         : ['Variación absoluta', ['Diferencia en cantidad de pedidos entre el período actual y el año anterior.', 'Positivo indica más pedidos que el año anterior.']],
            'kv-pc-unid-ped'    : ['Unidades pedidas', ['Unidades solicitadas en los pedidos consolidados del período filtrado.']],
            'kv-pc-unid-fact'   : ['Unidades facturadas', ['Unidades ya facturadas correspondientes a los pedidos consolidados del período.']],
        },
        sections: {
            'gauges-canal'          : ['Eficiencia por canal', ['Cada gauge muestra la eficiencia (unidades facturadas / unidades pedidas) por canal.', 'La marca negra indica la meta del 95%. Verde ≥ 95%, amarillo ≥ 85%, rojo < 85%.']],
            'chart-eficiencia'      : ['Evolución mensual de eficiencia', ['Eficiencia mensual de facturación en los últimos 12 meses.', 'La línea roja punteada marca la meta del 95%.']],
            'chart-leadtime-hist'   : ['Distribución de lead times', ['Cantidad de comprobantes agrupados por días de demora entre pedido y facturación.', 'Barras rojas: más de 5 días (demorados). Barras verdes: en término.']],
            'chart-leadtime-evol'   : ['Evolución de facturación demorada', ['Porcentaje mensual de comprobantes con lead time mayor a 5 días.', 'Muestra la tendencia de demoras en los últimos 12 meses.']],
            'chart-stock'           : ['Diferencia de stock por rubro', ['Compara stock Tango vs. WMS en los principales rubros logísticos.', 'Diferencias significativas entre barras indican desvíos de inventario.']],
            'tabla-stock'           : ['Detalle de stock por rubro', ['Tabla con stock Tango, WMS, diferencia neta, diferencia porcentual y precisión de inventario por rubro.']],
            'chart-prod-fact'       : ['Facturación diaria', ['Unidades facturadas por día en el período seleccionado.']],
            'tabla-usuarios-fact'   : ['Productividad por usuario', ['Resumen de unidades, días productivos, promedio, mediana y máximo por usuario de facturación.']],
            'chart-prod-picking'    : ['Picking diario', ['Barras azules: unidades pickeadas por día. Línea naranja: promedio de unidades por hora productiva.']],
            'tabla-usuarios-picking': ['Productividad por picker', ['Resumen de unidades, porcentaje del equipo, pico, mediana, horas y promedio por hora para cada picker.']],
            'tabla-picking-ult7'    : ['Últimos 7 días por picker', ['Unidades pickeadas por día y picker en los últimos 7 días con actividad.', 'Las columnas de totales suman unidades y horas del período completo.']],
            'tabla-pend-hoy'        : ['Pendientes hoy', ['Pedidos con unidades pendientes de despacho para el día operativo actual.']],
            'tabla-demorados'       : ['Demorados', ['Pedidos fuera del plazo esperado de despacho, ordenados por antigüedad.']],
            'tabla-prox-entrega'    : ['Cola de pedidos pendientes', ['Todos los pedidos con estado PENDIENTE, ordenados por fecha del pedido (más antiguos primero).']],
            'chart-pedidos-evol'    : ['Evolución mensual de pedidos', ['Cantidad de pedidos consolidados por mes en los últimos 12 meses.']],
            'tabla-pedidos'         : ['Pedidos consolidados', ['Detalle de pedidos con estado, canal, talón y unidades pedidas, pendientes y facturadas.']],
        },
        tableHeaders: {
            'tabla-stock': [
                'Rubro logístico.',
                'Stock registrado en Tango.',
                'Stock registrado en WMS.',
                'Diferencia neta (WMS − Tango).',
                'Diferencia porcentual sobre Tango.',
                'Precisión: coincidencia exacta entre WMS y Tango.',
            ],
            'tabla-usuarios-fact': [
                'Usuario de facturación.',
                'Unidades facturadas en el período.',
                'Días con al menos una factura registrada.',
                'Promedio de unidades por día productivo.',
                'Mediana de unidades por día (menos sensible a outliers).',
                'Máximo de unidades registrado en un día.',
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
            'tabla-pend-hoy': [
                'Número de pedido.',
                'Nombre del cliente.',
                'Unidades pendientes de despacho.',
            ],
            'tabla-demorados': [
                'Número de pedido.',
                'Nombre del cliente.',
                'Estado logístico actual del pedido.',
                'Unidades demoradas.',
            ],
            'tabla-prox-entrega': [
                'Número de pedido.',
                'Código de cliente.',
                'Nombre del cliente.',
                'Fecha del pedido (más antiguo = prioridad más alta).',
                'Unidades pendientes de despacho.',
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

    // ── Formato numérico es-AR ────────────────────────────────────────────
    const fmt = {
        num(v, dec = 0) {
            if (v == null || v === '' || isNaN(v) || !isFinite(v)) return '—';
            return Number(v).toLocaleString('es-AR', {
                minimumFractionDigits: dec,
                maximumFractionDigits: dec,
            });
        },
        pct(v, dec = 1) {
            if (v == null || v === '' || isNaN(v) || !isFinite(v)) return '—';
            return Number(v * 100).toLocaleString('es-AR', {
                minimumFractionDigits: dec,
                maximumFractionDigits: dec,
            }) + '%';
        },
        money(v) {
            if (v == null || isNaN(v)) return '—';
            return '$ ' + Number(v).toLocaleString('es-AR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },
        date(s) {
            if (!s) return '—';
            // s puede venir como '2026-05-22' o como Date de sqlsrv serializado
            const d = typeof s === 'string' ? s.substring(0, 10) : s;
            const parts = String(d).split('-');
            if (parts.length === 3) return parts[2] + '/' + parts[1] + '/' + parts[0];
            return d;
        },
        varLabel(actual, meta) {
            if (actual == null) return { text: '—', cls: 'neu' };
            const a = parseFloat(actual);
            if (isNaN(a)) return { text: '—', cls: 'neu' };
            if (meta != null) {
                const m = parseFloat(meta);
                const cls = a >= m ? 'alcanza' : 'no-alcanza';
                const icono = a >= m ? '<i class="bi bi-check-circle-fill"></i>' : '<i class="bi bi-x-circle-fill"></i>';
                return { text: icono + ' Meta: ' + fmt.pct(m), cls, semaforo: true };
            }
            return { text: '—', cls: 'neu' };
        },
        varPct(actual, prev) {
            if (actual == null || prev == null) return { text: '—', cls: 'neu' };
            const a = parseFloat(actual), p = parseFloat(prev);
            if (isNaN(a) || isNaN(p) || p === 0) return { text: '—', cls: 'neu' };
            const v = (a - p) / Math.abs(p);
            const pct = v * 100;
            if (pct > 0.05)  return { text: '▲ ' + fmt.num(pct, 1) + '%', cls: 'pos' };
            if (pct < -0.05) return { text: '▼ ' + fmt.num(Math.abs(pct), 1) + '%', cls: 'neg' };
            return { text: '0,0%', cls: 'neu' };
        },
    };

    function setVar(id, v) {
        const $el = $(id);
        if (!$el.length) return;
        $el.html(v.text).removeClass('pos neg neu alcanza no-alcanza semaforo');
        $el.addClass('kpi-var ' + v.cls);
        if (v.semaforo) $el.addClass('semaforo');
    }

    // ── Overlay / loading bar ─────────────────────────────────────────────
    function showOverlay() { $('#loading-overlay').removeAttr('hidden'); $('body').addClass('is-loading'); }
    function hideOverlay() { $('#loading-overlay').attr('hidden', '');   $('body').removeClass('is-loading'); }
    function setReload(on) { $('#btn-reload').toggleClass('spinning', on); $('i', '#btn-reload').toggleClass('bi-arrow-clockwise', !on).toggleClass('bi-arrow-repeat', on); }

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
            poblarSelect('#sel-canal',    json.canales,          'Todos');
            poblarSelect('#sel-rubro',    json.rubros_stock,     'Todos');
            poblarSelect('#sel-usuario',  json.usuarios_fact,    'Todos');
            // usuarios_picking se carga según tab activa
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
        // Ocultar todos
        $('#wrap-canal, #wrap-rubro, #wrap-usuario').hide();
        // Mostrar los del tab activo
        (TAB_SLICERS[tab] || []).forEach(id => $('#' + id).show());
        // Para picking, cargar usuarios de picking en el select
        if (tab === 'prod-picking') {
            fetchFiltros()
                .then(json => { if (json.ok) poblarSelect('#sel-usuario', json.usuarios_picking, 'Todos'); })
                .catch(() => {});
        } else if (tab === 'prod-fact') {
            fetchFiltros()
                .then(json => { if (json.ok) poblarSelect('#sel-usuario', json.usuarios_fact, 'Todos'); })
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
        if (Cache[tab]) return;   // ya está cargado con este filtro
        showOverlay();
        setReload(true);
        try {
            switch (tab) {
                case 'eficiencia':    await loadEficiencia(); break;
                case 'leadtime':      await loadLeadTime();   break;
                case 'stock':         await loadStock();       break;
                case 'prod-fact':     await loadProdFact();   break;
                case 'prod-picking':  await loadProdPicking();break;
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

        // KPIs
        $('#kv-efi').text(fmt.pct(k.EFI_UNIDADES));
        setVar('#kvar-efi', fmt.varLabel(k.EFI_UNIDADES, k.META_EFICIENCIA));

        $('#kv-unid-ped').text(fmt.num(k.UNID_PEDIDAS));
        setVar('#kvar-unid-ped', fmt.varPct(k.UNID_PEDIDAS, k.UNID_PEDIDAS_AA));

        $('#kv-unid-fact').text(fmt.num(k.UNID_FACTURADAS));

        $('#kv-perdida').text(fmt.num(k.PERDIDA_FACT));
        const pctPerd = fmt.pct(k.PCT_PERDIDA);
        $('#kvar-perdida').text(pctPerd).removeClass('pos neg neu').addClass('kpi-var ' + (k.PCT_PERDIDA > 0.05 ? 'neg' : 'neu'));

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

        // Plugin inline: dibuja un tick en el ángulo correspondiente al 95% del arco
        const metaGaugeLine = {
            id: 'metaGaugeLine',
            afterDraw(chart) {
                const arc = chart.getDatasetMeta(0).data[0];
                if (!arc) return;
                const { ctx } = chart;
                const { x: cx, y: cy, innerRadius, outerRadius } = arc;
                // Gauge: rotation=-90°, circumference=180° → arco va de -π a 0
                // 95% del arco: -π + 0.95*π = -0.05π
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

        // Gráfico evolución — siempre 12 meses; label incluye año si abarca 2 años
        const evol = data.evolucion || [];
        const multiAnio = evol.length > 1 && evol[0].ANIO !== evol[evol.length - 1].ANIO;
        const labels = evol.map(r => multiAnio ? r.NOMBRE_MES.substring(0,3) + ' ' + r.ANIO : r.NOMBRE_MES);
        const efiData = evol.map(r => {
            const ped = parseFloat(r.UNID_PEDIDAS_MES) || 0;
            const fct = parseFloat(r.UNID_FACTURADAS_MES) || 0;
            return ped > 0 ? parseFloat((fct / ped * 100).toFixed(1)) : null;
        });

        if (chartEfi) chartEfi.destroy();
        chartEfi = new Chart($('#chart-eficiencia')[0], {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label     : 'Eficiencia (%)',
                    data      : efiData,
                    borderColor: PALETTE[0],
                    backgroundColor: 'rgba(0,168,120,.08)',
                    borderWidth: 2,
                    pointRadius: 4,
                    tension    : 0.3,
                    fill       : true,
                }, {
                    label     : 'Meta (95%)',
                    data      : labels.map(() => 95),
                    borderColor: '#dc2626',
                    borderWidth: 1.5,
                    borderDash : [6, 4],
                    pointRadius: 0,
                    fill       : false,
                }],
            },
            options: chartOptions('Eficiencia (%)', { min: 0, max: 100, suggestedMax: 105 }, { pct: true }),
        });
    }

    // ── Área 2: Lead Time ─────────────────────────────────────────────────
    async function loadLeadTime() {
        const data = await apiFetch('leadtime');
        const k = data.kpis || {};

        $('#kv-lt-total').text(fmt.num(k.COMP_FACTURADOS));
        $('#kv-lt-dem').text(fmt.num(k.COMP_DEMORADOS));
        const pctDem = k.PCT_DEMORADOS;
        setVar('#kvar-lt-dem', {
            text: fmt.pct(pctDem),
            cls : pctDem > 0.1 ? 'neg' : 'pos',
        });
        $('#kv-lt-prom').text(fmt.num(k.LEAD_TIME_PROMEDIO, 1));
        $('#kv-lt-abiertos').text(fmt.num(k.PEDIDOS_ABIERTOS));

        // Histograma
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

        // Evolución % demorada
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
        const data = await apiFetch('stock', { rubro: State.rubro });
        const k = data.kpis || {};

        $('#kv-stock-tango').text(fmt.num(k.STOCK_TANGO));
        $('#kv-stock-wms').text(fmt.num(k.STOCK_WMS));
        $('#kv-stock-dif').text(fmt.num(k.DIFERENCIA));
        setVar('#kvar-stock-dif', {
            text: fmt.pct(k.DIF_PCT),
            cls : Math.abs(parseFloat(k.DIF_PCT || 0)) < 0.01 ? 'pos' : 'neg',
        });
        $('#kv-stock-prec').text(fmt.pct(k.PRECISION_INVENTARIO));

        // Gráfico rubros
        const rubros = (data.rubros || []).slice(0, 15);
        if (chartStock) chartStock.destroy();
        chartStock = new Chart($('#chart-stock')[0], {
            type: 'bar',
            data: {
                labels  : rubros.map(r => r.RUBRO),
                datasets: [
                    {
                        label          : 'Stock Tango',
                        data           : rubros.map(r => r.STOCK_TANGO),
                        backgroundColor: 'rgba(37,99,235,.65)',
                        borderRadius   : 4,
                    },
                    {
                        label          : 'Stock WMS',
                        data           : rubros.map(r => r.STOCK_WMS),
                        backgroundColor: 'rgba(0,168,120,.65)',
                        borderRadius   : 4,
                    },
                ],
            },
            options: chartOptions('Unidades', {}, { integer: true }),
        });

        // Tabla
        const $tbody = $('#tbody-stock').empty();
        if (!rubros.length) {
            $tbody.append('<tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }
        $tbody.html(rubros.map(r => {
            const dif = parseFloat(r.DIFERENCIA || 0);
            return `<tr>
                <td>${r.RUBRO}</td>
                <td class="col-num">${fmt.num(r.STOCK_TANGO)}</td>
                <td class="col-num">${fmt.num(r.STOCK_WMS)}</td>
                <td class="col-num ${dif !== 0 ? (dif < 0 ? 'var-neg' : 'var-pos') : ''}">${fmt.num(dif)}</td>
                <td class="col-num ${dif !== 0 ? 'var-neg' : ''}">${fmt.pct(r.DIF_PCT)}</td>
                <td class="col-num ${parseFloat(r.PRECISION||0) >= 0.99 ? 'var-pos' : 'var-neg'}">${fmt.pct(r.PRECISION)}</td>
            </tr>`;
        }).join(''));
    }

    // ── Área 4: Productividad Facturación ─────────────────────────────────
    async function loadProdFact() {
        const data = await apiFetch('productividad_fact', { usuario: State.usuario });
        const k = data.kpis || {};

        $('#kv-pf-unid').text(fmt.num(k.UNIDADES_FACT));
        $('#kv-pf-dias').text(fmt.num(k.DIAS_PRODUCTIVOS));
        $('#kv-pf-prom').text(fmt.num(k.PROMEDIO, 1));
        $('#kv-pf-mediana').text(fmt.num(k.MEDIANA, 1));
        $('#kv-pf-moda').text(fmt.num(k.MODA));
        $('#kv-pf-ult30').text(fmt.num(k.UNIDADES_ULT30));

        // Gráfico diario
        const evol = data.evolucion || [];
        if (chartProdFact) chartProdFact.destroy();
        chartProdFact = new Chart($('#chart-prod-fact')[0], {
            type: 'bar',
            data: {
                labels  : evol.map(r => fmt.date(r.FECHA_COMP)),
                datasets: [{
                    label          : 'Unidades/día',
                    data           : evol.map(r => r.UNIDADES_DIA),
                    backgroundColor: 'rgba(37,99,235,.65)',
                    borderRadius   : 3,
                }],
            },
            options: { ...chartOptions('Unidades', {}, { integer: true }), plugins: { legend: { display: false } } },
        });

        // Tabla usuarios
        const $tbody = $('#tbody-usuarios-fact').empty();
        const usuarios = data.usuarios || [];
        if (!usuarios.length) {
            $tbody.append('<tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }
        $tbody.html(usuarios.map(u => `<tr>
                <td>${u.USUARIO || '—'}</td>
                <td class="col-num">${fmt.num(u.UNIDADES_FACT)}</td>
                <td class="col-num">${fmt.num(u.DIAS_PRODUCTIVOS)}</td>
                <td class="col-num">${fmt.num(u.PROMEDIO, 1)}</td>
                <td class="col-num">${fmt.num(u.MEDIANA, 1)}</td>
                <td class="col-num">${fmt.num(u.MODA)}</td>
            </tr>`).join(''));
    }

    // ── Área 5: Productividad Picking ─────────────────────────────────────
    async function loadProdPicking() {
        const data = await apiFetch('productividad_picking', { usuario: State.usuario });
        const k = data.kpis || {};

        // KPIs sidebar
        $('#kv-pp-prom-dia').text(fmt.num(k.PROM_UNID_DIA));
        $('#kv-pp-pico-dia').text(fmt.num(k.PICO_DIA_UNIDADES));
        $('#kv-pp-pico-dia-fecha').text(fmt.date(k.PICO_DIA_FECHA));
        $('#kv-pp-pico-usuario').text(fmt.num(k.PICO_USUARIO_UNIDADES));
        $('#kv-pp-pico-usuario-nombre').text(k.PICO_USUARIO || '—');
        $('#kv-pp-hs').text(fmt.num(k.TIEMPO_PRODUCTIVO_HS, 1));
        $('#kv-pp-u-hora').text(fmt.num(k.PROM_UNID_HORA, 1));
        $('#kv-pp-prom').text(fmt.num(k.PROM_UNID_PICKERS, 1));

        // Gráfico: Unidades pickeadas por día (barras) + Prom. unid. x hora (línea)
        const evol = data.evolucion || [];
        const canvasPP = $('#chart-prod-picking')[0];
        if (chartProdPicking) { chartProdPicking.destroy(); chartProdPicking = null; }
        if (canvasPP) chartProdPicking = new Chart(canvasPP, {
            type: 'bar',
            data: {
                labels  : evol.map(r => fmt.date(r.FECHA_INI_PICKING)),
                datasets: [{
                    label          : 'Unidades pickeadas',
                    data           : evol.map(r => r.UNIDADES_DIA),
                    backgroundColor: 'rgba(37,99,235,.65)',
                    borderRadius   : 3,
                    yAxisID        : 'y',
                }, {
                    label          : 'Promedio unid. x hora',
                    data           : evol.map(r => r.PROM_UNID_HORA),
                    type           : 'line',
                    borderColor    : '#f59e0b',
                    backgroundColor: 'rgba(245,158,11,.15)',
                    borderWidth    : 2,
                    pointRadius    : 3,
                    tension        : 0.3,
                    fill           : false,
                    yAxisID        : 'y1',
                }],
            },
            options: {
                ...chartOptions('Unidades pickeadas', {}, { integer: true }),
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.92)',
                        titleColor: '#f8fafc',
                        bodyColor: '#cbd5e1',
                        borderColor: 'rgba(255,255,255,0.12)',
                        borderWidth: 1,
                        padding: 10,
                        callbacks: {
                            label(ctx) {
                                const v = ctx.parsed.y;
                                if (v == null) return null;
                                if (ctx.datasetIndex === 0) {
                                    return ' ' + ctx.dataset.label + ': ' + Number(v).toLocaleString('es-AR', { maximumFractionDigits: 0 });
                                }
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

        // Tabla indicadores por usuario

        const $tbody = $('#tbody-usuarios-picking').empty();
        const usuarios = data.usuarios || [];
        if (!usuarios.length) {
            $tbody.html('<tr><td colspan=”8”><div class=”empty-state”><i class=”bi bi-inbox”></i>Sin datos</div></td></tr>');
        } else {
            $tbody.html(usuarios.map(u => `<tr>
                <td>${u.USUARIO || '—'}</td>
                <td class=”col-num”>${fmt.num(u.UNIDADES)}</td>
                <td class=”col-num”>${fmt.pct(u.PCT_UNIDADES)}</td>
                <td class=”col-num”>${fmt.num(u.PICO_PICKING)}</td>
                <td class=”col-num”>${fmt.num(u.MEDIANA_PICKING, 1)}</td>
                <td class=”col-num”>${fmt.num(u.HORAS, 1)}</td>
                <td class=”col-num”>${fmt.num(u.PROM_UNID_HORA, 1)}</td>
                <td class=”col-num”>${fmt.num(u.UNIDADES_ULT30)}</td>
            </tr>`).join(''));
        }

        renderPickingUlt7(data.ultimos7 || []);
    }

    function renderPickingUlt7(rows) {
        const $thead = $('#thead-picking-ult7').empty();
        const $tbody = $('#tbody-picking-ult7').empty();
        const fechas   = [...new Set(rows.map(r => r.FECHA_PICK))].sort();
        const usuarios = [...new Set(rows.map(r => r.USUARIO || '—'))];

        if (!fechas.length || !usuarios.length) {
            $thead.html('<tr><th>Usuario</th></tr>');
            $tbody.html('<tr><td><div class=”empty-state”><i class=”bi bi-inbox”></i>Sin datos</div></td></tr>');
            return;
        }

        // Una columna por fecha (unidades) + totales al final
        $thead.html(
            '<tr>' +
            '<th>Usuario</th>' +
            fechas.map(f => `<th class=”col-num”>${fmt.date(f)}<br><span class=”pick-sub”>Unid.</span></th>`).join('') +
            '<th class=”col-num col-total”>Total<br><span class=”pick-sub”>Unid.</span></th>' +
            '<th class=”col-num col-total”>Total<br><span class=”pick-sub”>Hs. prod.</span></th>' +
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
                return `<td class=”col-num”>${unid > 0 ? fmt.num(unid) : ''}</td>`;
            }).join('');
            totUnidGlobal += sumUnid;
            totHsGlobal   += sumHoras;
            return `<tr>` +
                `<td>${usuario}</td>` +
                cols +
                `<td class=”col-num pick-subtotal”>${sumUnid > 0 ? fmt.num(sumUnid) : ''}</td>` +
                `<td class=”col-num pick-subtotal”>${sumHoras > 0 ? fmt.num(sumHoras, 1) : ''}</td>` +
                `</tr>`;
        }).join(''));

        const totalFechaCols = fechas.map(f =>
            `<td class=”col-num”>${totUnidFecha[f] > 0 ? fmt.num(totUnidFecha[f]) : ''}</td>`
        ).join('');
        $tbody.append(
            `<tr class=”pick-total”>` +
            `<td>Total</td>` +
            totalFechaCols +
            `<td class=”col-num”>${totUnidGlobal > 0 ? fmt.num(totUnidGlobal) : ''}</td>` +
            `<td class=”col-num”>${totHsGlobal > 0 ? fmt.num(totHsGlobal, 1) : ''}</td>` +
            `</tr>`
        );
    }

    // ── Área 6: Demanda y Despacho ────────────────────────────────────────
    async function loadDespacho() {
        const data = await apiFetch('demanda_despacho');
        const k = data.kpis || {};

        $('#kv-dd-ped-tot').text(fmt.num(k.PED_TOTALES));
        $('#kv-dd-ped-pend').text(fmt.num(k.PED_PENDIENTES));
        const pct = parseFloat(k.PCT_UNID_CUMPLIDAS || 0);
        $('#kv-dd-cumpl').text(fmt.pct(k.PCT_UNID_CUMPLIDAS));
        setVar('#kvar-dd-cumpl', fmt.varLabel(k.PCT_UNID_CUMPLIDAS, k.META_CUMPLIMIENTO));

        $('#kv-dd-eficacia').text(fmt.pct(k.EFICACIA_DESPACHO));
        $('#kv-dd-prox-habil').text(fmt.date(k.PROX_DIA_HABIL));
        $('#kv-dd-pickers').text(fmt.num(k.PICKERS_NECESARIOS));
        $('#prox-habil-label').text(fmt.date(k.PROX_DIA_HABIL));

        // Tablas pendientes
        renderTablaSimple('#tbody-pend-hoy', data.pendientes_hoy || [],
            r => `<td>${r.NRO_PEDIDO}</td><td>${r.NOMBRE_CLIENTE || r.COD_CLIENT}</td><td class="col-num">${fmt.num(r.UNIDADES)}</td>`
        );
        renderTablaSimple('#tbody-demorados', data.demorados || [],
            r => `<td>${r.NRO_PEDIDO}</td><td>${r.NOMBRE_CLIENTE || r.COD_CLIENT}</td><td>${badgeEstado(r.ESTADO_DESPACHO)}</td><td class="col-num">${fmt.num(r.UNIDADES)}</td>`
        );
        renderTablaSimple('#tbody-prox-entrega', data.prox_entrega || [],
            r => `<td>${r.NRO_PEDIDO}</td><td>${r.COD_CLIENT}</td><td>${r.NOMBRE_CLIENTE || '—'}</td><td>${fmt.date(r.FECHA_PEDIDO)}</td><td class="col-num">${fmt.num(r.UNIDADES)}</td>`
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

        // Gráfico evolución mensual — siempre 12 meses; incluye año si abarca 2 años
        const evol = data.evolucion || [];
        const multiAnioPC = evol.length > 1 && evol[0].ANIO !== evol[evol.length - 1].ANIO;
        if (chartPedidos) chartPedidos.destroy();
        chartPedidos = new Chart($('#chart-pedidos-evol')[0], {
            type: 'bar',
            data: {
                labels  : evol.map(r => multiAnioPC ? r.NOMBRE_MES.substring(0, 3) + ' ' + r.ANIO : r.NOMBRE_MES),
                datasets: [{
                    label          : 'Pedidos',
                    data           : evol.map(r => r.PEDIDOS_MES),
                    backgroundColor: 'rgba(37,99,235,.65)',
                    borderRadius   : 4,
                }],
            },
            options: { ...chartOptions('Pedidos', {}, { integer: true }), plugins: { legend: { display: false } } },
        });

        // Tabla pedidos
        const $tbody = $('#tbody-pedidos').empty();
        const tabla  = data.tabla || [];
        if (!tabla.length) {
            $tbody.append('<tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }
        $tbody.html(tabla.map(r => `<tr>
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
    function renderTablaSimple(sel, rows, rowFn) {
        const $tbody = $(sel).empty();
        if (!rows.length) {
            $tbody.append(`<tr><td colspan="10"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>`);
            return;
        }
        $tbody.html(rows.map(r => '<tr>' + rowFn(r) + '</tr>').join(''));
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

    // ── Opciones base Chart.js ────────────────────────────────────────────
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[ch]));
    }

    function addInfoButton($target, title, tips, mode = 'append') {
        if (!$target.length || $target.find('.info-btn').length) return;
        const safeTitle = escapeHtml(title);
        const safeTips = (tips || []).map(escapeHtml).join('|');
        const $btn = $(`<button type="button" class="info-btn" aria-label="Ver ayuda: ${safeTitle}" title="Ver ayuda" data-info-title="${safeTitle}" data-info-tips="${safeTips}">
            <i class="bi bi-info-circle"></i>
        </button>`);
        mode === 'prepend' ? $target.prepend($btn) : $target.append($btn);
    }

    function initInfoPopover() {
        if (document.getElementById('logistica-info-popover')) return;
        const popover = document.createElement('div');
        popover.id = 'logistica-info-popover';
        popover.className = 'info-popover';
        document.body.appendChild(popover);

        let activeBtn = null;

        function show(btn) {
            if (activeBtn) activeBtn.classList.remove('active');
            activeBtn = btn;
            btn.classList.add('active');

            const title = btn.dataset.infoTitle || '';
            const tips = (btn.dataset.infoTips || '').split('|').filter(Boolean);
            popover.innerHTML =
                `<div class="info-popover-title"><i class="bi bi-info-circle-fill"></i>${title}</div>` +
                tips.map(t => `<div class="info-popover-tip">
                    <i class="bi bi-lightbulb-fill info-popover-tip-icon"></i>
                    <span>${t}</span>
                </div>`).join('');

            popover.style.display = 'block';
            position(btn);
        }

        function hide() {
            popover.style.display = 'none';
            if (activeBtn) activeBtn.classList.remove('active');
            activeBtn = null;
        }

        function position(btn) {
            const r = btn.getBoundingClientRect();
            const pw = popover.offsetWidth;
            const ph = popover.offsetHeight;
            let left = r.right - pw;
            let top = r.bottom + 8;
            if (left < 8) left = 8;
            if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
            if (top + ph > window.innerHeight - 8) top = r.top - ph - 8;
            popover.style.left = Math.max(8, left) + 'px';
            popover.style.top = Math.max(8, top) + 'px';
        }

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.info-btn');
            if (btn) {
                e.stopPropagation();
                activeBtn === btn ? hide() : show(btn);
                return;
            }
            if (activeBtn) hide();
        });

        document.addEventListener('mouseover', function (e) {
            const btn = e.target.closest('.info-btn');
            if (btn) show(btn);
        });

        document.addEventListener('mouseout', function (e) {
            const btn = e.target.closest('.info-btn');
            if (!btn) return;
            const next = e.relatedTarget;
            if (next && (btn.contains(next) || popover.contains(next))) return;
            hide();
        });

        document.addEventListener('focusin', function (e) {
            const btn = e.target.closest('.info-btn');
            if (btn) show(btn);
        });

        document.addEventListener('focusout', function (e) {
            const btn = e.target.closest('.info-btn');
            if (btn) hide();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') hide();
        });

        window.addEventListener('resize', function () {
            if (activeBtn) position(activeBtn);
        });
    }

    function initStaticTooltips() {
        initInfoPopover();

        $('#inp-desde').attr('title', 'Fecha inicial del periodo de analisis.');
        $('#inp-hasta').attr('title', 'Fecha final del periodo de analisis.');
        $('#sel-canal').attr('title', 'Filtra los datos por canal.');
        $('#sel-rubro').attr('title', 'Filtra los datos por rubro.');
        $('#sel-usuario').attr('title', 'Filtra los datos por usuario.');
        $('#btn-aplicar').attr({ title: 'Aplicar fechas y filtros seleccionados.', 'aria-label': 'Aplicar filtros' });
        $('#btn-reload').attr({ title: 'Recargar la pestana activa con los filtros actuales.', 'aria-label': 'Recargar pestana activa' });

        $('.tab-btn').each(function () {
            const label = $(this).text().trim().replace(/\s+/g, ' ');
            $(this).attr({ title: 'Ver ' + label, 'aria-label': 'Ver ' + label });
        });

        Object.entries(HELP.kpis).forEach(([id, cfg]) => {
            const $label = $('#' + id).closest('.kpi-body').find('.kpi-label').first();
            // Envolver nodos de texto en span para que ellipsis funcione sin cortar el botón
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

    // tOpts: { pct: bool, integer: bool, suffix: string }
    // pct     → muestra "95,3%" (el valor ya está en escala 0-100)
    // integer → muestra "1.234" sin decimales
    // suffix  → agrega texto al final, ej. " unid./h"
    function chartOptions(yLabel = '', yScale = {}, tOpts = {}) {
        const { pct = false, integer = false, suffix = '' } = tOpts;
        function fmtVal(v) {
            if (pct)     return Number(v).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
            if (integer) return Number(v).toLocaleString('es-AR', { maximumFractionDigits: 0 });
            return Number(v).toLocaleString('es-AR', { maximumFractionDigits: 1 });
        }
        return {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } },
                tooltip: {
                    backgroundColor: 'rgba(15,23,42,0.92)',
                    titleColor: '#f8fafc',
                    bodyColor: '#cbd5e1',
                    borderColor: 'rgba(255,255,255,0.12)',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label(ctx) {
                            const v = ctx.parsed.y;
                            if (v == null) return null;
                            return ' ' + ctx.dataset.label + ': ' + fmtVal(v) + suffix;
                        },
                    },
                },
            },
            scales: {
                x: { ticks: { maxRotation: 45, font: { size: 11 } } },
                y: { beginAtZero: true, ...yScale, title: { display: !!yLabel, text: yLabel } },
            },
        };
    }

    // ── Label período en topbar ───────────────────────────────────────────
    function updatePeriodLabel() {
        const d = State.desde, h = State.hasta;
        if (d && h) {
            $('#periodo-label').text(fmt.date(d) + ' — ' + fmt.date(h));
        }
    }

    // ── Bootstrap de la app ───────────────────────────────────────────────
    function init() {
        // Fechas por defecto: mes actual
        State.desde = $('#inp-desde').val() || new Date().toISOString().substring(0,7) + '-01';
        State.hasta = $('#inp-hasta').val() || new Date().toISOString().substring(0,10);
        updatePeriodLabel();
        initStaticTooltips();

        // Cargar filtros globales
        initFiltros();

        // Cargar tab inicial
        updateSlicers('eficiencia');
        loadTab('eficiencia');

        // ── Eventos ──────────────────────────────────────────────────────
        // Tab click
        $('.tab-btn').on('click', function () {
            const tab = $(this).data('tab');
            if (tab) switchTab(tab);
        });

        // Aplicar filtros
        $('#btn-aplicar').on('click', function () {
            const d = $('#inp-desde').val();
            const h = $('#inp-hasta').val();
            if (!d || !h) return;
            if (d > h) { alert('La fecha Desde no puede ser mayor que Hasta.'); return; }
            State.desde   = d;
            State.hasta   = h;
            State.canal   = $('#sel-canal').val()   || '';
            State.rubro   = $('#sel-rubro').val()   || '';
            State.usuario = $('#sel-usuario').val() || '';
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

        // Cambio de slicer canal/rubro/usuario → solo invalida tab actual
        $('#sel-canal, #sel-rubro, #sel-usuario').on('change', function () {
            State.canal   = $('#sel-canal').val()   || '';
            State.rubro   = $('#sel-rubro').val()   || '';
            State.usuario = $('#sel-usuario').val() || '';
            delete Cache[State.activeTab];
            loadTab(State.activeTab);
        });
    }

    // Arrancar cuando el DOM esté listo
    $(document).ready(init);

})(jQuery);
