# Dashboard Premios Comercial

Migración del tablero de Power BI "Premios Comercial" a web.
URL de acceso: `/bi/premios/`

---

## Estructura de archivos

```
/bi/premios/
├── index.php                        Página principal (auth + HTML + orquestador)
├── class/
│   └── PremiosDB.php                 Datos reales (sqlsrv) + reglas de premios
├── api/
│   ├── filtros.php                   Lista de supervisoras para el selector
│   ├── resumen.php                   Vista 1: premios por supervisora (propios + franquicias)
│   ├── propios.php                   Vista 2: facturación vs objetivos, locales propios
│   └── franquicias.php               Vista 3: facturación vs objetivos, franquicias
├── components/
│   └── ExcelExporter.js              Exportación a Excel (copia de global/components/)
├── css/
│   └── premios.css                   Estilos específicos del dashboard
└── js/
    ├── premios.js                    Helpers compartidos: filtros del toolbar, período, semáforo
    ├── supervisoras.js                Vista 1: cards de premio + 2 tablas de detalle
    ├── propios.js                     Vista 2: KPIs + tabla agrupada por supervisora
    └── franquicias.js                 Vista 3: KPIs + tabla ordenada alfabéticamente
```

---

## Acceso

Solo requiere sesión activa (`$_SESSION['username']`), sin restricción por `tipo` —
el control de quién ve el link vive en el portal donde está indexado (`indicadores.php`),
no en este módulo. Cualquier usuario logueado que llegue a `/bi/premios/` puede verlo.

---

## Endpoints API

Todos requieren sesión activa (`$_SESSION['username']`), sin restricción por `tipo`.

| Archivo            | Parámetros principales                                              | Respuesta                                  |
|---------------------|-----------------------------------------------------------------------|---------------------------------------------|
| `filtros.php`       | —                                                                      | supervisoras[]                              |
| `resumen.php`       | periodo, desde/hasta/comp_mode (si custom), supervisora                | periodo, supervisoras[] (premios propios+franquicias, total) |
| `propios.php`       | periodo, ..., supervisora                                              | periodo, kpis, grupos[] (por supervisora, con subtotal), total |
| `franquicias.php`   | periodo, ..., supervisora                                              | periodo, kpis, sucursales[] (orden alfabético), total |

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

Mismo patrón que `sales/` y `global/`: `PremiosDB::getUltimaActualizacion()` consulta
`sys.dm_db_index_usage_stats` para saber cuándo se escribió por última vez cada tabla de
origen (`BI_T_ESTADISTICAS_VENTAS_PROPIOS` en `power`, `BI_T_ESTADISTICAS_VENTAS_FRANQUICIAS`
en `power_franquicias`), con fallback a `MAX(FECHA)` si no hay estadísticas de uso (ej. tras
un reinicio del motor). Como hay DOS orígenes, se devuelve la más antigua de las dos fechas —
si cualquiera de las dos tablas está desactualizada, el reporte completo lo está.

El dato es independiente del período elegido por el usuario en el toolbar (siempre refleja
cuándo se cargaron los datos por última vez, no el rango de fechas consultado). Se considera
"desactualizado" (`is_outdated=true`, badge rojo con animación de pulso) cuando esa fecha es
anterior a "ayer 00:00:00".

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
- **Benchmark de marca — Franquicias**: acá el +10% es **MULTIPLICATIVO**, no aditivo —
  `(1 + var%) * 1.1 - 1` (`benchmarkVarMarcaFranquicias()`). Confirmado por DAX:
  `Facturación Var % All Franq. = CALCULATE([Facturación Var % Franq.], ALL(...)) * 1.1`,
  donde `[Facturación Var % Franq.]` está en formato ratio por un error de paréntesis en el
  `.pbix` original (`DIVIDE(SUM(IMP_FACT), SUM(IMP_FACT_ANT)-1)` — el "-1" quedó dentro del
  denominador, sin efecto práctico, pero el resultado es un ratio ≈ FACT/FACT_ANT en vez de
  un delta). **No usar la misma fórmula de benchmark para ambos canales** — es la causa real
  de la discrepancia "21 vs 20" en `Premio Obj. Crecimiento Cant. Franq.` que tardamos varias
  rondas en encontrar (afectaba solo a sucursales franquicia muy cercanas al límite, como
  ADROGUE).
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
- El % de variación de la fila Total en Franquicias no coincidió (probablemente el original
  promedia el % por fila en vez de recalcular sobre la suma) — es una celda de detalle
  aislada, no afecta ningún monto de premio; pendiente de revisar si hace falta
  pixel-perfect ahí también.
- **KPI "Facturación Var % Marca" (Vistas Locales Propios y Franquicias)**: muestra el
  BENCHMARK (con el ajuste +10pp/×1.1 ya aplicado), no el agregado crudo — confirmado
  contra el KPI real de Locales Propios (302,07 % = 292,07 % + 10pp). `api/propios.php`
  y `api/franquicias.php` usan `benchmarkVarMarca()`/`benchmarkVarMarcaFranquicias()` para
  ese KPI puntual, no `facturacionVarMarca()`.
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
