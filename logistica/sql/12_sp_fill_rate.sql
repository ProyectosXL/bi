USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 12_sp_fill_rate.sql
-- RO_SP_FILL_RATE
-- Fill Rate por remito: qué se remitió en un día, a qué pedido
-- corresponde cada remito y cómo quedó el cumplimiento del pedido.
--
-- Fuentes:
--   BI_FACTURACION_LOGISTICA  cantidad REAL por remito + artículo
--                             (no tiene nro. de pedido)
--   BI_KPI_LOG_FACTURACION    solo como MAPA remito + artículo → pedido.
--                             OJO: su CANT_FACTURADA repite el total del
--                             pedido en cada remito; no sumarla.
--   BI_EFICIENCIA_LOGISTICA   estado ACTUAL del pedido (pedidas/pendientes)
--
-- Si un artículo del remito figura en más de un pedido (≈0,5% de los
-- casos), sus unidades se reparten en proporción a lo pedido.
-- El cumplimiento es el acumulado actual del pedido, no el que tenía
-- al momento de cada remito.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_FILL_RATE','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_FILL_RATE;
GO

CREATE PROCEDURE dbo.RO_SP_FILL_RATE
    @FECHA DATE          = NULL,   -- NULL = último día con remitos cargados
    @CANAL NVARCHAR(100) = NULL,   -- NULL = todos
    @TIPO  NVARCHAR(50)  = NULL    -- NULL = todos (REPOSICION / DIST. INICIAL)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @FECHA_MAX DATE = (SELECT MAX(FECHA_COMP) FROM dbo.BI_FACTURACION_LOGISTICA);
    IF @FECHA IS NULL SET @FECHA = @FECHA_MAX;
    DECLARE @FECHA_TXT VARCHAR(10) = CONVERT(VARCHAR(10), @FECHA, 23);

    -- ── 1. Remitos del día (cantidad real por remito + artículo) ──────────
    SELECT f.N_COMP, f.COD_ARTICU,
           CAST(f.CANTIDAD AS FLOAT)            AS CANTIDAD,
           LTRIM(RTRIM(f.CLIENTE))              AS CLIENTE,
           f.CANAL,
           ISNULL(f.TIPO_FACTURACION, '(NULL)') AS TIPO_FACTURACION
    INTO #R
    FROM dbo.BI_FACTURACION_LOGISTICA f
    WHERE f.FECHA_COMP = @FECHA
      AND (@CANAL IS NULL OR f.CANAL = @CANAL)
      AND (@TIPO  IS NULL OR f.TIPO_FACTURACION = @TIPO);

    CREATE NONCLUSTERED INDEX IX_R ON #R (N_COMP, COD_ARTICU);

    -- ── 2. Mapa remito + artículo → pedido, con peso de reparto ───────────
    SELECT k.N_COMP, k.COD_ARTICU, k.TALON_PED, k.NRO_PEDIDO,
           MAX(ISNULL(k.CANT_PEDID, 0)) AS CANT_PEDID
    INTO #M
    FROM dbo.BI_KPI_LOG_FACTURACION k
    WHERE k.FECHA_COMP = @FECHA_TXT
      AND k.N_COMP IN (SELECT N_COMP FROM #R)
    GROUP BY k.N_COMP, k.COD_ARTICU, k.TALON_PED, k.NRO_PEDIDO;

    ALTER TABLE #M ADD PESO FLOAT;

    ;WITH T AS (
        SELECT N_COMP, COD_ARTICU, SUM(CANT_PEDID) AS TOT, COUNT(*) AS N
        FROM #M GROUP BY N_COMP, COD_ARTICU
    )
    UPDATE m SET PESO = CASE WHEN t.TOT > 0 THEN m.CANT_PEDID / t.TOT ELSE 1.0 / t.N END
    FROM #M m JOIN T t ON t.N_COMP = m.N_COMP AND t.COD_ARTICU = m.COD_ARTICU;

    -- ── 3. Unidades por remito × pedido (pedido NULL = sin pedido asociado)
    SELECT r.N_COMP, m.TALON_PED, m.NRO_PEDIDO,
           MAX(r.CLIENTE)          AS CLIENTE_REMITO,
           MAX(r.CANAL)            AS CANAL,
           MAX(r.TIPO_FACTURACION) AS TIPO_FACTURACION,
           SUM(r.CANTIDAD * ISNULL(m.PESO, 1.0)) AS UNID_REMITO
    INTO #RP
    FROM #R r
    LEFT JOIN #M m ON m.N_COMP = r.N_COMP AND m.COD_ARTICU = r.COD_ARTICU
    GROUP BY r.N_COMP, m.TALON_PED, m.NRO_PEDIDO;

    -- ── 4. Estado actual de los pedidos atendidos ─────────────────────────
    SELECT e.TALON_PED, e.NRO_PEDIDO,
           MIN(e.FECHA_PEDI)                 AS FECHA_PEDI,
           MAX(LTRIM(RTRIM(e.CLIENTE)))      AS CLIENTE,
           MAX(ISNULL(e.ESTADO_TANGO, ''))   AS ESTADO_TANGO,
           SUM(ISNULL(e.CANT_PEDID, 0))      AS UNID_PEDIDAS,
           SUM(ISNULL(e.CANT_PEND, 0))       AS UNID_PENDIENTES
    INTO #PED
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    JOIN (SELECT DISTINCT TALON_PED, NRO_PEDIDO FROM #RP WHERE NRO_PEDIDO IS NOT NULL) p
      ON p.NRO_PEDIDO = e.NRO_PEDIDO AND p.TALON_PED = e.TALON_PED
    GROUP BY e.TALON_PED, e.NRO_PEDIDO;

    -- ── Result set 1: KPIs ────────────────────────────────────────────────
    SELECT
        @FECHA                                                          AS FECHA,
        @FECHA_MAX                                                      AS FECHA_MAX,
        CAST(ISNULL((SELECT SUM(CANTIDAD) FROM #R), 0) AS DECIMAL(18,0)) AS UNID_REMITIDAS,
        (SELECT COUNT(DISTINCT N_COMP) FROM #R)                         AS REMITOS,
        (SELECT COUNT(*) FROM #PED)                                     AS PEDIDOS,
        CAST(ISNULL((SELECT SUM(UNID_REMITO) FROM #RP WHERE NRO_PEDIDO IS NOT NULL), 0) AS DECIMAL(18,0)) AS UNID_CON_PEDIDO,
        CAST(ISNULL((SELECT SUM(UNID_REMITO) FROM #RP WHERE NRO_PEDIDO IS NULL), 0)     AS DECIMAL(18,0)) AS UNID_SIN_PEDIDO,
        (SELECT COUNT(DISTINCT N_COMP) FROM #RP WHERE NRO_PEDIDO IS NULL)               AS REMITOS_SIN_PEDIDO,
        CAST((SELECT CASE WHEN SUM(UNID_PEDIDAS) > 0
                          THEN 1.0 - SUM(UNID_PENDIENTES) / SUM(UNID_PEDIDAS) END FROM #PED) AS DECIMAL(10,4)) AS FILL_RATE,
        (SELECT COUNT(*) FROM #PED WHERE UNID_PENDIENTES <= 0)          AS PED_COMPLETOS,
        CAST(0.95 AS DECIMAL(5,2))                                      AS META;

    -- ── Result set 2: apertura por canal y tipo ───────────────────────────
    ;WITH P AS (   -- un pedido cuenta en el canal/tipo de su remito
        SELECT DISTINCT rp.CANAL, rp.TIPO_FACTURACION, p.TALON_PED, p.NRO_PEDIDO,
               p.UNID_PEDIDAS, p.UNID_PENDIENTES
        FROM #RP rp JOIN #PED p ON p.NRO_PEDIDO = rp.NRO_PEDIDO AND p.TALON_PED = rp.TALON_PED
    ), U AS (
        SELECT CANAL, TIPO_FACTURACION, SUM(UNID_REMITO) AS UNID_REMITIDAS,
               COUNT(DISTINCT N_COMP) AS REMITOS
        FROM #RP GROUP BY CANAL, TIPO_FACTURACION
    )
    SELECT u.CANAL, u.TIPO_FACTURACION,
           CAST(u.UNID_REMITIDAS AS DECIMAL(18,0))                       AS UNID_REMITIDAS,
           u.REMITOS,
           COUNT(p.NRO_PEDIDO)                                           AS PEDIDOS,
           SUM(CASE WHEN p.UNID_PENDIENTES <= 0 THEN 1 ELSE 0 END)       AS PED_COMPLETOS,
           CAST(CASE WHEN SUM(p.UNID_PEDIDAS) > 0
                     THEN 1.0 - SUM(p.UNID_PENDIENTES) / SUM(p.UNID_PEDIDAS) END AS DECIMAL(10,4)) AS FILL_RATE
    FROM U u
    LEFT JOIN P p ON p.CANAL = u.CANAL AND p.TIPO_FACTURACION = u.TIPO_FACTURACION
    GROUP BY u.CANAL, u.TIPO_FACTURACION, u.UNID_REMITIDAS, u.REMITOS
    ORDER BY u.UNID_REMITIDAS DESC;

    -- ── Result set 3: detalle remito × pedido ─────────────────────────────
    SELECT
        rp.N_COMP,
        LTRIM(RTRIM(rp.NRO_PEDIDO))                         AS NRO_PEDIDO,
        rp.TALON_PED,
        p.FECHA_PEDI,
        ISNULL(p.CLIENTE, rp.CLIENTE_REMITO)                AS CLIENTE,
        rp.CANAL,
        rp.TIPO_FACTURACION,
        p.ESTADO_TANGO,
        CAST(rp.UNID_REMITO AS DECIMAL(18,1))               AS UNID_REMITO,
        CAST(p.UNID_PEDIDAS AS DECIMAL(18,0))               AS UNID_PEDIDAS,
        CAST(p.UNID_PEDIDAS - p.UNID_PENDIENTES AS DECIMAL(18,0)) AS UNID_REMITIDAS_ACUM,
        CAST(p.UNID_PENDIENTES AS DECIMAL(18,0))            AS UNID_PENDIENTES,
        CAST(CASE WHEN p.UNID_PEDIDAS > 0
                  THEN 1.0 - p.UNID_PENDIENTES / p.UNID_PEDIDAS END AS DECIMAL(10,4)) AS CUMPL,
        CASE WHEN rp.NRO_PEDIDO IS NULL  THEN 'SIN PEDIDO'
             WHEN p.NRO_PEDIDO IS NULL   THEN 'SIN DATOS'
             WHEN p.UNID_PENDIENTES <= 0 THEN 'COMPLETO'
             ELSE 'PARCIAL' END                             AS ESTADO
    FROM #RP rp
    LEFT JOIN #PED p ON p.NRO_PEDIDO = rp.NRO_PEDIDO AND p.TALON_PED = rp.TALON_PED
    ORDER BY rp.N_COMP, rp.NRO_PEDIDO;

    -- ── Pedidos cargados el día anterior (D−1): vista de supply chain ─────
    -- Responde: ¿cuánto entró vs lo normal?, ¿cuándo hay que entregarlo?,
    -- ¿se está cumpliendo en plazo? y ¿qué está en riesgo?
    -- "A tiempo" = fecha del último remito del pedido <= fecha de entrega
    -- comprometida (BI_T_DESPACHO_PEDIDOS). Los completos sin remito
    -- vinculable (Ecommerce, Dist. Inicial de franquicias) no se pueden
    -- medir y se cuentan aparte.
    DECLARE @ING_FECHA DATE = DATEADD(DAY, -1, @FECHA);
    DECLARE @HOY       DATE = CAST(GETDATE() AS DATE);
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

    -- ── Result set 4: KPIs del ingreso ────────────────────────────────────
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
        -- 3. ¿Se está cumpliendo en plazo? (pedidos)
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
        -- 6. Calidad de datos
        SUM(CASE WHEN PLAZO_ORD = 6 THEN 1 ELSE 0 END)                        AS PED_SIN_FECHA,
        SUM(CASE WHEN PLAZO_ORD = 5 THEN 1 ELSE 0 END)                        AS PED_FECHA_ANTERIOR,
        CAST(0.95 AS DECIMAL(5,2))                                            AS META
    FROM #ING;

    -- ── Result set 5: por canal ───────────────────────────────────────────
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

    -- ── Result set 6: ¿cuándo hay que entregarlo? (plazo desde la carga) ──
    SELECT
        PLAZO, PLAZO_ORD,
        COUNT(*)                                        AS PEDIDOS,
        CAST(SUM(UNID_PEDIDAS)    AS DECIMAL(18,0))     AS UNID_PEDIDAS,
        CAST(SUM(UNID_PENDIENTES) AS DECIMAL(18,0))     AS UNID_PENDIENTES
    FROM #ING
    GROUP BY PLAZO, PLAZO_ORD
    ORDER BY PLAZO_ORD;

    -- ── Result set 7: pedidos a atender primero (vencidos y en riesgo) ────
    SELECT
        LTRIM(RTRIM(NRO_PEDIDO))                        AS NRO_PEDIDO,
        TALON_PED, CLIENTE, CANAL, FECHA_ENTREGA,
        CAST(UNID_PEDIDAS    AS DECIMAL(18,0))          AS UNID_PEDIDAS,
        CAST(UNID_PENDIENTES AS DECIMAL(18,0))          AS UNID_PENDIENTES,
        SITUACION
    FROM #ING
    WHERE SITUACION IN ('VENCIDO', 'EN RIESGO')
    ORDER BY FECHA_ENTREGA, UNID_PENDIENTES DESC;

    DROP TABLE #R; DROP TABLE #M; DROP TABLE #RP; DROP TABLE #PED; DROP TABLE #ING; DROP TABLE #REF;
END;
GO
