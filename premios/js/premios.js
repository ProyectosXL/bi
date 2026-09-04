/**
 * /bi/premios/js/premios.js
 * Namespace compartido por los módulos de pestaña (supervisoras/propios/franquicias):
 * helpers de formato, lectura de filtros del toolbar y etiqueta de período.
 */
const Premios = (() => {

    const $ = id => document.getElementById(id);
    const fmt = BIUtils.fmt;

    /* ── Parámetros del toolbar → querystring para las APIs ── */
    function buildQS(extra = {}) {
        const p = {
            periodo    : $('sel-periodo')?.value      ?? 'mes_actual',
            supervisora: $('sel-supervisora')?.value   ?? '',
            ...extra,
        };
        if (p.periodo === 'custom') {
            p.desde     = $('input-desde')?.value ?? '';
            p.hasta     = $('input-hasta')?.value ?? '';
            p.comp_mode = document.querySelector('input[name="comp-mode"]:checked')?.value ?? 'year_ago';
            if (p.comp_mode === 'custom') {
                p.desde_comp = $('input-comp-desde')?.value ?? '';
                p.hasta_comp = $('input-comp-hasta')?.value ?? '';
            }
        }
        if (!p.supervisora) delete p.supervisora;
        return new URLSearchParams(p).toString();
    }

    async function apiFetch(endpoint, extra = {}) {
        const res = await fetch(`api/${endpoint}?${buildQS(extra)}`);
        if (!res.ok) throw new Error(`Error ${res.status} en ${endpoint}`);
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Error en API');
        return data;
    }

    /**
     * POST a un endpoint de acción (marcar_controlado.php, enviar_mail_supervisora.php, etc.).
     * El body incluye los filtros del toolbar (período/supervisora) + lo que se pase en `body`,
     * así el endpoint puede resolver el período con PeriodHelper::fromRequest() igual que en GET.
     */
    async function apiPost(endpoint, body = {}) {
        const qs = buildQS();
        const params = Object.fromEntries(new URLSearchParams(qs));
        const res = await fetch(`api/${endpoint}`, {
            method : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body   : JSON.stringify({ ...params, ...body }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.ok) throw new Error(data.error || `Error ${res.status} en ${endpoint}`);
        return data;
    }

    /* ── Etiqueta de período en el topbar ──
       "Desde el D/M/AA al D/M/AA (N días)" / "vs. período del DD/MM/AAAA al DD/MM/AAAA (N días)" */
    function fmtCorta(s) {
        const [y, m, d] = s.split('-');
        return `${+d}/${+m}/${y.slice(2)}`;
    }
    function fmtLarga(s) {
        const [y, m, d] = s.split('-');
        return `${d}/${m}/${y}`;
    }
    function cantDias(desde, hasta) {
        const a = new Date(desde + 'T00:00:00');
        const b = new Date(hasta + 'T00:00:00');
        return Math.round((b - a) / 86400000) + 1;
    }

    function updatePeriodoLabel(periodo) {
        if (!periodo) return;
        const lab  = $('periodo-label');
        const prev = $('periodo-previo-label');
        const dias     = cantDias(periodo.desde, periodo.hasta);
        const diasPrev = cantDias(periodo.desde_prev, periodo.hasta_prev);
        if (lab) {
            lab.textContent = `Desde el ${fmtCorta(periodo.desde)} al ${fmtCorta(periodo.hasta)} (${dias} días)`;
        }
        if (prev) {
            prev.textContent = `vs. período del ${fmtLarga(periodo.desde_prev)} al ${fmtLarga(periodo.hasta_prev)} (${diasPrev} días)`;
        }
    }

    /* ── Popula el <select> de supervisoras (una sola vez) ── */
    function setSupervisoraOptions(lista) {
        const sel = $('sel-supervisora');
        if (!sel || sel.dataset.loaded) return;
        sel.dataset.loaded = '1';
        lista.forEach(nombre => {
            const opt = document.createElement('option');
            opt.value = nombre;
            opt.textContent = nombre;
            sel.appendChild(opt);
        });
    }

    /**
     * Redondea a la misma precisión con la que se muestra en pantalla (1 decimal de %, ver
     * fmt.pct/fmt.varPct) — evita que dos valores comparados por `calculaCumplePorConsuelo`/
     * `facturacionVarMarcaCellHTML` que se VEN iguales (ej. "18,9 %" vs "18,9 %") no se marquen
     * como "superado" por una diferencia de decimales invisible en pantalla.
     */
    function redondeoPct(n) {
        return Math.round(n * 1000) / 1000;
    }

    /**
     * true/false si cumple el objetivo de venta, directo o "de consuelo" (igual a la del
     * premio de crecimiento real: si no alcanza el objetivo pero su variación de facturación
     * supera el benchmark de marca, igual se considera cumplido). null si no hay dato.
     * Este resultado determina si la fila ganó ALGÚN premio (por cualquiera de los dos
     * caminos), y por eso se usa como "gate" para el resto de badges de su fila (ver
     * `badgeCellHTML`) — esas otras métricas no deben verse como "cumplidas" si la fila en su
     * conjunto no ganó el premio. No se usa para decidir el badge de "% Cumplimiento Obj.
     * Venta" en sí (ver `cumplimientoCellHTML`, que badgea solo el camino directo) ni el de
     * "Facturación Var %" (ver `calculaCumplePorConsuelo`, que badgea solo el camino de
     * consuelo) — esas dos columnas se reparten los dos caminos sin superponerse.
     */
    function calculaCumpleObjetivo(valor, facturacionVar, benchmarkMarca, sinDatos) {
        if (sinDatos || valor === null || valor === undefined) return null;
        const directo = valor >= 0;
        return directo || calculaCumplePorConsuelo(valor, facturacionVar, benchmarkMarca, sinDatos);
    }

    /**
     * true/false si cumple el objetivo ESPECÍFICAMENTE por la regla de consuelo (no de forma
     * directa) — o sea, no llegó al objetivo de venta pero su variación de facturación llega
     * al benchmark de marca. Es una condición excluyente con el cumplimiento directo: si ya
     * cumplió directo, no corresponde evaluarlo también por esta regla (ver
     * `facturacionVarMarcaCellHTML`) — cada fila gana el premio por un camino o el otro, nunca
     * por los dos a la vez. Compara con `redondeoPct()` (>=, no >): sin esto, dos valores que
     * se ven IGUALES en pantalla (ambos redondeados a "18,9 %", por ejemplo) podían no marcarse
     * como cumplidos por una diferencia de decimales invisible (a pedido del cliente, 2026-09-02).
     */
    function calculaCumplePorConsuelo(valor, facturacionVar, benchmarkMarca, sinDatos) {
        if (sinDatos || valor === null || valor === undefined) return false;
        const directo = valor >= 0;
        return !directo
            && facturacionVar !== null && facturacionVar !== undefined
            && benchmarkMarca !== null && benchmarkMarca !== undefined
            && redondeoPct(facturacionVar) >= redondeoPct(benchmarkMarca);
    }

    /**
     * Celda de "% Cumplimiento Obj. Venta": badge verde SOLO si cumple el objetivo de venta
     * DIRECTO (valor >= 0). El cumplimiento "de consuelo" (ver `calculaCumplePorConsuelo`) ya
     * no se badgea acá — se badgea exclusivamente en la columna de Facturación Var %
     * (`facturacionVarMarcaCellHTML`), para que el badge de una fila nunca aparezca en ambas
     * columnas a la vez: si cumple directo, el mérito es de esta columna; si cumple solo por
     * consuelo, el mérito es de la otra y esta queda en rojo.
     */
    function cumplimientoCellHTML(valor, sinDatos) {
        if (sinDatos || valor === null || valor === undefined) {
            return `<td class="td-num">${fmt.pct(valor)}</td>`;
        }
        if (valor >= 0) {
            return `<td class="td-num"><span class="badge-cumple" title="Cumple objetivo de venta"><i class="bi bi-check-circle-fill"></i> ${fmt.pct(valor)}</span></td>`;
        }
        return `<td class="td-num"><span class="badge-no-cumple" title="No cumple objetivo de venta"><i class="bi bi-x-circle-fill"></i> ${fmt.pct(valor)}</span></td>`;
    }

    /**
     * Celda con badge verde (`.badge-cumple`) cuando el valor supera un umbral (benchmark de
     * marca, o 0 para una variación positiva simple), o badge rojo (`.badge-no-cumple`, mismo
     * diseño en pill que el verde) cuando no lo supera — a pedido del cliente (2026-09-03):
     * antes quedaba en texto plano sin remarcar que no llegó al benchmark.
     * `opts.gate`: si se pasa `false`, nunca muestra el badge VERDE (aunque supere el umbral) —
     * para filas de supervisora, donde estas métricas secundarias solo cuentan si la fila
     * también cumplió el objetivo de venta (ver `calculaCumpleObjetivo`). En ese caso NO se
     * pinta de rojo tampoco si igual superó el umbral (el rojo es solo para "no llegó al
     * benchmark", no para "el gate lo bloqueó") — queda neutro.
     * `opts.tituloNoCumple`: tooltip del badge rojo (independiente de `opts.titulo`, que es
     * el del verde — evita mostrar "Supera..." en un badge que dice lo contrario).
     */
    function badgeCellHTML(valor, umbral, formateado, opts = {}) {
        if (valor === null || valor === undefined || umbral === null || umbral === undefined) {
            return `<td class="td-num">${formateado}</td>`;
        }
        const superaUmbral = opts.inclusive ? valor >= umbral : valor > umbral;
        const gate = opts.gate ?? true;
        if (superaUmbral && gate) {
            const tituloAttr = opts.titulo ? ` title="${opts.titulo}"` : '';
            return `<td class="td-num"><span class="badge-cumple"${tituloAttr}><i class="bi bi-check-circle-fill"></i> ${formateado}</span></td>`;
        }
        if (!superaUmbral) {
            const tituloAttr = opts.tituloNoCumple ? ` title="${opts.tituloNoCumple}"` : '';
            return `<td class="td-num"><span class="badge-no-cumple"${tituloAttr}><i class="bi bi-x-circle-fill"></i> ${formateado}</span></td>`;
        }
        return `<td class="td-num">${formateado}</td>`;
    }

    /**
     * Celda de "Facturación Var %": badge verde si supera el benchmark de marca, rojo si no —
     * puramente visual (2026-09-04, a pedido del cliente), sin relación con si la fila ya
     * ganó el premio por objetivo de venta directo en la otra columna. La regla de negocio de
     * "consuelo" (mutuamente excluyente con % Cumplimiento Obj. Venta para no acreditar el
     * mismo mérito dos veces) sigue intacta para el CÁLCULO del premio — ver
     * `calculaCumplePorConsuelo()` y `PremiosDB::contarCrecimiento()` — esto solo cambia lo
     * que se ve pintado en esta celda puntual.
     */
    function facturacionVarMarcaCellHTML(valor, benchmarkMarca, formateado) {
        if (valor === null || valor === undefined || benchmarkMarca === null || benchmarkMarca === undefined) {
            return `<td class="td-num">${formateado}</td>`;
        }
        const superaMarca = redondeoPct(valor) >= redondeoPct(benchmarkMarca);
        if (superaMarca) {
            return `<td class="td-num"><span class="badge-cumple" title="Supera la variación de facturación de marca"><i class="bi bi-check-circle-fill"></i> ${formateado}</span></td>`;
        }
        return `<td class="td-num"><span class="badge-no-cumple" title="No supera la variación de facturación de marca"><i class="bi bi-x-circle-fill"></i> ${formateado}</span></td>`;
    }

    /* ── Modal genérico de aviso/confirmación (reemplaza alert()/confirm() nativos del
       navegador por algo con el mismo estilo del resto del dashboard). ── */
    function _abrirModal(html) {
        const overlay = document.createElement('div');
        overlay.className = 'premios-alert-overlay';
        overlay.innerHTML = `<div class="premios-alert-box">${html}</div>`;
        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('visible'));
        return overlay;
    }
    function _cerrarModal(overlay, resolve, valor) {
        overlay.classList.remove('visible');
        setTimeout(() => overlay.remove(), 150);
        resolve(valor);
    }

    /** @param opts.titulo, opts.tono ('exito'|'error'|'info', default 'info') */
    function alertModal(mensaje, opts = {}) {
        const tono = opts.tono ?? 'info';
        const icono = { exito: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' }[tono];
        const titulo = opts.titulo ?? { exito: 'Listo', error: 'Error', info: 'Aviso' }[tono];
        return new Promise(resolve => {
            const overlay = _abrirModal(`
                <div class="premios-alert-header premios-alert-${tono}"><i class="bi ${icono}"></i> ${titulo}</div>
                <div class="premios-alert-body">${mensaje}</div>
                <div class="premios-alert-actions">
                    <button class="premios-alert-btn premios-alert-btn-ok">OK</button>
                </div>`);
            const cerrar = () => _cerrarModal(overlay, resolve);
            overlay.querySelector('.premios-alert-btn-ok').addEventListener('click', cerrar);
            overlay.addEventListener('click', e => { if (e.target === overlay) cerrar(); });
        });
    }

    /** @return Promise<boolean> true si confirma, false si cancela. */
    function confirmModal(mensaje, opts = {}) {
        return new Promise(resolve => {
            const overlay = _abrirModal(`
                <div class="premios-alert-header premios-alert-info"><i class="bi bi-question-circle-fill"></i> ${opts.titulo ?? 'Confirmar'}</div>
                <div class="premios-alert-body">${mensaje}</div>
                <div class="premios-alert-actions">
                    <button class="premios-alert-btn premios-alert-btn-cancel">Cancelar</button>
                    <button class="premios-alert-btn premios-alert-btn-ok">Aceptar</button>
                </div>`);
            const cerrar = valor => _cerrarModal(overlay, resolve, valor);
            overlay.querySelector('.premios-alert-btn-ok').addEventListener('click', () => cerrar(true));
            overlay.querySelector('.premios-alert-btn-cancel').addEventListener('click', () => cerrar(false));
            overlay.addEventListener('click', e => { if (e.target === overlay) cerrar(false); });
        });
    }

    /* ── Ordenamiento de tablas por click en el encabezado ────────────────────────────
       Estado por tabla: {key: string|null, dir: 'desc'|'asc'|null}. Ciclo por click en un
       <th data-sort-key="..."> : sin orden → mayor a menor → menor a mayor → sin orden (vuelve
       al orden por defecto). Cada módulo de pestaña guarda su propio estado en una variable de
       módulo y la resetea a `nuevoEstadoOrden()` al arrancar `load()`, así el tablero siempre
       arranca en el orden original al cambiar de período/supervisora o al recargar la pestaña
       — a pedido del cliente, el orden manual nunca se recuerda entre cargas. ── */
    function nuevoEstadoOrden() {
        return { key: null, dir: null };
    }
    function cicloOrden(state, key) {
        if (state.key !== key) return { key, dir: 'desc' };
        if (state.dir === 'desc') return { key, dir: 'asc' };
        return nuevoEstadoOrden();
    }
    function iconoOrden(state, key) {
        if (state.key !== key) return '<i class="bi bi-arrow-down-up sort-icon"></i>';
        const cls = 'bi ' + (state.dir === 'desc' ? 'bi-sort-down-alt' : 'bi-sort-up') + ' sort-icon sort-icon-active';
        return `<i class="${cls}"></i>`;
    }
    /** @param getValue (fila, key) => string|number|null — valor crudo a comparar para esa key. */
    function ordenarPor(filas, state, getValue) {
        if (!state.key) return filas;
        const copia = [...filas];
        copia.sort((a, b) => {
            const va = getValue(a, state.key);
            const vb = getValue(b, state.key);
            if (va == null && vb == null) return 0;
            if (va == null) return 1;
            if (vb == null) return -1;
            if (typeof va === 'string' || typeof vb === 'string') {
                return state.dir === 'desc' ? String(vb).localeCompare(String(va)) : String(va).localeCompare(String(vb));
            }
            return state.dir === 'desc' ? vb - va : va - vb;
        });
        return copia;
    }
    /** Delega los clicks de todos los <th data-sort-key> del wrap a `onClick(key)`. */
    function attachSortHandlers(wrap, onClick) {
        wrap.querySelectorAll('th[data-sort-key]').forEach(th => {
            th.addEventListener('click', () => onClick(th.dataset.sortKey));
        });
    }

    /* ── Badge "Última actualización" / "DESACTUALIZADO" (reactivo a cada respuesta AJAX) ── */
    function actualizarUltimaActualizacion(data) {
        if (data.ultima_actualizacion) {
            const elAct = $('ultima-actualizacion');
            if (elAct) elAct.textContent = data.ultima_actualizacion;
        }
        const elBadge = $('badge-desactualizado');
        if (elBadge) {
            elBadge.style.display = data.is_outdated ? 'inline-flex' : 'none';
        }
    }

    return {
        $, fmt, buildQS, apiFetch, apiPost, updatePeriodoLabel, setSupervisoraOptions,
        calculaCumpleObjetivo, calculaCumplePorConsuelo, cumplimientoCellHTML, badgeCellHTML,
        facturacionVarMarcaCellHTML, actualizarUltimaActualizacion, alertModal, confirmModal,
        nuevoEstadoOrden, cicloOrden, iconoOrden, ordenarPor, attachSortHandlers,
    };
})();
