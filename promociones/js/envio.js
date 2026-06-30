/**
 * /bi/promociones/js/envio.js
 * Módulo para gestionar la configuración de automatizaciones de envíos
 * y lanzar envíos de emails manuales y guardados.
 */
const PromoEnvio = (() => {

    const $ = id => document.getElementById(id);

    let _sucursales = [];
    let _config     = {};
    let _selectedSuc = null;
    let _promociones = [];

    async function load() {
        const listWrap = $('envio-lista-sucursales');
        if (listWrap) listWrap.innerHTML = '<div class="promo-loading">Cargando sucursales…</div>';
        $('envio-edicion-vacio').hidden = false;
        $('envio-edicion-formulario').hidden = true;

        try {
            // Cargar datos de sucursales franquicias y la config JSON
            const data = await fetch('/bi/promociones/api/envio_config.php?action=get').then(r => r.json());
            if (!data.ok) throw new Error(data.error ?? 'Error cargando config');

            _sucursales = data.sucursales ?? [];
            _config     = data.config ?? {};
            
            // Cargar promociones para el formulario de exclusión usando el mismo período/filtro del dashboard arriba
            const params = Promociones.getParams();
            let queryStr = `origen=franquicias&periodo=${params.periodo}`;
            if (params.desde && params.hasta) {
                queryStr += `&desde=${encodeURIComponent(params.desde)}&hasta=${encodeURIComponent(params.hasta)}`;
            }
            const filterData = await fetch(`/bi/promociones/api/filtros.php?${queryStr}`).then(r => r.json());
            _promociones = filterData.promociones ?? [];

            renderSucursalesList();
        } catch (e) {
            console.error('[PromoEnvio] Error en carga:', e);
            if (listWrap) listWrap.innerHTML = `<div style="color:var(--red);padding:10px;">Error: ${e.message}</div>`;
        }
    }

    function renderSucursalesList() {
        const listWrap = $('envio-lista-sucursales');
        if (!listWrap) return;

        if (!_sucursales.length) {
            listWrap.innerHTML = '<div style="color:var(--text-3);padding:10px;">No hay sucursales activas</div>';
            return;
        }

        listWrap.innerHTML = _sucursales.map(suc => {
            const nro = suc.NRO_SUCURSAL;
            const hasConfig = _config[nro] && _config[nro].emails;
            const badge = hasConfig 
                ? '<span style="background:#d1fae5;color:#065f46;font-size:0.65rem;font-weight:700;padding:2px 6px;border-radius:4px;margin-left:auto;">Configurado</span>'
                : '<span style="background:#f1f5f9;color:#64748b;font-size:0.65rem;font-weight:700;padding:2px 6px;border-radius:4px;margin-left:auto;">Sin config</span>';

            return `<div class="suc-envio-item" data-nro="${nro}" style="display:flex;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:6px;cursor:pointer;background:#f8fafc;transition:all 0.15s;">
                <div style="display:flex;flex-direction:column;gap:2px;">
                    <strong style="font-size:0.82rem;color:var(--text-1);">${suc.DESC_SUCURSAL}</strong>
                    <span style="font-size:0.7rem;color:var(--text-3);font-weight:600;">Cod: ${suc.cod_client ?? '—'}</span>
                </div>
                ${badge}
            </div>`;
        }).join('');

        // Bind clicks
        listWrap.querySelectorAll('.suc-envio-item').forEach(el => {
            el.addEventListener('click', () => {
                listWrap.querySelectorAll('.suc-envio-item').forEach(i => i.style.borderColor = 'var(--border)');
                el.style.borderColor = 'var(--accent, #2563eb)';
                const nro = Number(el.dataset.nro);
                selectSucursal(nro);
            });
        });
    }

    function selectSucursal(nro) {
        _selectedSuc = _sucursales.find(s => s.NRO_SUCURSAL === nro);
        if (!_selectedSuc) return;

        $('envio-edicion-vacio').hidden = true;
        $('envio-edicion-formulario').hidden = false;

        $('envio-form-sucursal-nombre').textContent = _selectedSuc.DESC_SUCURSAL;
        $('envio-form-sucursal-meta').textContent = `Nro Sucursal: ${_selectedSuc.NRO_SUCURSAL} | Cod Cliente: ${_selectedSuc.cod_client ?? '—'}`;

        const config = _config[nro] ?? {};
        // Si no hay configuración previa de emails, precargar el de la base de datos SUCURSALES_LAKERS si existe
        $('envio-input-emails').value = config.emails || _selectedSuc.MAIL || '';

        renderPromocionesExclusion(config.excluidas ?? []);
    }

    function renderPromocionesExclusion(excluidas) {
        const wrap = $('envio-lista-promociones');
        if (!wrap) return;

        if (!_promociones.length) {
            wrap.innerHTML = '<div style="color:var(--text-3);font-size:0.8rem;">No hay promociones registradas</div>';
            return;
        }

        const excluidasSet = new Set(excluidas);

        wrap.innerHTML = _promociones.map(promo => {
            const val = promo.promocion;
            const checked = excluidasSet.has(val) ? 'checked' : '';
            return `<label style="display:flex;align-items:center;gap:8px;font-size:0.82rem;color:var(--text-1);cursor:pointer;padding:4px 0;">
                <input type="checkbox" class="envio-chk-excluir" value="${val}" ${checked} style="width:14px;height:14px;accent-color:#dc2626;">
                <span>${val}</span>
            </label>`;
        }).join('');
    }

    async function guardarConfig() {
        if (!_selectedSuc) return;
        const emailsInput = $('envio-input-emails').value.trim();
        const excluidas = Array.from(document.querySelectorAll('.envio-chk-excluir:checked')).map(cb => cb.value);

        Promociones.setLoading(true, 'Guardando configuración…');
        try {
            const res = await fetch('/bi/promociones/api/envio_config.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    nro_sucursal: _selectedSuc.NRO_SUCURSAL,
                    emails: emailsInput,
                    excluidas: excluidas
                })
            }).then(r => r.json());

            if (!res.ok) throw new Error(res.error ?? 'Error al guardar');

            // Actualizar config en memoria y lista
            _config[_selectedSuc.NRO_SUCURSAL] = {
                emails: emailsInput,
                excluidas: excluidas
            };
            renderSucursalesList();
            
            // Re-resaltar elemento
            const item = document.querySelector(`.suc-envio-item[data-nro="${_selectedSuc.NRO_SUCURSAL}"]`);
            if (item) item.style.borderColor = 'var(--accent, #2563eb)';

            PromoNotify.success('Configuración guardada correctamente.');
        } catch (e) {
            console.error(e);
            PromoNotify.error('Error: ' + e.message);
        } finally {
            Promociones.setLoading(false);
        }
    }

    async function enviarManual() {
        if (!_selectedSuc) return;
        const emailsInput = $('envio-input-emails').value.trim();
        const excluidas = Array.from(document.querySelectorAll('.envio-chk-excluir:checked')).map(cb => cb.value);

        if (!emailsInput) {
            PromoNotify.error('Por favor ingrese al menos un email destinatario.');
            return;
        }

        if (!confirm(`¿Está seguro de enviar ahora el email con el reporte del mes anterior a: ${emailsInput}?`)) {
            return;
        }

        Promociones.setLoading(true, 'Generando reporte y enviando email…');
        try {
            const res = await fetch('/bi/promociones/api/envio_config.php?action=send_manual', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    nro_sucursal: _selectedSuc.NRO_SUCURSAL,
                    nombre: _selectedSuc.DESC_SUCURSAL,
                    emails: emailsInput,
                    excluidas: excluidas
                })
            }).then(r => r.json());

            if (!res.ok) throw new Error(res.error ?? 'Error en envío');

            PromoNotify.success(res.msg || 'Reporte enviado con éxito.');
        } catch (e) {
            console.error(e);
            PromoNotify.error('Error en envío: ' + e.message);
        } finally {
            Promociones.setLoading(false);
        }
    }

    // Inicializar listeners del formulario
    document.addEventListener('DOMContentLoaded', () => {
        $('btn-envio-guardar')?.addEventListener('click', guardarConfig);
        $('btn-envio-manual')?.addEventListener('click', enviarManual);
    });

    return {
        load
    };
})();
