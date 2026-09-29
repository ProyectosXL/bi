USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 13_sp_wip_picking.sql
-- RO_SP_WIP_PICKING
-- WIP (Work In Progress): tareas de picking iniciadas y no terminadas
-- (FECHA_FIN_PICKING IS NULL) en BI_T_TRACKING_PICKING, que se
-- actualiza casi en tiempo real. Situación actual: no usa fechas.
--
-- Estados:
--   EN CURSO  = iniciada hoy, abierta hace <= 120 min
--   DEMORADA  = iniciada hoy, abierta hace > 120 min (p95 de duración
--               de tareas terminadas ≈ 107 min)
--   COLGADA   = iniciada un día anterior y nunca cerrada: NO es WIP,
--               se lista aparte para que se cierre en el WMS.
--               Solo se miran los últimos 7 días (las más viejas no aportan).
--
-- Limitación: la tabla no tiene nro. de pedido ni canal; el WIP se ve
-- por picker y por tarea.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_WIP_PICKING','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_WIP_PICKING;
GO

CREATE PROCEDURE dbo.RO_SP_WIP_PICKING
    @USUARIO NVARCHAR(100) = NULL   -- NULL = todos los pickers
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @AHORA DATETIME = GETDATE();
    DECLARE @HOY   DATE     = CAST(@AHORA AS DATE);
    DECLARE @DEMORA_MIN INT = 120;

    SELECT
        t.ID,
        t.FECHA_INI_PICKING,
        t.HM_INICIO,
        LTRIM(RTRIM(t.USUARIO))                       AS USUARIO,
        CAST(ISNULL(t.CANT_ASIGNADA, 0) AS FLOAT)     AS CANT_ASIGNADA,
        CAST(ISNULL(t.CANT_PICKING, 0)  AS FLOAT)     AS CANT_PICKING,
        CAST(ISNULL(t.FALTANTE, 0)      AS FLOAT)     AS FALTANTE,
        CASE WHEN t.FECHA_INI_PICKING = @HOY
             THEN DATEDIFF(MINUTE,
                      CAST(t.FECHA_INI_PICKING AS DATETIME) + CAST(TRY_CONVERT(TIME, t.HM_INICIO) AS DATETIME),
                      @AHORA)
        END                                           AS MIN_ABIERTA,
        DATEDIFF(DAY, t.FECHA_INI_PICKING, @HOY)      AS DIAS_ABIERTA
    INTO #T
    FROM dbo.BI_T_TRACKING_PICKING t
    WHERE t.FECHA_FIN_PICKING IS NULL
      AND t.FECHA_INI_PICKING >= DATEADD(DAY, -7, @HOY)   -- colgadas: solo la última semana
      AND (@USUARIO IS NULL OR LTRIM(RTRIM(t.USUARIO)) = @USUARIO);

    ALTER TABLE #T ADD ESTADO VARCHAR(10);
    UPDATE #T SET ESTADO = CASE
        WHEN FECHA_INI_PICKING < @HOY       THEN 'COLGADA'
        WHEN MIN_ABIERTA > @DEMORA_MIN      THEN 'DEMORADA'
        ELSE 'EN CURSO' END;

    -- ── Result set 1: KPIs del WIP (solo tareas de hoy) ───────────────────
    SELECT
        CONVERT(VARCHAR(16), @AHORA, 120)                                   AS AHORA,
        (SELECT CONVERT(VARCHAR(10), MAX(FECHA_INI_PICKING), 23) + ' ' +
                MAX(HM_INICIO)
         FROM dbo.BI_T_TRACKING_PICKING
         WHERE FECHA_INI_PICKING = (SELECT MAX(FECHA_INI_PICKING) FROM dbo.BI_T_TRACKING_PICKING)) AS ULTIMO_INICIO,
        ISNULL(SUM(CASE WHEN ESTADO <> 'COLGADA' THEN 1 ELSE 0 END), 0)                AS TAREAS,
        COUNT(DISTINCT CASE WHEN ESTADO <> 'COLGADA' THEN USUARIO END)      AS PICKERS,
        CAST(ISNULL(SUM(CASE WHEN ESTADO <> 'COLGADA' THEN CANT_ASIGNADA END), 0) AS DECIMAL(18,0)) AS UNID_ASIGNADAS,
        CAST(ISNULL(SUM(CASE WHEN ESTADO <> 'COLGADA' THEN CANT_PICKING  END), 0) AS DECIMAL(18,0)) AS UNID_PICKEADAS,
        CAST(ISNULL(SUM(CASE WHEN ESTADO <> 'COLGADA' THEN FALTANTE      END), 0) AS DECIMAL(18,0)) AS UNID_FALTANTES,
        CAST(CASE WHEN SUM(CASE WHEN ESTADO <> 'COLGADA' THEN CANT_ASIGNADA END) > 0
                  THEN SUM(CASE WHEN ESTADO <> 'COLGADA' THEN CANT_PICKING END)
                     / SUM(CASE WHEN ESTADO <> 'COLGADA' THEN CANT_ASIGNADA END) END AS DECIMAL(10,4)) AS AVANCE,
        ISNULL(SUM(CASE WHEN ESTADO = 'DEMORADA' THEN 1 ELSE 0 END), 0)                AS TAREAS_DEMORADAS,
        ISNULL(SUM(CASE WHEN ESTADO = 'COLGADA'  THEN 1 ELSE 0 END), 0)                AS TAREAS_COLGADAS,
        @DEMORA_MIN                                                         AS DEMORA_MIN
    FROM #T;

    -- ── Result set 2: detalle de tareas abiertas (WIP primero, colgadas al final)
    SELECT
        ID, FECHA_INI_PICKING, HM_INICIO, USUARIO,
        CAST(CANT_ASIGNADA AS DECIMAL(18,0)) AS CANT_ASIGNADA,
        CAST(CANT_PICKING  AS DECIMAL(18,0)) AS CANT_PICKING,
        CAST(FALTANTE      AS DECIMAL(18,0)) AS FALTANTE,
        CAST(CASE WHEN CANT_ASIGNADA > 0 THEN CANT_PICKING / CANT_ASIGNADA END AS DECIMAL(10,4)) AS AVANCE,
        MIN_ABIERTA,
        DIAS_ABIERTA,
        ESTADO
    FROM #T
    ORDER BY CASE ESTADO WHEN 'DEMORADA' THEN 1 WHEN 'EN CURSO' THEN 2 ELSE 3 END,
             FECHA_INI_PICKING DESC, HM_INICIO;

    DROP TABLE #T;
END;
GO
