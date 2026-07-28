/**
 * /bi/global/js/circulacion.js
 * Pestaña Circulación: merodeo, ingresos, tasa de atracción y tasa de conversión.
 * Depende de Dashboard.buildQS(), Dashboard.getSucNombre(), Dashboard.isSoloActivas()
 * y Dashboard.getSucursalesActivasIds().
 */

const Circulacion = (() => {

    let _lastResumen    = null;
    let _lastSucursales = null;
    let _evolucionMeses = 13;
    let _sortCol = 'facturacion';
    let _sortDir = 'desc';

    let _chartEvol = null;
    let _chartQuadrant = null;

    /* ── Formato ─────────────────────────────── */
    const fmt = {
        num   : n => n === null || n === undefined ? '—' : Number(n).toLocaleString('es-AR'),
        money : n => n === null || n === undefined ? '—' : '$' + Number(n).toLocaleString('es-AR', { maximumFractionDigits: 0 }),
        pct   : (n, d = 1) => n === null || n === undefined ? '—'
            : (n * 100).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }) + ' %',
        varPct: (n, d = 1) => {
            if (n === null || n === undefined) return '—';
            const s = n >= 0 ? '+' : '';
            return s + (n * 100).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }) + ' %';
        },
    };

    /* ── KPI cards ───────────────────────────── */
    function setKpi(id, valText, ratio, prevText) {
        const elVal  = document.getElementById(id + '-val');
        const elVar  = document.getElementById(id + '-var');
        const elPrev = document.getElementById(id + '-prev');
        if (elVal)  elVal.textContent = valText;
        if (elVar) {
            elVar.textContent = fmt.varPct(ratio);
            elVar.className   = 'kpi-var ' + (ratio >= 0 ? 'pos' : 'neg');
        }
        if (elPrev) elPrev.textContent = prevText;
    }

    function renderKpis(payload) {
        const a = payload.actual, p = payload.previo, v = payload.variacion;

        setKpi('circ-merodeo',    fmt.num(a.merodeo),                v.merodeo,             fmt.num(p.merodeo));
        setKpi('circ-ingresos',   fmt.num(a.ingresos),               v.ingresos,            fmt.num(p.ingresos));
        setKpi('circ-atraccion',  fmt.pct(a.atraccion),              v.atraccion,           fmt.pct(p.atraccion));
        setKpi('circ-tickets',    fmt.num(a.tickets),                v.tickets,             fmt.num(p.tickets));
        setKpi('circ-conversion', fmt.pct(a.conversion),             v.conversion,          fmt.pct(p.conversion));
        setKpi('circ-vpv',        fmt.money(a.venta_por_visitante),  v.venta_por_visitante, fmt.money(p.venta_por_visitante));

        // Chip de cobertura
        const chip = document.getElementById('circ-cobertura-chip');
        if (chip) {
            const con = a.sucursales_con_sensor ?? 0;
            const tot = a.sucursales_total ?? 0;
            chip.innerHTML = `<i class="bi bi-broadcast"></i> ${con} de ${tot} sucursales con sensor de tráfico en el período`;
            chip.classList.toggle('circ-chip-warning', tot > 0 && con < tot);
        }

        renderFunnel(a);
    }

    /* ── Embudo Merodeo → Ingresos → Tickets ─── */
    function renderFunnel(a) {
        const wrap = document.getElementById('circ-funnel');
        if (!wrap) return;

        const stages = [
            { label: 'Merodeo',  value: a.merodeo,  icon: 'bi-person-walking' },
            { label: 'Ingresos', value: a.ingresos, icon: 'bi-door-open-fill' },
            { label: 'Tickets',  value: a.tickets,  icon: 'bi-receipt' },
        ];
        const max = Math.max(1, ...stages.map(s => s.value || 0));
        const pasos = [a.atraccion, a.conversion];

        let html = '';
        stages.forEach((s, i) => {
            const widthPct = Math.max(14, (s.value || 0) / max * 100);
            html += `
                <div class="funnel-stage">
                    <div class="funnel-icon"><i class="bi ${s.icon}"></i></div>
                    <div class="funnel-bar-track">
                        <div class="funnel-bar" style="width:${widthPct.toFixed(1)}%"></div>
                    </div>
                    <div class="funnel-value">${fmt.num(s.value)}</div>
                    <div class="funnel-label">${s.label}</div>
                </div>`;
            if (i < stages.length - 1) {
                html += `
                    <div class="funnel-connector">
                        <i class="bi bi-chevron-right"></i>
                        <span class="funnel-connector-pct">${fmt.pct(pasos[i])}</span>
                    </div>`;
            }
        });
        wrap.innerHTML = html;
    }

    /* ── Semáforo vs. media ponderada de la cadena ─ */
    function semaforoClass(value, avg) {
        if (value === null || value === undefined || !avg) return '';
        const ratio = value / avg;
        if (ratio >= 1.05) return 'sem-green';
        if (ratio >= 0.90) return 'sem-yellow';
        return 'sem-red';
    }

    /* ── Tabla por sucursal ──────────────────── */
    function renderTabla() {
        const tbody = document.getElementById('tbody-circulacion-sucursales');
        if (!tbody || !_lastSucursales) return;

        let rows = _lastSucursales;
        if (typeof Dashboard !== 'undefined' && Dashboard.isSoloActivas?.()) {
            const ids = Dashboard.getSucursalesActivasIds?.();
            if (ids?.size) rows = rows.filter(r => ids.has(+r.nro_sucurs));
        }

        const avgAtraccion  = _lastResumen?.actual?.atraccion  ?? 0;
        const avgConversion = _lastResumen?.actual?.conversion ?? 0;

        const sorted = [...rows].sort((x, y) => {
            const vx = x[_sortCol], vy = y[_sortCol];
            const nx = vx === null || vx === undefined ? -Infinity : vx;
            const ny = vy === null || vy === undefined ? -Infinity : vy;
            if (typeof nx === 'string') {
                const cmp = nx.localeCompare(ny, 'es');
                return _sortDir === 'asc' ? cmp : -cmp;
            }
            return _sortDir === 'asc' ? nx - ny : ny - nx;
        });

        const getSucNombre = n => (typeof Dashboard !== 'undefined' ? Dashboard.getSucNombre(n) : null);

        tbody.innerHTML = sorted.map(r => {
            const nombre = getSucNombre(r.nro_sucurs) || r.desc_sucursal || ('Suc. ' + r.nro_sucurs);
            const claseAtr  = semaforoClass(r.atraccion,  avgAtraccion);
            const claseConv = semaforoClass(r.conversion, avgConversion);
            const sinSensor = !r.tiene_sensor;
            return `<tr class="${sinSensor ? 'circ-sin-sensor' : ''}">
                <td class="td-nombre">${nombre}${sinSensor ? ' <i class="bi bi-exclamation-triangle-fill circ-icon-sin-sensor" title="Sin sensor de tráfico en el período"></i>' : ''}</td>
                <td class="text-right">${r.tiene_sensor ? fmt.num(r.merodeo) : '—'}</td>
                <td class="text-right">${r.tiene_sensor ? fmt.num(r.ingresos) : '—'}</td>
                <td class="text-right">${fmt.num(r.tickets)}</td>
                <td class="text-right">${fmt.money(r.facturacion)}</td>
                <td class="text-right ${claseAtr}">${r.tiene_sensor ? fmt.pct(r.atraccion) : '—'}</td>
                <td class="text-right ${claseConv}">${r.tiene_sensor ? fmt.pct(r.conversion) : '—'}</td>
                <td class="text-right">${fmt.money(r.ticket_promedio)}</td>
                <td class="text-right">${r.tiene_sensor ? fmt.money(r.venta_por_visitante) : '—'}</td>
            </tr>`;
        }).join('');

        document.querySelectorAll('#table-circulacion-sucursales thead th[data-col]').forEach(th => {
            th.classList.remove('sort-asc', 'sort-desc');
            if (th.dataset.col === _sortCol) th.classList.add(_sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
        });
    }

    function exportarSucursales() {
        if (!_lastSucursales?.length || typeof ExcelExporter === 'undefined') return;
        let rows = _lastSucursales;
        if (typeof Dashboard !== 'undefined' && Dashboard.isSoloActivas?.()) {
            const ids = Dashboard.getSucursalesActivasIds?.();
            if (ids?.size) rows = rows.filter(r => ids.has(+r.nro_sucurs));
        }
        const getSucNombre = n => (typeof Dashboard !== 'undefined' ? Dashboard.getSucNombre(n) : null);

        const headers = ['Sucursal', 'Merodeo', 'Ingresos', 'Tickets', 'Facturación', 'Atracción %', 'Conversión %', 'Ticket promedio', 'Venta por visitante'];
        const dataRows = rows.map(r => [
            getSucNombre(r.nro_sucurs) || r.desc_sucursal || ('Suc. ' + r.nro_sucurs),
            r.tiene_sensor ? r.merodeo  : null,
            r.tiene_sensor ? r.ingresos : null,
            r.tickets,
            r.facturacion,
            r.tiene_sensor ? r.atraccion  : null,
            r.tiene_sensor ? r.conversion : null,
            r.ticket_promedio,
            r.tiene_sensor ? r.venta_por_visitante : null,
        ]);

        ExcelExporter.export({
            title     : 'Circulación por Sucursal',
            headers,
            rows      : dataRows,
            colFormats: ['text', 'num', 'num', 'num', 'money', 'pct', 'pct', 'money', 'money'],
            filename  : 'circulacion_sucursales',
        });
    }

    /* ── Evolución mensual (Chart.js combinado) ─ */
    async function cargarEvolucion() {
        const canvas = document.getElementById('chart-circulacion-evol');
        if (!canvas) return;
        try {
            const qs   = Dashboard.buildQS({ action: 'evolucion', meses: _evolucionMeses });
            const res  = await fetch(`/bi/global/api/circulacion.php?${qs}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Error en evolución de circulación');
            renderEvolucion(data.evolucion || []);
        } catch (e) {
            console.error('[Circulacion] evolución', e);
        }
    }

    function renderEvolucion(serie) {
        const canvas = document.getElementById('chart-circulacion-evol');
        if (!canvas) return;
        if (_chartEvol) { _chartEvol.destroy(); _chartEvol = null; }

        const labels = serie.map(r => {
            const [y, m] = r.periodo.split('-');
            const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
            return MESES[parseInt(m, 10) - 1] + ' ' + y.slice(2);
        });

        _chartEvol = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { type: 'bar',  label: 'Merodeo',      data: serie.map(r => r.merodeo),  backgroundColor: 'rgba(59,130,246,.45)',  borderColor: '#3b82f6', borderWidth: 1, borderRadius: 3, yAxisID: 'y', order: 3 },
                    { type: 'bar',  label: 'Ingresos',     data: serie.map(r => r.ingresos), backgroundColor: 'rgba(236,72,153,.55)', borderColor: '#ec4899', borderWidth: 1, borderRadius: 3, yAxisID: 'y', order: 2 },
                    { type: 'line', label: 'Atracción %',  data: serie.map(r => r.atraccion  * 100), borderColor: '#f59e0b', backgroundColor: '#f59e0b', borderWidth: 2, tension: .3, pointRadius: 3, yAxisID: 'y2', order: 1 },
                    { type: 'line', label: 'Conversión %', data: serie.map(r => r.conversion * 100), borderColor: '#16a34a', backgroundColor: '#16a34a', borderWidth: 2, tension: .3, pointRadius: 3, yAxisID: 'y2', order: 0 },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { color: '#374151', font: { size: 11 }, boxWidth: 12 } },
                    datalabels: { display: false },
                    tooltip: {
                        backgroundColor: '#1a2340', titleColor: '#9ba8c8', bodyColor: '#fff', padding: 10,
                        callbacks: {
                            label: ctx => ctx.dataset.yAxisID === 'y2'
                                ? `${ctx.dataset.label}: ${ctx.parsed.y.toFixed(1)} %`
                                : `${ctx.dataset.label}: ${fmt.num(ctx.parsed.y)}`,
                        },
                    },
                },
                scales: {
                    x : { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 10 } } },
                    y : { position: 'left',  grid: { color: 'rgba(0,0,0,.06)' }, ticks: { color: '#6b7280', font: { size: 10 }, callback: v => fmt.num(v) } },
                    y2: { position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#6b7280', font: { size: 10 }, callback: v => v + ' %' }, min: 0 },
                },
            },
        });
    }

    /* ── Matriz de cuadrantes (scatter) ─────────── */

    /* Cuadrante 1 (mejor) a 4 (peor), acordado con negocio:
       1 = atracción alta + conversión alta
       2 = atracción alta + conversión baja (problema de salón — peor: se desperdicia el tráfico logrado)
       3 = atracción baja + conversión alta (problema de vidriera — más fácil de resolver)
       4 = atracción baja + conversión baja */
    const QUADRANT_COLOR = { 1: '#22c55e', 2: '#f97316', 3: '#eab308', 4: '#dc2626' };
    const QUADRANT_LABEL = {
        1: 'Cuadrante 1 — Atracción alta + Conversión alta',
        2: 'Cuadrante 2 — Atracción alta + Conversión baja (salón)',
        3: 'Cuadrante 3 — Atracción baja + Conversión alta (vidriera)',
        4: 'Cuadrante 4 — Atracción baja + Conversión baja',
    };
    function quadrantOf(atraccion, conversion, xAvg, yAvg) {
        const altaAtr  = atraccion  >= xAvg;
        const altaConv = conversion >= yAvg;
        if (altaAtr  &&  altaConv) return 1;
        if (altaAtr  && !altaConv) return 2;
        if (!altaAtr &&  altaConv) return 3;
        return 4;
    }

    const quadrantBgPlugin = {
        id: 'circQuadrantBg',
        beforeDatasetsDraw(chart, args, opts) {
            const { ctx, chartArea, scales } = chart;
            if (!chartArea || !opts) return;
            const xPix = scales.x.getPixelForValue(opts.xAvg);
            const yPix = scales.y.getPixelForValue(opts.yAvg);
            ctx.save();
            ctx.fillStyle = 'rgba(234,179,8,.10)';   // atracción baja / conversión alta → vidriera
            ctx.fillRect(chartArea.left, chartArea.top, xPix - chartArea.left, yPix - chartArea.top);
            ctx.fillStyle = 'rgba(34,197,94,.10)';    // ambas altas
            ctx.fillRect(xPix, chartArea.top, chartArea.right - xPix, yPix - chartArea.top);
            ctx.fillStyle = 'rgba(220,38,38,.10)';    // ambas bajas
            ctx.fillRect(chartArea.left, yPix, xPix - chartArea.left, chartArea.bottom - yPix);
            ctx.fillStyle = 'rgba(249,115,22,.10)';   // atracción alta / conversión baja → salón
            ctx.fillRect(xPix, yPix, chartArea.right - xPix, chartArea.bottom - yPix);
            ctx.restore();
        },
    };

    function renderQuadrant() {
        const canvas = document.getElementById('chart-circulacion-quadrant');
        if (!canvas || !_lastSucursales) return;
        if (_chartQuadrant) { _chartQuadrant.destroy(); _chartQuadrant = null; }

        let rows = _lastSucursales.filter(r => r.tiene_sensor && r.atraccion !== null && r.conversion !== null);
        if (typeof Dashboard !== 'undefined' && Dashboard.isSoloActivas?.()) {
            const ids = Dashboard.getSucursalesActivasIds?.();
            if (ids?.size) rows = rows.filter(r => ids.has(+r.nro_sucurs));
        }
        if (!rows.length) return;

        const xAvg = _lastResumen?.actual?.atraccion  ?? 0;
        const yAvg = _lastResumen?.actual?.conversion ?? 0;
        const getSucNombre = n => (typeof Dashboard !== 'undefined' ? Dashboard.getSucNombre(n) : null);

        // Eficiencia real por sucursal: de cada 100 personas que pasan, cuántas terminan
        // comprando (atracción × conversión = tickets / merodeo). Se usa solo como desempate
        // DENTRO de cada cuadrante — el cuadrante siempre manda en el tamaño del punto.
        const quadrants  = rows.map(r => quadrantOf(r.atraccion, r.conversion, xAvg, yAvg));
        const eficiencias = rows.map(r => r.atraccion * r.conversion);
        const minEff = Math.min(...eficiencias), maxEff = Math.max(...eficiencias);
        // Tope < 1 para que el mejor caso de un cuadrante nunca alcance al peor caso del siguiente.
        const effNorm = eficiencias.map(e => (maxEff > minEff ? (e - minEff) / (maxEff - minEff) : 0.5) * 0.999);

        const points = rows.map((r, i) => ({
            x: r.atraccion * 100,
            y: r.conversion * 100,
            nombre: getSucNombre(r.nro_sucurs) || r.desc_sucursal || ('Suc. ' + r.nro_sucurs),
            quadrant: quadrants[i],
            effNorm: effNorm[i],
        }));

        // Tamaño = cuadrante (manda) + eficiencia real como desempate dentro del cuadrante.
        // quadrant 1 (verde) → tier 3 · quadrant 2 (naranja) → tier 2
        // quadrant 3 (amarillo) → tier 1 · quadrant 4 (rojo) → tier 0
        const pointRadius = points.map(p => {
            const tier = 4 - p.quadrant;
            const compositeScore = tier + p.effNorm; // rango [0, 4)
            return 5 + (compositeScore / 4) * 11;     // rango [5, 16)
        });
        const pointHoverRadius = pointRadius.map(r => r + 3);
        const pointColor       = points.map(p => QUADRANT_COLOR[p.quadrant]);

        _chartQuadrant = new Chart(canvas, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Sucursales',
                    data: points,
                    backgroundColor: pointColor,
                    borderColor: '#ffffff',
                    borderWidth: 1.5,
                    pointRadius,
                    pointHoverRadius,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    datalabels: { display: false },
                    circQuadrantBg: { xAvg: xAvg * 100, yAvg: yAvg * 100 },
                    tooltip: {
                        backgroundColor: '#1a2340', titleColor: '#9ba8c8', bodyColor: '#fff', padding: 10,
                        callbacks: {
                            title: items => points[items[0].dataIndex]?.nombre ?? '',
                            label: ctx => {
                                const pt = points[ctx.dataIndex];
                                return [
                                    `Atracción: ${ctx.parsed.x.toFixed(1)} %`,
                                    `Conversión: ${ctx.parsed.y.toFixed(1)} %`,
                                    QUADRANT_LABEL[pt.quadrant],
                                    `Eficiencia dentro del cuadrante: ${(pt.effNorm / 0.999 * 100).toFixed(0)} % (a mayor eficiencia, más grande dentro de su cuadrante)`,
                                ];
                            },
                        },
                    },
                },
                scales: {
                    x: { title: { display: true, text: 'Tasa de atracción (%)', color: '#6b7280', font: { size: 11 } }, grid: { color: 'rgba(0,0,0,.06)' }, ticks: { color: '#6b7280', font: { size: 10 } } },
                    y: { title: { display: true, text: 'Tasa de conversión (%)', color: '#6b7280', font: { size: 11 } }, grid: { color: 'rgba(0,0,0,.06)' }, ticks: { color: '#6b7280', font: { size: 10 } } },
                },
            },
            plugins: [quadrantBgPlugin],
        });
    }

    /* ── Init listeners (una sola vez) ──────────── */
    let _listenersAttached = false;
    function attachListeners() {
        if (_listenersAttached) return;
        _listenersAttached = true;

        document.querySelectorAll('#table-circulacion-sucursales thead th[data-col]').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.col;
                if (_sortCol === col) {
                    _sortDir = _sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    _sortCol = col;
                    _sortDir = 'desc';
                }
                renderTabla();
            });
        });

        const selMeses = document.getElementById('sel-circulacion-meses');
        if (selMeses) {
            selMeses.addEventListener('change', () => {
                _evolucionMeses = parseInt(selMeses.value, 10) || 13;
                cargarEvolucion();
            });
        }

        if (typeof ExcelExporter !== 'undefined') {
            const header = document.querySelector('#circulacion-tabla-header');
            const rightDiv = header?.querySelector('[style*="margin-left"]');
            ExcelExporter.addExportButton(rightDiv ?? header, exportarSucursales);
        }
    }

    /* ── Carga ───────────────────────────────── */
    async function loadAll() {
        const kpiRow = document.getElementById('circ-kpi-row');
        if (kpiRow) kpiRow.classList.add('is-loading');
        Spinner.show('Cargando circulación...');

        try {
            const qsResumen = Dashboard.buildQS();
            const qsSucursales = Dashboard.buildQS({ action: 'sucursales' });

            const [resResumen, resSucursales] = await Promise.all([
                fetch(`/bi/global/api/circulacion.php?${qsResumen}`),
                fetch(`/bi/global/api/circulacion.php?${qsSucursales}`),
            ]);
            const dataResumen    = await resResumen.json();
            const dataSucursales = await resSucursales.json();
            if (!dataResumen.ok) throw new Error(dataResumen.error || 'Error en resumen de circulación');
            if (!dataSucursales.ok) throw new Error(dataSucursales.error || 'Error en sucursales de circulación');

            _lastResumen    = dataResumen;
            _lastSucursales = dataSucursales.sucursales || [];

            renderKpis(_lastResumen);
            renderTabla();
            renderQuadrant();
            attachListeners();

            cargarEvolucion();
        } catch (e) {
            console.error('[Circulacion]', e);
            const tbody = document.getElementById('tbody-circulacion-sucursales');
            if (tbody) tbody.innerHTML = `<tr><td colspan="9" style="padding:16px;color:var(--neg)"><i class="bi bi-exclamation-triangle"></i> ${e.message}</td></tr>`;
        } finally {
            if (kpiRow) kpiRow.classList.remove('is-loading');
            Spinner.hide();
        }
    }

    return { loadAll };
})();
