/**
 * /bi/promociones/js/detalle.js
 * Tablas de detalle:
 *   - Por Sucursal  (getDetalleSucursales)
 *   - Por Promoción (getDetallePromociones)
 * Expone: PromoDetalle.loadSucursales(), PromoDetalle.loadPromociones(),
 *         PromoDetalle.onMonedaChange()
 */
const PromoDetalle = (() => {

    const $ = id => document.getElementById(id);

    /* Estado */
    let _lastSucursales   = null;
    let _lastPromociones  = null;
    let _sortSuc  = { key: 'fac_cpromo', dir: -1 };
    let _sortProm = { key: 'fac_cpromo', dir: -1 };
    let _selectedPromoKey = null;

    /* ── Helpers de formato delegados ── */
    function moneyK(n)   { return Promociones.fmt?.moneyK(n)   ?? '—'; }
    function moneyInt(n) { return Promociones.fmt?.money(n, 0) ?? '—'; }
    function pct(n, d=2) { return n == null ? '—' : Promociones.fmt?.pct(n, d) ?? (n * 100).toFixed(d) + ' %'; }
    function num(n)      { return n == null ? '—' : Promociones.fmt?.num(n)    ?? Number(n).toLocaleString('es-AR'); }
    function varPct(n)   { return Promociones.fmt?.varPct(n)   ?? '—'; }
    function varPp(n)    { return Promociones.fmt?.varPp(n)    ?? '—'; }

    function esFranquicias() {
        const btn = document.querySelector('.origen-btn.active');
        return btn ? btn.dataset.origen === 'franquicias' : true;
    }

    function varCls(n, invertir = false) {
        if (n == null) return '';
        const pos = invertir ? n <= 0 : n >= 0;
        return pos ? 'var-pos' : 'var-neg';
    }

    function escHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /* Clave compuesta promocion + banco para identificar fila */
    function promoKey(row) {
        return `${row.promocion ?? ''}|||${row.banco ?? ''}`;
    }

    /* ── COLUMNAS tabla sucursales ── */
    const COLS_SUC = [
        { key: 'sucursal',         label: 'Sucursal',      align: 'left',  fmt: v => v ?? '—',     xlFmt: null,     sortKey: 'sucursal'      },
        { key: 'fac_total',        label: 'Fact. Total',   align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'fac_total'     },
        { key: 'fac_cpromo',       label: 'Fact. C/Promo', align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'fac_cpromo'    },
        { key: 'tickets_cpromo',   label: 'Tickets C/P',   align: 'right', fmt: num,                xlFmt: 'number', sortKey: 'tickets_cpromo'},
        { key: 'costo_total',      label: 'Costo Total',   align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'costo_total'   },
        { key: 'pct_costo_total',  label: '% Costo/FAC',   align: 'right', fmt: n => pct(n, 2),     xlFmt: 'pct1',   sortKey: 'pct_costo_total', invertVar: true },
        { key: 'pct_promo_fac',    label: '% Promo/FAC',   align: 'right', fmt: n => pct(n, 2),     xlFmt: 'pct1',   sortKey: 'pct_promo_fac' },
    ];

    const COL_COD_CLIENT = { key: 'cod_client', label: 'Cod. Cliente', align: 'left', fmt: v => v ?? '—', xlFmt: null, sortKey: 'cod_client' };
    const COL_RECONOCIMIENTO_SUC = { key: 'reconocimiento', label: 'Reconocimiento $', align: 'right', fmt: moneyInt, xlFmt: 'money', sortKey: 'reconocimiento' };

    function getActiveCOLS_SUC() {
        if (esFranquicias()) {
            return [COL_COD_CLIENT, ...COLS_SUC, COL_RECONOCIMIENTO_SUC];
        }
        return COLS_SUC;
    }

    /* ── COLUMNAS tabla promociones ── */
    const COLS_PROM = [
        { key: 'promocion',       label: 'Promoción',      align: 'left',  fmt: v => v ?? '—',     xlFmt: null,     sortKey: 'promocion'    },
        { key: 'banco',           label: 'Banco',          align: 'left',  fmt: v => v ?? '—',     xlFmt: null,     sortKey: 'banco'        },
        { key: 'fac_cpromo',      label: 'Fact. C/Promo',  align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'fac_cpromo'   },
        { key: 'fac_prev',        label: 'Fact. C/P prev', align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'fac_prev'     },
        { key: 'var_fac',         label: 'Var. Fact.',     align: 'right', fmt: varPct,             xlFmt: 'pct1',   sortKey: 'var_fac'      },
        { key: 'tickets_cpromo',  label: 'Tickets',        align: 'right', fmt: num,                xlFmt: 'number', sortKey: 'tickets_cpromo'},
        { key: 'costo_total',     label: 'Costo Total',    align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'costo_total'  },
        { key: 'pct_costo_total', label: '% Costo/FAC',    align: 'right', fmt: n => pct(n, 2),     xlFmt: 'pct1',   sortKey: 'pct_costo_total', invertVar: true },
    ];

    const COL_RECONOCIMIENTO_PROM = { key: 'reconocimiento', label: 'Reconocimiento $', align: 'right', fmt: moneyInt, xlFmt: 'money', sortKey: 'reconocimiento' };

    function getActiveCOLS_PROM() {
        return esFranquicias() ? [...COLS_PROM, COL_RECONOCIMIENTO_PROM] : COLS_PROM;
    }

    /* ── Genérico: render tabla ── */
    function renderTabla(wrapId, rows, cols, sortState, sortFn, exportFn, showTotal = true, rowAttrsFn = null) {
        const wrap = $(wrapId);
        if (!wrap) return;

        if (!rows?.length) {
            wrap.innerHTML = '<div class="promo-loading">Sin datos</div>';
            return;
        }

        const sorted = [...rows].sort((a, b) => {
            const av = a[sortState.key], bv = b[sortState.key];
            if (av == null) return 1;
            if (bv == null) return -1;
            if (typeof av === 'string') return sortState.dir * av.localeCompare(bv, 'es');
            return sortState.dir * (av - bv);
        });

        /* Fila total */
        const total = {};
        if (showTotal) {
            const numKeys = cols.filter(c => c.align === 'right' && c.key !== 'var_fac').map(c => c.key);
            numKeys.forEach(k => {
                total[k] = rows.reduce((s, r) => s + (r[k] ?? 0), 0);
            });
            if (total.fac_prev != null && total.fac_cpromo != null && total.fac_prev > 0) {
                total.var_fac = (total.fac_cpromo - total.fac_prev) / total.fac_prev;
            }
            const denomTotal = total.fac_total || total.fac_cpromo || 0;
            if (denomTotal > 0) {
                total.pct_costo_total = total.costo_total / denomTotal;
                total.pct_promo_fac   = (total.fac_cpromo ?? 0) / denomTotal;
            }
        }

        let html = `<table class="promo-detalle-table"><thead><tr>`;
        cols.forEach(c => {
            const sortIcon = sortState.key === c.sortKey
                ? (sortState.dir > 0 ? ' sorted-asc' : ' sorted-desc')
                : '';
            html += `<th class="${sortIcon}" style="text-align:${c.align}" data-sort="${c.sortKey}">${c.label}</th>`;
        });
        html += '</tr></thead><tbody>';

        sorted.forEach(row => {
            const extraAttrs = rowAttrsFn ? rowAttrsFn(row) : '';
            html += `<tr${extraAttrs ? ' ' + extraAttrs : ''}>`;
            cols.forEach(c => {
                const v = row[c.key];
                html += `<td style="text-align:${c.align}">${c.fmt(v)}</td>`;
            });
            html += '</tr>';
        });

        if (showTotal && rows.length > 1) {
            html += '<tr class="row-total">';
            let totalLabelPlaced = false;
            cols.forEach((c, idx) => {
                if (c.align === 'left') {
                    if (!totalLabelPlaced) {
                        html += `<td style="text-align:left">Total</td>`;
                        totalLabelPlaced = true;
                    } else {
                        html += `<td style="text-align:left">—</td>`;
                    }
                } else {
                    const v = total[c.key];
                    html += `<td style="text-align:${c.align}">${c.fmt(v ?? null)}</td>`;
                }
            });
            html += '</tr>';
        }

        html += '</tbody></table>';
        wrap.innerHTML = html;

        /* Bind sort */
        wrap.querySelectorAll('th[data-sort]').forEach(th => {
            th.style.cursor = 'pointer';
            th.addEventListener('click', () => {
                const k = th.dataset.sort;
                sortFn(k);
            });
        });
    }

    /* ── Cross-filter: filtrar sucursales por promoción clickeada ── */

    async function applyPromoFilter(promoName, bancoName) {
        const wrapSuc = $('detalle-suc-wrap');
        if (!wrapSuc) return;
        try {
            const qs = Promociones.buildQS({ action: 'sucursales', promocion: promoName, banco: bancoName });
            const data = await fetch(`/bi/promociones/api/detalle.php?${qs}`).then(r => r.json());
            if (!data.ok) return;
            const sucConPromo = new Set((data.sucursales ?? []).map(r => r.sucursal));
            wrapSuc.querySelectorAll('tbody tr[data-suc]').forEach(tr => {
                const match = sucConPromo.has(tr.dataset.suc);
                tr.classList.toggle('row-highlighted', match);
                tr.classList.toggle('row-dimmed', !match);
            });
        } catch (e) {
            console.error('[PromoDetalle] applyPromoFilter:', e);
        }
    }

    function clearPromoFilter() {
        _selectedPromoKey = null;
        const wrapSuc = $('detalle-suc-wrap');
        if (wrapSuc) {
            wrapSuc.querySelectorAll('tbody tr').forEach(tr => {
                tr.classList.remove('row-highlighted', 'row-dimmed');
            });
        }
        const wrapProm = $('detalle-prom-wrap');
        if (wrapProm) {
            wrapProm.querySelectorAll('tbody tr').forEach(tr => tr.classList.remove('row-selected'));
        }
        const banner = $('detalle-promo-filter-banner');
        if (banner) banner.hidden = true;
    }

    function showFilterBanner(label) {
        let banner = $('detalle-promo-filter-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'detalle-promo-filter-banner';
            banner.className = 'promo-filter-banner';
            const wrapSuc = $('detalle-suc-wrap');
            wrapSuc?.parentNode?.insertBefore(banner, wrapSuc);
        }
        banner.innerHTML = `<i class="bi bi-funnel-fill"></i> Sucursales con: <strong>${escHtml(label)}</strong><button class="promo-filter-clear-btn" id="btn-clear-promo-filter" title="Limpiar filtro"><i class="bi bi-x-lg"></i></button>`;
        banner.hidden = false;
        $('btn-clear-promo-filter')?.addEventListener('click', clearPromoFilter);
    }

    function bindPromoRowClicks() {
        const wrap = $('detalle-prom-wrap');
        if (!wrap) return;

        /* Restaurar clase seleccionada si hay filtro activo */
        if (_selectedPromoKey) {
            wrap.querySelectorAll('tbody tr[data-promo-key]').forEach(tr => {
                tr.classList.toggle('row-selected', tr.dataset.promoKey === _selectedPromoKey);
            });
        }

        wrap.querySelectorAll('tbody tr[data-promo-key]').forEach(tr => {
            tr.addEventListener('click', async () => {
                const key = tr.dataset.promoKey;

                if (_selectedPromoKey === key) {
                    clearPromoFilter();
                    return;
                }

                _selectedPromoKey = key;
                wrap.querySelectorAll('tbody tr').forEach(r => r.classList.remove('row-selected'));
                tr.classList.add('row-selected');

                const [promoName, bancoName] = key.split('|||');
                const label = `${promoName}${bancoName ? ' / ' + bancoName : ''}`;
                showFilterBanner(label);
                await applyPromoFilter(promoName, bancoName);
            });
        });
    }

    /* ── Sucursales ── */
    async function loadSucursales() {
        const wrap = $('detalle-suc-wrap');
        if (wrap) wrap.innerHTML = '<div class="promo-loading">Cargando…</div>';
        clearPromoFilter();

        try {
            const data = await fetch(`/bi/promociones/api/detalle.php?${Promociones.buildQS({ action: 'sucursales' })}`).then(r => r.json());
            if (!data.ok) throw new Error(data.error ?? 'Error en detalle sucursales');
            _lastSucursales = (data.sucursales ?? []).map(r => ({
                ...r,
                reconocimiento: (r.costo_total ?? 0) * 0.5,
            }));
            renderSucursales();
        } catch (e) {
            console.error('[PromoDetalle] loadSucursales:', e);
            if (wrap) wrap.innerHTML = `<div class="promo-loading" style="color:var(--red)">Error: ${e.message}</div>`;
        }
    }

    function renderSucursales() {
        const cols = getActiveCOLS_SUC();
        renderTabla(
            'detalle-suc-wrap',
            _lastSucursales,
            cols,
            _sortSuc,
            key => { _sortSuc = { key, dir: _sortSuc.key === key ? -_sortSuc.dir : -1 }; renderSucursales(); },
            exportSucursales,
            true,
            row => `data-suc="${escHtml(row.sucursal ?? '')}"`,
        );
        bindExportBtn('btn-export-suc', exportSucursales);

        /* Reaplicar resaltado si hay filtro activo */
        if (_selectedPromoKey) {
            const [promoName, bancoName] = _selectedPromoKey.split('|||');
            applyPromoFilter(promoName, bancoName);
        }
    }

    function exportSucursales() {
        if (!_lastSucursales?.length) return;
        const cols     = getActiveCOLS_SUC();
        const headers  = cols.map(c => c.label);
        const colFmts  = cols.map(c => c.xlFmt);
        const rows     = _lastSucursales.map(r => cols.map(c => r[c.key] ?? null));
        exportarExcel('Detalle por Sucursal - Promociones', headers, rows, colFmts, 'promo_detalle_sucursales');
    }

    /* ── Promociones ── */
    async function loadPromociones() {
        const wrap = $('detalle-prom-wrap');
        if (wrap) wrap.innerHTML = '<div class="promo-loading">Cargando…</div>';
        clearPromoFilter();

        try {
            const data = await fetch(`/bi/promociones/api/detalle.php?${Promociones.buildQS({ action: 'promociones' })}`).then(r => r.json());
            if (!data.ok) throw new Error(data.error ?? 'Error en detalle promociones');
            _lastPromociones = (data.promociones ?? []).map(r => ({
                ...r,
                reconocimiento: (r.costo_total ?? 0) * 0.5,
            }));
            renderPromociones();
        } catch (e) {
            console.error('[PromoDetalle] loadPromociones:', e);
            if (wrap) wrap.innerHTML = `<div class="promo-loading" style="color:var(--red)">Error: ${e.message}</div>`;
        }
    }

    function renderPromociones() {
        const cols = getActiveCOLS_PROM();
        renderTabla(
            'detalle-prom-wrap',
            _lastPromociones,
            cols,
            _sortProm,
            key => { _sortProm = { key, dir: _sortProm.key === key ? -_sortProm.dir : -1 }; renderPromociones(); },
            exportPromociones,
            true,
            row => {
                const attrs = `data-promo-key="${escHtml(promoKey(row))}"`;
                return row.promocion?.includes(' / ')
                    ? attrs + ' class="row-combinada"'
                    : attrs;
            },
        );
        bindExportBtn('btn-export-prom', exportPromociones);
        bindPromoRowClicks();
    }

    function exportPromociones() {
        if (!_lastPromociones?.length) return;
        const cols     = getActiveCOLS_PROM();
        const headers  = cols.map(c => c.label);
        const colFmts  = cols.map(c => c.xlFmt);
        const rows     = _lastPromociones.map(r => cols.map(c => r[c.key] ?? null));
        exportarExcel('Detalle por Promoción', headers, rows, colFmts, 'promo_detalle_promociones');
    }

    /* ── Helpers ── */
    function bindExportBtn(btnId, fn) {
        const btn = $(btnId);
        if (btn) btn.onclick = fn;
    }

    function exportarExcel(title, headers, rows, colFormats, filename) {
        if (typeof ExcelExporter !== 'undefined') {
            ExcelExporter.export({ title, headers, rows, filename, colFormats });
        } else {
            console.warn('[PromoDetalle] ExcelExporter no disponible');
        }
    }

    /* Reactualizar moneda sin nueva carga */
    function onMonedaChange() {
        if (_lastSucursales)  renderSucursales();
        if (_lastPromociones) renderPromociones();
    }

    return {
        loadSucursales,
        loadPromociones,
        onMonedaChange,
    };
})();
