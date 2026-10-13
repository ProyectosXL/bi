<?php
/**
 * /bi/abastecimiento/index.php
 * Dashboard de Abastecimiento (Ingresos, Ventas, Stock e Imágenes de Producto)
 * Extra Large (XL)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../class/config.php';
$tipoSesion = $_SESSION['tipo'] ?? 'GERENCIA';
$nombreUsuario = $_SESSION['fp_auth_user']['nombre_completo'] ?? $_SESSION['nombreCompleto'] ?? $_SESSION['username'] ?? 'Usuario';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$ultimaAct = date('d/m/Y H:i:s');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Abastecimiento — XL Extra Large</title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">

    <!-- Estilos Base y Componentes Compartidos -->
    <link rel="stylesheet" href="/bi/css/base.css?v=<?= time() ?>">
    <link rel="stylesheet" href="/bi/css/components.css?v=<?= time() ?>">
    
    <!-- Estilos específicos de Abastecimiento -->
    <link rel="stylesheet" href="/bi/abastecimiento/css/abastecimiento.css?v=<?= time() ?>">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="is-loading">

<!-- ══ LOADING OVERLAY INICIAL ════════════════════════════════════ -->
<div id="loading-bar"></div>
<div id="loading-overlay" class="loading-overlay-active">
    <div class="loading-modal-box">
        <div class="loading-spinner-brand"></div>
        <div class="loading-text-main">Cargando Abastecimiento</div>
        <div class="loading-text-sub" id="loading-status-text">Conectando con bases de datos y cargando indicadores...</div>
    </div>
</div>

<div class="dash-wrap">

    <!-- ══ TOPBAR CORPORATIVO ═══════════════════════════════════════ -->
    <header class="topbar abast-topbar">
        <div class="topbar-left">
            <a href="/bi/index.php" class="brand-link" title="Volver al menú principal de BI">
                <div class="logo-box">XL</div>
            </a>
            <div class="topbar-info">
                <div class="topbar-title-row">
                    <span class="topbar-title">DASHBOARD DE ABASTECIMIENTO</span>
                    <span class="topbar-badge">INGRESOS · VENTAS · STOCK</span>
                </div>
                <div class="topbar-sub">
                    Control de flujo comercial e inventario consolidado
                </div>
            </div>
        </div>

        <div class="topbar-right">
            <div class="topbar-meta">
                <span class="meta-label">Última actualización</span>
                <strong class="meta-time"><?= $ultimaAct ?></strong>
            </div>
            <a href="/bi/index.php" class="btn-topbar-back">
                <i class="bi bi-arrow-left"></i> Volver al BI
            </a>
        </div>
    </header>

    <!-- ══ BARRA DE FILTROS ══════════════════════════════════════════ -->
    <section class="abast-filters-container">
        <div class="abast-filters-grid">
            <div class="filter-group">
                <label for="sel-proveedor"><i class="bi bi-building"></i> Proveedor</label>
                <select id="sel-proveedor" class="filter-control">
                    <option value="">Todos</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="sel-rubro"><i class="bi bi-tag"></i> Rubro</label>
                <select id="sel-rubro" class="filter-control">
                    <option value="">Todos</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="sel-categoria"><i class="bi bi-grid-3x3"></i> Categoría</label>
                <select id="sel-categoria" class="filter-control">
                    <option value="">Todos</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="sel-temporada"><i class="bi bi-calendar-event"></i> Temporada</label>
                <select id="sel-temporada" class="filter-control">
                    <option value="">Todos</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="sel-canal"><i class="bi bi-shop"></i> Canal</label>
                <select id="sel-canal" class="filter-control">
                    <option value="">Todos</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="sel-sucursal"><i class="bi bi-geo-alt"></i> Sucursal</label>
                <select id="sel-sucursal" class="filter-control">
                    <option value="">Todos</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="input-articulo"><i class="bi bi-upc-scan"></i> Artículo</label>
                <input type="text" id="input-articulo" class="filter-control" placeholder="Buscar código...">
            </div>

            <div class="filter-group">
                <label for="input-desc"><i class="bi bi-search"></i> Descripción</label>
                <input type="text" id="input-desc" class="filter-control" placeholder="Modelo / Desc...">
            </div>

            <div class="filter-group">
                <label for="input-desde"><i class="bi bi-calendar"></i> Desde</label>
                <input type="date" id="input-desde" class="filter-control" value="<?= date('Y-01-01') ?>">
            </div>

            <div class="filter-group">
                <label for="input-hasta"><i class="bi bi-calendar"></i> Hasta</label>
                <input type="date" id="input-hasta" class="filter-control" value="<?= date('Y-m-d') ?>">
            </div>

            <div class="filter-actions">
                <button id="btn-apply-filters" class="btn-filter-apply">
                    <i class="bi bi-funnel-fill"></i> Aplicar
                </button>
                <button id="btn-clear-filters" class="btn-filter-clear" title="Limpiar filtros">
                    <i class="bi bi-x-circle"></i>
                </button>
            </div>
        </div>
    </section>

    <!-- ══ FILA DE TARJETAS KPI ══════════════════════════════════════ -->
    <section class="abast-kpi-row">
        
        <!-- Bloque INGRESOS -->
        <div class="kpi-block kpi-block-ingresos">
            <div class="kpi-block-title">
                <i class="bi bi-box-arrow-in-down"></i> Ingresos
            </div>
            <div class="kpi-items-flex">
                <div class="kpi-sub-item">
                    <span class="kpi-sub-value" id="kpi-ingresos">—</span>
                    <span class="kpi-sub-label">Cantidad Total Ingresada</span>
                </div>
            </div>
        </div>

        <!-- Bloque VENTAS -->
        <div class="kpi-block kpi-block-ventas">
            <div class="kpi-block-title">
                <i class="bi bi-graph-up-arrow"></i> Ventas
            </div>
            <div class="kpi-items-flex">
                <div class="kpi-sub-item">
                    <span class="kpi-sub-value" id="kpi-venta-ny">—</span>
                    <span class="kpi-sub-label">Venta Cen a MY</span>
                </div>
                <div class="kpi-sub-item">
                    <span class="kpi-sub-value" id="kpi-venta-fr">—</span>
                    <span class="kpi-sub-label">Venta Cen a FQ</span>
                </div>
                <div class="kpi-sub-item">
                    <span class="kpi-sub-value" id="kpi-venta-pp">—</span>
                    <span class="kpi-sub-label">PP a Público</span>
                </div>
                <div class="kpi-sub-item">
                    <span class="kpi-sub-value" id="kpi-venta-fq">—</span>
                    <span class="kpi-sub-label">FQ a Público</span>
                </div>
            </div>
        </div>

        <!-- Bloque STOCK -->
        <div class="kpi-block kpi-block-stock">
            <div class="kpi-block-title">
                <i class="bi bi-boxes"></i> Stock
            </div>
            <div class="kpi-items-flex">
                <div class="kpi-sub-item">
                    <span class="kpi-sub-value" id="kpi-stock-suc">—</span>
                    <span class="kpi-sub-label">Stock Sucursal</span>
                </div>
                <div class="kpi-sub-item">
                    <span class="kpi-sub-value" id="kpi-stock-cen">—</span>
                    <span class="kpi-sub-label">Stock Central</span>
                </div>
                <div class="kpi-sub-item">
                    <span class="kpi-sub-value" id="kpi-pedidos">—</span>
                    <span class="kpi-sub-label">Pedidos Abiertos</span>
                </div>
            </div>
        </div>

    </section>

    <!-- ══ CONTENEDOR PRINCIPAL REORGANIZADO ══════════════════════════ -->
    <main class="abast-content-wrap">

        <!-- ── FILA 1: INGRESOS & IMAGEN DEL PRODUCTO ── -->
        <section class="abast-row-top">
            <!-- Tabla Ingresos -->
            <div class="abast-panel">
                <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div class="panel-title">
                        <i class="bi bi-arrow-down-circle text-primary"></i> Ingresos de Mercadería
                    </div>
                    <div id="filter-badge-box" style="display:none; align-items:center; gap:8px;">
                        <span id="filter-active-badge" class="badge" style="background:#0284c7; color:#fff; font-size:0.75rem; padding:4px 8px; border-radius:4px; font-weight:600;"></span>
                        <button id="btn-unfilter-art" class="btn-filter-clear" style="padding:2px 8px; font-size:0.75rem;" title="Quitar filtro de artículo"><i class="bi bi-x-circle"></i> Ver todos</button>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="table-responsive-box table-tall">
                        <table class="abast-table" id="table-ingresos">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Artículo</th>
                                    <th>Descripción</th>
                                    <th>Color</th>
                                    <th>Proveedor</th>
                                    <th class="num">Cantidad</th>
                                    <th>Comprobante</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-ingresos">
                                <tr><td colspan="7" class="center"><div class="bi-loader"><i class="bi bi-arrow-repeat"></i> Cargando ingresos...</div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Vista Previa de Imagen -->
            <div class="abast-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="bi bi-image text-primary"></i> Imagen del Producto
                    </div>
                </div>
                <div class="panel-body" style="display:flex; flex-direction:column;">
                    <div class="product-image-container" id="product-img-box">
                        <div id="product-no-img" class="product-no-image">
                            <i class="bi bi-image" style="font-size: 3rem; color: #94a3b8; margin-bottom: 8px;"></i>
                            <span style="color: #64748b; font-size: 0.88rem;">Seleccione un artículo de la tabla para ver su foto</span>
                        </div>
                        <img id="product-img-preview" class="product-image-preview" src="" alt="Producto XL" style="display: none;">
                        <div id="product-img-label" class="product-image-label" style="display: none;"></div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── FILA 2: MATRIZ DE VENTAS (ANCHO COMPLETO) ── -->
        <section class="abast-row-full">
            <div class="abast-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="bi bi-table text-primary"></i> Matriz de Ventas por Período y Canal
                    </div>
                </div>
                <div class="panel-body">
                    <div class="table-responsive-box table-tall">
                        <table class="abast-table" id="table-ventas-matrix">
                            <thead id="thead-ventas-matrix">
                                <tr><th>Artículo</th><th>Total</th></tr>
                            </thead>
                            <tbody id="tbody-ventas-matrix">
                                <tr><td colspan="2" class="center"><div class="bi-loader"><i class="bi bi-arrow-repeat"></i> Cargando matriz de ventas...</div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- ── FILA 3: STOCK SUCURSAL & STOCK CENTRAL (50% / 50%) ── -->
        <section class="abast-row-bottom">
            <!-- Stock Sucursal -->
            <div class="abast-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="bi bi-shop-window text-primary"></i> Stock Sucursal
                    </div>
                </div>
                <div class="panel-body">
                    <div class="table-responsive-box table-tall">
                        <table class="abast-table" id="table-stock-suc">
                            <thead>
                                <tr>
                                    <th>Artículo</th>
                                    <th>Sucursal</th>
                                    <th>Color</th>
                                    <th class="num">Stock</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-stock-suc">
                                <tr><td colspan="4" class="center"><div class="bi-loader"><i class="bi bi-arrow-repeat"></i> Cargando stock sucursales...</div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Stock Central -->
            <div class="abast-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="bi bi-building text-primary"></i> Stock Central
                    </div>
                </div>
                <div class="panel-body">
                    <div class="table-responsive-box table-tall">
                        <table class="abast-table" id="table-stock-cen">
                            <thead>
                                <tr>
                                    <th>Artículo</th>
                                    <th>Color</th>
                                    <th class="num">Disponible</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-stock-cen">
                                <tr><td colspan="3" class="center"><div class="bi-loader"><i class="bi bi-arrow-repeat"></i> Cargando stock central...</div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

    </main>

</div>

<!-- Scripts -->
<script src="/bi/abastecimiento/js/abastecimiento.js?v=<?= time() ?>"></script>
</body>
</html>
