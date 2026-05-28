USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 08_sp_pedidos_consolidados.sql
-- RO_SP_PEDIDOS_CONSOLIDADOS
-- Área 7: Pedidos consolidados (desde BI_EFICIENCIA_LOGISTICA).
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_PEDIDOS_CONSOLIDADOS','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_PEDIDOS_CONSOLIDADOS;
GO

CREATE PROCEDURE dbo.RO_SP_PEDIDOS_CONSOLIDADOS
    @FECHA_DESDE DATE,
    @FECHA_HASTA DATE,
    @CANAL       NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @AA_DESDE DATE = DATEADD(YEAR, -1, @FECHA_DESDE);
    DECLARE @AA_HASTA DATE = DATEADD(YEAR, -1, @FECHA_HASTA);
    -- Ventana fija 12 meses para el gráfico de evolución (independiente del filtro)
    DECLARE @EVOL_DESDE DATE = DATEFROMPARTS(
        YEAR(DATEADD(MONTH, -11, @FECHA_HASTA)),
        MONTH(DATEADD(MONTH, -11, @FECHA_HASTA)), 1
    );

    -- ── Reconstrucción PEDIDOS_CONSOLIDADOS ───────────────────────────────
    ;WITH Consolidados AS (
        SELECT
            NRO_PEDIDO,
            CANAL,
            ESTADO_TANGO          AS ESTADO,
            FECHA_PEDI,
            TALON_PED,
            SUM(CANT_PEDID)       AS UNID_PEDIDO,
            SUM(CANT_PEND)        AS UNID_PENDIENTES
        FROM dbo.BI_EFICIENCIA_LOGISTICA
        WHERE (@CANAL IS NULL OR CANAL = @CANAL)
        GROUP BY NRO_PEDIDO, CANAL, ESTADO_TANGO, FECHA_PEDI, TALON_PED
    )

    -- ── Result set 1: KPIs ────────────────────────────────────────────────
    SELECT
        COUNT(DISTINCT CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                            THEN NRO_PEDIDO END)                            AS PEDIDOS,

        COUNT(DISTINCT CASE WHEN FECHA_PEDI BETWEEN @AA_DESDE AND @AA_HASTA
                            THEN NRO_PEDIDO END)                            AS PEDIDOS_AA,

        COUNT(DISTINCT CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                            THEN NRO_PEDIDO END)
        - COUNT(DISTINCT CASE WHEN FECHA_PEDI BETWEEN @AA_DESDE AND @AA_HASTA
                              THEN NRO_PEDIDO END)                          AS PEDIDOS_VAR,

        CAST(ISNULL(SUM(CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN UNID_PEDIDO END), 0) AS DECIMAL(18,2))       AS UNID_PEDIDO,

        CAST(ISNULL(SUM(CASE WHEN FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                              AND ESTADO <> 'SIN FACTURAR'
                         THEN UNID_PEDIDO - UNID_PENDIENTES END), 0) AS DECIMAL(18,2))
                                                                            AS UNID_FACT
    FROM Consolidados
    WHERE FECHA_PEDI BETWEEN @AA_DESDE AND @FECHA_HASTA;

    -- ── Result set 2: evolución mensual de pedidos ────────────────────────
    SELECT
        c.ANIO,
        c.MES,
        c.NOMBRE_MES,
        COUNT(DISTINCT CASE WHEN co.FECHA_PEDI = c.FECHA THEN co.NRO_PEDIDO END) AS PEDIDOS_MES
    FROM dbo.RO_T_CALENDARIO c
    LEFT JOIN (
        SELECT NRO_PEDIDO, FECHA_PEDI, CANAL, ESTADO_TANGO
        FROM dbo.BI_EFICIENCIA_LOGISTICA
        WHERE (@CANAL IS NULL OR CANAL = @CANAL)
        GROUP BY NRO_PEDIDO, FECHA_PEDI, CANAL, ESTADO_TANGO
    ) co ON co.FECHA_PEDI = c.FECHA
    WHERE c.FECHA BETWEEN @EVOL_DESDE AND @FECHA_HASTA
    GROUP BY c.ANIO, c.MES, c.NOMBRE_MES
    ORDER BY c.ANIO, c.MES;

    -- ── Result set 3: tabla de pedidos consolidados ───────────────────────
    SELECT TOP 1000
        NRO_PEDIDO,
        CANAL,
        ESTADO,
        FECHA_PEDI,
        TALON_PED,
        CAST(UNID_PEDIDO AS DECIMAL(18,2))      AS UNID_PEDIDO,
        CAST(UNID_PENDIENTES AS DECIMAL(18,2))  AS UNID_PENDIENTES,
        CAST(CASE WHEN ESTADO <> 'SIN FACTURAR'
                  THEN UNID_PEDIDO - UNID_PENDIENTES ELSE 0 END AS DECIMAL(18,2))
                                                AS UNID_FACTURADAS
    FROM (
        SELECT
            NRO_PEDIDO, CANAL, ESTADO_TANGO AS ESTADO, FECHA_PEDI, TALON_PED,
            SUM(CANT_PEDID) AS UNID_PEDIDO,
            SUM(CANT_PEND)  AS UNID_PENDIENTES
        FROM dbo.BI_EFICIENCIA_LOGISTICA
        WHERE FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
          AND (@CANAL IS NULL OR CANAL = @CANAL)
        GROUP BY NRO_PEDIDO, CANAL, ESTADO_TANGO, FECHA_PEDI, TALON_PED
    ) t
    ORDER BY FECHA_PEDI DESC, NRO_PEDIDO;

    -- ── Result set 4: canales disponibles (slicer) ────────────────────────
    SELECT DISTINCT CANAL
    FROM dbo.BI_EFICIENCIA_LOGISTICA
    WHERE CANAL IS NOT NULL AND LTRIM(RTRIM(CANAL)) <> ''
    ORDER BY CANAL;
END;
GO
