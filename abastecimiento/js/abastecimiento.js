/**
 * /bi/abastecimiento/js/abastecimiento.js
 * Lógica interactiva del Dashboard de Abastecimiento (XL Extra Large)
 */

const Abastecimiento = (() => {
    let selectedArticulo = null;

    // Elementos DOM
    const el = {
        selProveedor:   document.getElementById('sel-proveedor'),
        selRubro:       document.getElementById('sel-rubro'),
        selCategoria:   document.getElementById('sel-categoria'),
        selTemporada:   document.getElementById('sel-temporada'),
        selCanal:       document.getElementById('sel-canal'),
        selSucursal:    document.getElementById('sel-sucursal'),
        inputArticulo:  document.getElementById('input-articulo'),
        inputDesc:      document.getElementById('input-desc'),
        inputDesde:     document.getElementById('input-desde'),
        inputHasta:     document.getElementById('input-hasta'),
        btnApply:       document.getElementById('btn-apply-filters'),
        btnClear:       document.getElementById('btn-clear-filters'),

        // KPIs
        kpiIngresos:    document.getElementById('kpi-ingresos'),
        kpiVentaNY:     document.getElementById('kpi-venta-ny'),
        kpiVentaFR:     document.getElementById('kpi-venta-fr'),
        kpiVentaPP:     document.getElementById('kpi-venta-pp'),
        kpiVentaFQ:     document.getElementById('kpi-venta-fq'),
        kpiStockSuc:    document.getElementById('kpi-stock-suc'),
        kpiStockCen:    document.getElementById('kpi-stock-cen'),
        kpiPedidos:     document.getElementById('kpi-pedidos'),

        // Tablas & Contenedores
        tbodyIngresos:  document.getElementById('tbody-ingresos'),
        imgPreview:     document.getElementById('product-img-preview'),
        imgLabel:       document.getElementById('product-img-label'),
        productNoImg:   document.getElementById('product-no-img'),
        
        tableMatrix:    document.getElementById('table-ventas-matrix'),
        theadMatrix:    document.getElementById('thead-ventas-matrix'),
        tbodyMatrix:    document.getElementById('tbody-ventas-matrix'),

        tbodyStockSuc:  document.getElementById('tbody-stock-suc'),
        tbodyStockCen:  document.getElementById('tbody-stock-cen'),

        loadingOverlay:    document.getElementById('loading-overlay'),
        loadingStatusText: document.getElementById('loading-status-text')
    };

    function numFmt(n) {
        if (n == null || isNaN(n)) return '0';
        return Number(n).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function showLoading(show = true, statusMsg = 'Cargando indicadores...') {
        if (el.loadingOverlay) {
            el.loadingOverlay.style.display = show ? 'flex' : 'none';
        }
        if (el.loadingStatusText && statusMsg) {
            el.loadingStatusText.textContent = statusMsg;
        }
        if (show) {
            document.body.classList.add('is-loading');
        } else {
            document.body.classList.remove('is-loading');
        }
    }

    function getFilters() {
        return {
            proveedor:   el.selProveedor ? el.selProveedor.value : '',
            rubro:       el.selRubro ? el.selRubro.value : '',
            categoria:   el.selCategoria ? el.selCategoria.value : '',
            temporada:   el.selTemporada ? el.selTemporada.value : '',
            canal:       el.selCanal ? el.selCanal.value : '',
            sucursal:    el.selSucursal ? el.selSucursal.value : '',
            articulo:    el.inputArticulo ? el.inputArticulo.value.trim() : '',
            descripcion: el.inputDesc ? el.inputDesc.value.trim() : '',
            fecha_desde: el.inputDesde ? el.inputDesde.value : '',
            fecha_hasta: el.inputHasta ? el.inputHasta.value : ''
        };
    }

    function buildQueryString(params) {
        const query = new URLSearchParams();
        for (const key in params) {
            if (params[key]) {
                query.append(key, params[key]);
            }
        }
        return query.toString();
    }

    /* ── 1. Cargar Filtros ────────────────────────────────────── */
    async function initFilters() {
        try {
            const res = await fetch('/bi/abastecimiento/ajax/filtros.php');
            const json = await res.json();
            if (!json.ok) return;

            const d = json.data;
            populateSelect(el.selProveedor, d.proveedores);
            populateSelect(el.selRubro, d.rubros);
            populateSelect(el.selCategoria, d.categorias);
            populateSelect(el.selTemporada, d.temporadas);
            populateSelect(el.selCanal, d.canales);
            populateSelect(el.selSucursal, d.sucursales);
        } catch (e) {
            console.error('Error cargando filtros:', e);
        }
    }

    function populateSelect(selectEl, items) {
        if (!selectEl || !items) return;
        const currentVal = selectEl.value;
        selectEl.innerHTML = '<option value="">Todos</option>';
        items.forEach(item => {
            if (!item) return;
            const opt = document.createElement('option');
            opt.value = item;
            opt.textContent = item;
            selectEl.appendChild(opt);
        });
        if (currentVal) selectEl.value = currentVal;
    }

    /* ── 2. Cargar KPIs ──────────────────────────────────────── */
    async function loadKPIs() {
        try {
            const qs = buildQueryString(getFilters());
            const res = await fetch(`/bi/abastecimiento/ajax/kpis.php?${qs}`);
            const json = await res.json();
            if (!json.ok) return;

            const k = json.kpis;
            if (el.kpiIngresos) el.kpiIngresos.textContent = numFmt(k.total_ingresos);
            if (el.kpiVentaNY)  el.kpiVentaNY.textContent  = numFmt(k.venta_cen_my);
            if (el.kpiVentaFR)  el.kpiVentaFR.textContent  = numFmt(k.venta_cen_fq);
            if (el.kpiVentaPP)  el.kpiVentaPP.textContent  = numFmt(k.venta_pp);
            if (el.kpiVentaFQ)  el.kpiVentaFQ.textContent  = numFmt(k.venta_fq);
            if (el.kpiStockSuc) el.kpiStockSuc.textContent = numFmt(k.stock_sucursal);
            if (el.kpiStockCen) el.kpiStockCen.textContent = numFmt(k.stock_central_disponible);
            if (el.kpiPedidos)  el.kpiPedidos.textContent  = numFmt(k.pedidos_abiertos);
        } catch (e) {
            console.error('Error cargando KPIs:', e);
        }
    }

    /* ── 3. Cargar Ingresos ──────────────────────────────────── */
    async function loadIngresos() {
        if (!el.tbodyIngresos) return;
        try {
            const qs = buildQueryString(getFilters());
            const res = await fetch(`/bi/abastecimiento/ajax/ingresos.php?${qs}`);
            const json = await res.json();
            if (!json.ok) {
                el.tbodyIngresos.innerHTML = `<tr><td colspan="7" class="center" style="color:var(--neg)">Error: ${json.error}</td></tr>`;
                return;
            }

            if (json.data.length === 0) {
                el.tbodyIngresos.innerHTML = '<tr><td colspan="7" class="center">No hay ingresos registrados con los filtros seleccionados</td></tr>';
                return;
            }

            let html = '';
            json.data.forEach((r, idx) => {
                const isActive = (selectedArticulo === r.cod_articu) ? 'active-row' : '';
                html += `
                    <tr class="${isActive}" data-articulo="${r.cod_articu}" data-modelo="${r.modelo}">
                        <td>${r.fecha_mov}</td>
                        <td><strong>${r.cod_articu}</strong></td>
                        <td>${r.descripcion}</td>
                        <td>${r.color}</td>
                        <td>${r.cod_pro_cl || r.proveedor}</td>
                        <td class="num font-semibold">${numFmt(r.cantidad)}</td>
                        <td>${r.n_comp}</td>
                    </tr>
                `;
            });
            el.tbodyIngresos.innerHTML = html;

            // Seleccionar automáticamente el primer artículo si no hay ninguno seleccionado
            if (!selectedArticulo && json.data.length > 0) {
                // Preferir artículos de producto terminado (código tipo X... o longitud >= 10) si los hay
                const firstProduct = json.data.find(d => /^X[A-Z0-9]{5,}/i.test(d.cod_articu)) || json.data[0];
                selectProduct(firstProduct.cod_articu, firstProduct.descripcion);
            }
        } catch (e) {
            console.error('Error cargando ingresos:', e);
            el.tbodyIngresos.innerHTML = '<tr><td colspan="7" class="center" style="color:var(--neg)">Error al conectar con el servidor</td></tr>';
        }
    }

    /* ── 4. Cargar Matriz de Ventas ──────────────────────────── */
    async function loadVentas() {
        try {
            const qs = buildQueryString(getFilters());
            const res = await fetch(`/bi/abastecimiento/ajax/ventas.php?${qs}`);
            const json = await res.json();
            if (!json.ok) {
                if (el.tbodyMatrix) {
                    el.tbodyMatrix.innerHTML = `<tr><td colspan="2" class="center" style="color:var(--neg)">Error cargando ventas: ${json.error || 'No disponible'}</td></tr>`;
                }
                return;
            }

            renderVentasMatrix(json.matriz);
        } catch (e) {
            console.error('Error cargando ventas:', e);
            if (el.tbodyMatrix) {
                el.tbodyMatrix.innerHTML = '<tr><td colspan="2" class="center" style="color:var(--neg)">Error al conectar con el servidor para la matriz de ventas</td></tr>';
            }
        }
    }

    /* Estado de expansión de la matriz jerárquica - por defecto TODO cerrado */
    let matrixData = null;
    let expandedYears = new Set();
    let expandedMonths = new Set();
    let expandedCanales = new Set(); // Para aperturar CEN en CEN a MY y CEN a FQ

    /* ── 4. Cargar Matriz de Ventas ──────────────────────────── */
    async function loadVentas() {
        try {
            const qs = buildQueryString(getFilters());
            const res = await fetch(`/bi/abastecimiento/ajax/ventas.php?${qs}`);
            const json = await res.json();
            if (!json.ok) {
                if (el.tbodyMatrix) {
                    el.tbodyMatrix.innerHTML = `<tr><td colspan="2" class="center" style="color:var(--neg)">Error cargando ventas: ${json.error || 'No disponible'}</td></tr>`;
                }
                return;
            }

            matrixData = json.matriz;
            // Por defecto: Todas las columnas aparecen CERRADAS a nivel año
            renderVentasMatrix();
        } catch (e) {
            console.error('Error cargando ventas:', e);
            if (el.tbodyMatrix) {
                el.tbodyMatrix.innerHTML = '<tr><td colspan="2" class="center" style="color:var(--neg)">Error al conectar con el servidor para la matriz de ventas</td></tr>';
            }
        }
    }

    function renderVentasMatrix() {
        if (!el.theadMatrix || !el.tbodyMatrix || !matrixData) return;

        const { hierarchy, filas } = matrixData;
        if (!filas || filas.length === 0 || !hierarchy || hierarchy.length === 0) {
            el.theadMatrix.innerHTML = '<tr><th>Artículo</th><th class="num">Total</th></tr>';
            el.tbodyMatrix.innerHTML = '<tr><td colspan="2" class="center">Sin datos de ventas en el período</td></tr>';
            return;
        }

        // Construir columnas terminales
        const leafCols = [];
        const rowYear = ['<th rowspan="3" style="min-width:140px; vertical-align:middle;">Artículo</th>'];
        const rowMonth = [];
        const rowCanal = [];

        hierarchy.forEach(y => {
            const isYearExp = expandedYears.has(y.anio);
            const months = y.meses || [];

            if (!isYearExp) {
                // Año colapsado
                leafCols.push({ type: 'year', anio: y.anio, key: `${y.anio}` });
                rowYear.push(`<th class="th-level-year" data-year="${y.anio}" title="Click para abrir meses"><i class="bi bi-plus-square matrix-expand-icon"></i> ${y.anio}</th>`);
                rowMonth.push(`<th class="th-level-month" style="opacity:0.6;">Total</th>`);
                rowCanal.push(`<th class="th-level-canal" style="opacity:0.6;">—</th>`);
            } else {
                // Año expandido
                let yearColspan = 0;
                months.forEach(m => {
                    const mKey = `${y.anio}_${m.mes}`;
                    const isMonthExp = expandedMonths.has(mKey);

                    if (!isMonthExp) {
                        yearColspan += 1;
                        leafCols.push({ type: 'month', anio: y.anio, mes: m.mes, key: mKey });
                        rowMonth.push(`<th class="th-level-month" data-month-key="${mKey}" title="Click para abrir canales"><i class="bi bi-plus-square matrix-expand-icon"></i> ${m.nombre}</th>`);
                        rowCanal.push(`<th class="th-level-canal" style="opacity:0.6;">Total</th>`);
                    } else {
                        // Mes expandido: iterar canales (CEN, PP, FQ, etc.)
                        let monthColspan = 0;
                        m.canales.forEach(cObj => {
                            const canalName = cObj.canal;
                            const cKey = `${y.anio}_${m.mes}_${canalName}`;
                            const isCanalExp = (canalName === 'CEN' && expandedCanales.has(cKey));

                            if (canalName === 'CEN') {
                                if (!isCanalExp) {
                                    monthColspan += 1;
                                    leafCols.push({ type: 'canal', anio: y.anio, mes: m.mes, canal: 'CEN', key: cKey });
                                    rowCanal.push(`<th class="th-level-canal" data-canal-key="${cKey}" style="cursor:pointer;" title="Click para abrir CEN a MY / FQ"><i class="bi bi-plus-square matrix-expand-icon"></i> CEN</th>`);
                                } else {
                                    // Apertura CEN a MY y CEN a FQ
                                    monthColspan += 2;
                                    leafCols.push({ type: 'subcanal', anio: y.anio, mes: m.mes, canal: 'CEN', subcanal: 'MY', key: `${cKey}_MY` });
                                    leafCols.push({ type: 'subcanal', anio: y.anio, mes: m.mes, canal: 'CEN', subcanal: 'FQ', key: `${cKey}_FQ` });
                                    rowCanal.push(`<th class="th-level-canal" data-canal-key="${cKey}" style="cursor:pointer; background:#1b2a4a !important;" title="Click para cerrar apertura"><i class="bi bi-dash-square matrix-expand-icon"></i> CEN a MY</th>`);
                                    rowCanal.push(`<th class="th-level-canal" data-canal-key="${cKey}" style="cursor:pointer; background:#1b2a4a !important;" title="Click para cerrar apertura"><i class="bi bi-dash-square matrix-expand-icon"></i> CEN a FQ</th>`);
                                }
                            } else {
                                monthColspan += 1;
                                leafCols.push({ type: 'canal', anio: y.anio, mes: m.mes, canal: canalName, key: cKey });
                                rowCanal.push(`<th class="th-level-canal">${canalName}</th>`);
                            }
                        });

                        yearColspan += monthColspan;
                        rowMonth.push(`<th class="th-level-month" colspan="${monthColspan}" data-month-key="${mKey}" title="Click para cerrar canales"><i class="bi bi-dash-square matrix-expand-icon"></i> ${m.nombre}</th>`);
                    }
                });

                rowYear.push(`<th class="th-level-year" colspan="${yearColspan}" data-year="${y.anio}" title="Click para cerrar meses"><i class="bi bi-dash-square matrix-expand-icon"></i> ${y.anio}</th>`);
            }
        });

        // Columna TOTAL ALINEADA A LA DERECHA
        rowYear.push('<th rowspan="3" class="num matrix-total-col" style="min-width:85px; vertical-align:middle; border-left:2px solid #2563eb !important;">TOTAL</th>');

        el.theadMatrix.innerHTML = `
            <tr>${rowYear.join('')}</tr>
            <tr>${rowMonth.join('')}</tr>
            <tr>${rowCanal.join('')}</tr>
        `;

        // Filas del cuerpo de la tabla
        let tbHtml = '';
        filas.forEach(f => {
            const isActive = (selectedArticulo === f.articulo) ? 'active-row' : '';
            tbHtml += `<tr class="${isActive}" data-articulo="${f.articulo}">`;
            tbHtml += `<td><strong>${f.articulo}</strong></td>`;

            leafCols.forEach(col => {
                let val = 0;
                if (col.type === 'subcanal' || col.type === 'canal') {
                    val = f.data[col.key] || 0;
                } else if (col.type === 'month') {
                    // Sumar todos los canales de ese mes
                    const prefix = `${col.anio}_${col.mes}_`;
                    for (const k in f.data) {
                        // Tomar solo claves primarias de canal (evitar doble suma de subcanales)
                        if (k.startsWith(prefix) && k.split('_').length === 3) val += f.data[k];
                    }
                } else if (col.type === 'year') {
                    // Sumar todo el año
                    const prefix = `${col.anio}_`;
                    for (const k in f.data) {
                        if (k.startsWith(prefix) && k.split('_').length === 3) val += f.data[k];
                    }
                }
                tbHtml += `<td class="num">${val > 0 ? numFmt(val) : '—'}</td>`;
            });

            // Total al final (derecha)
            tbHtml += `<td class="num font-semibold matrix-total-col" style="border-left:2px solid #2563eb !important;">${numFmt(f.total)}</td>`;
            tbHtml += '</tr>';
        });
        el.tbodyMatrix.innerHTML = tbHtml;
    }

    /* ── 5. Cargar Stock Sucursal & Central ───────────────────── */
    async function loadStock() {
        if (!el.tbodyStockSuc || !el.tbodyStockCen) return;

        try {
            const qs = buildQueryString(getFilters());
            const res = await fetch(`/bi/abastecimiento/ajax/stock.php?${qs}`);
            const json = await res.json();
            if (!json.ok) return;

            // Stock Sucursal
            if (json.stock_sucursal.length === 0) {
                el.tbodyStockSuc.innerHTML = '<tr><td colspan="4" class="center">Sin stock en sucursales</td></tr>';
            } else {
                let htmlS = '';
                json.stock_sucursal.forEach(r => {
                    const isActive = (selectedArticulo === r.articulo) ? 'active-row' : '';
                    htmlS += `
                        <tr class="${isActive}" data-articulo="${r.articulo}">
                            <td><strong>${r.articulo}</strong></td>
                            <td>${r.sucursal}</td>
                            <td>${r.color}</td>
                            <td class="num font-semibold">${numFmt(r.stock_sucursal)}</td>
                        </tr>
                    `;
                });
                el.tbodyStockSuc.innerHTML = htmlS;
            }

            // Stock Central
            if (json.stock_central.length === 0) {
                el.tbodyStockCen.innerHTML = '<tr><td colspan="4" class="center">Sin stock en depósito central</td></tr>';
            } else {
                let htmlC = '';
                json.stock_central.forEach(r => {
                    const isActive = (selectedArticulo === r.articulo) ? 'active-row' : '';
                    htmlC += `
                        <tr class="${isActive}" data-articulo="${r.articulo}">
                            <td><strong>${r.articulo}</strong></td>
                            <td>${r.color}</td>
                            <td class="num font-semibold">${numFmt(r.disponible)}</td>
                            <td class="num">${numFmt(r.temporal)}</td>
                        </tr>
                    `;
                });
                el.tbodyStockCen.innerHTML = htmlC;
            }
        } catch (e) {
            console.error('Error cargando stock:', e);
        }
    }

    /* ── 6. Cargar Imagen del Producto ───────────────────────── */
    function selectProduct(articulo, desc = '') {
        if (!articulo) return;
        selectedArticulo = articulo;

        document.querySelectorAll('.abast-table tr[data-articulo]').forEach(tr => {
            if (tr.getAttribute('data-articulo') === articulo) {
                tr.classList.add('active-row');
            } else {
                tr.classList.remove('active-row');
            }
        });

        if (el.imgLabel) {
            el.imgLabel.textContent = `${articulo} ${desc ? '— ' + desc : ''}`;
            el.imgLabel.style.display = 'block';
        }

        // URL directa exacta de la imagen en el servidor
        const directImgUrl = `https://app.xl.com.ar/Imagenes/${encodeURIComponent(articulo)}.jpg`;

        if (el.productNoImg) el.productNoImg.style.display = 'none';
        if (el.imgPreview) {
            el.imgPreview.style.display = 'block';
            el.imgPreview.src = directImgUrl;

            el.imgPreview.onerror = () => {
                // Si falla por talle al final (ej: termina en L, M, S, 36, 40)
                let baseCod = articulo;
                if (articulo.length > 13) {
                    baseCod = articulo.substring(0, 13);
                } else if (articulo.length > 8 && /[A-Z0-9]$/.test(articulo)) {
                    baseCod = articulo.replace(/[A-Z]{1,2}$/, '');
                }

                if (baseCod !== articulo) {
                    el.imgPreview.onerror = () => {
                        el.imgPreview.style.display = 'none';
                        if (el.productNoImg) {
                            el.productNoImg.innerHTML = `
                                <i class="bi bi-card-image" style="font-size: 3rem; color: #94a3b8; margin-bottom: 8px;"></i>
                                <span style="color: #64748b; font-size: 0.88rem;">Sin imagen disponible para <strong>${articulo}</strong></span>
                            `;
                            el.productNoImg.style.display = 'flex';
                        }
                    };
                    el.imgPreview.src = `https://app.xl.com.ar/Imagenes/${encodeURIComponent(baseCod)}.jpg`;
                } else {
                    el.imgPreview.style.display = 'none';
                    if (el.productNoImg) {
                        el.productNoImg.innerHTML = `
                            <i class="bi bi-card-image" style="font-size: 3rem; color: #94a3b8; margin-bottom: 8px;"></i>
                            <span style="color: #64748b; font-size: 0.88rem;">Sin imagen disponible para <strong>${articulo}</strong></span>
                        `;
                        el.productNoImg.style.display = 'flex';
                    }
                }
            };
        }
    }

    /* ── 7. Recargar Todo (Carga en Paralelo) ─────────────────── */
    async function reloadAll() {
        showLoading(true, 'Cargando ingresos, ventas y stock...');

        const pIngresos = loadIngresos().then(() => {
            if (el.loadingStatusText) el.loadingStatusText.textContent = 'Ingresos cargados...';
        });
        const pStock = loadStock().then(() => {
            if (el.loadingStatusText) el.loadingStatusText.textContent = 'Stock central y sucursales cargados...';
        });
        const pKPIs = loadKPIs().then(() => {
            if (el.loadingStatusText) el.loadingStatusText.textContent = 'Indicadores consolidados listos...';
        });
        const pVentas = loadVentas().then(() => {
            if (el.loadingStatusText) el.loadingStatusText.textContent = 'Matriz de ventas calculada...';
        });

        await Promise.allSettled([pIngresos, pStock, pKPIs, pVentas]);
        showLoading(false);
    }

    /* ── 8. Bind Events ──────────────────────────────────────── */
    function bindEvents() {
        if (el.btnApply) {
            el.btnApply.addEventListener('click', reloadAll);
        }

        if (el.btnClear) {
            el.btnClear.addEventListener('click', () => {
                if (el.selProveedor) el.selProveedor.value = '';
                if (el.selRubro) el.selRubro.value = '';
                if (el.selCategoria) el.selCategoria.value = '';
                if (el.selTemporada) el.selTemporada.value = '';
                if (el.selCanal) el.selCanal.value = '';
                if (el.selSucursal) el.selSucursal.value = '';
                if (el.inputArticulo) el.inputArticulo.value = '';
                if (el.inputDesc) el.inputDesc.value = '';
                const yStart = new Date().getFullYear() + '-01-01';
                if (el.inputDesde) el.inputDesde.value = yStart;
                reloadAll();
            });
        }

        document.addEventListener('click', (e) => {
            // Click en encabezado de Año
            const thYear = e.target.closest('.th-level-year');
            if (thYear) {
                const year = parseInt(thYear.getAttribute('data-year'), 10);
                if (year) {
                    if (expandedYears.has(year)) {
                        expandedYears.delete(year);
                    } else {
                        expandedYears.add(year);
                    }
                    renderVentasMatrix();
                }
                return;
            }

            // Click en encabezado de Mes
            const thMonth = e.target.closest('.th-level-month');
            if (thMonth) {
                const mKey = thMonth.getAttribute('data-month-key');
                if (mKey) {
                    if (expandedMonths.has(mKey)) {
                        expandedMonths.delete(mKey);
                    } else {
                        expandedMonths.add(mKey);
                    }
                    renderVentasMatrix();
                }
                return;
            }

            // Click en encabezado de Canal (apertura de CEN a CEN a MY / CEN a FQ)
            const thCanal = e.target.closest('th[data-canal-key]');
            if (thCanal) {
                const cKey = thCanal.getAttribute('data-canal-key');
                if (cKey) {
                    if (expandedCanales.has(cKey)) {
                        expandedCanales.delete(cKey);
                    } else {
                        expandedCanales.add(cKey);
                    }
                    renderVentasMatrix();
                }
                return;
            }

            // Click en fila de artículo
            const tr = e.target.closest('tr[data-articulo]');
            if (tr) {
                const art = tr.getAttribute('data-articulo');
                const desc = tr.querySelector('td:nth-child(3)') ? tr.querySelector('td:nth-child(3)').textContent : '';
                selectProduct(art, desc);
            }
        });
    }

    /* ── Inicialización ──────────────────────────────────────── */
    function init() {
        showLoading(true, 'Inicializando filtros e indicadores...');
        bindEvents();
        initFilters();
        reloadAll();
    }

    return { init, selectProduct, reloadAll };
})();

document.addEventListener('DOMContentLoaded', () => {
    Abastecimiento.init();
});
