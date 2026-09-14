/* ═══════════════════════════════════════════════════════════════════════════
   diag_rubros.sql — Validación del cruce BI_PROMOCIONES × BI_SALES_SUCURSALES
   para el reporte "Unidades x Rubro" del dashboard de Promociones.

   Correr en la base del origen que se quiera validar:
       POWER_BI_CONTROL              (Argentina)
       POWER_BI_CONTROL_FRANQUICIAS  (Franquicias)

   Ajustar el mes de prueba en las variables del bloque 0.
   Los bloques 3 y 4 son los que DECIDEN si el reporte es viable.
   ═══════════════════════════════════════════════════════════════════════════ */

-- ── 0) Parámetros de la prueba ────────────────────────────────────────────
-- Usar un mes CERRADO (no el mes en curso) para que los totales sean estables.
DECLARE @desde DATE = '2026-07-01';
DECLARE @hasta DATE = '2026-08-01';   -- exclusivo
GO


/* ═══════════════════════════════════════════════════════════════════════════
   1) ESQUEMA — nombres de columna y COLLATION
   ═══════════════════════════════════════════════════════════════════════════
   Qué mirar:
     · Que BI_SALES_SUCURSALES tenga N_COMP, NRO_SUCURS, FECHA, RUBRO, CANTIDAD, IMPORTE.
     · Si BI_SALES_SUCURSALES tiene T_COMP (para poder filtrar 'FAC' si hace falta).
     · La COLLATION de N_COMP en las dos tablas. Si difieren (BI_PROMOCIONES suele
       ser Latin1_General_BIN), el JOIN necesita COLLATE DATABASE_DEFAULT de ambos
       lados — que es lo que hace la query definitiva.
   ═══════════════════════════════════════════════════════════════════════════ */
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLLATION_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME IN ('BI_SALES_SUCURSALES', 'BI_PROMOCIONES')
ORDER BY TABLE_NAME, ORDINAL_POSITION;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   2) CARDINALIDAD — ¿cuántas filas por ticket tiene BI_PROMOCIONES?
   ═══════════════════════════════════════════════════════════════════════════
   Qué mirar:
     · filas > tickets  →  hay varias filas por ticket (un renglón por medio de
       pago / promo). Confirma que el DEDUPE previo al join es OBLIGATORIO:
       sin él, las unidades de BI_SALES_SUCURSALES se multiplicarían.
     · filas = tickets  →  la tabla ya es un renglón por ticket (el dedupe igual
       no molesta, queda como red de seguridad).
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @desde DATE = '2026-07-01';
DECLARE @hasta DATE = '2026-08-01';

SELECT COUNT(*)                                                   AS filas,
       COUNT(DISTINCT CAST(FECHA AS DATE))                        AS dias,
       COUNT(DISTINCT N_COMP)                                     AS n_comp_distintos,
       COUNT(DISTINCT CONCAT(CAST(FECHA AS DATE), '|',
                             NRO_SUCURSAL, '|', N_COMP))          AS tickets_unicos,
       CAST(COUNT(*) * 1.0 /
            NULLIF(COUNT(DISTINCT CONCAT(CAST(FECHA AS DATE), '|',
                                         NRO_SUCURSAL, '|', N_COMP)), 0)
            AS DECIMAL(10,2))                                     AS filas_por_ticket
FROM dbo.BI_PROMOCIONES WITH (NOLOCK)
WHERE FECHA >= @desde AND FECHA < @hasta;
GO

-- 2.1) Ejemplo concreto: los 10 tickets con más filas, para ver qué las diferencia.
DECLARE @desde DATE = '2026-07-01';
DECLARE @hasta DATE = '2026-08-01';

SELECT TOP 10
       CAST(p.FECHA AS DATE) AS fecha, p.NRO_SUCURSAL, p.N_COMP, p.T_COMP,
       COUNT(*) AS filas,
       COUNT(DISTINCT p.BANCO) AS bancos,
       COUNT(DISTINCT p.DESC_PROMOCION_TARJETA) AS promos
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.FECHA >= @desde AND p.FECHA < @hasta
GROUP BY CAST(p.FECHA AS DATE), p.NRO_SUCURSAL, p.N_COMP, p.T_COMP
ORDER BY filas DESC;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   3) TASA DE MATCH — ¿los tickets de BI_PROMOCIONES existen en las líneas?
   ═══════════════════════════════════════════════════════════════════════════
   ESTE ES EL BLOQUE QUE DECIDE.

   Qué mirar:
     · pct_match ALTO (>90%)  →  el join por FECHA + NRO_SUCURS + N_COMP cierra.
       Se puede implementar el reporte tal como está planeado.
     · pct_match BAJO         →  el criterio de join está mal. Probar las variantes
       3.1 (sin FECHA) y 3.2 (sólo T_COMP='FAC') antes de descartar nada.
       Si ninguna levanta, el reporte NO puede cortar por promoción y hay que
       replantearlo — avisar antes de seguir.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @desde DATE = '2026-07-01';
