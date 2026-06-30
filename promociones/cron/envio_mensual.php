<?php
/**
 * /bi/promociones/cron/envio_mensual.php
 * Script PHP diseñado para ejecutarse mensualmente como tarea programada.
 * Envía el reporte de promociones del mes pasado a todas las franquicias configuradas.
 */

if (php_sapi_name() !== 'cli') {
    die("Este script solo puede ser ejecutado desde la línea de comandos (CLI).\n");
}

require_once __DIR__ . '/../class/PromocionesDB.php';
require_once 'w:/sistemas/class/email.php';

echo "Iniciando proceso de envío mensual de reportes...\n";

$configFile = __DIR__ . '/../config_comunicaciones.json';
if (!file_exists($configFile)) {
    die("No existe el archivo de configuración config_comunicaciones.json. Proceso cancelado.\n");
}

$config = json_decode(file_get_contents($configFile), true) ?? [];
if (empty($config)) {
    die("El archivo de configuración está vacío. Nada que enviar.\n");
}

// Período: mes cerrado anterior
[$da, $ha, $dp, $hp] = PromocionesDB::calcularPeriodo('mes_pasado');
echo "Período a procesar: $da al $ha\n";

$db = new PromocionesDB('franquicias');

// Obtener datos de todas las sucursales habilitadas para obtener su descripción
$sql = "
    SELECT DISTINCT sl.NRO_SUCURSAL, sl.DESC_SUCURSAL
    FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WITH (NOLOCK)
    INNER JOIN BI_PROMOCIONES s WITH (NOLOCK)
        ON sl.NRO_SUCURSAL = s.NRO_SUCURSAL AND sl.HABILITADO = 1 AND sl.NRO_SUC_MADRE IS NULL";
    
$ref = new ReflectionClass($db);
$method = $ref->getMethod('query');
$method->setAccessible(true);
$sucursalesData = $method->invoke($db, $sql);

$sucursalesMap = [];
foreach ($sucursalesData as $s) {
    $sucursalesMap[(int)$s['NRO_SUCURSAL']] = $s['DESC_SUCURSAL'];
}

// Configuración SMTP del .env
$vars = new DotEnv('w:/sistemas/class/../../.env');
$env = $vars->listVars();

foreach ($config as $nroSucursal => $sucConfig) {
    $nroSucursal = (int)$nroSucursal;
    $emails = $sucConfig['emails'] ?? '';
    $excluidas = $sucConfig['excluidas'] ?? [];

    if (empty($emails)) {
        echo "Sucursal Nro $nroSucursal: Sin emails configurados. Omitiendo.\n";
        continue;
    }

    $nombreSucursal = $sucursalesMap[$nroSucursal] ?? "Sucursal $nroSucursal";
    echo "Procesando $nombreSucursal...\n";

    try {
        $fp = [
            'banco'     => null,
            'sucursal'  => $nroSucursal,
            'promocion' => null,
            'excluir_promociones' => $excluidas
        ];

        $rows = $db->getDetallePromociones($da, $ha, $dp, $hp, $fp);
        if (empty($rows)) {
            echo "-> $nombreSucursal: No tiene facturación con promociones en este período. Omitiendo.\n";
            continue;
        }

        // Calcular total reconocimiento
        $totalReconocimiento = 0;
        foreach ($rows as $r) {
            $totalReconocimiento += ($r['costo_total'] ?? 0) * 0.5;
        }

        // Render HTML
        $html = renderReporteHtml($nombreSucursal, 'mes_pasado', $da, $ha, $rows, $totalReconocimiento);

        // Instanciar PHPMailer
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = 'smtp.gmail.com';
        $mailer->SMTPAuth = true;
        $mailer->Username = $env['USER_EMAIL_PRESUPUESTOS_SUPERVISION'];
        $mailer->Password = $env['PASS_EMAIL_PRESUPUESTOS_SUPERVISION'];
        $mailer->SMTPSecure = 'tls';
        $mailer->Port = 587;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($env['USER_EMAIL_PRESUPUESTOS_SUPERVISION'], 'Dashboard Promociones XL');

        $destinos = explode(',', $emails);
        foreach ($destinos as $dst) {
            $mailer->addAddress(trim($dst));
        }

        $mailer->Subject = "Reporte de Reconocimiento Promociones - $nombreSucursal (Mes Pasado)";
        $mailer->Body = $html;
        $mailer->isHTML(true);

        $mailer->send();
        echo "-> $nombreSucursal: Reporte enviado correctamente a $emails\n";

    } catch (Exception $e) {
        echo "-> $nombreSucursal: ERROR al enviar: " . $e->getMessage() . "\n";
    }
}

