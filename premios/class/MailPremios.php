<?php
/**
 * MailPremios
 * Envío de mails de Premios Comercial (detalle por supervisora / resumen mensual) vía
 * Database Mail de SQL Server (msdb.dbo.sp_send_dbmail), reusando el mismo mecanismo que
 * sistemas/adminNotificaciones (perfil 'sistemas', conexión 'central') sin depender de su
 * código — ver premios/README.md, sección "Envío de mail".
 *
 * El mapeo supervisora→email vive en una tabla propia de premios
 * (PremiosDB::getEmailSupervisora()), no en RO_T_DESTINATARIOS_MAIL (esa tabla modela
 * "un proceso con N destinatarios fijos", no "un destinatario por persona"). El resumen
 * mensual sí encaja 1 a 1 en ese modelo y usa RO_T_DESTINATARIOS_MAIL/RO_T_CONFIGURACION_MAIL
 * con TIPO_NOTIFICACION='PREMIOS_RESUMEN_MENSUAL' (ver premios/sql/setup_control_mail.sql).
 */
class MailPremios
{
    public const PROFILE_DEFAULT = 'sistemas';
    public const TIPO_NOTIFICACION_RESUMEN_MENSUAL = 'PREMIOS_RESUMEN_MENSUAL';
    public const TIPO_NOTIFICACION_AVANCE_QUINCENAL = 'PREMIOS_AVANCE_QUINCENAL';

    /** @var resource Conexión a la base 'central' (msdb vive en el mismo servidor). */
    private $connCentral;

    public function __construct()
    {
        require_once __DIR__ . '/../../class/classEnv.php';
        require_once __DIR__ . '/../../class/Conexion.php';
        $this->connCentral = (new Conexion())->conectar('central');
        if (!$this->connCentral) {
            throw new RuntimeException('No se pudo conectar a la base central (Database Mail)');
        }
    }

    /**
     * @param string[] $destinatarios
     * @param string[] $copia En copia (CC) — @copy_recipients de sp_send_dbmail, no se agrega
     *                        a $destinatarios para que quede como CC real y no como "Para".
     */
    public function enviar(array $destinatarios, string $asunto, string $htmlBody, string $perfil = self::PROFILE_DEFAULT, array $copia = []): void
    {
        if (!$destinatarios) {
            throw new RuntimeException('No hay destinatarios para enviar el mail');
        }
        $copyParam = $copia ? ', @copy_recipients = ?' : '';
        $sql = "EXEC msdb.dbo.sp_send_dbmail
                    @profile_name = ?,
                    @recipients   = ?,
                    @subject      = ?,
                    @body         = ?,
                    @body_format  = 'HTML'
                    $copyParam";
        $params = [$perfil, implode(';', $destinatarios), $asunto, $htmlBody];
        if ($copia) {
            $params[] = implode(';', $copia);
        }
        $stmt = sqlsrv_query($this->connCentral, $sql, $params);
        if ($stmt === false) {
            $errores = sqlsrv_errors() ?? [];
            // sp_send_dbmail devuelve un mensaje informativo ("Mail (Id: N) queued") con
            // SQLSTATE 01000 (warning, no error real) — sqlsrv lo reporta como si la
            // consulta hubiera fallado aunque el mail se encoló correctamente. Solo se
            // considera un error real si hay alguno con SQLSTATE distinto de warning.
            $esSoloAdvertencia = $errores && !array_filter($errores, fn($e) => ($e['SQLSTATE'] ?? '') !== '01000');
            if (!$esSoloAdvertencia) {
                throw new RuntimeException('Error al enviar mail: ' . print_r($errores, true));
            }
        }
    }

