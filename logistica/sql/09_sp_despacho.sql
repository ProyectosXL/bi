USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 09_sp_despacho.sql
-- RO_SP_DESPACHO
-- Área 6b: Eficacia de despacho. Fuente: RO_T_DESPACHO_PEDIDOS.
--
-- Definiciones:
--   Despachado (universo eficacia) = filas con FECHA_GUIA dentro del rango
--     y ESTADO_DESPACHO IN ('EN TERMINO','FUERA DE PLAZO').
--   Eficacia = comprobantes EN TERMINO / comprobantes despachados.
--   Desvío (días) = DATEDIFF(día, FECHA_GUIA, PROX_DESPACHO)  → negativo = tarde.
--   Demorados = ESTADO_DESPACHO IN ('DEMORADO','FUERA DE PLAZO') con
--     PROX_DESPACHO dentro del rango.  DIAS para DEMORADO (sin guía) usa HOY.
--
-- Reemplaza a la mitad "despacho" del antiguo RO_SP_DEMANDA_DESPACHO.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_DESPACHO','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_DESPACHO;
GO

CREATE PROCEDURE dbo.RO_SP_DESPACHO
    @FECHA_DESDE DATE,
    @FECHA_HASTA DATE,
    @CANAL   NVARCHAR(100) = NULL,       -- NULL = todos
    @CLIENTE NVARCHAR(200) = NULL        -- NULL = todos
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @HOY DATE = CAST(GETDATE() AS DATE);

    -- ── Universo "despachado" (con guía en el rango) ──────────────────────
    -- Se materializa para reutilizar en KPIs, canal, cliente y evolución.
    SELECT
        CLIENTE, CANAL, NRO_PEDIDO, N_COMP, PROX_DESPACHO, FECHA_GUIA, ESTADO_DESPACHO,
        DATEDIFF(DAY, FECHA_GUIA, PROX_DESPACHO) AS DESVIO
    INTO #DESP
    FROM dbo.RO_T_DESPACHO_PEDIDOS
    WHERE FECHA_GUIA BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND ESTADO_DESPACHO IN ('EN TERMINO','FUERA DE PLAZO')
      AND (@CANAL   IS NULL OR CANAL   = @CANAL)
      AND (@CLIENTE IS NULL OR CLIENTE = @CLIENTE);

    -- ── Universo "demorados" (vencidos en el rango por PROX_DESPACHO) ──────
    SELECT
        CLIENTE, CANAL, NRO_PEDIDO, N_COMP, PROX_DESPACHO, FECHA_GUIA, ESTADO_DESPACHO,
        DATEDIFF(DAY, ISNULL(FECHA_GUIA, @HOY), PROX_DESPACHO) AS DIAS
    INTO #DEM
    FROM dbo.RO_T_DESPACHO_PEDIDOS
    WHERE ESTADO_DESPACHO IN ('DEMORADO','FUERA DE PLAZO')
      AND PROX_DESPACHO BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND (@CANAL   IS NULL OR CANAL   = @CANAL)
      AND (@CLIENTE IS NULL OR CLIENTE = @CLIENTE);

    -- ── Result set 1: KPIs totales ────────────────────────────────────────
    SELECT
        CAST(
            (SELECT CAST(COUNT(DISTINCT CASE WHEN ESTADO_DESPACHO = 'EN TERMINO' THEN N_COMP END) AS FLOAT)
                  / NULLIF(COUNT(DISTINCT N_COMP), 0)
             FROM #DESP)
        AS DECIMAL(10,4))                                      AS EFICACIA_TOTAL,
        (SELECT AVG(CAST(DESVIO AS FLOAT)) FROM #DESP)         AS DESVIO_PROM_GUIA,
        (SELECT AVG(CAST(DIAS   AS FLOAT)) FROM #DEM)          AS DESVIO_PROM_DEMORADOS,
        CAST(0.95 AS DECIMAL(5,2))                             AS META;

    -- ── Result set 2: eficacia por canal ──────────────────────────────────
    SELECT
        CANAL,
        COUNT(DISTINCT CASE WHEN ESTADO_DESPACHO = 'EN TERMINO' THEN N_COMP END) AS ENTERMINO,
        COUNT(DISTINCT N_COMP)                                                   AS TOTAL,
        CAST(
            CAST(COUNT(DISTINCT CASE WHEN ESTADO_DESPACHO = 'EN TERMINO' THEN N_COMP END) AS FLOAT)
            / NULLIF(COUNT(DISTINCT N_COMP), 0)
        AS DECIMAL(10,4))                                                        AS EFICACIA
    FROM #DESP
    GROUP BY CANAL
    ORDER BY CANAL;

    -- ── Result set 3: eficacia por cliente (apertura) ─────────────────────
    SELECT TOP 500
        CLIENTE,
        COUNT(DISTINCT N_COMP)                                                   AS TOTAL,
        COUNT(DISTINCT CASE WHEN ESTADO_DESPACHO = 'EN TERMINO' THEN N_COMP END) AS ENTERMINO,
        CAST(
            CAST(COUNT(DISTINCT CASE WHEN ESTADO_DESPACHO = 'EN TERMINO' THEN N_COMP END) AS FLOAT)
            / NULLIF(COUNT(DISTINCT N_COMP), 0)
        AS DECIMAL(10,4))                                                        AS EFICACIA,
        AVG(CAST(DESVIO AS FLOAT))                                               AS DESVIO_PROM
    FROM #DESP
    GROUP BY CLIENTE
    HAVING COUNT(DISTINCT N_COMP) > 0
    ORDER BY EFICACIA ASC, TOTAL DESC;

    -- ── Result set 4: eficacia desglose por pedido (los problemáticos) ─────
    SELECT TOP 500
        CLIENTE,
        NRO_PEDIDO,
        N_COMP,
        CAST(PROX_DESPACHO AS DATE) AS PROX_DESPACHO,
        CAST(FECHA_GUIA    AS DATE) AS FECHA_GUIA,
        ESTADO_DESPACHO,
        DESVIO
    FROM #DESP
    WHERE ESTADO_DESPACHO = 'FUERA DE PLAZO'
    ORDER BY DESVIO ASC;

    -- ── Result set 5: pedidos demorados promedio por cliente ──────────────
    SELECT TOP 500
        CLIENTE,
        AVG(CAST(DIAS AS FLOAT))      AS DIAS_PROM,
        COUNT(DISTINCT NRO_PEDIDO)    AS PEDIDOS
    FROM #DEM
    GROUP BY CLIENTE
    ORDER BY DIAS_PROM ASC;

    -- ── Result set 6: pedidos demorados desglose por pedido ───────────────
    SELECT TOP 500
        CLIENTE,
        NRO_PEDIDO,
        N_COMP,
        CAST(PROX_DESPACHO AS DATE) AS PROX_DESPACHO,
        CAST(FECHA_GUIA    AS DATE) AS FECHA_GUIA,
        ESTADO_DESPACHO,
        DIAS
    FROM #DEM
    ORDER BY DIAS ASC;

    -- ── Result set 7: evolución mensual (año actual + anterior) ───────────
    SELECT
        YEAR(FECHA_GUIA)  AS ANIO,
        MONTH(FECHA_GUIA) AS MES,
        COUNT(DISTINCT CASE WHEN ESTADO_DESPACHO = 'EN TERMINO' THEN N_COMP END) AS ENTERMINO,
        COUNT(DISTINCT N_COMP)                                                   AS TOTAL,
        CAST(
            CAST(COUNT(DISTINCT CASE WHEN ESTADO_DESPACHO = 'EN TERMINO' THEN N_COMP END) AS FLOAT)
            / NULLIF(COUNT(DISTINCT N_COMP), 0)
        AS DECIMAL(10,4))                                                        AS EFICACIA
    FROM dbo.RO_T_DESPACHO_PEDIDOS
    WHERE ESTADO_DESPACHO IN ('EN TERMINO','FUERA DE PLAZO')
      AND FECHA_GUIA >= DATEFROMPARTS(YEAR(@FECHA_HASTA) - 1, 1, 1)
      AND FECHA_GUIA <  DATEFROMPARTS(YEAR(@FECHA_HASTA) + 1, 1, 1)
      AND (@CANAL   IS NULL OR CANAL   = @CANAL)
      AND (@CLIENTE IS NULL OR CLIENTE = @CLIENTE)
    GROUP BY YEAR(FECHA_GUIA), MONTH(FECHA_GUIA)
    ORDER BY ANIO, MES;

    DROP TABLE #DESP;
    DROP TABLE #DEM;
END;
GO
