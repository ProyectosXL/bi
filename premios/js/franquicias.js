/**
 * /bi/premios/js/franquicias.js
 * Vista "Franquicias": KPIs + tabla de facturación vs objetivos por sucursal
 * franquicia, ordenada alfabéticamente, con fila de total.
 */
const PremiosFranquicias = (() => {

    const {
        $, fmt, updatePeriodoLabel, calculaCumplePorConsuelo, cumplimientoCellHTML,
        facturacionVarMarcaCellHTML, apiFetch, actualizarUltimaActualizacion,
    } = Premios;

    let _lastSucursales = [];
    let _lastTotal = null;

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

    function renderTabla(sucursales, total, kpis) {
        const wrap = $('tabla-franquicias-wrap');
        if (!wrap) return;

        const benchmarkMarca = kpis.facturacion_var_marca;

        // Cada franquicia (fila) es su propio destinatario del premio (a diferencia de
        // Locales Propios, acá no hay agrupación por supervisora) — mismo criterio que ahí:
        // el badge de Facturación Var % solo cuenta si la fila ganó el premio ESPECÍFICAMENTE
        // por la regla de consuelo, no si ya cumplió el objetivo de venta directo (condición
        // excluyente con la celda de Cumplimiento Obj. Venta, ver calculaCumplePorConsuelo/
        // facturacionVarMarcaCellHTML).
        const filas = sucursales.map(f => {
            const cls = f.sin_datos ? 'row-sin-datos' : '';
            const cumplePorConsuelo = calculaCumplePorConsuelo(f.cumplimiento_obj, f.facturacion_var, benchmarkMarca, f.sin_datos);
            return `<tr class="${cls}">
                <td>${f.sucursal}</td>
                <td class="td-num">${fmt.money(f.facturacion)}</td>
                <td class="td-num">${fmt.money(f.objetivo_total)}</td>
                ${cumplimientoCellHTML(f.cumplimiento_obj, f.sin_datos)}
                <td class="td-num">${fmt.money(f.facturacion_previa)}</td>
                ${facturacionVarMarcaCellHTML(f.facturacion_var, benchmarkMarca, cumplePorConsuelo, fmt.varPct(f.facturacion_var))}
            </tr>`;
        }).join('');

        wrap.innerHTML = `
            <table class="premios-table">
                <thead>
                    <tr>
                        <th>Sucursal</th>
                        <th class="th-num">Facturación</th>
                        <th class="th-num">Objetivo Total $</th>
                        <th class="th-num">% Cumplimiento Obj. Venta</th>
                        <th class="th-num">Facturación Período Anterior</th>
                        <th class="th-num">Facturación Variación %</th>
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
                        ${facturacionVarMarcaCellHTML(total.facturacion_var, benchmarkMarca, calculaCumplePorConsuelo(total.cumplimiento_obj, total.facturacion_var, benchmarkMarca), fmt.varPct(total.facturacion_var))}
                    </tr>
                </tfoot>
            </table>`;
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
        const data = await apiFetch('franquicias.php');
        updatePeriodoLabel(data.periodo);
        actualizarUltimaActualizacion(data);

        _lastSucursales = data.sucursales ?? [];
        _lastTotal = data.total;

        renderKpis(data.kpis ?? {});
        renderTabla(_lastSucursales, _lastTotal, data.kpis ?? {});

        const btn = $('btn-export-franquicias-tabla');
        if (btn) btn.onclick = exportar;
    }

    return { load };
})();
