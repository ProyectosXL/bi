/* /bi/logistica/assets/logistica_core.js
   Helpers compartidos entre logistica_ar.js y logistica_uy.js.
   Expone window.LogiCore (no requiere jQuery).
   ============================================================ */
;(function (global) {
    'use strict';

    const PALETTE = [
        '#00a878','#2563eb','#f59e0b','#ef4444','#8b5cf6',
        '#06b6d4','#f97316','#10b981','#6366f1','#ec4899',
        '#14b8a6','#a855f7','#84cc16','#f43f5e','#0ea5e9',
    ];

    // ── Formato numérico es-AR ────────────────────────────────────────────
    const fmt = {
        num(v, dec = 0) {
            if (v == null || v === '' || isNaN(v) || !isFinite(v)) return '—';
            return Number(v).toLocaleString('es-AR', {
                minimumFractionDigits: dec,
                maximumFractionDigits: dec,
            });
        },
        pct(v, dec = 1) {
            if (v == null || v === '' || isNaN(v) || !isFinite(v)) return '—';
            return Number(v * 100).toLocaleString('es-AR', {
                minimumFractionDigits: dec,
                maximumFractionDigits: dec,
            }) + '%';
        },
        money(v) {
            if (v == null || isNaN(v)) return '—';
            return '$ ' + Number(v).toLocaleString('es-AR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },
        moneyUyu(v) {
            if (v == null || isNaN(v)) return '—';
            return '$U ' + Number(v).toLocaleString('es-AR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },
        moneyUsd(v) {
            if (v == null || isNaN(v)) return '—';
            return 'U$S ' + Number(v).toLocaleString('es-AR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },
        date(s) {
            if (!s) return '—';
            const d = typeof s === 'string' ? s.substring(0, 10) : s;
            const parts = String(d).split('-');
            if (parts.length === 3) return parts[2] + '/' + parts[1] + '/' + parts[0];
            return d;
        },
        varLabel(actual, meta) {
            if (actual == null) return { text: '—', cls: 'neu' };
            const a = parseFloat(actual);
            if (isNaN(a)) return { text: '—', cls: 'neu' };
            if (meta != null) {
                const m = parseFloat(meta);
                const cls = a >= m ? 'alcanza' : 'no-alcanza';
                const icono = a >= m
                    ? '<i class="bi bi-check-circle-fill"></i>'
                    : '<i class="bi bi-x-circle-fill"></i>';
                return { text: icono + ' Meta: ' + fmt.pct(m), cls, semaforo: true };
            }
            return { text: '—', cls: 'neu' };
        },
        varPct(actual, prev) {
            if (actual == null || prev == null) return { text: '—', cls: 'neu' };
            const a = parseFloat(actual), p = parseFloat(prev);
            if (isNaN(a) || isNaN(p) || p === 0) return { text: '—', cls: 'neu' };
            const v = (a - p) / Math.abs(p);
            const pct = v * 100;
            if (pct > 0.05)  return { text: '▲ ' + fmt.num(pct, 1) + '%', cls: 'pos' };
            if (pct < -0.05) return { text: '▼ ' + fmt.num(Math.abs(pct), 1) + '%', cls: 'neg' };
            return { text: '0,0%', cls: 'neu' };
        },
    };

    function setVar(id, v) {
        const $el = (typeof jQuery !== 'undefined') ? jQuery(id) : null;
        if (!$el || !$el.length) return;
        $el.html(v.text).removeClass('pos neg neu alcanza no-alcanza semaforo');
        $el.addClass('kpi-var ' + v.cls);
        if (v.semaforo) $el.addClass('semaforo');
    }

    // ── Overlay / loading bar ─────────────────────────────────────────────
    function showOverlay() {
        document.getElementById('loading-overlay').removeAttribute('hidden');
        document.body.classList.add('is-loading');
    }
    function hideOverlay() {
        document.getElementById('loading-overlay').setAttribute('hidden', '');
        document.body.classList.remove('is-loading');
    }
    function setReload(on) {
        const btn = document.getElementById('btn-reload');
        if (!btn) return;
        btn.classList.toggle('spinning', on);
        const ico = btn.querySelector('i');
        if (ico) {
            ico.classList.toggle('bi-arrow-clockwise', !on);
            ico.classList.toggle('bi-arrow-repeat', on);
        }
    }

    // ── Info popover ──────────────────────────────────────────────────────
    function addInfoButton($target, title, tips, mode = 'append') {
        if (!$target || !$target.length || $target.find('.info-btn').length) return;
        const safeTitle = escapeHtml(title);
        const safeTips = (tips || []).map(escapeHtml).join('|');
        const $btn = jQuery(
            `<button type="button" class="info-btn" aria-label="Ver ayuda: ${safeTitle}" title="Ver ayuda" ` +
            `data-info-title="${safeTitle}" data-info-tips="${safeTips}">` +
            `<i class="bi bi-info-circle"></i></button>`
        );
        mode === 'prepend' ? $target.prepend($btn) : $target.append($btn);
    }

    function initInfoPopover() {
        if (document.getElementById('logistica-info-popover')) return;
        const popover = document.createElement('div');
        popover.id = 'logistica-info-popover';
        popover.className = 'info-popover';
        document.body.appendChild(popover);

        let activeBtn = null;

        function show(btn) {
            if (activeBtn) activeBtn.classList.remove('active');
            activeBtn = btn;
            btn.classList.add('active');
            const title = btn.dataset.infoTitle || '';
            const tips = (btn.dataset.infoTips || '').split('|').filter(Boolean);
            popover.innerHTML =
                `<div class="info-popover-title"><i class="bi bi-info-circle-fill"></i>${title}</div>` +
                tips.map(t => `<div class="info-popover-tip">` +
                    `<i class="bi bi-lightbulb-fill info-popover-tip-icon"></i><span>${t}</span></div>`).join('');
            popover.style.display = 'block';
            position(btn);
        }

        function hide() {
            popover.style.display = 'none';
            if (activeBtn) activeBtn.classList.remove('active');
            activeBtn = null;
        }

        function position(btn) {
            const r = btn.getBoundingClientRect();
            const pw = popover.offsetWidth;
            const ph = popover.offsetHeight;
            let left = r.right - pw;
            let top  = r.bottom + 8;
            if (left < 8) left = 8;
            if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
            if (top + ph > window.innerHeight - 8) top = r.top - ph - 8;
            popover.style.left = Math.max(8, left) + 'px';
            popover.style.top  = Math.max(8, top)  + 'px';
        }

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.info-btn');
            if (btn) { e.stopPropagation(); activeBtn === btn ? hide() : show(btn); return; }
            if (activeBtn) hide();
        });
        document.addEventListener('mouseover', function (e) {
            const btn = e.target.closest('.info-btn');
            if (btn) show(btn);
        });
        document.addEventListener('mouseout', function (e) {
            const btn = e.target.closest('.info-btn');
            if (!btn) return;
            const next = e.relatedTarget;
            if (next && (btn.contains(next) || popover.contains(next))) return;
            hide();
        });
        document.addEventListener('focusin',  function (e) { const b = e.target.closest('.info-btn'); if (b) show(b); });
        document.addEventListener('focusout', function (e) { const b = e.target.closest('.info-btn'); if (b) hide(); });
        document.addEventListener('keydown',  function (e) { if (e.key === 'Escape') hide(); });
        window.addEventListener('resize',     function () { if (activeBtn) position(activeBtn); });
    }

    // ── escapeHtml ────────────────────────────────────────────────────────
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
        }[ch]));
    }

    // ── chartOptions base ─────────────────────────────────────────────────
    // tOpts: { pct: bool, integer: bool, suffix: string }
    function chartOptions(yLabel = '', yScale = {}, tOpts = {}) {
        const { pct = false, integer = false, suffix = '' } = tOpts;
        function fmtVal(v) {
            if (pct)     return Number(v).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
            if (integer) return Number(v).toLocaleString('es-AR', { maximumFractionDigits: 0 });
            return Number(v).toLocaleString('es-AR', { maximumFractionDigits: 1 });
        }
        return {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } },
                tooltip: {
                    backgroundColor: 'rgba(15,23,42,0.92)',
                    titleColor: '#f8fafc',
                    bodyColor: '#cbd5e1',
                    borderColor: 'rgba(255,255,255,0.12)',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label(ctx) {
                            const v = ctx.parsed.y;
                            if (v == null) return null;
                            return ' ' + ctx.dataset.label + ': ' + fmtVal(v) + suffix;
                        },
                    },
                },
            },
            scales: {
                x: { ticks: { maxRotation: 45, font: { size: 11 } } },
                y: { beginAtZero: true, ...yScale, title: { display: !!yLabel, text: yLabel } },
            },
        };
    }

    // ── Exportar namespace ────────────────────────────────────────────────
    global.LogiCore = {
        PALETTE,
        fmt,
        setVar,
        showOverlay,
        hideOverlay,
        setReload,
        escapeHtml,
        addInfoButton,
        initInfoPopover,
        chartOptions,
    };

})(window);
