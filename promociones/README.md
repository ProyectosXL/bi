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
