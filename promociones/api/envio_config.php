<?php
/**
 * /bi/promociones/api/envio_config.php
 * Endpoint para obtener/guardar configuraciones de envíos y gatillar envíos manuales.
 */
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$configFile = __DIR__ . '/../config_comunicaciones.json';

try {
    date_default_timezone_set('America/Argentina/Buenos_Aires');

    if (!isset($_SESSION['username'])) {
        throw new RuntimeException('No autenticado');
    }
    if (!in_array($_SESSION['tipo'] ?? '', ['GERENCIA', 'SUPERVISION'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $action = $_GET['action'] ?? 'get';

    // Leer config actual
    $config = [];
    if (file_exists($configFile)) {
        $config = json_decode(file_get_contents($configFile), true) ?? [];
    }

    if ($action === 'get') {
        // Retornar lista de franquicias cruzando por mail y nombres
        require_once __DIR__ . '/../class/PromocionesDB.php';
        $db = new PromocionesDB('franquicias');
        
        $sql = "
            SELECT DISTINCT sl.NRO_SUCURSAL, sl.DESC_SUCURSAL, sl.cod_client, sl.MAIL
            FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WITH (NOLOCK)
            INNER JOIN BI_PROMOCIONES s WITH (NOLOCK)
                ON sl.NRO_SUCURSAL = s.NRO_SUCURSAL AND sl.HABILITADO = 1 AND sl.NRO_SUC_MADRE IS NULL
            ORDER BY sl.DESC_SUCURSAL";
            
        // Usar reflection o query de PromocionesDB
        $ref = new ReflectionClass($db);
        $method = $ref->getMethod('query');
        $method->setAccessible(true);
        $sucursales = $method->invoke($db, $sql);

        ob_clean();
        echo json_encode([
            'ok' => true,
            'sucursales' => $sucursales,
            'config' => $config
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['nro_sucursal'])) {
            throw new RuntimeException('Datos inválidos');
        }

        $nro = (string)$data['nro_sucursal'];
        $config[$nro] = [
            'emails' => $data['emails'] ?? '',
            'excluidas' => $data['excluidas'] ?? []
        ];

        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        ob_clean();
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'send_manual') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['nro_sucursal'])) {
            throw new RuntimeException('Datos inválidos');
        }

        $nro = (int)$data['nro_sucursal'];
        $nombre = $data['nombre'] ?? '';
        $emails = $data['emails'] ?? '';
        $excluidas = $data['excluidas'] ?? [];

        if (empty($emails)) {
            throw new RuntimeException('Debe ingresar al menos un correo de destino.');
        }

        // Obtener el período (mes actual)
        require_once __DIR__ . '/../class/PromocionesDB.php';
        $periodo = $_GET['periodo'] ?? 'mes_pasado'; // Por defecto mandar mes cerrado
        [$da, $ha, $dp, $hp] = PromocionesDB::calcularPeriodo($periodo);

        $db = new PromocionesDB('franquicias');
        
        // Obtener el detalle por promoción para esta sucursal respetando la exclusión
        $fp = [
            'banco'     => null,
            'sucursal'  => $nro,
            'promocion' => null,
            'excluir_promociones' => $excluidas
        ];

        $rows = $db->getDetallePromociones($da, $ha, $dp, $hp, $fp);
        
        if (empty($rows)) {
            throw new RuntimeException('Esta sucursal no registra facturación con promociones en el período seleccionado.');
        }

        // Calcular total reconocimiento
        $totalReconocimiento = 0;
        foreach ($rows as $r) {
            $totalReconocimiento += ($r['costo_total'] ?? 0) * 0.5;
        }

        // Render HTML premium
        $html = renderReporteHtml($nombre, $periodo, $da, $ha, $rows, $totalReconocimiento);

        // Envío por Mail usando sistemas/class/email.php y PHPMailer
        require_once 'w:/sistemas/class/email.php';
        
        // Vamos a instanciar y usar PHPMailer directamente para poder personalizar los correos
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        
        // Leer credenciales del .env
        $vars = new DotEnv('w:/sistemas/class/../../.env');
        $env = $vars->listVars();
        
        $mailer->Host = 'smtp.gmail.com';
        $mailer->SMTPAuth = true;
        $mailer->Username = $env['USER_EMAIL_PRESUPUESTOS_SUPERVISION'];
        $mailer->Password = $env['PASS_EMAIL_PRESUPUESTOS_SUPERVISION'];
        $mailer->SMTPSecure = 'tls';
        $mailer->Port = 587;
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($env['USER_EMAIL_PRESUPUESTOS_SUPERVISION'], 'Dashboard Promociones XL');

        // Agregar destinos
        $destinos = explode(',', $emails);
        foreach ($destinos as $dst) {
            $mailer->addAddress(trim($dst));
        }

        $mailer->Subject = "Reporte de Reconocimiento Promociones - $nombre ($periodo)";
        $mailer->Body = $html;
        $mailer->isHTML(true);
        
        $mailer->send();

        ob_clean();
        echo json_encode(['ok' => true, 'msg' => 'Email enviado correctamente a ' . $emails]);
        exit;
    }

} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;

// Render del HTML de la imagen enviada por el usuario
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
