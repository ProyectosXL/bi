-- ═══════════════════════════════════════════════════════════════════════
-- Diagnóstico de franquicias renumeradas (cambio de razón social).
-- Correr en: XL-APPS / POWER_BI_CONTROL_FRANQUICIAS (conexión 'power_franquicias').
-- Todo de lectura: no modifica nada.
--
-- CONTEXTO (2026-09): 5 locales del mismo grupo quedaron con TRES códigos cada
-- uno — el viejo (HABILITADO=0, con todo el histórico) y dos nuevos legítimos en
-- relación madre/hija vía SUCURSALES_LAKERS.NRO_SUC_MADRE:
--
--     local                    viejo   madre (02/09)   hija (09/09)
--     DEVOTO                    890        956             690
--     VILLA DEL PARQUE SHOP      ?         957             731
--     VILLA URQUIZA              ?         958             718
--     MARTINEZ                   ?         959             674
--     RAMOS 2                    ?         960             620
--
-- El tablero los ve como locales distintos: el histórico se le escapa por el
-- filtro HABILITADO=1 y los nuevos se le duplican porque global/ ignora
-- NRO_SUC_MADRE. Ver el plan del arreglo y setup_franquicias_canon.sql.
-- ═══════════════════════════════════════════════════════════════════════

USE [POWER_BI_CONTROL_FRANQUICIAS];
SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;
GO

-- ── 1) Mapa completo en el maestro ─────────────────────────────────────
-- Descubre los códigos VIEJOS de los 5 locales cruzando por DESC_SUCURSAL y por
-- cod_client (fue cod_client lo que emparejó 890 con 690 en DEVOTO).
-- Esperable: 3 filas por local. Si alguno trae más o menos, revisar antes de
-- sembrar la tabla de equivalencias.
WITH nuevos AS (
    SELECT NRO_SUCURSAL, DESC_SUCURSAL, cod_client
    FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS
    WHERE NRO_SUCURSAL IN (956,957,958,959,960, 690,731,718,674,620)
)
SELECT sl.NRO_SUCURSAL, sl.DESC_SUCURSAL, sl.cod_client, sl.CANAL, sl.TIPO_LOCAL,
       sl.TANGO, sl.HABILITADO, sl.NRO_SUC_MADRE
FROM [XL-LAKERBIS].LOCALES_LAKERS.DBO.SUCURSALES_LAKERS sl
WHERE EXISTS (
        SELECT 1 FROM nuevos n
        WHERE n.DESC_SUCURSAL = sl.DESC_SUCURSAL
           OR n.cod_client    = sl.cod_client)
ORDER BY sl.DESC_SUCURSAL, sl.NRO_SUCURSAL;
GO

-- ── 2) Dónde está la facturación ───────────────────────────────────────
-- Dice cuál de los códigos recibe la venta y con qué GRUPO_EMPRESARIO / ZONA
-- quedó cada uno. Si la query 1 revela códigos viejos además del 890, agregarlos
-- al IN y volver a correr.
SELECT NRO_SUCURS, SUCURSAL, GRUPO_EMPRESARIO, ZONA,
       YEAR(FECHA) AS anio, MONTH(FECHA) AS mes,
       COUNT(*) AS filas, SUM(IMPORTE) AS importe, SUM(CANTIDAD) AS unidades
FROM dbo.BI_SALES_SUCURSALES
WHERE NRO_SUCURS IN (890,956,690, 957,731, 958,718, 959,674, 960,620)
GROUP BY NRO_SUCURS, SUCURSAL, GRUPO_EMPRESARIO, ZONA, YEAR(FECHA), MONTH(FECHA)
ORDER BY NRO_SUCURS, anio, mes;
GO

-- ── 3) Objetivos: a qué código quedaron atados ─────────────────────────
-- Ruta franquicias (por idPOS). El objetivo de septiembre cuelga del POS nuevo
-- mientras la venta histórica cuelga del viejo → cumplimiento descolocado.
SELECT pv.idTango AS NRO_SUCURS, o.anio, o.mes, SUM(o.importeObjetivo) AS objetivo
FROM sistemas.dbo.FP_ObjetivosFinales o WITH (NOLOCK)
JOIN sistemas.dbo.PuntosDeVenta pv WITH (NOLOCK) ON pv.id = o.idPOS
WHERE pv.idTango IN (890,956,690, 957,731, 958,718, 959,674, 960,620)
GROUP BY pv.idTango, o.anio, o.mes
ORDER BY pv.idTango, o.anio, o.mes;

-- Tabla de objetivos del tablero.
SELECT o.NRO_SUCURSAL, YEAR(o.FECHA) AS anio, MONTH(o.FECHA) AS mes,
       SUM(o.IMPORTE_OBJ) AS objetivo
