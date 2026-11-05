<?php
require_once __DIR__ . '/class/AuthKpis.php';

/* Sin usuario: aviso de iniciar sesión. Sin permiso de lectura: aviso de sin
   acceso. En los dos casos no se dibuja nada del tablero ni se pide ningún
   dato (la API, además, responde 401/403 por su cuenta). */
$kpisUsuario = AuthKpis::usuario();
$kpisBloqueo = null;
if ($kpisUsuario === null) {
    $kpisBloqueo = 'login';
    http_response_code(401);
} elseif (!AuthKpis::puede()) {
    $kpisBloqueo = 'sin-acceso';
    http_response_code(403);
}
$kpisPuedeEditar = ($kpisBloqueo === null) && AuthKpis::puedeEditar();
$kpisNombreUsuario = $kpisUsuario ? (trim((string) $kpisUsuario['nombre']) ?: $kpisUsuario['username']) : '';

if ($kpisBloqueo !== null): ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tablero de KPIs — Innovación</title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="css/kpis.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">
                <i class="fas fa-gauge-high me-2"></i>
                Tablero de KPIs <span class="text-white-50">· Innovación</span>
            </span>
        </div>
    </nav>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-8 col-lg-6">
                <div class="card kpi-acceso-card text-center">
                    <div class="card-body p-4">
                        <?php if ($kpisBloqueo === 'login'): ?>
                            <i class="fas fa-right-to-bracket fa-2x text-primary mb-3"></i>
                            <h5 class="mb-2">Tenés que iniciar sesión</h5>
                            <p class="text-muted mb-4">Para ver el tablero de KPIs de Innovación ingresá con tu usuario.</p>
                            <a href="/bi/sistemas/login.php" class="btn btn-primary">
                                <i class="fas fa-right-to-bracket me-1"></i>Ir al login
                            </a>
                        <?php else: ?>
                            <i class="fas fa-lock fa-2x text-danger mb-3"></i>
                            <h5 class="mb-2">No tenés acceso a este tablero</h5>
                            <p class="text-muted mb-0">
                                El usuario <strong><?= htmlspecialchars($kpisUsuario['username']) ?></strong>
                                (rol: <?= htmlspecialchars($kpisUsuario['rol_nombre']) ?>) no tiene el permiso
                                para ver el tablero de KPIs de Innovación. Pedilo en Gestión de Usuarios.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit;
