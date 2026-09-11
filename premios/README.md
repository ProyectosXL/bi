# Dashboard Premios Comercial

Migración del tablero de Power BI "Premios Comercial" a web.
URL de acceso: `/bi/premios/`

---

## Estructura de archivos

```
/bi/premios/
├── index.php                        Página principal (auth + HTML + orquestador)
├── class/
│   ├── PremiosDB.php                 Datos reales (sqlsrv) + reglas de premios
│   └── PremiosEcommerceDB.php        Vista 4: premios del área Ecommerce (motor de escalón fijo)
├── api/
│   ├── filtros.php                   Lista de supervisoras para el selector
│   ├── resumen.php                   Vista 1: premios por supervisora (propios + franquicias)
│   ├── propios.php                   Vista 2: facturación vs objetivos, locales propios
│   ├── franquicias.php               Vista 3: facturación vs objetivos, franquicias
│   ├── ecommerce.php                 Vista 4: premios por persona y concepto
│   ├── ecommerce_kpis.php            Vista 4: carga manual de órdenes / sesiones / conversión
│   └── ecommerce_escalas.php         Vista 4: escalas de premio (las "PAUTAS")
├── components/
│   └── ExcelExporter.js              Exportación a Excel (copia de global/components/)
├── css/
│   └── premios.css                   Estilos específicos del dashboard
├── sql/
│   └── setup_premios_ecommerce.sql   DDL + seed de las 4 tablas de la Vista 4
└── js/
    ├── premios.js                    Helpers compartidos: filtros del toolbar, período, semáforo
    ├── supervisoras.js                Vista 1: cards de premio + 2 tablas de detalle
    ├── propios.js                     Vista 2: KPIs + tabla agrupada por supervisora
    ├── franquicias.js                 Vista 3: KPIs + tabla ordenada alfabéticamente
    └── ecommerce.js                   Vista 4: cards + tabla + los 2 modales de gestión
```

---

## Acceso

Solo requiere sesión activa (`$_SESSION['username']`), sin restricción por `tipo` —
el control de quién ve el link vive en el portal donde está indexado (`indicadores.php`),
no en este módulo. Cualquier usuario logueado que llegue a `/bi/premios/` puede verlo.

---

## Endpoints API

Todos requieren sesión activa (`$_SESSION['username']`). Los de lectura no tienen
restricción por `tipo`; los de gestión (marcados abajo, más `marcar_controlado.php`,
`supervisoras_orden.php` y los de envío de mail) exigen además `isGlobalMode()`
(GERENCIA/SUPERVISION) y responden 403 si no.

| Archivo            | Parámetros principales                                              | Respuesta                                  |
|---------------------|-----------------------------------------------------------------------|---------------------------------------------|
| `filtros.php`       | —                                                                      | supervisoras[]                              |
| `resumen.php`       | periodo, desde/hasta/comp_mode (si custom), supervisora                | periodo, supervisoras[] (premios propios+franquicias, total) |
| `propios.php`       | periodo, ..., supervisora                                              | periodo, kpis, grupos[] (por supervisora, con subtotal), total |
| `franquicias.php`   | periodo, ..., supervisora                                              | periodo, kpis, sucursales[] (orden alfabético), total |
| `ecommerce.php`     | periodo (ignora supervisora)                                           | periodo, periodo_parcial, personas[] (conceptos con premio), total_general, kpis_faltantes[], puede_gestionar |
| `ecommerce_kpis.php` | GET `mes`; POST `{mes, canales[]}`                                    | GET: mes, meses_disponibles[], canales[]. POST: ok. **403 si no `isGlobalMode()`** |
| `ecommerce_escalas.php` | GET —; POST `{conceptos:[{id, escalas[]}]}`                        | GET: personas[] con conceptos y tramos. POST: ok. **403 si no `isGlobalMode()`** |

El período usa `class/PeriodHelper.php::fromRequest($_GET)` — mismo contrato que el resto
de los tableros (`mes_actual|mes_pasado|año_actual|año_pasado|custom`, con `comp_mode=year_ago|custom`).

---

## Origen de datos

`PremiosDB` consulta datos reales vía `sqlsrv` (sin mock):

```sql
-- POWER_BI_CONTROL (conexión 'power')
BI_T_ESTADISTICAS_VENTAS_PROPIOS (
    FECHA, SUPERVISORA, SUCURSAL, NRO_SUCURS,
    IMP_FACT, IMP_FACT_ANT, IMP_FACT_S_IVA, IMP_OBJ,
    TICKETS, TICKETS_2DO_PROD, TICKETS_3ER_PROD,
    P_OBJ_VENTA, P_OBJ_CREC_VTA, P_TICKET_PROM, P_TICKET_2PROD, P_TICKET_3PROD
)

-- POWER_BI_CONTROL_FRANQUICIAS (conexión 'power_franquicias')
BI_T_ESTADISTICAS_VENTAS_FRANQUICIAS (FECHA, SUCURSAL, NRO_SUCURS, IMP_FACT, IMP_FACT_ANT, IMP_OBJ)
BI_T_PREMIOS_SUPERVISION_FRANQUICIAS (FECHA, SUPERVISORA, P_OBJ_VENTA_F, P_OBJ_CREC_VTA_F)

-- LAKERBIS (locales_lakers), vía linked server [XL-LAKERBIS] desde la conexión 'power'
[XL-LAKERBIS].locales_lakers.dbo.RO_T_SUPERVISORAS_COMERCIAL (ID, NOMBRE, ACTIVA)
```

