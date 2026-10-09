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
--   [XL-TANGO] STA14/STA20    cantidad de kits por remito (apertura)
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

    -- ── 1b. Kits por remito (Tango, vía linked server XL-TANGO) ──────────
    -- BI_FACTURACION_LOGISTICA solo trae los componentes. En STA20 cada kit
    -- tiene un renglón cabecera (COD_ARTICU = COD_ARTICU_KIT, CANTIDAD = kits)
    -- y debajo sus componentes (COD_ARTICU_KIT = código del kit).
    -- OPENQUERY (no INSERT…EXEC AT) para no abrir transacción distribuida.
    -- Si Tango no responde, el resto del SP sigue y los kits quedan en NULL.
    CREATE TABLE #K (N_COMP VARCHAR(20) COLLATE DATABASE_DEFAULT, KITS FLOAT, UNID_KIT FLOAT);
    DECLARE @KITS_OK BIT = 1;
    DECLARE @LISTA NVARCHAR(MAX) = STUFF((
        SELECT DISTINCT N''',''' + CAST(N_COMP AS NVARCHAR(20)) COLLATE DATABASE_DEFAULT
        FROM #R WHERE CHARINDEX('''', N_COMP) = 0 FOR XML PATH(''), TYPE
    ).value('.', 'NVARCHAR(MAX)'), 1, 3, N'');
    IF @LISTA IS NOT NULL
    BEGIN
        DECLARE @QK NVARCHAR(MAX) = N'SELECT h.N_COMP,
                SUM(CASE WHEN r.COD_ARTICU =  r.COD_ARTICU_KIT THEN r.CANTIDAD ELSE 0 END) AS KITS,
                SUM(CASE WHEN r.COD_ARTICU <> r.COD_ARTICU_KIT THEN r.CANTIDAD ELSE 0 END) AS UNID_KIT
            FROM LAKER_SA.dbo.STA14 h
            JOIN LAKER_SA.dbo.STA20 r ON r.TCOMP_IN_S = h.TCOMP_IN_S AND r.NCOMP_IN_S = h.NCOMP_IN_S
            WHERE h.T_COMP = ''REM'' AND h.N_COMP IN (''' + @LISTA + N''')
              AND ISNULL(r.COD_ARTICU_KIT, '''') <> ''''
            GROUP BY h.N_COMP';
        DECLARE @OQ NVARCHAR(MAX) = N'INSERT INTO #K SELECT * FROM OPENQUERY([XL-TANGO], '''
                                  + REPLACE(@QK, '''', '''''') + N''')';
        BEGIN TRY
            EXEC sp_executesql @OQ;
        END TRY
        BEGIN CATCH
            TRUNCATE TABLE #K;
            SET @KITS_OK = 0;
        END CATCH
    END;

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
        CAST(0.95 AS DECIMAL(5,2))                                      AS META,
        @KITS_OK                                                        AS KITS_OK;

    -- ── Result set 2: apertura por canal y tipo ───────────────────────────
    ;WITH P AS (   -- un pedido cuenta en el canal/tipo de su remito
        SELECT DISTINCT rp.CANAL, rp.TIPO_FACTURACION, p.TALON_PED, p.NRO_PEDIDO,
               p.UNID_PEDIDAS, p.UNID_PENDIENTES
        FROM #RP rp JOIN #PED p ON p.NRO_PEDIDO = rp.NRO_PEDIDO AND p.TALON_PED = rp.TALON_PED
    ), U AS (
        SELECT CANAL, TIPO_FACTURACION, SUM(UNID_REMITO) AS UNID_REMITIDAS,
               COUNT(DISTINCT N_COMP) AS REMITOS
        FROM #RP GROUP BY CANAL, TIPO_FACTURACION
    ), K AS (      -- kits del remito, en el canal/tipo del remito
        SELECT r.CANAL, r.TIPO_FACTURACION, SUM(k.KITS) AS KITS, SUM(k.UNID_KIT) AS UNID_KIT
        FROM (SELECT DISTINCT N_COMP, CANAL, TIPO_FACTURACION FROM #R) r
        JOIN #K k ON k.N_COMP = r.N_COMP COLLATE DATABASE_DEFAULT
        GROUP BY r.CANAL, r.TIPO_FACTURACION
    )
    SELECT u.CANAL, u.TIPO_FACTURACION,
           CAST(u.UNID_REMITIDAS AS DECIMAL(18,0))                       AS UNID_REMITIDAS,
           CAST(MAX(k.KITS)     AS DECIMAL(18,0))                        AS KITS,
           CAST(MAX(k.UNID_KIT) AS DECIMAL(18,0))                        AS UNID_KIT,
           u.REMITOS,
           COUNT(p.NRO_PEDIDO)                                           AS PEDIDOS,
           SUM(CASE WHEN p.UNID_PENDIENTES <= 0 THEN 1 ELSE 0 END)       AS PED_COMPLETOS,
           CAST(CASE WHEN SUM(p.UNID_PEDIDAS) > 0
                     THEN 1.0 - SUM(p.UNID_PENDIENTES) / SUM(p.UNID_PEDIDAS) END AS DECIMAL(10,4)) AS FILL_RATE
    FROM U u
    LEFT JOIN P p ON p.CANAL = u.CANAL AND p.TIPO_FACTURACION = u.TIPO_FACTURACION
    LEFT JOIN K k ON k.CANAL = u.CANAL AND k.TIPO_FACTURACION = u.TIPO_FACTURACION
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

    DROP TABLE #R; DROP TABLE #M; DROP TABLE #RP; DROP TABLE #PED; DROP TABLE #K;
END;
GO
