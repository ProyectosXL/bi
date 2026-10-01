/**
 * /bi/premios/js/propios.js
 * Vista "Locales Propios": KPIs de marca + tabla de facturación vs objetivos
 * por sucursal, agrupada por supervisora con subtotales y total general.
 */
const PremiosPropios = (() => {

    const {
        $, fmt, updatePeriodoLabel, calculaCumpleObjetivo,
        cumplimientoCellHTML, badgeCellHTML, facturacionVarMarcaCellHTML, apiFetch,
        actualizarUltimaActualizacion,
        nuevoEstadoOrden, cicloOrden, iconoOrden, ordenarPor, attachSortHandlers,
    } = Premios;

    let _lastGrupos = [];
    let _lastTotal  = null;
    let _lastKpis   = {};
    // Se resetea a "sin orden" en cada load() — el tablero siempre arranca agrupado por
    // supervisora en el orden original, nunca recuerda el último click de un usuario entre
    // cargas. Ordena los GRUPOS de supervisora por su subtotal (no las sucursales sueltas,
    // para no romper el agrupado/colapsable).
    let _sortState = nuevoEstadoOrden();

    /** Valor crudo de un grupo (supervisora) para una key ordenable. */
    function valorOrdenGrupo(g, key) {
        return key === 'supervisora' ? g.supervisora : g.subtotal[key];
    }

    // Encabezados abreviados (mismo criterio que PremiosSupervisoras): headers cortos con
    // tooltip para el significado completo. $titulo vacío = sin tooltip.
    function th(texto, titulo = '', attrsExtra = '', key = null) {
        const tituloAttr = titulo ? ` title="${titulo}"` : '';
        const keyAttr = key ? ` data-sort-key="${key}"` : '';
        const contenido = key ? `${texto} ${iconoOrden(_sortState, key)}` : texto;
        return `<th${tituloAttr}${keyAttr} ${attrsExtra}>${contenido}</th>`;
    }

    function renderKpis(kpis) {
        const wrap = $('kpi-propios-wrap');
        if (!wrap) return;
        // [label, valor, tooltip]
        const cards = [
            ['Facturación Var % Promedio', fmt.varPct(kpis.facturacion_var_promedio),
                'Variación de facturación C/IVA de todas las sucursales (sin Ecommerce) contra el período anterior.'],
            ['Facturación Var % Marca',    fmt.varPct(kpis.facturacion_var_marca),
                'Benchmark de crecimiento: Facturación Var % Promedio + 10 puntos (sin Ecommerce). Es la variación que debe superar cada sucursal y la que define el Objetivo de Crecimiento $.'],
            ['Ticket Promedio Marca',      fmt.money(kpis.ticket_promedio_marca),
                'Facturación C/IVA de la marca dividida por la cantidad de tickets, redondeada hacia arriba a la centena. Es el ticket promedio que debe superar cada sucursal.'],
            ['% Tickets 2do Prod. Marca',  fmt.pct(kpis.pct_ticket_2do_marca),
                '% de tickets de la marca que incluyen un 2do producto (excluye Central y Ecommerce). Es el % que debe superar cada sucursal.'],
            ['% Tickets 3er Prod. Marca',  fmt.pct(kpis.pct_ticket_3er_marca),
                '% de tickets de la marca que incluyen un 3er producto (excluye Central y Ecommerce). Es el % que debe superar cada sucursal.'],
        ];
        wrap.innerHTML = cards.map(([label, val, tooltip]) => `
            <div class="kpi-card" title="${tooltip}">
                <div class="kpi-card-header">
                    <span class="kpi-title">${label}</span>
                    <i class="bi bi-info-circle kpi-info"></i>
                </div>
                <div class="kpi-val">${val}</div>
            </div>`).join('');
    }

    function filaSucursalHTML(f, bm, groupId) {
        // La sucursal no recibe premio (el premio de "consuelo" por crecimiento sobre marca
        // es exclusivo de la supervisora): % de Cumplimiento Obj. Venta simple, sin la regla
        // de consuelo. El badge de Facturación Var % es puramente visual (supera/no supera
        // marca, ver facturacionVarMarcaCellHTML) — no tiene relación con el premio de la fila.
        // data-grupo la liga a su fila de supervisora (filaSubtotalHTML) para el colapsar/desplegar.
        const cls = f.sin_datos ? 'row-sin-datos' : '';
        return `<tr class="${cls}" data-grupo="${groupId}">
            <td class="td-sucursal">${f.sucursal}</td>
            <td class="td-num">${fmt.money(f.facturacion_c_iva)}</td>
            <td class="td-num">${fmt.money(f.facturacion_ant)}</td>
            <td class="td-num">${fmt.money(f.objetivo_total)}</td>
            <td class="td-num">${fmt.money(f.objetivo_crecimiento)}</td>
            ${cumplimientoCellHTML(f.cumplimiento_obj, f.sin_datos)}
            ${facturacionVarMarcaCellHTML(f.facturacion_var, bm.facturacionVarMarca, fmt.varPct(f.facturacion_var))}
            ${badgeCellHTML(f.ticket_promedio, bm.ticketPromedioMarca, fmt.money(f.ticket_promedio), { titulo: 'Supera el ticket promedio de marca' })}
            ${badgeCellHTML(f.pct_ticket_2do, bm.pct2Marca, fmt.pct(f.pct_ticket_2do), { titulo: 'Supera el % tickets 2do producto de marca' })}
            ${badgeCellHTML(f.pct_ticket_3er, bm.pct3Marca, fmt.pct(f.pct_ticket_3er), { titulo: 'Supera el % tickets 3er producto de marca' })}
            <td class="td-num">—</td>
        </tr>`;
    }

    function filaSubtotalHTML(nombre, s, extraClass, groupId) {
        // Fila de supervisora: la que efectivamente recibe el premio. Sin badges
        // verdes de cumplimiento (esos son informativos por sucursal/local, ver
        // filaSucursalHTML) — acá se diferencia solo con negrita
        // (.row-supervisora td), para no duplicar la marca de "cumplió" a nivel agregado.
        // %Cumpl.Obj.Vta./Fact. Var %/Ticket Promedio/%2do/%3er en blanco a pedido del cliente
        // (2026-09-02): no hace falta mostrar el promedio de zona de estos KPIs a nivel
        // supervisora (ya se ve sucursal por sucursal debajo, y se prestaba a confundirse con
        // un objetivo).
        // data-toggle-grupo + el ícono .toggle-caret habilitan colapsar/desplegar sus sucursales
        // (data-grupo="${groupId}"), delegado en renderTabla().
        return `<tr class="row-supervisora ${extraClass ?? ''}" data-toggle-grupo="${groupId}">
            <td><i class="bi bi-chevron-down toggle-caret"></i> ${nombre}</td>
            <td class="td-num">${fmt.money(s.facturacion_c_iva)}</td>
            <td class="td-num">${fmt.money(s.facturacion_ant)}</td>
            <td class="td-num">${fmt.money(s.objetivo_total)}</td>
            <td class="td-num">${fmt.money(s.objetivo_crecimiento)}</td>
            <td class="td-num">—</td>
            <td class="td-num">—</td>
            <td class="td-num">—</td>
            <td class="td-num">—</td>
            <td class="td-num">—</td>
            <td class="td-num td-premio">${fmt.pct(s.pct_cumplimiento_cadena)}</td>
        </tr>`;
    }

    function renderTabla(grupos, total, kpis) {
        const wrap = $('tabla-propios-wrap');
        if (!wrap) return;

        const bm = {
            facturacionVarMarca: kpis.facturacion_var_marca,
            ticketPromedioMarca: kpis.ticket_promedio_marca,
            pct2Marca          : kpis.pct_ticket_2do_marca,
            pct3Marca          : kpis.pct_ticket_3er_marca,
        };
        const cumpleObjetivoTotal = calculaCumpleObjetivo(total.cumplimiento_obj, total.facturacion_var, bm.facturacionVarMarca);

        // Ordenar por cualquier columna que NO sea "Supervisora / Sucursal" aplana la tabla:
        // se pierde el agrupado y se ordenan TODAS las sucursales de TODAS las supervisoras
        // juntas, sin nombres de supervisora — a pedido del cliente (2026-09-03). Ordenar por
        // "supervisora" es la única key que mantiene el agrupado (ordena los grupos entre sí,
        // las sucursales quedan en su orden original dentro de cada uno). Sin orden activo
        // (estado por defecto) también se ve agrupado.
        const aplanada = !!_sortState.key && _sortState.key !== 'supervisora';

        let cuerpo;
        if (aplanada) {
            const todasLasSucursales = grupos.flatMap(g => g.sucursales);
            const sucursalesOrdenadas = ordenarPor(todasLasSucursales, _sortState, (f, key) => f[key]);
            cuerpo = sucursalesOrdenadas.map(f => filaSucursalHTML(f, bm, '')).join('');
        } else {
            const gruposOrdenados = ordenarPor(grupos, _sortState, valorOrdenGrupo);
            cuerpo = gruposOrdenados.map((g, i) => {
                const groupId = `g${i}`;
                const filaSup = filaSubtotalHTML(g.supervisora, g.subtotal, null, groupId);
                const filasSuc = g.sucursales.map(f => filaSucursalHTML(f, bm, groupId)).join('');
                return filaSup + filasSuc;
            }).join('');
        }

        wrap.innerHTML = `
            <table class="premios-table">
                <thead>
                    <tr>
                        ${th('Supervisora / Sucursal', 'Ordena los grupos de supervisora — las sucursales quedan en su orden original dentro de cada grupo. Ordenar por cualquier otra columna aplana la tabla (todas las sucursales juntas, sin agrupar).', '', 'supervisora')}
                        ${th('Fact. C/IVA', 'Facturación C/IVA', 'class="th-num"', 'facturacion_c_iva')}
                        ${th('Fact. Ant.', 'Facturación C/IVA del período anterior (base de Fact. Var % y del Obj. Crec. $)', 'class="th-num"', 'facturacion_ant')}
                        ${th('Obj. Total $', 'Objetivo Total $', 'class="th-num"', 'objetivo_total')}
                        ${th('Obj. Crec. $', 'Objetivo de Crecimiento (facturación necesaria para superar el benchmark de crecimiento de marca)', 'class="th-num"', 'objetivo_crecimiento')}
                        ${th('%Cumpl.Obj.Vta.', '% Cumplimiento Obj. Venta', 'class="th-num"', 'cumplimiento_obj')}
                        ${th('Fact. Var %', 'Facturación C/IVA Var %', 'class="th-num"', 'facturacion_var')}
                        ${th('Tkt. Prom.', 'Ticket Promedio', 'class="th-num"', 'ticket_promedio')}
                        ${th('%Tkt.2°P.', '% Tickets 2do Producto', 'class="th-num"', 'pct_ticket_2do')}
                        ${th('%Tkt.3°P.', '% Tickets 3er Producto', 'class="th-num"', 'pct_ticket_3er')}
                        ${th('%Cumpl.Coach', '% Cumplimiento Coach (solo a nivel supervisora/total, no ordenable)', 'class="th-num"')}
                    </tr>
                </thead>
                <tbody>${cuerpo}</tbody>
                <tfoot>
                    <tr class="totales">
                        <td>Total</td>
                        <td class="td-num">${fmt.money(total.facturacion_c_iva)}</td>
                        <td class="td-num">${fmt.money(total.facturacion_ant)}</td>
                        <td class="td-num">${fmt.money(total.objetivo_total)}</td>
                        <td class="td-num">${fmt.money(total.objetivo_crecimiento)}</td>
                        ${cumplimientoCellHTML(total.cumplimiento_obj)}
                        ${facturacionVarMarcaCellHTML(total.facturacion_var, bm.facturacionVarMarca, fmt.varPct(total.facturacion_var))}
                        ${badgeCellHTML(total.ticket_promedio, bm.ticketPromedioMarca, fmt.money(total.ticket_promedio), { titulo: 'Supera el ticket promedio de marca', gate: cumpleObjetivoTotal })}
                        ${badgeCellHTML(total.pct_ticket_2do, bm.pct2Marca, fmt.pct(total.pct_ticket_2do), { titulo: 'Supera el % tickets 2do producto de marca', gate: cumpleObjetivoTotal })}
                        ${badgeCellHTML(total.pct_ticket_3er, bm.pct3Marca, fmt.pct(total.pct_ticket_3er), { titulo: 'Supera el % tickets 3er producto de marca', gate: cumpleObjetivoTotal })}
                        <td class="td-num td-total">${fmt.pct(total.pct_cumplimiento_cadena)}</td>
                    </tr>
                </tfoot>
            </table>`;

        attachToggleColapsar(wrap);
        attachSortHandlers(wrap, key => {
            _sortState = cicloOrden(_sortState, key);
            renderTabla(_lastGrupos, _lastTotal, _lastKpis);
        });
    }

    /** Click en una fila de supervisora colapsa u despliega sus sucursales hijas. */
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
            rows.push([g.supervisora, g.subtotal.facturacion_c_iva, g.subtotal.facturacion_ant,
                g.subtotal.objetivo_total, g.subtotal.objetivo_crecimiento, g.subtotal.cumplimiento_obj,
                g.subtotal.facturacion_var, g.subtotal.ticket_promedio, g.subtotal.pct_ticket_2do,
                g.subtotal.pct_ticket_3er, g.subtotal.pct_cumplimiento_cadena]);
            g.sucursales.forEach(f => rows.push([
                '  ' + f.sucursal, f.facturacion_c_iva, f.facturacion_ant, f.objetivo_total,
                f.objetivo_crecimiento, f.cumplimiento_obj, f.facturacion_var, f.ticket_promedio,
                f.pct_ticket_2do, f.pct_ticket_3er, null,
            ]));
        });
        ExcelExporter.export({
            title  : 'Locales Propios — Facturación vs. Objetivos por Sucursales',
            headers: ['Supervisora / Sucursal', 'Facturación C/IVA', 'Facturación C/IVA Período Anterior', 'Objetivo Total $',
                'Objetivo de Crecimiento $', '% Cumplimiento Obj. Venta', 'Facturación C/IVA Var %',
                'Ticket Promedio', '% Tickets 2do Producto', '% Tickets 3er Producto', '% Cumpl. Coach'],
            rows,
            totalsRow: ['Total', _lastTotal.facturacion_c_iva, _lastTotal.facturacion_ant, _lastTotal.objetivo_total,
                _lastTotal.objetivo_crecimiento, _lastTotal.cumplimiento_obj, _lastTotal.facturacion_var,
                _lastTotal.ticket_promedio, _lastTotal.pct_ticket_2do, _lastTotal.pct_ticket_3er,
                _lastTotal.pct_cumplimiento_cadena],
            colFormats: ['text', 'money', 'money', 'money', 'money', 'pct', 'pct', 'money', 'pct', 'pct', 'pct'],
            filename: 'locales_propios_facturacion_objetivos',
        });
    }

    async function load() {
        _sortState = nuevoEstadoOrden();
        const data = await apiFetch('propios.php');
        updatePeriodoLabel(data.periodo);
        actualizarUltimaActualizacion(data);

        _lastGrupos = data.grupos ?? [];
        _lastTotal  = data.total;
        _lastKpis   = data.kpis ?? {};

        renderKpis(_lastKpis);
        renderTabla(_lastGrupos, _lastTotal, _lastKpis);

        const btn = $('btn-export-propios-tabla');
        if (btn) btn.onclick = exportar;
    }

    return { load };
})();
