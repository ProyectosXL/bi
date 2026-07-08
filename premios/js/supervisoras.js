/**
 * /bi/premios/js/supervisoras.js
 * Vista "Premios Supervisoras": cards de premio total + tablas de detalle
 * (Locales Propios y Franquicias) por supervisora.
 */
const PremiosSupervisoras = (() => {

    const { $, fmt, updatePeriodoLabel, setSupervisoraOptions, apiFetch, actualizarUltimaActualizacion } = Premios;

    let _lastPropios = [];
    let _lastFranquicias = [];

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

    function renderTablaPropios(supervisoras) {
        const wrap = $('tabla-resumen-propios-wrap');
        if (!wrap) return;

        const filas = supervisoras.map(s => {
            const p = s.propios;
            return `<tr>
                <td>${s.supervisora}</td>
                <td class="td-num">${p.venta.cant}</td>
                <td class="td-num">${fmt.money(p.venta.importe)}</td>
                <td class="td-num">${fmt.money(p.venta.premio)}</td>
                <td class="td-num">${p.crecimiento.cant}</td>
                <td class="td-num">${fmt.money(p.crecimiento.importe)}</td>
                <td class="td-num">${fmt.money(p.crecimiento.premio)}</td>
                <td class="td-num">${p.ticket_promedio.cant}</td>
                <td class="td-num">${fmt.money(p.ticket_promedio.importe)}</td>
                <td class="td-num">${fmt.money(p.ticket_promedio.premio)}</td>
                <td class="td-num">${p.ticket_2do.cant}</td>
                <td class="td-num">${fmt.money(p.ticket_2do.importe)}</td>
                <td class="td-num">${fmt.money(p.ticket_2do.premio)}</td>
                <td class="td-num">${p.ticket_3er.cant}</td>
                <td class="td-num">${fmt.money(p.ticket_3er.importe)}</td>
                <td class="td-num">${fmt.money(p.ticket_3er.premio)}</td>
                <td class="td-num"><strong>${fmt.money(p.total)}</strong></td>
            </tr>`;
        }).join('');

        const totalGeneral = supervisoras.reduce((s, r) => s + r.propios.total, 0);

        wrap.innerHTML = `
            <table class="premios-table">
                <thead>
                    <tr>
                        <th rowspan="2">Supervisora</th>
                        <th colspan="3" class="th-center">Objetivo Venta</th>
                        <th colspan="3" class="th-center">Objetivo Crecimiento</th>
                        <th colspan="3" class="th-center">Ticket Promedio</th>
                        <th colspan="3" class="th-center">Ticket 2do. Producto</th>
                        <th colspan="3" class="th-center">Ticket 3er Producto</th>
                        <th rowspan="2" class="th-num">Total premios</th>
                    </tr>
                    <tr>
                        <th class="th-center">Cant. Locales</th><th class="th-center">Importe</th><th class="th-center">Premio</th>
                        <th class="th-center">Cant. Locales</th><th class="th-center">Importe</th><th class="th-center">Premio</th>
                        <th class="th-center">Cant. Locales</th><th class="th-center">Importe</th><th class="th-center">Premio</th>
                        <th class="th-center">Cant. Locales</th><th class="th-center">Importe</th><th class="th-center">Premio</th>
                        <th class="th-center">Cant. Locales</th><th class="th-center">Importe</th><th class="th-center">Premio</th>
                    </tr>
                </thead>
                <tbody>${filas}</tbody>
                <tfoot>
                    <tr class="totales">
                        <td>Total</td><td colspan="15"></td>
                        <td class="td-num">${fmt.money(totalGeneral)}</td>
                    </tr>
                </tfoot>
            </table>`;
    }

    function renderTablaFranquicias(supervisoras) {
        const wrap = $('tabla-resumen-franquicias-wrap');
        if (!wrap) return;

        const filas = supervisoras.map(s => {
            const f = s.franquicias;
            return `<tr>
                <td>${s.supervisora}</td>
                <td class="td-num">${f.venta.cant}</td>
                <td class="td-num">${fmt.money(f.venta.importe)}</td>
                <td class="td-num">${fmt.money(f.venta.premio)}</td>
                <td class="td-num">${f.crecimiento.cant}</td>
                <td class="td-num">${fmt.money(f.crecimiento.importe)}</td>
                <td class="td-num">${fmt.money(f.crecimiento.premio)}</td>
                <td class="td-num"><strong>${fmt.money(f.total)}</strong></td>
            </tr>`;
        }).join('');

        const totalGeneral = supervisoras.reduce((s, r) => s + r.franquicias.total, 0);

        wrap.innerHTML = `
            <table class="premios-table">
                <thead>
                    <tr>
                        <th rowspan="2">Supervisora</th>
                        <th colspan="3" class="th-center">Objetivo Venta Franq.</th>
                        <th colspan="3" class="th-center">Objetivo Crecimiento Franq.</th>
                        <th rowspan="2" class="th-num">Total premios</th>
                    </tr>
                    <tr>
                        <th class="th-center">Cant. Franquicias</th><th class="th-center">Importe</th><th class="th-center">Premio</th>
                        <th class="th-center">Cant. Franquicias</th><th class="th-center">Importe</th><th class="th-center">Premio</th>
                    </tr>
                </thead>
                <tbody>${filas}</tbody>
                <tfoot>
                    <tr class="totales">
                        <td>Total</td><td colspan="6"></td>
                        <td class="td-num">${fmt.money(totalGeneral)}</td>
                    </tr>
                </tfoot>
            </table>`;
    }

    function exportarPropios() {
        if (!_lastPropios.length || typeof ExcelExporter === 'undefined') return;
        ExcelExporter.export({
            title  : 'Premios por Supervisora (Locales Propios)',
            headers: ['Supervisora',
                'Cant. Locales (Venta)', 'Importe', 'Premio Obj. Venta',
                'Cant. Locales (Crec.)', 'Importe', 'Premio Obj. Crecimiento',
                'Cant. Locales (Ticket)', 'Importe', 'Premio Ticket Promedio',
                'Cant. Locales (2do)', 'Importe', 'Premio Ticket 2do Prod.',
                'Cant. Locales (3er)', 'Importe', 'Premio Ticket 3er Prod.',
                'Total premios'],
            rows: _lastPropios.map(s => [
                s.supervisora,
                s.propios.venta.cant, s.propios.venta.importe, s.propios.venta.premio,
                s.propios.crecimiento.cant, s.propios.crecimiento.importe, s.propios.crecimiento.premio,
                s.propios.ticket_promedio.cant, s.propios.ticket_promedio.importe, s.propios.ticket_promedio.premio,
                s.propios.ticket_2do.cant, s.propios.ticket_2do.importe, s.propios.ticket_2do.premio,
                s.propios.ticket_3er.cant, s.propios.ticket_3er.importe, s.propios.ticket_3er.premio,
                s.propios.total,
            ]),
            colFormats: ['text', 'num', 'money', 'money', 'num', 'money', 'money', 'num', 'money', 'money', 'num', 'money', 'money', 'num', 'money', 'money', 'money'],
            filename: 'premios_supervisoras_locales_propios',
        });
    }

    function exportarFranquicias() {
        if (!_lastFranquicias.length || typeof ExcelExporter === 'undefined') return;
        ExcelExporter.export({
            title  : 'Premios por Supervisora (Franquicias)',
            headers: ['Supervisora',
                'Cant. Franquicias (Venta)', 'Importe', 'Premio Obj. Venta Franq.',
                'Cant. Franquicias (Crec.)', 'Importe', 'Premio Obj. Crecimiento Franq.',
                'Total premios'],
            rows: _lastFranquicias.map(s => [
                s.supervisora,
                s.franquicias.venta.cant, s.franquicias.venta.importe, s.franquicias.venta.premio,
                s.franquicias.crecimiento.cant, s.franquicias.crecimiento.importe, s.franquicias.crecimiento.premio,
                s.franquicias.total,
            ]),
            colFormats: ['text', 'num', 'money', 'money', 'num', 'money', 'money', 'money'],
            filename: 'premios_supervisoras_franquicias',
        });
    }

    async function load() {
        const data = await apiFetch('resumen.php');
        updatePeriodoLabel(data.periodo);
        actualizarUltimaActualizacion(data);

        _lastPropios = data.supervisoras ?? [];
        _lastFranquicias = data.supervisoras ?? [];

        renderHeroCards(data.supervisoras ?? []);
        renderTablaPropios(data.supervisoras ?? []);
        renderTablaFranquicias(data.supervisoras ?? []);

        const btnPropios = $('btn-export-propios-resumen');
        if (btnPropios) btnPropios.onclick = exportarPropios;
        const btnFranquicias = $('btn-export-franquicias-resumen');
        if (btnFranquicias) btnFranquicias.onclick = exportarFranquicias;
    }

    return { loadFiltros, load };
})();
