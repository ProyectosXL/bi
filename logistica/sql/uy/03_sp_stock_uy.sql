USE [POWER_BI_CONTROL_URUGUAY];
GO
-- ============================================================
-- 03_sp_stock_uy.sql
-- RO_SP_STOCK_WMS_TANGO_UY
-- Pestaña 2 UY: KPIs de stock + detalle por rubro + slicer
--               + Top 10 sobrantes + Top 10 faltantes por depósito.
--
-- RS1–RS3: fuente EB_V_STOCK_JAUSER_CENTRAL + SOF_RUBROS (XL-TANGO).
--   Compara STOCK_CENTRAL (sistema Central) vs STOCK_JAUSER (sistema Jauser)
--   a nivel de artículo — cada fila ya tiene ambos valores, sin mezclar depósitos.
--
-- RS4–RS8: fuente BI_T_STOCK_WMS_TANGO_UY (tabla materializada).
--   Análisis a nivel artículo con columna DEPOSITO para filtrar por depósito.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_STOCK_WMS_TANGO_UY','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_STOCK_WMS_TANGO_UY;
GO

CREATE PROCEDURE dbo.RO_SP_STOCK_WMS_TANGO_UY
    @RUBRO    NVARCHAR(100) = NULL,
    @DEPOSITO NVARCHAR(50)  = NULL
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
    -- RS4–RS8: análisis por artículo y depósito
    -- Fuente: BI_T_STOCK_WMS_TANGO_UY (tabla materializada con DEPOSITO)
    -- DIFERENCIA = STOCK_UBIC (Jauser) − STOCK_TANGO (Central)
    -- ════════════════════════════════════════════════════════════════════

    -- ── Result set 4: Top 10 sobrantes (todos los depósitos) ─────────────
    -- Sobrante = DIFERENCIA > 0: Jauser tiene MÁS que Central
    SELECT TOP 10
        COD_ARTICU,
        DESCRIPCION,
        RUBRO,
        DEPOSITO,
        CAST(STOCK_TANGO AS DECIMAL(18,2)) AS STOCK_TANGO,
        CAST(STOCK_UBIC  AS DECIMAL(18,2)) AS STOCK_WMS,
        CAST(DIFERENCIA  AS DECIMAL(18,2)) AS DIFERENCIA
    FROM dbo.BI_T_STOCK_WMS_TANGO_UY
    WHERE DIFERENCIA > 0
      AND (@RUBRO IS NULL OR RUBRO COLLATE Modern_Spanish_CI_AI = @RUBRO COLLATE Modern_Spanish_CI_AI)
    ORDER BY DIFERENCIA DESC;

    -- ── Result set 5: Top 10 faltantes — Depósito 82 (Central) ──────────
    -- Faltante = DIFERENCIA < 0: Jauser registra MENOS que Central
    SELECT TOP 10
        COD_ARTICU,
        DESCRIPCION,
        RUBRO,
        DEPOSITO,
        CAST(STOCK_TANGO AS DECIMAL(18,2)) AS STOCK_TANGO,
        CAST(STOCK_UBIC  AS DECIMAL(18,2)) AS STOCK_WMS,
        CAST(DIFERENCIA  AS DECIMAL(18,2)) AS DIFERENCIA
    FROM dbo.BI_T_STOCK_WMS_TANGO_UY
    WHERE DIFERENCIA < 0
      AND DEPOSITO = '82'
      AND (@RUBRO IS NULL OR RUBRO COLLATE Modern_Spanish_CI_AI = @RUBRO COLLATE Modern_Spanish_CI_AI)
    ORDER BY DIFERENCIA ASC;

    -- ── Result set 6: diferencia por rubro — Depósito 82 (gráfico) ───────
    SELECT
        RUBRO,
        CAST(SUM(DIFERENCIA)     AS DECIMAL(18,2)) AS DIFERENCIA,
        CAST(SUM(DIFERENCIA_ABS) AS DECIMAL(18,2)) AS DIFERENCIA_ABS
    FROM dbo.BI_T_STOCK_WMS_TANGO_UY
    WHERE DEPOSITO = '82'
      AND RUBRO IS NOT NULL AND LTRIM(RTRIM(RUBRO)) <> ''
      AND (@RUBRO IS NULL OR RUBRO COLLATE Modern_Spanish_CI_AI = @RUBRO COLLATE Modern_Spanish_CI_AI)
    GROUP BY RUBRO
    ORDER BY SUM(DIFERENCIA_ABS) DESC;

    -- ── Result set 7: Top 10 faltantes — Depósito 83 (Jauser) ───────────
    SELECT TOP 10
        COD_ARTICU,
        DESCRIPCION,
        RUBRO,
        DEPOSITO,
        CAST(STOCK_TANGO AS DECIMAL(18,2)) AS STOCK_TANGO,
        CAST(STOCK_UBIC  AS DECIMAL(18,2)) AS STOCK_WMS,
        CAST(DIFERENCIA  AS DECIMAL(18,2)) AS DIFERENCIA
    FROM dbo.BI_T_STOCK_WMS_TANGO_UY
    WHERE DIFERENCIA < 0
      AND DEPOSITO = '83'
      AND (@RUBRO IS NULL OR RUBRO COLLATE Modern_Spanish_CI_AI = @RUBRO COLLATE Modern_Spanish_CI_AI)
    ORDER BY DIFERENCIA ASC;

    -- ── Result set 8: diferencia por rubro — Depósito 83 (gráfico) ───────
    SELECT
        RUBRO,
        CAST(SUM(DIFERENCIA)     AS DECIMAL(18,2)) AS DIFERENCIA,
        CAST(SUM(DIFERENCIA_ABS) AS DECIMAL(18,2)) AS DIFERENCIA_ABS
    FROM dbo.BI_T_STOCK_WMS_TANGO_UY
    WHERE DEPOSITO = '83'
      AND RUBRO IS NOT NULL AND LTRIM(RTRIM(RUBRO)) <> ''
      AND (@RUBRO IS NULL OR RUBRO COLLATE Modern_Spanish_CI_AI = @RUBRO COLLATE Modern_Spanish_CI_AI)
    GROUP BY RUBRO
    ORDER BY SUM(DIFERENCIA_ABS) DESC;

END;
GO

PRINT 'SP RO_SP_STOCK_WMS_TANGO_UY creado correctamente.';
GO
