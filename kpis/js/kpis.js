
const MESES_FULL = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function renderEstrellas(score) {
    if (score === null || score === undefined) return '—';
    const llenas = Math.round(Math.min(Math.max(score, 0), 5));
    return '★'.repeat(llenas) + '☆'.repeat(5 - llenas) + ' ' + score.toFixed(1);
}

function normalizarHeader(h) {
    return String(h || '')
        .replace(/([a-z0-9])([A-Z])/g, '$1_$2') // camelCase -> snake_case (ej. "UsuarioAsignado" -> "Usuario_Asignado")
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

const INSTRUCTIVOS_KPI = {
    completados: {
        titulo: 'Proyectos Completados vs Plan', categoria: 'Ejecución',
        peso: '20%', frecuencia: 'Mensual (acumulado)', objetivo: '60%',
        formula: 'Cantidad de proyectos cerrados formalmente en el período ÷ Cantidad de proyectos planificados para el período',
        fuente: 'Sistema de gestión de proyectos del área (fechas de inicio, finalización y entregables por acta). Un proyecto se considera CERRADO cuando: (a) los entregables del acta están finalizados y entregados al sponsor, (b) transcurrió la ventana de 10 días hábiles post-finalización sin objeciones del sponsor (aceptación tácita), (c) el estado del proyecto está marcado como cerrado en el sistema.',
        reglas: [
            'Proyectos aún en ventana de los 10 días post-finalización NO cuentan hasta vencer la ventana.',
            'Proyectos con objeciones del sponsor durante los 10 días: se reabren y NO computan hasta nueva finalización.',
            'Proyectos cancelados por decisión del sponsor NO penalizan el plan (se descuentan del denominador con justificación).',
            'Replanificaciones por causas externas (recortes de presupuesto, prioridades estratégicas) ajustan el plan, no penalizan al área.'
        ],
        ejemplo: 'Plan T1: 9 proyectos. Cerrados: 8. Cancelados por sponsor: 1 (se descuenta). Resultado: 8/8 = 100%.'
    },
    sla: {
        titulo: '% Cumplimiento de SLA Proyectos', categoria: 'Ejecución',
        peso: '20%', frecuencia: 'Mensual', objetivo: '85%',
        formula: 'Proyectos cerrados en fecha (≤ fecha comprometida) ÷ Total de proyectos cerrados en el período',
        fuente: 'Sistema de gestión de proyectos: fecha de inicio, fecha de finalización comprometida (del acta), fecha real de finalización y, si aplica, fecha replanificada con motivo documentado. Un desvío de hasta el 20% se considera cumplido.',
        reglas: [
            'Si hubo replanificación por causa externa (cambio de scope del sponsor, dependencia bloqueada, recursos no asignados): se compara contra la fecha replanificada, no la original.',
            'Replanificaciones por causa interna (mala estimación del área) SÍ penalizan.',
            'Documentar SIEMPRE el motivo de replanificación en el kickoff/acta.'
        ],
        ejemplo: 'Cerrados: 8. En fecha original: 6. Replanificado por sponsor: 1 (cuenta como en fecha). Replanificado por subestimación interna: 1 (fuera de SLA). Resultado: 7/8 = 87,5%.'
    },
    'tickets-sla': {
        titulo: '% Tickets dentro de SLA (gestionables)', categoria: 'Soporte',
        peso: '15%', frecuencia: 'Mensual', objetivo: '90%',
        formula: 'Tickets gestionables resueltos dentro de SLA ÷ Total de tickets gestionables del período',
        fuente: 'Sistema de tickets. SLA definido por tipo: Crítico ≤ 4 hs hábiles · Alto ≤ 1 día hábil · Medio ≤ 3 días hábiles · Bajo ≤ 5 días hábiles.',
        reglas: [
            '"Gestionable" = ticket que depende del área para resolverse. NO se cuentan: tickets que requieren acción de un tercero (proveedor, otra área), ni tickets con información incompleta esperando respuesta del usuario (se pausa el reloj).',
            'Tickets ingresados en las últimas 48 hs del período NO se cuentan (se trasladan al mes siguiente).',
            'Re-tickets sobre el mismo problema (reapertura) cuentan como tickets nuevos.'
        ],
        ejemplo: 'Tickets del mes: 50. Excluidos (proveedor / fuera de ventana): 8. Gestionables: 42. Dentro de SLA: 39. Resultado: 39/42 = 93%.',
        aviso: 'El cálculo que muestra hoy esta card es una aproximación: SLA fijo de 48 horas hábiles para todos los tipos, sin distinguir criticidad ni excluir tickets no gestionables / ingresados en las últimas 48hs. Este instructivo documenta el modelo objetivo a futuro.'
    },
    procesos: {
        titulo: '% Procesos Relevados vs Plan', categoria: 'Mejora Continua',
        peso: '5%', frecuencia: 'Mensual', objetivo: '2 procesos/mes',
        formula: 'Procesos con madurez (score_actual) ≥ 2.0 relevados en el período ÷ (2 × cantidad de meses del período)',
        fuente: 'Tabla de procesos relevados/documentados por el equipo de Innovación (FP_PROCESSES). La fecha de relevado es la fecha de creación del registro (created_at). La madurez se mide en una escala de 1 a 5 estrellas: 1.0 Inicial (informal, no documentado) · 2.0 Gestionado (documentado) · 3.0 Definido (estandarizado) · 4.0 Medido (con KPIs) · 5.0 Optimizado.',
        reglas: [
            'Solo cuentan como "relevados" los procesos que ya alcanzaron score_actual ≥ 2.0 (Gestionado o superior); los que quedaron en 1.0 (Inicial/informal) no computan para el KPI, aunque se muestren igual en el detalle.',
            'No se filtra por estado del proceso.',
            'No se filtra por área ni responsable: toda la tabla corresponde al trabajo de relevamiento de Innovación, aunque el proceso documentado pertenezca a otra área de la empresa.'
        ],
        ejemplo: 'Rango de 1 mes con plan de 2 procesos/mes. Se crean 3 procesos en el mes, 2 llegan a score_actual = 2.0 y uno queda en 1.0 (informal). Relevados que cuentan: 2. Resultado: 2 / (2×1) = 100%.'
    }
};

const CATEGORIAS_INFO = {
    'Ejecución':       { icono: 'fa-gear',                clase: 'kpi-badge-ejecucion' },
    'Soporte':         { icono: 'fa-screwdriver-wrench',  clase: 'kpi-badge-soporte' },
    'Impacto':         { icono: 'fa-chart-column',        clase: 'kpi-badge-impacto' },
    'Mejora Continua': { icono: 'fa-magnifying-glass',    clase: 'kpi-badge-mejora' }
};

const KPIS_GENERAL = [
    { id: 'completados', nombre: 'Completados vs Plan',        peso: 20, objetivo: 60,  getActual: d => d.pct_completados },
    { id: 'sla',         nombre: '% Cumplimiento SLA',          peso: 20, objetivo: 85,  getActual: d => d.pct_sla },
    { id: 'tickets-sla', nombre: '% Tickets dentro SLA',        peso: 15, objetivo: 90,  getActual: d => d.pct_tickets_sla },
    { id: 'procesos',    nombre: '% Procesos Relevados vs Plan', peso: 5,  objetivo: 100, getActual: d => d.pct_procesos }
];

function calcularCumplimientoGeneral(data) {
    let sumaPonderada = 0;
    let sumaPesos = 0;
    const detalle = [];
    KPIS_GENERAL.forEach(k => {
        const actual = k.getActual(data);
        if (actual === null || actual === undefined) return;
        const logro = Math.min(actual / k.objetivo, 1) * 100;
        sumaPonderada += logro * k.peso;
        sumaPesos += k.peso;
        detalle.push({ ...k, actual, logro });
    });
    return {
        pctGeneral: sumaPesos > 0 ? sumaPonderada / sumaPesos : null,
        sumaPesos,
        detalle
    };
}

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

        $(document).on('click', '.btn-ver-procesos', () => this.cargarProcesosDetalle());

        $(document).on('click', '.btn-ver-instructivo', (e) => this.mostrarInstructivo(e.currentTarget.dataset.kpi));
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
        const barCompletados = document.getElementById('kpiBarCompletados');
        barCompletados.style.width = (pctComp || 0) + '%';
        barCompletados.className = 'progress-bar ' + (
            pctComp === null ? 'bg-secondary' :
            pctComp >= 60    ? 'bg-success'   :
            pctComp >= 40    ? 'bg-warning'   : 'bg-danger'
        );
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

        // KPI 4 — Procesos Relevados vs Plan
        const pctProcesos = data.pct_procesos;
        document.getElementById('kpiPctProcesos').textContent =
            pctProcesos !== null ? pctProcesos.toFixed(1) : '—';
        const barProcesos = document.getElementById('kpiBarProcesos');
        barProcesos.style.width = (pctProcesos || 0) + '%';
        barProcesos.className = 'progress-bar ' + (
            pctProcesos === null ? 'bg-secondary' :
            pctProcesos >= 100   ? 'bg-success'   :
            pctProcesos >= 60    ? 'bg-warning'   : 'bg-danger'
        );
        document.getElementById('kpiTextProcesos').textContent =
            data.objetivo_procesos > 0
                ? `${data.total_procesos} de ${data.objetivo_procesos} procesos relevados (plan de ${data.meses_rango} ${data.meses_rango === 1 ? 'mes' : 'meses'})`
                : 'Sin período seleccionado';

        // Cumplimiento General Ponderado
        this.renderCumplimientoGeneral(data);
    },

    renderCumplimientoGeneral: function(data) {
        const { pctGeneral, sumaPesos, detalle } = calcularCumplimientoGeneral(data);

        document.getElementById('kpiPctGeneral').textContent =
            pctGeneral !== null ? pctGeneral.toFixed(1) : '—';

        const pesoTotalKpis = 100; // suma de pesos de los 6 KPIs de la planilla
        document.getElementById('kpiTextGeneral').textContent =
            sumaPesos > 0
                ? `Ponderado sobre los KPIs con datos disponibles (${sumaPesos}% del peso total de ${pesoTotalKpis}%). Impacto (Ahorro/Beneficio Generado y % Costo del Área) aún sin datos.`
                : 'Sin datos suficientes para calcular el cumplimiento general';

        document.getElementById('kpiGeneralBreakdown').innerHTML = detalle.map(d => {
            const barClass = d.logro >= 90 ? 'bg-success' : d.logro >= 70 ? 'bg-warning' : 'bg-danger';
            return '<div class="kpi-general-item">' +
                '<div class="d-flex justify-content-between small mb-1">' +
                    '<span>' + escapeHtml(d.nombre) + ' <span class="text-muted">(' + d.peso + '/' + sumaPesos + ')</span></span>' +
                    '<span class="fw-semibold">' + d.logro.toFixed(1) + '%</span>' +
                '</div>' +
                '<div class="progress" style="height:5px;background:rgba(255,255,255,0.15)">' +
                    '<div class="progress-bar ' + barClass + '" style="width:' + d.logro + '%"></div>' +
                '</div>' +
            '</div>';
        }).join('');
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
            `Tickets cerrados (CERRADO=1) cuya Fecha Tarea cae en ${periodo}. SLA: 48 horas hábiles entre Fecha Tarea y Fecha Cierre (no cuentan fines de semana ni feriados).`;
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

    // ── Procesos detail modal (KPI 4) ────────────────────────────────────────
    cargarProcesosDetalle: function() {
        const rango = this.rangoActivo();
        if (!rango) return;
        const periodo = this.textoPeriodo(rango);

        document.getElementById('procesosDetalleNota').textContent =
            `Procesos con Fecha de Relevado (created_at) en ${periodo}. Cuentan para el KPI los que ya alcanzaron madurez (score_actual) ≥ 2.0 (Gestionado).`;
        document.getElementById('procesosDetalleResumen').textContent = '';
        document.getElementById('procesosDetalleTabla').innerHTML =
            '<tr><td colspan="8" class="text-center text-muted py-3">' +
            '<i class="fas fa-spinner fa-spin me-2"></i>Cargando...</td></tr>';

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalProcesosDetalle')).show();

        const params = new URLSearchParams({ action: 'kpi-detalle', tipo: 'procesos', fecha_desde: rango.desde, fecha_hasta: rango.hasta });
        fetch('api/kpis.php?' + params)
            .then(r => r.json())
            .then(data => {
                if (!data.success) { this.mostrarError('Error al cargar detalle: ' + (data.error || '')); return; }
                this.pintarTablaProcesosDetalle(data);
            })
            .catch(() => this.mostrarError('Error al cargar detalle de procesos'));
    },

    pintarTablaProcesosDetalle: function(data) {
        const Y = data.total;
        const X = data.cumplen;
        const pct = Y > 0 ? (X / Y * 100).toFixed(1) : null;
        const badgeClass = pct === null ? 'bg-secondary' : parseFloat(pct) >= 60 ? 'bg-success' : 'bg-warning text-dark';

        document.getElementById('procesosDetalleResumen').innerHTML =
            '<div class="d-flex align-items-center gap-4 flex-wrap">' +
                '<div>' +
                    '<div class="kpi-detalle-count">' + X +
                        '<span class="text-muted fw-normal" style="font-size:.65em"> / ' + Y + '</span>' +
                    '</div>' +
                    '<div class="small text-muted">relevados (≥ Gestionado)</div>' +
                '</div>' +
                '<span class="badge rounded-pill ' + badgeClass + ' kpi-detalle-pct-badge">' + (pct !== null ? pct + '%' : '—') + '</span>' +
            '</div>';

        const tbody = document.getElementById('procesosDetalleTabla');
        if (!data.procesos || data.procesos.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" class="text-center py-4">' +
                '<i class="fas fa-inbox fa-2x text-muted d-block mb-2"></i>' +
                '<span class="text-muted">No hay procesos para el período seleccionado.</span>' +
                '</td></tr>';
            return;
        }
        tbody.innerHTML = data.procesos.map(p => {
            const estadoBadge = p.relevado
                ? '<span class="badge rounded-pill bg-success">Relevado</span>'
                : '<span class="badge rounded-pill bg-secondary">Informal</span>';
            return '<tr>' +
                '<td class="ps-3 text-nowrap">' + escapeHtml(p.codigo || '') + '</td>' +
                '<td>' + escapeHtml(p.nombre || '') + '</td>' +
                '<td>' + escapeHtml(p.area || '') + '</td>' +
                '<td>' + escapeHtml(p.categoria || '') + '</td>' +
                '<td class="text-nowrap">' + (p.criticidad !== null ? p.criticidad : '-') + '</td>' +
                '<td class="text-nowrap kpi-estrellas">' + renderEstrellas(p.score_actual) + '</td>' +
                '<td class="text-nowrap">' + (p.created_at || '-') + '</td>' +
                '<td class="text-nowrap">' + estadoBadge + '</td>' +
                '</tr>';
        }).join('');
    },

    // ── Instructivo de medición por KPI ──────────────────────────────────────
    mostrarInstructivo: function(kpiId) {
        const info = INSTRUCTIVOS_KPI[kpiId];
        if (!info) return;

        const cat = CATEGORIAS_INFO[info.categoria] || { icono: 'fa-tag', clase: 'kpi-badge-ejecucion' };

        document.getElementById('instructivoTitulo').textContent = info.titulo;
        document.getElementById('instructivoMeta').innerHTML =
            '<span class="badge kpi-instructivo-badge ' + cat.clase + '"><i class="fas ' + cat.icono + ' me-1"></i>' + escapeHtml(info.categoria) + '</span>' +
            '<span class="text-muted small"><i class="fas fa-repeat me-1"></i>' + escapeHtml(info.frecuencia) + '</span>' +
            '<span class="text-muted small"><i class="fas fa-weight-hanging me-1"></i>Peso: ' + escapeHtml(info.peso) + '</span>' +
            '<span class="text-muted small"><i class="fas fa-bullseye me-1"></i>Objetivo: ' + escapeHtml(info.objetivo) + '</span>';

        const avisoEl = document.getElementById('instructivoAviso');
        if (info.aviso) {
            document.getElementById('instructivoAvisoTexto').textContent = info.aviso;
            avisoEl.classList.remove('d-none');
        } else {
            avisoEl.classList.add('d-none');
        }

        document.getElementById('instructivoFormula').textContent = info.formula;
        document.getElementById('instructivoFuente').textContent = info.fuente;
        document.getElementById('instructivoReglas').innerHTML =
            info.reglas.map(r => '<li><i class="fas fa-circle-minus me-2"></i>' + escapeHtml(r) + '</li>').join('');
        document.getElementById('instructivoEjemplo').textContent = info.ejemplo;

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalInstructivo')).show();
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
