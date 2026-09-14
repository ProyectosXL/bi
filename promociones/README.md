# Dashboard Promociones

Migración del tablero de Promociones de Power BI a web.
URL de acceso: `/bi/promociones/`

---

## Estructura de archivos

```
/bi/promociones/
├── index.php                        Página principal (auth + HTML + orquestador)
├── class/
│   └── PromocionesDB.php            Acceso a datos (sqlsrv, multi-origen)
├── api/
│   ├── kpis.php                     KPIs principales + cotización
│   ├── cards_bancos.php             Top N bancos con variación
│   ├── donuts.php                   Distribución por sucursal / promoción / banco
│   ├── mensual.php                  Serie anual-mensual completa (tabla + gráfico)
│   ├── detalle.php                  ?action=sucursales | ?action=promociones
│   ├── rubros.php                   Unidades x Rubro (penetración de promociones)
│   ├── filtros.php                  Listas para selectores (bancos, promociones, sucursales)
│   └── cotizacion.php               Cotizaciones USD mensuales (BCRA)
├── components/
│   └── ExcelExporter.js             Exportación a Excel (copia de global/components/)
├── css/
│   └── promociones.css              Estilos específicos del dashboard
├── js/
│   ├── promociones.js               Módulo raíz: KPIs, bancos, donuts, moneda, filtros
│   ├── mensual.js                   Tabla mensual + gráfico evolución multi-anual
│   └── detalle.js                   Tablas por sucursal y por promoción
│   ├── rubros.js                    Tabla Unidades x Rubro
└── assets/
    └── bancos/                      Logos de bancos (JPG/JPEG)
```

---

## Endpoints API

Todos los endpoints requieren sesión activa (`$_SESSION['username']`) con tipo
`GERENCIA`, `SUPERVISION` o `GRUPO`.

| Archivo              | Parámetros principales                              | Respuesta                                      |
|----------------------|-----------------------------------------------------|------------------------------------------------|
| `kpis.php`           | origen, periodo, sucursal, banco, promocion         | actual, previo, variacion, periodo, cotizacion |
| `cards_bancos.php`   | origen, periodo, sucursal, banco, promocion, top_n  | bancos[]                                       |
| `donuts.php`         | origen, periodo, sucursal, banco, promocion         | donuts.{facturacion,promociones,bancos}[]      |
| `mensual.php`        | origen, sucursal, banco, promocion                  | anios[], datos[anio][mes], totales[anio]       |
| `detalle.php`        | action, origen, periodo, sucursal, banco, promocion | sucursales[] \| promociones[]                  |
| `rubros.php`         | origen, periodo, sucursal, banco, promocion         | rubros[]                                       |
| `filtros.php`        | origen                                              | bancos[], promociones[], sucursales[]          |
| `cotizacion.php`     | desde, hasta, desde_prev, hasta_prev                | cotizaciones{mesKey:tcc}, tcc_actual           |

---

## Multi-origen

```php
// class/config.php → getConfigForOrigen($origen)
'argentina'   → 'power'
'franquicias' → 'power_franquicias'
```

No hay origen Uruguay en Promociones (tabla `BI_PROMOCIONES` solo existe en
los orígenes de AR y franquicias).

El perfil **GRUPO** siempre usa origen `franquicias` y restringe las sucursales
a `$_SESSION['sucursalesGrupo']`.

---

## Tabla fuente

```sql
BI_PROMOCIONES (
    FECHA                    date
    NRO_SUCURSAL             int
    SUCURSAL                 nvarchar  -- nombre de la sucursal
    N_COMP                   nvarchar  COLLATE Latin1_General_BIN
    T_COMP                   nvarchar  COLLATE Latin1_General_BIN
    BANCO                    nvarchar
    DESC_PROMOCION_TARJETA   nvarchar  -- 'SIN PROMO' = sin promoción
    IMPORTE_TOTAL            decimal   -- facturación total del ticket
    IMPORTE_CPROMO           decimal   -- importe con promo (puede ser = IMPORTE_TOTAL)
    COSTO_TOTAL              decimal   -- costo financiero total (banco + ventas)
    COSTO_BANCO              decimal   -- cargo banco
    COSTO_VENTAS             decimal   -- costo operativo ventas
    CANT_TICKETS             int
)
```

---

## Regla de negocio clave: filtro Promoción

El filtro `sel-promocion` aplica **solo a los numeradores** (importe_cpromo,
costo_total, etc.) pero **NO recorta el denominador** (importe_total).

Esto preserva la ratio `% Costo / Fact. Total` y `% $ Promo / FAC` con el
denominador correcto (toda la facturación en el período y sucursal).

