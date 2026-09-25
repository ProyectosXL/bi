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

    -- ── Ingreso del día anterior: pedidos cargados en D−1 y su cumplimiento ─
    -- Día calendario anterior (también hay carga los fines de semana).
    DECLARE @ING_FECHA DATE = DATEADD(DAY, -1, @FECHA);

    SELECT e.TALON_PED, e.NRO_PEDIDO, MAX(e.CANAL) AS CANAL,
           SUM(ISNULL(e.CANT_PEDID, 0)) AS UNID_PEDIDAS,
           SUM(ISNULL(e.CANT_PEND, 0))  AS UNID_PENDIENTES
    INTO #ING
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    WHERE e.FECHA_PEDI = @ING_FECHA
      AND ISNULL(e.ESTADO_TANGO, '') <> 'CANCELADO'
      AND (@CANAL IS NULL OR e.CANAL = @CANAL)
      AND (@TIPO  IS NULL OR e.TIPO_FACTURACION = @TIPO)
    GROUP BY e.TALON_PED, e.NRO_PEDIDO;

    -- Unidades de esos pedidos que salieron en los remitos del día D
    SELECT i.TALON_PED, i.NRO_PEDIDO, SUM(rp.UNID_REMITO) AS UNID_EN_D
    INTO #INGD
    FROM #ING i
    JOIN #RP rp ON rp.NRO_PEDIDO = i.NRO_PEDIDO AND rp.TALON_PED = i.TALON_PED
    GROUP BY i.TALON_PED, i.NRO_PEDIDO;

    -- ── Result set 4: KPIs del ingreso ────────────────────────────────────
    SELECT
        @ING_FECHA                                                         AS ING_FECHA,
        COUNT(*)                                                           AS ING_PEDIDOS,
        CAST(ISNULL(SUM(i.UNID_PEDIDAS), 0) AS DECIMAL(18,0))              AS ING_UNID_PEDIDAS,
        CAST(ISNULL(SUM(i.UNID_PEDIDAS - i.UNID_PENDIENTES), 0) AS DECIMAL(18,0)) AS ING_UNID_REMITIDAS_ACUM,
        CAST(ISNULL(SUM(i.UNID_PENDIENTES), 0) AS DECIMAL(18,0))           AS ING_UNID_PENDIENTES,
        CAST(CASE WHEN SUM(i.UNID_PEDIDAS) > 0
                  THEN 1.0 - SUM(i.UNID_PENDIENTES) / SUM(i.UNID_PEDIDAS) END AS DECIMAL(10,4)) AS ING_CUMPL,
        SUM(CASE WHEN i.UNID_PENDIENTES <= 0 THEN 1 ELSE 0 END)            AS ING_COMPLETOS,
        CAST(ISNULL((SELECT SUM(UNID_EN_D) FROM #INGD), 0) AS DECIMAL(18,0)) AS ING_REMITIDAS_EN_D,
        (SELECT COUNT(*) FROM #INGD)                                       AS ING_PED_CON_REMITO_EN_D,
        CAST(0.95 AS DECIMAL(5,2))                                         AS META
    FROM #ING i;

    -- ── Result set 5: ingreso por canal ───────────────────────────────────
    SELECT
        i.CANAL,
        COUNT(*)                                                           AS PEDIDOS,
        CAST(SUM(i.UNID_PEDIDAS) AS DECIMAL(18,0))                         AS UNID_PEDIDAS,
        CAST(SUM(i.UNID_PEDIDAS - i.UNID_PENDIENTES) AS DECIMAL(18,0))     AS UNID_REMITIDAS_ACUM,
        CAST(SUM(i.UNID_PENDIENTES) AS DECIMAL(18,0))                      AS UNID_PENDIENTES,
        CAST(CASE WHEN SUM(i.UNID_PEDIDAS) > 0
                  THEN 1.0 - SUM(i.UNID_PENDIENTES) / SUM(i.UNID_PEDIDAS) END AS DECIMAL(10,4)) AS CUMPL,
        SUM(CASE WHEN i.UNID_PENDIENTES <= 0 THEN 1 ELSE 0 END)            AS COMPLETOS,
        CAST(ISNULL(SUM(d.UNID_EN_D), 0) AS DECIMAL(18,0))                 AS REMITIDAS_EN_D
    FROM #ING i
    LEFT JOIN #INGD d ON d.NRO_PEDIDO = i.NRO_PEDIDO AND d.TALON_PED = i.TALON_PED
    GROUP BY i.CANAL
    ORDER BY UNID_PEDIDAS DESC;

    DROP TABLE #R; DROP TABLE #M; DROP TABLE #RP; DROP TABLE #PED; DROP TABLE #ING; DROP TABLE #INGD;
END;
GO
