# Dashboard Logística — XL Extra Large

Módulo BI multi-país (AR + UY). Base AR: `POWER_BI_CONTROL` en `XL-APPS`. Base UY: `POWER_BI_CONTROL_URUGUAY` en `XL-APPS`.

---

## Multi-país

### Cómo alternar de país

| Método | Ejemplo |
|--------|---------|
| Toggle en topbar | Clic en **AR** o **UY** |
| Query string (compartible) | `/bi/logistica/?pais=UY` |
| Sesión | El país queda guardado hasta que se cambie |

La sesión persiste en `$_SESSION['logistica_pais']`. Si se accede sin `?pais=`, se usa el valor de sesión o `AR` por defecto.

### Reglas de diseño

- Mismo CSS (`logistica.css`), mismos colores y tipografías.
- Tema claro siempre. Sin dark mode.
- Sin dependencias nuevas: jQuery, Chart.js y Bootstrap Icons preexistentes.

---

## SQL — Argentina (POWER_BI_CONTROL)

Ejecutar en SQL Server Management Studio sobre `POWER_BI_CONTROL`, en orden:

| # | Script | Qué hace |
|---|--------|----------|
| 1 | `sql/01_calendario.sql` | Crea `RO_T_FERIADOS` + `RO_T_CALENDARIO` (2022-2027, feriados AR) |
| 2 | `sql/02_sp_eficiencia.sql` | SP Área 1: Eficiencia logística |
| 3 | `sql/03_sp_leadtime.sql` | SP Área 2: Lead time facturación |
| 4 | `sql/04_sp_stock.sql` | SP Área 3: Stock WMS vs Tango |
| 5 | `sql/05_sp_productividad_fact.sql` | SP Área 4: Productividad facturación |
| 6 | `sql/06_sp_productividad_picking.sql` | SP Área 5: Productividad picking |
| 7 | `sql/07_sp_demanda_despacho.sql` | SP Área 6: Demanda y despacho |
| 8 | `sql/08_sp_pedidos_consolidados.sql` | SP Área 7: Pedidos consolidados |
| 9 | `sql/00_indices.sql` | Índices cubrientes (ejecutar en cualquier momento) |

---

## SQL — Uruguay (POWER_BI_CONTROL_URUGUAY)

Ejecutar en SQL Server Management Studio sobre `POWER_BI_CONTROL_URUGUAY`, en orden:

| # | Script | Qué hace |
|---|--------|----------|
| 1 | `sql/uy/01_calendario_uy.sql` | Crea `RO_T_FERIADOS_UY` + `RO_T_CALENDARIO_UY` (2022-2027, feriados UY) |
| 2 | `sql/uy/00b_cotizacion.sql` | Crea y pobla `RO_T_COTIZACION_UYU_USD` |
| 3 | `sql/uy/04_sync_stock_uy.sql` | Crea tabla `BI_T_STOCK_WMS_TANGO_UY` + SP `RO_SP_SYNC_STOCK_UY` (materialización nightly desde XL-TANGO) |
| 4 | `sql/uy/02_sp_eficiencia_uy.sql` | SP `RO_SP_EFICIENCIA_LOGISTICA_UY` (pestaña Eficiencia UY) |
| 5 | `sql/uy/03_sp_stock_uy.sql` | SP `RO_SP_STOCK_WMS_TANGO_UY` (pestaña Stock UY) |
| 6 | `sql/uy/00_indices_uy.sql` | Índices cubrientes UY |

> **Nota stock:** El SP de stock lee `BI_T_STOCK_WMS_TANGO_UY` (tabla materializada, opción A).
> Después de crear la tabla, ejecutar `EXEC dbo.RO_SP_SYNC_STOCK_UY` una vez para poblarla.
> Agendar como SQL Agent Job nightly.

> **Nota cotización:** `RO_T_COTIZACION_UYU_USD` tiene cotizaciones de referencia. Actualizar con
> los valores reales. El dashboard también permite ingresar una cotización manual por sesión.

---

## Estructura de archivos

