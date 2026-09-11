/**
 * /bi/premios/js/ecommerce.js
 * Vista "Premios Ecommerce": premios del personal del área, concepto por concepto,
 * según el cumplimiento de sus objetivos (escalón fijo — ver PremiosEcommerceDB).
 *
 * Incluye los dos modales de gestión (solo para GERENCIA/SUPERVISION):
 *   - "Cargar órdenes y conversión": los KPIs que no existen en ninguna tabla del BI.
 *   - "Escalas de premios": los importes de cada tramo (las "PAUTAS").
 */
const PremiosEcommerce = (() => {

    const {
        $, fmt, updatePeriodoLabel, buildQS, apiPost, badgeCellHTML,
        actualizarUltimaActualizacion, alertModal,
    } = Premios;

    let _lastPersonas = [];
    let _lastTotal    = 0;
    let _lastSesiones = {};

    const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                   'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    /** '2026-07-31' → 'Julio 2026' (los meses se identifican por su último día). */
    function nombreMes(finDeMes) {
        const [anio, mes] = finDeMes.split('-');
        return `${MESES[Number(mes) - 1]} ${anio}`;
    }

    /* ── Formato ─────────────────────────────────────────────────────────────
       La tasa de conversión está en PUNTOS DE PORCENTAJE (0,83 = 0,83 %), así que va con
       fmt.num(v, 2) + ' %' y NO con fmt.pct(), que multiplica por 100 y mostraría "83,0 %".
       El % de cumplimiento sí es un ratio (1,052 = 105,2 %) y va con fmt.pct(). */
    function fmtMetrica(metrica, v) {
        if (v === null || v === undefined) return '—';
        if (metrica === 'FACTURACION') return fmt.money(v);
        if (metrica === 'ORDENES')     return fmt.num(v);
        return fmt.num(v, 2) + ' %';
    }

    function fmtUmbral(tipoUmbral, v) {
        if (v === null || v === undefined) return '—';
        return tipoUmbral === 'PCT_CUMPLIMIENTO' ? fmt.pct(v) : fmt.num(v, 2) + ' %';
    }

    /* ── Avisos arriba de la pestaña ───────────────────────────────────────── */
    function renderBanners(data) {
        const wrap = $('ecom-banners-wrap');
        if (!wrap) return;

        const avisos = [];
        if (data.periodo_parcial) {
            avisos.push(['aviso', 'bi-hourglass-split',
                `<strong>Avance parcial del mes.</strong> El objetivo de facturación es del mes
                 COMPLETO, así que el % de cumplimiento todavía no es comparable — para liquidar,
                 elegí un período que cubra meses cerrados.`]);
        }
        if (data.kpis_faltantes?.length) {
            const detalle = data.kpis_faltantes
                .map(k => `${nombreMes(k.mes)} (${k.canal}: ${k.campos.join(', ')})`)
                .join(' · ');
            avisos.push(['falta', 'bi-exclamation-triangle-fill',
                `<strong>Faltan KPIs de carga manual.</strong> Los conceptos que dependen de estos
                 datos figuran como "falta carga", no como incumplidos: ${detalle}`]);
        }
        if (data.tasa_estimada) {
            avisos.push(['aviso', 'bi-info-circle-fill',
                `<strong>Tasa de conversión estimada.</strong> El período abarca varios meses y no
                 hay sesiones cargadas, así que se promediaron las tasas de cada mes. Cargando las
                 sesiones se recalcula correctamente (órdenes totales ÷ sesiones totales).`]);
        }

        wrap.innerHTML = avisos.map(([tono, icono, texto]) => `
            <div class="ecom-banner ecom-banner-${tono}">
                <i class="bi ${icono}"></i><div>${texto}</div>
            </div>`).join('');
    }

    /* ── Cards de premio por persona + total del área ──────────────────────── */
    function renderHero(personas, totalGeneral) {
        const wrap = $('hero-ecommerce-wrap');
        if (!wrap) return;
        if (!personas.length) {
            wrap.innerHTML = `<div class="premios-loading">No hay personas configuradas en
                BI_T_PREMIOS_ECOM_PERSONAS.</div>`;
            return;
        }

        const cards = personas.map((p, i) => `
            <div class="premio-hero-card c${i % 7}">
                <div class="premio-hero-nombre">${p.nombre}</div>
                <div class="premio-hero-valor">${fmt.money(p.total_premio)}</div>
                <div class="premio-hero-label">Total premios del período</div>
            </div>`);

        // El total va con su propia clase (no un c<N> del ciclo de colores) para que no se
        // confunda con una persona más si mañana se suman integrantes al área.
        cards.push(`
            <div class="premio-hero-card premio-hero-total">
                <div class="premio-hero-nombre">Total área Ecommerce</div>
                <div class="premio-hero-valor">${fmt.money(totalGeneral)}</div>
                <div class="premio-hero-label">Suma de todas las personas</div>
            </div>`);

        wrap.innerHTML = cards.join('');
    }

    /* ── Tabla de detalle ──────────────────────────────────────────────────── */
    /**
     * Cabecera del bloque de una persona: solo el nombre, a todo el ancho. El total va en
     * una fila aparte DEBAJO de sus conceptos (ver filaTotalPersonaHTML) y no acá: tener un
     * importe en la línea del nombre y otro en la última fila del bloque se prestaba a
     * confundir cuál era el total de la persona.
     */
    function filaCabeceraPersonaHTML(p) {
        return `<tr class="row-supervisora"><td colspan="7">${p.nombre}</td></tr>`;
    }

    /**
     * Cierre del bloque: el premio total de esa persona. Dice solo "Total" —repetir el
     * nombre acá hacía que "Total Agustina" quedara pegado a la cabecera "Vanesa Di Feo" y
     * costara ver dónde terminaba un bloque; el marco del <tbody> ya lo atribuye.
     * Las columnas de objetivo/real/cumplimiento quedan vacías a propósito: sumarlas
     * mezclaría pesos con órdenes y con tasa de conversión.
     */
    function filaTotalPersonaHTML(p) {
        return `<tr class="row-total-persona">
            <td>Total</td>
            <td></td>
            <td class="td-num"></td>
            <td class="td-num"></td>
            <td class="td-num"></td>
            <td class="td-num"></td>
            <td class="td-num td-premio">${fmt.money(p.total_premio)}</td>
        </tr>`;
    }

    /**
     * Los conceptos de umbral ABSOLUTO (la tasa de conversión) no tienen un objetivo cargado:
     * su escala son valores de tasa (0,90 / 0,80 / 0,70…) y el objetivo de ese mes es el
     * escalón que efectivamente alcanzaron. Se muestra ese, a pedido del usuario
     * (2026-09-11) — antes decía "Según escala" y antes de eso un "—" que hacía pensar que
     * faltaba cargar algo.
     *
     * Consecuencia: en estas filas Objetivo y Tramo muestran el mismo valor (el tramo
     * alcanzado), y si no se alcanza ningún escalón la celda vuelve a quedar vacía.
     */
    function celdaObjetivoHTML(c) {
        if (c.tipo_umbral === 'VALOR_ABSOLUTO') {
            const alcanzado = fmtUmbral(c.tipo_umbral, c.tramo_umbral);
            const ayuda = c.tramo_umbral === null || c.tramo_umbral === undefined
                ? 'No alcanzó ningún escalón de la escala de este concepto.'
                : 'Escalón de la escala que alcanzó. Este concepto no se mide contra un objetivo cargado: su escala son valores absolutos de tasa, editables en «Escalas de premios».';
            return `<span class="ecom-objetivo-escala" title="${ayuda}">${alcanzado}</span>`;
        }
        return fmtMetrica(c.metrica, c.objetivo);
    }

    /**
     * Las sesiones no entran en el cálculo (solo ponderan la tasa en períodos de varios
     * meses), así que no llevan columna propia — pero se muestran junto a la tasa como
     * contexto, para que quien las carga las vea reflejadas en algún lado.
     */
    function sufijoSesiones(c) {
        if (c.metrica !== 'TASA_CONVERSION' || !c.canal) return '';
        const s = _lastSesiones[c.canal];
        if (!s) return '';
        return ` <span class="ecom-sesiones" title="Sesiones cargadas para el período en ${c.canal}. No afectan el premio: solo ponderan la tasa cuando el período abarca varios meses.">· ${fmt.num(s)} sesiones</span>`;
    }

    function filaConceptoHTML(c) {
        const cls = c.sin_dato ? 'row-sin-datos' : '';
        const titulo = c.sin_dato
            ? ' title="Falta cargar el dato de este concepto para algún mes del período — no es un incumplimiento"'
            : '';
        return `<tr class="${cls}"${titulo}>
            <td class="td-sucursal">${c.concepto}</td>
            <td>${c.canal ?? 'VTEX + ML'}</td>
            <td class="td-num">${celdaObjetivoHTML(c)}</td>
            <td class="td-num">${fmtMetrica(c.metrica, c.real)}${sufijoSesiones(c)}</td>
            ${badgeCellHTML(c.pct_cumplimiento, 1, fmt.pct(c.pct_cumplimiento), {
                inclusive     : true,
                titulo        : 'Alcanza el 100 % del objetivo',
                tituloNoCumple: 'No alcanza el 100 % del objetivo',
            })}
            <td class="td-num">${fmtUmbral(c.tipo_umbral, c.tramo_umbral)}</td>
            <td class="td-num td-premio">${fmt.money(c.premio)}</td>
        </tr>`;
    }

    function renderTabla(personas, totalGeneral) {
        const wrap = $('tabla-ecommerce-wrap');
        if (!wrap) return;

        // Cada persona en su propio <tbody> para poder enmarcarla (ver premios.css), con un
        // <tbody> espaciador entre bloques que hace de margen. Sigue siendo UNA sola tabla a
        // propósito: si cada persona tuviera su tabla, las columnas dejarían de alinearse
        // entre bloques y comparar un premio contra otro se volvería incómodo.
        const separador = '<tbody class="ecom-espacio"><tr><td colspan="7"></td></tr></tbody>';
        const cuerpo = personas.map(p => `<tbody class="ecom-bloque">
                ${filaCabeceraPersonaHTML(p)}
                ${p.conceptos.map(filaConceptoHTML).join('')}
                ${filaTotalPersonaHTML(p)}
            </tbody>`).join(separador) + (personas.length ? separador : '');

        wrap.innerHTML = `
            <table class="premios-table">
                <thead>
                    <tr>
                        <th>Persona / Concepto</th>
                        <th>Canal</th>
                        <th class="th-num" title="Objetivo del período. La facturación sale de FP_ObjetivosFinales (mensual); el de órdenes es de carga manual. La tasa de conversión no tiene objetivo: su escala son valores absolutos.">Objetivo</th>
                        <th class="th-num" title="Valor real del período">Real</th>
                        <th class="th-num" title="Real ÷ Objetivo. Vacío en los conceptos cuya escala es de valor absoluto (tasa de conversión).">% Cumpl.</th>
                        <th class="th-num" title="Tramo de la escala efectivamente alcanzado. Se paga el importe completo de ese tramo, sin prorratear.">Tramo</th>
                        <th class="th-num th-premio">Premio</th>
                    </tr>
                </thead>
                ${cuerpo}
                <tfoot>
                    <tr class="totales">
                        <td>Total área Ecommerce</td>
                        <td>—</td>
                        <td class="td-num">—</td>
                        <td class="td-num">—</td>
                        <td class="td-num">—</td>
                        <td class="td-num">—</td>
                        <td class="td-num td-total">${fmt.money(totalGeneral)}</td>
                    </tr>
                </tfoot>
            </table>`;
    }

    /* ── Export a Excel ────────────────────────────────────────────────────── */
    function exportar() {
        if (!_lastPersonas.length || typeof ExcelExporter === 'undefined') return;
        const rows = [];
        // Mismo orden que la tabla en pantalla: nombre, sus conceptos, y recién ahí su total.
        _lastPersonas.forEach(p => {
            rows.push([p.nombre, null, null, null, null, null, null]);
            p.conceptos.forEach(c => rows.push([
                '  ' + c.concepto,
                c.canal ?? 'VTEX + ML',
                c.objetivo,
                c.real,
                c.pct_cumplimiento,
                c.tramo_umbral,
                c.premio,
            ]));
            rows.push([`Total ${p.nombre}`, null, null, null, null, null, p.total_premio]);
        });
        ExcelExporter.export({
            title  : 'Premios Ecommerce — Cumplimiento de objetivos y premio por concepto',
            headers: ['Persona / Concepto', 'Canal', 'Objetivo', 'Real', '% Cumplimiento', 'Tramo', 'Premio'],
            rows,
            totalsRow: ['Total área Ecommerce', null, null, null, null, null, _lastTotal],
            // Objetivo/Real/Tramo van SIN formato: en esas columnas convive facturación en
            // pesos con órdenes y tasa de conversión, y un formato de moneda mostraría
            // "$ 2.894" para las órdenes. Se exporta el número crudo.
            colFormats: ['text', 'text', null, null, 'pct', null, 'money'],
            filename: 'premios_ecommerce',
        });
    }

    /* ═══════════════════════════════════════════════════════════════════════
       Modal: carga manual de órdenes / sesiones / tasa de conversión
       ═══════════════════════════════════════════════════════════════════════ */

    function inputNum(canal, campo, valor, step, placeholder = '') {
        const v = (valor === null || valor === undefined) ? '' : valor;
        return `<input type="number" class="ecom-kpi-input" min="0" step="${step}"
                       data-canal="${canal}" data-campo="${campo}" value="${v}"
                       placeholder="${placeholder}">`;
    }

    /**
     * Control de tipeo de la tasa: muestra la que se deduciría de las órdenes de Tango
     * sobre las sesiones cargadas. NO es la que se liquida (la que vale es la tipeada, del
     * panel de VTEX — ver README), pero sirve para darse cuenta de un error de magnitud:
     * si alguien carga "83" queriendo decir 0,83 %, la referencia queda a dos órdenes de
     * magnitud de distancia y salta a la vista.
     */
    function actualizarTasaControl(box, canal, ordenesTango) {
        const el = box.querySelector(`.ecom-kpi-control[data-canal="${canal}"]`);
        if (!el) return;
        const inp = box.querySelector(`.ecom-kpi-input[data-canal="${canal}"][data-campo="sesiones"]`);
        const sesiones = inp && inp.value !== '' ? Number(inp.value) : null;
        el.textContent = (ordenesTango > 0 && sesiones > 0)
            ? `Referencia: ${fmt.num(ordenesTango)} órdenes de Tango ÷ ${fmt.num(sesiones)} sesiones = ${fmt.num(ordenesTango / sesiones * 100, 2)} %`
            : '';
    }

    function bloqueCanalHTML(c) {
        // A ML no se le mide hoy ni conversión ni objetivo de órdenes, pero el form es el
        // mismo para los dos canales: si mañana se le miden, ya se puede cargar sin tocar nada.
        return `<div class="ecom-kpi-canal" data-canal="${c.canal}" data-ordenes-tango="${c.ordenes_tango}">
            <div class="ecom-kpi-canal-titulo">${c.canal}</div>
            <div class="ecom-kpi-grid">
                <label>Órdenes <span class="ecom-kpi-hint">(de Tango, no se carga)</span></label>
                <div class="ecom-kpi-readonly">${fmt.num(c.ordenes_tango)}</div>
                <label>Objetivo de órdenes</label>    ${inputNum(c.canal, 'objetivo_ordenes', c.objetivo_ordenes, '1')}
                <label>Sesiones <span class="ecom-kpi-hint">(opcional, no cambia el premio)</span></label>
                                                      ${inputNum(c.canal, 'sesiones', c.sesiones, '1')}
                <label>Tasa de conversión (%)</label> ${inputNum(c.canal, 'tasa_conversion', c.tasa_conversion, '0.01', 'ej. 0,83')}
            </div>
            <div class="ecom-kpi-control" data-canal="${c.canal}"></div>
            ${c.fecha_actualizacion
                ? `<div class="ecom-kpi-meta">Última carga: ${c.fecha_actualizacion} — ${c.actualizado_por ?? '—'}</div>`
                : `<div class="ecom-kpi-meta">Sin cargar todavía para este mes.</div>`}
        </div>`;
    }

    async function pintarCuerpoKpis(modal, mes) {
        const body = modal.querySelector('#modal-ecom-kpis-body');
        body.innerHTML = '<div class="premios-loading">Cargando…</div>';

        // Se mandan los filtros del toolbar para que, cuando no se pide un mes explícito, el
        // endpoint arranque en el último mes del período que el usuario está viendo y no en
        // el mes en curso (ver el default del GET en ecommerce_kpis.php).
        const res = await fetch(`api/ecommerce_kpis.php?${buildQS(mes ? { mes } : {})}`);
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.error || `Error ${res.status}`);

        body.innerHTML = `
            <p class="modal-config-hint">La tasa de conversión se toma del <strong>panel de
               VTEX</strong> y va en <strong>puntos de porcentaje</strong> (0,83 = 0,83 %). Las
               órdenes ya no se cargan: salen de Tango. Dejá un campo vacío para borrar el dato.</p>
            <div class="ecom-kpi-mes">
                <label for="modal-ecom-mes">Mes</label>
                <select id="modal-ecom-mes">
                    ${data.meses_disponibles.map(m =>
                        `<option value="${m}" ${m === data.mes ? 'selected' : ''}>${nombreMes(m)}</option>`
                    ).join('')}
                </select>
            </div>
            ${data.canales.map(bloqueCanalHTML).join('')}
            <div class="modal-config-actions">
                <button class="premios-alert-btn premios-alert-btn-ok" id="modal-ecom-kpis-guardar">Guardar</button>
            </div>`;

        // Cambiar de mes recarga el form con lo guardado de ese mes (sin guardar lo tipeado).
        body.querySelector('#modal-ecom-mes').onchange = (e) => {
            pintarCuerpoKpis(modal, e.target.value)
                .catch(err => alertModal(`No se pudo cargar el mes: ${err.message}`, { tono: 'error' }));
        };
        body.querySelectorAll('.ecom-kpi-input').forEach(inp => {
            const c = data.canales.find(x => x.canal === inp.dataset.canal);
            inp.addEventListener('input', () => actualizarTasaControl(body, inp.dataset.canal, c?.ordenes_tango ?? 0));
        });
        data.canales.forEach(c => actualizarTasaControl(body, c.canal, c.ordenes_tango));

        body.querySelector('#modal-ecom-kpis-guardar').onclick = (e) =>
            guardarKpis(modal, body, e.currentTarget);
    }

    async function guardarKpis(modal, body, btn) {
        const mes = body.querySelector('#modal-ecom-mes').value;
        const porCanal = {};
        body.querySelectorAll('.ecom-kpi-input').forEach(inp => {
            const { canal, campo } = inp.dataset;
            porCanal[canal] = porCanal[canal] ?? { canal };
            porCanal[canal][campo] = inp.value === '' ? null : Number(inp.value);
        });

        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = 'Guardando…';
        try {
            await apiPost('ecommerce_kpis.php', { mes, canales: Object.values(porCanal) });
            modal.classList.remove('visible');
            await load();
            await alertModal('KPIs guardados con éxito.', { tono: 'exito' });
        } catch (err) {
            await alertModal(`No se pudo guardar: ${err.message}`, { tono: 'error' });
        } finally {
            btn.innerHTML = original;
            btn.disabled = false;
        }
    }

    async function abrirModalKpis() {
        const modal = abrirModalVacio('modal-ecom-kpis', 'Cargar órdenes y conversión', 'modal-ecom-kpis-body');
        try {
            await pintarCuerpoKpis(modal, null);
        } catch (err) {
            await alertModal(`No se pudieron cargar los KPIs: ${err.message}`, { tono: 'error' });
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════
       Modal: escalas de premio (las "PAUTAS")
       ═══════════════════════════════════════════════════════════════════════ */

    /* Los umbrales se guardan como ratio (1.0 = 100 %) pero se EDITAN en la unidad en la
       que están escritas las pautas: en % para los conceptos de cumplimiento (100, 90,
       111) y en puntos de tasa para los de valor absoluto (0,80). Estas dos funciones son
       la única conversión — cambiar una sin la otra desalinea las escalas guardadas. */
    function umbralAInput(tipoUmbral, umbral) {
        return tipoUmbral === 'PCT_CUMPLIMIENTO' ? Math.round(umbral * 10000) / 100 : umbral;
    }
    function umbralDesdeInput(tipoUmbral, valor) {
        return tipoUmbral === 'PCT_CUMPLIMIENTO' ? valor / 100 : valor;
    }

    function filaTramoHTML(tipoUmbral, tramo) {
        const u = tramo ? umbralAInput(tipoUmbral, tramo.umbral) : '';
        const i = tramo ? tramo.importe : '';
        return `<div class="ecom-escala-fila">
            <input type="number" class="ecom-escala-umbral" step="0.01" min="0" value="${u}">
            <span class="ecom-escala-unidad">${tipoUmbral === 'PCT_CUMPLIMIENTO' ? '% cumpl.' : '% tasa'}</span>
            <input type="number" class="ecom-escala-importe" step="1000" min="0" value="${i}">
            <span class="ecom-escala-unidad">$</span>
            <button type="button" class="btn-accion-fila ecom-escala-quitar" title="Quitar tramo">
                <i class="bi bi-trash"></i>
            </button>
        </div>`;
    }

    function bloqueConceptoHTML(c) {
        return `<div class="ecom-escala-concepto" data-concepto="${c.id}" data-tipo-umbral="${c.tipo_umbral}">
            <div class="ecom-escala-titulo">
                ${c.etiqueta} <span class="ecom-kpi-hint">${c.canal ?? 'VTEX + ML'}</span>
            </div>
            <div class="ecom-escala-tramos">
                ${c.escalas.map(t => filaTramoHTML(c.tipo_umbral, t)).join('')}
            </div>
            <button type="button" class="btn-accion-fila ecom-escala-agregar">
                <i class="bi bi-plus-lg"></i> Agregar tramo
            </button>
        </div>`;
    }

    function attachEscalaHandlers(body) {
        body.querySelectorAll('.ecom-escala-concepto').forEach(bloque => {
            const tipoUmbral = bloque.dataset.tipoUmbral;
            const tramos = bloque.querySelector('.ecom-escala-tramos');
            bloque.querySelector('.ecom-escala-agregar').onclick = () => {
                tramos.insertAdjacentHTML('beforeend', filaTramoHTML(tipoUmbral, null));
                attachQuitarHandlers(tramos);
            };
            attachQuitarHandlers(tramos);
        });
    }
    function attachQuitarHandlers(tramos) {
        tramos.querySelectorAll('.ecom-escala-quitar').forEach(btn => {
            btn.onclick = () => btn.closest('.ecom-escala-fila').remove();
        });
    }

    async function guardarEscalas(modal, body, btn) {
        const conceptos = [];
        for (const bloque of body.querySelectorAll('.ecom-escala-concepto')) {
            const tipoUmbral = bloque.dataset.tipoUmbral;
            const escalas = [];
            for (const fila of bloque.querySelectorAll('.ecom-escala-fila')) {
                const u = fila.querySelector('.ecom-escala-umbral').value;
                const i = fila.querySelector('.ecom-escala-importe').value;
                if (u === '' || i === '') {
                    await alertModal('Hay tramos con el umbral o el importe vacíos. Completalos o quitalos.', { tono: 'error' });
                    return;
                }
                escalas.push({ umbral: umbralDesdeInput(tipoUmbral, Number(u)), importe: Number(i) });
            }
            if (!escalas.length) {
                await alertModal(`El concepto "${bloque.querySelector('.ecom-escala-titulo').textContent.trim()}" quedó sin tramos. Un concepto sin escala nunca paga premio.`, { tono: 'error' });
                return;
            }
            conceptos.push({ id: Number(bloque.dataset.concepto), escalas });
        }

        btn.disabled = true;
        const original = btn.innerHTML;
        btn.innerHTML = 'Guardando…';
        try {
            await apiPost('ecommerce_escalas.php', { conceptos });
            modal.classList.remove('visible');
            await load();
            await alertModal('Escalas guardadas con éxito.', { tono: 'exito' });
        } catch (err) {
            await alertModal(`No se pudo guardar: ${err.message}`, { tono: 'error' });
        } finally {
            btn.innerHTML = original;
            btn.disabled = false;
        }
    }

    async function abrirModalEscalas() {
        const modal = abrirModalVacio('modal-ecom-escalas', 'Escalas de premios', 'modal-ecom-escalas-body');
        const body = modal.querySelector('#modal-ecom-escalas-body');
        try {
            const res = await fetch('api/ecommerce_escalas.php');
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.ok) throw new Error(data.error || `Error ${res.status}`);

            body.innerHTML = `
                <p class="modal-config-hint">Se paga el importe completo del <strong>tramo más alto
                   alcanzado</strong>, sin prorratear. Los tramos de cumplimiento van en % del
                   objetivo (100, 90, 111…); los de tasa de conversión, en puntos de tasa (0,80).</p>
                ${data.personas.map(p => `
                    <div class="ecom-escala-persona">${p.nombre}</div>
                    ${p.conceptos.map(bloqueConceptoHTML).join('')}
                `).join('')}
                <div class="modal-config-actions">
                    <button class="premios-alert-btn premios-alert-btn-ok" id="modal-ecom-escalas-guardar">Guardar</button>
                </div>`;

            attachEscalaHandlers(body);
            body.querySelector('#modal-ecom-escalas-guardar').onclick = (e) =>
                guardarEscalas(modal, body, e.currentTarget);
        } catch (err) {
            await alertModal(`No se pudieron cargar las escalas: ${err.message}`, { tono: 'error' });
        }
    }

    /* ── Shell de modal compartido por los dos (mismas clases que el resto del módulo) ── */
    function abrirModalVacio(id, titulo, bodyId) {
        let modal = $(id);
        if (!modal) {
            modal = document.createElement('div');
            modal.id = id;
            modal.className = 'modal-emails-overlay';
            document.body.appendChild(modal);
        }
        modal.innerHTML = `<div class="modal-emails-box modal-ecom-box">
            <div class="modal-emails-header">
                <span>${titulo}</span>
                <button class="modal-emails-cerrar"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="modal-emails-body" id="${bodyId}">
                <div class="premios-loading">Cargando…</div>
            </div>
        </div>`;
        modal.classList.add('visible');
        modal.querySelector('.modal-emails-cerrar').onclick = () => modal.classList.remove('visible');
        modal.onclick = (e) => { if (e.target === modal) modal.classList.remove('visible'); };
        return modal;
    }

    /* ── Carga de la pestaña ───────────────────────────────────────────────── */

    /**
     * Error de carga a la vista, en vez de dejar la pestaña en "Cargando…" para siempre.
     * El caso más probable en la primera puesta en marcha es que falten las tablas, así que
     * se nombra el script que hay que correr.
     */
    function mostrarError(mensaje) {
        const faltanTablas = /BI_T_PREMIOS_ECOM/i.test(mensaje);
        const ayuda = faltanTablas
            ? `<br>Parece que faltan las tablas de esta pestaña: correr
               <code>premios/sql/setup_premios_ecommerce.sql</code> en POWER_BI_CONTROL.`
            : '';
        const hero = $('hero-ecommerce-wrap');
        if (hero) hero.innerHTML = '';
        const wrap = $('tabla-ecommerce-wrap');
        if (wrap) {
            wrap.innerHTML = `<div class="ecom-banner ecom-banner-falta" style="margin:14px;">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div><strong>No se pudieron cargar los premios de Ecommerce.</strong><br>
                     ${mensaje}${ayuda}</div>
            </div>`;
        }
        const banners = $('ecom-banners-wrap');
        if (banners) banners.innerHTML = '';
    }

    async function load() {
        let data;
        try {
            // No se usa Premios.apiFetch() acá a propósito: ese helper tira
            // `Error ${status}` ANTES de leer el body, y se pierde el mensaje real del
            // servidor — que es justo lo que mostrarError() necesita para poder decir
            // "faltan las tablas, corré el .sql". Leemos el JSON siempre, haya fallado o no.
            const res  = await fetch(`api/ecommerce.php?${buildQS()}`);
            const body = await res.json().catch(() => ({}));
            if (!res.ok || !body.ok) {
                throw new Error(body.error || `Error ${res.status} en ecommerce.php`);
            }
            data = body;
        } catch (err) {
            mostrarError(err.message);
            return;
        }
        updatePeriodoLabel(data.periodo);
        actualizarUltimaActualizacion(data);

        _lastPersonas = data.personas ?? [];
        _lastTotal    = data.total_general ?? 0;
        _lastSesiones = data.sesiones ?? {};

        renderBanners(data);
        renderHero(_lastPersonas, _lastTotal);
        renderTabla(_lastPersonas, _lastTotal);

        const btnExport = $('btn-export-ecommerce');
        if (btnExport) btnExport.onclick = exportar;

        // Los botones de gestión solo existen para GERENCIA/SUPERVISION — el mismo criterio
        // (y el mismo flag del endpoint) que la columna "Acciones" de Premios Supervisoras.
        const btnKpis = $('btn-ecom-cargar-kpis');
        const btnEsc  = $('btn-ecom-escalas');
        [btnKpis, btnEsc].forEach(b => { if (b) b.style.display = data.puede_gestionar ? 'flex' : 'none'; });
        if (btnKpis) btnKpis.onclick = abrirModalKpis;
        if (btnEsc)  btnEsc.onclick  = abrirModalEscalas;
    }

    return { load };
})();
