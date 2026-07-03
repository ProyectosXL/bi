/**
 * /bi/global/js/dashboard.js
 * Módulo de KPIs para el dashboard global (GERENCIA/SUPERVISIÓN).
 * Expone: Dashboard.loadAll(), Dashboard.loadFilters(), Dashboard.getParams()
 */

/* ── Spinner bloqueante (global — usado por dashboard.js y módulos) ── */
const Spinner = (() => {
    let overlay = null;

    function show(msg = 'Cargando datos...') {
        if (overlay) return;
        overlay = document.createElement('div');
        overlay.id = 'bi-spinner-overlay';
        overlay.style.cssText = [
            'position:fixed',
            'inset:0',
            'z-index:99999',
            'background:rgba(26,35,64,0.6)',
            'backdrop-filter:blur(2px)',
            'display:flex',
            'flex-direction:column',
            'align-items:center',
            'justify-content:center',
            'gap:16px',
            'pointer-events:all'
        ].join(';');

        if (!document.getElementById('bi-spin-style')) {
            const style = document.createElement('style');
            style.id = 'bi-spin-style';
            style.textContent = '@keyframes bi-spin{to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }

        overlay.innerHTML = `
            <div style="
                width:52px;height:52px;
                border:4px solid rgba(255,255,255,0.2);
                border-top-color:#00a878;
                border-radius:50%;
                animation:bi-spin 0.75s linear infinite;
            "></div>
            <div style="
                color:rgba(255,255,255,0.92);
                font-family:'Barlow Condensed',sans-serif;
                font-size:1.1rem;
                font-weight:600;
                letter-spacing:0.5px;
            ">${msg}</div>
        `;
        document.body.appendChild(overlay);
    }

    function hide() {
        if (!overlay) return;
        overlay.style.opacity = '0';
        overlay.style.transition = 'opacity 0.2s ease';
        setTimeout(() => { overlay?.remove(); overlay = null; }, 200);
    }

    return { show, hide };
})();

const Dashboard = (() => {

    /* ── Referencias al DOM ──────────────────── */
    const $ = id => document.getElementById(id);

    /* ── Estado moneda ───────────────────────── */
    let _cotizaciones = {};   // { "2024-01": 834.15, ... } — TCC último día de cada mes
    let _tccActual    = 1;    // última TCC disponible, para display en el label
    let _moneda       = 'ARS';
    let _lastPeriodo  = null; // d.periodo del último loadAll (desde_act, hasta_act, etc.)

    /**
     * Retorna la TCC para un mes dado (formato 'YYYY-MM').
     * Fallback: si no hay dato para ese mes, usa _tccActual.
     */
    function getTCCParaMes(mesKey) {
        return _cotizaciones[mesKey] ?? _tccActual;
    }

    /**
     * Convierte un valor monetario usando la TCC del mes al que pertenece la fecha.
     * @param {number} valor
     * @param {string} fecha  'YYYY-MM-DD' o 'YYYY-MM'
     */
    function convertirConFecha(valor, fecha) {
        if (_moneda === 'ARS') return valor ?? 0;
        const mesKey = (fecha ?? '').substring(0, 7);
        return (valor ?? 0) / getTCCParaMes(mesKey);
    }

    /**
     * Conversión sin fecha explícita: usa el mes de inicio del período actual.
     * Para KPIs escalares (no serie temporal).
     */
    function convertir(val) {
        if (_moneda === 'ARS') return val ?? 0;
        const mesKey = (_lastPeriodo?.desde_act ?? '').substring(0, 7);
        return (val ?? 0) / getTCCParaMes(mesKey);
    }

    function moneyPrefix()  { return _moneda === 'USD' ? 'U$S\u00A0' : '$\u00A0'; }

    /* ── Formato (usa BIUtils si está disponible, o local) ── */
    const fmt = (() => {
        const f = n => n === null || n === undefined ? '—' : n;
        return {
            money  : (n, d=0) => f(n) === '—' ? '—' : moneyPrefix() + convertir(n).toLocaleString('es-AR', {minimumFractionDigits:d,maximumFractionDigits:d}),
            moneyK : n => {
                if (n === null || n === undefined) return '—';
                const v = convertir(n);
                const pfx = moneyPrefix();
                if (Math.abs(v) >= 1_000_000) return pfx + (v/1_000_000).toLocaleString('es-AR',{minimumFractionDigits:1,maximumFractionDigits:1})+'M';
                if (Math.abs(v) >= 1_000)     return pfx + (v/1_000).toLocaleString('es-AR',{minimumFractionDigits:0,maximumFractionDigits:0})+'K';
                return pfx + v.toLocaleString('es-AR',{minimumFractionDigits:0,maximumFractionDigits:0});
            },
            pct    : (n, d=1) => n===null||n===undefined ? '—' : (n*100).toLocaleString('es-AR',{minimumFractionDigits:d,maximumFractionDigits:d})+'\u00A0%',
            varPct : (n, d=1) => { if(n===null||n===undefined) return '—'; const s=n>=0?'+':''; return s+(n*100).toLocaleString('es-AR',{minimumFractionDigits:d,maximumFractionDigits:d})+'\u00A0%'; },
            num    : (n, d=0) => n===null||n===undefined ? '—' : Number(n).toLocaleString('es-AR',{minimumFractionDigits:d,maximumFractionDigits:d}),
        };
    })();

    /* ── Estado "solo activas" ───────────────── */
    let _sucursalesActivasIds = new Set();

    function isSoloActivas() {
        return !!($('chk-solo-activas')?.checked);
    }

    function getSucursalesActivasIds() {
        return _sucursalesActivasIds;
    }

    /* ── Leer parámetros del DOM ─────────────── */
    function getParams(extra = {}) {
        const cfg = window.BI_CONFIG ?? { isGrupo: false, esGrupo: false, sucursalesGrupo: [] };

        const origenActive = document.querySelector('.origen-btn.active');
        const p = {
            origen      : cfg.isGrupo ? 'franquicias' : (origenActive?.dataset.origen ?? 'argentina'),
            periodo     : ($('sel-periodo')?.value     ?? 'mes_actual'),
            vendedor    : ($('sel-vendedor')?.value    ?? '%'),
            rubro       : ($('sel-rubro')?.value       ?? '%'),
            sucursal    : ($('sel-sucursal')?.value    ?? ''),
            grupo       : cfg.isGrupo ? '' : ($('sel-grupo')?.value       ?? ''),
            tipo_tienda : cfg.isGrupo ? '' : ($('sel-tipo-tienda')?.value ?? ''),
            canal       : cfg.isGrupo ? '' : ($('sel-canal')?.value       ?? ''),
            tipo_local  : ($('sel-tipo-local')?.value  ?? ''),
            zona        : ($('sel-zona')?.value        ?? ''),
            grupo_empresario : ($('sel-grupo-empresario')?.value ?? ''),
            solo_activas: (!cfg.isGrupo && isSoloActivas()) ? '1' : '0',
            ...extra
        };
        if (p.periodo === 'custom') {
            p.desde     = $('input-desde')?.value     ?? '';
            p.hasta     = $('input-hasta')?.value     ?? '';
            p.comp_mode = document.querySelector('input[name="comp-mode"]:checked')?.value ?? 'year_ago';
            if (p.comp_mode === 'custom') {
                p.desde_comp = $('input-comp-desde')?.value ?? '';
                p.hasta_comp = $('input-comp-hasta')?.value ?? '';
            }
        }
        // Limpiar vacíos para no enviar param vacío
        ['sucursal','grupo','tipo_tienda','canal','tipo_local','zona','grupo_empresario'].forEach(k => { if (!p[k]) delete p[k]; });
        return p;
    }

    function buildQS(extra = {}) {
        return new URLSearchParams(getParams(extra)).toString();
    }

    async function apiFetch(endpoint, extra = {}) {
        const res = await fetch(`/bi/global/api/${endpoint}?${buildQS(extra)}`);
        if (!res.ok) {
            const body = await res.text().catch(() => '');
            console.error(`[apiFetch] ${endpoint} → HTTP ${res.status}`, body);
            throw new Error(`Error ${res.status} en ${endpoint}`);
        }
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || `Error en ${endpoint}`);
        return data;
    }

    /* ── SparkCharts ─────────────────────────── */
    const charts = {};

    const _DIAS     = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    const _DIAS_ABR = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

    function sparkLine(canvasId, values, color = '#00a878', prevValues = null, dates = null) {
        const canvas = $(canvasId);
        if (!canvas) return;
        if (charts[canvasId]) { charts[canvasId].destroy(); delete charts[canvasId]; }

        // Botón expandir (igual que en sucursales)
        const wrap = canvas.parentElement;
        if (wrap && !wrap.querySelector('.spark-expand-btn')) {
            wrap.style.position = 'relative';
            const btn = document.createElement('button');
            btn.className = 'spark-expand-btn';
            btn.dataset.spark = canvasId;
            btn.title = 'Ver gráfico ampliado';
            btn.innerHTML = '<i class="bi bi-arrows-angle-expand"></i>';
            wrap.appendChild(btn);
        }

        // Dot markers: small for intermediate points, larger for last point
        const n = values.length;
        const pointRadii        = values.map((_, i) => i === n - 1 ? 5   : 2.5);
        const pointBgColors     = values.map((_, i) => i === n - 1 ? color : '#ffffff');
        const pointBorderColors = values.map((_, i) => i === n - 1 ? '#ffffff' : color + 'aa');
        const pointHoverRadii   = values.map((_, i) => i === n - 1 ? 7   : 5);

        // Area gradient (vertical): rich at top, transparent at bottom
        const makeAreaGrad = (c, chartArea) => {
            if (!chartArea) return color + '20';
            const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
            g.addColorStop(0,   color + '40');
            g.addColorStop(0.6, color + '18');
            g.addColorStop(1,   color + '00');
            return g;
        };

        // Line gradient (horizontal): fades in left-to-right for tonal variation
        const makeLineGrad = (c, chartArea) => {
            if (!chartArea) return color;
            const g = c.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
            g.addColorStop(0,   color + '66');
            g.addColorStop(0.5, color + 'bb');
            g.addColorStop(1,   color);
            return g;
        };

        const datasets = [{
            data                     : values,
            borderColor              : ctxObj => makeLineGrad(ctxObj.chart.ctx, ctxObj.chart.chartArea),
            borderWidth              : 2,
            tension                  : 0.4,
            fill                     : true,
            backgroundColor          : ctxObj => makeAreaGrad(ctxObj.chart.ctx, ctxObj.chart.chartArea),
            pointRadius              : pointRadii,
            pointBackgroundColor     : pointBgColors,
            pointBorderColor         : pointBorderColors,
            pointBorderWidth         : 1.5,
            pointHoverRadius         : pointHoverRadii,
            pointHoverBackgroundColor: color,
            pointHoverBorderColor    : '#ffffff',
            pointHoverBorderWidth    : 2,
        }];
        if (prevValues?.length) {
            datasets.push({
                data: prevValues,
                borderColor: 'rgba(150,160,180,.35)',
                borderWidth: 1,
                borderDash : [3,3],
                pointRadius: 0,
                tension    : 0.3,
                fill       : false,
            });
        }

        // Formatear etiquetas de fechas si están disponibles
        const labels = dates
            ? dates.map(d => d ? new Date(d + 'T00:00:00').toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' }) : '')
            : values.map((_, i) => i);

        charts[canvasId] = new Chart(canvas, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: false,
                animation : { duration: 300 },
                plugins   : {
                    legend    : { display: false },
                    datalabels: { display: false },
                    tooltip   : dates ? {
                        enabled        : true,
                        backgroundColor: '#1a2340',
                        titleColor     : 'rgba(255,255,255,.65)',
                        bodyColor      : '#ffffff',
                        padding        : 8,
                        cornerRadius   : 6,
                        displayColors  : false,
                        callbacks: {
                            title: items => {
                                const ds = dates[items[0].dataIndex];
                                if (!ds) return '';
                                const d = new Date(ds + 'T00:00:00');
                                const dia = _DIAS[d.getDay()];
                                const fecha = d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' });
                                return `${dia} ${fecha}`;
                            },
                            label: ctx => {
                                // Usar el formatFn del SparkModal si está registrado (late binding)
                                const reg = typeof SparkModal !== 'undefined' ? SparkModal._registry?.[canvasId] : null;
                                const v   = ctx.parsed.y;
                                return reg ? reg.formatFn(v) : String(v);
                            },
                        }
                    } : { enabled: false },
                },
                scales  : { x: { display: false }, y: { display: false } },
                elements: { line: { capBezierPoints: false } },
            }
        });
    }

    /* ── Sparkline bi-color cumplimiento objetivo ─────────── */
    function drawSparklineObj(canvasId, values, dates) {
        const canvas = $(canvasId);
        if (!canvas || !values?.length) return;

        const C_POS = '#16a34a';
        const C_NEG = '#dc2626';
        SparkModal.register(canvasId, values, dates, C_POS, fmt.varPct, 'Cumplimiento Objetivo');

        const ctx = canvas.getContext('2d');
        const W = canvas.width, H = canvas.height;
        ctx.clearRect(0, 0, W, H);

        const min   = Math.min(...values, 0);
        const max   = Math.max(...values, 0);
        const range = max - min || 1;
        const toY   = v => H - ((v - min) / range) * (H - 4) - 2;
        const toX   = i => values.length > 1 ? (i / (values.length - 1)) * W : W / 2;
        const pts   = values.map((v, i) => ({ x: toX(i), y: toY(v), value: v, date: dates?.[i] || '' }));
        const zeroY = toY(0);

        ctx.save();
        ctx.beginPath(); ctx.rect(0, 0, W, zeroY); ctx.clip();
        const gPos = ctx.createLinearGradient(0, 0, 0, zeroY);
        gPos.addColorStop(0, C_POS + '55'); gPos.addColorStop(1, C_POS + '00');
        ctx.beginPath();
        ctx.moveTo(pts[0].x, zeroY);
        pts.forEach(p => ctx.lineTo(p.x, p.y));
        ctx.lineTo(pts[pts.length - 1].x, zeroY);
        ctx.closePath();
        ctx.fillStyle = gPos; ctx.fill();
        ctx.restore();

        ctx.save();
        ctx.beginPath(); ctx.rect(0, zeroY, W, H - zeroY); ctx.clip();
        const gNeg = ctx.createLinearGradient(0, zeroY, 0, H);
        gNeg.addColorStop(0, C_NEG + '00'); gNeg.addColorStop(1, C_NEG + '55');
        ctx.beginPath();
        ctx.moveTo(pts[0].x, zeroY);
        pts.forEach(p => ctx.lineTo(p.x, p.y));
        ctx.lineTo(pts[pts.length - 1].x, zeroY);
        ctx.closePath();
        ctx.fillStyle = gNeg; ctx.fill();
        ctx.restore();

        ctx.save();
        ctx.beginPath(); ctx.moveTo(0, zeroY); ctx.lineTo(W, zeroY);
        ctx.strokeStyle = 'rgba(100,120,160,0.45)'; ctx.lineWidth = 1;
        ctx.setLineDash([3, 3]); ctx.stroke(); ctx.setLineDash([]);
        ctx.restore();

        ctx.lineWidth = 2; ctx.lineJoin = 'round';
        for (let i = 0; i < pts.length - 1; i++) {
            const p1 = pts[i], p2 = pts[i + 1];
            const v1 = values[i], v2 = values[i + 1];
            if ((v1 >= 0) === (v2 >= 0)) {
                ctx.beginPath(); ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y);
                ctx.strokeStyle = v1 >= 0 ? C_POS : C_NEG; ctx.stroke();
            } else {
                const t = Math.abs(v1) / (Math.abs(v1) + Math.abs(v2));
                const xMid = p1.x + t * (p2.x - p1.x);
                ctx.beginPath(); ctx.moveTo(p1.x, p1.y); ctx.lineTo(xMid, zeroY);
                ctx.strokeStyle = v1 >= 0 ? C_POS : C_NEG; ctx.stroke();
                ctx.beginPath(); ctx.moveTo(xMid, zeroY); ctx.lineTo(p2.x, p2.y);
                ctx.strokeStyle = v2 >= 0 ? C_POS : C_NEG; ctx.stroke();
            }
        }
        const last = pts[pts.length - 1];
        ctx.beginPath(); ctx.arc(last.x, last.y, 3, 0, Math.PI * 2);
        ctx.fillStyle = values[values.length - 1] >= 0 ? C_POS : C_NEG;
        ctx.fill(); ctx.strokeStyle = '#fff'; ctx.lineWidth = 1; ctx.stroke();
    }

    /* ── DonutManager: donuts con drilldown ─── */
    const DonutManager = (() => {
        const _charts  = {};
        const _cache   = {};   // wrapperId → { originalRows, dataKey }
        const _state   = {};   // wrapperId → { level: 1|2 }
        const COLORS   = ['#2563eb','#16a34a','#dc2626','#f59e0b','#7c3aed',
                          '#0891b2','#ea580c','#84cc16','#db2777','#64748b',
                          '#059669','#b91c1c','#d97706','#4f46e5','#0284c7'];

        function _renderChart(wrapperId, rows, labelKey, dataKey, isDrilldown) {
            const wrap = $(wrapperId);
            if (!wrap) return;

            // Destruir chart previo
            if (_charts[wrapperId]) { _charts[wrapperId].destroy(); delete _charts[wrapperId]; }

            // Canvas
            let cw = wrap.querySelector('.donut-chart-wrapper');
            if (!cw) { cw = document.createElement('div'); cw.className = 'donut-chart-wrapper'; wrap.appendChild(cw); }
            cw.innerHTML = '';
            const canvas = document.createElement('canvas');
            cw.appendChild(canvas);

            // Ordenar por dataKey descendente
            const sortedRows = [...rows].sort((a, b) => (b[dataKey] ?? 0) - (a[dataKey] ?? 0));
            const labels = sortedRows.map(r => r[labelKey]);
            const data   = sortedRows.map(r => r[dataKey] ?? 0);
            const total  = data.reduce((s, v) => s + v, 0) || 1;
            const isFact = dataKey === 'facturacion';

            _charts[wrapperId] = new Chart(canvas, {
                type: 'doughnut',
                data: { labels, datasets: [{ data, backgroundColor: COLORS, borderWidth: 2, borderColor: '#fff' }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '55%',
                    animation: { duration: 350 },
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            color: '#fff',
                            anchor: 'center',
                            align: 'center',
                            font: { weight: 'bold', size: 10 },
                            formatter: (v) => {
                                const p = v / total * 100;
                                return p >= 5 ? p.toFixed(1) + '%' : '';
                            }
                        },
                        tooltip: {
                            backgroundColor: '#ffffff',
                            titleColor: '#1a2340',
                            bodyColor: '#1a2340',
                            borderColor: '#e2e6f0',
                            borderWidth: 1,
                            padding: 10,
                            callbacks: {
                                title: items => items[0].label,
                                label: ctx => {
                                    const v = ctx.parsed;
                                    const p = (v / total * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                                    return isFact
                                        ? ` $${v.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 })} (${p}%)`
                                        : ` ${v.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 })} (${p}%)`;
                                }
                            }
                        }
                    },
                    onClick: isDrilldown ? undefined : (evt, elems) => {
                        if (!elems.length || _state[wrapperId]?.level !== 1) return;
                        const rubro = labels[elems[0].index];
                        _drilldown(wrapperId, dataKey, rubro);
                    },
                },
            });

            // Leyenda
            let leg = wrap.querySelector('.donut-legend');
            if (!leg) { leg = document.createElement('div'); leg.className = 'donut-legend'; wrap.appendChild(leg); }
            leg.innerHTML = sortedRows.map((r, i) => {
                const v   = r[dataKey] ?? 0;
                const p   = (v / total * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                const fv  = isFact
                    ? '$' + v.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
                    : v.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                return `<div class="donut-legend-item">
                    <span class="donut-legend-color" style="background:${COLORS[i % COLORS.length]}"></span>
                    <span class="donut-legend-label">${r[labelKey]}</span>
                    <span class="donut-legend-value">${fv} (${p}%)</span>
                </div>`;
            }).join('');
        }

        async function _drilldown(wrapperId, dataKey, rubroName) {
            _state[wrapperId] = { level: 2 };
            const wrap = $(wrapperId);
            if (!wrap) return;

            // Mostrar botón volver
            const headerEl = wrap.querySelector('.donut-header-row');
            if (headerEl) headerEl.style.display = 'block';

            // Caché
            const ck = `${wrapperId}_${rubroName}`;
            if (_cache[ck]) {
                _renderChart(wrapperId, _cache[ck], 'CATEGORIA', dataKey, true);
                return;
            }

            const cw = wrap.querySelector('.donut-chart-wrapper');
            if (cw) cw.innerHTML = '<div class="analisis-loading" style="padding:20px"><i class="bi bi-arrow-repeat"></i> Cargando</div>';

            try {
                const qs  = Dashboard.buildQS({ action: 'ranking_categorias', rubro_filter: rubroName });
                const res = await fetch(`/bi/global/api/analisis.php?${qs}`);
                const d   = await res.json();
                const rows = d.categorias ?? [];
                _cache[ck] = rows;
                _renderChart(wrapperId, rows, 'CATEGORIA', dataKey, true);
            } catch(e) {
                create(wrapperId, _cache[wrapperId]?.dataKey ?? dataKey, _cache[wrapperId]?.originalRows ?? []);
            }
        }

        function create(wrapperId, dataKey, rows, title) {
            const wrap = $(wrapperId);
            if (!wrap) return;
            if (!rows.length) {
                wrap.innerHTML = '<div style="color:var(--text-3);font-size:.82rem;padding:20px">Sin datos</div>';
                return;
            }

            _state[wrapperId] = { level: 1 };
            _cache[wrapperId] = { originalRows: rows, dataKey, originalTitle: title ?? '' };
            wrap.innerHTML = '';

            // Breadcrumb: ocupa el ancho completo fuera del flex (absolute-like via wrapper)
            const headerEl = document.createElement('div');
            headerEl.className = 'donut-header-row';
            headerEl.style.cssText = 'position:absolute;top:6px;left:10px;z-index:5;display:none';

            const bc = document.createElement('button');
            bc.className = 'btn-volver';
            bc.textContent = '← Volver';
            bc.addEventListener('click', () => {
                _state[wrapperId] = { level: 1 };
                headerEl.style.display = 'none';
                _renderChart(wrapperId, rows, 'RUBRO', dataKey, false);
            });
            headerEl.appendChild(bc);
            wrap.style.position = 'relative';
            wrap.appendChild(headerEl);

            _renderChart(wrapperId, rows, 'RUBRO', dataKey, false);
        }

        function reset() {
            Object.keys(_charts).forEach(id => { if (_charts[id]) { _charts[id].destroy(); delete _charts[id]; } });
            Object.keys(_cache).forEach(k => delete _cache[k]);
            Object.keys(_state).forEach(k => delete _state[k]);
        }

        return { create, reset };
    })();

    /* ── SparkModal ──────────────────────────── */
    const SparkModal = (() => {
        const registry = {};
        let _modalChart = null;

        function register(canvasId, values, dates, color, formatFn, title) {
            registry[canvasId] = { values, dates, color, formatFn, title };
        }

        function setHoras(canvasId, horas) {
            if (registry[canvasId]) registry[canvasId].horas = horas;
        }

        function open(canvasId) {
            const entry = registry[canvasId];
            if (!entry) return;
            const { values, dates, color, formatFn, title } = entry;

            const max = Math.max(...values), min = Math.min(...values);
            const avg = values.reduce((a, b) => a + b, 0) / values.length;
            const last = values[values.length - 1];
            const trend = avg !== 0 ? (last - avg) / Math.abs(avg) : 0;
            const maxIdx = values.indexOf(max);
            const minIdx = values.indexOf(min);
            const fmtDate = ds => ds ? new Date(ds + 'T00:00:00').toLocaleDateString('es-AR', { weekday: 'short', day: '2-digit', month: '2-digit' }) : '';

            const trendSign = trend >= 0 ? '+' : '';
            const trendCls  = trend >= 0 ? 'pos' : 'neg';
            const trendTitle = `Último: ${formatFn(last)} | Promedio: ${formatFn(avg)} | Var: ${trendSign}${(trend*100).toFixed(1)}%`;

            const toggleHtml = entry.horas ? `
                <div class="conv-view-switch" id="conv-view-switch">
                    <button class="conv-view-btn active" data-view="dia">Por día</button>
                    <button class="conv-view-btn" data-view="hora">Por hora</button>
                </div>` : '';

            const root = $('spark-modal-root');
            root.innerHTML = `
                <div class="spark-modal-overlay" id="spark-overlay">
                    <div class="spark-modal">
                        <div class="spark-modal-header">
                            <span class="spark-modal-title">${title}</span>
                            ${toggleHtml}
                            <button class="spark-modal-close" id="spark-modal-close-btn"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="spark-modal-stats">
                            <div class="spark-modal-stat">
                                <span class="spark-modal-stat-label">Último</span>
                                <span class="spark-modal-stat-val">${formatFn(last)}</span>
                                <span class="stat-date">${fmtDate(dates[dates.length - 1])}</span>
                            </div>
                            <div class="spark-modal-stat">
                                <span class="spark-modal-stat-label">Promedio</span>
                                <span class="spark-modal-stat-val">${formatFn(avg)}</span>
                            </div>
                            <div class="spark-modal-stat">
                                <span class="spark-modal-stat-label">Máximo</span>
                                <span class="spark-modal-stat-val">${formatFn(max)}</span>
                                <span class="stat-date">${fmtDate(dates[maxIdx])}</span>
                            </div>
                            <div class="spark-modal-stat">
                                <span class="spark-modal-stat-label">Mínimo</span>
                                <span class="spark-modal-stat-val">${formatFn(min)}</span>
                                <span class="stat-date">${fmtDate(dates[minIdx])}</span>
                            </div>
                            <div class="spark-modal-stat">
                                <span class="spark-modal-stat-label">Tendencia</span>
                                <span class="trend-badge ${trendCls}" title="${trendTitle}">${trendSign}${(trend * 100).toFixed(1)} %</span>
                                <span class="stat-date" style="font-size:.68rem;color:var(--text-3)">vs promedio período</span>
                            </div>
                        </div>
                        <div class="spark-modal-chart-wrap">
                            <canvas id="spark-modal-canvas"></canvas>
                        </div>
                    </div>
                </div>`;

            $('spark-modal-close-btn').addEventListener('click', close);
            $('spark-overlay').addEventListener('click', e => { if (e.target.id === 'spark-overlay') close(); });

            if (_modalChart) { _modalChart.destroy(); _modalChart = null; }
            const modalLabels = dates.map(d => {
                if (!d) return '';
                const dt = new Date(d + 'T00:00:00');
                return _DIAS_ABR[dt.getDay()] + ' ' + String(dt.getDate()).padStart(2, '0');
            });

            // Dot markers
            const mn = values.length;
            const mPointRadii    = values.map((_, i) => i === mn - 1 ? 6   : 3);
            const mPointBgColors = values.map((_, i) => i === mn - 1 ? color : '#ffffff');
            const mPointBdColors = values.map((_, i) => i === mn - 1 ? '#ffffff' : color + 'aa');
            const mPointHover    = values.map((_, i) => i === mn - 1 ? 8   : 5);

            const makeModalAreaGrad = (c, chartArea) => {
                if (!chartArea) return color + '20';
                const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                g.addColorStop(0,   color + '50');
                g.addColorStop(0.6, color + '20');
                g.addColorStop(1,   color + '00');
                return g;
            };
            const makeModalLineGrad = (c, chartArea) => {
                if (!chartArea) return color;
                const g = c.createLinearGradient(chartArea.left, 0, chartArea.right, 0);
                g.addColorStop(0,   color + '66');
                g.addColorStop(0.5, color + 'bb');
                g.addColorStop(1,   color);
                return g;
            };

            _modalChart = new Chart($('spark-modal-canvas'), {
                type: 'line',
                data: {
                    labels  : modalLabels,
                    datasets: [{
                        data                     : values,
                        borderColor              : ctxObj => makeModalLineGrad(ctxObj.chart.ctx, ctxObj.chart.chartArea),
                        borderWidth              : 2,
                        tension                  : 0.4,
                        fill                     : true,
                        backgroundColor          : ctxObj => makeModalAreaGrad(ctxObj.chart.ctx, ctxObj.chart.chartArea),
                        pointRadius              : mPointRadii,
                        pointBackgroundColor     : mPointBgColors,
                        pointBorderColor         : mPointBdColors,
                        pointBorderWidth         : 1.5,
                        pointHoverRadius         : mPointHover,
                        pointHoverBackgroundColor: color,
                        pointHoverBorderColor    : '#ffffff',
                        pointHoverBorderWidth    : 2,
                    }]
                },
                options: {
                    responsive: true,
                    plugins   : {
                        legend    : { display: false },
                        datalabels: { display: false },
                        tooltip   : {
                            backgroundColor: '#1a2340',
                            titleColor     : '#9ba8c8',
                            bodyColor      : '#ffffff',
                            padding        : 8,
                            callbacks: {
                                title: items => {
                                    const d = new Date(dates[items[0].dataIndex] + 'T00:00:00');
                                    return d.toLocaleDateString('es-AR', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' });
                                },
                                label: ctx => ' ' + formatFn(ctx.parsed.y),
                            },
                        },
                    },
                    scales    : { x: { ticks: { maxRotation: 45, font: { size: 10 }, color: '#9ba8c8' } }, y: { ticks: { callback: v => formatFn(v), font: { size: 10 } } } },
                }
            });

            if (entry.horas) {
                document.getElementById('conv-view-switch')?.addEventListener('click', e => {
                    const btn = e.target.closest('.conv-view-btn');
                    if (!btn) return;
                    const view = btn.dataset.view;
                    document.querySelectorAll('.conv-view-btn').forEach(b => b.classList.toggle('active', b === btn));
                    if (view === 'hora') renderHoraView(entry);
                    else renderDiaView(entry, canvasId);
                });
            }
        }

        function renderDiaView(entry, canvasId) {
            if (_modalChart) { _modalChart.destroy(); _modalChart = null; }
            open(canvasId);
        }

        function renderHoraView(entry) {
            if (_modalChart) { _modalChart.destroy(); _modalChart = null; }
            const { horas, formatFn, color } = entry;
            const labels = horas.map(h => h.label);
            const values = horas.map(h => h.conversion);
            _modalChart = new Chart($('spark-modal-canvas'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{ data: values, backgroundColor: color + '77', borderColor: color, borderWidth: 1, borderRadius: 3 }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend    : { display: false },
                        datalabels: { display: false },
                        tooltip   : {
                            backgroundColor: '#1a2340',
                            titleColor     : '#9ba8c8',
                            bodyColor      : '#ffffff',
                            padding        : 8,
                            callbacks: {
                                title: items => horas[items[0].dataIndex].label,
                                label: ctx => ` ${formatFn(ctx.parsed.y)}  (${horas[ctx.dataIndex].tickets} tickets / ${horas[ctx.dataIndex].ingresos} ingresos)`,
                            },
                        },
                    },
                    scales: {
                        x: { ticks: { font: { size: 10 }, color: '#9ba8c8' } },
                        y: { ticks: { callback: v => formatFn(v), font: { size: 10 } } },
                    },
                }
            });
        }

        function close() {
            if (_modalChart) { _modalChart.destroy(); _modalChart = null; }
            const root = $('spark-modal-root');
            if (root) root.innerHTML = '';
        }

        return { register, open, setHoras, _registry: registry };
    })();

    /* ── Expandir sparklines ─────────────────── */
    document.addEventListener('click', e => {
        const btn = e.target.closest('.spark-expand-btn');
        if (!btn) return;
        const id = btn.dataset.spark;
        if (id) SparkModal.open(id);
    });

    /* ── Etiqueta período ────────────────────── */
    function updatePeriodLabel(per) {
        const fmtDate = s => {
            const [y, m, d] = s.split('-');
            return `${d}/${m}/${y}`;
        };
        const lab  = $('periodo-label');
        const prev = $('periodo-previo-label');
        if (lab)  lab.textContent  = `${fmtDate(per.desde_act)} — ${fmtDate(per.hasta_act)}`;
        if (prev) prev.textContent = `(vs ${fmtDate(per.desde_prev)} — ${fmtDate(per.hasta_prev)})`;
    }

    /* ── Exportar tabla de sucursales a Excel ─── */
    function exportarTablaSucursales() {
        if (!_tablaSucRows?.length || typeof ExcelExporter === 'undefined') return;
        let rows = _tablaSucRows;
        if (isSoloActivas() && _sucursalesActivasIds.size) {
            rows = rows.filter(r => _sucursalesActivasIds.has(+r.nro_sucurs));
        }
        const totFact = rows.reduce((s, r) => s + (r.facturacion      ?? 0), 0);
        const totPrev = rows.reduce((s, r) => s + (r.facturacion_prev ?? 0), 0);
        const totObjF = rows.reduce((s, r) => s + (r.objetivo_fecha   ?? 0), 0);
        const totObjT = rows.reduce((s, r) => s + (r.objetivo_total   ?? 0), 0);
        const totDesv = totObjF > 0 ? (totFact - totObjF) / totObjF : null;
        ExcelExporter.export({
            title     : 'Facturación vs Objetivos por Sucursal',
            headers   : ['Sucursal', 'Fact. Actual', 'Fact. Año Ant.', 'Var. Fact.', 'Objetivo Total', 'Objetivo Fecha', 'Desvío'],
            rows      : rows.map(r => [
                getSucNombre(r.nro_sucurs),
                r.facturacion      != null ? convertir(r.facturacion)      : null,
                r.facturacion_prev != null ? convertir(r.facturacion_prev) : null,
                r.var_facturacion  ?? null,
                r.objetivo_total   != null ? convertir(r.objetivo_total)   : null,
                r.objetivo_fecha   != null ? convertir(r.objetivo_fecha)   : null,
                r.desvio           ?? null,
            ]),
            totalsRow : ['TOTAL',
                convertir(totFact),
                totPrev ? convertir(totPrev) : null,
                null,
                totObjT ? convertir(totObjT) : null,
                totObjF ? convertir(totObjF) : null,
                totDesv ?? null],
            colFormats: ['text', 'money', 'money', 'pct', 'money', 'money', 'pct'],
            filename  : 'facturacion_vs_objetivos',
        });
    }

    /* ── Tabla Facturación vs Objetivos ──────── */
    let _tablaSucRows = null;
    let _tablaSucSort = { col: 'facturacion', asc: false };

    const TABLA_SUC_COLS = [
        { key: 'nombre',          label: 'Sucursal',        sortKey: 'nombre',           align: 'left'  },
        { key: 'facturacion',     label: 'Fact. Actual',    sortKey: 'facturacion',       align: 'right' },
        { key: 'facturacion_prev',label: 'Fact. Año Ant.',  sortKey: 'facturacion_prev',  align: 'right' },
        { key: 'var_facturacion', label: 'Var. Fact.',      sortKey: 'var_facturacion',   align: 'right' },
        { key: 'objetivo_total',  label: 'Objetivo Total',  sortKey: 'objetivo_total',    align: 'right' },
        { key: 'objetivo_fecha',  label: 'Objetivo a Fecha',sortKey: 'objetivo_fecha',    align: 'right' },
        { key: 'desvio',          label: 'Desvío',          sortKey: 'desvio',            align: 'right' },
    ];

    function renderTablaSucursales(rows) {
        if (rows) _tablaSucRows = rows;

        // Filtro "solo activas" (client-side)
        let allRows = _tablaSucRows;
        if (isSoloActivas() && _sucursalesActivasIds.size) {
            allRows = (allRows ?? []).filter(r => _sucursalesActivasIds.has(+r.nro_sucurs));
        }

        const table = document.getElementById('tabla-sucursales');
        const tbody = table?.querySelector('tbody');
        if (!tbody) return;

        // Botón de exportación (se agrega una sola vez)
        if (typeof ExcelExporter !== 'undefined') {
            const headerEl = table.closest('.table-card')?.querySelector('.table-card-header');
            ExcelExporter.addExportButton(headerEl, exportarTablaSucursales);
        }

        if (!allRows?.length) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--text-3)">Sin datos</td></tr>`;
            return;
        }

        const iconVar = (v) => {
            if (v === null || v === undefined) return '—';
            const cls  = v >= 0 ? 'pos' : 'neg';
            const icon = v >= 0 ? '▲' : '▼';
            return `<span class="${cls}">${icon}\u00A0${(Math.abs(v) * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%</span>`;
        };

        // Ordenar
        const { col, asc } = _tablaSucSort;
        const sorted = [...allRows].sort((a, b) => {
            const av = col === 'nombre' ? getSucNombre(a.nro_sucurs) : (a[col] ?? -Infinity);
            const bv = col === 'nombre' ? getSucNombre(b.nro_sucurs) : (b[col] ?? -Infinity);
            if (av < bv) return asc ? -1 : 1;
            if (av > bv) return asc ? 1 : -1;
            return 0;
        });

        const dataRows = sorted.map(r => `<tr>
            <td>${getSucNombre(r.nro_sucurs)}</td>
            <td style="text-align:right">${fmt.money(r.facturacion)}</td>
            <td style="text-align:right">${r.facturacion_prev ? fmt.money(r.facturacion_prev) : '—'}</td>
            <td style="text-align:right">${iconVar(r.var_facturacion)}</td>
            <td style="text-align:right">${r.objetivo_total ? fmt.money(r.objetivo_total) : '—'}</td>
            <td style="text-align:right">${r.objetivo_fecha ? fmt.money(r.objetivo_fecha) : '—'}</td>
            <td style="text-align:right">${iconVar(r.desvio)}</td>
        </tr>`).join('');

        // Fila de totales
        const totFact   = allRows.reduce((s, r) => s + (r.facturacion      ?? 0), 0);
        const totPrev   = allRows.reduce((s, r) => s + (r.facturacion_prev ?? 0), 0);
        const totObjF   = allRows.reduce((s, r) => s + (r.objetivo_fecha   ?? 0), 0);
        const totObjT   = allRows.reduce((s, r) => s + (r.objetivo_total   ?? 0), 0);
        const totDesv   = totObjF > 0 ? (totFact - totObjF) / totObjF : null;
        const totalsRow = `<tr style="font-weight:700;border-top:2px solid var(--border);background:var(--surface-1)">
            <td>TOTAL</td>
            <td style="text-align:right">${fmt.money(totFact)}</td>
            <td style="text-align:right">${totPrev ? fmt.money(totPrev) : '—'}</td>
            <td style="text-align:right">—</td>
            <td style="text-align:right">${totObjT ? fmt.money(totObjT) : '—'}</td>
            <td style="text-align:right">${totObjF ? fmt.money(totObjF) : '—'}</td>
            <td style="text-align:right">${iconVar(totDesv)}</td>
        </tr>`;

        tbody.innerHTML = dataRows + totalsRow;

        // Headers con sort
        const ths = table.querySelectorAll('thead th');
        TABLA_SUC_COLS.forEach((colDef, i) => {
            const th = ths[i];
            if (!th) return;
            const arrow = col === colDef.sortKey ? (asc ? ' ▲' : ' ▼') : ' ⇅';
            th.innerHTML = colDef.label + `<span style="opacity:.5;font-size:.7rem">${arrow}</span>`;
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';
            th.onclick = () => {
                if (_tablaSucSort.col === colDef.sortKey) {
                    _tablaSucSort.asc = !_tablaSucSort.asc;
                } else {
                    _tablaSucSort.col = colDef.sortKey;
                    _tablaSucSort.asc = colDef.sortKey === 'nombre';
                }
                renderTablaSucursales();
            };
        });
    }

    /* ── Medios de Pago ─────────────────────── */
    const MediosPago = (() => {
        let _mpChart = null;
        const COLORS = ['#2563eb','#00a878','#f59e0b','#8b5cf6','#ec4899','#14b8a6','#f97316','#6366f1','#84cc16','#ef4444'];

        function renderMPChart(labels, values, isDrilldown) {
            const wrap = $('medios-pago-wrap');
            if (!wrap) return;
            const total = values.reduce((s, v) => s + v, 0) || 1;

            if (_mpChart) { _mpChart.destroy(); _mpChart = null; }
            wrap.innerHTML = '';

            if (isDrilldown) {
                const btn = document.createElement('button');
                btn.className = 'btn-volver-ranking';
                btn.style.cssText = 'margin-bottom:6px;display:block';
                btn.textContent = '← Medios de Pago';
                btn.addEventListener('click', loadMediosPago);
                wrap.appendChild(btn);
            }

            // Canvas centrado
            const canvasWrap = document.createElement('div');
            canvasWrap.style.cssText = 'width:100%;max-width:180px;height:180px;position:relative;margin:0 auto';
            const canvas = document.createElement('canvas');
            canvasWrap.appendChild(canvas);
            wrap.appendChild(canvasWrap);

            _mpChart = new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        data           : values,
                        backgroundColor: COLORS.map(c => c + 'dd'),
                        borderColor    : '#fff',
                        borderWidth    : 2,
                        hoverOffset    : 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '55%',
                    onClick: isDrilldown ? undefined : (evt, elems) => {
                        if (!elems.length) return;
                        if (labels[elems[0].index] === 'TARJETA') loadCuotasTarjeta();
                    },
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            color: '#fff',
                            anchor: 'center',
                            align: 'center',
                            font: { weight: 'bold', size: 10 },
                            formatter: (v) => {
                                const p = v / total * 100;
                                return p >= 6 ? p.toFixed(1) + '%' : '';
                            },
                        },
                        tooltip: {
                            backgroundColor: '#ffffff',
                            titleColor: '#1a2340',
                            bodyColor: '#1a2340',
                            borderColor: '#e2e6f0',
                            borderWidth: 1,
                            callbacks: { label: ctx => ` ${fmt.money(ctx.parsed)} (${(ctx.parsed / total * 100).toFixed(1)} %)` }
                        },
                    },
                },
            });

            // Leyenda debajo del gráfico
            const legendDiv = document.createElement('div');
            legendDiv.style.cssText = 'margin-top:8px;font-size:.72rem;display:flex;flex-direction:column;gap:3px;overflow-y:auto;max-height:110px';
            legendDiv.innerHTML = labels.map((lbl, i) => {
                const v   = values[i] ?? 0;
                const pct = (v / total * 100).toFixed(1);
                const cur = !isDrilldown && lbl === 'TARJETA' ? 'pointer' : 'default';
                return `<div style="display:flex;align-items:center;gap:5px;cursor:${cur}" data-mp-idx="${i}">
                    <span style="width:8px;height:8px;border-radius:50%;background:${COLORS[i % COLORS.length]};flex-shrink:0"></span>
                    <span style="flex:1;color:var(--text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${lbl}</span>
                    <span style="font-weight:600;color:var(--text-1);flex-shrink:0">${pct}\u00A0%</span>
                </div>`;
            }).join('');
            wrap.appendChild(legendDiv);

            // Click en leyenda TARJETA
            if (!isDrilldown) {
                legendDiv.querySelectorAll('[data-mp-idx]').forEach(el => {
                    const idx = parseInt(el.dataset.mpIdx);
                    if (labels[idx] === 'TARJETA') el.addEventListener('click', loadCuotasTarjeta);
                });
            }
        }

        async function loadMediosPago() {
            const wrap = $('medios-pago-wrap');
            if (wrap) wrap.innerHTML = '<div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>';
            try {
                const d = await apiFetch('medios_pago.php');
                if (!d.medios?.length) {
                    if (wrap) wrap.innerHTML = '<div style="padding:16px;color:var(--text-3);font-size:.85rem">Sin datos</div>';
                    return;
                }
                renderMPChart(
                    d.medios.map(m => m.MEDIO_DE_PAGO || 'Sin especificar'),
                    d.medios.map(m => m.facturacion),
                    false
                );
            } catch(e) {
                if (wrap) wrap.innerHTML = `<div style="padding:16px;color:var(--neg);font-size:.85rem"><i class="bi bi-exclamation-triangle"></i> ${e.message}</div>`;
            }
        }

        async function loadCuotasTarjeta() {
            const wrap = $('medios-pago-wrap');
            if (wrap) wrap.innerHTML = '<div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>';
            try {
                const d = await apiFetch('medios_pago.php', { action: 'cuotas' });
                if (!d.cuotas?.length) { loadMediosPago(); return; }
                const total = d.cuotas.reduce((s, c) => s + c.facturacion, 0) || 1;
                renderMPChart(
                    d.cuotas.map(c => c.CUOTAS == 1 ? '1 cuota' : (c.CUOTAS + ' cuotas')),
                    d.cuotas.map(c => c.facturacion),
                    true
                );
            } catch(e) { loadMediosPago(); }
        }

        return { loadAll: loadMediosPago };
    })();

    /* ── Donuts ──────────────────────────────── */
    let _lastAnalisisData = null;

    async function loadDonuts() {
        try {
            const d = await apiFetch('analisis.php', { action: 'ranking_rubros' });
            _lastAnalisisData = d;
            renderDonuts(d);
        } catch(_) { /* donuts son opcionales */ }
    }

    function renderDonuts(d) {
        if (!d?.rubros?.length) return;
        const rubros = d.rubros.slice(0, 12);
        DonutManager.reset();
        DonutManager.create('donut-unidades-wrap',    'unidades',    rubros, '% Participación Unidades por Rubro');
        DonutManager.create('donut-facturacion-wrap', 'facturacion', rubros, '% Participación $ por Rubro');
    }

    /* ── Mapa de nombres de sucursales (compartido con otros módulos) ── */
    const _sucNombres = {};   // nro_sucurs (int) → nombre

    function getSucNombre(nro) {
        return _sucNombres[+nro] ?? ('Suc. ' + nro);
    }

    /* ── Custom searchable select ───────────── */
    function initSearchableSelect(selId) {
        const sel = $(selId);
        if (!sel || sel._ssInit) return;
        sel._ssInit = true;
        sel.style.display = 'none';

        const wrap = document.createElement('div');
        wrap.className = 'ss-wrap';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);

        // Botón visible (reemplaza el select)
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ss-btn';
        btn.innerHTML = `<span class="ss-txt">${sel.options[0]?.text ?? ''}</span><span class="ss-arrow">▾</span>`;
        wrap.insertBefore(btn, sel);

        // Panel con búsqueda + lista
        const panel = document.createElement('div');
        panel.className = 'ss-panel';
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'ss-input';
        input.placeholder = 'Buscar...';
        const list = document.createElement('div');
        list.className = 'ss-list';
        panel.appendChild(input);
        panel.appendChild(list);
        wrap.appendChild(panel);

        function buildList(q) {
            const opts = Array.from(sel.options);
            const filtered = q
                ? opts.filter(o => o.text.toLowerCase().includes(q.toLowerCase()))
                : opts;
            list.innerHTML = '';
            filtered.forEach(opt => {
                const item = document.createElement('div');
                item.className = 'ss-item' + (opt.value === sel.value ? ' ss-selected' : '');
                item.textContent = opt.text;
                item.addEventListener('click', () => {
                    sel.value = opt.value;
                    btn.querySelector('.ss-txt').textContent = opt.text;
                    wrap.classList.remove('open');
                    input.value = '';
                });
                list.appendChild(item);
            });
        }

        btn.addEventListener('click', e => {
            e.stopPropagation();
            const opening = !wrap.classList.contains('open');
            // Cerrar todos
            document.querySelectorAll('.ss-wrap.open').forEach(w => w.classList.remove('open'));
            if (opening) {
                wrap.classList.add('open');
                buildList('');
                input.value = '';
                input.focus();
            }
        });

        input.addEventListener('input', () => buildList(input.value.trim()));
        input.addEventListener('click', e => e.stopPropagation());

        document.addEventListener('click', () => {
            if (wrap.classList.contains('open')) wrap.classList.remove('open');
        });

        // Sincronizar texto del botón desde el valor actual del select
        sel._ssSync = () => {
            const opt = Array.from(sel.options).find(o => o.value === sel.value);
            btn.querySelector('.ss-txt').textContent = opt?.text ?? '';
        };
    }

    function syncSearchableSelect(selId) {
        const sel = $(selId);
        if (sel?._ssSync) sel._ssSync();
    }

    /* ── Cargar filtros dependientes ─────────── */
    async function loadFilters() {
        try {
            const p   = getParams();
            const qs  = new URLSearchParams({ origen: p.origen, periodo: p.periodo }).toString();
            const res = await fetch(`/bi/global/api/filtros.php?${qs}`);
            if (!res.ok) return;
            const data = await res.json();
            if (!data.ok) return;

            // allValue: el valor del option "Todos" — '' para sucursal/grupo/tipo (PHP usa null),
            //           '%' para vendedor/rubro (PHP usa LIKE '%' = sin filtro)
            const fill = (selId, items, valKey, labelKey, allLabel = 'Todos', allValue = '') => {
                const sel = $(selId);
                if (!sel) return;
                const cur = sel.value;
                sel.innerHTML = `<option value="${allValue}">${allLabel}</option>` +
                    items.map(it => `<option value="${it[valKey]}"${String(it[valKey]) === cur ? ' selected' : ''}>${it[labelKey] ?? it[valKey]}</option>`).join('');
            };

            // Poblar mapa de nombres
            (data.sucursales ?? []).forEach(s => {
                _sucNombres[+s.NRO_SUCURS] = s.DESC_SUCURSAL ?? ('Suc. ' + s.NRO_SUCURS);
            });

            // IDs de sucursales activas (para filtro client-side)
            if (Array.isArray(data.sucursales_activas)) {
                _sucursalesActivasIds = new Set(data.sucursales_activas.map(Number));
            }

            fill('sel-sucursal',    data.sucursales   ?? [], 'NRO_SUCURS',   'DESC_SUCURSAL', 'Todas',  '');
            fill('sel-grupo',       data.grupos       ?? [], 'GRUPO',        'GRUPO',          'Todos',  '');
            fill('sel-tipo-tienda', data.tipos_tienda ?? [], 'TIPO_TIENDA',  'TIPO_TIENDA',   'Todos',  '');
            fill('sel-vendedor',    data.vendedores   ?? [], 'DESC_VENDEDOR','DESC_VENDEDOR',  'Todos',  '%');
            fill('sel-rubro',       data.rubros       ?? [], 'RUBRO',        'RUBRO',          'Todos',  '%');
            fill('sel-tipo-local',  data.tipos_local  ?? [], 'TIPO_LOCAL',   'TIPO_LOCAL',     'Todos',  '');
            fill('sel-zona',        data.zonas        ?? [], 'ZONA',         'ZONA',           'Todos',  '');
            fill('sel-grupo-empresario', data.grupos_empresario ?? [], 'GRUPO_EMPRESARIO', 'GRUPO_EMPRESARIO', 'Todos', '');

            // Inicializar custom selects (solo la primera vez) y sincronizar texto
            ['sel-sucursal', 'sel-vendedor', 'sel-rubro', 'sel-grupo-empresario'].forEach(id => {
                initSearchableSelect(id);
                syncSearchableSelect(id);
            });

        } catch(_) { /* filtros no críticos */ }
    }

    /* ── KPIs ────────────────────────────────── */
    function renderKPIs(d) {
        const a = d.actual, p = d.previo, v = d.variacion;

        // Ventas
        setText('fact-act',  fmt.moneyK(a.facturacion));
        setVar ('fact-var',  v.facturacion);
        setText('fact-prev', fmt.moneyK(p.facturacion));

        // Objetivo
        setText('obj-act',   fmt.moneyK(a.objetivo));
        setVar ('obj-var',   v.objetivo);
        setText('obj-total', fmt.moneyK(a.objetivo_total));

        // Unidades
        setText('unid-act',  fmt.num(a.unidades));
        setVar ('unid-var',  v.unidades);
        setText('unid-prev', fmt.num(p.unidades));

        // Tickets
        setText('tickets-act',  fmt.num(a.tickets));
        setVar ('tickets-var',  v.tickets);
        setText('tickets-prev', fmt.num(p.tickets));

        // KPI cards
        setText('card-tprom-val',   fmt.money(a.ticket_promedio));
        setKpiVar('card-tprom-var', v.ticket_promedio,     fmt.money(p.ticket_promedio),     fmt.money(a.ticket_promedio));

        setText('card-tp2do-val',   fmt.money(a.ticket_promedio_2do));
        setKpiVar('card-tp2do-var', v.ticket_promedio_2do, fmt.money(p.ticket_promedio_2do), fmt.money(a.ticket_promedio_2do));

        setText('card-t2do-val',    fmt.pct(a.porc_2do));
        setVarDiff('card-t2do-var', v.porc_2do,         false, fmt.pct(p.porc_2do),         fmt.pct(a.porc_2do));

        setText('card-t3ro-val',    fmt.pct(a.porc_3ro));
        setVarDiff('card-t3ro-var', v.porc_3ro,         false, fmt.pct(p.porc_3ro),         fmt.pct(a.porc_3ro));

        setText('card-cambios-val',    fmt.pct(a.porc_cambios));
        setVarDiff('card-cambios-var', v.porc_cambios,   true,  fmt.pct(p.porc_cambios),     fmt.pct(a.porc_cambios));

        setText('card-incr-val',    fmt.pct(a.porc_incremental));
        setVarDiff('card-incr-var', v.porc_incremental, false, fmt.pct(p.porc_incremental),  fmt.pct(a.porc_incremental));
    }

    /* ── Benchmark diferido (?action=benchmark) ──────────────────────────── */
    function renderBenchmark(b) {
        setText('card-tprom-bench',   fmt.money(b.ticket_promedio));
        setText('card-tp2do-bench',   fmt.money(b.ticket_promedio_2do));
        setText('card-t2do-bench',    fmt.pct(b.porc_2do));
        setText('card-t3ro-bench',    fmt.pct(b.porc_3ro));
        setText('card-cambios-bench', fmt.pct(b.porc_cambios));
        setText('card-incr-bench',    fmt.pct(b.porc_incremental));
    }

    /* ── Conversión diferida (?action=conversion) ────────────────────────── */
    function renderConversion(cd) {
        const a = cd.actual ?? {}, p = cd.previo ?? {}, v = cd.variacion ?? {};
        setText('conv-act',      fmt.pct(a.conversion));
        setVar ('conv-var',      v.conversion);
        setText('conv-prev',     fmt.pct(p.conversion));
        setText('conv-ingresos', fmt.num(a.ingresos));
    }

    /* ── Sparklines (carga diferida desde ?action=serie) ─────────────── */
    function renderSparklines(serie) {
        if (serie?.cumplimiento?.length) {
            const cumpl      = serie.cumplimiento;
            const cumplVals  = cumpl.map(r => r.cumplimiento);
            const cumplDates = cumpl.map(r => r.fecha);
            sparkLine('spark-obj', cumplVals, '#2563eb', null, cumplDates);
            SparkModal.register('spark-obj', cumplVals, cumplDates, '#2563eb', n => fmt.varPct(n), 'Cumplimiento Objetivo');
        }

        if (serie?.actual?.length) {
            const sa = serie.actual;
            const sp = serie.previo ?? [];
            const dates = sa.map(x => x.fecha);
            const vFact  = sa.map(x => x.facturacion ?? 0);
            const vPFact = sp.map(x => x.facturacion ?? 0);
            const vUnid  = sa.map(x => x.unidades ?? 0);
            const vTick  = sa.map(x => x.tickets ?? 0);
            const vTProm = sa.map(x => x.ticket_promedio ?? 0);
            const vTp2do = sa.map(x => x.ticket_promedio_2do ?? 0);
            const vT2do  = sa.map(x => x.porc_2do ?? 0);
            const vT3ro  = sa.map(x => x.porc_3ro ?? 0);
            const vCamb  = sa.map(x => x.porc_cambios ?? 0);
            const vIncr  = sa.map(x => x.porc_incremental ?? 0);
            const vConv  = sa.map(x => x.conversion ?? 0);
            console.log('[renderSparklines] sa:', sa);
            console.log('[renderSparklines] vConv:', vConv);

            sparkLine('spark-fact',          vFact,  '#00a878', vPFact, dates);
            sparkLine('spark-unid',          vUnid,  '#f59e0b', null,   dates);
            sparkLine('spark-tickets-main',  vTick,  '#8b5cf6', null,   dates);
            sparkLine('spark-conv',          vConv,  '#ec4899', null,   dates);
            sparkLine('spark-tprom',         vTProm, '#2563eb', null,   dates);
            sparkLine('spark-tp2do',         vTp2do, '#a855f7', null,   dates);
            sparkLine('spark-t2do',          vT2do,  '#14b8a6', null,   dates);
            sparkLine('spark-t3ro',          vT3ro,  '#6366f1', null,   dates);
            sparkLine('spark-cambios',       vCamb,  '#f97316', null,   dates);
            sparkLine('spark-incr',          vIncr,  '#22c55e', null,   dates);

            SparkModal.register('spark-fact',  vFact,  dates, '#00a878', fmt.moneyK, 'Facturación diaria');
            SparkModal.register('spark-unid',  vUnid,  dates, '#f59e0b', fmt.num,    'Unidades diarias');
            SparkModal.register('spark-tickets-main', vTick, dates, '#8b5cf6', fmt.num, 'Tickets diarios');
            SparkModal.register('spark-conv',  vConv,  dates, '#ec4899', n => fmt.pct(n), 'Conversión diaria');
            SparkModal.register('spark-tprom', vTProm, dates, '#2563eb', fmt.money, 'Ticket Promedio');
            SparkModal.register('spark-tp2do', vTp2do, dates, '#a855f7', fmt.money, 'T. Prom. 2do Producto');
            SparkModal.register('spark-t2do',  vT2do,  dates, '#14b8a6', n => fmt.pct(n), '% Tickets 2do Producto');
            SparkModal.register('spark-t3ro',  vT3ro,  dates, '#6366f1', n => fmt.pct(n), '% Tickets 3er Producto');
            SparkModal.register('spark-cambios', vCamb, dates, '#f97316', n => fmt.pct(n), '% Cambios');
            SparkModal.register('spark-incr',  vIncr,  dates, '#22c55e', n => fmt.pct(n), '% Incremental');
        }
    }

    /* ── DOM helpers ─────────────────────────── */
    function setText(id, text) { const el = $(id); if (el) el.textContent = text; }
    function setVar(id, ratio) {
        const el = $(id);
        if (!el) return;
        el.textContent = fmt.varPct(ratio);
        el.className   = 'summary-var ' + (ratio >= 0 ? 'pos' : 'neg');
    }
    function setKpiVar(id, ratio, prevText = null, actualText = null) {
        const el = $(id);
        if (!el) return;
        const sign = ratio >= 0 ? '+' : '';
        el.textContent = sign + (ratio * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '\u00A0%';
        el.className   = 'kpi-var ' + (ratio >= 0 ? 'pos' : 'neg');
        if (prevText   !== null) el.dataset.prev   = prevText;
        if (actualText !== null) el.dataset.actual = actualText;
    }
    function setVarDiff(id, diff, inverse = false, prevText = null, actualText = null) {
        const el = $(id);
        if (!el) return;
        const sign = diff >= 0 ? '+' : '';
        el.textContent = sign + (diff * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '\u00A0pp';
        const good = inverse ? diff <= 0 : diff >= 0;
        el.className   = 'kpi-var ' + (good ? 'pos' : 'neg');
        if (prevText   !== null) el.dataset.prev   = prevText;
        if (actualText !== null) el.dataset.actual = actualText;
    }

    /* ── Loading state ───────────────────────── */
    function setLoading(on, msg = 'Cargando datos...') {
        // Si intentamos activar el loading pero no estamos en la pestaña de KPIs, lo ignoramos
        // (a menos que sea un mensaje genérico no relacionado a KPIs)
        if (on && msg.includes('KPIs') && !document.getElementById('tab-kpis')?.classList.contains('active')) {
            return;
        }

        document.body.classList.toggle('is-loading', on);
        if (on) Spinner.show(msg);
        else    Spinner.hide();
    }

    /* ── Actualizar label TCC ────────────────── */
    function updateTccLabel() {
        const el = $('moneda-tcc-label');
        if (!el) return;
        if (_moneda !== 'USD') {
            el.hidden = true;
            el.textContent = '';
            return;
        }
        // Mostrar TCC del último mes del período actual (no la más reciente global)
        const mesActual = (_lastPeriodo?.hasta_act ?? '').substring(0, 7);
        const tccPeriodo = mesActual ? (_cotizaciones[mesActual] ?? _tccActual) : _tccActual;
        el.hidden = false;
        el.innerHTML = `1 U$S = $${tccPeriodo.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} <i class="bi bi-table" style="font-size:.7rem;opacity:.7;margin-left:3px"></i>`;
    }

    /* ── Modal cotizaciones mes a mes ────────── */
    (function () {
        function openTccModal() {
            const overlay = $('tcc-modal-overlay');
            const body    = $('tcc-modal-body');
            if (!overlay || !body) return;

            const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
            // Ordenar de más reciente a más antiguo (claves son YYYY-MM)
            const entries = Object.entries(_cotizaciones).sort(([a], [b]) => b.localeCompare(a));

            if (!entries.length) {
                body.innerHTML = '<p style="color:var(--text-3);text-align:center;padding:20px">Sin datos de cotización para el período.</p>';
            } else {
                const mesActual = (_lastPeriodo?.hasta_act ?? '').substring(0, 7);

                // Agrupar por año directamente desde las claves YYYY-MM
                let html = '';
                let anioActual = null;
                entries.forEach(([mk, tcc]) => {
                    const anio = mk.substring(0, 4);
                    const mesIdx = parseInt(mk.substring(5, 7), 10) - 1; // 0-based
                    const label = MESES[mesIdx] + ' ' + anio;
                    const esActual = mk === mesActual;

                    if (anio !== anioActual) {
                        if (anioActual !== null) html += '</tbody></table></div>';
                        html += `<div class="tcc-anio-group"><div class="tcc-anio-label">${anio}</div><table class="tcc-table"><tbody>`;
                        anioActual = anio;
                    }
                    html += `<tr class="${esActual ? 'tcc-row-highlight' : ''}">
                        <td>${label}</td>
                        <td>$&nbsp;${tcc.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
                        <td>1 U$S</td>
                    </tr>`;
                });
                if (anioActual !== null) html += '</tbody></table></div>';
                body.innerHTML = html;
            }

            overlay.hidden = false;
            document.body.style.overflow = 'hidden';
        }

        function closeTccModal() {
            const overlay = $('tcc-modal-overlay');
            if (overlay) overlay.hidden = true;
            document.body.style.overflow = '';
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('moneda-tcc-label')?.addEventListener('click', openTccModal);
            document.getElementById('tcc-modal-close')?.addEventListener('click', closeTccModal);
            document.getElementById('tcc-modal-overlay')?.addEventListener('click', e => {
                if (e.target.id === 'tcc-modal-overlay') closeTccModal();
            });
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTccModal(); });
    })();

    /* ── Cargar cotizaciones mensuales ───────── */
    async function loadCotizaciones(periodo) {
        try {
            const origenActive = document.querySelector('.origen-btn.active');
            const origen = (window.BI_CONFIG?.isGrupo ? 'franquicias' : (origenActive?.dataset.origen ?? 'argentina'));
            const qs = new URLSearchParams({
                desde      : periodo.desde_act  ?? '',
                hasta      : periodo.hasta_act  ?? '',
                desde_prev : periodo.desde_prev ?? '',
                hasta_prev : periodo.hasta_prev ?? '',
                origen,
            }).toString();
            const res  = await fetch(`/bi/global/api/cotizacion.php?${qs}`);
            const data = await res.json();
            if (data.ok) {
                _cotizaciones = data.cotizaciones ?? {};
                _tccActual    = data.tcc_actual   ?? 1;
                updateTccLabel();
            }
        } catch (e) {
            console.warn('[Dashboard] loadCotizaciones:', e);
        }
    }

    /* ── Moneda toggle ───────────────────────── */
    let _lastKpiData  = null;
    let _lastBenchData = null;
    let _lastConvData  = null;

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('#moneda-toggle .moneda-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (btn.dataset.moneda === _moneda) return;
                _moneda = btn.dataset.moneda;
                document.querySelectorAll('#moneda-toggle .moneda-btn').forEach(b => b.classList.toggle('active', b === btn));
                updateTccLabel();
                if (_lastKpiData) {
                    renderKPIs(_lastKpiData);
                    renderTablaSucursales(_lastKpiData.tabla_sucursales);
                }
                if (_lastBenchData) renderBenchmark(_lastBenchData);
                if (_lastConvData)  renderConversion(_lastConvData);
            });
        });
    });

    /* ── API principal ───────────────────────── */
    async function loadAll() {
        if (!document.getElementById('tab-kpis')?.classList.contains('active')) return;
        setLoading(true, 'Cargando KPIs...');
        try {
            const d = await apiFetch('kpis.php');
            _lastKpiData  = d;
            _lastPeriodo  = d.periodo ?? null;
            // Cargar cotizaciones antes de renderizar
            if (_lastPeriodo) await loadCotizaciones(_lastPeriodo);
            updatePeriodLabel(d.periodo);
            renderKPIs(d);
            renderTablaSucursales(d.tabla_sucursales);
            // Async — no bloquean el render inicial; se ejecutan de a uno para no saturar el servidor
            await (async () => {
                try { const sd = await apiFetch('kpis.php', { action: 'serie' });
                      if (sd?.serie) renderSparklines(sd.serie); } catch(e) {}
                try { const bd = await apiFetch('kpis.php', { action: 'benchmark' });
                      if (bd?.benchmark) { _lastBenchData = bd.benchmark; renderBenchmark(bd.benchmark); } } catch(e) {}
                try { const cd = await apiFetch('kpis.php', { action: 'conversion' });
                      if (cd) { _lastConvData = cd; renderConversion(cd); } } catch(e) {}
                try { const hd = await apiFetch('kpis.php', { action: 'conversion_horas' });
                      if (hd?.horas?.length) SparkModal.setHoras('spark-conv', hd.horas); } catch(e) {}
            })();
        } catch(e) {
            console.error('[Dashboard]', e);
        } finally {
            setLoading(false);
        }
    }

    return {
        loadAll, loadFilters, getParams, buildQS, getSucNombre,
        initSearchableSelect, syncSearchableSelect,
        isSoloActivas, getSucursalesActivasIds,
        convertir, convertirConFecha, moneyPrefix,
        getTCCParaMes, getMoneda: () => _moneda,
        apiFetch, fmt, setLoading,
        loadDonuts, loadMediosPago: MediosPago.loadAll
    };
})();

