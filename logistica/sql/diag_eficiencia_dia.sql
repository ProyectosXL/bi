USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- diag_eficiencia_dia.sql
-- Diagnóstico del KPI "Eficiencia Facturación" para UN día puntual.
-- Caso reportado: 18/09/2026 -> 197 pedidas / 101 facturadas = 51,3%
--
-- Objetivo: mostrar qué parte de las unidades del día quedan FUERA
-- del KPI y cuánto de la baja eficiencia es maduración (pedidos del
-- viernes que se facturan el lunes) y no un problema operativo.
-- ============================================================

DECLARE @DIA DATE = '2026-09-18';   -- <<< cambiar acá el día a analizar

-- ── 1. UNIVERSO CRUDO DEL DÍA (sin ningún filtro del SP) ─────────────────
--    Esto es "todo lo que se pidió ese día". Comparar UNID_PEDIDAS contra
--    las 197 que muestra el tablero: la diferencia es lo que el SP descarta.
SELECT
    'CRUDO (sin filtros)'                                AS UNIVERSO,
    COUNT(DISTINCT NRO_PEDIDO)                           AS PEDIDOS,
    CAST(SUM(ISNULL(CANT_PEDID,0))     AS DECIMAL(18,0)) AS UNID_PEDIDAS,
    CAST(SUM(ISNULL(CANT_FACTURADA,0)) AS DECIMAL(18,0)) AS UNID_FACTURADAS,
    CAST(SUM(ISNULL(CANT_PEND,0))      AS DECIMAL(18,0)) AS UNID_PENDIENTES
FROM dbo.BI_EFICIENCIA_LOGISTICA
WHERE FECHA_PEDI = @DIA;
GO


-- ── 2. DÓNDE SE PIERDEN LAS UNIDADES (motivo de exclusión) ───────────────
--    El SP aplica 3 filtros. Este desglose dice cuánto saca cada uno.
DECLARE @DIA DATE = '2026-09-18';

WITH Est AS (
    SELECT FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL,
           CASE WHEN SUM(CANT_PEDID) = SUM(CANT_PEND) THEN 'SIN FACTURAR'
                WHEN SUM(CANT_PEND)  = 0              THEN 'COMPLETO'
                ELSE 'PARCIAL' END AS ESTADO
    FROM dbo.BI_EFICIENCIA_LOGISTICA
    WHERE FECHA_PEDI = @DIA
    GROUP BY FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL
)
SELECT
    CASE
        WHEN est.ESTADO = 'SIN FACTURAR'                       THEN '1. Pedido SIN FACTURAR (sale entero del KPI)'
        WHEN e.ESTADO_TANGO IS NULL                            THEN '2. ESTADO_TANGO NULL (se descarta por <> sin ISNULL)'
        WHEN e.ESTADO_TANGO = 'CANCELADO'                      THEN '3. Linea CANCELADA'
        WHEN e.TIPO_FACTURACION IS NULL                        THEN '4. TIPO_FACTURACION NULL (se descarta por <> sin ISNULL)'
        WHEN e.TIPO_FACTURACION = 'DIST. INICIAL'              THEN '5. DIST. INICIAL'
        ELSE                                                        '0. >>> ENTRA AL KPI <<<'
    END                                                  AS MOTIVO,
    COUNT(DISTINCT e.NRO_PEDIDO)                         AS PEDIDOS,
    CAST(SUM(ISNULL(e.CANT_PEDID,0))     AS DECIMAL(18,0)) AS UNID_PEDIDAS,
    CAST(SUM(ISNULL(e.CANT_FACTURADA,0)) AS DECIMAL(18,0)) AS UNID_FACTURADAS
FROM dbo.BI_EFICIENCIA_LOGISTICA e
JOIN Est est
  ON est.FECHA_PEDI = e.FECHA_PEDI AND est.TALON_PED = e.TALON_PED
 AND est.NRO_PEDIDO = e.NRO_PEDIDO AND est.CANAL     = e.CANAL
