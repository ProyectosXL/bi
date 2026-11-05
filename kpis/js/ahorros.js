/**
 * KPI "Ahorro / Beneficio Generado": modal "Ver ahorros", carga/edición,
 * baja, historial y parámetros. Usa los helpers de kpis.js (escapeHtml,
 * formatoPesos, fechaPantalla, mesPantalla) y KpisManager (rango y permisos).
 *
 * Los controles de edición se ocultan sin kpis.editar (KpisManager.puedeEditar
 * y .kpi-solo-edicion), pero la API valida cada escritura por su cuenta.
 */

const AhorrosUI = {

    // Nombres de pantalla de las claves de RO_T_KPI_PARAMETROS (historial).
    NOMBRES_PARAMETROS: {
        OBJETIVO_AHORRO_ANUAL: 'Objetivo anual de ahorro',
        COSTO_HORA_DEFAULT:    'Costo hora por defecto'
    },

    catalogos: null,
    ahorros: [],
    expandidos: {},
    ahorroEnBaja: null,
    volverAlListado: false,
    huboCambios: false,

    // Estado del formulario
    _modo: null,
    _proveedores: [],
    _fechasTocadas: false,
    _previewFirma: null,
    _previewTimer: null,
    _buscarTimer: null,

    init: function() {
        $(document).on('click', '.btn-ver-ahorros', () => this.abrirListado());
        $(document).on('click', '.btn-parametros', () => this.abrirParametros());
        $('#switchAhorrosBajas').on('change', () => this.cargarListado());
        $('#btnNuevoAhorro').on('click', () => this.abrirFormulario(null));

        $('#ahorrosTabla').on('click', '.kpi-btn-expandir', (e) => {
            const id = e.currentTarget.dataset.id;
            this.expandidos[id] = !this.expandidos[id];
            this.renderTabla();
        });
        $('#ahorrosTabla').on('click', '.btn-editar-ahorro', (e) => this.abrirFormulario(this.buscarAhorro(e.currentTarget.dataset.id)));
        $('#ahorrosTabla').on('click', '.btn-baja-ahorro', (e) => this.abrirBaja(this.buscarAhorro(e.currentTarget.dataset.id)));
        $('#ahorrosTabla').on('click', '.btn-historial-ahorro', (e) => this.abrirHistorial(this.buscarAhorro(e.currentTarget.dataset.id)));

        // Formulario
        $('#ahorroTipo').on('change', () => this.aplicarModo());
        $('#ahorroPeriodicidad').on('change', () => this.actualizarEtiquetasMonto());
        $('#ahorroHoras, #ahorroCostoHora').on('input', () => this.actualizarMontoHoras());
        $('#ahorroDesde, #ahorroHasta').on('input', () => { this._fechasTocadas = true; });
        $('#btnGuardarAhorro').on('click', () => this.guardarAhorro());

        $('#ahorroProyectoBuscar').on('input', () => this.buscarConDemora(() => this.buscarProyectos()));
        $('#btnQuitarProyecto').on('click', () => this.elegirProyecto(null));
        $('#ahorroProyectoResultados').on('click', '.list-group-item', (e) => {
            this.elegirProyecto(JSON.parse(e.currentTarget.dataset.proyecto));
        });

        $('#ahorroProveedorBuscar').on('input', () => this.buscarConDemora(() => this.buscarProveedores()));
        $('#ahorroProveedorResultados').on('click', '.list-group-item', (e) => {
            this.agregarProveedor(JSON.parse(e.currentTarget.dataset.proveedor));
        });
        $('#ahorroProveedoresTabla').on('input change', '.input-fecha-baja', (e) => {
            this._proveedores[e.currentTarget.dataset.idx].fecha_baja = e.currentTarget.value;
            this.proveedoresCambiaron();
        });
        $('#ahorroProveedoresTabla').on('click', '.btn-quitar-proveedor', (e) => {
            this._proveedores.splice(parseInt(e.currentTarget.dataset.idx, 10), 1);
            this.renderProveedores();
            this.proveedoresCambiaron();
        });

        // Cerrar los resultados de los buscadores al hacer clic afuera.
        $(document).on('click', (e) => {
            if (!$(e.target).closest('#ahorroProyectoBuscar, #ahorroProyectoResultados').length) $('#ahorroProyectoResultados').addClass('d-none');
            if (!$(e.target).closest('#ahorroProveedorBuscar, #ahorroProveedorResultados').length) $('#ahorroProveedorResultados').addClass('d-none');
        });

        $('#btnConfirmarBajaAhorro').on('click', () => this.confirmarBaja());
        $('#btnGuardarParametros').on('click', () => this.guardarParametros());

        // Bootstrap no apila modales: los modales hijos (formulario, baja,
        // historial) ocultan el listado y, al cerrarse, lo vuelven a abrir.
        $('#modalAhorroForm, #modalAhorroBaja, #modalHistorial').on('hidden.bs.modal', () => {
            if (!this.volverAlListado) return;
            this.volverAlListado = false;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAhorros')).show();
            if (this.huboCambios) this.cargarListado();
        });
        $('#modalAhorros').on('hidden.bs.modal', () => {
            if (this.huboCambios && !this.volverAlListado) {
                this.huboCambios = false;
                KpisManager.cargarKPIResumen();
            }
        });
    },

    // ── API ───────────────────────────────────────────────────────────────
    /** fetch a api/kpis.php. Resuelve { ok, status, data } (data.error trae el mensaje). */
    api: function(action, params = {}, body = null) {
        const qs = new URLSearchParams(Object.assign({ action }, params));
        const opts = body === null ? {} : {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        };
        return fetch('api/kpis.php?' + qs, opts)
            .then(r => r.json().catch(() => ({ success: false, error: 'Respuesta inválida del servidor.' }))
                .then(data => ({ ok: r.ok && data.success !== false, status: r.status, data })))
            .catch(() => ({ ok: false, status: 0, data: { success: false, error: 'Error de red.' } }));
    },

    cargarCatalogos: function() {
        if (this.catalogos) return Promise.resolve(this.catalogos);
        return this.api('ahorros-catalogos').then(res => {
            if (!res.ok) throw new Error(res.data.error || 'No se pudieron cargar los catálogos.');
            this.catalogos = res.data;
            return res.data;
        });
    },

    buscarAhorro: function(id) {
        return this.ahorros.find(a => String(a.id) === String(id)) || null;
    },

    mostrarErrores: function(selector, res) {
        const errores = (res.data && res.data.errores) ? res.data.errores : [res.data.error || 'No se pudo guardar.'];
        $(selector).html(errores.length > 1
            ? '<i class="fas fa-triangle-exclamation me-1"></i>Revisá los datos:<ul class="mb-0 mt-1">' + errores.map(e => '<li>' + escapeHtml(e) + '</li>').join('') + '</ul>'
            : '<i class="fas fa-triangle-exclamation me-1"></i>' + escapeHtml(errores[0])
        ).removeClass('d-none');
    },

    abrirHijo: function(modalId) {
        const listado = document.getElementById('modalAhorros');
        if (listado.classList.contains('show')) {
            this.volverAlListado = true;
            bootstrap.Modal.getOrCreateInstance(listado).hide();
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById(modalId)).show();
    },

    // ── Listado ("Ver ahorros") ───────────────────────────────────────────
    abrirListado: function() {
        const rango = KpisManager.rangoActivo();
        if (!rango) return;
        this.expandidos = {};
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAhorros')).show();
        this.cargarListado();
    },

    cargarListado: function() {
        const rango = KpisManager.rangoActivo();
        if (!rango) return;
        $('#ahorrosNota').text('Ahorro devengado en ' + KpisManager.textoPeriodo(rango) +
            '. Los montos de "Baja de proveedor" se recalculan con lo facturado en Tango en cada consulta.');
        $('#ahorrosResumen, #ahorrosPorTipo').empty();
        $('#ahorrosTabla').html('<tr><td colspan="14" class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-2"></i>Cargando...</td></tr>');

        this.api('ahorros-listado', {
            fecha_desde: rango.desde, fecha_hasta: rango.hasta,
            incluir_bajas: $('#switchAhorrosBajas').is(':checked') ? 1 : 0
        }).then(res => {
            if (!res.ok) {
                $('#ahorrosTabla').html('<tr><td colspan="14" class="text-center text-danger py-3">' + escapeHtml(res.data.error || 'Error al cargar los ahorros') + '</td></tr>');
                return;
            }
            this.ahorros = res.data.ahorros || [];
            this.renderResumen(res.data);
            this.renderTabla();
        });
    },

    renderResumen: function(d) {
        const stat = (label, valor, extra = '') =>
            '<div class="col-6 col-md"><div class="kpi-ahorro-stat">' +
                '<div class="kpi-ahorro-stat-label">' + label + '</div>' +
                '<div class="kpi-ahorro-stat-valor">' + valor + '</div>' + extra +
            '</div></div>';
        const pct = d.pct_ahorro;
        const objetivo = d.ahorro_objetivo_anual > 0
            ? formatoPesos(d.ahorro_objetivo_rango)
            : 'Sin definir';
        const objetivoExtra = d.ahorro_objetivo_anual > 0
            ? '<div class="small text-muted">' + formatoPesos(d.ahorro_objetivo_anual) + ' anual</div>' : '';

        $('#ahorrosResumen').html(
            stat('Ahorro del rango', formatoPesos(d.ahorro_rango)) +
            stat('Proyección ' + d.ahorro_anio_proyeccion, formatoPesos(d.ahorro_proyeccion)) +
            stat('Objetivo del rango', objetivo, objetivoExtra) +
            stat('% de logro', pct !== null ? pct.toFixed(1) + '%' : '—')
        );

        const tipos = d.ahorro_por_tipo || [];
        $('#ahorrosPorTipo').html(tipos.length
            ? '<span class="text-muted me-2"><i class="fas fa-layer-group me-1"></i>Por tipo:</span>' +
              tipos.map(t => '<span class="badge bg-light text-dark border me-1 mb-1">' + escapeHtml(t.tipo) +
                  ': ' + formatoPesos(t.rango) + ' <span class="text-muted">(proy. ' + formatoPesos(t.proyeccion) + ')</span></span>').join('')
            : '');
    },

    renderTabla: function() {
        const tbody = document.getElementById('ahorrosTabla');
        if (!this.ahorros.length) {
            tbody.innerHTML = '<tr><td colspan="14" class="text-center py-4">' +
                '<i class="fas fa-inbox fa-2x text-muted d-block mb-2"></i>' +
                '<span class="text-muted">Todavía no hay ahorros cargados.</span></td></tr>';
            return;
        }
        tbody.innerHTML = this.ahorros.map(a => this.filaAhorro(a) +
            (a.modo === 'PROVEEDOR' && this.expandidos[a.id] ? this.filaDetalleProveedores(a) : '')).join('');
    },

    filaAhorro: function(a) {
        const expandir = a.modo === 'PROVEEDOR'
            ? '<button type="button" class="kpi-btn-expandir" data-id="' + a.id + '" aria-expanded="' + (this.expandidos[a.id] ? 'true' : 'false') +
              '" title="Ver el cálculo por proveedor"><i class="fas fa-chevron-right"></i></button>'
            : '';
        const monto = formatoPesos(a.monto, 2) + (a.periodicidad === 'UNICO' ? ' <span class="text-muted">único</span>' : ' <span class="text-muted">/ mes</span>');
        const avisos = (a.avisos || []).map(av => '<span class="badge bg-warning-subtle text-warning-emphasis kpi-aviso d-block mb-1"><i class="fas fa-triangle-exclamation me-1"></i>' + escapeHtml(av) + '</span>').join('');
        const ultima = a.activo
            ? escapeHtml(a.usuario_modif || a.usuario_alta) + '<br><span class="text-muted">' + fechaPantalla(a.fecha_modif || a.fecha_alta) + '</span>'
            : '<span class="badge bg-danger">Baja</span> ' + escapeHtml(a.usuario_baja || '') + '<br><span class="text-muted">' + fechaPantalla(a.fecha_baja) + '</span>';
        const acciones =
            (a.activo
                ? '<button type="button" class="btn btn-sm btn-outline-primary btn-editar-ahorro me-1" data-id="' + a.id + '" title="Editar"><i class="fas fa-pen"></i></button>' +
                  '<button type="button" class="btn btn-sm btn-outline-danger btn-baja-ahorro me-1" data-id="' + a.id + '" title="Dar de baja"><i class="fas fa-ban"></i></button>'
                : '') +
            '<button type="button" class="btn btn-sm btn-outline-secondary btn-historial-ahorro" data-id="' + a.id + '" title="Historial"><i class="fas fa-clock-rotate-left"></i></button>';

        return '<tr class="' + (a.activo ? '' : 'kpi-ahorro-baja') + '">' +
            '<td class="ps-3 kpi-no-tachar">' + expandir + '</td>' +
            '<td>' + escapeHtml(a.descripcion) + (a.observaciones ? '<div class="small text-muted">' + escapeHtml(a.observaciones) + '</div>' : '') + '</td>' +
            '<td class="text-nowrap">' + escapeHtml(a.tipo_nombre) + '</td>' +
            '<td>' + escapeHtml(a.sector_nombre || '') + '</td>' +
            '<td>' + (a.proyecto_id ? '#' + a.proyecto_id + ' ' + escapeHtml(a.proyecto_nombre || '') : '<span class="text-muted">—</span>') + '</td>' +
            '<td>' + (a.periodicidad === 'UNICO' ? 'Único' : 'Mensual') + '</td>' +
            '<td class="text-nowrap">' + fechaPantalla(a.fecha_desde) + '</td>' +
            '<td class="text-nowrap">' + fechaPantalla(a.fecha_hasta) + '</td>' +
            '<td class="text-end text-nowrap">' + monto + '</td>' +
            '<td class="text-end text-nowrap fw-semibold">' + formatoPesos(a.ahorro_rango, 2) + '</td>' +
            '<td class="text-end text-nowrap">' + formatoPesos(a.proyeccion_anio, 2) + '</td>' +
            '<td class="kpi-no-tachar">' + avisos + '</td>' +
            '<td class="small text-nowrap kpi-no-tachar">' + ultima + '</td>' +
            '<td class="pe-3 text-nowrap kpi-solo-edicion kpi-no-tachar">' + acciones + '</td>' +
        '</tr>';
    },

    filaDetalleProveedores: function(a) {
        return '<tr class="kpi-ahorro-detalle"><td></td><td colspan="13" class="py-2">' +
            this.tablaCalculoProveedores(a.proveedores.map(p => p.calculo)) + '</td></tr>';
    },

    /** Tabla del cálculo de 2.4 por proveedor (listado y vista previa del formulario). */
    tablaCalculoProveedores: function(calculos) {
        if (!calculos.length) return '<span class="text-muted small">Sin proveedores.</span>';
        const filas = calculos.map(c => {
            const avisos = (c.avisos || []).map(av => '<span class="badge bg-warning-subtle text-warning-emphasis d-block mb-1"><i class="fas fa-triangle-exclamation me-1"></i>' + escapeHtml(av) + '</span>').join('');
            const posteriores = (c.posteriores || []).length
                ? '<tr class="small"><td></td><td colspan="9" class="pt-0">' +
                    '<div class="text-warning-emphasis mb-1"><i class="fas fa-file-invoice me-1"></i>Comprobantes posteriores a la baja (no se descuentan del ahorro):</div>' +
                    '<table class="table table-sm table-borderless mb-0 w-auto"><tbody>' +
                    c.posteriores.map(p => '<tr><td class="py-0 pe-3">' + fechaPantalla(p.fecha) + '</td><td class="py-0 pe-3">' + escapeHtml(p.t_comp) + ' ' + escapeHtml(p.n_comp) +
                        '</td><td class="py-0 pe-3 text-muted">' + escapeHtml(p.descripcion || '') + '</td><td class="py-0 text-end">' + formatoPesos(p.importe, 2) + '</td></tr>').join('') +
                    '</tbody></table></td></tr>'
                : '';
            return '<tr>' +
                '<td class="text-nowrap"><strong>' + escapeHtml(c.cod_provee) + '</strong> - ' + escapeHtml(c.nombre || 'No existe en Tango') + '</td>' +
                '<td class="text-nowrap">' + escapeHtml(c.cond_iva || '') + ' <span class="text-muted">(' + (c.con_iva ? 'con IVA' : 'sin IVA') + ')</span></td>' +
                '<td class="text-nowrap">' + fechaPantalla(c.fecha_baja) + '</td>' +
                '<td class="text-end">' + c.comprobantes + '</td>' +
                '<td class="text-end text-nowrap">' + formatoPesos(c.total_neto, 2) + '</td>' +
                '<td class="text-end">' + c.meses_considerados + '</td>' +
                '<td class="text-end text-nowrap fw-semibold">' + formatoPesos(c.promedio_mensual, 2) + '</td>' +
                '<td class="text-nowrap">' + (c.inicio_ahorro ? mesPantalla(c.inicio_ahorro) : '—') + '</td>' +
                '<td class="text-end text-nowrap">' + formatoPesos(c.ahorro_proyectado_anio, 2) + '</td>' +
                '<td>' + avisos + '</td>' +
            '</tr>' + posteriores;
        }).join('');
        return '<div class="table-responsive"><table class="table table-sm align-middle mb-0 bg-white">' +
            '<thead class="table-light"><tr>' +
                '<th>Proveedor</th><th>Cond. IVA</th><th>Fecha de baja</th><th class="text-end">Comprob.</th>' +
                '<th class="text-end">Total facturado neto</th><th class="text-end">Meses considerados</th>' +
                '<th class="text-end">Promedio mensual</th><th>Inicio del ahorro</th><th class="text-end">Ahorro proyectado del año</th><th>Avisos</th>' +
            '</tr></thead><tbody>' + filas + '</tbody></table></div>';
    },

    // ── Formulario de alta / edición ──────────────────────────────────────
    abrirFormulario: function(ahorro) {
        if (!KpisManager.puedeEditar) return;
        this.cargarCatalogos().then(cat => {
            $('#ahorroFormErrores').addClass('d-none').empty();
            $('#ahorroTipo').html('<option value="">Elegí el tipo…</option>' +
                cat.tipos.map(t => '<option value="' + t.id + '" data-modo="' + t.modo + '">' + escapeHtml(t.nombre) + '</option>').join(''));
            $('#ahorroSector').html('<option value="">Elegí el sector…</option>' +
                cat.sectores.map(s => '<option value="' + s.id + '">' + escapeHtml(s.nombre) + '</option>').join(''));

            const a = ahorro || {};
            $('#ahorroFormTitulo').text(ahorro ? 'Editar ahorro' : 'Nuevo ahorro');
            $('#ahorroId').val(a.id || '');
            $('#ahorroTipo').val(a.tipo_id || '');
            $('#ahorroDescripcion').val(a.descripcion || '');
            $('#ahorroSector').val(a.sector_id || '');
            $('#ahorroSectorAviso').empty();
            $('#ahorroPeriodicidad').val(a.periodicidad || '');
            $('#ahorroDesde').val(a.fecha_desde || '');
            $('#ahorroHasta').val(a.fecha_hasta || '');
            $('#ahorroMonto').val(ahorro && a.modo === 'MONTO' ? a.monto : '');
            $('#ahorroHoras').val(a.horas_mes || '');
            $('#ahorroCostoHora').val(a.costo_hora || (cat.parametros.costo_hora_default || ''));
            $('#ahorroObservaciones').val(a.observaciones || '');
            $('#ahorroMotivo').val('');
            $('#ahorroMotivoGrupo').toggleClass('d-none', !ahorro);
            this.elegirProyecto(a.proyecto_id ? { id: a.proyecto_id, desarrollo: a.proyecto_nombre } : null, false);

            this._proveedores = (a.proveedores || []).map(p => ({ cod_provee: p.cod_provee, nombre: p.calculo ? p.calculo.nombre : '', fecha_baja: p.fecha_baja }));
            this._fechasTocadas = !!ahorro;     // al editar, las fechas ya son del usuario
            this._previewFirma = null;
            this.renderProveedores();
            this.aplicarModo();
            if (this._modo === 'PROVEEDOR' && this._proveedores.length) this.calcularPreview();

            this.abrirHijo('modalAhorroForm');
        }).catch(err => KpisManager.mostrarError(err.message));
    },

    aplicarModo: function() {
        const opt = $('#ahorroTipo option:selected');
        this._modo = opt.data('modo') || null;
        $('#ahorroCamposComunes').toggleClass('d-none', !this._modo);
        $('#ahorroModoMonto').toggleClass('d-none', this._modo !== 'MONTO');
        $('#ahorroModoHoras').toggleClass('d-none', this._modo !== 'HORAS');
        $('#ahorroModoProveedor').toggleClass('d-none', this._modo !== 'PROVEEDOR');

        // Baja de proveedor: periodicidad fija en mensual.
        const per = $('#ahorroPeriodicidad');
        if (this._modo === 'PROVEEDOR') {
            per.val('MENSUAL').prop('disabled', true);
            this.precargarFechasProveedor();
        } else {
            per.prop('disabled', false);
        }
        this.actualizarEtiquetasMonto();
        this.actualizarMontoHoras();
    },

    actualizarEtiquetasMonto: function() {
        const unico = $('#ahorroPeriodicidad').val() === 'UNICO';
        $('#ahorroMontoLabel').text(unico ? 'Monto único' : 'Monto mensual');
        $('#ahorroHorasPeriodo').text(unico ? 'único' : 'mensual');
    },

    actualizarMontoHoras: function() {
        const h = parseFloat($('#ahorroHoras').val()) || 0;
        const c = parseFloat($('#ahorroCostoHora').val()) || 0;
        $('#ahorroHorasMonto').text(formatoPesos(Math.round(h * c * 100) / 100, 2));
    },

    buscarConDemora: function(fn) {
        clearTimeout(this._buscarTimer);
        this._buscarTimer = setTimeout(fn, 300);
    },

    // Proyecto asociado (HistorialProyectos)
    buscarProyectos: function() {
        const q = $('#ahorroProyectoBuscar').val().trim();
        const cont = $('#ahorroProyectoResultados');
        if (!q) { cont.addClass('d-none').empty(); return; }
        this.api('buscar-proyectos', { q }).then(res => {
            const lista = res.ok ? res.data.proyectos : [];
            cont.html(lista.length
                ? lista.map(p => '<button type="button" class="list-group-item list-group-item-action" data-proyecto="' + escapeHtml(JSON.stringify(p)) + '">' +
                    '<strong>#' + p.id + '</strong> ' + escapeHtml(p.desarrollo || '') +
                    ' <span class="text-muted">· ' + escapeHtml(p.sector || 'sin sector') + (p.estado ? ' · ' + escapeHtml(p.estado) : '') + '</span></button>').join('')
                : '<div class="list-group-item text-muted">Sin resultados</div>').removeClass('d-none');
        });
    },

    elegirProyecto: function(p, precargarSector = true) {
        $('#ahorroProyectoResultados').addClass('d-none');
        if (!p) {
            $('#ahorroProyectoId').val('');
            $('#ahorroProyectoBuscar').val('');
            $('#ahorroProyectoElegido').empty();
            return;
        }
        $('#ahorroProyectoId').val(p.id);
        $('#ahorroProyectoBuscar').val('#' + p.id + ' ' + (p.desarrollo || ''));
        $('#ahorroProyectoElegido').html('<i class="fas fa-link me-1"></i>Proyecto #' + p.id + ' asociado');
        if (!precargarSector) return;
        // Sector del proyecto, si tiene equivalente en FP_SECTORES; si no, a mano.
        if (p.sector_id_sugerido) {
            $('#ahorroSector').val(p.sector_id_sugerido);
            $('#ahorroSectorAviso').html('<i class="fas fa-wand-magic-sparkles me-1"></i>Precargado del sector del proyecto (' + escapeHtml(p.sector) + ').');
        } else {
            $('#ahorroSectorAviso').html(p.sector
                ? '<i class="fas fa-circle-info me-1"></i>El sector del proyecto (' + escapeHtml(p.sector) + ') no tiene equivalente: elegilo a mano.'
                : '');
        }
    },

    // Proveedores (CPA01) del modo PROVEEDOR
    buscarProveedores: function() {
        const q = $('#ahorroProveedorBuscar').val().trim();
        const cont = $('#ahorroProveedorResultados');
        if (q.length < 2) { cont.addClass('d-none').empty(); return; }
        this.api('buscar-proveedores', { q }).then(res => {
            const lista = res.ok ? res.data.proveedores : [];
            cont.html(lista.length
                ? lista.map(p => '<button type="button" class="list-group-item list-group-item-action" data-proveedor="' + escapeHtml(JSON.stringify(p)) + '">' +
                    '<strong>' + escapeHtml(p.cod_provee) + '</strong> - ' + escapeHtml(p.nombre) + ' <span class="text-muted">(' + escapeHtml(p.cond_iva || '') + ')</span></button>').join('')
                : '<div class="list-group-item text-muted">Sin resultados</div>').removeClass('d-none');
        });
    },

    agregarProveedor: function(p) {
        $('#ahorroProveedorResultados').addClass('d-none');
        $('#ahorroProveedorBuscar').val('');
        if (this._proveedores.some(x => x.cod_provee === p.cod_provee)) return;
        this._proveedores.push({ cod_provee: p.cod_provee, nombre: p.nombre, fecha_baja: '' });
        this.renderProveedores();
        this.proveedoresCambiaron();
    },

    renderProveedores: function() {
        const tbody = $('#ahorroProveedoresTabla');
        if (!this._proveedores.length) {
            tbody.html('<tr><td colspan="3" class="text-muted small">Todavía no agregaste proveedores.</td></tr>');
            return;
        }
        tbody.html(this._proveedores.map((p, i) =>
            '<tr><td><strong>' + escapeHtml(p.cod_provee) + '</strong> - ' + escapeHtml(p.nombre || '') + '</td>' +
            '<td><input type="date" class="form-control form-control-sm input-fecha-baja" data-idx="' + i + '" value="' + escapeHtml(p.fecha_baja || '') + '"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger btn-quitar-proveedor" data-idx="' + i + '" title="Quitar"><i class="fas fa-xmark"></i></button></td></tr>'
        ).join(''));
    },

    firmaProveedores: function() {
        return JSON.stringify(this._proveedores.map(p => [p.cod_provee, p.fecha_baja]));
    },

    proveedoresCambiaron: function() {
        this.precargarFechasProveedor();
        this._previewFirma = null;
        clearTimeout(this._previewTimer);
        this._previewTimer = setTimeout(() => this.calcularPreview(), 400);
    },

    /** Fecha desde = la baja más temprana; hasta = 31/12 de ese año (si el usuario no las tocó). */
    precargarFechasProveedor: function() {
        if (this._modo !== 'PROVEEDOR' || this._fechasTocadas) return;
        const fechas = this._proveedores.map(p => p.fecha_baja).filter(f => /^\d{4}-\d{2}-\d{2}$/.test(f)).sort();
        if (!fechas.length) return;
        $('#ahorroDesde').val(fechas[0]);
        $('#ahorroHasta').val(fechas[0].slice(0, 4) + '-12-31');
    },

    calcularPreview: function() {
        const cont = $('#ahorroProveedoresCalculo');
        if (!this._proveedores.length) {
            cont.html('Agregá un proveedor con su fecha de baja para ver el cálculo.');
            return Promise.resolve(false);
        }
        if (this._proveedores.some(p => !p.fecha_baja)) {
            cont.html('<span class="text-warning-emphasis"><i class="fas fa-calendar-xmark me-1"></i>Cargá la fecha de baja de cada proveedor para ver el cálculo.</span>');
            return Promise.resolve(false);
        }
        const firma = this.firmaProveedores();
        cont.html('<i class="fas fa-spinner fa-spin me-2"></i>Calculando con lo facturado en Tango…');
        return this.api('ahorro-preview-proveedores', {}, { proveedores: this._proveedores }).then(res => {
            if (firma !== this.firmaProveedores()) return false;   // cambió mientras calculaba
            if (!res.ok) {
                cont.html('<span class="text-danger">' + escapeHtml(res.data.error || 'No se pudo calcular') + '</span>');
                return false;
            }
            const total = res.data.proveedores.reduce((s, c) => s + c.promedio_mensual, 0);
            cont.removeClass('text-muted').html(this.tablaCalculoProveedores(res.data.proveedores) +
                '<div class="mt-2">Monto mensual del ahorro (suma de promedios): <strong>' + formatoPesos(total, 2) + '</strong></div>');
            this._previewFirma = firma;
            return true;
        });
    },

    datosFormulario: function() {
        const d = {
            descripcion:   $('#ahorroDescripcion').val(),
            tipo_id:       $('#ahorroTipo').val(),
            sector_id:     $('#ahorroSector').val(),
            proyecto_id:   $('#ahorroProyectoId').val() || null,
            periodicidad:  $('#ahorroPeriodicidad').val(),
            fecha_desde:   $('#ahorroDesde').val(),
            fecha_hasta:   $('#ahorroHasta').val(),
            observaciones: $('#ahorroObservaciones').val()
        };
        if ($('#ahorroId').val()) {
            d.id = $('#ahorroId').val();
            d.motivo = $('#ahorroMotivo').val();
        }
        if (this._modo === 'MONTO') d.monto = $('#ahorroMonto').val();
        if (this._modo === 'HORAS') { d.horas_mes = $('#ahorroHoras').val(); d.costo_hora = $('#ahorroCostoHora').val(); }
        if (this._modo === 'PROVEEDOR') d.proveedores = this._proveedores.map(p => ({ cod_provee: p.cod_provee, fecha_baja: p.fecha_baja }));
        return d;
    },

    guardarAhorro: function() {
        $('#ahorroFormErrores').addClass('d-none').empty();
        if (!this._modo) {
            this.mostrarErrores('#ahorroFormErrores', { data: { error: 'Falta el tipo de ahorro.' } });
            return;
        }
        // Baja de proveedor: el cálculo tiene que estar a la vista antes de guardar.
        if (this._modo === 'PROVEEDOR' && this._proveedores.length && this._previewFirma !== this.firmaProveedores()) {
            this.calcularPreview().then(ok => {
                if (ok) this.mostrarErrores('#ahorroFormErrores', { data: { error: 'Revisá el cálculo por proveedor y tocá Guardar de nuevo.' } });
            });
            return;
        }
        const $btn = $('#btnGuardarAhorro').prop('disabled', true);
        this.api('ahorro-guardar', {}, this.datosFormulario()).then(res => {
            $btn.prop('disabled', false);
            if (!res.ok) { this.mostrarErrores('#ahorroFormErrores', res); return; }
            this.huboCambios = true;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAhorroForm')).hide();
            KpisManager.cargarKPIResumen();
        });
    },

    // ── Baja ──────────────────────────────────────────────────────────────
    abrirBaja: function(a) {
        if (!a || !KpisManager.puedeEditar) return;
        this.ahorroEnBaja = a;
        $('#ahorroBajaDescripcion').text(a.descripcion);
        $('#ahorroBajaMotivo').val('');
        $('#ahorroBajaErrores').addClass('d-none').empty();
        this.abrirHijo('modalAhorroBaja');
    },

    confirmarBaja: function() {
        if (!this.ahorroEnBaja) return;
        const $btn = $('#btnConfirmarBajaAhorro').prop('disabled', true);
        this.api('ahorro-baja', {}, { id: this.ahorroEnBaja.id, motivo: $('#ahorroBajaMotivo').val() }).then(res => {
            $btn.prop('disabled', false);
            if (!res.ok) { this.mostrarErrores('#ahorroBajaErrores', res); return; }
            this.huboCambios = true;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAhorroBaja')).hide();
            KpisManager.cargarKPIResumen();
        });
    },

    // ── Historial ─────────────────────────────────────────────────────────
    abrirHistorial: function(a) {
        if (!a) return;
        $('#historialTitulo').html('<i class="fas fa-clock-rotate-left me-2"></i>Historial · ' + escapeHtml(a.descripcion));
        $('#historialCuerpo').html('<div class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-2"></i>Cargando...</div>');
        this.abrirHijo('modalHistorial');
        this.api('ahorros-historial', { entidad: 'AHORRO', id: a.id }).then(res => {
            $('#historialCuerpo').html(res.ok ? this.renderHistorial(res.data.historial, false) : '<div class="text-danger">' + escapeHtml(res.data.error || 'Error') + '</div>');
        });
    },

    renderHistorial: function(lista, conEntidad) {
        if (!lista || !lista.length) return '<div class="text-muted">Sin cambios registrados.</div>';
        const acciones = { ALTA: 'Alta', EDICION: 'Edición', BAJA: 'Baja' };
        const clases = { ALTA: 'bg-success', EDICION: 'bg-primary', BAJA: 'bg-danger' };
        return lista.map(h =>
            '<div class="kpi-historial-item ' + h.accion.toLowerCase() + '">' +
                '<div><span class="badge ' + clases[h.accion] + ' me-2">' + acciones[h.accion] + '</span>' +
                    (conEntidad ? '<strong class="me-2">' + escapeHtml(this.NOMBRES_PARAMETROS[h.entidad_id] || h.entidad_id) + '</strong>' : '') +
                    '<i class="fas fa-user me-1 text-muted"></i>' + escapeHtml(h.usuario) +
                    ' <span class="text-muted ms-2"><i class="far fa-clock me-1"></i>' + fechaPantalla(h.fecha) + '</span></div>' +
                (h.motivo ? '<div class="mt-1"><span class="text-muted">Motivo:</span> ' + escapeHtml(h.motivo) + '</div>' : '') +
                (h.cambios && h.cambios.length
                    ? '<table class="table table-sm table-borderless small mb-0 mt-1 w-auto"><tbody>' +
                        h.cambios.map(c => '<tr><td class="py-0 pe-3 text-muted">' + escapeHtml(c.campo) + '</td>' +
                            '<td class="py-0 pe-2">' + this.valorHistorial(c.campo, c.antes) + '</td>' +
                            '<td class="py-0 pe-2"><i class="fas fa-arrow-right text-muted"></i></td>' +
                            '<td class="py-0 fw-semibold">' + this.valorHistorial(c.campo, c.despues) + '</td></tr>').join('') +
                      '</tbody></table>'
                    : '') +
            '</div>'
        ).join('');
    },

    valorHistorial: function(campo, v) {
        if (v === null || v === undefined || v === '') return '—';
        if (['Monto', 'Costo hora', 'Valor'].includes(campo) && !isNaN(v)) return formatoPesos(v, 2);
        if (['Desde', 'Hasta'].includes(campo)) return fechaPantalla(v);
        return escapeHtml(String(v));
    },

    // ── Parámetros ────────────────────────────────────────────────────────
    abrirParametros: function() {
        if (!KpisManager.puedeEditar) return;
        this.catalogos = null;   // relee los valores vigentes
        this.cargarCatalogos().then(cat => {
            $('#paramObjetivo').val(cat.parametros.objetivo_ahorro_anual);
            $('#paramCostoHora').val(cat.parametros.costo_hora_default);
            $('#paramMotivo').val('');
            $('#parametrosErrores').addClass('d-none').empty();
            this.cargarHistorialParametros();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalParametros')).show();
        }).catch(err => KpisManager.mostrarError(err.message));
    },

    cargarHistorialParametros: function() {
        $('#parametrosHistorial').html('<span class="text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Cargando...</span>');
        this.api('ahorros-historial', { entidad: 'PARAMETRO' }).then(res => {
            $('#parametrosHistorial').html(res.ok ? this.renderHistorial(res.data.historial, true) : '');
        });
    },

    guardarParametros: function() {
        $('#parametrosErrores').addClass('d-none').empty();
        const $btn = $('#btnGuardarParametros').prop('disabled', true);
        this.api('parametros-guardar', {}, {
            objetivo_ahorro_anual: $('#paramObjetivo').val(),
            costo_hora_default:    $('#paramCostoHora').val(),
            motivo:                $('#paramMotivo').val()
        }).then(res => {
            $btn.prop('disabled', false);
            if (!res.ok) { this.mostrarErrores('#parametrosErrores', res); return; }
            this.catalogos = null;
            $('#paramMotivo').val('');
            this.cargarHistorialParametros();
            KpisManager.cargarKPIResumen();
        });
    }
};
