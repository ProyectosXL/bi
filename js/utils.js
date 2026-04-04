/**
 * /bi/js/utils.js
 * Helpers compartidos de formato y utilidades DOM para todos los tableros.
 *
 * Expone el objeto global `BIUtils` con:
 *   BIUtils.fmt   — funciones de formato de números/moneda/porcentajes
 *   BIUtils.dom   — helpers de creación de elementos DOM
 *   BIUtils.color — lógica de color semántico (positivo/negativo/neutro)
 *
 * Uso en cualquier tablero:
 *   <script src="/bi/js/utils.js"></script>
 *   const fmt = BIUtils.fmt;
 */

window.BIUtils = (() => {

    /* ── Formato numérico ────────────────────────── */
    const fmt = {
        /** $ 1.234.567 */
        money: (n, dec = 0) => {
            if (n === null || n === undefined) return '—';
            return '$\u00A0' + Number(n).toLocaleString('es-AR', {
                minimumFractionDigits: dec,
                maximumFractionDigits: dec,
            });
        },

        /** $ 1,2M  /  $ 86K  /  $ 1.234 */
        moneyK: (n) => {
            if (n === null || n === undefined) return '—';
            if (Math.abs(n) >= 1_000_000)
                return '$\u00A0' + (n / 1_000_000).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + 'M';
            if (Math.abs(n) >= 1_000)
                return '$\u00A0' + (n / 1_000).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + 'K';
            return fmt.money(n);
        },

        /** 27,7 % */
        pct: (n, dec = 1) => (n === null || n === undefined) ? '—'
            : (n * 100).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + '\u00A0%',

        /** +8,2 % o -8,2 % */
        varPct: (n, dec = 1) => {
            if (n === null || n === undefined) return '—';
            const sign = n >= 0 ? '+' : '';
            return sign + (n * 100).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + '\u00A0%';
        },

        /** 1.118 */
        num: (n, dec = 0) => (n === null || n === undefined) ? '—'
            : Number(n).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec }),

        /** Fecha 'Y-m-d' → '01/04/2026' */
        fecha: (s) => {
            if (!s) return '—';
            const [y, m, d] = s.split('-');
            return `${d}/${m}/${y}`;
        },

        /** Fecha 'Y-m-d' → 'abr 2026' */
        mesCorto: (s) => {
            if (!s) return '—';
            const meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
            const [y, m] = s.split('-');
            return `${meses[+m - 1]} ${y}`;
        },
    };

    /* ── Color semántico ─────────────────────────── */
    const color = {
        /**
         * Devuelve clase CSS 'pos' o 'neg' según si el valor es positivo/negativo.
         * Para métricas inversas (donde negativo es bueno), pasar inverse=true.
         */
        varClass: (n, inverse = false) => {
            if (n === null || n === undefined) return '';
            const good = inverse ? n <= 0 : n >= 0;
            return good ? 'pos' : 'neg';
        },

        /**
         * Colorea una celda de tabla con verde/rojo según umbral.
         * umbral: valor a partir del cual se aplica color positivo.
         */
        cell: (td, value, umbral = 0, inverse = false) => {
            const good = inverse ? value <= umbral : value >= umbral;
            td.classList.toggle('cell-pos', good);
            td.classList.toggle('cell-neg', !good);
        },

        /** Fondo rojo→amarillo→verde por percentil (para heatmaps). */
        heatmap: (ratio) => {
            // ratio: 0 = mínimo (rojo), 1 = máximo (verde)
            const r = ratio ?? 0;
            if (r < 0.33) return `rgba(220,38,38,${0.15 + r * 0.4})`;
            if (r < 0.66) return `rgba(245,158,11,${0.15 + r * 0.3})`;
            return `rgba(22,163,74,${0.15 + r * 0.35})`;
        },
    };

    /* ── DOM helpers ─────────────────────────────── */
    const dom = {
        /** td con texto y class opcionales */
        td: (text = '', cls = '') => {
            const el = document.createElement('td');
            el.textContent = text;
            if (cls) el.className = cls;
            return el;
        },

        /** td numérico alineado a la derecha */
        tdNum: (text = '', cls = '') => {
            const el = document.createElement('td');
            el.textContent = text;
            el.style.textAlign = 'right';
            if (cls) el.className = cls;
            return el;
        },

        /** tr vacío con N celdas de "Cargando..." */
        loadingRow: (cols, text = 'Cargando...') => {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = cols;
            td.textContent = text;
            td.style.cssText = 'text-align:center;padding:24px;color:var(--text-3)';
            tr.appendChild(td);
            return tr;
        },

        /** Muestra/oculta un loader dentro de un contenedor */
        setLoading: (container, loading = true) => {
            if (!container) return;
            const existing = container.querySelector('.bi-loader');
            if (loading && !existing) {
                const loader = document.createElement('div');
                loader.className = 'bi-loader';
                loader.innerHTML = '<i class="bi bi-arrow-repeat"></i> <span>Cargando</span>';
                container.appendChild(loader);
            } else if (!loading && existing) {
                existing.remove();
            }
        },
    };

    /* ── API fetch helper ────────────────────────── */

    /**
     * Crea un fetcher atado a un state y un basePath.
     * @param {object} state    Objeto de estado con periodo, vendedor, etc.
     * @param {string} basePath Ruta base de la API, e.g. 'api/' o '../global/api/'
     */
    function createApiFetch(state, basePath = 'api/') {
        let _abort = null;

        function buildQS(extra = {}) {
            const p = {
                periodo: state.periodo,
                vendedor: state.vendedor,
                rubro: state.rubro,
                ...extra,
            };
            if (state.periodo === 'custom') {
                p.desde     = state.desde;
                p.hasta     = state.hasta;
                p.comp_mode = state.compMode;
                if (state.compMode === 'custom') {
                    p.desde_comp = state.compDesde;
                    p.hasta_comp = state.compHasta;
                }
            }
            return new URLSearchParams(p).toString();
        }

        async function apiFetch(endpoint, extra = {}) {
            if (_abort) _abort.abort();
            _abort = new AbortController();
            const res = await fetch(`${basePath}${endpoint}?${buildQS(extra)}`, { signal: _abort.signal });
            if (!res.ok) throw new Error(`Error ${res.status} en ${endpoint}`);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Error en API');
            return data;
        }

        return { buildQS, apiFetch };
    }

    return { fmt, color, dom, createApiFetch };
})();
