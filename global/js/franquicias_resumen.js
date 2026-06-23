/**
 * /bi/global/js/franquicias_resumen.js
 * Módulo para la pestaña "Resumen Anual Anterior" (exclusiva de Franquicias).
 */
const FranquiciasResumen = (function () {
    const PANE_ID = 'tab-franquicias-resumen';
    const WRAP_ID = 'franquicias-resumen-wrap';
    
    let _state = {
        meses: [],
        sucursales: [],
        data: {},
        periodo: {}
    };

    async function loadAll() {
        const wrap = document.getElementById(WRAP_ID);
        if (!wrap) return;

        wrap.innerHTML = '<div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando Resumen Anual Anterior...</span></div>';

        try {
            const params = Dashboard.getParams();
            const res = await Dashboard.apiFetch('franquicias_resumen.php', params);
            
            if (!res.ok) throw new Error(res.error || 'Error al cargar datos');

            _state = res;
            renderTable();
        } catch (err) {
            console.error('[FranquiciasResumen] Error:', err);
            wrap.innerHTML = `<div class="analisis-error"><i class="bi bi-exclamation-triangle"></i> ${err.message}</div>`;
        }
    }

    function renderTable() {
        const wrap = document.getElementById(WRAP_ID);
        if (!wrap) return;

        if (_state.sucursales.length === 0 || _state.meses.length === 0) {
            wrap.innerHTML = '<div class="analisis-empty">No hay datos para mostrar en este período.</div>';
            return;
        }

        // 1. Calcular promedios por sucursal
        const numMeses = _state.meses.length;
        const promedios = {};
        _state.sucursales.forEach(s => {
            let sumAct = 0;
            let sumPrev = 0;
            _state.meses.forEach(m => {
                const val = _state.data[s.nro]?.[m.key] || { act: 0, prev: 0, obj: 0 };
                sumAct += val.act || 0;
                sumPrev += val.prev || 0;
            });
            promedios[s.nro] = {
                act: sumAct / numMeses,
                prev: sumPrev / numMeses
            };
        });

        // 2. Armar cabecera de la tabla
        let html = `
            <table class="ranking-table rk-detalle-table" id="tabla-fran-resumen">
                <thead>
                    <tr>
                        <th style="position:sticky; left:0; z-index:10; background:var(--bg-header); min-width:180px; width:180px;">Mes</th>
                        <th style="position:sticky; left:180px; z-index:10; background:var(--bg-header); min-width:220px; width:220px;">Indicador</th>
        `;

        _state.sucursales.forEach(s => {
            html += `<th style="text-align:right; min-width:140px">${s.nombre}</th>`;
        });

        html += `
                    </tr>
                </thead>
                <tbody>
        `;

        // 3. Fila de Promedios
        // Promedio ventas año anterior
        html += `
            <tr style="background:rgba(255, 255, 255, 0.03); font-weight:600">
                <td style="position:sticky; left:0; z-index:5; background:var(--bg-card2); font-weight:bold" rowspan="2">Promedios</td>
                <td style="position:sticky; left:180px; z-index:5; background:var(--bg-card2)">Promedio ventas año ant.</td>
        `;
        _state.sucursales.forEach(s => {
            const p = promedios[s.nro].prev;
            html += `<td style="text-align:right" data-t="n" data-v="${p}">${p > 0 ? Dashboard.fmt.money(p) : '—'}</td>`;
        });
        html += `</tr>`;

        // Promedio ventas actual
        html += `
            <tr style="background:rgba(255, 255, 255, 0.03); font-weight:600">
                <td style="position:sticky; left:180px; z-index:5; background:var(--bg-card2)">Promedio de ventas actual</td>
        `;
        _state.sucursales.forEach(s => {
            const p = promedios[s.nro].act;
            html += `<td style="text-align:right" data-t="n" data-v="${p}">${p > 0 ? Dashboard.fmt.money(p) : '—'}</td>`;
        });
        html += `</tr>`;

        // Separador visual
        html += `<tr style="height:12px; background:transparent;"><td colspan="${_state.sucursales.length + 2}"></td></tr>`;

        // 4. Bloques mensuales
        _state.meses.forEach(m => {
            // Ventas año anterior
            html += `
                <tr>
                    <td style="position:sticky; left:0; z-index:5; background:var(--bg-card); font-weight:bold" rowspan="5">${m.label}</td>
                    <td style="position:sticky; left:180px; z-index:5; background:var(--bg-card2)">Ventas año anterior</td>
            `;
            _state.sucursales.forEach(s => {
                const val = _state.data[s.nro]?.[m.key]?.prev || 0;
                html += `<td style="text-align:right" data-t="n" data-v="${val}">${val > 0 ? Dashboard.fmt.money(val) : '—'}</td>`;
            });
            html += `</tr>`;

            // Objetivo Actual
            html += `
                <tr>
                    <td style="position:sticky; left:180px; z-index:5; background:var(--bg-card2)">Objetivo Actual</td>
            `;
            _state.sucursales.forEach(s => {
                const val = _state.data[s.nro]?.[m.key]?.obj || 0;
                html += `<td style="text-align:right" data-t="n" data-v="${val}">${val > 0 ? Dashboard.fmt.money(val) : '—'}</td>`;
            });
            html += `</tr>`;

            // Ventas actual
            html += `
                <tr>
                    <td style="position:sticky; left:180px; z-index:5; background:var(--bg-card2); font-weight:600">Ventas actual</td>
            `;
            _state.sucursales.forEach(s => {
                const val = _state.data[s.nro]?.[m.key]?.act || 0;
                html += `<td style="text-align:right; font-weight:600" data-t="n" data-v="${val}">${val > 0 ? Dashboard.fmt.money(val) : '—'}</td>`;
            });
            html += `</tr>`;

            // % objetivo
            html += `
                <tr style="color:var(--text-2)">
                    <td style="position:sticky; left:180px; z-index:5; background:var(--bg-card2)">% objetivo</td>
            `;
            _state.sucursales.forEach(s => {
                const act = _state.data[s.nro]?.[m.key]?.act || 0;
                const obj = _state.data[s.nro]?.[m.key]?.obj || 0;
                const pct = obj > 0 ? act / obj : 0;
                html += `<td style="text-align:right" data-t="n" data-v="${pct}">${obj > 0 ? Dashboard.fmt.pct(pct) : '—'}</td>`;
            });
            html += `</tr>`;

            // % crecimiento
            html += `
                <tr style="font-weight:600">
                    <td style="position:sticky; left:180px; z-index:5; background:var(--bg-card2)">% crecimiento</td>
            `;
            _state.sucursales.forEach(s => {
                const act = _state.data[s.nro]?.[m.key]?.act || 0;
                const prev = _state.data[s.nro]?.[m.key]?.prev || 0;
                const growth = prev > 0 ? (act - prev) / prev : 0;
                const cls = growth >= 0 ? 'text-pos' : 'text-neg';
                html += `<td style="text-align:right" class="${cls}" data-t="n" data-v="${growth}">${prev > 0 ? Dashboard.fmt.varPct(growth) : '—'}</td>`;
            });
            html += `</tr>`;

            // Fila de separación entre meses
            html += `<tr style="height:8px; background:rgba(255, 255, 255, 0.02)"><td colspan="${_state.sucursales.length + 2}"></td></tr>`;
        });

        html += `
                </tbody>
            </table>
        `;

        wrap.innerHTML = html;
    }

    function exportToExcel() {
        if (_state.sucursales.length === 0) return;
        
        const table = document.getElementById('tabla-fran-resumen');
        if (!table) return;

        const filename = `Resumen_Anual_Anterior_Franquicias_${_state.periodo.act.desde}_al_${_state.periodo.act.hasta}`;
        ExcelExporter.exportTable(table, filename);
    }

    // Inicializar listener de exportación
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('btn-export-franquicias-resumen');
        if (btn) btn.addEventListener('click', exportToExcel);
    });

    return {
        loadAll
    };
})();
