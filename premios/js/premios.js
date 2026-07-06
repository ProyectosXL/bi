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

    /* ── Semáforo verde/rojo para % Cumplimiento Obj. Venta ── */
    function claseSemaforo(valor, sinDatos) {
        if (sinDatos || valor === null || valor === undefined) return '';
        return valor >= 0 ? 'text-green' : 'text-red';
    }

    return { $, fmt, buildQS, apiFetch, updatePeriodoLabel, setSupervisoraOptions, claseSemaforo };
})();
