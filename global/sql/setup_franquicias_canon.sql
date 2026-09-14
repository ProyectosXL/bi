-- ═══════════════════════════════════════════════════════════════════════
-- Capa de canonicalización de sucursales de franquicias.
-- Correr TODO este script en: POWER_BI_CONTROL_FRANQUICIAS (host_apps,
-- conexión 'power_franquicias' de bi/class/Conexion.php).
--
-- Es idempotente: se puede correr más de una vez sin duplicar nada.
--
-- PROBLEMA QUE RESUELVE
--   Un mismo local termina representado por varios NRO_SUCURSAL y el tablero los
--   trata como locales distintos. Dos casos verificados (2026-09):
--     a) VENTA Y OBJETIVO EN CÓDIGOS DISTINTOS. Para los 5 locales del grupo
--        Ripoll la venta se carga en 956-960 mientras el objetivo se cargaba en
--        890/874/920/931/918 (HABILITADO=0). Resultado: venta con objetivo 0 y
--        objetivo sin venta durante todo 2026 hasta agosto.
--     b) MADRE E HIJA DUPLICADAS. Las altas 690/674/620/731/718 apuntan por
--        NRO_SUC_MADRE a 956-960, pero global/ ignora NRO_SUC_MADRE (0
--        referencias) mientras promociones/ sí lo respeta (8 lugares).
--
--   NO resuelve la caída de facturación de agosto 2026 en esos locales: esa
--   venta no está en BI_SALES_SUCURSALES bajo ningún código — es un problema del
--   proceso de carga, aguas arriba del tablero. Ver diag_franquicias.sql §7.
--
-- REGLA CANÓNICA (en este orden de prioridad)
--   NRO_SUCURS_CANON = COALESCE(alias.NRO_SUCURS_CANON,  -- equivalencia a mano
--                               sl.NRO_SUC_MADRE,         -- jerarquía del maestro
--                               sl.NRO_SUCURSAL)          -- el propio número
--
--   Ejemplo DEVOTO:  890 → 956 (alias)   690 → 956 (madre)   956 → 956
--   El canónico (956) está HABILITADO=1, así que las filas del 890 pasan el
--   filtro de habilitados sin tocar ninguno de los 13 `HABILITADO = 1` del PHP.
--
-- MODELO
--   BI_DIM_SUCURSALES_ALIAS  → equivalencias declaradas a mano (lo que el
--                              maestro NO sabe: código viejo → código vigente).
--   BI_DIM_SUCURSALES_CANON  → tabla materializada con el mapeo ya resuelto.
--                              La reconstruye RO_SP_SYNC_SUCURSALES_CANON.
--   VW_*_CANON               → vistas que reetiquetan NRO_SUCURS. Es lo único
--                              que consume el PHP.
--
-- Se materializa (en vez de resolver en vivo contra [XL-LAKERBIS]) porque el
-- código evita cruzar el servidor vinculado por query — de ahí #pv_st/#sl_st en
-- GlobalDashboardDB. Mismo patrón que logistica/sql/uy/04_sync_stock_uy.sql.
-- ═══════════════════════════════════════════════════════════════════════

USE [POWER_BI_CONTROL_FRANQUICIAS];
GO

-- ── 1) Equivalencias declaradas a mano ─────────────────────────────────
IF OBJECT_ID('dbo.BI_DIM_SUCURSALES_ALIAS','U') IS NULL
BEGIN
    CREATE TABLE dbo.BI_DIM_SUCURSALES_ALIAS (
        NRO_SUCURS_ALIAS INT          NOT NULL,  -- código viejo
        NRO_SUCURS_CANON INT          NOT NULL,  -- código vigente
        CLIENTE_ALIAS    VARCHAR(200) NULL,      -- razón social vieja (para sales/)
        CLIENTE_CANON    VARCHAR(200) NULL,      -- razón social vigente
        GRUPO_EMPRESARIO VARCHAR(100) NULL,      -- override explícito del grupo
        MOTIVO           VARCHAR(200) NULL,
        VIGENCIA_DESDE   DATE         NULL,
        FECHA_ALTA       DATETIME     NOT NULL DEFAULT GETDATE(),
        CONSTRAINT PK_BI_DIM_SUCURSALES_ALIAS PRIMARY KEY (NRO_SUCURS_ALIAS)
    );
    CREATE INDEX IX_BI_DIM_SUCURSALES_ALIAS_CANON
        ON dbo.BI_DIM_SUCURSALES_ALIAS (NRO_SUCURS_CANON);
    CREATE INDEX IX_BI_DIM_SUCURSALES_ALIAS_CLIENTE
        ON dbo.BI_DIM_SUCURSALES_ALIAS (CLIENTE_ALIAS);
    PRINT 'Tabla BI_DIM_SUCURSALES_ALIAS creada.';