/* ── Tooltip período previo en KPI vars ── */
(function () {
    const tt = document.createElement('div');
    tt.className = 'sparkline-tooltip';
    tt.style.display = 'none';
    document.body.appendChild(tt);

    document.addEventListener('mouseover', function (e) {
        const el = e.target.closest('.kpi-var[data-prev]');
        if (!el) return;
        if (el.dataset.actual) {
            const varCls = el.classList.contains('pos') ? 'pos' : 'neg';
            tt.innerHTML =
                '<div style="font-size:.68rem;color:#9ba8c8;margin-bottom:5px;font-weight:600">Período anterior</div>' +
                '<div class="tooltip-row"><span>Actual</span><strong>' + el.dataset.actual + '</strong></div>' +
                '<div class="tooltip-row"><span>Anterior</span><strong>' + el.dataset.prev + '</strong></div>' +
                '<div class="tooltip-row"><span>Variación</span><strong class="' + varCls + '">' + el.textContent.trim() + '</strong></div>';
        } else {
            tt.innerHTML = '<span class="tooltip-date">Período previo</span><span class="tooltip-value">' + el.dataset.prev + '</span>';
        }
        tt.style.display = 'block';
    });
    document.addEventListener('mousemove', function (e) {
        if (tt.style.display === 'none') return;
        const x = e.clientX + 12;
        const y = e.clientY - 36;
        tt.style.left = Math.min(x, window.innerWidth - tt.offsetWidth - 8) + 'px';
        tt.style.top  = (y < 8 ? e.clientY + 12 : y) + 'px';
    });
    document.addEventListener('mouseout', function (e) {
        if (!e.target.closest('.kpi-var[data-prev]')) return;
        tt.style.display = 'none';
    });
})();
