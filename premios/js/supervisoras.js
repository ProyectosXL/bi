/**
 * /bi/premios/js/supervisoras.js
 * Vista "Premios Supervisoras": cards de premio total + tabla única de detalle
 * (Locales Propios + Franquicias) por supervisora.
 */
const PremiosSupervisoras = (() => {

    const { $, fmt, updatePeriodoLabel, setSupervisoraOptions, apiFetch, actualizarUltimaActualizacion } = Premios;

    let _lastResumen = [];
    let _lastPctCadenaTotal = null;
    let _puedeGestionar = false;
    let _mesUnico = null;

    async function loadFiltros() {
        const data = await Premios.apiFetch('filtros.php');
        setSupervisoraOptions(data.supervisoras ?? []);
    }

    function renderHeroCards(supervisoras) {
        const wrap = $('hero-cards-wrap');
        if (!wrap) return;
        wrap.innerHTML = supervisoras.map((s, i) => `
            <div class="premio-hero-card c${i % 7}">
                <div class="premio-hero-nombre">${s.supervisora}</div>
                <div class="premio-hero-valor">${fmt.money(s.total_premios)}</div>
                <div class="premio-hero-label">Total Premios</div>
            </div>`).join('');
    }

    // Columna "Acciones" (solo visible para roles GERENCIA/SUPERVISION, ver puede_gestionar
    // en api/resumen.php): botón de mail individual + toggle de estado "Controlado".
    function accionesCellHTML(s) {
        if (!_puedeGestionar) return '';
        const controlado = s.controlado?.controlado === true;
        const tituloControlado = s.controlado?.usuario
            ? `${controlado ? 'Controlado' : 'Reabierto'} por ${s.controlado.usuario} el ${s.controlado.fecha_control}`
            : '';
        const disabledControlado = _mesUnico ? '' : 'disabled title="Solo disponible para un período de un solo mes"';
        return `<td class="td-acciones">
            <button class="btn-accion-fila btn-enviar-mail" data-supervisora="${s.supervisora}" title="Enviar mail a la supervisora">
                <i class="bi bi-envelope"></i>
            </button>
            <button class="btn-accion-fila btn-controlado ${controlado ? 'is-controlado' : ''}"
                    data-supervisora="${s.supervisora}" ${disabledControlado} title="${tituloControlado}">
                <i class="bi ${controlado ? 'bi-check-circle-fill' : 'bi-circle'}"></i> ${controlado ? 'Controlado' : 'Pendiente'}
            </button>
        </td>`;
    }

    // Encabezados abreviados a pedido del cliente (headers lo más cortos posible, con
    // tooltip para el significado completo): "C. PP." = Canal Propios, "C. FQ." = Canal
    // Franquicias, "T." = Total del canal, "Obj." = Objetivo, "Tkt." = Ticket.
    function th(texto, titulo, attrsExtra = '') {
        return `<th title="${titulo}" ${attrsExtra}>${texto}</th>`;
    }

    // Objetivo Venta / Objetivo Crecimiento: Locales Propios | Franquicias | Total (premio propios + premio franquicias).
    // Tickets (Promedio/2do/3er): Locales | Total — no aplican a franquicias.
    // % Cumpl. Cadena (última columna): de los 3 indicadores secundarios (Ticket Promedio,
    // Ticket 2do y 3er Producto) de cada local PROPIO real de esa supervisora, cuántos
    // llegan a su benchmark de marca — 5 locales = 15 indicadores en la base del cálculo
    // (ver PremiosDB::pctCumplimientoCadenaIndicadores). Distinto del "cant" de premio de
    // Objetivo Venta/Crecimiento, que es un conteo a nivel empresa.
    function renderTabla(supervisoras, pctCadenaTotal) {
        const wrap = $('tabla-resumen-wrap');
        if (!wrap) return;

        const filas = supervisoras.map(s => {
            const p = s.propios;
            const f = s.franquicias;
            return `<tr>
                <td>${s.supervisora}</td>
                <td class="td-num">${p.venta.cant}</td>
                <td class="td-num">${f.venta.cant}</td>
                <td class="td-num td-premio">${fmt.money(p.venta.premio + f.venta.premio)}</td>
                <td class="td-num">${p.crecimiento.cant}</td>
                <td class="td-num">${f.crecimiento.cant}</td>
                <td class="td-num td-premio">${fmt.money(p.crecimiento.premio + f.crecimiento.premio)}</td>
                <td class="td-num">${p.ticket_promedio.cant}</td>
                <td class="td-num td-premio">${fmt.money(p.ticket_promedio.premio)}</td>
                <td class="td-num">${p.ticket_2do.cant}</td>
                <td class="td-num td-premio">${fmt.money(p.ticket_2do.premio)}</td>
                <td class="td-num">${p.ticket_3er.cant}</td>
                <td class="td-num td-premio">${fmt.money(p.ticket_3er.premio)}</td>
                <td class="td-num td-total"><strong>${fmt.money(s.total_premios)}</strong></td>
                <td class="td-num">${fmt.pct(s.pct_cumplimiento_cadena)}</td>
                ${accionesCellHTML(s)}
            </tr>`;
        }).join('');

        const totalGeneral = supervisoras.reduce((s, r) => s + r.total_premios, 0);
        const thAcciones = _puedeGestionar ? '<th rowspan="2" class="th-center">Acciones</th>' : '';
        const tdAccionesFoot = _puedeGestionar ? '<td></td>' : '';

        wrap.innerHTML = `
            <table class="premios-table">
                <thead>
                    <tr>
                        ${th('Supervisora', 'Supervisora', 'rowspan="2"')}
                        ${th('Obj. Vta.', 'Objetivo de Venta', 'colspan="3" class="th-center"')}
                        ${th('Obj. Crec.', 'Objetivo de Crecimiento', 'colspan="3" class="th-center"')}
                        ${th('Tkt. Prom.', 'Premio por Ticket Promedio', 'colspan="2" class="th-center"')}
                        ${th('Tkt. 2°P.', 'Premio por Ticket 2do Producto', 'colspan="2" class="th-center"')}
                        ${th('Tkt. 3°P.', 'Premio por Ticket 3er Producto', 'colspan="2" class="th-center"')}
                        ${th('Tot. Premios', 'Total de Premios (suma de todos los conceptos)', 'rowspan="2" class="th-num th-total"')}
                        ${th('%Cumpl.Cad.', '% Cumplimiento de Cadena: indicadores de Ticket Promedio, 2do y 3er Producto que llegan al benchmark de marca, sobre el total de indicadores de la cadena', 'rowspan="2" class="th-num"')}
                        ${thAcciones}
                    </tr>
                    <tr>
                        ${th('C. PP.', 'Canal Propios', 'class="th-center"')}${th('C. FQ.', 'Canal Franquicias', 'class="th-center"')}${th('T.', 'Total (Canal Propios + Canal Franquicias)', 'class="th-center th-premio"')}
                        ${th('C. PP.', 'Canal Propios', 'class="th-center"')}${th('C. FQ.', 'Canal Franquicias', 'class="th-center"')}${th('T.', 'Total (Canal Propios + Canal Franquicias)', 'class="th-center th-premio"')}
                        ${th('C. PP.', 'Canal Propios (no aplica a Franquicias)', 'class="th-center"')}${th('T.', 'Importe total del premio', 'class="th-center th-premio"')}
                        ${th('C. PP.', 'Canal Propios (no aplica a Franquicias)', 'class="th-center"')}${th('T.', 'Importe total del premio', 'class="th-center th-premio"')}
                        ${th('C. PP.', 'Canal Propios (no aplica a Franquicias)', 'class="th-center"')}${th('T.', 'Importe total del premio', 'class="th-center th-premio"')}
                    </tr>
                </thead>
                <tbody>${filas}</tbody>
                <tfoot>
                    <tr class="totales">
                        <td>Total</td><td colspan="12"></td>
                        <td class="td-num td-total">${fmt.money(totalGeneral)}</td>
                        <td class="td-num td-total">${fmt.pct(pctCadenaTotal)}</td>
                        ${tdAccionesFoot}
                    </tr>
                </tfoot>
            </table>`;

        if (_puedeGestionar) {
            wrap.querySelectorAll('.btn-enviar-mail').forEach(btn => {
                btn.addEventListener('click', () => enviarMailSupervisora(btn));
            });
            wrap.querySelectorAll('.btn-controlado').forEach(btn => {
                btn.addEventListener('click', () => toggleControlado(btn));
            });
        }
    }

    async function enviarMailSupervisora(btn) {
        const supervisora = btn.dataset.supervisora;
        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
        try {
            const res = await Premios.apiPost('enviar_mail_supervisora.php', { supervisora });
            btn.innerHTML = '<i class="bi bi-check2"></i>';
            setTimeout(() => { btn.innerHTML = original; btn.disabled = false; }, 1500);
            await Premios.alertModal(`Mail enviado con éxito a <strong>${supervisora}</strong> (${res.email}).`, { tono: 'exito' });
        } catch (err) {
            btn.innerHTML = original;
            btn.disabled = false;
            await Premios.alertModal(`No se pudo enviar el mail de ${supervisora}: ${err.message}`, { tono: 'error' });
        }
    }

    async function toggleControlado(btn) {
        const supervisora = btn.dataset.supervisora;
        const nuevoEstado = !btn.classList.contains('is-controlado');
        btn.disabled = true;
        try {
            const res = await Premios.apiPost('marcar_controlado.php', { supervisora, controlado: nuevoEstado });
            await load(); // repinta con el estado persistido (usuario/fecha incluidos)
            if (res.resumen_auto_enviado) {
                await Premios.alertModal('Todas las supervisoras quedaron <strong>Controlado</strong> — se envió automáticamente el resumen mensual a RRHH.', { tono: 'exito', titulo: 'Resumen mensual enviado' });
            } else if (res.resumen_auto_error) {
                await Premios.alertModal(`Todas las supervisoras quedaron Controlado, pero el envío automático del resumen mensual falló: ${res.resumen_auto_error}. Podés usar el botón "Enviar resumen mensual" para reintentarlo.`, { tono: 'error' });
            }
        } catch (err) {
            btn.disabled = false;
            await Premios.alertModal(`No se pudo actualizar el estado de ${supervisora}: ${err.message}`, { tono: 'error' });
        }
    }

    function exportarResumen() {
        if (!_lastResumen.length || typeof ExcelExporter === 'undefined') return;
        ExcelExporter.export({
            title  : 'Premios por Supervisora',
            headers: ['Supervisora',
                'Locales Propios (Venta)', 'Franquicias (Venta)', 'Total Obj. Venta',
                'Locales Propios (Crec.)', 'Franquicias (Crec.)', 'Total Obj. Crecimiento',
                'Locales (Ticket Prom.)', 'Total Ticket Promedio',
                'Locales (2do Prod.)', 'Total Ticket 2do Prod.',
                'Locales (3er Prod.)', 'Total Ticket 3er Prod.',
                'Total premios', '% Cumpl. Cadena'],
            rows: _lastResumen.map(s => [
                s.supervisora,
                s.propios.venta.cant, s.franquicias.venta.cant, s.propios.venta.premio + s.franquicias.venta.premio,
                s.propios.crecimiento.cant, s.franquicias.crecimiento.cant, s.propios.crecimiento.premio + s.franquicias.crecimiento.premio,
                s.propios.ticket_promedio.cant, s.propios.ticket_promedio.premio,
                s.propios.ticket_2do.cant, s.propios.ticket_2do.premio,
                s.propios.ticket_3er.cant, s.propios.ticket_3er.premio,
                s.total_premios, s.pct_cumplimiento_cadena,
            ]),
            colFormats: ['text', 'num', 'num', 'money', 'num', 'num', 'money', 'num', 'money', 'num', 'money', 'num', 'money', 'money', 'pct'],
            filename: 'premios_supervisoras',
        });
    }

    async function enviarResumenMensual(btn) {
        const confirma = await Premios.confirmModal('¿Enviar el resumen mensual de premios a los destinatarios configurados?');
        if (!confirma) return;
        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Enviando…';
        try {
            const res = await Premios.apiPost('enviar_resumen_mensual.php');
            await Premios.alertModal(`Resumen enviado con éxito a: ${res.destinatarios.join(', ')}`, { tono: 'exito' });
        } catch (err) {
            await Premios.alertModal(`No se pudo enviar el resumen mensual: ${err.message}`, { tono: 'error' });
        } finally {
            btn.innerHTML = original;
            btn.disabled = false;
        }
    }

    /* ── Drag & drop nativo sobre las filas del modal de orden ──
       Reordena el DOM en vivo al soltar; el guardado real es explícito (botón "Guardar
       orden") para no disparar un POST por cada micro-arrastre. */
    function habilitarDragOrden(listaEl) {
        let arrastrada = null;
        listaEl.querySelectorAll('.modal-config-fila').forEach(fila => {
            fila.addEventListener('dragstart', () => {
                arrastrada = fila;
                fila.classList.add('dragging');
            });
            fila.addEventListener('dragend', () => {
                fila.classList.remove('dragging');
                arrastrada = null;
            });
            fila.addEventListener('dragover', (e) => {
                e.preventDefault();
                if (!arrastrada || arrastrada === fila) return;
                const rect = fila.getBoundingClientRect();
                const despuesDelPuntero = (e.clientY - rect.top) > rect.height / 2;
                fila.parentNode.insertBefore(arrastrada, despuesDelPuntero ? fila.nextSibling : fila);
            });
        });
    }

    async function guardarConfigModal(modal, btnGuardar) {
        const items = Array.from(modal.querySelectorAll('.modal-config-fila')).map(fila => ({
            nombre: fila.dataset.supervisora,
            visible: fila.querySelector('.modal-config-check').checked,
        }));
        btnGuardar.disabled = true;
        const original = btnGuardar.innerHTML;
        btnGuardar.innerHTML = '<i class="bi bi-hourglass-split"></i> Guardando…';
        try {
            await Premios.apiPost('supervisoras_orden.php', { items });
            modal.classList.remove('visible');
            await load(); // repinta cards/tabla con el nuevo orden/visibilidad
            await Premios.alertModal('Configuración guardada con éxito.', { tono: 'exito' });
        } catch (err) {
            await Premios.alertModal(`No se pudo guardar: ${err.message}`, { tono: 'error' });
        } finally {
            btnGuardar.innerHTML = original;
            btnGuardar.disabled = false;
        }
    }

    async function renderModalOrdenSupervisoras() {
        let modal = $('modal-orden-supervisoras');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'modal-orden-supervisoras';
            modal.className = 'modal-emails-overlay';
            document.body.appendChild(modal);
        }
        modal.innerHTML = `<div class="modal-emails-box">
            <div class="modal-emails-header">
                <span>Configuración de supervisoras</span>
                <button class="modal-emails-cerrar" id="modal-orden-cerrar"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="modal-emails-body" id="modal-orden-body">
                <div class="premios-loading">Cargando…</div>
            </div>
        </div>`;
        modal.classList.add('visible');
        modal.querySelector('#modal-orden-cerrar').onclick = () => modal.classList.remove('visible');
        modal.onclick = (e) => { if (e.target === modal) modal.classList.remove('visible'); };

        try {
            const res = await fetch('api/supervisoras_orden.php');
            const data = await res.json();
            if (!data.ok) throw new Error(data.error);
            const body = modal.querySelector('#modal-orden-body');
            body.innerHTML = `
                <p class="modal-config-hint">Arrastrá para reordenar y destildá para ocultar del dashboard
                (ej. Julieta Dalmeida). El mail es de solo lectura — se edita directo en RO_T_SUPERVISORAS_COMERCIAL.</p>
                <div id="modal-orden-lista">
                    ${data.supervisoras.map(s => `
                        <div class="modal-config-fila ${s.visible ? '' : 'oculta'}" draggable="true" data-supervisora="${s.nombre}">
                            <i class="bi bi-grip-vertical modal-config-handle"></i>
                            <input type="checkbox" class="modal-config-check" ${s.visible ? 'checked' : ''} title="Mostrar en el dashboard">
                            <span class="modal-emails-nombre">${s.nombre}</span>
                            <span class="modal-config-mail ${s.mail ? '' : 'sin-mail'}">${s.mail ?? 'Sin mail configurado'}</span>
                        </div>`).join('')}
                </div>
                <div class="modal-config-actions">
                    <button class="premios-alert-btn premios-alert-btn-ok" id="btn-guardar-orden">
                        <i class="bi bi-save"></i> Guardar
                    </button>
                </div>`;
            habilitarDragOrden(body.querySelector('#modal-orden-lista'));
            body.querySelectorAll('.modal-config-check').forEach(chk => {
                chk.addEventListener('change', () => chk.closest('.modal-config-fila').classList.toggle('oculta', !chk.checked));
            });
            body.querySelector('#btn-guardar-orden').addEventListener('click', () => guardarConfigModal(modal, body.querySelector('#btn-guardar-orden')));
        } catch (err) {
            modal.querySelector('#modal-orden-body').innerHTML = `<p class="text-red">${err.message}</p>`;
        }
    }

    async function load() {
        const data = await apiFetch('resumen.php');
        updatePeriodoLabel(data.periodo);
        actualizarUltimaActualizacion(data);

        _lastResumen = data.supervisoras ?? [];
        _lastPctCadenaTotal = data.pct_cumplimiento_cadena_total ?? null;
        _puedeGestionar = data.puede_gestionar ?? false;
        _mesUnico = data.mes_unico ?? null;

        renderHeroCards(_lastResumen);
        renderTabla(_lastResumen, _lastPctCadenaTotal);

        const btn = $('btn-export-resumen');
        if (btn) btn.onclick = exportarResumen;

        const btnResumenMensual = $('btn-resumen-mensual');
        if (btnResumenMensual) {
            btnResumenMensual.style.display = _puedeGestionar ? 'flex' : 'none';
            btnResumenMensual.disabled = !_mesUnico;
            btnResumenMensual.title = _mesUnico ? '' : 'Solo disponible para un período de un solo mes';
            btnResumenMensual.onclick = () => enviarResumenMensual(btnResumenMensual);
        }
        const btnOrden = $('btn-orden-supervisoras');
        if (btnOrden) {
            btnOrden.style.display = _puedeGestionar ? 'flex' : 'none';
            btnOrden.onclick = renderModalOrdenSupervisoras;
        }
    }

    return { loadFiltros, load };
})();