WHERE e.FECHA_PEDI = @DIA
GROUP BY
    CASE
        WHEN est.ESTADO = 'SIN FACTURAR'                       THEN '1. Pedido SIN FACTURAR (sale entero del KPI)'
        WHEN e.ESTADO_TANGO IS NULL                            THEN '2. ESTADO_TANGO NULL (se descarta por <> sin ISNULL)'
        WHEN e.ESTADO_TANGO = 'CANCELADO'                      THEN '3. Linea CANCELADA'
        WHEN e.TIPO_FACTURACION IS NULL                        THEN '4. TIPO_FACTURACION NULL (se descarta por <> sin ISNULL)'
        WHEN e.TIPO_FACTURACION = 'DIST. INICIAL'              THEN '5. DIST. INICIAL'
        ELSE                                                        '0. >>> ENTRA AL KPI <<<'
    END
ORDER BY MOTIVO;
GO


-- ── 3. CURVA DE MADURACIÓN — la prueba clave ─────────────────────────────
--    Misma fórmula del SP, pero día por día de los últimos 25 días.
--    Si la eficiencia SUBE a medida que el pedido envejece, el 51,3% del
--    18/09 no es un problema operativo: es que el día todavía no maduró.
DECLARE @HASTA DATE = '2026-09-18';
DECLARE @DESDE DATE = DATEADD(DAY, -24, @HASTA);

WITH Est AS (
    SELECT FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL,
           CASE WHEN SUM(CANT_PEDID) = SUM(CANT_PEND) THEN 'SIN FACTURAR'
                WHEN SUM(CANT_PEND)  = 0              THEN 'COMPLETO'
                ELSE 'PARCIAL' END AS ESTADO
    FROM dbo.BI_EFICIENCIA_LOGISTICA
    WHERE FECHA_PEDI BETWEEN @DESDE AND @HASTA
    GROUP BY FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL
)
SELECT
    e.FECHA_PEDI,
    DATENAME(WEEKDAY, e.FECHA_PEDI)                        AS DIA_SEMANA,
    DATEDIFF(DAY, e.FECHA_PEDI, CAST(GETDATE() AS DATE))   AS DIAS_DE_MADUREZ,
    COUNT(DISTINCT e.NRO_PEDIDO)                           AS PEDIDOS_EN_KPI,
    CAST(SUM(ISNULL(e.CANT_PEDID,0))     AS DECIMAL(18,0)) AS UNID_PEDIDAS,
    CAST(SUM(ISNULL(e.CANT_FACTURADA,0)) AS DECIMAL(18,0)) AS UNID_FACTURADAS,
    CAST(100.0 * SUM(ISNULL(e.CANT_FACTURADA,0))
         / NULLIF(SUM(ISNULL(e.CANT_PEDID,0)),0) AS DECIMAL(5,1)) AS EFI_PCT
FROM dbo.BI_EFICIENCIA_LOGISTICA e
JOIN Est est
  ON est.FECHA_PEDI = e.FECHA_PEDI AND est.TALON_PED = e.TALON_PED
 AND est.NRO_PEDIDO = e.NRO_PEDIDO AND est.CANAL     = e.CANAL
WHERE e.FECHA_PEDI BETWEEN @DESDE AND @HASTA
  AND e.ESTADO_TANGO     <> 'CANCELADO'
  AND e.TIPO_FACTURACION <> 'DIST. INICIAL'
  AND est.ESTADO         <> 'SIN FACTURAR'
GROUP BY e.FECHA_PEDI
ORDER BY e.FECHA_PEDI;
GO


-- ── 4. CONSISTENCIA: CANT_FACTURADA vs (CANT_PEDID - CANT_PEND) ──────────
--    El KPI de unidades usa CANT_FACTURADA; el de importe usa
--    IMPORTE_PEDIDO - IMPORTE_PENDIENTE. Si estas dos columnas no cierran,
--    unidades e importe están midiendo cosas distintas.
DECLARE @DIA DATE = '2026-09-18';

SELECT
    CAST(SUM(ISNULL(CANT_FACTURADA,0))                        AS DECIMAL(18,0)) AS SUMA_CANT_FACTURADA,
    CAST(SUM(ISNULL(CANT_PEDID,0) - ISNULL(CANT_PEND,0))      AS DECIMAL(18,0)) AS SUMA_PEDID_MENOS_PEND,
    SUM(CASE WHEN ISNULL(CANT_FACTURADA,0)
                  <> ISNULL(CANT_PEDID,0) - ISNULL(CANT_PEND,0)
             THEN 1 ELSE 0 END)                                                 AS LINEAS_QUE_NO_CIERRAN
