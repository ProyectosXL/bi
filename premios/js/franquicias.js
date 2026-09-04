/**
 * /bi/premios/js/franquicias.js
 * Vista "Franquicias": KPIs + tabla de facturación vs objetivos por sucursal
 * franquicia, ordenada alfabéticamente, con fila de total.
 */
const PremiosFranquicias = (() => {

    const {
        $, fmt, updatePeriodoLabel, cumplimientoCellHTML,
        facturacionVarMarcaCellHTML, apiFetch, actualizarUltimaActualizacion,
        nuevoEstadoOrden, cicloOrden, iconoOrden, ordenarPor, attachSortHandlers,
    } = Premios;

    let _lastSucursales = [];
    let _lastTotal = null;
    let _lastKpis = {};
    // Se resetea a "sin orden" en cada load() — el tablero siempre arranca con el orden
    // original (alfabético), nunca recuerda el último click de un usuario entre cargas.
    let _sortState = nuevoEstadoOrden();

    function renderKpis(kpis) {
        const wrap = $('kpi-franquicias-wrap');
        if (!wrap) return;
        const cards = [
            ['Cant. Franquicias Cump. Obj.',  fmt.num(kpis.cant_cumplen_objetivo)],
            ['Cant. Franquicias Obj. Crec.',  fmt.num(kpis.cant_objetivo_crec)],
            ['Facturación Var % Marca',       fmt.varPct(kpis.facturacion_var_marca)],
        ];
        wrap.innerHTML = cards.map(([label, val]) => `
            <div class="kpi-card">
                <div class="kpi-card-header"><span class="kpi-title">${label}</span></div>
                <div class="kpi-val">${val}</div>
            </div>`).join('');
    }

    // Encabezado ordenable por click: sin key = no ordenable (ninguna columna de esta tabla
    // lo necesita, pero se deja el parámetro por si hiciera falta a futuro).
    function th(texto, key, clase = '') {
        if (!key) return `<th class="${clase}">${texto}</th>`;
        const activa = _sortState.key === key ? ' th-ordenada' : '';
        return `<th class="${clase} th-ordenable${activa}" data-sort-key="${key}">${texto} ${iconoOrden(_sortState, key)}</th>`;
    }

    function renderTabla(sucursales, total, kpis) {
        const wrap = $('tabla-franquicias-wrap');
        if (!wrap) return;

        const benchmarkMarca = kpis.facturacion_var_marca;
        const filasOrdenadas = ordenarPor(sucursales, _sortState, (f, key) => f[key]);

        // Cada franquicia (fila) es su propio destinatario del premio (a diferencia de
        // Locales Propios, acá no hay agrupación por supervisora). El badge de Facturación
        // Var % es puramente visual (supera/no supera marca, ver facturacionVarMarcaCellHTML)
        // — no tiene relación con el premio de la fila.
        const filas = filasOrdenadas.map(f => {
            const cls = f.sin_datos ? 'row-sin-datos' : '';
            return `<tr class="${cls}">
                <td>${f.sucursal}</td>
                <td class="td-num">${fmt.money(f.facturacion)}</td>
                <td class="td-num">${fmt.money(f.objetivo_total)}</td>
                ${cumplimientoCellHTML(f.cumplimiento_obj, f.sin_datos)}
                <td class="td-num">${fmt.money(f.facturacion_previa)}</td>
                ${facturacionVarMarcaCellHTML(f.facturacion_var, benchmarkMarca, fmt.varPct(f.facturacion_var))}
            </tr>`;
        }).join('');

        wrap.innerHTML = `
            <table class="premios-table">
                <thead>
                    <tr>
                        ${th('Sucursal', 'sucursal')}
                        ${th('Facturación', 'facturacion', 'th-num')}
                        ${th('Objetivo Total $', 'objetivo_total', 'th-num')}
                        ${th('% Cumplimiento Obj. Venta', 'cumplimiento_obj', 'th-num')}
                        ${th('Facturación Período Anterior', 'facturacion_previa', 'th-num')}
                        ${th('Facturación Variación %', 'facturacion_var', 'th-num')}
                    </tr>
                </thead>
                <tbody>${filas}</tbody>
                <tfoot>
                    <tr class="totales">
                        <td>Total</td>
                        <td class="td-num">${fmt.money(total.facturacion)}</td>
                        <td class="td-num">${fmt.money(total.objetivo_total)}</td>
                        ${cumplimientoCellHTML(total.cumplimiento_obj)}
                        <td class="td-num">${fmt.money(total.facturacion_previa)}</td>
                        ${facturacionVarMarcaCellHTML(total.facturacion_var, benchmarkMarca, fmt.varPct(total.facturacion_var))}
                    </tr>
                </tfoot>
            </table>`;

        attachSortHandlers(wrap, key => {
            _sortState = cicloOrden(_sortState, key);
            renderTabla(_lastSucursales, _lastTotal, _lastKpis);
        });
    }

    function exportar() {
        if (!_lastSucursales.length || typeof ExcelExporter === 'undefined') return;
        ExcelExporter.export({
            title  : 'Franquicias — Facturación vs. Objetivos por Sucursales',
            headers: ['Sucursal', 'Facturación', 'Objetivo Total $', '% Cumplimiento Obj. Venta',
                'Facturación Período Anterior', 'Facturación Variación %'],
            rows: _lastSucursales.map(f => [
                f.sucursal, f.facturacion, f.objetivo_total, f.cumplimiento_obj,
                f.facturacion_previa, f.facturacion_var,
            ]),
            totalsRow: ['Total', _lastTotal.facturacion, _lastTotal.objetivo_total, _lastTotal.cumplimiento_obj,
                _lastTotal.facturacion_previa, _lastTotal.facturacion_var],
            colFormats: ['text', 'money', 'money', 'pct', 'money', 'pct'],
            filename: 'franquicias_facturacion_objetivos',
        });
    }

    async function load() {
        _sortState = nuevoEstadoOrden();
        const data = await apiFetch('franquicias.php');
        updatePeriodoLabel(data.periodo);
        actualizarUltimaActualizacion(data);

        _lastSucursales = data.sucursales ?? [];
        _lastTotal = data.total;
        _lastKpis = data.kpis ?? {};

        renderKpis(_lastKpis);
        renderTabla(_lastSucursales, _lastTotal, _lastKpis);

        const btn = $('btn-export-franquicias-tabla');
        if (btn) btn.onclick = exportar;
    }

    return { load };
})();
