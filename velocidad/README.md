# Velocidad de Ventas (web)

Réplica web del informe de Power BI "Velocidad de Ventas": **Ranking Argentina**, **Ranking Uruguay** y **Ranking Negativo** (50 inferiores).

- Entrada: `/bi/velocidad/index.php` (requiere sesión del BI; los endpoints devuelven 401 sin sesión).
- Datos: `class/VelocidadVentasDB.php` consulta `BI_ROTACION_VENTAS`, `BI_ROTACION_VENTAS_UY` y `BI_STOCK_LOCALES` (stock solo Argentina).
- Medidas (equivalentes a las DAX del informe), por `COD_ARTICU`:
  - `% Venta 15 días` = `SUM(15_DIAS) / SUM(CANT_PRIMER_INGRESO)` → es la que lleva el semáforo.
  - `% Venta / ingreso locales` = `SUM(VENTA_TOTAL) / SUM(INGRESO_LOCALES)`.
  - `Días de stock` = `SUM(INGRESO_LOCALES) / (SUM(15_DIAS) / 15)`.
  - `Crecimiento a 30 / 60` = `(Vta30|Vta60 − Vta15) / Vta15`.
- Semáforo: Argentina/Uruguay rojo ≤ 30 %, verde ≥ 40 %; Ranking Negativo rojo ≤ 40 %, verde ≥ 50 % (amarillo en el medio).
- Filtros: Fecha → `INGRESO` · Modelo → `COD_ARTICU` · Línea → `NOMBRE` · Rubro / Categoría / Color → columnas homónimas.
- Fotos: proxy a `W:\Imagenes` reutilizando `AbastecimientoDB::getImagenPath()` (módulo `abastecimiento`).

## Configuración
En `VelocidadVentasDB::ORIGENES` se define en qué conexión (`power`, `power_uy`, `locales`…) vive cada tabla.
Si una tabla no está donde se indica, el tablero muestra el error de SQL Server (p. ej. `Invalid object name`).

El rango de fechas inicial se cambia en `index.php` (`$DESDE_DEFAULT`).
