/**
 * /bi/premios/js/propios.js
 * Vista "Locales Propios": KPIs de marca + tabla de facturación vs objetivos
 * por sucursal, agrupada por supervisora con subtotales y total general.
 */
const PremiosPropios = (() => {

    const { $, fmt, updatePeriodoLabel, cumplimientoCellHTML, claseBenchmark, apiFetch, actualizarUltimaActualizacion } = Premios;

    let _lastGrupos = [];
    let _lastTodas  = null;
    let _lastTotal  = null;

    function renderKpis(kpis) {
        const wrap = $('kpi-propios-wrap');
        if (!wrap) return;
        const cards = [
            ['Facturación Var % Marca',    fmt.varPct(kpis.facturacion_var_marca)],
            ['Ticket Promedio Marca',      fmt.money(kpis.ticket_promedio_marca)],
            ['% Tickets 2do Prod. Marca',  fmt.pct(kpis.pct_ticket_2do_marca)],
            ['% Tickets 3er Prod. Marca',  fmt.pct(kpis.pct_ticket_3er_marca)],
        ];
        wrap.innerHTML = cards.map(([label, val]) => `
            <div class="kpi-card">
                <div class="kpi-card-header"><span class="kpi-title">${label}</span></div>
                <div class="kpi-val">${val}</div>
            </div>`).join('');
    }

    function filaSucursalHTML(f, bm) {
        const cls = f.sin_datos ? 'row-sin-datos' : '';
        return `<tr class="${cls}">
            <td class="td-sucursal">${f.sucursal}</td>
            <td class="td-num">${fmt.money(f.facturacion_s_iva)}</td>
            <td class="td-num">${fmt.money(f.facturacion_c_iva)}</td>
            <td class="td-num">${fmt.money(f.objetivo_total)}</td>
            ${cumplimientoCellHTML(f.cumplimiento_obj, f.facturacion_var, bm.facturacionVarMarca, f.sin_datos)}
            <td class="td-num ${f.facturacion_var !== null ? (f.facturacion_var >= 0 ? 'text-green' : 'text-red') : ''}">${fmt.varPct(f.facturacion_var)}</td>
            <td class="td-num ${claseBenchmark(f.ticket_promedio, bm.ticketPromedioMarca)}">${fmt.money(f.ticket_promedio)}</td>
            <td class="td-num ${claseBenchmark(f.pct_ticket_2do, bm.pct2Marca)}">${fmt.pct(f.pct_ticket_2do)}</td>
            <td class="td-num ${claseBenchmark(f.pct_ticket_3er, bm.pct3Marca)}">${fmt.pct(f.pct_ticket_3er)}</td>
        </tr>`;
    }

    function filaSubtotalHTML(nombre, s, bm, sinDatos, extraClass) {
        return `<tr class="row-supervisora ${extraClass ?? ''}">
            <td>${nombre}</td>
            <td class="td-num">${fmt.money(s.facturacion_s_iva)}</td>
            <td class="td-num">${fmt.money(s.facturacion_c_iva)}</td>
            <td class="td-num">${fmt.money(s.objetivo_total)}</td>
            ${cumplimientoCellHTML(s.cumplimiento_obj, s.facturacion_var, bm.facturacionVarMarca, sinDatos)}
            <td class="td-num ${s.facturacion_var !== null ? (s.facturacion_var >= 0 ? 'text-green' : 'text-red') : ''}">${fmt.varPct(s.facturacion_var)}</td>
            <td class="td-num ${claseBenchmark(s.ticket_promedio, bm.ticketPromedioMarca)}">${fmt.money(s.ticket_promedio)}</td>
            <td class="td-num ${claseBenchmark(s.pct_ticket_2do, bm.pct2Marca)}">${fmt.pct(s.pct_ticket_2do)}</td>
            <td class="td-num ${claseBenchmark(s.pct_ticket_3er, bm.pct3Marca)}">${fmt.pct(s.pct_ticket_3er)}</td>
        </tr>`;
    }

    function filaEcommerceHTML(f, bm) {
        // Fila sintética SUPERVISORA='TODAS' en la BD = canal ECOMMERCE, hija del grupo
        // "Todas" (no pertenece a ninguna supervisora real) — indentada igual que una
        // sucursal bajo su supervisora.
        const cls = f.sin_datos ? 'row-sin-datos' : '';
        return `<tr class="${cls}">
            <td class="td-sucursal">ECOMMERCE</td>
            <td class="td-num">${fmt.money(f.facturacion_s_iva)}</td>
            <td class="td-num">${fmt.money(f.facturacion_c_iva)}</td>
            <td class="td-num">${fmt.money(f.objetivo_total)}</td>
            ${cumplimientoCellHTML(f.cumplimiento_obj, f.facturacion_var, bm.facturacionVarMarca, f.sin_datos)}
            <td class="td-num ${f.facturacion_var !== null ? (f.facturacion_var >= 0 ? 'text-green' : 'text-red') : ''}">${fmt.varPct(f.facturacion_var)}</td>
            <td class="td-num ${claseBenchmark(f.ticket_promedio, bm.ticketPromedioMarca)}">${fmt.money(f.ticket_promedio)}</td>
            <td class="td-num ${claseBenchmark(f.pct_ticket_2do, bm.pct2Marca)}">${fmt.pct(f.pct_ticket_2do)}</td>
            <td class="td-num ${claseBenchmark(f.pct_ticket_3er, bm.pct3Marca)}">${fmt.pct(f.pct_ticket_3er)}</td>
        </tr>`;
    }

    function renderTabla(grupos, todas, total, kpis) {
        const wrap = $('tabla-propios-wrap');
        if (!wrap) return;

        const bm = {
            facturacionVarMarca: kpis.facturacion_var_marca,
            ticketPromedioMarca: kpis.ticket_promedio_marca,
            pct2Marca          : kpis.pct_ticket_2do_marca,
            pct3Marca          : kpis.pct_ticket_3er_marca,
        };

        const cuerpo = grupos.map(g => {
            const filaSup = filaSubtotalHTML(g.supervisora, g.subtotal, bm);
            const filasSuc = g.sucursales.map(f => filaSucursalHTML(f, bm)).join('');
            return filaSup + filasSuc;
        }).join('')
            // "Todas": grupo de un solo miembro (Ecommerce) — el subtotal es por eso
            // numéricamente igual a la fila hija que lo compone.
            + (todas ? filaSubtotalHTML('Todas', todas, bm, todas.sin_datos, 'row-todas-separador') : '')
            + (todas ? filaEcommerceHTML(todas, bm) : '');

        wrap.innerHTML = `
            <table class="premios-table">
                <thead>
                    <tr>
                        <th>Supervisora / Sucursal</th>
                        <th class="th-num">Facturación S/IVA</th>
                        <th class="th-num">Facturación C/IVA</th>
                        <th class="th-num">Objetivo Total $</th>
                        <th class="th-num">% Cumplimiento Obj. Venta</th>
                        <th class="th-num">Facturación C/IVA Var %</th>
                        <th class="th-num">Ticket Promedio</th>
                        <th class="th-num">% Tickets 2do Producto</th>
                        <th class="th-num">% Tickets 3er Producto</th>
                    </tr>
                </thead>
                <tbody>${cuerpo}</tbody>
                <tfoot>
                    <tr class="totales">
                        <td>Total</td>
                        <td class="td-num">${fmt.money(total.facturacion_s_iva)}</td>
                        <td class="td-num">${fmt.money(total.facturacion_c_iva)}</td>
                        <td class="td-num">${fmt.money(total.objetivo_total)}</td>
                        ${cumplimientoCellHTML(total.cumplimiento_obj, total.facturacion_var, bm.facturacionVarMarca)}
                        <td class="td-num ${total.facturacion_var !== null ? (total.facturacion_var >= 0 ? 'text-green' : 'text-red') : ''}">${fmt.varPct(total.facturacion_var)}</td>
                        <td class="td-num ${claseBenchmark(total.ticket_promedio, bm.ticketPromedioMarca)}">${fmt.money(total.ticket_promedio)}</td>
                        <td class="td-num ${claseBenchmark(total.pct_ticket_2do, bm.pct2Marca)}">${fmt.pct(total.pct_ticket_2do)}</td>
                        <td class="td-num ${claseBenchmark(total.pct_ticket_3er, bm.pct3Marca)}">${fmt.pct(total.pct_ticket_3er)}</td>
                    </tr>
                </tfoot>
            </table>`;
    }

    function exportar() {
        if (!_lastGrupos.length || typeof ExcelExporter === 'undefined') return;
        const rows = [];
        _lastGrupos.forEach(g => {
            rows.push([g.supervisora, g.subtotal.facturacion_s_iva, g.subtotal.facturacion_c_iva,
                g.subtotal.objetivo_total, g.subtotal.cumplimiento_obj, g.subtotal.facturacion_var,
                g.subtotal.ticket_promedio, g.subtotal.pct_ticket_2do, g.subtotal.pct_ticket_3er]);
            g.sucursales.forEach(f => rows.push([
                '  ' + f.sucursal, f.facturacion_s_iva, f.facturacion_c_iva, f.objetivo_total,
                f.cumplimiento_obj, f.facturacion_var, f.ticket_promedio, f.pct_ticket_2do, f.pct_ticket_3er,
            ]));
        });
        if (_lastTodas) {
            rows.push(['Todas', _lastTodas.facturacion_s_iva, _lastTodas.facturacion_c_iva,
                _lastTodas.objetivo_total, _lastTodas.cumplimiento_obj, _lastTodas.facturacion_var,
                _lastTodas.ticket_promedio, _lastTodas.pct_ticket_2do, _lastTodas.pct_ticket_3er]);
            rows.push(['  ECOMMERCE', _lastTodas.facturacion_s_iva, _lastTodas.facturacion_c_iva,
                _lastTodas.objetivo_total, _lastTodas.cumplimiento_obj, _lastTodas.facturacion_var,
                _lastTodas.ticket_promedio, _lastTodas.pct_ticket_2do, _lastTodas.pct_ticket_3er]);
        }
        ExcelExporter.export({
            title  : 'Locales Propios — Facturación vs. Objetivos por Sucursales',
            headers: ['Supervisora / Sucursal', 'Facturación S/IVA', 'Facturación C/IVA', 'Objetivo Total $',
                '% Cumplimiento Obj. Venta', 'Facturación C/IVA Var %', 'Ticket Promedio',
                '% Tickets 2do Producto', '% Tickets 3er Producto'],
            rows,
            totalsRow: ['Total', _lastTotal.facturacion_s_iva, _lastTotal.facturacion_c_iva, _lastTotal.objetivo_total,
                _lastTotal.cumplimiento_obj, _lastTotal.facturacion_var, _lastTotal.ticket_promedio,
                _lastTotal.pct_ticket_2do, _lastTotal.pct_ticket_3er],
            colFormats: ['text', 'money', 'money', 'money', 'pct', 'pct', 'money', 'pct', 'pct'],
            filename: 'locales_propios_facturacion_objetivos',
        });
    }

    async function load() {
        const data = await apiFetch('propios.php');
        updatePeriodoLabel(data.periodo);
        actualizarUltimaActualizacion(data);

        _lastGrupos = data.grupos ?? [];
        _lastTodas  = data.todas ?? null;
        _lastTotal  = data.total;

        renderKpis(data.kpis ?? {});
        renderTabla(_lastGrupos, _lastTodas, _lastTotal, data.kpis ?? {});

        const btn = $('btn-export-propios-tabla');
        if (btn) btn.onclick = exportar;
    }

    return { load };
})();
