/* ═══════════════════════════════════════════════════════════════════════════
   valida_promo.sql — ¿Son confiables los números de UNA promoción puntual
   en la pestaña Rubros del dashboard de Promociones?

   Parametrizado para "Día del Maestro 2026" con lo que devolvió el bloque 0.
   Para otra promo: cambiar @promo_like / @promo_exact y las fechas.

   REQUISITO: correr primero diag_rubros.sql (bloques 3 y 4). Si el join no
   cierra a nivel general, no tiene sentido validar una promo en particular.

   Correr en la base del origen que se quiera validar:
       POWER_BI_CONTROL              (Argentina)
       POWER_BI_CONTROL_FRANQUICIAS  (Franquicias)
   ═══════════════════════════════════════════════════════════════════════════ */


/* ═══════════════════════════════════════════════════════════════════════════
   0) NOMBRE EXACTO DE LA PROMO   —  ✅ YA CORRIDO (2026-09-14)
   ═══════════════════════════════════════════════════════════════════════════
   Resultado, quedándonos con lo de 2026:

     PROMO DIA DEL MAESTRO                              3909 tickets  $476.837.726
     PROMO DIA DEL MAESTRO / CUENTA DNI - PROVINCIA       26 tickets  $  2.750.430
     PROMO DIA DEL MAESTRO / SANTANDER REC. SORPRESA      21 tickets  $  2.894.000
     PROMO DIA DEL MAESTRO / SANTANDER REC. CART GRAL      4 tickets  $    425.160
     PROMO DIA DEL MAESTRO / TARJETA NARANJA               1 ticket   $     79.030
     PROMO DIA DEL MAESTRO / BANCO NACION                  1 ticket   $     38.130

   TRES COSAS QUE SALEN DE ACÁ:

   a) filas = tickets (3909 = 3909) en la promo principal. Cuando un ticket
      combina dos promos, el modelo NO crea dos renglones: concatena los
      nombres con ' / '. Por eso hay una fila por ticket.

   b) ⚠️ EL FILTRO DEL TABLERO DEJA AFUERA LAS COMBINADAS. El selector filtra
      por igualdad exacta, así que elegir "PROMO DIA DEL MAESTRO" pierde los
      53 tickets combinados ($6.186.750, ~1,3% del total de la promo).
      El bloque 1 mide exactamente cuánto.

   c) ⚠️ EL RANGO REAL ES 2026-08-31 a 2026-09-11, no el mes de septiembre.
      Si mirás "mes actual" en el tablero, el 31/08 queda afuera.

   Además: la promo cambia de nombre todos los años
   (2026 "PROMO DIA DEL MAESTRO", 2024 "4x3 MAESTRO", 2023/2020 "PROMO 4X3
   MAESTRO") y en 2025 no aparece ninguna. Eso rompe la columna comparativa
   — ver bloque 8.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @promo_like NVARCHAR(200) = N'%MAESTRO%';

SELECT p.DESC_PROMOCION_TARJETA,
       p.BANCO,
       MIN(p.FECHA)              AS primera_fecha,
       MAX(p.FECHA)              AS ultima_fecha,
       COUNT(*)                  AS filas,
       COUNT(DISTINCT p.N_COMP)  AS tickets,
       SUM(p.IMPORTE_TO)         AS importe_to_sumado
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.DESC_PROMOCION_TARJETA LIKE @promo_like
GROUP BY p.DESC_PROMOCION_TARJETA, p.BANCO
ORDER BY filas DESC;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   1) CUÁNTO PIERDE EL FILTRO EXACTO DEL TABLERO
   ═══════════════════════════════════════════════════════════════════════════
   Compara las dos formas de definir "la promo":
     · exacto → lo que hace hoy el selector del tablero.
     · like   → el universo real, incluyendo las combinadas con banco.

   Qué mirar:
     · pct_perdido en tickets y en importe. Si es ~1-2%, el número del tablero
       es utilizable con una nota al pie. Si fuera mayor, habría que cambiar el
       filtro de la promo de '=' a LIKE.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @promo_exact NVARCHAR(200) = N'PROMO DIA DEL MAESTRO';
DECLARE @promo_like  NVARCHAR(200) = N'%DIA DEL MAESTRO%';
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-12';   -- exclusivo

SELECT
    SUM(CASE WHEN p.DESC_PROMOCION_TARJETA = @promo_exact THEN 1 ELSE 0 END)          AS tickets_exacto,
    COUNT(*)                                                                          AS tickets_like,
    COUNT(*) - SUM(CASE WHEN p.DESC_PROMOCION_TARJETA = @promo_exact THEN 1 ELSE 0 END) AS tickets_perdidos,
    CAST(100.0 * (COUNT(*) - SUM(CASE WHEN p.DESC_PROMOCION_TARJETA = @promo_exact THEN 1 ELSE 0 END))
         / NULLIF(COUNT(*),0) AS DECIMAL(5,2))                                        AS pct_tickets_perdidos,
    SUM(CASE WHEN p.DESC_PROMOCION_TARJETA = @promo_exact THEN p.IMPORTE_TO ELSE 0 END) AS importe_exacto,
    SUM(p.IMPORTE_TO)                                                                 AS importe_like,
    CAST(100.0 * SUM(CASE WHEN p.DESC_PROMOCION_TARJETA <> @promo_exact THEN p.IMPORTE_TO ELSE 0 END)
         / NULLIF(SUM(p.IMPORTE_TO),0) AS DECIMAL(5,2))                               AS pct_importe_perdido
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.FECHA >= @desde AND p.FECHA < @hasta
  AND p.DESC_PROMOCION_TARJETA LIKE @promo_like;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   2) CARDINALIDAD Y CONVIVENCIA CON OTRAS PROMOS
   ═══════════════════════════════════════════════════════════════════════════
   El bloque 0 ya sugiere que hay una fila por ticket. Esto lo confirma sobre
   TODA la tabla en el período (no sólo esta promo), que es lo que importa para
   el dedupe del tablero.

   Qué mirar:
     · filas_por_ticket = 1,00 → el dedupe del CTE es inocuo (red de seguridad).
       Y, sobre todo, el KPI "Fact. con Promo" del Resumen NO está inflado:
       SUM(IMPORTE_TO) sobre todas las filas es correcto.
     · filas_por_ticket > 1 → hay tickets con varios renglones. Revisar el
       bloque 2.1 de diag_rubros.sql para ver qué los diferencia.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-12';

SELECT COUNT(*)                                                     AS filas,
       COUNT(DISTINCT CONCAT(CAST(p.FECHA AS DATE), '|',
                             p.NRO_SUCURSAL, '|', p.N_COMP))        AS tickets,
       CAST(COUNT(*) * 1.0
            / NULLIF(COUNT(DISTINCT CONCAT(CAST(p.FECHA AS DATE), '|',
                                           p.NRO_SUCURSAL, '|', p.N_COMP)),0)
            AS DECIMAL(10,2))                                       AS filas_por_ticket
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.FECHA >= @desde AND p.FECHA < @hasta;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   3) MATCH CON LAS LÍNEAS DE VENTA — ¿los tickets encuentran sus artículos?
   ═══════════════════════════════════════════════════════════════════════════
   Qué mirar:
     · pct_match ≥ 95%  →  confiable.
     · pct_match 80-95% →  usable, pero el % C/Promo del rubro queda
       subestimado en esa proporción.
     · pct_match < 80%  →  NO confiable. Ver bloque 3.3 de diag_rubros.sql
       para inspeccionar los N_COMP que no matchean.

     · importe_no_matcheado: si los tickets sin match son pocos pero grandes,
       el sesgo es peor de lo que sugiere el porcentaje.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @promo_like NVARCHAR(200) = N'%DIA DEL MAESTRO%';
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-12';

WITH tk AS (
    SELECT p.FECHA, p.NRO_SUCURSAL,
           p.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP,
           MAX(p.IMPORTE_TO) AS importe_ticket
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
      AND p.DESC_PROMOCION_TARJETA LIKE @promo_like
    GROUP BY p.FECHA, p.NRO_SUCURSAL, p.N_COMP COLLATE DATABASE_DEFAULT
),
ln AS (
    SELECT s.FECHA, s.NRO_SUCURS,
           s.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP,
           SUM(s.CANTIDAD) AS unidades,
           SUM(s.IMPORTE)  AS importe_lineas
    FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
    WHERE s.FECHA >= @desde AND s.FECHA < @hasta
      AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
    GROUP BY s.FECHA, s.NRO_SUCURS, s.N_COMP COLLATE DATABASE_DEFAULT
)
SELECT COUNT(*)                                                           AS tickets_promo,
       SUM(CASE WHEN ln.N_COMP IS NOT NULL THEN 1 ELSE 0 END)             AS con_lineas,
       CAST(100.0 * SUM(CASE WHEN ln.N_COMP IS NOT NULL THEN 1 ELSE 0 END)
            / NULLIF(COUNT(*),0) AS DECIMAL(5,2))                         AS pct_match,
       SUM(CASE WHEN ln.N_COMP IS NULL THEN tk.importe_ticket ELSE 0 END) AS importe_no_matcheado,
       SUM(tk.importe_ticket)                                             AS importe_total_promo,
       SUM(ISNULL(ln.unidades,0))                                         AS unidades_matcheadas
FROM tk
LEFT JOIN ln ON ln.FECHA      = tk.FECHA
            AND ln.NRO_SUCURS = tk.NRO_SUCURSAL
            AND ln.N_COMP     = tk.N_COMP;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   4) CUADRE PLATA A PLATA — LA PRUEBA DURA
   ═══════════════════════════════════════════════════════════════════════════
   Para los MISMOS tickets: lo que dice la cabecera (BI_PROMOCIONES) contra lo
   que suman sus líneas (BI_SALES_SUCURSALES).

   Qué mirar:
     · dif_pct dentro de ±5%       →  el join trae las líneas correctas.
     · imp_lineas MUCHO menor      →  faltan líneas.
     · imp_lineas MUCHO mayor      →  el join DUPLICA. Es un bug: volver al
                                      bloque 4 de diag_rubros.sql.
     · unid_por_ticket             →  ¿tiene sentido? (2-4 unidades por ticket
                                      es razonable; 0,5 o 40 no).

   Nunca da exacto: las líneas excluyen CONCEPTO y PACKAGING. Importa el orden
   de magnitud.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @promo_like NVARCHAR(200) = N'%DIA DEL MAESTRO%';
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-12';

WITH tk AS (
    SELECT p.FECHA, p.NRO_SUCURSAL,
           p.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP,
           MAX(p.IMPORTE_TO) AS imp_ticket
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
      AND p.DESC_PROMOCION_TARJETA LIKE @promo_like
    GROUP BY p.FECHA, p.NRO_SUCURSAL, p.N_COMP COLLATE DATABASE_DEFAULT
),
ln AS (
    SELECT s.FECHA, s.NRO_SUCURS,
           s.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP,
           SUM(s.CANTIDAD) AS unidades,
           SUM(s.IMPORTE)  AS importe
    FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
    WHERE s.FECHA >= @desde AND s.FECHA < @hasta
      AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
    GROUP BY s.FECHA, s.NRO_SUCURS, s.N_COMP COLLATE DATABASE_DEFAULT
)
SELECT COUNT(*)                                          AS tickets_con_match,
       SUM(tk.imp_ticket)                                AS imp_cabecera,
       SUM(ln.importe)                                   AS imp_lineas,
       SUM(ln.unidades)                                  AS unidades,
       CAST(100.0 * (SUM(ln.importe) - SUM(tk.imp_ticket))
            / NULLIF(SUM(tk.imp_ticket),0) AS DECIMAL(6,2)) AS dif_pct,
       CAST(SUM(ln.unidades) * 1.0
            / NULLIF(COUNT(*),0) AS DECIMAL(10,2))          AS unid_por_ticket
FROM tk
INNER JOIN ln ON ln.FECHA      = tk.FECHA
             AND ln.NRO_SUCURS = tk.NRO_SUCURSAL
             AND ln.N_COMP     = tk.N_COMP;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   5) DESGLOSE POR RUBRO — replica de lo que muestra el tablero
   ═══════════════════════════════════════════════════════════════════════════
   Misma lógica que PromocionesDB::getUnidadesPorRubro() con filtro de promo.
   Devuelve las dos versiones para que se vea el efecto de las combinadas:

     · unid_cp_exacto → lo que muestra HOY el tablero (filtro por igualdad).
     · unid_cp_like   → el universo real de la promo.

   Qué mirar:
     · unid_cp_exacto tiene que coincidir con la pantalla. Si no coincide, el
       problema está en el PHP, no en el dato.
     · La brecha entre las dos columnas es lo que pierde el filtro exacto.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @promo_exact NVARCHAR(200) = N'PROMO DIA DEL MAESTRO';
DECLARE @promo_like  NVARCHAR(200) = N'%DIA DEL MAESTRO%';
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-12';

WITH tk AS (
    SELECT p.FECHA, p.NRO_SUCURSAL,
           p.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP,
           MAX(CASE WHEN p.DESC_PROMOCION_TARJETA = @promo_exact    THEN 1 ELSE 0 END) AS cp_exacto,
           MAX(CASE WHEN p.DESC_PROMOCION_TARJETA LIKE @promo_like  THEN 1 ELSE 0 END) AS cp_like
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
    GROUP BY p.FECHA, p.NRO_SUCURSAL, p.N_COMP COLLATE DATABASE_DEFAULT
)
SELECT s.RUBRO,
       SUM(s.CANTIDAD)                                                     AS unid_total,
       SUM(CASE WHEN ISNULL(tk.cp_exacto,0)=1 THEN s.CANTIDAD ELSE 0 END)  AS unid_cp_exacto,
       SUM(CASE WHEN ISNULL(tk.cp_like,0)=1   THEN s.CANTIDAD ELSE 0 END)  AS unid_cp_like,
       CAST(100.0 * SUM(CASE WHEN ISNULL(tk.cp_exacto,0)=1 THEN s.CANTIDAD ELSE 0 END)
            / NULLIF(SUM(s.CANTIDAD),0) AS DECIMAL(5,2))                   AS pct_penetracion,
       SUM(CASE WHEN ISNULL(tk.cp_exacto,0)=1 THEN s.IMPORTE ELSE 0 END)   AS fact_cpromo
FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
LEFT JOIN tk ON tk.FECHA        = s.FECHA
            AND tk.NRO_SUCURSAL = s.NRO_SUCURS
            AND tk.N_COMP       = s.N_COMP COLLATE DATABASE_DEFAULT
WHERE s.FECHA >= @desde AND s.FECHA < @hasta
  AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
GROUP BY s.RUBRO
ORDER BY unid_cp_exacto DESC;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   6) TICKETS REALES, UNO POR UNO — la verificación definitiva
   ═══════════════════════════════════════════════════════════════════════════
   Agarrá 3 o 4 y comparalos contra Tango o el ticket físico. Si los artículos
   y los importes son los que se vendieron, el reporte es confiable.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @promo_like NVARCHAR(200) = N'%DIA DEL MAESTRO%';
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-12';

-- 6.1) 10 tickets de la promo con el total de sus líneas al lado.
WITH tk AS (
    SELECT TOP 10
           p.FECHA, p.NRO_SUCURSAL,
           p.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP,
           MAX(p.IMPORTE_TO)                 AS importe_ticket,
           MAX(p.DESC_PROMOCION_TARJETA)     AS promo
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
      AND p.DESC_PROMOCION_TARJETA LIKE @promo_like
    GROUP BY p.FECHA, p.NRO_SUCURSAL, p.N_COMP COLLATE DATABASE_DEFAULT
    ORDER BY MAX(p.IMPORTE_TO) DESC
)
SELECT tk.FECHA, tk.NRO_SUCURSAL, tk.N_COMP, tk.promo,
       tk.importe_ticket,
       COUNT(s.N_COMP)                    AS lineas,
       SUM(s.CANTIDAD)                    AS unidades,
       SUM(s.IMPORTE)                     AS imp_lineas,
       SUM(s.IMPORTE) - tk.importe_ticket AS diferencia
FROM tk
LEFT JOIN dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
       ON s.FECHA      = tk.FECHA
      AND s.NRO_SUCURS = tk.NRO_SUCURSAL
      AND s.N_COMP COLLATE DATABASE_DEFAULT = tk.N_COMP
      AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
GROUP BY tk.FECHA, tk.NRO_SUCURSAL, tk.N_COMP, tk.promo, tk.importe_ticket
ORDER BY tk.importe_ticket DESC;
GO

-- 6.2) Detalle artículo por artículo de UN ticket. Completar con 6.1.
DECLARE @fecha  DATE          = '2026-09-11';     -- ⚠️ del resultado de 6.1
DECLARE @suc    INT           = 0;                -- ⚠️ del resultado de 6.1
DECLARE @ncomp  NVARCHAR(50)  = N'';              -- ⚠️ del resultado de 6.1

SELECT 'PAGOS' AS bloque, p.FECHA, p.NRO_SUCURSAL, p.N_COMP, p.T_COMP,
       p.BANCO, p.DESC_PROMOCION_TARJETA,
       p.IMPORTE_TO, p.COSTO, p.COSTO_PROMO_BANCARIA, p.COSTO_PROMO_VENTAS
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.FECHA = @fecha AND p.NRO_SUCURSAL = @suc
  AND p.N_COMP COLLATE DATABASE_DEFAULT = @ncomp COLLATE DATABASE_DEFAULT;

SELECT 'ARTICULOS' AS bloque, s.FECHA, s.NRO_SUCURS, s.N_COMP,
       s.RUBRO, s.CATEGORIA, s.COD_ARTICU, s.CANTIDAD, s.IMPORTE
FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
WHERE s.FECHA = @fecha AND s.NRO_SUCURS = @suc
  AND s.N_COMP COLLATE DATABASE_DEFAULT = @ncomp COLLATE DATABASE_DEFAULT
ORDER BY s.RUBRO, s.COD_ARTICU;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   7) ¿ESTÁ COMPLETO EL DATO? — carga por día, las dos tablas
   ═══════════════════════════════════════════════════════════════════════════
   La promo terminó el 11/09 y hoy es 14/09. Antes de sacar conclusiones:

   Qué mirar:
     · Que la promo tenga tickets TODOS los días del 31/08 al 11/09.
     · Que las líneas (7.1) lleguen al menos hasta el 11/09. Si BI_SALES_
       SUCURSALES viene más atrasada que BI_PROMOCIONES, el % C/Promo de los
       últimos días da bajo por falta de dato, no por falta de venta.
     · Que la cantidad de sucursales sea estable día a día.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @promo_like NVARCHAR(200) = N'%DIA DEL MAESTRO%';
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-12';

SELECT CAST(p.FECHA AS DATE)          AS dia,
       COUNT(DISTINCT p.N_COMP)       AS tickets,
       COUNT(DISTINCT p.NRO_SUCURSAL) AS sucursales,
       SUM(p.IMPORTE_TO)              AS importe
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.FECHA >= @desde AND p.FECHA < @hasta
  AND p.DESC_PROMOCION_TARJETA LIKE @promo_like
GROUP BY CAST(p.FECHA AS DATE)
ORDER BY dia;
GO

-- 7.1) Lado líneas: ¿hasta qué día llegó la carga?
--      ⚠️ La exclusión de CONCEPTO/PACKAGING es OBLIGATORIA acá. Sin ella las
--      unidades dan absurdamente negativas (esos rubros guardan importes y
--      ajustes, no unidades). La primera versión de esta consulta no la tenía
--      y devolvía cosas como -801.411 unidades en un día.
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-15';

SELECT CAST(s.FECHA AS DATE)        AS dia,
       COUNT(*)                     AS lineas,
       COUNT(DISTINCT s.NRO_SUCURS) AS sucursales,
       SUM(s.CANTIDAD)              AS unidades
FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
WHERE s.FECHA >= @desde AND s.FECHA < @hasta
  AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
GROUP BY CAST(s.FECHA AS DATE)
ORDER BY dia;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   8) ⚠️ LA COLUMNA COMPARATIVA — por qué "Var. Unid." no sirve para esta promo
   ═══════════════════════════════════════════════════════════════════════════
   El bloque 0 no devolvió NINGUNA promo con "MAESTRO" en 2025, y el nombre
   cambia todos los años. Como el tablero compara por igualdad de nombre contra
   el mismo período del año anterior, "Unid. C/P prev" va a dar 0 y la
   variación +100%, sin significado.

   Esta consulta lista qué promos SÍ existieron en el mismo período de 2025,
   para elegir a mano el equivalente si se quiere comparar de verdad.

   Qué mirar:
     · Si hay una promo de septiembre 2025 con volumen parecido, ésa es la
       comparable — pero hay que mirarla aparte, el tablero no la va a cruzar.
     · Si no hay nada, la conclusión es que la comparación interanual de esta
       promo no existe. Leer sólo la columna del período actual.
   ═══════════════════════════════════════════════════════════════════════════ */