Granularidad **mensual** (una fila por sucursal por mes, `FECHA` = último día del mes).
`PremiosDB::finesDeMes()` traduce el rango de fechas del selector de período a la lista
de fines de mes a incluir en el `WHERE FECHA IN (...)`.

**Deduplicación**: la tabla origen tiene filas repetidas para la misma sucursal+mes, y no
siempre son idénticas en todas las columnas (se vio un caso real donde `IMP_FACT_S_IVA`
difería entre filas "duplicadas" del mismo NRO_SUCURS+FECHA). Por eso no alcanza con
`SELECT DISTINCT` — se usa `ROW_NUMBER() OVER (PARTITION BY NRO_SUCURS, FECHA ...) = 1`
para quedarse con una sola fila por sucursal+mes antes de sumar. Es un parche defensivo
por una inconsistencia del origen, no una corrección del dato en sí.

**`NRO_SUCURS=1` ("CENTRAL") tiene una fila real POR CADA supervisora que la recibe**
(Elina Costamagna y Natalia Bontempo, según la vista `RO_V_PREMIOS_SUPERVISION`). Por eso
`datosPropios($supervisora)` **siempre** filtra por supervisora ANTES del `ROW_NUMBER()`
(no después) — si se piden todas las sucursales sin filtro y DESPUÉS se filtra en PHP por
nombre, la fila CENTRAL de una de esas dos supervisoras se pierde (el `GROUP BY NRO_SUCURS`
sin filtro previo las colapsa en una sola, con una sola etiqueta de `SUPERVISORA`). Los
endpoints (`resumen.php`, `propios.php`) piden las filas de cada supervisora con
`datosPropios($sup)` en vez de filtrar el array ya traído sin filtro — es más consultas
pero evita este problema.

**La fila sintética `SUPERVISORA='TODAS'` (NRO_SUCURS=9, "ECOMMERCE") SÍ se incluye en los
benchmarks de marca y en el conteo empresa-wide de Venta.** `datosPropios(null)` trae TODAS
las filas del período (incluida "TODAS"/ECOMMERCE) — se confirmó empíricamente que excluirla
rompe el benchmark `Ticket Promedio Marca` (daba $328.000 calculado vs $281.900 real; con
ECOMMERCE incluida en la suma, da $281.900 exacto). El conteo `Premio Obj. Venta Cant. Suc.`
del DAX real solo excluye `NRO_SUCURS=1` ("CENTRAL"), no el 9, así que ECOMMERCE debe contar
igual que cualquier sucursal para ese propósito.

**En la tabla de Locales Propios (`api/propios.php`), la fila "TODAS" se muestra suelta**,
sin agrupar bajo ninguna supervisora (justo antes de la fila "Total", igual que en el
tablero real) — no se pierde ni se oculta. Sí suma al total general de la tabla (el "Total"
real del tablero la incluye: $6.142.648.265 de facturación C/IVA, no $5.794.109.258 que daría
sin ella). Los subtotales por supervisora (`grupos[].subtotal`) siguen calculándose con
`datosPropios($sup)` sin incluirla, ya que no pertenece a ninguna.

**Comparación año anterior**: se usa directamente la columna `IMP_FACT_ANT` de cada fila
(ya viene calculada por el ETL como "mismo mes, año anterior"), en vez de consultar el
período previo por separado. Limitación conocida: si el usuario elige "Rango personalizado"
con un período de comparación que NO es el mismo rango un año atrás, esta columna no lo
va a reflejar correctamente — es una limitación del origen de datos, no de este código.

**Franquicias, sin mapeo sucursal→supervisora**: se confirmó contra el DAX real del .pbix
original que no existe (ni hace falta) una relación entre sucursal-franquicia y supervisora.
La cantidad de franquicias que cumplen objetivo/crecimiento es un número a nivel EMPRESA
(`PremiosDB::conteosFranquiciaEmpresa()`), igual para todas las supervisoras; solo el
importe del premio varía por supervisora (`PremiosDB::importesFranquiciaPorSupervisora()`,
con `MIN`/`MAX` para colapsar duplicados de la misma supervisora+mes — igual criterio que
las medidas DAX `Premio Obj. Venta Franq. (importe)` / `Premio Obj. Crecimiento Franq. (importe)`).

---

## Badge "Última actualización" / "DESACTUALIZADO"

`PremiosDB::getUltimaActualizacion()` consulta `sys.dm_db_index_usage_stats` para saber
cuándo se escribió por última vez cada tabla de origen (`BI_T_ESTADISTICAS_VENTAS_PROPIOS`
en `power`, `BI_T_ESTADISTICAS_VENTAS_FRANQUICIAS` en `power_franquicias`), con fallback a
`MAX(FECHA)` si no hay estadísticas de uso (ej. tras un reinicio del motor). Como hay DOS
orígenes, se devuelve la más antigua de las dos fechas — si cualquiera de las dos tablas está
desactualizada, el reporte completo lo está.

El dato es independiente del período elegido por el usuario en el toolbar (siempre refleja
cuándo se cargaron los datos por última vez, no el rango de fechas consultado).