DECLARE @hasta DATE = '2026-08-01';

WITH tk AS (
    SELECT DISTINCT
           CAST(p.FECHA AS DATE)                  AS FECHA,
           p.NRO_SUCURSAL                         AS NRO_SUC,
           p.N_COMP COLLATE DATABASE_DEFAULT      AS N_COMP
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
),
ln AS (
    SELECT DISTINCT
           CAST(s.FECHA AS DATE)                  AS FECHA,
           s.NRO_SUCURS                           AS NRO_SUC,
           s.N_COMP COLLATE DATABASE_DEFAULT      AS N_COMP
    FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
    WHERE s.FECHA >= @desde AND s.FECHA < @hasta
)
SELECT COUNT(*)                                                        AS tickets_promo,
       SUM(CASE WHEN ln.N_COMP IS NOT NULL THEN 1 ELSE 0 END)          AS con_lineas,
       CAST(100.0 * SUM(CASE WHEN ln.N_COMP IS NOT NULL THEN 1 ELSE 0 END)
            / NULLIF(COUNT(*), 0) AS DECIMAL(5,2))                     AS pct_match
FROM tk
LEFT JOIN ln ON ln.FECHA   = tk.FECHA
            AND ln.NRO_SUC = tk.NRO_SUC
            AND ln.N_COMP  = tk.N_COMP;
GO

-- 3.1) Variante SIN FECHA en el join (por si las fechas difieren entre tablas).
--      Si esta da MUCHO mejor que 3), el problema es la fecha, no el N_COMP.
DECLARE @desde DATE = '2026-07-01';
DECLARE @hasta DATE = '2026-08-01';

WITH tk AS (
    SELECT DISTINCT p.NRO_SUCURSAL AS NRO_SUC, p.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
),
ln AS (
    SELECT DISTINCT s.NRO_SUCURS AS NRO_SUC, s.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP
    FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
    WHERE s.FECHA >= @desde AND s.FECHA < @hasta
)
SELECT COUNT(*)                                                AS tickets_promo,
       SUM(CASE WHEN ln.N_COMP IS NOT NULL THEN 1 ELSE 0 END)  AS con_lineas,
       CAST(100.0 * SUM(CASE WHEN ln.N_COMP IS NOT NULL THEN 1 ELSE 0 END)
            / NULLIF(COUNT(*), 0) AS DECIMAL(5,2))             AS pct_match_sin_fecha
FROM tk
LEFT JOIN ln ON ln.NRO_SUC = tk.NRO_SUC AND ln.N_COMP = tk.N_COMP;
GO

-- 3.2) Sólo facturas (T_COMP='FAC'). Las NC/devoluciones pueden no estar en líneas.
DECLARE @desde DATE = '2026-07-01';
DECLARE @hasta DATE = '2026-08-01';

WITH tk AS (
    SELECT DISTINCT CAST(p.FECHA AS DATE) AS FECHA, p.NRO_SUCURSAL AS NRO_SUC,
           p.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
      AND p.T_COMP COLLATE Latin1_General_BIN = 'FAC'
),
ln AS (
    SELECT DISTINCT CAST(s.FECHA AS DATE) AS FECHA, s.NRO_SUCURS AS NRO_SUC,
           s.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP
    FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
    WHERE s.FECHA >= @desde AND s.FECHA < @hasta
)
SELECT COUNT(*)                                                AS tickets_fac,
       SUM(CASE WHEN ln.N_COMP IS NOT NULL THEN 1 ELSE 0 END)  AS con_lineas,
       CAST(100.0 * SUM(CASE WHEN ln.N_COMP IS NOT NULL THEN 1 ELSE 0 END)
            / NULLIF(COUNT(*), 0) AS DECIMAL(5,2))             AS pct_match_solo_fac
FROM tk
LEFT JOIN ln ON ln.FECHA = tk.FECHA AND ln.NRO_SUC = tk.NRO_SUC AND ln.N_COMP = tk.N_COMP;
GO