SELECT p.DESC_PROMOCION_TARJETA,
       p.BANCO,
       MIN(p.FECHA)             AS primera_fecha,
       MAX(p.FECHA)             AS ultima_fecha,
       COUNT(DISTINCT p.N_COMP) AS tickets,
       SUM(p.IMPORTE_TO)        AS importe
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.FECHA >= '2025-08-25' AND p.FECHA < '2025-09-20'
  AND p.DESC_PROMOCION_TARJETA <> 'SIN PROMO'
GROUP BY p.DESC_PROMOCION_TARJETA, p.BANCO
ORDER BY tickets DESC;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   11) "PROMOS USADAS" — validar el conteo por separador ' / '
   ═══════════════════════════════════════════════════════════════════════════
   La columna "Promos Usadas" del Detalle por Sucursal cuenta promociones
   APLICADAS, no renglones. Como el modelo concatena las promos de un mismo
   ticket en una sola etiqueta separadas por ' / ', el conteo es
   (separadores + 1). Este bloque valida los dos supuestos de esa cuenta.

   11.1) ¿El separador ' / ' es confiable?
   Lista las etiquetas que contienen ' / ' con la cantidad de promos que se les
   está asignando.

   Qué mirar:
     · Que cada fila con promos=2 sea efectivamente DOS promos, no un nombre que
       casualmente lleva ' / ' en el medio.
     · Ojo con nombres tipo '(DM 25/8 AL 14/9)': esos llevan '/' SIN espacios,
       así que no cuentan como separador. Es correcto.
     · Si aparece una promo con un nombre que legítimamente incluye ' / ', el
       conteo la estaría partiendo mal — avisar.
   ═══════════════════════════════════════════════════════════════════════════ */
