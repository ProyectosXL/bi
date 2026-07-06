
<!DOCTYPE HTML>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes Power BI</title>
    <link rel="shortcut icon" href="../image/logo.jpg" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #64748b;
            --accent-color: #8b5cf6;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #06b6d4;
            --dark-color: #1e293b;
            --purple-color: #800080;
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #cbd5e1 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        .container {
            max-width: 1400px;
        }

        .power-bi-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .power-bi-logo {
            width: 130px;
            height: auto;
            margin-right: 15px;
        }

        .power-bi-header h1 {
            color: var(--dark-color);
            font-weight: 700;
            margin: 0;
        }

        /* Main Tabs Styling */
        .main-tabs-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .main-nav-pills .nav-link {
            background: transparent;
            color: #64748b;
            border: 2px solid rgba(100, 116, 139, 0.2);
            border-radius: 12px;
            padding: 12px 20px;
            margin-right: 8px;
            margin-bottom: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .main-nav-pills .nav-link:hover {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary-color);
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }

        .main-nav-pills .nav-link.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            height: 100%;
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .card-body {
            padding: 2rem;
            text-align: center;
            position: relative;
        }

        .card-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        .card-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: var(--dark-color);
        }

        .btn-card {
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            position: relative;
            overflow: hidden;
        }

        .btn-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-card:hover::before {
            left: 100%;
        }

        .btn-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .btn-primary { background: var(--primary-color); color: white; }
        .btn-success { background: var(--success-color); color: white; }
        .btn-info { background: var(--info-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }
        .btn-secondary { background: var(--secondary-color); color: white; }
        .btn-purple { background: var(--purple-color); color: white; }

        .text-primary { color: var(--primary-color) !important; }
        .text-success { color: var(--success-color) !important; }
        .text-info { color: var(--info-color) !important; }
        .text-warning { color: var(--warning-color) !important; }
        .text-danger { color: var(--danger-color) !important; }
        .text-secondary { color: var(--secondary-color) !important; }
        .text-purple { color: var(--purple-color) !important; }

        .section-title {
            color: var(--dark-color);
            font-weight: 600;
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            padding-left: 1rem;
            border-left: 4px solid var(--primary-color);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        /* Tab Content Animations */
        .tab-pane {
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .card-body {
                padding: 1.5rem;
            }
            
            .section-title {
                font-size: 1.3rem;
            }
            
            .main-nav-pills .nav-link {
                margin-bottom: 8px;
                margin-right: 4px;
                padding: 10px 16px;
                font-size: 0.9rem;
            }
            
            .power-bi-header {
                padding: 1.5rem;
            }
            
            .main-tabs-container {
                padding: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .power-bi-header h1 {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .main-nav-pills {
                flex-direction: column;
            }
            
            .main-nav-pills .nav-link {
                margin-right: 0;
                margin-bottom: 8px;
                width: 100%;
                text-align: center;
            }

            .power-bi-header {
                flex-direction: column;
                text-align: center;
            }

            .power-bi-logo {
                margin-right: 0;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <!-- Power BI Header -->
        <div class="power-bi-header d-flex align-items-center justify-content-center">
            <img src="../css/Power-BI-Logo.png" alt="Power BI Logo" class="power-bi-logo">
            <h1>REPORTES POWER BI</h1>
        </div>

        <!-- Main Tabs Container -->
        <div class="main-tabs-container">
            <!-- Navigation Pills -->
            <ul class="nav nav-pills main-nav-pills mb-4 justify-content-center" id="mainTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="ventas-tab" data-bs-toggle="pill" data-bs-target="#ventas" type="button" role="tab">
                        <i class="fas fa-chart-line me-2"></i>
                        Ventas
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="comercial-tab" data-bs-toggle="pill" data-bs-target="#comercial" type="button" role="tab">
                        <i class="fas fa-handshake me-2"></i>
                        Comercial
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="ecommerce-tab" data-bs-toggle="pill" data-bs-target="#ecommerce" type="button" role="tab">
                        <i class="fas fa-shopping-cart me-2"></i>
                        E-commerce
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="logistica-tab" data-bs-toggle="pill" data-bs-target="#logistica" type="button" role="tab">
                        <i class="fas fa-truck me-2"></i>
                        Logística
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="administracion-tab" data-bs-toggle="pill" data-bs-target="#administracion" type="button" role="tab">
                        <i class="fas fa-cogs me-2"></i>
                        Administración
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="mainTabsContent">
                <!-- Ventas Tab -->
                <div class="tab-pane fade show active" id="ventas" role="tabpanel">
    <section class="mb-5">
        <h2 class="section-title">
            <i class="fas fa-chart-line me-2"></i>
            Reportes de Ventas
        </h2>
        <div class="stats-grid">
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-chart-line card-icon text-primary"></i>
                    <h5 class="card-title">Sales Gerencia</h5>
                    <a href="tableros/salesGcia.html" class="btn btn-primary btn-card">Ver Reporte</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-store card-icon text-success"></i>
                    <h5 class="card-title">Sales Sucursales</h5>
                    <a href="tableros/salesSucursales.html" class="btn btn-success btn-card">Ver Reporte</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-handshake card-icon text-info"></i>
                    <h5 class="card-title">Sales Franquicias</h5>
                    <a href="tableros/salesFranquicias.html" class="btn btn-info btn-card">Ver Reporte</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-globe-americas card-icon text-danger"></i>
                    <h5 class="card-title">Sales Sucursales UY</h5>
                    <a href="tableros/salesSucursalesUy.html" class="btn btn-danger btn-card">Ver Reporte</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-bullseye card-icon text-purple"></i>
                    <h5 class="card-title">Objetivos</h5>
                    <a href="tableros/objetivosSucursales.html" class="btn btn-purple btn-card">Ver Reporte</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-truck card-icon text-warning"></i>
                    <h5 class="card-title">Ventas Mayoristas</h5>
                    <a href="tableros/ventaMayorista.html" class="btn btn-warning btn-card">Ver Reporte</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-tachometer-alt card-icon text-primary"></i>
                    <h5 class="card-title">Velocidad de Ventas</h5>
                    <a href="https://app.powerbi.com/view?r=eyJrIjoiMWJiNDk5ZDAtYzFkYy00OWRmLTk1OTYtMDIxNWU2MTllNmEzIiwidCI6IjQ0Y2E2MmNkLTY4MjItNDZkNC05NTUxLTEzNDQ5N2ZmM2VjMiIsImMiOjR9" class="btn btn-primary btn-card" target="_blank">Ver Reporte</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-map-marked-alt card-icon text-warning"></i>
                    <h5 class="card-title">Geodatos</h5>
                    <a href="tableros/geodatos.html" class="btn btn-warning btn-card">Ver Reporte</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-bullhorn card-icon text-success"></i>
                    <h5 class="card-title">Campañas De Ventas</h5>
                    <a href="tableros/campañasDeVentas.html" class="btn btn-success btn-card">Ver Reporte</a>
                </div>
            </div>
        </div>
    </section>
</div>
                <!-- Comercial Tab -->
                <div class="tab-pane fade" id="comercial" role="tabpanel">
                    <section class="mb-5">
                        <h2 class="section-title">
                            <i class="fas fa-handshake me-2"></i>
                            Reportes Comerciales
                        </h2>
                        <div class="stats-grid">
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-dolly card-icon text-danger"></i>
                                    <h5 class="card-title">Abastecimiento</h5>
                                    <a href="tableros/abastecimiento.html" class="btn btn-danger btn-card">Ver Reporte</a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-chart-bar card-icon text-primary"></i>
                                    <h5 class="card-title">KPIs Comerciales ARG</h5>
                                    <a href="tableros/kpisComercialesArg.html" class="btn btn-primary btn-card">Ver Reporte</a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-chart-area card-icon text-success"></i>
                                    <h5 class="card-title">KPIs Comerciales UY</h5>
                                    <a href="tableros/kpisComercialesUy.html" class="btn btn-success btn-card">Ver Reporte</a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-percent card-icon text-info"></i>
                                    <h5 class="card-title">Promociones Sucursales</h5>
                                    <a href="tableros/promociones.html" class="btn btn-info btn-card">Ver Reporte</a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-trophy card-icon text-secondary"></i>
                                    <h5 class="card-title">Premios Comercial</h5>
                                    <a href="premios/" class="btn btn-secondary btn-card">Ver Reporte</a>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- E-commerce Tab -->
                <div class="tab-pane fade" id="ecommerce" role="tabpanel">
                    <section class="mb-5">
                        <h2 class="section-title">
                            <i class="fas fa-shopping-cart me-2"></i>
                            E-commerce Analytics
                        </h2>
                        <div class="stats-grid">
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-shopping-cart card-icon text-primary"></i>
                                    <h5 class="card-title">Dashboard E-commerce</h5>
                                    <a href="tableros/dashEcommerce.html" class="btn btn-primary btn-card">Ver Reporte</a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-chart-pie card-icon text-success"></i>
                                    <h5 class="card-title">KPIs E-commerce</h5>
                                    <a href="tableros/dashKpiEcommerce.html" class="btn btn-success btn-card">Ver Reporte</a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-tags card-icon text-info"></i>
                                    <h5 class="card-title">Promociones E-commerce</h5>
                                    <a href="tableros/promocionesEcommerce.html" class="btn btn-info btn-card">Ver Reporte</a>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Logística Tab -->
                <div class="tab-pane fade" id="logistica" role="tabpanel">
                    <section class="mb-5">
                        <h2 class="section-title">
                            <i class="fas fa-truck me-2"></i>
                            Reportes de Logística
                        </h2>
                        <div class="stats-grid">
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-boxes card-icon text-primary"></i>
                                    <h5 class="card-title">Inventarios</h5>
                                    <a href="tableros/inventarios.html" class="btn btn-primary btn-card">Ver Reporte</a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-clipboard-check card-icon text-success"></i>
                                    <h5 class="card-title">Conteo Locales</h5>
                                    <a href="tableros/conteos.html" class="btn btn-success btn-card">Ver Reporte</a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-chart-bar card-icon text-info"></i>
                                    <h5 class="card-title">KPIs Logística</h5>
                                    <a href="tableros/kpiLogistica.html" class="btn btn-info btn-card">Ver Reporte</a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-body">
                                    <i class="fas fa-exclamation-triangle card-icon text-warning"></i>
                                    <h5 class="card-title">Fallas Producto</h5>
                                    <a href="tableros/fallasProducto.html" class="btn btn-warning btn-card">Ver Reporte</a>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

<!-- Administración Tab -->
<div class="tab-pane fade" id="administracion" role="tabpanel">
    <section class="mb-5">
        <h2 class="section-title">
            <i class="fas fa-cogs me-2"></i>
            Reportes Administrativos
        </h2>
        <div class="stats-grid">
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-project-diagram card-icon text-warning"></i>
                    <h5 class="card-title">Proyectos</h5>
                    <a href="tableros/proyectos.html" class="btn btn-warning btn-card">Ver Reporte</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-file-invoice card-icon text-info"></i>
                    <h5 class="card-title">Notas de Crédito</h5>
                    <a href="tableros/notasDeCredito.html" class="btn btn-info btn-card">Ver Reporte</a>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    <i class="fas fa-chart-line card-icon text-success"></i>
                    <h5 class="card-title">Informe Económico</h5>
                    <a href="tableros/informeEconomico.html" class="btn btn-success btn-card">Ver Reporte</a>
                </div>
            </div>
        </div>
    </section>
</div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>