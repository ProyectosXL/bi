USE [POWER_BI_CONTROL_URUGUAY];
GO
-- ============================================================
-- 00b_cotizacion.sql
-- Tabla de cotización UYU/USD para el tablero de eficiencia.
-- Ejecutar en POWER_BI_CONTROL_URUGUAY con derechos de DDL.
-- ============================================================

IF OBJECT_ID('dbo.RO_T_COTIZACION_UYU_USD','U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_COTIZACION_UYU_USD (
        FECHA      DATE           NOT NULL CONSTRAINT PK_COTIZ_UYU_USD PRIMARY KEY,
        COTIZACION DECIMAL(18,4)  NOT NULL,
        FUENTE     NVARCHAR(100)  COLLATE Modern_Spanish_CI_AI NULL
    );
    PRINT 'Tabla RO_T_COTIZACION_UYU_USD creada.';
END
ELSE
    PRINT 'Tabla RO_T_COTIZACION_UYU_USD ya existe.';
GO

-- Cargar cotizaciones históricas de referencia (ajustar según datos reales).
-- La clave es FECHA: se guarda la cotización vigente en esa fecha.
-- El SP/PHP toma la última con FECHA <= @FECHA_HASTA.
MERGE dbo.RO_T_COTIZACION_UYU_USD AS tgt
USING (VALUES
    ('2022-01-03', 43.25, 'carga_inicial'),
    ('2022-07-01', 41.50, 'carga_inicial'),
    ('2023-01-02', 39.80, 'carga_inicial'),
    ('2023-07-03', 38.60, 'carga_inicial'),
    ('2024-01-02', 39.10, 'carga_inicial'),
    ('2024-07-01', 40.20, 'carga_inicial'),
    ('2025-01-02', 42.00, 'carga_inicial'),
    ('2025-07-01', 43.80, 'carga_inicial'),
    ('2026-01-02', 44.50, 'carga_inicial')
) AS src (FECHA, COTIZACION, FUENTE)
ON tgt.FECHA = src.FECHA
WHEN NOT MATCHED THEN
    INSERT (FECHA, COTIZACION, FUENTE) VALUES (src.FECHA, src.COTIZACION, src.FUENTE);
GO

SELECT * FROM dbo.RO_T_COTIZACION_UYU_USD ORDER BY FECHA DESC;
GO
