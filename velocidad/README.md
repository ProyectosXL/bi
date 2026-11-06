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
| Crecimiento a 30 / 60 días | `(SUM(30_DIAS \| 60_DIAS) − SUM(15_DIAS)) / SUM(15_DIAS)` |
| Stock locales / Stock central (01 AR, 82 UY) | `SUM(STOCK_LOCALES)` / `SUM(STOCK_CENTRAL)` |

- **Semáforo** (medida `[Color KPI % ABC]`, igual en las 3 páginas): rojo ≤ 30 %, amarillo > 30 % y < 40 %, verde ≥ 40 %.
- **Ranking Negativo**: filtro Top N del visual = los **50 modelos con menos `[15 Dias]`** (empates incluidos,
  por eso puede mostrar más de 50), ordenados por Venta / ingreso 15 días ascendente.
- **Filtro de visual `[Ingreso central] > 0`**: Argentina y Negativo.
- Filtros: Fecha → `INGRESO` · Modelo → `COD_ARTICU` · Línea → `NOMBRE` · Rubro / Categoría / Color → columnas homónimas.
- Indicadores de arriba: las mismas medidas evaluadas sobre todos los modelos de la selección (como una fila de total).

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
