/**
 * /bi/velocidad/js/velocidad.js
 * Tablero "Velocidad de Ventas" (réplica web del informe de Power BI).
 * Las medidas se calculan en el servidor (VelocidadVentasDB); acá solo se muestran,
 * se ordenan, se pinta el semáforo y se arman los indicadores de la selección.
 */
const VelocidadVentas = (() => {
    'use strict';

    const CFG = window.VV_CONFIG || {};
    const BASE = CFG.base || '/bi/velocidad/ajax/';
    const IMG_BASE = CFG.imgBase || 'https://app.xl.com.ar/Imagenes/';

    /**
     * Configuración por pestaña.
     *  origen : tabla de rotación que se consulta (ar | uy)
     *  rank   : top = todos por ventas a 15 días · bottom = 50 con menos ventas a 15 días (+ empates)
     *  umbral : [rojo hasta, verde desde] → rojo ≤ u1 · amarillo entre u1 y u2 · verde ≥ u2
     *           Las 3 páginas del informe pintan con la misma medida [Color KPI % ABC] (30 % / 40 %).
     *           Ojo: la leyenda de texto del Ranking Negativo en Power BI dice 40 % / 50 %, pero no es
     *           lo que pinta; acá se usa la regla real de la medida.
     *  pctLoc : decimales de "% Venta / ingreso locales" (el formato cambia entre AR y UY en el informe)
     */
    const MODES = {
        ar:  { origen: 'ar', rank: 'top',    stock: true,  umbral: [0.30, 0.40], pctLoc: 1, central: '01',
               title: 'Ranking ventas Argentina · 15 días', sort: { k: 'vta_15', dir: -1 },
               podium: 'Más vendidos', foot: 'con ingreso a locales en el período' },
        uy:  { origen: 'uy', rank: 'top',    stock: false, umbral: [0.30, 0.40], pctLoc: 0, central: '82',
               title: 'Ranking ventas Uruguay · 15 días',   sort: { k: 'vta_15', dir: -1 },
               podium: 'Más vendidos', foot: 'con ingreso a locales en el período' },
        neg: { origen: 'ar', rank: 'bottom', stock: true,  umbral: [0.30, 0.40], pctLoc: 1, central: '01',
               title: 'Ranking 50 inferiores · 15 días',     sort: { k: 'pct_venta_15', dir: 1 },
               podium: 'Menos vendidos', foot: 'los 50 con menos venta a 15 días (empates incluidos)' },
    };

    const GROUPS = {
        art: 'Artículo', ing: 'Ingresos', acu: 'Venta acumulada',
        d15: '15 días', d30: '30 días', d60: '60 días', stk: 'Stock',
    };

    // Columnas de la tabla. `only` limita a ciertas pestañas (el Ranking Negativo es más angosto, igual que en el informe).
    const COLS = [
        { k: 'descripcion',               t: 'Modelo',                    f: 'art',    g: 'art' },
        { k: 'color',                     t: 'Color',                     f: 'txt',    g: 'art' },
        { k: 'ingreso_central',           t: 'Central',                   f: 'int',    g: 'ing', csv: 'Ingreso central' },
        { k: 'fecha_ing_central',         t: 'Fecha central',             f: 'date',   g: 'ing', only: ['ar', 'uy'], csv: 'Fecha ingreso central' },
        { k: 'fecha_ing_locales',         t: 'Fecha locales',             f: 'date',   g: 'ing', csv: 'Fecha ingreso locales' },
        { k: 'ingreso_locales',           t: 'Locales',                   f: 'int',    g: 'ing', csv: 'Ingreso locales' },
        { k: 'venta_total',               t: 'Venta total',               f: 'int',    g: 'acu', only: ['ar', 'uy'] },
        { k: 'pct_venta_ingreso_locales', t: '% Venta / ingreso locales', f: 'pctLoc', g: 'acu' },
        { k: 'vta_15',                    t: 'Venta',                     f: 'int',    g: 'd15', csv: 'Vta. 15 días' },
        { k: 'pct_venta_15',              t: 'Venta / ingreso',           f: 'sem',    g: 'd15', csv: 'Venta / ingreso 15 días' },
        { k: 'dias_stock',                t: 'Días de stock',             f: 'int',    g: 'd15' },
        { k: 'vta_30',                    t: 'Venta',                     f: 'int',    g: 'd30', csv: 'Vta. 30 días' },
        { k: 'pct_venta_30',              t: 'Venta / ingreso',           f: 'pct',    g: 'd30', csv: 'Venta / ingreso 30 días',
          tip: 'Venta de los primeros 30 días / unidades remitidas a locales propios desde el ingreso a central hasta el día 30.' },
        { k: 'vta_60',                    t: 'Venta',                     f: 'int',    g: 'd60', csv: 'Vta. 60 días' },
        { k: 'pct_venta_60',              t: 'Venta / ingreso',           f: 'pct',    g: 'd60', csv: 'Venta / ingreso 60 días',
          tip: 'Venta de los primeros 60 días / unidades remitidas a locales propios desde el ingreso a central hasta el día 60.' },
        { k: 'stock_locales',             t: 'Locales',                   f: 'int',    g: 'stk', csv: 'Stock locales' },
        { k: 'stock_central',             t: 'Central',                   f: 'int',    g: 'stk', csv: 'Stock central' },
    ];
    const TXT_KEYS = ['descripcion', 'color', 'modelo'];

    const $ = (id) => document.getElementById(id);
    const el = {
        body: document.body,
        title: $('vv-title'), caption: $('vv-caption'), count: $('vv-count'),
        updated: $('vv-updated'), error: $('vv-error'),
        tabs: Array.from(document.querySelectorAll('.vv-tab')),
        fLinea: $('f-linea'), fDesde: $('f-desde'), fHasta: $('f-hasta'),
        fRubro: $('f-rubro'), fCategoria: $('f-categoria'), fModelo: $('f-modelo'), fColor: $('f-color'),
        dlLinea: $('dl-linea'), dlModelo: $('dl-modelo'), btnClear: $('btn-clear'), btnCsv: $('btn-csv'),
        thead: $('vv-table').tHead, tbody: $('vv-table').tBodies[0],
        overlay: $('vv-overlay'), tableWrap: $('vv-table-wrap'),
        podium: $('vv-podium'), legend: $('vv-legend'),
        kModelos: $('k-modelos'), kModelosFoot: $('k-modelos-foot'), kVta15: $('k-vta15'), kVta15Foot: $('k-vta15-foot'),
        kPct15: $('k-pct15'), kPct15Foot: $('k-pct15-foot'), kDias: $('k-dias'),
        kSembar: $('k-sembar'), kSemchips: $('k-semchips'),
        viz: $('vv-viz'), vizImg: $('vv-viz-img'), vizEmpty: $('vv-viz-empty'),
        modal: $('vv-modal'), mViewer: $('vv-modal-viewer'), mImg: $('vv-modal-img'),
        mBody: $('vv-modal-body'), mZl: $('vv-modal-zl'), mClose: $('vv-modal-close'),
        mThumbs: $('vv-modal-thumbs'), mShop: $('vv-modal-shop'),
        dName: $('d-name'), dCode: $('d-code'), dStats: $('d-stats'),
        stock: $('vv-stock'), stockList: $('vv-stock-list'), stockTotal: $('vv-stock-total'), stockNa: $('vv-stock-na'),
    };

    const S = {
        mode: 'ar',
        rows: [],                // tal como vienen del servidor (con _rank)
        sort: { k: 'vta_15', dir: -1 },
        sem: null,               // filtro de semáforo local: null | 'g' | 'y' | 'r'
        selected: null,          // código de modelo seleccionado
        lists: {},               // listas de filtros por origen (cache en memoria)
        rankReq: 0,
    };

    // ── Utilidades ────────────────────────────────────────────

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    const num = (v, d = 0) => Number(v).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d });
    const pct = (v, d = 0) => num(v * 100, d) + ' %';
    const fdate = (v) => String(v).slice(8, 10) + '/' + String(v).slice(5, 7) + '/' + String(v).slice(0, 4);
    const isNull = (v) => v == null || v === '';
    const sum = (rows, k) => rows.reduce((a, r) => a + (Number(r[k]) || 0), 0);
    const div = (a, b) => (b ? a / b : null);        // DIVIDE() de DAX: vacío si el divisor es 0

    const SEM_ICON = { g: 'bi-arrow-up-short', y: 'bi-dash', r: 'bi-arrow-down-short' };
    const SEM_NAME = { g: 'Verde', y: 'Amarillo', r: 'Rojo' };

    /** Semáforo de la medida [Color KPI % ABC]: verde ≥ u2 · amarillo > u1 · rojo ≤ u1 · sin dato = null. */
    function semaforo(p, umbral = MODES[S.mode].umbral) {
        if (isNull(p)) return null;
        if (p >= umbral[1]) return 'g';
        if (p > umbral[0]) return 'y';
        return 'r';
    }
    const semDot = (s) => '<span class="vv-dot ' + s + '" aria-hidden="true"><i class="bi ' + SEM_ICON[s] + '"></i></span>';

    function fmtCell(col, v) {
        if (isNull(v)) return '';
        switch (col.f) {
            case 'int':    return num(v, 0);
            case 'pctLoc': return pct(v, MODES[S.mode].pctLoc);
            case 'pct':    return pct(v);
            case 'date':   return fdate(v);
            case 'delta': {
                const cls = v > 0 ? 'up' : v < 0 ? 'down' : '';
                const ar = v > 0 ? '▲ ' : v < 0 ? '▼ ' : '';
                return '<span class="vv-delta ' + cls + '">' + ar + pct(Math.abs(v)) + '</span>';
            }
            case 'sem': {
                const s = semaforo(v);
                return '<span class="vv-sem ' + s + '" title="' + SEM_NAME[s] + '">' + semDot(s) + pct(v) + '</span>';
            }
            default:       return esc(v);
        }
    }

    const cols = () => COLS.filter((c) => !c.only || c.only.includes(S.mode));
    const colTitle = (c) => (c.k === 'stock_central' ? 'Central (' + MODES[S.mode].central + ')' : c.t);

    // ── Imágenes ──────────────────────────────────────────────
    // 1) La misma URL que usa el informe de Power BI · 2) el proxy local a W:\Imagenes
    //    (resuelve variantes de temporada/color) · 3) un marcador gris.
    const imgUrl = (cod) => IMG_BASE + encodeURIComponent(cod) + '.jpg';
    const proxyUrl = (cod) => BASE + 'imagen.php?articulo=' + encodeURIComponent(cod);
    const PLACEHOLDER = 'data:image/svg+xml,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><rect width="48" height="48" fill="#f7f8fc"/>' +
        '<path d="M14 32l7-9 5 6 4-4 6 7z" fill="#cfd5e3"/><circle cx="31" cy="17" r="3" fill="#cfd5e3"/></svg>');
    const imgTag = (cod, cls) => '<img class="' + cls + ' vv-img" loading="lazy" decoding="async" alt="" data-cod="' + esc(cod) + '" src="' + imgUrl(cod) + '">';

    document.addEventListener('error', (ev) => {
        const img = ev.target;
        if (!(img instanceof HTMLImageElement) || !img.classList.contains('vv-img')) return;
        const cod = img.dataset.cod;
        if (!img.dataset.fb && cod) { img.dataset.fb = '1'; img.src = proxyUrl(cod); return; }
        if (img.dataset.fb === '1') { img.dataset.fb = '2'; img.src = PLACEHOLDER; }
    }, true);

    // ── Red ───────────────────────────────────────────────────

    // Cada tipo de consulta tiene su propio AbortController: una respuesta vieja nunca pisa a una nueva.
    const inflight = {};
    async function getJson(kind, file, params) {
        if (inflight[kind]) inflight[kind].abort();
        const ctl = (inflight[kind] = new AbortController());
        const url = BASE + file + (params ? '?' + params.toString() : '');
        let res;
        try {
            res = await fetch(url, { signal: ctl.signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        } catch (e) {
            if (e.name === 'AbortError') throw e;
            throw new Error('No se pudo conectar con el servidor.');
        }
        let json = null;
        try { json = await res.json(); } catch (_) { /* respuesta no JSON */ }
        if (!res.ok || !json || json.ok === false) {
            const msg = (json && json.error) || (res.status === 401 ? 'Sesión expirada. Volvé a iniciar sesión.' : 'Error ' + res.status);
            throw new Error(msg);
        }
        return json;
    }

    function showError(msg) {
        el.error.textContent = msg;
        el.error.hidden = !msg;
    }

    function debounce(fn, ms) {
        let t;
        return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
    }

    // ── Filtros ───────────────────────────────────────────────

    function filtros() {
        return {
            desde: el.fDesde.value, hasta: el.fHasta.value,
            rubro: el.fRubro.value, categoria: el.fCategoria.value,
            color: el.fColor.value, linea: el.fLinea.value.trim(), modelo: el.fModelo.value.trim(),
        };
    }

    function rankingParams() {
        const m = MODES[S.mode];
        const p = new URLSearchParams({ origen: m.origen, modo: m.rank });
        const f = filtros();
        Object.keys(f).forEach((k) => { if (f[k]) p.set(k, f[k]); });
        return p;
    }

    function markSetFields() {
        [el.fLinea, el.fRubro, el.fCategoria, el.fModelo, el.fColor].forEach((c) => {
            c.closest('.vv-field').classList.toggle('is-set', !!c.value.trim());
        });
        const dChanged = el.fDesde.value !== el.fDesde.dataset.default || el.fHasta.value !== el.fHasta.dataset.default;
        el.fDesde.closest('.vv-field').classList.toggle('is-set', dChanged);
    }

    function fillSelect(sel, values, todas) {
        const cur = sel.value;
        sel.innerHTML = '<option value="">' + esc(todas) + '</option>' +
            values.map((v) => '<option value="' + esc(v) + '">' + esc(v) + '</option>').join('');
        sel.value = values.includes(cur) ? cur : '';
    }

    function fillCategorias() {
        const l = S.lists[MODES[S.mode].origen];
        if (!l) return;
        const rubro = el.fRubro.value;
        const set = new Set();
        l.categorias.forEach((c) => { if (!rubro || c.rubro === rubro) set.add(c.categoria); });
        fillSelect(el.fCategoria, Array.from(set).sort((a, b) => a.localeCompare(b, 'es')), 'Todas');
    }

    function applyLists(origen) {
        const l = S.lists[origen];
        if (!l) return;
        fillSelect(el.fRubro, l.rubros, 'Todos');
        fillSelect(el.fColor, l.colores, 'Todos');
        fillCategorias();
        el.dlLinea.innerHTML = l.lineas.map((v) => '<option value="' + esc(v) + '"></option>').join('');
        el.dlModelo.innerHTML = l.modelos.map((m) =>
            '<option value="' + esc(m.modelo) + '" label="' + esc(m.descripcion || '') + '"></option>').join('');
    }

    async function loadLists(origen) {
        if (S.lists[origen]) { applyLists(origen); return; }
        try {
            const j = await getJson('filtros', 'filtros.php', new URLSearchParams({ origen }));
            S.lists[origen] = j.data;
            applyLists(origen);
        } catch (e) {
            if (e.name !== 'AbortError') showError('No se pudieron cargar los filtros: ' + e.message);
        }
    }

    function resetSelectionFilters() {
        el.fRubro.value = ''; el.fCategoria.value = ''; el.fColor.value = '';
        el.fLinea.value = ''; el.fModelo.value = '';
    }

    // ── Indicadores ───────────────────────────────────────────

    function renderKpis() {
        const rows = S.rows;
        const m = MODES[S.mode];
        const v15 = sum(rows, 'vta_15');
        const p15 = div(v15, sum(rows, 'cant_primer_ingreso'));          // [% Venta 15 dias] sin filtro de modelo
        const dias = div(sum(rows, 'ingreso_locales'), v15 / 15);        // [Días de stock]  sin filtro de modelo

        el.kModelos.textContent = rows.length ? num(rows.length) : '—';
        el.kModelosFoot.textContent = m.foot;
        el.kVta15.textContent = rows.length ? num(v15) : '—';
        el.kVta15Foot.textContent = rows.length ? '30 días: ' + num(sum(rows, 'vta_30')) + ' · 60 días: ' + num(sum(rows, 'vta_60')) : '\u00a0';
        const s = semaforo(p15);
        el.kPct15.innerHTML = p15 == null ? '—' : semDot(s) + esc(pct(p15, 1));
        el.kPct15Foot.textContent = p15 == null ? '\u00a0' : 'sobre ' + num(sum(rows, 'cant_primer_ingreso')) + ' u. de primer ingreso';
        el.kDias.textContent = dias == null ? '—' : num(dias);

        const cnt = { g: 0, y: 0, r: 0, n: 0 };
        rows.forEach((r) => { cnt[semaforo(r.pct_venta_15) || 'n']++; });
        const tot = rows.length || 1;
        el.kSembar.innerHTML = ['g', 'y', 'r'].map((k) =>
            '<span style="flex-grow:' + cnt[k] + ';background:var(--vv-' + ({ g: 'good', y: 'warn', r: 'crit' })[k] + ')" title="' +
            SEM_NAME[k] + ': ' + cnt[k] + ' (' + pct(cnt[k] / tot) + ')"></span>').join('') +
            (cnt.n ? '<span style="flex-grow:' + cnt.n + ';background:var(--vv-line)" title="Sin dato: ' + cnt.n + '"></span>' : '');
        el.kSemchips.classList.toggle('has-on', !!S.sem);
        el.kSemchips.innerHTML = ['g', 'y', 'r'].map((k) =>
            '<button type="button" class="vv-semchip' + (S.sem === k ? ' is-on' : '') + '" data-sem="' + k + '" aria-pressed="' + (S.sem === k) + '">' +
            semDot(k) + SEM_NAME[k] + ' <b>' + num(cnt[k]) + '</b></button>').join('') +
            (cnt.n ? '<span class="vv-kpi-foot">Sin dato: ' + num(cnt.n) + '</span>' : '');
    }

    function renderLegend() {
        const [u1, u2] = MODES[S.mode].umbral;
        const p1 = Math.round(u1 * 100), p2 = Math.round(u2 * 100);
        const next = (p1 + 0.1).toFixed(1).replace('.', ',');
        el.legend.innerHTML =
            '<span class="vv-legend-title">Venta / ingreso 15 días</span>' +
            '<span>' + semDot('r') + 'Menor o igual al ' + p1 + ' %</span>' +
            '<span>' + semDot('y') + 'Mayor o igual al ' + next + ' % y menor al ' + p2 + ' %</span>' +
            '<span>' + semDot('g') + 'Mayor o igual al ' + p2 + ' %</span>';
    }

    // ── Destacados ────────────────────────────────────────────

    function renderPodium() {
        const m = MODES[S.mode];
        // Igual que el visual de fotos del informe: top 5 por [15 Dias] (desc), o bottom 5 en el Negativo.
        const list = S.rows.slice().sort((a, b) => {
            const d = (Number(a.vta_15) || 0) - (Number(b.vta_15) || 0);
            return (m.rank === 'bottom' ? d : -d) || (a.modelo < b.modelo ? -1 : 1);
        }).slice(0, 5);
        el.podium.innerHTML = list.map((r, i) =>
            '<button type="button" class="vv-pod' + (r.modelo === S.selected ? ' is-sel' : '') + '" data-modelo="' + esc(r.modelo) + '" title="' + esc(r.descripcion + ' · ' + r.color + ' (' + r.modelo + ')') + '">' +
            imgTag(r.modelo, 'vv-pod-img') +
            '<span class="vv-pod-txt"><span class="vv-pod-rank">' + esc(m.podium) + ' #' + (i + 1) + '</span>' +
            '<span class="vv-pod-name">' + esc(r.descripcion) + '</span>' +
            '<span class="vv-pod-val">' + num(r.vta_15 || 0) + ' u. en 15 días</span></span></button>').join('');
        el.podium.hidden = !list.length;
    }

    // ── Tabla ─────────────────────────────────────────────────

    function visibleRows() {
        const { k, dir } = S.sort;
        const base = S.sem ? S.rows.filter((r) => semaforo(r.pct_venta_15) === S.sem) : S.rows;
        return base.slice().sort((a, b) => {
            const va = a[k], vb = b[k];
            const na = isNull(va), nb = isNull(vb);
            if (na && nb) return a._rank - b._rank;
            if (na) return 1;       // los vacíos siempre al final
            if (nb) return -1;
            const c = (typeof va === 'string') ? va.localeCompare(vb, 'es') : va - vb;
            return c === 0 ? a._rank - b._rank : c * dir;
        });
    }

    function renderHead() {
        const cs = cols();
        // fila de grupos
        const grp = [];
        cs.forEach((c) => {
            const last = grp[grp.length - 1];
            if (last && last.g === c.g) last.n++; else grp.push({ g: c.g, n: 1 });
        });
        const sortedGroup = (cs.find((c) => c.k === S.sort.k) || {}).g;
        const rowGrp = '<tr class="grp">' + grp.map((g, i) =>
            '<th colspan="' + g.n + '" class="' + (i === 0 ? 'c-art ' : '') + (g.g === sortedGroup ? 'g-on' : '') + '">' + esc(GROUPS[g.g]) + '</th>').join('') + '</tr>';

        let prevG = null;
        const rowCols = '<tr class="cols">' + cs.map((c) => {
            const isSort = S.sort.k === c.k;
            const arrow = isSort ? '<span class="arrow">' + (S.sort.dir === 1 ? '▲' : '▼') + '</span>' : '';
            const cls = ['sortable'];
            if (c.f === 'art') cls.push('c-art');
            if (!['art', 'txt', 'date'].includes(c.f)) cls.push('num');
            if (prevG !== null && prevG !== c.g) cls.push('g-start');
            if (isSort) cls.push('is-sorted');
            if (c.tip) cls.push('has-tip');
            prevG = c.g;
            return '<th class="' + cls.join(' ') + '" data-k="' + c.k + '" scope="col"' +
                (c.tip ? ' title="' + esc(c.tip) + '"' : '') + ' aria-sort="' +
                (isSort ? (S.sort.dir === 1 ? 'ascending' : 'descending') : 'none') + '">' + esc(colTitle(c)) +
                (c.tip ? ' <i class="bi bi-info-circle vv-th-tip" aria-hidden="true"></i>' : '') + arrow + '</th>';
        }).join('') + '</tr>';

        el.thead.innerHTML = rowGrp + rowCols;
    }

    function renderBody() {
        const cs = cols();
        const rows = visibleRows();

        if (!rows.length) {
            el.tbody.innerHTML = '<tr><td class="vv-empty" colspan="' + cs.length + '"><i class="bi bi-inboxes"></i>' +
                (S.rows.length ? 'Ningún modelo en ' + SEM_NAME[S.sem].toLowerCase() + ' con estos filtros.' : 'Sin resultados para los filtros elegidos.') + '</td></tr>';
            return rows;
        }

        el.tbody.innerHTML = rows.map((r) => {
            let prevG = null;
            const tds = cs.map((c) => {
                const cls = [];
                if (c.f === 'art') cls.push('c-art');
                else if (c.f === 'date') cls.push('dim');
                else if (c.f !== 'txt') cls.push('num');
                if (prevG !== null && prevG !== c.g) cls.push('g-start');
                prevG = c.g;
                if (c.f === 'art') {
                    return '<td class="' + cls.join(' ') + '"><div class="vv-art"><span class="vv-rank">' + r._rank + '</span>' +
                        imgTag(r.modelo, 'vv-art-img') +
                        '<div class="vv-art-txt"><div class="vv-art-name">' + esc(r.descripcion) + '</div>' +
                        '<div class="vv-art-code">' + esc(r.modelo) + '</div></div></div></td>';
                }
                return '<td class="' + cls.join(' ') + '">' + fmtCell(c, r[c.k]) + '</td>';
            }).join('');
            const sel = r.modelo === S.selected;
            return '<tr data-modelo="' + esc(r.modelo) + '" tabindex="' + (sel ? '0' : '-1') + '"' + (sel ? ' class="is-sel" aria-selected="true"' : '') + '>' + tds + '</tr>';
        }).join('');
        return rows;
    }

    function renderCaption(shown) {
        // Réplica de la medida [Título 1]: rubro y categoría elegidos (vacío si no hay uno solo).
        const parts = [];
        if (el.fRubro.value) parts.push('Rubro: ' + el.fRubro.value);
        if (el.fCategoria.value) parts.push('Categoría: ' + el.fCategoria.value);
        if (el.fLinea.value.trim()) parts.push('Línea: ' + el.fLinea.value.trim());
        if (el.fColor.value) parts.push('Color: ' + el.fColor.value);
        parts.push('Ingreso a locales ' + (el.fDesde.value ? fdate(el.fDesde.value) : '…') + ' – ' + (el.fHasta.value ? fdate(el.fHasta.value) : '…'));
        el.caption.textContent = parts.join(' · ');
        const n = S.rows.length;
        el.count.textContent = !n ? 'Sin modelos'
            : S.sem ? num(shown) + ' de ' + num(n) + ' modelos'
            : num(n) + (n === 1 ? ' modelo' : ' modelos');
    }

    function renderTable() {
        renderHead();
        const shown = renderBody();
        renderCaption(shown.length);
        renderKpis();
        return shown;
    }

    function setLoading(on) {
        el.overlay.hidden = !on;
        el.body.classList.toggle('is-loading', on);
    }

    function fmtUpdated(s) {
        // 'YYYY-MM-DD HH:MM' -> 'DD/MM/YYYY HH:MM'
        if (!s) return null;
        return fdate(s.slice(0, 10)) + ' ' + s.slice(11, 16);
    }

    async function loadRanking() {
        const my = ++S.rankReq;      // solo la última consulta puede apagar el "Cargando…"
        showError('');
        setLoading(true);
        markSetFields();
        try {
            const j = await getJson('ranking', 'ranking.php', rankingParams());
            S.rows = (j.data || []).map((r, i) => Object.assign(r, { _rank: i + 1 }));
            el.updated.textContent = fmtUpdated(j.actualizado) ||
                ('consulta de las ' + new Date().toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', hour12: false }));
            const shown = renderTable();
            renderPodium();
            // Si el modelo elegido ya no está en el resultado, seleccionar el primero.
            if (shown.length && !shown.some((r) => r.modelo === S.selected)) {
                selectModelo(shown[0].modelo, false);
            } else if (!shown.length) {
                clearSelection();
            } else {
                selectModelo(S.selected, false);
            }
        } catch (e) {
            if (e.name === 'AbortError') return;
            S.rows = [];
            renderTable();
            renderPodium();
            clearSelection();
            showError('No se pudo cargar el ranking: ' + e.message);
        } finally {
            if (my === S.rankReq) setLoading(false);
        }
    }

    // ── Selección: imagen + detalle + stock ───────────────────

    function markSelected() {
        el.tbody.querySelectorAll('tr.is-sel').forEach((tr) => {
            tr.classList.remove('is-sel'); tr.removeAttribute('aria-selected'); tr.tabIndex = -1;
        });
        el.podium.querySelectorAll('.is-sel').forEach((b) => b.classList.remove('is-sel'));
        if (!S.selected) return null;
        const q = '[data-modelo="' + (window.CSS && CSS.escape ? CSS.escape(S.selected) : S.selected) + '"]';
        const tr = el.tbody.querySelector('tr' + q);
        if (tr) { tr.classList.add('is-sel'); tr.setAttribute('aria-selected', 'true'); tr.tabIndex = 0; }
        const pod = el.podium.querySelector(q);
        if (pod) pod.classList.add('is-sel');
        return tr;
    }

    function clearSelection() {
        S.selected = null;
        el.vizImg.hidden = true; el.vizImg.removeAttribute('src');
        el.vizEmpty.hidden = false;
        el.dName.textContent = '—';
        el.dCode.textContent = '';
        el.dStats.innerHTML = '';
        el.stockTotal.textContent = '';
        el.stockList.innerHTML = '';
    }

    function renderDetail(r) {
        const m = MODES[S.mode];
        el.dName.textContent = r ? r.descripcion : S.selected;
        el.dCode.textContent = r ? (r.modelo + (r.color ? ' · ' + r.color : '')) : '';
        if (!r) { el.dStats.innerHTML = ''; return; }
        const s = semaforo(r.pct_venta_15);
        const stat = (t, v) => '<div><dt>' + esc(t) + '</dt><dd>' + (v === '' || v == null ? '—' : v) + '</dd></div>';
        el.dStats.innerHTML =
            stat('Venta 15 d', isNull(r.vta_15) ? '' : num(r.vta_15)) +
            stat('Vta/ingreso 15 d', s ? semDot(s) + pct(r.pct_venta_15) : '') +
            stat('Días de stock', isNull(r.dias_stock) ? '' : num(r.dias_stock)) +
            stat('Vta/ingreso 30 d', fmtCell({ f: 'pct' }, r.pct_venta_30)) +
            stat('Vta/ingreso 60 d', fmtCell({ f: 'pct' }, r.pct_venta_60)) +
            stat('Ing. locales', isNull(r.ingreso_locales) ? '' : num(r.ingreso_locales)) +
            stat('Stock locales', isNull(r.stock_locales) ? '' : num(r.stock_locales)) +
            stat('Stock central ' + m.central, isNull(r.stock_central) ? '' : num(r.stock_central)) +
            stat('Llegó a locales', isNull(r.fecha_ing_locales) ? '' : fdate(r.fecha_ing_locales));
    }

    function selectModelo(modelo, scroll) {
        S.selected = modelo;
        const tr = markSelected();
        if (tr && scroll) tr.scrollIntoView({ block: 'nearest' });
        const r = S.rows.find((x) => x.modelo === modelo);

        if (el.vizImg.dataset.cod !== modelo || el.vizImg.hidden) {
            el.vizEmpty.hidden = true;
            el.vizImg.hidden = false;
            el.vizImg.alt = r ? 'Foto de ' + r.descripcion : 'Foto del modelo';
            el.vizImg.dataset.cod = modelo;
            delete el.vizImg.dataset.fb;
            el.vizImg.src = imgUrl(modelo);
        }
        renderDetail(r);

        const m = MODES[S.mode];
        el.stock.hidden = !m.stock;
        el.stockNa.hidden = m.stock;
        if (m.stock) loadStock(modelo);
    }

    async function loadStock(modelo) {
        el.stockTotal.textContent = '';
        el.stockList.innerHTML = '<li class="s-msg">Cargando stock…</li>';
        try {
            const j = await getJson('stock', 'stock.php', new URLSearchParams({ modelo }));
            if (S.selected !== modelo) return;       // el usuario ya eligió otro
            const d = j.data;
            const max = Math.max(1, ...d.sucursales.map((s) => Number(s.stock) || 0));
            el.stockTotal.textContent = 'Total ' + num(d.total || 0) + ' · ' + d.sucursales.length + ' suc.';
            el.stockList.innerHTML = d.sucursales.length
                ? d.sucursales.map((s) => {
                    const v = Number(s.stock) || 0;
                    return '<li title="' + esc(s.sucursal + ': ' + num(v)) + '"><span class="s-name">' + esc(s.sucursal) + '</span>' +
                        '<span class="s-bar" aria-hidden="true"><i style="width:' + Math.max(0, v / max * 100).toFixed(1) + '%"></i></span>' +
                        '<span class="s-val">' + num(v) + '</span></li>';
                }).join('')
                : '<li class="s-msg">Sin stock en locales.</li>';
        } catch (e) {
            if (e.name === 'AbortError') return;
            el.stockList.innerHTML = '<li class="s-msg">No se pudo cargar el stock: ' + esc(e.message) + '</li>';
        }
        syncModal();
    }

    // ── Modal: foto ampliable + detalle ───────────────────────

    const Z = { s: 1, x: 0, y: 0, min: 1, max: 6 };

    function applyZoom() {
        if (Z.s <= Z.min) { Z.s = Z.min; Z.x = 0; Z.y = 0; }
        // Limitar el desplazamiento para no "perder" la foto fuera del visor.
        const w = el.mViewer.clientWidth, h = el.mViewer.clientHeight;
        const mx = (w * (Z.s - 1)) / 2, my = (h * (Z.s - 1)) / 2;
        Z.x = Math.max(-mx, Math.min(mx, Z.x));
        Z.y = Math.max(-my, Math.min(my, Z.y));
        el.mImg.style.transform = 'translate(' + Z.x + 'px,' + Z.y + 'px) scale(' + Z.s + ')';
        el.mZl.textContent = Math.round(Z.s * 100) + ' %';
        el.mViewer.classList.toggle('is-zoomed', Z.s > 1);
    }

    // Zoom hacia un punto (cx, cy relativos al centro del visor) para que ese punto quede fijo.
    function zoomTo(s, cx, cy) {
        s = Math.max(Z.min, Math.min(Z.max, s));
        const k = s / Z.s;
        Z.x = cx - (cx - Z.x) * k;
        Z.y = cy - (cy - Z.y) * k;
        Z.s = s;
        applyZoom();
    }

    function viewerPoint(ev) {
        const r = el.mViewer.getBoundingClientRect();
        return [ev.clientX - r.left - r.width / 2, ev.clientY - r.top - r.height / 2];
    }

    // Copia el detalle del panel (nombre, stats y stock) al modal, sin ids duplicados.
    function syncModal() {
        if (!el.modal.open) return;
        const body = el.dName.parentElement.cloneNode(true);
        body.querySelectorAll('[id]').forEach((n) => n.removeAttribute('id'));
        const name = body.querySelector('.vv-detail-name');
        if (name) name.id = 'vv-modal-title';
        el.mBody.replaceChildren(...body.childNodes);
    }

    function openModal() {
        if (!S.selected || el.vizImg.hidden) return;
        // Misma cadena de respaldo que el panel (clase vv-img + data-cod → proxy → marcador).
        el.mImg.dataset.cod = S.selected;
        if (el.vizImg.dataset.fb) el.mImg.dataset.fb = el.vizImg.dataset.fb; else delete el.mImg.dataset.fb;
        el.mImg.src = el.vizImg.currentSrc || el.vizImg.src;
        el.mImg.alt = el.vizImg.alt;
        el.mThumbs.hidden = true; el.mThumbs.innerHTML = '';
        el.mShop.hidden = true;
        Z.s = 1; Z.x = 0; Z.y = 0;
        el.modal.showModal();
        applyZoom();
        syncModal();
        el.mClose.focus();
        loadFotosHd(S.selected);
    }

    // Fotos en alta desde el catálogo de la tienda (VTEX). La de /Imagenes es de 300x300:
    // se muestra al instante y se reemplaza cuando la de alta terminó de bajar.
    const fotosCache = {};

    async function loadFotosHd(modelo) {
        let d = fotosCache[modelo];
        if (!d) {
            try {
                d = (await getJson('fotos', 'fotos.php', new URLSearchParams({ modelo }))).data;
                fotosCache[modelo] = d;
            } catch (e) {
                return;   // sin fotos en alta: queda la de siempre
            }
        }
        if (!el.modal.open || S.selected !== modelo) return;
        if (d.link) { el.mShop.href = d.link; el.mShop.hidden = false; }
        if (!d.fotos || !d.fotos.length) return;
        if (d.fotos.length > 1) {
            el.mThumbs.innerHTML = d.fotos.map((u, i) =>
                '<button type="button" data-i="' + i + '" title="Foto ' + (i + 1) + '"><img src="' + esc(thumbUrl(u)) + '" alt=""></button>').join('');
            el.mThumbs.hidden = false;
        }
        showFotoHd(modelo, d.fotos, 0);
    }

    // VTEX redimensiona con /ids/{id}-{ancho}-{alto}/ ; sin tamaño devuelve la original.
    const thumbUrl = (u) => u.replace(/(\/arquivos\/ids\/\d+)\//, '$1-120-150/');

    function showFotoHd(modelo, fotos, i) {
        el.mThumbs.querySelectorAll('button').forEach((b) => b.classList.toggle('is-on', Number(b.dataset.i) === i));
        const pre = new Image();
        pre.onload = () => {
            if (!el.modal.open || S.selected !== modelo) return;
            el.mImg.dataset.fb = '2';   // si fallara, directo al marcador
            el.mImg.src = pre.src;
            Z.s = 1; applyZoom();
        };
        pre.src = fotos[i];
    }

    function bindModal() {
        el.viz.addEventListener('click', openModal);
        el.viz.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openModal(); }
        });
        el.mClose.addEventListener('click', () => el.modal.close());
        // Click en el fondo (fuera del contenido) cierra.
        el.modal.addEventListener('click', (ev) => { if (ev.target === el.modal) el.modal.close(); });

        el.mThumbs.addEventListener('click', (ev) => {
            const b = ev.target.closest('button[data-i]');
            const d = fotosCache[S.selected];
            if (b && d) showFotoHd(S.selected, d.fotos, Number(b.dataset.i));
        });
        el.modal.querySelector('.vv-modal-tools').addEventListener('click', (ev) => {
            const b = ev.target.closest('button[data-zoom]');
            if (!b) return;
            if (b.dataset.zoom === 'reset') { Z.s = 1; applyZoom(); return; }
            zoomTo(Z.s * (b.dataset.zoom === 'in' ? 1.5 : 1 / 1.5), 0, 0);
        });
        el.mViewer.addEventListener('wheel', (ev) => {
            ev.preventDefault();
            const [cx, cy] = viewerPoint(ev);
            zoomTo(Z.s * (ev.deltaY < 0 ? 1.2 : 1 / 1.2), cx, cy);
        }, { passive: false });
        el.mImg.addEventListener('dblclick', (ev) => {
            const [cx, cy] = viewerPoint(ev);
            if (Z.s > 1) { Z.s = 1; applyZoom(); } else zoomTo(2.5, cx, cy);
        });

        // Arrastrar para mover la foto ampliada (mouse y touch).
        let drag = null;
        el.mImg.addEventListener('pointerdown', (ev) => {
            if (Z.s <= 1) return;
            drag = { px: ev.clientX, py: ev.clientY, x: Z.x, y: Z.y };
            el.mImg.setPointerCapture(ev.pointerId);
            el.mViewer.classList.add('is-dragging');
        });
        el.mImg.addEventListener('pointermove', (ev) => {
            if (!drag) return;
            Z.x = drag.x + ev.clientX - drag.px;
            Z.y = drag.y + ev.clientY - drag.py;
            applyZoom();
        });
        const endDrag = () => { drag = null; el.mViewer.classList.remove('is-dragging'); };
        el.mImg.addEventListener('pointerup', endDrag);
        el.mImg.addEventListener('pointercancel', endDrag);
        window.addEventListener('resize', () => { if (el.modal.open) applyZoom(); });
    }

    // ── Exportar ──────────────────────────────────────────────

    function exportCsv() {
        const cs = cols();
        const rows = visibleRows();
        if (!rows.length) return;
        const q = (v) => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
        const val = (c, v) => {
            if (isNull(v)) return '';
            if (c.f === 'date') return fdate(v);
            if (['pctLoc', 'sem', 'delta', 'pct'].includes(c.f)) return num(v * 100, 2);
            if (c.f === 'int') return num(v, 2).replace(/\./g, '');
            return v;
        };
        const head = ['Posición', 'Modelo', 'Descripción'].concat(cs.filter((c) => c.f !== 'art').map((c) =>
            c.k === 'stock_central' ? 'Stock central (' + MODES[S.mode].central + ')' : (c.csv || c.t) + (['pctLoc', 'sem', 'delta', 'pct'].includes(c.f) ? ' (%)' : '')));
        const lines = [head.map(q).join(';')].concat(rows.map((r) =>
            [r._rank, r.modelo, r.descripcion].concat(cs.filter((c) => c.f !== 'art').map((c) => val(c, r[c.k]))).map(q).join(';')));
        const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'velocidad-ventas-' + S.mode + '-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    }

    // ── Pestañas ──────────────────────────────────────────────

    async function setMode(mode) {
        if (!MODES[mode]) return;
        const prevOrigen = MODES[S.mode].origen;
        S.mode = mode;
        const m = MODES[mode];

        el.body.dataset.mode = mode;
        el.title.textContent = m.title;
        try { history.replaceState(null, '', mode === 'ar' ? location.pathname + location.search : '#' + mode); } catch (_) { /* file:// u otros */ }
        el.tabs.forEach((t) => {
            const on = t.dataset.mode === mode;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        S.sort = Object.assign({}, m.sort);
        S.sem = null;
        S.rows = [];
        clearSelection();
        el.stock.hidden = !m.stock;
        el.stockNa.hidden = m.stock;
        renderTable();
        renderPodium();
        renderLegend();

        if (m.origen !== prevOrigen) resetSelectionFilters();   // las listas de AR y UY son distintas
        await loadLists(m.origen);
        loadRanking();
    }

    // ── Eventos ───────────────────────────────────────────────

    function bind() {
        el.tabs.forEach((t) => t.addEventListener('click', () => { if (t.dataset.mode !== S.mode) setMode(t.dataset.mode); }));

        el.thead.addEventListener('click', (ev) => {
            const th = ev.target.closest('th[data-k]');
            if (!th) return;
            const k = th.dataset.k;
            S.sort = (S.sort.k === k) ? { k, dir: -S.sort.dir } : { k, dir: TXT_KEYS.includes(k) ? 1 : -1 };
            renderHead();
            renderBody();
            markSelected();
        });

        el.tbody.addEventListener('click', (ev) => {
            const tr = ev.target.closest('tr[data-modelo]');
            if (tr) selectModelo(tr.dataset.modelo, false);
        });
        // Flechas arriba/abajo recorren la tabla.
        el.tbody.addEventListener('keydown', (ev) => {
            const tr = ev.target.closest('tr[data-modelo]');
            if (!tr) return;
            let next = null;
            if (ev.key === 'ArrowDown') next = tr.nextElementSibling;
            else if (ev.key === 'ArrowUp') next = tr.previousElementSibling;
            else if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); selectModelo(tr.dataset.modelo, false); return; }
            if (next && next.dataset.modelo) {
                ev.preventDefault();
                selectModelo(next.dataset.modelo, true);
                next.focus({ preventScroll: true });
            }
        });
        el.podium.addEventListener('click', (ev) => {
            const b = ev.target.closest('.vv-pod');
            if (b) selectModelo(b.dataset.modelo, true);
        });
        el.kSemchips.addEventListener('click', (ev) => {
            const b = ev.target.closest('.vv-semchip');
            if (!b) return;
            S.sem = S.sem === b.dataset.sem ? null : b.dataset.sem;
            const shown = renderTable();
            const still = shown.some((r) => r.modelo === S.selected);
            if (shown.length && !still) selectModelo(shown[0].modelo, false); else markSelected();
        });

        const reload = debounce(loadRanking, 250);
        [el.fDesde, el.fHasta, el.fRubro, el.fColor, el.fCategoria].forEach((c) => c.addEventListener('change', reload));
        el.fRubro.addEventListener('change', fillCategorias);
        // Línea y Modelo son combos con búsqueda: aplican al confirmar (elegir de la lista, Enter o salir del campo).
        [el.fLinea, el.fModelo].forEach((c) => c.addEventListener('change', reload));

        el.btnClear.addEventListener('click', () => {
            resetSelectionFilters();
            el.fDesde.value = el.fDesde.dataset.default || '';
            el.fHasta.value = el.fHasta.dataset.default || '';
            S.sem = null;
            fillCategorias();
            loadRanking();
        });
        el.btnCsv.addEventListener('click', exportCsv);
        bindModal();
    }

    function init() {
        bind();
        // Enlace directo a una pestaña: index.php#uy · index.php#neg
        const h = location.hash.replace('#', '');
        setMode(MODES[h] ? h : 'ar');
    }

    init();
    return { setMode, reload: loadRanking };
})();
