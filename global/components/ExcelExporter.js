/**
 * /bi/global/components/ExcelExporter.js
 * Utilidad compartida para exportar tablas a Excel usando SheetJS.
 * Depende de: XLSX (SheetJS) cargado desde CDN antes de este script.
 *
 * API pública:
 *   ExcelExporter.export({ title, headers, rows, totalsRow, filename })
 *   ExcelExporter.addExportButton(headerEl, exportFn)  → inserta el botón en headerEl
 */
const ExcelExporter = (() => {

    /* ── Lee contexto de período y filtros desde el DOM ── */
    function _getContext() {
        const periodLabel = document.getElementById('periodo-label')?.textContent?.trim() ?? '';
        const periodPrev  = document.getElementById('periodo-previo-label')?.textContent?.trim() ?? '';
        const origen      = document.querySelector('.origen-btn.active')?.textContent?.trim() ?? '';

        const selSuc  = document.getElementById('sel-sucursal');
        const selGrp  = document.getElementById('sel-grupo');
        const selTipo = document.getElementById('sel-tipo-tienda');

        const sucText  = selSuc?.options[selSuc.selectedIndex]?.text  ?? '';
        const grpText  = selGrp?.options[selGrp.selectedIndex]?.text  ?? '';
        const tipoText = selTipo?.options[selTipo.selectedIndex]?.text ?? '';

        const parts = [];
        if (origen)                               parts.push('Origen: ' + origen);
        if (sucText  && sucText  !== 'Todas')     parts.push('Sucursal: ' + sucText);
        if (grpText  && grpText  !== 'Todos')     parts.push('Grupo: ' + grpText);
        if (tipoText && tipoText !== 'Todos')     parts.push('Tipo tienda: ' + tipoText);

        return {
            period  : [periodLabel, periodPrev].filter(Boolean).join(' '),
            filters : parts.length ? parts.join(' | ') : 'Sin filtros adicionales',
        };
    }

    const FMT_MAP = { money: '#,##0', pct: '0.0%', num: '#,##0', num1: '#,##0.0' };

    /* ── Exporta al formato XLSX ──────────────────────── */
    function exportData({ title, headers, rows, totalsRow, filename, colFormats }) {
        if (typeof XLSX === 'undefined') {
            alert('La librería de Excel no está cargada aún. Intentá de nuevo en unos segundos.');
            return;
        }

        const ctx   = _getContext();
        const today = new Date().toISOString().slice(0, 10);
        const fname = `${filename}_${today}.xlsx`;

        /* Armar el array de arrays para aoa_to_sheet */
        const wsData = [
            [title],                                 // fila 1 – título
            [`Período: ${ctx.period}`],              // fila 2 – período
            [ctx.filters],                           // fila 3 – filtros
            [],                                      // fila 4 – separador
            headers,                                 // fila 5 – encabezados
        ];
        rows.forEach(r => wsData.push(r));
        if (totalsRow) wsData.push(totalsRow);

        const ws = XLSX.utils.aoa_to_sheet(wsData);

        /* Formatos numéricos por columna (dinero, porcentaje, número) */
        if (colFormats?.length) {
            for (let ri = 5; ri < wsData.length; ri++) {
                colFormats.forEach((type, ci) => {
                    const fmt = FMT_MAP[type];
                    if (!fmt) return;
                    const ref = XLSX.utils.encode_cell({ r: ri, c: ci });
                    if (ws[ref]?.t === 'n') ws[ref].z = fmt;
                });
            }
        }

        /* Negritas en encabezados (fila índice 4) y en la fila de totales */
        const boldRows = [4];
        if (totalsRow) boldRows.push(wsData.length - 1);

        boldRows.forEach(ri => {
            headers.forEach((_, ci) => {
                const ref = XLSX.utils.encode_cell({ r: ri, c: ci });
                if (!ws[ref]) ws[ref] = { t: 'z' };
                ws[ref].s = ws[ref].s ?? {};
                ws[ref].s.font = { bold: true };
            });
        });

        /* Ancho de columnas automático */
        ws['!cols'] = headers.map((h, ci) => {
            const maxLen = Math.max(
                String(h ?? '').length,
                ...rows.map(r => String(r[ci] ?? '').length),
                totalsRow ? String(totalsRow[ci] ?? '').length : 0,
            );
            return { wch: Math.min(Math.max(maxLen + 2, 10), 45) };
        });

        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Datos');
        XLSX.writeFile(wb, fname);
    }

    /* ── Exporta una tabla HTML directamente ──────────── */
    function exportTable(tableEl, filename) {
        if (typeof XLSX === 'undefined') {
            alert('La librería de Excel no está cargada aún. Intentá de nuevo en unos segundos.');
            return;
        }

        const ctx   = _getContext();
        const today = new Date().toISOString().slice(0, 10);
        const fname = `${filename}_${today}.xlsx`;

        const title = filename.split('_').join(' ');

        const wsData = [
            [title],
            [`Período: ${ctx.period}`],
            [ctx.filters],
            []
        ];
        const ws = XLSX.utils.aoa_to_sheet(wsData);
        
        /* Agregamos la tabla a partir de la fila 5 */
        XLSX.utils.sheet_add_dom(ws, tableEl, { origin: "A5" });

        /* Ajuste de anchos básico */
        const range = XLSX.utils.decode_range(ws['!ref']);
        ws['!cols'] = [];
        for (let i = 0; i <= range.e.c; i++) {
            ws['!cols'].push({ wch: 15 });
        }

        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Datos');
        XLSX.writeFile(wb, fname);
    }

    /* ── Inserta botón Excel en el elemento header recibido ── */
    function addExportButton(headerEl, exportFn) {
        if (!headerEl || headerEl.querySelector('.btn-export-excel')) return;
        const btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'btn-export-excel';
        btn.title     = 'Exportar a Excel';
        btn.innerHTML = '<i class="bi bi-file-earmark-excel"></i> Excel';
        btn.addEventListener('click', e => { e.stopPropagation(); exportFn(); });
        headerEl.appendChild(btn);
        return btn;
    }

    return { export: exportData, exportTable, addExportButton };
})();
