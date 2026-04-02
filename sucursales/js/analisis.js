/**
 * analisis.js
 * Módulo de la pestaña "Análisis" para el Dashboard Sales XL.
 * Depende de Dashboard.fmt (definido en dashboard.js).
 */

const Analisis = (() => {

    /* ── Referencias a charts Chart.js ──────── */
    const _charts = {};

    /* ── Colores para gráficos de evolución ─── */
    const EVOLUCION_COLORS = [
        { border: '#2563eb', bg: 'rgba(37,99,235,0.12)' },  // año más viejo
        { border: '#f59e0b', bg: 'rgba(245,158,11,0.12)' }, // año intermedio
        { border: '#00a878', bg: 'rgba(0,168,120,0.15)' },  // año actual
    ];

    /* ── Utilidades ──────────────────────────── */
    const fmt = () => Dashboard.fmt; // referencia lazy a los formatters del dashboard principal

    function varIcon(v) {
        if (v === null || v === undefined) return '';
        return v >= 0
            ? '<span class="var-pos">▲ ' + (v * 100).toLocaleString('es-AR', {minimumFractionDigits:1,maximumFractionDigits:1}) + '\u00A0%</span>'
            : '<span class="var-neg">▼ ' + (Math.abs(v) * 100).toLocaleString('es-AR', {minimumFractionDigits:1,maximumFractionDigits:1}) + '\u00A0%</span>';
    }

    function numFmt(n, dec = 0) {
        return n === null || n === undefined ? '—'
            : Number(n).toLocaleString('es-AR', {minimumFractionDigits:dec, maximumFractionDigits:dec});
    }

    function moneyFmt(n) {
        if (n === null || n === undefined) return '—';
        return '$\u00A0' + Number(n).toLocaleString('es-AR', {minimumFractionDigits:0,maximumFractionDigits:0});
    }

    /* ── Build query string (reutiliza estado del Dashboard padre) ── */
    function buildQS(extra = {}) {
        const s = Dashboard.state;
        const p = {
            periodo : s.periodo,
            vendedor: s.vendedor,
            rubro   : s.rubro,
            ...extra
        };
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
        const res = await fetch(`api/analisis.php?action=${action}&${buildQS(extra)}`);
        if (!res.ok) throw new Error(`Error ${res.status} (${res.statusText}) en analisis/${action}`);
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Error en API análisis');
        return data;
    }

    /* ── Carga principal de la pestaña ──────── */
    async function loadAll() {
        setLoading(true);

        try {
            // ── Bloque 1: Cards de rubros + Ranking (resumen visual) ─────────
            await Promise.all([
                (async () => {
                    try {
                        const data = await apiFetch('cards_rubros');
                        renderCardsRubros(data.cards);
                    } catch (err) {
                        console.error('Análisis cards_rubros error:', err);
                        const grid = document.getElementById('analisis-rubros-cards');
                        if (grid) grid.innerHTML = '<div class="analisis-loading" style="display:flex;color:var(--neg)">Error al cargar</div>';
                    }
                })(),
                (async () => {
                    try {
                        const data = await apiFetch('ranking_rubros');
                        renderRankingRubros(data);
                    } catch (err) {
                        console.error('Análisis ranking_rubros error:', err);
                        showToastAnalisis('Error al cargar ranking: ' + err.message);
                    }
                })(),
            ]);

            // ── Bloque 2: Tablas (jerarquía + vendedores) ────────────────────
            await Promise.all([
                (async () => {
                    try {
                        const data = await apiFetch('jerarquia');
                        renderJerarquia(data.jerarquia, data.periodo);
                    } catch (err) {
                        console.error('Análisis jerarquia error:', err);
                        const tbody = document.querySelector('#tabla-jerarquia tbody');
                        if (tbody) tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:16px;color:var(--neg)">Error al cargar</td></tr>';
                    }
                })(),
                (async () => {
                    try {
                        const data = await apiFetch('vendedores');
                        renderVendedoresAnalisis(data.vendedores);
                    } catch (err) {
                        console.error('Análisis vendedores error:', err);
                        const tbody = document.querySelector('#tabla-vendedores-analisis tbody');
                        if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:16px;color:var(--neg)">Error al cargar</td></tr>';
                    }
                })(),
            ]);

            // ── Bloque 3: Gráficos de evolución (más pesados, al final) ──────
            await Promise.all([
                (async () => {
                    try {
                        const data = await apiFetch('evolucion_unidades');
                        renderEvolucion('chart-evolucion-unidades', data.evolucion, 'Unidades por Mes', false);
                    } catch (err) {
                        console.error('Análisis evolucion_unidades error:', err);
                    }
                })(),
                (async () => {
                    try {
                        const data = await apiFetch('evolucion_tickets');
                        renderEvolucion('chart-evolucion-tickets', data.evolucion, 'Tickets por Mes', false);
                    } catch (err) {
                        console.error('Análisis evolucion_tickets error:', err);
                    }
                })(),
            ]);

        } finally {
            setLoading(false);
        }
    }

    function setLoading(on) {
        document.body.classList.toggle('is-loading', on);
        document.querySelectorAll('.analisis-loading').forEach(el => {
            el.style.display = on ? 'flex' : 'none';
        });
    }

    /* ── Cards de rubros específicos ────────── */
    function renderCardsRubros(cards) {
        const grid = document.getElementById('analisis-rubros-cards');
        if (!grid) return;
        grid.innerHTML = '';

        cards.forEach(card => {
            const v   = card.variacion;
            const cls = v === null ? '' : (v >= 0 ? 'pos-card' : 'neg-card');
            const div = document.createElement('div');
            div.className = `rubro-kpi-card ${cls}`;
            div.innerHTML = `
                <div class="rk-header">
                    <span class="rk-label">${card.rubro}</span>
                    <div class="rk-prev-wrap">
                        <span class="rk-prev-label">Período Previo</span>
                        <span class="rk-prev-val">${numFmt(card.unidades_prev)}</span>
                    </div>
                </div>
                <div class="rk-body">
                    <div class="rk-value">${numFmt(card.unidades)}</div>
                    <div class="rk-var">${varIcon(v)}</div>
                </div>
            `;
            grid.appendChild(div);
        });
    }

    /* ── Ranking rubros en pestaña análisis ─── */
    const _rankingAnalisis = {
        cache        : {},
        drilldownState: { level: 1, rubro: null },
        originalData : null,

        render(data) {
            const unidContainer = document.getElementById('analisis-ranking-unidades');
            const factContainer = document.getElementById('analisis-ranking-facturacion');
            if (!unidContainer || !factContainer) return;

            if (!this.originalData) this.originalData = data;

            const rubros = data.rubros || [];
            this._renderList(unidContainer, rubros, 'unidades');
            this._renderList(factContainer, rubros, 'facturacion');
            this._updateHeaders(null);
        },

        _updateHeaders(rubroName) {
            const unidCard = document.getElementById('analisis-ranking-unidades')?.closest('.analisis-card');
            const factCard = document.getElementById('analisis-ranking-facturacion')?.closest('.analisis-card');

            const infoBtn = (tips) =>
                `<button class="info-btn" style="margin-left:auto"
                    data-info-title="Ranking Rubros"
                    data-info-tips="${tips}">
                    <i class="bi bi-info-circle"></i>
                 </button>`;

            const tipsComunes = 'Hacé clic en un rubro para ver el desglose por categorías|La línea vertical indica el valor del período anterior|Usá ← Rubros para volver al ranking general';

            if (rubroName) {
                if (unidCard) unidCard.querySelector('.analisis-section-header').innerHTML =
                    `<i class="bi bi-bar-chart-fill"></i> ${rubroName} — Categorías Unidades`;
                if (factCard) factCard.querySelector('.analisis-section-header').innerHTML =
                    `<i class="bi bi-bar-chart-fill"></i> ${rubroName} — Categorías Facturación`;
            } else {
                if (unidCard) unidCard.querySelector('.analisis-section-header').innerHTML =
                    `<i class="bi bi-bar-chart-fill"></i> Ranking Rubros — Unidades ${infoBtn(tipsComunes)}`;
                if (factCard) factCard.querySelector('.analisis-section-header').innerHTML =
                    `<i class="bi bi-bar-chart-fill"></i> Ranking Rubros — Facturación ${infoBtn(tipsComunes)}`;
            }
        },

        _renderList(container, items, key) {
            container.innerHTML = '';
            const isDrill = this.drilldownState.level === 2;
            const self    = this;

            // Breadcrumb (solo en drilldown)
            if (isDrill) {
                const bc = document.createElement('div');
                bc.className = 'ranking-breadcrumb';
                bc.innerHTML = `<button class="btn-volver-ranking">← Rubros</button><span class="ranking-drill-label">${this.drilldownState.rubro}</span>`;
                bc.querySelector('button').addEventListener('click', () => {
                    self.drilldownState = { level: 1, rubro: null };
                    self._updateHeaders(null);
                    self.render(self.originalData);
                });
                container.appendChild(bc);
            }

            if (!items.length) {
                container.insertAdjacentHTML('beforeend',
                    '<div class="analisis-loading" style="display:flex">Sin datos</div>');
                return;
            }

            const sorted = [...items].sort((a, b) => b[key] - a[key]);
            const max    = sorted[0]?.[key] || 1;

            sorted.forEach(r => {
                const val      = r[key];
                const prev     = key === 'unidades' ? r.unidades_prev : r.facturacion_prev;
                const variacion = r.variacion;
                const pct      = (val / max) * 100;
                const prevPct  = prev > 0 ? Math.min((prev / max) * 100, 100) : 0;
                const colorCls = variacion >= 0 ? 'rubro-bar-green' : 'rubro-bar-red';
                const display  = key === 'facturacion' ? moneyFmt(val) : numFmt(val);
                const label    = r.RUBRO;

                const div = document.createElement('div');
                div.className = 'rubro-row' + (isDrill ? '' : ' rubro-row-drillable');
                div.style.cursor = isDrill ? 'default' : 'pointer';
                div.innerHTML = `
                    <span class="rubro-nombre">${label}</span>
                    <div class="rubro-bar-wrap">
                        <div class="rubro-bar ${colorCls}" style="width:${pct}%"></div>
                        ${prevPct > 0 ? `<div class="rubro-target-line" style="left:${prevPct}%"></div>` : ''}
                    </div>
                    <span class="rubro-val">${display}</span>
                `;

                div.addEventListener('mouseenter', () => {
                    const rect = div.getBoundingClientRect();
                    let tip = document.getElementById('analisis-ranking-tip');
                    if (!tip) {
                        tip = document.createElement('div');
                        tip.id = 'analisis-ranking-tip';
                        tip.className = 'ranking-tooltip';
                        document.body.appendChild(tip);
                    }
                    tip.innerHTML = `
                        <div class="tooltip-rubro">${label}</div>
                        <div class="tooltip-row"><span>Actual:</span><strong>${display}</strong></div>
                        <div class="tooltip-row"><span>Anterior:</span><strong>${key === 'facturacion' ? moneyFmt(prev) : numFmt(prev)}</strong></div>
                        <div class="tooltip-row"><span>Var.:</span><strong>${varIcon(variacion)}</strong></div>
                        ${!isDrill ? '<div class="tooltip-hint">Click para ver categorías</div>' : ''}
                    `;
                    tip.style.left    = `${rect.left + rect.width / 2}px`;
                    tip.style.top     = `${rect.top - 10}px`;
                    tip.style.display = 'block';
                });

                div.addEventListener('mouseleave', () => {
                    const tip = document.getElementById('analisis-ranking-tip');
                    if (tip) tip.style.display = 'none';
                });

                if (!isDrill) {
                    div.addEventListener('click', () => {
                        const tip = document.getElementById('analisis-ranking-tip');
                        if (tip) tip.style.display = 'none';
                        self.drilldown(r.RUBRO);
                    });
                }

                container.appendChild(div);
            });
        },

        async drilldown(rubroName) {
            this.drilldownState = { level: 2, rubro: rubroName };
            this._updateHeaders(rubroName);

            const unidContainer = document.getElementById('analisis-ranking-unidades');
            const factContainer = document.getElementById('analisis-ranking-facturacion');
            [unidContainer, factContainer].forEach(c => {
                c.innerHTML = '<div class="analisis-loading" style="display:flex"><i class="bi bi-arrow-repeat"></i><span class="loading-text">Cargando categorías</span></div>';
            });

            const cacheKey = `cat_${rubroName}`;
            try {
                if (!this.cache[cacheKey]) {
                    this.cache[cacheKey] = await apiFetch('ranking_categorias', { rubro_filter: rubroName });
                }
                const items = (this.cache[cacheKey].categorias || [])
                    .map(c => ({ ...c, RUBRO: c.CATEGORIA }));
                this._renderList(unidContainer, items, 'unidades');
                this._renderList(factContainer, items, 'facturacion');
            } catch (err) {
                console.error('Drilldown categorías:', err);
                [unidContainer, factContainer].forEach(c => {
                    c.innerHTML = '<div class="analisis-loading" style="display:flex;color:var(--neg)">Error al cargar categorías</div>';
                });
            }
        }
    };

    function renderRankingRubros(data) {
        _rankingAnalisis.originalData  = null;
        _rankingAnalisis.drilldownState = { level: 1, rubro: null };
        _rankingAnalisis.cache         = {};
        _rankingAnalisis.render(data);
    }

    /* ── Tabla jerárquica ────────────────────── */
    function renderJerarquia(jerarquia, periodo) {
        const tbody = document.querySelector('#tabla-jerarquia tbody');
        if (!tbody) return;
        tbody.innerHTML = '';

        // Actualizar subtítulo de período
        const subtitulo = document.getElementById('jerarquia-periodo-sub');
        if (subtitulo && periodo) {
            const fmt = d => d.split('-').reverse().join('/');
            subtitulo.textContent =
                `${fmt(periodo.desde_act)} – ${fmt(periodo.hasta_act)}  vs  ${fmt(periodo.desde_prev)} – ${fmt(periodo.hasta_prev)}`;
        }

        // Acumuladores globales
        let grandTotUnidAct = 0, grandTotUnidPrev = 0, grandTotFactAct = 0, grandTotFactPrev = 0;

        jerarquia.forEach(destino => {
            const destId = 'dest_' + btoa(unescape(encodeURIComponent(destino.label))).replace(/=/g,'');
            grandTotUnidAct  += destino.totals.unidades;
            grandTotUnidPrev += destino.totals.unidades_prev;
            grandTotFactAct  += destino.totals.facturacion;
            grandTotFactPrev += destino.totals.facturacion_prev;

            // Fila DESTINO
            const trDest = document.createElement('tr');
            trDest.className = 'tr-destino';
            trDest.dataset.destId = destId;
            trDest.innerHTML = `
                <td>
                    <span class="toggle-icon" data-toggle="${destId}" title="Expandir/Colapsar">▼</span>
                    ${destino.label}
                </td>
                <td class="td-jerarquia-num">${numFmt(destino.totals.unidades)}</td>
                <td class="td-jerarquia-num">${numFmt(destino.totals.unidades_prev)}</td>
                <td class="td-jerarquia-num var-cell">${varIcon(destino.totals.var_unidades)}</td>
                <td class="td-jerarquia-num">${moneyFmt(destino.totals.facturacion)}</td>
            `;
            tbody.appendChild(trDest);

            // Filas RUBROS
            destino.rubros.forEach(rubro => {
                const rubroId = destId + '_rub_' + btoa(unescape(encodeURIComponent(rubro.label))).replace(/=/g,'');

                const trRub = document.createElement('tr');
                trRub.className = `tr-rubro`;
                trRub.dataset.parentDest = destId;
                trRub.dataset.rubroId = rubroId;
                trRub.innerHTML = `
                    <td class="indent-rubro">
                        <span class="toggle-icon" data-toggle="${rubroId}" title="Ver categorías">▼</span>
                        ${rubro.label}
                    </td>
                    <td class="td-jerarquia-num">${numFmt(rubro.totals.unidades)}</td>
                    <td class="td-jerarquia-num">${numFmt(rubro.totals.unidades_prev)}</td>
                    <td class="td-jerarquia-num var-cell">${varIcon(rubro.totals.var_unidades)}</td>
                    <td class="td-jerarquia-num">${moneyFmt(rubro.totals.facturacion)}</td>
                `;
                tbody.appendChild(trRub);

                // Filas CATEGORÍAS (colapsadas por defecto)
                rubro.categorias.forEach(cat => {
                    const trCat = document.createElement('tr');
                    trCat.className = 'tr-categoria';
                    trCat.dataset.parentRubro = rubroId;
                    trCat.dataset.parentDest  = destId;
                    trCat.style.display = 'none'; // colapsado por defecto
                    trCat.innerHTML = `
                        <td class="indent-categoria">${cat.label}</td>
                        <td class="td-jerarquia-num">${numFmt(cat.unidades)}</td>
                        <td class="td-jerarquia-num">${numFmt(cat.unidades_prev)}</td>
                        <td class="td-jerarquia-num var-cell">${varIcon(cat.var_unidades)}</td>
                        <td class="td-jerarquia-num">${moneyFmt(cat.facturacion)}</td>
                    `;
                    tbody.appendChild(trCat);
                });
            });
        });

        // Fila TOTAL GENERAL
        const grandVar = grandTotUnidPrev != 0
            ? (grandTotUnidAct - grandTotUnidPrev) / Math.abs(grandTotUnidPrev)
            : null;
        const trGrand = document.createElement('tr');
        trGrand.className = 'tr-total-general';
        trGrand.innerHTML = `
            <td><strong>TOTAL GENERAL</strong></td>
            <td class="td-jerarquia-num"><strong>${numFmt(grandTotUnidAct)}</strong></td>
            <td class="td-jerarquia-num"><strong>${numFmt(grandTotUnidPrev)}</strong></td>
            <td class="td-jerarquia-num var-cell">${varIcon(grandVar)}</td>
            <td class="td-jerarquia-num"><strong>${moneyFmt(grandTotFactAct)}</strong></td>
        `;
        tbody.appendChild(trGrand);

        // Bind de toggles
        tbody.querySelectorAll('.toggle-icon').forEach(icon => {
            icon.addEventListener('click', () => toggleGroup(icon, tbody));
        });
    }

    function toggleGroup(icon, tbody) {
        const targetId = icon.dataset.toggle;
        const isDestino = targetId.startsWith('dest_') && !targetId.includes('_rub_');

        if (isDestino) {
            // Toggle filas hijas con parentDest = targetId (rubros)
            const rubros = tbody.querySelectorAll(`[data-parent-dest="${targetId}"]`);
            let anyVisible = false;
            rubros.forEach(r => { if (r.tagName === 'TR' && r.classList.contains('tr-rubro') && r.style.display !== 'none') anyVisible = true; });
            const show = !anyVisible;
            rubros.forEach(row => {
                if (row.classList.contains('tr-rubro')) {
                    row.style.display = show ? '' : 'none';
                } else if (row.classList.contains('tr-categoria')) {
                    // categorías siempre ocultas cuando se colapsa el destino
                    row.style.display = 'none';
                    // resetear toggle de rubro
                    const rubroId = row.dataset.parentRubro;
                    const rubroToggle = tbody.querySelector(`[data-toggle="${rubroId}"]`);
                    if (rubroToggle) rubroToggle.textContent = '▶';
                }
            });
            icon.textContent = show ? '▼' : '▶';
        } else {
            // Toggle categorías de este rubro
            const cats = tbody.querySelectorAll(`[data-parent-rubro="${targetId}"]`);
            let anyVisible = false;
            cats.forEach(c => { if (c.style.display !== 'none') anyVisible = true; });
            const show = !anyVisible;
            cats.forEach(c => c.style.display = show ? '' : 'none');
            icon.textContent = show ? '▼' : '▶';
        }
    }

    /* ── Tabla vendedores análisis ───────────── */
    const _vendSortState = { column: 'facturacion', asc: false };

    function renderVendedoresAnalisis(vendedores) {
        _vendSortState._data = vendedores;
        _renderVendTable(vendedores);
        _setupVendSort();
    }

    function _renderVendTable(vendedores) {
        const tbody = document.querySelector('#tabla-vendedores-analisis tbody');
        if (!tbody) return;
        tbody.innerHTML = '';

        let totUnidAct = 0, totUnidPrev = 0, totFactAct = 0, totFactPrev = 0;

        vendedores.forEach(v => {
            totUnidAct  += v.unidades;
            totUnidPrev += v.unidades_prev;
            totFactAct  += v.facturacion;
            totFactPrev += v.facturacion_prev;

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-weight:500;white-space:nowrap">${v.vendedor}</td>
                <td class="td-jerarquia-num">${numFmt(v.unidades)}</td>
                <td class="td-jerarquia-num">${numFmt(v.unidades_prev)}</td>
                <td class="td-jerarquia-num var-cell">${varIcon(v.var_unidades)}</td>
                <td class="td-jerarquia-num">${moneyFmt(v.facturacion)}</td>
                <td class="td-jerarquia-num">${moneyFmt(v.facturacion_prev)}</td>
                <td class="td-jerarquia-num var-cell">${varIcon(v.var_facturacion)}</td>
            `;
            tbody.appendChild(tr);
        });

        const totVarUnid = totUnidPrev != 0 ? (totUnidAct - totUnidPrev) / Math.abs(totUnidPrev) : null;
        const totVarFact = totFactPrev != 0 ? (totFactAct - totFactPrev) / Math.abs(totFactPrev) : null;
        const trTot = document.createElement('tr');
        trTot.className = 'tr-total-vend';
        trTot.innerHTML = `
            <td><strong>Total</strong></td>
            <td class="td-jerarquia-num"><strong>${numFmt(totUnidAct)}</strong></td>
            <td class="td-jerarquia-num"><strong>${numFmt(totUnidPrev)}</strong></td>
            <td class="td-jerarquia-num var-cell">${varIcon(totVarUnid)}</td>
            <td class="td-jerarquia-num"><strong>${moneyFmt(totFactAct)}</strong></td>
            <td class="td-jerarquia-num"><strong>${moneyFmt(totFactPrev)}</strong></td>
            <td class="td-jerarquia-num var-cell">${varIcon(totVarFact)}</td>
        `;
        tbody.appendChild(trTot);
    }

    function _setupVendSort() {
        const headers = document.querySelectorAll('#tabla-vendedores-analisis thead th');
        const colMap  = ['vendedor','unidades','unidades_prev','var_unidades','facturacion','facturacion_prev','var_facturacion'];

        headers.forEach((th, i) => {
            th.replaceWith(th.cloneNode(true)); // limpiar listeners previos
        });

        document.querySelectorAll('#tabla-vendedores-analisis thead th').forEach((th, i) => {
            th.addEventListener('click', () => {
                const col = colMap[i];
                if (_vendSortState.column === col) _vendSortState.asc = !_vendSortState.asc;
                else { _vendSortState.column = col; _vendSortState.asc = col === 'vendedor'; }

                const sorted = [...(_vendSortState._data || [])].sort((a, b) => {
                    let va = a[col], vb = b[col];
                    if (typeof va === 'string') return _vendSortState.asc ? va.localeCompare(vb) : vb.localeCompare(va);
                    return _vendSortState.asc ? va - vb : vb - va;
                });

                _renderVendTable(sorted);
                document.querySelectorAll('#tabla-vendedores-analisis thead th').forEach(h => h.classList.remove('sort-asc','sort-desc'));
                th.classList.add(_vendSortState.asc ? 'sort-asc' : 'sort-desc');
            });
        });
    }

    /* ── Gráficos de evolución ───────────────── */
    function renderEvolucion(canvasId, evolucion, label, isTickets) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        if (_charts[canvasId]) {
            _charts[canvasId].destroy();
            delete _charts[canvasId];
        }

        const { meses, series } = evolucion;
        const today = new Date();
        const currentYear  = today.getFullYear();
        const currentMonth = today.getMonth(); // 0-based

        const datasets = series.map((s, idx) => {
            const isCurrentYear = s.anio === currentYear;
            const color = EVOLUCION_COLORS[idx % EVOLUCION_COLORS.length];

            // Para el año actual, ocultar meses futuros (null → no plotear)
            const valores = s.valores.map((v, m) => {
                if (isCurrentYear && m > currentMonth) return null;
                return v;
            });

            return {
                label          : String(s.anio),
                data           : valores,
                borderColor    : color.border,
                backgroundColor: color.bg,
                borderWidth    : isCurrentYear ? 3 : 1.5,
                borderDash     : isCurrentYear ? [] : [4,3],
                pointRadius    : 4,
                pointHoverRadius: 6,
                pointBackgroundColor: color.border,
                fill           : false,
                tension        : 0.35,
                spanGaps       : true,
            };
        });

        _charts[canvasId] = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels: meses, datasets },
            options: {
                responsive         : true,
                maintainAspectRatio: false,
                interaction        : { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        labels : { color: '#4b5674', font: { size: 11 }, boxWidth: 14, padding: 14 }
                    },
                    tooltip: {
                        backgroundColor: '#1a2340',
                        titleColor     : '#9ba8c8',
                        bodyColor      : '#ffffff',
                        padding        : 10,
                        callbacks      : {
                            label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y !== null ? numFmt(ctx.parsed.y) : '—'}`
                        }
                    },
                    datalabels: { display: false },
                },
                scales: {
                    x: {
                        grid : { color: 'rgba(26,35,64,0.06)' },
                        ticks: { color: '#8e96ae', font: { size: 11 } }
                    },
                    y: {
                        grid : { color: 'rgba(26,35,64,0.06)' },
                        ticks: {
                            color: '#8e96ae',
                            font : { size: 11 },
                            callback: v => numFmt(v)
                        }
                    }
                }
            },
            plugins: [ChartDataLabels]
        });
    }

    /* ── Toast ───────────────────────────────── */
    function showToastAnalisis(msg) {
        const t = document.createElement('div');
        t.className = 'toast toast-error';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 5000);
    }

    /* ── Público ─────────────────────────────── */
    return { loadAll };

})();
