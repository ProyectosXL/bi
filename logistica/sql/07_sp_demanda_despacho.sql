USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 07_sp_demanda_despacho.sql
-- RO_SP_DEMANDA_DESPACHO
-- Área 6: Demanda y despacho (BI_T_DESPACHO_PEDIDOS + RO_T_DESPACHO_PEDIDOS).
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_DEMANDA_DESPACHO','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_DEMANDA_DESPACHO;
GO

CREATE PROCEDURE dbo.RO_SP_DEMANDA_DESPACHO
    @FECHA_DESDE DATE,
    @FECHA_HASTA DATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @HOY DATE = CAST(GETDATE() AS DATE);
    DECLARE @HOY_FIN DATE = DATEADD(DAY, 1, @HOY);

    -- ── Próximo día hábil ─────────────────────────────────────────────────
    DECLARE @PROX_HABIL DATE;
    SELECT TOP 1 @PROX_HABIL = FECHA
    FROM dbo.RO_T_CALENDARIO
    WHERE FECHA > @HOY AND DIA_LABORAL = 1
    ORDER BY FECHA;

    -- "+1": segundo día hábil a partir de hoy (próximo hábil + 1)
    DECLARE @MAS_UNO DATE;
    SELECT TOP 1 @MAS_UNO = FECHA
    FROM dbo.RO_T_CALENDARIO
    WHERE FECHA > @PROX_HABIL AND DIA_LABORAL = 1
    ORDER BY FECHA;

    -- ── PromedioUltimos7Dias de picking (para pickers necesarios) ─────────
    DECLARE @PROM_PICK_7D FLOAT;
    SELECT @PROM_PICK_7D = AVG(CAST(Unidades AS FLOAT))
    FROM (
        SELECT FECHA_INI_PICKING, USUARIO,
               SUM(CANT_PICKING) AS Unidades,
               SUM(MINUTOS)      AS Minutos
        FROM dbo.BI_T_TRACKING_PICKING
        WHERE FECHA_INI_PICKING >= DATEADD(DAY, -7, @HOY)
          AND FECHA_INI_PICKING <  @HOY
        GROUP BY FECHA_INI_PICKING, USUARIO
        HAVING SUM(MINUTOS) >= 180
    ) t;

    -- Unidades pendientes del próximo día hábil
    DECLARE @UNID_PEND_PROX DECIMAL(18,2);
    SELECT @UNID_PEND_PROX = ISNULL(SUM(CAST(CANT_PEDIDO AS DECIMAL(18,2))), 0)
    FROM dbo.BI_T_DESPACHO_PEDIDOS
    WHERE ESTADO = 'PENDIENTE'
      AND FECHA_PEDIDO >= @PROX_HABIL
      AND FECHA_PEDIDO <  DATEADD(DAY, 1, @PROX_HABIL);

    -- ── Result set 1: KPIs demanda + despacho ────────────────────────────
    SELECT
        -- Demanda (BI_T_DESPACHO_PEDIDOS)
        COUNT(NRO_PEDIDO)                                       AS PED_TOTALES,
        CAST(ISNULL(SUM(CANT_PEDIDO),0) AS DECIMAL(18,2))      AS UNID_TOTALES,
        CAST(ISNULL(SUM(CASE WHEN ESTADO = 'PENDIENTE' THEN CANT_PEDIDO END),0) AS DECIMAL(18,2))
                                                                AS UNID_PENDIENTES,
        COUNT(CASE WHEN ESTADO = 'PENDIENTE' THEN 1 END)       AS PED_PENDIENTES,
        CAST(0.97 AS DECIMAL(5,2))                             AS META_CUMPLIMIENTO,
        @PROX_HABIL                                            AS PROX_DIA_HABIL,
        @MAS_UNO                                               AS MAS_UNO,
        CAST(
            ISNULL(CEILING(@UNID_PEND_PROX / NULLIF(@PROM_PICK_7D, 0)), 0)
        AS INT)                                                AS PICKERS_NECESARIOS,
        -- Eficacia despacho (RO_T_DESPACHO_PEDIDOS)
        CAST(
            (SELECT CAST(COUNT(DISTINCT CASE WHEN ESTADO_DESPACHO = 'EN TERMINO' THEN N_COMP END) AS FLOAT)
                  / NULLIF(COUNT(DISTINCT CASE WHEN ESTADO_DESPACHO IN ('EN TERMINO','DEMORADO','FUERA DE PLAZO')
                                              THEN N_COMP END), 0)
             FROM dbo.RO_T_DESPACHO_PEDIDOS
             WHERE FECHA_GUIA BETWEEN @FECHA_DESDE AND @FECHA_HASTA)
        AS DECIMAL(10,4))                                      AS EFICACIA_DESPACHO
    FROM dbo.BI_T_DESPACHO_PEDIDOS
    WHERE FECHA_PEDIDO BETWEEN @FECHA_DESDE AND @FECHA_HASTA;

    -- ── Result set 2: pendientes hoy ──────────────────────────────────────
    SELECT TOP 500
        NRO_PEDIDO,
        COD_CLIENT,
        NOMBRE_CLIENTE,
        CAST(FECHA_PEDIDO AS DATE) AS FECHA_PEDIDO,
        CAST(ISNULL(SUM(CANT_PEDIDO),0) AS DECIMAL(18,2)) AS UNIDADES
    FROM dbo.BI_T_DESPACHO_PEDIDOS
    WHERE ESTADO = 'PENDIENTE'
      AND FECHA_PEDIDO >= @HOY
      AND FECHA_PEDIDO <  @HOY_FIN
    GROUP BY NRO_PEDIDO, COD_CLIENT, NOMBRE_CLIENTE, CAST(FECHA_PEDIDO AS DATE)
    ORDER BY UNIDADES DESC;

    -- ── Result set 3: pedidos demorados ───────────────────────────────────
    SELECT TOP 500
        p.NRO_PEDIDO,
        p.COD_CLIENT,
        p.NOMBRE_CLIENTE,
        CAST(p.FECHA_PEDIDO AS DATE)   AS FECHA_PEDIDO,
        CAST(ISNULL(SUM(p.CANT_PEDIDO),0) AS DECIMAL(18,2)) AS UNIDADES,
        d.ESTADO_DESPACHO
    FROM dbo.BI_T_DESPACHO_PEDIDOS p
    LEFT JOIN dbo.RO_T_DESPACHO_PEDIDOS d ON d.NRO_PEDIDO = p.NRO_PEDIDO
    WHERE p.FECHA_PEDIDO BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND d.ESTADO_DESPACHO IN ('DEMORADO','FUERA DE PLAZO')
    GROUP BY p.NRO_PEDIDO, p.COD_CLIENT, p.NOMBRE_CLIENTE,
             CAST(p.FECHA_PEDIDO AS DATE), d.ESTADO_DESPACHO
    ORDER BY FECHA_PEDIDO ASC;

    -- ── Result set 4: pedidos PENDIENTE del período seleccionado (cola) ─────
    SELECT TOP 500
        NRO_PEDIDO,
        COD_CLIENT,
        NOMBRE_CLIENTE,
        CAST(FECHA_PEDIDO AS DATE) AS FECHA_PEDIDO,
        CAST(ISNULL(SUM(CANT_PEDIDO),0) AS DECIMAL(18,2)) AS UNIDADES
    FROM dbo.BI_T_DESPACHO_PEDIDOS
    WHERE ESTADO = 'PENDIENTE'
      AND FECHA_PEDIDO BETWEEN @FECHA_DESDE AND @FECHA_HASTA
    GROUP BY NRO_PEDIDO, COD_CLIENT, NOMBRE_CLIENTE, CAST(FECHA_PEDIDO AS DATE)
    ORDER BY FECHA_PEDIDO ASC, UNIDADES DESC;
END;
GO
