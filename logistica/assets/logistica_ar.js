/* /bi/logistica/assets/logistica_ar.js
   Dashboard Logística AR — IIFE jQuery
   Requiere logistica_core.js (window.LogiCore) cargado antes.
   Stack: jQuery 3.7, Chart.js 4.4
   ============================================================ */
;(function ($) {
    'use strict';

    // ── Aliases desde LogiCore ───────────────────────────────────────────
    const fmt           = LogiCore.fmt;
    const setVar        = LogiCore.setVar;
    const escapeHtml    = LogiCore.escapeHtml;
    const chartOptions  = LogiCore.chartOptions;
    const PALETTE       = LogiCore.PALETTE;
    const showOverlay   = LogiCore.showOverlay;
    const hideOverlay   = LogiCore.hideOverlay;
    const setReload     = LogiCore.setReload;
    const initInfoPopover = LogiCore.initInfoPopover;
    const addInfoButton   = LogiCore.addInfoButton;

    // ── Estado global ────────────────────────────────────────────────────
    const State = {
        desde    : '',
        hasta    : '',
        activeTab: 'eficiencia',
        canal    : '',
        rubro    : '',
        deposito : '01',   // Inventario: depósito por defecto
        rubroFact: '',
        usuario  : '',
        cliente  : '',
        tipo     : 'REPOSICION',   // Remisión arranca filtrando por Reposición (como el tablero viejo)
        pendFiltro : 'HOY',
        pendientes : [],
        estDias     : 90,      // Estancados: antigüedad mínima
        estCobertura: 'ALL',   // ALL | CON STOCK | STOCK PARCIAL | SIN STOCK
        estBusca    : '',
        estCliente  : '',      // filtro por clic en "Concentración por cliente"
        estancados  : [],
        estTotal    : 0,
        frFecha     : '',      // Fill Rate: '' = último día con datos (lo resuelve el SP)
        frFechaMax  : '',
        frTipo      : '',      // '' | REPOSICION | DIST. INICIAL
        frFiltro    : 'ALL',   // ALL | COMPLETO | PARCIAL | SIN PEDIDO
        frBusca     : '',
        frDetalle   : [],
        forceRefresh: false,
    };

    const Cache = {};
    let filtrosPromise = null;

    let chartEfi         = null;
    let chartsCanalEfi   = [];
    let chartPerdidaSpark = null;   // mini-gráfico dentro del KPI de pérdida
    let chartPerdidaModal = null;   // versión ampliada (modal)
    let perdida12mData    = [];     // serie proporción/importe pérdida (últ. 12 meses)
    let efiPedidosGroups  = [];     // pedidos agrupados por cliente (tabla drill)
    let chartLtHist      = null;
    let chartLtEvol      = null;
    let chartStock       = null;
    let chartProdFact    = null;
    let chartProdPicking = null;
    let chartPedidos     = null;
    let chartPlanWip     = null;
    let chartDespEvol    = null;
    let chartsPlanGauges = [];
    let chartsDespCanal  = [];
    let chartPickingDia  = null;

    // Charts y estado para Evolución Tipo de Remisión
    let chartRemDist     = null;
    let chartRemRepo     = null;
    let chartRemPropios  = null;
    let chartRemFranq    = null;
    let chartRemTotal    = null;
    let remisionRawData  = null;
    let remisionMetric   = 'unidades'; // 'unidades' | 'pedidos'
    let remisionFilterMes = null;

    const ult7Data       = {};   // base ('picking-ult7'|'fact-ult7') -> { rows, title }

    // Detalle de artículos con diferencia (drill-down del detalle por rubro)
    let stockDetalleArticulos = [];
    let stockExportInit = false;

    const BASE = '/bi/logistica/ajax/';

    const TAB_SLICERS = {
        'eficiencia'        : ['wrap-canal'],
        'leadtime'          : ['wrap-canal'],
        'stock'             : ['wrap-rubro', 'wrap-deposito'],
        'prod-fact'         : ['wrap-tipo', 'wrap-rubro', 'wrap-canal'],
        'prod-picking'      : ['wrap-usuario'],
        'planificacion'     : ['wrap-canal'],
        'despacho'          : ['wrap-canal', 'wrap-cliente'],
        'pedidos'           : ['wrap-canal'],
        'evolucion-remision': ['wrap-rubro'],
        'estancados'        : ['wrap-canal'],
        'fill-rate'         : ['wrap-canal'],
    };

    const HELP = {
        kpis: {
            'kv-efi'            : ['Eficiencia de remisión', ['Proporción de las unidades pedidas que ya fueron remitidas.', '= Unidades remitidas ÷ Unidades pedidas', 'Pedidos por fecha de pedido. Excluye cancelados, Dist. Inicial y pedidos sin ninguna unidad remitida.', 'Meta: 95%. Verde si se alcanza, rojo si está por debajo.', 'Para ver lo remitido por día y a qué pedido corresponde cada remito, ver la pestaña Fill Rate.']],
            'kv-unid-ped'       : ['Unidades pedidas', ['Total de unidades solicitadas en los pedidos del período (misma base que la eficiencia).', '= Var. % = (Período actual − Año anterior) ÷ Año anterior', 'Año anterior: mismas fechas un año atrás, con los mismos filtros.']],
            'kv-unid-fact'      : ['Unidades remitidas', ['Unidades efectivamente remitidas de los pedidos del período y filtros activos.']],
            'kv-perdida'        : ['Pérdida de remisión', ['Importe pendiente de remitir de los pedidos del período.', '= Pérdida = Σ Importe pendiente', '= % Pérdida = Importe pendiente ÷ Importe pedido', 'Solo pedidos con al menos una unidad remitida; excluye cancelados y Dist. Inicial. "prev": mismo cálculo un año atrás.', 'Tocá el ícono de gráfico para dar vuelta la tarjeta y ver la evolución de los últimos 12 meses (ampliable).']],
            'kv-importe'        : ['Importe remitido', ['Importe total remitido en el período y filtros activos, expresado en pesos.', '= Importe pedido − Importe pendiente']],
            'kv-pedidos'        : ['Pedidos totales', ['Cantidad de pedidos distintos incluidos en el cálculo de eficiencia.', '= Var. % = (Período actual − Año anterior) ÷ Año anterior']],
            'kv-lt-total'       : ['Comprobantes remitidos', ['Cantidad de comprobantes de remisión distintos con fecha de comprobante en el período.']],
            'kv-lt-dem'         : ['Remisión demorada', ['Comprobantes con lead time mayor a 5 días corridos desde el pedido.', '= % Demorada = Comprobantes con lead time > 5 días ÷ Total de comprobantes', 'Lead time = Fecha de comprobante − Fecha de pedido, en días corridos (incluye fines de semana y feriados).', 'Rojo si supera el 10%.']],
            'kv-lt-prom'        : ['Lead time promedio', ['Promedio de días corridos entre la fecha del pedido y la fecha de remisión.', '= Σ (Fecha de comprobante − Fecha de pedido) ÷ Cantidad de renglones remitidos']],
            'kv-lt-abiertos'    : ['Pedidos abiertos', ['Pedidos que hoy están en estado PENDIENTE.', 'No depende del rango de fechas seleccionado: muestra la situación actual. Respeta el filtro de canal.']],
            'kv-stock-tango'    : ['Stock Tango', ['Stock registrado en el sistema Tango para los rubros filtrados.']],
            'kv-stock-wms'      : ['Stock WMS', ['Stock registrado en el sistema WMS para los rubros filtrados.']],
            'kv-stock-dif'      : ['Diferencia neta', ['Diferencia entre stock WMS y stock Tango; los positivos y negativos se compensan.', '= Σ (WMS − Tango)', '= % = Diferencia neta ÷ Stock Tango', 'Verde si el desvío es menor al 1%.']],
            'kv-stock-dif-abs'  : ['Diferencia absoluta', ['Magnitud total del desvío, sin que los positivos y negativos se compensen.', '= Σ |WMS − Tango|']],
            'kv-stock-prec'     : ['Precisión de inventario', ['Qué parte del stock coincide entre WMS y Tango, ponderado por unidades.', '= 1 − (Diferencia absoluta ÷ Stock Tango)', 'Meta ideal: 99% o superior.']],
            'kv-pf-prom-dia'    : ['Promedio por día', ['Promedio de unidades remitidas por día con actividad, según el tipo de remisión y rubro seleccionados.', '= Unidades remitidas ÷ Días con actividad']],
            'kv-pf-pico-dia'    : ['Pico x día', ['Mayor cantidad de unidades remitidas en un único día del período (respeta los filtros).', '= Máx (unidades remitidas por día)']],
            'kv-pf-pico-user'   : ['Pico x usuario', ['Mayor cantidad de unidades remitidas por un usuario en un solo día. No depende del filtro de tipo de remisión. Respeta rubro y canal.', '= Máx (unidades por usuario y día)']],
            'kv-pf-tendencia'   : ['Tendencia x día x usuario', ['Rendimiento diario típico de un usuario. No depende del filtro de tipo de remisión. Respeta rubro y canal.', '= Mediana (unidades por usuario y día), solo días con más de 200 unidades']],
            'kv-pp-prom-dia'    : ['Promedio unid./día', ['Promedio de unidades pickeadas por día con actividad en el período.', '= Unidades pickeadas ÷ Días con actividad']],
            'kv-pp-pico-dia'    : ['Pico por día', ['Mayor cantidad de unidades preparadas en un único día del período.', '= Máx (unidades por día)']],
            'kv-pp-pico-usuario': ['Pico por usuario', ['Mayor cantidad de unidades preparadas por un único picker en un solo día del período.', '= Máx (unidades por picker y día)']],
            'kv-pp-prom'        : ['Promedio por picker', ['Unidades que prepara un picker en un día típico de trabajo.', '= Promedio (unidades por picker y día), solo días con 3 h productivas o más']],
            'kv-pp-u-hora'      : ['Promedio unid./hora', ['Unidades pickeadas por hora productiva registrada en el período (todos los días, sin mínimo de horas).', '= Unidades pickeadas ÷ Horas productivas']],
            'kv-pp-prom-hs'     : ['Promedio tiempo productivo', ['Horas productivas de un picker en un día típico de trabajo.', '= Promedio (horas por picker y día), solo días con 3 h productivas o más']],
            'kv-dsp-efi'        : ['Eficacia total (incluye demorados)', ['Proporción de comprobantes en término sobre todo el universo: incluye los demorados que todavía no se despacharon.', '= En término ÷ (En término + Fuera de plazo + Demorados)', 'Despachados: por fecha de guía. Demorados (aún sin despachar): por fecha comprometida.', 'Meta: 95%. Verde si se alcanza, rojo por debajo.']],
            'kv-dsp-dem-dias'   : ['Promedio días pedidos demorados', ['Promedio de días de desvío de los comprobantes demorados y fuera de plazo.', '= Promedio (Fecha comprometida − Fecha de guía)', 'Si todavía no se despachó, se toma la fecha de hoy. Negativo = atraso.']],
            'kv-dsp-guia-dias'  : ['Promedio días guía vs despacho', ['Promedio de días entre la fecha de guía y la fecha comprometida, sobre los comprobantes despachados.', '= Promedio (Fecha comprometida − Fecha de guía)', 'Negativo = despachado después de lo comprometido.']],
            'kv-pc-ped'         : ['Pedidos del período', ['Cantidad de pedidos distintos en el rango de fechas y canal seleccionados (incluye todos los estados).', '= Var. % = (Período actual − Año anterior) ÷ Año anterior']],
            'kv-pc-ped-aa'      : ['Pedidos año anterior', ['Pedidos del mismo rango de fechas en el año anterior, para comparación directa.']],
            'kv-pc-var'         : ['Variación absoluta', ['Diferencia en cantidad de pedidos entre el período actual y el año anterior.', '= Pedidos del período − Pedidos año anterior', 'Positivo indica más pedidos que el año anterior.']],
            'kv-pc-unid-ped'    : ['Unidades pedidas', ['Unidades solicitadas en los pedidos consolidados del período filtrado.']],
            'kv-pc-unid-fact'   : ['Unidades remitidas', ['Unidades ya remitidas correspondientes a los pedidos consolidados del período.']],
            'kv-rem-dist-total' : ['Distribución Total', ['Unidades remitidas (o pedidos) de tipo Distribución, incluida Dist. Inicial, por fecha de pedido.', '= Locales propios + Franquicias']],
            'kv-rem-repo-total' : ['Reposición Total', ['Unidades remitidas (o pedidos) de tipo Reposición, por fecha de pedido.', '= Locales propios + Franquicias']],
            'kv-rem-propios-total': ['Locales Propios', ['Unidades remitidas (o pedidos) para locales propios en el período.', '= Distribución + Reposición']],
            'kv-rem-franq-total': ['Franquicias', ['Unidades remitidas (o pedidos) para franquicias en el período.', '= Distribución + Reposición']],
            'kv-rem-gran-total' : ['Total General', ['Volumen global de Locales Propios y Franquicias.', '= Distribución Total + Reposición Total']],
            'kv-est-ped'        : ['Pedidos estancados', ['Mide PEDIDOS: pedidos del año en curso, sin cancelar, que todavía tienen unidades pendientes y fueron cargados hace más días que el umbral elegido.', '= Pedidos con unidades pendientes > 0 y (Hoy − Fecha de pedido) ≥ umbral', 'Abajo: cuántos están remitidos en parte y cuántos no tienen nada remitido.']],
            'kv-est-unid'       : ['Unidades pendientes', ['Mide UNIDADES: cuántas unidades faltan remitir en esos pedidos.', '= Σ Unidades pendientes']],
            'kv-est-imp'        : ['Importe pendiente', ['Mide PESOS: importe de las unidades que faltan remitir.', '= Σ Importe pendiente']],
            'kv-fr-unid'        : ['Unidades remitidas en el día', ['Mide UNIDADES: cuántas unidades salieron en todos los remitos del día elegido.', '= Σ Unidades de los remitos del día', 'Abajo: cuántas de esas unidades salieron en remitos que no se pudieron vincular a un pedido (sobre todo Dist. Inicial).']],
            'kv-fr-remitos'     : ['Remitos emitidos', ['Mide REMITOS: cantidad de remitos distintos emitidos en el día.', 'Un remito puede llevar artículos de varios pedidos.', 'Abajo: cuántos remitos no se pudieron vincular a un pedido.']],
            'kv-fr-pedidos'     : ['Pedidos con remito en el día', ['Mide PEDIDOS: cuántos pedidos distintos recibieron al menos una unidad en los remitos del día.']],
            'kv-fr-rate'        : ['Fill rate (% de unidades cumplidas)', ['Mide UNIDADES: de todas las unidades que pidieron los pedidos con remito en el día, qué porcentaje ya se remitió.', '= Unidades remitidas a hoy ÷ Unidades pedidas (de los pedidos con remito en el día)', 'Cuenta todo lo remitido del pedido hasta hoy, no solo lo de este día.', 'Meta: 95%.']],
            'kv-dsp-efi-desp'   : ['Despachado en término', ['De todo lo que se despachó en el período, qué parte salió dentro de la fecha comprometida.', '= En término ÷ (En término + Fuera de plazo)', 'No cuenta los demorados que todavía no se despacharon (esos sí entran en la Eficacia total).', 'Meta: 95%.']],
            'kv-fr-completos'   : ['Pedidos completos', ['Mide PEDIDOS: cuántos de los pedidos con remito en el día ya no tienen ninguna unidad pendiente.', '= % = Pedidos completos ÷ Pedidos con remito en el día']],
            'kv-est-dias'       : ['Antigüedad promedio', ['Días corridos promedio desde la fecha del pedido hasta hoy.', '= Promedio (Hoy − Fecha de pedido)', 'Abajo: el pedido estancado más viejo.']],
        },
        sections: {
            'gauges-canal'          : ['Eficiencia por canal', ['Cada gauge muestra la eficiencia (unidades remitidas / unidades pedidas) por canal.', 'La marca negra indica la meta del 95%. Verde ≥ 95%, amarillo ≥ 85%, rojo < 85%.']],
            'chart-eficiencia'      : ['Evolución mensual de eficiencia', ['Eficiencia mensual de remisión: una línea por año (año actual vs. año anterior).', 'Bandas de color = umbrales: verde ≥ 95%, amarillo 85–95%, rojo < 85%.', 'La línea roja punteada marca la meta del 95%.']],
            'tabla-efi-unid-cliente': ['% Eficiencia unidades por cliente', ['Los 10 clientes con menor eficiencia (unidades remitidas / pedidas) en el período.']],
            'tabla-efi-unid-rubro'  : ['% Eficiencia unidades por rubro', ['Eficiencia de unidades por rubro, ordenada de menor a mayor.']],
            'tabla-efi-pedidos'     : ['% Eficiencia por pedido y cliente', ['Eficiencia por pedido, agrupada por cliente. Clic en un cliente para desplegar sus pedidos.', 'Clic en un pedido para ver el detalle por rubro.']],
            'chart-leadtime-hist'   : ['Distribución de lead times', ['Cantidad de comprobantes agrupados por días corridos de demora entre pedido y remisión.', 'Barras rojas: más de 5 días (demorados). Barras verdes: en término.']],
            'chart-leadtime-evol'   : ['Evolución de remisión demorada', ['Porcentaje mensual de comprobantes con lead time mayor a 5 días corridos.', 'Muestra la tendencia de demoras en los últimos 12 meses.']],
            'chart-stock'           : ['Diferencia de stock por rubro', ['Compara stock Tango vs. WMS en los principales rubros logísticos.', 'Diferencias significativas entre barras indican desvíos de inventario.']],
            'tabla-stock'           : ['Detalle de stock por rubro', ['Tabla con stock Tango, WMS, diferencia neta, diferencia porcentual y precisión de inventario por rubro.']],
            'chart-prod-fact'       : ['Remisión diaria', ['Unidades remitidas por día en el período seleccionado.']],
            'tabla-usuarios-fact'   : ['Indicadores por usuario', ['Unidades remitidas, participación, pico y tendencia por usuario. Pico y días productivos no dependen del filtro de tipo de remisión.']],
            'tabla-fact-ult7'       : ['Unidades remitidas por usuario (últimos 7 días)', ['Unidades remitidas por día y usuario en los últimos 7 días. Clic en una fecha para ver el gráfico de ese día.']],
            'chart-prod-picking'    : ['Picking diario', ['Barras azules: unidades pickeadas por día. Línea naranja: promedio de unidades por hora productiva.']],
            'tabla-usuarios-picking': ['Productividad por picker', ['Resumen de unidades, porcentaje del equipo, pico, mediana, horas y promedio por hora para cada picker.']],
            'tabla-picking-ult7'    : ['Últimos 7 días por picker', ['Unidades pickeadas por día y picker en los últimos 7 días con actividad.', 'Las columnas de totales suman unidades y horas del período completo.']],
            'gauges-despacho-canal' : ['Eficacia de despacho por canal', ['Cada gauge muestra la eficacia de despacho (comprobantes en término / despachados) por canal.', 'La marca negra indica la meta del 95%. Verde ≥ 95%, amarillo ≥ 85%, rojo < 85%.']],
            'chart-despacho-evol'   : ['Evolución eficacia de despacho', ['Eficacia de despacho mensual: una línea por año (actual vs. anterior).', 'Bandas de color = umbrales: verde ≥ 95%, amarillo 85–95%, rojo < 85%.', 'La línea roja punteada marca la meta del 95%.']],
            'tabla-pend'            : ['Pedidos pendientes', ['Pedidos con unidades pendientes de despacho, filtrables por ventana de entrega: Hoy, Próxima entrega, Próxima entrega +1 día o Todos.']],
            'tabla-plan-demorados'  : ['Pedidos demorados', ['Pedidos pendientes cuya fecha de entrega comprometida ya venció, dentro de los últimos 30 días, ordenados por fecha de entrega.']],
            'tabla-efi-cliente'     : ['% Eficacia despacho por cliente', ['Eficacia de despacho y desvío promedio de días por cliente, ordenado de menor a mayor eficacia.']],
            'tabla-efi-pedido'      : ['Eficacia — desglose por pedido', ['Pedidos despachados fuera de plazo, con desvío en días entre fecha de guía y despacho comprometido.']],
            'tabla-dem-cliente'     : ['Pedidos demorados promedio por cliente', ['Promedio de días de demora y cantidad de pedidos demorados por cliente en el período.']],
            'tabla-dem-pedido'      : ['Pedidos demorados — desglose por pedido', ['Detalle de pedidos demorados con estado, fecha comprometida y días de demora.']],
            'chart-pedidos-evol'    : ['Evolución mensual de pedidos', ['Cantidad de pedidos consolidados por mes en los últimos 12 meses.']],
            'tabla-pedidos'         : ['Pedidos consolidados', ['Detalle de pedidos con estado, canal, talón y unidades pedidas, pendientes y remitidas.']],
            'chart-plan-wip'        : ['Pendiente a despachar por día de entrega', ['Cartera pendiente: unidades de pedidos pendientes con fecha de entrega de hoy en adelante, por día. Incluye pedidos que todavía no se empezaron.', '= Pendiente a despachar = Σ Unidades pendientes con fecha de entrega ≥ hoy', 'Muestra hoy y los próximos 10 días hábiles; lo posterior se agrupa en una barra.', 'Vencidas (en el encabezado): pendientes con entrega ya vencida en los últimos 30 días, no incluidas.', 'Lo que está en proceso (WIP) se ve en Prod. Picking.']],
            'tabla-wip-picking'     : ['WIP · picking en curso', ['Work In Progress: tareas de picking iniciadas hoy y todavía no terminadas. Se actualiza casi en tiempo real; usá Actualizar para refrescar.', '= % Avance = Unidades pickeadas ÷ Unidades asignadas de las tareas en curso', 'Demorada: abierta hace más de 2 horas (el 95% de las tareas termina en menos de ~107 min).', 'Colgada: iniciada en los últimos 7 días (antes de hoy) y nunca cerrada; no cuenta en el WIP y conviene cerrarla en el WMS.', 'La tabla de picking no tiene número de pedido ni canal: el WIP se ve por picker y tarea.']],
            'tabla-fr-ingreso'      : ['Pedidos cargados el día anterior', ['Los pedidos que entraron el día anterior al elegido (sin cancelados), mirados como lo haría supply chain: cuánto entró, si se cumple en plazo, cuándo hay que entregarlo y qué atender primero. La situación es la de hoy.', '1. Volumen: se compara con el promedio del mismo día de la semana en las 4 semanas anteriores (mismos filtros).', '= % de pedidos remitidos a tiempo = Remitidos a tiempo ÷ (Remitidos a tiempo + Remitidos tarde + Vencidos sin completar)', 'A tiempo: el último remito del pedido salió en su fecha de entrega comprometida o antes. Vencido: pasó la fecha y todavía tiene unidades pendientes.', 'En riesgo: todavía tiene pendientes y vence hoy o el próximo día hábil. En plazo: vence más adelante.', '3. Plazo: días entre la carga del pedido y su fecha de entrega comprometida.', 'Los pedidos completos cuyo remito no está vinculado al pedido (Ecommerce, Dist. Inicial de franquicias) no se pueden medir y quedan fuera del %.']],
            'tabla-fr-apertura'     : ['Remitos del día por canal y tipo', ['Lo remitido en el día, separado por canal y tipo de remisión.', 'Unidades remitidas y remitos emitidos miden lo que salió ese día; Pedidos y Pedidos completos cuentan pedidos.', '= Fill rate (% unidades) = Unidades remitidas a hoy ÷ Unidades pedidas de los pedidos del grupo']],
            'tabla-fr-detalle'      : ['Detalle: cada remito y su pedido', ['Cada fila es un remito del día y uno de los pedidos que lleva. Si un remito lleva varios pedidos, aparece en varias filas.', 'Unidades en este remito: lo que salió de ese pedido en ese remito. El resto de las columnas de unidades son del pedido completo.', '= % Unidades cumplidas = Unidades remitidas a hoy ÷ Unidades pedidas', 'Remitos sin pedido: remitos que no se pudieron vincular a un pedido.', 'Si un mismo artículo del remito figura en dos pedidos, sus unidades se reparten en proporción a lo pedido.', 'Clic en una fila con pedido para ver el detalle por rubro.']],
            'est-cobertura'         : ['¿Hay stock para lo pendiente?', ['Arriba: de las unidades pendientes, cuántas tienen stock disponible (stock Tango del depósito 01) y cuántas no.', '= % con stock = Unidades pendientes con stock ÷ Unidades pendientes', 'Los pedidos se agrupan según la cobertura de su saldo:', 'Con stock: hay stock del artículo para todas las unidades pendientes.', 'Stock parcial: hay stock solo para parte de las unidades pendientes.', 'Sin stock: no hay stock para ninguna unidad pendiente.', 'El stock se compara artículo por artículo sin descontar lo que piden otros pedidos.', 'Clic en un grupo para filtrar el listado.']],
            'tabla-est-meses'       : ['¿Desde cuándo se acumula?', ['Pedidos estancados según el mes en que se cargaron (solo año en curso).', 'Sirve para ver si el problema es viejo y se arrastra, o si se está generando ahora.']],
            'tabla-est-clientes'    : ['¿Por dónde empezar?', ['Los 30 clientes con más importe pendiente en pedidos estancados.', 'Pedidos con stock: pedidos de ese cliente con stock para todas sus unidades pendientes.', 'Clic en un cliente para filtrar el listado; clic de nuevo para quitar el filtro.']],
            'tabla-est-pedidos'     : ['Pedidos estancados', ['Cada pedido estancado con su cobertura de stock, ordenados por importe pendiente.', 'Sin remitir: ninguna unidad remitida. Remitido en parte: tiene saldo.', 'Pendientes con stock: unidades del saldo para las que hay stock del artículo.', 'Muestra hasta 3.000 pedidos; el Excel exporta lo que se ve con los filtros aplicados.', 'Clic en un pedido para ver el detalle por rubro.']],
        },
        tableHeaders: {
            'tabla-efi-unid-cliente': [
                'Cliente.',
                'Unidades pedidas en el período.',
                'Unidades remitidas en el período.',
                'Eficiencia: remitidas / pedidas. Verde ≥ 95%, amarillo 85–95%, rojo < 85%.',
            ],
            'tabla-efi-unid-rubro': [
                'Rubro.',
                'Unidades pedidas en el período.',
                'Unidades remitidas en el período.',
                'Eficiencia: remitidas / pedidas. Verde ≥ 95%, amarillo 85–95%, rojo < 85%.',
            ],
            'tabla-efi-pedidos': [
                'Cliente (agrupado) / número de pedido al desplegar.',
                'Fecha del pedido.',
                'Unidades pedidas.',
                'Unidades remitidas.',
                'Eficiencia: remitidas / pedidas.',
            ],
            'tabla-stock': [
                'Rubro logístico.',
                'Stock registrado en Tango.',
                'Stock registrado en WMS.',
                'Diferencia neta (WMS − Tango).',
                'Diferencia porcentual sobre Tango.',
                'Precisión: coincidencia exacta entre WMS y Tango.',
            ],
            'tabla-usuarios-fact': [
                'Usuario de remisión (agrupado sin distinguir mayúsculas).',
                'Unidades remitidas en el período (según tipo y rubro).',
                'Participación sobre el total remitido del período.',
                'Pico: máximo de unidades en un solo día (no depende del tipo).',
                'Tendencia: mediana de unidades por día del usuario.',
                'Días productivos (no depende del tipo de remisión).',
                'Unidades remitidas en los últimos 30 días.',
            ],
            'tabla-usuarios-picking': [
                'Usuario picker.',
                'Unidades pickeadas en el período.',
                'Participación sobre el total del equipo.',
                'Pico: máximo de unidades en un solo día.',
                'Mediana de unidades por día.',
                'Horas productivas registradas.',
                'Promedio de unidades por hora productiva.',
                'Unidades pickeadas en los últimos 30 días.',
            ],
            'tabla-pend': [
                'Número de pedido.',
                'Código de cliente.',
                'Nombre del cliente.',
                'Canal del pedido.',
                'Fecha comprometida de entrega.',
                'Unidades pendientes de despacho.',
            ],
            'tabla-plan-demorados': [
                'Número de pedido.',
                'Nombre del cliente.',
                'Canal del pedido.',
                'Fecha de entrega comprometida (ya vencida).',
                'Días de atraso (negativo = vencido hace N días).',
                'Unidades pendientes de despacho.',
            ],
            'tabla-efi-cliente': [
                'Nombre del cliente.',
                'Eficacia de despacho (en término / despachados).',
                'Desvío promedio en días (negativo = atraso).',
                'Comprobantes despachados.',
            ],
            'tabla-efi-pedido': [
                'Nombre del cliente.',
                'Número de pedido.',
                'Número de comprobante.',
                'Fecha comprometida de despacho.',
                'Fecha de guía (despacho real).',
                'Desvío en días (negativo = tarde).',
            ],
            'tabla-dem-cliente': [
                'Nombre del cliente.',
                'Promedio de días de demora.',
                'Cantidad de pedidos demorados.',
            ],
            'tabla-dem-pedido': [
                'Nombre del cliente.',
                'Número de pedido.',
                'Número de comprobante.',
                'Fecha comprometida de despacho.',
                'Estado de despacho.',
                'Días de demora (negativo = vencido).',
            ],
            'tabla-pedidos': [
                'Número de pedido.',
                'Canal del pedido.',
                'Estado actual del pedido.',
                'Fecha de ingreso del pedido.',
                'Talón asociado.',
                'Unidades pedidas.',
                'Unidades pendientes de remitir.',
                'Unidades ya remitidas.',
            ],
            'tabla-fr-plazos': [
                'Días entre la carga del pedido y su fecha de entrega comprometida.',
                'Pedidos cargados con ese plazo.',
                'Unidades pedidas en esos pedidos.',
                'Unidades de esos pedidos que todavía faltan remitir.',
                'Peso de cada plazo en las unidades que entraron.',
            ],
            'tabla-fr-ingreso': [
                'Canal.',
                'Pedidos cargados.',
                'Unidades pedidas.',
                'Unidades que todavía faltan remitir.',
                'Pedidos remitidos a tiempo sobre los pedidos medibles (pasar el cursor sobre el % para ver el detalle).',
                'Pedidos vencidos o que vencen hoy / el próximo día hábil con unidades pendientes.',
            ],
            'tabla-fr-riesgo': [
                'Número de pedido (clic para ver el detalle).',
                'Cliente.',
                'Canal.',
                'Fecha de entrega comprometida.',
                'Unidades pedidas.',
                'Unidades que todavía faltan remitir.',
                'Vencido: ya pasó la fecha. Vence pronto: vence hoy o el próximo día hábil.',
            ],
            'tabla-fr-detalle': [
                'Número de remito.',
                'Pedido al que corresponde (vacío: sin pedido asociado).',
                'Fecha de ingreso del pedido.',
                'Cliente.',
                'Canal.',
                'Tipo de remisión.',
                'Unidades de este pedido que salieron en este remito.',
                'Unidades que pidió el pedido en total.',
                'Unidades del pedido remitidas hasta hoy, sumando todos sus remitos.',
                'Unidades del pedido que todavía faltan remitir.',
                'Porcentaje de las unidades del pedido que ya se remitieron: remitidas a hoy ÷ pedidas.',
                'Completo: el pedido no tiene unidades pendientes. Parcial: todavía le faltan unidades.',
            ],
            'tabla-est-pedidos': [
                'Número de pedido.',
                'Fecha de carga del pedido.',
                'Días corridos desde la carga. Ámbar: 90–180. Rojo: más de 180.',
                'Canal del pedido.',
                'Cliente.',
                'Estado del pedido en Tango (un COMPLETO con saldo es un dato inconsistente).',
                'Sin remitir: ninguna unidad remitida. Remitido en parte: tiene saldo.',
                'Unidades pedidas en total.',
                'Unidades que faltan remitir.',
                'Unidades del saldo para las que hay stock del artículo (depósito 01).',
                'Importe de las unidades que faltan remitir.',
                'Con stock / Stock parcial / Sin stock para las unidades pendientes.',
            ],
        },
    };

    // ── Fetch AJAX ────────────────────────────────────────────────────────
    function buildQS(extra = {}) {
        if (State.forceRefresh) extra._nocache = Date.now();
        return $.param({ desde: State.desde, hasta: State.hasta, ...extra });
    }

    async function apiFetch(endpoint, extra = {}) {
        const url = BASE + endpoint + '.php?' + buildQS(extra);
        const res = await fetch(url);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Error en el servidor');
        return json.data;
    }

    // ── Inicialización de selectores ──────────────────────────────────────
    async function initFiltros() {
        try {
            const json = await fetchFiltros();
            if (!json.ok) return;
            poblarSelect('#sel-canal',   json.canales.filter(c => c.toUpperCase() !== 'DESCONOCIDO'),  'Todos');
            poblarSelect('#sel-rubro',   json.rubros_stock,     'Todos');
            poblarSelect('#sel-usuario', json.usuarios_fact,    'Todos');
            poblarSelect('#sel-cliente', json.clientes_despacho, 'Todos');
            poblarSelect('#sel-tipo',    json.tipos_fact,        'Todas');
        } catch (e) { console.error('Filtros:', e); }
    }

    function poblarSelect(sel, items, placeholder) {
        const $s = $(sel).empty().append($('<option>').val('').text(placeholder));
        (items || []).forEach(v => $s.append($('<option>').val(v).text(v)));
    }

    function fetchFiltros() {
        if (!filtrosPromise) {
            filtrosPromise = fetch(BASE + 'filtros.php').then(res => res.json());
        }
        return filtrosPromise;
    }

    // ── Slicers por tab ───────────────────────────────────────────────────
    function updateSlicers(tab) {
        $('#wrap-canal, #wrap-rubro, #wrap-deposito, #wrap-usuario, #wrap-cliente, #wrap-tipo').hide();
        (TAB_SLICERS[tab] || []).forEach(id => $('#' + id).show());
        if (tab === 'prod-picking') {
            fetchFiltros()
                .then(json => { if (json.ok) poblarSelect('#sel-usuario', json.usuarios_picking, 'Todos'); })
                .catch(() => {});
        } else if (tab === 'prod-fact') {
            // En Facturación el slicer Rubro usa los rubros de facturación.
            fetchFiltros()
                .then(json => {
                    if (!json.ok) return;
                    poblarSelect('#sel-tipo',  json.tipos_fact,  'Todas');
                    poblarSelect('#sel-rubro', json.rubros_fact, 'Todas');
                    $('#sel-tipo').val(State.tipo || '');
                    $('#sel-rubro').val(State.rubroFact || '');
                })
                .catch(() => {});
        } else if (tab === 'stock') {
            // Inventario usa los rubros y depósitos de stock.
            fetchFiltros()
                .then(json => {
                    if (!json.ok) return;
                    poblarSelect('#sel-rubro', json.rubros_stock, 'Todos');
                    $('#sel-rubro').val(State.rubro || '');
                    poblarSelect('#sel-deposito', json.depositos_stock, 'Todos');
                    $('#sel-deposito').val(State.deposito || '');
                })
                .catch(() => {});
        }
    }

    // ── Tabs ──────────────────────────────────────────────────────────────
    function switchTab(tab) {
        State.activeTab = tab;
        $('.tab-btn').removeClass('active');
        $(`.tab-btn[data-tab="${tab}"]`).addClass('active');
        $('.tab-pane').removeClass('active');
        $(`#tab-${tab}`).addClass('active');
        updateSlicers(tab);
        loadTab(tab);
    }

    async function loadTab(tab) {
        if (Cache[tab]) return;
        showOverlay();
        setReload(true);
        try {
            switch (tab) {
                case 'eficiencia':    await loadEficiencia(); break;
                case 'leadtime':      await loadLeadTime();   break;
                case 'stock':         await loadStock();       break;
                case 'prod-fact':     await loadProdFact();   break;
                case 'prod-picking':  await loadProdPicking();break;
                case 'planificacion': await loadPlanificacion(); break;
                case 'despacho':      await loadDespacho();   break;
                case 'pedidos':       await loadPedidos();    break;
                case 'evolucion-remision': await loadEvolucionRemision(); break;
                case 'estancados':    await loadEstancados(); break;
                case 'fill-rate':     await loadFillRate();   break;
            }
            Cache[tab] = true;
        } catch (e) {
            mostrarError(tab, e.message);
        } finally {
            setReload(false);
            hideOverlay();
        }
    }

    function invalidarCache() {
        Object.keys(Cache).forEach(k => delete Cache[k]);
    }

    function mostrarError(tab, msg) {
        const $pane = $(`#tab-${tab} .dash-content`);
        $pane.find('.error-state').remove();
        $pane.prepend(`<div class="error-state"><i class="bi bi-exclamation-triangle-fill"></i> ${msg}</div>`);
    }

    // ── Área 1: Eficiencia ────────────────────────────────────────────────
    async function loadEficiencia() {
        const data = await apiFetch('eficiencia', { canal: State.canal });
        const k = data.kpis || {};

        $('#kv-efi').text(fmt.pct(k.EFI_UNIDADES));
        setVar('#kvar-efi', fmt.varLabel(k.EFI_UNIDADES, k.META_EFICIENCIA));

        $('#kv-unid-ped').text(fmt.num(k.UNID_PEDIDAS));
        setVar('#kvar-unid-ped', fmt.varPct(k.UNID_PEDIDAS, k.UNID_PEDIDAS_AA));

        $('#kv-unid-fact').text(fmt.num(k.UNID_FACTURADAS));

        // Monto sin centavos (el importe es grande) para que no se amontone.
        $('#kv-perdida').text(
            (k.PERDIDA_FACT == null || isNaN(k.PERDIDA_FACT)) ? '—' : '$ ' + fmt.num(k.PERDIDA_FACT, 0)
        );
        $('#kvar-perdida')
            .html('% Pérd.: ' + fmt.pct(k.PCT_PERDIDA) +
                  '<span class="kpi-var-sub">prev. ' + fmt.pct(k.PCT_PERDIDA_AA) + '</span>')
            .removeClass('pos neg neu')
            .addClass('kpi-var ' + (k.PCT_PERDIDA > 0.05 ? 'neg' : 'neu'));

        $('#kv-importe').text(fmt.money(k.IMPORTE_FACTURADO));

        $('#kv-pedidos').text(fmt.num(k.PEDIDOS_TOTAL));
        setVar('#kvar-pedidos', fmt.varPct(k.PEDIDOS_TOTAL, k.PEDIDOS_AA));

        // Gauges por canal
        const canalEfi = (data.eficiencia_canal || []).filter(c => {
            const n = (c.CANAL || '').trim().toUpperCase();
            return n !== '' && n !== 'DESCONOCIDO';
        });
        const $gw = $('#gauges-canal').empty();
        chartsCanalEfi.forEach(c => c.destroy());
        chartsCanalEfi = [];

        const metaGaugeLine = {
            id: 'metaGaugeLine',
            afterDraw(chart) {
                const arc = chart.getDatasetMeta(0).data[0];
                if (!arc) return;
                const { ctx } = chart;
                const { x: cx, y: cy, innerRadius, outerRadius } = arc;
                const angle = -Math.PI + 0.95 * Math.PI;
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(cx + (innerRadius - 4) * Math.cos(angle), cy + (innerRadius - 4) * Math.sin(angle));
                ctx.lineTo(cx + (outerRadius + 4) * Math.cos(angle), cy + (outerRadius + 4) * Math.sin(angle));
                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 2.5;
                ctx.lineCap = 'round';
                ctx.stroke();
                ctx.restore();
            },
        };

        if (canalEfi.length) {
            canalEfi.forEach((c, i) => {
                const ped   = parseFloat(c.UNID_PEDIDAS)    || 0;
                const fact  = parseFloat(c.UNID_FACTURADAS) || 0;
                const efi   = ped > 0 ? fact / ped : 0;
                const color = efi >= 0.95 ? '#16a34a' : efi >= 0.85 ? '#f59e0b' : '#dc2626';
                const id    = 'gauge-canal-' + i;
                $gw.append(`<div class="gauge-card" title="${escapeHtml(c.CANAL)}: ${fmt.pct(efi)} de eficiencia">
                    <div class="gauge-wrap">
                        <canvas id="${id}" class="gauge-canvas"></canvas>
                        <div class="gauge-pct" style="color:${color}">${fmt.pct(efi)}</div>
                    </div>
                    <div class="gauge-label">${c.CANAL}</div>
                </div>`);
                chartsCanalEfi.push(new Chart(document.getElementById(id), {
                    type: 'doughnut',
                    plugins: [metaGaugeLine],
                    data: {
                        datasets: [{
                            data: [efi * 100, 100 - efi * 100],
                            backgroundColor: [color, '#e5e7eb'],
                            borderWidth: 0,
                            hoverOffset: 0,
                        }],
                    },
                    options: {
                        rotation: -90,
                        circumference: 180,
                        cutout: '72%',
                        responsive: true,
                        maintainAspectRatio: true,
                        aspectRatio: 2,
                        animation: { duration: 600 },
                        plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    },
                }));
            });
        } else {
            $gw.html('<div class="empty-state"><i class="bi bi-inbox"></i>Sin datos de canal</div>');
        }

        // Tablas de eficiencia por unidades
        renderEfiUnidTabla('#tbody-efi-unid-cliente', data.efi_cliente || [], 'CLIENTE');
        renderEfiUnidTabla('#tbody-efi-unid-rubro',   data.efi_rubro   || [], 'RUBRO');
        renderEfiPedidos(data.efi_pedidos || []);

        // Mini-gráfico de pérdida (últ. 12 meses) dentro del KPI
        perdida12mData = data.perdida_12m || [];
        buildPerdidaSpark(perdida12mData);

        // Gráfico evolución interanual + umbrales
        renderEvolucionEfi(data.evolucion || []);
    }

    // Etiqueta corta de mes ("Ene 25") para series de 12 / 24 meses.
    function mesAbbr(r) {
        return String(r.NOMBRE_MES || '').substring(0, 3) + ' ' + String(r.ANIO || '').slice(-2);
    }

    // Plugin: bandas de umbral (verde ≥95, amarillo 85–95, rojo <85) en el área del gráfico.
    const umbralesBands = {
        id: 'umbralesBands',
        beforeDatasetsDraw(chart) {
            const y = chart.scales.y;
            const area = chart.chartArea;
            if (!y || !area) return;
            const bands = [
                { from: 0,  to: 85,  color: 'rgba(220,38,38,0.06)' },
                { from: 85, to: 95,  color: 'rgba(245,158,11,0.07)' },
                { from: 95, to: 100, color: 'rgba(22,163,74,0.08)' },
            ];
            const { ctx } = chart;
            ctx.save();
            bands.forEach(b => {
                const yTop = y.getPixelForValue(Math.min(b.to, y.max));
                const yBot = y.getPixelForValue(Math.max(b.from, y.min));
                ctx.fillStyle = b.color;
                ctx.fillRect(area.left, yTop, area.right - area.left, yBot - yTop);
            });
            ctx.restore();
        },
    };

    // Evolución mensual interanual de eficiencia (una línea por año) + umbrales.
    function renderEvolucionEfi(evol) {
        const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        const byYear = {};
        (evol || []).forEach(r => {
            const y   = String(r.ANIO);
            const m   = parseInt(r.MES, 10);
            const ped = parseFloat(r.UNID_PEDIDAS_MES) || 0;
            const fct = parseFloat(r.UNID_FACTURADAS_MES) || 0;
            (byYear[y] = byYear[y] || {})[m] = ped > 0 ? parseFloat((fct / ped * 100).toFixed(1)) : null;
        });
        const years = Object.keys(byYear).sort();
        const yearColors = { prev: '#2563eb', curr: '#f59e0b' };
        const datasets = years.map((y, idx) => {
            const isCurrent = idx === years.length - 1;
            return {
                label          : y,
                data           : MESES.map((_, i) => byYear[y][i + 1] ?? null),
                borderColor    : isCurrent ? yearColors.curr : yearColors.prev,
                backgroundColor : isCurrent ? 'rgba(245,158,11,.10)' : 'rgba(37,99,235,.05)',
                borderWidth    : isCurrent ? 2.5 : 2,
                pointRadius    : 3,
                tension        : 0.3,
                fill           : isCurrent,
                spanGaps       : true,
            };
        });
        datasets.push({
            label: 'Meta (95%)', data: MESES.map(() => 95),
            borderColor: '#dc2626', borderWidth: 1.5, borderDash: [6, 4], pointRadius: 0, fill: false,
        });

        // Eje Y con zoom dinámico al rango real (las líneas suelen estar 90–99%).
        // Piso redondeado 5 pts por debajo del mínimo, pero nunca por encima de
        // 85% para conservar el contexto de los umbrales y la meta.
        const valores = datasets
            .filter(d => d.label !== 'Meta (95%)')
            .flatMap(d => d.data)
            .filter(v => v != null && isFinite(v));
        const dataMin = valores.length ? Math.min(...valores) : 0;
        const yMin = valores.length
            ? Math.max(0, Math.min(85, Math.floor((dataMin - 5) / 5) * 5))
            : 0;

        // Tooltip agrupado: al pasar el mouse por un mes, una sola tarjeta con
        // el valor de todas las líneas (años) de ese punto.
        const opts = chartOptions('Eficiencia (%)', { min: yMin, max: 100 }, { pct: true });
        opts.interaction = { mode: 'index', intersect: false };
        opts.plugins.tooltip.mode = 'index';
        opts.plugins.tooltip.intersect = false;
        opts.plugins.tooltip.filter = item => item.dataset.label !== 'Meta (95%)' && item.parsed.y != null;

        if (chartEfi) chartEfi.destroy();
        chartEfi = new Chart($('#chart-eficiencia')[0], {
            type: 'line',
            plugins: [umbralesBands],
            data: { labels: MESES, datasets },
            options: opts,
        });
    }

    // ── Tablas de eficiencia por unidades (cliente / rubro) ───────────────
    function renderEfiUnidTabla(sel, rows, nameKey) {
        const $tb = $(sel).empty();
        if (!rows || !rows.length) {
            $tb.append('<tr><td colspan="4"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }
        $tb.html(rows.map(r => {
            const ped = parseFloat(r.UNID_PEDIDAS)     || 0;
            const fac = parseFloat(r.UNID_FACTURADAS)  || 0;
            const efi = ped > 0 ? fac / ped : null;
            return `<tr><td title="${escapeHtml(r[nameKey] || '')}">${escapeHtml(r[nameKey] || '—')}</td>` +
                   `<td class="col-num">${fmt.num(ped)}</td>` +
                   `<td class="col-num">${fmt.num(fac)}</td>` +
                   `<td class="col-num">${efiCell(efi)}</td></tr>`;
        }).join(''));
    }

    // ── Tabla drill: % Eficiencia por pedido, agrupada por cliente ────────
    function renderEfiPedidos(rows) {
        const map = new Map();
        (rows || []).forEach(r => {
            const cli = (r.CLIENTE || '—');
            let g = map.get(cli);
            if (!g) { g = { cliente: cli, ped: 0, fact: 0, pedidos: [] }; map.set(cli, g); }
            const ped = parseFloat(r.UNID_PEDIDAS)    || 0;
            const fac = parseFloat(r.UNID_FACTURADAS) || 0;
            g.ped += ped; g.fact += fac;
            g.pedidos.push({ nro: r.NRO_PEDIDO, fecha: r.FECHA_PEDI, ped, fac });
        });
        efiPedidosGroups = [...map.values()]
            .map(g => ({ ...g, efi: g.ped > 0 ? g.fact / g.ped : null }))
            .sort((a, b) => (a.efi == null ? 99 : a.efi) - (b.efi == null ? 99 : b.efi));

        const $tb = $('#tbody-efi-pedidos').empty();
        if (!efiPedidosGroups.length) {
            $tb.append('<tr><td colspan="5"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }
        $tb.html(efiPedidosGroups.map((g, i) =>
            `<tr class="rubro-row expandible efi-cli-row" data-cli="${i}">
                <td><i class="bi bi-chevron-right caret"></i> ${escapeHtml(g.cliente)} <span class="badge-arts">${g.pedidos.length}</span></td>
                <td>—</td>
                <td class="col-num">${fmt.num(g.ped)}</td>
                <td class="col-num">${fmt.num(g.fact)}</td>
                <td class="col-num">${efiCell(g.efi)}</td>
            </tr>`
        ).join(''));
    }

    function toggleEfiPedidos($row) {
        const idx   = parseInt($row.data('cli'), 10);
        const $next = $row.next('.detalle-row');
        if ($next.length) { $next.remove(); $row.removeClass('abierto'); return; }
        const g = efiPedidosGroups[idx];
        if (!g) return;
        const filas = g.pedidos
            .map(p => ({ ...p, efi: p.ped > 0 ? p.fac / p.ped : null }))
            .sort((a, b) => (a.efi == null ? 99 : a.efi) - (b.efi == null ? 99 : b.efi))
            .map(p => `<tr class="pedido-row" data-pedido="${escapeHtml(String(p.nro).trim())}">
                <td class="efi-ped-nro">${escapeHtml(String(p.nro).trim())}</td>
                <td>${fmt.date(p.fecha)}</td>
                <td class="col-num">${fmt.num(p.ped)}</td>
                <td class="col-num">${fmt.num(p.fac)}</td>
                <td class="col-num">${efiCell(p.efi)}</td>
            </tr>`).join('');
        const sub = `<tr class="detalle-row"><td colspan="5">
            <table class="tabla-sub">
                <tbody>${filas}</tbody>
            </table>
        </td></tr>`;
        $row.addClass('abierto').after(sub);
    }

    // ── Mini-gráfico de pérdida (sparkline dentro del KPI) ────────────────
    function buildPerdidaSpark(rows) {
        if (chartPerdidaSpark) { chartPerdidaSpark.destroy(); chartPerdidaSpark = null; }
        const el = document.getElementById('spark-perdida');
        if (!el || !rows || !rows.length) return;
        const imp  = rows.map(r => parseFloat(r.PERDIDA_FACT) || 0);
        const prop = rows.map(r => {
            const ped = parseFloat(r.IMPORTE_PEDIDO) || 0;
            return ped > 0 ? (parseFloat(r.PERDIDA_FACT) || 0) / ped * 100 : null;
        });
        chartPerdidaSpark = new Chart(el, {
            type: 'line',
            data: {
                labels: rows.map(mesAbbr),
                datasets: [
                    { data: imp,  borderColor: '#f59e0b', borderWidth: 1.5, pointRadius: 0, tension: .35, yAxisID: 'y',  spanGaps: true },
                    { data: prop, borderColor: '#2563eb', borderWidth: 1.5, pointRadius: 0, tension: .35, yAxisID: 'y2', spanGaps: true },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false }, y2: { display: false } },
            },
        });
    }

    // ── Modal: proporción e importe de pérdida fact. (últ. 12 meses) ──────
    function openPerdidaModal() {
        const rows = perdida12mData || [];
        $('#perdida-12m-modal').removeAttr('hidden');
        $('body').addClass('modal-open');

        const totImp = rows.reduce((s, r) => s + (parseFloat(r.PERDIDA_FACT)  || 0), 0);
        const totPed = rows.reduce((s, r) => s + (parseFloat(r.IMPORTE_PEDIDO) || 0), 0);
        $('#perdida12-meta').html(
            pmetaItem('Meses', fmt.num(rows.length)) +
            pmetaItem('Pérdida total', fmt.money(totImp)) +
            pmetaItem('% Pérdida prom.', totPed > 0 ? fmt.pct(totImp / totPed) : '—')
        );

        if (chartPerdidaModal) { chartPerdidaModal.destroy(); chartPerdidaModal = null; }
        const el = document.getElementById('chart-perdida-12m');
        if (!el || !rows.length) {
            if (!rows.length) $('#perdida12-meta').append('<div class="pmodal-note">Sin datos en los últimos 12 meses.</div>');
            return;
        }
        const imp  = rows.map(r => parseFloat(r.PERDIDA_FACT) || 0);
        const prop = rows.map(r => {
            const ped = parseFloat(r.IMPORTE_PEDIDO) || 0;
            return ped > 0 ? parseFloat(((parseFloat(r.PERDIDA_FACT) || 0) / ped * 100).toFixed(2)) : null;
        });
        chartPerdidaModal = new Chart(el, {
            data: {
                labels: rows.map(mesAbbr),
                datasets: [
                    { type: 'bar',  label: 'Importe pérdida ($)', data: imp,  backgroundColor: 'rgba(245,158,11,.55)', borderRadius: 4, yAxisID: 'y',  order: 2 },
                    { type: 'line', label: '% Pérdida',           data: prop, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.10)', borderWidth: 2, pointRadius: 3, tension: .3, yAxisID: 'y2', order: 1, spanGaps: true },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 14, font: { size: 12 } } },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.92)', titleColor: '#f8fafc', bodyColor: '#cbd5e1',
                        callbacks: {
                            label(ctx) {
                                const v = ctx.parsed.y;
                                if (v == null) return null;
                                return ctx.dataset.yAxisID === 'y2'
                                    ? ' ' + ctx.dataset.label + ': ' + fmt.num(v, 1) + '%'
                                    : ' ' + ctx.dataset.label + ': ' + fmt.money(v);
                            },
                        },
                    },
                },
                scales: {
                    x:  { ticks: { font: { size: 11 } } },
                    y:  { position: 'left',  beginAtZero: true, title: { display: true, text: 'Importe ($)' }, ticks: { callback: v => fmt.num(v, 0) } },
                    y2: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: '% Pérdida' }, ticks: { callback: v => fmt.num(v, 0) + '%' } },
                },
            },
        });
    }
    function closePerdidaModal() {
        $('#perdida-12m-modal').attr('hidden', '');
        if (chartPerdidaModal) { chartPerdidaModal.destroy(); chartPerdidaModal = null; }
        if (!$('.pmodal:not([hidden])').length) $('body').removeClass('modal-open');
    }

    // ── Área 2: Lead Time ─────────────────────────────────────────────────
    async function loadLeadTime() {
        const data = await apiFetch('leadtime', { canal: State.canal });
        const k = data.kpis || {};

        $('#kv-lt-total').text(fmt.num(k.COMP_FACTURADOS));
        $('#kv-lt-dem').text(fmt.num(k.COMP_DEMORADOS));
        const pctDem = k.PCT_DEMORADOS;
        setVar('#kvar-lt-dem', { text: fmt.pct(pctDem), cls: pctDem > 0.1 ? 'neg' : 'pos' });
        $('#kv-lt-prom').text(fmt.num(k.LEAD_TIME_PROMEDIO, 1));
        $('#kv-lt-abiertos').text(fmt.num(k.PEDIDOS_ABIERTOS));

        const hist = data.histograma || [];
        if (chartLtHist) chartLtHist.destroy();
        chartLtHist = new Chart($('#chart-leadtime-hist')[0], {
            type: 'bar',
            data: {
                labels  : hist.map(r => r.DIAS + ' d'),
                datasets: [{
                    label          : 'Comprobantes',
                    data           : hist.map(r => r.CANTIDAD),
                    backgroundColor: hist.map(r => parseInt(r.DIAS) > 5 ? 'rgba(220,38,38,.7)' : 'rgba(0,168,120,.7)'),
                    borderRadius   : 4,
                }],
            },
            options: { ...chartOptions('Comprobantes', {}, { integer: true }), plugins: { legend: { display: false } } },
        });

        const evol = data.evolucion || [];
        if (chartLtEvol) chartLtEvol.destroy();
        chartLtEvol = new Chart($('#chart-leadtime-evol')[0], {
            type: 'line',
            data: {
                labels  : evol.map(r => r.NOMBRE_MES),
                datasets: [{
                    label     : '% Fact. demorada',
                    data      : evol.map(r => {
                        const tot = parseInt(r.TOTAL_MES) || 0;
                        const dem = parseInt(r.DEMORADOS_MES) || 0;
                        return tot > 0 ? parseFloat((dem / tot * 100).toFixed(1)) : null;
                    }),
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239,68,68,.08)',
                    borderWidth: 2,
                    pointRadius: 4,
                    tension    : 0.3,
                    fill       : true,
                }],
            },
            options: chartOptions('% Fact. demorada', { min: 0 }, { pct: true }),
        });
    }

    // ── Área 3: Stock WMS vs Tango ────────────────────────────────────────
    async function loadStock() {
        const data = await apiFetch('stock', { rubro: State.rubro, deposito: State.deposito });
        const k = data.kpis || {};

        $('#kv-stock-tango').text(fmt.num(k.STOCK_TANGO));
        $('#kv-stock-wms').text(fmt.num(k.STOCK_WMS));
        $('#kv-stock-dif').text(fmt.num(k.DIFERENCIA));
        setVar('#kvar-stock-dif', {
            text: fmt.pct(k.DIF_PCT),
            cls : Math.abs(parseFloat(k.DIF_PCT || 0)) < 0.01 ? 'pos' : 'neg',
        });
        $('#kv-stock-dif-abs').text(fmt.num(k.DIFERENCIA_ABS));
        $('#kv-stock-prec').text(fmt.pct(k.PRECISION_INVENTARIO));

        const rubros = (data.rubros || []).slice(0, 15);
        if (chartStock) chartStock.destroy();
        chartStock = new Chart($('#chart-stock')[0], {
            type: 'bar',
            data: {
                labels  : rubros.map(r => r.RUBRO),
                datasets: [
                    { label: 'Stock Tango', data: rubros.map(r => r.STOCK_TANGO), backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 4 },
                    { label: 'Stock WMS',   data: rubros.map(r => r.STOCK_WMS),   backgroundColor: 'rgba(0,168,120,.65)', borderRadius: 4 },
                ],
            },
            options: chartOptions('Unidades', {}, { integer: true }),
        });

        stockDetalleArticulos = data.detalle_articulos || [];
        const $tbody = $('#tbody-stock').empty();
        if (!rubros.length) {
            $tbody.append('<tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            initStockExport();
            return;
        }
        $tbody.html(rubros.map(r => {
            const dif    = parseFloat(r.DIFERENCIA || 0);
            const nArts  = stockDetalleArticulos.filter(a => a.RUBRO === r.RUBRO).length;
            const expand = nArts > 0;
            return `<tr class="rubro-row${expand ? ' expandible' : ''}" data-rubro="${escapeHtml(r.RUBRO || '')}">
                <td>${expand ? '<i class="bi bi-chevron-right caret"></i> ' : ''}${escapeHtml(r.RUBRO || '')}${expand ? ` <span class="badge-arts">${nArts}</span>` : ''}</td>
                <td class="col-num">${fmt.num(r.STOCK_TANGO)}</td>
                <td class="col-num">${fmt.num(r.STOCK_WMS)}</td>
                <td class="col-num ${dif !== 0 ? (dif < 0 ? 'var-neg' : 'var-pos') : ''}">${fmt.num(dif)}</td>
                <td class="col-num ${dif !== 0 ? 'var-neg' : ''}">${fmt.pct(r.DIF_PCT)}</td>
            </tr>`;
        }).join(''));

        // Botón "Exportar a Excel" del detalle por rubro (se inserta una sola vez)
        initStockExport();
    }

    // ── Drill-down: artículos con diferencia de un rubro ──────────────────
    function toggleDrillRubro($row) {
        const rubro = $row.data('rubro');
        const $next = $row.next('.detalle-row');
        if ($next.length) { $next.remove(); $row.removeClass('abierto'); return; }

        const arts = stockDetalleArticulos
            .filter(a => a.RUBRO === rubro)
            .sort((a, b) => Math.abs(parseFloat(b.DIFERENCIA || 0)) - Math.abs(parseFloat(a.DIFERENCIA || 0)));

        const filas = arts.map(a => {
            const dif = parseFloat(a.DIFERENCIA || 0);
            return `<tr>
                <td>${escapeHtml(a.COD_ARTICU || '—')}</td>
                <td>${escapeHtml(a.DESCRIPCION || '—')}</td>
                <td class="col-num">${fmt.num(a.STOCK_TANGO)}</td>
                <td class="col-num">${fmt.num(a.STOCK_WMS)}</td>
                <td class="col-num ${dif < 0 ? 'var-neg' : 'var-pos'}">${fmt.num(dif)}</td>
            </tr>`;
        }).join('');

        const sub = `<tr class="detalle-row"><td colspan="6">
            <table class="tabla-sub">
                <thead><tr>
                    <th>Código</th><th>Descripción</th>
                    <th class="col-num">Stock Tango</th>
                    <th class="col-num">Stock WMS</th>
                    <th class="col-num">Diferencia</th>
                </tr></thead>
                <tbody>${filas}</tbody>
            </table>
        </td></tr>`;

        $row.addClass('abierto').after(sub);
    }

    // ── Exportar a Excel: detalle de artículos con diferencias ────────────
    function initStockExport() {
        if (stockExportInit || typeof ExcelExporter === 'undefined') return;
        const hdr = document.getElementById('hdr-stock-detalle');
        if (!hdr) return;
        ExcelExporter.addExportButton(hdr, exportDetalleArticulos);
        stockExportInit = true;
    }

    function exportDetalleArticulos() {
        if (!stockDetalleArticulos.length) {
            alert('No hay artículos con diferencias para exportar.');
            return;
        }
        const rows = stockDetalleArticulos
            .slice()
            .sort((a, b) =>
                (a.RUBRO || '').localeCompare(b.RUBRO || '') ||
                Math.abs(parseFloat(b.DIFERENCIA || 0)) - Math.abs(parseFloat(a.DIFERENCIA || 0)))
            .map(a => [
                a.RUBRO || '',
                a.COD_ARTICU || '',
                a.DESCRIPCION || '',
                parseFloat(a.STOCK_TANGO || 0),
                parseFloat(a.STOCK_WMS   || 0),
                parseFloat(a.DIFERENCIA  || 0),
            ]);
        ExcelExporter.export({
            title     : 'Stock Tango vs WMS — Artículos con diferencias',
            headers   : ['Rubro', 'Código', 'Descripción', 'Stock Tango', 'Stock WMS', 'Diferencia'],
            rows,
            colFormats: ['text', 'text', 'text', 'num', 'num', 'num'],
            filename  : 'stock_articulos_con_diferencias',
        });
    }

    // ── Área 4: Productividad Facturación ─────────────────────────────────
    async function loadProdFact() {
        const data = await apiFetch('productividad_fact', { tipo: State.tipo, rubro: State.rubroFact, canal: State.canal });
        const k = data.kpis || {};

        $('#kv-pf-prom-dia').text(fmt.num(k.PROMEDIO_DIA));
        $('#kv-pf-pico-dia').text(fmt.num(k.PICO_DIA));
        $('#kv-pf-pico-dia-fecha').text(fmt.date(k.PICO_DIA_FECHA));
        $('#kv-pf-pico-user').text(fmt.num(k.PICO_USUARIO));
        $('#kv-pf-pico-user-nombre').text(k.PICO_USUARIO_NOMBRE || '—');
        $('#kv-pf-tendencia').text(fmt.num(k.TENDENCIA_GLOBAL));

        // Gráfico doble serie: total del día (barras) + mejor usuario del día (línea)
        const evol = data.evolucion || [];
        if (chartProdFact) chartProdFact.destroy();
        chartProdFact = new Chart($('#chart-prod-fact')[0], {
            type: 'bar',
            data: {
                labels  : evol.map(r => fmt.date(r.FECHA_COMP)),
                datasets: [{
                    label: 'Unidades fact. x día', data: evol.map(r => r.UNIDADES_DIA),
                    backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 3, yAxisID: 'y',
                }, {
                    label: 'Pico fact. x usuario', data: evol.map(r => r.PICO_USER),
                    type: 'line', borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.15)',
                    borderWidth: 2, pointRadius: 3, tension: 0.3, fill: false, yAxisID: 'y1',
                }],
            },
            options: {
                ...chartOptions('Unidades', {}, { integer: true }),
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } },
                    tooltip: {
                        mode: 'index', intersect: false,
                        backgroundColor: 'rgba(15,23,42,0.92)', titleColor: '#f8fafc',
                        bodyColor: '#cbd5e1', borderColor: 'rgba(255,255,255,0.12)', borderWidth: 1, padding: 10,
                        callbacks: {
                            label(ctx) {
                                const v = ctx.parsed.y;
                                if (v == null) return null;
                                return ' ' + ctx.dataset.label + ': ' + Number(v).toLocaleString('es-AR', { maximumFractionDigits: 0 });
                            },
                        },
                    },
                },
                scales: {
                    x : { ticks: { maxRotation: 45, font: { size: 10 } } },
                    y : { beginAtZero: true, title: { display: true, text: 'Unidades fact. x día' } },
                    y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Pico fact. x usuario' }, grid: { drawOnChartArea: false } },
                },
            },
        });

        const $tbody = $('#tbody-usuarios-fact').empty();
        const usuarios = data.usuarios || [];
        if (!usuarios.length) {
            $tbody.append('<tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
        } else {
            $tbody.html(usuarios.map(u => `<tr>
                <td>${u.USUARIO || '—'}</td>
                <td class="col-num">${u.UNIDADES_FACT == null ? '' : fmt.num(u.UNIDADES_FACT)}</td>
                <td class="col-num">${u.PCT_UNIDADES == null ? '' : fmt.pct(u.PCT_UNIDADES, 2)}</td>
                <td class="col-num">${fmt.num(u.PICO_FACT)}</td>
                <td class="col-num">${u.TENDENCIA == null ? '' : fmt.num(u.TENDENCIA)}</td>
                <td class="col-num">${fmt.num(u.DIAS_PRODUCTIVOS)}</td>
                <td class="col-num">${u.UNIDADES_ULT30 ? fmt.num(u.UNIDADES_ULT30) : ''}</td>
            </tr>`).join(''));
        }

        renderUlt7('fact-ult7', data.ultimos7 || [], { hours: false, title: 'Remisión del' });
    }

    // ── Área 5: Productividad Picking ─────────────────────────────────────
    // ── WIP de picking: tareas iniciadas y no terminadas (foto de ahora) ──
    function fmtMinAbierta(r) {
        if (r.ESTADO === 'COLGADA') return fmt.num(r.DIAS_ABIERTA) + ' días';
        const m = parseInt(r.MIN_ABIERTA, 10);
        if (isNaN(m)) return '—';
        return m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') + ' min';
    }

    async function loadWipPicking() {
        const $tb = $('#tbody-wip-picking');
        try {
            const data = await apiFetch('wip_picking', { usuario: State.usuario });
            const k = data.kpis || {};
            const av = k.AVANCE == null || k.AVANCE === '' ? null : parseFloat(k.AVANCE);
            $('#wip-hora').text(`Actualizado ${String(k.AHORA || '').substring(11, 16)} · último inicio de picking: ` +
                `${fmt.date(String(k.ULTIMO_INICIO || '').substring(0, 10))} ${String(k.ULTIMO_INICIO || '').substring(11)}`);
            $('#kv-wip-tareas').text(fmt.num(k.TAREAS));
            $('#kv-wip-pickers').text(fmt.num(k.PICKERS));
            $('#kv-wip-asig').text(fmt.num(k.UNID_ASIGNADAS));
            $('#kv-wip-pick').text(fmt.num(k.UNID_PICKEADAS));
            $('#kv-wip-falt').text(fmt.num(k.UNID_FALTANTES));
            $('#kv-wip-avance').text(av == null ? '—' : fmt.pct(av, 0));
            $('#kv-wip-dem').text(fmt.num(k.TAREAS_DEMORADAS)).css('color', k.TAREAS_DEMORADAS > 0 ? 'var(--neg)' : '');

            const tareas = data.tareas || [];
            if (!tareas.length) {
                $tb.html(`<tr><td colspan="8"><div class="empty-state"><i class="bi bi-cup-hot"></i>No hay tareas de picking abiertas en este momento</div></td></tr>`);
                return;
            }
            const BADGE = { 'EN CURSO': 'badge-en-curso', 'DEMORADA': 'badge-cancelado', 'COLGADA': 'badge-colgada' };
            $tb.html(tareas.map(r => {
                const a = r.AVANCE == null || r.AVANCE === '' ? null : parseFloat(r.AVANCE);
                const txt = r.ESTADO === 'COLGADA' ? 'Colgada' : r.ESTADO === 'DEMORADA' ? 'Demorada' : 'En curso';
                const tit = r.ESTADO === 'COLGADA' ? ' title="Iniciada un día anterior y nunca cerrada: no cuenta en el WIP. Conviene cerrarla en el WMS."' : '';
                return `<tr${r.ESTADO === 'COLGADA' ? ' class="wip-colgada"' : ''}>` +
                    `<td>${escapeHtml(r.USUARIO || '—')}</td>` +
                    `<td>${fmt.date(r.FECHA_INI_PICKING)} ${escapeHtml(r.HM_INICIO || '')}</td>` +
                    `<td class="col-num">${fmtMinAbierta(r)}</td>` +
                    `<td class="col-num">${fmt.num(r.CANT_ASIGNADA)}</td><td class="col-num">${fmt.num(r.CANT_PICKING)}</td>` +
                    `<td class="col-num">${fmt.num(r.FALTANTE)}</td>` +
                    `<td class="col-num">${a == null ? '—' : fmt.pct(a, 0)}</td>` +
                    `<td><span class="badge-estado ${BADGE[r.ESTADO] || 'badge-colgada'}"${tit}>${txt}</span></td></tr>`;
            }).join(''));
        } catch (e) {
            $tb.html(`<tr><td colspan="8"><div class="error-state"><i class="bi bi-exclamation-triangle-fill"></i> ${escapeHtml(e.message)}</div></td></tr>`);
        }
    }

    async function loadProdPicking() {
        const wipP = loadWipPicking();   // en paralelo; maneja sus propios errores
        const data = await apiFetch('productividad_picking', { usuario: State.usuario });
        const k = data.kpis || {};

        $('#kv-pp-prom-dia').text(fmt.num(k.PROM_UNID_DIA));
        $('#kv-pp-pico-dia').text(fmt.num(k.PICO_DIA_UNIDADES));
        $('#kv-pp-pico-dia-fecha').text(fmt.date(k.PICO_DIA_FECHA));
        $('#kv-pp-pico-usuario').text(fmt.num(k.PICO_USUARIO_UNIDADES));
        $('#kv-pp-pico-usuario-nombre').text(k.PICO_USUARIO || '—');
        $('#kv-pp-prom-hs').text(fmt.num(k.PROM_TIEMPO_PROD_HS, 2));
        $('#kv-pp-u-hora').text(fmt.num(k.PROM_UNID_HORA, 1));
        $('#kv-pp-prom').text(fmt.num(k.PROM_UNID_PICKERS, 1));

        const evol = data.evolucion || [];
        const canvasPP = $('#chart-prod-picking')[0];
        if (chartProdPicking) { chartProdPicking.destroy(); chartProdPicking = null; }
        if (canvasPP) chartProdPicking = new Chart(canvasPP, {
            type: 'bar',
            data: {
                labels  : evol.map(r => fmt.date(r.FECHA_INI_PICKING)),
                datasets: [{
                    label: 'Unidades pickeadas', data: evol.map(r => r.UNIDADES_DIA),
                    backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 3, yAxisID: 'y',
                }, {
                    label: 'Promedio unid. x hora', data: evol.map(r => r.PROM_UNID_HORA),
                    type: 'line', borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.15)',
                    borderWidth: 2, pointRadius: 3, tension: 0.3, fill: false, yAxisID: 'y1',
                }],
            },
            options: {
                ...chartOptions('Unidades pickeadas', {}, { integer: true }),
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 12 }, boxWidth: 14 } },
                    tooltip: {
                        mode: 'index', intersect: false,
                        backgroundColor: 'rgba(15,23,42,0.92)', titleColor: '#f8fafc',
                        bodyColor: '#cbd5e1', borderColor: 'rgba(255,255,255,0.12)',
                        borderWidth: 1, padding: 10,
                        callbacks: {
                            label(ctx) {
                                const v = ctx.parsed.y;
                                if (v == null) return null;
                                if (ctx.datasetIndex === 0)
                                    return ' ' + ctx.dataset.label + ': ' + Number(v).toLocaleString('es-AR', { maximumFractionDigits: 0 });
                                return ' ' + ctx.dataset.label + ': ' + Number(v).toLocaleString('es-AR', { maximumFractionDigits: 1 }) + ' unid./h';
                            },
                        },
                    },
                },
                scales: {
                    x : { ticks: { maxRotation: 45, font: { size: 10 } } },
                    y : { beginAtZero: true, title: { display: true, text: 'Unidades' } },
                    y1: { beginAtZero: true, position: 'right', title: { display: true, text: 'Unid./hora' }, grid: { drawOnChartArea: false } },
                },
            },
        });

        const $tbody = $('#tbody-usuarios-picking').empty();
        const usuarios = data.usuarios || [];
        if (!usuarios.length) {
            $tbody.html('<tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
        } else {
            $tbody.html(usuarios.map(u => `<tr>
                <td>${u.USUARIO || '—'}</td>
                <td class="col-num">${fmt.num(u.UNIDADES)}</td>
                <td class="col-num">${fmt.pct(u.PCT_UNIDADES)}</td>
                <td class="col-num">${fmt.num(u.PICO_PICKING)}</td>
                <td class="col-num">${fmt.num(u.MEDIANA_PICKING, 1)}</td>
                <td class="col-num">${fmt.num(u.HORAS, 1)}</td>
                <td class="col-num">${fmt.num(u.PROM_UNID_HORA, 1)}</td>
                <td class="col-num">${fmt.num(u.UNIDADES_ULT30)}</td>
            </tr>`).join(''));
        }

        renderUlt7('picking-ult7', data.ultimos7 || [], { hours: true, title: 'Picking del' });
        await wipP;
    }

    // Tabla "Últimos 7 días" genérica (picking y facturación).
    // base = sufijo de ids (#thead-<base>, #tbody-<base>); opts.hours añade
    // columna de horas; opts.title prefija el modal del mini-gráfico.
    function renderUlt7(base, rows, opts) {
        opts = opts || {};
        const hasHoras = !!opts.hours;
        rows = rows || [];
        ult7Data[base] = { rows: rows, title: opts.title || '' };
        const $thead = $('#thead-' + base).empty();
        const $tbody = $('#tbody-' + base).empty();
        const fechas   = [...new Set(rows.map(r => r.FECHA_PICK))].sort();
        const usuarios = [...new Set(rows.map(r => r.USUARIO || '—'))];

        if (!fechas.length || !usuarios.length) {
            $thead.html('<tr><th>Usuario</th></tr>');
            $tbody.html('<tr><td><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }

        $thead.html(
            '<tr>' +
            '<th>Usuario</th>' +
            fechas.map(f => `<th class="col-num pick-day-th" data-base="${base}" data-fecha="${f}" title="Ver gráfico del día ${fmt.date(f)}">` +
                `${fmt.date(f)} <i class="bi bi-bar-chart-line pick-day-ico"></i><br><span class="pick-sub">Unid.</span></th>`).join('') +
            '<th class="col-num col-total">Total<br><span class="pick-sub">Unid.</span></th>' +
            (hasHoras ? '<th class="col-num col-total">Total<br><span class="pick-sub">Hs. prod.</span></th>' : '') +
            '</tr>'
        );

        const byKey = new Map(rows.map(r => [(r.USUARIO || '—') + '|' + r.FECHA_PICK, r]));
        const totUnidFecha = Object.fromEntries(fechas.map(f => [f, 0]));
        let totUnidGlobal = 0, totHsGlobal = 0;

        $tbody.html(usuarios.map(usuario => {
            let sumUnid = 0, sumHoras = 0;
            const cols = fechas.map(fecha => {
                const r = byKey.get(usuario + '|' + fecha) || {};
                const unid = parseFloat(r.UNIDADES || 0);
                const hs   = parseFloat(r.HORAS    || 0);
                sumUnid += unid;
                sumHoras += hs;
                totUnidFecha[fecha] += unid;
                return `<td class="col-num">${unid > 0 ? fmt.num(unid) : ''}</td>`;
            }).join('');
            totUnidGlobal += sumUnid;
            totHsGlobal   += sumHoras;
            return `<tr><td>${usuario}</td>` + cols +
                `<td class="col-num pick-subtotal">${sumUnid > 0 ? fmt.num(sumUnid) : ''}</td>` +
                (hasHoras ? `<td class="col-num pick-subtotal">${sumHoras > 0 ? fmt.num(sumHoras, 1) : ''}</td>` : '') + '</tr>';
        }).join(''));

        const totalFechaCols = fechas.map(f =>
            `<td class="col-num">${totUnidFecha[f] > 0 ? fmt.num(totUnidFecha[f]) : ''}</td>`
        ).join('');
        $tbody.append(
            `<tr class="pick-total"><td>Total</td>` + totalFechaCols +
            `<td class="col-num">${totUnidGlobal > 0 ? fmt.num(totUnidGlobal) : ''}</td>` +
            (hasHoras ? `<td class="col-num">${totHsGlobal > 0 ? fmt.num(totHsGlobal, 1) : ''}</td>` : '') + '</tr>'
        );
    }

    // Mini-gráfico: unidades por usuario en un día (clic en columna de fecha).
    function openDiaChart(base, fecha) {
        if (!fecha) return;
        const cfg  = ult7Data[base] || { rows: [], title: '' };
        const rows = cfg.rows
            .filter(r => r.FECHA_PICK === fecha && (parseFloat(r.UNIDADES) || 0) > 0)
            .map(r => ({ u: r.USUARIO || '—', unid: parseFloat(r.UNIDADES) || 0, hs: parseFloat(r.HORAS) || 0 }))
            .sort((a, b) => b.unid - a.unid);

        $('#pdia-title').html(`<i class="bi bi-bar-chart-line"></i> ${cfg.title} ${fmt.date(fecha)}`);
        const totU = rows.reduce((s, r) => s + r.unid, 0);
        const hasHoras = cfg.rows.some(r => r.HORAS != null && r.HORAS !== '');
        let meta = pmetaItem('Fecha', fmt.date(fecha)) +
                   pmetaItem('Usuarios', fmt.num(rows.length)) +
                   pmetaItem('Unidades', fmt.num(totU));
        if (hasHoras) meta += pmetaItem('Hs. productivas', fmt.num(rows.reduce((s, r) => s + r.hs, 0), 1));
        $('#pdia-meta').html(meta);

        $('#picking-dia-modal').removeAttr('hidden');
        $('body').addClass('modal-open');

        if (chartPickingDia) { chartPickingDia.destroy(); chartPickingDia = null; }
        const el = document.getElementById('chart-picking-dia');
        if (!el || !rows.length) {
            if (!rows.length) $('#pdia-meta').append('<div class="pmodal-note">Sin actividad ese día.</div>');
            return;
        }
        chartPickingDia = new Chart(el, {
            type: 'bar',
            data: {
                labels: rows.map(r => r.u),
                datasets: [{ label: 'Unidades', data: rows.map(r => r.unid),
                             backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 3 }],
            },
            options: { ...chartOptions('Unidades', {}, { integer: true }), plugins: { legend: { display: false } } },
        });
    }
    function closePickingDia() {
        $('#picking-dia-modal').attr('hidden', '');
        if (chartPickingDia) { chartPickingDia.destroy(); chartPickingDia = null; }
        if (!$('.pmodal:not([hidden])').length) $('body').removeClass('modal-open');
    }

    // ── Gauge reutilizable (semicírculo con marca de meta) ────────────────
    function destroyGauges(arr) {
        arr.forEach(c => { try { c.destroy(); } catch (e) {} });
        arr.length = 0;
    }

    function gaugeColor(pct, meta) {
        if (pct == null) return '#e5e7eb';
        return pct >= meta ? '#16a34a' : pct >= 0.85 ? '#f59e0b' : '#dc2626';
    }

    function renderGauge(canvasId, pct, color, meta) {
        const el = document.getElementById(canvasId);
        if (!el) return null;
        const p = Math.max(0, Math.min(1, pct || 0)) * 100;
        const metaLine = {
            id: 'metaGaugeLine_' + canvasId,
            afterDraw(chart) {
                const arc = chart.getDatasetMeta(0).data[0];
                if (!arc) return;
                const { ctx } = chart;
                const { x: cx, y: cy, innerRadius, outerRadius } = arc;
                const angle = -Math.PI + (meta || 0) * Math.PI;
                ctx.save();
                ctx.beginPath();
                ctx.moveTo(cx + (innerRadius - 4) * Math.cos(angle), cy + (innerRadius - 4) * Math.sin(angle));
                ctx.lineTo(cx + (outerRadius + 4) * Math.cos(angle), cy + (outerRadius + 4) * Math.sin(angle));
                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 2.5;
                ctx.lineCap = 'round';
                ctx.stroke();
                ctx.restore();
            },
        };
        return new Chart(el, {
            type: 'doughnut',
            plugins: [metaLine],
            data: { datasets: [{ data: [p, 100 - p], backgroundColor: [color, '#e5e7eb'], borderWidth: 0, hoverOffset: 0 }] },
            options: {
                rotation: -90, circumference: 180, cutout: '72%',
                responsive: true, maintainAspectRatio: true, aspectRatio: 2,
                animation: { duration: 600 },
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
            },
        });
    }

    // Formatea días con signo (negativo = demora) y color
    function fmtDias(v) {
        if (v == null || v === '' || isNaN(v)) return '—';
        return fmt.num(parseFloat(v), 1) + ' días';
    }

    // Celda de % eficacia con color semáforo (meta 95%)
    function efiCell(pct) {
        if (pct == null) return '—';
        const p = parseFloat(pct);
        const cls = p >= 0.95 ? 'pos' : p >= 0.85 ? '' : 'neg';
        const color = cls === 'pos' ? 'var(--pos)' : cls === 'neg' ? 'var(--neg)' : 'var(--accent3)';
        return `<span style="color:${color};font-weight:600">${fmt.pct(p, 0)}</span>`;
    }

    // ── Área 6a: Planificación ────────────────────────────────────────────
    async function loadPlanificacion() {
        const data = await apiFetch('planificacion', { canal: State.canal });
        const k = data.kpis || {};
        const v = data.ventanas || {};

        destroyGauges(chartsPlanGauges);
        const winMap = { hoy: 'HOY', prox: 'PROX', mas: 'MAS_UNO' };
        Object.entries(winMap).forEach(([dom, key]) => {
            const w    = v[key] || {};
            const pct  = (w.PCT_PICK == null || w.PCT_PICK === '') ? null : parseFloat(w.PCT_PICK);
            const meta = parseFloat(w.META) || 0.97;
            const color = gaugeColor(pct, meta);
            $(`#pl-${dom}-fecha`).text(fmt.date(w.FECHA));
            $(`#pl-${dom}-ped-tot`).text(fmt.num(w.PED_TOTAL));
            $(`#pl-${dom}-ped-pend`).text(fmt.num(w.PED_PEND));
            $(`#pl-${dom}-unid-tot`).text(fmt.num(w.UNID_TOTAL));
            $(`#pl-${dom}-unid-pend`).text(fmt.num(w.UNID_PEND));
            $(`#pl-${dom}-pickers`).text(fmt.num(w.PICKERS));
            $(`#pl-${dom}-pct`).text(pct == null ? '—' : fmt.pct(pct, 0)).css('color', color);
            chartsPlanGauges.push(renderGauge(`gauge-plan-${dom}`, pct || 0, color, meta));
        });
        $('#pl-hoy-dem').text(fmt.num(k.PED_DEMORADOS));
        $('#kv-pl-prom-dia').text(fmt.num(k.PROM_UNID_DIA, 0));

        // Pendiente a despachar (cartera, no WIP): entrega de hoy en adelante, vencidos aparte
        $('#kv-pl-wip').text(fmt.num(k.WIP_UNID));
        $('#kv-pl-wip-ped').text(fmt.num(k.WIP_PED));
        $('#kv-pl-venc').text(parseFloat(k.VENC_UNID) > 0 ? `· ${fmt.num(k.VENC_UNID)} vencidas (30 d)` : '');
        const wip = data.wip || [];
        if (chartPlanWip) chartPlanWip.destroy();
        const wipOpts = chartOptions('Unidades', {}, { integer: true });
        wipOpts.plugins.legend = { display: false };
        chartPlanWip = new Chart($('#chart-plan-wip')[0], {
            type: 'bar',
            data: {
                labels  : wip.map(r => r.FECHA === 'POSTERIOR' ? 'Posterior' : fmt.date(r.FECHA).substring(0, 5)),
                datasets: [{
                    label          : 'Unidades pendientes',
                    data           : wip.map(r => parseFloat(r.UNIDADES) || 0),
                    backgroundColor: wip.map(r => r.FECHA === 'POSTERIOR' ? 'rgba(100,116,139,.45)' : 'rgba(37,99,235,.65)'),
                    borderRadius   : 4,
                }],
            },
            options: wipOpts,
        });

        State.pendientes = data.pendientes || [];
        renderPendientes();

        renderTablaSimple('#tbody-plan-demorados', data.demorados || [],
            r => `<td>${r.NRO_PEDIDO}</td><td title="${escapeHtml(r.NOMBRE_CLIENTE || '')}">${r.NOMBRE_CLIENTE || '—'}</td>` +
                 `<td>${r.CANAL || '—'}</td><td>${fmt.date(r.FECHA_ENTREGA)}</td>` +
                 `<td class="col-num">${fmt.num(r.DIAS)}</td><td class="col-num">${fmt.num(r.UNIDADES)}</td>`,
            r => r.NRO_PEDIDO
        );
    }

    function renderPendientes() {
        const f    = State.pendFiltro || 'HOY';
        const rows = (State.pendientes || []).filter(r => f === 'ALL' ? true : r.VENTANA === f);
        renderTablaSimple('#tbody-pend', rows,
            r => `<td>${r.NRO_PEDIDO}</td><td>${r.COD_CLIENT || '—'}</td>` +
                 `<td title="${escapeHtml(r.NOMBRE_CLIENTE || '')}">${r.NOMBRE_CLIENTE || '—'}</td>` +
                 `<td>${r.CANAL || '—'}</td><td>${fmt.date(r.FECHA_ENTREGA)}</td>` +
                 `<td class="col-num">${fmt.num(r.UNIDADES)}</td>`,
            r => r.NRO_PEDIDO
        );
    }

    // ── Área 6b: Despacho ─────────────────────────────────────────────────
    async function loadDespacho() {
        const data = await apiFetch('despacho', { canal: State.canal, cliente: State.cliente });
        const k = data.kpis || {};

        $('#kv-dsp-efi').text(fmt.pct(k.EFICACIA_TOTAL, 0));
        setVar('#kvar-dsp-efi', fmt.varLabel(k.EFICACIA_TOTAL, k.META));
        $('#kv-dsp-efi-desp').text(k.EFICACIA_DESPACHADOS == null ? '—' : fmt.pct(k.EFICACIA_DESPACHADOS, 0));
        setVar('#kvar-dsp-efi-desp', k.EFICACIA_DESPACHADOS == null ? { text: '', cls: 'neu' } : fmt.varLabel(k.EFICACIA_DESPACHADOS, k.META));
        const demD = k.DESVIO_PROM_DEMORADOS, guiaD = k.DESVIO_PROM_GUIA;
        $('#kv-dsp-dem-dias').text(fmtDias(demD)).css('color', parseFloat(demD) < 0 ? 'var(--neg)' : 'var(--text-1)');
        $('#kv-dsp-guia-dias').text(fmtDias(guiaD)).css('color', parseFloat(guiaD) < 0 ? 'var(--neg)' : 'var(--text-1)');

        // Gauges por canal
        destroyGauges(chartsDespCanal);
        const $gw = $('#gauges-despacho-canal').empty();
        const canal = data.canal || [];
        if (!canal.length) {
            $gw.html('<div class="empty-state"><i class="bi bi-inbox"></i>Sin datos de canal</div>');
        } else {
            canal.forEach((c, i) => {
                const efi   = c.EFICACIA == null ? 0 : parseFloat(c.EFICACIA);
                const meta  = 0.95;
                const color = gaugeColor(efi, meta);
                const id    = 'gauge-dsp-canal-' + i;
                $gw.append(`<div class="gauge-card" title="${escapeHtml(c.CANAL)}: ${fmt.pct(efi, 0)} de eficacia">
                    <div class="gauge-wrap">
                        <canvas id="${id}" class="gauge-canvas"></canvas>
                        <div class="gauge-pct" style="color:${color}">${fmt.pct(efi, 0)}</div>
                    </div>
                    <div class="gauge-label">${escapeHtml(c.CANAL)}</div>
                </div>`);
                chartsDespCanal.push(renderGauge(id, efi, color, meta));
            });
        }

        // Evolución (una línea por año, eje X = meses) + umbrales
        const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        const evol  = data.evolucion || [];
        const years = [...new Set(evol.map(r => parseInt(r.ANIO)))].sort();
        const yearColors = { prev: '#2563eb', curr: '#f59e0b' };
        const datasets = years.map((y, idx) => {
            const isCurrent = idx === years.length - 1;
            const arr = Array(12).fill(null);
            evol.filter(r => parseInt(r.ANIO) === y).forEach(r => {
                arr[parseInt(r.MES) - 1] = r.EFICACIA == null ? null : parseFloat((parseFloat(r.EFICACIA) * 100).toFixed(1));
            });
            return {
                label          : String(y),
                data           : arr,
                borderColor    : isCurrent ? yearColors.curr : yearColors.prev,
                backgroundColor : isCurrent ? 'rgba(245,158,11,.10)' : 'rgba(37,99,235,.05)',
                borderWidth    : isCurrent ? 2.5 : 2,
                pointRadius    : 3,
                tension        : 0.3,
                fill           : isCurrent,
                spanGaps       : true,
            };
        });
        datasets.push({ label: 'Meta (95%)', data: Array(12).fill(95), borderColor: '#dc2626',
                        borderWidth: 1.5, borderDash: [6, 4], pointRadius: 0, fill: false });

        // Eje Y con zoom dinámico (piso 5 pts bajo el mínimo, tope 85%)
        const valoresDsp = datasets
            .filter(d => d.label !== 'Meta (95%)')
            .flatMap(d => d.data)
            .filter(v => v != null && isFinite(v));
        const yMinDsp = valoresDsp.length
            ? Math.max(0, Math.min(85, Math.floor((Math.min(...valoresDsp) - 5) / 5) * 5))
            : 0;

        const optsDsp = chartOptions('Eficacia (%)', { min: yMinDsp, max: 100 }, { pct: true });
        optsDsp.interaction = { mode: 'index', intersect: false };
        optsDsp.plugins.tooltip.mode = 'index';
        optsDsp.plugins.tooltip.intersect = false;
        optsDsp.plugins.tooltip.filter = item => item.dataset.label !== 'Meta (95%)' && item.parsed.y != null;

        if (chartDespEvol) chartDespEvol.destroy();
        chartDespEvol = new Chart($('#chart-despacho-evol')[0], {
            type: 'line',
            plugins: [umbralesBands],
            data: { labels: MESES, datasets },
            options: optsDsp,
        });

        // Tablas
        renderTablaSimple('#tbody-efi-cliente', data.eficacia_cliente || [],
            r => `<td title="${escapeHtml(r.CLIENTE || '')}">${r.CLIENTE || '—'}</td>` +
                 `<td class="col-num">${efiCell(r.EFICACIA)}</td>` +
                 `<td class="col-num">${fmt.num(r.DESVIO_PROM, 1)}</td>` +
                 `<td class="col-num">${fmt.num(r.TOTAL)}</td>`
        );
        renderTablaSimple('#tbody-efi-pedido', data.eficacia_pedido || [],
            r => `<td title="${escapeHtml(r.CLIENTE || '')}">${r.CLIENTE || '—'}</td>` +
                 `<td>${r.NRO_PEDIDO}</td><td>${r.N_COMP || '—'}</td>` +
                 `<td>${fmt.date(r.PROX_DESPACHO)}</td><td>${fmt.date(r.FECHA_GUIA)}</td>` +
                 `<td class="col-num">${fmt.num(r.DESVIO)}</td>`,
            r => r.NRO_PEDIDO
        );
        renderTablaSimple('#tbody-dem-cliente', data.demorados_cliente || [],
            r => `<td title="${escapeHtml(r.CLIENTE || '')}">${r.CLIENTE || '—'}</td>` +
                 `<td class="col-num">${fmt.num(r.DIAS_PROM, 1)}</td>` +
                 `<td class="col-num">${fmt.num(r.PEDIDOS)}</td>`
        );
        renderTablaSimple('#tbody-dem-pedido', data.demorados_pedido || [],
            r => `<td title="${escapeHtml(r.CLIENTE || '')}">${r.CLIENTE || '—'}</td>` +
                 `<td>${r.NRO_PEDIDO}</td><td>${r.N_COMP || '—'}</td>` +
                 `<td>${fmt.date(r.PROX_DESPACHO)}</td><td>${badgeEstado(r.ESTADO_DESPACHO)}</td>` +
                 `<td class="col-num">${fmt.num(r.DIAS)}</td>`,
            r => r.NRO_PEDIDO
        );
    }

    // ── Área 7: Pedidos Consolidados ──────────────────────────────────────
    async function loadPedidos() {
        const data = await apiFetch('pedidos_consolidados', { canal: State.canal });
        const k = data.kpis || {};

        $('#kv-pc-ped').text(fmt.num(k.PEDIDOS));
        setVar('#kvar-pc-ped', fmt.varPct(k.PEDIDOS, k.PEDIDOS_AA));
        $('#kv-pc-ped-aa').text(fmt.num(k.PEDIDOS_AA));
        const varAbs = parseInt(k.PEDIDOS_VAR || 0);
        $('#kv-pc-var').text((varAbs >= 0 ? '+' : '') + fmt.num(varAbs))
            .css('color', varAbs >= 0 ? 'var(--pos)' : 'var(--neg)');
        $('#kv-pc-unid-ped').text(fmt.num(k.UNID_PEDIDO));
        $('#kv-pc-unid-fact').text(fmt.num(k.UNID_FACT));

        const evol = data.evolucion || [];
        const multiAnioPC = evol.length > 1 && evol[0].ANIO !== evol[evol.length - 1].ANIO;
        if (chartPedidos) chartPedidos.destroy();
        chartPedidos = new Chart($('#chart-pedidos-evol')[0], {
            type: 'bar',
            data: {
                labels  : evol.map(r => multiAnioPC ? r.NOMBRE_MES.substring(0, 3) + ' ' + r.ANIO : r.NOMBRE_MES),
                datasets: [{ label: 'Pedidos', data: evol.map(r => r.PEDIDOS_MES), backgroundColor: 'rgba(37,99,235,.65)', borderRadius: 4 }],
            },
            options: { ...chartOptions('Pedidos', {}, { integer: true }), plugins: { legend: { display: false } } },
        });

        const $tbody = $('#tbody-pedidos').empty();
        const tabla  = data.tabla || [];
        if (!tabla.length) {
            $tbody.append('<tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }
        $tbody.html(tabla.map(r => `<tr class="pedido-row" data-pedido="${escapeHtml(String(r.NRO_PEDIDO).trim())}">
            <td>${r.NRO_PEDIDO}</td>
            <td>${r.CANAL || '—'}</td>
            <td>${badgeEstado(r.ESTADO)}</td>
            <td>${fmt.date(r.FECHA_PEDI)}</td>
            <td>${r.TALON_PED || '—'}</td>
            <td class="col-num">${fmt.num(r.UNID_PEDIDO)}</td>
            <td class="col-num">${fmt.num(r.UNID_PENDIENTES)}</td>
            <td class="col-num">${fmt.num(r.UNID_FACTURADAS)}</td>
        </tr>`).join(''));
    }

    // ── Área 8: Evolución Tipo de Remisión ──────────────────────────────
    async function loadEvolucionRemision() {
        const data = await apiFetch('evolucion_remision', { rubro: State.rubro });
        remisionRawData = data;
        renderEvolucionRemision();
    }

    function renderEvolucionRemision() {
        if (!remisionRawData) return;
        const evol = remisionRawData.evolucion || [];
        const kpis = remisionRawData.kpis || {};
        const isUnid = (remisionMetric === 'unidades');

        // Totales basados en la métrica activa
        const totalDist = isUnid ? parseFloat(kpis.TOTAL_DIST_UNID || 0) : parseFloat(kpis.TOTAL_DIST_PED || 0);
        const totalRepo = isUnid ? parseFloat(kpis.TOTAL_REPO_UNID || 0) : parseFloat(kpis.TOTAL_REPO_PED || 0);
        const totalProp = isUnid ? parseFloat(kpis.TOTAL_PROPIOS_UNID || 0) : parseFloat(kpis.TOTAL_PROPIOS_PED || 0);
        const totalFran = isUnid ? parseFloat(kpis.TOTAL_FRANQ_UNID || 0) : parseFloat(kpis.TOTAL_FRANQ_PED || 0);
        const totalGran = isUnid ? parseFloat(kpis.TOTAL_UNIDADES || 0) : parseFloat(kpis.TOTAL_PEDIDOS || 0);

        // 1. KPI Cards
        // setVar espera { text, cls }: participación sobre el total general
        const pctDelTotal = v => ({ text: totalGran > 0 ? fmt.pct(v / totalGran) + ' del total' : '—', cls: 'neu' });

        $('#kv-rem-dist-total').text(fmt.num(totalDist));
        setVar('#kvar-rem-dist-pct', pctDelTotal(totalDist));

        $('#kv-rem-repo-total').text(fmt.num(totalRepo));
        setVar('#kvar-rem-repo-pct', pctDelTotal(totalRepo));

        $('#kv-rem-propios-total').text(fmt.num(totalProp));
        setVar('#kvar-rem-propios-pct', pctDelTotal(totalProp));

        $('#kv-rem-franq-total').text(fmt.num(totalFran));
        setVar('#kvar-rem-franq-pct', pctDelTotal(totalFran));

        $('#kv-rem-gran-total').text(fmt.num(totalGran));
        $('#kvar-rem-pedidos-total').text(
            isUnid 
                ? (fmt.num(kpis.TOTAL_PEDIDOS) + ' pedidos consolidados') 
                : (fmt.num(kpis.TOTAL_UNIDADES) + ' unidades remitidas')
        );

        // 2. Gráficos (Labels: 'Ene 2026', 'Feb 2026', etc.)
        const labels = evol.map(r => r.MES_NOMBRE + ' ' + r.ANIO);

        // Paleta de colores acorde a XL
        const COLOR_FRANQ = '#7c3aed';    // Violeta
        const COLOR_PROP  = '#f59e0b';    // Ámbar
        const COLOR_DIST  = '#2563eb';    // Azul
        const COLOR_REPO  = '#00a878';    // Verde Esmeralda

        // Gráfica 1: Universo Todas las Distribuciones (Franquicias vs Propios)
        const d1Fran = evol.map(r => isUnid ? parseFloat(r.DIST_FRANQ_UNID || 0) : parseFloat(r.DIST_FRANQ_PED || 0));
        const d1FranLoc = evol.map(r => parseInt(r.DIST_FRANQ_LOCALES || 0));
        const d1Prop = evol.map(r => isUnid ? parseFloat(r.DIST_PROPIOS_UNID || 0) : parseFloat(r.DIST_PROPIOS_PED || 0));
        const d1PropLoc = evol.map(r => parseInt(r.DIST_PROPIOS_LOCALES || 0));
        chartRemDist = createStackedRemChart(
            chartRemDist,
            'chart-rem-distribuciones',
            labels,
            [
                { label: 'Franquicias', data: d1Fran, locales: d1FranLoc, color: COLOR_FRANQ },
                { label: 'Locales Propios', data: d1Prop, locales: d1PropLoc, color: COLOR_PROP }
            ],
            'Distribuciones',
            true
        );

        // Gráfica 2: Universo Todas las Reposiciones (Franquicias vs Propios)
        const d2Fran = evol.map(r => isUnid ? parseFloat(r.REPO_FRANQ_UNID || 0) : parseFloat(r.REPO_FRANQ_PED || 0));
        const d2FranLoc = evol.map(r => parseInt(r.REPO_FRANQ_LOCALES || 0));
        const d2Prop = evol.map(r => isUnid ? parseFloat(r.REPO_PROPIOS_UNID || 0) : parseFloat(r.REPO_PROPIOS_PED || 0));
        const d2PropLoc = evol.map(r => parseInt(r.REPO_PROPIOS_LOCALES || 0));
        chartRemRepo = createStackedRemChart(
            chartRemRepo,
            'chart-rem-reposiciones',
            labels,
            [
                { label: 'Franquicias', data: d2Fran, locales: d2FranLoc, color: COLOR_FRANQ },
                { label: 'Locales Propios', data: d2Prop, locales: d2PropLoc, color: COLOR_PROP }
            ],
            'Reposiciones',
            true
        );

        // Gráfica 3: Universo Propios (Distribución vs Reposición)
        const d3Dist = evol.map(r => isUnid ? parseFloat(r.DIST_PROPIOS_UNID || 0) : parseFloat(r.DIST_PROPIOS_PED || 0));
        const d3Repo = evol.map(r => isUnid ? parseFloat(r.REPO_PROPIOS_UNID || 0) : parseFloat(r.REPO_PROPIOS_PED || 0));
        chartRemPropios = createStackedRemChart(
            chartRemPropios,
            'chart-rem-propios',
            labels,
            [
                { label: 'Distribución', data: d3Dist, color: COLOR_DIST },
                { label: 'Reposición', data: d3Repo, color: COLOR_REPO }
            ],
            'Pedidos Propios'
        );

        // Gráfica 4: Universo Franquicias (Distribución vs Reposición)
        const d4Dist = evol.map(r => isUnid ? parseFloat(r.DIST_FRANQ_UNID || 0) : parseFloat(r.DIST_FRANQ_PED || 0));
        const d4Repo = evol.map(r => isUnid ? parseFloat(r.REPO_FRANQ_UNID || 0) : parseFloat(r.REPO_FRANQ_PED || 0));
        chartRemFranq = createStackedRemChart(
            chartRemFranq,
            'chart-rem-franquicias',
            labels,
            [
                { label: 'Distribución', data: d4Dist, color: COLOR_DIST },
                { label: 'Reposición', data: d4Repo, color: COLOR_REPO }
            ],
            'Pedidos Franquicias'
        );

        // Gráfica 5: Universo Total General (Distribución vs Reposición)
        const d5Dist = evol.map(r => isUnid ? parseFloat(r.DIST_TOTAL_UNID || 0) : parseFloat(r.DIST_TOTAL_PED || 0));
        const d5Repo = evol.map(r => isUnid ? parseFloat(r.REPO_TOTAL_UNID || 0) : parseFloat(r.REPO_TOTAL_PED || 0));
        chartRemTotal = createStackedRemChart(
            chartRemTotal,
            'chart-rem-total-general',
            labels,
            [
                { label: 'Distribución', data: d5Dist, color: COLOR_DIST },
                { label: 'Reposición', data: d5Repo, color: COLOR_REPO }
            ],
            'Total General'
        );

        // 3. Renderizado de la tabla de detalle
        renderRemisionTabla();
    }

    function createStackedRemChart(existingChart, canvasId, labels, series, universeTitle, hasLocales = false) {
        if (existingChart) existingChart.destroy();
        const canvas = document.getElementById(canvasId);
        if (!canvas) return null;

        // Calcular porcentajes 100% por mes
        const count = labels.length;
        const pctSeries0 = [];
        const pctSeries1 = [];
        const rawTotals  = [];

        for (let i = 0; i < count; i++) {
            const v0 = series[0].data[i] || 0;
            const v1 = series[1].data[i] || 0;
            const sum = v0 + v1;
            rawTotals.push(sum);
            if (sum > 0) {
                pctSeries0.push((v0 / sum) * 100);
                pctSeries1.push((v1 / sum) * 100);
            } else {
                pctSeries0.push(0);
                pctSeries1.push(0);
            }
        }

        const isUnid = (remisionMetric === 'unidades');
        const metricLabel = isUnid ? 'unidades' : 'pedidos';

        // Plugin para dibujar directamente los valores y porcentajes sobre las barras
        const stackedBarLabelsPlugin = {
            id: 'stackedBarLabels_' + canvasId,
            afterDatasetsDraw(chart) {
                const { ctx } = chart;
                ctx.save();

                chart.data.datasets.forEach((dataset, dsIdx) => {
                    const meta = chart.getDatasetMeta(dsIdx);
                    if (meta.hidden) return;

                    meta.data.forEach((bar, index) => {
                        const pct = dataset.data[index] || 0;
                        const rawVal = dataset.rawValues[index] || 0;
                        const locs = dataset.locales ? (dataset.locales[index] || 0) : null;
                        const prom = (locs && locs > 0) ? (rawVal / locs) : null;

                        if (pct < 5) return; // Si es menor a 5%, no dibujar dentro

                        const { x, y, base } = bar;
                        const height = Math.abs(base - y);
                        if (height < 20) return;

                        const centerY = (y + base) / 2;

                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.fillStyle = '#ffffff';

                        ctx.shadowColor = 'rgba(0, 0, 0, 0.45)';
                        ctx.shadowBlur = 4;
                        ctx.shadowOffsetX = 0;
                        ctx.shadowOffsetY = 1;

                        if (locs != null && height >= 48) {
                            ctx.font = 'bold 12px Inter, system-ui, -apple-system, sans-serif';
                            ctx.fillText(pct.toFixed(1) + '%', x, centerY - 12);
                            ctx.font = '600 10px Inter, system-ui, -apple-system, sans-serif';
                            ctx.fillText(fmt.num(rawVal), x, centerY);
                            ctx.font = '500 9px Inter, system-ui, -apple-system, sans-serif';
                            ctx.fillText(`${locs} loc · ⌀ ${fmt.num(prom, 0)}`, x, centerY + 11);
                        } else if (locs != null && height >= 34) {
                            ctx.font = 'bold 11px Inter, system-ui, -apple-system, sans-serif';
                            ctx.fillText(pct.toFixed(1) + '% (' + fmt.num(rawVal) + ')', x, centerY - 6);
                            ctx.font = '500 9px Inter, system-ui, -apple-system, sans-serif';
                            ctx.fillText(`⌀ ${fmt.num(prom, 0)} (${locs} loc)`, x, centerY + 6);
                        } else if (height >= 38) {
                            ctx.font = 'bold 12px Inter, system-ui, -apple-system, sans-serif';
                            ctx.fillText(pct.toFixed(1) + '%', x, centerY - 7);
                            ctx.font = '600 10.5px Inter, system-ui, -apple-system, sans-serif';
                            ctx.fillText(fmt.num(rawVal), x, centerY + 8);
                        } else {
                            ctx.font = 'bold 11px Inter, system-ui, -apple-system, sans-serif';
                            ctx.fillText(pct.toFixed(1) + '% (' + fmt.num(rawVal) + ')', x, centerY);
                        }
                    });
                });

                // Dibujar la suma total arriba de cada columna
                const topMeta = chart.getDatasetMeta(chart.data.datasets.length - 1);
                if (topMeta && !topMeta.hidden) {
                    topMeta.data.forEach((bar, index) => {
                        const total = rawTotals[index] || 0;
                        if (total <= 0) return;
                        const { x, y } = bar;

                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        ctx.fillStyle = '#0f172a';
                        ctx.shadowColor = 'transparent';
                        ctx.shadowBlur = 0;
                        ctx.shadowOffsetX = 0;
                        ctx.shadowOffsetY = 0;
                        ctx.font = 'bold 11px Inter, system-ui, -apple-system, sans-serif';
                        ctx.fillText(fmt.num(total), x, y - 4);
                    });
                }

                ctx.restore();
            }
        };

        return new Chart(canvas, {
            type: 'bar',
            plugins: [stackedBarLabelsPlugin],
            data: {
                labels: labels,
                datasets: [
                    {
                        label: series[0].label,
                        data: pctSeries0,
                        backgroundColor: series[0].color,
                        borderRadius: { topLeft: 0, topRight: 0, bottomLeft: 4, bottomRight: 4 },
                        maxBarThickness: 90,
                        rawValues: series[0].data,
                        locales: series[0].locales || null,
                    },
                    {
                        label: series[1].label,
                        data: pctSeries1,
                        backgroundColor: series[1].color,
                        borderRadius: { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 },
                        maxBarThickness: 90,
                        rawValues: series[1].data,
                        locales: series[1].locales || null,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                onClick: function (evt, elements) {
                    if (elements && elements.length) {
                        const idx = elements[0].index;
                        const evol = remisionRawData.evolucion || [];
                        if (evol[idx]) {
                            const sel = evol[idx].PERIODO;
                            remisionFilterMes = (remisionFilterMes === sel) ? null : sel;
                            updateRemisionFilterUI();
                            renderRemisionTabla();
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: { font: { family: 'inherit', size: 11, weight: 600 } }
                    },
                    y: {
                        stacked: true,
                        min: 0,
                        max: 114,
                        grid: { color: 'rgba(0,0,0,0.06)' },
                        ticks: {
                            callback: v => v <= 100 ? v + '%' : '',
                            font: { family: 'inherit', size: 11 }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 12,
                            boxHeight: 12,
                            usePointStyle: true,
                            font: { family: 'inherit', size: 12, weight: 600 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const ds = ctx.dataset;
                                const dsIdx = ctx.datasetIndex;
                                const dataIdx = ctx.dataIndex;
                                const pctVal = ctx.parsed.y || 0;
                                const rawVal = ds.rawValues[dataIdx] || 0;
                                const locs   = ds.locales ? (ds.locales[dataIdx] || 0) : null;

                                if (locs != null && locs > 0) {
                                    const prom = rawVal / locs;
                                    return [
                                        ` ${ds.label}: ${pctVal.toFixed(1)}% (${fmt.num(rawVal)} ${metricLabel})`,
                                        `    ↳ ${locs} ptos. venta | Promedio: ${fmt.num(prom, 1)} ${metricLabel}/local`
                                    ];
                                }
                                return ` ${ds.label}: ${pctVal.toFixed(1)}% (${fmt.num(rawVal)} ${metricLabel})`;
                            },
                            footer: function (items) {
                                if (!items.length) return '';
                                const dataIdx = items[0].dataIndex;
                                const total = rawTotals[dataIdx] || 0;
                                return `Total ${universeTitle}: ${fmt.num(total)} ${metricLabel} (100%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    function updateRemisionFilterUI() {
        if (remisionFilterMes) {
            $('#rem-filter-mes-txt').text(remisionFilterMes);
            $('#remision-filter-status').show();
        } else {
            $('#remision-filter-status').hide();
        }
    }

    function renderRemisionTabla() {
        if (!remisionRawData) return;
        const allRows = remisionRawData.detalle || [];
        const rows = remisionFilterMes 
            ? allRows.filter(r => r.PERIODO === remisionFilterMes)
            : allRows;

        const $tbody = $('#tbody-remision-detalle').empty();
        const $tfoot = $('#tfoot-remision-detalle').empty();

        if (!rows.length) {
            $tbody.html('<tr><td colspan="11"><div class="empty-state"><i class="bi bi-inbox"></i> Sin datos para el filtro seleccionado</div></td></tr>');
            return;
        }

        // Totales del grupo visualizado
        let totPed = 0, totFact = 0, totPedidas = 0, totLocales = 0;
        rows.forEach(r => {
            totLocales += parseInt(r.CANT_LOCALES || 0);
            totPed     += parseFloat(r.CANT_PEDIDOS || 0);
            totFact    += parseFloat(r.UNID_FACTURADAS || 0);
            totPedidas += parseFloat(r.UNID_PEDIDAS || 0);
        });

        const globalPromPed = totLocales > 0 ? (totPed / totLocales) : 0;
        const globalPromFact = totLocales > 0 ? (totFact / totLocales) : 0;

        const html = rows.map(r => {
            const locs = parseInt(r.CANT_LOCALES || 0);
            const peds = parseFloat(r.CANT_PEDIDOS || 0);
            const fact = parseFloat(r.UNID_FACTURADAS || 0);
            const pedi = parseFloat(r.UNID_PEDIDAS || 0);
            const promPed = locs > 0 ? (peds / locs) : 0;
            const promFact = locs > 0 ? (fact / locs) : 0;
            const pctPed = totPed > 0 ? (peds / totPed) : 0;
            const pctFact = totFact > 0 ? (fact / totFact) : 0;

            const badgeCanal = r.CANAL_AGRUP === 'PROPIOS' 
                ? '<span class="badge-estado badge-facturado" style="background:rgba(245,158,11,.15);color:#b45309">Locales Propios</span>'
                : '<span class="badge-estado badge-facturado" style="background:rgba(124,58,237,.15);color:#7c3aed">Franquicias</span>';

            const badgeTipo = r.TIPO_AGRUP === 'DISTRIBUCION'
                ? '<span class="badge-estado badge-facturado" style="background:rgba(37,99,235,.15);color:#1d4ed8">Distribución</span>'
                : '<span class="badge-estado badge-facturado" style="background:rgba(0,168,120,.15);color:#047857">Reposición</span>';

            return `<tr>
                <td><strong>${r.MES_ANIO || r.PERIODO}</strong></td>
                <td>${badgeCanal}</td>
                <td>${badgeTipo}</td>
                <td class="col-num">${fmt.num(locs)}</td>
                <td class="col-num">${fmt.num(peds)}</td>
                <td class="col-num" style="color:var(--accent2);font-weight:600">${fmt.num(promPed, 1)}</td>
                <td class="col-num">${fmt.pct(pctPed)}</td>
                <td class="col-num">${fmt.num(fact)}</td>
                <td class="col-num" style="color:var(--accent);font-weight:600">${fmt.num(promFact, 1)}</td>
                <td class="col-num">${fmt.pct(pctFact)}</td>
                <td class="col-num">${fmt.num(pedi)}</td>
            </tr>`;
        }).join('');

        $tbody.html(html);

        $tfoot.html(`<tr>
            <td colspan="3"><strong>Total ${remisionFilterMes ? '(' + remisionFilterMes + ')' : 'Consolidado'}</strong></td>
            <td class="col-num"><strong>${fmt.num(totLocales)}</strong></td>
            <td class="col-num"><strong>${fmt.num(totPed)}</strong></td>
            <td class="col-num" style="color:var(--accent2)"><strong>${fmt.num(globalPromPed, 1)}</strong></td>
            <td class="col-num"><strong>100.0%</strong></td>
            <td class="col-num"><strong>${fmt.num(totFact)}</strong></td>
            <td class="col-num" style="color:var(--accent)"><strong>${fmt.num(globalPromFact, 1)}</strong></td>
            <td class="col-num"><strong>100.0%</strong></td>
            <td class="col-num"><strong>${fmt.num(totPedidas)}</strong></td>
        </tr>`);
    }

    // ── Helpers de render ─────────────────────────────────────────────────
    function renderTablaSimple(sel, rows, rowFn, pedidoFn) {
        const $tbody = $(sel).empty();
        if (!rows.length) {
            $tbody.append(`<tr><td colspan="10"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>`);
            return;
        }
        $tbody.html(rows.map(r => {
            const ped  = pedidoFn ? pedidoFn(r) : null;
            const attr = ped ? ` class="pedido-row" data-pedido="${escapeHtml(String(ped).trim())}"` : '';
            return `<tr${attr}>` + rowFn(r) + '</tr>';
        }).join(''));
    }

    function badgeEstado(estado) {
        if (!estado) return '—';
        const map = {
            'PENDIENTE'     : 'badge-pendiente',
            'SIN FACTURAR'  : 'badge-pendiente',
            'FACTURADO'     : 'badge-facturado',
            'CANCELADO'     : 'badge-cancelado',
            'DEMORADO'      : 'badge-demorado',
            'FUERA DE PLAZO': 'badge-fuera',
            'EN TERMINO'    : 'badge-facturado',
        };
        const cls = map[estado.toUpperCase()] || '';
        return `<span class="badge-estado ${cls}" title="Estado: ${escapeHtml(estado)}">${estado}</span>`;
    }

    // ── Modal: detalle de pedido (líneas por artículo) ────────────────────
    function pmetaItem(label, val) {
        return `<div class="pmeta-item"><span class="pmeta-label">${label}</span>` +
               `<span class="pmeta-val">${escapeHtml(String(val))}</span></div>`;
    }
    function openModal()  { $('#pedido-modal').removeAttr('hidden'); $('body').addClass('modal-open'); }
    function closeModal() {
        $('#pedido-modal').attr('hidden', '');
        if (!$('.pmodal:not([hidden])').length) $('body').removeClass('modal-open');
    }

    async function openPedidoDetalle(nroPedido) {
        if (!nroPedido) return;
        const $meta = $('#pmodal-meta');
        $('#pmodal-title').html(`<i class="bi bi-receipt"></i> Detalle del pedido ${escapeHtml(nroPedido)}`);
        $meta.html('<div class="pmodal-loading"><i class="bi bi-arrow-repeat"></i> Cargando detalle…</div>');
        $('#tbody-pedido-detalle').empty();
        $('#tfoot-pedido-detalle').empty();
        openModal();
        try {
            const data   = await apiFetch('pedido_detalle', { pedido: nroPedido });
            const h      = data.header   || {};
            const rubros = data.rubros   || [];
            const tot    = data.totales  || {};
            const efi    = tot.EFICIENCIA == null ? null : parseFloat(tot.EFICIENCIA);

            $meta.html(
                pmetaItem('Cliente', h.CLIENTE || '—') +
                pmetaItem('Canal', h.CANAL || '—') +
                pmetaItem('Fecha pedido', fmt.date(h.FECHA_PEDI)) +
                pmetaItem('Talón', h.TALON_PED != null ? h.TALON_PED : '—') +
                pmetaItem('U. pedidas', fmt.num(tot.CANT_PEDID)) +
                pmetaItem('U. remitidas', fmt.num(tot.CANT_FACT)) +
                `<div class="pmeta-item pmeta-efi"><span class="pmeta-label">% Eficiencia</span>` +
                `<span class="pmeta-val" style="color:${efiColor(efi)}">${efi == null ? '—' : fmt.pct(efi, 0)}</span></div>`
            );

            renderTablaSimple('#tbody-pedido-detalle', rubros,
                r => `<td title="${escapeHtml(r.RUBRO || '')}">${r.RUBRO || '—'}</td>` +
                     `<td class="col-num">${fmt.num(r.CANT_PEDID)}</td>` +
                     `<td class="col-num">${fmt.num(r.CANT_FACT)}</td>` +
                     `<td class="col-num">${efiCell(r.EFICIENCIA)}</td>`
            );

            if (rubros.length) {
                $('#tfoot-pedido-detalle').html(
                    `<tr><td>Total</td>` +
                    `<td class="col-num">${fmt.num(tot.CANT_PEDID)}</td>` +
                    `<td class="col-num">${fmt.num(tot.CANT_FACT)}</td>` +
                    `<td class="col-num">${efiCell(efi)}</td></tr>`
                );
            } else {
                $meta.append('<div class="pmodal-note">Sin detalle disponible para este pedido.</div>');
            }
        } catch (e) {
            $meta.html(`<div class="error-state"><i class="bi bi-exclamation-triangle-fill"></i> ${escapeHtml(e.message)}</div>`);
        }
    }

    // ── Área 9: Pedidos estancados (vista supply chain) ──────────────────
    let estExportInit = false;

    // Cobertura de stock del saldo (descriptiva: la pestaña es de análisis, no sugiere acciones)
    const EST_COBERTURA = {
        'CON STOCK'    : { cls: 'remitir', badge: 'badge-acc-remitir', txt: 'Con stock',
                           desc: 'Hay stock del artículo para todas las unidades pendientes.' },
        'STOCK PARCIAL': { cls: 'revisar', badge: 'badge-acc-revisar', txt: 'Stock parcial',
                           desc: 'Hay stock solo para parte de las unidades pendientes.' },
        'SIN STOCK'    : { cls: 'cerrar',  badge: 'badge-acc-cerrar',  txt: 'Sin stock',
                           desc: 'No hay stock para ninguna de las unidades pendientes.' },
    };

    async function loadEstancados() {
        const data = await apiFetch('pedidos_estancados', { dias: State.estDias, canal: State.canal });
        const k = data.kpis || {};
        const money = v => '$ ' + fmt.num(v, 0);
        const ped = parseInt(k.PEDIDOS, 10) || 0;

        $('#est-rango').text(k.DESDE
            ? `Pedidos cargados entre el ${fmt.date(k.DESDE)} y el ${fmt.date(k.HASTA)} · situación a hoy`
            : 'Situación actual · no depende del rango de fechas');

        // 1. ¿Cuánto hay trabado?
        $('#kv-est-ped').text(fmt.num(ped));
        setVar('#kvar-est-ped', { text: `${fmt.num(k.PED_PARCIAL)} remitidos en parte · ${fmt.num(k.PED_SIN_REMITIR)} sin nada remitido`, cls: 'neu' });
        $('#kv-est-unid').text(fmt.num(k.UNID_PENDIENTES));
        setVar('#kvar-est-unid', { text: ped > 0 ? `${fmt.num(parseFloat(k.UNID_PENDIENTES) / ped, 1)} unidades por pedido` : '', cls: 'neu' });
        $('#kv-est-imp').text(money(k.IMPORTE_PENDIENTE));
        setVar('#kvar-est-imp', { text: ped > 0 ? `${money(parseFloat(k.IMPORTE_PENDIENTE) / ped)} por pedido` : '', cls: 'neu' });
        $('#kv-est-dias').text(ped > 0 ? fmt.num(k.DIAS_PROMEDIO, 0) + ' días' : '—');
        setVar('#kvar-est-dias', { text: ped > 0 ? 'El más viejo: ' + fmt.num(k.DIAS_MAX) + ' días' : '', cls: 'neu' });

        // 2. ¿Por qué está trabado? unidades con / sin stock
        const uPend = parseFloat(k.UNID_PENDIENTES) || 0;
        const uCon  = parseFloat(k.UNID_CON_STOCK) || 0;
        const pCon  = uPend > 0 ? uCon / uPend : 0;
        $('#est-stock-txt').html(uPend > 0
            ? `De las <b>${fmt.num(uPend)}</b> unidades pendientes, <b>${fmt.num(uCon)}</b> (${fmt.pct(pCon, 0)}) tienen stock disponible y ` +
              `<b>${fmt.num(k.UNID_SIN_STOCK)}</b> (${fmt.pct(1 - pCon, 0)}) no tienen stock en el depósito 01.`
            : 'No hay unidades pendientes.');
        $('#est-stock-con').css('width', (pCon * 100).toFixed(1) + '%');
        const inc = parseInt(k.PED_TANGO_COMPLETO, 10) || 0;
        if (inc > 0) $('#est-stock-txt').append(`<br><small>Dato inconsistente: ${fmt.num(inc)} pedido(s) figuran COMPLETO en Tango pero tienen unidades pendientes.</small>`);

        // 3. Pedidos según la cobertura de stock del saldo
        const cob = data.cobertura || [];
        $('#est-cobertura').html(cob.length ? cob.map(a => {
            const cfg = EST_COBERTURA[a.COBERTURA] || { cls: '', txt: a.COBERTURA, desc: '' };
            return `<button type="button" class="est-accion ${cfg.cls}${State.estCobertura === a.COBERTURA ? ' activo' : ''}" data-a="${escapeHtml(a.COBERTURA)}">` +
                `<h4>${escapeHtml(cfg.txt)}</h4>` +
                `<div class="est-accion-num">${fmt.num(a.PEDIDOS)} pedidos</div>` +
                `<p>${fmt.num(a.UNID_PENDIENTES)} unidades pendientes · ${money(a.IMPORTE_PENDIENTE)}</p>` +
                `<p>${escapeHtml(cfg.desc)}</p></button>`;
        }).join('') : `<div class="empty-state"><i class="bi bi-inbox"></i>Sin pedidos estancados</div>`);

        // 4. ¿Desde cuándo se acumula?
        const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        const meses = data.meses || [];
        const maxImp = Math.max(1, ...meses.map(r => parseFloat(r.IMPORTE_PENDIENTE) || 0));
        renderTablaSimple('#tbody-est-meses', meses,
            r => {
                const [y, m] = String(r.MES).split('-');
                const imp = parseFloat(r.IMPORTE_PENDIENTE) || 0;
                return `<td>${MESES[parseInt(m, 10) - 1] || r.MES} ${y}</td><td class="col-num">${fmt.num(r.PEDIDOS)}</td>` +
                    `<td class="col-num">${fmt.num(r.UNID_PENDIENTES)}</td><td class="col-num">${money(imp)}</td>` +
                    `<td><div class="fr-bar" style="width:${(imp / maxImp * 100).toFixed(1)}%"></div></td>`;
            }
        );

        // 5. ¿Por dónde empezar? (clientes)
        const clientes = data.clientes || [];
        const $cli = $('#tbody-est-clientes').empty();
        if (!clientes.length) {
            $cli.append(`<tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>`);
        } else {
            $cli.html(clientes.map(r => {
                const c = String(r.CLIENTE || '');
                const rem = parseInt(r.PED_CON_STOCK, 10) || 0;
                return `<tr class="est-cli-row${c === State.estCliente ? ' activo' : ''}" data-cliente="${escapeHtml(c)}">` +
                    `<td title="${escapeHtml(c)}">${escapeHtml(c) || '—'}</td><td>${escapeHtml(r.CANAL || '—')}</td>` +
                    `<td class="col-num">${fmt.num(r.PEDIDOS)}</td><td class="col-num">${fmt.num(r.UNID_PENDIENTES)}</td>` +
                    `<td class="col-num">${money(r.IMPORTE_PENDIENTE)}</td>` +
                    `<td class="col-num"${rem > 0 ? ' style="color:var(--pos);font-weight:700"' : ''}>${fmt.num(rem)}</td></tr>`;
            }).join(''));
        }

        State.estancados = data.pedidos || [];
        State.estTotal   = ped;
        renderEstancados();

        if (!estExportInit) {
            ExcelExporter.addExportButton(document.getElementById('hdr-est-pedidos'), exportEstancados);
            estExportInit = true;
        }
    }

    function filtrarEstancados() {
        const q  = State.estBusca.trim().toUpperCase();
        const q0 = q.replace(/^0+/, '');   // permite buscar el pedido sin los ceros a la izquierda
        return State.estancados.filter(r => {
            if (State.estCobertura !== 'ALL' && r.COBERTURA !== State.estCobertura) return false;
            if (State.estCliente && String(r.CLIENTE || '') !== State.estCliente) return false;
            if (!q) return true;
            const ped = String(r.NRO_PEDIDO || '');
            return String(r.CLIENTE || '').toUpperCase().includes(q) ||
                   ped.includes(q) || (q0 !== '' && ped.replace(/^0+/, '').includes(q0));
        });
    }

    function renderEstancados() {
        const rows = filtrarEstancados();
        const cargados = State.estancados.length;
        const corte = State.estTotal > cargados
            ? ` · se cargaron los ${fmt.num(cargados)} de mayor importe (de ${fmt.num(State.estTotal)})` : '';
        $('#est-count').text(`${fmt.num(rows.length)} pedidos${corte} · clic para ver el detalle`);

        $('#est-cob-filtro .pill').removeClass('active').filter(`[data-a="${State.estCobertura}"]`).addClass('active');
        $('#est-cobertura .est-accion').removeClass('activo').filter(`[data-a="${State.estCobertura}"]`).addClass('activo');

        const $act = $('#est-cliente-activo');
        if (State.estCliente) {
            $act.html(`<i class="bi bi-funnel-fill"></i> Cliente: <b>${escapeHtml(State.estCliente)}</b>` +
                      `<button type="button" id="est-cliente-quitar" title="Quitar filtro de cliente" aria-label="Quitar filtro de cliente"><i class="bi bi-x-circle-fill"></i></button>`)
                .prop('hidden', false);
        } else {
            $act.empty().prop('hidden', true);
        }

        renderTablaSimple('#tbody-est-pedidos', rows,
            r => {
                const dias = parseInt(r.DIAS, 10);
                const dCls = dias > 180 ? 'est-dias-alto' : dias >= 90 ? 'est-dias-medio' : '';
                const sCls = r.SITUACION === 'SIN REMITIR' ? 'badge-sin-remitir' : 'badge-parcial';
                const sTxt = r.SITUACION === 'SIN REMITIR' ? 'Sin remitir' : 'Remitido en parte';
                const cobR = EST_COBERTURA[r.COBERTURA] || { badge: 'badge-sin-pedido', txt: r.COBERTURA || '—', desc: '' };
                return `<td>${escapeHtml(r.NRO_PEDIDO)}</td><td>${fmt.date(r.FECHA_PEDI)}</td>` +
                    `<td class="col-num ${dCls}">${fmt.num(dias)}</td><td>${escapeHtml(r.CANAL || '—')}</td>` +
                    `<td title="${escapeHtml(r.CLIENTE || '')}">${escapeHtml(r.CLIENTE || '—')}</td>` +
                    `<td>${escapeHtml(r.ESTADO_TANGO || '—')}</td>` +
                    `<td><span class="badge-estado ${sCls}">${sTxt}</span></td>` +
                    `<td class="col-num">${fmt.num(r.UNID_PEDIDAS)}</td><td class="col-num">${fmt.num(r.UNID_PENDIENTES)}</td>` +
                    `<td class="col-num">${fmt.num(r.UNID_CON_STOCK)}</td>` +
                    `<td class="col-num">$ ${fmt.num(r.IMPORTE_PENDIENTE, 0)}</td>` +
                    `<td><span class="badge-estado ${cobR.badge}" title="${escapeHtml(cobR.desc)}">${escapeHtml(cobR.txt)}</span></td>`;
            },
            r => r.NRO_PEDIDO
        );
    }

    function exportEstancados() {
        const rows = filtrarEstancados();
        if (!rows.length) {
            alert('No hay pedidos estancados para exportar con los filtros actuales.');
            return;
        }
        const filtros = [
            'Antigüedad ≥ ' + State.estDias + ' días (año en curso)',
            State.canal ? 'Canal: ' + State.canal : '',
            State.estCobertura !== 'ALL' ? 'Stock: ' + ((EST_COBERTURA[State.estCobertura] || {}).txt || State.estCobertura) : '',
            State.estCliente ? 'Cliente: ' + State.estCliente : '',
            State.estBusca.trim() ? 'Búsqueda: ' + State.estBusca.trim() : '',
        ].filter(Boolean).join(' · ');
        const corte = State.estTotal > State.estancados.length
            ? ` (top ${State.estancados.length} de ${State.estTotal} por importe)` : '';
        ExcelExporter.export({
            title     : `Pedidos estancados — ${filtros}${corte}`,
            headers   : ['Pedido', 'Talón', 'Fecha pedido', 'Días', 'Canal', 'Cliente', 'Tipo', 'Estado Tango', 'Situación',
                         'Unidades pedidas', 'Unidades pendientes', 'Pendientes con stock', 'Importe pedido', 'Importe pendiente', 'Stock para el saldo'],
            rows      : rows.map(r => [
                String(r.NRO_PEDIDO || ''), r.TALON_PED, fmt.date(r.FECHA_PEDI), parseInt(r.DIAS, 10),
                r.CANAL || '', r.CLIENTE || '', r.TIPO_FACTURACION || '', r.ESTADO_TANGO || '',
                r.SITUACION === 'SIN REMITIR' ? 'Sin remitir' : 'Remitido en parte',
                parseFloat(r.UNID_PEDIDAS || 0), parseFloat(r.UNID_PENDIENTES || 0), parseFloat(r.UNID_CON_STOCK || 0),
                parseFloat(r.IMPORTE_PEDIDO || 0), parseFloat(r.IMPORTE_PENDIENTE || 0),
                (EST_COBERTURA[r.COBERTURA] || {}).txt || r.COBERTURA || '',
            ]),
            colFormats: ['text', 'num', 'text', 'num', 'text', 'text', 'text', 'text', 'text', 'num', 'num', 'num', 'money', 'money', 'text'],
            filename  : 'pedidos_estancados',
        });
    }

    // ── Área 10: Fill Rate por remito ─────────────────────────────────────
    let frExportInit = false;
    const frAbiertos = new Set();   // pedidos desplegados en el detalle (sobreviven a filtros)

    function frAddDays(ymd, n) {   // aritmética en UTC para no correr el día por huso horario
        const d = new Date(ymd + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().substring(0, 10);
    }

    async function loadFillRate() {
        const data = await apiFetch('fill_rate', { fecha: State.frFecha, canal: State.canal, tipo: State.frTipo });
        const k = data.kpis || {};

        // El SP resuelve la fecha (último día con datos si vino vacía)
        State.frFecha    = k.FECHA || State.frFecha;
        State.frFechaMax = k.FECHA_MAX || '';
        $('#fr-fecha').val(State.frFecha).attr('max', State.frFechaMax);
        $('#fr-next').prop('disabled', !State.frFechaMax || State.frFecha >= State.frFechaMax);
        $('#fr-ultimo').prop('disabled', !State.frFechaMax || State.frFecha === State.frFechaMax);
        $('#fr-carga').text('Qué se remitió en el día y cómo quedaron esos pedidos · datos cargados hasta ' + fmt.date(State.frFechaMax));

        const ped = parseInt(k.PEDIDOS, 10) || 0;
        $('#kv-fr-unid').text(fmt.num(k.UNID_REMITIDAS));
        setVar('#kvar-fr-unid', { text: parseFloat(k.UNID_SIN_PEDIDO) > 0 ? fmt.num(k.UNID_SIN_PEDIDO) + ' unidades sin pedido asociado' : '', cls: 'neu' });
        $('#kv-fr-remitos').text(fmt.num(k.REMITOS));
        setVar('#kvar-fr-remitos', { text: parseInt(k.REMITOS_SIN_PEDIDO, 10) > 0 ? fmt.num(k.REMITOS_SIN_PEDIDO) + ' remitos sin pedido asociado' : '', cls: 'neu' });
        $('#kv-fr-pedidos').text(fmt.num(ped));
        $('#kv-fr-rate').text(k.FILL_RATE == null ? '—' : fmt.pct(k.FILL_RATE));
        setVar('#kvar-fr-rate', k.FILL_RATE == null ? { text: '', cls: 'neu' } : fmt.varLabel(k.FILL_RATE, k.META));
        $('#kv-fr-completos').text(fmt.num(k.PED_COMPLETOS));
        setVar('#kvar-fr-completos', { text: ped > 0 ? fmt.pct(k.PED_COMPLETOS / ped) + ' de los pedidos con remito' : '', cls: 'neu' });

        renderTablaSimple('#tbody-fr-apertura', data.apertura || [],
            r => `<td>${escapeHtml(r.CANAL || '—')}</td><td>${escapeHtml(r.TIPO_FACTURACION || '—')}</td>` +
                 `<td class="col-num">${fmt.num(r.UNID_REMITIDAS)}</td><td class="col-num">${fmt.num(r.REMITOS)}</td>` +
                 `<td class="col-num">${fmt.num(r.PEDIDOS)}</td><td class="col-num">${fmt.num(r.PED_COMPLETOS)}</td>` +
                 `<td class="col-num" style="color:${efiColor(r.FILL_RATE == null ? null : parseFloat(r.FILL_RATE))};font-weight:600">` +
                 `${r.FILL_RATE == null ? '—' : fmt.pct(r.FILL_RATE)}</td>`
        );

        renderIngreso(data);

        State.frDetalle = data.detalle || [];
        frAbiertos.clear();
        renderFillRate();

        if (!frExportInit) {
            ExcelExporter.addExportButton(document.getElementById('hdr-fr-detalle'), exportFillRate);
            frExportInit = true;
        }
    }

    // Pedidos cargados el día anterior (D−1): cuánto entró vs lo normal, en plazo,
    // cuándo hay que entregarlo y qué atender primero.
    function renderIngreso(data) {
        const ing = data.ingreso || {};
        const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        const dia  = ing.ING_FECHA ? DIAS[new Date(ing.ING_FECHA + 'T00:00:00Z').getUTCDay()] : '';
        const sem  = parseInt(ing.REF_SEMANAS, 10) || 0;
        const money = v => '$ ' + fmt.num(v, 0);

        $('#fr-ing-fecha').text(ing.ING_FECHA
            ? `Pedidos que entraron el ${fmt.date(ing.ING_FECHA)} · situación a hoy (${fmt.date(ing.HOY)})`
            : 'Qué entró, cuándo hay que entregarlo y qué está en riesgo');

        // 1. Volumen vs lo normal (promedio del mismo día de la semana, 4 semanas previas)
        const vsNormal = (sel, actual, ref) => {
            const v = sem > 0 ? fmt.varPct(actual, ref) : { text: '—', cls: 'neu' };
            $(sel).attr('class', v.cls === 'pos' ? 'pos' : v.cls === 'neg' ? 'neg' : '')
                  .text(sem > 0 ? `${v.text} vs un ${dia} normal` : 'sin referencia');
        };
        $('#kv-fr-ing-ped').text(fmt.num(ing.ING_PEDIDOS));
        vsNormal('#kvar-fr-ing-ped', ing.ING_PEDIDOS, ing.REF_PEDIDOS);
        $('#kv-fr-ing-unid').text(fmt.num(ing.ING_UNIDADES));
        vsNormal('#kvar-fr-ing-unid', ing.ING_UNIDADES, ing.REF_UNIDADES);
        $('#kv-fr-ing-imp').text(money(ing.ING_IMPORTE));
        vsNormal('#kvar-fr-ing-imp', ing.ING_IMPORTE, ing.REF_IMPORTE);

        // 2. Cumplimiento en plazo (pedidos)
        const pct = ing.PCT_A_TIEMPO == null ? null : parseFloat(ing.PCT_A_TIEMPO);
        const medibles = (parseInt(ing.PED_A_TIEMPO, 10) || 0) + (parseInt(ing.PED_TARDE, 10) || 0) + (parseInt(ing.PED_VENCIDOS, 10) || 0);
        $('#kv-fr-ing-pct').text(pct == null ? '—' : fmt.pct(pct)).css('color', efiColor(pct));
        $('#kvar-fr-ing-pct').text(pct == null ? 'todavía no venció ningún pedido' : `sobre ${fmt.num(medibles)} pedidos medibles · meta 95%`);
        $('#kv-fr-ing-atiempo').text(fmt.num(ing.PED_A_TIEMPO));
        $('#kv-fr-ing-tarde').text(fmt.num(ing.PED_TARDE));
        $('#kv-fr-ing-venc').text(fmt.num(ing.PED_VENCIDOS));
        $('#kvar-fr-ing-venc').text(`pedidos · ${fmt.num(ing.UNID_VENCIDAS)} unidades pendientes`);
        $('#kv-fr-ing-riesgo').text(fmt.num(ing.PED_EN_RIESGO));
        $('#kvar-fr-ing-riesgo').text(`pedidos · ${fmt.num(ing.UNID_EN_RIESGO)} unidades pendientes`);
        $('#kv-fr-ing-plazo').text(fmt.num(ing.PED_EN_PLAZO));
        $('#kvar-fr-ing-plazo').text(`pedidos · ${fmt.num(ing.UNID_EN_PLAZO)} unidades pendientes`);

        const notas = [];
        if (parseInt(ing.PED_COMPLETO_SIN_DATO, 10) > 0)
            notas.push(`${fmt.num(ing.PED_COMPLETO_SIN_DATO)} pedidos completos no se pueden medir (su remito no está vinculado al pedido, p. ej. Ecommerce o Dist. Inicial)`);
        if (parseInt(ing.PED_SIN_FECHA, 10) > 0)
            notas.push(`${fmt.num(ing.PED_SIN_FECHA)} pedidos sin fecha de entrega`);
        if (parseInt(ing.PED_FECHA_ANTERIOR, 10) > 0)
            notas.push(`${fmt.num(ing.PED_FECHA_ANTERIOR)} con fecha de entrega anterior a la carga`);
        $('#fr-ing-nota').html(notas.length ? '<i class="bi bi-info-circle"></i> ' + notas.map(escapeHtml).join(' · ') : '');

        // 3. ¿Cuándo hay que entregarlo? (plazo desde la carga)
        const plazos = data.ingreso_plazos || [];
        const maxU = Math.max(1, ...plazos.map(r => parseFloat(r.UNID_PEDIDAS) || 0));
        renderTablaSimple('#tbody-fr-plazos', plazos,
            r => {
                const u = parseFloat(r.UNID_PEDIDAS) || 0;
                const gris = parseInt(r.PLAZO_ORD, 10) >= 5 ? ' gris' : '';
                return `<td>${escapeHtml(r.PLAZO)}</td><td class="col-num">${fmt.num(r.PEDIDOS)}</td>` +
                    `<td class="col-num">${fmt.num(u)}</td><td class="col-num">${fmt.num(r.UNID_PENDIENTES)}</td>` +
                    `<td><div class="fr-bar${gris}" style="width:${(u / maxU * 100).toFixed(1)}%"></div></td>`;
            }
        );

        // Por canal
        renderTablaSimple('#tbody-fr-ingreso', data.ingreso_canal || [],
            r => {
                const c = r.PCT_A_TIEMPO == null ? null : parseFloat(r.PCT_A_TIEMPO);
                const aten = parseInt(r.PED_A_ATENDER, 10) || 0;
                return `<td>${escapeHtml(r.CANAL || '—')}</td><td class="col-num">${fmt.num(r.PEDIDOS)}</td>` +
                    `<td class="col-num">${fmt.num(r.UNID_PEDIDAS)}</td><td class="col-num">${fmt.num(r.UNID_PENDIENTES)}</td>` +
                    `<td class="col-num" style="color:${efiColor(c)};font-weight:600" title="${fmt.num(r.PED_A_TIEMPO)} de ${fmt.num(r.PED_MEDIBLES)} pedidos medibles">${c == null ? '—' : fmt.pct(c)}</td>` +
                    `<td class="col-num"${aten > 0 ? ' style="color:var(--neg);font-weight:700"' : ''}>${fmt.num(aten)}</td>`;
            }
        );

        // 4. Pedidos a atender primero
        const $r = $('#tbody-fr-riesgo');
        const riesgo = data.ingreso_riesgo || [];
        if (!riesgo.length) {
            $r.html(`<tr><td colspan="7"><div class="empty-state"><i class="bi bi-check2-circle"></i>No hay pedidos vencidos ni en riesgo</div></td></tr>`);
        } else {
            renderTablaSimple('#tbody-fr-riesgo', riesgo,
                r => {
                    const venc = r.SITUACION === 'VENCIDO';
                    return `<td>${escapeHtml(r.NRO_PEDIDO)}</td>` +
                        `<td title="${escapeHtml(r.CLIENTE || '')}">${escapeHtml(r.CLIENTE || '—')}</td>` +
                        `<td>${escapeHtml(r.CANAL || '—')}</td><td>${fmt.date(r.FECHA_ENTREGA)}</td>` +
                        `<td class="col-num">${fmt.num(r.UNID_PEDIDAS)}</td><td class="col-num">${fmt.num(r.UNID_PENDIENTES)}</td>` +
                        `<td><span class="badge-estado ${venc ? 'badge-cancelado' : 'badge-parcial'}">${venc ? 'Vencido' : 'Vence pronto'}</span></td>`;
                },
                r => r.NRO_PEDIDO
            );
        }
    }

    function filtrarFillRate() {
        const q  = State.frBusca.trim().toUpperCase();
        const q0 = q.replace(/^0+/, '');
        return State.frDetalle.filter(r => {
            if (State.frFiltro !== 'ALL' && r.ESTADO !== State.frFiltro) return false;
            if (!q) return true;
            const ped = String(r.NRO_PEDIDO || '');
            return String(r.N_COMP || '').toUpperCase().includes(q) ||
                   String(r.CLIENTE || '').toUpperCase().includes(q) ||
                   ped.includes(q) || (q0 !== '' && ped.replace(/^0+/, '').includes(q0));
        });
    }

    // Agrupa las filas remito × pedido por pedido (talón + número); los remitos
    // sin pedido quedan como un grupo propio cada uno, sin desplegable.
    function agruparFillRate(rows) {
        const map = new Map();
        rows.forEach(r => {
            const key = r.NRO_PEDIDO ? `P|${r.TALON_PED ?? ''}|${r.NRO_PEDIDO}` : `R|${r.N_COMP}`;
            let g = map.get(key);
            if (!g) { g = { key, head: r, unid: 0, remitos: [] }; map.set(key, g); }
            g.unid += parseFloat(r.UNID_REMITO) || 0;
            g.remitos.push(r);
        });
        return [...map.values()];
    }

    function renderFillRate() {
        const rows   = filtrarFillRate();
        const grupos = agruparFillRate(rows);
        const nPed   = grupos.filter(g => g.head.NRO_PEDIDO).length;
        $('#fr-count').text(`${fmt.num(nPed)} pedidos · ${fmt.num(new Set(rows.map(r => r.N_COMP)).size)} remitos · clic en un pedido para ver sus remitos`);

        const $tb = $('#tbody-fr-detalle').empty();
        if (!grupos.length) {
            $tb.html('<tr><td colspan="12"><div class="empty-state"><i class="bi bi-inbox"></i>Sin datos</div></td></tr>');
            return;
        }
        const BADGE = {
            'COMPLETO'  : ['badge-facturado',  'Completo'],
            'PARCIAL'   : ['badge-parcial',    'Parcial'],
            'SIN PEDIDO': ['badge-sin-pedido', 'Remito sin pedido'],
            'SIN DATOS' : ['badge-sin-pedido', 'Sin datos'],
        };
        const abrirTodo = State.frBusca.trim() !== '';   // con búsqueda, mostrar el remito que coincide

        $tb.html(grupos.map(g => {
            const r = g.head;
            const [bCls, bTxt] = BADGE[r.ESTADO] || ['badge-sin-pedido', r.ESTADO || '—'];
            const cumpl  = r.CUMPL == null ? null : parseFloat(r.CUMPL);
            const expand = !!r.NRO_PEDIDO;
            const open   = expand && (abrirTodo || frAbiertos.has(g.key));
            const nRem   = g.remitos.length;
            const pedTd  = expand
                ? `<td><i class="bi bi-chevron-right caret"></i> ${escapeHtml(r.NRO_PEDIDO)}` +
                  ` <button type="button" class="fr-ver-ped" data-pedido="${escapeHtml(String(r.NRO_PEDIDO).trim())}" title="Ver detalle del pedido"><i class="bi bi-box-arrow-up-right"></i></button></td>`
                : '<td>—</td>';
            const remTd  = nRem === 1 ? escapeHtml(r.N_COMP) : `<span class="badge-arts">${nRem}</span> remitos`;
            const cls    = expand ? ` class="rubro-row expandible fr-ped-row${open ? ' abierto' : ''}" data-key="${escapeHtml(g.key)}"` : '';

            let html = `<tr${cls}>${pedTd}<td>${remTd}</td>` +
                `<td>${fmt.date(r.FECHA_PEDI)}</td>` +
                `<td title="${escapeHtml(r.CLIENTE || '')}">${escapeHtml(r.CLIENTE || '—')}</td>` +
                `<td>${escapeHtml(r.CANAL || '—')}</td><td>${escapeHtml(r.TIPO_FACTURACION || '—')}</td>` +
                `<td class="col-num">${fmt.num(g.unid)}</td><td class="col-num">${fmt.num(r.UNID_PEDIDAS)}</td>` +
                `<td class="col-num">${fmt.num(r.UNID_REMITIDAS_ACUM)}</td><td class="col-num">${fmt.num(r.UNID_PENDIENTES)}</td>` +
                `<td class="col-num" style="color:${efiColor(cumpl)};font-weight:600">${cumpl == null ? '—' : fmt.pct(cumpl)}</td>` +
                `<td><span class="badge-estado ${bCls}">${bTxt}</span></td></tr>`;
            if (expand) html += frRemitoRows(g, open);
            return html;
        }).join(''));
    }

    // Filas hijas: un remito por fila (clic → modal con el detalle del pedido)
    function frRemitoRows(g, open) {
        return g.remitos.map(r =>
            `<tr class="pedido-row fr-remito-row" data-grupo="${escapeHtml(g.key)}" data-pedido="${escapeHtml(String(r.NRO_PEDIDO).trim())}"${open ? '' : ' hidden'}>` +
            `<td></td><td><i class="bi bi-arrow-return-right"></i> ${escapeHtml(r.N_COMP)}</td>` +
            `<td></td><td></td>` +
            `<td>${escapeHtml(r.CANAL || '—')}</td><td>${escapeHtml(r.TIPO_FACTURACION || '—')}</td>` +
            `<td class="col-num">${fmt.num(r.UNID_REMITO)}</td>` +
            `<td colspan="5"></td></tr>`
        ).join('');
    }

    function toggleFrPedido($row) {
        const key  = String($row.attr('data-key'));
        const open = !$row.hasClass('abierto');
        $row.toggleClass('abierto', open);
        $row.nextAll('tr.fr-remito-row').filter((_, tr) => tr.getAttribute('data-grupo') === key).prop('hidden', !open);
        if (open) frAbiertos.add(key); else frAbiertos.delete(key);
    }

    function exportFillRate() {
        const rows = filtrarFillRate();
        if (!rows.length) {
            alert('No hay filas para exportar con los filtros actuales.');
            return;
        }
        const filtros = [
            'Remitos del ' + fmt.date(State.frFecha),
            State.canal  ? 'Canal: ' + State.canal : '',
            State.frTipo ? 'Tipo: ' + State.frTipo : '',
            State.frFiltro !== 'ALL' ? 'Estado: ' + State.frFiltro : '',
            State.frBusca.trim() ? 'Búsqueda: ' + State.frBusca.trim() : '',
        ].filter(Boolean).join(' · ');
        const num = v => (v == null || v === '') ? '' : parseFloat(v);
        ExcelExporter.export({
            title     : `Fill Rate — ${filtros}`,
            headers   : ['Remito', 'Pedido', 'Talón', 'Fecha pedido', 'Cliente', 'Canal', 'Tipo', 'Estado Tango',
                         'Unidades en este remito', 'Unidades pedidas', 'Unidades remitidas (a hoy)', 'Unidades pendientes', '% Unidades cumplidas', 'Estado del pedido'],
            rows      : rows.map(r => [
                r.N_COMP || '', r.NRO_PEDIDO || '', r.TALON_PED ?? '', r.FECHA_PEDI ? fmt.date(r.FECHA_PEDI) : '',
                r.CLIENTE || '', r.CANAL || '', r.TIPO_FACTURACION || '', r.ESTADO_TANGO || '',
                num(r.UNID_REMITO), num(r.UNID_PEDIDAS), num(r.UNID_REMITIDAS_ACUM), num(r.UNID_PENDIENTES),
                num(r.CUMPL), r.ESTADO || '',
            ]),
            colFormats: ['text', 'text', 'num', 'text', 'text', 'text', 'text', 'text', 'num1', 'num', 'num', 'num', 'pct', 'text'],
            filename  : 'fill_rate_' + State.frFecha,
        });
    }

    function frIrA(fecha) {
        if (!fecha || fecha === State.frFecha) return;
        if (State.frFechaMax && fecha > State.frFechaMax) fecha = State.frFechaMax;
        State.frFecha = fecha;
        delete Cache['fill-rate'];
        loadTab('fill-rate');
    }

    function efiColor(pct) {
        if (pct == null) return 'var(--text-1)';
        return pct >= 0.95 ? 'var(--pos)' : pct >= 0.85 ? 'var(--accent3)' : 'var(--neg)';
    }

    // ── Label período en topbar ───────────────────────────────────────────
    function updatePeriodLabel() {
        const d = State.desde, h = State.hasta;
        if (d && h) $('#periodo-label').text(fmt.date(d) + ' — ' + fmt.date(h));
    }

    // ── Tooltips estáticos ────────────────────────────────────────────────
    function initStaticTooltips() {
        initInfoPopover();

        $('#inp-desde').attr('title', 'Fecha inicial del periodo de analisis.');
        $('#inp-hasta').attr('title', 'Fecha final del periodo de analisis.');
        $('#sel-canal').attr('title', 'Filtra los datos por canal.');
        $('#sel-rubro').attr('title', 'Filtra los datos por rubro.');
        $('#sel-deposito').attr('title', 'Filtra el inventario por depósito.');
        $('#sel-usuario').attr('title', 'Filtra los datos por usuario.');
        $('#sel-cliente').attr('title', 'Filtra los datos por cliente.');
        $('#sel-tipo').attr('title', 'Filtra por tipo de remisión.');
        $('#btn-aplicar').attr({ title: 'Aplicar fechas y filtros seleccionados.', 'aria-label': 'Aplicar filtros' });
        $('#btn-reload').attr({ title: 'Recargar la pestana activa con los filtros actuales.', 'aria-label': 'Recargar pestana activa' });

        $('.tab-btn').each(function () {
            const label = $(this).text().trim().replace(/\s+/g, ' ');
            $(this).attr({ title: 'Ver ' + label, 'aria-label': 'Ver ' + label });
        });

        Object.entries(HELP.kpis).forEach(([id, cfg]) => {
            const $label = $('#' + id).closest('.kpi-body').find('.kpi-label').first();
            $label.contents().filter(function () { return this.nodeType === 3; })
                  .wrap('<span class="kpi-label-txt"></span>');
            $label.attr('title', cfg[1][0]);
            addInfoButton($label, cfg[0], cfg[1]);
        });

        Object.entries(HELP.sections).forEach(([id, cfg]) => {
            const $section = $('#' + id).closest('.analisis-card').find('.analisis-section-header').first();
            addInfoButton($section, cfg[0], cfg[1]);
        });

        Object.entries(HELP.tableHeaders).forEach(([tableId, tips]) => {
            $('#' + tableId + ' thead th').each(function (i) {
                if (tips[i]) $(this).attr('title', tips[i]);
            });
        });
    }

    // ── Bootstrap ─────────────────────────────────────────────────────────
    function init() {
        State.desde = $('#inp-desde').val() || new Date().toISOString().substring(0,7) + '-01';
        State.hasta = $('#inp-hasta').val() || new Date().toISOString().substring(0,10);
        updatePeriodLabel();
        initStaticTooltips();
        initFiltros();
        updateSlicers('eficiencia');
        loadTab('eficiencia');

        $('.tab-btn').on('click', function () {
            const tab = $(this).data('tab');
            if (tab) switchTab(tab);
        });

        $('#btn-aplicar').on('click', function () {
            const d = $('#inp-desde').val();
            const h = $('#inp-hasta').val();
            if (!d || !h) return;
            if (d > h) { alert('La fecha Desde no puede ser mayor que Hasta.'); return; }
            State.desde   = d;
            State.hasta   = h;
            State.canal   = $('#sel-canal').val()   || '';
            State.usuario = $('#sel-usuario').val() || '';
            State.cliente = $('#sel-cliente').val() || '';
            State.tipo    = $('#sel-tipo').val()    || '';
            // El slicer Rubro es compartido: Facturación y Stock usan listas distintas.
            if (State.activeTab === 'prod-fact') State.rubroFact = $('#sel-rubro').val() || '';
            else                                 State.rubro     = $('#sel-rubro').val() || '';
            if (State.activeTab === 'stock')     State.deposito  = $('#sel-deposito').val() || '';
            invalidarCache();
            updatePeriodLabel();
            loadTab(State.activeTab);
        });

        $('#btn-reload').on('click', function () {
            delete Cache[State.activeTab];
            State.forceRefresh = true;
            loadTab(State.activeTab).finally(() => { State.forceRefresh = false; });
        });

        $('#sel-canal, #sel-rubro, #sel-deposito, #sel-usuario, #sel-cliente, #sel-tipo').on('change', function () {
            State.canal   = $('#sel-canal').val()   || '';
            State.usuario = $('#sel-usuario').val() || '';
            State.cliente = $('#sel-cliente').val() || '';
            State.tipo    = $('#sel-tipo').val()    || '';
            if (State.activeTab === 'prod-fact') State.rubroFact = $('#sel-rubro').val() || '';
            else                                 State.rubro     = $('#sel-rubro').val() || '';
            if (State.activeTab === 'stock')     State.deposito  = $('#sel-deposito').val() || '';
            delete Cache[State.activeTab];
            loadTab(State.activeTab);
        });

        // Filtro de ventana para "Pedidos pendientes" (Planificación)
        $('#pend-filtros').on('click', '.pill', function () {
            $('#pend-filtros .pill').removeClass('active');
            $(this).addClass('active');
            State.pendFiltro = $(this).data('f');
            renderPendientes();
        });

        // Estancados: el umbral recalcula en el servidor; el resto filtra en el cliente
        $('#est-umbral').on('click', '.pill', function () {
            const d = parseInt($(this).data('d'), 10);
            if (d === State.estDias) return;
            $('#est-umbral .pill').removeClass('active');
            $(this).addClass('active');
            State.estDias    = d;
            State.estCliente = '';
            delete Cache['estancados'];
            loadTab('estancados');
        });
        // Filtro por cobertura de stock: pills del listado o tarjetas (clic de nuevo = quitar)
        $('#est-cob-filtro').on('click', '.pill', function () {
            State.estCobertura = String($(this).data('a'));
            renderEstancados();
        });
        $('#est-cobertura').on('click', '.est-accion', function () {
            const a = String($(this).data('a'));
            State.estCobertura = (State.estCobertura === a) ? 'ALL' : a;
            renderEstancados();
        });
        let estBuscaTimer = null;
        $('#est-busca').on('input', function () {
            clearTimeout(estBuscaTimer);
            const v = this.value;
            estBuscaTimer = setTimeout(() => { State.estBusca = v; renderEstancados(); }, 200);
        });
        $('#tbody-est-clientes').on('click', 'tr.est-cli-row', function () {
            const c = $(this).attr('data-cliente');
            State.estCliente = (State.estCliente === c) ? '' : c;
            $('#tbody-est-clientes tr.est-cli-row').removeClass('activo');
            if (State.estCliente) $(this).addClass('activo');
            renderEstancados();
        });
        // WIP de picking: refresco manual sin recargar toda la pestaña
        $('#wip-actualizar').on('click', function () { loadWipPicking(); });

        // Fill Rate: día y tipo recalculan en el servidor; estado y búsqueda filtran en el cliente
        $('#fr-fecha').on('change', function () { frIrA(this.value); });
        $('#fr-prev').on('click', function () { if (State.frFecha) frIrA(frAddDays(State.frFecha, -1)); });
        $('#fr-next').on('click', function () { if (State.frFecha) frIrA(frAddDays(State.frFecha, 1)); });
        $('#fr-ultimo').on('click', function () { frIrA(State.frFechaMax); });
        $('#fr-tipo').on('click', '.pill', function () {
            const t = String($(this).data('t') || '');
            if (t === State.frTipo) return;
            $('#fr-tipo .pill').removeClass('active');
            $(this).addClass('active');
            State.frTipo = t;
            delete Cache['fill-rate'];
            loadTab('fill-rate');
        });
        $('#fr-filtro').on('click', '.pill', function () {
            $('#fr-filtro .pill').removeClass('active');
            $(this).addClass('active');
            State.frFiltro = $(this).data('f');
            renderFillRate();
        });
        let frBuscaTimer = null;
        $('#fr-busca').on('input', function () {
            clearTimeout(frBuscaTimer);
            const v = this.value;
            frBuscaTimer = setTimeout(() => { State.frBusca = v; renderFillRate(); }, 200);
        });

        $('#est-cliente-activo').on('click', '#est-cliente-quitar', function () {
            State.estCliente = '';
            $('#tbody-est-clientes tr.est-cli-row').removeClass('activo');
            renderEstancados();
        });

        // Drill-down del detalle por rubro (delegado: el tbody se re-renderiza)
        $('#tbody-stock').on('click', 'tr.rubro-row.expandible', function () {
            toggleDrillRubro($(this));
        });

        // Modal de detalle de pedido (delegado: cualquier fila con data-pedido)
        $(document).on('click', 'tr.pedido-row', function () {
            openPedidoDetalle($(this).attr('data-pedido'));
        });
        $('#pedido-modal').on('click', '[data-close]', closeModal);

        // Mini-gráfico por día (clic en columna de fecha en tablas Últ. 7 días)
        $(document).on('click', 'th.pick-day-th', function () {
            openDiaChart($(this).attr('data-base'), $(this).attr('data-fecha'));
        });
        $('#picking-dia-modal').on('click', '[data-close]', closePickingDia);

        // Drill de % Eficiencia por cliente (delegado: el tbody se re-renderiza)
        $('#tbody-efi-pedidos').on('click', 'tr.efi-cli-row.expandible', function () {
            toggleEfiPedidos($(this));
        });

        // Fill rate: pedido → remitos (el botón abre el detalle del pedido)
        $('#tbody-fr-detalle').on('click', 'tr.fr-ped-row', function (e) {
            const $btn = $(e.target).closest('.fr-ver-ped');
            if ($btn.length) { openPedidoDetalle($btn.attr('data-pedido')); return; }
            toggleFrPedido($(this));
        });

        // Card de pérdida: flip (frente KPI ⇄ dorso mini-gráfico) + ampliar.
        // Toda la cara delantera da vuelta la tarjeta (salvo el botón de ayuda).
        $('#card-perdida').on('click', '.kpi-flip-front', function (e) {
            if ($(e.target).closest('.info-btn').length) return;
            $('#card-perdida').addClass('flipped');
        });
        $('#card-perdida').on('click', '[data-flip-back]', function (e) {
            e.stopPropagation();
            $('#card-perdida').removeClass('flipped');
        });
        $('#btn-perdida-expand').on('click', function (e) { e.stopPropagation(); openPerdidaModal(); });
        $('#perdida-12m-modal').on('click', '[data-close]', closePerdidaModal);

        // Selector de métrica en Evolución Remisión
        $('#tab-evolucion-remision').on('click', '.rem-toggle-btn', function () {
            const metric = $(this).data('metric');
            if (metric === remisionMetric) return;
            $('#tab-evolucion-remision .rem-toggle-btn').removeClass('active');
            $(this).addClass('active');
            remisionMetric = metric;
            renderEvolucionRemision();
        });

        // Botón limpiar filtro de mes en Evolución Remisión
        $('#btn-clear-rem-filter').on('click', function () {
            remisionFilterMes = null;
            updateRemisionFilterUI();
            renderRemisionTabla();
        });

        // Botón exportar Excel en Evolución Remisión
        $('#btn-export-remision-excel').on('click', function () {
            if (!remisionRawData) return;
            if (typeof XLSX !== 'undefined') {
                const table = document.getElementById('tabla-remision-detalle');
                const wb = XLSX.utils.table_to_book(table, { sheet: 'Evolucion_Remision' });
                XLSX.writeFile(wb, `Evolucion_Tipo_Remision_${State.desde}_${State.hasta}.xlsx`);
            } else if (typeof ExcelExporter !== 'undefined') {
                ExcelExporter.exportTable('#tabla-remision-detalle', 'Evolucion_Tipo_Remision');
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (!$('#pedido-modal').attr('hidden'))      closeModal();
            if (!$('#picking-dia-modal').attr('hidden')) closePickingDia();
            if (!$('#perdida-12m-modal').attr('hidden')) closePerdidaModal();
        });
    }

    $(document).ready(init);

})(jQuery);
