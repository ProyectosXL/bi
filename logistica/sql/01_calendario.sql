USE [POWER_BI_CONTROL];
GO
-- ============================================================
-- 01_calendario.sql
-- Crea RO_T_FERIADOS y RO_T_CALENDARIO (2022-2027).
-- Ejecutar con derechos de DDL sobre POWER_BI_CONTROL.
-- ============================================================

SET DATEFIRST 7;   -- garantiza: 1=Domingo ... 7=Sábado (DAX convention)
GO

-- ── RO_T_FERIADOS ─────────────────────────────────────────────────────────
IF OBJECT_ID('dbo.RO_T_FERIADOS','U') IS NOT NULL DROP TABLE dbo.RO_T_FERIADOS;
GO
CREATE TABLE dbo.RO_T_FERIADOS (
    FECHA       DATE         NOT NULL CONSTRAINT PK_RO_T_FERIADOS PRIMARY KEY,
    DESCRIPCION VARCHAR(100) COLLATE Modern_Spanish_CI_AI NOT NULL
);
GO

INSERT INTO dbo.RO_T_FERIADOS (FECHA, DESCRIPCION) VALUES
/* ---- 2022 ---- */
('2022-01-01','Año Nuevo'),
('2022-02-28','Carnaval'),
('2022-03-01','Carnaval'),
('2022-03-24','Día Nacional de la Memoria'),
('2022-04-02','Veteranos de Malvinas'),
('2022-04-14','Jueves Santo'),
('2022-04-15','Viernes Santo'),
('2022-05-01','Día del Trabajador'),
('2022-05-18','Censo Nacional 2022'),
('2022-05-25','Revolución de Mayo'),
('2022-06-17','Güemes (trasladado)'),
('2022-06-20','Belgrano'),
('2022-07-09','Independencia'),
('2022-08-15','San Martín (trasladado)'),
('2022-10-07','Feriado puente'),
('2022-10-10','Respeto a la Diversidad Cultural (trasladado)'),
('2022-11-20','Soberanía Nacional (trasladado)'),
('2022-11-21','Feriado puente'),
('2022-12-08','Inmaculada Concepción'),
('2022-12-25','Navidad'),
/* ---- 2023 ---- */
('2023-01-01','Año Nuevo'),
('2023-02-20','Carnaval'),
('2023-02-21','Carnaval'),
('2023-03-24','Día Nacional de la Memoria'),
('2023-04-02','Veteranos de Malvinas'),
('2023-04-06','Jueves Santo'),
('2023-04-07','Viernes Santo'),
('2023-05-01','Día del Trabajador'),
('2023-05-25','Revolución de Mayo'),
('2023-05-26','Feriado puente'),
('2023-06-17','Güemes'),
('2023-06-19','Feriado puente'),
('2023-06-20','Belgrano'),
('2023-07-09','Independencia'),
('2023-08-21','San Martín (trasladado)'),
('2023-10-13','Feriado puente'),
('2023-10-15','Respeto a la Diversidad Cultural (trasladado)'),
('2023-11-17','Feriado puente'),
('2023-11-20','Soberanía Nacional'),
('2023-12-08','Inmaculada Concepción'),
('2023-12-25','Navidad'),
/* ---- 2024 ---- */
('2024-01-01','Año Nuevo'),
('2024-02-12','Carnaval'),
('2024-02-13','Carnaval'),
('2024-03-24','Día Nacional de la Memoria'),
('2024-03-28','Jueves Santo'),
('2024-03-29','Viernes Santo'),
('2024-04-01','Feriado puente'),
('2024-04-02','Veteranos de Malvinas'),
('2024-05-01','Día del Trabajador'),
('2024-05-25','Revolución de Mayo'),
('2024-06-17','Güemes'),
('2024-06-20','Belgrano'),
('2024-06-21','Feriado puente'),
('2024-07-09','Independencia'),
('2024-08-19','San Martín (trasladado)'),
('2024-10-11','Feriado puente'),
('2024-10-12','Respeto a la Diversidad Cultural'),
('2024-11-18','Feriado puente'),
('2024-11-20','Soberanía Nacional'),
('2024-12-08','Inmaculada Concepción'),
('2024-12-25','Navidad'),
/* ---- 2025 ---- */
('2025-01-01','Año Nuevo'),
('2025-03-03','Carnaval'),
('2025-03-04','Carnaval'),
('2025-03-24','Día Nacional de la Memoria'),
('2025-04-02','Veteranos de Malvinas'),
('2025-04-17','Jueves Santo'),
('2025-04-18','Viernes Santo'),
('2025-05-01','Día del Trabajador'),
('2025-05-25','Revolución de Mayo'),
('2025-06-16','Güemes (trasladado)'),
('2025-06-20','Belgrano'),
('2025-07-09','Independencia'),
('2025-08-17','San Martín'),
('2025-10-12','Respeto a la Diversidad Cultural'),
('2025-11-20','Soberanía Nacional'),
('2025-12-08','Inmaculada Concepción'),
('2025-12-25','Navidad'),
/* ---- 2026 ---- */
('2026-01-01','Año Nuevo'),
('2026-02-16','Carnaval'),
('2026-02-17','Carnaval'),
('2026-03-24','Día Nacional de la Memoria'),
('2026-04-02','Veteranos de Malvinas / Jueves Santo'),   -- coincidencia 2026
('2026-04-03','Viernes Santo'),
('2026-05-01','Día del Trabajador'),
('2026-05-25','Revolución de Mayo'),
('2026-06-15','Güemes (trasladado)'),
('2026-06-20','Belgrano'),
('2026-07-09','Independencia'),
('2026-08-17','San Martín'),
('2026-10-12','Respeto a la Diversidad Cultural'),
('2026-11-20','Soberanía Nacional'),
('2026-12-08','Inmaculada Concepción'),
('2026-12-25','Navidad'),
/* ---- 2027 ---- */
('2027-01-01','Año Nuevo'),
('2027-02-08','Carnaval'),
('2027-02-09','Carnaval'),
('2027-03-24','Día Nacional de la Memoria'),
('2027-03-25','Jueves Santo'),
('2027-03-26','Viernes Santo'),
('2027-04-02','Veteranos de Malvinas'),
('2027-05-01','Día del Trabajador'),
('2027-05-25','Revolución de Mayo'),
('2027-06-17','Güemes'),
('2027-06-20','Belgrano'),
('2027-06-21','Feriado puente'),
('2027-07-09','Independencia'),
('2027-08-16','San Martín (trasladado)'),
('2027-10-11','Feriado puente'),
('2027-10-12','Respeto a la Diversidad Cultural'),
('2027-11-20','Soberanía Nacional'),
('2027-11-22','Feriado puente'),
('2027-12-08','Inmaculada Concepción'),
('2027-12-25','Navidad');
GO