endif;
?>
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
<body<?= AuthKpis::atributoEdicion() ?>>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-gauge-high me-2"></i>
                Tablero de KPIs <span class="text-white-50">· Innovación</span>
            </a>
            <span class="navbar-text text-white-50 small ms-auto kpi-navbar-usuario" title="<?= htmlspecialchars($kpisUsuario['username']) ?>">
                <i class="fas fa-user-circle me-1"></i><?= htmlspecialchars($kpisNombreUsuario) ?>
                <?php if ($kpisPuedeEditar): ?>
                    <span class="badge bg-success-subtle text-success-emphasis ms-1">Edición</span>
                <?php endif; ?>
            </span>
        </div>
    </nav>

    <div class="container-fluid mt-4">

        <div id="kpiAvisos"></div>

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
                            <label for="filtroMesDesde" class="form-label kpi-filtro-label mb-1">
                                <i class="fas fa-calendar-day me-1"></i>Desde
                            </label>
                            <div class="d-flex gap-2">
                                <select id="filtroMesDesde" class="form-select form-select-sm kpi-filtro-mes" aria-label="Mes desde"></select>
                                <select id="filtroAnoDesde" class="form-select form-select-sm kpi-filtro-ano" aria-label="Año desde"></select>
                            </div>
                        </div>
                        <div>
                            <label for="filtroMesHasta" class="form-label kpi-filtro-label mb-1">
                                <i class="fas fa-calendar-check me-1"></i>Hasta
                            </label>
                            <div class="d-flex gap-2">
                                <select id="filtroMesHasta" class="form-select form-select-sm kpi-filtro-mes" aria-label="Mes hasta"></select>
                                <select id="filtroAnoHasta" class="form-select form-select-sm kpi-filtro-ano" aria-label="Año hasta"></select>
                            </div>
                        </div>
                        <button id="btnAplicarPeriodo" class="btn btn-primary btn-sm">
                            <i class="fas fa-filter me-1"></i>Aplicar
                        </button>
                        <button id="btnRecargar" class="btn btn-outline-secondary btn-sm" type="button" title="Recargar la página manteniendo el período seleccionado">
                            <i class="fas fa-rotate-right me-1"></i>Recargar
                        </button>
                        <div id="periodoAviso" class="text-danger small d-none">
                            <i class="fas fa-triangle-exclamation me-1"></i>El período "desde" debe ser anterior o igual a "hasta".
                        </div>
                        <div class="ms-md-auto kpi-solo-edicion">
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
            <div class="col-12 col-md-6 col-xl-4 col-xxl mb-3">
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
            <div class="col-12 col-md-6 col-xl-4 col-xxl mb-3">
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
            <div class="col-12 col-md-6 col-xl-4 col-xxl mb-3">
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
            <div class="col-12 col-md-6 col-xl-4 col-xxl mb-3">
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

            <!-- KPI 5: Ahorro / Beneficio Generado -->
            <div class="col-12 col-md-6 col-xl-4 col-xxl mb-3">
                <div class="card kpi-card h-100 position-relative">
                    <div class="card-body p-3">
                        <button class="kpi-info-btn"
                            data-bs-toggle="popover"
                            data-bs-placement="top"
                            data-bs-trigger="hover focus"
                            title="¿Cómo se calcula?"
                            data-bs-content="Suma la plata que el área ahorró o generó en los meses elegidos: horas liberadas (horas × costo hora), costos evitados, renegociaciones, ingresos habilitados y proveedores dados de baja (lo que se les pagaba en promedio por mes, desde el mes siguiente a su última factura). Se compara contra el objetivo anual repartido en partes iguales por mes.">
                            <i class="fas fa-circle-info fa-lg"></i>
                        </button>
                        <p class="kpi-label">Ahorro / Beneficio Generado</p>
                        <div class="d-flex align-items-baseline gap-1 mb-2">
                            <span class="kpi-value kpi-value-monto" id="kpiAhorroValor">—</span>
                        </div>
                        <div class="progress mb-1" style="height:5px;background:rgba(255,255,255,0.15)">
                            <div class="progress-bar" id="kpiBarAhorro" role="progressbar" style="width:0%;transition:width .8s ease"></div>
                        </div>
                        <p class="kpi-sub mb-0" id="kpiTextAhorro">&nbsp;</p>
                        <p class="kpi-sub" id="kpiTextAhorroLogro">&nbsp;</p>
                        <div class="kpi-meta">
                            <span><i class="fas fa-tag me-1"></i>Categoría: Impacto</span>
                            <span><i class="fas fa-repeat me-1"></i>Frecuencia: Trimestral</span>
                            <span><i class="fas fa-weight-hanging me-1"></i>Peso: 15%</span>
                            <span><i class="fas fa-bullseye me-1"></i>Objetivo: <span id="kpiAhorroObjetivo">—</span></span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <button class="btn btn-sm btn-outline-light flex-fill btn-ver-ahorros">
                                <i class="fas fa-list me-1"></i>Ver ahorros
                            </button>
                            <button class="btn btn-sm btn-outline-info flex-fill btn-ver-instructivo" data-kpi="ahorro">
                                <i class="fas fa-book me-1"></i>Instructivo
                            </button>
                            <button class="btn btn-sm btn-outline-warning flex-fill btn-parametros kpi-solo-edicion">
                                <i class="fas fa-sliders me-1"></i>Parámetros
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
                    <ul class="nav nav-tabs kpi-tabs mt-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tabTicketsDetalle" data-bs-toggle="tab" data-bs-target="#paneTicketsDetalle" type="button" role="tab" aria-controls="paneTicketsDetalle" aria-selected="true">
                                <i class="fas fa-list me-1"></i>Detalle
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tabTicketsUsuarios" data-bs-toggle="tab" data-bs-target="#paneTicketsUsuarios" type="button" role="tab" aria-controls="paneTicketsUsuarios" aria-selected="false">
                                <i class="fas fa-users me-1"></i>Por usuario
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="modal-body p-0">
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="paneTicketsDetalle" role="tabpanel" aria-labelledby="tabTicketsDetalle">
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
                        <div class="tab-pane fade p-3" id="paneTicketsUsuarios" role="tabpanel" aria-labelledby="tabTicketsUsuarios">
                            <h6 class="kpi-seccion-titulo"><i class="fas fa-user-check me-2"></i>Resueltos y fuera de SLA por usuario <span class="text-muted fw-normal" id="ticketsUsuariosPeriodo"></span></h6>
                            <div class="row g-3 mb-4" id="ticketsUsuariosIndicadores">
                                <div class="col-12 text-center text-muted py-3">Cargando...</div>
                            </div>

                            <h6 class="kpi-seccion-titulo"><i class="fas fa-calendar-days me-2"></i>Resueltos en los últimos 3 meses <span class="text-muted fw-normal" id="tickets3mPeriodo"></span></h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0 kpi-detalle-table">
                                    <thead class="table-dark">
                                        <tr id="tickets3mHead">
                                            <th class="ps-3">Usuario</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tickets3mTabla">
                                        <tr><td class="text-center text-muted py-3">Cargando...</td></tr>
                                    </tbody>
                                    <tfoot id="tickets3mPie"></tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
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
                        <div id="previewPeriodos" class="alert alert-warning small py-2 mb-2 d-none"></div>
                        <div id="previewDuplicados" class="alert alert-info small py-2 mb-2 d-none"></div>
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

    <!-- ═══ Modal: Ver ahorros (KPI 5) ═══════════════════════════════════════ -->
    <div class="modal fade" id="modalAhorros" tabindex="-1" aria-labelledby="ahorrosTitulo" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen-xl-down modal-xl kpi-modal-ahorros modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title" id="ahorrosTitulo"><i class="fas fa-piggy-bank me-2"></i>Ahorro / Beneficio Generado</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="kpi-detalle-header">
                    <p id="ahorrosNota" class="text-muted small mb-2"></p>
                    <div id="ahorrosResumen" class="row g-2 mb-2"></div>
                    <div id="ahorrosPorTipo" class="small mb-2"></div>
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="switchAhorrosBajas">
                            <label class="form-check-label small" for="switchAhorrosBajas">Ver dados de baja</label>
                        </div>
                        <button type="button" class="btn btn-success btn-sm ms-auto kpi-solo-edicion" id="btnNuevoAhorro">
                            <i class="fas fa-plus me-1"></i>Nuevo ahorro
                        </button>
                    </div>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 kpi-detalle-table kpi-ahorros-table">
                            <thead class="table-dark kpi-thead-sticky">
                                <tr>
                                    <th class="ps-3"></th>
                                    <th>Descripción</th>
                                    <th>Tipo</th>
                                    <th>Sector</th>
                                    <th>Proyecto</th>
                                    <th>Periodicidad</th>
                                    <th>Desde</th>
                                    <th>Hasta</th>
                                    <th class="text-end">Monto mensual / único</th>
                                    <th class="text-end">Ahorro en el rango</th>
                                    <th class="text-end">Proyección del año</th>
                                    <th>Avisos</th>
                                    <th>Última modificación</th>
                                    <th class="pe-3 kpi-solo-edicion">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="ahorrosTabla">
                                <tr><td colspan="14" class="text-center text-muted py-3">Cargando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Modal: Alta / edición de ahorro (solo con kpis.editar) ═══════════ -->
    <div class="modal fade" id="modalAhorroForm" tabindex="-1" aria-labelledby="ahorroFormTitulo" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title" id="ahorroFormTitulo">Nuevo ahorro</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <form id="formAhorro" novalidate autocomplete="off">
                        <input type="hidden" id="ahorroId">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label for="ahorroTipo" class="form-label kpi-form-label">Tipo <span class="text-danger">*</span></label>
                                <select id="ahorroTipo" class="form-select form-select-sm"></select>
                            </div>
                            <div class="col-12 col-md-8">
                                <label for="ahorroDescripcion" class="form-label kpi-form-label">Descripción <span class="text-danger">*</span></label>
                                <input type="text" id="ahorroDescripcion" class="form-control form-control-sm" maxlength="300">
                            </div>
                        </div>

                        <div id="ahorroCamposComunes" class="d-none">
                            <div class="row g-3 mt-0">
                                <div class="col-12 col-md-6 position-relative">
                                    <label for="ahorroProyectoBuscar" class="form-label kpi-form-label">Proyecto asociado <span class="text-muted fw-normal">(opcional)</span></label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="ahorroProyectoBuscar" class="form-control" placeholder="Buscá por ID o nombre">
                                        <button class="btn btn-outline-secondary" type="button" id="btnQuitarProyecto" title="Quitar proyecto"><i class="fas fa-xmark"></i></button>
                                    </div>
                                    <input type="hidden" id="ahorroProyectoId">
                                    <div id="ahorroProyectoResultados" class="list-group kpi-buscador-resultados d-none"></div>
                                    <div id="ahorroProyectoElegido" class="small text-muted mt-1"></div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="ahorroSector" class="form-label kpi-form-label">Sector <span class="text-danger">*</span></label>
                                    <select id="ahorroSector" class="form-select form-select-sm"></select>
                                    <div id="ahorroSectorAviso" class="small text-muted mt-1"></div>
                                </div>
                                <div class="col-6 col-md-4">
                                    <label for="ahorroPeriodicidad" class="form-label kpi-form-label">Periodicidad <span class="text-danger">*</span></label>
                                    <select id="ahorroPeriodicidad" class="form-select form-select-sm">
                                        <option value="">Elegí…</option>
                                        <option value="MENSUAL">Mensual (recurrente)</option>
                                        <option value="UNICO">Único</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-4">
                                    <label for="ahorroDesde" class="form-label kpi-form-label">Fecha desde <span class="text-danger">*</span></label>
                                    <input type="date" id="ahorroDesde" class="form-control form-control-sm">
                                </div>
                                <div class="col-6 col-md-4">
                                    <label for="ahorroHasta" class="form-label kpi-form-label">Fecha hasta <span class="text-danger">*</span></label>
                                    <input type="date" id="ahorroHasta" class="form-control form-control-sm">
                                </div>
                            </div>

                            <!-- Modo MONTO -->
                            <div id="ahorroModoMonto" class="kpi-modo-bloque d-none">
                                <div class="row g-3">
                                    <div class="col-12 col-md-4">
                                        <label for="ahorroMonto" class="form-label kpi-form-label"><span id="ahorroMontoLabel">Monto</span> ($) <span class="text-danger">*</span></label>
                                        <input type="number" id="ahorroMonto" class="form-control form-control-sm" min="0" step="0.01">
                                    </div>
                                </div>
                            </div>

                            <!-- Modo HORAS -->
                            <div id="ahorroModoHoras" class="kpi-modo-bloque d-none">
                                <div class="row g-3 align-items-end">
                                    <div class="col-6 col-md-3">
                                        <label for="ahorroHoras" class="form-label kpi-form-label">Horas por mes <span class="text-danger">*</span></label>
                                        <input type="number" id="ahorroHoras" class="form-control form-control-sm" min="0" step="0.5">
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label for="ahorroCostoHora" class="form-label kpi-form-label">Costo hora ($) <span class="text-danger">*</span></label>
                                        <input type="number" id="ahorroCostoHora" class="form-control form-control-sm" min="0" step="0.01">
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="kpi-monto-vivo">Monto <span id="ahorroHorasPeriodo">mensual</span>: <strong id="ahorroHorasMonto">$ 0</strong></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Modo PROVEEDOR -->
                            <div id="ahorroModoProveedor" class="kpi-modo-bloque d-none">
                                <p class="small text-muted mb-2">
                                    <i class="fas fa-circle-info me-1"></i>Elegí los proveedores dados de baja y cargá la fecha de baja de cada uno.
                                    El monto mensual se calcula con lo facturado en Tango; la periodicidad queda fija en mensual.
                                </p>
                                <div class="position-relative mb-2" style="max-width:480px">
                                    <input type="text" id="ahorroProveedorBuscar" class="form-control form-control-sm" placeholder="Agregar proveedor: buscá por código o nombre">
                                    <div id="ahorroProveedorResultados" class="list-group kpi-buscador-resultados d-none"></div>
                                </div>
                                <table class="table table-sm align-middle mb-2">
                                    <thead class="table-light">
                                        <tr><th>Proveedor</th><th style="width:180px">Fecha de baja</th><th style="width:40px"></th></tr>
                                    </thead>
                                    <tbody id="ahorroProveedoresTabla">
                                        <tr class="kpi-sin-proveedores"><td colspan="3" class="text-muted small">Todavía no agregaste proveedores.</td></tr>
                                    </tbody>
                                </table>
                                <h6 class="kpi-seccion-titulo mt-3"><i class="fas fa-calculator me-2"></i>Cálculo con lo facturado en Tango</h6>
                                <div id="ahorroProveedoresCalculo" class="small text-muted">Agregá un proveedor con su fecha de baja para ver el cálculo.</div>
                            </div>

                            <div class="row g-3 mt-0">
                                <div class="col-12">
                                    <label for="ahorroObservaciones" class="form-label kpi-form-label">Observaciones <span class="text-muted fw-normal">(opcional)</span></label>
                                    <textarea id="ahorroObservaciones" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
                                </div>
                                <div class="col-12 d-none" id="ahorroMotivoGrupo">
                                    <label for="ahorroMotivo" class="form-label kpi-form-label">Motivo del cambio <span class="text-danger">*</span></label>
                                    <input type="text" id="ahorroMotivo" class="form-control form-control-sm" maxlength="500">
                                </div>
                            </div>
                        </div>
                    </form>
                    <div id="ahorroFormErrores" class="alert alert-danger small mt-3 mb-0 d-none"></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnGuardarAhorro">
                        <i class="fas fa-floppy-disk me-1"></i>Guardar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Modal: Dar de baja un ahorro ═════════════════════════════════════ -->
    <div class="modal fade" id="modalAhorroBaja" tabindex="-1" aria-labelledby="ahorroBajaTitulo" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white py-2">
                    <h5 class="modal-title" id="ahorroBajaTitulo"><i class="fas fa-ban me-2"></i>Dar de baja el ahorro</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Vas a dar de baja <strong id="ahorroBajaDescripcion"></strong>. Deja de sumar en la tarjeta y solo se ve con "Ver dados de baja".</p>
                    <label for="ahorroBajaMotivo" class="form-label kpi-form-label">Motivo <span class="text-danger">*</span></label>
                    <input type="text" id="ahorroBajaMotivo" class="form-control form-control-sm" maxlength="500">
                    <div id="ahorroBajaErrores" class="alert alert-danger small mt-3 mb-0 d-none"></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btnConfirmarBajaAhorro"><i class="fas fa-ban me-1"></i>Dar de baja</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Modal: Historial (ahorro o parámetros) ═══════════════════════════ -->
    <div class="modal fade" id="modalHistorial" tabindex="-1" aria-labelledby="historialTitulo" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title" id="historialTitulo"><i class="fas fa-clock-rotate-left me-2"></i>Historial</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body" id="historialCuerpo"></div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ Modal: Parámetros del tablero (solo con kpis.editar) ═════════════ -->
    <div class="modal fade" id="modalParametros" tabindex="-1" aria-labelledby="parametrosTitulo" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title" id="parametrosTitulo"><i class="fas fa-sliders me-2"></i>Parámetros del KPI de Ahorro</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label for="paramObjetivo" class="form-label kpi-form-label">Objetivo anual de ahorro ($)</label>
                            <input type="number" id="paramObjetivo" class="form-control form-control-sm" min="0" step="0.01">
                            <div class="form-text">0 = sin definir: el KPI no entra en el Cumplimiento General.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="paramCostoHora" class="form-label kpi-form-label">Costo hora por defecto ($)</label>
                            <input type="number" id="paramCostoHora" class="form-control form-control-sm" min="0" step="0.01">
                            <div class="form-text">Se precarga en los ahorros de "Horas liberadas"; se puede cambiar en cada uno.</div>
                        </div>
                        <div class="col-12">
                            <label for="paramMotivo" class="form-label kpi-form-label">Motivo del cambio <span class="text-danger">*</span></label>
                            <input type="text" id="paramMotivo" class="form-control form-control-sm" maxlength="500">
                        </div>
                    </div>
                    <div id="parametrosErrores" class="alert alert-danger small mt-3 mb-0 d-none"></div>
                    <h6 class="kpi-seccion-titulo mt-4"><i class="fas fa-clock-rotate-left me-2"></i>Historial de cambios</h6>
                    <div id="parametrosHistorial" class="small"></div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-success" id="btnGuardarParametros"><i class="fas fa-floppy-disk me-1"></i>Guardar</button>
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
    <script src="js/ahorros.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
            [...popoverTriggerList].map(el => new bootstrap.Popover(el));
        });
    </script>
</body>
</html>