-- 3.3) Muestra de 20 N_COMP de promociones que NO matchean, para inspección visual
--      (¿formato distinto? ¿ceros a la izquierda? ¿prefijo de punto de venta?).
DECLARE @desde DATE = '2026-07-01';
DECLARE @hasta DATE = '2026-08-01';

WITH tk AS (
    SELECT DISTINCT CAST(p.FECHA AS DATE) AS FECHA, p.NRO_SUCURSAL AS NRO_SUC,
           p.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP, p.T_COMP COLLATE DATABASE_DEFAULT AS T_COMP
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
),
ln AS (
    SELECT DISTINCT CAST(s.FECHA AS DATE) AS FECHA, s.NRO_SUCURS AS NRO_SUC,
           s.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP
    FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
    WHERE s.FECHA >= @desde AND s.FECHA < @hasta
)
SELECT TOP 20 tk.*
FROM tk
LEFT JOIN ln ON ln.FECHA = tk.FECHA AND ln.NRO_SUC = tk.NRO_SUC AND ln.N_COMP = tk.N_COMP
WHERE ln.N_COMP IS NULL;
GO

-- 3.4) Y una muestra de N_COMP del lado de las líneas, para comparar formatos.
DECLARE @desde DATE = '2026-07-01';

SELECT TOP 20 CAST(s.FECHA AS DATE) AS FECHA, s.NRO_SUCURS, s.N_COMP, s.RUBRO, s.CANTIDAD
FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
WHERE s.FECHA >= @desde
ORDER BY s.FECHA;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   4) CONTROL DE SUMA CERO — el join no puede inflar unidades
   ═══════════════════════════════════════════════════════════════════════════
   Compara, por rubro:
     · directo    → SUM(CANTIDAD) sobre BI_SALES_SUCURSALES, sin join.
     · con_join   → lo mismo, pero pasando por el LEFT JOIN al CTE deduplicado.

   Qué mirar:
     · diff = 0 en TODAS las filas  →  el dedupe funciona, el join es seguro.
     · diff > 0 en alguna fila      →  el join está duplicando líneas. NO seguir:
       revisar la clave del dedupe (bloque 2) antes de implementar nada.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @desde DATE = '2026-07-01';
DECLARE @hasta DATE = '2026-08-01';

WITH tk AS (
    SELECT p.FECHA, p.NRO_SUCURSAL,
           p.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP,
           MAX(CASE WHEN p.DESC_PROMOCION_TARJETA <> 'SIN PROMO' THEN 1 ELSE 0 END) AS cp
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @desde AND p.FECHA < @hasta
    GROUP BY p.FECHA, p.NRO_SUCURSAL, p.N_COMP COLLATE DATABASE_DEFAULT
),
directo AS (
    SELECT s.RUBRO, SUM(s.CANTIDAD) AS unid, SUM(s.IMPORTE) AS imp
    FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
    WHERE s.FECHA >= @desde AND s.FECHA < @hasta
      AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
    GROUP BY s.RUBRO
),
con_join AS (
    SELECT s.RUBRO,
           SUM(s.CANTIDAD)                                              AS unid,
           SUM(s.IMPORTE)                                               AS imp,
           SUM(CASE WHEN ISNULL(tk.cp,0)=1 THEN s.CANTIDAD ELSE 0 END)  AS unid_cp,
           SUM(CASE WHEN ISNULL(tk.cp,0)=1 THEN s.IMPORTE  ELSE 0 END)  AS imp_cp
    FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
    LEFT JOIN tk ON tk.FECHA        = s.FECHA
                AND tk.NRO_SUCURSAL = s.NRO_SUCURS
                AND tk.N_COMP       = s.N_COMP COLLATE DATABASE_DEFAULT
    WHERE s.FECHA >= @desde AND s.FECHA < @hasta
      AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
    GROUP BY s.RUBRO
)
SELECT d.RUBRO,
       d.unid                                   AS unid_directo,
       j.unid                                   AS unid_con_join,
       j.unid - d.unid                          AS diff_unid,      -- DEBE SER 0
       j.imp  - d.imp                           AS diff_imp,       -- DEBE SER 0
       j.unid_cp                                AS unid_cpromo,
       CAST(100.0 * j.unid_cp / NULLIF(j.unid,0) AS DECIMAL(5,2)) AS pct_penetracion,
       j.imp_cp                                 AS fact_cpromo