A diferencia de `sales/` y `global/` (SPs que corren a diario, donde alcanza con comparar
contra "ayer"), el SP que carga estas tablas corre **una sola vez al mes** (día 1) y deja
cargado el mes recién cerrado. Como las tablas son mensuales, el valor más nuevo posible
SIEMPRE tiene fecha de fin del mes anterior (ej. el 17/07 los datos llegan hasta el 30/06),
nunca "ayer" — comparar contra "ayer" (o incluso contra el 1° del mes en curso) hace que el
badge quede en rojo el resto del mes aunque el dato esté perfectamente al día.

`PremiosDB::esDesactualizado()` encapsula el criterio correcto: el límite de comparación es
el inicio del **mes de datos esperado**, no el inicio del mes en curso. Pasado el día 5 del
mes en curso ya se espera que el SP haya corrido este mes, o sea que el dato debe llegar como
mínimo hasta el mes anterior (límite = 1° del mes anterior). Hasta el día 5 alcanza con que
el dato llegue hasta el mes ante-anterior (límite = 1° del mes ante-anterior), porque la
corrida de este mes puede no haber ocurrido todavía sin que sea un problema real. Se
considera "desactualizado" (`is_outdated=true`, badge rojo con animación de pulso) cuando la
fecha de última actualización es anterior a ese límite.

- `index.php` calcula el valor inicial en el primer render de la página (`$ultimaAct`,
  `$isOutdated`) para el badge en `.topbar-meta` (`#ultima-actualizacion`, `#badge-desactualizado`).
- Los 3 endpoints (`resumen.php`, `propios.php`, `franquicias.php`) devuelven también
  `ultima_actualizacion` (string `d/m/Y H:i:s`) e `is_outdated` (bool) en cada respuesta, y
  `js/premios.js::actualizarUltimaActualizacion(data)` actualiza el badge en cada AJAX
  (cambio de período/supervisora), sin necesidad de recargar la página.

---

## Reglas de negocio implementadas en `PremiosDB`

- **Facturación Var %** = `IMP_FACT / IMP_FACT_ANT - 1`.
- **Benchmark de marca — Locales Propios** (sin filtro de sucursal/supervisora) = variación
  de marca **+ 10 puntos ADITIVO** (`benchmarkVarMarca()`). Confirmado por DAX:
  `Facturación Var % All = CALCULATE([Facturación Var %], ALL(...)) + 0.1`.
- **Benchmark de marca — Franquicias** (`benchmarkVarMarcaFranquicias()`): a pedido del
  cliente (2026-09-02), ahora usa el MISMO criterio ADITIVO (+10pp) que Locales Propios —
  delega directo en `benchmarkVarMarca()`. Antes usaba un ajuste MULTIPLICATIVO
  (`(1 + var%) * 1.1 - 1`), confirmado contra el DAX real del `.pbix` original
  (`Facturación Var % All Franq. = CALCULATE([Facturación Var % Franq.], ALL(...)) * 1.1`,
  con `[Facturación Var % Franq.]` en formato ratio por un error de paréntesis en el `.pbix`
  original — `DIVIDE(SUM(IMP_FACT), SUM(IMP_FACT_ANT)-1)`, el "-1" quedó dentro del
  denominador sin efecto práctico). Esa fórmula multiplicativa daba un benchmark
  visiblemente más exigente con crecimientos altos (ej. +8,9% real → +19,8% con ×1.1, vs.
  +18,9% con +10pp), lo cual generaba confusión — divergencia intencional del reporte
  original. La discrepancia histórica "21 vs 20" en `Premio Obj. Crecimiento Cant. Franq.`
  (afectaba a ADROGUE) fue resuelta bajo la fórmula multiplicativa vieja — con el cambio a
  +10pp el conteo puede volver a diferir levemente y es esperado.
- **Premio Objetivo Venta / Crecimiento (Locales Propios)**: confirmado contra el DAX real.
  Para TODAS las supervisoras excepto Carolina Commendatore, la CANTIDAD de sucursales que
  cumplen es un número A NIVEL EMPRESA (mismas medidas `ALL(SUPERVISORA)` que en Franquicias),
  igual para todas — solo el importe (P_OBJ_VENTA/P_OBJ_CREC_VTA) es el propio de cada una.
  **Caso especial Carolina Commendatore** (medidas DAX `...Caro`): Venta compara la facturación
  total agrupada de sus sucursales + la fila sintética `SUPERVISORA='TODAS'` contra el objetivo
  total agrupado, sin tolerancia (umbral en 0); si la comparación agregada da positiva, cuentan
  TODAS sus sucursales, si no, ninguna. Crecimiento para Carolina SÍ se evalúa solo sobre sus
  propias sucursales (sin el atajo empresa-wide que reciben las demás). Ver
  `PremiosDB::premiosVentaCrecimiento()`.
- **Premio Ticket Promedio / 2do y 3er Producto**: sucursales que superan el benchmark de marca
  respectivo (`CEILING(FACT/TICKETS,100)` para ticket promedio; ratio simple para 2do/3er
  producto) — sin el atajo empresa-wide, se evalúa por supervisora para todas por igual
  (confirmado por DAX: estas medidas no usan `ALL(SUPERVISORA)`).
- **Franquicias**: Premio Venta = (cant. de franquicias con `FACT > OBJ`, a nivel empresa) ×
  (importe de esa supervisora, `MIN(P_OBJ_VENTA_F)`). Premio Crecimiento = misma idea con la
  condición dual de crecimiento × `MAX(P_OBJ_CREC_VTA_F)`. Confirmado contra el DAX real.
