USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 05_sp_productividad_fact.sql
-- RO_SP_PRODUCTIVIDAD_FACTURACION
-- Área 4: Productividad de facturación.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_PRODUCTIVIDAD_FACTURACION','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_PRODUCTIVIDAD_FACTURACION;
GO

CREATE PROCEDURE dbo.RO_SP_PRODUCTIVIDAD_FACTURACION
    @FECHA_DESDE DATE,
    @FECHA_HASTA DATE,
    @USUARIO     NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @HOY DATE = CAST(GETDATE() AS DATE);

    -- Temp table reutilizable en los 3 result sets
    -- (CTE solo cubre el SELECT inmediato siguiente; PERCENTILE_CONT no puede
    --  mezclarse con SUM/COUNT/AVG/MAX en el mismo SELECT)
    SELECT
        FECHA_COMP,
        USUARIO,
        SUM(CANTIDAD) AS Unidades
    INTO #FactBase
    FROM dbo.BI_FACTURACION_LOGISTICA
    WHERE FECHA_COMP BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND (@USUARIO IS NULL OR USUARIO = @USUARIO)
    GROUP BY FECHA_COMP, USUARIO;

    -- ── Result set 1: KPIs ────────────────────────────────────────────────
    SELECT
        CAST(ISNULL(SUM(Unidades), 0) AS DECIMAL(18,2))                AS UNIDADES_FACT,
        COUNT(DISTINCT FECHA_COMP)                                      AS DIAS_PRODUCTIVOS,
        CAST(ISNULL(AVG(CAST(Unidades AS FLOAT)), 0) AS DECIMAL(18,2)) AS PROMEDIO,
        CAST(
            (SELECT TOP 1
                 PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY Unidades) OVER ()
             FROM #FactBase)
        AS DECIMAL(18,2))                                               AS MEDIANA,
        CAST(
            (SELECT TOP 1
                 PERCENTILE_CONT(0.5) WITHIN GROUP (
                     ORDER BY CASE WHEN Unidades > 200 THEN Unidades END) OVER ()
             FROM #FactBase)
        AS DECIMAL(18,2))                                               AS MEDIANA_GT200,
        CAST(ISNULL(MAX(Unidades), 0) AS DECIMAL(18,2))                AS MODA,
        CAST(
            ISNULL(
                (SELECT SUM(CANTIDAD)
                 FROM dbo.BI_FACTURACION_LOGISTICA
                 WHERE FECHA_COMP >= DATEADD(DAY, -30, @HOY)
                   AND FECHA_COMP  < @HOY
                   AND (@USUARIO IS NULL OR USUARIO = @USUARIO))
            , 0)
        AS DECIMAL(18,2))                                               AS UNIDADES_ULT30
    FROM #FactBase;

    -- ── Result set 2: evolución diaria (gráfico) ──────────────────────────
    SELECT
        FECHA_COMP,
        CAST(SUM(Unidades) AS DECIMAL(18,2)) AS UNIDADES_DIA
    FROM #FactBase
    GROUP BY FECHA_COMP
    ORDER BY FECHA_COMP;

    -- ── Result set 3: tabla por usuario ───────────────────────────────────
    -- PERCENTILE_CONT OVER (PARTITION BY) tampoco puede mezclarse con GROUP BY;
    -- se pre-calcula con DISTINCT sobre la temp table.
    ;WITH Percentiles AS (
        SELECT DISTINCT
            USUARIO,
            CAST(
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY Unidades)
                OVER (PARTITION BY USUARIO)
            AS DECIMAL(18,2)) AS MEDIANA
        FROM #FactBase
    )
    SELECT
        f.USUARIO,
        CAST(SUM(f.Unidades) AS DECIMAL(18,2))                AS UNIDADES_FACT,
        COUNT(DISTINCT f.FECHA_COMP)                          AS DIAS_PRODUCTIVOS,
        CAST(AVG(CAST(f.Unidades AS FLOAT)) AS DECIMAL(18,2)) AS PROMEDIO,
        p.MEDIANA,
        CAST(MAX(f.Unidades) AS DECIMAL(18,2))                AS MODA
    FROM #FactBase f
    JOIN Percentiles p ON p.USUARIO = f.USUARIO
    GROUP BY f.USUARIO, p.MEDIANA
    ORDER BY UNIDADES_FACT DESC;

    DROP TABLE #FactBase;
END;
GO
