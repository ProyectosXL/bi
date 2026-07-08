<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tablero de KPIs — Innovación</title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link href="css/kpis.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-gauge-high me-2"></i>
                Tablero de KPIs <span class="text-white-50">· Innovación</span>
            </a>
        </div>
    </nav>

    <div class="container-fluid mt-4">

        <!-- Page title -->
        <div class="row mb-2">
            <div class="col-12">
                <h5 class="text-secondary mb-0">
                    <i class="fas fa-chart-pie me-2"></i>Indicadores del período (según Fecha Límite)
                    <span class="badge bg-primary ms-2">Área Innovación</span>
                </h5>
            </div>
        </div>

        <!-- Filtro de rango de períodos (desde/hasta por mes) -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body d-flex flex-wrap align-items-end gap-3">
                        <div>
                            <label class="form-label small text-muted mb-1">Desde</label>
                            <div class="d-flex gap-2">
                                <select id="filtroMesDesde" class="form-select form-select-sm"></select>
                                <select id="filtroAnoDesde" class="form-select form-select-sm"></select>
                            </div>
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1">Hasta</label>
                            <div class="d-flex gap-2">
                                <select id="filtroMesHasta" class="form-select form-select-sm"></select>
                                <select id="filtroAnoHasta" class="form-select form-select-sm"></select>
                            </div>
                        </div>
                        <button id="btnAplicarPeriodo" class="btn btn-primary btn-sm">
                            <i class="fas fa-filter me-1"></i>Aplicar
                        </button>
                        <div id="periodoAviso" class="text-danger small d-none">
                            <i class="fas fa-triangle-exclamation me-1"></i>El período "desde" debe ser anterior o igual a "hasta".
                        </div>
                        <div class="ms-md-auto">
                            <button id="btnImportarTickets" class="btn btn-outline-success btn-sm" type="button"
                                data-bs-toggle="modal" data-bs-target="#modalImportarTickets">
                                <i class="fas fa-file-excel me-1"></i>Importar Excel (Tickets)
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cumplimiento General Ponderado -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card kpi-card kpi-card-general position-relative">
                    <div class="card-body p-3 d-flex flex-wrap align-items-center gap-4">
                        <div>
                            <p class="kpi-label mb-1">Cumplimiento General Ponderado</p>
                            <div class="d-flex align-items-baseline gap-1">
                                <span class="kpi-value" id="kpiPctGeneral">—</span>
                                <span class="fs-4 opacity-50">%</span>
                            </div>
                            <p class="kpi-sub mb-0" id="kpiTextGeneral">&nbsp;</p>
                        </div>
                        <div class="kpi-general-breakdown flex-grow-1" id="kpiGeneralBreakdown"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tarjetas KPI -->
        <div class="row mb-4">

            <!-- KPI 1: Completados vs Plan % -->
            <div class="col-12 col-md-6 col-xl-3 mb-3">
                <div class="card kpi-card h-100 position-relative">
                    <div class="card-body p-3">
                        <button class="kpi-info-btn"
                            data-bs-toggle="popover"
                            data-bs-placement="top"
                            data-bs-trigger="hover focus"
                            title="¿Cómo se calcula?"
                            data-bs-content="Proyectos con Fecha Límite dentro del rango de fechas seleccionado. Se excluyen los proyectos con estado Pausado. Fórmula: cerrados (Estado Terminado con Finalización Real) ÷ total del rango × 100.">
                            <i class="fas fa-circle-info fa-lg"></i>
                        </button>
                        <p class="kpi-label">Completados vs Plan</p>
                        <div class="d-flex align-items-baseline gap-1 mb-2">
                            <span class="kpi-value" id="kpiPctCompletados">—</span>
                            <span class="fs-5 opacity-50">%</span>
                        </div>
                        <div class="progress mb-1" style="height:5px;background:rgba(255,255,255,0.15)">
                            <div class="progress-bar" id="kpiBarCompletados" role="progressbar" style="width:0%;transition:width .8s ease"></div>
                        </div>
                        <p class="kpi-sub" id="kpiTextCompletados">&nbsp;</p>
                        <div class="kpi-meta">
                            <span><i class="fas fa-tag me-1"></i>Categoría: Ejecución</span>
                            <span><i class="fas fa-repeat me-1"></i>Frecuencia: Mensual (tracking)</span>
                            <span><i class="fas fa-weight-hanging me-1"></i>Peso: 20%</span>
                            <span><i class="fas fa-bullseye me-1"></i>Objetivo: 60%</span>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button class="btn btn-sm btn-outline-light flex-fill btn-ver-proyectos" data-tipo="completados">
                                <i class="fas fa-list me-1"></i>Ver proyectos
                            </button>
                            <button class="btn btn-sm btn-outline-info flex-fill btn-ver-instructivo" data-kpi="completados">
                                <i class="fas fa-book me-1"></i>Instructivo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI 2: % Cumplimiento SLA -->
            <div class="col-12 col-md-6 col-xl-3 mb-3">
                <div class="card kpi-card h-100 position-relative">
                    <div class="card-body p-3">
                        <button class="kpi-info-btn"
                            data-bs-toggle="popover"
                            data-bs-placement="top"
                            data-bs-trigger="hover focus"
                            title="¿Cómo se calcula?"
                            data-bs-content="Sobre los proyectos completados del rango de fechas seleccionado: porcentaje cuya Finalización Real fue igual o anterior a la Fecha Límite. Objetivo: 85%.">
                            <i class="fas fa-circle-info fa-lg"></i>
                        </button>
                        <p class="kpi-label">% Cumplimiento SLA</p>
                        <div class="d-flex align-items-baseline gap-1 mb-2">
                            <span class="kpi-value" id="kpiPctSLA">—</span>
                            <span class="fs-5 opacity-50">%</span>
                        </div>
                        <div class="progress mb-1" style="height:5px;background:rgba(255,255,255,0.15)">
                            <div class="progress-bar" id="kpiBarSLA" role="progressbar" style="width:0%;transition:width .8s ease"></div>
                        </div>
                        <p class="kpi-sub" id="kpiTextSLA">&nbsp;</p>
                        <div class="kpi-meta">
                            <span><i class="fas fa-tag me-1"></i>Categoría: Ejecución</span>
                            <span><i class="fas fa-repeat me-1"></i>Frecuencia: Mensual</span>
                            <span><i class="fas fa-weight-hanging me-1"></i>Peso: 20%</span>
                            <span><i class="fas fa-bullseye me-1"></i>Objetivo: 85%</span>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button class="btn btn-sm btn-outline-light flex-fill btn-ver-proyectos" data-tipo="sla">
                                <i class="fas fa-list me-1"></i>Ver proyectos
                            </button>
                            <button class="btn btn-sm btn-outline-info flex-fill btn-ver-instructivo" data-kpi="sla">
                                <i class="fas fa-book me-1"></i>Instructivo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI 3: % Tickets dentro de SLA -->
            <div class="col-12 col-md-6 col-xl-3 mb-3">
                <div class="card kpi-card h-100 position-relative">
                    <div class="card-body p-3">
                        <button class="kpi-info-btn"
                            data-bs-toggle="popover"
                            data-bs-placement="top"
                            data-bs-trigger="hover focus"
                            title="¿Cómo se calcula?"
                            data-bs-content="Tickets con CERRADO=1 cuya Fecha Tarea cae en el rango seleccionado. Cumple SLA si las horas hábiles entre Fecha Tarea y Fecha Cierre (excluyendo fines de semana y feriados) son 48 o menos. Objetivo: 90%.">
                            <i class="fas fa-circle-info fa-lg"></i>
                        </button>
                        <p class="kpi-label">% Tickets dentro de SLA</p>
                        <div class="d-flex align-items-baseline gap-1 mb-2">
                            <span class="kpi-value" id="kpiPctTicketsSLA">—</span>
                            <span class="fs-5 opacity-50">%</span>
                        </div>
                        <div class="progress mb-1" style="height:5px;background:rgba(255,255,255,0.15)">
                            <div class="progress-bar" id="kpiBarTicketsSLA" role="progressbar" style="width:0%;transition:width .8s ease"></div>
                        </div>
                        <p class="kpi-sub" id="kpiTextTicketsSLA">&nbsp;</p>
                        <div class="kpi-meta">
                            <span><i class="fas fa-tag me-1"></i>Categoría: Soporte</span>
                            <span><i class="fas fa-repeat me-1"></i>Frecuencia: Mensual</span>
                            <span><i class="fas fa-weight-hanging me-1"></i>Peso: 15%</span>
                            <span><i class="fas fa-bullseye me-1"></i>Objetivo: 90%</span>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button class="btn btn-sm btn-outline-light flex-fill btn-ver-tickets">
                                <i class="fas fa-list me-1"></i>Ver tickets
                            </button>
                            <button class="btn btn-sm btn-outline-info flex-fill btn-ver-instructivo" data-kpi="tickets-sla">
                                <i class="fas fa-book me-1"></i>Instructivo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KPI 4: % Procesos Relevados vs Plan -->
            <div class="col-12 col-md-6 col-xl-3 mb-3">
                <div class="card kpi-card h-100 position-relative">
                    <div class="card-body p-3">
                        <button class="kpi-info-btn"
                            data-bs-toggle="popover"
                            data-bs-placement="top"
                            data-bs-trigger="hover focus"
                            title="¿Cómo se calcula?"
                            data-bs-content="Procesos con score_actual >= 2.0 (ya 'Gestionado'/documentado) cuya Fecha de Relevado (created_at) cae en el rango seleccionado, sobre un plan fijo de 2 procesos/mes.">
                            <i class="fas fa-circle-info fa-lg"></i>
                        </button>
                        <p class="kpi-label">% Procesos Relevados vs Plan</p>
                        <div class="d-flex align-items-baseline gap-1 mb-2">
                            <span class="kpi-value" id="kpiPctProcesos">—</span>
                            <span class="fs-5 opacity-50">%</span>
                        </div>
                        <div class="progress mb-1" style="height:5px;background:rgba(255,255,255,0.15)">
                            <div class="progress-bar" id="kpiBarProcesos" role="progressbar" style="width:0%;transition:width .8s ease"></div>
                        </div>
                        <p class="kpi-sub" id="kpiTextProcesos">&nbsp;</p>
                        <div class="kpi-meta">
                            <span><i class="fas fa-tag me-1"></i>Categoría: Mejora Continua</span>
                            <span><i class="fas fa-repeat me-1"></i>Frecuencia: Mensual</span>
                            <span><i class="fas fa-weight-hanging me-1"></i>Peso: 5%</span>
                            <span><i class="fas fa-bullseye me-1"></i>Objetivo: 2 procesos/mes</span>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <button class="btn btn-sm btn-outline-light flex-fill btn-ver-procesos">
                                <i class="fas fa-list me-1"></i>Ver procesos
                            </button>
                            <button class="btn btn-sm btn-outline-info flex-fill btn-ver-instructivo" data-kpi="procesos">
                                <i class="fas fa-book me-1"></i>Instructivo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Tarjetas KPI en desarrollo (sin datos aún) -->
        <div class="row mb-4">
            <div class="col-12 mb-1"><h6 class="text-muted"><i class="fas fa-flask me-2"></i>En desarrollo — sin datos aún</h6></div>

            <div class="col-12 col-md-6 col-xl-6 mb-3">
                <div class="card kpi-card kpi-card-placeholder h-100 position-relative">
                    <div class="card-body p-3">
                        <span class="badge bg-secondary kpi-badge-desarrollo">En desarrollo</span>
                        <p class="kpi-label">Ahorro / Beneficio Generado</p>
                        <div class="kpi-value-placeholder mb-2">—</div>
                        <p class="kpi-sub mb-2">Impacto · Suma de $ ahorrados o generados por proyectos cerrados en el período (hs liberadas × costo hora, costos evitados, ingresos habilitados).</p>
                        <div class="kpi-placeholder-meta">
                            <span><i class="fas fa-tag me-1"></i>Categoría: Impacto</span>
                            <span><i class="fas fa-repeat me-1"></i>Frecuencia: Trimestral</span>
                            <span><i class="fas fa-weight-hanging me-1"></i>Peso: 15%</span>
                            <span><i class="fas fa-bullseye me-1"></i>Objetivo: $ a definir (piso anual / 4)</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-6 mb-3">
                <div class="card kpi-card kpi-card-placeholder h-100 position-relative">
                    <div class="card-body p-3">
                        <span class="badge bg-secondary kpi-badge-desarrollo">En desarrollo</span>
                        <p class="kpi-label">% Costo del Área</p>
                        <div class="kpi-value-placeholder mb-2">—</div>
                        <p class="kpi-sub mb-2">Impacto · (Ventas – Costo del equipo) / Costo del equipo.</p>
                        <div class="kpi-placeholder-meta">
                            <span><i class="fas fa-tag me-1"></i>Categoría: Impacto</span>
                            <span><i class="fas fa-repeat me-1"></i>Frecuencia: Trimestral</span>
                            <span><i class="fas fa-weight-hanging me-1"></i>Peso: 25%</span>
                            <span><i class="fas fa-bullseye me-1"></i>Objetivo: &gt; 100%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notas aclaratorias -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-dark text-white py-2">
                        <i class="fas fa-star me-2"></i>Notas Aclaratorias
                    </div>
                    <div class="card-body p-0">
                        <p class="text-muted small px-3 pt-3 mb-2">
                            <i class="fas fa-compass me-1"></i>Lectura ejecutiva del modelo
                        </p>
                        <table class="table table-sm align-middle mb-0 kpi-notas-table">
                            <tbody>
                                <tr class="kpi-notas-ejecucion">
                                    <td class="kpi-notas-categoria"><i class="fas fa-gear me-1"></i>Ejecución (40%)</td>
                                    <td>Capacidad de entregar proyectos en tiempo y forma, según plan comprometido.</td>
                                </tr>
                                <tr class="kpi-notas-soporte">
                                    <td class="kpi-notas-categoria"><i class="fas fa-screwdriver-wrench me-1"></i>Soporte (15%)</td>
                                    <td>Calidad operativa del área en la gestión de incidencias y tickets de soporte.</td>
                                </tr>
                                <tr class="kpi-notas-impacto">
                                    <td class="kpi-notas-categoria"><i class="fas fa-chart-column me-1"></i>Impacto (40%)</td>
                                    <td>Valor económico generado por el área, medido en $ acumulados y retorno sobre el costo del equipo (ROI).</td>
                                </tr>
                                <tr class="kpi-notas-mejora">
                                    <td class="kpi-notas-categoria"><i class="fas fa-magnifying-glass me-1"></i>Mejora Continua (5%)</td>
                                    <td>Pipeline futuro: procesos relevados y documentados como base para mejoras siguientes.</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="kpi-notas-footer px-3 py-2">
                            <i class="fas fa-scale-balanced me-1"></i><strong>Criterio de ponderación:</strong>
                            <span class="fst-italic">Los KPIs están agrupados por tipo de gestión y ponderados según el valor que aportan al área, priorizando ejecución sin perder foco en impacto.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /container-fluid -->

    <!-- ═══ Modal: KPI Detalle ═══════════════════════════════════════════════ -->
    <div class="modal fade" id="modalKpiDetalle" tabindex="-1" aria-labelledby="kpiDetalleTitulo" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title" id="kpiDetalleTitulo">Detalle KPI</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div id="kpiDetalleHeader" class="kpi-detalle-header">
                    <p id="kpiDetalleNota" class="text-muted small mb-2"></p>
                    <div id="kpiDetalleResumen"></div>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-hover align-middle mb-0 kpi-detalle-table">
                        <thead class="table-dark kpi-thead-sticky">
                            <tr>
                                <th class="ps-3">ID</th>
                                <th>Desarrollo</th>
                                <th>Sector</th>
                                <th>Desarrollador</th>
                                <th>Fecha Límite</th>
                                <th>Finalización Real</th>
                                <th>Estado</th>
                                <th>Clasificación</th>
                            </tr>
                        </thead>
                        <tbody id="kpiDetalleTabla">
                            <tr><td colspan="8" class="text-center text-muted py-3">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Modal: Tickets Detalle (KPI 3) ══════════════════════════════════ -->
    <div class="modal fade" id="modalTicketsDetalle" tabindex="-1" aria-labelledby="ticketsDetalleTitulo" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title" id="ticketsDetalleTitulo">Tickets evaluados — SLA (48 hs hábiles)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="kpi-detalle-header">
                    <p id="ticketsDetalleNota" class="text-muted small mb-2"></p>
                    <div id="ticketsDetalleResumen"></div>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-hover align-middle mb-0 kpi-detalle-table">
                        <thead class="table-dark kpi-thead-sticky">
                            <tr>
                                <th class="ps-3">Ticket</th>
                                <th>Fecha Tarea</th>
                                <th>Fecha Cierre</th>
                                <th>Horas</th>
                                <th>Área</th>
                                <th>Usuario Asignado</th>
                                <th>Tipo</th>
                                <th>SLA</th>
                            </tr>
                        </thead>
                        <tbody id="ticketsDetalleTabla">
                            <tr><td colspan="8" class="text-center text-muted py-3">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Modal: Procesos Detalle (KPI 4) ══════════════════════════════════ -->
    <div class="modal fade" id="modalProcesosDetalle" tabindex="-1" aria-labelledby="procesosDetalleTitulo" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title" id="procesosDetalleTitulo">Procesos relevados</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="kpi-detalle-header">
                    <p id="procesosDetalleNota" class="text-muted small mb-2"></p>
                    <div id="procesosDetalleResumen"></div>
                </div>
                <div class="modal-body p-0">
                    <table class="table table-hover align-middle mb-0 kpi-detalle-table">
                        <thead class="table-dark kpi-thead-sticky">
                            <tr>
                                <th class="ps-3">Código</th>
                                <th>Nombre</th>
                                <th>Área</th>
                                <th>Categoría</th>
                                <th>Criticidad</th>
                                <th>Madurez</th>
                                <th>Fecha Relevado</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody id="procesosDetalleTabla">
                            <tr><td colspan="8" class="text-center text-muted py-3">Cargando...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Modal: Importar Tickets desde Excel ═════════════════════════════ -->
    <div class="modal fade" id="modalImportarTickets" tabindex="-1" aria-labelledby="importarTicketsTitulo" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title" id="importarTicketsTitulo"><i class="fas fa-file-excel me-2"></i>Importar tickets desde Excel</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div id="importPaso1">
                        <p class="text-muted small mb-2">
                            <i class="fas fa-circle-info me-1"></i>Columnas esperadas (el orden, mayúsculas y acentos no importan; columnas extra se ignoran):
                        </p>
                        <div class="kpi-import-columnas mb-3">
                            <span class="badge kpi-import-col-badge">FECHA_TAREA</span>
                            <span class="badge kpi-import-col-badge">TICKET</span>
                            <span class="badge kpi-import-col-badge">FECHA_CIERRE</span>
                            <span class="badge kpi-import-col-badge">CERRADO</span>
                            <span class="badge kpi-import-col-badge">AREA</span>
                            <span class="badge kpi-import-col-badge">USUARIO_ASIGNADO</span>
                            <span class="badge kpi-import-col-badge">CONTACTOS</span>
                            <span class="badge kpi-import-col-badge">TIPO</span>
                        </div>
                        <label for="inputArchivoTickets" class="kpi-import-dropzone">
                            <i class="fas fa-file-arrow-up fa-2x mb-2"></i>
                            <span class="fw-semibold">Hacé clic para elegir un archivo Excel</span>
                            <span class="small text-muted">.xlsx o .xls</span>
                        </label>
                        <input type="file" id="inputArchivoTickets" accept=".xlsx,.xls" class="d-none">
                    </div>
                    <div id="importPreview" class="d-none mt-3">
                        <div class="d-flex gap-2 mb-2">
                            <span class="badge bg-info-subtle text-info-emphasis kpi-import-badge" id="previewTotalFilas"></span>
                            <span class="badge bg-danger-subtle text-danger-emphasis kpi-import-badge d-none" id="previewTotalErrores"></span>
                        </div>
                        <div id="previewErroresLista" class="small text-danger mb-2" style="max-height:150px;overflow:auto;"></div>
                        <div class="table-responsive kpi-import-preview-table" style="max-height:240px;overflow:auto;">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-dark kpi-thead-sticky">
                                    <tr>
                                        <th>Ticket</th><th>F. Tarea</th><th>F. Cierre</th><th>Cerrado</th>
                                        <th>Área</th><th>Usuario</th><th>Tipo</th>
                                    </tr>
                                </thead>
                                <tbody id="previewTablaBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div id="importResultado" class="d-none mt-3 alert alert-success"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" id="btnConfirmarImportar" class="btn btn-success d-none">
                        <i class="fas fa-upload me-1"></i>Confirmar e importar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Modal: Instructivo de Medición por KPI ═══════════════════════════ -->
    <div class="modal fade" id="modalInstructivo" tabindex="-1" aria-labelledby="instructivoTitulo" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title" id="instructivoTitulo">Instructivo de Medición</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div id="instructivoMeta" class="d-flex flex-wrap align-items-center gap-2 mb-3"></div>
                    <div id="instructivoAviso" class="alert alert-warning small d-none">
                        <i class="fas fa-triangle-exclamation me-1"></i><span id="instructivoAvisoTexto"></span>
                    </div>
                    <div class="kpi-instructivo-section kpi-instructivo-formula">
                        <h6><i class="fas fa-square-root-variable me-2"></i>Fórmula</h6>
                        <p id="instructivoFormula" class="mb-0"></p>
                    </div>
                    <div class="kpi-instructivo-section">
                        <h6><i class="fas fa-database me-2"></i>Fuente de datos</h6>
                        <p id="instructivoFuente" class="mb-0"></p>
                    </div>
                    <div class="kpi-instructivo-section">
                        <h6><i class="fas fa-ban me-2"></i>Reglas de exclusión</h6>
                        <ul id="instructivoReglas" class="kpi-instructivo-reglas mb-0"></ul>
                    </div>
                    <div class="kpi-instructivo-section kpi-instructivo-section-ejemplo mb-0">
                        <h6><i class="fas fa-lightbulb me-2"></i>Ejemplo</h6>
                        <p id="instructivoEjemplo" class="mb-0"></p>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- SheetJS (lectura de Excel para importar tickets) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

    <!-- Custom JS -->
    <script src="js/kpis.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
            [...popoverTriggerList].map(el => new bootstrap.Popover(el));
        });
    </script>
</body>
</html>