- **Semáforo**: verde (`text-green`) si el KPI supera su benchmark, rojo (`text-red`) si no,
  sin color si la sucursal no tiene datos en el período (`sin_datos`).

Ver comentarios en `class/PremiosDB.php` para el detalle método a método.

---

## Vista 4 — Premios Ecommerce (`PremiosEcommerceDB`)

Cuarta pestaña, agregada para reemplazar el Excel manual donde se liquidaban los premios
del área de Ecommerce. **Funciona con reglas propias**, distintas a las de las otras tres
vistas — no comparte nada con `PremiosDB` salvo el helper de calendario `finesDeMes()`.

### Quién cobra qué

Dos personas con esquemas fijos, en `BI_T_PREMIOS_ECOM_PERSONAS` / `..._CONCEPTOS`:

| Persona | Concepto | Canal | Tipo de umbral |
|---|---|---|---|
| Agustina | FACT E COMM | VTEX + ML (agregado) | % de cumplimiento |
| Agustina | T.CONV vtex | VTEX | valor absoluto |
| Vanesa Di Feo | FACTURACION VTEX | VTEX | % de cumplimiento |
| Vanesa Di Feo | ORDENES (OC) | VTEX | % de cumplimiento |
| Vanesa Di Feo | TASA CONVERSION | VTEX | valor absoluto |
| Vanesa Di Feo | FACTURACION ML | ML | % de cumplimiento |

Dar de alta a alguien es un `INSERT` en esas dos tablas (documentado en el `.sql`); no hay
CRUD de personas en el dashboard, a propósito — cambian muy de vez en cuando. Lo que **sí**
se edita desde el tablero son las escalas y los KPIs manuales.

### Regla de pago: ESCALÓN FIJO

`PremiosEcommerceDB::escalonFijo()` busca el tramo más alto alcanzado y paga **ese importe
completo, sin prorratear**. Si no llega al tramo más bajo, el premio es $0.

**No aplica la tolerancia de `-0,5 %` de `PremiosDB::TOLERANCIA_OBJ_VENTA`**: un 99,6 % de
cumplimiento NO paga el tramo de 100 %. La única tolerancia es `EPS = 1e-6`, y es solo para
ruido de punto flotante. Consecuencia práctica a tener presente: con métricas enteras el
umbral se evalúa exacto, así que 3.840 órdenes sobre un objetivo de 3.491 dan 109,997 % y
**no** alcanzan el tramo de 110 % (hacen falta 3.841).

Hay **dos tipos de umbral**, indicados en `CONCEPTOS.TIPO_UMBRAL`:
- `PCT_CUMPLIMIENTO` → se compara el ratio `real / objetivo` (`1,0000` = 100 %).
- `VALOR_ABSOLUTO` → se compara el valor real crudo, sin objetivo. Es el caso de la tasa de
  conversión, cuya "tabla" son valores absolutos de tasa (0,90 / 0,80 / 0,70…).

Ojo con el `% de cumplimiento`: acá es el RATIO `real / objetivo` (1,05 = 105 %), **no** el
`real / objetivo - 1` de `PremiosDB::cumplimientoObjVenta()`. Por eso esta vista usa
`Premios.badgeCellHTML(pct, 1, ...)` y no `cumplimientoCellHTML()`.

### Origen de cada dato

```sql
-- Objetivo de facturación — sistemas.dbo (cross-database desde la conexión 'power')
FP_ObjetivosFinales (idPOS, mes, anio, importeObjetivo, fecha_finalizacion)
  idPOS '553AEDC5-AB01-4034-BB8C-B9A78165309E' = 1 ECOMMERCE VTEX (numero 99, idTango 9)
  idPOS 'C457D171-5EA2-43ED-96FC-FF233C406214' = 1 ECOMMERCE ML   (numero 98, idTango 1)

-- Facturación real, TOTAL del canal — POWER_BI_CONTROL (conexión 'power')
BI_T_ESTADISTICAS_VENTAS_PROPIOS, NRO_SUCURS 9 ('ECOMMERCE', consolidado VTEX+ML), IMP_FACT

-- Facturación real, VTEX y ML por separado — POWER_BI_CONTROL (conexión 'power')
BI_SALES_SUCURSALES, NRO_SUCURS 9 (VTEX) / 1 (ML)

-- Órdenes reales — LAKER_SA (conexión 'central', OTRO servidor: XL-TANGO)
RO_T_ESTADO_PEDIDOS_ECOMMERCE, TALON_PED 99 (VTEX) / 98 (ML) / 80 (ICBC, no se mide)

-- Tasa de conversión y objetivo de órdenes — CARGA MANUAL
BI_T_PREMIOS_ECOM_KPIS (MES, CANAL, SESIONES, TASA_CONVERSION, OBJETIVO_ORDENES)
```

La clase abre **dos conexiones**: `power` para facturación, objetivos y configuración, y
`central` para las órdenes (es otro servidor, no alcanza un cross-database). La de `central`
se abre en forma perezosa, así los endpoints de configuración no la pagan al pedo.

### ⚠ `NRO_SUCURS` significa cosas distintas en las dos tablas

Es la confusión más fácil de cometer en esta vista. Verificado contra la base (2026-09-10/11):

