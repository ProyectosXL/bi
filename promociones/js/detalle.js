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

    /**
     * Variación para la fila Total. El valor del período previo se manda en el
     * JSON pero no tiene columna propia, así que se suma desde las filas.
     */
    function varTotal(actual, rows, prevKey) {
        const prev = rows.reduce((s, r) => s + (r[prevKey] ?? 0), 0);
        const act  = actual ?? 0;
        return prev !== 0 ? (act - prev) / prev : (act > 0 ? 1 : 0);
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
        { key: 'fac_total',        label: 'Fact. Total',   align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'fac_total',
          tip: 'Facturación total de la sucursal en el período, con y sin promoción. Si arriba hay un banco seleccionado, cuenta sólo lo pagado con ese banco.' },
        { key: 'fac_cpromo',       label: 'Fact. C/Promo', align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'fac_cpromo',
          tip: 'Facturación de los tickets que llevaron al menos una promoción.' },
        { key: 'tickets_total',    label: 'Tickets Tot.',  align: 'right', fmt: num,                xlFmt: 'number', sortKey: 'tickets_total',
          tip: 'Todos los tickets (comprobantes FAC) de la sucursal, con y sin promoción.' },
        { key: 'tickets_cpromo',   label: 'Tickets C/P',   align: 'right', fmt: num,                xlFmt: 'number', sortKey: 'tickets_cpromo',
          tip: 'Tickets que llevaron al menos una promoción. Mismo criterio que el KPI «Tickets con Promo» del Resumen.' },
        {
            key: 'var_tickets_cpromo', label: 'Var. T. C/P', align: 'right', fmt: varPct, xlFmt: 'pct1', sortKey: 'var_tickets_cpromo',
            tip: 'Crecimiento de los tickets con promo contra el mismo período del año anterior.',
            recalcTotal: (t, rows) => varTotal(t.tickets_cpromo, rows, 'tickets_cpromo_prev'),
        },
        {
            key: 'pct_tickets_cpromo', label: '% Tickets C/P', align: 'right', fmt: n => pct(n, 1), xlFmt: 'pct1', sortKey: 'pct_tickets_cpromo',
            tip: 'Tickets C/P sobre Tickets Tot.: qué parte de las ventas de la sucursal usó alguna promoción.',
            recalcTotal: t => (t.tickets_total > 0 ? t.tickets_cpromo / t.tickets_total : 0),
        },
        { key: 'promos_usadas',    label: 'Promos Usadas', align: 'right', fmt: num,                xlFmt: 'number', sortKey: 'promos_usadas',
          tip: 'Cantidad de promociones aplicadas. Un ticket puede llevar más de una: las combinadas («PROMO A / BANCO B») cuentan 2.' },
        {
            key: 'var_promos_usadas', label: 'Var. Promos', align: 'right', fmt: varPct, xlFmt: 'pct1', sortKey: 'var_promos_usadas',
            tip: 'Crecimiento de las promociones aplicadas contra el mismo período del año anterior. Ojo: con una promoción filtrada, si el año pasado se llamaba distinto el período previo da 0.',
            recalcTotal: (t, rows) => varTotal(t.promos_usadas, rows, 'promos_usadas_prev'),
        },
        { key: 'costo_total',      label: 'Costo Total',   align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'costo_total',
          tip: 'Costo financiero de las promociones: cargo del banco más costo operativo de ventas.' },
        { key: 'pct_costo_total',  label: '% Costo/FAC',   align: 'right', fmt: n => pct(n, 2),     xlFmt: 'pct1',   sortKey: 'pct_costo_total', invertVar: true,
          tip: 'Costo Total sobre la facturación TOTAL de la sucursal (no sobre la facturación con promo). Cuanto más bajo, mejor.' },
        { key: 'pct_promo_fac',    label: '% Promo/FAC',   align: 'right', fmt: n => pct(n, 2),     xlFmt: 'pct1',   sortKey: 'pct_promo_fac',
          tip: 'Facturación con promo sobre la facturación total: cuánto de lo que vendió la sucursal pasó por una promoción.' },
    ];

    const COL_COD_CLIENT = { key: 'cod_client', label: 'Cod. Cliente', align: 'left', fmt: v => v ?? '—', xlFmt: null, sortKey: 'cod_client',
        tip: 'Código de cliente de la franquicia en el sistema de locales.' };
    const COL_RECONOCIMIENTO_SUC = { key: 'reconocimiento', label: 'Reconocimiento $ (s/IVA)', align: 'right', fmt: moneyInt, xlFmt: 'money', sortKey: 'reconocimiento',
        tip: 'Lo que se le reconoce a la franquicia: la mitad del costo de promociones, sin IVA (costo ÷ 1,21 × 0,5).' };
    const COL_CON_DIFERENCIAS = {
        key: 'con_diferencias',
        label: 'Con Diferencias',
        align: 'center',
        xlFmt: null,
        sortKey: 'con_diferencias',
        tip: 'Si hay diferencias entre la venta que informó la franquicia y la registrada en el período. «Sin Tango» = franquicia nueva, sin sistema conectado.',
        fmt: (v, row) => {
            if (row && row.sin_tango === true) {
                return '<span class="badge-dif-sintango">Sin Tango</span>';
            }
            if (v === true)  return '<span class="badge-dif-si">&#9888; SÍ</span>';
            if (v === false) return '<span class="badge-dif-no">&#10003; NO</span>';
            return '<span style="color:var(--text-3)">—</span>';
        }
    };
    const COL_COMUNICADO = {
        key: 'comunicado',
        label: 'Comunicado?',
        align: 'center',
        xlFmt: null,
        sortKey: 'comunicado',
        tip: 'Si ya se le envió a la franquicia el reporte mensual de promociones por mail.',
        fmt: v => {
            if (v === true)  return '<span class="badge-com-si">&#10003; SÍ</span>';
            if (v === false) return '<span class="badge-com-no">&#215; NO</span>';
            return '<span style="color:var(--text-3)">—</span>';
        }
    };
    const COL_CON_CONEXION = {
        key: 'con_conexion',
        label: 'Con Conexión?',
        align: 'center',
        xlFmt: null,
        sortKey: 'con_conexion',
        tip: 'Si la sucursal tuvo conexión con Tango durante el período. Sin conexión, sus datos de promociones pueden estar incompletos.',
        fmt: (v, row) => {
            if (row && row.sin_tango === true) {
                return '<span style="color:var(--text-3)">—</span>';
            }
            if (v === true)  return '<span class="badge-conn-si">&#10003; SÍ</span>';
            if (v === false) return '<span class="badge-conn-no">&#10007; NO</span>';
            return '<span style="color:var(--text-3)">—</span>';
        }
    };

    function getActiveCOLS_SUC() {
        if (esFranquicias()) {
            return [COL_COD_CLIENT, ...COLS_SUC, COL_RECONOCIMIENTO_SUC, COL_CON_DIFERENCIAS, COL_COMUNICADO, COL_CON_CONEXION];
        }
        return COLS_SUC;
    }

    /* ── COLUMNAS tabla promociones ── */
    const COL_TIPO_PROM = {
        key: 'tipo_prom',
        label: 'Tipo',
        align: 'center',
        xlFmt: null,
        sortKey: 'banco',
        tip: 'Bancaria si la promoción está asociada a un banco; Interna si no tiene banco asignado.',
        fmt: (v, row) => {
            if (!row) return '—';
            const esBancaria = row.banco && row.banco.trim() !== '' && row.banco.toUpperCase() !== 'DESCONOCIDO';
            if (esBancaria) {
                return '<span class="badge-prom-bancaria">Bancaria</span>';
            }
            return '<span class="badge-prom-interna">Interna</span>';
        }
    };

    const COLS_PROM = [
        { key: 'promocion',       label: 'Promoción',      align: 'left',  fmt: v => v ?? '—',     xlFmt: null,     sortKey: 'promocion',
          tip: 'Nombre de la promoción tal como viene cargada. Las que llevan « / » son tickets con más de una promo aplicada, y se cuentan aparte de las simples.' },
        { key: 'banco',           label: 'Banco',          align: 'left',  fmt: v => v ?? '—',     xlFmt: null,     sortKey: 'banco',
          tip: 'Banco asociado a la promoción. «DESCONOCIDO» o vacío significa que es una promoción interna, no bancaria.' },
        { key: 'fac_cpromo',      label: 'Fact. C/Promo',  align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'fac_cpromo',
          tip: 'Facturación de los tickets que usaron esta promoción, en el período seleccionado.' },
        { key: 'fac_prev',        label: 'Fact. C/P prev', align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'fac_prev',
          tip: 'Lo mismo en el mismo período del año anterior. Si la promoción se llamaba distinto el año pasado, da 0.' },
        { key: 'var_fac',         label: 'Var. Fact.',     align: 'right', fmt: varPct,             xlFmt: 'pct1',   sortKey: 'var_fac',
          tip: 'Crecimiento de la facturación contra el año anterior. Un +100% suele significar que la promoción no existía con ese nombre.' },
        { key: 'tickets_cpromo',  label: 'Tickets',        align: 'right', fmt: num,                xlFmt: 'number', sortKey: 'tickets_cpromo',
          tip: 'Tickets que usaron esta promoción.' },
        { key: 'costo_total',     label: 'Costo Total',    align: 'right', fmt: moneyInt,           xlFmt: 'money',  sortKey: 'costo_total',
          tip: 'Costo financiero de la promoción: cargo del banco más costo operativo de ventas.' },
        { key: 'pct_costo_total', label: '% Costo/FAC',    align: 'right', fmt: n => pct(n, 2),     xlFmt: 'pct1',   sortKey: 'pct_costo_total', invertVar: true,
          tip: 'Costo Total sobre la facturación de esta promoción. Cuanto más bajo, más barata resultó.' },
    ];

    const COL_RECONOCIMIENTO_PROM = { key: 'reconocimiento', label: 'Reconocimiento $ (s/IVA)', align: 'right', fmt: moneyInt, xlFmt: 'money', sortKey: 'reconocimiento',
        tip: 'Lo que se le reconoce a la franquicia por esta promoción: la mitad del costo, sin IVA (costo ÷ 1,21 × 0,5).' };

    function getActiveCOLS_PROM() {
        const base = esFranquicias() ? [...COLS_PROM, COL_RECONOCIMIENTO_PROM] : COLS_PROM;
        return [COL_TIPO_PROM, ...base];
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
            // Las columnas con recalcTotal son ratios o variaciones: sumarlas no
            // significa nada, se calculan aparte una vez que están los subtotales.
            const numKeys = cols
                .filter(c => c.align === 'right' && c.key !== 'var_fac' && typeof c.recalcTotal !== 'function')
                .map(c => c.key);
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
            cols.forEach(c => {
                if (typeof c.recalcTotal === 'function') total[c.key] = c.recalcTotal(total, rows);
            });
        }

        let html = `<table class="promo-detalle-table"><thead><tr>`;
        cols.forEach(c => {
            const sortIcon = sortState.key === c.sortKey
                ? (sortState.dir > 0 ? ' sorted-asc' : ' sorted-desc')
                : '';
            // El tooltip va en data-tip; lo muestra el listener delegado de
            // Promociones.initTooltips(), que sobrevive a estos re-render.
            const tipAttr = c.tip ? ` data-tip="${escHtml(c.tip)}"` : '';
            const label   = c.tip ? `<span class="th-tip">${c.label}</span>` : c.label;
            html += `<th class="${sortIcon}" style="text-align:${c.align}" data-sort="${c.sortKey}"${tipAttr}>${label}</th>`;
        });
        html += '</tr></thead><tbody>';

        sorted.forEach(row => {
            const extraAttrs = rowAttrsFn ? rowAttrsFn(row) : '';
            // Fila en rojo si tiene diferencias de ventas o falta de conexión, y NO es sin Tango
            const rowStyle = ((row.con_diferencias === true || row.con_conexion === false) && row.sin_tango !== true) ? ' style="background:rgba(220,38,38,0.07);"' : '';
            html += `<tr${extraAttrs ? ' ' + extraAttrs : ''}${rowStyle}>`;
            cols.forEach(c => {
                const v = row[c.key];
                html += `<td style="text-align:${c.align}">${c.fmt(v, row)}</td>`;
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
                // Reconocimiento s/IVA: (costo_total / 1.21) * 0.5
                reconocimiento: ((r.costo_total ?? 0) / 1.21) * 0.5,
                con_diferencias: null,  // se llena luego si es franquicias
                con_conexion: null,
                comunicado: null,
            }));

            // Para franquicias: enriquecer con estado de diferencias y comunicados
            if (esFranquicias() && _lastSucursales.length) {
                await enrichWithDiferencias();
            }

            renderSucursales();
        } catch (e) {
            console.error('[PromoDetalle] loadSucursales:', e);
            if (wrap) wrap.innerHTML = `<div class="promo-loading" style="color:var(--red)">Error: ${e.message}</div>`;
        }
    }

    /**
     * Llama a los endpoints de diferencias y log de envíos para enriquecer
     * _lastSucursales con los campos con_diferencias y comunicado.
     */
    async function enrichWithDiferencias() {
        try {
            const lastPeriodo = Promociones.getLastPeriodo();
            const desde       = lastPeriodo?.desde_act || '';
            const hasta       = lastPeriodo?.hasta_act || '';
            if (!desde || !hasta) return;

            const nros   = _lastSucursales.map(r => r.nro_sucursal).join(',');
            const difUrl = `/bi/promociones/api/diferencias_ventas.php?desde=${encodeURIComponent(desde)}&hasta=${encodeURIComponent(hasta)}&sucursales=${nros}`;
            const logUrl = `/bi/promociones/api/log_envios.php?desde=${encodeURIComponent(desde)}&hasta=${encodeURIComponent(hasta)}&sucursales=${nros}`;

            const [difData, logData] = await Promise.all([
                fetch(difUrl).then(r => r.json()).catch(() => ({ ok: false })),
                fetch(logUrl).then(r => r.json()).catch(() => ({ ok: false })),
            ]);

            const difMap = difData.ok  ? difData.diferencias ?? {} : {};
            const logMap = logData.ok  ? logData.comunicados  ?? {} : {};

            _lastSucursales = _lastSucursales.map(r => {
                const sDif = difMap[r.nro_sucursal] || { con_diferencias: null, con_conexion: null };
                return {
                    ...r,
                    con_diferencias: sDif.con_diferencias,
                    con_conexion:    sDif.con_conexion,
                    comunicado:      logMap[r.nro_sucursal] ?? false,
                };
            });
        } catch (e) {
            console.warn('[PromoDetalle] enrichWithDiferencias error:', e);
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

        const params     = Promociones.getParams();
        const selectedId = params.sucursal ? Number(params.sucursal) : null;
        const selectedSuc = selectedId ? _lastSucursales.find(s => s.nro_sucursal === selectedId) : null;

        if (selectedSuc && selectedSuc.sin_tango === true) {
            if (wrap) {
                wrap.innerHTML = `
                    <div style="background:rgba(79,70,229,0.06); border:1px solid rgba(79,70,229,0.2); border-radius:8px; padding:24px; text-align:center; color:#4338ca; font-size:0.9rem; font-weight:600; margin:10px 0;">
                        <i class="bi bi-info-circle-fill" style="font-size:1.5rem; display:block; margin-bottom:8px;"></i>
                        La sucursal ${selectedSuc.sucursal} es una nueva franquicia sin Tango. Su facturación se lee del portal de objetivos y su costo se estima en base al promedio global.
                    </div>
                `;
            }
            _lastPromociones = [];
            return;
        }

        try {
            const data = await fetch(`/bi/promociones/api/detalle.php?${Promociones.buildQS({ action: 'promociones' })}`).then(r => r.json());
            if (!data.ok) throw new Error(data.error ?? 'Error en detalle promociones');
            _lastPromociones = (data.promociones ?? []).map(r => ({
                ...r,
                // Reconocimiento s/IVA: (costo_total / 1.21) * 0.5
                reconocimiento: ((r.costo_total ?? 0) / 1.21) * 0.5,
            }));
            renderPromociones();
        } catch (e) {
            console.error('[PromoDetalle] loadPromociones:', e);
            if (wrap) wrap.innerHTML = `<div class="promo-loading" style="color:var(--red)">Error: ${e.message}</div>`;
        }
    }

    function renderPromociones() {
        const searchInput = $('search-promocion');
        const searchTerm  = searchInput ? searchInput.value.toLowerCase().trim() : '';

        let filtered = _lastPromociones;
        if (searchTerm) {
            filtered = _lastPromociones.filter(r => 
                (r.promocion && r.promocion.toLowerCase().includes(searchTerm)) ||
                (r.banco && r.banco.toLowerCase().includes(searchTerm))
            );
        }

        const cols = getActiveCOLS_PROM();
        renderTabla(
            'detalle-prom-wrap',
            filtered,
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

    document.addEventListener('DOMContentLoaded', () => {
        const searchInput = $('search-promocion');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                renderPromociones();
            });
        }
    });

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
        // Reutilizables por otras pestañas (ver js/rubros.js)
        renderTabla,
        bindExportBtn,
        exportarExcel,
    };
})();
