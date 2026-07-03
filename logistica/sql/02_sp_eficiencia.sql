USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 02_sp_eficiencia.sql
-- RO_SP_EFICIENCIA_LOGISTICA
-- Área 1: KPIs de eficiencia logística + evolución mensual.
--
-- Result sets:
--   1. KPIs período actual + año anterior (incl. pérdida AA)
--   2. Evolución mensual interanual (año actual vs año anterior, ene–dic)
--   3. Filtros disponibles (canales)
--   4. Eficiencia por canal — período actual
--   5. % Eficiencia unidades por cliente — peores 10 (período actual)
--   6. % Eficiencia unidades por rubro (período actual)
--   7. % Eficiencia por pedido y cliente (período actual)
--   8. Proporción e importe de pérdida fact. — últimos 12 meses
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

    -- FILTRO_EFICIENCIA — replica la query de referencia de "Pérdida fact. $":
    --   ESTADO se calcula agrupando BI_EFICIENCIA_LOGISTICA contra sí misma
    --   por (FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL):
    --     SIN FACTURAR = SUM(CANT_PEDID)=SUM(CANT_PEND)
    --     COMPLETO     = SUM(CANT_PEND)=0
    --     PARCIAL      = resto
    --   y se vuelve a unir a BI_EFICIENCIA_LOGISTICA por esas 4 columnas
    --   (INNER JOIN — el match siempre existe, es un self-aggregate).
    --   Filtros finales: ESTADO<>'SIN FACTURAR', ESTADO_TANGO<>'CANCELADO',
    --                     TIPO_FACTURACION<>'DIST. INICIAL' (sin ISNULL: la
    --   query de referencia usa NULL SQL estándar, no compensa blanks).
    --
    -- OJO: NO usar BI_KPI_LOG_FACTURACION para esto — es una tabla distinta
    -- y da un universo/agrupación diferente al de la query de referencia.

    DECLARE @AA_DESDE DATE = DATEADD(YEAR, -1, @FECHA_DESDE);
    DECLARE @AA_HASTA DATE = DATEADD(YEAR, -1, @FECHA_HASTA);

    -- Evolución interanual: desde el 1-ene del año anterior al de @FECHA_HASTA.
    -- Devuelve el año anterior completo + el año actual hasta la fecha.
    DECLARE @YOY_DESDE DATE = DATEFROMPARTS(YEAR(@FECHA_HASTA) - 1, 1, 1);

    -- Serie de pérdida: primer día del mes, 11 meses antes de @FECHA_HASTA.
    DECLARE @PERD_DESDE DATE = DATEFROMPARTS(YEAR(DATEADD(MONTH,-11,@FECHA_HASTA)),
                                             MONTH(DATEADD(MONTH,-11,@FECHA_HASTA)), 1);

    -- Universo mínimo a reconstruir (cubre AA + evolución interanual + serie pérdida).
    DECLARE @BASE_DESDE DATE = @YOY_DESDE;
    IF @AA_DESDE   < @BASE_DESDE SET @BASE_DESDE = @AA_DESDE;
    IF @PERD_DESDE < @BASE_DESDE SET @BASE_DESDE = @PERD_DESDE;

    -- ESTADO: self-aggregate de BI_EFICIENCIA_LOGISTICA por (FECHA_PEDI,
    -- TALON_PED, NRO_PEDIDO, CANAL) — igual que la query de referencia.
    -- Sin filtro de canal acá: CANAL es parte de la clave, no un filtro:
    -- el filtro @CANAL se sigue aplicando en cada result set más abajo.
    SELECT
        e.FECHA_PEDI,
        e.TALON_PED,
        e.NRO_PEDIDO,
        e.CANAL,
        CASE
            WHEN SUM(e.CANT_PEDID) = SUM(e.CANT_PEND) THEN 'SIN FACTURAR'
            WHEN SUM(e.CANT_PEND)  = 0                THEN 'COMPLETO'
            ELSE                                       'PARCIAL'
        END AS ESTADO
    INTO #EstadosPedidos
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    WHERE e.FECHA_PEDI BETWEEN @BASE_DESDE AND @FECHA_HASTA
    GROUP BY e.FECHA_PEDI, e.TALON_PED, e.NRO_PEDIDO, e.CANAL;

    CREATE NONCLUSTERED INDEX IX_Est ON #EstadosPedidos (FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL);

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

        -- Pérdida año anterior (mismo rango, año previo)
        CAST(ISNULL(SUM(CASE WHEN e.FECHA_PEDI BETWEEN @AA_DESDE AND @AA_HASTA
                         THEN ISNULL(e.IMPORTE_PENDIENTE,0) END), 0) AS DECIMAL(18,2)) AS PERDIDA_FACT_AA,

        CAST(ISNULL(SUM(CASE WHEN e.FECHA_PEDI BETWEEN @AA_DESDE AND @AA_HASTA
                         THEN ISNULL(e.IMPORTE_PEDIDO,0) END), 0) AS DECIMAL(18,2))    AS IMPORTE_PEDIDO_AA,

        CAST(0.95 AS DECIMAL(5,2)) AS META_EFICIENCIA

    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    JOIN #EstadosPedidos est
      ON est.FECHA_PEDI  = e.FECHA_PEDI
     AND est.TALON_PED   = e.TALON_PED
     AND est.NRO_PEDIDO  = e.NRO_PEDIDO
     AND est.CANAL       = e.CANAL
    WHERE (@CANAL IS NULL OR e.CANAL = @CANAL)
      AND e.FECHA_PEDI BETWEEN @AA_DESDE AND @FECHA_HASTA
      AND e.ESTADO_TANGO <> 'CANCELADO'
      AND e.TIPO_FACTURACION <> 'DIST. INICIAL'
      AND est.ESTADO <> 'SIN FACTURAR';

    -- ── Result set 2: evolución mensual interanual ────────────────────────
    -- Año anterior completo + año actual hasta la fecha. El front pivota por
    -- mes (ene–dic) y dibuja una línea por año.
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
          ON est.FECHA_PEDI  = e.FECHA_PEDI
         AND est.TALON_PED   = e.TALON_PED
         AND est.NRO_PEDIDO  = e.NRO_PEDIDO
         AND est.CANAL       = e.CANAL
        WHERE e.ESTADO_TANGO <> 'CANCELADO'
          AND e.TIPO_FACTURACION <> 'DIST. INICIAL'
          AND est.ESTADO <> 'SIN FACTURAR'
    ) fe ON fe.FECHA_PEDI = c.FECHA
        AND (@CANAL IS NULL OR fe.CANAL = @CANAL)
    WHERE c.FECHA BETWEEN @YOY_DESDE AND @FECHA_HASTA
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
    JOIN #EstadosPedidos est
      ON est.FECHA_PEDI  = e.FECHA_PEDI
     AND est.TALON_PED   = e.TALON_PED
     AND est.NRO_PEDIDO  = e.NRO_PEDIDO
     AND est.CANAL       = e.CANAL
    WHERE e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND e.ESTADO_TANGO <> 'CANCELADO'
      AND e.TIPO_FACTURACION <> 'DIST. INICIAL'
      AND est.ESTADO <> 'SIN FACTURAR'
      AND e.CANAL IS NOT NULL AND LTRIM(RTRIM(e.CANAL)) <> ''
    GROUP BY e.CANAL
    ORDER BY e.CANAL;

    -- ── Result set 5: % Eficiencia unidades por cliente — peores 10 ────────
    SELECT TOP 10
        LTRIM(RTRIM(e.CLIENTE)) AS CLIENTE,
        CAST(ISNULL(SUM(e.CANT_PEDID),     0) AS DECIMAL(18,2)) AS UNID_PEDIDAS,
        CAST(ISNULL(SUM(e.CANT_FACTURADA), 0) AS DECIMAL(18,2)) AS UNID_FACTURADAS
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    JOIN #EstadosPedidos est
      ON est.FECHA_PEDI  = e.FECHA_PEDI
     AND est.TALON_PED   = e.TALON_PED
     AND est.NRO_PEDIDO  = e.NRO_PEDIDO
     AND est.CANAL       = e.CANAL
    WHERE e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND (@CANAL IS NULL OR e.CANAL = @CANAL)
      AND e.ESTADO_TANGO <> 'CANCELADO'
      AND e.TIPO_FACTURACION <> 'DIST. INICIAL'
      AND est.ESTADO <> 'SIN FACTURAR'
      AND e.CLIENTE IS NOT NULL AND LTRIM(RTRIM(e.CLIENTE)) <> ''
    GROUP BY LTRIM(RTRIM(e.CLIENTE))
    HAVING SUM(e.CANT_PEDID) > 0 AND SUM(e.CANT_FACTURADA) > 0
    ORDER BY (SUM(e.CANT_FACTURADA) / NULLIF(SUM(e.CANT_PEDID),0)) ASC,
             SUM(e.CANT_PEDID) DESC;

    -- ── Result set 6: % Eficiencia unidades por rubro — período actual ─────
    SELECT
        ISNULL(NULLIF(LTRIM(RTRIM(e.RUBRO)), ''), 'SIN RUBRO') AS RUBRO,
        CAST(ISNULL(SUM(e.CANT_PEDID),     0) AS DECIMAL(18,2)) AS UNID_PEDIDAS,
        CAST(ISNULL(SUM(e.CANT_FACTURADA), 0) AS DECIMAL(18,2)) AS UNID_FACTURADAS
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    JOIN #EstadosPedidos est
      ON est.FECHA_PEDI  = e.FECHA_PEDI
     AND est.TALON_PED   = e.TALON_PED
     AND est.NRO_PEDIDO  = e.NRO_PEDIDO
     AND est.CANAL       = e.CANAL
    WHERE e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND (@CANAL IS NULL OR e.CANAL = @CANAL)
      AND e.ESTADO_TANGO <> 'CANCELADO'
      AND e.TIPO_FACTURACION <> 'DIST. INICIAL'
      AND est.ESTADO <> 'SIN FACTURAR'
    GROUP BY ISNULL(NULLIF(LTRIM(RTRIM(e.RUBRO)), ''), 'SIN RUBRO')
    HAVING SUM(e.CANT_PEDID) > 0 AND SUM(e.CANT_FACTURADA) > 0
    ORDER BY (SUM(e.CANT_FACTURADA) / NULLIF(SUM(e.CANT_PEDID),0)) ASC;

    -- ── Result set 7: % Eficiencia por pedido y cliente — período actual ───
    SELECT
        LTRIM(RTRIM(e.CLIENTE))                                AS CLIENTE,
        LTRIM(RTRIM(e.NRO_PEDIDO))                             AS NRO_PEDIDO,
        MIN(e.FECHA_PEDI)                                      AS FECHA_PEDI,
        CAST(ISNULL(SUM(e.CANT_PEDID),     0) AS DECIMAL(18,2)) AS UNID_PEDIDAS,
        CAST(ISNULL(SUM(e.CANT_FACTURADA), 0) AS DECIMAL(18,2)) AS UNID_FACTURADAS
    FROM dbo.BI_EFICIENCIA_LOGISTICA e
    JOIN #EstadosPedidos est
      ON est.FECHA_PEDI  = e.FECHA_PEDI
     AND est.TALON_PED   = e.TALON_PED
     AND est.NRO_PEDIDO  = e.NRO_PEDIDO
     AND est.CANAL       = e.CANAL
    WHERE e.FECHA_PEDI BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND (@CANAL IS NULL OR e.CANAL = @CANAL)
      AND e.ESTADO_TANGO <> 'CANCELADO'
      AND e.TIPO_FACTURACION <> 'DIST. INICIAL'
      AND est.ESTADO <> 'SIN FACTURAR'
      AND e.CLIENTE IS NOT NULL AND LTRIM(RTRIM(e.CLIENTE)) <> ''
    GROUP BY LTRIM(RTRIM(e.CLIENTE)), LTRIM(RTRIM(e.NRO_PEDIDO))
    HAVING SUM(e.CANT_PEDID) > 0 AND SUM(e.CANT_FACTURADA) > 0
    ORDER BY CLIENTE, NRO_PEDIDO;

    -- ── Result set 8: proporción e importe de pérdida fact. — últ. 12 meses ─
    SELECT
        c.ANIO,
        c.MES,
        c.NOMBRE_MES,
        CAST(ISNULL(SUM(fe.IMPORTE_PENDIENTE), 0) AS DECIMAL(18,2)) AS PERDIDA_FACT,
        CAST(ISNULL(SUM(fe.IMPORTE_PEDIDO),    0) AS DECIMAL(18,2)) AS IMPORTE_PEDIDO
    FROM dbo.RO_T_CALENDARIO c
    LEFT JOIN (
        SELECT e.FECHA_PEDI, e.CANAL,
               ISNULL(e.IMPORTE_PENDIENTE,0) AS IMPORTE_PENDIENTE,
               ISNULL(e.IMPORTE_PEDIDO,0)    AS IMPORTE_PEDIDO
        FROM dbo.BI_EFICIENCIA_LOGISTICA e
        JOIN #EstadosPedidos est
          ON est.FECHA_PEDI  = e.FECHA_PEDI
         AND est.TALON_PED   = e.TALON_PED
         AND est.NRO_PEDIDO  = e.NRO_PEDIDO
         AND est.CANAL       = e.CANAL
        WHERE e.ESTADO_TANGO <> 'CANCELADO'
          AND e.TIPO_FACTURACION <> 'DIST. INICIAL'
          AND est.ESTADO <> 'SIN FACTURAR'
    ) fe ON fe.FECHA_PEDI = c.FECHA
        AND (@CANAL IS NULL OR fe.CANAL = @CANAL)
    WHERE c.FECHA BETWEEN @PERD_DESDE AND @FECHA_HASTA
    GROUP BY c.ANIO, c.MES, c.NOMBRE_MES
    ORDER BY c.ANIO, c.MES;

    DROP TABLE #EstadosPedidos;
END;
GO
