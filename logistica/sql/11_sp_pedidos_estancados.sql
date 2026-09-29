USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 11_sp_pedidos_estancados.sql
-- RO_SP_PEDIDOS_ESTANCADOS
-- Pedidos estancados: no cancelados, con saldo pendiente, del AÑO EN
-- CURSO y con antigüedad >= @DIAS. Pestaña de ANÁLISIS (no sugiere
-- acciones): cuánto hay trabado, si hay stock para el saldo, desde
-- cuándo se acumula y dónde se concentra.
--
-- Situación actual: NO depende del rango de fechas del toolbar.
-- Clave de pedido: FECHA_PEDI + TALON_PED + NRO_PEDIDO + CANAL + CLIENTE
-- (mismo criterio que el bloque 8 de diag_eficiencia_dia.sql).
--
-- SITUACION: 'SIN REMITIR' = ninguna unidad remitida
--            'PARCIAL'     = remitido en parte, con saldo pendiente
--
-- Stock: BI_STOCK_WMS_TANGO, stock Tango del depósito 01, por artículo.
-- Se compara cada renglón pendiente contra el stock del artículo SIN
-- descontar lo que piden otros pedidos: es una señal, no una reserva.
--
-- COBERTURA de stock del saldo (descriptiva):
--   CON STOCK     = hay stock del artículo para todas las unidades pendientes
--   STOCK PARCIAL = hay stock solo para parte de las unidades pendientes
--   SIN STOCK     = no hay stock para ninguna unidad pendiente
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

    DECLARE @HOY        DATE = CAST(GETDATE() AS DATE);
    DECLARE @CORTE      DATE = DATEADD(DAY, -@DIAS, @HOY);
    DECLARE @INICIO_ANIO DATE = DATEFROMPARTS(YEAR(@HOY), 1, 1);

    -- ── Renglones pendientes (pedido + artículo) ──────────────────────────
    SELECT
        e.FECHA_PEDI,
        e.TALON_PED,
        LTRIM(RTRIM(e.NRO_PEDIDO))            AS NRO_PEDIDO,
        e.CANAL,
        LTRIM(RTRIM(e.CLIENTE))               AS CLIENTE,
        e.COD_ARTICU,
        ISNULL(e.ESTADO_TANGO, '(NULL)')      AS ESTADO_TANGO,
        ISNULL(e.TIPO_FACTURACION, '(NULL)')  AS TIPO_FACTURACION,
        ISNULL(e.CANT_PEDID, 0)               AS CANT_PEDID,
        ISNULL(e.CANT_PEND, 0)                AS CANT_PEND,
        ISNULL(e.IMPORTE_PEDIDO, 0)           AS IMPORTE_PEDIDO,
        ISNULL(e.IMPORTE_PENDIENTE, 0)        AS IMPORTE_PENDIENTE
    INTO #L
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    WHERE e.FECHA_PEDI >= @INICIO_ANIO
      AND e.FECHA_PEDI <= @CORTE
      AND ISNULL(e.ESTADO_TANGO, '') <> 'CANCELADO'
      AND (@CANAL IS NULL OR e.CANAL = @CANAL);

    -- Stock Tango del depósito 01 por artículo (solo los artículos con saldo)
    SELECT s.COD_ARTICU, SUM(ISNULL(s.STOCK_TANGO, 0)) AS STOCK
    INTO #S
    FROM dbo.BI_STOCK_WMS_TANGO s
    WHERE s.DEPOSITO = '01'
      AND s.COD_ARTICU IN (SELECT COD_ARTICU FROM #L WHERE CANT_PEND > 0)
    GROUP BY s.COD_ARTICU;

    -- ── Un renglón por pedido ─────────────────────────────────────────────
    SELECT
        l.FECHA_PEDI, l.TALON_PED, l.NRO_PEDIDO, l.CANAL, l.CLIENTE,
        MAX(l.ESTADO_TANGO)                   AS ESTADO_TANGO,
        MAX(l.TIPO_FACTURACION)               AS TIPO_FACTURACION,
        SUM(l.CANT_PEDID)                     AS UNID_PEDIDAS,
        SUM(l.CANT_PEND)                      AS UNID_PENDIENTES,
        SUM(l.IMPORTE_PEDIDO)                 AS IMPORTE_PEDIDO,
        SUM(l.IMPORTE_PENDIENTE)              AS IMPORTE_PENDIENTE,
        -- unidades del saldo para las que hay stock del artículo
        SUM(CASE WHEN l.CANT_PEND <= 0 THEN 0
                 WHEN ISNULL(s.STOCK, 0) >= l.CANT_PEND THEN l.CANT_PEND
                 WHEN ISNULL(s.STOCK, 0) > 0 THEN s.STOCK
                 ELSE 0 END)                  AS UNID_CON_STOCK
    INTO #P
    FROM #L l
    LEFT JOIN #S s ON s.COD_ARTICU = l.COD_ARTICU
    GROUP BY l.FECHA_PEDI, l.TALON_PED, l.NRO_PEDIDO, l.CANAL, l.CLIENTE
    HAVING SUM(l.CANT_PEND) > 0;

    ALTER TABLE #P ADD
        DIAS      INT,
        SITUACION VARCHAR(12),
        COBERTURA VARCHAR(16),
        MES       VARCHAR(7);

    UPDATE #P SET
        DIAS      = DATEDIFF(DAY, FECHA_PEDI, @HOY),
        SITUACION = CASE WHEN UNID_PEDIDAS = UNID_PENDIENTES THEN 'SIN REMITIR' ELSE 'PARCIAL' END,
        MES       = CONVERT(VARCHAR(7), FECHA_PEDI, 23),
        COBERTURA = CASE
            WHEN UNID_CON_STOCK >= UNID_PENDIENTES             THEN 'CON STOCK'
            WHEN UNID_CON_STOCK <= 0                           THEN 'SIN STOCK'
            ELSE 'STOCK PARCIAL' END;

    -- ── Result set 1: KPIs ────────────────────────────────────────────────
    SELECT
        COUNT(*)                                                             AS PEDIDOS,
        CAST(ISNULL(SUM(UNID_PENDIENTES), 0)   AS DECIMAL(18,0))             AS UNID_PENDIENTES,
        CAST(ISNULL(SUM(IMPORTE_PENDIENTE), 0) AS DECIMAL(18,2))             AS IMPORTE_PENDIENTE,
        CAST(ISNULL(AVG(CAST(DIAS AS FLOAT)), 0) AS DECIMAL(10,1))           AS DIAS_PROMEDIO,
        MAX(DIAS)                                                            AS DIAS_MAX,
        SUM(CASE WHEN SITUACION = 'SIN REMITIR' THEN 1 ELSE 0 END)           AS PED_SIN_REMITIR,
        SUM(CASE WHEN SITUACION = 'PARCIAL'     THEN 1 ELSE 0 END)           AS PED_PARCIAL,
        -- ¿Por qué está trabado? stock para el saldo
        CAST(ISNULL(SUM(UNID_CON_STOCK), 0) AS DECIMAL(18,0))                AS UNID_CON_STOCK,
        CAST(ISNULL(SUM(UNID_PENDIENTES - UNID_CON_STOCK), 0) AS DECIMAL(18,0)) AS UNID_SIN_STOCK,
        -- Calidad de datos: Tango los marca COMPLETO pero figuran con saldo
        SUM(CASE WHEN ESTADO_TANGO = 'COMPLETO' THEN 1 ELSE 0 END)           AS PED_TANGO_COMPLETO,
        @DIAS                                                                AS UMBRAL,
        @INICIO_ANIO                                                         AS DESDE,
        @CORTE                                                               AS HASTA
    FROM #P;

    -- ── Result set 2: cobertura de stock del saldo ────────────────────────
    SELECT
        COBERTURA,
        CASE COBERTURA WHEN 'CON STOCK' THEN 1 WHEN 'STOCK PARCIAL' THEN 2 ELSE 3 END AS ORD,
        COUNT(*)                                                            AS PEDIDOS,
        CAST(SUM(UNID_PENDIENTES)   AS DECIMAL(18,0))                       AS UNID_PENDIENTES,
        CAST(SUM(UNID_CON_STOCK)    AS DECIMAL(18,0))                       AS UNID_CON_STOCK,
        CAST(SUM(IMPORTE_PENDIENTE) AS DECIMAL(18,2))                       AS IMPORTE_PENDIENTE
    FROM #P
    GROUP BY COBERTURA
    ORDER BY ORD;

    -- ── Result set 3: ¿desde cuándo se acumula? (mes de carga) ────────────
    SELECT
        MES,
        COUNT(*)                                        AS PEDIDOS,
        CAST(SUM(UNID_PENDIENTES)   AS DECIMAL(18,0))   AS UNID_PENDIENTES,
        CAST(SUM(IMPORTE_PENDIENTE) AS DECIMAL(18,2))   AS IMPORTE_PENDIENTE
    FROM #P
    GROUP BY MES
    ORDER BY MES;

    -- ── Result set 4: concentración por cliente (por dónde empezar) ───────
    SELECT TOP 30
        CANAL,
        CLIENTE,
        COUNT(*)                                        AS PEDIDOS,
        CAST(SUM(UNID_PENDIENTES)   AS DECIMAL(18,0))   AS UNID_PENDIENTES,
        CAST(SUM(IMPORTE_PENDIENTE) AS DECIMAL(18,2))   AS IMPORTE_PENDIENTE,
        SUM(CASE WHEN COBERTURA = 'CON STOCK' THEN 1 ELSE 0 END) AS PED_CON_STOCK,
        MAX(DIAS)                                       AS DIAS_MAX
    FROM #P
    GROUP BY CANAL, CLIENTE
    ORDER BY IMPORTE_PENDIENTE DESC;

    -- ── Result set 5: listado pedido por pedido ───────────────────────────
    SELECT TOP 3000
        NRO_PEDIDO, TALON_PED, FECHA_PEDI, DIAS, CANAL, CLIENTE,
        TIPO_FACTURACION, ESTADO_TANGO, SITUACION, COBERTURA,
        CAST(UNID_PEDIDAS      AS DECIMAL(18,0)) AS UNID_PEDIDAS,
        CAST(UNID_PENDIENTES   AS DECIMAL(18,0)) AS UNID_PENDIENTES,
        CAST(UNID_CON_STOCK    AS DECIMAL(18,0)) AS UNID_CON_STOCK,
        CAST(IMPORTE_PEDIDO    AS DECIMAL(18,2)) AS IMPORTE_PEDIDO,
        CAST(IMPORTE_PENDIENTE AS DECIMAL(18,2)) AS IMPORTE_PENDIENTE
    FROM #P
    ORDER BY IMPORTE_PENDIENTE DESC, DIAS DESC;

    DROP TABLE #L; DROP TABLE #S; DROP TABLE #P;
END;
GO