FROM dbo.BI_EFICIENCIA_LOGISTICA
WHERE FECHA_PEDI = @DIA;
GO


-- ── 5. DETALLE PEDIDO POR PEDIDO DEL DÍA ─────────────────────────────────
--    Los 39 pedidos que el tablero contó + los que quedaron afuera.
--    Sirve para validar a mano 2 o 3 casos contra Tango.
DECLARE @DIA DATE = '2026-09-18';

WITH Est AS (
    SELECT FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL,
           CASE WHEN SUM(CANT_PEDID) = SUM(CANT_PEND) THEN 'SIN FACTURAR'
                WHEN SUM(CANT_PEND)  = 0              THEN 'COMPLETO'
                ELSE 'PARCIAL' END AS ESTADO
    FROM dbo.BI_EFICIENCIA_LOGISTICA
    WHERE FECHA_PEDI = @DIA
    GROUP BY FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL
)
SELECT
    e.NRO_PEDIDO,
    e.TALON_PED,
    e.CANAL,
    LTRIM(RTRIM(e.CLIENTE))                                AS CLIENTE,
    est.ESTADO,
    CASE WHEN est.ESTADO <> 'SIN FACTURAR' THEN 'SI' ELSE 'NO' END AS ENTRA_AL_KPI,
    CAST(SUM(ISNULL(e.CANT_PEDID,0))     AS DECIMAL(18,0)) AS UNID_PEDIDAS,
    CAST(SUM(ISNULL(e.CANT_FACTURADA,0)) AS DECIMAL(18,0)) AS UNID_FACTURADAS,
    CAST(SUM(ISNULL(e.CANT_PEND,0))      AS DECIMAL(18,0)) AS UNID_PENDIENTES,
    CAST(SUM(ISNULL(e.IMPORTE_PEDIDO,0))     AS DECIMAL(18,2)) AS IMPORTE_PEDIDO,
    CAST(SUM(ISNULL(e.IMPORTE_PENDIENTE,0))  AS DECIMAL(18,2)) AS IMPORTE_PENDIENTE
FROM dbo.BI_EFICIENCIA_LOGISTICA e
JOIN Est est
  ON est.FECHA_PEDI = e.FECHA_PEDI AND est.TALON_PED = e.TALON_PED
 AND est.NRO_PEDIDO = e.NRO_PEDIDO AND est.CANAL     = e.CANAL
WHERE e.FECHA_PEDI = @DIA
  AND e.ESTADO_TANGO     <> 'CANCELADO'
  AND e.TIPO_FACTURACION <> 'DIST. INICIAL'
GROUP BY e.NRO_PEDIDO, e.TALON_PED, e.CANAL, LTRIM(RTRIM(e.CLIENTE)), est.ESTADO
ORDER BY ENTRA_AL_KPI DESC, UNID_PEDIDAS DESC;
GO


-- ============================================================
-- 6. COBERTURA DEL KPI SOBRE UN PERÍODO LARGO
--    Pregunta: el 18/09 midió solo el 2% de las unidades del día.
--    ¿Es un caso extremo o es la norma?
--
--    Se corre sobre MESES CERRADOS (todo ya maduró), así el
--    "SIN FACTURAR" que aparece es ineficiencia real y no atraso.
-- ============================================================

DECLARE @DESDE DATE = '2025-09-01';
DECLARE @HASTA DATE = '2026-08-31';   -- <<< último mes YA CERRADO

-- Estado por pedido (mismo criterio que el SP)
SELECT FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL,
       CASE WHEN SUM(CANT_PEDID) = SUM(CANT_PEND) THEN 'SIN FACTURAR'
            WHEN SUM(CANT_PEND)  = 0              THEN 'COMPLETO'
            ELSE 'PARCIAL' END AS ESTADO
INTO #E
FROM dbo.BI_EFICIENCIA_LOGISTICA
WHERE FECHA_PEDI BETWEEN @DESDE AND @HASTA
GROUP BY FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL;

CREATE NONCLUSTERED INDEX IX_E ON #E (FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL);

