USE [POWER_BI_CONTROL_URUGUAY];
GO
-- ============================================================
-- 03_sp_stock_uy.sql
-- RO_SP_STOCK_WMS_TANGO_UY
-- Pestaña 2 UY: KPIs de stock + detalle por rubro + slicer
--               + Top 10 sobrantes + Top 10 faltantes
--               + detalle de artículos con diferencia (drill-down).
--
-- Fuente única: EB_V_STOCK_JAUSER_CENTRAL + SOF_RUBROS (XL-TANGO).
--   Compara, a nivel ARTÍCULO, lo que dice el sistema Central (Tango) vs
--   lo que dice Jauser (WMS). NO se separa por depósito: el stock se
--   consolida por artículo (suma de depósitos) y se contrasta Central vs Jauser.
--   DIFERENCIA = STOCK_JAUSER (WMS) − STOCK_CENTRAL (Tango).
--     > 0  → sobrante (Jauser tiene MÁS que Central)
--     < 0  → faltante (Jauser tiene MENOS que Central)
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_STOCK_WMS_TANGO_UY','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_STOCK_WMS_TANGO_UY;
GO

CREATE PROCEDURE dbo.RO_SP_STOCK_WMS_TANGO_UY
    @RUBRO NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- CTE compartida para RS1 y RS2:
    -- una fila por artículo con STOCK_CENTRAL vs STOCK_JAUSER
    ;WITH base AS (
        SELECT
            ISNULL(r.RUBRO COLLATE Modern_Spanish_CI_AI, '(Sin rubro)')  AS RUBRO,
            ISNULL(TRY_CAST(v.STOCK_CENTRAL AS DECIMAL(18,2)), 0)         AS STOCK_CENTRAL,
            ISNULL(TRY_CAST(v.STOCK_JAUSER  AS DECIMAL(18,2)), 0)         AS STOCK_JAUSER,
            ISNULL(TRY_CAST(v.DIFERENCIA    AS DECIMAL(18,2)), 0)         AS DIFERENCIA
        FROM [XL-TANGO].[TASKY_SA].[dbo].[EB_V_STOCK_JAUSER_CENTRAL] v
        LEFT JOIN [XL-TANGO].[TASKY_SA].[dbo].[SOF_RUBROS] r
            ON v.COD_ARTICU COLLATE Modern_Spanish_CI_AI
             = r.CODIGO     COLLATE Modern_Spanish_CI_AI
        WHERE @RUBRO IS NULL
           OR ISNULL(r.RUBRO, '') COLLATE Modern_Spanish_CI_AI
            = @RUBRO              COLLATE Modern_Spanish_CI_AI
    )

    -- ── Result set 1: KPIs globales ───────────────────────────────────────
    SELECT
        CAST(SUM(STOCK_CENTRAL)                                          AS DECIMAL(18,2)) AS STOCK_TANGO,
        CAST(SUM(STOCK_JAUSER)                                           AS DECIMAL(18,2)) AS STOCK_WMS,
        CAST(SUM(DIFERENCIA)                                             AS DECIMAL(18,2)) AS DIFERENCIA,
        CAST(SUM(ABS(DIFERENCIA))                                        AS DECIMAL(18,2)) AS DIFERENCIA_ABS,
        CAST(SUM(DIFERENCIA)      / NULLIF(SUM(STOCK_CENTRAL), 0)        AS DECIMAL(10,4)) AS DIF_PCT,
        CAST(1.0 - SUM(ABS(DIFERENCIA)) / NULLIF(SUM(STOCK_CENTRAL), 0) AS DECIMAL(10,4)) AS PRECISION_INVENTARIO
    FROM base;

    -- ── Result set 2: detalle por rubro ───────────────────────────────────
    ;WITH base AS (
        SELECT
            ISNULL(r.RUBRO COLLATE Modern_Spanish_CI_AI, '(Sin rubro)')  AS RUBRO,
            ISNULL(TRY_CAST(v.STOCK_CENTRAL AS DECIMAL(18,2)), 0)         AS STOCK_CENTRAL,
            ISNULL(TRY_CAST(v.STOCK_JAUSER  AS DECIMAL(18,2)), 0)         AS STOCK_JAUSER,
            ISNULL(TRY_CAST(v.DIFERENCIA    AS DECIMAL(18,2)), 0)         AS DIFERENCIA
        FROM [XL-TANGO].[TASKY_SA].[dbo].[EB_V_STOCK_JAUSER_CENTRAL] v
        LEFT JOIN [XL-TANGO].[TASKY_SA].[dbo].[SOF_RUBROS] r
            ON v.COD_ARTICU COLLATE Modern_Spanish_CI_AI
             = r.CODIGO     COLLATE Modern_Spanish_CI_AI
        WHERE @RUBRO IS NULL
           OR ISNULL(r.RUBRO, '') COLLATE Modern_Spanish_CI_AI
            = @RUBRO              COLLATE Modern_Spanish_CI_AI
    )
    SELECT
        RUBRO,
        CAST(SUM(STOCK_CENTRAL)   AS DECIMAL(18,2))                           AS STOCK_TANGO,
        CAST(SUM(STOCK_JAUSER)    AS DECIMAL(18,2))                           AS STOCK_WMS,
        CAST(SUM(DIFERENCIA)      AS DECIMAL(18,2))                           AS DIFERENCIA,
        CAST(SUM(ABS(DIFERENCIA)) AS DECIMAL(18,2))                           AS DIFERENCIA_ABS,
        CAST(SUM(DIFERENCIA)      / NULLIF(SUM(STOCK_CENTRAL), 0) AS DECIMAL(10,4)) AS DIF_PCT,
        CAST(1.0 - SUM(ABS(DIFERENCIA)) / NULLIF(SUM(STOCK_CENTRAL), 0) AS DECIMAL(10,4)) AS PRECISION
    FROM base
    WHERE RUBRO NOT IN ('(Sin rubro)')
    GROUP BY RUBRO
    ORDER BY SUM(ABS(DIFERENCIA)) DESC;

    -- ── Result set 3: rubros disponibles (slicer) ─────────────────────────
    SELECT DISTINCT
        r.RUBRO COLLATE Modern_Spanish_CI_AI AS RUBRO
    FROM [XL-TANGO].[TASKY_SA].[dbo].[EB_V_STOCK_JAUSER_CENTRAL] v
    INNER JOIN [XL-TANGO].[TASKY_SA].[dbo].[SOF_RUBROS] r
        ON v.COD_ARTICU COLLATE Modern_Spanish_CI_AI
         = r.CODIGO     COLLATE Modern_Spanish_CI_AI
    WHERE r.RUBRO IS NOT NULL AND LTRIM(RTRIM(r.RUBRO)) <> ''
    ORDER BY r.RUBRO COLLATE Modern_Spanish_CI_AI;

    -- ════════════════════════════════════════════════════════════════════
    -- RS4–RS6: comparación Central vs Jauser a nivel ARTÍCULO.
    -- Roll-up por artículo (suma de depósitos). #art se construye una sola vez.
    -- ════════════════════════════════════════════════════════════════════
    SELECT
        ISNULL(r.RUBRO COLLATE Modern_Spanish_CI_AI, '(Sin rubro)')         AS RUBRO,
        v.COD_ARTICU  COLLATE Modern_Spanish_CI_AI                          AS COD_ARTICU,
        MAX(v.DESCRIPCION COLLATE Modern_Spanish_CI_AI)                     AS DESCRIPCION,
        CAST(SUM(ISNULL(TRY_CAST(v.STOCK_CENTRAL AS DECIMAL(18,2)), 0)) AS DECIMAL(18,2)) AS STOCK_TANGO,
        CAST(SUM(ISNULL(TRY_CAST(v.STOCK_JAUSER  AS DECIMAL(18,2)), 0)) AS DECIMAL(18,2)) AS STOCK_WMS,
        CAST(SUM(ISNULL(TRY_CAST(v.DIFERENCIA    AS DECIMAL(18,2)), 0)) AS DECIMAL(18,2)) AS DIFERENCIA
    INTO #art
    FROM [XL-TANGO].[TASKY_SA].[dbo].[EB_V_STOCK_JAUSER_CENTRAL] v
    LEFT JOIN [XL-TANGO].[TASKY_SA].[dbo].[SOF_RUBROS] r
        ON v.COD_ARTICU COLLATE Modern_Spanish_CI_AI
         = r.CODIGO     COLLATE Modern_Spanish_CI_AI
    WHERE @RUBRO IS NULL
       OR ISNULL(r.RUBRO, '') COLLATE Modern_Spanish_CI_AI
        = @RUBRO              COLLATE Modern_Spanish_CI_AI
    GROUP BY ISNULL(r.RUBRO COLLATE Modern_Spanish_CI_AI, '(Sin rubro)'),
             v.COD_ARTICU COLLATE Modern_Spanish_CI_AI;

    -- ── Result set 4: Top 10 sobrantes (Jauser > Central) ────────────────
    SELECT TOP 10
        COD_ARTICU,
        DESCRIPCION,
        RUBRO,
        STOCK_TANGO,
        STOCK_WMS,
        DIFERENCIA
    FROM #art
    WHERE DIFERENCIA > 0
    ORDER BY DIFERENCIA DESC;

    -- ── Result set 5: Top 10 faltantes (Jauser < Central) ────────────────
    SELECT TOP 10
        COD_ARTICU,
        DESCRIPCION,
        RUBRO,
        STOCK_TANGO,
        STOCK_WMS,
        DIFERENCIA
    FROM #art
    WHERE DIFERENCIA < 0
    ORDER BY DIFERENCIA ASC;

    -- ── Result set 6: detalle de artículos con diferencia (drill-down RS2) ─
    -- Todos los artículos con diferencia neta <> 0, agrupables por rubro.
    SELECT
        RUBRO,
        COD_ARTICU,
        DESCRIPCION,
        STOCK_TANGO,
        STOCK_WMS,
        DIFERENCIA,
        CAST(ABS(DIFERENCIA) AS DECIMAL(18,2)) AS DIFERENCIA_ABS
    FROM #art
    WHERE RUBRO NOT IN ('(Sin rubro)')
      AND DIFERENCIA <> 0
    ORDER BY RUBRO, ABS(DIFERENCIA) DESC;

    DROP TABLE #art;
END;
GO

PRINT 'SP RO_SP_STOCK_WMS_TANGO_UY creado correctamente.';
GO