FROM dbo.BI_OBJETIVOS_FRANQUICIAS o WITH (NOLOCK)
WHERE o.NRO_SUCURSAL IN (890,956,690, 957,731, 958,718, 959,674, 960,620)
GROUP BY o.NRO_SUCURSAL, YEAR(o.FECHA), MONTH(o.FECHA)
ORDER BY o.NRO_SUCURSAL, anio, mes;
GO

-- ── 4) Línea base — guardar ANTES de crear las vistas ──────────────────
-- Control de suma cero: después de crear VW_BI_SALES_SUCURSALES_CANON el mismo
-- total tiene que dar IDÉNTICO. La vista reetiqueta, no filtra ni duplica.
SELECT YEAR(FECHA) AS anio, MONTH(FECHA) AS mes,
       SUM(IMPORTE) AS importe_total, COUNT(DISTINCT NRO_SUCURS) AS locales
FROM dbo.BI_SALES_SUCURSALES
WHERE FECHA >= '2025-01-01'
GROUP BY YEAR(FECHA), MONTH(FECHA)
ORDER BY anio, mes;
GO

-- ── 5) Tickets (afecta ticket promedio, se canonicaliza igual) ─────────
SELECT NRO_SUCURS, YEAR(FECHA) AS anio, MONTH(FECHA) AS mes,
       COUNT(DISTINCT N_COMP) AS tickets, SUM(IMP_TOTAL_TICKET) AS importe
FROM dbo.BI_SALES_TOTAL_TICKETS
WHERE T_COMP = 'FAC'
  AND NRO_SUCURS IN (890,956,690, 957,731, 958,718, 959,674, 960,620)
GROUP BY NRO_SUCURS, YEAR(FECHA), MONTH(FECHA)
ORDER BY NRO_SUCURS, anio, mes;
GO

-- ── 7) LA CAÍDA DE AGOSTO 2026 ─────────────────────────────────────────
-- Verificado: los 5 locales pierden 80-87 % de facturación desde agosto 2026 y
-- esa venta NO aparece bajo ningún otro código. Los tickets caen igual (956:
-- 785 en julio → 121 en agosto), así que faltan transacciones, no importes.
-- Estas 3 queries sirven para ubicar dónde se cortó la carga.

-- 7.1) Día por día: ¿qué fecha exacta dejó de cargar?
SELECT NRO_SUCURS, CAST(FECHA AS DATE) AS dia,
       COUNT(*) AS filas, SUM(IMPORTE) AS importe
FROM dbo.BI_SALES_SUCURSALES WITH (NOLOCK)
WHERE NRO_SUCURS IN (956,957,958,959,960)
  AND FECHA >= '2026-07-01'
GROUP BY NRO_SUCURS, CAST(FECHA AS DATE)
ORDER BY NRO_SUCURS, dia;

-- 7.2) ¿Aparecieron códigos nuevos que se estén llevando esa venta?
-- Lista las sucursales con venta en ago/sep que NO tenían en jun/jul.
SELECT NRO_SUCURS, SUCURSAL, GRUPO_EMPRESARIO,
       MIN(FECHA) AS primera, SUM(IMPORTE) AS importe
FROM dbo.BI_SALES_SUCURSALES WITH (NOLOCK)
WHERE FECHA >= '2026-08-01'
  AND NRO_SUCURS NOT IN (
        SELECT DISTINCT NRO_SUCURS FROM dbo.BI_SALES_SUCURSALES WITH (NOLOCK)
        WHERE FECHA >= '2026-06-01' AND FECHA < '2026-08-01')
GROUP BY NRO_SUCURS, SUCURSAL, GRUPO_EMPRESARIO
ORDER BY importe DESC;

-- 7.3) ¿La venta existe en la OTRA tabla de hechos (la que usa sales/)?
-- Si acá sí está y en BI_SALES_SUCURSALES no, el corte es del proceso que
-- alimenta la base de franquicias. Si tampoco está, el corte viene de Tango.
SELECT CLIENTE, CANAL, YEAR(FECHA) AS anio, MONTH(FECHA) AS mes,
       COUNT(*) AS filas, SUM(IMPORTE) AS importe
FROM POWER_BI_CONTROL.dbo.BI_SALES_LAKERS WITH (NOLOCK)
WHERE FECHA >= '2026-06-01'
  AND (CLIENTE LIKE '%DEVOTO%'    OR CLIENTE LIKE '%URQUIZA%'
    OR CLIENTE LIKE '%MARTINEZ%'  OR CLIENTE LIKE '%RAMOS%'
    OR CLIENTE LIKE '%PARQUE%'    OR CLIENTE LIKE '%RIPOLL%')
GROUP BY CLIENTE, CANAL, YEAR(FECHA), MONTH(FECHA)
ORDER BY CLIENTE, anio, mes;
GO