| | `NRO_SUCURS = 9` | `NRO_SUCURS = 1` |
|---|---|---|
| `BI_SALES_SUCURSALES` (diaria) | `'ECOMMERCE VTEX'` | `'ECOMMERCE ML'` |
| `BI_T_ESTADISTICAS_VENTAS_PROPIOS` (mensual) | `'ECOMMERCE'`, **consolidado VTEX+ML** | `'CENTRAL'`, administrativa, todo en cero |

En la diaria el nombre viene en la propia columna `SUCURSAL`, y es la convención de
`class/Filters.php` y `global/class/CadenaDB.php` (`canal ECOMMERCE == NRO_SUCURS IN (1,9)`).
Que el 9 mensual sea el consolidado está probado por su `IMP_OBJ`, que coincide **al peso**
con la suma de los objetivos de los dos idPOS en `FP_ObjetivosFinales` — junio 2026
$330.998.960, julio $388.202.535, agosto $291.539.982, los tres exactos.

### Dos fuentes de facturación, ninguna estimada

Cada concepto se mide con la fuente que efectivamente mide lo que ese concepto premia.
**Ningún importe es derivado ni prorrateado**: de estos números depende lo que cobra una
persona, así que tienen que ser medidos.

| Concepto | Fuente | Por qué |
|---|---|---|
| FACT E COMM (Agustina) | `BI_T_ESTADISTICAS_VENTAS_PROPIOS`, fila ECOMMERCE | Es el total del canal, y es la fuente contra la que liquidan las otras tres pestañas. Su fila ECOMMERCE ya se muestra en "Locales Propios", así que el número coincide. |
| FACTURACION VTEX / ML (Vanesa) | `BI_SALES_SUCURSALES`, `NRO_SUCURS` 9 y 1 | Es la **única** tabla que separa los dos canales. La mensual los trae consolidados en una sola fila. |

**⚠ Consecuencia conocida y aceptada: las partes no suman el total.** Julio 2026 muestra
$389.311.751 en el concepto de Agustina y $224.083.924 + $125.015.550 = $349.099.474 en los
dos de Vanesa — un **11,5 %** de diferencia entre dos tablas del mismo BI para el mismo canal
y mes. No es deriva por notas de crédito: en agosto la diaria marcaba $361,7M, todavía 7,7 %
por debajo. La mensual incluye algo que la diaria no (¿ICBC? ¿envío? ¿otro conjunto de
rubros?), y **no está investigado**. Vale la pena: de esos $40M depende que el concepto de
Agustina cruce o no el 100 %, o sea $115.000 por mes.

Se prefiere mostrar esa inconsistencia antes que taparla. La alternativa que se evaluó y
**descartó** (2026-09-11) era prorratear el total mensual con la proporción de la diaria: daba
una tabla que cerraba perfecto, pero liquidaba los premios de Vanesa sobre importes estimados.
Un premio no se paga sobre una estimación.

**El arreglo de fondo es que el SP que carga la tabla mensual emita dos filas en vez de una**,
como ya hace la diaria. Eso está fuera de este repo. El día que pase, los tres importes salen
de la misma fuente y cierran solos.

**Deduplicación del objetivo**: `FP_ObjetivosFinales` admite recargas del mismo `idPOS+mes+
anio` (por eso tiene `fecha_finalizacion`). Se deduplica con
`ROW_NUMBER() ... ORDER BY fecha_finalizacion DESC = 1`, quedándose con la carga más
reciente. Verificado (2026-09-10) que hoy **no hay duplicados** para estos dos idPOS, así que
el `ROW_NUMBER` es un no-op; queda como red de seguridad, porque sumar dos cargas del mismo
mes duplicaría el objetivo y llevaría todos los premios a $0. Se sigue usando esta tabla (y
no el `IMP_OBJ` de la mensual) porque hace falta el objetivo **por canal** para Vanesa, y la
mensual solo trae el consolidado — pero como se verificó que dan lo mismo, son intercambiables.

**Criterio de IVA**: `DIVISOR_IVA = 1.0`, sin conversión. Se usa la columna `IMP_FACT`
(C/IVA) y se la compara contra un objetivo que sale de la misma corrida del ETL, así que las
dos puntas están en la misma base. Ojo: **`IMP_FACT_S_IVA` viene en CERO** para la fila
ECOMMERCE (el ETL no la puebla ahí), verificado en los tres meses — no sirve como alternativa.

### Órdenes: automáticas desde Tango

`RO_T_ESTADO_PEDIDOS_ECOMMERCE` (base `central`) tiene el canal en `TALON_PED` — 99=VTEX,
98=Mercado Libre, 80=ICBC — el mismo mapeo que hace `v:\ecommerce\Class\Control.php` en
varias consultas, y que coincide con `PuntosDeVenta.numero`. `TALON_PED` es **numérico**: un
`CASE` que lo devuelva junto a literales de texto falla con "Conversion failed … to data
type int".

Criterios de conteo (decididos el 2026-09-11, se cambian solo en `ordenesReales()`):

| Criterio | Por qué |
|---|---|
| `FECHA_PEDI` | Es la fecha del pedido: mide actividad comercial del mes, y es la misma base temporal que usa una tasa de conversión. Las otras candidatas eran `FECHA_SINCRONIZADO` y `FECHA_FACTURADO`. |
| `COUNT(DISTINCT ORDER_ID)` | La tabla tiene más de una fila por orden — julio 2026: 2.634 filas para 2.602 órdenes. Un `COUNT(*)` sobrecontaría. |
| `CANCELADO IS NULL` | Una orden cancelada no es una venta. Mismo criterio con el que se toma la facturación, y evita premiar cancelaciones. |