-- ── RO_T_CALENDARIO ───────────────────────────────────────────────────────
IF OBJECT_ID('dbo.RO_T_CALENDARIO','U') IS NOT NULL DROP TABLE dbo.RO_T_CALENDARIO;
GO
CREATE TABLE dbo.RO_T_CALENDARIO (
    FECHA       DATE         NOT NULL CONSTRAINT PK_RO_T_CALENDARIO PRIMARY KEY,
    ANIO        SMALLINT     NOT NULL,
    MES         TINYINT      NOT NULL,
    DIA         TINYINT      NOT NULL,
    TRIMESTRE   TINYINT      NOT NULL,
    NOM_DIA     VARCHAR(10)  COLLATE Modern_Spanish_CI_AI NOT NULL,
    NOMBRE_MES  VARCHAR(12)  COLLATE Modern_Spanish_CI_AI NOT NULL,
    DIA_SEMANA  TINYINT      NOT NULL,   -- 1=Dom, 2=Lun ... 7=Sáb (DAX)
    POSIC_ANIO  SMALLINT     NOT NULL,
    ES_FERIADO  BIT          NOT NULL CONSTRAINT DF_CAL_FERIADO  DEFAULT 0,
    DIA_LABORAL BIT          NOT NULL CONSTRAINT DF_CAL_LABORAL  DEFAULT 0
);
GO

