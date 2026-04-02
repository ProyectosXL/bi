/**
 * grupos.js
 * Módulo de la pestaña "Grupos" para el Dashboard Sales XL.
 * Depende de Dashboard.state (definido en dashboard.js).
 */

const Grupos = (() => {

    /* ── Estado ──────────────────────────────────── */
    let _sucursales    = [];
    let _nroBase       = null;
    let _descBase      = '—';
    let _selectedNroB  = null;
    let _dropdownReady = false;

    /* Sort state para las dos tablas */
    let _desgloseData = null;
    let _desgloseSort = { col: null, asc: false };
    let _pivotData    = null;
    let _pivotSort    = { col: null, asc: false, colMap: [] };

    /* ── Definición de KPIs para Versus ─────────── */
    const KPI_DEFS = [
        { key: 'facturacion',      label: 'Facturación',          fmt: moneyFmt, higherBetter: true  },
        { key: 'unidades',         label: 'Unidades',             fmt: numFmt,   higherBetter: true  },
        { key: 'tickets',          label: 'Tickets',              fmt: numFmt,   higherBetter: true  },
        { key: 'ticket_promedio',  label: 'Ticket Promedio',      fmt: moneyFmt, higherBetter: true  },
        { key: 'porc_2do',         label: '% Tickets 2do Prod.',  fmt: pctFmt,   higherBetter: true  },
        { key: 'porc_3ro',         label: '% Tickets 3er Prod.',  fmt: pctFmt,   higherBetter: true  },
        { key: 'porc_cambios',     label: '% Cambios',            fmt: pctFmt,   higherBetter: false },
        { key: 'porc_incremental', label: '% Incremental',        fmt: pctFmt,   higherBetter: true  },
    ];

    /* ── Formatters ──────────────────────────────── */
    function numFmt(n, dec = 0) {
        return (n === null || n === undefined) ? '—'
            : Number(n).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }

    function moneyFmt(n) {
        if (n === null || n === undefined) return '—';
        return '$\u00A0' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function pctFmt(n) {
        if (n === null || n === undefined) return '—';
        return (n * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '\u00A0%';
    }

    function varCell(v) {
        if (v === null || v === undefined) return '<span class="var-neu">—</span>';
        const abs = Math.abs(v * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        return v >= 0
            ? `<span class="var-pos">▲ ${abs}\u00A0%</span>`
            : `<span class="var-neg">▼ ${abs}\u00A0%</span>`;
    }

    /* ── Build query string (solo período, sin vendedor/rubro) ── */
    function buildQS(extra = {}) {
        const s = Dashboard.state;
        const p = { periodo: s.periodo, ...extra };
        if (s.periodo === 'custom') {
            p.desde     = s.desde;
            p.hasta     = s.hasta;
            p.comp_mode = s.compMode;
            if (s.compMode === 'custom') {
                p.desde_comp = s.compDesde;
                p.hasta_comp = s.compHasta;
            }
        }
        return new URLSearchParams(p).toString();
    }

    async function apiFetch(action, extra = {}) {
        const res = await fetch(`api/grupos.php?action=${action}&${buildQS(extra)}`);
        if (!res.ok) throw new Error(`Error ${res.status} (${res.statusText}) en grupos/${action}`);
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Error en API grupos');
        return data;
    }

    /* ── Carga principal ─────────────────────────── */
    async function loadAll() {
        setLoading(true);
        try {
            // 1) Obtener sucursales del grupo
            const grupoData = await apiFetch('sucursales_grupo');
            _sucursales = grupoData.sucursales || [];
            _nroBase    = grupoData.nro_sucurs_base;

            // Nombre de la sucursal base
            const baseRow = _sucursales.find(s => (s.NRO_SUCURS || s.nro_sucurs) == _nroBase);
            _descBase = baseRow ? (baseRow.DESC_SUCURSAL || baseRow.desc_sucursal) : '—';

            document.getElementById('versus-suc-a-name').textContent = _descBase;

            // 2) Poblar dropdown (preservar selección previa si existe en el grupo)
            const otras = _sucursales.filter(s => (s.NRO_SUCURS || s.nro_sucurs) != _nroBase);
            populateDropdown(otras);

            if (otras.length === 0) {
                showVersusEmpty();
                await Promise.all([loadDesglose(), loadRubrosPivot()]);
                return;
            }

            // 3) Versus primero, luego tabla y pivot en paralelo
            await loadVersus(_selectedNroB);
            await Promise.all([loadDesglose(), loadRubrosPivot()]);
        } catch (err) {
            console.error('Grupos error:', err);
            showToast('Error al cargar datos de grupos: ' + err.message);
        } finally {
            setLoading(false);
        }
    }

    function setLoading(on) {
        document.querySelectorAll('#tab-grupos .analisis-loading').forEach(el => {
            el.style.display = on ? 'flex' : 'none';
        });
    }

    /* ── Dropdown de sucursal B ──────────────────── */
    function populateDropdown(otras) {
        const sel = document.getElementById('sel-suc-versus');
        if (!sel) return;

        // Determinar si la selección previa sigue siendo válida
        const prevNro = _selectedNroB;
        const prevValid = prevNro && otras.some(s => (s.NRO_SUCURS || s.nro_sucurs) == prevNro);

        sel.innerHTML = '';
        otras.forEach(s => {
            const nro  = s.NRO_SUCURS || s.nro_sucurs;
            const desc = s.DESC_SUCURSAL || s.desc_sucursal;
            const opt  = document.createElement('option');
            opt.value       = nro;
            opt.textContent = desc;
            if (prevValid && nro == prevNro) opt.selected = true;
            sel.appendChild(opt);
        });

        _selectedNroB = parseInt(sel.value);

        if (!_dropdownReady) {
            _dropdownReady = true;
            sel.addEventListener('change', function () {
                _selectedNroB = parseInt(this.value);
                loadVersus(_selectedNroB);
            });
        }
    }

    /* ── VERSUS ──────────────────────────────────── */
    async function loadVersus(nroSucB) {
        const container = document.getElementById('versus-content');
        if (!container) return;
        container.innerHTML = '<div class="analisis-loading" style="display:flex"><i class="bi bi-arrow-repeat"></i><span class="loading-text">Cargando comparación</span></div>';

        try {
            const data = await apiFetch('versus', { nro_suc_b: nroSucB });
            renderVersus(data);
        } catch (err) {
            container.innerHTML = `<div class="versus-empty">Error al cargar comparación: ${err.message}</div>`;
        }
    }

    function showVersusEmpty() {
        const container = document.getElementById('versus-content');
        if (container) {
            container.innerHTML = '<div class="versus-empty"><i class="bi bi-info-circle" style="font-size:1.4rem;color:var(--text-3)"></i><br>No hay otras sucursales en el mismo grupo para comparar.</div>';
        }
    }

    function renderVersus(data) {
        const container = document.getElementById('versus-content');
        if (!container) return;

        const { suc_a, suc_b } = data;
        const kpisA = suc_a.kpis;
        const kpisB = suc_b.kpis;

        const rows = KPI_DEFS.map(def => {
            const vA = kpisA[def.key] ?? 0;
            const vB = kpisB[def.key] ?? 0;
            const displayA = def.fmt(vA);
            const displayB = def.fmt(vB);

            // Determinar ganador
            let aWins = false, bWins = false;
            const EPSILON = 1e-9;
            if (def.higherBetter) {
                aWins = vA - vB > EPSILON;
                bWins = vB - vA > EPSILON;
            } else {
                // lower is better
                aWins = vB - vA > EPSILON;
                bWins = vA - vB > EPSILON;
            }

            // Diferencia porcentual (A respecto a B)
            let badgeHtml = '<span class="versus-badge tie">Igual</span>';
            if ((aWins || bWins) && Math.abs(vB) > EPSILON) {
                const diffPct = Math.abs((vA - vB) / Math.abs(vB) * 100);
                const diffStr = diffPct.toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
                const label   = suc_a.desc.split(' ')[0]; // primer palabra del nombre
                const labelB  = suc_b.desc.split(' ')[0];
                if (aWins) {
                    badgeHtml = `<span class="versus-badge a-wins">${label} +${diffStr}%</span>`;
                } else {
                    badgeHtml = `<span class="versus-badge b-wins">${labelB} +${diffStr}%</span>`;
                }
            }

            const clsA = aWins ? 'win' : (bWins ? 'lose' : '');
            const clsB = bWins ? 'win' : (aWins ? 'lose' : '');

            return `
                <tr>
                    <td><span class="versus-kpi-label">${def.label}</span></td>
                    <td><span class="versus-val-a ${clsA}">${displayA}</span></td>
                    <td class="versus-diff-cell">${badgeHtml}</td>
                    <td><span class="versus-val-b ${clsB}">${displayB}</span></td>
                </tr>
            `;
        }).join('');

        container.innerHTML = `
            <table class="versus-table">
                <thead>
                    <tr>
                        <th class="versus-th-kpi">KPI</th>
                        <th class="versus-th-a">${suc_a.desc}</th>
                        <th class="versus-th-diff">Diferencia</th>
                        <th class="versus-th-b">${suc_b.desc}</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    /* ── DESGLOSE POR SUCURSALES ─────────────────── */
    const DESGLOSE_COLS = [
        'desc_sucursal', 'unidades', 'porc_facturacion', 'tickets',
        'var_tickets', 'ticket_promedio', 'porc_2do', 'porc_3ro',
        'porc_cambios', 'porc_incremental',
    ];

    async function loadDesglose() {
        try {
            const data = await apiFetch('desglose');
            _desgloseData = data.grupos || [];
            _renderDesgloseTable();
            _setupDesgloseSort();
        } catch (err) {
            const tbody = document.querySelector('#tabla-grupos-desglose tbody');
            if (tbody) tbody.innerHTML = `<tr><td colspan="10" style="padding:20px;text-align:center;color:var(--neg)">Error: ${err.message}</td></tr>`;
        }
    }

    function _renderDesgloseTable() {
        const tbody = document.querySelector('#tabla-grupos-desglose tbody');
        if (!tbody || !_desgloseData) return;
        tbody.innerHTML = '';

        const { col, asc } = _desgloseSort;

        // Ordenar sucursales dentro de cada grupo
        const grupos = _desgloseData.map(g => ({
            ...g,
            sucursales: col
                ? [...g.sucursales].sort((a, b) => {
                    const va = a[col] ?? 0;
                    const vb = b[col] ?? 0;
                    if (typeof va === 'string') return asc ? va.localeCompare(vb) : vb.localeCompare(va);
                    return asc ? va - vb : vb - va;
                  })
                : g.sucursales
        }));

        const grandAcc = { unidades: 0, tickets: 0, ticket_promedio: 0, var_tickets: null, porc_2do: 0, porc_3ro: 0, porc_cambios: 0, porc_incremental: 0 };

        grupos.forEach(grupo => {
            const trGrupo = document.createElement('tr');
            trGrupo.className = 'tr-grupo';
            trGrupo.innerHTML = `<td colspan="10"><i class="bi bi-collection-fill" style="margin-right:6px;color:var(--accent2)"></i>${grupo.grupo}</td>`;
            tbody.appendChild(trGrupo);

            grupo.sucursales.forEach(s => {
                const tr = document.createElement('tr');
                tr.className = 'tr-suc' + (s.is_current ? ' is-current' : '');
                tr.innerHTML = `
                    <td style="padding-left:24px">${s.desc_sucursal}</td>
                    <td class="num">${numFmt(s.unidades)}</td>
                    <td class="num">${pctFmt(s.porc_facturacion)}</td>
                    <td class="num">${numFmt(s.tickets)}</td>
                    <td class="num">${varCell(s.var_tickets)}</td>
                    <td class="num">${moneyFmt(s.ticket_promedio)}</td>
                    <td class="num">${pctFmt(s.porc_2do)}</td>
                    <td class="num">${pctFmt(s.porc_3ro)}</td>
                    <td class="num">${pctFmt(s.porc_cambios)}</td>
                    <td class="num">${varCell(s.porc_incremental)}</td>
                `;
                tbody.appendChild(tr);
            });

            const t = grupo.totales;
            const trTot = document.createElement('tr');
            trTot.className = 'tr-total-grupo';
            trTot.innerHTML = `
                <td><strong>Total ${grupo.grupo}</strong></td>
                <td class="num"><strong>${numFmt(t.unidades)}</strong></td>
                <td class="num"><strong>${pctFmt(t.porc_facturacion)}</strong></td>
                <td class="num"><strong>${numFmt(t.tickets)}</strong></td>
                <td class="num">${varCell(t.var_tickets)}</td>
                <td class="num"><strong>${moneyFmt(t.ticket_promedio)}</strong></td>
                <td class="num"><strong>${pctFmt(t.porc_2do)}</strong></td>
                <td class="num"><strong>${pctFmt(t.porc_3ro)}</strong></td>
                <td class="num"><strong>${pctFmt(t.porc_cambios)}</strong></td>
                <td class="num">${varCell(t.porc_incremental)}</td>
            `;
            tbody.appendChild(trTot);

            grandAcc.unidades        += (t.unidades || 0);
            grandAcc.tickets         += (t.tickets  || 0);
            grandAcc.ticket_promedio  = t.ticket_promedio;
            grandAcc.var_tickets      = t.var_tickets;
            grandAcc.porc_2do         = t.porc_2do;
            grandAcc.porc_3ro         = t.porc_3ro;
            grandAcc.porc_cambios     = t.porc_cambios;
            grandAcc.porc_incremental = t.porc_incremental;
        });

        if (grupos.length > 1) {
            const trGrand = document.createElement('tr');
            trGrand.className = 'tr-total-general';
            trGrand.innerHTML = `
                <td><strong>TOTAL GENERAL</strong></td>
                <td class="num"><strong>${numFmt(grandAcc.unidades)}</strong></td>
                <td class="num"><strong>100%</strong></td>
                <td class="num"><strong>${numFmt(grandAcc.tickets)}</strong></td>
                <td class="num">${varCell(grandAcc.var_tickets)}</td>
                <td class="num"><strong>${moneyFmt(grandAcc.ticket_promedio)}</strong></td>
                <td class="num"><strong>${pctFmt(grandAcc.porc_2do)}</strong></td>
                <td class="num"><strong>${pctFmt(grandAcc.porc_3ro)}</strong></td>
                <td class="num"><strong>${pctFmt(grandAcc.porc_cambios)}</strong></td>
                <td class="num">${varCell(grandAcc.porc_incremental)}</td>
            `;
            tbody.appendChild(trGrand);
        }
    }

    function _setupDesgloseSort() {
        // Clonar ths para limpiar listeners previos
        Array.from(document.querySelectorAll('#tabla-grupos-desglose thead th'))
            .forEach(th => th.replaceWith(th.cloneNode(true)));

        document.querySelectorAll('#tabla-grupos-desglose thead th').forEach((th, i) => {
            const col = DESGLOSE_COLS[i];
            th.classList.remove('sort-asc', 'sort-desc');
            if (_desgloseSort.col === col) {
                th.classList.add(_desgloseSort.asc ? 'sort-asc' : 'sort-desc');
            }
            th.addEventListener('click', () => {
                if (_desgloseSort.col === col) {
                    _desgloseSort.asc = !_desgloseSort.asc;
                } else {
                    _desgloseSort.col = col;
                    _desgloseSort.asc = col === 'desc_sucursal';
                }
                _renderDesgloseTable();
                _setupDesgloseSort();
            });
        });
    }

    /* ── TOP RUBROS PIVOT ────────────────────────── */
    async function loadRubrosPivot() {
        const wrap = document.getElementById('grupos-rubros-wrap');
        try {
            const data = await apiFetch('rubros_pivot');
            _pivotData = data.data || { top_rubros: [], grupos: [] };
            _renderPivotTable();
            _setupPivotSort();
        } catch (err) {
            if (wrap) wrap.innerHTML = `<div class="versus-empty">Error: ${err.message}</div>`;
        }
    }

    function _renderPivotTable() {
        const wrap = document.getElementById('grupos-rubros-wrap');
        if (!wrap || !_pivotData) return;

        const { top_rubros: rubros, grupos } = _pivotData;

        if (!rubros.length) {
            wrap.innerHTML = '<div class="versus-empty">Sin datos de rubros para el período.</div>';
            return;
        }

        // Mapa de columnas para sort: '' no sortable, luego desc_sucursal, rubros, fact_total
        _pivotSort.colMap = ['', 'desc_sucursal', ...rubros, 'fact_total'];

        const { col, asc } = _pivotSort;

        // Max por rubro para heatmap por columna
        const maxByRubro = {};
        rubros.forEach(r => { maxByRubro[r] = 0; });
        grupos.forEach(g => g.sucursales.forEach(s => {
            rubros.forEach(r => { const v = s.rubros[r] || 0; if (v > maxByRubro[r]) maxByRubro[r] = v; });
        }));

        function heatCls(val, rubro) {
            const max = maxByRubro[rubro];
            if (!max || !val) return '';
            return 'heat-' + Math.min(5, Math.floor((val / max) * 6));
        }

        // Ordenar sucursales dentro de cada grupo
        const sortedGrupos = col ? grupos.map(g => ({
            ...g,
            sucursales: [...g.sucursales].sort((a, b) => {
                if (col === 'desc_sucursal') {
                    return asc
                        ? a.desc_sucursal.localeCompare(b.desc_sucursal)
                        : b.desc_sucursal.localeCompare(a.desc_sucursal);
                }
                const va = col === 'fact_total' ? a.fact_total : (a.rubros[col] || 0);
                const vb = col === 'fact_total' ? b.fact_total : (b.rubros[col] || 0);
                return asc ? va - vb : vb - va;
            })
        })) : grupos;

        const thRubros = rubros.map(r => `<th style="min-width:90px">${r}</th>`).join('');

        let tbody = '';
        sortedGrupos.forEach(grupo => {
            tbody += `<tr class="tr-grupo-piv">
                <td colspan="${2 + rubros.length + 1}">
                    <i class="bi bi-collection-fill" style="margin-right:6px;color:var(--accent2)"></i>${grupo.grupo}
                </td>
            </tr>`;
            grupo.sucursales.forEach(s => {
                const rubroCells = rubros.map(r => {
                    const v = s.rubros[r] || 0;
                    return `<td class="num-pct ${heatCls(v, r)}">${v > 0 ? pctFmt(v) : '—'}</td>`;
                }).join('');
                tbody += `<tr class="tr-suc-piv${s.is_current ? ' is-current' : ''}">
                    <td></td>
                    <td>${s.desc_sucursal}</td>
                    ${rubroCells}
                    <td class="num-pct" style="color:var(--text-3);font-size:.7rem">${moneyFmt(s.fact_total)}</td>
                </tr>`;
            });
        });

        wrap.innerHTML = `
            <table class="rubros-pivot-tabla">
                <thead>
                    <tr>
                        <th style="width:20px"></th>
                        <th style="min-width:160px">Sucursal</th>
                        ${thRubros}
                        <th style="min-width:110px">Fact. Total</th>
                    </tr>
                </thead>
                <tbody>${tbody}</tbody>
            </table>
        `;
    }

    function _setupPivotSort() {
        const colMap = _pivotSort.colMap;
        document.querySelectorAll('#grupos-rubros-wrap .rubros-pivot-tabla thead th').forEach((th, i) => {
            const col = colMap[i];
            if (!col) return; // primera columna vacía, no sortable
            th.classList.remove('sort-asc', 'sort-desc');
            if (_pivotSort.col === col) {
                th.classList.add(_pivotSort.asc ? 'sort-asc' : 'sort-desc');
            }
            th.addEventListener('click', () => {
                if (_pivotSort.col === col) {
                    _pivotSort.asc = !_pivotSort.asc;
                } else {
                    _pivotSort.col = col;
                    _pivotSort.asc = col === 'desc_sucursal';
                }
                _renderPivotTable();
                _setupPivotSort();
            });
        });
    }

    /* ── Toast ───────────────────────────────────── */
    function showToast(msg) {
        const t = document.createElement('div');
        t.className   = 'toast toast-error';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 5000);
    }

    /* ── Público ─────────────────────────────────── */
    return { loadAll };

})();
