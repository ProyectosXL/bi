USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 11_sp_pedidos_estancados.sql
-- RO_SP_PEDIDOS_ESTANCADOS
-- Pedidos estancados: no cancelados, con saldo pendiente y con
-- antigüedad >= @DIAS. Lista operativa para depurar (cerrar en
-- Tango) o reclamar.
--
-- Situación actual: NO depende del rango de fechas del toolbar.
-- Clave de pedido: FECHA_PEDI + TALON_PED + NRO_PEDIDO + CANAL + CLIENTE
-- (mismo criterio que el bloque 8 de diag_eficiencia_dia.sql; evita
-- mezclar pedidos con igual número y distinto talón).
--
-- SITUACION: 'SIN REMITIR' = ninguna unidad remitida (pedidas = pendientes)
--            'PARCIAL'     = remitido en parte, con saldo pendiente
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_PEDIDOS_ESTANCADOS','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_PEDIDOS_ESTANCADOS;
GO

CREATE PROCEDURE dbo.RO_SP_PEDIDOS_ESTANCADOS
    @DIAS  INT           = 90,     -- antigüedad mínima en días corridos
    @CANAL NVARCHAR(100) = NULL    -- NULL = todos los canales
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @HOY   DATE = CAST(GETDATE() AS DATE);
    DECLARE @CORTE DATE = DATEADD(DAY, -@DIAS, @HOY);

    -- ── Base: un renglón por pedido ───────────────────────────────────────
    SELECT
        e.FECHA_PEDI,
        e.TALON_PED,
        LTRIM(RTRIM(e.NRO_PEDIDO))                           AS NRO_PEDIDO,
        e.CANAL,
        LTRIM(RTRIM(e.CLIENTE))                              AS CLIENTE,
        MAX(ISNULL(e.ESTADO_TANGO, '(NULL)'))                AS ESTADO_TANGO,
        MAX(ISNULL(e.TIPO_FACTURACION, '(NULL)'))            AS TIPO_FACTURACION,
        SUM(ISNULL(e.CANT_PEDID, 0))                         AS UNID_PEDIDAS,
        SUM(ISNULL(e.CANT_PEND, 0))                          AS UNID_PENDIENTES,
        SUM(ISNULL(e.IMPORTE_PEDIDO, 0))                     AS IMPORTE_PEDIDO,
        SUM(ISNULL(e.IMPORTE_PENDIENTE, 0))                  AS IMPORTE_PENDIENTE
    INTO #P
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    WHERE e.FECHA_PEDI <= @CORTE
      AND ISNULL(e.ESTADO_TANGO, '') <> 'CANCELADO'
      AND (@CANAL IS NULL OR e.CANAL = @CANAL)
    GROUP BY e.FECHA_PEDI, e.TALON_PED, LTRIM(RTRIM(e.NRO_PEDIDO)), e.CANAL, LTRIM(RTRIM(e.CLIENTE))
    HAVING SUM(ISNULL(e.CANT_PEND, 0)) > 0;

    ALTER TABLE #P ADD
        DIAS      INT,
        SITUACION VARCHAR(12),
        TRAMO     VARCHAR(12),
        TRAMO_ORD TINYINT;

    UPDATE #P SET
        DIAS      = DATEDIFF(DAY, FECHA_PEDI, @HOY),
        SITUACION = CASE WHEN UNID_PEDIDAS = UNID_PENDIENTES THEN 'SIN REMITIR' ELSE 'PARCIAL' END;

    UPDATE #P SET
        TRAMO     = CASE WHEN DIAS <  90 THEN '60-90'
                         WHEN DIAS < 180 THEN '90-180'
                         WHEN DIAS < 365 THEN '180-365'
                         ELSE                 '> 365' END,
        TRAMO_ORD = CASE WHEN DIAS <  90 THEN 1
                         WHEN DIAS < 180 THEN 2
                         WHEN DIAS < 365 THEN 3
                         ELSE                 4 END;

    -- ── Result set 1: KPIs ────────────────────────────────────────────────
    SELECT
        COUNT(*)                                                             AS PEDIDOS,
        CAST(ISNULL(SUM(UNID_PENDIENTES), 0)   AS DECIMAL(18,0))             AS UNID_PENDIENTES,
        CAST(ISNULL(SUM(IMPORTE_PENDIENTE), 0) AS DECIMAL(18,2))             AS IMPORTE_PENDIENTE,
        CAST(ISNULL(AVG(CAST(DIAS AS FLOAT)), 0) AS DECIMAL(10,1))           AS DIAS_PROMEDIO,
        SUM(CASE WHEN SITUACION = 'SIN REMITIR' THEN 1 ELSE 0 END)           AS PED_SIN_REMITIR,
        SUM(CASE WHEN SITUACION = 'PARCIAL'     THEN 1 ELSE 0 END)           AS PED_PARCIAL,
        CAST(ISNULL(SUM(CASE WHEN SITUACION = 'SIN REMITIR' THEN UNID_PENDIENTES END), 0) AS DECIMAL(18,0))   AS UNID_SIN_REMITIR,
        CAST(ISNULL(SUM(CASE WHEN SITUACION = 'PARCIAL'     THEN UNID_PENDIENTES END), 0) AS DECIMAL(18,0))   AS UNID_PARCIAL,
        CAST(ISNULL(SUM(CASE WHEN SITUACION = 'SIN REMITIR' THEN IMPORTE_PENDIENTE END), 0) AS DECIMAL(18,2)) AS IMP_SIN_REMITIR,
        CAST(ISNULL(SUM(CASE WHEN SITUACION = 'PARCIAL'     THEN IMPORTE_PENDIENTE END), 0) AS DECIMAL(18,2)) AS IMP_PARCIAL,
        MAX(DIAS)                                                            AS DIAS_MAX,
        @DIAS                                                                AS UMBRAL
    FROM #P;

    -- ── Result set 2: tramos de antigüedad ────────────────────────────────
    SELECT
        TRAMO,
        COUNT(*)                                                  AS PEDIDOS,
        SUM(CASE WHEN SITUACION = 'SIN REMITIR' THEN 1 ELSE 0 END) AS PED_SIN_REMITIR,
        CAST(SUM(UNID_PENDIENTES)   AS DECIMAL(18,0))             AS UNID_PENDIENTES,
        CAST(SUM(IMPORTE_PENDIENTE) AS DECIMAL(18,2))             AS IMPORTE_PENDIENTE
    FROM #P
    GROUP BY TRAMO, TRAMO_ORD
    ORDER BY TRAMO_ORD;

    -- ── Result set 3: concentración por cliente (por dónde empezar) ───────
    SELECT TOP 30
        CANAL,
        CLIENTE,
        COUNT(*)                                        AS PEDIDOS,
        CAST(SUM(UNID_PENDIENTES)   AS DECIMAL(18,0))   AS UNID_PENDIENTES,
        CAST(SUM(IMPORTE_PENDIENTE) AS DECIMAL(18,2))   AS IMPORTE_PENDIENTE,
        MIN(FECHA_PEDI)                                 AS PEDIDO_MAS_VIEJO,
        MAX(DIAS)                                       AS DIAS_MAX
    FROM #P
    GROUP BY CANAL, CLIENTE
    ORDER BY IMPORTE_PENDIENTE DESC;

    -- ── Result set 4: listado pedido por pedido ───────────────────────────
    SELECT TOP 3000
        NRO_PEDIDO,
        TALON_PED,
        FECHA_PEDI,
        DIAS,
        CANAL,
        CLIENTE,
        TIPO_FACTURACION,
        ESTADO_TANGO,
        SITUACION,
        CAST(UNID_PEDIDAS      AS DECIMAL(18,0)) AS UNID_PEDIDAS,
        CAST(UNID_PENDIENTES   AS DECIMAL(18,0)) AS UNID_PENDIENTES,
        CAST(IMPORTE_PEDIDO    AS DECIMAL(18,2)) AS IMPORTE_PEDIDO,
        CAST(IMPORTE_PENDIENTE AS DECIMAL(18,2)) AS IMPORTE_PENDIENTE
    FROM #P
    ORDER BY IMPORTE_PENDIENTE DESC, DIAS DESC;

    DROP TABLE #P;
END;
GO