    /**
     * Destinatarios activos + config (perfil/asunto) de un TIPO_NOTIFICACION ya dado de alta
     * en adminNotificaciones (RO_T_DESTINATARIOS_MAIL / RO_T_CONFIGURACION_MAIL).
     */
    public function obtenerDestinatariosYConfig(string $tipoNotificacion): array
    {
        $sql = "SELECT EMAIL FROM RO_T_DESTINATARIOS_MAIL WHERE TIPO_NOTIFICACION = ? AND ACTIVO = 1";
        $stmt = sqlsrv_query($this->connCentral, $sql, [$tipoNotificacion]);
        if ($stmt === false) {
            throw new RuntimeException('Error consultando RO_T_DESTINATARIOS_MAIL: ' . print_r(sqlsrv_errors(), true));
        }
        $emails = [];
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $emails[] = $r['EMAIL'];
        }

        $sql2 = "SELECT PROFILE_NAME, EMAIL_SUBJECT FROM RO_T_CONFIGURACION_MAIL WHERE TIPO_NOTIFICACION = ?";
        $stmt2 = sqlsrv_query($this->connCentral, $sql2, [$tipoNotificacion]);
        if ($stmt2 === false) {
            throw new RuntimeException('Error consultando RO_T_CONFIGURACION_MAIL: ' . print_r(sqlsrv_errors(), true));
        }
        $config = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)
            ?: ['PROFILE_NAME' => self::PROFILE_DEFAULT, 'EMAIL_SUBJECT' => 'Notificación de ' . $tipoNotificacion];

        return ['emails' => $emails, 'profile' => $config['PROFILE_NAME'], 'subject' => $config['EMAIL_SUBJECT']];
    }

    /**
     * Arma y manda el resumen mensual (supervisora + total premios) a los destinatarios
     * configurados en adminNotificaciones bajo TIPO_NOTIFICACION_RESUMEN_MENSUAL. Punto
     * único usado tanto por el botón manual (api/enviar_resumen_mensual.php) como por el
     * disparo automático cuando todas las supervisoras quedan "Controlado"
     * (api/marcar_controlado.php) — para no duplicar la lógica de armado + envío.
     *
     * @return string[] Los destinatarios a los que se mandó.
     * @throws RuntimeException si no hay destinatarios activos configurados.
     */
    public static function enviarResumenMensual(PremiosDB $db, string $desde, string $hasta): array
    {
        $supervisoras = $db->getSupervisoras();
        $resumen = $db->resumenPorSupervisora($supervisoras);
        $html = self::renderResumenMensual($resumen['filas'], $desde, $hasta);

        $mail = new self();
        $cfg = $mail->obtenerDestinatariosYConfig(self::TIPO_NOTIFICACION_RESUMEN_MENSUAL);
        if (!$cfg['emails']) {
            throw new RuntimeException('No hay destinatarios activos configurados para PREMIOS_RESUMEN_MENSUAL en el admin de notificaciones');
        }

        $mail->enviar($cfg['emails'], $cfg['subject'], $html, $cfg['profile']);
        return $cfg['emails'];
    }

    /* ─────────────────────────────────────────────────────────
     * Formateo (equivalente PHP de BIUtils.fmt usado en el front)
     * ───────────────────────────────────────────────────────── */

    private static function money(float $v): string
    {
        return '$' . number_format($v, 0, ',', '.');
    }

    private static function pct(?float $v): string
    {
        if ($v === null) return '—';
        return number_format($v * 100, 2, ',', '.') . '%';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private static function estiloBase(): string
    {
        return 'font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#1f2937;';
    }

    private static function estiloTabla(): string
    {
        return 'border-collapse:collapse;width:100%;margin:10px 0 20px;';
    }

    private static function estiloTh(): string
    {
        return 'background:#1a2340;color:#fff;padding:6px 10px;text-align:right;font-size:12px;';
    }

    private static function estiloTd(bool $num = true): string
    {
        return 'padding:5px 10px;border-bottom:1px solid #e5e7eb;' . ($num ? 'text-align:right;' : 'text-align:left;');
    }

    /* ─────────────────────────────────────────────────────────
     * Render — mail individual de una supervisora
     * ───────────────────────────────────────────────────────── */

    /** Verde si $valor supera $umbral (o >=0 cuando $umbral es null), rojo si no, vacío si $valor es null. */
    private static function colorSegunUmbral(?float $valor, ?float $umbral): string
    {
        if ($valor === null) return '';
        $supera = $umbral !== null ? $valor > $umbral : $valor >= 0;
        return $supera ? 'color:#16a34a;font-weight:600;' : 'color:#dc2626;';
    }

    /**
     * Tabla de sucursales acotada a las columnas "% Cumplimiento Obj. Venta" .. "% Cumpl.
     * Coach" (mismo tramo que se ve en la tabla de "Locales Propios" del dashboard) + el
     * total del premio — a pedido explícito: el mail NO incluye los montos de facturación/
     * objetivo en $ ni el desglose de premio por concepto (Objetivo Venta, Crecimiento,
     * etc.), solo estos indicadores y el total.
     *
     * @param array $filasSup     Retorno de PremiosDB::datosPropios($supervisora)
     * @param array $totalGeneral ['cumplimiento_obj','facturacion_var','ticket_promedio',
     *                            'pct_ticket_2do','pct_ticket_3er','pct_cumplimiento_cadena']
     *                            — TOTAL de TODA la cadena (todas las supervisoras + Ecommerce),
     *                            no el propio de esta supervisora — ver
     *                            PremiosDB::totalGeneralPropios(). Es la fila "Total" del mail,
     *                            a pedido del cliente (2026-09-02): mostrar acá el total de la
     *                            supervisora confundía, porque se leía como si fuera el objetivo.
     * @param array $benchmarks   ['ticket_marca','pct2_marca','pct3_marca'] para pintar en
     *                            verde/rojo cada celda de sucursal, igual criterio que los
     *                            badges de la UI
     */
    public static function renderDetalleSupervisora(
        PremiosDB $db,
        string $supervisora,
        array $filasSup,
        array $totalGeneral,
        array $benchmarks,
        float $totalPremios,
        string $desde,
        string $hasta
    ): string {
        $periodoTxt = date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));

        $filasSucursales = '';
        foreach ($filasSup as $f) {
            if ($f['casa_central']) continue; // no es un local real, ver PremiosDB::CASA_CENTRAL_NRO
            $cumpl = $f['sin_datos'] ? null : $db->cumplimientoObjVenta($f['imp_fact'], $f['imp_obj']);
            $var   = $f['sin_datos'] ? null : $db->facturacionVarPct($f['imp_fact'], $f['imp_fact_ant']);
            $ticketProm = $f['sin_datos'] ? null : $db->ticketPromedioEst($f['imp_fact'], $f['tickets']);
            $pct2 = ($f['sin_datos'] || $f['tickets'] <= 0) ? null : $f['tickets_2do_prod'] / $f['tickets'];
            $pct3 = ($f['sin_datos'] || $f['tickets'] <= 0) ? null : $f['tickets_3er_prod'] / $f['tickets'];

            $filasSucursales .= '<tr>'
                . '<td style="' . self::estiloTd(false) . '">' . self::esc($f['sucursal']) . '</td>'
                . '<td style="' . self::estiloTd() . self::colorSegunUmbral($cumpl, null) . '">' . self::pct($cumpl) . '</td>'
                . '<td style="' . self::estiloTd() . self::colorSegunUmbral($var, null) . '">' . self::pct($var) . '</td>'
                . '<td style="' . self::estiloTd() . self::colorSegunUmbral($ticketProm, $benchmarks['ticket_marca']) . '">' . ($ticketProm === null ? '—' : self::money($ticketProm)) . '</td>'
                . '<td style="' . self::estiloTd() . self::colorSegunUmbral($pct2, $benchmarks['pct2_marca']) . '">' . self::pct($pct2) . '</td>'
                . '<td style="' . self::estiloTd() . self::colorSegunUmbral($pct3, $benchmarks['pct3_marca']) . '">' . self::pct($pct3) . '</td>'
                . '<td style="' . self::estiloTd() . '">—</td>' // % Cumpl. Coach es de la supervisora, no por sucursal — igual criterio que la tabla del dashboard
                . '</tr>';
        }

        // Fila "Total": TOTAL GENERAL de toda la cadena (todas las supervisoras + Ecommerce,
        // ver PremiosDB::totalGeneralPropios()) — a pedido del cliente (2026-09-02), reemplaza
        // al total propio de esta supervisora, que se confundía con un objetivo/benchmark
        // cuando en realidad era su propio promedio de zona. Con esto, cada sucursal se compara
        // visualmente contra el resultado real de TODA la empresa, no contra sí misma.
        $filaTotal = '<tr>'
            . '<td style="padding:6px 10px;font-weight:700;background:#fffbeb;">Total</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;">' . self::pct($totalGeneral['cumplimiento_obj']) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;">' . self::pct($totalGeneral['facturacion_var']) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;">' . self::money($totalGeneral['ticket_promedio']) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;">' . self::pct($totalGeneral['pct_ticket_2do']) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;">' . self::pct($totalGeneral['pct_ticket_3er']) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;color:#92400e;">' . self::pct($totalGeneral['pct_cumplimiento_cadena']) . '</td>'
            . '</tr>';

        return '<html><body style="' . self::estiloBase() . '">'
            . '<h2 style="font-size:17px;margin:0 0 4px;">Detalle de Premios — ' . self::esc($supervisora) . '</h2>'
            . '<p style="color:#6b7280;margin:0 0 16px;">Período: ' . $periodoTxt . '</p>'

            . '<table style="' . self::estiloTabla() . '">'
            . '<tr>'
            . '<th style="' . self::estiloTh() . 'text-align:left;">Sucursal</th>'
            . '<th style="' . self::estiloTh() . '">% Cumplimiento Obj. Venta</th>'
            . '<th style="' . self::estiloTh() . '">Facturación C/IVA Var %</th>'
            . '<th style="' . self::estiloTh() . '">Ticket Promedio</th>'
            . '<th style="' . self::estiloTh() . '">% Tickets 2do Producto</th>'
            . '<th style="' . self::estiloTh() . '">% Tickets 3er Producto</th>'
            . '<th style="' . self::estiloTh() . '">% Cumpl. Coach</th>'
            . '</tr>'
            . $filasSucursales
            . $filaTotal
            . '</table>'

            . '<p style="font-size:15px;font-weight:700;margin:16px 0 0;">Total del Premio: '
            . '<span style="color:#92400e;">' . self::money($totalPremios) . '</span></p>'
            . '</body></html>';
    }

    /* ─────────────────────────────────────────────────────────
     * Render — resumen mensual (todas las supervisoras)
     * ───────────────────────────────────────────────────────── */

    /** @param array $filas Retorno de PremiosDB::resumenPorSupervisora()['filas'] */
    public static function renderResumenMensual(array $filas, string $desde, string $hasta): string
    {
        $periodoTxt = date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));
        $totalGeneral = array_sum(array_column($filas, 'total_premios'));

        $filasHtml = '';
        foreach ($filas as $f) {
            $filasHtml .= '<tr>'
                . '<td style="' . self::estiloTd(false) . '">' . self::esc($f['supervisora']) . '</td>'
                . '<td style="' . self::estiloTd() . 'font-weight:700;">' . self::money($f['total_premios']) . '</td>'
                . '</tr>';
        }

        return '<html><body style="' . self::estiloBase() . '">'
            . '<h2 style="font-size:17px;margin:0 0 4px;">Resumen Mensual de Premios Comercial</h2>'
            . '<p style="color:#6b7280;margin:0 0 16px;">Período: ' . $periodoTxt . '</p>'
            . '<table style="' . self::estiloTabla() . '">'
            . '<tr><th style="' . self::estiloTh() . 'text-align:left;">Supervisora</th><th style="' . self::estiloTh() . '">Total Premios</th></tr>'
            . $filasHtml
            . '<tr><td style="padding:6px 10px;font-weight:700;background:#fffbeb;">Total</td><td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;color:#92400e;">' . self::money($totalGeneral) . '</td></tr>'
            . '</table>'
            . '</body></html>';
    }

    /* ─────────────────────────────────────────────────────────
     * Render — avance de los primeros 15 días (parcial, NO es el cierre del mes)
     * ───────────────────────────────────────────────────────── */

    private static function avisoAvanceParcial(): string
    {
        return '<p style="background:#fef9c3;border:1px solid #fde68a;color:#854d0e;padding:8px 12px;'
            . 'border-radius:4px;font-size:12px;margin:0 0 14px;">'
            . '⚠️ Este es un AVANCE PARCIAL de venta (facturación acumulada a la fecha vs. el '
            . 'objetivo REAL del mes completo, no un premio calculado) — los locales propios de '
            . 'premios se cargan una sola vez al mes, al cierre.</p>';
    }

    /**
     * @param array $filasSucursal Retorno de AvanceQuincenalDB::avancePorSupervisora()[n]['sucursales']
     * @param ?string $comentarioJohanna Mensaje libre que Johanna escribe en el modal antes de mandar
     *        (ver api/enviar_avance_quincenal.php) — no se persiste en ningún lado, solo viaja en el
     *        cuerpo de este mail. Si viene vacío/null no se renderiza el bloque.
     * @param ?float $ticketPromedio,$pctTicket2do,$pctTicket3er,$pctCumplimientoCadena
     *        Retorno de AvanceQuincenalDB::avancePorSupervisora()[n] — mismos campos que la
     *        tabla de "Locales Propios" del dashboard, pero calculados sobre las tablas diarias
     *        del avance parcial (ver AvanceQuincenalDB::pctCumplimientoCoach()).
     * @param array $benchmarks ['ticket_marca','pct2_marca','pct3_marca'] — mismo array que
     *        AvanceQuincenalDB::avancePorSupervisora()[n]['benchmarks'], para pintar cada
     *        indicador en verde/rojo igual criterio que renderDetalleSupervisora().
     */
    public static function renderAvanceSupervisora(
        string $supervisora,
        array $filasSucursal,
        float $facturacionTotal,
        float $objetivoTotal,
        ?float $pctCumplimiento,
        string $desde,
        string $hasta,
        ?string $comentarioJohanna = null,
        ?float $pctVar = null,
        float $ticketPromedio = 0.0,
        float $pctTicket2do = 0.0,
        float $pctTicket3er = 0.0,
        float $pctCumplimientoCadena = 0.0,
        array $benchmarks = ['ticket_marca' => 0.0, 'pct2_marca' => 0.0, 'pct3_marca' => 0.0]
    ): string {
        $periodoTxt = date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta));

        $bloqueComentario = '';
        if ($comentarioJohanna !== null && trim($comentarioJohanna) !== '') {
            $bloqueComentario = '<div style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;'
                . 'padding:10px 14px;border-radius:4px;font-size:13px;margin:0 0 14px;">'
                . '<strong>💬 Mensaje de Johanna:</strong><br>'
                . nl2br(self::esc(trim($comentarioJohanna)))
                . '</div>';
        }

        $filasHtml = '';
        foreach ($filasSucursal as $f) {
            $pct = self::pctSeguro($f['facturacion'], $f['objetivo']);
            $var = self::pctVarSeguro($f['facturacion'], $f['facturacion_prev'] ?? 0.0);
            $tickets = $f['tickets'] ?? 0;
            $ticketProm = $tickets > 0 ? self::ticketPromedioEstSeguro($f['facturacion'], $tickets) : null;
            $pct2 = $tickets > 0 ? ($f['tickets_2do_prod'] ?? 0) / $tickets : null;
            $pct3 = $tickets > 0 ? ($f['tickets_3er_prod'] ?? 0) / $tickets : null;
            $filasHtml .= '<tr>'
                . '<td style="' . self::estiloTd(false) . '">' . self::esc($f['sucursal']) . '</td>'
                . '<td style="' . self::estiloTd() . '">' . self::money($f['facturacion']) . '</td>'
                . '<td style="' . self::estiloTd() . '">' . self::money($f['objetivo']) . '</td>'
                . '<td style="' . self::estiloTd() . self::colorSegunUmbral($pct, null) . '">' . self::pct($pct) . '</td>'
                . '<td style="' . self::estiloTd() . self::colorSegunUmbral($var, null) . '">' . self::pct($var) . '</td>'
                . '<td style="' . self::estiloTd() . self::colorSegunUmbral($ticketProm, $benchmarks['ticket_marca']) . '">' . ($ticketProm === null ? '—' : self::money($ticketProm)) . '</td>'
                . '<td style="' . self::estiloTd() . self::colorSegunUmbral($pct2, $benchmarks['pct2_marca']) . '">' . self::pct($pct2) . '</td>'
                . '<td style="' . self::estiloTd() . self::colorSegunUmbral($pct3, $benchmarks['pct3_marca']) . '">' . self::pct($pct3) . '</td>'
                . '<td style="' . self::estiloTd() . '">—</td>' // % Cumpl. Coach es de la supervisora, no por sucursal — igual criterio que renderDetalleSupervisora()
                . '</tr>';
        }

        return '<html><body style="' . self::estiloBase() . '">'
            . '<h2 style="font-size:17px;margin:0 0 4px;">Avance de Venta — ' . self::esc($supervisora) . '</h2>'
            . '<p style="color:#6b7280;margin:0 0 12px;">Días 1 al ' . (new DateTime($hasta))->format('j') . ' — Período: ' . $periodoTxt . '</p>'
            . $bloqueComentario
            . self::avisoAvanceParcial()
            . '<table style="' . self::estiloTabla() . '">'
            . '<tr>'
            . '<th style="' . self::estiloTh() . 'text-align:left;">Sucursal</th>'
            . '<th style="' . self::estiloTh() . '">Facturación</th>'
            . '<th style="' . self::estiloTh() . '">Objetivo del Mes</th>'
            . '<th style="' . self::estiloTh() . '">% Cumplimiento</th>'
            . '<th style="' . self::estiloTh() . '">Facturación Var %</th>'
            . '<th style="' . self::estiloTh() . '">Ticket Promedio</th>'
            . '<th style="' . self::estiloTh() . '">% Tickets 2do Producto</th>'
            . '<th style="' . self::estiloTh() . '">% Tickets 3er Producto</th>'
            . '<th style="' . self::estiloTh() . '">% Cumpl. Coach</th>'
            . '</tr>'
            . $filasHtml
            . '<tr>'
            . '<td style="padding:6px 10px;font-weight:700;background:#fffbeb;">Total</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;">' . self::money($facturacionTotal) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;">' . self::money($objetivoTotal) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;color:#92400e;">' . self::pct($pctCumplimiento) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;color:#92400e;">' . self::pct($pctVar) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;">' . self::money($ticketPromedio) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;">' . self::pct($pctTicket2do) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;">' . self::pct($pctTicket3er) . '</td>'
            . '<td style="padding:6px 10px;text-align:right;font-weight:700;background:#fffbeb;color:#92400e;">' . self::pct($pctCumplimientoCadena) . '</td>'
            . '</tr>'
            . '</table>'
            . '</body></html>';
    }

    /** Mismo criterio que AvanceQuincenalDB::ticketPromedioEst() — para pintar el Ticket Promedio por sucursal. */
    private static function ticketPromedioEstSeguro(float $fact, int $tickets): float
    {
        if ($tickets <= 0) return 0.0;
        return ceil(($fact / $tickets) / 100) * 100;
    }

    /** pctSeguro: igual fórmula que AvanceQuincenalDB::pctCumplimiento(), para las celdas por sucursal. */
    private static function pctSeguro(float $fact, float $obj): ?float
    {
        if ($obj <= 0) return $fact > 0 ? null : -1.0;
        return $fact / $obj - 1;
    }

    /** pctVarSeguro: igual fórmula que AvanceQuincenalDB::pctVar(), para las celdas por sucursal. */
    private static function pctVarSeguro(float $fact, float $factAnt): ?float
    {
        if ($factAnt <= 0) return $fact > 0 ? null : -1.0;
        return $fact / $factAnt - 1;
    }
}
