/**
 * /bi/premios/js/propios.js
 * Vista "Locales Propios": KPIs de marca + tabla de facturación vs objetivos
 * por sucursal, agrupada por supervisora con subtotales y total general.
 */
const PremiosPropios = (() => {

    const {
        $, fmt, updatePeriodoLabel, calculaCumpleObjetivo, calculaCumplePorConsuelo,
        cumplimientoCellHTML, badgeCellHTML, facturacionVarMarcaCellHTML, apiFetch,
        actualizarUltimaActualizacion,
    } = Premios;

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

    function filaSucursalHTML(f, bm, groupId) {
        // La sucursal no recibe premio (el premio de "consuelo" por crecimiento sobre marca
        // es exclusivo de la supervisora): % de Cumplimiento Obj. Venta simple, sin la regla
        // de consuelo. El badge de Facturación Var % es solo informativo (sin gate de "premio"
        // de fila), pero igual respeta la exclusión mutua con la columna de Cumplimiento: si la
        // sucursal ya cumplió directo, no se le repite el badge acá aunque también supere marca.
        // data-grupo la liga a su fila de supervisora (filaSubtotalHTML) para el colapsar/desplegar.
        const cumplePorConsuelo = calculaCumplePorConsuelo(f.cumplimiento_obj, f.facturacion_var, bm.facturacionVarMarca, f.sin_datos);
        const cls = f.sin_datos ? 'row-sin-datos' : '';
        return `<tr class="${cls}" data-grupo="${groupId}">
            <td class="td-sucursal">${f.sucursal}</td>
            <td class="td-num">${fmt.money(f.facturacion_s_iva)}</td>
            <td class="td-num">${fmt.money(f.facturacion_c_iva)}</td>
            <td class="td-num">${fmt.money(f.objetivo_total)}</td>
            ${cumplimientoCellHTML(f.cumplimiento_obj, f.sin_datos)}
            ${facturacionVarMarcaCellHTML(f.facturacion_var, bm.facturacionVarMarca, cumplePorConsuelo, fmt.varPct(f.facturacion_var))}
            ${badgeCellHTML(f.ticket_promedio, bm.ticketPromedioMarca, fmt.money(f.ticket_promedio), { titulo: 'Supera el ticket promedio de marca' })}
            ${badgeCellHTML(f.pct_ticket_2do, bm.pct2Marca, fmt.pct(f.pct_ticket_2do), { titulo: 'Supera el % tickets 2do producto de marca' })}
            ${badgeCellHTML(f.pct_ticket_3er, bm.pct3Marca, fmt.pct(f.pct_ticket_3er), { titulo: 'Supera el % tickets 3er producto de marca' })}
            <td class="td-num">—</td>
        </tr>`;
    }

    function filaSubtotalHTML(nombre, s, bm, sinDatos, extraClass, groupId) {
        // Fila de supervisora (o "Todas"): la que efectivamente recibe el premio. El
        // cumplimiento de objetivo (directo o de consuelo) es el "gate" del resto de badges
        // de la fila — ninguno de ellos debe verse como "cumplido" si la fila no ganó el premio.
        // data-toggle-grupo + el ícono .toggle-caret habilitan colapsar/desplegar sus sucursales
        // (data-grupo="${groupId}"), delegado en renderTabla().
        const cumpleObjetivo = calculaCumpleObjetivo(s.cumplimiento_obj, s.facturacion_var, bm.facturacionVarMarca, sinDatos);
        const cumplePorConsuelo = calculaCumplePorConsuelo(s.cumplimiento_obj, s.facturacion_var, bm.facturacionVarMarca, sinDatos);
        return `<tr class="row-supervisora ${extraClass ?? ''}" data-toggle-grupo="${groupId}">
            <td><i class="bi bi-chevron-down toggle-caret"></i> ${nombre}</td>
            <td class="td-num">${fmt.money(s.facturacion_s_iva)}</td>
            <td class="td-num">${fmt.money(s.facturacion_c_iva)}</td>
            <td class="td-num">${fmt.money(s.objetivo_total)}</td>
            ${cumplimientoCellHTML(s.cumplimiento_obj, sinDatos)}
            ${facturacionVarMarcaCellHTML(s.facturacion_var, bm.facturacionVarMarca, cumplePorConsuelo, fmt.varPct(s.facturacion_var))}
            ${badgeCellHTML(s.ticket_promedio, bm.ticketPromedioMarca, fmt.money(s.ticket_promedio), { titulo: 'Supera el ticket promedio de marca', gate: cumpleObjetivo })}
            ${badgeCellHTML(s.pct_ticket_2do, bm.pct2Marca, fmt.pct(s.pct_ticket_2do), { titulo: 'Supera el % tickets 2do producto de marca', gate: cumpleObjetivo })}
            ${badgeCellHTML(s.pct_ticket_3er, bm.pct3Marca, fmt.pct(s.pct_ticket_3er), { titulo: 'Supera el % tickets 3er producto de marca', gate: cumpleObjetivo })}
            <td class="td-num td-premio">${fmt.pct(s.pct_cumplimiento_cadena)}</td>
        </tr>`;
    }

    function filaEcommerceHTML(f, bm, groupId) {
        // Fila sintética SUPERVISORA='TODAS' en la BD = canal ECOMMERCE, hija del grupo
        // "Todas" (no pertenece a ninguna supervisora real) — indentada igual que una
        // sucursal bajo su supervisora. Es una fila de "local", no de supervisora: igual que
        // filaSucursalHTML, sin la regla de consuelo ni gate en el resto de columnas (pero sí
        // con la exclusión mutua entre Cumplimiento y Facturación Var %, ver esa función).
        const cumplePorConsuelo = calculaCumplePorConsuelo(f.cumplimiento_obj, f.facturacion_var, bm.facturacionVarMarca, f.sin_datos);
        const cls = f.sin_datos ? 'row-sin-datos' : '';
        return `<tr class="${cls}" data-grupo="${groupId}">
            <td class="td-sucursal">ECOMMERCE</td>
            <td class="td-num">${fmt.money(f.facturacion_s_iva)}</td>
            <td class="td-num">${fmt.money(f.facturacion_c_iva)}</td>
            <td class="td-num">${fmt.money(f.objetivo_total)}</td>
            ${cumplimientoCellHTML(f.cumplimiento_obj, f.sin_datos)}
            ${facturacionVarMarcaCellHTML(f.facturacion_var, bm.facturacionVarMarca, cumplePorConsuelo, fmt.varPct(f.facturacion_var))}
            ${badgeCellHTML(f.ticket_promedio, bm.ticketPromedioMarca, fmt.money(f.ticket_promedio), { titulo: 'Supera el ticket promedio de marca' })}
            ${badgeCellHTML(f.pct_ticket_2do, bm.pct2Marca, fmt.pct(f.pct_ticket_2do), { titulo: 'Supera el % tickets 2do producto de marca' })}
            ${badgeCellHTML(f.pct_ticket_3er, bm.pct3Marca, fmt.pct(f.pct_ticket_3er), { titulo: 'Supera el % tickets 3er producto de marca' })}
            <td class="td-num">—</td>
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
        const cumpleObjetivoTotal = calculaCumpleObjetivo(total.cumplimiento_obj, total.facturacion_var, bm.facturacionVarMarca);
        const cumplePorConsueloTotal = calculaCumplePorConsuelo(total.cumplimiento_obj, total.facturacion_var, bm.facturacionVarMarca);

        const cuerpo = grupos.map((g, i) => {
            const groupId = `g${i}`;
            const filaSup = filaSubtotalHTML(g.supervisora, g.subtotal, bm, false, null, groupId);
            const filasSuc = g.sucursales.map(f => filaSucursalHTML(f, bm, groupId)).join('');
            return filaSup + filasSuc;
        }).join('')
            // "Todas": grupo de un solo miembro (Ecommerce) — el subtotal es por eso
            // numéricamente igual a la fila hija que lo compone.
            + (todas ? filaSubtotalHTML('Todas', todas, bm, todas.sin_datos, 'row-todas-separador', 'todas') : '')
            + (todas ? filaEcommerceHTML(todas, bm, 'todas') : '');

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
                        <th class="th-num">% Cumpl. Cadena</th>
                    </tr>
                </thead>
                <tbody>${cuerpo}</tbody>
                <tfoot>
                    <tr class="totales">
                        <td>Total</td>
                        <td class="td-num">${fmt.money(total.facturacion_s_iva)}</td>
                        <td class="td-num">${fmt.money(total.facturacion_c_iva)}</td>
                        <td class="td-num">${fmt.money(total.objetivo_total)}</td>
                        ${cumplimientoCellHTML(total.cumplimiento_obj)}
                        ${facturacionVarMarcaCellHTML(total.facturacion_var, bm.facturacionVarMarca, cumplePorConsueloTotal, fmt.varPct(total.facturacion_var))}
                        ${badgeCellHTML(total.ticket_promedio, bm.ticketPromedioMarca, fmt.money(total.ticket_promedio), { titulo: 'Supera el ticket promedio de marca', gate: cumpleObjetivoTotal })}
                        ${badgeCellHTML(total.pct_ticket_2do, bm.pct2Marca, fmt.pct(total.pct_ticket_2do), { titulo: 'Supera el % tickets 2do producto de marca', gate: cumpleObjetivoTotal })}
                        ${badgeCellHTML(total.pct_ticket_3er, bm.pct3Marca, fmt.pct(total.pct_ticket_3er), { titulo: 'Supera el % tickets 3er producto de marca', gate: cumpleObjetivoTotal })}
                        <td class="td-num td-total">${fmt.pct(total.pct_cumplimiento_cadena)}</td>
                    </tr>
                </tfoot>
            </table>`;

        attachToggleColapsar(wrap);
    }

    /** Click en una fila de supervisora/"Todas" colapsa u despliega sus sucursales hijas. */
    function attachToggleColapsar(wrap) {
        wrap.querySelectorAll('tr[data-toggle-grupo]').forEach(header => {
            header.addEventListener('click', () => {
                const id = header.dataset.toggleGrupo;
                const colapsado = header.classList.toggle('colapsada');
                wrap.querySelectorAll(`tr[data-grupo="${id}"]`).forEach(fila => {
                    fila.classList.toggle('fila-oculta', colapsado);
                });
            });
        });
    }

    function exportar() {
        if (!_lastGrupos.length || typeof ExcelExporter === 'undefined') return;
        const rows = [];
        _lastGrupos.forEach(g => {
            rows.push([g.supervisora, g.subtotal.facturacion_s_iva, g.subtotal.facturacion_c_iva,
                g.subtotal.objetivo_total, g.subtotal.cumplimiento_obj, g.subtotal.facturacion_var,
                g.subtotal.ticket_promedio, g.subtotal.pct_ticket_2do, g.subtotal.pct_ticket_3er,
                g.subtotal.pct_cumplimiento_cadena]);
            g.sucursales.forEach(f => rows.push([
                '  ' + f.sucursal, f.facturacion_s_iva, f.facturacion_c_iva, f.objetivo_total,
                f.cumplimiento_obj, f.facturacion_var, f.ticket_promedio, f.pct_ticket_2do, f.pct_ticket_3er,
                null,
            ]));
        });
        if (_lastTodas) {
            rows.push(['Todas', _lastTodas.facturacion_s_iva, _lastTodas.facturacion_c_iva,
                _lastTodas.objetivo_total, _lastTodas.cumplimiento_obj, _lastTodas.facturacion_var,
                _lastTodas.ticket_promedio, _lastTodas.pct_ticket_2do, _lastTodas.pct_ticket_3er, null]);
            rows.push(['  ECOMMERCE', _lastTodas.facturacion_s_iva, _lastTodas.facturacion_c_iva,
                _lastTodas.objetivo_total, _lastTodas.cumplimiento_obj, _lastTodas.facturacion_var,
                _lastTodas.ticket_promedio, _lastTodas.pct_ticket_2do, _lastTodas.pct_ticket_3er, null]);
        }
        ExcelExporter.export({
            title  : 'Locales Propios — Facturación vs. Objetivos por Sucursales',
            headers: ['Supervisora / Sucursal', 'Facturación S/IVA', 'Facturación C/IVA', 'Objetivo Total $',
                '% Cumplimiento Obj. Venta', 'Facturación C/IVA Var %', 'Ticket Promedio',
                '% Tickets 2do Producto', '% Tickets 3er Producto', '% Cumpl. Cadena'],
            rows,
            totalsRow: ['Total', _lastTotal.facturacion_s_iva, _lastTotal.facturacion_c_iva, _lastTotal.objetivo_total,
                _lastTotal.cumplimiento_obj, _lastTotal.facturacion_var, _lastTotal.ticket_promedio,
                _lastTotal.pct_ticket_2do, _lastTotal.pct_ticket_3er, _lastTotal.pct_cumplimiento_cadena],
            colFormats: ['text', 'money', 'money', 'money', 'pct', 'pct', 'money', 'pct', 'pct', 'pct'],
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
