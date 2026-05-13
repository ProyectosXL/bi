/**
 * /bi/global/js/franquicias_detalle.js
 * Módulo para la pestaña "Venta día por día" (exclusiva de Franquicias).
 */
const FranquiciasDetalle = (function () {
    const PANE_ID = 'tab-franquicias-detalle';
    const WRAP_ID = 'franquicias-detalle-wrap';
    
    let _state = {
        dias: [],
        sucursales: [],
        data: {},
        prevTotals: {},
        periodo: {}
    };

    async function loadAll() {
        const wrap = document.getElementById(WRAP_ID);
        if (!wrap) return;

        wrap.innerHTML = '<div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando venta día por día...</span></div>';

        try {
            const params = Dashboard.getParams();
            const res = await Dashboard.apiFetch('franquicias_detalle.php', params);
            
            if (!res.ok) throw new Error(res.error || 'Error al cargar datos');

            _state = res;
            renderTable();
        } catch (err) {
            console.error('[FranquiciasDetalle] Error:', err);
            wrap.innerHTML = `<div class="analisis-error"><i class="bi bi-exclamation-triangle"></i> ${err.message}</div>`;
        }
    }

    function renderTable() {
        const wrap = document.getElementById(WRAP_ID);
        if (!wrap) return;

        if (_state.sucursales.length === 0) {
            wrap.innerHTML = '<div class="analisis-empty">No hay datos para mostrar en este período.</div>';
            return;
        }

        let html = `
            <table class="ranking-table rk-detalle-table" id="tabla-fran-detalle">
                <thead>
                    <tr>
                        <th style="position:sticky; left:0; z-index:10; background:var(--bg-card)">Día / Sucursal</th>
        `;

        _state.sucursales.forEach(s => {
            html += `<th style="text-align:right; min-width:120px">${s.nombre}</th>`;
        });

        html += `
                        <th style="text-align:right; font-weight:700; background:rgba(255,255,255,0.05)">TOTAL DÍA</th>
                    </tr>
                </thead>
                <tbody>
        `;

        _state.dias.forEach(d => {
            html += `<tr><td style="position:sticky; left:0; z-index:5; background:var(--bg-card2); font-weight:600" data-t="s">${d.label}</td>`;
            let totalDia = 0;
            _state.sucursales.forEach(s => {
                const val = _state.data[s.nro]?.[d.dia] || 0;
                totalDia += val;
                html += `<td style="text-align:right" data-t="n" data-v="${val}">${val > 0 ? Dashboard.fmt.money(val) : '—'}</td>`;
            });
            html += `<td style="text-align:right; font-weight:700; background:rgba(255,255,255,0.05)" data-t="n" data-v="${totalDia}">${totalDia > 0 ? Dashboard.fmt.money(totalDia) : '—'}</td></tr>`;
        });

        // Fila de TOTALES
        html += `
                </tbody>
                <tfoot>
                    <tr style="background:var(--accent2); color:#fff; font-weight:700">
                        <td style="position:sticky; left:0; z-index:10; background:var(--accent2)">TOTAL PERÍODO</td>
        `;
        
        let totalGeneral = 0;
        _state.sucursales.forEach(s => {
            let totalSuc = 0;
            Object.values(_state.data[s.nro] || {}).forEach(v => totalSuc += v);
            totalGeneral += totalSuc;
            html += `<td style="text-align:right" data-t="n" data-v="${totalSuc}">${Dashboard.fmt.money(totalSuc)}</td>`;
        });
        html += `<td style="text-align:right" data-t="n" data-v="${totalGeneral}">${Dashboard.fmt.money(totalGeneral)}</td></tr>`;

        // Fila de PERÍODO PREVIO
        html += `
                    <tr style="background:rgba(255,255,255,0.05); color:var(--text-2)">
                        <td style="position:sticky; left:0; z-index:10; background:var(--bg-card)">AÑO ANTERIOR</td>
        `;
        let totalPrevGeneral = 0;
        _state.sucursales.forEach(s => {
            const valPrev = _state.prevTotals[s.nro] || 0;
            totalPrevGeneral += valPrev;
            html += `<td style="text-align:right" data-t="n" data-v="${valPrev}">${Dashboard.fmt.money(valPrev)}</td>`;
        });
        html += `<td style="text-align:right" data-t="n" data-v="${totalPrevGeneral}">${Dashboard.fmt.money(totalPrevGeneral)}</td></tr>`;

        // Fila de CRECIMIENTO
        html += `
                    <tr style="font-weight:600">
                        <td style="position:sticky; left:0; z-index:10; background:var(--bg-card)">CRECIMIENTO</td>
        `;
        _state.sucursales.forEach(s => {
            let totalSuc = 0;
            Object.values(_state.data[s.nro] || {}).forEach(v => totalSuc += v);
            const valPrev = _state.prevTotals[s.nro] || 0;
            const growth = valPrev > 0 ? (totalSuc - valPrev) / valPrev : (totalSuc > 0 ? 1 : 0);
            const cls = growth >= 0 ? 'text-pos' : 'text-neg';
            html += `<td style="text-align:right" class="${cls}" data-t="n" data-v="${growth}">${Dashboard.fmt.pct(growth)}</td>`;
        });
        const totalGrowth = totalPrevGeneral > 0 ? (totalGeneral - totalPrevGeneral) / totalPrevGeneral : (totalGeneral > 0 ? 1 : 0);
        const clsGen = totalGrowth >= 0 ? 'text-pos' : 'text-neg';
        html += `<td style="text-align:right" class="${clsGen}" data-t="n" data-v="${totalGrowth}">${Dashboard.fmt.pct(totalGrowth)}</td></tr>`;

        html += `
                </tfoot>
            </table>
        `;

        wrap.innerHTML = html;
    }

    function exportToExcel() {
        if (_state.sucursales.length === 0) return;
        
        const table = document.getElementById('tabla-fran-detalle');
        if (!table) return;

        const filename = `Venta_Dia_x_Dia_Franquicias_${_state.periodo.act.desde}_al_${_state.periodo.act.hasta}`;
        ExcelExporter.exportTable(table, filename);
    }

    // Inicializar listener de exportación
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('btn-export-franquicias-detalle');
        if (btn) btn.addEventListener('click', exportToExcel);
    });

    return {
        loadAll
    };
})();
