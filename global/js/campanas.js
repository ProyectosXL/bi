/**
 * /bi/global/js/campanas.js
 * Módulo para la pestaña "Campañas De Ventas".
 * Gráficos de desglose por día, donas por rubro, tablas de facturación, unidades, stock y tickets.
 */

const Campanas = (() => {
    let _data = null;
    let _charts = {};
    let _expandedSucsFact = new Set();
    let _expandedSucsUnid = new Set();

    const $ = id => document.getElementById(id);

    function fmtMoney(n) {
        if (n == null || isNaN(n)) return '—';
        return '$\u00A0' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function fmtNum(n) {
        if (n == null || isNaN(n)) return '—';
        return Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function fmtPct(n) {
        if (n == null || isNaN(n)) return '—';
        return (n * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '\u00A0%';
    }

    function fmtVar(curr, prev) {
        if (!prev || prev === 0) return '<span style="color:var(--text-3)">Infinito</span>';
        const diff = (curr - prev) / Math.abs(prev);
        const sign = diff >= 0 ? '+' : '';
        const cls  = diff >= 0 ? 'campanas-var-pos' : 'campanas-var-neg';
        return `<span class="${cls}">${sign}${(diff * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}\u00A0%</span>`;
    }

    function fmtDiffCant(curr, prev) {
        if (prev == null) return '—';
        const diff = curr - prev;
        const sign = diff >= 0 ? '+' : '';
        const cls  = diff >= 0 ? 'campanas-var-pos' : 'campanas-var-neg';
        return `<span class="${cls}">${sign}${fmtNum(diff)}</span>`;
    }

    /* ── Carga de datos ────────────────────────────────────── */
    async function loadAll() {
        const wrap = $('tab-campanas');
        if (!wrap) return;

        const campana = $('sel-campana-tipo')?.value || 'madre';
        const anio    = $('sel-campana-anio')?.value || '2026';
        const evento  = $('sel-campana-evento')?.value || 'todos';
        const sucursal= $('sel-campana-sucursal')?.value || '';
        const rubro   = $('sel-campana-rubro')?.value || '%';

        const globalParams = Dashboard ? Dashboard.getParams() : {};
        const qs = new URLSearchParams({
            campana,
            anio,
            evento,
            sucursal,
            rubro,
            canal: globalParams.canal || ''
        }).toString();

        try {
            Dashboard.setLoading(true, 'Cargando Campañas De Ventas...');
            const res = await fetch(`/bi/global/api/campanas.php?${qs}`);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            _data = await res.json();
            if (!_data.ok) throw new Error(_data.error || 'Error al obtener datos');

            populateFiltrosHeader();
            updateInfoTag();
            renderMatrizDiaria();
            renderTablaTickets();
            renderTablasComparativas();
            renderCharts();
        } catch (err) {
            console.error('[Campanas] Error:', err);
            const content = wrap.querySelector('.dash-content');
            if (content) {
                content.insertAdjacentHTML('afterbegin', `<div class="analisis-error" style="margin-bottom:15px"><i class="bi bi-exclamation-triangle"></i> ${err.message}</div>`);
            }
        } finally {
            Dashboard.setLoading(false);
        }
    }

    function populateFiltrosHeader() {
        const selSuc = $('sel-campana-sucursal');
        if (selSuc && selSuc.options.length <= 1 && _data?.sucursales_lista) {
            const curSuc = selSuc.value;
            selSuc.innerHTML = '<option value="">Todas</option>' +
                _data.sucursales_lista.map(s => `<option value="${s.nro}" ${String(s.nro) === curSuc ? 'selected' : ''}>${s.nombre}</option>`).join('');
            selSuc.onchange = () => loadAll();
        }

        const selRub = $('sel-campana-rubro');
        if (selRub && selRub.options.length <= 1 && _data?.rubros_mes_act) {
            const curRub = selRub.value;
            const allRubros = Array.from(new Set([
                ...(_data.rubros_mes_act || []).map(r => r.rubro),
                ...(_data.rubros_mes_prev || []).map(r => r.rubro)
            ])).sort();
            selRub.innerHTML = '<option value="%">Todos</option>' +
                allRubros.map(r => `<option value="${r}" ${r === curRub ? 'selected' : ''}>${r}</option>`).join('');
            selRub.onchange = () => loadAll();
        }
    }

    function updateInfoTag() {
        const tag = $('campanas-info-rango');
        if (!tag || !_data?.config) return;
        const c = _data.config;
        tag.innerHTML = `<i class="bi bi-calendar-event"></i> Rango Especial: <strong>${c.dia_inicio} al ${c.dia_fin} de ${c.mes_nombre}</strong> (${c.nombre})`;
    }

    /* ── Render de Gráficos (4 cuadrantes) ─────────────────── */
    function destroyChart(id) {
        if (_charts[id]) {
            _charts[id].destroy();
            delete _charts[id];
        }
    }

    function renderCharts() {
        if (!_data) return;
        const anioAct  = _data.anio_seleccionado;
        const anioPrev = anioAct - 1;
        const mesNombre = (_data.config.mes_nombre || 'mes').toUpperCase();

        $('chart-title-pie-prev').textContent = `VTA - ${mesNombre} COMPLETO - ${anioPrev}`;
        $('chart-title-bar-prev').textContent = `VTA - ${mesNombre} COMPLETO - ${anioPrev}`;
        $('chart-title-pie-act').textContent  = `VTA - ${mesNombre} COMPLETO - ${anioAct}`;
        $('chart-title-bar-act').textContent  = `VTA - ${mesNombre} COMPLETO - ${anioAct}`;

        // 1. Dona Año Previo
        renderDonut('chart-pie-prev', _data.rubros_mes_prev || []);
        // 2. Dona Año Actual
        renderDonut('chart-pie-act', _data.rubros_mes_act || []);

        // 3. Barras Diarias Año Previo
        renderDailyBars('chart-bar-prev', _data.serie_dias || [], 'cant_prev', anioPrev);
        // 4. Barras Diarias Año Actual
        renderDailyBars('chart-bar-act', _data.serie_dias || [], 'cant_act', anioAct);
    }

    function renderDonut(canvasId, rubrosData) {
        destroyChart(canvasId);
        const canvas = $(canvasId);
        if (!canvas) return;

        // Top 7 rubros + Otros
        const top = rubrosData.slice(0, 7);
        const otros = rubrosData.slice(7);
        const otrosCant = otros.reduce((acc, r) => acc + (r.unidades || 0), 0);

        const labels = top.map(r => r.rubro);
        const values = top.map(r => r.unidades);
        if (otrosCant > 0) {
            labels.push('OTROS');
            values.push(otrosCant);
        }

        const colors = [
            '#f59e0b', '#3b82f6', '#10b981', '#ec4899', '#8b5cf6',
            '#06b6d4', '#64748b', '#cbd5e1'
        ];

        const totalUnid = values.reduce((a, b) => a + b, 0);

        _charts[canvasId] = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            plugins: typeof ChartDataLabels !== 'undefined' ? [ChartDataLabels] : [],
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors.slice(0, labels.length),
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: 10
                },
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            font: { family: 'Barlow', size: 11 },
                            boxWidth: 12
                        }
                    },
                    datalabels: {
                        color: '#1e293b',
                        font: { family: 'Barlow', weight: 'bold', size: 10 },
                        formatter: function(val) {
                            if (!val || val <= 0) return '';
                            const pct = totalUnid > 0 ? (val / totalUnid) * 100 : 0;
                            // Solo mostrar etiqueta si representa más del 4% para no empastar sectores mínimos
                            if (pct < 4) return '';
                            return fmtNum(val) + '\n(' + pct.toFixed(0) + '%)';
                        },
                        align: 'center',
                        textAlign: 'center'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const val = ctx.parsed;
                                const pct = total > 0 ? ((val / total) * 100).toFixed(1) + '%' : '0%';
                                return ` ${ctx.label}: ${fmtNum(val)} un. (${pct})`;
                            }
                        }
                    }
                },
                cutout: '54%'
            }
        });
    }

    function renderDailyBars(canvasId, serie, valueKey, anio) {
        destroyChart(canvasId);
        const canvas = $(canvasId);
        if (!canvas) return;

        const labels = serie.map(s => String(s.dia));
        const dataValues = serie.map(s => s[valueKey] || 0);
        // Colores según sea día especial de campaña o día regular
        const barColors = serie.map(s => s.es_campana ? '#f59e0b' : '#93c5fd');

        _charts[canvasId] = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            plugins: typeof ChartDataLabels !== 'undefined' ? [ChartDataLabels] : [],
            data: {
                labels,
                datasets: [{
                    label: `Unidades (${anio})`,
                    data: dataValues,
                    backgroundColor: barColors,
                    borderRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        top: 24,
                        bottom: 4
                    }
                },
                plugins: {
                    legend: { display: false },
                    datalabels: {
                        anchor: 'end',
                        align: 'top',
                        color: '#334155',
                        font: { family: 'Barlow', weight: 'bold', size: 9 },
                        formatter: function(val) {
                            return val > 0 ? fmtNum(val) : '';
                        },
                        offset: 1
                    },
                    tooltip: {
                        callbacks: {
                            title: items => `Día ${items[0].label} de ${_data.config.mes_nombre} (${anio})`,
                            label: ctx => {
                                const s = serie[ctx.dataIndex];
                                return [
                                    `Tipo: ${s.tipo}`,
                                    `Unidades: ${fmtNum(ctx.parsed.y)}`
                                ];
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { family: 'Barlow', size: 10 } }
                    },
                    y: {
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: {
                            font: { family: 'Barlow', size: 10 },
                            callback: v => v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v
                        }
                    }
                }
            }
        });
    }

    /* ── Render de Matriz Diaria Objetivo vs Facturación ───── */
    function renderMatrizDiaria() {
        const wrap = $('campanas-matriz-wrap');
        const labelSuc = $('campanas-matriz-suc-label');
        if (!wrap || !_data) return;

        const sucs = _data.sucursales_lista || [];
        const selSucFilter = $('sel-campana-sucursal')?.value;

        // Si hay una sucursal seleccionada en el filtro, mostramos solo esa; si dice "Todas", mostramos todas las sucursales
        const sucsToRender = selSucFilter 
            ? sucs.filter(s => String(s.nro) === String(selSucFilter))
            : sucs;

        if (labelSuc) {
            if (selSucFilter && sucsToRender.length === 1) {
                labelSuc.innerHTML = `Mostrando: <strong>${sucsToRender[0].nombre}</strong>`;
            } else {
                labelSuc.innerHTML = `Mostrando: <strong>Todas las sucursales (${sucsToRender.length})</strong>`;
            }
        }

        const matrizTotal = _data.matriz_diaria || {};
        const cfg = _data.config;
        const diasTot = cfg.dias_mes;
        const anioAct = _data.anio_seleccionado;
        const selEventoFilter = $('sel-campana-evento')?.value || 'todos';

        // Determinar qué días mostrar según el filtro de Evento Especial
        const diasToRender = [];
        for (let d = 1; d <= diasTot; d++) {
            const isCamp = (d >= cfg.dia_inicio && d <= cfg.dia_fin);
            if (selEventoFilter === 'campana' && !isCamp) continue;
            if (selEventoFilter === 'regular' && isCamp) continue;
            diasToRender.push(d);
        }

        let theadHtml = `
            <tr>
                <th class="sticky-col" style="min-width:200px">AÑO: ${anioAct}</th>
                <th colspan="${diasToRender.length + 1}" style="text-align:left; background: #243060; color:#fff">MES: ${cfg.mes_nombre.toUpperCase()} — APERTURA POR SUCURSAL (DÍA A DÍA)</th>
            </tr>
            <tr>
                <th class="sticky-col" style="min-width:200px">SUCURSAL / MÉTRICA</th>`;
        for (const d of diasToRender) {
            const isCamp = (d >= cfg.dia_inicio && d <= cfg.dia_fin);
            const cls = isCamp ? 'header-day-campana' : '';
            theadHtml += `<th style="text-align:right; min-width:85px" class="${cls}">${d}</th>`;
        }
        theadHtml += `<th style="text-align:right; min-width:110px">TOTAL</th></tr>`;

        // Acumuladores globales para el pie de tabla (TOTAL GENERAL)
        const totalPorDiaObj = {};
        const totalPorDiaFact = {};
        const totalPorDiaFactPrev = {};
        for (const d of diasToRender) {
            totalPorDiaObj[d] = 0;
            totalPorDiaFact[d] = 0;
            totalPorDiaFactPrev[d] = 0;
        }

        let tbodyHtml = '';
        const anioPrev = anioAct - 1;

        sucsToRender.forEach(s => {
            const sMatriz = matrizTotal[s.nro] || {};

            let sTotObj = 0, sTotFact = 0, sTotFactPrev = 0;
            let rowObj      = `<tr class="row-matriz-metric"><td class="sticky-col">Importe Objetivo (${anioAct})</td>`;
            let rowFact     = `<tr class="row-matriz-metric"><td class="sticky-col">Importe Facturación (${anioAct})</td>`;
            let rowCumpl    = `<tr class="row-matriz-metric"><td class="sticky-col">Facturación vs Objetivo</td>`;
            let rowFalta    = `<tr class="row-matriz-metric"><td class="sticky-col">Falta Para Objetivo</td>`;
            let rowFactPrev = `<tr class="row-matriz-metric" style="background:#f8fafc"><td class="sticky-col" style="color:var(--text-2)">Facturación Año Anterior (${anioPrev})</td>`;
            let rowVarPrev  = `<tr class="row-matriz-metric" style="background:#f8fafc"><td class="sticky-col" style="color:var(--text-2)">Vs Año Anterior (${anioAct} vs ${anioPrev})</td>`;

            for (const d of diasToRender) {
                const val = sMatriz[d] || { fact: 0, obj: 0, fact_prev: 0 };
                const isCamp = (d >= cfg.dia_inicio && d <= cfg.dia_fin);
                const tdCls = isCamp ? 'class="cell-day-campana"' : '';

                const obj = val.obj || 0;
                const fact = val.fact || 0;
                const factPrev = val.fact_prev || 0;

                sTotObj += obj;
                sTotFact += fact;
                sTotFactPrev += factPrev;

                totalPorDiaObj[d] += obj;
                totalPorDiaFact[d] += fact;
                totalPorDiaFactPrev[d] += factPrev;

                const cumpl = obj > 0 ? (fact / obj) : (fact > 0 ? 1 : 0);
                const falta = Math.max(0, obj - fact);

                rowObj      += `<td style="text-align:right" ${tdCls}>${fmtMoney(obj)}</td>`;
                rowFact     += `<td style="text-align:right; font-weight:600" ${tdCls}>${fmtMoney(fact)}</td>`;
                rowCumpl    += `<td style="text-align:right; font-weight:600; color:${cumpl >= 1 ? 'var(--pos)' : 'var(--neg)'}" ${tdCls}>${fmtPct(cumpl)}</td>`;
                rowFalta    += `<td style="text-align:right" ${tdCls}>${fmtMoney(falta)}</td>`;
                rowFactPrev += `<td style="text-align:right; color:var(--text-2)" ${tdCls}>${fmtMoney(factPrev)}</td>`;
                rowVarPrev  += `<td style="text-align:right; font-weight:600" ${tdCls}>${fmtVar(fact, factPrev)}</td>`;
            }

            const sTotCumpl = sTotObj > 0 ? (sTotFact / sTotObj) : (sTotFact > 0 ? 1 : 0);
            const sTotFalta = Math.max(0, sTotObj - sTotFact);

            rowObj      += `<td style="text-align:right; font-weight:700">${fmtMoney(sTotObj)}</td></tr>`;
            rowFact     += `<td style="text-align:right; font-weight:700">${fmtMoney(sTotFact)}</td></tr>`;
            rowCumpl    += `<td style="text-align:right; font-weight:700; color:${sTotCumpl >= 1 ? 'var(--pos)' : 'var(--neg)'}">${fmtPct(sTotCumpl)}</td></tr>`;
            rowFalta    += `<td style="text-align:right; font-weight:700">${fmtMoney(sTotFalta)}</td></tr>`;
            rowFactPrev += `<td style="text-align:right; font-weight:700; color:var(--text-2)">${fmtMoney(sTotFactPrev)}</td></tr>`;
            rowVarPrev  += `<td style="text-align:right; font-weight:700">${fmtVar(sTotFact, sTotFactPrev)}</td></tr>`;

            // Fila de encabezado de la sucursal
            tbodyHtml += `
                <tr class="row-matriz-suc">
                    <td class="sticky-col" style="background:#eef2f9; font-weight:700; color:#1e293b; padding-left:10px">
                        <i class="bi bi-shop" style="margin-right:6px; color:var(--accent2)"></i>${s.nombre}
                    </td>
                    <td colspan="${diasToRender.length + 1}" style="background:#eef2f9"></td>
                </tr>
                ${rowObj}
                ${rowFact}
                ${rowCumpl}
                ${rowFalta}
                ${rowFactPrev}
                ${rowVarPrev}
            `;
        });

        // Fila de TOTAL GENERAL (Pie de tabla)
        let tfootObj      = `<tr><td class="sticky-col">TOTAL OBJETIVO (${anioAct})</td>`;
        let tfootFact     = `<tr><td class="sticky-col">TOTAL FACTURACIÓN (${anioAct})</td>`;
        let tfootCumpl    = `<tr><td class="sticky-col">TOTAL CUMPLIMIENTO</td>`;
        let tfootFalta    = `<tr><td class="sticky-col">TOTAL FALTA OBJETIVO</td>`;
        let tfootFactPrev = `<tr><td class="sticky-col" style="color:rgba(255,255,255,0.85)">TOTAL FACTURACIÓN (${anioPrev})</td>`;
        let tfootVarPrev  = `<tr><td class="sticky-col" style="color:rgba(255,255,255,0.85)">TOTAL VS AÑO ANTERIOR</td>`;

        let gTotObj = 0, gTotFact = 0, gTotFactPrev = 0;
        for (const d of diasToRender) {
            const dObj = totalPorDiaObj[d] || 0;
            const dFact = totalPorDiaFact[d] || 0;
            const dFactPrev = totalPorDiaFactPrev[d] || 0;

            gTotObj += dObj;
            gTotFact += dFact;
            gTotFactPrev += dFactPrev;

            const dCumpl = dObj > 0 ? (dFact / dObj) : (dFact > 0 ? 1 : 0);
            const dFalta = Math.max(0, dObj - dFact);

            tfootObj      += `<td style="text-align:right">${fmtMoney(dObj)}</td>`;
            tfootFact     += `<td style="text-align:right">${fmtMoney(dFact)}</td>`;
            tfootCumpl    += `<td style="text-align:right; color:${dCumpl >= 1 ? '#86efac' : '#fca5a5'}">${fmtPct(dCumpl)}</td>`;
            tfootFalta    += `<td style="text-align:right">${fmtMoney(dFalta)}</td>`;
            tfootFactPrev += `<td style="text-align:right">${fmtMoney(dFactPrev)}</td>`;
            tfootVarPrev  += `<td style="text-align:right">${fmtVar(dFact, dFactPrev)}</td>`;
        }

        const gTotCumpl = gTotObj > 0 ? (gTotFact / gTotObj) : (gTotFact > 0 ? 1 : 0);
        const gTotFalta = Math.max(0, gTotObj - gTotFact);

        tfootObj      += `<td style="text-align:right">${fmtMoney(gTotObj)}</td></tr>`;
        tfootFact     += `<td style="text-align:right">${fmtMoney(gTotFact)}</td></tr>`;
        tfootCumpl    += `<td style="text-align:right; color:${gTotCumpl >= 1 ? '#86efac' : '#fca5a5'}">${fmtPct(gTotCumpl)}</td></tr>`;
        tfootFalta    += `<td style="text-align:right">${fmtMoney(gTotFalta)}</td></tr>`;
        tfootFactPrev += `<td style="text-align:right">${fmtMoney(gTotFactPrev)}</td></tr>`;
        tfootVarPrev  += `<td style="text-align:right">${fmtVar(gTotFact, gTotFactPrev)}</td></tr>`;

        wrap.innerHTML = `
            <table class="campanas-table">
                <thead>${theadHtml}</thead>
                <tbody>
                    ${tbodyHtml}
                </tbody>
                <tfoot>
                    ${tfootObj}
                    ${tfootFact}
                    ${tfootCumpl}
                    ${tfootFalta}
                    ${tfootFactPrev}
                    ${tfootVarPrev}
                </tfoot>
            </table>
        `;
    }

    /* ── Render de Tablas Comparativas (Facturación y Unidades) ── */
    function renderTablasComparativas() {
        if (!_data) return;
        const anios = _data.anios;
        const sucsData = _data.comparativa_sucursales || [];

        // 1. Tabla de Facturación
        renderTablaFacturacion(anios, sucsData);
        // 2. Tabla de Unidades & Stock
        renderTablaUnidades(anios, sucsData);
    }

    function renderTablaFacturacion(anios, sucsData) {
        const wrap = $('campanas-tabla-fact-wrap');
        if (!wrap) return;

        const [a0, a1, a2] = anios;
        let html = `
            <table class="campanas-table" id="tabla-campana-fact">
                <thead>
                    <tr>
                        <th class="sticky-col" style="min-width:220px">Sucursal / Rubro</th>
                        <th style="text-align:right; min-width:110px">Objetivo ($)</th>
                        <th style="text-align:right; min-width:130px">Vta Sem. ${a0}</th>
                        <th style="text-align:right; min-width:130px">Vta Sem. ${a1}</th>
                        <th style="text-align:right; min-width:110px">Tendencia ${a1} vs ${a0}</th>
                        <th style="text-align:right; min-width:130px">Vta Sem. ${a2}</th>
                    </tr>
                </thead>
                <tbody>
        `;

        let totObj = 0, totA0 = 0, totA1 = 0, totA2 = 0;

        sucsData.forEach(s => {
            const v0 = s.fact_anios[a0] || 0;
            const v1 = s.fact_anios[a1] || 0;
            const v2 = s.fact_anios[a2] || 0;
            const obj = s.obj_octubre || 0;

            totObj += obj;
            totA0  += v0;
            totA1  += v1;
            totA2  += v2;

            const isExpanded = _expandedSucsFact.has(s.nro);
            const rubrosList = Object.values(s.rubros || {});
            const hasRubros = rubrosList.length > 0;
            let sucNombre = (!s.nombre || s.nombre.startsWith('Suc. ')) 
                ? (Dashboard?.getSucNombre?.(s.nro) || s.nombre || ('Sucursal ' + s.nro))
                : s.nombre;
            if (s.nro === 1 || /DAFITI/i.test(sucNombre)) {
                sucNombre = 'ECOMMERCE ML';
            }

            html += `
                <tr class="row-sucursal" data-suc="${s.nro}">
                    <td class="sticky-col">
                        ${hasRubros ? `<button class="btn-toggle-suc" onclick="Campanas.toggleSucFact(${s.nro})"><i class="bi bi-${isExpanded ? 'dash' : 'plus'}-square"></i></button>` : ''}
                        ${sucNombre}
                    </td>
                    <td style="text-align:right">${fmtMoney(obj)}</td>
                    <td style="text-align:right">${fmtMoney(v0)}</td>
                    <td style="text-align:right">${fmtMoney(v1)}</td>
                    <td style="text-align:right">${fmtVar(v1, v0)}</td>
                    <td style="text-align:right; font-weight:700">${fmtMoney(v2)}</td>
                </tr>
            `;

            if (isExpanded) {
                rubrosList.forEach(r => {
                    const rv0 = r.fact_anios[a0] || 0;
                    const rv1 = r.fact_anios[a1] || 0;
                    const rv2 = r.fact_anios[a2] || 0;
                    html += `
                        <tr class="row-rubro" style="background:#f8fafc">
                            <td class="sticky-col">${r.rubro}</td>
                            <td style="text-align:right; color:var(--text-3)">—</td>
                            <td style="text-align:right">${fmtMoney(rv0)}</td>
                            <td style="text-align:right">${fmtMoney(rv1)}</td>
                            <td style="text-align:right">${fmtVar(rv1, rv0)}</td>
                            <td style="text-align:right">${fmtMoney(rv2)}</td>
                        </tr>
                    `;
                });
            }
        });

        html += `
                </tbody>
                <tfoot>
                    <tr>
                        <td class="sticky-col">TOTAL</td>
                        <td style="text-align:right">${fmtMoney(totObj)}</td>
                        <td style="text-align:right">${fmtMoney(totA0)}</td>
                        <td style="text-align:right">${fmtMoney(totA1)}</td>
                        <td style="text-align:right">${fmtVar(totA1, totA0)}</td>
                        <td style="text-align:right">${fmtMoney(totA2)}</td>
                    </tr>
                </tfoot>
            </table>
        `;
        wrap.innerHTML = html;
    }

    function renderTablaUnidades(anios, sucsData) {
        const wrap = $('campanas-tabla-unid-wrap');
        if (!wrap) return;

        const [a0, a1, a2] = anios;
        let html = `
            <table class="campanas-table" id="tabla-campana-unid">
                <thead>
                    <tr>
                        <th class="sticky-col" style="min-width:220px">Sucursal / Rubro</th>
                        <th style="text-align:right; min-width:110px">Unid. Sem. ${a0}</th>
                        <th style="text-align:right; min-width:110px">Unid. Sem. ${a1}</th>
                        <th style="text-align:right; min-width:110px">Tendencia ${a1} vs ${a0}</th>
                        <th style="text-align:right; min-width:110px">Unid. Sem. ${a2}</th>
                        <th style="text-align:right; min-width:110px">Stock Actual</th>
                    </tr>
                </thead>
                <tbody>
        `;

        let totA0 = 0, totA1 = 0, totA2 = 0, totStock = 0;

        sucsData.forEach(s => {
            const v0 = s.unid_anios[a0] || 0;
            const v1 = s.unid_anios[a1] || 0;
            const v2 = s.unid_anios[a2] || 0;
            const st = s.stock_actual || 0;

            totA0    += v0;
            totA1    += v1;
            totA2    += v2;
            totStock += st;

            const isExpanded = _expandedSucsUnid.has(s.nro);
            const rubrosList = Object.values(s.rubros || {});
            const hasRubros = rubrosList.length > 0;
            let sucNombre = (!s.nombre || s.nombre.startsWith('Suc. ')) 
                ? (Dashboard?.getSucNombre?.(s.nro) || s.nombre || ('Sucursal ' + s.nro))
                : s.nombre;
            if (s.nro === 1 || /DAFITI/i.test(sucNombre)) {
                sucNombre = 'ECOMMERCE ML';
            }

            html += `
                <tr class="row-sucursal" data-suc="${s.nro}">
                    <td class="sticky-col">
                        ${hasRubros ? `<button class="btn-toggle-suc" onclick="Campanas.toggleSucUnid(${s.nro})"><i class="bi bi-${isExpanded ? 'dash' : 'plus'}-square"></i></button>` : ''}
                        ${sucNombre}
                    </td>
                    <td style="text-align:right">${fmtNum(v0)}</td>
                    <td style="text-align:right">${fmtNum(v1)}</td>
                    <td style="text-align:right">${fmtVar(v1, v0)}</td>
                    <td style="text-align:right; font-weight:700">${fmtNum(v2)}</td>
                    <td style="text-align:right; font-weight:600">${fmtNum(st)}</td>
                </tr>
            `;

            if (isExpanded) {
                rubrosList.forEach(r => {
                    const rv0 = r.unid_anios[a0] || 0;
                    const rv1 = r.unid_anios[a1] || 0;
                    const rv2 = r.unid_anios[a2] || 0;
                    const rst = r.stock || 0;
                    html += `
                        <tr class="row-rubro" style="background:#f8fafc">
                            <td class="sticky-col">${r.rubro}</td>
                            <td style="text-align:right">${fmtNum(rv0)}</td>
                            <td style="text-align:right">${fmtNum(rv1)}</td>
                            <td style="text-align:right">${fmtVar(rv1, rv0)}</td>
                            <td style="text-align:right">${fmtNum(rv2)}</td>
                            <td style="text-align:right">${fmtNum(rst)}</td>
                        </tr>
                    `;
                });
            }
        });

        html += `
                </tbody>
                <tfoot>
                    <tr>
                        <td class="sticky-col">TOTAL</td>
                        <td style="text-align:right">${fmtNum(totA0)}</td>
                        <td style="text-align:right">${fmtNum(totA1)}</td>
                        <td style="text-align:right">${fmtVar(totA1, totA0)}</td>
                        <td style="text-align:right">${fmtNum(totA2)}</td>
                        <td style="text-align:right">${fmtNum(totStock)}</td>
                    </tr>
                </tfoot>
            </table>
        `;
        wrap.innerHTML = html;
    }

    /* ── Render de Tabla de Tickets ────────────────────────── */
    function renderTablaTickets() {
        const wrap = $('campanas-tabla-tickets-wrap');
        if (!wrap || !_data) return;

        const [a0, a1, a2] = _data.anios;
        const sucs = _data.sucursales_lista || [];
        const ticketsMap = _data.tickets_sucursales || {};

        let html = `
            <table class="campanas-table" id="tabla-campana-tickets">
                <thead>
                    <tr>
                        <th class="sticky-col" style="min-width:220px">Sucursal</th>
                        <th style="text-align:right; min-width:110px">Cantidad Tickets ${a0}</th>
                        <th style="text-align:right; min-width:110px">Cantidad Tickets ${a1}</th>
                        <th style="text-align:right; min-width:110px">Dif Cant ${a1} vs ${a0}</th>
                        <th style="text-align:right; min-width:110px">Dif % ${a1} vs ${a0}</th>
                        <th style="text-align:right; min-width:110px">Cantidad Tickets ${a2}</th>
                        <th style="text-align:right; min-width:110px">Dif Cant ${a2} vs ${a1}</th>
                        <th style="text-align:right; min-width:110px">Dif % ${a2} vs ${a1}</th>
                    </tr>
                </thead>
                <tbody>
        `;

        let totA0 = 0, totA1 = 0, totA2 = 0;

        sucs.forEach(s => {
            const t = ticketsMap[s.nro] || {};
            const tk0 = t[a0] || 0;
            const tk1 = t[a1] || 0;
            const tk2 = t[a2] || 0;

            totA0 += tk0;
            totA1 += tk1;
            totA2 += tk2;

            html += `
                <tr>
                    <td class="sticky-col">${s.nombre}</td>
                    <td style="text-align:right">${fmtNum(tk0)}</td>
                    <td style="text-align:right">${fmtNum(tk1)}</td>
                    <td style="text-align:right">${fmtDiffCant(tk1, tk0)}</td>
                    <td style="text-align:right">${fmtVar(tk1, tk0)}</td>
                    <td style="text-align:right; font-weight:700">${fmtNum(tk2)}</td>
                    <td style="text-align:right">${fmtDiffCant(tk2, tk1)}</td>
                    <td style="text-align:right">${fmtVar(tk2, tk1)}</td>
                </tr>
            `;
        });

        html += `
                </tbody>
                <tfoot>
                    <tr>
                        <td class="sticky-col">TOTAL</td>
                        <td style="text-align:right">${fmtNum(totA0)}</td>
                        <td style="text-align:right">${fmtNum(totA1)}</td>
                        <td style="text-align:right">${fmtDiffCant(totA1, totA0)}</td>
                        <td style="text-align:right">${fmtVar(totA1, totA0)}</td>
                        <td style="text-align:right">${fmtNum(totA2)}</td>
                        <td style="text-align:right">${fmtDiffCant(totA2, totA1)}</td>
                        <td style="text-align:right">${fmtVar(totA2, totA1)}</td>
                    </tr>
                </tfoot>
            </table>
        `;
        wrap.innerHTML = html;
    }

    function toggleSucFact(nro) {
        if (_expandedSucsFact.has(nro)) _expandedSucsFact.delete(nro);
        else _expandedSucsFact.add(nro);
        if (_data) renderTablaFacturacion(_data.anios, _data.comparativa_sucursales || []);
    }

    function toggleSucUnid(nro) {
        if (_expandedSucsUnid.has(nro)) _expandedSucsUnid.delete(nro);
        else _expandedSucsUnid.add(nro);
        if (_data) renderTablaUnidades(_data.anios, _data.comparativa_sucursales || []);
    }

    return {
        loadAll,
        toggleSucFact,
        toggleSucUnid
    };
})();