echo "Proceso finalizado.\n";

// Reutilizar la función de renderizado
function renderReporteHtml($nombre, $periodo, $da, $ha, $rows, $totalReconocimiento) {
    $rowsHtml = '';
    foreach ($rows as $r) {
        $costoTotal = (float)$r['costo_total'];
        $reconocimiento = $costoTotal * 0.5;
        $pctCostoFac = ($r['pct_costo_total'] * 100);
        
        $rowsHtml .= "
        <tr style='border-bottom: 1px solid #e2e8f0;'>
            <td style='padding: 8px; font-size: 13px; color: #1e293b; text-align: left;'>" . htmlspecialchars($r['promocion']) . "</td>
            <td style='padding: 8px; font-size: 13px; color: #1e293b; text-align: left;'>" . htmlspecialchars($r['banco']) . "</td>
            <td style='padding: 8px; font-size: 13px; color: #1e293b; text-align: right;'>$ " . number_format($r['fac_cpromo'], 2, ',', '.') . "</td>
            <td style='padding: 8px; font-size: 13px; color: #1e293b; text-align: right;'>" . number_format($r['tickets_cpromo'], 0, ',', '.') . "</td>
            <td style='padding: 8px; font-size: 13px; color: #1e293b; text-align: right;'>$ " . number_format($costoTotal, 2, ',', '.') . "</td>
            <td style='padding: 8px; font-size: 13px; color: #1e293b; text-align: right;'>" . number_format($pctCostoFac, 2, ',', '.') . " %</td>
            <td style='padding: 8px; font-size: 13px; color: #1e293b; text-align: right; font-weight: bold;'>$ " . number_format($reconocimiento, 2, ',', '.') . "</td>
        </tr>";
    }

    return "
    <html>
    <body style='font-family: Arial, sans-serif; background-color: #f8fafc; padding: 20px;'>
        <div style='max-width: 800px; margin: 0 auto; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);'>
            <div style='border-bottom: 2px solid #2563eb; padding-bottom: 12px; margin-bottom: 20px;'>
                <h2 style='color: #1e293b; margin: 0; font-size: 20px;'>Reporte General de Reconocimiento</h2>
                <p style='margin: 4px 0 0; color: #64748b; font-size: 14px;'>Período: $da al $ha</p>
            </div>
            
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;'>
                <tr style='background: #f1f5f9;'>
                    <th style='padding: 10px; text-align: left; color: #475569;'>Franquicia</th>
                    <th style='padding: 10px; text-align: right; color: #475569;'>Reconocimiento</th>
                </tr>
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #e2e8f0; font-weight: bold;'>Origen: Franquicias | Sucursal: $nombre</td>
                    <td style='padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #16a34a; font-size: 16px;'>$ " . number_format($totalReconocimiento, 2, ',', '.') . "</td>
                </tr>
            </table>

            <table style='width: 100%; border-collapse: collapse; font-size: 13px;'>
                <thead>
                    <tr style='background: #e2e8f0; border-bottom: 2px solid #cbd5e1;'>
                        <th style='padding: 8px; text-align: left; color: #334155;'>Promoción</th>
                        <th style='padding: 8px; text-align: left; color: #334155;'>Banco</th>
                        <th style='padding: 8px; text-align: right; color: #334155;'>Fact. C/Promo</th>
                        <th style='padding: 8px; text-align: right; color: #334155;'>Tickets</th>
                        <th style='padding: 8px; text-align: right; color: #334155;'>Costo Total</th>
                        <th style='padding: 8px; text-align: right; color: #334155;'>% Costo/FAC</th>
                        <th style='padding: 8px; text-align: right; color: #334155;'>Reconocimiento $</th>
                    </tr>
                </thead>
                <tbody>
                    $rowsHtml
                    <tr style='background: #f8fafc; font-weight: bold; border-top: 2px solid #cbd5e1;'>
                        <td colspan='6' style='padding: 10px; text-align: right; font-size: 14px;'>Total General Reconocido</td>
                        <td style='padding: 10px; text-align: right; font-size: 15px; color: #16a34a;'>$ " . number_format($totalReconocimiento, 2, ',', '.') . "</td>
                    </tr>
                </tbody>
            </table>
            
            <div style='margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 15px; font-size: 11px; color: #94a3b8; text-align: center;'>
                Este es un email automatizado. Por favor no responder a esta dirección de correo.
            </div>
        </div>
    </body>
    </html>";
}
