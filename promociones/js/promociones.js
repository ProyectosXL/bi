/**
 * /bi/promociones/js/promociones.js
 * Orquestador principal del dashboard de Promociones.
 */

/* ── Spinner bloqueante ── */
const PromoSpinner = (() => {
    let overlay = null;
    function show(msg = 'Cargando datos...') {
        if (overlay) {
            if (msg) {
                const textDiv = overlay.querySelector('div:last-child');
                if (textDiv) textDiv.textContent = msg;
            }
            return;
        }
        overlay = document.createElement('div');
        overlay.id = 'bi-spinner-overlay';
        overlay.style.cssText = [
            'position:fixed','inset:0','z-index:99999',
            'background:rgba(26,35,64,0.6)','backdrop-filter:blur(2px)',
            'display:flex','flex-direction:column','align-items:center',
            'justify-content:center','gap:16px','pointer-events:all'
        ].join(';');
        if (!document.getElementById('bi-spin-style')) {
            const style = document.createElement('style');
            style.id = 'bi-spin-style';
            style.textContent = '@keyframes bi-spin{to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }
        overlay.innerHTML = `
            <div style="width:52px;height:52px;border:4px solid rgba(255,255,255,0.2);border-top-color:#00a878;border-radius:50%;animation:bi-spin 0.75s linear infinite;"></div>
            <div style="color:rgba(255,255,255,0.92);font-family:'Barlow Condensed',sans-serif;font-size:1.1rem;font-weight:600;letter-spacing:0.5px;">${msg}</div>`;
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

const Promociones = (() => {

    const $ = id => document.getElementById(id);

    /* ── Estado ── */
    let _cotizaciones        = {};
    let _tccActual           = 1;
    let _moneda              = 'ARS';
    let _lastPeriodo         = null;
    let _sucursalesActivasIds = new Set();

    function isSoloActivas() {
        const cfg = window.BI_CONFIG ?? {};
        return !cfg.isGrupo && !!($('chk-solo-activas')?.checked);
    }

    /* ── Moneda ── */
    function getTCCParaMes(mesKey) { return _cotizaciones[mesKey] ?? _tccActual; }

    function convertirConFecha(valor, fecha) {
        if (_moneda === 'ARS') return valor ?? 0;
        const mesKey = (fecha ?? '').substring(0, 7);
        return (valor ?? 0) / getTCCParaMes(mesKey);
    }

    function convertir(val) {
        if (_moneda === 'ARS') return val ?? 0;
        const mesKey = (_lastPeriodo?.desde_act ?? '').substring(0, 7);
        return (val ?? 0) / getTCCParaMes(mesKey);
    }

    function moneyPrefix() { return _moneda === 'USD' ? 'U$S ' : '$ '; }

    /* ── Logos de bancos ── */
    const BANK_LOGOS = {
        'GALICIA'  : 'Banco Galicia.jpeg',
        'ICBC'     : 'Banco ICBC.JPG',
        'PROVINCIA': 'Banco Provincia.jpg',
        'SANTANDER': 'Banco Santander.JPG',
        'NACION': 'Banco Nacion.jpg',
        'MACRO': 'Banco Macro.jpg',
        'FRANCES': 'Banco Frances.jpg',
        'HIPOTECARIO': 'Banco Hipotecario.jpg',
        'PATAGONIA': 'Banco Patagonia.jpg',
    };

    function normalizarBanco(banco) {
        if (!banco) return '';
        return banco.toUpperCase().trim().replace(/^BANCO\s+/, '');
    }

    function bancoLogoSrc(banco) {
        const key = normalizarBanco(banco);
        if (BANK_LOGOS[key]) return `/bi/promociones/assets/bancos/${encodeURIComponent(BANK_LOGOS[key])}`;
        return null;
    }

    /* ── Formato ── */
    const fmt = {
        money: (n, d = 0) => {
            if (n == null) return '—';
            return moneyPrefix() + convertir(n).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d });
        },
        moneyK: n => {
            if (n == null) return '—';
            const v   = convertir(n);
            const pfx = moneyPrefix();
            if (Math.abs(v) >= 1_000_000) return pfx + (v / 1_000_000).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'M';
            if (Math.abs(v) >= 1_000)     return pfx + (v / 1_000).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + 'K';
            return pfx + v.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        },
        pct: (n, d = 1) => n == null ? '—' : (n * 100).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }) + ' %',
        varPct: (n, d = 1) => {
            if (n == null) return '—';
            return (n >= 0 ? '+' : '') + (n * 100).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }) + ' %';
        },
        varPp: (n, d = 2) => {
            if (n == null) return '—';
            return (n >= 0 ? '+' : '') + (n * 100).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }) + ' pp';
        },
        /* Tickets: entero, sin moneda, nunca afectado por toggle de moneda */
        num: (n, d = 0) => n == null ? '—' : Number(n).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d }),
    };

    /* ── Cache payloads ── */
    let _lastKpis   = null;
    let _lastBancos = null;
    let _lastDonuts = null;

    /* ── Parámetros del DOM ── */
    function getParams(extra = {}) {
        const cfg          = window.BI_CONFIG ?? { isGrupo: false, sucursalesGrupo: [] };
        const origenActive = document.querySelector('.origen-btn.active');

        const p = {
            origen      : cfg.isGrupo ? 'franquicias' : (origenActive?.dataset.origen ?? 'argentina'),
            periodo     : ($('sel-periodo')?.value   ?? 'mes_actual'),
            sucursal    : ($('sel-sucursal')?.value  ?? ''),
            banco       : ($('sel-banco')?.value     ?? ''),
            promocion   : ($('sel-promocion')?.value ?? ''),
            solo_activas: isSoloActivas() ? '1' : '0',
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
        ['sucursal', 'banco', 'promocion'].forEach(k => { if (!p[k]) delete p[k]; });
        return p;
    }

    function buildQS(extra = {}) { return new URLSearchParams(getParams(extra)).toString(); }

    async function apiFetch(endpoint, extra = {}) {
        const res = await fetch(`/bi/promociones/api/${endpoint}?${buildQS(extra)}`);
        if (!res.ok) {
            const body = await res.text().catch(() => '');
            throw new Error(`Error ${res.status} en ${endpoint}: ${body}`);
        }
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || `Error en ${endpoint}`);
        return data;
    }

    /* ── Spinner público ── */
    function setLoading(show, msg) {
        if (show) PromoSpinner.show(msg);
        else PromoSpinner.hide();
    }

    /* ── Cotización TCC ── */
    async function loadCotizacion(periodoData) {
        if (!periodoData) return;
        try {
            const qs = new URLSearchParams({
                desde      : periodoData.desde_act  ?? '',
                hasta      : periodoData.hasta_act  ?? '',
                desde_prev : periodoData.desde_prev ?? '',
                hasta_prev : periodoData.hasta_prev ?? '',
            }).toString();
            const data = await fetch(`/bi/promociones/api/cotizacion.php?${qs}`).then(r => r.json());
            if (data.ok) {
                _cotizaciones = data.cotizaciones ?? {};
                _tccActual    = data.tcc_actual   ?? 1;
                renderTCCLabel();
                renderTCCModal(_cotizaciones, _tccActual);
            }
        } catch (_) {}
    }

    function renderTCCLabel() {
        const lbl = $('moneda-tcc-label');
        if (!lbl) return;
        if (_moneda === 'USD' && _tccActual > 1) {
            lbl.textContent = `TCC $ ${_tccActual.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
            lbl.hidden = false;
        } else {
            lbl.hidden = true;
        }
    }

    function renderTCCModal(cotizaciones, tccActual) {
        const body = $('tcc-modal-body');
        if (!body) return;
        const meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        const keys  = Object.keys(cotizaciones).sort();
        if (!keys.length) { body.innerHTML = '<div style="color:var(--text-3);padding:12px">Sin datos de cotización</div>'; return; }
        body.innerHTML = keys.map(k => {
            const [y, m] = k.split('-');
            const label  = `${meses[parseInt(m) - 1]} ${y}`;
            const val    = cotizaciones[k];
            const isCurr = Math.abs(val - tccActual) < 0.01;
            return `<div class="tcc-row${isCurr ? ' tcc-row-current' : ''}">
                <span class="tcc-mes">${label}</span>
                <span class="tcc-val">$ ${val.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
            </div>`;
        }).join('');
    }

    /* ── Toggle moneda ── */
    function initMonedaToggle() {
        document.querySelectorAll('.moneda-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.moneda-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                _moneda = btn.dataset.moneda ?? 'ARS';
                renderTCCLabel();
                if (_lastKpis)   renderKpis(_lastKpis);
                if (_lastBancos) renderBancos(_lastBancos);
                if (_lastDonuts) renderDonuts(_lastDonuts);
                if (typeof PromoMensual !== 'undefined') PromoMensual.onMonedaChange();
                if (typeof PromoDetalle !== 'undefined') PromoDetalle.onMonedaChange();
            });
        });
        const tccLabel   = $('moneda-tcc-label');
        const tccOverlay = $('tcc-modal-overlay');
        const tccClose   = $('tcc-modal-close');
        if (tccLabel && tccOverlay) {
            tccLabel.addEventListener('click', () => { tccOverlay.hidden = false; });
            tccClose?.addEventListener('click', () => { tccOverlay.hidden = true; });
            tccOverlay.addEventListener('click', e => { if (e.target === tccOverlay) tccOverlay.hidden = true; });
        }
    }

    /* ── Render KPIs ── */
    function renderKpis(d) {
        _lastKpis    = d;
        _lastPeriodo = d.periodo;

        const act  = d.actual    ?? {};
        const prv  = d.previo    ?? {};
        const var_ = d.variacion ?? {};

        const lbl = $('periodo-label');
        const lpv = $('periodo-previo-label');
        if (lbl && d.periodo) lbl.textContent = `${d.periodo.desde_act} → ${d.periodo.hasta_act}`;
        if (lpv && d.periodo) lpv.textContent = `(prev: ${d.periodo.desde_prev} → ${d.periodo.hasta_prev})`;

        /* Setter genérico para cards monetarias */
        const setCard = (id, val, varVal, prev) => {
            const el = $(id);
            if (!el) return;
            const valEl  = el.querySelector('.promo-kpi-value');
            const varEl  = el.querySelector('.promo-kpi-var');
            const prevEl = el.querySelector('.promo-kpi-prev');
            if (valEl)  valEl.textContent  = fmt.moneyK(val);
            if (varEl) {
                varEl.textContent = fmt.varPct(varVal);
                varEl.className   = 'promo-kpi-var ' + (varVal >= 0 ? 'pos' : 'neg');
            }
            if (prevEl) prevEl.textContent = fmt.moneyK(prev);
        };

        /* Setter para tickets — siempre entero, sin moneda */
        const setTicketsCard = (id, val, varVal, prev) => {
            const el = $(id);
            if (!el) return;
            const valEl  = el.querySelector('.promo-kpi-value');
            const varEl  = el.querySelector('.promo-kpi-var');
            const prevEl = el.querySelector('.promo-kpi-prev');
            if (valEl)  valEl.textContent  = fmt.num(val);
            if (varEl) {
                varEl.textContent = fmt.varPct(varVal);
                varEl.className   = 'promo-kpi-var ' + (varVal >= 0 ? 'pos' : 'neg');
            }
            if (prevEl) prevEl.textContent = fmt.num(prev);
        };

        /* Setter para ratios % — variación en pp, subir costo = malo */
        const setRatioCard = (id, actVal, varVal, prevVal, costoBad = false) => {
            const el = $(id);
            if (!el) return;
            const valEl  = el.querySelector('.promo-kpi-value');
            const varEl  = el.querySelector('.promo-kpi-var');
            const prevEl = el.querySelector('.promo-kpi-prev');
            if (valEl)  valEl.textContent  = fmt.pct(actVal, 2);
            if (varEl) {
                varEl.textContent = fmt.varPp(varVal);
                const esBueno = costoBad ? varVal <= 0 : varVal >= 0;
                varEl.className = 'promo-kpi-var ' + (esBueno ? 'pos' : 'neg');
            }
            if (prevEl) prevEl.textContent = fmt.pct(prevVal, 2);
        };

        setCard('kpi-fact-cpromo', act.facturacion_cpromo, var_.facturacion_cpromo, prv.facturacion_cpromo);
        setTicketsCard('kpi-tickets-cpromo', act.tickets_cpromo, var_.tickets_cpromo, prv.tickets_cpromo);

        setRatioCard('kpi-pct-costo-total', act.pct_costo_total, var_.pct_costo_total, prv.pct_costo_total, true);
        setRatioCard('kpi-pct-promo-fac',   act.pct_promo_fac,   var_.pct_promo_fac,   prv.pct_promo_fac,  false);

        /* Mini-KPIs ahora usan el mismo markup (.promo-kpi-value, etc.) */
        setRatioCard('kpi-mini-banc',   act.pct_costo_banc_fac,  var_.pct_costo_banc_fac,  prv.pct_costo_banc_fac,  true);
        setRatioCard('kpi-mini-ventas', act.pct_costo_ventas_fac, var_.pct_costo_ventas_fac, prv.pct_costo_ventas_fac, true);
    }

    /* ── Render cards banco ── */
    function renderBancos(bancos) {
        _lastBancos = bancos;
        const wrap  = $('banco-cards-wrap');
        if (!wrap) return;
        if (!bancos?.length) { wrap.innerHTML = '<div class="promo-loading">Sin datos de bancos</div>'; return; }

        wrap.innerHTML = bancos.map(b => {
            const src = bancoLogoSrc(b.banco);
            const logoHtml = src
                ? `<img class="banco-logo" src="${src}" alt="${b.banco}" onerror="this.style.display='none'">`
                : `<span style="font-size:.85rem;font-weight:700;color:var(--text-2)">${b.banco}</span>`;

            const fv   = fmt.varPct(b.var_facturacion);
            const tv   = fmt.varPct(b.var_tickets);
            const fcls = b.var_facturacion >= 0 ? 'pos' : 'neg';
            const tcls = b.var_tickets     >= 0 ? 'pos' : 'neg';

            return `<div class="banco-card">
                <div class="banco-card-header">
                    <div class="banco-logo-wrap">${logoHtml}</div>
                    <span class="banco-name">${b.banco}</span>
                </div>
                <div class="banco-metrics">
                    <div class="banco-metric-row">
                        <span class="banco-metric-label">Fact. C/Promo</span>
                        <span class="banco-metric-value">${fmt.moneyK(b.facturacion_act)}</span>
                    </div>
                    <div class="banco-metric-row">
                        <span class="banco-metric-label"></span>
                        <span class="banco-metric-var ${fcls}">${fv}</span>
                    </div>
                    <div class="banco-metric-row" style="margin-top:4px">
                        <span class="banco-metric-label">Tickets C/Promo</span>
                        <span class="banco-metric-value">${fmt.num(b.tickets_act)}</span>
                    </div>
                    <div class="banco-metric-row">
                        <span class="banco-metric-label"></span>
                        <span class="banco-metric-var ${tcls}">${tv}</span>
                    </div>
                </div>
            </div>`;
        }).join('');
    }

    /* ── Render donuts ── */
    const _donutCharts = {};
    const DONUT_PALETTE = [
        '#2563eb','#16a34a','#dc2626','#d97706','#7c3aed',
        '#0891b2','#db2777','#65a30d','#9f1239','#0d9488',
        '#6366f1','#ea580c',
    ];

    function renderDonut(canvasId, legendId, items) {
        const canvas = $(canvasId);
        const legend = $(legendId);
        if (!canvas) return;
        if (_donutCharts[canvasId]) { _donutCharts[canvasId].destroy(); delete _donutCharts[canvasId]; }

        const top    = items.slice(0, 10);
        const labels = top.map(i => i.etiqueta ?? '—');
        const vals   = top.map(i => convertir(i.valor));
        const colors = top.map((_, idx) => DONUT_PALETTE[idx % DONUT_PALETTE.length]);
        const total  = vals.reduce((a, b) => a + b, 0);

        _donutCharts[canvasId] = new Chart(canvas, {
            type: 'doughnut',
            data: { labels, datasets: [{ data: vals, backgroundColor: colors, borderWidth: 1, borderColor: '#fff' }] },
            options: {
                responsive: true, maintainAspectRatio: true, cutout: '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                const v    = ctx.raw ?? 0;
                                const pct  = total > 0 ? (v / total * 100).toFixed(1) : '0.0';
                                const item = top[ctx.dataIndex];
                                return [
                                    ` Facturación: ${fmt.moneyK(item?.valor ?? 0)} (${pct} %)`,
                                    ` Costo Promo: ${fmt.moneyK(item?.costo_promo ?? 0)}`,
                                ];
                            }
                        }
                    },
                    datalabels: { display: false },
                },
            }
        });

        if (legend) {
            legend.innerHTML = top.map((item, idx) => {
                const v   = convertir(item.valor);
                const pct = total > 0 ? (v / total * 100).toFixed(1) : '0.0';
                const lbl = (item.etiqueta ?? '').length > 28
                    ? (item.etiqueta ?? '').substring(0, 26) + '…'
                    : (item.etiqueta ?? '—');
                return `<div class="promo-legend-item" title="${item.etiqueta ?? ''}">
                    <span class="promo-legend-dot" style="background:${colors[idx]}"></span>
                    <span class="promo-legend-label">${lbl}</span>
                    <span class="promo-legend-val">${pct} %</span>
                </div>`;
            }).join('');
        }
    }

    function renderDonuts(donuts) {
        _lastDonuts = donuts;
        if (!donuts) return;
        renderDonut('donut-fact-canvas',  'donut-fact-legend',  donuts.facturacion ?? []);
        renderDonut('donut-promo-canvas', 'donut-promo-legend', donuts.promociones ?? []);
        renderDonut('donut-banco-canvas', 'donut-banco-legend', donuts.bancos      ?? []);
    }

    /* ── Custom searchable select (portado de dashboard.js del global) ── */
    function initSearchableSelect(selId) {
        const sel = $(selId);
        if (!sel || sel._ssInit) return;
        sel._ssInit = true;
        sel.style.display = 'none';

        const wrap = document.createElement('div');
        wrap.className = 'ss-wrap';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ss-btn';
        btn.innerHTML = `<span class="ss-txt">${sel.options[0]?.text ?? ''}</span><span class="ss-arrow">▾</span>`;
        wrap.insertBefore(btn, sel);

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
            const opts     = Array.from(sel.options);
            const filtered = q ? opts.filter(o => o.text.toLowerCase().includes(q.toLowerCase())) : opts;
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
            document.querySelectorAll('.ss-wrap.open').forEach(w => w.classList.remove('open'));
            if (opening) { wrap.classList.add('open'); buildList(''); input.value = ''; input.focus(); }
        });
        input.addEventListener('input', () => buildList(input.value.trim()));
        input.addEventListener('click', e => e.stopPropagation());
        document.addEventListener('click', () => { if (wrap.classList.contains('open')) wrap.classList.remove('open'); });

        sel._ssSync = () => {
            const opt = Array.from(sel.options).find(o => o.value === sel.value);
            btn.querySelector('.ss-txt').textContent = opt?.text ?? '';
        };
    }

    function syncSearchableSelect(selId) {
        const sel = $(selId);
        if (sel?._ssSync) sel._ssSync();
    }

    /* ── Carga de filtros ── */
    async function loadFilters() {
        try {
            const data = await apiFetch('filtros.php');
            if (!data.ok) return;

            if (Array.isArray(data.sucursales_activas)) {
                _sucursalesActivasIds = new Set(data.sucursales_activas.map(Number));
            }

            const fill = (selId, items, keyFn, labelFn, allLabel = 'Todas') => {
                const sel = $(selId);
                if (!sel) return;
                const cur = sel.value;
                sel.innerHTML = `<option value="">${allLabel}</option>` +
                    (items ?? []).map(it => {
                        const v = keyFn(it), l = labelFn(it);
                        return `<option value="${v}"${String(v) === cur ? ' selected' : ''}>${l}</option>`;
                    }).join('');
            };

            fill('sel-sucursal',
                data.sucursales,
                r => r.NRO_SUCURSAL ?? r.nro_sucursal,
                r => r.SUCURSAL     ?? r.sucursal ?? ('Suc. ' + (r.NRO_SUCURSAL ?? r.nro_sucursal))
            );
            fill('sel-banco',
                data.bancos,
                r => r.BANCO ?? r.banco,
                r => r.BANCO ?? r.banco,
                'Todos'
            );
            fill('sel-promocion',
                data.promociones,
                r => r.promocion ?? r.DESC_PROMOCION_TARJETA,
                r => r.promocion ?? r.DESC_PROMOCION_TARJETA,
                'Todas'
            );

            ['sel-sucursal', 'sel-banco', 'sel-promocion'].forEach(id => {
                initSearchableSelect(id);
                syncSearchableSelect(id);
            });

            /* Selector de promoción más ancho para nombres largos */
            $('sel-promocion')?.closest('.ss-wrap')?.classList.add('ss-wide');

        } catch (e) {
            console.error('[Promociones] loadFilters error:', e);
        }
    }

    /* ── Carga principal (el orquestador maneja el spinner global) ── */
    async function loadAll() {
        const errEl = $('promo-load-error');
        if (errEl) errEl.hidden = true;
        try {
            const [kpisData, bancosData, donutsData] = await Promise.all([
                apiFetch('kpis.php'),
                apiFetch('cards_bancos.php'),
                apiFetch('donuts.php'),
            ]);
            if (kpisData.ok)  { renderKpis(kpisData);  loadCotizacion(kpisData.periodo); }
            if (bancosData.ok) renderBancos(bancosData.bancos ?? []);
            if (donutsData.ok) renderDonuts(donutsData.donuts ?? {});
        } catch (e) {
            console.error('[Promociones] loadAll error:', e);
            if (errEl) { errEl.textContent = 'Error al cargar datos: ' + e.message; errEl.hidden = false; }
        }
    }

    /* ── Modales de ampliación de donuts ── */
    function initDonutModals() {
        const overlay  = $('modal-donut');
        const closeBtn = $('modal-donut-close');
        const titleEl  = $('modal-donut-title');

        const DONUT_META = {
            fact : { title: 'Desglose por Facturación', key: 'facturacion' },
            promo: { title: 'Desglose por Promociones',  key: 'promociones' },
            banco: { title: 'Desglose por Banco',        key: 'bancos'      },
        };

        document.querySelectorAll('.donut-expand-btn[data-donut]').forEach(btn => {
            btn.addEventListener('click', () => {
                const meta = DONUT_META[btn.dataset.donut];
                if (!meta || !overlay) return;
                const items = _lastDonuts?.[meta.key] ?? [];
                if (titleEl) titleEl.textContent = meta.title;
                renderDonut('donut-modal-canvas', 'donut-modal-legend', items);
                overlay.hidden = false;
            });
        });

        closeBtn?.addEventListener('click', () => { if (overlay) overlay.hidden = true; });
        overlay?.addEventListener('click', e => { if (e.target === overlay) overlay.hidden = true; });
    }

    return {
        loadAll,
        loadFilters,
        getParams,
        buildQS,
        apiFetch,
        convertir,
        convertirConFecha,
        getTCCParaMes,
        moneyPrefix,
        setLoading,
        fmt,
        initMonedaToggle,
        initDonutModals,
        initSearchableSelect,
        syncSearchableSelect,
        getMoneda        : () => _moneda,
        getLastPeriodo   : () => _lastPeriodo,
        getSucActivasIds : () => _sucursalesActivasIds,
        isSoloActivas,
    };
})();