-- ── 8) ¿DÓNDE CAYÓ LA VENTA DE AGOSTO? ─────────────────────────────────
-- El corte es 03/08 → 01/09 (MARTINEZ vuelve el 12/08). La 7.2 solo detecta
-- códigos NUEVOS: se le escapa que la venta haya ido a una sucursal que ya
-- existía, o a la base de locales propios. Estas tres la buscan sin suponer
-- ningún número.

-- 8.1) Búsqueda POR NOMBRE en las DOS bases, sin asumir código.
-- Si el número de sucursal cambió a algo que no conocemos, acá aparece.
-- Las dos bases tienen collation distinta (Modern_Spanish_CI_AS vs CI_AI), por
-- eso SUCURSAL va con COLLATE DATABASE_DEFAULT: sin eso el UNION ALL falla con
-- "collation conflict" (mismo motivo por el que GlobalDashboardDB lo usa en el
-- UNION de fromVentasSucursales()).
SELECT 'FRANQUICIAS' AS base, NRO_SUCURS,
       SUCURSAL COLLATE DATABASE_DEFAULT AS SUCURSAL,
       YEAR(FECHA) AS anio, MONTH(FECHA) AS mes,
       COUNT(*) AS filas, SUM(IMPORTE) AS importe
FROM POWER_BI_CONTROL_FRANQUICIAS.dbo.BI_SALES_SUCURSALES WITH (NOLOCK)
WHERE FECHA >= '2026-07-01'
  AND (SUCURSAL LIKE '%DEVOTO%'  OR SUCURSAL LIKE '%URQUIZA%'
    OR SUCURSAL LIKE '%MARTINEZ%' OR SUCURSAL LIKE '%RAMOS%'
    OR SUCURSAL LIKE '%PARQUE%'   OR SUCURSAL LIKE '%ZINARKA%')
GROUP BY NRO_SUCURS, SUCURSAL, YEAR(FECHA), MONTH(FECHA)
UNION ALL
SELECT 'ARGENTINA', NRO_SUCURS,
       SUCURSAL COLLATE DATABASE_DEFAULT,
       YEAR(FECHA), MONTH(FECHA), COUNT(*), SUM(IMPORTE)
FROM POWER_BI_CONTROL.dbo.BI_SALES_SUCURSALES WITH (NOLOCK)
WHERE FECHA >= '2026-07-01'
  AND (SUCURSAL LIKE '%DEVOTO%'  OR SUCURSAL LIKE '%URQUIZA%'
    OR SUCURSAL LIKE '%MARTINEZ%' OR SUCURSAL LIKE '%RAMOS%'
    OR SUCURSAL LIKE '%PARQUE%'   OR SUCURSAL LIKE '%ZINARKA%')
GROUP BY NRO_SUCURS, SUCURSAL, YEAR(FECHA), MONTH(FECHA)
ORDER BY SUCURSAL, base, anio, mes;

-- 8.2) ¿Los códigos conocidos cargaron en la base de LOCALES PROPIOS?
-- (por si el cambio de número los reclasificó de canal)
SELECT NRO_SUCURS, SUCURSAL, YEAR(FECHA) AS anio, MONTH(FECHA) AS mes,
       COUNT(*) AS filas, SUM(IMPORTE) AS importe
FROM POWER_BI_CONTROL.dbo.BI_SALES_SUCURSALES WITH (NOLOCK)
WHERE FECHA >= '2026-06-01'
  AND NRO_SUCURS IN (890,956,690, 957,731, 958,718, 959,674, 960,620,
                     874,920,931,918)
GROUP BY NRO_SUCURS, SUCURSAL, YEAR(FECHA), MONTH(FECHA)
ORDER BY NRO_SUCURS, anio, mes;

-- 8.3) ¿Alguna sucursal YA EXISTENTE absorbió esa venta en agosto?
-- Lista las que saltaron anormalmente de julio a agosto.
WITH m AS (
    SELECT NRO_SUCURS, SUCURSAL,
           SUM(CASE WHEN FECHA >= '2026-07-01' AND FECHA < '2026-08-01'
                    THEN IMPORTE ELSE 0 END) AS jul,
           SUM(CASE WHEN FECHA >= '2026-08-01' AND FECHA < '2026-09-01'
                    THEN IMPORTE ELSE 0 END) AS ago
    FROM dbo.BI_SALES_SUCURSALES WITH (NOLOCK)
    WHERE FECHA >= '2026-07-01' AND FECHA < '2026-09-01'
    GROUP BY NRO_SUCURS, SUCURSAL
)
SELECT NRO_SUCURS, SUCURSAL, jul, ago, ago - jul AS delta
FROM m
WHERE ago > jul * 1.4
ORDER BY delta DESC;
GO

