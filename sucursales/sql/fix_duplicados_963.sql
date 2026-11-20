-- ═══════════════════════════════════════════════════════════════════════
-- MDP FLORIDA (963) — ventas duplicadas en el tablero.
-- Correr en: XL-APPS / POWER_BI_CONTROL_FRANQUICIAS (conexión 'power_franquicias').
--
-- Antecedente (18/11/2026): del 01/11 al 11/11 la carga diaria metió dos veces
-- los mismos comprobantes en BI_SALES_SUCURSALES y BI_SALES_TOTAL_TICKETS.
-- Tablero 21.537.200 vs. real 14.626.080 (01–17/11). Solo afectó a 963.
-- En 13 renglones las dos copias difieren en CUOTAS / MEDIO_DE_PAGO, por eso
-- esas columnas no se usan para decidir si dos renglones son copia.
--
-- 20/11/2026: el reclamo vuelve (tablero ~22M, local ~15M).
-- 22M ≈ los 21,5M de antes + 18 y 19/11 → o el COMMIT del 18/11 no se hizo,
-- o la carga volvió a meter copias. El paso 1 lo distingue.
-- ═══════════════════════════════════════════════════════════════════════

USE [POWER_BI_CONTROL_FRANQUICIAS];

DECLARE @suc   INT  = 963;
DECLARE @desde DATE = '2026-11-01';
DECLARE @hasta DATE = DATEADD(day, 1, CAST(GETDATE() AS DATE));   -- exclusivo: incluye hoy

-- ── 1) Diagnóstico día por día (no modifica nada) ─────────────────────
--  · Sobrante en 01–11/11 y cero después → el fix del 18/11 no quedó
--    guardado, o la carga volvió a cargar esos mismos días.
--  · Sobrante en días nuevos (18/11 en adelante) → la duplicación sigue
--    activa en el origen.
--  La última fila (FECHA vacía) es el total.
WITH d AS (
    SELECT FECHA, N_COMP, COD_ARTICU, COD_VENDED, IMPORTE, CANTIDAD, COUNT(*) AS veces
    FROM dbo.BI_SALES_SUCURSALES WITH (NOLOCK)
    WHERE NRO_SUCURS = @suc AND FECHA >= @desde AND FECHA < @hasta
    GROUP BY FECHA, N_COMP, COD_ARTICU, COD_VENDED, IMPORTE, CANTIDAD
)
SELECT FECHA,
       SUM(veces)                 AS renglones,
       COUNT(*)                   AS renglones_distintos,
       SUM(IMPORTE * veces)       AS importe_tablero,
       SUM(IMPORTE)               AS importe_sin_duplicados,
       SUM(IMPORTE * (veces - 1)) AS sobrante
FROM d
GROUP BY ROLLUP(FECHA)
ORDER BY GROUPING(FECHA), FECHA;

-- Tickets: comprobantes repetidos por día
SELECT FECHA, COUNT(*) AS filas, COUNT(DISTINCT N_COMP) AS comprobantes,
       SUM(IMP_TOTAL_TICKET) AS importe
FROM dbo.BI_SALES_TOTAL_TICKETS WITH (NOLOCK)
WHERE NRO_SUCURS = @suc AND FECHA >= @desde AND FECHA < @hasta
GROUP BY FECHA
HAVING COUNT(*) > COUNT(DISTINCT N_COMP)
ORDER BY FECHA;

-- ── 2) Vista previa de lo que se borraría ─────────────────────────────
SELECT 'BI_SALES_SUCURSALES' AS tabla, COUNT(*) AS filas_a_borrar, SUM(IMPORTE) AS importe_a_borrar
FROM (
    SELECT IMPORTE,
           ROW_NUMBER() OVER (PARTITION BY FECHA, NRO_SUCURS, SUCURSAL, CANAL, N_COMP,
                                  COD_VENDED, DESC_VENDEDOR, COD_ARTICU, MODELO, CANTIDAD,
                                  IMPORTE, RUBRO, CATEGORIA, COLOR,
                                  LOCALIDAD, PROVINCIA, ZONA, GRUPO_EMPRESARIO, DESTINO
                              ORDER BY (SELECT NULL)) AS rn
    FROM dbo.BI_SALES_SUCURSALES
    WHERE NRO_SUCURS = @suc AND FECHA >= @desde AND FECHA < @hasta
) x WHERE rn > 1
UNION ALL
SELECT 'BI_SALES_TOTAL_TICKETS', COUNT(*), SUM(IMP_TOTAL_TICKET)
FROM (
    SELECT IMP_TOTAL_TICKET,
           ROW_NUMBER() OVER (PARTITION BY NRO_SUCURS, FECHA, T_COMP, N_COMP, IMP_TOTAL_TICKET
                              ORDER BY (SELECT NULL)) AS rn
    FROM dbo.BI_SALES_TOTAL_TICKETS
    WHERE NRO_SUCURS = @suc AND FECHA >= @desde AND FECHA < @hasta
) x WHERE rn > 1;
-- El importe de BI_SALES_SUCURSALES tiene que coincidir con el "sobrante"
-- total del paso 1. Si no coincide, NO seguir.

-- ── 3) Borrado, dentro de una transacción ─────────────────────────────
BEGIN TRAN;

WITH d AS (
    SELECT ROW_NUMBER() OVER (PARTITION BY FECHA, NRO_SUCURS, SUCURSAL, CANAL, N_COMP,
                                  COD_VENDED, DESC_VENDEDOR, COD_ARTICU, MODELO, CANTIDAD,
                                  IMPORTE, RUBRO, CATEGORIA, COLOR,
                                  LOCALIDAD, PROVINCIA, ZONA, GRUPO_EMPRESARIO, DESTINO
                              ORDER BY (SELECT NULL)) AS rn
    FROM dbo.BI_SALES_SUCURSALES
    WHERE NRO_SUCURS = @suc AND FECHA >= @desde AND FECHA < @hasta
)
DELETE FROM d WHERE rn > 1;
PRINT CONCAT('BI_SALES_SUCURSALES borradas: ', @@ROWCOUNT);

WITH t AS (
    SELECT ROW_NUMBER() OVER (PARTITION BY NRO_SUCURS, FECHA, T_COMP, N_COMP, IMP_TOTAL_TICKET
                              ORDER BY (SELECT NULL)) AS rn
    FROM dbo.BI_SALES_TOTAL_TICKETS
    WHERE NRO_SUCURS = @suc AND FECHA >= @desde AND FECHA < @hasta
)
DELETE FROM t WHERE rn > 1;
PRINT CONCAT('BI_SALES_TOTAL_TICKETS borradas: ', @@ROWCOUNT);

-- Control: tiene que dar el "importe_sin_duplicados" total del paso 1.
SELECT SUM(IMPORTE) AS total_despues
FROM dbo.BI_SALES_SUCURSALES
WHERE NRO_SUCURS = @suc AND FECHA >= @desde AND FECHA < @hasta;

ROLLBACK;   -- ← cambiar a COMMIT solo si los números de arriba dan bien
