USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 14_sp_picking_canal.sql
-- RO_SP_PICKING_CANAL
-- Unidades pickeadas por canal (Prod. Picking).
--
-- BI_T_TRACKING_PICKING no tiene pedido ni canal. El WMS de Tango
-- (tablas SEIN_*, vía linked server XL-TANGO) sí: cada operación de
-- picking tiene sus pedidos, y el canal sale del pedido en
-- BI_EFICIENCIA_LOGISTICA. Los totales por día coinciden con
-- BI_T_TRACKING_PICKING (validado oct-2026).
--
--   SEIN_OperacionPorUsuario         tarea de picking (picker, inicio)
--   SEIN_DetalleOperacionPorUsuario  unidades pickeadas por artículo
--   SEIN_PedidosPorOperacion         pedidos de la operación
--   SEIN_Usuarios                    nombre del picker (para @USUARIO)
--
-- Una operación es de un solo cliente, así que cae en un solo canal.
-- @TIPO filtra por el tipo de remisión de los pedidos de la operación.
-- Result sets: 1) OK (0 = no se pudo leer Tango)  2) filas por canal.
-- ============================================================

IF OBJECT_ID('dbo.RO_SP_PICKING_CANAL','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_PICKING_CANAL;
GO

CREATE PROCEDURE dbo.RO_SP_PICKING_CANAL
    @FECHA_DESDE DATE,
    @FECHA_HASTA DATE,
    @USUARIO     NVARCHAR(100) = NULL,
    @TIPO        NVARCHAR(50)  = NULL   -- NULL = todos los tipos de remisión
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @D CHAR(8) = CONVERT(CHAR(8), @FECHA_DESDE, 112);
    DECLARE @H CHAR(8) = CONVERT(CHAR(8), DATEADD(DAY, 1, @FECHA_HASTA), 112);
    DECLARE @OK BIT = 1;

    -- Tareas del rango: unidades pickeadas + nombre del picker en los dos órdenes
    -- (en SEIN_Usuarios a veces Nombre/Apellido están invertidos).
    CREATE TABLE #O (ID_OPU INT, ID_OP INT, PICK FLOAT,
                     USR1 NVARCHAR(200) COLLATE DATABASE_DEFAULT,
                     USR2 NVARCHAR(200) COLLATE DATABASE_DEFAULT);
    CREATE TABLE #P (ID_OP INT, NRO_PEDIDO VARCHAR(20) COLLATE DATABASE_DEFAULT, TALON_PED INT);

    DECLARE @QO NVARCHAR(MAX) = N'SELECT o.ID, o.IdOperacion, SUM(d.CantidadPickeada),
            UPPER(LTRIM(RTRIM(MAX(u.Nombre))) + '' '' + LTRIM(RTRIM(MAX(u.Apellido)))),
            UPPER(LTRIM(RTRIM(MAX(u.Apellido))) + '' '' + LTRIM(RTRIM(MAX(u.Nombre))))
        FROM LAKER_SA.dbo.SEIN_OperacionPorUsuario o
        JOIN LAKER_SA.dbo.SEIN_DetalleOperacionPorUsuario d ON d.IdOperacionPorUsuario = o.ID
        LEFT JOIN LAKER_SA.dbo.SEIN_Usuarios u ON u.ID = o.IdUsuario
        WHERE o.FechaInicioPicking >= ''' + @D + N''' AND o.FechaInicioPicking < ''' + @H + N'''
        GROUP BY o.ID, o.IdOperacion';
    DECLARE @QP NVARCHAR(MAX) = N'SELECT DISTINCT pp.IdOperacion, pp.NumeroPedido, pp.TalonarioPedido
        FROM LAKER_SA.dbo.SEIN_PedidosPorOperacion pp
        JOIN LAKER_SA.dbo.SEIN_OperacionPorUsuario o ON o.IdOperacion = pp.IdOperacion
        WHERE o.FechaInicioPicking >= ''' + @D + N''' AND o.FechaInicioPicking < ''' + @H + N'''';

    -- OPENQUERY (no INSERT…EXEC AT) para no abrir transacción distribuida.
    DECLARE @SQL NVARCHAR(MAX);
    BEGIN TRY
        SET @SQL = N'INSERT INTO #O SELECT * FROM OPENQUERY([XL-TANGO], ''' + REPLACE(@QO, '''', '''''') + N''')';
        EXEC sp_executesql @SQL;
        SET @SQL = N'INSERT INTO #P SELECT * FROM OPENQUERY([XL-TANGO], ''' + REPLACE(@QP, '''', '''''') + N''')';
        EXEC sp_executesql @SQL;
    END TRY
    BEGIN CATCH
        TRUNCATE TABLE #O;
        TRUNCATE TABLE #P;
        SET @OK = 0;
    END CATCH;

    IF @USUARIO IS NOT NULL
        DELETE FROM #O
        WHERE UPPER(LTRIM(RTRIM(@USUARIO))) NOT IN (ISNULL(USR1, ''), ISNULL(USR2, ''));

    -- ── Result set 1: estado de la lectura de Tango ───────────────────────
    SELECT @OK AS OK;

    -- ── Result set 2: unidades pickeadas por canal ───────────────────────
    -- Canal y tipo de remisión de cada operación, por sus pedidos
    SELECT p.ID_OP, MAX(e.CANAL) AS CANAL, MAX(e.TIPO_FACTURACION) AS TIPO
    INTO #C
    FROM #P p
    JOIN (SELECT DISTINCT NRO_PEDIDO COLLATE DATABASE_DEFAULT AS NRO_PEDIDO, TALON_PED, CANAL, TIPO_FACTURACION
          FROM dbo.BI_EFICIENCIA_LOGISTICA
          WHERE NRO_PEDIDO COLLATE DATABASE_DEFAULT IN (SELECT NRO_PEDIDO FROM #P)) e
      ON e.NRO_PEDIDO = p.NRO_PEDIDO AND e.TALON_PED = p.TALON_PED
    GROUP BY p.ID_OP;

    -- Con filtro de tipo, quedan solo las tareas cuyos pedidos son de ese tipo
    IF @TIPO IS NOT NULL
        DELETE o FROM #O o
        WHERE NOT EXISTS (SELECT 1 FROM #C c WHERE c.ID_OP = o.ID_OP AND c.TIPO = @TIPO);

    ;WITH T AS (
        SELECT SUM(PICK) AS TOTAL FROM #O
    )
    SELECT
        ISNULL(c.CANAL, 'SIN CANAL')                                  AS CANAL,
        COUNT(*)                                                      AS TAREAS,
        CAST(SUM(o.PICK) AS DECIMAL(18,0))                            AS UNID_PICKEADAS,
        CAST(SUM(o.PICK) / NULLIF(MAX(t.TOTAL), 0) AS DECIMAL(10,4))  AS PCT_UNIDADES
    FROM #O o
    CROSS JOIN T t
    LEFT JOIN #C c ON c.ID_OP = o.ID_OP
    GROUP BY c.CANAL
    ORDER BY UNID_PICKEADAS DESC;

    DROP TABLE #O; DROP TABLE #P; DROP TABLE #C;
END;
GO
