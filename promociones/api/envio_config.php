<?php
/**
 * /bi/promociones/api/envio_config.php
 * Endpoint para obtener/guardar configuraciones de envíos y gatillar envíos manuales.
 */
session_start();
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$configFile  = __DIR__ . '/../config_comunicaciones.json';
$logEnviosFile = __DIR__ . '/../log_envios.json';

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
        // Retornar todas las franquicias habilitadas desde locales_lakers (con o sin Tango)
        require_once __DIR__ . '/../class/PromocionesDB.php';
        $db = new PromocionesDB('franquicias');
        
        $sql = "
            SELECT sl.NRO_SUCURSAL, sl.DESC_SUCURSAL, sl.cod_client, sl.MAIL
            FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WITH (NOLOCK)
            WHERE sl.CANAL = 'FRANQUICIAS' AND sl.HABILITADO = 1 AND sl.NRO_SUC_MADRE IS NULL
            ORDER BY sl.DESC_SUCURSAL";
            
        $ref = new ReflectionClass($db);
        $method = $ref->getMethod('query');
        $method->setAccessible(true);
        $sucursales = $method->invoke($db, $sql);

        // Exclusiones globales
        $globalExcluidas = $config['_global']['excluidas'] ?? [];

        ob_clean();
        echo json_encode([
            'ok'              => true,
            'sucursales'      => $sucursales,
            'config'          => $config,
            'global_excluidas'=> $globalExcluidas,
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
            'emails'    => $data['emails'] ?? '',
            'excluidas' => $data['excluidas'] ?? []
        ];

        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        ob_clean();
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'save_global') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || !isset($data['excluidas'])) {
            throw new RuntimeException('Datos inválidos para configuración global');
        }

        $config['_global'] = [
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

        $nro    = (int)$data['nro_sucursal'];
        $nombre = $data['nombre'] ?? '';
        $emails = $data['emails'] ?? '';
        $excluidas = $data['excluidas'] ?? [];

        if (empty($emails)) {
            throw new RuntimeException('Debe ingresar al menos un correo de destino.');
        }

        require_once __DIR__ . '/../class/PromocionesDB.php';
        $periodo = $_GET['periodo'] ?? 'mes_pasado';
        [$da, $ha, $dp, $hp] = PromocionesDB::calcularPeriodo($periodo);

        $db = new PromocionesDB('franquicias');

        // ── CAMBIO 3: Verificar diferencias de ventas antes de enviar ────────
        $diferencias = $db->getDiferenciaVentas($da, $ha, [$nro]);
        if (!empty($diferencias[$nro]) && $diferencias[$nro] === true) {
            ob_clean();
            echo json_encode([
                'ok'    => false,
                'error' => "La sucursal $nombre presenta diferencias de ventas o no posee conexión en el período seleccionado. El email NO fue enviado.",
                'con_diferencias' => true,
            ]);
            exit;
        }

        // Determinar si la sucursal es sin Tango (si no tiene registros de promociones en BI_PROMOCIONES)
        $ref = new ReflectionClass($db);
        $methodQuery = $ref->getMethod('query');
        $methodQuery->setAccessible(true);
        
        $sqlHasTango = "SELECT TOP 1 1 FROM BI_PROMOCIONES WITH (NOLOCK) WHERE NRO_SUCURSAL = ? AND FECHA >= ? AND FECHA < ?";
        $resTango = $methodQuery->invoke($db, $sqlHasTango, [$nro, $da, (new DateTime($ha))->modify('+1 day')->format('Y-m-d')]);
        $sinTango = empty($resTango);

        $html = '';
        $totalReconocimiento = 0;

        if ($sinTango) {
            // ── FRANQUICIAS SIN TANGO: estimar facturación y costo ────────────────
            
            // 1. Obtener la facturación mensual real desde la base de objetivos
            $sqlObj = "
                SELECT SUM(ISNULL(FD.importeVentaReal, 0)) AS fact_total
                FROM sistemas.dbo.FP_ObjetivosFinalesDetalle FD WITH (NOLOCK)
                INNER JOIN sistemas.dbo.PuntosDeVenta PV WITH (NOLOCK) ON FD.idPOS = PV.id
                WHERE PV.idTango = ? AND FD.fecha >= ? AND FD.fecha < ?
            ";
            $resObj = $methodQuery->invoke($db, $sqlObj, [$nro, $da, (new DateTime($ha))->modify('+1 day')->format('Y-m-d')]);
            $factTotalObj = !empty($resObj) ? (float)$resObj[0]['fact_total'] : 0;

            if ($factTotalObj <= 0) {
                throw new RuntimeException('Esta sucursal no registra facturación cargada en el portal de objetivos para el período seleccionado.');
            }

            // 2. Calcular el costo promedio general de las tradicionales
            $sqlTrad = "
                SELECT 
                    ISNULL(SUM(s.COSTO), 0) AS costo_total,
                    ISNULL(SUM(s.IMPORTE_TO), 0) AS fact_total
                FROM BI_PROMOCIONES s WITH (NOLOCK)
                INNER JOIN [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl WITH (NOLOCK)
                    ON sl.NRO_SUCURSAL = s.NRO_SUCURSAL
                WHERE s.FECHA >= ? AND s.FECHA < ? AND sl.HABILITADO = 1 AND sl.NRO_SUC_MADRE IS NULL
            ";
            $resTrad = $methodQuery->invoke($db, $sqlTrad, [$da, (new DateTime($ha))->modify('+1 day')->format('Y-m-d')]);
            $costoTrad = !empty($resTrad) ? (float)$resTrad[0]['costo_total'] : 0;
            $factTrad = !empty($resTrad) ? (float)$resTrad[0]['fact_total'] : 0;
            $pctCostoPromedio = $factTrad > 0 ? ($costoTrad / $factTrad) : 0.0171;

            // 3. Estimar costo y calcular reconocimiento sin IVA
            $costoEstimado = $factTotalObj * $pctCostoPromedio;
            $totalReconocimiento = ($costoEstimado / 1.21) * 0.5;

            // Render HTML simplificado
            $html = renderReporteHtmlSinTango($nombre, $periodo, $da, $ha, $factTotalObj, $pctCostoPromedio, $costoEstimado, $totalReconocimiento);

        } else {
            // ── FRANQUICIAS TRADICIONALES: flujo detallado original ──────────────
            $globalExcluidas = $config['_global']['excluidas'] ?? [];
            $excluidas = array_values(array_unique(array_merge($globalExcluidas, $excluidas)));

            $fp = [
                'banco'               => null,
                'sucursal'            => $nro,
                'promocion'           => null,
                'excluir_promociones' => $excluidas
            ];

            $rows = $db->getDetallePromociones($da, $ha, $dp, $hp, $fp);
            
            if (empty($rows)) {
                throw new RuntimeException('Esta sucursal no registra facturación con promociones en el período seleccionado.');
            }

            foreach ($rows as $r) {
                $totalReconocimiento += (($r['costo_total'] ?? 0) / 1.21) * 0.5;
            }

            $html = renderReporteHtml($nombre, $periodo, $da, $ha, $rows, $totalReconocimiento);
        }

        // Envío por Mail
        require_once 'w:/sistemas/class/email.php';
        
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        
        $vars = new DotEnv('w:/sistemas/class/../../.env');
        $env = $vars->listVars();
        
        $mailer->Host       = 'smtp.gmail.com';
        $mailer->SMTPAuth   = true;
        $mailer->Username   = $env['USER_EMAIL_PRESUPUESTOS_SUPERVISION'];
        $mailer->Password   = $env['PASS_EMAIL_PRESUPUESTOS_SUPERVISION'];
        $mailer->SMTPSecure = 'tls';
        $mailer->Port       = 587;
        $mailer->CharSet    = 'UTF-8';
        $mailer->setFrom($env['USER_EMAIL_PRESUPUESTOS_SUPERVISION'], 'Dashboard Promociones XL');

        $destinos = explode(',', $emails);
        foreach ($destinos as $dst) {
            $mailer->addAddress(trim($dst));
        }

        $mailer->Subject = "Reporte de Reconocimiento Promociones - $nombre ($periodo)";
        $mailer->Body    = $html;
        $mailer->isHTML(true);
        
        $mailer->send();

        // Registrar envío en log_envios.json
        $log = file_exists($logEnviosFile)
            ? (json_decode(file_get_contents($logEnviosFile), true) ?? [])
            : [];
        $period = $da . '|' . $ha;
        if (!isset($log[$period])) $log[$period] = [];
        $log[$period][(string)$nro] = [
            'enviado_at' => date('Y-m-d H:i:s'),
            'nombre'     => $nombre,
            'usuario'    => $_SESSION['username'] ?? '',
        ];
        file_put_contents($logEnviosFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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

// ── Render HTML del email para sucursales con Tango ──────────────────────
function renderReporteHtml($nombre, $periodo, $da, $ha, $rows, $totalReconocimiento) {
    $rowsHtml = '';
    foreach ($rows as $r) {
        $costoTotal     = (float)$r['costo_total'];
        $reconocimiento = ($costoTotal / 1.21) * 0.5;
        $pctCostoFac    = ($r['pct_costo_total'] * 100);
        
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
                    <th style='padding: 10px; text-align: right; color: #475569;'>Reconocimiento (s/IVA)</th>
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
                        <th style='padding: 8px; text-align: right; color: #334155;'>Reconocimiento $ (s/IVA)</th>
                    </tr>
                </thead>
                <tbody>
                    $rowsHtml
                    <tr style='background: #f8fafc; font-weight: bold; border-top: 2px solid #cbd5e1;'>
                        <td colspan='6' style='padding: 10px; text-align: right; font-size: 14px;'>Total General Reconocido (s/IVA)</td>
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

// ── Render HTML del email para sucursales sin Tango (Simplificado) ────────
function renderReporteHtmlSinTango($nombre, $periodo, $da, $ha, $factTotal, $pctCostoPromedio, $costoEstimado, $totalReconocimiento) {
    return "
    <html>
    <body style='font-family: Arial, sans-serif; background-color: #f8fafc; padding: 20px;'>
        <div style='max-width: 800px; margin: 0 auto; background: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);'>
            <div style='border-bottom: 2px solid #4f46e5; padding-bottom: 12px; margin-bottom: 20px;'>
                <h2 style='color: #1e293b; margin: 0; font-size: 20px;'>Reporte General de Reconocimiento (Franquicia Nueva)</h2>
                <p style='margin: 4px 0 0; color: #64748b; font-size: 14px;'>Período: $da al $ha</p>
            </div>
            
            <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 16px; margin-bottom: 20px; font-size: 14px; color: #334155; line-height: 1.5;'>
                Esta sucursal no registra reporte detallado de promociones en Tango. Su reconocimiento se calcula utilizando el <strong>costo promedio del mes</strong> de las restantes franquicias.
            </div>

            <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;'>
                <tr style='background: #f1f5f9;'>
                    <th style='padding: 10px; text-align: left; color: #475569;'>Concepto</th>
                    <th style='padding: 10px; text-align: right; color: #475569;'>Valor</th>
                </tr>
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #e2e8f0; font-weight: bold;'>Facturación Total del Período (Portal)</td>
                    <td style='padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;'>$ " . number_format($factTotal, 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #e2e8f0; font-weight: bold;'>% Costo / FAC Promedio Franquicias</td>
                    <td style='padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;'>" . number_format($pctCostoPromedio * 100, 2, ',', '.') . " %</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border-bottom: 1px solid #e2e8f0; font-weight: bold;'>Costo Estimado de Promociones</td>
                    <td style='padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: right;'>$ " . number_format($costoEstimado, 2, ',', '.') . "</td>
                </tr>
                <tr style='background: #f0fdf4;'>
                    <td style='padding: 12px 10px; border-bottom: 1px solid #e2e8f0; font-weight: bold; color: #166534; font-size: 15px;'>Reconocimiento $ (s/IVA)</td>
                    <td style='padding: 12px 10px; border-bottom: 1px solid #e2e8f0; text-align: right; font-weight: bold; color: #16a34a; font-size: 17px;'>$ " . number_format($totalReconocimiento, 2, ',', '.') . "</td>
                </tr>
            </table>
            
            <div style='margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 15px; font-size: 11px; color: #94a3b8; text-align: center;'>
                Este es un email automatizado. Por favor no responder a esta dirección de correo.
            </div>
        </div>
    </body>
    </html>";
}
