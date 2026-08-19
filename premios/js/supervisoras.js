/**
 * /bi/premios/js/supervisoras.js
 * Vista "Premios Supervisoras": cards de premio total + tabla única de detalle
 * (Locales Propios + Franquicias) por supervisora.
 */
const PremiosSupervisoras = (() => {

    const { $, fmt, updatePeriodoLabel, setSupervisoraOptions, apiFetch, actualizarUltimaActualizacion } = Premios;

    let _lastResumen = [];
    let _lastPctCadenaTotal = null;

    async function loadFiltros() {
        const data = await Premios.apiFetch('filtros.php');
        setSupervisoraOptions(data.supervisoras ?? []);
    }

    function renderHeroCards(supervisoras) {
        const wrap = $('hero-cards-wrap');
        if (!wrap) return;
        wrap.innerHTML = supervisoras.map((s, i) => `
            <div class="premio-hero-card c${i % 7}">
                <div class="premio-hero-nombre">${s.supervisora}</div>
                <div class="premio-hero-valor">${fmt.money(s.total_premios)}</div>
                <div class="premio-hero-label">Total Premios</div>
            </div>`).join('');
    }

    // Objetivo Venta / Objetivo Crecimiento: Locales Propios | Franquicias | Total (premio propios + premio franquicias).
    // Tickets (Promedio/2do/3er): Locales | Total — no aplican a franquicias.
    // % Cumpl. Cadena (última columna): % de locales PROPIOS de la cadena de esa supervisora
    // que cumplen individualmente el objetivo de venta (ver PremiosDB::pctCumplimientoCadenaVenta,
    // distinto del "cant" de premio que es un conteo a nivel empresa).
    function renderTabla(supervisoras, pctCadenaTotal) {
        const wrap = $('tabla-resumen-wrap');
        if (!wrap) return;

        const filas = supervisoras.map(s => {
            const p = s.propios;
            const f = s.franquicias;
            return `<tr>
                <td>${s.supervisora}</td>
                <td class="td-num">${p.venta.cant}</td>
                <td class="td-num">${f.venta.cant}</td>
                <td class="td-num td-premio">${fmt.money(p.venta.premio + f.venta.premio)}</td>
                <td class="td-num">${p.crecimiento.cant}</td>
                <td class="td-num">${f.crecimiento.cant}</td>
                <td class="td-num td-premio">${fmt.money(p.crecimiento.premio + f.crecimiento.premio)}</td>
                <td class="td-num">${p.ticket_promedio.cant}</td>
                <td class="td-num td-premio">${fmt.money(p.ticket_promedio.premio)}</td>
                <td class="td-num">${p.ticket_2do.cant}</td>
                <td class="td-num td-premio">${fmt.money(p.ticket_2do.premio)}</td>
                <td class="td-num">${p.ticket_3er.cant}</td>
                <td class="td-num td-premio">${fmt.money(p.ticket_3er.premio)}</td>
                <td class="td-num td-total"><strong>${fmt.money(s.total_premios)}</strong></td>
                <td class="td-num">${fmt.pct(s.pct_cumplimiento_cadena)}</td>
            </tr>`;
        }).join('');

        const totalGeneral = supervisoras.reduce((s, r) => s + r.total_premios, 0);

        wrap.innerHTML = `
            <table class="premios-table">
                <thead>
                    <tr>
                        <th rowspan="2">Supervisora</th>
                        <th colspan="3" class="th-center">Objetivo Venta</th>
                        <th colspan="3" class="th-center">Objetivo Crecimiento</th>
                        <th colspan="2" class="th-center">Ticket Promedio</th>
                        <th colspan="2" class="th-center">Ticket 2do. Producto</th>
                        <th colspan="2" class="th-center">Ticket 3er Producto</th>
                        <th rowspan="2" class="th-num th-total">Total premios</th>
                        <th rowspan="2" class="th-num">% Cumpl. Cadena</th>
                    </tr>
                    <tr>
                        <th class="th-center">Locales Propios</th><th class="th-center">Franquicias</th><th class="th-center th-premio">Total</th>
                        <th class="th-center">Locales Propios</th><th class="th-center">Franquicias</th><th class="th-center th-premio">Total</th>
                        <th class="th-center">Locales</th><th class="th-center th-premio">Total</th>
                        <th class="th-center">Locales</th><th class="th-center th-premio">Total</th>
                        <th class="th-center">Locales</th><th class="th-center th-premio">Total</th>
                    </tr>
                </thead>
                <tbody>${filas}</tbody>
                <tfoot>
                    <tr class="totales">
                        <td>Total</td><td colspan="12"></td>
                        <td class="td-num td-total">${fmt.money(totalGeneral)}</td>
                        <td class="td-num td-total">${fmt.pct(pctCadenaTotal)}</td>
                    </tr>
                </tfoot>
            </table>`;
    }

    function exportarResumen() {
        if (!_lastResumen.length || typeof ExcelExporter === 'undefined') return;
        ExcelExporter.export({
            title  : 'Premios por Supervisora',
            headers: ['Supervisora',
                'Locales Propios (Venta)', 'Franquicias (Venta)', 'Total Obj. Venta',
                'Locales Propios (Crec.)', 'Franquicias (Crec.)', 'Total Obj. Crecimiento',
                'Locales (Ticket Prom.)', 'Total Ticket Promedio',
                'Locales (2do Prod.)', 'Total Ticket 2do Prod.',
                'Locales (3er Prod.)', 'Total Ticket 3er Prod.',
                'Total premios', '% Cumpl. Cadena'],
            rows: _lastResumen.map(s => [
                s.supervisora,
                s.propios.venta.cant, s.franquicias.venta.cant, s.propios.venta.premio + s.franquicias.venta.premio,
                s.propios.crecimiento.cant, s.franquicias.crecimiento.cant, s.propios.crecimiento.premio + s.franquicias.crecimiento.premio,
                s.propios.ticket_promedio.cant, s.propios.ticket_promedio.premio,
                s.propios.ticket_2do.cant, s.propios.ticket_2do.premio,
                s.propios.ticket_3er.cant, s.propios.ticket_3er.premio,
                s.total_premios, s.pct_cumplimiento_cadena,
            ]),
            colFormats: ['text', 'num', 'num', 'money', 'num', 'num', 'money', 'num', 'money', 'num', 'money', 'num', 'money', 'money', 'pct'],
            filename: 'premios_supervisoras',
        });
    }

    async function load() {
        const data = await apiFetch('resumen.php');
        updatePeriodoLabel(data.periodo);
        actualizarUltimaActualizacion(data);

        _lastResumen = data.supervisoras ?? [];
        _lastPctCadenaTotal = data.pct_cumplimiento_cadena_total ?? null;

        renderHeroCards(_lastResumen);
        renderTabla(_lastResumen, _lastPctCadenaTotal);

        const btn = $('btn-export-resumen');
        if (btn) btn.onclick = exportarResumen;
    }

    return { loadFiltros, load };
})();