END
ELSE
    PRINT 'Tabla BI_DIM_SUCURSALES_ALIAS ya existe.';
GO

-- ── 1.b) Seed: grupo Ripoll ────────────────────────────────────────────
-- Confirmado contra SUCURSALES_LAKERS y BI_SALES_SUCURSALES (2026-09-11).
-- El canónico es el código donde está la VENTA (956-960); el alias es el código
-- HABILITADO=0 donde estaban cargados los OBJETIVOS hasta agosto 2026.
-- Las hijas (690/674/620/731/718) las resuelve sola NRO_SUC_MADRE: no van acá.
--
-- ┌─────────────────────────────────────────────────────────────────────┐
-- │ NO CORRER ESTE BLOQUE TODAVÍA — decisión pendiente.                 │
-- │                                                                     │
-- │ Para 2026-09 hay objetivo cargado en LOS DOS códigos de DEVOTO:     │
-- │     890 → 86.000.000  (cargado a mano, parece el real)             │
-- │     956 → 64.676.165  (= venta de 956 en 2025-09 al centavo:        │
-- │                        placeholder autogenerado; ídem 957-960)      │
-- │ Mapear 890 → 956 sin acotar por fecha los SUMA: 150,7 M en vez de   │
-- │ 86 M. Hay que decidir cuál objetivo vale y, si el mapeo tiene que   │
-- │ cortar en 2026-08, agregar VIGENCIA_HASTA y hacer la vista          │
-- │ sensible a la fecha (hoy BI_DIM_SUCURSALES_CANON es una fila por    │
-- │ NRO_SUCURS, sin vigencia).                                          │
-- │                                                                     │
-- │ Nota: de los 5 pares, SOLO 890→956 cambia números. 874, 920, 931 y  │
-- │ 918 no tienen ni venta ni objetivos en ninguna tabla — mapearlos es │
-- │ solo higiene del desplegable.                                       │
-- └─────────────────────────────────────────────────────────────────────┘
MERGE dbo.BI_DIM_SUCURSALES_ALIAS AS d
USING (VALUES
        (890, 956, 'DEVOTO'),
        (874, 959, 'MARTINEZ'),
        (920, 960, 'RAMOS 2'),
        (931, 957, 'VILLA DEL PARQUE SHOP'),
        (918, 958, 'VILLA URQUIZA')
      ) AS s (alias, canon, local)
   ON d.NRO_SUCURS_ALIAS = s.alias
WHEN NOT MATCHED THEN
    INSERT (NRO_SUCURS_ALIAS, NRO_SUCURS_CANON, MOTIVO, VIGENCIA_DESDE)
    VALUES (s.alias, s.canon,
            'Grupo Ripoll: objetivos en codigo viejo, venta en canonico (' + s.local + ')',
            '2024-01-01');
GO

-- Renumeraciones anteriores (sanear en una segunda etapa, validando totales aparte):
--     875 → 948  (4 BERAZATEGUI, 2025)
--      56 → 909  (MDP GUEMES, 2018)
--       ? → 939  (4 CASEROS, 2024)
GO

-- ── 2) Tabla canónica materializada ────────────────────────────────────
IF OBJECT_ID('dbo.BI_DIM_SUCURSALES_CANON','U') IS NULL
BEGIN
    CREATE TABLE dbo.BI_DIM_SUCURSALES_CANON (
        NRO_SUCURS       INT           NOT NULL,
        NRO_SUCURS_CANON INT           NOT NULL,
        DESC_SUCURSAL    NVARCHAR(200) NULL,
        HABILITADO       BIT           NULL,
        ORIGEN           VARCHAR(10)   NOT NULL,  -- ALIAS | MADRE | PROPIO
        FECHA_SYNC       DATETIME      NOT NULL DEFAULT GETDATE(),
        CONSTRAINT PK_BI_DIM_SUCURSALES_CANON PRIMARY KEY (NRO_SUCURS)
    );
    CREATE INDEX IX_BI_DIM_SUCURSALES_CANON_CANON
        ON dbo.BI_DIM_SUCURSALES_CANON (NRO_SUCURS_CANON);
    PRINT 'Tabla BI_DIM_SUCURSALES_CANON creada.';
END
ELSE
    PRINT 'Tabla BI_DIM_SUCURSALES_CANON ya existe.';
GO