-- Universo clasificado por motivo (misma precedencia que la consulta 2)
SELECT
    e.FECHA_PEDI,
    e.CANAL,
    e.NRO_PEDIDO,
    CASE
        WHEN est.ESTADO = 'SIN FACTURAR'              THEN 'SIN_FACTURAR'
        WHEN e.ESTADO_TANGO IS NULL                   THEN 'OTROS'
        WHEN e.ESTADO_TANGO = 'CANCELADO'             THEN 'OTROS'
        WHEN e.TIPO_FACTURACION IS NULL               THEN 'OTROS'
        WHEN e.TIPO_FACTURACION = 'DIST. INICIAL'     THEN 'DIST_INICIAL'
        ELSE                                               'KPI'
    END                                AS CLASE,
    ISNULL(e.CANT_PEDID,0)             AS CANT_PEDID,
    ISNULL(e.CANT_FACTURADA,0)         AS CANT_FACTURADA,
    ISNULL(e.IMPORTE_PEDIDO,0)         AS IMPORTE_PEDIDO
INTO #U
FROM dbo.BI_EFICIENCIA_LOGISTICA e
JOIN #E est
  ON est.FECHA_PEDI = e.FECHA_PEDI AND est.TALON_PED = e.TALON_PED
 AND est.NRO_PEDIDO = e.NRO_PEDIDO AND est.CANAL     = e.CANAL
WHERE e.FECHA_PEDI BETWEEN @DESDE AND @HASTA;


-- ── 6.A  Cobertura mes a mes ─────────────────────────────────────────────
--    PCT_COBERTURA = qué % de las unidades pedidas del mes llega al KPI.
--    Las 3 eficiencias = las 3 lecturas posibles del mismo mes.
SELECT
    YEAR(FECHA_PEDI)                                                     AS ANIO,
    MONTH(FECHA_PEDI)                                                    AS MES,
    CAST(SUM(CANT_PEDID) AS DECIMAL(18,0))                               AS UNID_PEDIDAS_TOT,
    CAST(SUM(CANT_FACTURADA) AS DECIMAL(18,0))                           AS UNID_FACT_TOT,

    CAST(SUM(CASE WHEN CLASE='KPI'          THEN CANT_PEDID END) AS DECIMAL(18,0)) AS PED_KPI,
    CAST(SUM(CASE WHEN CLASE='DIST_INICIAL' THEN CANT_PEDID END) AS DECIMAL(18,0)) AS PED_DIST_INI,
    CAST(SUM(CASE WHEN CLASE='SIN_FACTURAR' THEN CANT_PEDID END) AS DECIMAL(18,0)) AS PED_SIN_FACT,
    CAST(SUM(CASE WHEN CLASE='OTROS'        THEN CANT_PEDID END) AS DECIMAL(18,0)) AS PED_OTROS,

    -- % del día/mes que el KPI realmente mide
    CAST(100.0 * SUM(CASE WHEN CLASE='KPI' THEN CANT_PEDID END)
         / NULLIF(SUM(CANT_PEDID),0) AS DECIMAL(5,1))                    AS PCT_COBERTURA,

    -- Lectura 1: la que muestra el tablero hoy
    CAST(100.0 * SUM(CASE WHEN CLASE='KPI' THEN CANT_FACTURADA END)
         / NULLIF(SUM(CASE WHEN CLASE='KPI' THEN CANT_PEDID END),0) AS DECIMAL(5,1)) AS EFI_TABLERO,

    -- Lectura 2: incluyendo distribución inicial
    CAST(100.0 * SUM(CASE WHEN CLASE IN ('KPI','DIST_INICIAL') THEN CANT_FACTURADA END)
         / NULLIF(SUM(CASE WHEN CLASE IN ('KPI','DIST_INICIAL') THEN CANT_PEDID END),0) AS DECIMAL(5,1)) AS EFI_CON_DIST_INI,

    -- Lectura 3: universo crudo, todo incluido
    CAST(100.0 * SUM(CANT_FACTURADA) / NULLIF(SUM(CANT_PEDID),0) AS DECIMAL(5,1))    AS EFI_CRUDA
FROM #U
GROUP BY YEAR(FECHA_PEDI), MONTH(FECHA_PEDI)
ORDER BY ANIO, MES;