**Tango cuenta ~13,5 % menos órdenes que el panel de VTEX** (julio 2026: 2.504 contra las
2.894 de la planilla), en la misma línea que la brecha ya medida en pesos. Los objetivos
cargados vienen de la planilla anual del área y están expresados en órdenes de VTEX, así que
sobre el conteo de Tango quedan exigentes de más.

**Pero medido sobre 2026, recalibrarlos casi no cambia nada** (análisis del 2026-09-11,
enero a agosto, objetivo de VTEX contra órdenes reales de Tango):

| Mes | Obj. | Tango | % cumpl. | Premio | % recalibrado ×0,865 | Premio |
|---|---|---|---|---|---|---|
| Enero | 2.579 | 1.851 | 71,8 % | 0 | 83,0 % | 0 |
| Febrero | 2.297 | 1.490 | 64,9 % | 0 | 75,0 % | 0 |
| Marzo | 2.585 | 1.682 | 65,1 % | 0 | 75,2 % | 0 |
| Abril | 2.182 | 1.689 | 77,4 % | 0 | 89,5 % | 0 |
| Mayo | 4.743 | 3.855 | 81,3 % | 0 | 93,9 % | 0 |
| Junio | 2.618 | 2.073 | 79,2 % | 0 | 91,5 % | 0 |
| Julio | 3.491 | 2.504 | 71,7 % | 0 | 82,9 % | 0 |
| Agosto | 2.755 | 2.841 | **103,1 %** | 250.000 | **119,2 %** | 300.000 |
| | | | | **250.000** | | **300.000** |

La recalibración mueve $50.000 en ocho meses, y solo en agosto. **El hallazgo de fondo es
otro: el objetivo de órdenes se alcanza 1 de cada 8 meses** (promedio de cumplimiento 76,8 %),
con o sin corregir el sesgo de medición. Ese concepto de las pautas hoy es casi nominal —
decisión del área si se refijan los objetivos, pero no es un problema de qué fuente usar.

Límite de este análisis: el factor 0,865 está medido en **un solo mes** (julio, el único del
que se tiene el conteo del panel de VTEX). Con estos datos no se puede separar el sesgo de
medición de la variación real de desempeño; para eso haría falta el conteo mensual de VTEX.

**Mercado Libre está descalibrado en otro orden de magnitud**: cumplimientos de 125 % a 349 %
(promedio ~206 %; febrero: objetivo 588, Tango 2.052). No afecta ningún premio —a ML no se le
mide ningún concepto de órdenes— pero sugiere que o los objetivos de ese canal están viejos, o
Tango cuenta las órdenes de ML con otro criterio que el panel (¿una orden con varios packs
abierta en varios pedidos?). Sin investigar.

### Tasa de conversión: carga manual, del panel de VTEX

No existe en ninguna tabla ni hay integración con VTEX API / Google Analytics — verificado
sobre todo el repo y sobre `v:\ecommerce`. El dato de **sesiones** (el denominador) no vive
en ningún sistema propio, solo en VTEX Analytics. Se carga a mano desde el modal "Cargar
órdenes y conversión" (solo `isGlobalMode()`), una fila por mes y canal.

- La `TASA_CONVERSION` va en **puntos de porcentaje** (`0,83` = 0,83 %). El input valida
  `0 < v <= 100`, y al lado se muestra la tasa que darían las órdenes de Tango ÷ las sesiones
  cargadas. Esa referencia **no es la que se liquida**: sirve solo para detectar un error de
  magnitud (tipear "83" queriendo decir 0,83 % la deja a dos órdenes de distancia).
- **Deliberadamente NO se calcula la tasa como órdenes ÷ sesiones.** Las órdenes son de Tango
  y cuentan ~9,6 % menos que VTEX; la escala de conversión es de valores **absolutos**
  (0,90 / 0,80 / 0,70), no un % contra objetivo, así que no hay forma de recalibrarla como sí
  se puede con el objetivo de órdenes. Calcularla daría 0,75 en vez de 0,83 para julio 2026 y
  le costaría a Vanesa un tramo entero ($240.000 → $220.000) sin que hubiera cambiado nada de
  su desempeño real.
- `SESIONES` es opcional: cuando el período abarca varios meses y están cargadas en todos, la
  tasa se promedia **ponderada por sesiones** (la forma correcta de promediar tasas conociendo
  el tráfico). Tasa y sesiones son ambas de VTEX, así que la ponderación no mezcla fuentes.
  Sin sesiones, cae al promedio simple y el dashboard lo marca como "tasa estimada".
- Si falta un dato que algún concepto necesita, ese concepto queda en **`sin_dato`**: premio
  $0 pero mostrado como "falta carga", no como incumplimiento, y el mes aparece en el banner
  de `kpis_faltantes`.

### Período parcial

`FP_ObjetivosFinales` es mensual, así que con `periodo=mes_actual` (del 1 al día de ayer) se
compara un real parcial contra el objetivo del mes COMPLETO y todo da "no cumple". El
endpoint devuelve `periodo_parcial: true` y la pestaña muestra un banner de aviso. El toolbar
es compartido entre pestañas, así que esta vista no tiene un período por defecto propio.