SET DATEFIRST 7;
GO

WITH Fechas AS (
    SELECT CAST('2022-01-01' AS DATE) AS FECHA
    UNION ALL
    SELECT DATEADD(DAY, 1, FECHA) FROM Fechas WHERE FECHA < '2027-12-31'
)
INSERT INTO dbo.RO_T_CALENDARIO
    (FECHA, ANIO, MES, DIA, TRIMESTRE, NOM_DIA, NOMBRE_MES,
     DIA_SEMANA, POSIC_ANIO, ES_FERIADO, DIA_LABORAL)
SELECT
    FECHA,
    CAST(YEAR (FECHA) AS SMALLINT),
    CAST(MONTH(FECHA) AS TINYINT),
    CAST(DAY  (FECHA) AS TINYINT),
    CAST(CEILING(MONTH(FECHA) / 3.0) AS TINYINT),
    -- NOM_DIA: CASE explícito, nunca SET LANGUAGE
    CASE DATEPART(WEEKDAY, FECHA)
        WHEN 1 THEN 'Domingo'   WHEN 2 THEN 'Lunes'
        WHEN 3 THEN 'Martes'    WHEN 4 THEN 'Miércoles'
        WHEN 5 THEN 'Jueves'    WHEN 6 THEN 'Viernes'
        WHEN 7 THEN 'Sábado'
    END,
    -- NOMBRE_MES: CASE explícito
    CASE MONTH(FECHA)
        WHEN 1  THEN 'Enero'      WHEN 2  THEN 'Febrero'
        WHEN 3  THEN 'Marzo'      WHEN 4  THEN 'Abril'
        WHEN 5  THEN 'Mayo'       WHEN 6  THEN 'Junio'
        WHEN 7  THEN 'Julio'      WHEN 8  THEN 'Agosto'
        WHEN 9  THEN 'Septiembre' WHEN 10 THEN 'Octubre'
        WHEN 11 THEN 'Noviembre'  WHEN 12 THEN 'Diciembre'
    END,
    CAST(DATEPART(WEEKDAY, FECHA) AS TINYINT),          -- 1=Dom...7=Sáb (DATEFIRST=7)
    CAST(DATEPART(DAYOFYEAR, FECHA) AS SMALLINT),
    0,
    CASE WHEN DATEPART(WEEKDAY, FECHA) NOT IN (1, 7) THEN 1 ELSE 0 END
FROM Fechas
OPTION (MAXRECURSION 0);
GO

-- Marcar feriados y actualizar DIA_LABORAL
UPDATE c
SET    c.ES_FERIADO  = 1,
       c.DIA_LABORAL = 0
FROM   dbo.RO_T_CALENDARIO c
INNER JOIN dbo.RO_T_FERIADOS f ON c.FECHA = f.FECHA;
GO

DECLARE @cnt INT = (SELECT COUNT(1) FROM dbo.RO_T_CALENDARIO);
PRINT 'RO_T_CALENDARIO poblada: ' + CAST(@cnt AS VARCHAR) + ' filas';
-- Verificación rápida
SELECT TOP 5 * FROM dbo.RO_T_CALENDARIO ORDER BY FECHA;
SELECT ANIO, COUNT(*) AS Dias, SUM(CAST(DIA_LABORAL AS INT)) AS Laborales, SUM(CAST(ES_FERIADO AS INT)) AS Feriados
FROM dbo.RO_T_CALENDARIO GROUP BY ANIO ORDER BY ANIO;
GO
