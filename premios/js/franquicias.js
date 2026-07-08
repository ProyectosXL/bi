/**
 * /bi/premios/js/franquicias.js
 * Vista "Franquicias": KPIs + tabla de facturación vs objetivos por sucursal
 * franquicia, ordenada alfabéticamente, con fila de total.
 */
const PremiosFranquicias = (() => {

    const { $, fmt, updatePeriodoLabel, claseSemaforo, apiFetch, actualizarUltimaActualizacion } = Premios;

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

    function renderTabla(sucursales, total) {
        const wrap = $('tabla-franquicias-wrap');
        if (!wrap) return;

        const filas = sucursales.map(f => {
            const cls = f.sin_datos ? 'row-sin-datos' : '';
            return `<tr class="${cls}">
                <td>${f.sucursal}</td>
                <td class="td-num">${fmt.money(f.facturacion)}</td>
                <td class="td-num">${fmt.money(f.objetivo_total)}</td>
                <td class="td-num ${claseSemaforo(f.cumplimiento_obj, f.sin_datos)}">${fmt.pct(f.cumplimiento_obj)}</td>
                <td class="td-num">${fmt.money(f.facturacion_previa)}</td>
                <td class="td-num ${f.facturacion_var !== null ? (f.facturacion_var >= 0 ? 'text-green' : 'text-red') : ''}">${fmt.varPct(f.facturacion_var)}</td>
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
                        <td class="td-num ${claseSemaforo(total.cumplimiento_obj)}">${fmt.pct(total.cumplimiento_obj)}</td>
                        <td class="td-num">${fmt.money(total.facturacion_previa)}</td>
                        <td class="td-num ${total.facturacion_var !== null ? (total.facturacion_var >= 0 ? 'text-green' : 'text-red') : ''}">${fmt.varPct(total.facturacion_var)}</td>
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
        renderTabla(_lastSucursales, _lastTotal);

        const btn = $('btn-export-franquicias-tabla');
        if (btn) btn.onclick = exportar;
    }

    return { load };
})();
