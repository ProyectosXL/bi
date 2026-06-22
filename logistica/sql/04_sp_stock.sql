USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 04_sp_stock.sql
-- RO_SP_STOCK_WMS_TANGO
-- Área 3: Comparación stock WMS vs Tango.
-- Sin filtro de fecha (snapshot actual); slicer por RUBRO.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_STOCK_WMS_TANGO','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_STOCK_WMS_TANGO;
GO

CREATE PROCEDURE dbo.RO_SP_STOCK_WMS_TANGO
    @RUBRO NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- ── Result set 1: KPIs globales ───────────────────────────────────────
    SELECT
        CAST(ISNULL(SUM(STOCK_TANGO), 0) AS DECIMAL(18,2))  AS STOCK_TANGO,
        CAST(ISNULL(SUM(STOCK_UBIC),  0) AS DECIMAL(18,2))  AS STOCK_WMS,
        CAST(ISNULL(SUM(DIFERENCIA),  0) AS DECIMAL(18,2))  AS DIFERENCIA,
        -- DIF_PCT: diferencia relativa sobre stock Tango
        CAST(
            ISNULL(
                SUM(DIFERENCIA) * 1.0 / NULLIF(SUM(STOCK_TANGO), 0),
                0
            ) AS DECIMAL(10,4)
        )                                                    AS DIF_PCT,
        -- PRECISION_INVENTARIO = 1 - ABS(diferencia) / stock Tango
        CAST(
            1.0 - ISNULL(
                SUM(ABS(DIFERENCIA)) * 1.0 / NULLIF(SUM(STOCK_TANGO), 0),
                0
            ) AS DECIMAL(10,4)
        )                                                    AS PRECISION_INVENTARIO
    FROM dbo.BI_STOCK_WMS_TANGO
    WHERE (@RUBRO IS NULL OR RUBRO = @RUBRO);

    -- ── Result set 2: detalle por rubro ───────────────────────────────────
    SELECT
        RUBRO,
        CAST(ISNULL(SUM(STOCK_TANGO), 0) AS DECIMAL(18,2)) AS STOCK_TANGO,
        CAST(ISNULL(SUM(STOCK_UBIC),  0) AS DECIMAL(18,2)) AS STOCK_WMS,
        CAST(ISNULL(SUM(DIFERENCIA),  0) AS DECIMAL(18,2)) AS DIFERENCIA,
        CAST(
            ISNULL(SUM(DIFERENCIA) * 1.0 / NULLIF(SUM(STOCK_TANGO), 0), 0)
            AS DECIMAL(10,4)
        )                                                   AS DIF_PCT,
        CAST(
            1.0 - ISNULL(SUM(ABS(DIFERENCIA)) * 1.0 / NULLIF(SUM(STOCK_TANGO),0), 0)
            AS DECIMAL(10,4)
        )                                                   AS PRECISION
    FROM dbo.BI_STOCK_WMS_TANGO
    WHERE (@RUBRO IS NULL OR RUBRO = @RUBRO)
    GROUP BY RUBRO
    ORDER BY ABS(SUM(DIFERENCIA)) DESC;

    -- ── Result set 3: lista de rubros disponibles (slicer) ───────────────
    SELECT DISTINCT RUBRO
    FROM dbo.BI_STOCK_WMS_TANGO
    WHERE RUBRO IS NOT NULL AND LTRIM(RTRIM(RUBRO)) <> ''
    ORDER BY RUBRO;

    -- ── Result set 4: detalle de artículos con diferencia (drill-down RS2) ─
    -- Roll-up a nivel artículo (suma sobre depósitos). Solo artículos con
    -- diferencia neta <> 0. Respeta el filtro @RUBRO. Misma fuente que RS2.
    SELECT
        RUBRO,
        COD_ARTICU,
        MAX(DESCRIPCION)                                   AS DESCRIPCION,
        CAST(SUM(STOCK_TANGO) AS DECIMAL(18,2))            AS STOCK_TANGO,
        CAST(SUM(STOCK_UBIC)  AS DECIMAL(18,2))            AS STOCK_WMS,
        CAST(SUM(DIFERENCIA)  AS DECIMAL(18,2))            AS DIFERENCIA
    FROM dbo.BI_STOCK_WMS_TANGO
    WHERE (@RUBRO IS NULL OR RUBRO = @RUBRO)
      AND RUBRO IS NOT NULL AND LTRIM(RTRIM(RUBRO)) <> ''
    GROUP BY RUBRO, COD_ARTICU
    HAVING SUM(DIFERENCIA) <> 0
    ORDER BY RUBRO, ABS(SUM(DIFERENCIA)) DESC;
END;
GO