```
logistica/
├── index.php               Shell multi-país (toggle AR/UY, include partial)
├── class/
│   ├── Pais.php            Resolución y persistencia de país en sesión
│   ├── LogisticaDBBase.php Clase base abstracta (plomería sqlsrv + cache)
│   ├── LogisticaDB.php     Datos AR (extiende base, clave 'power')
│   ├── LogisticaDB_UY.php  Datos UY (extiende base, clave 'power_uy')
│   └── LogisticaDBFactory.php  Fábrica: make($pais) → LogisticaDBBase
├── partials/
│   ├── tabs_ar.php         7 tab-panes AR (incluido desde index.php)
│   └── tabs_uy.php         2 tab-panes UY (incluido desde index.php)
├── ajax/                   Endpoints AR (sin cambios)
│   ├── filtros.php
│   ├── eficiencia.php
│   ├── leadtime.php
│   ├── stock.php
│   ├── productividad_fact.php
│   ├── productividad_picking.php
│   ├── demanda_despacho.php
│   └── pedidos_consolidados.php
├── ajax/uy/                Endpoints UY
│   ├── filtros.php         { canales, rubros }
│   ├── eficiencia.php      getEficienciaUy(desde,hasta,canal,rubro,cotizacion)
│   └── stock.php           getStockUy(rubro)
├── assets/
│   ├── logistica.css       Estilos del módulo (incluye toggle de país)
│   ├── logistica_core.js   Helpers compartidos → window.LogiCore
│   ├── logistica_ar.js     Controlador AR (consume LogiCore)
│   └── logistica_uy.js     Controlador UY (consume LogiCore)
├── sql/                    Scripts AR (sin cambios)
└── sql/uy/                 Scripts UY
    ├── 00_indices_uy.sql
    ├── 00b_cotizacion.sql
    ├── 01_calendario_uy.sql
    ├── 02_sp_eficiencia_uy.sql
    ├── 03_sp_stock_uy.sql
    └── 04_sync_stock_uy.sql
```

---

## Cache

El cache de AR y UY está separado por diseño: `LogisticaDBBase` incluye la clave de conexión (`power` / `power_uy`) como prefijo del archivo y en el hash SHA1. Archivos en `sys_get_temp_dir()`:
- AR: `bi_logistica_power_<sha1>.json`
- UY: `bi_logistica_power_uy_<sha1>.json`

---

## Indicadores UY — v1

### Pestaña 1: Eficiencia

| Indicador | Descripción |
|-----------|-------------|
| % Eficiencia global | `SUM(CANT_FACTURADA) / SUM(CANT_PEDID)` |
| % Eficiencia por rubro | Idem agrupado por RUBRO (chart barras horizontales + tabla) |
| Pérdida ($UY) | `SUM(IMPORTE_PENDIENTE)` |
| Pérdida (U$S) | Pérdida / cotización USD activa |
| Evolución 24 meses | LEFT JOIN `RO_T_CALENDARIO_UY`, línea de meta 95% |
| Cotización USD | Última en `RO_T_COTIZACION_UYU_USD` ≤ fecha hasta; override manual |

### Pestaña 2: Stock Tango vs WMS Jauser

| KPI | Cálculo |
|-----|---------|
| Diferencia neta | `SUM(DIFERENCIA)` |
| % Diferencia neta | `SUM(DIFERENCIA) / SUM(STOCK_TANGO)` |
| Diferencia absoluta | `SUM(DIFERENCIA_ABS)` |
| Precisión inventario | `1 − SUM(DIFERENCIA_ABS) / SUM(STOCK_TANGO)` |

Gráfico A: barras agrupadas Tango vs WMS por rubro.  
Gráfico B: diferencia absoluta por rubro (magnitud del desvío).

---

## Notas de mantenimiento

- Los feriados en `RO_T_FERIADOS` (AR) y `RO_T_FERIADOS_UY` (UY) deben actualizarse cada año.
- Para agregar años al calendario, extender la CTE (hasta `'202X-12-31'`) y re-ejecutar el script.
- Todos los SPs usan `SET NOCOUNT ON`; severidades `PRINT`/`RAISERROR` deben ser < 10.
- El parámetro `@CANAL`/`@RUBRO` en los SPs es `NVARCHAR(100) = NULL`: pasar `null` para "todos".
- Para actualizar cotizaciones UY: `INSERT INTO dbo.RO_T_COTIZACION_UYU_USD (FECHA, COTIZACION) VALUES ('YYYY-MM-DD', 44.50)`.
