USE [POWER_BI_CONTROL_URUGUAY];
GO
-- ============================================================
-- 03_sp_stock_uy.sql
-- RO_SP_STOCK_WMS_TANGO_UY
-- Pestaña 2 UY: KPIs de stock + detalle por rubro + slicer.
-- Fuente: EB_V_STOCK_JAUSER_CENTRAL + SOF_RUBROS (ambas en XL-TANGO.TASKY_SA)
--   STOCK_CENTRAL = nuestro sistema (referencia)
--   STOCK_JAUSER  = WMS Jauser
--   DIFERENCIA    = STOCK_JAUSER - STOCK_CENTRAL  (varchar → TRY_CAST)
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_STOCK_WMS_TANGO_UY','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_STOCK_WMS_TANGO_UY;
GO

CREATE PROCEDURE dbo.RO_SP_STOCK_WMS_TANGO_UY
    @RUBRO NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- CTE compartida: castea a DECIMAL y resuelve RUBRO desde SOF_RUBROS
    ;WITH base AS (
        SELECT
            ISNULL(r.RUBRO COLLATE Modern_Spanish_CI_AI, '(Sin rubro)') AS RUBRO,
            ISNULL(TRY_CAST(v.STOCK_CENTRAL AS DECIMAL(18,2)), 0)        AS STOCK_CENTRAL,
            ISNULL(TRY_CAST(v.STOCK_JAUSER  AS DECIMAL(18,2)), 0)        AS STOCK_JAUSER,
            ISNULL(TRY_CAST(v.DIFERENCIA    AS DECIMAL(18,2)), 0)        AS DIFERENCIA
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
        CAST(SUM(STOCK_CENTRAL)                                         AS DECIMAL(18,2)) AS STOCK_TANGO,
        CAST(SUM(STOCK_JAUSER)                                          AS DECIMAL(18,2)) AS STOCK_WMS,
        CAST(SUM(DIFERENCIA)                                            AS DECIMAL(18,2)) AS DIFERENCIA,
        CAST(SUM(ABS(DIFERENCIA))                                       AS DECIMAL(18,2)) AS DIFERENCIA_ABS,
        CAST(SUM(DIFERENCIA)      / NULLIF(SUM(STOCK_CENTRAL), 0)       AS DECIMAL(10,4)) AS DIF_PCT,
        CAST(1.0 - SUM(ABS(DIFERENCIA)) / NULLIF(SUM(STOCK_CENTRAL), 0) AS DECIMAL(10,4)) AS PRECISION_INVENTARIO
    FROM base;

    -- ── Result set 2: detalle por rubro ───────────────────────────────────
    ;WITH base AS (
        SELECT
            ISNULL(r.RUBRO COLLATE Modern_Spanish_CI_AI, '(Sin rubro)') AS RUBRO,
            ISNULL(TRY_CAST(v.STOCK_CENTRAL AS DECIMAL(18,2)), 0)        AS STOCK_CENTRAL,
            ISNULL(TRY_CAST(v.STOCK_JAUSER  AS DECIMAL(18,2)), 0)        AS STOCK_JAUSER,
            ISNULL(TRY_CAST(v.DIFERENCIA    AS DECIMAL(18,2)), 0)        AS DIFERENCIA
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
        CAST(SUM(STOCK_CENTRAL)   AS DECIMAL(18,2))                          AS STOCK_TANGO,
        CAST(SUM(STOCK_JAUSER)    AS DECIMAL(18,2))                          AS STOCK_WMS,
        CAST(SUM(DIFERENCIA)      AS DECIMAL(18,2))                          AS DIFERENCIA,
        CAST(SUM(ABS(DIFERENCIA)) AS DECIMAL(18,2))                          AS DIFERENCIA_ABS,
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

END;
GO

PRINT 'SP RO_SP_STOCK_WMS_TANGO_UY creado correctamente (fuente: EB_V_STOCK_JAUSER_CENTRAL + SOF_RUBROS).';
GO