-- ── 6.B  Dónde se concentra cada exclusión, por canal ────────────────────
--    Para saber si "dist. inicial" y "sin facturar" son un problema
--    transversal o de un canal puntual (p. ej. preventa mayorista).
WITH PorCanal AS (
    SELECT
        ISNULL(NULLIF(LTRIM(RTRIM(CANAL)),''),'(sin canal)') AS CANAL_N,
        CLASE,
        COUNT(DISTINCT NRO_PEDIDO) AS PEDIDOS,
        SUM(CANT_PEDID)            AS UNID_PEDIDAS,
        SUM(CANT_FACTURADA)        AS UNID_FACTURADAS,
        SUM(IMPORTE_PEDIDO)        AS IMPORTE_PEDIDO
    FROM #U
    GROUP BY ISNULL(NULLIF(LTRIM(RTRIM(CANAL)),''),'(sin canal)'), CLASE
)
SELECT
    CANAL_N                                    AS CANAL,
    CLASE,
    PEDIDOS,
    CAST(UNID_PEDIDAS     AS DECIMAL(18,0))    AS UNID_PEDIDAS,
    CAST(UNID_FACTURADAS  AS DECIMAL(18,0))    AS UNID_FACTURADAS,
    CAST(IMPORTE_PEDIDO   AS DECIMAL(18,0))    AS IMPORTE_PEDIDO,
    CAST(100.0 * UNID_PEDIDAS
         / NULLIF(SUM(UNID_PEDIDAS) OVER (PARTITION BY CANAL_N),0) AS DECIMAL(5,1)) AS PCT_DEL_CANAL
FROM PorCanal
ORDER BY CANAL_N, UNID_PEDIDAS DESC;


-- ── 6.C  Distribución de la cobertura diaria ─────────────────────────────
--    ¿Cuántos días se parecen al 18/09 (cobertura < 10%)?
--    Si la mayoría de los días cae en los tramos bajos, el problema es
--    estructural y no un día raro.
WITH Dia AS (
    SELECT FECHA_PEDI,
           SUM(CANT_PEDID)                                        AS PED_TOT,
           SUM(CASE WHEN CLASE='KPI' THEN CANT_PEDID ELSE 0 END)  AS PED_KPI
    FROM #U
    GROUP BY FECHA_PEDI
)
SELECT
    CASE WHEN 100.0*PED_KPI/NULLIF(PED_TOT,0) <  5 THEN 'a) < 5%'
         WHEN 100.0*PED_KPI/NULLIF(PED_TOT,0) < 10 THEN 'b) 5-10%'
         WHEN 100.0*PED_KPI/NULLIF(PED_TOT,0) < 25 THEN 'c) 10-25%'
         WHEN 100.0*PED_KPI/NULLIF(PED_TOT,0) < 50 THEN 'd) 25-50%'
         WHEN 100.0*PED_KPI/NULLIF(PED_TOT,0) < 75 THEN 'e) 50-75%'
         ELSE                                            'f) >= 75%' END AS TRAMO_COBERTURA,
    COUNT(*)                                       AS CANT_DIAS,
    CAST(SUM(PED_TOT) AS DECIMAL(18,0))            AS UNID_PEDIDAS_EN_TRAMO
FROM Dia
GROUP BY
    CASE WHEN 100.0*PED_KPI/NULLIF(PED_TOT,0) <  5 THEN 'a) < 5%'
         WHEN 100.0*PED_KPI/NULLIF(PED_TOT,0) < 10 THEN 'b) 5-10%'
         WHEN 100.0*PED_KPI/NULLIF(PED_TOT,0) < 25 THEN 'c) 10-25%'
         WHEN 100.0*PED_KPI/NULLIF(PED_TOT,0) < 50 THEN 'd) 25-50%'
         WHEN 100.0*PED_KPI/NULLIF(PED_TOT,0) < 75 THEN 'e) 50-75%'
         ELSE                                            'f) >= 75%' END
ORDER BY TRAMO_COBERTURA;


DROP TABLE #U;
DROP TABLE #E;
GO


-- ============================================================
-- 7. QUÉ HAY ADENTRO DEL BUCKET "SIN FACTURAR"
--    La consulta 6 clasifica SIN_FACTURAR antes de chequear
--    CANCELADO, así que ese bucket puede estar mezclando
--    pedidos anulados con pedidos vivos nunca entregados.
--    Foco: MAYORISTAS, 15.784 unid. / $482M en 12 meses cerrados.
--    Bloque independiente: rearma sus propias temporales.
-- ============================================================