-- ── 3) SP de sincronización ────────────────────────────────────────────
-- Reconstruye BI_DIM_SUCURSALES_CANON desde el maestro + la tabla de alias.
-- HAY QUE CORRERLO cada vez que se da de alta o se renumera un local, o el
-- tablero vuelve a mostrar el código crudo. Agendar como SQL Agent Job diario.
IF OBJECT_ID('dbo.RO_SP_SYNC_SUCURSALES_CANON','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_SYNC_SUCURSALES_CANON;
GO

CREATE PROCEDURE dbo.RO_SP_SYNC_SUCURSALES_CANON
AS
BEGIN
    SET NOCOUNT ON;

    BEGIN TRY
        -- 3.1) Maestro al tempdb de una sola pasada por el servidor vinculado
        IF OBJECT_ID('tempdb..#sl') IS NOT NULL DROP TABLE #sl;
        SELECT NRO_SUCURSAL, DESC_SUCURSAL, HABILITADO, NRO_SUC_MADRE
        INTO #sl
        FROM OPENQUERY([XL-LAKERBIS],
             'SELECT NRO_SUCURSAL, DESC_SUCURSAL, HABILITADO, NRO_SUC_MADRE
              FROM LOCALES_LAKERS.DBO.SUCURSALES_LAKERS');

        CREATE UNIQUE CLUSTERED INDEX IX_sl ON #sl (NRO_SUCURSAL);

        -- 3.2) Universo = maestro ∪ códigos con ventas ∪ alias declarados.
        -- El DISTINCT sobre la tabla de hechos es un scan, pero corre una vez
        -- por sync, no por request.
        IF OBJECT_ID('tempdb..#canon') IS NOT NULL DROP TABLE #canon;
        CREATE TABLE #canon (
            NRO_SUCURS       INT PRIMARY KEY,
            NRO_SUCURS_CANON INT,
            DESC_SUCURSAL    NVARCHAR(200),
            HABILITADO       BIT,
            ORIGEN           VARCHAR(10)
        );

        INSERT INTO #canon (NRO_SUCURS, NRO_SUCURS_CANON, DESC_SUCURSAL, HABILITADO, ORIGEN)
        SELECT u.NRO_SUCURS,
               COALESCE(a.NRO_SUCURS_CANON, sl.NRO_SUC_MADRE, u.NRO_SUCURS),
               sl.DESC_SUCURSAL,
               sl.HABILITADO,
               CASE WHEN a.NRO_SUCURS_CANON IS NOT NULL THEN 'ALIAS'
                    WHEN sl.NRO_SUC_MADRE   IS NOT NULL THEN 'MADRE'
                    ELSE 'PROPIO' END
        FROM (
            SELECT NRO_SUCURSAL AS NRO_SUCURS FROM #sl
            UNION
            SELECT DISTINCT NRO_SUCURS FROM dbo.BI_SALES_SUCURSALES WITH (NOLOCK)
            UNION
            SELECT NRO_SUCURS_ALIAS FROM dbo.BI_DIM_SUCURSALES_ALIAS
        ) u
        LEFT JOIN #sl sl                          ON sl.NRO_SUCURSAL    = u.NRO_SUCURS
        LEFT JOIN dbo.BI_DIM_SUCURSALES_ALIAS a   ON a.NRO_SUCURS_ALIAS = u.NRO_SUCURS;

        -- 3.3) Resolver cadenas (una hija cuya madre a su vez tenga alias).
        -- Tope de 5 vueltas: si sigue cambiando hay un ciclo en los datos.
        DECLARE @i INT = 0, @cambios INT = 1;
        WHILE @cambios > 0 AND @i < 5
        BEGIN
            UPDATE c
               SET c.NRO_SUCURS_CANON = p.NRO_SUCURS_CANON
            FROM #canon c
            JOIN #canon p ON p.NRO_SUCURS = c.NRO_SUCURS_CANON
            WHERE p.NRO_SUCURS <> p.NRO_SUCURS_CANON
              AND c.NRO_SUCURS_CANON <> p.NRO_SUCURS_CANON;

            SET @cambios = @@ROWCOUNT;
            SET @i = @i + 1;
        END

        IF @cambios > 0
            RAISERROR('RO_SP_SYNC_SUCURSALES_CANON: no convergio en 5 pasadas, revisar ciclos en BI_DIM_SUCURSALES_ALIAS.', 16, 1);

        -- 3.4) Swap
        BEGIN TRANSACTION;
            TRUNCATE TABLE dbo.BI_DIM_SUCURSALES_CANON;
            INSERT INTO dbo.BI_DIM_SUCURSALES_CANON
                (NRO_SUCURS, NRO_SUCURS_CANON, DESC_SUCURSAL, HABILITADO, ORIGEN, FECHA_SYNC)
            SELECT NRO_SUCURS, NRO_SUCURS_CANON, DESC_SUCURSAL, HABILITADO, ORIGEN, GETDATE()
            FROM #canon;
        COMMIT TRANSACTION;

        DECLARE @mapeados INT = (SELECT COUNT(*) FROM dbo.BI_DIM_SUCURSALES_CANON
                                 WHERE NRO_SUCURS <> NRO_SUCURS_CANON);
        PRINT 'RO_SP_SYNC_SUCURSALES_CANON OK. Codigos reasignados: ' + CAST(@mapeados AS VARCHAR(10));
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END
GO

