/**
 * /bi/global/js/producto.js
 * Pestaña Producto: RUBRO/CATEGORÍA, colores, sucursales, top categorías, stock.
 */

const Producto = (() => {

    const _charts = {};
    let _sucNombresMap = {};

    /* ── Estado de ordenamiento ──────────────────────────── */
    let _rubroData = [];
    let _rubroSort = { col: 'facturacion', asc: false };
    let _colorData = [];
    let _colorSort = { col: 'unidades', asc: false };

    /* ── Formato ──────────────────────────────────────────── */
    function numFmt(n) {
        return n == null ? '—' : Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    function moneyFull(n) {
        if (n == null) return '—';
        return '$\u00A0' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }
    function diasFmt(n) {
        if (n == null) return '—';
        return Math.round(n).toLocaleString('es-AR');
    }
    function varHtml(v) {
        if (v == null) return '<span style="color:var(--text-3)">—</span>';
        const cls  = v >= 0 ? 'prod-var-pos' : 'prod-var-neg';
        const icon = v >= 0 ? '▲' : '▼';
        return `<span class="${cls}">${icon}\u00A0${(Math.abs(v) * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}\u00A0%</span>`;
    }
    function pctFmt(v) {
        if (v == null) return '—';
        return v.toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '\u00A0%';
    }

    /* ── Definición de columnas ───────────────────────────── */
    const RUBRO_COLS = [
        { key: 'facturacion',     label: 'Facturación',  fmt: moneyFull, right: true, minw: '110px' },
        { key: 'unidades',        label: 'Unid. Act.',   fmt: numFmt,  right: true, minw: '80px'  },
        { key: 'unidades_prev',   label: 'Unid. Ant.',   fmt: numFmt,  right: true, minw: '80px'  },
        { key: 'var_unidades',    label: 'Var.',         fmt: varHtml, right: true, minw: '70px', html: true },
        { key: 'stock_local',     label: 'Stock Local',  fmt: numFmt,  right: true, minw: '80px'  },
        { key: 'dias_stock',      label: 'Días Stock',   fmt: diasFmt, right: true, minw: '80px'  },
        { key: 'dias_stock_meta', label: 'Días Meta',    fmt: diasFmt, right: true, minw: '80px'  },
        { key: 'stock_central',   label: 'Stock Central',fmt: numFmt,  right: true, minw: '90px'  },
    ];

    const COLOR_COLS = [
        { key: 'unidades',      label: 'Unid. Act.',   fmt: numFmt,  right: true, minw: '80px'  },
        { key: 'unidades_prev', label: 'Unid. Ant.',   fmt: numFmt,  right: true, minw: '80px'  },
        { key: 'var_unidades',  label: 'Var.',         fmt: varHtml, right: true, minw: '70px', html: true },
        { key: 'stock_local',   label: 'Stock Local',  fmt: numFmt,  right: true, minw: '80px'  },
        { key: 'pct',           label: '% Part.',      fmt: pctFmt,  right: true, minw: '70px'  },
    ];

    /* ── Utilidad de ordenamiento ─────────────────────────── */
    function sortRows(rows, col, asc) {
        return [...rows].sort((a, b) => {
            const av = a[col], bv = b[col];
            if (av == null && bv == null) return 0;
            if (av == null) return 1;   // nulls al final
            if (bv == null) return -1;
            return asc ? av - bv : bv - av;
        });
    }

    /* ── Ícono de ordenamiento ────────────────────────────── */
    function sortIcon(col, current) {
        if (current.col !== col) return '<span class="prod-sort-icon">⇅</span>';
        return current.asc
            ? '<span class="prod-sort-icon prod-sort-active">▲</span>'
            : '<span class="prod-sort-icon prod-sort-active">▼</span>';
    }

    /* ── Fetch ────────────────────────────────────────────── */
    function getParams() {
        return Dashboard.buildQS({
            categoria: document.getElementById('sel-prod-categoria')?.value ?? ''
        });
    }

    async function apiFetch(action) {
        const res = await fetch(`/bi/global/api/producto.php?${getParams()}&action=${action}`);
        if (!res.ok) throw new Error(`Error ${res.status}`);
        const d = await res.json();
        if (!d.ok) throw new Error(d.error || `Error en ${action}`);
        return d;
    }

    /* ── Tabla RUBRO → CATEGORÍA (sortable, con stock) ─────── */
    function drawRubrosTable() {
        const wrap = document.getElementById('prod-rubros-wrap');
        if (!wrap) return;

        const sorted = sortRows(_rubroData, _rubroSort.col, _rubroSort.asc);

        // Encabezado
        const thLabel = `<th style="min-width:180px">Rubro / Categoría</th>`;
        const thCols   = RUBRO_COLS.map(c =>
            `<th class="prod-th-sort" style="min-width:${c.minw};text-align:right" data-col="${c.key}">${c.label}${sortIcon(c.key, _rubroSort)}</th>`
        ).join('');

        let html = `<table class="prod-tree-table">
            <thead><tr>${thLabel}${thCols}</tr></thead>
            <tbody>`;

        // Totales de rubro
        const totR = {
            facturacion    : _rubroData.reduce((s, r) => s + (r.facturacion    ?? 0), 0),
            unidades       : _rubroData.reduce((s, r) => s + (r.unidades       ?? 0), 0),
            unidades_prev  : _rubroData.reduce((s, r) => s + (r.unidades_prev  ?? 0), 0),
            stock_local    : _rubroData.reduce((s, r) => s + (r.stock_local    ?? 0), 0),
            stock_central  : _rubroData.reduce((s, r) => s + (r.stock_central  ?? 0), 0),
        };
        totR.var_unidades    = totR.unidades_prev > 0 ? (totR.unidades - totR.unidades_prev) / totR.unidades_prev : null;
        totR.dias_stock      = null; // no sumar días, no tiene sentido
        totR.dias_stock_meta = null;

        sorted.forEach((rb, ri) => {
            const tdCols = RUBRO_COLS.map(c => {
                const v   = rb[c.key];
                const val = c.fmt(v);
                return `<td style="text-align:right">${val}</td>`;
            }).join('');

            html += `<tr class="prod-row-rubro" data-ri="${ri}">
                <td><span class="jer-icon">▶</span>${rb.rubro}</td>${tdCols}
            </tr>`;

            const catsSorted = sortRows(rb.categorias ?? [], _rubroSort.col, _rubroSort.asc);
            catsSorted.forEach(cat => {
                const catCols = RUBRO_COLS.map(c => {
                    const v   = cat[c.key];
                    const val = c.fmt(v);
                    return `<td style="text-align:right">${val}</td>`;
                }).join('');
                html += `<tr class="prod-row-cat" data-ri="${ri}" style="display:none">
                    <td style="padding-left:28px">${cat.categoria}</td>${catCols}
                </tr>`;
            });
        });

        // Fila totales
        const totCols = RUBRO_COLS.map(c => {
            const val = c.fmt(totR[c.key]);
            return `<td style="text-align:right">${val}</td>`;
        }).join('');
        html += `<tr class="prod-row-total"><td>TOTAL</td>${totCols}</tr>`;

        html += '</tbody></table>';
        wrap.innerHTML = html;

        // Click en encabezado → ordenar
        wrap.querySelectorAll('.prod-th-sort').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.col;
                if (_rubroSort.col === col) {
                    _rubroSort.asc = !_rubroSort.asc;
                } else {
                    _rubroSort = { col, asc: false };
                }
                drawRubrosTable();
            });
        });

        // Expandir/colapsar
        wrap.querySelectorAll('.prod-row-rubro').forEach(tr => {
            tr.addEventListener('click', () => {
                const ri   = tr.dataset.ri;
                const cats = wrap.querySelectorAll(`.prod-row-cat[data-ri="${ri}"]`);
                if (!cats.length) return;
                const open = !tr.classList.contains('expanded');
                tr.classList.toggle('expanded', open);
                const icon = tr.querySelector('.jer-icon');
                if (icon) icon.textContent = open ? '▼' : '▶';
                cats.forEach(c => c.style.display = open ? '' : 'none');
            });
        });
    }

    function renderRubrosCategorias(rubros) {
        const wrap = document.getElementById('prod-rubros-wrap');
        if (!wrap) return;
        if (!rubros?.length) {
            wrap.innerHTML = '<div class="prod-loading">Sin datos</div>';
            return;
        }
        _rubroData = rubros;
        drawRubrosTable();
    }

    /* ── Tabla COLORES (sortable, con stock) ──────────────── */
    function drawColoresTable() {
        const wrap = document.getElementById('prod-colores-wrap');
        if (!wrap) return;
        if (!_colorData.length) return;

        const thLabel = `<th style="min-width:100px">Color</th>`;
        const thCols   = COLOR_COLS.map(c =>
            `<th class="prod-th-sort" style="min-width:${c.minw};text-align:right" data-col="${c.key}">${c.label}${sortIcon(c.key, _colorSort)}</th>`
        ).join('');

        const sorted = sortRows(_colorData, _colorSort.col, _colorSort.asc);

        let html = `<table class="prod-color-table">
            <thead><tr>${thLabel}${thCols}</tr></thead><tbody>`;

        // Totales de colores (sobre datos completos, no solo los 20 mostrados)
        const totC = {
            unidades     : _colorData.reduce((s, r) => s + (r.unidades     ?? 0), 0),
            unidades_prev: _colorData.reduce((s, r) => s + (r.unidades_prev ?? 0), 0),
            stock_local  : _colorData.reduce((s, r) => s + (r.stock_local   ?? 0), 0),
        };
        totC.var_unidades = totC.unidades_prev > 0 ? (totC.unidades - totC.unidades_prev) / totC.unidades_prev : null;
        totC.pct          = 100; // siempre 100 %

        sorted.forEach(r => {
            const tdCols = COLOR_COLS.map(c => {
                const v   = r[c.key];
                const val = c.fmt(v);
                return `<td style="text-align:right">${val}</td>`;
            }).join('');
            html += `<tr><td>${r.color}</td>${tdCols}</tr>`;
        });

        // Fila totales
        const totColsCols = COLOR_COLS.map(c => {
            const val = c.fmt(totC[c.key]);
            return `<td style="text-align:right">${val}</td>`;
        }).join('');
        html += `<tr class="prod-row-total"><td>TOTAL</td>${totColsCols}</tr>`;

        html += '</tbody></table>';
        wrap.innerHTML = html;

        // Click en encabezado → ordenar
        wrap.querySelectorAll('.prod-th-sort').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.col;
                if (_colorSort.col === col) {
                    _colorSort.asc = !_colorSort.asc;
                } else {
                    _colorSort = { col, asc: false };
                }
                drawColoresTable();
            });
        });
    }

    function renderColores(colores) {
        const wrap = document.getElementById('prod-colores-wrap');
        if (!wrap) return;
        if (!colores?.length) {
            wrap.innerHTML = '<div class="prod-loading" style="font-size:.80rem;color:var(--text-3)">Sin datos de color</div>';
            return;
        }

        // Calcular % participación sobre todos los colores (sin límite)
        const total = colores.reduce((s, r) => s + (r.unidades ?? 0), 0) || 1;
        _colorData  = colores.map(r => ({
            ...r,
            pct: (r.unidades / total) * 100,
        }));
        drawColoresTable();
    }

    /* ── Ranking sucursales (estilo Análisis) ─────────────── */
    let _sucTooltip = null;

    function renderSucursales(rows) {
        const wrap = document.getElementById('prod-suc-wrap');
        if (!wrap) return;
        if (!rows?.length) {
            wrap.innerHTML = '<div class="prod-loading">Sin datos</div>';
            return;
        }

        const top = rows.slice(0, 20);
        const max = Math.max(...top.map(r => r.unidades ?? 0), 1);

        wrap.innerHTML = top.map((r, i) => {
            const nombre  = _sucNombresMap[+r.NRO_SUCURS] ?? ('Suc. ' + r.NRO_SUCURS);
            const lbl     = nombre.length > 24 ? nombre.substring(0, 22) + '…' : nombre;
            const val     = r.unidades ?? 0;
            const prev    = r.unidades_prev ?? 0;
            const varV    = r.var_unidades;
            const pct     = (val / max * 100).toFixed(1);
            const prevPct = prev > 0 ? Math.min(prev / max * 100, 100).toFixed(1) : null;
            const barCls  = (varV ?? 0) >= 0 ? 'rubro-bar-green' : 'rubro-bar-red';
            const prevMrk = prevPct ? `<div class="rubro-target-line" style="left:${prevPct}%"></div>` : '';
            return `<div class="rubro-row"
                data-lbl="${encodeURIComponent(nombre)}"
                data-val="${val}"
                data-prev="${prev}"
                data-var="${varV ?? ''}">
                <span class="rubro-nombre" title="${nombre}">${lbl}</span>
                <div class="rubro-bar-wrap"><div class="rubro-bar ${barCls}" style="width:${pct}%"></div>${prevMrk}</div>
                <span class="rubro-val">${numFmt(val)}</span>
            </div>`;
        }).join('');

        // Tooltip
        if (!_sucTooltip) {
            _sucTooltip = document.createElement('div');
            _sucTooltip.className = 'ranking-tooltip';
            document.body.appendChild(_sucTooltip);
        }
        const tip = _sucTooltip;

        wrap.querySelectorAll('.rubro-row').forEach(div => {
            div.addEventListener('mouseenter', e => {
                const lbl  = decodeURIComponent(div.dataset.lbl ?? '');
                const val  = parseFloat(div.dataset.val ?? 0);
                const prev = parseFloat(div.dataset.prev ?? 0);
                const varV = div.dataset.var !== '' ? parseFloat(div.dataset.var) : null;
                const varStr = varV != null
                    ? `<span style="color:${varV >= 0 ? '#16a34a' : '#dc2626'};font-weight:600">${varV >= 0 ? '▲' : '▼'} ${(Math.abs(varV) * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} %</span>`
                    : '—';
                tip.innerHTML = `<strong>${lbl}</strong><br>
                    Actual: <strong>${numFmt(val)}</strong> unid.<br>
                    Anterior: ${numFmt(prev)} unid.<br>
                    Var.: ${varStr}`;
                tip.style.display = 'block';
                const rect = div.getBoundingClientRect();
                tip.style.left = (rect.right + window.scrollX + 8) + 'px';
                tip.style.top  = (rect.top  + window.scrollY - 4) + 'px';
            });
            div.addEventListener('mouseleave', () => { tip.style.display = 'none'; });
        });
    }

    /* ── Top 10 categorías (ranking bars) ─────────────────── */
    function renderTopCategorias(top) {
        const wrap = document.getElementById('prod-top-wrap');
        if (!wrap) return;
        if (!top?.length) { wrap.innerHTML = '<div class="prod-loading">Sin datos</div>'; return; }

        const max = Math.max(...top.map(r => r.unidades ?? 0), 1);

        wrap.innerHTML = top.map(r => {
            const pct     = ((r.unidades / max) * 100).toFixed(1);
            const prevPct = r.unidades_prev > 0 ? Math.min((r.unidades_prev / max) * 100, 100).toFixed(1) : null;
            const barCls  = (r.var_unidades ?? 0) >= 0 ? 'rubro-bar-green' : 'rubro-bar-red';
            const prevMrk = prevPct ? `<div class="rubro-target-line" style="left:${prevPct}%"></div>` : '';
            const lbl     = `${r.rubro} › ${r.categoria}`;
            return `<div class="rubro-row" title="${lbl}">
                <span class="rubro-nombre">${lbl}</span>
                <div class="rubro-bar-wrap"><div class="rubro-bar ${barCls}" style="width:${pct}%"></div>${prevMrk}</div>
                <span class="rubro-val">${numFmt(r.unidades)}</span>
            </div>`;
        }).join('');
    }

    /* ── Donut colores ────────────────────────────────────── */
    function renderDonutColores(colores) {
        const canvas = document.getElementById('prod-donut-colores');
        if (!canvas || !colores?.length) return;
        if (_charts['prod-donut']) { _charts['prod-donut'].destroy(); delete _charts['prod-donut']; }

        const COLORS = ['#2563eb','#16a34a','#dc2626','#f59e0b','#7c3aed','#0891b2','#ea580c','#84cc16','#db2777','#64748b','#059669','#b91c1c'];
        const top    = colores.slice(0, 12);
        const total  = top.reduce((s, r) => s + (r.unidades ?? 0), 0) || 1;

        _charts['prod-donut'] = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels  : top.map(r => r.color),
                datasets: [{
                    data           : top.map(r => r.unidades ?? 0),
                    backgroundColor: COLORS,
                    borderColor    : '#fff',
                    borderWidth    : 2,
                }]
            },
            options: {
                responsive         : true,
                maintainAspectRatio: false,
                cutout             : '55%',
                plugins: {
                    legend    : { position: 'right', labels: { font: { size: 10 }, boxWidth: 12, padding: 6 } },
                    datalabels: {
                        color : '#fff', anchor: 'center', align: 'center',
                        font  : { weight: 'bold', size: 10 },
                        formatter: v => { const p = v / total * 100; return p >= 5 ? p.toFixed(1) + '%' : ''; }
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                const v = ctx.parsed;
                                const p = (v / total * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                                return ` ${numFmt(v)} (${p}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    /* ── Poblar select de categorías (con buscador) ─────────── */
    let _catSelectInited = false;

    function populateCatSelect(rubros) {
        const sel = document.getElementById('sel-prod-categoria');
        if (!sel) return;
        const cur = sel.value;
        const cats = new Set();
        rubros.forEach(rb => (rb.categorias ?? []).forEach(c => cats.add(c.categoria)));
        sel.innerHTML = '<option value="">Todas</option>' +
            [...cats].sort().map(c => `<option value="${c}"${c === cur ? ' selected' : ''}>${c}</option>`).join('');

        if (!_catSelectInited && typeof Dashboard !== 'undefined') {
            _catSelectInited = true;
            Dashboard.initSearchableSelect('sel-prod-categoria');
        } else if (typeof Dashboard !== 'undefined') {
            Dashboard.syncSearchableSelect('sel-prod-categoria');
        }
    }

    /* ── Carga principal ──────────────────────────────────── */
    async function loadAll() {
        if (typeof Dashboard !== 'undefined' && Dashboard.getSucNombre) {
            _sucNombresMap = new Proxy({}, { get: (_, key) => Dashboard.getSucNombre(key) });
        }

        const setLoading = id => {
            const el = document.getElementById(id);
            if (el) el.innerHTML = '<div class="prod-loading"><i class="bi bi-arrow-repeat"></i> Cargando…</div>';
        };
        ['prod-rubros-wrap', 'prod-colores-wrap', 'prod-top-wrap', 'prod-suc-wrap'].forEach(setLoading);

        try {
            const dRubros = await apiFetch('rubros_categorias').catch(() => ({ rubros: [] }));
            const dColores = await apiFetch('colores').catch(() => ({ colores: [] }));
            const dSuc    = await apiFetch('sucursales').catch(() => ({ sucursales: [] }));
            const dTop    = await apiFetch('top_categorias').catch(() => ({ top: [] }));

            renderRubrosCategorias(dRubros.rubros ?? []);
            renderColores(dColores.colores ?? []);
            renderSucursales(dSuc.sucursales ?? []);
            renderTopCategorias(dTop.top ?? []);
            renderDonutColores(dColores.colores ?? []);
            populateCatSelect(dRubros.rubros ?? []);

        } catch (e) {
            console.error('[Producto]', e);
        }
    }

    /* ── Filtro de categoría ──────────────────────────────── */
    let _filtroReady = false;

    function setupFiltro() {
        if (_filtroReady) return;
        _filtroReady = true;
        document.getElementById('btn-prod-aplicar')?.addEventListener('click', () => loadAll());
    }

    async function loadAllAndSetup() {
        setupFiltro();
        return loadAll();
    }

    return { loadAll: loadAllAndSetup, setupFiltro };
})();