Implementado en `PromocionesDB::cpExpr()` usando un flag CASE WHEN en la
cláusula SELECT, en lugar de un filtro WHERE.

---

## Tickets en el Detalle por Sucursal

`getDetalleSucursales()` devuelve **tres** medidas distintas sobre tickets, que
no hay que confundir:

| Campo | Qué es |
|-------|--------|
| `tickets_total`       | Todos los tickets `FAC` de la sucursal, con y sin promo |
| `tickets_cpromo`      | Sólo los que llevan promo (flag `cp`) |
| `var_tickets_cpromo`  | Variación de `tickets_cpromo` vs. el período previo |
| `pct_tickets_cpromo`  | `tickets_cpromo / tickets_total` |
| `promos_usadas`       | Cuántas promociones se **aplicaron** en total |
| `var_promos_usadas`   | Variación de `promos_usadas` vs. el período previo |

`tickets_cpromo` es el que tiene que cuadrar con el KPI "Tickets con Promo" del
Resumen (`getKPIsBulk`). Puede haber una diferencia de pocos tickets: el KPI
hace un `COUNT(DISTINCT N_COMP)` global y el detalle cuenta por sucursal y
después suma, así que un mismo `N_COMP` repetido en dos sucursales se cuenta una
vez en el KPI y dos en el detalle.

> **Histórico:** hasta septiembre 2026 la columna rotulada "Tickets C/P" en esta
> tabla traía en realidad el total de tickets — al `COUNT` le faltaba la
> condición `cp=1`. Por eso nunca coincidió con el KPI del Resumen. Si comparás
> contra un Excel exportado antes de esa fecha, la columna vieja equivale a la
> actual `tickets_total`.

### Cómo se cuentan las promos aplicadas

Un ticket puede llevar **más de una promo**, y el modelo NO crea un renglón por
promo: las **concatena en una sola etiqueta separadas por `' / '`**. Así,
`'PROMO DIA DEL MAESTRO / CUENTA DNI - PROVINCIA'` son DOS promociones en un
renglón.

Por eso `promos_usadas` no cuenta filas: cuenta **separadores + 1**.

```sql
1 + (LEN(DESC_PROMOCION_TARJETA + '.')
     - LEN(REPLACE(DESC_PROMOCION_TARJETA, ' / ', '') + '.')) / 3
```

El `'.'` concatenado de los dos lados protege los espacios finales, que `LEN()`
recorta — hay promos cargadas como `'BANCO PROVINCIA '`. Es la misma convención
de separador que usa `detalle.js` para marcar las filas combinadas
(`row-combinada`).

Cuidado con dos cosas:

- Los nombres con `/` **sin espacios** no son combinaciones y no se parten bien:
  `'40%OFF 2DA UNIDAD (DM 25/8 AL 14/9)'` cuenta como una sola promo, que es lo
  correcto.
- La suma es **por renglón**, no por ticket. Si un ticket se pagó con dos
  tarjetas y ambas llevan promo, cuenta las dos — son dos aplicaciones.
  `sql/valida_promo.sql` bloque 11 valida los dos supuestos y mide cuánto pesa
  el segundo.

### Comparativo contra el período previo

`getDetalleSucursales()` recibe los cuatro límites (`$da, $ha, $dp, $hp`) y
resuelve los dos períodos en **un solo scan** con flags `is_a` / `is_p`, igual
que `getKPIsBulk()`. De ahí salen `var_tickets_cpromo` y `var_promos_usadas`.

El `HAVING` mira **sólo el período actual**, así que la tabla sigue listando las
sucursales con promo hoy: una que tuvo promo el año pasado y este año no, no
aparece.

Los valores absolutos del período previo viajan en el JSON
(`tickets_cpromo_prev`, `promos_usadas_prev`) pero **no tienen columna**: se usan
para que la fila Total pueda recalcular las variaciones sobre los subtotales, vía
el helper `varTotal()` de `detalle.js`.

---

## Unidades x Rubro — el cruce con las líneas de venta

`BI_PROMOCIONES` es de grano **ticket × medio de pago**: no tiene `RUBRO` ni
`CANTIDAD`. Para la pestaña Rubros las unidades salen de `BI_SALES_SUCURSALES`
(grano **línea de venta**: `RUBRO`, `CANTIDAD`, `IMPORTE`, `COD_ARTICU`,
`NRO_SUCURS`, `FECHA`, `N_COMP`), cruzada por `FECHA + NRO_SUCURS + N_COMP`.

Implementado en `PromocionesDB::getUnidadesPorRubro()`. Tres cosas no se pueden
tocar sin romper los números:

