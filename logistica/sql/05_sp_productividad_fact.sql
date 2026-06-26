USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 05_sp_productividad_fact.sql
-- RO_SP_PRODUCTIVIDAD_FACTURACION
-- Área 4: Productividad de facturación.
--
-- Filtros: @TIPO (TIPO_FACTURACION) y @RUBRO. Usuarios agrupados
-- sin distinguir mayúsculas (UPPER), igual que el tablero anterior.
--
-- Semántica de filtros (replica el tablero viejo):
--   Respetan @TIPO  → Unidades, %, Tendencia (mediana por usuario),
--                     Unid. últ. 30 días, Promedio por día, Pico x día.
--   IGNORAN @TIPO   → Pico facturación (máx. diario por usuario),
--                     Días productivos, Pico x usuario,
--                     Tendencia x día x usuario (mediana de días >200).
--   @RUBRO se aplica en todos los casos.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_PRODUCTIVIDAD_FACTURACION','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_PRODUCTIVIDAD_FACTURACION;
GO

CREATE PROCEDURE dbo.RO_SP_PRODUCTIVIDAD_FACTURACION
    @FECHA_DESDE DATE,
    @FECHA_HASTA DATE,
    @TIPO  NVARCHAR(100) = NULL,
    @RUBRO NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @HOY DATE = CAST(GETDATE() AS DATE);
    DECLARE @ULT7_DESDE DATE = DATEADD(DAY, -6, @FECHA_HASTA);

    -- Base A: respeta @TIPO y @RUBRO. Por usuario (merge mayúsculas) y día.
    SELECT UPPER(LTRIM(RTRIM(USUARIO))) AS UMAJ,
           FECHA_COMP,
           SUM(CANTIDAD) AS Unidades
    INTO #A
    FROM dbo.BI_FACTURACION_LOGISTICA
    WHERE FECHA_COMP BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND (@TIPO  IS NULL OR TIPO_FACTURACION = @TIPO)
      AND (@RUBRO IS NULL OR RUBRO = @RUBRO)
      AND LTRIM(RTRIM(USUARIO)) <> ''
    GROUP BY UPPER(LTRIM(RTRIM(USUARIO))), FECHA_COMP;

    -- Base B: IGNORA @TIPO (respeta @RUBRO). Por usuario y día.
    SELECT UPPER(LTRIM(RTRIM(USUARIO))) AS UMAJ,
           FECHA_COMP,
           SUM(CANTIDAD) AS Unidades
    INTO #B
    FROM dbo.BI_FACTURACION_LOGISTICA
    WHERE FECHA_COMP BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND (@RUBRO IS NULL OR RUBRO = @RUBRO)
      AND LTRIM(RTRIM(USUARIO)) <> ''
    GROUP BY UPPER(LTRIM(RTRIM(USUARIO))), FECHA_COMP;

    -- Nombre de display por usuario (casing "lindo" = MAX sobre todos los tipos)
    SELECT UPPER(LTRIM(RTRIM(USUARIO))) AS UMAJ, MAX(LTRIM(RTRIM(USUARIO))) AS USUARIO
    INTO #N
    FROM dbo.BI_FACTURACION_LOGISTICA
    WHERE FECHA_COMP BETWEEN @FECHA_DESDE AND @FECHA_HASTA
      AND (@RUBRO IS NULL OR RUBRO = @RUBRO)
      AND LTRIM(RTRIM(USUARIO)) <> ''
    GROUP BY UPPER(LTRIM(RTRIM(USUARIO)));

    DECLARE @TOTAL DECIMAL(18,2) = (SELECT ISNULL(SUM(Unidades), 0) FROM #A);

    -- ── Result set 1: KPIs ────────────────────────────────────────────────
    SELECT
        CAST(@TOTAL AS DECIMAL(18,2))                                   AS UNIDADES_FACT,
        -- Promedio por día = promedio de los totales diarios (respeta tipo)
        CAST(ISNULL((SELECT AVG(CAST(dt AS FLOAT))
                     FROM (SELECT FECHA_COMP, SUM(Unidades) dt FROM #A GROUP BY FECHA_COMP) d), 0)
        AS DECIMAL(18,2))                                               AS PROMEDIO_DIA,
        -- Pico x día = máximo total diario (respeta tipo) + fecha
        CAST(ISNULL((SELECT MAX(dt) FROM (SELECT FECHA_COMP, SUM(Unidades) dt FROM #A GROUP BY FECHA_COMP) d), 0)
        AS DECIMAL(18,2))                                               AS PICO_DIA,
        (SELECT TOP 1 FECHA_COMP
         FROM (SELECT FECHA_COMP, SUM(Unidades) dt FROM #A GROUP BY FECHA_COMP) d
         ORDER BY dt DESC, FECHA_COMP DESC)                             AS PICO_DIA_FECHA,
        -- Pico x usuario = máx. usuario-día (IGNORA tipo) + nombre
        CAST(ISNULL((SELECT MAX(Unidades) FROM #B), 0) AS DECIMAL(18,2)) AS PICO_USUARIO,
        (SELECT TOP 1 n.USUARIO FROM #B b JOIN #N n ON n.UMAJ = b.UMAJ
         ORDER BY b.Unidades DESC)                                      AS PICO_USUARIO_NOMBRE,
        -- Tendencia x día x usuario = mediana de usuario-días con >200 (IGNORA tipo)
        CAST((SELECT TOP 1 PERCENTILE_CONT(0.5) WITHIN GROUP (
                  ORDER BY CASE WHEN Unidades > 200 THEN Unidades END) OVER ()
              FROM #B)
        AS DECIMAL(18,2))                                               AS TENDENCIA_GLOBAL;

    -- ── Result set 2: evolución diaria (gráfico, doble serie) ─────────────
    SELECT
        FECHA_COMP,
        CAST(SUM(Unidades) AS DECIMAL(18,2)) AS UNIDADES_DIA,   -- total del día (tipo)
        CAST(MAX(Unidades) AS DECIMAL(18,2)) AS PICO_USER       -- mejor usuario del día (tipo)
    FROM #A
    GROUP BY FECHA_COMP
    ORDER BY FECHA_COMP;

    -- ── Result set 3: tabla por usuario ───────────────────────────────────
    -- Conducida por #B (todos los usuarios, ignora tipo) → permite mostrar el
    -- Pico aunque el usuario no tenga unidades del tipo seleccionado.
    ;WITH MedA AS (
        SELECT DISTINCT UMAJ,
               CAST(PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY Unidades)
                    OVER (PARTITION BY UMAJ) AS DECIMAL(18,2)) AS TENDENCIA
        FROM #A
    ),
    AggA AS (SELECT UMAJ, SUM(Unidades) AS UNID FROM #A GROUP BY UMAJ),
    AggB AS (SELECT UMAJ, MAX(Unidades) AS PICO, COUNT(DISTINCT FECHA_COMP) AS DIAS FROM #B GROUP BY UMAJ),
    Ult30 AS (
        SELECT UPPER(LTRIM(RTRIM(USUARIO))) AS UMAJ, SUM(CANTIDAD) AS U30
        FROM dbo.BI_FACTURACION_LOGISTICA
        WHERE FECHA_COMP >= DATEADD(DAY, -30, @HOY)
          AND FECHA_COMP <  @HOY
          AND (@TIPO  IS NULL OR TIPO_FACTURACION = @TIPO)
          AND (@RUBRO IS NULL OR RUBRO = @RUBRO)
          AND LTRIM(RTRIM(USUARIO)) <> ''
        GROUP BY UPPER(LTRIM(RTRIM(USUARIO)))
    )
    SELECT
        n.USUARIO,
        CAST(a.UNID AS DECIMAL(18,2))                            AS UNIDADES_FACT,
        CAST(a.UNID * 1.0 / NULLIF(@TOTAL, 0) AS DECIMAL(10,4))  AS PCT_UNIDADES,
        CAST(b.PICO AS DECIMAL(18,2))                            AS PICO_FACT,
        m.TENDENCIA,
        b.DIAS                                                   AS DIAS_PRODUCTIVOS,
        CAST(ISNULL(u.U30, 0) AS DECIMAL(18,2))                  AS UNIDADES_ULT30
    FROM AggB b
    JOIN #N n         ON n.UMAJ = b.UMAJ
    LEFT JOIN AggA a  ON a.UMAJ = b.UMAJ
    LEFT JOIN MedA m  ON m.UMAJ = b.UMAJ
    LEFT JOIN Ult30 u ON u.UMAJ = b.UMAJ
    ORDER BY b.PICO DESC, n.USUARIO;

    -- ── Result set 4: unidades por usuario (últimos 7 días, respeta tipo) ──
    SELECT
        a.FECHA_COMP AS FECHA_PICK,
        n.USUARIO,
        CAST(SUM(a.Unidades) AS DECIMAL(18,2)) AS UNIDADES
    FROM #A a
    JOIN #N n ON n.UMAJ = a.UMAJ
    WHERE a.FECHA_COMP BETWEEN @ULT7_DESDE AND @FECHA_HASTA
    GROUP BY a.FECHA_COMP, n.USUARIO
    ORDER BY a.FECHA_COMP, n.USUARIO;

    DROP TABLE #A;
    DROP TABLE #B;
    DROP TABLE #N;
END;
GO