Por el mismo motivo esta vista **no tiene comparativa interanual**: `$desdePrev/$hastaPrev`
no se usan, y `index.php` oculta `#periodo-previo-label` y el selector de supervisora
(que tampoco aplica) mientras la pestaña está activa.

### Badge "Última actualización"

Mira `BI_T_ESTADISTICAS_VENTAS_PROPIOS` y delega en `PremiosDB::esDesactualizado()`, o sea el
criterio **MENSUAL**, igual que las otras tres pestañas: ahora que la facturación —el dato que
domina el cálculo— sale de esa tabla, que se carga una vez por mes, es el eslabón lento. Las
órdenes (diarias) y la tasa (carga manual) no mueven el badge.

Con la fuente anterior (la tabla diaria) esta vista usaba un criterio diario propio; se
revirtió al migrar la facturación, porque si no la pestaña habría mostrado "DESACTUALIZADO"
todo el mes mientras las otras tres no.

### ⚠ El tablero NO reproduce los importes del Excel que reemplaza

**Esto es esperado y es una decisión tomada (2026-09-10), no un bug.** Si alguien del área
compara el tablero contra su planilla y los premios no coinciden, la explicación es esta.

La planilla tomaba la facturación real de los **paneles de VTEX y Mercado Libre**; el tablero
la toma de la **BI**, que es la misma fuente contra la que liquidan las otras tres pestañas
de Premios Comercial. Los dos números miden cosas distintas: VTEX/ML le facturan al comprador
incluyendo el envío y a precio bruto, mientras que Tango/BI contabiliza como venta solo el
valor del producto, neto de descuentos y sin envío. Está documentado en detalle en la
investigación de agosto 2026 sobre la brecha BI vs VTEX (julio 2026: BI $231,2M vs VTEX
"Total Value" $259,9M; usando `SKU Total Price + Discounts` del export de VTEX se llega a
$237,2M, a 2,6 % de la BI — el residual es ruido de fecha de corte).

Comparación de julio 2026, mismos objetivos y mismas escalas, cambiando solo la fuente del
real:

| Julio 2026 | Total ecommerce | vs. objetivo (388.202.535) |
|---|---|---|
| Excel (planilla, de los paneles VTEX/ML) | 408.342.000 | 105,19 % |
| BI mensual — la que usa el tablero | **389.311.751** | **100,29 %** |
| BI diaria (9+1) | 349.099.474 | 89,93 % |

| Premio | Con los números del Excel | Con los datos de BI |
|---|---|---|
| Agustina | 195.000 | **195.000** ✓ |
| Vanesa Di Feo | 690.000 | **490.000** |
| **Total** | **885.000** | **685.000** |

**Agustina coincide exacto con la planilla** (cruza el 100 % por poco, 100,29 %). Lo único
que separa a Vanesa son sus $200.000 de FACTURACION VTEX: necesita ≥90 % y el reparto
estimado da 88,30 % — a menos de dos puntos. Es el concepto más sensible del tablero.

Cambiar de fuente es tocar un solo método (`realesFacturacion()`) más las constantes
`NRO_SUCURS_*`; si en algún momento el negocio decide sumar el envío o pasar la facturación
a carga manual, ese es el lugar.

### Validación

**El motor de escalón** está validado contra los seis conceptos y contra los bordes de cada
escala (99,6 % no paga el tramo de 100 %; 0,49 de tasa no paga nada). Con los números de la
planilla como entrada, reproduce exactamente sus $195.000 / $690.000 / $885.000 — o sea que
la fórmula es correcta y la diferencia de arriba es puramente de fuente de dato.

**End-to-end**, con `periodo=custom&desde=2026-07-01&hasta=2026-07-31`, el endpoint debe dar:

| Persona | Concepto | Objetivo | Real | % Cump. | Tramo | Premio |
|---|---|---|---|---|---|---|
| Agustina | FACT E COMM | 388.202.535 | 389.311.751 | 100,29 % | 100 % | 115.000 |
| Agustina | T.CONV vtex | — | 0,83 % | — | 0,70 | 80.000 |
| Vanesa | FACTURACION VTEX | 283.006.188 | 224.083.924 | 79,18 % | — | 0 |
| Vanesa | ORDENES (OC) | 3.491 | 2.504 | 71,73 % | — | 0 |
| Vanesa | TASA CONVERSION | — | 0,83 % | — | 0,80 | 240.000 |
| Vanesa | FACTURACION ML | 105.196.347 | 125.015.550 | 118,84 % | 100 % | 250.000 |

**Agustina $195.000 · Vanesa $490.000 · Total $685.000.**

Ojo: la tabla diaria **cambia para meses ya cerrados**, así que los dos importes de Vanesa se
mueven. Para el mismo julio 2026 daba $231.175.507 (VTEX) y $130.527.383 (ML) el 2026-08-12, y
$224.083.924 / $125.015.550 el 2026-09-10 — un ~3 % menos en un mes, probablemente notas de
crédito posteriores. El total de Agustina en cambio es mensual y queda congelado al cierre. Al
validar, comparar el `%` de cumplimiento contra `real / objetivo` con los valores del momento,
no contra estos importes.

**Agustina queda al borde**: cruza el 100 % por 0,29 puntos, así que un movimiento chico del
dato le cambia el premio en $115.000. Conviene vigilarlo mes a mes.

### Pendiente de confirmar