1. **Dedupe obligatorio.** El CTE `tk` reduce `BI_PROMOCIONES` a **un renglón
   por ticket** con un flag `cp` (`MAX(...)`). Sin ese `GROUP BY`, un ticket
   pagado con varias tarjetas multiplicaría las unidades de todas sus líneas.
2. **`LEFT JOIN`, no `INNER`.** Las unidades *totales* del rubro (el
   denominador) no pueden depender de que el ticket exista en `BI_PROMOCIONES`.
3. **`COLLATE DATABASE_DEFAULT` en los dos lados del `N_COMP`.** En
   `BI_PROMOCIONES` la columna es `Latin1_General_BIN`.

Los filtros **Banco / Promoción / excluir_promociones** aplican sólo al CTE
(el numerador). **Sucursal** y la restricción del perfil GRUPO aplican a los dos
lados del join — de ahí el helper aparte `filtroSucursalVentas()`, porque la
columna se llama `NRO_SUCURS` en ventas y `NRO_SUCURSAL` en promociones.

### Supuesto: la promoción es del ticket, no del artículo

"Unidades con promo" = **todas** las unidades de un ticket que tuvo al menos un
pago con promoción. No es atribución por producto — ese dato no existe en
ninguna tabla. La nota al pie de la pestaña lo aclara al usuario.

Se excluyen los rubros `CONCEPTO` y `PACKAGING`, criterio estándar del repo
(ver `SalesDB::whereExcluirRubrosUnid()`).

### Por qué no cuadra exacto con el KPI "Fact. con Promo"

El KPI del Resumen suma `IMPORTE_TO` del **ticket**; la columna Fact. C/Promo de
Rubros suma `IMPORTE` de las **líneas** excluyendo CONCEPTO/PACKAGING. La
diferencia es esperable. `sql/diag_rubros.sql` (bloque 5) la mide.

---

## Logos de bancos

Subir imágenes a `/bi/promociones/assets/bancos/`:

| Archivo                | Banco             |
|------------------------|-------------------|
| `Banco BBVA.jpg`       | BBVA              |
| `Banco Galicia.jpeg`   | GALICIA           |
| `Banco ICBC.JPG`       | ICBC              |
| `Banco Provincia.jpg`  | PROVINCIA         |
| `Banco Santander.JPG`  | SANTANDER         |

La normalización convierte el campo `BANCO` de la BD a mayúsculas, quita
espacios y elimina el prefijo `BANCO ` antes de buscar en el mapa.

---

## Dependencias JS / CSS externas

```html
<!-- Desde CDN — mismas versiones que /bi/global/ -->
bootstrap-icons 1.11.3
Chart.js 4.4.1
chartjs-plugin-datalabels 2.2.0
SheetJS (xlsx) 0.18.5
```

---

## Supuestos y notas

- PHP 8.x con driver `sqlsrv` exclusivamente (PDO prohibido).
- Cache-busting mediante `filemtime()` en todos los includes JS/CSS.
- Las queries usan `WITH (NOLOCK)` y rangos `DATEADD(day,1,?)` para incluir
  la fecha final correctamente.
- `getKPIsBulk` ejecuta un único scan con derived-table + flags `is_a`/`is_p`/`cp`
  para calcular actual y previo en una sola consulta.
- La tabla mensual de la pestaña "Evolución" trae **toda la historia** disponible
  sin restricción de período (endpoint `mensual.php` ignora el período selector).
- El gráfico de evolución muestra `% Costo / Fact. Total` por mes para cada año
  disponible, con tooltip enriquecido (4 ratios por punto).
- La pestaña Rubros es la única que escanea `BI_SALES_SUCURSALES` (grano línea)
  y lo hace sobre dos períodos; por eso `api/rubros.php` levanta el
  `set_time_limit` a 120 s. `sql/diag_rubros.sql` (bloque 6) mide el tiempo.
- `js/rubros.js` no tiene render propio: reutiliza `PromoDetalle.renderTabla()`,
  que acepta un `recalcTotal` por columna para los ratios que la fila Total no
  puede sumar (`pct_penetracion`, `var_unid_cp`).
- **Tooltips de encabezado**: agregando `tip: '…'` a una columna, `renderTabla()`
  emite `data-tip` en el `<th>` y subraya el label. Los muestra
  `Promociones.initTooltips()`, un único listener delegado en `document` —
  delegado a propósito, porque `renderTabla()` reconstruye el `innerHTML` en cada
  sort y un bind por elemento se perdería. El tooltip es `position: fixed` en
  `<body>` para que no lo recorte el `overflow` de `.promo-detalle-wrap`.
