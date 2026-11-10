# Velocidad de Ventas (web)

Réplica web del informe de Power BI "Velocidad de Ventas" (`T:\Velocidad de Ventas.pbix`):
**Ranking Argentina**, **Ranking Uruguay** y **Ranking Negativo**.

- Entrada: `/bi/velocidad/index.php` (requiere sesión del BI; los endpoints devuelven 401 sin sesión).
  Enlace directo a una pestaña: `index.php#uy`, `index.php#neg`.
- Datos: `class/VelocidadVentasDB.php`. Mismos orígenes que el `.pbix`:
  - `XL-APPS / POWER_BI_CONTROL.dbo.BI_ROTACION_VENTAS` y `BI_STOCK_LOCALES` → conexión `power`
  - `XL-APPS / POWER_BI_CONTROL_URUGUAY.dbo.BI_ROTACION_VENTAS_UY` → conexión `power_uy`

## Medidas (iguales a las DAX del informe), por `COD_ARTICU`

| Columna | Fórmula |
|---|---|
| % Venta / ingreso locales | `SUM(VENTA_TOTAL) / SUM(INGRESO_LOCALES)` |
| Venta / ingreso 15 días (semáforo) | `SUM(15_DIAS) / SUM(CANT_PRIMER_INGRESO)` |
| Días de stock | `SUM(INGRESO_LOCALES) / (SUM(15_DIAS) / 15)` |
| Venta / ingreso 30 / 60 días | `SUM(30_DIAS \| 60_DIAS) / SUM(CANT_INGRESO_30 \| CANT_INGRESO_60)` (sin semáforo) |
| Stock locales / Stock central (01 AR, 82 UY) | `SUM(STOCK_LOCALES)` / `SUM(STOCK_CENTRAL)` |

- **Semáforo** (medida `[Color KPI % ABC]`, igual en las 3 páginas): rojo ≤ 30 %, amarillo > 30 % y < 40 %, verde ≥ 40 %.
- **Ranking Negativo**: filtro Top N del visual = los **50 modelos con menos `[15 Dias]`** (empates incluidos,
  por eso puede mostrar más de 50), ordenados por Venta / ingreso 15 días ascendente.
- **Filtro de visual `[Ingreso central] > 0`**: Argentina y Negativo.
- Filtros: Fecha → `INGRESO` · Modelo → `COD_ARTICU` · Línea → `NOMBRE` · Rubro / Categoría / Color → columnas homónimas.
- Indicadores de arriba: las mismas medidas evaluadas sobre todos los modelos de la selección (como una fila de total).

## Origen de los datos (ETL)

`BI_ROTACION_VENTAS` se recarga todas las noches (~00:18) desde **XL-LAKERBIS / `LOCALES_LAKERS`**, job
*BI - SUCURSALES*: el paso 19 (`RO_SP_TABLAS_VELOCIDAD_VENTAS`) materializa las tablas auxiliares y el paso 20
(`SJ_SP_BI_ROTACION_VENTAS`) trunca la tabla de XL-APPS y le copia la vista `LOCALES_LAKERS.dbo.BI_ROTACION_VENTAS`.
Uruguay: job *BI - URUGUAY*, paso 11, base `SUCURSALES_URUGUAY`.

- `15_DIAS`: ventas de locales propios (`CTA03`, sucursal ≠ 1) + ecommerce (FAC cliente `000000`) desde `INGRESO`
  (primera recepción en un local) hasta `INGRESO + 15`.
- `CANT_PRIMER_INGRESO`: **remitos de central a locales propios** (REM, salida, clientes `%GT%`) desde
  `FECHA_ING_CENTRAL` hasta `INGRESO + 15` (vista `SJ_V_INGRESOS_CENTRAL_CANTIDAD`).
  *Corregido el 10/11/2026*: antes sumaba todos los movimientos de stock (franquicias, mayoristas, ecommerce,
  entradas) desde `INGRESO`, y el % de venta a 15 días quedaba muy subestimado (93 % de los modelos en rojo).
  Ej. BELICE TOTE NEGRO: 126 / 484 = 26 % → 126 / 140 = 90 %.
- `CANT_INGRESO_30` / `CANT_INGRESO_60` (agregadas 11/2026, solo AR): igual que `CANT_PRIMER_INGRESO` pero hasta
  `INGRESO + 30` / `+ 60`. Reemplazan al "crecimiento" del informe original, `(30_DIAS − 15_DIAS) / 15_DIAS`, que
  tenía el título "Venta / ingreso" pero no era venta sobre ingreso. Si la tabla no tiene estas columnas (UY),
  la web deja esas celdas vacías.
- `INGRESO_LOCALES` / `INGRESO_CENTRAL`: vista `RO_V_INGRESOS_VELOCIDAD_VENTAS` (todos los remitos a locales `%GT%`
  y remitos de proveedor `RP`, sin ventana de fechas).

## Validación contra Power BI (06/11/2026, modelo refrescado ese día, rango 01/08–31/12/2026)

Consultando el modelo abierto en Power BI Desktop (DAX) y comparando celda por celda con la web:
Argentina 588/588 filas, Uruguay 512/512 y Negativo 54/54, **todas las celdas iguales**; stock por sucursal
igual; filtros de rubro/categoría/color/línea iguales.

Diferencias conocidas **del informe de Power BI** que la web no copia a propósito:
- En la página Uruguay, el slicer **Línea** apunta a `LINEAS` (la tabla de Argentina) y no filtra nada.
  La web sí filtra Uruguay por línea.
- La leyenda de texto del Ranking Negativo dice 40 % / 50 %, pero la celda se pinta con la regla de 30 % / 40 %.
- El orden entre empates (mismo valor) no está definido en Power BI; la web desempata por posición.
- No se replicó la cuarta página del informe, "Display" (galería con fotos de los 20 más vendidos).

## Fotos

Misma URL que arma el informe (tabla `MODELOS`, columna `URL`): `https://app.xl.com.ar/Imagenes/{COD_ARTICU}.jpg`
(98,6 % de los modelos del período tienen foto). Si no existe, se intenta el proxy `ajax/imagen.php`
(`W:\Imagenes` vía `AbastecimientoDB::getImagenPath()`) y por último un marcador gris.

## Configuración
En `VelocidadVentasDB::ORIGENES` se define en qué conexión vive cada tabla.
El rango de fechas inicial se cambia en `index.php` (`$DESDE_DEFAULT`).