SELECT p.DESC_PROMOCION_TARJETA,
       1 + (LEN(p.DESC_PROMOCION_TARJETA + '.')
            - LEN(REPLACE(p.DESC_PROMOCION_TARJETA, ' / ', '') + '.')) / 3 AS promos_contadas,
       COUNT(*) AS filas
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.DESC_PROMOCION_TARJETA LIKE '% / %'
  AND p.FECHA >= '2026-01-01'
GROUP BY p.DESC_PROMOCION_TARJETA
ORDER BY promos_contadas DESC, filas DESC;
GO

/* 11.2) ¿Cuánto pesa el doble conteo por ticket multi-renglón?
   promos_usadas suma por RENGLÓN. Si un ticket se pagó con dos tarjetas y las
   dos tienen promo, cuenta las dos (son dos aplicaciones). Este bloque mide
   cuántas de esas hay, y cuánto cambiaría el número si se contara una sola vez
   por combinación ticket+promo.

   Qué mirar:
     · dif_pct ≈ 0  →  el doble conteo es marginal, la columna se lee directo.
     · dif_pct alto →  conviene revisar si "aplicaciones" o "tickets con esa
                       promo" es lo que se quiere mostrar.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-12';

WITH pc AS (
    SELECT p.FECHA, p.NRO_SUCURSAL, p.N_COMP, p.DESC_PROMOCION_TARJETA,
           1 + (LEN(p.DESC_PROMOCION_TARJETA + '.')
                - LEN(REPLACE(p.DESC_PROMOCION_TARJETA, ' / ', '') + '.')) / 3 AS promos
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
      AND p.DESC_PROMOCION_TARJETA <> 'SIN PROMO'
)
SELECT
    (SELECT SUM(promos) FROM pc)                                        AS por_renglon,
    (SELECT SUM(promos) FROM (
        SELECT DISTINCT FECHA, NRO_SUCURSAL, N_COMP, DESC_PROMOCION_TARJETA, promos FROM pc
     ) d)                                                               AS por_ticket_promo,
    (SELECT COUNT(*) FROM pc) - (SELECT COUNT(*) FROM (
        SELECT DISTINCT FECHA, NRO_SUCURSAL, N_COMP, DESC_PROMOCION_TARJETA FROM pc
     ) d2)                                                              AS renglones_duplicados;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   9) ¿QUÉ ES "SIN RUBRO"?   — pendiente
   ═══════════════════════════════════════════════════════════════════════════
   El bloque 5 devolvió 1030 unidades bajo RUBRO = 'SIN RUBRO', con CERO
   unidades en tickets de la promo. Ese cero es lo llamativo: si fueran
   artículos normales, ~50% debería haber caído en tickets con promo, igual que
   el resto. Un cero exacto significa que esas líneas están sistemáticamente
   fuera del universo de tickets de BI_PROMOCIONES.

   Hipótesis a descartar: otro canal (ecommerce, mayorista), ajustes de stock,
   o artículos sin clasificar en el maestro.

   Qué mirar:
     · Si son pocas sucursales o un COD_ARTICU repetido → es un caso puntual,
       ignorable.
     · Si el N_COMP tiene otro formato (otra letra, otra longitud) → son de
       otro circuito y por eso nunca matchean.
     · 1030 sobre ~18.700 unidades totales es 5,5% del denominador: si no son
       ventas de local, están diluyendo el % C/Promo de todos los rubros.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-12';

SELECT TOP 30
       s.NRO_SUCURS, s.COD_ARTICU, s.CATEGORIA,
       COUNT(*)              AS lineas,
       COUNT(DISTINCT s.N_COMP) AS tickets,
       MIN(s.N_COMP)         AS ejemplo_n_comp,
       SUM(s.CANTIDAD)       AS unidades,
       SUM(s.IMPORTE)        AS importe
FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
WHERE s.FECHA >= @desde AND s.FECHA < @hasta
  AND s.RUBRO = 'SIN RUBRO'
GROUP BY s.NRO_SUCURS, s.COD_ARTICU, s.CATEGORIA
ORDER BY unidades DESC;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   10) ⚠️ ¿ESTÁ INFLADO EL KPI "Fact. con Promo" DEL RESUMEN?   — pendiente
   ═══════════════════════════════════════════════════════════════════════════
   El bloque 2 dio filas_por_ticket = 1,07 sobre toda la tabla: 10.259 filas
   para 9.552 tickets, o sea ~707 tickets con más de un renglón.

   (En la promo Día del Maestro no pasa: ahí filas = tickets exacto. El tema es
   de OTRAS promos del período.)

   Importa porque getKPIsBulk() hace SUM(IMPORTE_TO) sobre TODAS las filas. Si
   IMPORTE_TO repite el total del ticket en cada renglón, esos 707 tickets se
   cuentan dos veces y el KPI del Resumen queda inflado.

   Qué mirar:
     · dif_pct ≈ 0  →  IMPORTE_TO es la PORCIÓN de cada medio de pago. El KPI
                       está bien, no hay nada que hacer.
     · dif_pct > 0  →  IMPORTE_TO REPITE el total del ticket. El KPI del
                       Resumen está inflado en ese porcentaje y hay que
                       corregir getKPIsBulk() para deduplicar por ticket.

   El segundo SELECT muestra 10 tickets multi-renglón para verlo a ojo.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @desde DATE = '2026-08-31';
DECLARE @hasta DATE = '2026-09-12';

WITH tk AS (
    SELECT p.FECHA, p.NRO_SUCURSAL, p.N_COMP,
           COUNT(*)          AS renglones,
           SUM(p.IMPORTE_TO) AS imp_sum,
           MAX(p.IMPORTE_TO) AS imp_max
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
    GROUP BY p.FECHA, p.NRO_SUCURSAL, p.N_COMP
)
SELECT SUM(imp_sum)                                        AS como_lo_suma_el_kpi,
       SUM(imp_max)                                        AS deduplicado_por_ticket,
       SUM(imp_sum) - SUM(imp_max)                         AS diferencia,
       CAST(100.0 * (SUM(imp_sum) - SUM(imp_max))
            / NULLIF(SUM(imp_max),0) AS DECIMAL(6,2))      AS dif_pct,
       SUM(CASE WHEN renglones > 1 THEN 1 ELSE 0 END)      AS tickets_multi_renglon
FROM tk;

-- Los 10 tickets con más renglones, para ver qué los diferencia.
DECLARE @d2 DATE = '2026-08-31';
DECLARE @h2 DATE = '2026-09-12';

SELECT TOP 10 WITH TIES
       p.FECHA, p.NRO_SUCURSAL, p.N_COMP, p.T_COMP,
       p.BANCO, p.DESC_PROMOCION_TARJETA, p.IMPORTE_TO
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
INNER JOIN (
    SELECT TOP 10 FECHA, NRO_SUCURSAL, N_COMP
    FROM dbo.BI_PROMOCIONES WITH (NOLOCK)
    WHERE FECHA >= @d2 AND FECHA < @h2
    GROUP BY FECHA, NRO_SUCURSAL, N_COMP
    HAVING COUNT(*) > 1
    ORDER BY COUNT(*) DESC
) t ON t.FECHA = p.FECHA AND t.NRO_SUCURSAL = p.NRO_SUCURSAL AND t.N_COMP = p.N_COMP
WHERE p.FECHA >= @d2 AND p.FECHA < @h2
ORDER BY p.FECHA, p.N_COMP;
GO