DECLARE @DESDE DATE = '2025-09-01';
DECLARE @HASTA DATE = '2026-08-31';   -- <<< mismos meses cerrados que la 6

SELECT FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL,
       CASE WHEN SUM(CANT_PEDID) = SUM(CANT_PEND) THEN 'SIN FACTURAR'
            WHEN SUM(CANT_PEND)  = 0              THEN 'COMPLETO'
            ELSE 'PARCIAL' END AS ESTADO
INTO #E7
FROM dbo.BI_EFICIENCIA_LOGISTICA
WHERE FECHA_PEDI BETWEEN @DESDE AND @HASTA
GROUP BY FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL;

CREATE NONCLUSTERED INDEX IX_E7 ON #E7 (FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL);


-- ── 7.A  El bucket SIN FACTURAR, abierto por estado y tipo ───────────────
--    Si el grueso es ESTADO_TANGO='CANCELADO' -> ruido administrativo.
--    Si es un estado vivo (pendiente/abierto) -> venta perdida real.
SELECT
    e.CANAL,
    ISNULL(e.ESTADO_TANGO,     '(NULL)')                 AS ESTADO_TANGO,
    ISNULL(e.TIPO_FACTURACION, '(NULL)')                 AS TIPO_FACTURACION,
    COUNT(DISTINCT e.NRO_PEDIDO)                         AS PEDIDOS,
    CAST(SUM(ISNULL(e.CANT_PEDID,0)) AS DECIMAL(18,0))   AS UNID_PEDIDAS,
    CAST(SUM(ISNULL(e.IMPORTE_PEDIDO,0)) AS DECIMAL(18,0)) AS IMPORTE_PEDIDO
FROM dbo.BI_EFICIENCIA_LOGISTICA e
JOIN #E7 est
  ON est.FECHA_PEDI = e.FECHA_PEDI AND est.TALON_PED = e.TALON_PED
 AND est.NRO_PEDIDO = e.NRO_PEDIDO AND est.CANAL     = e.CANAL
WHERE e.FECHA_PEDI BETWEEN @DESDE AND @HASTA
  AND est.ESTADO = 'SIN FACTURAR'
GROUP BY e.CANAL, ISNULL(e.ESTADO_TANGO,'(NULL)'), ISNULL(e.TIPO_FACTURACION,'(NULL)')
ORDER BY e.CANAL, UNID_PEDIDAS DESC;


-- ── 7.B  Antigüedad de los SIN FACTURAR no cancelados ────────────────────
--    Un pedido vivo de hace 6 meses sin una sola unidad facturada
--    no es atraso: es venta perdida que el KPI no está mostrando.
SELECT
    e.CANAL,
    CASE WHEN DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) <  30 THEN 'a) < 30 dias'
         WHEN DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) <  60 THEN 'b) 30-60'
         WHEN DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) <  90 THEN 'c) 60-90'
         WHEN DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) < 180 THEN 'd) 90-180'
         ELSE                                                'e) > 180 dias' END AS ANTIGUEDAD,
    COUNT(DISTINCT e.NRO_PEDIDO)                         AS PEDIDOS,
    CAST(SUM(ISNULL(e.CANT_PEDID,0)) AS DECIMAL(18,0))   AS UNID_PEDIDAS,
    CAST(SUM(ISNULL(e.IMPORTE_PEDIDO,0)) AS DECIMAL(18,0)) AS IMPORTE_PEDIDO
FROM dbo.BI_EFICIENCIA_LOGISTICA e
JOIN #E7 est
  ON est.FECHA_PEDI = e.FECHA_PEDI AND est.TALON_PED = e.TALON_PED
 AND est.NRO_PEDIDO = e.NRO_PEDIDO AND est.CANAL     = e.CANAL
WHERE e.FECHA_PEDI BETWEEN @DESDE AND @HASTA
  AND est.ESTADO = 'SIN FACTURAR'
  AND ISNULL(e.ESTADO_TANGO,'') <> 'CANCELADO'
GROUP BY e.CANAL,
    CASE WHEN DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) <  30 THEN 'a) < 30 dias'
         WHEN DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) <  60 THEN 'b) 30-60'
         WHEN DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) <  90 THEN 'c) 60-90'
         WHEN DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) < 180 THEN 'd) 90-180'
         ELSE                                                'e) > 180 dias' END
