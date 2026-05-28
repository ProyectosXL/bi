USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 00_indices.sql
-- Índices cubrientes para el Dashboard Logística.
-- Seguro de re-ejecutar: cada bloque verifica existencia antes
-- de crear. No modifica índices existentes.
-- ============================================================

-- ──────────────────────────────────────────────────────────────
-- 1. BI_EFICIENCIA_LOGISTICA
--    Usada en: SP02 (eficiencia), SP08 (pedidos consolidados)
--    Filtros principales : FECHA_PEDI (rango), CANAL (igualdad)
--    CASE WHEN sobre     : ESTADO_TANGO, FECHA_PEDI
--    Columnas agregadas  : CANT_PEDID, CANT_PEND, CANT_FACTURADA,
--                          IMPORTE_PEDIDO, IMPORTE_PENDIENTE
--    GROUP BY / SELECT   : NRO_PEDIDO, TALON_PED
-- ──────────────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_EFICIENCIA_LOGISTICA')
      AND name = 'IX_BI_EFICIENCIA_LOGISTICA_FECHA_CANAL'
)
    CREATE NONCLUSTERED INDEX IX_BI_EFICIENCIA_LOGISTICA_FECHA_CANAL
    ON dbo.BI_EFICIENCIA_LOGISTICA (FECHA_PEDI, CANAL)
    INCLUDE (
        ESTADO_TANGO,
        NRO_PEDIDO,
        TALON_PED,
        CANT_PEDID,
        CANT_PEND,
        CANT_FACTURADA,
        IMPORTE_PEDIDO,
        IMPORTE_PENDIENTE
    );
GO

-- Variante para cuando el slicer Canal esta activo: igualdad por CANAL + rango por FECHA.
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_EFICIENCIA_LOGISTICA')
      AND name = 'IX_BI_EFICIENCIA_LOGISTICA_CANAL_FECHA'
)
    CREATE NONCLUSTERED INDEX IX_BI_EFICIENCIA_LOGISTICA_CANAL_FECHA
    ON dbo.BI_EFICIENCIA_LOGISTICA (CANAL, FECHA_PEDI)
    INCLUDE (
        ESTADO_TANGO,
        TIPO_FACTURACION,
        NRO_PEDIDO,
        TALON_PED,
        CANT_PEDID,
        CANT_PEND,
        CANT_FACTURADA,
        IMPORTE_PEDIDO,
        IMPORTE_PENDIENTE
    );
GO

-- ──────────────────────────────────────────────────────────────
-- 2. BI_FACTURACION_LOGISTICA
--    Usada en: SP05 (productividad facturación)
--    Filtros : FECHA_COMP (rango), USUARIO (igualdad)
--    Columnas: CANTIDAD
-- ──────────────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_FACTURACION_LOGISTICA')
      AND name = 'IX_BI_FACTURACION_LOGISTICA_FECHA_USUARIO'
)
    CREATE NONCLUSTERED INDEX IX_BI_FACTURACION_LOGISTICA_FECHA_USUARIO
    ON dbo.BI_FACTURACION_LOGISTICA (FECHA_COMP, USUARIO)
    INCLUDE (CANTIDAD);
GO

-- ──────────────────────────────────────────────────────────────
-- 3. BI_T_TRACKING_PICKING
--    Usada en: SP06 (productividad picking), SP07 (despacho)
--    Filtros : FECHA_INI_PICKING (rango), USUARIO (igualdad)
--              HAVING SUM(MINUTOS) >= 180
--    Columnas: CANT_PICKING, MINUTOS
-- ──────────────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_T_TRACKING_PICKING')
      AND name = 'IX_BI_T_TRACKING_PICKING_FECHA_USUARIO'
)
    CREATE NONCLUSTERED INDEX IX_BI_T_TRACKING_PICKING_FECHA_USUARIO
    ON dbo.BI_T_TRACKING_PICKING (FECHA_INI_PICKING, USUARIO)
    INCLUDE (CANT_PICKING, MINUTOS);
GO

-- ──────────────────────────────────────────────────────────────
-- 4. BI_T_DESPACHO_PEDIDOS
--    Usada en: SP07 (demanda y despacho)
--    Filtros : FECHA_PEDIDO (rango / igualdad a fecha), ESTADO
--    Columnas: NRO_PEDIDO, COD_CLIENT, NOMBRE_CLIENTE, CANT_PEDIDO
--
--    NOTA: el predicado CAST(FECHA_PEDIDO AS DATE) = @HOY no es
--    sargable si FECHA_PEDIDO es DATETIME. Si eso ocurre, reemplazar
--    en el SP por el rango equivalente:
--      FECHA_PEDIDO >= @HOY AND FECHA_PEDIDO < DATEADD(DAY,1,@HOY)
-- ──────────────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_T_DESPACHO_PEDIDOS')
      AND name = 'IX_BI_T_DESPACHO_PEDIDOS_FECHA_ESTADO'
)
    CREATE NONCLUSTERED INDEX IX_BI_T_DESPACHO_PEDIDOS_FECHA_ESTADO
    ON dbo.BI_T_DESPACHO_PEDIDOS (FECHA_PEDIDO, ESTADO)
    INCLUDE (NRO_PEDIDO, COD_CLIENT, NOMBRE_CLIENTE, CANT_PEDIDO);
GO