-- ── 9) BARRIDO: ¿agosto está en ALGUNA tabla, bajo CUALQUIER código? ───
-- Descartado que la venta esté en BI_SALES_SUCURSALES (viejos y nuevos) ni en
-- la base de propios. Falta descartar el resto de la base: staging, las tablas
-- _BK que referencia GlobalDashboardDB, o cualquier otra.
-- Recorre toda tabla que tenga una columna NRO_SUCURS/NRO_SUCURSAL y una FECHA,
-- y cuenta las filas de agosto 2026 para los 15 códigos de los 5 locales.
-- Correr una vez en POWER_BI_CONTROL_FRANQUICIAS y otra en POWER_BI_CONTROL.

DECLARE @sql NVARCHAR(MAX) =
    N'SELECT CAST(NULL AS SYSNAME) AS tabla, CAST(NULL AS INT) AS nro_sucurs,
             CAST(NULL AS INT) AS filas WHERE 1 = 0';

SELECT @sql = @sql + N'
UNION ALL SELECT ''' + t.TABLE_NAME + N''', ' + QUOTENAME(k.COLUMN_NAME) + N', COUNT(*)
FROM dbo.' + QUOTENAME(t.TABLE_NAME) + N' WITH (NOLOCK)
WHERE ' + QUOTENAME(f.COLUMN_NAME) + N' >= ''2026-08-01''
  AND ' + QUOTENAME(f.COLUMN_NAME) + N' <  ''2026-09-01''
  AND ' + QUOTENAME(k.COLUMN_NAME) + N' IN (890,874,920,931,918,
                                            956,957,958,959,960,
                                            690,674,620,731,718)
GROUP BY ' + QUOTENAME(k.COLUMN_NAME)
FROM INFORMATION_SCHEMA.TABLES t
JOIN INFORMATION_SCHEMA.COLUMNS k
  ON k.TABLE_SCHEMA = t.TABLE_SCHEMA AND k.TABLE_NAME = t.TABLE_NAME
 AND k.COLUMN_NAME IN ('NRO_SUCURS', 'NRO_SUCURSAL')
JOIN INFORMATION_SCHEMA.COLUMNS f
  ON f.TABLE_SCHEMA = t.TABLE_SCHEMA AND f.TABLE_NAME = t.TABLE_NAME
 AND f.COLUMN_NAME = 'FECHA'
WHERE t.TABLE_TYPE = 'BASE TABLE' AND t.TABLE_SCHEMA = 'dbo';

EXEC sp_executesql @sql;
GO

-- 9.b) Si el barrido no encuentra nada: comparar agosto contra el MISMO
-- período en la tabla de tickets, para confirmar que faltan comprobantes y no
-- solo renglones de artículo.
SELECT NRO_SUCURS,
       SUM(CASE WHEN FECHA >= '2026-07-01' AND FECHA < '2026-08-01' THEN 1 ELSE 0 END) AS renglones_jul,
       SUM(CASE WHEN FECHA >= '2026-08-01' AND FECHA < '2026-09-01' THEN 1 ELSE 0 END) AS renglones_ago,
       COUNT(DISTINCT CASE WHEN FECHA >= '2026-07-01' AND FECHA < '2026-08-01' THEN N_COMP END) AS comprob_jul,
       COUNT(DISTINCT CASE WHEN FECHA >= '2026-08-01' AND FECHA < '2026-09-01' THEN N_COMP END) AS comprob_ago
FROM dbo.BI_SALES_TOTAL_TICKETS WITH (NOLOCK)
WHERE T_COMP = 'FAC' AND FECHA >= '2026-07-01' AND FECHA < '2026-09-01'
  AND NRO_SUCURS IN (890,874,920,931,918, 956,957,958,959,960, 690,674,620,731,718)
GROUP BY NRO_SUCURS
ORDER BY NRO_SUCURS;
GO

-- ── 6) Otros locales con más de un punto de venta ──────────────────────
-- La renumeración es recurrente (4 BERAZATEGUI 875→948, MDP GUEMES 56→909,
-- 4 CASEROS →939). Sirve para decidir si se sanea también ese histórico.
-- La normalización solo saca el prefijo '1 ' — puede juntar de más en nombres
-- que contengan '1 ' en otro lugar: revisar la salida antes de usarla.
WITH pv AS (
    SELECT id, numero, idTango, nombre, fechaCreacion, activo,
           LTRIM(RTRIM(REPLACE(UPPER(nombre), '1 ', ''))) AS nombre_norm
    FROM sistemas.dbo.PuntosDeVenta WITH (NOLOCK)
),
dup AS (
    SELECT nombre_norm FROM pv GROUP BY nombre_norm HAVING COUNT(*) > 1
)
SELECT p.nombre_norm, p.nombre, p.idTango, p.numero, p.activo, p.fechaCreacion
FROM pv p
JOIN dup d ON d.nombre_norm = p.nombre_norm
ORDER BY p.nombre_norm, p.fechaCreacion;
GO
