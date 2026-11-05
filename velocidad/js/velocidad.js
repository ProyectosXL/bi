/**
 * /bi/velocidad/js/velocidad.js
 * Tablero "Velocidad de Ventas" (réplica web del informe de Power BI).
 * Las medidas se calculan en el servidor (VelocidadVentasDB); acá solo se muestran,
 * se ordenan y se pinta el semáforo.
 */
const VelocidadVentas = (() => {
    'use strict';

    const BASE = (window.VV_CONFIG && window.VV_CONFIG.base) || '/bi/velocidad/ajax/';

    // Colores del semáforo: los mismos hex que la medida DAX [Color KPI % ABC].
    const COLOR = { rojo: '#FF4500', amarillo: '#FFFF00', verde: '#3CB371' };

    /**
     * Configuración por pestaña.
     *  origen : tabla de rotación que se consulta (ar | uy)
     *  rank   : top = por ventas a 15 días · bottom = 50 peores por Venta/ingreso 15 días
     *  umbral : [rojo hasta, verde desde]  → rojo ≤ u1 · amarillo entre u1 y u2 · verde ≥ u2
     *  pctLoc : decimales de "% Venta / ingreso locales" (el formato cambia entre AR y UY)
     */
    const MODES = {
        ar:  { origen: 'ar', rank: 'top',    theme: 'teal', stock: true,  umbral: [0.30, 0.40], pctLoc: 1,
               title: 'RANKING VENTAS ARGENTINA (15 DÍAS)', sort: { k: 'vta_15', dir: -1 } },
        uy:  { origen: 'uy', rank: 'top',    theme: 'blue', stock: false, umbral: [0.30, 0.40], pctLoc: 0,
               title: 'RANKING VENTAS URUGUAY (15 DÍAS)',   sort: { k: 'vta_15', dir: -1 } },
        neg: { origen: 'ar', rank: 'bottom', theme: 'teal', stock: true,  umbral: [0.40, 0.50], pctLoc: 1,
               title: 'RANKING 50 INFERIORES',              sort: { k: 'pct_venta_15', dir: 1 } },
    };

    // Columnas de la tabla. `only` limita a ciertas pestañas (el Ranking Negativo es más angosto).
    const COLS = [
        { k: 'modelo',                    t: 'Modelo',                     f: 'txt' },
        { k: 'descripcion',               t: 'Descripción',                f: 'txt' },
        { k: 'color',                     t: 'Color',                      f: 'txt' },
        { k: 'ingreso_central',           t: 'Ingreso central',            f: 'int' },
        { k: 'fecha_ing_central',         t: 'Fecha ingreso central',      f: 'date', only: ['ar', 'uy'] },
        { k: 'fecha_ing_locales',         t: 'Fecha ingreso locales',      f: 'date' },
        { k: 'ingreso_locales',           t: 'Ingreso locales',            f: 'int' },
        { k: 'venta_total',               t: 'Venta total',                f: 'int',  only: ['ar', 'uy'] },
        { k: 'pct_venta_ingreso_locales', t: '% Venta / ingreso locales',  f: 'pctLoc' },
        { k: 'vta_15',                    t: 'Vta. 15 días',               f: 'int' },
        { k: 'pct_venta_15',              t: 'Venta / ingreso 15 días',    f: 'pct0', sem: true },
        { k: 'dias_stock',                t: 'Días de stock',              f: 'int' },
        { k: 'vta_30',                    t: 'Vta. 30 días',               f: 'int' },
        { k: 'crecimiento_30',            t: 'Crecimiento a 30 días',      f: 'pct0' },
        { k: 'vta_60',                    t: 'Vta. 60 días',               f: 'int' },
        { k: 'crecimiento_60',            t: 'Crecimiento a 60 días',      f: 'pct0' },
    ];

    const $ = (id) => document.getElementById(id);
    const el = {
        body: document.body,
        title: $('vv-title'), updated: $('vv-updated'), error: $('vv-error'),
        tabs: Array.from(document.querySelectorAll('.vv-tab')),
        fLinea: $('f-linea'), fDesde: $('f-desde'), fHasta: $('f-hasta'),
        fRubro: $('f-rubro'), fCategoria: $('f-categoria'), fModelo: $('f-modelo'), fColor: $('f-color'),
        dlLinea: $('dl-linea'), dlModelo: $('dl-modelo'), btnClear: $('btn-clear'),
        caption: $('vv-caption'), count: $('vv-count'),
        table: $('vv-table'), thead: $('vv-table').tHead, tbody: $('vv-table').tBodies[0],
        overlay: $('vv-overlay'), tableWrap: $('vv-table-wrap'),
        lower: document.querySelector('.vv-lower'),
        stockCard: $('vv-stock-card'), stockBody: $('vv-stock-body'), stockSub: $('vv-stock-sub'),
        vizImg: $('vv-viz-img'), vizEmpty: $('vv-viz-empty'), vizLabel: $('vv-viz-label'),
        legend: $('vv-legend'), thumbs: $('vv-thumbs'),
    };

    const S = {
        mode: 'ar',
        rows: [],
        sort: { k: 'vta_15', dir: -1 },
        selected: null,          // código de modelo seleccionado
        lists: {},               // listas de filtros por origen (cache en memoria)
        rankReq: 0,              // contador de consultas de ranking en curso
    };

    // ── Utilidades ────────────────────────────────────────────

    const esc = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    const num = (v, d = 0) => Number(v).toLocaleString('es-AR', { minimumFractionDigits: d, maximumFractionDigits: d });

    function fmt(col, v, mode) {
        if (v == null || v === '') return '';
        switch (col.f) {
            case 'int':    return num(v, 0);
            case 'pct0':   return num(v * 100, 0) + ' %';
            case 'pctLoc': return num(v * 100, MODES[mode].pctLoc) + ' %';
            case 'date':   return String(v).slice(8, 10) + '/' + String(v).slice(5, 7) + '/' + String(v).slice(0, 4);
            default:       return String(v);
        }
    }

    /** Semáforo: verde ≥ u2 · amarillo > u1 · rojo ≤ u1 · sin dato = sin color. */
    function semaforo(p, umbral) {
        if (p == null) return null;
        if (p >= umbral[1]) return COLOR.verde;
        if (p > umbral[0]) return COLOR.amarillo;
        return COLOR.rojo;
    }

    const cols = () => COLS.filter((c) => !c.only || c.only.includes(S.mode));
    const imgUrl = (cod) => BASE + 'imagen.php?articulo=' + encodeURIComponent(cod);

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
        fillSelect(el.fRubro, l.rubros, 'Todas');
        fillSelect(el.fColor, l.colores, 'Todas');
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

    // ── Tabla ─────────────────────────────────────────────────

    function sortedRows() {
        const { k, dir } = S.sort;
        return S.rows.slice().sort((a, b) => {
            const va = a[k], vb = b[k];
            const na = va == null || va === '', nb = vb == null || vb === '';
            if (na && nb) return a.modelo < b.modelo ? -1 : a.modelo > b.modelo ? 1 : 0;
            if (na) return 1;       // los vacíos siempre al final
            if (nb) return -1;
            let c = (typeof va === 'string') ? va.localeCompare(vb, 'es') : va - vb;
            if (c === 0) return a.modelo < b.modelo ? -1 : a.modelo > b.modelo ? 1 : 0;
            return c * dir;
        });
    }

    function renderHead() {
        el.thead.innerHTML = '<tr>' + cols().map((c) => {
            const isSort = S.sort.k === c.k;
            const arrow = isSort ? '<span class="arrow">' + (S.sort.dir === 1 ? '▲' : '▼') + '</span>' : '';
            const right = (c.f === 'txt' || c.f === 'date') ? '' : ' num';
            return '<th class="sortable' + right + '" data-k="' + c.k + '" aria-sort="' +
                (isSort ? (S.sort.dir === 1 ? 'ascending' : 'descending') : 'none') + '">' + esc(c.t) + arrow + '</th>';
        }).join('') + '</tr>';
    }

    function renderBody() {
        const cs = cols();
        const m = MODES[S.mode];
        const rows = sortedRows();

        if (!rows.length) {
            el.tbody.innerHTML = '<tr><td class="vv-empty" colspan="' + cs.length + '">Sin resultados para los filtros elegidos.</td></tr>';
            renderThumbs([]);
            return;
        }

        el.tbody.innerHTML = rows.map((r) => {
            const tds = cs.map((c) => {
                const txt = esc(fmt(c, r[c.k], S.mode));
                if (c.sem) {
                    const bg = semaforo(r[c.k], m.umbral);
                    return '<td class="sem" ' + (bg ? 'style="background:' + bg + '"' : '') + '>' + txt + '</td>';
                }
                const cls = (c.f === 'txt' || c.f === 'date') ? (c.f === 'date' ? ' class="dim"' : '') : ' class="num"';
                return '<td' + cls + '>' + txt + '</td>';
            }).join('');
            return '<tr data-modelo="' + esc(r.modelo) + '"' + (r.modelo === S.selected ? ' class="is-sel"' : '') + '>' + tds + '</tr>';
        }).join('');

        renderThumbs(rows.slice(0, 3));
    }

    function renderThumbs(rows) {
        el.thumbs.innerHTML = rows.map((r) =>
            '<button type="button" class="vv-thumb' + (r.modelo === S.selected ? ' is-sel' : '') + '" data-modelo="' + esc(r.modelo) + '" title="' + esc(r.descripcion + ' · ' + r.color) + '">' +
            '<img loading="lazy" alt="" src="' + imgUrl(r.modelo) + '"><span>' + esc(r.descripcion) + '</span></button>').join('');
    }

    function renderLegend() {
        const [u1, u2] = MODES[S.mode].umbral;
        const p1 = Math.round(u1 * 100), p2 = Math.round(u2 * 100);
        const next = (p1 + 0.1).toFixed(1).replace('.', ',');
        el.legend.innerHTML =
            '<li><span class="dot" style="background:' + COLOR.rojo + '"></span>Menor o igual al ' + p1 + '%</li>' +
            '<li><span class="dot" style="background:#FFD400"></span>Mayor o igual al ' + next + '% y menor al ' + p2 + '%</li>' +
            '<li><span class="dot" style="background:' + COLOR.verde + '"></span>Mayor o igual al ' + p2 + '%</li>';
    }

    function renderCaption() {
        // Réplica de la medida [Título 1]: SELECTEDVALUE devuelve vacío si no hay un único valor elegido.
        el.caption.textContent = 'Ranking ventas (15 días) Rubro: ' + (el.fRubro.value || '') + ' Categoría: ' + (el.fCategoria.value || '');
        el.count.textContent = S.rows.length
            ? num(S.rows.length) + (S.rows.length === 1 ? ' modelo' : ' modelos')
            : '';
    }

    async function loadRanking() {
        const my = ++S.rankReq;      // solo la última consulta puede apagar el "Cargando…"
        showError('');
        el.overlay.hidden = false;
        try {
            const j = await getJson('ranking', 'ranking.php', rankingParams());
            S.rows = j.data || [];
            el.updated.textContent = new Date().toLocaleTimeString('es-AR', { hour12: false });
            renderCaption();
            renderBody();
            // Si el modelo elegido ya no está en el resultado, seleccionar el primero.
            const rows = sortedRows();
            if (rows.length && !rows.some((r) => r.modelo === S.selected)) {
                selectModelo(rows[0].modelo);
            } else if (!rows.length) {
                clearSelection();
            }
        } catch (e) {
            if (e.name === 'AbortError') return;
            S.rows = [];
            renderCaption();
            renderBody();
            clearSelection();
            showError('No se pudo cargar el ranking: ' + e.message);
        } finally {
            if (my === S.rankReq) el.overlay.hidden = true;
        }
    }

    // ── Selección: imagen + stock ─────────────────────────────

    function markSelected() {
        el.tbody.querySelectorAll('tr.is-sel').forEach((tr) => tr.classList.remove('is-sel'));
        el.thumbs.querySelectorAll('.is-sel').forEach((b) => b.classList.remove('is-sel'));
        if (!S.selected) return;
        const q = '[data-modelo="' + (window.CSS && CSS.escape ? CSS.escape(S.selected) : S.selected) + '"]';
        const tr = el.tbody.querySelector('tr' + q);
        if (tr) tr.classList.add('is-sel');
        const th = el.thumbs.querySelector(q);
        if (th) th.classList.add('is-sel');
    }

    function clearSelection() {
        S.selected = null;
        el.vizImg.hidden = true; el.vizImg.removeAttribute('src');
        el.vizEmpty.hidden = false;
        el.vizLabel.textContent = '';
        el.stockSub.textContent = '';
        el.stockBody.innerHTML = '<tr><td colspan="2" class="vv-empty">Elegí un modelo de la tabla para ver su stock por sucursal.</td></tr>';
    }

    function selectModelo(modelo) {
        S.selected = modelo;
        markSelected();
        const r = S.rows.find((x) => x.modelo === modelo);

        el.vizEmpty.hidden = true;
        el.vizImg.hidden = false;
        el.vizImg.src = imgUrl(modelo);
        el.vizLabel.textContent = r ? (r.descripcion + (r.color ? ' · ' + r.color : '') + '  (' + modelo + ')') : modelo;

        if (MODES[S.mode].stock) loadStock(modelo);
    }

    async function loadStock(modelo) {
        el.stockSub.textContent = '';
        el.stockBody.innerHTML = '<tr><td colspan="2" class="vv-empty">Cargando stock…</td></tr>';
        try {
            const j = await getJson('stock', 'stock.php', new URLSearchParams({ modelo }));
            if (S.selected !== modelo) return;       // el usuario ya eligió otro
            const d = j.data;
            el.stockSub.textContent = (d.descripcion ? d.descripcion + (d.color ? ' · ' + d.color : '') + ' — ' : '') + 'total ' + num(d.total || 0);
            el.stockBody.innerHTML = d.sucursales.length
                ? d.sucursales.map((s) => '<tr><td>' + esc(s.sucursal) + '</td><td class="num' + (s.stock ? '' : ' zero') + '">' +
                    (s.stock == null ? '' : num(s.stock)) + '</td></tr>').join('')
                : '<tr><td colspan="2" class="vv-empty">Sin datos de stock.</td></tr>';
        } catch (e) {
            if (e.name === 'AbortError') return;
            el.stockBody.innerHTML = '<tr><td colspan="2" class="vv-empty">No se pudo cargar el stock: ' + esc(e.message) + '</td></tr>';
        }
    }

    // ── Pestañas ──────────────────────────────────────────────

    async function setMode(mode) {
        if (!MODES[mode]) return;
        const prevOrigen = MODES[S.mode].origen;
        S.mode = mode;
        const m = MODES[mode];

        el.body.dataset.theme = m.theme;
        el.title.textContent = m.title;
        el.tabs.forEach((t) => {
            const on = t.dataset.mode === mode;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        el.lower.classList.toggle('no-stock', !m.stock);
        S.sort = Object.assign({}, m.sort);
        S.selected = null;
        clearSelection();
        renderHead();
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
            S.sort = (S.sort.k === k) ? { k, dir: -S.sort.dir } : { k, dir: (k === 'modelo' || k === 'descripcion' || k === 'color') ? 1 : -1 };
            renderHead();
            renderBody();
            markSelected();
        });

        el.tbody.addEventListener('click', (ev) => {
            const tr = ev.target.closest('tr[data-modelo]');
            if (tr) selectModelo(tr.dataset.modelo);
        });
        el.thumbs.addEventListener('click', (ev) => {
            const b = ev.target.closest('.vv-thumb');
            if (b) selectModelo(b.dataset.modelo);
        });

        const reload = debounce(loadRanking, 250);
        [el.fDesde, el.fHasta, el.fRubro, el.fColor].forEach((c) => c.addEventListener('change', reload));
        el.fRubro.addEventListener('change', fillCategorias);
        el.fCategoria.addEventListener('change', reload);
        // Línea y Modelo son combos con búsqueda: aplican al confirmar (elegir de la lista, Enter o salir del campo).
        [el.fLinea, el.fModelo].forEach((c) => c.addEventListener('change', reload));

        el.btnClear.addEventListener('click', () => {
            resetSelectionFilters();
            el.fDesde.value = el.fDesde.dataset.default || '';
            el.fHasta.value = el.fHasta.dataset.default || '';
            fillCategorias();
            loadRanking();
        });
    }

    function init() {
        bind();
        setMode('ar');
    }

    init();
    return { setMode, reload: loadRanking };
})();
