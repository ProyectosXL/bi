/* ═══════════════════════════════════════════════════════════════════════════
   diag_promo_articulo.sql — ¿Dónde queda registrada cada APLICACIÓN de una promo?

   Caso testigo (negocio): ticket de ~$600.000 con 4 cremas en el que el POS
   aplicó 4 veces la promo de cremas. En "Promos Usadas" ese ticket cuenta 1,
   porque BI_PROMOCIONES (grano ticket × medio de pago) trae el nombre de la
   promo UNA vez por renglón. Este script busca si la repetición se pierde en
   el ETL o si existe en alguna tabla que podamos cruzar.

   Correr en la base del origen:
       POWER_BI_CONTROL              (Argentina)
       POWER_BI_CONTROL_FRANQUICIAS  (Franquicias)
   El bloque 3 se corre además en la base de Tango / POS de la sucursal.
   ═══════════════════════════════════════════════════════════════════════════ */


/* ═══════════════════════════════════════════════════════════════════════════
   1) Encontrar el ticket testigo
   ═══════════════════════════════════════════════════════════════════════════
   Si negocio pasó nro. de ticket, sucursal y fecha, saltear al bloque 2.
   Si no, lista candidatos: tickets de $550.000 a $650.000 con promo de crema.
   Ajustar @promo_like al nombre real de la promo.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @desde      DATE          = '2026-09-01';
DECLARE @hasta      DATE          = '2026-10-17';   -- exclusivo
DECLARE @promo_like NVARCHAR(100) = N'%CREMA%';

SELECT TOP 50
       p.FECHA, p.NRO_SUCURSAL, p.SUCURSAL, p.N_COMP, p.T_COMP,
       p.DESC_PROMOCION_TARJETA, p.BANCO, p.IMPORTE_TO, p.COSTO
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.FECHA >= @desde AND p.FECHA < @hasta
  AND p.DESC_PROMOCION_TARJETA LIKE @promo_like
  AND p.IMPORTE_TO BETWEEN 550000 AND 650000
ORDER BY p.FECHA DESC;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   2) El ticket testigo, en las dos tablas de BI
   ═══════════════════════════════════════════════════════════════════════════
   Completar las tres variables con el ticket elegido.

   2.1) BI_PROMOCIONES — qué mirar:
     · ¿Cuántos renglones tiene? ¿La promo de cremas aparece 1 vez o 4?
     · Si aparece 4 veces (4 renglones o el nombre repetido con ' / '), el dato
       está y alcanza con ajustar el conteo. Si aparece 1 vez, el ETL colapsa.
     · COSTO: si es 4 veces el descuento de una crema, el monto refleja las 4
       aplicaciones aunque el nombre aparezca una sola vez (posible atajo:
       aplicaciones = COSTO / descuento unitario).

   2.2) BI_SALES_SUCURSALES (SELECT * a propósito) — qué mirar:
     · Las líneas de las cremas: ¿alguna columna marca la promo (código,
       descuento, bonificación, precio 0 o distinto del de lista)?
     · ¿Las 4 cremas vienen en 1 línea con CANTIDAD = 4 o en 4 líneas?
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @fecha  DATE         = '2026-10-01';   -- completar
DECLARE @suc    INT          = 0;              -- completar
DECLARE @n_comp VARCHAR(30)  = '';             -- completar

SELECT p.*
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.FECHA = @fecha
  AND p.NRO_SUCURSAL = @suc
  AND p.N_COMP COLLATE DATABASE_DEFAULT = @n_comp;

SELECT s.*
FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
WHERE s.FECHA = @fecha
  AND s.NRO_SUCURS = @suc
  AND s.N_COMP COLLATE DATABASE_DEFAULT = @n_comp;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   3) Buscar columnas o tablas de promoción por renglón
   ═══════════════════════════════════════════════════════════════════════════
   Correr en esta base y también en la de Tango / POS (cambiar con USE).
   Qué mirar: tablas de renglones de factura o de promociones que tengan
   N_COMP o un ID de comprobante + artículo + algo de promo / bonificación.
   Pasar el resultado para armar el cruce.
   ═══════════════════════════════════════════════════════════════════════════ */
