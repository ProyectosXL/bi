
const MESES_FULL = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function normalizarHeader(h) {
    return String(h || '')
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .toUpperCase()
        .replace(/[^A-Z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

const CAMPOS_SINONIMOS = {
    FECHA_TAREA:      ['FECHA_TAREA', 'FECHATAREA', 'FECHA_DE_TAREA'],
    TICKET:           ['TICKET', 'NRO_TICKET', 'NUMERO_TICKET', 'N_TICKET', 'ID_TICKET'],
    FECHA_CIERRE:     ['FECHA_CIERRE', 'FECHACIERRE', 'FECHA_DE_CIERRE'],
    CERRADO:          ['CERRADO', 'CLOSED', 'ESTADO_CERRADO'],
    AREA:             ['AREA'],
    USUARIO_ASIGNADO: ['USUARIO_ASIGNADO', 'USUARIO', 'ASIGNADO', 'RESPONSABLE'],
    CONTACTOS:        ['CONTACTOS', 'CONTACTO'],
    TIPO:             ['TIPO', 'TIPO_TICKET', 'CATEGORIA']
};

function construirMapaColumnas(headers) {
    const mapa = {};
    headers.forEach(hOriginal => {
        const norm = normalizarHeader(hOriginal);
        for (const campo in CAMPOS_SINONIMOS) {
            if (CAMPOS_SINONIMOS[campo].includes(norm)) { mapa[hOriginal] = campo; break; }
        }
    });
    return mapa;
}

function normalizeCerrado(value) {
    if (value === null || value === undefined) return 0;
    if (typeof value === 'boolean') return value ? 1 : 0;
    if (typeof value === 'number') return value !== 0 ? 1 : 0;
    const v = String(value).trim().toUpperCase();
    if (['SI', 'SÍ', 'S', 'TRUE', '1', 'X', 'YES', 'Y'].includes(v)) return 1;
    return 0;
}

function formatSqlDateTime(value) {
    if (value === null || value === undefined || value === '') return null;
    const d = value instanceof Date ? value : new Date(value);
    if (isNaN(d.getTime())) return null;
    const pad = n => String(n).padStart(2, '0');
    const fecha = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const sinHora = d.getHours() === 0 && d.getMinutes() === 0 && d.getSeconds() === 0;
    return sinHora ? fecha : `${fecha} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function mapearFilaTicket(filaRaw, mapaColumnas, nroFila) {
    const out = { FECHA_TAREA: null, TICKET: null, FECHA_CIERRE: null, CERRADO: 0, AREA: null, USUARIO_ASIGNADO: null, CONTACTOS: null, TIPO: null };
    Object.keys(filaRaw).forEach(header => {
        const campo = mapaColumnas[header];
        if (!campo) return;
        const valor = filaRaw[header];
        if (campo === 'FECHA_TAREA' || campo === 'FECHA_CIERRE') out[campo] = formatSqlDateTime(valor);
        else if (campo === 'CERRADO') out[campo] = normalizeCerrado(valor);
        else if (campo === 'TICKET') out[campo] = valor;
        else out[campo] = (valor === null || valor === undefined) ? null : String(valor).trim();
    });

    const errores = [];
    const ticketNum = Number(out.TICKET);
    if (out.TICKET === null || out.TICKET === '' || isNaN(ticketNum)) {
        errores.push(`Fila ${nroFila}: TICKET vacío o no numérico`);
    } else {
        out.TICKET = ticketNum;
    }
    return { fila: nroFila, datos: out, errores };
}

function colorChipDesarrollador(nombre) {
    if (!nombre) return { bg: '#e9ecef', color: '#6c757d' };
    let hash = 0;
    for (let i = 0; i < nombre.length; i++) {
        hash = nombre.charCodeAt(i) + ((hash << 5) - hash);
        hash |= 0;
    }
    const hue = Math.abs(hash) % 360;
    return { bg: `hsl(${hue},55%,88%)`, color: `hsl(${hue},55%,28%)` };
}

const KpisManager = {

    kpiDetalleTipoActivo: null,

    // ── Init ──────────────────────────────────────────────────────────────────
    init: function() {
        this.setupSelectores();
        this.setupEventListeners();
        this.setupImportListeners();
        this.cargarKPIResumen();
    },

    // ── Period selectors (mes/año desde-hasta) ──────────────────────────────────
    setupSelectores: function() {
        const now       = new Date();
        const anoActual = now.getFullYear();
        const mesActual = now.getMonth() + 1;

        const anos = [];
        for (let a = anoActual; a >= anoActual - 4; a--) anos.push(a);

        const mesOptions = MESES_FULL.map((m, i) => `<option value="${i + 1}">${m}</option>`).join('');
        const anoOptions = anos.map(a => `<option value="${a}">${a}</option>`).join('');

        ['filtroMesDesde', 'filtroMesHasta'].forEach(id => { document.getElementById(id).innerHTML = mesOptions; });
        ['filtroAnoDesde', 'filtroAnoHasta'].forEach(id => { document.getElementById(id).innerHTML = anoOptions; });

        document.getElementById('filtroMesDesde').value = mesActual;
        document.getElementById('filtroMesHasta').value = mesActual;
        document.getElementById('filtroAnoDesde').value = anoActual;
        document.getElementById('filtroAnoHasta').value = anoActual;
    },

    setupEventListeners: function() {
        $('#btnAplicarPeriodo').on('click', () => this.cargarKPIResumen());

        $(document).on('click', '.btn-ver-proyectos', (e) => {
            const tipo = e.currentTarget.dataset.tipo;
            this.kpiDetalleTipoActivo = tipo;
            this.cargarKPIDetalle(tipo);
        });

        $(document).on('click', '.btn-ver-tickets', () => this.cargarTicketsDetalle());
    },

    // ── Range helpers ────────────────────────────────────────────────────────
    rangoActivo: function() {
        const desde = $('#filtroAnoDesde').val() + '-' + String($('#filtroMesDesde').val()).padStart(2, '0');
        const hasta = $('#filtroAnoHasta').val() + '-' + String($('#filtroMesHasta').val()).padStart(2, '0');
        return desde <= hasta ? { desde, hasta } : null;
    },

    textoPeriodo: function(rango) {
        const [anoD, mesD] = rango.desde.split('-');
        const [anoH, mesH] = rango.hasta.split('-');
        const desdeTxt = MESES_FULL[parseInt(mesD) - 1] + ' ' + anoD;
        const hastaTxt = MESES_FULL[parseInt(mesH) - 1] + ' ' + anoH;
        return desdeTxt === hastaTxt ? desdeTxt : (desdeTxt + ' a ' + hastaTxt);
    },

    // ── KPI cards ────────────────────────────────────────────────────────────
    cargarKPIResumen: function() {
        const rango = this.rangoActivo();
        $('#periodoAviso').toggleClass('d-none', !!rango);
        if (!rango) return;

        const params = new URLSearchParams({ action: 'kpi-resumen', fecha_desde: rango.desde, fecha_hasta: rango.hasta });
        fetch(`api/kpis.php?${params}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { this.mostrarError('Error KPI: ' + (data.error || '')); return; }
                this.actualizarKPICards(data);
            })
            .catch(() => this.mostrarError('Error al cargar KPIs'));
    },

    actualizarKPICards: function(data) {
        const pctComp = data.pct_completados;
        const pctSLA  = data.pct_sla;

        // KPI 1 — Completados vs Plan
        document.getElementById('kpiPctCompletados').textContent =
            pctComp !== null ? pctComp.toFixed(1) : '—';
        document.getElementById('kpiBarCompletados').style.width = (pctComp || 0) + '%';
        document.getElementById('kpiTextCompletados').textContent =
            data.total_plan > 0
                ? `${data.completados} de ${data.total_plan} proyectos cerrados`
                : 'Sin proyectos con fecha límite en el período';

        // KPI 2 — SLA
        document.getElementById('kpiPctSLA').textContent =
            pctSLA !== null ? pctSLA.toFixed(1) : '—';
        const barSLA = document.getElementById('kpiBarSLA');
        barSLA.style.width = (pctSLA || 0) + '%';
        barSLA.className = 'progress-bar ' + (
            pctSLA === null ? 'bg-secondary' :
            pctSLA >= 85    ? 'bg-success'   :
            pctSLA >= 60    ? 'bg-warning'   : 'bg-danger'
        );
        document.getElementById('kpiTextSLA').textContent =
            data.completados > 0
                ? `${data.a_tiempo} de ${data.completados} cerrados a tiempo · objetivo 85%`
                : 'Sin proyectos terminados en el período';

        // KPI 3 — Tickets dentro de SLA
        const pctTickets = data.pct_tickets_sla;
        document.getElementById('kpiPctTicketsSLA').textContent =
            pctTickets !== null ? pctTickets.toFixed(1) : '—';
        const barTickets = document.getElementById('kpiBarTicketsSLA');
        barTickets.style.width = (pctTickets || 0) + '%';
        barTickets.className = 'progress-bar ' + (
            pctTickets === null ? 'bg-secondary' :
            pctTickets >= 90    ? 'bg-success'   :
            pctTickets >= 70    ? 'bg-warning'   : 'bg-danger'
        );
        document.getElementById('kpiTextTicketsSLA').textContent =
            data.total_tickets > 0
                ? `${data.tickets_ok} de ${data.total_tickets} tickets cerrados a tiempo · objetivo 90%`
                : 'Sin tickets cerrados en el período';
    },

    // ── KPI detail modal ─────────────────────────────────────────────────────
    cargarKPIDetalle: function(tipo) {
        const rango = this.rangoActivo();
        if (!rango) return;
        const periodo = this.textoPeriodo(rango);

        const titulo = tipo === 'completados'
            ? 'Proyectos del plan — Completados vs Plan'
            : 'Proyectos evaluados — SLA';
        document.getElementById('kpiDetalleTitulo').textContent = titulo;

        let nota = 'Proyectos cuya Fecha Límite cae en ' + periodo + '.';
        if (tipo === 'completados') nota += ' Se excluyen los proyectos pausados.';
        document.getElementById('kpiDetalleNota').textContent = nota;
        document.getElementById('kpiDetalleResumen').textContent = '';
        document.getElementById('kpiDetalleTabla').innerHTML =
            '<tr><td colspan="8" class="text-center text-muted py-3">' +
            '<i class="fas fa-spinner fa-spin me-2"></i>Cargando...</td></tr>';

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKpiDetalle')).show();

        const params = new URLSearchParams({ action: 'kpi-detalle', tipo, fecha_desde: rango.desde, fecha_hasta: rango.hasta });
        fetch('api/kpis.php?' + params)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { this.mostrarError('Error al cargar detalle: ' + (data.error || '')); return; }
                this.pintarTablaKPIDetalle(data);
            })
            .catch(() => this.mostrarError('Error al cargar detalle KPI'));
    },

    pintarTablaKPIDetalle: function(data) {
        const Y = data.total;
        const X = data.cumplen;
        const pct = Y > 0 ? (X / Y * 100).toFixed(1) : null;
        const pctDisplay = pct !== null ? pct + '%' : '—';

        let badgePctClass;
        if (data.tipo === 'sla') {
            badgePctClass = pct === null        ? 'bg-secondary'
                : parseFloat(pct) >= 85         ? 'bg-success'
                : parseFloat(pct) >= 60         ? 'bg-warning text-dark'
                :                                 'bg-danger';
        } else {
            badgePctClass = (pct !== null && parseFloat(pct) === 100) ? 'bg-success' : 'bg-secondary';
        }
        const label = data.tipo === 'completados' ? 'completados' : 'a tiempo';

        document.getElementById('kpiDetalleResumen').innerHTML =
            '<div class="d-flex align-items-center gap-4 flex-wrap">' +
                '<div>' +
                    '<div class="kpi-detalle-count">' + X +
                        '<span class="text-muted fw-normal" style="font-size:.65em"> / ' + Y + '</span>' +
                    '</div>' +
                    '<div class="small text-muted">' + label + '</div>' +
                '</div>' +
                '<span class="badge rounded-pill ' + badgePctClass + ' kpi-detalle-pct-badge">' + pctDisplay + '</span>' +
            '</div>';

        const tbody = document.getElementById('kpiDetalleTabla');
        if (!data.proyectos || data.proyectos.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" class="text-center py-4">' +
                '<i class="fas fa-inbox fa-2x text-muted d-block mb-2"></i>' +
                '<span class="text-muted">No hay proyectos para el período seleccionado.</span>' +
                '</td></tr>';
            return;
        }
        tbody.innerHTML = data.proyectos.map(p => {
            const badgeClass = (p.clasificacion === 'Completado' || p.clasificacion === 'A tiempo')
                ? 'bg-success'
                : (p.clasificacion === 'No completado' ? 'bg-secondary' : 'bg-danger');
            const devNombre = p.desarrollador || '';
            const devDisplay = devNombre || 'Sin asignar';
            const chip = colorChipDesarrollador(devNombre || null);
            const devChip = '<span style="background:' + chip.bg + ';color:' + chip.color +
                ';font-size:0.78rem;padding:0.2em 0.6em;border-radius:999px;white-space:nowrap;font-weight:500">' +
                escapeHtml(devDisplay) + '</span>';
            return '<tr>' +
                '<td class="text-nowrap ps-3">' + p.id + '</td>' +
                '<td>' + escapeHtml(p.desarrollo || '') + '</td>' +
                '<td>' + escapeHtml(p.sector || '') + '</td>' +
                '<td class="text-nowrap">' + devChip + '</td>' +
                '<td class="text-nowrap">' + p.fecha_limite + '</td>' +
                '<td class="text-nowrap">' + p.finalizacion_real + '</td>' +
                '<td class="text-nowrap"><span class="badge rounded-pill badge-estado">' + escapeHtml(p.estado || '') + '</span></td>' +
                '<td class="text-nowrap"><span class="badge rounded-pill badge-clasificacion ' + badgeClass + '">' + escapeHtml(p.clasificacion) + '</span></td>' +
                '</tr>';
        }).join('');
    },

    // ── Tickets detail modal (KPI 3) ─────────────────────────────────────────
    cargarTicketsDetalle: function() {
        const rango = this.rangoActivo();
        if (!rango) return;
        const periodo = this.textoPeriodo(rango);

        document.getElementById('ticketsDetalleNota').textContent =
            `Tickets cerrados (CERRADO=1) cuya Fecha Tarea cae en ${periodo}. SLA: 48 horas entre Fecha Tarea y Fecha Cierre.`;
        document.getElementById('ticketsDetalleResumen').textContent = '';
        document.getElementById('ticketsDetalleTabla').innerHTML =
            '<tr><td colspan="8" class="text-center text-muted py-3">' +
            '<i class="fas fa-spinner fa-spin me-2"></i>Cargando...</td></tr>';

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTicketsDetalle')).show();

        const params = new URLSearchParams({ action: 'kpi-detalle', tipo: 'tickets-sla', fecha_desde: rango.desde, fecha_hasta: rango.hasta });
        fetch('api/kpis.php?' + params)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { this.mostrarError('Error al cargar detalle: ' + (data.error || '')); return; }
                this.pintarTablaTicketsDetalle(data);
            })
            .catch(() => this.mostrarError('Error al cargar detalle de tickets'));
    },

    pintarTablaTicketsDetalle: function(data) {
        const Y = data.total;
        const X = data.cumplen;
        const pct = Y > 0 ? (X / Y * 100).toFixed(1) : null;
        const badgeClass = pct === null ? 'bg-secondary' :
            parseFloat(pct) >= 90 ? 'bg-success' :
            parseFloat(pct) >= 70 ? 'bg-warning text-dark' : 'bg-danger';

        document.getElementById('ticketsDetalleResumen').innerHTML =
            '<div class="d-flex align-items-center gap-4 flex-wrap">' +
                '<div>' +
                    '<div class="kpi-detalle-count">' + X +
                        '<span class="text-muted fw-normal" style="font-size:.65em"> / ' + Y + '</span>' +
                    '</div>' +
                    '<div class="small text-muted">dentro de SLA</div>' +
                '</div>' +
                '<span class="badge rounded-pill ' + badgeClass + ' kpi-detalle-pct-badge">' + (pct !== null ? pct + '%' : '—') + '</span>' +
            '</div>';

        const tbody = document.getElementById('ticketsDetalleTabla');
        if (!data.tickets || data.tickets.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" class="text-center py-4">' +
                '<i class="fas fa-inbox fa-2x text-muted d-block mb-2"></i>' +
                '<span class="text-muted">No hay tickets para el período seleccionado.</span>' +
                '</td></tr>';
            return;
        }
        tbody.innerHTML = data.tickets.map(t => {
            const slaBadge = t.cumple_sla
                ? '<span class="badge rounded-pill bg-success">A tiempo</span>'
                : '<span class="badge rounded-pill bg-danger">Fuera de SLA</span>';
            return '<tr>' +
                '<td class="ps-3 text-nowrap">' + t.ticket + '</td>' +
                '<td class="text-nowrap">' + (t.fecha_tarea || '-') + '</td>' +
                '<td class="text-nowrap">' + (t.fecha_cierre || '-') + '</td>' +
                '<td class="text-nowrap">' + (t.horas !== null ? t.horas + ' hs' : '-') + '</td>' +
                '<td>' + escapeHtml(t.area || '') + '</td>' +
                '<td>' + escapeHtml(t.usuario_asignado || '') + '</td>' +
                '<td>' + escapeHtml(t.tipo || '') + '</td>' +
                '<td class="text-nowrap">' + slaBadge + '</td>' +
                '</tr>';
        }).join('');
    },

    // ── Importar tickets desde Excel ──────────────────────────────────────────
    setupImportListeners: function() {
        $('#inputArchivoTickets').on('change', (e) => this.handleArchivoTickets(e));
        $('#btnConfirmarImportar').on('click', () => this.confirmarImportarTickets());
        $('#modalImportarTickets').on('hidden.bs.modal', () => this.resetImportModal());
    },

    handleArchivoTickets: function(e) {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (ev) => {
            try {
                const wb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array', cellDates: true });
                const hoja = wb.Sheets[wb.SheetNames[0]];
                const filas = XLSX.utils.sheet_to_json(hoja, { defval: null });
                if (!filas.length) { alert('El archivo no tiene filas de datos.'); return; }

                const mapaColumnas = construirMapaColumnas(Object.keys(filas[0]));
                this._filasValidadas = [];
                this._erroresImportacion = [];
                filas.forEach((filaRaw, idx) => {
                    const { datos, errores } = mapearFilaTicket(filaRaw, mapaColumnas, idx + 2);
                    if (errores.length) this._erroresImportacion.push(...errores);
                    else this._filasValidadas.push(datos);
                });
                this.renderPreviewImportacion();
            } catch (err) {
                alert('No se pudo leer el archivo: ' + err.message);
            }
        };
        reader.readAsArrayBuffer(file);
    },

    renderPreviewImportacion: function() {
        const totalFilas = this._filasValidadas.length + this._erroresImportacion.length;
        $('#previewTotalFilas').text(totalFilas + ' filas detectadas');
        $('#previewTotalErrores').text(this._erroresImportacion.length + ' con errores')
            .toggleClass('d-none', this._erroresImportacion.length === 0);
        $('#previewErroresLista').html(
            this._erroresImportacion.slice(0, 20).map(e => '<div>' + escapeHtml(e) + '</div>').join('') +
            (this._erroresImportacion.length > 20 ? '<div>… y ' + (this._erroresImportacion.length - 20) + ' más</div>' : '')
        );
        $('#previewTablaBody').html(
            this._filasValidadas.slice(0, 20).map(f =>
                '<tr><td>' + f.TICKET + '</td><td>' + (f.FECHA_TAREA || '-') + '</td><td>' + (f.FECHA_CIERRE || '-') + '</td>' +
                '<td>' + (f.CERRADO ? 'Sí' : 'No') + '</td><td>' + escapeHtml(f.AREA || '') + '</td>' +
                '<td>' + escapeHtml(f.USUARIO_ASIGNADO || '') + '</td><td>' + escapeHtml(f.TIPO || '') + '</td></tr>'
            ).join('')
        );
        $('#importPreview').removeClass('d-none');
        $('#btnConfirmarImportar').toggleClass('d-none', this._filasValidadas.length === 0);
    },

    confirmarImportarTickets: function() {
        if (!this._filasValidadas || !this._filasValidadas.length) return;
        const $btn = $('#btnConfirmarImportar');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Importando...');

        fetch('api/kpis.php?action=importar-tickets', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(this._filasValidadas)
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert('Error al importar: ' + (data.error || '')); return; }
            document.getElementById('importResultado').classList.remove('d-none');
            document.getElementById('importResultado').innerHTML =
                `Importación completa: <b>${data.insertados}</b> nuevos, <b>${data.actualizados}</b> actualizados, ` +
                `<b>${data.omitidos}</b> omitidos (de ${data.total_filas} filas).` +
                (data.errores && data.errores.length
                    ? '<div class="text-danger small mt-2">' +
                        data.errores.slice(0, 20).map(e => escapeHtml(e.motivo) + ' (fila ' + e.fila + ')').join('<br>') +
                      '</div>'
                    : '');
            document.getElementById('importPreview').classList.add('d-none');
            document.getElementById('importPaso1').classList.add('d-none');
            this.cargarKPIResumen();
        })
        .catch(() => alert('Error de red al importar'))
        .finally(() => $btn.prop('disabled', false).html('<i class="fas fa-upload me-1"></i>Confirmar e importar'));
    },

    resetImportModal: function() {
        this._filasValidadas = [];
        this._erroresImportacion = [];
        $('#inputArchivoTickets').val('');
        $('#importPaso1').removeClass('d-none');
        $('#importPreview, #importResultado, #btnConfirmarImportar').addClass('d-none');
    },

    mostrarError: function(msg) {
        console.error(msg);
    }
};

$(document).ready(() => KpisManager.init());
