USE [POWER_BI_CONTROL_URUGUAY];
GO
-- ============================================================
-- 04_sync_stock_uy.sql
-- Tabla materializada + SP de sincronización nightly.
-- Crea BI_T_STOCK_WMS_TANGO_UY en POWER_BI_CONTROL_URUGUAY
-- y el SP RO_SP_SYNC_STOCK_UY que la refreshea desde
-- XL-TANGO.TASKY_SA.dbo.BI_STOCK_WMS_TANGO vía linked server.
-- Agendar como SQL Agent Job: diario fuera de horario pico.
-- ============================================================

-- ── Tabla destino (materializada) ────────────────────────────────────
IF OBJECT_ID('dbo.BI_T_STOCK_WMS_TANGO_UY','U') IS NULL
BEGIN
    CREATE TABLE dbo.BI_T_STOCK_WMS_TANGO_UY (
        COD_ARTICU    NVARCHAR(30)   COLLATE Modern_Spanish_CI_AI NOT NULL,
        DESCRIPCION   NVARCHAR(100)  COLLATE Modern_Spanish_CI_AI NULL,
        COLOR         NVARCHAR(30)   COLLATE Modern_Spanish_CI_AI NULL,
        TEMPORADA     NVARCHAR(30)   COLLATE Modern_Spanish_CI_AI NULL,
        MODELO        NVARCHAR(50)   COLLATE Modern_Spanish_CI_AI NULL,
        CATEGORIA     NVARCHAR(50)   COLLATE Modern_Spanish_CI_AI NULL,
        RUBRO         NVARCHAR(50)   COLLATE Modern_Spanish_CI_AI NULL,
        DESTINO       NVARCHAR(50)   COLLATE Modern_Spanish_CI_AI NULL,
        DEPOSITO      NVARCHAR(50)   COLLATE Modern_Spanish_CI_AI NULL,
        STOCK_TANGO   DECIMAL(18,2)  NULL,
        STOCK_UBIC    DECIMAL(18,2)  NULL,
        DIFERENCIA    DECIMAL(18,2)  NULL,
        DIFERENCIA_ABS DECIMAL(18,2) NULL,
        FECHA_SYNC    DATETIME       NOT NULL DEFAULT GETDATE()
    );
    PRINT 'Tabla BI_T_STOCK_WMS_TANGO_UY creada.';
END
ELSE
    PRINT 'Tabla BI_T_STOCK_WMS_TANGO_UY ya existe.';
GO

-- ── SP de sincronización ─────────────────────────────────────────────
IF OBJECT_ID('dbo.RO_SP_SYNC_STOCK_UY','P') IS NOT NULL
    DROP PROCEDURE dbo.RO_SP_SYNC_STOCK_UY;
GO

CREATE PROCEDURE dbo.RO_SP_SYNC_STOCK_UY
AS
BEGIN
    SET NOCOUNT ON;

    BEGIN TRY
        BEGIN TRANSACTION;

        TRUNCATE TABLE dbo.BI_T_STOCK_WMS_TANGO_UY;

        INSERT INTO dbo.BI_T_STOCK_WMS_TANGO_UY
            (COD_ARTICU, DESCRIPCION, COLOR, TEMPORADA, MODELO,
             CATEGORIA, RUBRO, DESTINO, DEPOSITO,
             STOCK_TANGO, STOCK_UBIC, DIFERENCIA, DIFERENCIA_ABS,
             FECHA_SYNC)
        SELECT
            COD_ARTICU   COLLATE Modern_Spanish_CI_AI,
            DESCRIPCION  COLLATE Modern_Spanish_CI_AI,
            COLOR        COLLATE Modern_Spanish_CI_AI,
            TEMPORADA    COLLATE Modern_Spanish_CI_AI,
            MODELO       COLLATE Modern_Spanish_CI_AI,
            CATEGORIA    COLLATE Modern_Spanish_CI_AI,
            RUBRO        COLLATE Modern_Spanish_CI_AI,
            DESTINO      COLLATE Modern_Spanish_CI_AI,
            DEPOSITO     COLLATE Modern_Spanish_CI_AI,
            STOCK_TANGO,
            STOCK_UBIC,
            DIFERENCIA,
            DIFERENCIA_ABS,
            GETDATE()
        FROM [XL-TANGO].[TASKY_SA].[dbo].[BI_STOCK_WMS_TANGO];

        COMMIT;
        DECLARE @cnt INT = (SELECT COUNT(1) FROM dbo.BI_T_STOCK_WMS_TANGO_UY);
        PRINT 'Sync stock UY OK: ' + CAST(@cnt AS VARCHAR) + ' filas — ' + CONVERT(VARCHAR, GETDATE(), 120);
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK;
        DECLARE @msg NVARCHAR(2048) = ERROR_MESSAGE();
        RAISERROR('RO_SP_SYNC_STOCK_UY falló: %s', 16, 1, @msg);
    END CATCH;
END;
GO

PRINT 'SP RO_SP_SYNC_STOCK_UY creado. Agendar como SQL Agent Job nightly.';
GO