-- ──────────────────────────────────────────────────────────────
-- 5. RO_T_DESPACHO_PEDIDOS
--    Usada en: SP07
--    Filtros : FECHA_GUIA (rango), ESTADO_DESPACHO (IN / igualdad)
--    JOIN con BI_T_DESPACHO_PEDIDOS por NRO_PEDIDO
--    Columnas: N_COMP
-- ──────────────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.RO_T_DESPACHO_PEDIDOS')
      AND name = 'IX_RO_T_DESPACHO_PEDIDOS_FECHA_ESTADO'
)
    CREATE NONCLUSTERED INDEX IX_RO_T_DESPACHO_PEDIDOS_FECHA_ESTADO
    ON dbo.RO_T_DESPACHO_PEDIDOS (FECHA_GUIA, ESTADO_DESPACHO)
    INCLUDE (N_COMP, NRO_PEDIDO);
GO

-- Índice adicional para el JOIN NRO_PEDIDO en RS3 del SP07
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.RO_T_DESPACHO_PEDIDOS')
      AND name = 'IX_RO_T_DESPACHO_PEDIDOS_PEDIDO_ESTADO'
)
    CREATE NONCLUSTERED INDEX IX_RO_T_DESPACHO_PEDIDOS_PEDIDO_ESTADO
    ON dbo.RO_T_DESPACHO_PEDIDOS (NRO_PEDIDO, ESTADO_DESPACHO)
    INCLUDE (N_COMP, FECHA_GUIA);
GO

-- ──────────────────────────────────────────────────────────────
-- 6. BI_KPI_LOG_FACTURACION
--    Usada en: SP03 (lead time)
--    Filtros : FECHA_COMP (rango), ESTADO_TANGO (igualdad)
--    Columnas: N_COMP, LEAD_TIME_FACT, NRO_PEDIDO
-- ──────────────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_KPI_LOG_FACTURACION')
      AND name = 'IX_BI_KPI_LOG_FACTURACION_FECHA'
)
    CREATE NONCLUSTERED INDEX IX_BI_KPI_LOG_FACTURACION_FECHA
    ON dbo.BI_KPI_LOG_FACTURACION (FECHA_COMP)
    INCLUDE (N_COMP, LEAD_TIME_FACT, NRO_PEDIDO, ESTADO_TANGO);
GO

-- Usado para reconstruir estado por pedido en RO_SP_EFICIENCIA_LOGISTICA.
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_KPI_LOG_FACTURACION')
      AND name = 'IX_BI_KPI_LOG_FACTURACION_PEDIDO'
)
    CREATE NONCLUSTERED INDEX IX_BI_KPI_LOG_FACTURACION_PEDIDO
    ON dbo.BI_KPI_LOG_FACTURACION (TALON_PED, NRO_PEDIDO)
    INCLUDE (CANT_PEDID, CANT_PEND, FECHA_COMP, N_COMP, LEAD_TIME_FACT, ESTADO_TANGO);
GO

-- Usado por RO_SP_LEADTIME_FACTURACION para pedidos abiertos.
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_KPI_LOG_FACTURACION')
      AND name = 'IX_BI_KPI_LOG_FACTURACION_ESTADO_PEDIDO'
)
    CREATE NONCLUSTERED INDEX IX_BI_KPI_LOG_FACTURACION_ESTADO_PEDIDO
    ON dbo.BI_KPI_LOG_FACTURACION (ESTADO_TANGO, NRO_PEDIDO)
    INCLUDE (FECHA_COMP, N_COMP, LEAD_TIME_FACT);
GO

-- ──────────────────────────────────────────────────────────────
-- 7. BI_STOCK_WMS_TANGO
--    Usada en: SP04 (stock)
--    Filtro : RUBRO (igualdad opcional)
--    Columnas: STOCK_TANGO, STOCK_UBIC, DIFERENCIA
-- ──────────────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_STOCK_WMS_TANGO')
      AND name = 'IX_BI_STOCK_WMS_TANGO_RUBRO'
)
    CREATE NONCLUSTERED INDEX IX_BI_STOCK_WMS_TANGO_RUBRO
    ON dbo.BI_STOCK_WMS_TANGO (RUBRO)
    INCLUDE (STOCK_TANGO, STOCK_UBIC, DIFERENCIA);
GO

-- ──────────────────────────────────────────────────────────────
-- 8. RO_T_CALENDARIO
--    Usada en: SP02, SP03, SP07, SP08 (JOIN y filtro de fechas)
--    Filtros : FECHA (rango / igualdad), DIA_LABORAL (igualdad)
--    Columnas: ANIO, MES, NOMBRE_MES
-- ──────────────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.RO_T_CALENDARIO')
      AND name = 'IX_RO_T_CALENDARIO_FECHA_LABORAL'
)
    CREATE NONCLUSTERED INDEX IX_RO_T_CALENDARIO_FECHA_LABORAL
    ON dbo.RO_T_CALENDARIO (FECHA, DIA_LABORAL)
    INCLUDE (ANIO, MES, NOMBRE_MES);
GO

-- ──────────────────────────────────────────────────────────────
-- Verificación: lista los índices recién creados
-- ──────────────────────────────────────────────────────────────
SELECT
    OBJECT_NAME(i.object_id)    AS Tabla,
    i.name                      AS Indice,
    i.type_desc                 AS Tipo,
    i.is_disabled               AS Deshabilitado
FROM sys.indexes i
WHERE i.name LIKE 'IX_BI_%'
   OR i.name LIKE 'IX_RO_%'
ORDER BY Tabla, Indice;
GO