SELECT c.TABLE_SCHEMA, c.TABLE_NAME, c.COLUMN_NAME, c.DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE c.TABLE_NAME  LIKE '%PROMO%'
   OR c.COLUMN_NAME LIKE '%PROMO%'
   OR c.COLUMN_NAME LIKE '%BONIF%'
   OR c.COLUMN_NAME LIKE '%DESCUENTO%'
   OR c.COLUMN_NAME LIKE '%DTO%'
ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   4) El ticket testigo en las tablas de Tango / POS
   ═══════════════════════════════════════════════════════════════════════════
   Correr en la MISMA base donde el bloque 3 devolvió las tablas AXV_ / CTA_.

   Testigo: ABASTO (suc. 7), 2026-10-12, FAC B0022200099478, 'PROMO CREMA 100%',
   IMPORTE_TO 577.700, COSTO 25.800 en BI_PROMOCIONES (aparece UNA vez).
   Negocio dice que el POS aplicó 4 promos por 4 cremas.

   Qué mirar:
     4.1  ¿Cuánto da CANTIDAD para la promo de crema? Si da 4 → esta tabla
          resuelve el pedido directo (1 fila por comprobante × promo).
     4.2  ¿Cuántos renglones / qué CANTIDAD tienen las cremas? ¿Qué valen
          CANTIDAD_A / CANTIDAD_B? ¿La suma de (PRECIO_ORIGINAL - PRECIO_PROMOCION)
          o IMPORTE_PROMOCION da los 25.800 de COSTO?
     4.3  Igual que 4.2 en la vista sin sucursal, por si la CENTRAL viene vacía.
     4.4  El COD_PROMOCION que trae BI_PROMOCIONES, para ver si cruza por código.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @n_comp VARCHAR(30) = 'B0022200099478';
DECLARE @fecha  DATE        = '2026-10-12';

-- 4.1
SELECT a.*
FROM dbo.CTA_PROMOCION_CANTIDAD_APLICADA a WITH (NOLOCK)
WHERE LTRIM(RTRIM(a.N_COMP)) = @n_comp;

-- 4.2
SELECT v.T_COMP, v.N_COMP, v.FECHA_EMIS, v.NRO_SUCURS, v.N_RENGLON, v.COD_ARTICU,
       v.CANTIDAD, v.COD_TIPO_PROMOCION, v.DESC_TIPO_PROMOCION,
       v.COD_PROMOCION, v.DESC_PROMOCION,
       v.PRECIO_ORIGINAL, v.PRECIO_PROMOCION, v.IMPORTE_PROMOCION,
       v.CANTIDAD_A, v.CANTIDAD_B, v.CRITERIO_APLICACION, v.APLICA_ARTICULO_IGUAL_CODIGO,
       v.APLICA_PROMOCION_MEDIO_PAGO, v.DESC_TARJETA_BENEFICIO
FROM dbo.AXV_LIVE_CENTRAL_VENTAS_PROMOCIONES v WITH (NOLOCK)
WHERE v.FECHA_EMIS >= @fecha AND v.FECHA_EMIS < DATEADD(day, 1, @fecha)
  AND LTRIM(RTRIM(v.N_COMP)) = @n_comp
ORDER BY v.N_RENGLON;

-- 4.3
SELECT v.T_COMP, v.N_COMP, v.FECHA_EMIS, v.N_RENGLON, v.COD_ARTICU, v.CANTIDAD,
       v.COD_PROMOCION, v.DESC_PROMOCION,
       v.PRECIO_ORIGINAL, v.PRECIO_PROMOCION, v.IMPORTE_PROMOCION,
       v.CANTIDAD_A, v.CANTIDAD_B, v.CRITERIO_APLICACION
FROM dbo.AXV_LIVE_VENTAS_PROMOCIONES v WITH (NOLOCK)
WHERE v.FECHA_EMIS >= @fecha AND v.FECHA_EMIS < DATEADD(day, 1, @fecha)
  AND LTRIM(RTRIM(v.N_COMP)) = @n_comp
ORDER BY v.N_RENGLON;
GO

-- 4.4  Correr en POWER_BI_CONTROL (la base de BI_PROMOCIONES).
SELECT p.N_COMP, p.COD_PROMOCION, p.COD_PROMOCION_TARJETA, p.DESC_PROMOCION_TARJETA,
       p.BANCO, p.PRECIO_ORIGINAL, p.IMPORTE_TO, p.COSTO_PROMO_VENTAS,
       p.COSTO_PROMO_BANCARIA, p.COSTO
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.FECHA = '2026-10-12'
  AND p.NRO_SUCURSAL = 7
  AND p.N_COMP COLLATE DATABASE_DEFAULT = 'B0022200099478';
GO
