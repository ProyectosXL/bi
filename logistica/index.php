<?php
/**
 * /bi/logistica/index.php
 * Dashboard Logística — XL Extra Large (AR + UY)
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['username'])) {
    header('Location: ../../sistemas/login.php');
    exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once __DIR__ . '/class/Pais.php';
$pais = Pais::resolver();

date_default_timezone_set($pais === 'UY' ? 'America/Montevideo' : 'America/Argentina/Buenos_Aires');
$ultimaAct = date('d/m/Y H:i:s');
$hoy       = date('Y-m-d');
$mesDesde  = date('Y-m-01');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Logística — XL Extra Large</title>
    <link rel="icon" type="image/jpg" href="/bi/images/icono.jpg">
    <link rel="stylesheet" href="/bi/css/base.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/base.css') ?>">
    <link rel="stylesheet" href="/bi/css/components.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/css/components.css') ?>">
    <link rel="stylesheet" href="/bi/logistica/assets/logistica.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/logistica/assets/logistica.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body>
<div class="dash-wrap">
<div id="loading-bar"></div>

<div id="loading-overlay" hidden>
    <div class="ov-spinner">
        <i class="bi bi-arrow-repeat"></i>
        <span>Cargando datos</span>
    </div>
</div>

<!-- ══ TOPBAR ════════════════════════════════════════════════════════════ -->
<header class="topbar">
    <div class="logo-box">XL</div>
    <div>
        <div class="topbar-title">DASHBOARD LOGÍSTICA</div>
        <div class="topbar-sub" id="periodo-label">—</div>
    </div>
    <!-- Toggle de país -->
    <div class="pais-toggle" role="group" aria-label="Seleccionar país">
        <a href="?pais=AR" class="pais-btn<?= $pais === 'AR' ? ' active' : '' ?>" aria-pressed="<?= $pais === 'AR' ? 'true' : 'false' ?>">
            AR
        </a>
        <a href="?pais=UY" class="pais-btn<?= $pais === 'UY' ? ' active' : '' ?>" aria-pressed="<?= $pais === 'UY' ? 'true' : 'false' ?>">
            UY
        </a>
    </div>
    <div class="topbar-meta">
        Última actualización<br>
        <strong><?= $ultimaAct ?></strong>
    </div>
</header>

<!-- ══ TOOLBAR ═══════════════════════════════════════════════════════════ -->
<div class="toolbar">
    <label for="inp-desde">Desde</label>
    <input type="date" id="inp-desde" value="<?= $mesDesde ?>">

    <label for="inp-hasta">Hasta</label>
    <input type="date" id="inp-hasta" value="<?= $hoy ?>">

    <!-- Slicer Canal -->
    <span class="slicer-wrap" id="wrap-canal" style="display:none">
        <label for="sel-canal">Canal</label>
        <select id="sel-canal"><option value="">Todos</option></select>
    </span>

    <!-- Slicer Rubro -->
    <span class="slicer-wrap" id="wrap-rubro" style="display:none">
        <label for="sel-rubro">Rubro</label>
        <select id="sel-rubro"><option value="">Todos</option></select>
    </span>

    <?php if ($pais === 'AR'): ?>
    <!-- Slicer Usuario (solo AR) -->
    <span class="slicer-wrap" id="wrap-usuario" style="display:none">
        <label for="sel-usuario">Usuario</label>
        <select id="sel-usuario"><option value="">Todos</option></select>
    </span>
    <?php endif; ?>

    <button class="btn-aplicar" id="btn-aplicar">
        <i class="bi bi-play-fill"></i> Aplicar
    </button>
</div>

<!-- ══ TAB NAV ═══════════════════════════════════════════════════════════ -->
<nav class="tab-nav">
<?php if ($pais === 'AR'): ?>
    <button class="tab-btn active" data-tab="eficiencia">
        <i class="bi bi-check2-circle"></i> Eficiencia
    </button>
    <button class="tab-btn" data-tab="leadtime">
        <i class="bi bi-clock-history"></i> Lead Time
    </button>
    <button class="tab-btn" data-tab="stock">
        <i class="bi bi-boxes"></i> Stock WMS
    </button>
    <button class="tab-btn" data-tab="prod-fact">
        <i class="bi bi-receipt"></i> Prod. Facturación
    </button>
    <button class="tab-btn" data-tab="prod-picking">
        <i class="bi bi-person-lines-fill"></i> Prod. Picking
    </button>
    <button class="tab-btn" data-tab="despacho">
        <i class="bi bi-truck"></i> Demanda y Despacho
    </button>
    <button class="tab-btn" data-tab="pedidos">
        <i class="bi bi-card-list"></i> Pedidos
    </button>
<?php else: ?>
    <button class="tab-btn active" data-tab="eficiencia-uy">
        <i class="bi bi-check2-circle"></i> Eficiencia
    </button>
    <button class="tab-btn" data-tab="stock-uy">
        <i class="bi bi-boxes"></i> Stock Tango vs WMS
    </button>
<?php endif; ?>
    <button class="tab-reload-btn" id="btn-reload" title="Recargar pestaña">
        <i class="bi bi-arrow-clockwise"></i>
    </button>
</nav>

<!-- ══ CONTENIDO ═════════════════════════════════════════════════════════ -->
<div class="main-content">
<?php if ($pais === 'AR'): ?>
    <?php include __DIR__ . '/partials/tabs_ar.php'; ?>
<?php else: ?>
    <?php include __DIR__ . '/partials/tabs_uy.php'; ?>
<?php endif; ?>
</div><!-- /main-content -->
</div><!-- /dash-wrap -->

<script>window.LOGISTICA_PAIS = '<?= $pais ?>';</script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="/bi/global/components/ExcelExporter.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/global/components/ExcelExporter.js') ?>"></script>
<script src="/bi/logistica/assets/logistica_core.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/logistica/assets/logistica_core.js') ?>"></script>
<?php if ($pais === 'AR'): ?>
<script src="/bi/logistica/assets/logistica_ar.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/logistica/assets/logistica_ar.js') ?>"></script>
<?php else: ?>
<script src="/bi/logistica/assets/logistica_uy.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/bi/logistica/assets/logistica_uy.js') ?>"></script>
<?php endif; ?>
</body>
</html>
