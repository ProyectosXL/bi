USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 03_sp_leadtime.sql
-- RO_SP_LEADTIME_FACTURACION
-- Area 2: Lead time de facturacion.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_LEADTIME_FACTURACION','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_LEADTIME_FACTURACION;
GO

CREATE PROCEDURE dbo.RO_SP_LEADTIME_FACTURACION
    @FECHA_DESDE DATE,
    @FECHA_HASTA DATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @EVOL_DESDE DATE = DATEFROMPARTS(
        YEAR(DATEADD(MONTH, -11, @FECHA_HASTA)),
        MONTH(DATEADD(MONTH, -11, @FECHA_HASTA)),
        1
    );

    -- Filtrar una sola vez el periodo pedido. La version anterior calculaba
    -- KPIs con CASE sobre toda la tabla, lo que podia disparar timeout.
    SELECT
        FECHA_COMP,
        N_COMP,
        LEAD_TIME_FACT
    INTO #LeadPeriodo
    FROM dbo.BI_KPI_LOG_FACTURACION
    WHERE FECHA_COMP BETWEEN @FECHA_DESDE AND @FECHA_HASTA;

    CREATE NONCLUSTERED INDEX IX_LeadPeriodo_Dias ON #LeadPeriodo (LEAD_TIME_FACT, N_COMP);

    -- Result set 1: KPIs
    SELECT
        (SELECT COUNT(DISTINCT N_COMP) FROM #LeadPeriodo) AS COMP_FACTURADOS,
        (SELECT COUNT(DISTINCT CASE WHEN LEAD_TIME_FACT > 5 THEN N_COMP END) FROM #LeadPeriodo) AS COMP_DEMORADOS,
        p.PEDIDOS_ABIERTOS,
        (SELECT CAST(ISNULL(AVG(CAST(LEAD_TIME_FACT AS FLOAT)), 0) AS DECIMAL(10,2)) FROM #LeadPeriodo) AS LEAD_TIME_PROMEDIO
    FROM (
        SELECT COUNT(DISTINCT NRO_PEDIDO) AS PEDIDOS_ABIERTOS
        FROM dbo.BI_KPI_LOG_FACTURACION
        WHERE ESTADO_TANGO = 'PENDIENTE'
          AND NRO_PEDIDO IS NOT NULL
    ) p;

    -- Result set 2: distribucion de lead times
    SELECT
        LEAD_TIME_FACT AS DIAS,
        COUNT(DISTINCT N_COMP) AS CANTIDAD
    FROM #LeadPeriodo
    WHERE LEAD_TIME_FACT IS NOT NULL
    GROUP BY LEAD_TIME_FACT
    ORDER BY LEAD_TIME_FACT;

    -- Result set 3: evolucion mensual de % demorados, ultimos 12 meses
    SELECT
        c.ANIO,
        c.MES,
        c.NOMBRE_MES,
        COUNT(DISTINCT k.N_COMP) AS TOTAL_MES,
        COUNT(DISTINCT CASE WHEN k.LEAD_TIME_FACT > 5 THEN k.N_COMP END) AS DEMORADOS_MES
    FROM dbo.RO_T_CALENDARIO c
    LEFT JOIN (
        SELECT FECHA_COMP, N_COMP, LEAD_TIME_FACT
        FROM dbo.BI_KPI_LOG_FACTURACION
        WHERE FECHA_COMP BETWEEN @EVOL_DESDE AND @FECHA_HASTA
    ) k ON k.FECHA_COMP = c.FECHA
    WHERE c.FECHA BETWEEN @EVOL_DESDE AND @FECHA_HASTA
    GROUP BY c.ANIO, c.MES, c.NOMBRE_MES
    ORDER BY c.ANIO, c.MES;

    DROP TABLE #LeadPeriodo;
END;
GO
