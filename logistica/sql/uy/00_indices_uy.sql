USE [POWER_BI_CONTROL_URUGUAY];
GO
-- ============================================================
-- 00_indices_uy.sql
-- Índices cubrientes para las tablas UY.
-- Verifica existencia antes de crear. Ejecutar con permisos DDL.
-- Orden de ejecución: ANTES de los SPs (no hay dependencias).
-- ============================================================

-- ── BI_T_EFICIENCIA_LOGISTICA_UY: IX_EFI_UY_FECHA_CANAL ──────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_T_EFICIENCIA_LOGISTICA_UY')
      AND name = 'IX_EFI_UY_FECHA_CANAL'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_EFI_UY_FECHA_CANAL
        ON dbo.BI_T_EFICIENCIA_LOGISTICA_UY (FECHA_PEDI, CANAL)
        INCLUDE (RUBRO, ESTADO_TANGO, NRO_PEDIDO,
                 CANT_PEDID, CANT_PEND, CANT_FACTURADA,
                 IMPORTE_PEDIDO, IMPORTE_PENDIENTE);
    PRINT 'Índice IX_EFI_UY_FECHA_CANAL creado.';
END
ELSE
    PRINT 'Índice IX_EFI_UY_FECHA_CANAL ya existe.';
GO

-- ── BI_T_EFICIENCIA_LOGISTICA_UY: IX_EFI_UY_RUBRO_FECHA ──────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_T_EFICIENCIA_LOGISTICA_UY')
      AND name = 'IX_EFI_UY_RUBRO_FECHA'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_EFI_UY_RUBRO_FECHA
        ON dbo.BI_T_EFICIENCIA_LOGISTICA_UY (RUBRO, FECHA_PEDI)
        INCLUDE (CANAL, ESTADO_TANGO, NRO_PEDIDO,
                 CANT_PEDID, CANT_PEND, CANT_FACTURADA,
                 IMPORTE_PEDIDO, IMPORTE_PENDIENTE);
    PRINT 'Índice IX_EFI_UY_RUBRO_FECHA creado.';
END
ELSE
    PRINT 'Índice IX_EFI_UY_RUBRO_FECHA ya existe.';
GO

-- ── BI_T_STOCK_WMS_TANGO_UY: IX_STOCK_UY_RUBRO ───────────────────────
-- Solo si se usa la opción A (tabla materializada).
IF OBJECT_ID('dbo.BI_T_STOCK_WMS_TANGO_UY','U') IS NOT NULL
AND NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_T_STOCK_WMS_TANGO_UY')
      AND name = 'IX_STOCK_UY_RUBRO'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_STOCK_UY_RUBRO
        ON dbo.BI_T_STOCK_WMS_TANGO_UY (RUBRO)
        INCLUDE (STOCK_TANGO, STOCK_UBIC, DIFERENCIA, DIFERENCIA_ABS);
    PRINT 'Índice IX_STOCK_UY_RUBRO creado.';
END
ELSE
    PRINT 'Índice IX_STOCK_UY_RUBRO ya existe o tabla no existe (opción B linked server).';
GO

-- ── BI_T_FACTURACION_LOGISTICA_UY: IX_FACT_UY_FECHA_CANAL (previsión) ──
IF OBJECT_ID('dbo.BI_T_FACTURACION_LOGISTICA_UY','U') IS NOT NULL
AND NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.BI_T_FACTURACION_LOGISTICA_UY')
      AND name = 'IX_FACT_UY_FECHA_CANAL'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_FACT_UY_FECHA_CANAL
        ON dbo.BI_T_FACTURACION_LOGISTICA_UY (FECHA_COMP, CANAL)
        INCLUDE (CANTIDAD, RUBRO);
    PRINT 'Índice IX_FACT_UY_FECHA_CANAL creado.';
END
ELSE
    PRINT 'Índice IX_FACT_UY_FECHA_CANAL ya existe o tabla no existe.';
GO

-- ── RO_T_CALENDARIO_UY: IX_CAL_UY_FECHA ─────────────────────────────
IF OBJECT_ID('dbo.RO_T_CALENDARIO_UY','U') IS NOT NULL
AND NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.RO_T_CALENDARIO_UY')
      AND name = 'IX_CAL_UY_FECHA'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_CAL_UY_FECHA
        ON dbo.RO_T_CALENDARIO_UY (FECHA, DIA_LABORAL)
        INCLUDE (ANIO, MES, NOMBRE_MES);
    PRINT 'Índice IX_CAL_UY_FECHA creado.';
END
ELSE
    PRINT 'Índice IX_CAL_UY_FECHA ya existe o tabla no existe (ejecutar 01_calendario_uy.sql primero).';
GO
