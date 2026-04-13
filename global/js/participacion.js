/**
 * /bi/global/js/participacion.js
 * Pestaña Participación: filas = grupos + sucursales, columnas = top-5 rubros.
 * Cada celda muestra % Facturación (principal) y % Unidades (secundario).
 * Depende de Dashboard.buildQS() y Dashboard.getSucNombre()
 */

const Participacion = (() => {

    let _lastData = null;

    /* ── Exportar a Excel ────────────────────── */
    function exportarParticipacion() {
        if (!_lastData?.rubros?.length || !_lastData?.sucursales?.length || typeof ExcelExporter === 'undefined') return;

        const rubros = _lastData.rubros;
        let sucursales = _lastData.sucursales;

        // Filtro solo activas: excluir filas tipo 'sucursal' que no estén en el Set
        if (typeof Dashboard !== 'undefined' && Dashboard.isSoloActivas?.()) {
            const ids = Dashboard.getSucursalesActivasIds?.();
            if (ids?.size) {
                sucursales = sucursales.filter(s => s.tipo === 'grupo' || ids.has(+s.nro_sucurs));
            }
        }

        const headers = ['Sucursal',
            ...rubros.flatMap(r => [r + ' % Fact', r + ' % Unid'])];

        const rows = sucursales.map(s => {
            if (s.tipo === 'grupo') {
                return [s.nombre ?? '', ...rubros.flatMap(() => [null, null])];
            }
            const getSucNombre = n => (typeof Dashboard !== 'undefined' ? Dashboard.getSucNombre(n) : 'Suc. ' + n);
            return [
                getSucNombre(s.nro_sucurs),
                ...rubros.flatMap(rub => [
                    s.rubros?.[rub]?.porc_facturacion ?? null,
                    s.rubros?.[rub]?.porc_unidades    ?? null,
                ]),
            ];
        });

        ExcelExporter.export({
            title   : 'Participación por Rubro y Sucursal',
            headers,
            rows,
            filename: 'participacion_rubros',
        });
    }

    /* ── Formato ─────────────────────────────── */
    function pctFmt(n) {
        return n === null || n === undefined ? '—'
            : (n * 100).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '\u00A0%';
    }

    /* ── Heatmap: normalizado por rubro (columna) ─ */
    function heatColor(ratio) {
        const r = ratio ?? 0;
        if (r < 0.33) return `rgba(220,38,38,${0.07 + r * 0.25})`;
        if (r < 0.66) return `rgba(245,158,11,${0.09 + r * 0.22})`;
        return `rgba(22,163,74,${0.09 + r * 0.28})`;
    }

    /* ── Render tabla pivot ──────────────────── */
    function renderPivot(data) {
        const wrap = document.getElementById('participacion-wrap');
        if (!wrap) return;
        if (!data?.rubros?.length || !data?.sucursales?.length) {
            wrap.innerHTML = '<div style="padding:24px;color:var(--text-3);font-size:.85rem">Sin datos para este período y filtros.</div>';
            return;
        }

        const rubros = data.rubros;

        // Filtro solo activas: mantener grupos, filtrar sucursales no activas
        let sucursales = data.sucursales;
        if (typeof Dashboard !== 'undefined' && Dashboard.isSoloActivas?.()) {
            const ids = Dashboard.getSucursalesActivasIds?.();
            if (ids?.size) {
                sucursales = sucursales.filter(s => s.tipo === 'grupo' || ids.has(+s.nro_sucurs));
            }
        }

        const getSucNombre = n => (typeof Dashboard !== 'undefined' ? Dashboard.getSucNombre(n) : 'Suc. ' + n);

        // Calcular max por rubro para heatmap (solo sucursales, no grupos)
        const maxByRubro = {};
        const maxByRubroUnid = {};
        rubros.forEach(rub => {
            const suc = sucursales.filter(s => s.tipo === 'sucursal');
            maxByRubro[rub]     = Math.max(...suc.map(s => s.rubros?.[rub]?.porc_facturacion ?? 0), 0.001);
            maxByRubroUnid[rub] = Math.max(...suc.map(s => s.rubros?.[rub]?.porc_unidades    ?? 0), 0.001);
        });

        // Encabezado: cada rubro ocupa 2 columnas (% Fact | % Unid)
        const thRubros = rubros.map(rub =>
            `<th colspan="2" style="text-align:center;padding:8px 6px;font-size:.78rem;white-space:nowrap;border-left:2px solid var(--border)">${rub}</th>`
        ).join('');
        const thSub = rubros.map(() =>
            `<th style="text-align:center;font-size:.65rem;color:rgba(255,255,255,.75);padding:2px 4px;font-weight:400;border-left:2px solid rgba(255,255,255,.2)">%\u00A0Fact</th>
             <th style="text-align:center;font-size:.65rem;color:rgba(255,255,255,.75);padding:2px 4px;font-weight:400">%\u00A0Unid</th>`
        ).join('');

        const thead = `<thead>
            <tr>
                <th style="min-width:160px;text-align:left;padding:8px 12px">Sucursal</th>
                ${thRubros}
            </tr>
            <tr>
                <th></th>
                ${thSub}
            </tr>
        </thead>`;

        // Filas
        const trows = sucursales.map(s => {
            if (s.tipo === 'grupo') {
                return `<tr>
                    <td colspan="${rubros.length * 2 + 1}" style="font-weight:700;font-size:.80rem;padding:7px 12px;background:var(--surface-1);color:var(--text-2);border-top:2px solid var(--border)">${s.nombre ?? ''}</td>
                </tr>`;
            }

            // Sucursal
            const nombre = getSucNombre(s.nro_sucurs) || s.desc_sucursal || ('Suc. ' + s.nro_sucurs);
            const cells = rubros.map(rub => {
                const d  = s.rubros?.[rub];
                const pf = d?.porc_facturacion ?? 0;
                const pu = d?.porc_unidades    ?? 0;
                const border = 'border-left:2px solid var(--border)';
                if (!d || (pf === 0 && pu === 0)) {
                    return `<td style="text-align:center;font-size:.78rem;color:var(--text-3);${border}">—</td>
                            <td style="text-align:center;font-size:.78rem;color:var(--text-3)">—</td>`;
                }
                const ratioF = maxByRubro[rub] > 0     ? pf / maxByRubro[rub]     : 0;
                const ratioU = maxByRubroUnid[rub] > 0 ? pu / maxByRubroUnid[rub] : 0;
                const bgF = heatColor(ratioF);
                const bgU = heatColor(ratioU);
                return `<td style="text-align:center;background:${bgF};padding:5px 6px;${border};font-size:.80rem;font-weight:600" title="${rub} Fact · ${nombre}">${pctFmt(pf)}</td>
                        <td style="text-align:center;background:${bgU};padding:5px 6px;font-size:.78rem;color:var(--text-2)" title="${rub} Unid · ${nombre}">${pctFmt(pu)}</td>`;
            }).join('');

            return `<tr>
                <td style="white-space:nowrap;font-size:.80rem;padding:5px 12px 5px 22px">${nombre}</td>
                ${cells}
            </tr>`;
        }).join('');

        wrap.innerHTML = `<div style="overflow-x:auto">
            <table class="participacion-tabla">
                ${thead}
                <tbody>${trows}</tbody>
            </table>
        </div>`;
    }

    /* ── Carga ───────────────────────────────── */
    async function loadAll() {
        const wrap = document.getElementById('participacion-wrap');
        if (wrap) wrap.innerHTML = '<div class="analisis-loading"><i class="bi bi-arrow-repeat"></i> <span class="loading-text">Cargando</span></div>';
        document.body.classList.add('is-loading');
        Spinner.show('Cargando participación...');

        try {
            const qs  = Dashboard.buildQS({ top_rubros: 5 });
            const res = await fetch(`/bi/global/api/participacion.php?${qs}`);
            if (!res.ok) throw new Error(`Error ${res.status}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Error en participación');

            _lastData = data;
            renderPivot(_lastData);

            // Botón de exportación (una sola vez)
            if (typeof ExcelExporter !== 'undefined') {
                const sectionHeader = document.querySelector('#tab-participacion .analisis-section-header');
                // Insertar dentro del div derecho si existe, si no, directo en el header
                const rightDiv = sectionHeader?.querySelector('[style*="margin-left"]');
                ExcelExporter.addExportButton(rightDiv ?? sectionHeader, exportarParticipacion);
            }
        } catch(e) {
            const wrap = document.getElementById('participacion-wrap');
            if (wrap) wrap.innerHTML = `<div style="padding:20px;color:var(--neg);font-size:.85rem"><i class="bi bi-exclamation-triangle"></i> ${e.message}</div>`;
            console.error('[Participacion]', e);
        } finally {
            document.body.classList.remove('is-loading');
            Spinner.hide();
        }
    }

    return { loadAll };
})();