ORDER BY e.CANAL, ANTIGUEDAD;


-- ── 7.C  Líneas inconsistentes: CANT_FACTURADA <> PEDID - PEND ───────────
--    El 18/09 cerraba perfecto, pero en 12 meses aparecen desvíos
--    (franquicias SIN_FACTURAR con 126 facturadas, mayoristas
--    dist. inicial con 3.945 facturadas sobre 3.070 pedidas).
SELECT
    e.CANAL,
    ISNULL(e.TIPO_FACTURACION,'(NULL)')                                  AS TIPO_FACTURACION,
    COUNT(*)                                                             AS LINEAS,
    CAST(SUM(ISNULL(e.CANT_FACTURADA,0)
             - (ISNULL(e.CANT_PEDID,0) - ISNULL(e.CANT_PEND,0)))
         AS DECIMAL(18,0))                                               AS DESVIO_NETO_UNID,
    MIN(e.FECHA_PEDI)                                                    AS DESDE,
    MAX(e.FECHA_PEDI)                                                    AS HASTA
FROM dbo.BI_EFICIENCIA_LOGISTICA e
WHERE e.FECHA_PEDI BETWEEN @DESDE AND @HASTA
  AND ISNULL(e.CANT_FACTURADA,0) <> ISNULL(e.CANT_PEDID,0) - ISNULL(e.CANT_PEND,0)
GROUP BY e.CANAL, ISNULL(e.TIPO_FACTURACION,'(NULL)')
ORDER BY ABS(SUM(ISNULL(e.CANT_FACTURADA,0)
                 - (ISNULL(e.CANT_PEDID,0) - ISNULL(e.CANT_PEND,0)))) DESC;


DROP TABLE #E7;
GO


-- ============================================================
-- 8. DETALLE DE LOS PEDIDOS ESTANCADOS
--    Los 575 pedidos / 11.194 unid. / $254.938.628 del informe:
--    sin anular, sin UNA sola unidad facturada, +180 dias.
--
--    Con los valores por defecto reproduce EXACTO ese total.
--    Para el panel operativo real, poner @HASTA = hoy y correr
--    @DESDE mas atras (ver nota al pie del bloque).
-- ============================================================

DECLARE @DESDE DATE = '2025-09-01';   -- piso del universo analizado
DECLARE @HASTA DATE = '2026-08-31';   -- fecha de corte / referencia de antiguedad
DECLARE @DIAS  INT  = 180;            -- antiguedad minima

SELECT FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL,
       CASE WHEN SUM(CANT_PEDID) = SUM(CANT_PEND) THEN 'SIN FACTURAR'
            WHEN SUM(CANT_PEND)  = 0              THEN 'COMPLETO'
            ELSE 'PARCIAL' END AS ESTADO
INTO #E8
FROM dbo.BI_EFICIENCIA_LOGISTICA
WHERE FECHA_PEDI BETWEEN @DESDE AND @HASTA
GROUP BY FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL;

CREATE NONCLUSTERED INDEX IX_E8 ON #E8 (FECHA_PEDI, TALON_PED, NRO_PEDIDO, CANAL);


-- ── 8.A  Listado pedido por pedido (esto es lo que se exporta) ───────────
SELECT
    e.CANAL,
    LTRIM(RTRIM(e.CLIENTE))                                AS CLIENTE,
    LTRIM(RTRIM(e.NRO_PEDIDO))                             AS NRO_PEDIDO,
    e.TALON_PED,
    e.FECHA_PEDI,
    DATEDIFF(DAY, e.FECHA_PEDI, @HASTA)                    AS DIAS_ANTIGUEDAD,
    MAX(ISNULL(e.ESTADO_TANGO,'(NULL)'))                   AS ESTADO_TANGO,
    MAX(ISNULL(e.TIPO_FACTURACION,'(NULL)'))               AS TIPO_FACTURACION,
    COUNT(*)                                               AS LINEAS,
    CAST(SUM(ISNULL(e.CANT_PEDID,0))     AS DECIMAL(18,0)) AS UNID_PEDIDAS,
    CAST(SUM(ISNULL(e.CANT_PEND,0))      AS DECIMAL(18,0)) AS UNID_PENDIENTES,
    CAST(SUM(ISNULL(e.IMPORTE_PEDIDO,0)) AS DECIMAL(18,2)) AS IMPORTE_PEDIDO