EXEC dbo.RO_SP_SYNC_SUCURSALES_CANON;
GO

-- ── 4) Vistas canónicas ────────────────────────────────────────────────
-- Se generan desde INFORMATION_SCHEMA para no tener que enumerar columnas a
-- mano ni quedar desactualizadas si la tabla de hechos cambia. Cada vista
-- expone NRO_SUCURS ya canonicalizado y conserva el original en
-- NRO_SUCURS_ORIGINAL para drill-down y debug.
DECLARE @tablas TABLE (rn INT IDENTITY(1,1), origen SYSNAME, vista SYSNAME);
INSERT INTO @tablas (origen, vista) VALUES
    ('BI_SALES_SUCURSALES',      'VW_BI_SALES_SUCURSALES_CANON'),
    ('BI_SALES_SUCURSALES_BK',   'VW_BI_SALES_SUCURSALES_BK_CANON'),
    ('BI_SALES_TOTAL_TICKETS',   'VW_BI_SALES_TOTAL_TICKETS_CANON'),
    ('BI_SALES_TOTAL_TICKETS_BK','VW_BI_SALES_TOTAL_TICKETS_BK_CANON');

DECLARE @rn INT = 1, @max INT = (SELECT MAX(rn) FROM @tablas);
DECLARE @origen SYSNAME, @vista SYSNAME, @cols NVARCHAR(MAX), @sql NVARCHAR(MAX);

WHILE @rn <= @max
BEGIN
    SELECT @origen = origen, @vista = vista FROM @tablas WHERE rn = @rn;

    IF OBJECT_ID('dbo.' + @origen, 'U') IS NULL
    BEGIN
        PRINT 'Tabla ' + @origen + ' no existe, se omite su vista.';
        SET @rn = @rn + 1;
        CONTINUE;
    END

    -- Todas las columnas menos NRO_SUCURS, que se reemplaza por la canónica
    SELECT @cols = STUFF((
        SELECT ', s.' + QUOTENAME(COLUMN_NAME)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = @origen
          AND COLUMN_NAME <> 'NRO_SUCURS'
        ORDER BY ORDINAL_POSITION
        FOR XML PATH(''), TYPE).value('.', 'NVARCHAR(MAX)'), 1, 2, '');

    IF OBJECT_ID('dbo.' + @vista, 'V') IS NOT NULL
        EXEC('DROP VIEW dbo.' + @vista);

    SET @sql = N'CREATE VIEW dbo.' + QUOTENAME(@vista) + N' AS
SELECT ISNULL(c.NRO_SUCURS_CANON, s.NRO_SUCURS) AS NRO_SUCURS,
       s.NRO_SUCURS AS NRO_SUCURS_ORIGINAL,
       ' + @cols + N'
FROM dbo.' + QUOTENAME(@origen) + N' s
LEFT JOIN dbo.BI_DIM_SUCURSALES_CANON c ON c.NRO_SUCURS = s.NRO_SUCURS;';

    EXEC sp_executesql @sql;
    PRINT 'Vista ' + @vista + ' creada sobre ' + @origen + '.';

    SET @rn = @rn + 1;
END
GO

-- ── 5) Verificación: control de suma cero ──────────────────────────────
-- La vista reetiqueta, no filtra ni duplica: los dos totales tienen que dar
-- IDÉNTICO. Si difieren, hay un ciclo o una fila duplicada en el mapeo.
SELECT 'BI_SALES_SUCURSALES' AS fuente,
       SUM(IMPORTE) AS importe, SUM(CANTIDAD) AS unidades, COUNT(*) AS filas
FROM dbo.BI_SALES_SUCURSALES WITH (NOLOCK)
WHERE FECHA >= '2025-01-01'
UNION ALL
SELECT 'VW_..._CANON',
       SUM(IMPORTE), SUM(CANTIDAD), COUNT(*)
FROM dbo.VW_BI_SALES_SUCURSALES_CANON WITH (NOLOCK)
WHERE FECHA >= '2025-01-01';
GO

-- Mapeos aplicados: revisar A OJO que cada par sea realmente el mismo local.
-- El control de suma cero detecta duplicación, NO un mapeo incorrecto.
SELECT c.NRO_SUCURS, c.NRO_SUCURS_CANON, c.ORIGEN, c.HABILITADO,
       c.DESC_SUCURSAL AS desc_origen,
       d.DESC_SUCURSAL AS desc_canonico
FROM dbo.BI_DIM_SUCURSALES_CANON c
LEFT JOIN dbo.BI_DIM_SUCURSALES_CANON d ON d.NRO_SUCURS = c.NRO_SUCURS_CANON
WHERE c.NRO_SUCURS <> c.NRO_SUCURS_CANON
ORDER BY c.NRO_SUCURS_CANON, c.NRO_SUCURS;
GO
