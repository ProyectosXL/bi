/**
 * Stock — pestaña de inventario del local.
 *
 * Árbol RUBRO → CATEGORÍA → MODELO → TALLE.
 *   - Niveles 1 y 2 vienen agregados en la carga inicial (action=arbol).
 *   - Los niveles 3 y 4 se piden al abrir una categoría (action=detalle) y se
 *     cachean: una sola request alimenta modelos y talles.
 *   - Un modelo con un solo talle lo muestra inline, sin icono de expandir.
 *
 * Depende de Dashboard.fmt y Dashboard.spinner. buildQS/apiFetch/setLoading son
 * propios porque dashboard.js no los expone (mismo criterio que analisis.js).
 */
const Stock = (() => {

    /* ── Estado ──────────────────────────────── */
    const _s = {
        rubro    : '%',
        categoria: '%',
        temporada: '%',
        destino  : '%',
        q        : '',

        meta    : null,
        totales : { unidades: 0 },
        filtros : null,   // { rubros, categorias, temporadas, destinos }
        cacheDet: {},     // 'RUBRO||CATEGORIA' → [filas]

        expRubros : new Set(),
        expCats   : new Set(),
        expModelos: new Set(),

        busqSort: { col: null, dir: 'desc' },
        busqRows: [],
    };

    let _debounce = null;

    const fmt = () => Dashboard.fmt;

    /** Las unidades pueden venir con decimales por datos sucios del ETL. */
    function uni(n) {
        const v = Number(n) || 0;
        return Number.isInteger(v) ? fmt().num(v, 0) : fmt().num(v, 2);
    }

    function pctTotal(n) {
        const t = _s.totales.unidades;
        if (!t) return '—';
        return fmt().pct((Number(n) || 0) / t, 1);
    }

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    /* ── Fetch helpers ───────────────────────── */
    function buildQS(extra = {}) {
        const p = {
            rubro    : _s.rubro,
            categoria: _s.categoria,
            temporada: _s.temporada,
            destino  : _s.destino,
            ...extra,
        };
        return new URLSearchParams(p).toString();
    }

    async function apiFetch(action, extra = {}) {
        const res = await fetch(`api/stock.php?action=${action}&${buildQS(extra)}`);
        const data = await res.json().catch(() => null);
        if (!res.ok || !data) {
            throw new Error(data?.error || `Error ${res.status} (${res.statusText}) en stock/${action}`);
        }
        if (!data.ok) throw new Error(data.error || 'Error en API stock');
        return data;
    }

    function setLoading(on) {
        if (on) Dashboard.spinner.show('Cargando stock...');
        else    Dashboard.spinner.hide();
    }

    function showToastStock(msg) {
        const t = document.createElement('div');
        t.className = 'toast toast-error';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 5000);
    }

    function errorEnTabla(tablaId, colspan, msg) {
        const tbody = document.querySelector(`#${tablaId} tbody`);
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="stock-empty">
                <i class="bi bi-exclamation-triangle"></i> ${esc(msg)}</td></tr>`;
        }
    }

    /* ── Carga principal ─────────────────────── */
    async function loadAll() {
        setLoading(true);
        try {
            if (!_s.filtros) {
                try {
                    const d = await apiFetch('filtros');
                    _s.filtros = d;
                    _s.meta    = d.meta;
                    poblarFiltros(d);
                    renderMeta(d.meta);
                } catch (e) {
                    showToastStock(e.message);
                }
            }

            _s.cacheDet   = {};
            _s.expCats    = new Set();
            _s.expModelos = new Set();

            const [resumen, arbol] = await Promise.allSettled([
                apiFetch('resumen'),
                apiFetch('arbol'),
            ]);

            if (resumen.status === 'fulfilled') {
                renderResumen(resumen.value.resumen);
            } else {
                document.getElementById('stock-resumen-cards').innerHTML =
                    `<div class="stock-empty"><i class="bi bi-exclamation-triangle"></i> ${esc(resumen.reason.message)}</div>`;
            }

            if (arbol.status === 'fulfilled') {
                _s.totales = arbol.value.totales || { unidades: 0 };
                _s.meta    = arbol.value.meta || _s.meta;
                renderMeta(_s.meta);
                renderArbol(arbol.value.arbol || []);
            } else {
                errorEnTabla('tabla-stock', 6, arbol.reason.message);
                showToastStock(arbol.reason.message);
            }
        } finally {
            setLoading(false);
        }
    }

    /* ── Meta / filtros ──────────────────────── */
    function renderMeta(meta) {
        const el = document.getElementById('stock-meta');
        if (!el || !meta) return;
        if (!meta.fecha_carga) { el.textContent = ''; return; }
        const f = new Date(String(meta.fecha_carga).replace(' ', 'T'));
        el.textContent = isNaN(f) ? '' : `Datos al ${f.toLocaleString('es-AR', {
            day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
        })}`;
    }

    function poblarFiltros(d) {
        const selR = document.getElementById('stock-sel-rubro');
        const selT = document.getElementById('stock-sel-temporada');
        const selD = document.getElementById('stock-sel-destino');

        selR.innerHTML = '<option value="%">Todos</option>' +
            (d.rubros || []).map(r =>
                `<option value="${esc(r.rubro)}">${esc(r.rubro)}</option>`).join('');

        selT.innerHTML = '<option value="%">Todas</option>' +
            (d.temporadas || []).map(t => `<option value="${esc(t)}">${esc(t)}</option>`).join('');

        selD.innerHTML = '<option value="%">Todos</option>' +
            (d.destinos || []).map(t => `<option value="${esc(t)}">${esc(t)}</option>`).join('');

        poblarCategorias();
    }

    /** Cascada: las categorías se filtran por el rubro elegido, sin ir al server. */
    function poblarCategorias() {
        const selC = document.getElementById('stock-sel-categoria');
        const todas = _s.filtros?.categorias || [];
        const lista = _s.rubro === '%'
            ? [...new Set(todas.map(c => c.categoria))]
            : [...new Set(todas.filter(c => c.rubro === _s.rubro).map(c => c.categoria))];

        selC.innerHTML = '<option value="%">Todas</option>' +
            lista.sort().map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');

        if (!lista.includes(_s.categoria)) _s.categoria = '%';
        selC.value = _s.categoria;
    }

    /* ── Resumen ─────────────────────────────── */
    function renderResumen(r) {
        const grid = document.getElementById('stock-resumen-cards');
        if (!grid) return;
        if (!r) { grid.innerHTML = ''; return; }

        const cards = [
            { icon: 'bi-box-seam',   label: 'Unidades en stock', val: uni(r.unidades),         sub: `${fmt().num(r.articulos, 0)} códigos` },
            { icon: 'bi-tags',       label: 'Modelos distintos', val: fmt().num(r.modelos, 0), sub: `${fmt().num(r.categorias, 0)} categorías` },
            { icon: 'bi-collection', label: 'Rubros',            val: fmt().num(r.rubros, 0),  sub: 'con stock' },
        ];

        grid.innerHTML = cards.map(c => `
            <div class="stock-card">
                <div class="stock-card-icon"><i class="bi ${c.icon}"></i></div>
                <div class="stock-card-body">
                    <div class="stock-card-label">${c.label}</div>
                    <div class="stock-card-val">${c.val}</div>
                    <div class="stock-card-sub">${c.sub}</div>
                </div>
            </div>`).join('');
    }

    /* ── Árbol ───────────────────────────────── */
    function renderArbol(arbol) {
        const tbody = document.querySelector('#tabla-stock tbody');
        if (!tbody) return;

        if (!arbol.length) {
            tbody.innerHTML = `<tr><td colspan="5" class="stock-empty">
                <i class="bi bi-inbox"></i> No hay stock para los filtros seleccionados.</td></tr>`;
            return;
        }

        let html = '';
        arbol.forEach((rb, i) => {
            const rid = `r${i}`;
            html += filaRubro(rid, rb);
            (rb.categorias || []).forEach((cat, j) => {
                html += filaCategoria(rid, `${rid}c${j}`, rb.rubro, cat);
            });
        });

        html += `<tr class="tr-total-general">
            <td>TOTAL</td><td></td><td></td>
            <td class="td-jerarquia-num">${uni(_s.totales.unidades)}</td>
            <td class="td-jerarquia-num">100,0&nbsp;%</td>
        </tr>`;

        tbody.innerHTML = html;

        // Arranca todo contraído: sólo se ven los rubros.
        _s.expRubros = new Set();
        aplicarVisibilidad();
        enlazarToggles(tbody);
    }

    function filaRubro(rid, rb) {
        return `<tr class="tr-destino" data-nivel="rubro" data-rid="${rid}">
            <td><span class="toggle-icon" data-toggle="rubro" data-rid="${rid}">▶</span>${esc(rb.rubro)}</td>
            <td class="stock-td-sub">${fmt().num(rb.modelos, 0)} modelos</td>
            <td></td>
            <td class="td-jerarquia-num">${uni(rb.unidades)}</td>
            <td class="td-jerarquia-num">${pctTotal(rb.unidades)}</td>
        </tr>`;
    }

    function filaCategoria(rid, cid, rubro, cat) {
        return `<tr class="tr-rubro" data-nivel="cat" data-rid="${rid}" data-cid="${cid}"
                    data-rubro="${esc(rubro)}" data-categoria="${esc(cat.categoria)}">
            <td class="indent-rubro">
                <span class="toggle-icon" data-toggle="cat" data-cid="${cid}">▶</span>${esc(cat.categoria)}
            </td>
            <td class="stock-td-sub">${fmt().num(cat.modelos, 0)} modelos</td>
            <td></td>
            <td class="td-jerarquia-num">${uni(cat.unidades)}</td>
            <td class="td-jerarquia-num">${pctTotal(cat.unidades)}</td>
        </tr>`;
    }

    /**
     * Visibilidad derivada de los Sets de expansión. Recalcular todo es más
     * barato y mucho menos frágil que togglear fila por fila.
     */
    function aplicarVisibilidad() {
        document.querySelectorAll('#tabla-stock tbody tr').forEach(tr => {
            const n = tr.dataset.nivel;
            if (!n) return;   // fila TOTAL
            const { rid, cid, mid } = tr.dataset;
            let visible;
            switch (n) {
                case 'rubro':  visible = true; break;
                case 'cat':    visible = _s.expRubros.has(rid); break;
                case 'loading':
                case 'modelo': visible = _s.expRubros.has(rid) && _s.expCats.has(cid); break;
                case 'talle':  visible = _s.expRubros.has(rid) && _s.expCats.has(cid) && _s.expModelos.has(mid); break;
                default:       visible = true;
            }
            tr.style.display = visible ? '' : 'none';
        });

        document.querySelectorAll('#tabla-stock .toggle-icon').forEach(ic => {
            const t = ic.dataset.toggle;
            const abierto =
                t === 'rubro'  ? _s.expRubros.has(ic.dataset.rid) :
                t === 'cat'    ? _s.expCats.has(ic.dataset.cid)   :
                t === 'modelo' ? _s.expModelos.has(ic.dataset.mid) : false;
            ic.textContent = abierto ? '▼' : '▶';
        });
    }

    /** Delegado y enlazado una sola vez: el tbody sobrevive a los re-render. */
    let _togglesEnlazados = false;
    function enlazarToggles(tbody) {
        if (_togglesEnlazados) return;
        tbody.addEventListener('click', onToggleClick);
        _togglesEnlazados = true;
    }

    async function onToggleClick(ev) {
        const ic = ev.target.closest('.toggle-icon');
        if (!ic) return;

        const tipo = ic.dataset.toggle;

        if (tipo === 'rubro') {
            const rid = ic.dataset.rid;
            _s.expRubros.has(rid) ? _s.expRubros.delete(rid) : _s.expRubros.add(rid);
            aplicarVisibilidad();
            return;
        }

        if (tipo === 'modelo') {
            const mid = ic.dataset.mid;
            _s.expModelos.has(mid) ? _s.expModelos.delete(mid) : _s.expModelos.add(mid);
            aplicarVisibilidad();
            return;
        }

        if (tipo === 'cat') {
            const cid = ic.dataset.cid;
            if (_s.expCats.has(cid)) {
                _s.expCats.delete(cid);
                aplicarVisibilidad();
                return;
            }
            _s.expCats.add(cid);
            const trCat = ic.closest('tr');
            const yaCargado = trCat.dataset.cargado === '1';
            aplicarVisibilidad();
            if (!yaCargado) await cargarDetalle(trCat);
        }
    }

    /* ── Detalle (modelos + talles) ──────────── */
    async function cargarDetalle(trCat) {
        const { rid, cid, rubro, categoria } = trCat.dataset;
        const key = `${rubro}||${categoria}`;

        // Limpia un mensaje de error previo si se está reintentando.
        document.querySelectorAll(`#tabla-stock tbody tr[data-nivel="loading"][data-cid="${cid}"]`)
            .forEach(tr => tr.remove());

        let filas = _s.cacheDet[key];

        if (!filas) {
            const trLoad = document.createElement('tr');
            trLoad.dataset.nivel = 'loading';
            trLoad.dataset.rid   = rid;
            trLoad.dataset.cid   = cid;
            trLoad.innerHTML = `<td colspan="5" class="stock-det-loading">
                <i class="bi bi-arrow-repeat"></i> Cargando detalle…</td>`;
            trCat.insertAdjacentElement('afterend', trLoad);

            try {
                const d = await apiFetch('detalle', { rubro, categoria });
                filas = d.filas || [];
                _s.cacheDet[key] = filas;
            } catch (e) {
                // La categoría queda expandida a propósito: si se colapsara, el
                // mensaje de error se ocultaría junto con ella. Como no se marca
                // 'cargado', volver a abrirla reintenta la request.
                trLoad.innerHTML = `<td colspan="5" class="stock-empty">
                    <i class="bi bi-exclamation-triangle"></i> ${esc(e.message)}</td>`;
                showToastStock(e.message);
                return;
            }
            trLoad.remove();
        }

        trCat.dataset.cargado = '1';
        trCat.insertAdjacentHTML('afterend', filasDetalleHTML(rid, cid, filas));
        aplicarVisibilidad();
    }

    /** Agrupa las filas ARTICULO por MODELO: nivel 3 el modelo, nivel 4 los talles. */
    function filasDetalleHTML(rid, cid, filas) {
        const porModelo = new Map();
        filas.forEach(f => {
            if (!porModelo.has(f.modelo)) {
                porModelo.set(f.modelo, {
                    modelo: f.modelo, descripcion: f.descripcion, temporada: f.temporada,
                    unidades: 0, talles: [],
                });
            }
            const m = porModelo.get(f.modelo);
            m.unidades += f.unidades;
            m.talles.push(f);
            if (!m.descripcion && f.descripcion) m.descripcion = f.descripcion;
        });

        const modelos = [...porModelo.values()].sort((a, b) => b.unidades - a.unidades);

        let html = '';
        modelos.forEach((m, k) => {
            const mid   = `${cid}m${k}`;
            const multi = m.talles.length > 1;

            // Con un solo talle no hay nada que desplegar: se muestra inline.
            const etiqueta = multi
                ? `<span class="toggle-icon" data-toggle="modelo" data-mid="${mid}">▶</span>${esc(m.modelo)}`
                : `<span class="toggle-spacer"></span>${esc(m.talles[0].articulo)}`;

            const talleInline = multi
                ? `<span class="stock-badge">${m.talles.length} talles</span>`
                : (m.talles[0].talle && m.talles[0].talle !== 'ÚNICO'
                    ? `<span class="stock-badge">T ${esc(m.talles[0].talle)}</span>` : '');

            html += `<tr class="tr-categoria" data-nivel="modelo" data-rid="${rid}" data-cid="${cid}" data-mid="${mid}">
                <td class="indent-categoria">${etiqueta}</td>
                <td>${esc(m.descripcion)} ${talleInline}</td>
                <td class="stock-td-sub">${esc(m.temporada)}</td>
                <td class="td-jerarquia-num">${uni(m.unidades)}</td>
                <td class="td-jerarquia-num">${pctTotal(m.unidades)}</td>
            </tr>`;

            if (multi) {
                m.talles
                    .slice()
                    .sort((a, b) => String(a.talle).localeCompare(String(b.talle), 'es', { numeric: true }))
                    .forEach(t => {
                        html += `<tr class="tr-talle" data-nivel="talle" data-rid="${rid}" data-cid="${cid}" data-mid="${mid}">
                            <td class="indent-talle">Talle ${esc(t.talle)}</td>
                            <td class="stock-td-sub">${esc(t.articulo)}</td>
                            <td></td>
                            <td class="td-jerarquia-num">${uni(t.unidades)}</td>
                            <td class="td-jerarquia-num">${pctTotal(t.unidades)}</td>
                        </tr>`;
                    });
            }
        });

        return html;
    }

    /* ── Búsqueda ────────────────────────────── */
    async function doBuscar(q) {
        const card   = document.getElementById('stock-busqueda-card');
        const arbol  = document.getElementById('stock-arbol-card');
        const metaEl = document.getElementById('stock-busqueda-meta');

        if (q.length < 2) {
            card.hidden  = true;
            arbol.hidden = false;
            _s.busqRows  = [];
            return;
        }

        card.hidden  = false;
        arbol.hidden = true;
        document.querySelector('#tabla-stock-busqueda tbody').innerHTML =
            `<tr><td colspan="7" class="analisis-loading" style="display:flex">
                <i class="bi bi-arrow-repeat"></i> <span class="loading-text">Buscando</span></td></tr>`;

        try {
            const d = await apiFetch('buscar', { q });
            _s.busqRows = d.filas || [];
            metaEl.textContent = d.truncado
                ? `${d.total} resultados (se muestran los primeros ${d.total})`
                : `${d.total} resultado${d.total === 1 ? '' : 's'}`;
            renderBusqueda();
        } catch (e) {
            errorEnTabla('tabla-stock-busqueda', 7, e.message);
            metaEl.textContent = '';
        }
    }

    function renderBusqueda() {
        const tbody = document.querySelector('#tabla-stock-busqueda tbody');
        if (!_s.busqRows.length) {
            tbody.innerHTML = `<tr><td colspan="7" class="stock-empty">
                <i class="bi bi-inbox"></i> Sin resultados.</td></tr>`;
            return;
        }

        const { col, dir } = _s.busqSort;
        const rows = _s.busqRows.slice();
        if (col) {
            rows.sort((a, b) => {
                const va = a[col], vb = b[col];
                const cmp = (typeof va === 'number' && typeof vb === 'number')
                    ? va - vb
                    : String(va ?? '').localeCompare(String(vb ?? ''), 'es', { numeric: true });
                return dir === 'asc' ? cmp : -cmp;
            });
        }

        tbody.innerHTML = rows.map(f => `<tr class="tr-categoria">
            <td>${esc(f.articulo)}</td>
            <td>${esc(f.descripcion)}</td>
            <td>${esc(f.talle)}</td>
            <td>${esc(f.rubro)}</td>
            <td>${esc(f.categoria)}</td>
            <td class="stock-td-sub">${esc(f.temporada)}</td>
            <td class="td-jerarquia-num">${uni(f.unidades)}</td>
        </tr>`).join('');
    }

    /** Sort sólo en la tabla de búsqueda: el árbol tiene orden jerárquico propio. */
    function setupSortBusqueda() {
        const cols = ['articulo', 'descripcion', 'talle', 'rubro', 'categoria', 'temporada', 'unidades'];
        document.querySelectorAll('#tabla-stock-busqueda thead th').forEach((th, i) => {
            th.addEventListener('click', () => {
                const col = cols[i];
                if (_s.busqSort.col === col) {
                    _s.busqSort.dir = _s.busqSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    _s.busqSort = { col, dir: 'desc' };
                }
                document.querySelectorAll('#tabla-stock-busqueda thead th')
                    .forEach(x => x.classList.remove('sort-asc', 'sort-desc'));
                th.classList.add(_s.busqSort.dir === 'asc' ? 'sort-asc' : 'sort-desc');
                renderBusqueda();
            });
        });
    }

    /* ── Listeners ───────────────────────────── */
    function init() {
        const selR = document.getElementById('stock-sel-rubro');
        const selC = document.getElementById('stock-sel-categoria');
        const selT = document.getElementById('stock-sel-temporada');
        const selD = document.getElementById('stock-sel-destino');
        const inp  = document.getElementById('stock-input-buscar');
        const btnL = document.getElementById('stock-btn-limpiar');
        if (!selR) return;   // la pestaña no está habilitada para este perfil

        selR.addEventListener('change', () => {
            _s.rubro = selR.value;
            poblarCategorias();
            loadAll();
        });
        selC.addEventListener('change', () => { _s.categoria = selC.value; loadAll(); });
        selT.addEventListener('change', () => { _s.temporada = selT.value; loadAll(); });
        selD.addEventListener('change', () => { _s.destino   = selD.value; loadAll(); });

        inp.addEventListener('input', () => {
            clearTimeout(_debounce);
            const q = inp.value.trim();
            _s.q = q;
            _debounce = setTimeout(() => doBuscar(q), 350);
        });

        btnL.addEventListener('click', () => {
            _s.rubro = _s.categoria = _s.temporada = _s.destino = '%';
            _s.q = '';
            selR.value = selT.value = selD.value = '%';
            inp.value  = '';
            poblarCategorias();
            doBuscar('');
            loadAll();
        });

        setupSortBusqueda();
    }

    document.addEventListener('DOMContentLoaded', init);

    /* ── Público ─────────────────────────────── */
    return { loadAll };

})();
