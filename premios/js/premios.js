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
     * directa) — o sea, no llegó al objetivo de venta pero su variación de facturación supera
     * el benchmark de marca. Es una condición excluyente con el cumplimiento directo: si ya
     * cumplió directo, no corresponde evaluarlo también por esta regla (ver
     * `facturacionVarMarcaCellHTML`) — cada fila gana el premio por un camino o el otro, nunca
     * por los dos a la vez.
     */
    function calculaCumplePorConsuelo(valor, facturacionVar, benchmarkMarca, sinDatos) {
        if (sinDatos || valor === null || valor === undefined) return false;
        const directo = valor >= 0;
        return !directo
            && facturacionVar !== null && facturacionVar !== undefined
            && benchmarkMarca !== null && benchmarkMarca !== undefined
            && facturacionVar > benchmarkMarca;
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
        return `<td class="td-num text-red">${fmt.pct(valor)}</td>`;
    }

    /**
     * Celda con badge verde (mismo `.badge-cumple` que `cumplimientoCellHTML`) cuando el valor
     * supera un umbral (benchmark de marca, o 0 para una variación positiva simple). Si no lo
     * supera, texto plano (o con `opts.claseNoCumple` si se pasa, ej. 'text-red').
     * `opts.gate`: si se pasa `false`, nunca muestra el badge (aunque supere el umbral) — para
     * filas de supervisora, donde estas métricas secundarias solo cuentan si la fila también
     * cumplió el objetivo de venta (ver `calculaCumpleObjetivo`).
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
        const cls = opts.claseNoCumple && !superaUmbral ? ` ${opts.claseNoCumple}` : '';
        return `<td class="td-num${cls}">${formateado}</td>`;
    }

    /**
     * Celda de "Facturación Var %" para filas de supervisora/Total: badge verde SOLO si la fila
     * ganó el premio específicamente POR la regla de consuelo (`cumplePorConsuelo`, ver
     * `calculaCumplePorConsuelo`) — no si ya cumplió el objetivo de venta directo. Es una
     * condición excluyente con la celda de "% Cumplimiento Obj. Venta": esa columna ya se
     * acredita el mérito de la fila (directo o por consuelo); esta columna solo repite el
     * badge cuando el camino fue específicamente el de consuelo, para no acreditar dos veces
     * el mismo cumplimiento y así no comparar contra la marca lo que ya cumplió de forma
     * directa. Texto rojo si la variación es negativa (no depende de `cumplePorConsuelo`: una
     * caída de facturación siempre se marca).
     */
    function facturacionVarMarcaCellHTML(valor, benchmarkMarca, cumplePorConsuelo, formateado) {
        if (valor === null || valor === undefined) {
            return `<td class="td-num">${formateado}</td>`;
        }
        const superaMarca = benchmarkMarca !== null && benchmarkMarca !== undefined && valor > benchmarkMarca;
        if (superaMarca && cumplePorConsuelo) {
            return `<td class="td-num"><span class="badge-cumple" title="Supera la variación de facturación de marca"><i class="bi bi-check-circle-fill"></i> ${formateado}</span></td>`;
        }
        const cls = valor < 0 ? ' text-red' : '';
        return `<td class="td-num${cls}">${formateado}</td>`;
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
        $, fmt, buildQS, apiFetch, updatePeriodoLabel, setSupervisoraOptions,
        calculaCumpleObjetivo, calculaCumplePorConsuelo, cumplimientoCellHTML, badgeCellHTML,
        facturacionVarMarcaCellHTML, actualizarUltimaActualizacion,
    };
})();