En la captura del Excel original, Agustina muestra `T.CONV vtex = 0,7` y Vanesa
`TASA CONVERSION = 0,83` para el mismo canal y mes. Se modeló como **la misma métrica** (el
real es 0,83; el 0,7 de la celda de Agustina es el tramo alcanzado, no el valor) — confirmado
con el usuario. Los premios coinciden en ambas lecturas, así que la validación de arriba no
lo distingue. Si el área dijera que son métricas distintas, harían falta un `ORIGEN_REAL`
nuevo en `origenes()` y una columna más en `BI_T_PREMIOS_ECOM_KPIS`.

---

## Dependencias JS / CSS externas

```html
bootstrap-icons 1.11.3
SheetJS (xlsx) 0.18.5
```

No usa Chart.js (el tablero original no tiene gráficos, solo cards y tablas).

---

## Supuestos y notas

- PHP 8.x, sin dependencias de build (vanilla JS, sin bundler).
- Cache-busting mediante `filemtime()` en todos los includes JS/CSS, igual que el resto del repo.
- Una sucursal queda marcada `sin_datos=true` cuando `IMP_FACT=0` y `IMP_OBJ=0` para el
  período — reproduce las filas en blanco / `-100,00 %` que se ven en el tablero original.
- **Validado con el mismo rango de fechas exacto (7/6/26–6/7/26) en ambos tableros**: Total
  Premios de Carolina Commendatore coincide **exacto** ($160.000, con las 5 sucursales
  contando en Venta, Ticket Promedio, Ticket 2do y Ticket 3er Producto — decomposición
  idéntica a la del tablero real). El resto de las supervisoras quedó dentro de $5.000-
  $6.000 del valor real (antes del fix de la fila "TODAS"/ECOMMERCE la diferencia era de
  $30.000-$90.000) — consistente con una sola sucursal en cada caso cambiando de
  clasificación por estar muy pegada al benchmark de marca. También coinciden exacto: Total
  Premios de Natalia Bontempo ($225.000 — su única sucursal es la fila sintética "CENTRAL"),
  y los totales de facturación/objetivo/facturación previa de la Vista Franquicias.
- **Premio Obj. Crecimiento Cant. Franq. — RESUELTO por completo.** La diferencia "21 vs 20"
  (afectaba a ADROGUE, la única sucursal borderline) se debía a que el benchmark de marca de
  Franquicias usa una fórmula MULTIPLICATIVA (`×1.1`), no aditiva (`+10pp`) como Locales
  Propios — ver "Benchmark de marca — Franquicias" arriba. Con el fix, el conteo da exacto
  20 y **las 7 supervisoras coinciden exacto, dólar por dólar**, con la captura real del
  tablero de Power BI (Carolina $160.000, Elina $241.000, Josefina $378.000, Julieta
  $144.000, Nahir $396.000, Natalia $225.000, Sonia $313.000).
  **Actualización (2026-09-02)**: a pedido del cliente se cambió el benchmark de Franquicias
  de multiplicativo a aditivo (+10pp, igual que Locales Propios) — esta validación "exacto,
  dólar por dólar" ya no aplica tal cual, es esperado que el conteo de crecimiento en
  Franquicias difiera levemente del tablero de Power BI original a partir de ahora.
- El % de variación de la fila Total en Franquicias no coincidió (probablemente el original
  promedia el % por fila en vez de recalcular sobre la suma) — es una celda de detalle
  aislada, no afecta ningún monto de premio; pendiente de revisar si hace falta
  pixel-perfect ahí también.
- **KPI "Facturación Var % Marca" (Vistas Locales Propios y Franquicias)**: muestra el
  BENCHMARK (con el ajuste +10pp ya aplicado en ambos canales, ver "Benchmark de marca —
  Franquicias" arriba), no el agregado crudo — confirmado contra el KPI real de Locales
  Propios (302,07 % = 292,07 % + 10pp). `api/propios.php` y `api/franquicias.php` usan
  `benchmarkVarMarca()`/`benchmarkVarMarcaFranquicias()` para ese KPI puntual, no
  `facturacionVarMarca()`.
- **Tabla "Facturación vs. Objetivos por Sucursales" (Locales Propios) — validada 100%
  exacta contra el tablero real**, incluida la fila "TODAS"/ECOMMERCE y el Total general
  ($6.142.648.265 de facturación C/IVA, $2.005.791.046 de objetivo, todos los % y el ticket
  promedio coinciden).
- **Anomalía conocida y no replicada del tablero original**: en la captura real, la
  supervisora "Julieta Dalmeida" aparece con 4 sucursales en blanco (Abasto, Alto Palermo,
  Solar, Unicenter) que en realidad son sucursales de OTRAS supervisoras (Sonia, Elina,
  Josefina) con datos reales ese mismo mes. Se confirmó en la base que **no existe ninguna
  fila con `SUPERVISORA='JULIETA DALMEIDA'`** para ese período — es decir, el tablero
  original muestra esas 4 sucursales duplicadas bajo Julieta con valores en blanco, lo que
  parece un bug/artefacto visual del `.pbix` (posible cruce sin relación real en el modelo).
  Como su impacto en $ es nulo (Julieta ya da $0 en Locales Propios, correctamente, en
  ambos tableros), no se replicó este comportamiento. Mismo caso con "Palmas del Pilar"
  bajo Josefina (sin datos desde marzo 2026, pero igual aparece en blanco en el original).
