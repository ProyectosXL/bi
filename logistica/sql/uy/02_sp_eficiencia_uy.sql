USE [POWER_BI_CONTROL_URUGUAY];
GO
-- ============================================================
-- 02_sp_eficiencia_uy.sql
-- RO_SP_EFICIENCIA_LOGISTICA_UY
-- Pestaña 1 UY: KPIs de eficiencia + eficiencia por rubro
--               + evolución 24 meses + canales disponibles.
-- Universo: BI_T_EFICIENCIA_LOGISTICA_UY, ESTADO_TANGO <> 'CANCELADO'.
-- Lógica directa (sin reconstrucción de estado como en AR).
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_EFICIENCIA_LOGISTICA_UY','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_EFICIENCIA_LOGISTICA_UY;
GO

CREATE PROCEDURE dbo.RO_SP_EFICIENCIA_LOGISTICA_UY
    @FECHA_DESDE    DATE,
    @FECHA_HASTA    DATE,
    @CANAL          NVARCHAR(100) = NULL,
    @RUBRO          NVARCHAR(100) = NULL,
    @COTIZACION_USD DECIMAL(18,4) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- Rango evolución: mismo mes del año anterior hasta @FECHA_HASTA
    DECLARE @EVOL_DESDE DATE = DATEFROMPARTS(YEAR(@FECHA_HASTA) - 1, MONTH(@FECHA_HASTA), 1);

    -- ── Result set 1: KPIs globales ───────────────────────────────────────
    SELECT
        -- Eficiencia (ratios calculados en PHP: EFI = fact/ped, PCT_PERDIDA = perdida/ped)
        CAST(ISNULL(SUM(CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN CANT_FACTURADA END), 0) AS DECIMAL(18,2))  AS UNID_FACTURADAS,

        CAST(ISNULL(SUM(CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN CANT_PEDID END), 0) AS DECIMAL(18,2))      AS UNID_PEDIDAS,

        CAST(ISNULL(SUM(CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN CANT_PEND END), 0) AS DECIMAL(18,2))       AS UNID_PENDIENTES,

        CAST(ISNULL(SUM(CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN ISNULL(IMPORTE_PENDIENTE, 0) END), 0) AS DECIMAL(18,2)) AS PERDIDA_UYU,

        -- Pérdida en USD (solo si se proveyó cotización)
        CASE
            WHEN @COTIZACION_USD IS NULL OR @COTIZACION_USD = 0 THEN NULL
            ELSE CAST(
                ISNULL(SUM(CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                            THEN ISNULL(IMPORTE_PENDIENTE, 0) END), 0)
                / @COTIZACION_USD
            AS DECIMAL(18,2))
        END AS PERDIDA_USD,

        CAST(ISNULL(SUM(CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN ISNULL(IMPORTE_PEDIDO, 0) END), 0) AS DECIMAL(18,2))    AS IMPORTE_PEDIDO_TOTAL,

        CAST(ISNULL(SUM(CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN ISNULL(IMPORTE_PEDIDO, 0) - ISNULL(IMPORTE_PENDIENTE, 0) END), 0)
             AS DECIMAL(18,2)) AS IMPORTE_FACTURADO,

        COUNT(DISTINCT CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                            THEN NRO_PEDIDO END)                          AS PEDIDOS_TOTAL,

        @COTIZACION_USD                                                   AS COTIZACION_USD,
        CAST(0.95 AS DECIMAL(5,2))                                        AS META_EFICIENCIA

    FROM dbo.BI_T_EFICIENCIA_LOGISTICA_UY
    WHERE ESTADO_TANGO <> 'CANCELADO'
      AND (@CANAL IS NULL OR CANAL COLLATE Modern_Spanish_CI_AI = @CANAL COLLATE Modern_Spanish_CI_AI)
      AND (@RUBRO IS NULL OR RUBRO COLLATE Modern_Spanish_CI_AI = @RUBRO COLLATE Modern_Spanish_CI_AI);

    -- ── Result set 2: eficiencia por rubro (período actual) ───────────────
    SELECT
        RUBRO COLLATE Modern_Spanish_CI_AI                                AS RUBRO,
        CAST(ISNULL(SUM(CANT_FACTURADA), 0) AS DECIMAL(18,2))            AS UNID_FACTURADAS,
        CAST(ISNULL(SUM(CANT_PEDID),     0) AS DECIMAL(18,2))            AS UNID_PEDIDAS,
        CAST(
            ISNULL(SUM(CANT_FACTURADA), 0.0)
            / NULLIF(SUM(CANT_PEDID), 0)
        AS DECIMAL(10,4))                                                 AS EFI
    FROM dbo.BI_T_EFICIENCIA_LOGISTICA_UY
    WHERE ESTADO_TANGO <> 'CANCELADO'
      AND FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND (@CANAL IS NULL OR CANAL COLLATE Modern_Spanish_CI_AI = @CANAL COLLATE Modern_Spanish_CI_AI)
      AND (@RUBRO IS NULL OR RUBRO COLLATE Modern_Spanish_CI_AI = @RUBRO COLLATE Modern_Spanish_CI_AI)
      AND RUBRO IS NOT NULL AND LTRIM(RTRIM(RUBRO)) <> ''
    GROUP BY RUBRO
    ORDER BY SUM(CANT_PEDID) DESC;

    -- ── Result set 3: evolución mensual — 24 meses ────────────────────────
    -- LEFT JOIN a calendario para preservar meses sin datos.
    SELECT
        c.ANIO,
        c.MES,
        c.NOMBRE_MES,
        CAST(ISNULL(SUM(e.CANT_FACTURADA), 0) AS DECIMAL(18,2)) AS UNID_FACTURADAS_MES,
        CAST(ISNULL(SUM(e.CANT_PEDID),     0) AS DECIMAL(18,2)) AS UNID_PEDIDAS_MES
    FROM dbo.RO_T_CALENDARIO_UY c
    LEFT JOIN dbo.BI_T_EFICIENCIA_LOGISTICA_UY e
           ON e.FECHA_PEDI = c.FECHA
          AND e.ESTADO_TANGO <> 'CANCELADO'
          AND (@CANAL IS NULL OR e.CANAL COLLATE Modern_Spanish_CI_AI = @CANAL COLLATE Modern_Spanish_CI_AI)
          AND (@RUBRO IS NULL OR e.RUBRO COLLATE Modern_Spanish_CI_AI = @RUBRO COLLATE Modern_Spanish_CI_AI)
    WHERE c.FECHA BETWEEN @EVOL_DESDE AND @FECHA_HASTA
    GROUP BY c.ANIO, c.MES, c.NOMBRE_MES
    ORDER BY c.ANIO, c.MES;

    -- ── Result set 4: canales disponibles (slicer) ────────────────────────
    SELECT DISTINCT CANAL COLLATE Modern_Spanish_CI_AI AS CANAL
    FROM dbo.BI_T_EFICIENCIA_LOGISTICA_UY
    WHERE CANAL IS NOT NULL AND LTRIM(RTRIM(CANAL)) <> ''
    ORDER BY CANAL;

    -- ── Result set 5: eficiencia semanal — últimas 12 semanas ────────────
    SELECT
        DATEPART(ISO_WEEK, e.FECHA_PEDI)                                   AS SEMANA,
        YEAR(e.FECHA_PEDI)                                                  AS ANIO,
        MIN(CAST(e.FECHA_PEDI AS DATE))                                     AS FECHA_INICIO,
        CAST(ISNULL(SUM(e.CANT_FACTURADA), 0) AS DECIMAL(18,2))            AS UNID_FACTURADAS_SEM,
        CAST(ISNULL(SUM(e.CANT_PEDID),     0) AS DECIMAL(18,2))            AS UNID_PEDIDAS_SEM
    FROM dbo.BI_T_EFICIENCIA_LOGISTICA_UY e
    WHERE e.FECHA_PEDI BETWEEN DATEADD(WEEK, -12, @FECHA_HASTA) AND @FECHA_HASTA
      AND e.ESTADO_TANGO <> 'CANCELADO'
      AND (@CANAL IS NULL OR e.CANAL COLLATE Modern_Spanish_CI_AI = @CANAL COLLATE Modern_Spanish_CI_AI)
      AND (@RUBRO IS NULL OR e.RUBRO COLLATE Modern_Spanish_CI_AI = @RUBRO COLLATE Modern_Spanish_CI_AI)
    GROUP BY DATEPART(ISO_WEEK, e.FECHA_PEDI), YEAR(e.FECHA_PEDI)
    ORDER BY ANIO, SEMANA;

END;
GO

PRINT 'SP RO_SP_EFICIENCIA_LOGISTICA_UY creado correctamente.';
GO