FROM directo d
JOIN con_join j ON j.RUBRO = d.RUBRO
ORDER BY j.unid_cp DESC;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   5) CUADRE CONTRA EL KPI "Fact. con Promo" del Resumen
   ═══════════════════════════════════════════════════════════════════════════
   El KPI del dashboard suma IMPORTE_TO del TICKET; el reporte de rubros suma
   IMPORTE de las LÍNEAS excluyendo CONCEPTO/PACKAGING. No van a dar exacto.

   Qué mirar: que la diferencia sea chica y explicable (packaging, conceptos,
   tickets sin líneas). Si es enorme, revisar el bloque 3.
   ═══════════════════════════════════════════════════════════════════════════ */
DECLARE @desde DATE = '2026-07-01';
DECLARE @hasta DATE = '2026-08-01';

SELECT 'KPI Resumen (ticket)' AS fuente,
       SUM(CASE WHEN p.DESC_PROMOCION_TARJETA <> 'SIN PROMO' THEN p.IMPORTE_TO ELSE 0 END) AS fact_cpromo
FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
WHERE p.FECHA >= @desde AND p.FECHA < @hasta

UNION ALL

SELECT 'Rubros (líneas)' AS fuente, SUM(x.imp_cp)
FROM (
    SELECT SUM(CASE WHEN ISNULL(tk.cp,0)=1 THEN s.IMPORTE ELSE 0 END) AS imp_cp
    FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
    LEFT JOIN (
        SELECT p.FECHA, p.NRO_SUCURSAL, p.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP,
               MAX(CASE WHEN p.DESC_PROMOCION_TARJETA <> 'SIN PROMO' THEN 1 ELSE 0 END) AS cp
        FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
        WHERE p.FECHA >= @desde AND p.FECHA < @hasta
        GROUP BY p.FECHA, p.NRO_SUCURSAL, p.N_COMP COLLATE DATABASE_DEFAULT
    ) tk ON tk.FECHA        = s.FECHA
        AND tk.NRO_SUCURSAL = s.NRO_SUCURS
        AND tk.N_COMP       = s.N_COMP COLLATE DATABASE_DEFAULT
    WHERE s.FECHA >= @desde AND s.FECHA < @hasta
      AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
) x;
GO


/* ═══════════════════════════════════════════════════════════════════════════
   6) PERFORMANCE — tiempo de la query real sobre un rango grande
   ═══════════════════════════════════════════════════════════════════════════
   Correr con "Include Client Statistics" o mirar el tiempo del grid.
   Si pasa de ~10 s, acotar el CTE a T_COMP='FAC' y/o revisar índices sobre
   (FECHA, NRO_SUCURS, N_COMP) en BI_SALES_SUCURSALES.
   ═══════════════════════════════════════════════════════════════════════════ */
SET STATISTICS TIME ON;

DECLARE @d1 DATE = '2026-01-01', @h1 DATE = '2026-09-01';

WITH tk AS (
    SELECT p.FECHA, p.NRO_SUCURSAL, p.N_COMP COLLATE DATABASE_DEFAULT AS N_COMP,
           MAX(CASE WHEN p.DESC_PROMOCION_TARJETA <> 'SIN PROMO' THEN 1 ELSE 0 END) AS cp
    FROM dbo.BI_PROMOCIONES p WITH (NOLOCK)
    WHERE p.FECHA >= @d1 AND p.FECHA < @h1
    GROUP BY p.FECHA, p.NRO_SUCURSAL, p.N_COMP COLLATE DATABASE_DEFAULT
)
SELECT s.RUBRO,
       SUM(s.CANTIDAD)                                             AS unid_total,
       SUM(CASE WHEN ISNULL(tk.cp,0)=1 THEN s.CANTIDAD ELSE 0 END) AS unid_cpromo
FROM dbo.BI_SALES_SUCURSALES s WITH (NOLOCK)
LEFT JOIN tk ON tk.FECHA        = s.FECHA
            AND tk.NRO_SUCURSAL = s.NRO_SUCURS
            AND tk.N_COMP       = s.N_COMP COLLATE DATABASE_DEFAULT
WHERE s.FECHA >= @d1 AND s.FECHA < @h1
  AND s.RUBRO NOT IN ('CONCEPTO','PACKAGING')
GROUP BY s.RUBRO
ORDER BY unid_cpromo DESC;

SET STATISTICS TIME OFF;
GO
