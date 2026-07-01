USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 07_sp_planificacion.sql
-- RO_SP_PLANIFICACION
-- Área 6a: Planificación de despacho (demanda comprometida por ventana
-- de entrega). Fuente: BI_T_DESPACHO_PEDIDOS + RO_T_DESPACHO_PEDIDOS.
--
-- Ventanas (relativas a HOY, NO al rango de fechas del toolbar):
--   HOY      = día operativo actual
--   PROX     = próximo día hábil
--   MAS_UNO  = segundo día hábil (próximo hábil + 1)
--
-- Reemplaza a la mitad "demanda" del antiguo RO_SP_DEMANDA_DESPACHO.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_PLANIFICACION','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_PLANIFICACION;
GO

CREATE PROCEDURE dbo.RO_SP_PLANIFICACION
    @CANAL NVARCHAR(100) = NULL          -- NULL = todos los canales
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @HOY DATE = CAST(GETDATE() AS DATE);

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

    -- ── Pedidos sin ninguna actividad de facturación (excluir de demanda) ──
    -- Mismo criterio que RO_SP_EFICIENCIA_LOGISTICA: SUM(CANT_PEDID)=SUM(CANT_PEND)
    SELECT NRO_PEDIDO
    INTO #PedSinFacturar
    FROM dbo.BI_KPI_LOG_FACTURACION
    GROUP BY NRO_PEDIDO
    HAVING SUM(CANT_PEDID) = SUM(CANT_PEND);

    CREATE NONCLUSTERED INDEX IX_PedSF ON #PedSinFacturar (NRO_PEDIDO);

    -- ── Promedio últimos 7 días de picking (para pickers necesarios) ──────
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

    -- ── Result set 1: KPIs de cabecera ────────────────────────────────────
    -- PED_DEMORADOS: pedidos PENDIENTES cuya fecha de entrega comprometida ya
    -- venció (FECHA_ENTREGA < hoy) dentro de los últimos 30 días. Se calcula
    -- sobre BI_T_DESPACHO_PEDIDOS (incluye todos los canales, ej. ECOMMERCE).
    SELECT
        (SELECT COUNT(DISTINCT NRO_PEDIDO)
         FROM dbo.BI_T_DESPACHO_PEDIDOS
         WHERE ESTADO = 'PENDIENTE'
           AND FECHA_ENTREGA <  @HOY
           AND FECHA_ENTREGA >= DATEADD(DAY, -30, @HOY)
           AND (@CANAL IS NULL OR CANAL = @CANAL)
           AND NOT EXISTS (SELECT 1 FROM #PedSinFacturar sf WHERE sf.NRO_PEDIDO = NRO_PEDIDO))
                                                         AS PED_DEMORADOS,
        @PROX_HABIL                                      AS PROX_HABIL,
        @MAS_UNO                                         AS MAS_UNO,
        CAST(0.97 AS DECIMAL(5,2))                       AS META,
        CAST(ISNULL(@PROM_PICK_7D, 0) AS DECIMAL(18,2)) AS PROM_UNID_DIA;

    -- ── Result set 2: ventanas (3 filas: HOY / PROX / MAS_UNO) ────────────
    ;WITH V AS (
        SELECT 'HOY'     AS VENTANA, @HOY        AS FECHA, 1 AS ORD
        UNION ALL SELECT 'PROX',     @PROX_HABIL,           2
        UNION ALL SELECT 'MAS_UNO',  @MAS_UNO,              3
    )
    SELECT
        V.VENTANA,
        V.FECHA,
        ISNULL(agg.PED_TOTAL, 0)                                   AS PED_TOTAL,
        ISNULL(agg.PED_PEND, 0)                                    AS PED_PEND,
        CAST(ISNULL(agg.UNID_TOTAL, 0) AS DECIMAL(18,2))           AS UNID_TOTAL,
        CAST(ISNULL(agg.UNID_PEND, 0)  AS DECIMAL(18,2))           AS UNID_PEND,
        CASE WHEN ISNULL(agg.UNID_TOTAL, 0) > 0
             THEN CAST(1.0 - ISNULL(agg.UNID_PEND,0) / agg.UNID_TOTAL AS DECIMAL(10,4))
             ELSE NULL END                                         AS PCT_PICK,
        CAST(ISNULL(CEILING(ISNULL(agg.UNID_PEND,0) / NULLIF(@PROM_PICK_7D, 0)), 0) AS INT)
                                                                   AS PICKERS,
        CAST(0.97 AS DECIMAL(5,2))                                 AS META
    FROM V
    OUTER APPLY (
        SELECT
            COUNT(DISTINCT b.NRO_PEDIDO)                                          AS PED_TOTAL,
            COUNT(DISTINCT CASE WHEN b.ESTADO = 'PENDIENTE' THEN b.NRO_PEDIDO END) AS PED_PEND,
            SUM(CAST(b.CANT_PEDIDO AS DECIMAL(18,2)))                             AS UNID_TOTAL,
            SUM(CASE WHEN b.ESTADO = 'PENDIENTE'
                     THEN CAST(b.CANT_PEDIDO AS DECIMAL(18,2)) END)               AS UNID_PEND
        FROM dbo.BI_T_DESPACHO_PEDIDOS b
        WHERE b.FECHA_ENTREGA = V.FECHA
          AND (@CANAL IS NULL OR b.CANAL = @CANAL)
          AND NOT EXISTS (SELECT 1 FROM #PedSinFacturar sf WHERE sf.NRO_PEDIDO = b.NRO_PEDIDO)
    ) agg
    ORDER BY V.ORD;

    -- ── Result set 3: pedidos PENDIENTES por ventana (tabla filtrable) ─────
    -- VENTANA permite filtrar en el front: HOY / PROX / MAS_UNO / (todos).
    SELECT TOP 1000
        b.NRO_PEDIDO,
        b.COD_CLIENT,
        b.NOMBRE_CLIENTE,
        b.CANAL,
        CAST(b.FECHA_ENTREGA AS DATE)                       AS FECHA_ENTREGA,
        CAST(ISNULL(SUM(b.CANT_PEDIDO), 0) AS DECIMAL(18,2)) AS UNIDADES,
        CASE
            WHEN CAST(b.FECHA_ENTREGA AS DATE) = @HOY        THEN 'HOY'
            WHEN CAST(b.FECHA_ENTREGA AS DATE) = @PROX_HABIL THEN 'PROX'
            WHEN CAST(b.FECHA_ENTREGA AS DATE) = @MAS_UNO    THEN 'MAS_UNO'
            ELSE 'OTRO'
        END                                                  AS VENTANA
    FROM dbo.BI_T_DESPACHO_PEDIDOS b
    WHERE b.ESTADO = 'PENDIENTE'
      AND b.FECHA_ENTREGA >= @HOY
      AND (@CANAL IS NULL OR b.CANAL = @CANAL)
      AND NOT EXISTS (SELECT 1 FROM #PedSinFacturar sf WHERE sf.NRO_PEDIDO = b.NRO_PEDIDO)
    GROUP BY b.NRO_PEDIDO, b.COD_CLIENT, b.NOMBRE_CLIENTE, b.CANAL,
             CAST(b.FECHA_ENTREGA AS DATE)
    ORDER BY CAST(b.FECHA_ENTREGA AS DATE) ASC, UNIDADES DESC;

    -- ── Result set 4: pedidos demorados ──────────────────────────────────
    -- Pedidos PENDIENTES con entrega vencida (FECHA_ENTREGA < hoy), últimos
    -- 30 días. Mismo criterio que el KPI PED_DEMORADOS.
    SELECT TOP 500
        b.NRO_PEDIDO,
        b.NOMBRE_CLIENTE,
        b.CANAL,
        CAST(b.FECHA_ENTREGA AS DATE)                       AS FECHA_ENTREGA,
        DATEDIFF(DAY, @HOY, b.FECHA_ENTREGA)                AS DIAS,
        CAST(ISNULL(SUM(b.CANT_PEDIDO), 0) AS DECIMAL(18,2)) AS UNIDADES
    FROM dbo.BI_T_DESPACHO_PEDIDOS b
    WHERE b.ESTADO = 'PENDIENTE'
      AND b.FECHA_ENTREGA <  @HOY
      AND b.FECHA_ENTREGA >= DATEADD(DAY, -30, @HOY)
      AND (@CANAL IS NULL OR b.CANAL = @CANAL)
      AND NOT EXISTS (SELECT 1 FROM #PedSinFacturar sf WHERE sf.NRO_PEDIDO = b.NRO_PEDIDO)
    GROUP BY b.NRO_PEDIDO, b.NOMBRE_CLIENTE, b.CANAL, CAST(b.FECHA_ENTREGA AS DATE)
    ORDER BY b.FECHA_ENTREGA ASC, UNIDADES DESC;

    DROP TABLE #PedSinFacturar;
END;
GO
