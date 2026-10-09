USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 15_sp_pedidos_ingreso.sql
-- RO_SP_PEDIDOS_INGRESO
-- Pedidos cargados en un día (pestaña Pedidos), vistos como supply
-- chain: ¿cuánto entró vs lo normal?, ¿cuándo hay que entregarlo?,
-- ¿se está cumpliendo en plazo? y ¿qué está en riesgo?
-- (Antes era parte de RO_SP_FILL_RATE, como "día anterior al elegido".)
--
-- "A tiempo" = fecha del último remito del pedido <= fecha de entrega
-- comprometida (BI_T_DESPACHO_PEDIDOS). Los completos sin remito
-- vinculable (Ecommerce, Dist. Inicial de franquicias) no se pueden
-- medir y se cuentan aparte. La situación es la de HOY.
--
-- Result sets: 1) KPIs  2) por canal  3) plazo de entrega  4) a atender
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_PEDIDOS_INGRESO','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_PEDIDOS_INGRESO;
GO

CREATE PROCEDURE dbo.RO_SP_PEDIDOS_INGRESO
    @FECHA DATE          = NULL,   -- día de carga; NULL = ayer
    @CANAL NVARCHAR(100) = NULL,   -- NULL = todos
    @TIPO  NVARCHAR(50)  = NULL    -- NULL = todos (REPOSICION / DIST. INICIAL)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @HOY       DATE = CAST(GETDATE() AS DATE);
    DECLARE @ING_FECHA DATE = ISNULL(@FECHA, DATEADD(DAY, -1, @HOY));
    DECLARE @PROX_HABIL DATE = (SELECT TOP 1 FECHA FROM dbo.RO_T_CALENDARIO
                                WHERE FECHA > @HOY AND DIA_LABORAL = 1 ORDER BY FECHA);

    SELECT e.TALON_PED, e.NRO_PEDIDO,
           MAX(e.CANAL)                     AS CANAL,
           MAX(LTRIM(RTRIM(e.CLIENTE)))     AS CLIENTE,
           SUM(ISNULL(e.CANT_PEDID, 0))     AS UNID_PEDIDAS,
           SUM(ISNULL(e.CANT_PEND, 0))      AS UNID_PENDIENTES,
           SUM(ISNULL(e.IMPORTE_PEDIDO, 0)) AS IMPORTE
    INTO #ING
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    WHERE e.FECHA_PEDI = @ING_FECHA
      AND ISNULL(e.ESTADO_TANGO, '') <> 'CANCELADO'
      AND (@CANAL IS NULL OR e.CANAL = @CANAL)
      AND (@TIPO  IS NULL OR e.TIPO_FACTURACION = @TIPO)
    GROUP BY e.TALON_PED, e.NRO_PEDIDO;

    -- Fecha de entrega comprometida y fecha del último remito de cada pedido
    ALTER TABLE #ING ADD FECHA_ENTREGA DATE, ULT_REMITO DATE, SITUACION VARCHAR(20), PLAZO VARCHAR(30), PLAZO_ORD TINYINT;

    UPDATE i SET FECHA_ENTREGA = f.FE
    FROM #ING i
    JOIN (SELECT CAST(TALON_PED AS SMALLINT) AS TALON_PED, NRO_PEDIDO, MIN(FECHA_ENTREGA) AS FE
          FROM dbo.BI_T_DESPACHO_PEDIDOS
          WHERE NRO_PEDIDO IN (SELECT NRO_PEDIDO FROM #ING)
          GROUP BY CAST(TALON_PED AS SMALLINT), NRO_PEDIDO) f
      ON f.NRO_PEDIDO = i.NRO_PEDIDO AND f.TALON_PED = i.TALON_PED;

    UPDATE i SET ULT_REMITO = u.UR
    FROM #ING i
    JOIN (SELECT TALON_PED, NRO_PEDIDO, MAX(TRY_CAST(FECHA_COMP AS DATE)) AS UR
          FROM dbo.BI_KPI_LOG_FACTURACION
          WHERE NRO_PEDIDO IN (SELECT NRO_PEDIDO FROM #ING) AND ISNULL(N_COMP, '') <> ''
          GROUP BY TALON_PED, NRO_PEDIDO) u
      ON u.NRO_PEDIDO = i.NRO_PEDIDO AND u.TALON_PED = i.TALON_PED;

    -- Fechas dummy (1900-01-01) se tratan como "sin fecha"
    UPDATE #ING SET FECHA_ENTREGA = NULL WHERE FECHA_ENTREGA < '2000-01-01';

    UPDATE #ING SET
        SITUACION = CASE
            WHEN FECHA_ENTREGA IS NULL                                THEN 'SIN FECHA'
            WHEN UNID_PENDIENTES <= 0 AND ULT_REMITO IS NULL          THEN 'COMPLETO SIN DATO'
            WHEN UNID_PENDIENTES <= 0 AND ULT_REMITO <= FECHA_ENTREGA THEN 'A TIEMPO'
            WHEN UNID_PENDIENTES <= 0                                 THEN 'TARDE'
            WHEN FECHA_ENTREGA < @HOY                                 THEN 'VENCIDO'
            WHEN FECHA_ENTREGA <= @PROX_HABIL                         THEN 'EN RIESGO'
            ELSE 'EN PLAZO' END,
        PLAZO = CASE
            WHEN FECHA_ENTREGA IS NULL                                THEN 'Sin fecha de entrega'
            WHEN FECHA_ENTREGA < @ING_FECHA                           THEN 'Fecha anterior a la carga'
            WHEN DATEDIFF(DAY, @ING_FECHA, FECHA_ENTREGA) <= 1        THEN '0 a 1 día'
            WHEN DATEDIFF(DAY, @ING_FECHA, FECHA_ENTREGA) <= 3        THEN '2 a 3 días'
            WHEN DATEDIFF(DAY, @ING_FECHA, FECHA_ENTREGA) <= 7        THEN '4 a 7 días'
            ELSE '8 días o más' END,
        PLAZO_ORD = CASE
            WHEN FECHA_ENTREGA IS NULL                                THEN 6
            WHEN FECHA_ENTREGA < @ING_FECHA                           THEN 5
            WHEN DATEDIFF(DAY, @ING_FECHA, FECHA_ENTREGA) <= 1        THEN 1
            WHEN DATEDIFF(DAY, @ING_FECHA, FECHA_ENTREGA) <= 3        THEN 2
            WHEN DATEDIFF(DAY, @ING_FECHA, FECHA_ENTREGA) <= 7        THEN 3
            ELSE 4 END;

    -- Referencia "normal": mismo día de la semana, 4 semanas previas (mismos filtros)
    SELECT e.FECHA_PEDI,
           COUNT(DISTINCT CONCAT(e.TALON_PED, '|', e.NRO_PEDIDO)) AS PEDIDOS,
           SUM(ISNULL(e.CANT_PEDID, 0))                            AS UNIDADES,
           SUM(ISNULL(e.IMPORTE_PEDIDO, 0))                        AS IMPORTE
    INTO #REF
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    WHERE e.FECHA_PEDI IN (DATEADD(DAY, -7, @ING_FECHA), DATEADD(DAY, -14, @ING_FECHA),
                           DATEADD(DAY, -21, @ING_FECHA), DATEADD(DAY, -28, @ING_FECHA))
      AND ISNULL(e.ESTADO_TANGO, '') <> 'CANCELADO'
      AND (@CANAL IS NULL OR e.CANAL = @CANAL)
      AND (@TIPO  IS NULL OR e.TIPO_FACTURACION = @TIPO)
    GROUP BY e.FECHA_PEDI;

    -- ── Result set 1: KPIs del ingreso ────────────────────────────────────
    SELECT
        @ING_FECHA                                                            AS ING_FECHA,
        @HOY                                                                  AS HOY,
        @PROX_HABIL                                                           AS PROX_HABIL,
        -- 1. ¿Cuánto entró vs lo normal?
        COUNT(*)                                                              AS ING_PEDIDOS,
        CAST(ISNULL(SUM(UNID_PEDIDAS), 0) AS DECIMAL(18,0))                   AS ING_UNIDADES,
        CAST(ISNULL(SUM(IMPORTE), 0) AS DECIMAL(18,0))                        AS ING_IMPORTE,
        (SELECT CAST(AVG(CAST(PEDIDOS AS FLOAT)) AS DECIMAL(18,1)) FROM #REF) AS REF_PEDIDOS,
        (SELECT CAST(AVG(UNIDADES) AS DECIMAL(18,0)) FROM #REF)               AS REF_UNIDADES,
        (SELECT CAST(AVG(IMPORTE)  AS DECIMAL(18,0)) FROM #REF)               AS REF_IMPORTE,
        (SELECT COUNT(*) FROM #REF)                                           AS REF_SEMANAS,
        -- 2. ¿Se está cumpliendo en plazo? (pedidos)
        SUM(CASE WHEN SITUACION = 'A TIEMPO'  THEN 1 ELSE 0 END)              AS PED_A_TIEMPO,
        SUM(CASE WHEN SITUACION = 'TARDE'     THEN 1 ELSE 0 END)              AS PED_TARDE,
        SUM(CASE WHEN SITUACION = 'VENCIDO'   THEN 1 ELSE 0 END)              AS PED_VENCIDOS,
        SUM(CASE WHEN SITUACION = 'EN RIESGO' THEN 1 ELSE 0 END)              AS PED_EN_RIESGO,
        SUM(CASE WHEN SITUACION = 'EN PLAZO'  THEN 1 ELSE 0 END)              AS PED_EN_PLAZO,
        SUM(CASE WHEN SITUACION = 'COMPLETO SIN DATO' THEN 1 ELSE 0 END)      AS PED_COMPLETO_SIN_DATO,
        CAST(CASE WHEN SUM(CASE WHEN SITUACION IN ('A TIEMPO','TARDE','VENCIDO') THEN 1 ELSE 0 END) > 0
                  THEN SUM(CASE WHEN SITUACION = 'A TIEMPO' THEN 1.0 ELSE 0 END)
                     / SUM(CASE WHEN SITUACION IN ('A TIEMPO','TARDE','VENCIDO') THEN 1 ELSE 0 END) END
             AS DECIMAL(10,4))                                                AS PCT_A_TIEMPO,
        -- 4. ¿Qué está en riesgo? (unidades pendientes)
        CAST(ISNULL(SUM(CASE WHEN SITUACION = 'VENCIDO'   THEN UNID_PENDIENTES END), 0) AS DECIMAL(18,0)) AS UNID_VENCIDAS,
        CAST(ISNULL(SUM(CASE WHEN SITUACION = 'EN RIESGO' THEN UNID_PENDIENTES END), 0) AS DECIMAL(18,0)) AS UNID_EN_RIESGO,
        CAST(ISNULL(SUM(CASE WHEN SITUACION = 'EN PLAZO'  THEN UNID_PENDIENTES END), 0) AS DECIMAL(18,0)) AS UNID_EN_PLAZO,
        -- Calidad de datos
        SUM(CASE WHEN PLAZO_ORD = 6 THEN 1 ELSE 0 END)                        AS PED_SIN_FECHA,
        SUM(CASE WHEN PLAZO_ORD = 5 THEN 1 ELSE 0 END)                        AS PED_FECHA_ANTERIOR,
        CAST(0.95 AS DECIMAL(5,2))                                            AS META
    FROM #ING;

    -- ── Result set 2: por canal ───────────────────────────────────────────
    SELECT
        CANAL,
        COUNT(*)                                                              AS PEDIDOS,
        CAST(SUM(UNID_PEDIDAS) AS DECIMAL(18,0))                              AS UNID_PEDIDAS,
        CAST(SUM(UNID_PENDIENTES) AS DECIMAL(18,0))                           AS UNID_PENDIENTES,
        SUM(CASE WHEN SITUACION = 'A TIEMPO' THEN 1 ELSE 0 END)               AS PED_A_TIEMPO,
        SUM(CASE WHEN SITUACION IN ('A TIEMPO','TARDE','VENCIDO') THEN 1 ELSE 0 END) AS PED_MEDIBLES,
        CAST(CASE WHEN SUM(CASE WHEN SITUACION IN ('A TIEMPO','TARDE','VENCIDO') THEN 1 ELSE 0 END) > 0
                  THEN SUM(CASE WHEN SITUACION = 'A TIEMPO' THEN 1.0 ELSE 0 END)
                     / SUM(CASE WHEN SITUACION IN ('A TIEMPO','TARDE','VENCIDO') THEN 1 ELSE 0 END) END
             AS DECIMAL(10,4))                                                AS PCT_A_TIEMPO,
        SUM(CASE WHEN SITUACION IN ('VENCIDO','EN RIESGO') THEN 1 ELSE 0 END) AS PED_A_ATENDER
    FROM #ING
    GROUP BY CANAL
    ORDER BY UNID_PEDIDAS DESC;

    -- ── Result set 3: ¿cuándo hay que entregarlo? (plazo desde la carga) ──
    SELECT
        PLAZO, PLAZO_ORD,
        COUNT(*)                                        AS PEDIDOS,
        CAST(SUM(UNID_PEDIDAS)    AS DECIMAL(18,0))     AS UNID_PEDIDAS,
        CAST(SUM(UNID_PENDIENTES) AS DECIMAL(18,0))     AS UNID_PENDIENTES
    FROM #ING
    GROUP BY PLAZO, PLAZO_ORD
    ORDER BY PLAZO_ORD;

    -- ── Result set 4: pedidos a atender primero (vencidos y en riesgo) ────
    SELECT
        LTRIM(RTRIM(NRO_PEDIDO))                        AS NRO_PEDIDO,
        TALON_PED, CLIENTE, CANAL, FECHA_ENTREGA,
        CAST(UNID_PEDIDAS    AS DECIMAL(18,0))          AS UNID_PEDIDAS,
        CAST(UNID_PENDIENTES AS DECIMAL(18,0))          AS UNID_PENDIENTES,
        SITUACION
    FROM #ING
    WHERE SITUACION IN ('VENCIDO', 'EN RIESGO')
    ORDER BY FECHA_ENTREGA, UNID_PENDIENTES DESC;

    DROP TABLE #ING; DROP TABLE #REF;
END;
GO
