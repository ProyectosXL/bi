# Dashboard Logística — XL Extra Large

Módulo BI replicado desde Power BI. Base de datos: `POWER_BI_CONTROL` en servidor `XL-APPS`.

---

## Orden de ejecución de los scripts SQL

Ejecutar en SQL Server Management Studio **sobre la base `POWER_BI_CONTROL`**, en orden:

| # | Script | Qué hace |
|---|--------|----------|
| 1 | `sql/01_calendario.sql` | Crea `RO_T_FERIADOS` + `RO_T_CALENDARIO` (2022-2027) |
| 2 | `sql/02_sp_eficiencia.sql` | SP Área 1: Eficiencia logística |
| 3 | `sql/03_sp_leadtime.sql` | SP Área 2: Lead time facturación |
| 4 | `sql/04_sp_stock.sql` | SP Área 3: Stock WMS vs Tango |
| 5 | `sql/05_sp_productividad_fact.sql` | SP Área 4: Productividad facturación |
| 6 | `sql/06_sp_productividad_picking.sql` | SP Área 5: Productividad picking |
| 7 | `sql/07_sp_demanda_despacho.sql` | SP Área 6: Demanda y despacho |
| 8 | `sql/08_sp_pedidos_consolidados.sql` | SP Área 7: Pedidos consolidados |

> El script 01 debe ejecutarse primero porque los SPs de área hacen JOIN sobre `RO_T_CALENDARIO`.

---

## Estructura de archivos

```
logistica/
├── index.php               Layout principal (7 pestañas)
├── ajax/
│   ├── filtros.php         Filtros dinámicos (canales, rubros, usuarios)
│   ├── eficiencia.php      Área 1
│   ├── leadtime.php        Área 2
│   ├── stock.php           Área 3
│   ├── productividad_fact.php    Área 4
│   ├── productividad_picking.php Área 5
│   ├── demanda_despacho.php      Área 6
│   └── pedidos_consolidados.php  Área 7
├── class/
│   └── LogisticaDB.php     Capa de acceso a datos (sqlsrv, sin PDO)
├── assets/
│   ├── logistica.css       Estilos del módulo
│   └── logistica.js        Lógica frontend (IIFE jQuery + Chart.js)
├── sql/                    Scripts DDL y SPs
└── README.md
```

---

## Cómo probar cada pestaña

### Pestaña 1 — Eficiencia logística
- Filtrá por rango de fechas y canal (opcional).
- Verificá que el semáforo de eficiencia sea verde cuando supere 95% y rojo cuando no.
- El gráfico de línea debe mostrar la evolución mensual con la línea de meta en rojo punteado.

### Pestaña 2 — Lead Time Facturación
- Sin filtros adicionales, aplica solo el rango de fechas.
- El histograma muestra barras rojas para lead times > 5 días y verdes para los demás.
- El KPI "Pedidos abiertos" no está afectado por el rango de fechas (es el estado actual).

### Pestaña 3 — Stock WMS vs Tango
- Sin filtro de fecha; el slicer de Rubro filtra tanto el gráfico como la tabla.
- La precisión de inventario ideal es cercana al 100%.

### Pestaña 4 — Productividad Facturación
- Filtrá por usuario para ver un operador específico.
- La tabla inferior muestra estadísticas individuales por usuario.

### Pestaña 5 — Productividad Picking
- El gráfico combina barras (unidades) con línea (horas) en doble eje Y.
- "Prom. últ. 7 días" siempre es sobre los 7 días anteriores a hoy, sin importar el rango de filtro.

### Pestaña 6 — Demanda y Despacho
- "Próximo día hábil" se calcula dinámicamente desde `RO_T_CALENDARIO`.
- "Pickers necesarios" = pendientes del próximo día hábil / promedio picking 7 días.
- Las tres tablas (pendientes hoy, demorados, próxima entrega) se cargan sin paginación (TOP 500).

### Pestaña 7 — Pedidos Consolidados
- Filtrá por canal para comparar canales de venta.
- "Variación vs año ant." compara el mismo rango de fechas un año atrás via `RO_T_CALENDARIO`.

---

## Notas de mantenimiento

- Los feriados en `RO_T_FERIADOS` deben actualizarse cada año con el decreto oficial.
- Para agregar un año al calendario, extender la CTE en `01_calendario.sql` y re-ejecutar.
- Todos los SPs usan `SET NOCOUNT ON`; si se agrega lógica con `PRINT` o `RAISERROR`, usar severity < 10 para no romper el parsing de result sets en PHP.
- El parámetro `@CANAL` en los SPs es `NVARCHAR(100) = NULL`: pasar `null` desde PHP para "todos".