FROM dbo.BI_EFICIENCIA_LOGISTICA e
JOIN #E8 est
  ON est.FECHA_PEDI = e.FECHA_PEDI AND est.TALON_PED = e.TALON_PED
 AND est.NRO_PEDIDO = e.NRO_PEDIDO AND est.CANAL     = e.CANAL
WHERE e.FECHA_PEDI BETWEEN @DESDE AND @HASTA
  AND est.ESTADO = 'SIN FACTURAR'
  AND ISNULL(e.ESTADO_TANGO,'') <> 'CANCELADO'
  AND DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) >= @DIAS
GROUP BY e.CANAL, LTRIM(RTRIM(e.CLIENTE)), LTRIM(RTRIM(e.NRO_PEDIDO)),
         e.TALON_PED, e.FECHA_PEDI
ORDER BY IMPORTE_PEDIDO DESC;


-- ── 8.B  Control: tiene que dar igual a la tabla del informe ─────────────
--    575 pedidos / 11.194 unid / $254.938.628
SELECT
    e.CANAL,
    COUNT(DISTINCT e.NRO_PEDIDO)                           AS PEDIDOS,
    CAST(SUM(ISNULL(e.CANT_PEDID,0))     AS DECIMAL(18,0)) AS UNIDADES,
    CAST(SUM(ISNULL(e.IMPORTE_PEDIDO,0)) AS DECIMAL(18,0)) AS IMPORTE
FROM dbo.BI_EFICIENCIA_LOGISTICA e
JOIN #E8 est
  ON est.FECHA_PEDI = e.FECHA_PEDI AND est.TALON_PED = e.TALON_PED
 AND est.NRO_PEDIDO = e.NRO_PEDIDO AND est.CANAL     = e.CANAL
WHERE e.FECHA_PEDI BETWEEN @DESDE AND @HASTA
  AND est.ESTADO = 'SIN FACTURAR'
  AND ISNULL(e.ESTADO_TANGO,'') <> 'CANCELADO'
  AND DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) >= @DIAS
GROUP BY e.CANAL
ORDER BY IMPORTE DESC;


-- ── 8.C  Concentracion por cliente — por donde empezar a depurar ─────────
SELECT TOP 30
    e.CANAL,
    LTRIM(RTRIM(e.CLIENTE))                                AS CLIENTE,
    COUNT(DISTINCT e.NRO_PEDIDO)                           AS PEDIDOS,
    CAST(SUM(ISNULL(e.CANT_PEDID,0))     AS DECIMAL(18,0)) AS UNIDADES,
    CAST(SUM(ISNULL(e.IMPORTE_PEDIDO,0)) AS DECIMAL(18,0)) AS IMPORTE,
    MIN(e.FECHA_PEDI)                                      AS PEDIDO_MAS_VIEJO,
    MAX(e.FECHA_PEDI)                                      AS PEDIDO_MAS_NUEVO
FROM dbo.BI_EFICIENCIA_LOGISTICA e
JOIN #E8 est
  ON est.FECHA_PEDI = e.FECHA_PEDI AND est.TALON_PED = e.TALON_PED
 AND est.NRO_PEDIDO = e.NRO_PEDIDO AND est.CANAL     = e.CANAL
WHERE e.FECHA_PEDI BETWEEN @DESDE AND @HASTA
  AND est.ESTADO = 'SIN FACTURAR'
  AND ISNULL(e.ESTADO_TANGO,'') <> 'CANCELADO'
  AND DATEDIFF(DAY, e.FECHA_PEDI, @HASTA) >= @DIAS
GROUP BY e.CANAL, LTRIM(RTRIM(e.CLIENTE))
ORDER BY IMPORTE DESC;


DROP TABLE #E8;
GO

-- NOTA: para el panel operativo (no para reproducir el informe), cambiar:
--   @DESDE = '2024-01-01'          -- o mas atras, para no perder pedidos viejos
--   @HASTA = CAST(GETDATE() AS DATE)
-- El universo va a ser mayor: el informe usaba una ventana de 12 meses
-- cerrados y deja afuera todo lo anterior a septiembre 2025.
