USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 02_sp_eficiencia.sql
-- RO_SP_EFICIENCIA_LOGISTICA
-- Área 1: KPIs de eficiencia logística + evolución mensual.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_EFICIENCIA_LOGISTICA','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_EFICIENCIA_LOGISTICA;
GO

CREATE PROCEDURE dbo.RO_SP_EFICIENCIA_LOGISTICA
    @FECHA_DESDE DATE,
    @FECHA_HASTA DATE,
    @CANAL       NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    -- FILTRO_EFICIENCIA — replica la medida DAX de Power BI.
    -- En Power BI, ESTADO es una columna calculada en Power Query:
    --   origen : BI_KPI_LOG_FACTURACION GROUP BY (TALON_PED, NRO_PEDIDO)
    --   lógica : SIN FACTURAR = SUM(CANT_PEDID)=SUM(CANT_PEND)
    --            COMPLETO     = SUM(CANT_PEND)=0
    --            PARCIAL      = resto
    -- Se une a BI_EFICIENCIA_LOGISTICA por (TALON_PED, NRO_PEDIDO).
    -- Filtros completos: ESTADO<>'SIN FACTURAR', ESTADO_TANGO<>'CANCELADO',
    --                    TIPO_FACTURACION<>'DIST. INICIAL'

    DECLARE @AA_DESDE DATE = DATEADD(YEAR, -1, @FECHA_DESDE);
    DECLARE @AA_HASTA DATE = DATEADD(YEAR, -1, @FECHA_HASTA);
    DECLARE @EVOL_DESDE DATE = DATEFROMPARTS(YEAR(DATEADD(MONTH,-11,@FECHA_HASTA)),
                                             MONTH(DATEADD(MONTH,-11,@FECHA_HASTA)), 1);
    DECLARE @BASE_DESDE DATE = CASE WHEN @AA_DESDE < @EVOL_DESDE THEN @AA_DESDE ELSE @EVOL_DESDE END;

    -- Limitar el universo antes de reconstruir estado. Evita agrupar toda la
    -- tabla BI_KPI_LOG_FACTURACION en cada ejecucion del dashboard.
    SELECT DISTINCT
        e.TALON_PED,
        e.NRO_PEDIDO
    INTO #PedidosPeriodo
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    WHERE (@CANAL IS NULL OR e.CANAL = @CANAL)
      AND e.FECHA_PEDI BETWEEN @BASE_DESDE AND @FECHA_HASTA
      AND e.ESTADO_TANGO <> 'CANCELADO'
      AND ISNULL(e.TIPO_FACTURACION,'') <> 'DIST. INICIAL';

    CREATE NONCLUSTERED INDEX IX_Ped ON #PedidosPeriodo (TALON_PED, NRO_PEDIDO);

    -- Pre-calcular ESTADO solo para pedidos dentro del rango relevante.
    SELECT
        k.TALON_PED,
        k.NRO_PEDIDO,
        CASE
            WHEN SUM(k.CANT_PEDID) = SUM(k.CANT_PEND) THEN 'SIN FACTURAR'
            WHEN SUM(k.CANT_PEND)  = 0                THEN 'COMPLETO'
            ELSE                                       'PARCIAL'
        END AS ESTADO
    INTO #EstadosPedidos
    FROM dbo.BI_KPI_LOG_FACTURACION k
    JOIN #PedidosPeriodo p
      ON p.TALON_PED  = k.TALON_PED
     AND p.NRO_PEDIDO = k.NRO_PEDIDO
    GROUP BY k.TALON_PED, k.NRO_PEDIDO;

    CREATE NONCLUSTERED INDEX IX_Est ON #EstadosPedidos (TALON_PED, NRO_PEDIDO);

    -- ── Result set 1: KPIs período actual + año anterior ──────────────────
    SELECT
        CAST(ISNULL(SUM(CASE WHEN e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN e.CANT_FACTURADA END), 0) AS DECIMAL(18,2)) AS UNID_FACTURADAS,

        CAST(ISNULL(SUM(CASE WHEN e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN e.CANT_PEDID END), 0) AS DECIMAL(18,2))     AS UNID_PEDIDAS,

        CAST(ISNULL(SUM(CASE WHEN e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN e.CANT_PEND END), 0) AS DECIMAL(18,2))      AS UNID_PENDIENTES,

        -- Importe (sin filtro de estado adicional, refleja lo real cobrado)
        CAST(ISNULL(SUM(CASE WHEN e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN ISNULL(e.IMPORTE_PEDIDO,0) - ISNULL(e.IMPORTE_PENDIENTE,0) END), 0)
             AS DECIMAL(18,2))                                             AS IMPORTE_FACTURADO,

        CAST(ISNULL(SUM(CASE WHEN e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN ISNULL(e.IMPORTE_PENDIENTE,0) END), 0) AS DECIMAL(18,2)) AS PERDIDA_FACT,

        CAST(ISNULL(SUM(CASE WHEN e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                         THEN ISNULL(e.IMPORTE_PEDIDO,0) END), 0) AS DECIMAL(18,2))    AS IMPORTE_PEDIDO_FILTRADO,

        COUNT(DISTINCT CASE WHEN e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
                            THEN e.NRO_PEDIDO END)                         AS PEDIDOS_TOTAL,

        -- Año anterior
        CAST(ISNULL(SUM(CASE WHEN e.FECHA_PEDI BETWEEN @AA_DESDE AND @AA_HASTA
                         THEN e.CANT_FACTURADA END), 0) AS DECIMAL(18,2)) AS UNID_FACTURADAS_AA,

        CAST(ISNULL(SUM(CASE WHEN e.FECHA_PEDI BETWEEN @AA_DESDE AND @AA_HASTA
                         THEN e.CANT_PEDID END), 0) AS DECIMAL(18,2))     AS UNID_PEDIDAS_AA,

        COUNT(DISTINCT CASE WHEN e.FECHA_PEDI BETWEEN @AA_DESDE AND @AA_HASTA
                            THEN e.NRO_PEDIDO END)                         AS PEDIDOS_AA,

        CAST(0.95 AS DECIMAL(5,2)) AS META_EFICIENCIA

    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    LEFT JOIN #EstadosPedidos est
           ON est.TALON_PED  = e.TALON_PED
          AND est.NRO_PEDIDO = e.NRO_PEDIDO
    WHERE (@CANAL IS NULL OR e.CANAL = @CANAL)
      AND e.FECHA_PEDI BETWEEN @AA_DESDE AND @FECHA_HASTA
      AND e.ESTADO_TANGO <> 'CANCELADO'
      AND ISNULL(e.TIPO_FACTURACION,'') <> 'DIST. INICIAL'
      AND est.ESTADO <> 'SIN FACTURAR';   -- excluye SF y NULLs (sin match)

    -- ── Result set 2: evolución mensual — últimos 12 meses ────────────────
    -- La subquery filtra primero; el LEFT JOIN al calendario preserva meses sin datos.
    SELECT
        c.ANIO,
        c.MES,
        c.NOMBRE_MES,
        CAST(ISNULL(SUM(fe.CANT_FACTURADA), 0) AS DECIMAL(18,2)) AS UNID_FACTURADAS_MES,
        CAST(ISNULL(SUM(fe.CANT_PEDID),     0) AS DECIMAL(18,2)) AS UNID_PEDIDAS_MES
    FROM dbo.RO_T_CALENDARIO c
    LEFT JOIN (
        SELECT e.FECHA_PEDI, e.CANAL, e.CANT_FACTURADA, e.CANT_PEDID
        FROM dbo.BI_EFICIENCIA_LOGISTICA e
        JOIN #EstadosPedidos est
               ON est.TALON_PED  = e.TALON_PED
              AND est.NRO_PEDIDO = e.NRO_PEDIDO
        WHERE e.ESTADO_TANGO <> 'CANCELADO'
          AND ISNULL(e.TIPO_FACTURACION,'') <> 'DIST. INICIAL'
          AND est.ESTADO <> 'SIN FACTURAR'
    ) fe ON fe.FECHA_PEDI = c.FECHA
        AND (@CANAL IS NULL OR fe.CANAL = @CANAL)
    WHERE c.FECHA BETWEEN
              @EVOL_DESDE
          AND @FECHA_HASTA
    GROUP BY c.ANIO, c.MES, c.NOMBRE_MES
    ORDER BY c.ANIO, c.MES;

    -- ── Result set 3: filtros disponibles (canales) ───────────────────────
    SELECT DISTINCT CANAL
    FROM dbo.BI_EFICIENCIA_LOGISTICA
    WHERE CANAL IS NOT NULL AND LTRIM(RTRIM(CANAL)) <> ''
    ORDER BY CANAL;

    -- ── Result set 4: eficiencia por canal — período actual ───────────────
    SELECT
        e.CANAL,
        CAST(ISNULL(SUM(e.CANT_FACTURADA), 0) AS DECIMAL(18,2)) AS UNID_FACTURADAS,
        CAST(ISNULL(SUM(e.CANT_PEDID),     0) AS DECIMAL(18,2)) AS UNID_PEDIDAS
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    LEFT JOIN #EstadosPedidos est
           ON est.TALON_PED  = e.TALON_PED
          AND est.NRO_PEDIDO = e.NRO_PEDIDO
    WHERE e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND e.ESTADO_TANGO <> 'CANCELADO'
      AND ISNULL(e.TIPO_FACTURACION,'') <> 'DIST. INICIAL'
      AND est.ESTADO <> 'SIN FACTURAR'
      AND e.CANAL IS NOT NULL AND LTRIM(RTRIM(e.CANAL)) <> ''
    GROUP BY e.CANAL
    ORDER BY e.CANAL;

    DROP TABLE #EstadosPedidos;
    DROP TABLE #PedidosPeriodo;
END;
GO
