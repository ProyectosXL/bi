<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tablero de KPIs — Innovación</title>

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

        <!-- Tarjetas KPI -->
        <div class="row mb-4">

            <!-- KPI 1: Completados vs Plan % -->
            <div class="col-12 col-md-6 col-xl-4 mb-3">
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
                            <div class="progress-bar bg-info" id="kpiBarCompletados" role="progressbar" style="width:0%;transition:width .8s ease"></div>
                        </div>
                        <p class="kpi-sub" id="kpiTextCompletados">&nbsp;</p>
                        <button class="btn btn-sm btn-outline-light mt-2 w-100 btn-ver-proyectos" data-tipo="completados">
                            <i class="fas fa-list me-1"></i>Ver proyectos
                        </button>
                    </div>
                </div>
            </div>

            <!-- KPI 2: % Cumplimiento SLA -->
            <div class="col-12 col-md-6 col-xl-4 mb-3">
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
                        <button class="btn btn-sm btn-outline-light mt-2 w-100 btn-ver-proyectos" data-tipo="sla">
                            <i class="fas fa-list me-1"></i>Ver proyectos
                        </button>
                    </div>
                </div>
            </div>

            <!-- KPI 3: % Tickets dentro de SLA -->
            <div class="col-12 col-md-6 col-xl-4 mb-3">
                <div class="card kpi-card h-100 position-relative">
                    <div class="card-body p-3">
                        <button class="kpi-info-btn"
                            data-bs-toggle="popover"
                            data-bs-placement="top"
                            data-bs-trigger="hover focus"
                            title="¿Cómo se calcula?"
                            data-bs-content="Tickets con CERRADO=1 cuya Fecha Tarea cae en el rango seleccionado. Cumple SLA si la diferencia entre Fecha Cierre y Fecha Tarea es de 48 horas o menos. Objetivo: 90%.">
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
                        <button class="btn btn-sm btn-outline-light mt-2 w-100 btn-ver-tickets">
                            <i class="fas fa-list me-1"></i>Ver tickets
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- Tarjetas KPI en desarrollo (sin datos aún) -->
        <div class="row mb-4">
            <div class="col-12 mb-1"><h6 class="text-muted"><i class="fas fa-flask me-2"></i>En desarrollo — sin datos aún</h6></div>

            <div class="col-12 col-md-6 col-xl-4 mb-3">
                <div class="card kpi-card kpi-card-placeholder h-100 position-relative">
                    <div class="card-body p-3">
                        <span class="badge bg-secondary kpi-badge-desarrollo">En desarrollo</span>
                        <p class="kpi-label">Ahorro / Beneficio Generado</p>
                        <div class="kpi-value-placeholder mb-2">—</div>
                        <p class="kpi-sub mb-2">Impacto · Suma de $ ahorrados o generados por proyectos cerrados en el período (hs liberadas × costo hora, costos evitados, ingresos habilitados).</p>
                        <div class="kpi-placeholder-meta">
                            <span><i class="fas fa-repeat me-1"></i>Frecuencia: Trimestral</span>
                            <span><i class="fas fa-weight-hanging me-1"></i>Peso: 15%</span>
                            <span><i class="fas fa-bullseye me-1"></i>Objetivo: $ a definir (piso anual / 4)</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-4 mb-3">
                <div class="card kpi-card kpi-card-placeholder h-100 position-relative">
                    <div class="card-body p-3">
                        <span class="badge bg-secondary kpi-badge-desarrollo">En desarrollo</span>
                        <p class="kpi-label">% Procesos Relevados vs Plan</p>
                        <div class="kpi-value-placeholder mb-2">—</div>
                        <p class="kpi-sub mb-2">Mejora Continua · Cantidad de procesos relevados/documentados (ej. sesiones de 3-4 hs con usuarios).</p>
                        <div class="kpi-placeholder-meta">
                            <span><i class="fas fa-repeat me-1"></i>Frecuencia: Mensual</span>
                            <span><i class="fas fa-weight-hanging me-1"></i>Peso: 5%</span>
                            <span><i class="fas fa-bullseye me-1"></i>Objetivo: 2 procesos/mes</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-4 mb-3">
                <div class="card kpi-card kpi-card-placeholder h-100 position-relative">
                    <div class="card-body p-3">
                        <span class="badge bg-secondary kpi-badge-desarrollo">En desarrollo</span>
                        <p class="kpi-label">% Costo del Área</p>
                        <div class="kpi-value-placeholder mb-2">—</div>
                        <p class="kpi-sub mb-2">Impacto · (Ventas – Costo del equipo) / Costo del equipo.</p>
                        <div class="kpi-placeholder-meta">
                            <span><i class="fas fa-repeat me-1"></i>Frecuencia: Trimestral</span>
                            <span><i class="fas fa-weight-hanging me-1"></i>Peso: 25%</span>
                            <span><i class="fas fa-bullseye me-1"></i>Objetivo: &gt; 100%</span>
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
                    <h5 class="modal-title" id="ticketsDetalleTitulo">Tickets evaluados — SLA (48 hs)</h5>
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

    <!-- ═══ Modal: Importar Tickets desde Excel ═════════════════════════════ -->
    <div class="modal fade" id="modalImportarTickets" tabindex="-1" aria-labelledby="importarTicketsTitulo" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="importarTicketsTitulo">Importar tickets desde Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div id="importPaso1">
                        <p class="text-muted small">
                            Columnas esperadas: FECHA_TAREA, TICKET, FECHA_CIERRE, CERRADO, AREA, USUARIO_ASIGNADO, CONTACTOS, TIPO
                            (no importa el orden ni mayúsculas/acentos; columnas extra se ignoran).
                        </p>
                        <input type="file" id="inputArchivoTickets" accept=".xlsx,.xls" class="form-control form-control-sm">
                    </div>
                    <div id="importPreview" class="d-none mt-3">
                        <div class="d-flex gap-2 mb-2">
                            <span class="badge bg-info" id="previewTotalFilas"></span>
                            <span class="badge bg-danger d-none" id="previewTotalErrores"></span>
                        </div>
                        <div id="previewErroresLista" class="small text-danger mb-2" style="max-height:150px;overflow:auto;"></div>
                        <div class="table-responsive" style="max-height:240px;overflow:auto;">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
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
