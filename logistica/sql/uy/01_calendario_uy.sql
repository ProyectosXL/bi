USE [POWER_BI_CONTROL_URUGUAY];
GO
-- ============================================================
-- 01_calendario_uy.sql
-- Crea RO_T_FERIADOS_UY y RO_T_CALENDARIO_UY (2022-2027).
-- Feriados de Uruguay (Ley 17.414 y modificatorias).
-- Nota: feriados móviles (Reyes, 33 Orientales, Batalla de Las
-- Piedras, Artigas, Día de las Culturas, Difuntos) se listan
-- en su fecha base. Ajustar el año si la implementación lo requiere.
-- Ejecutar con derechos de DDL sobre POWER_BI_CONTROL_URUGUAY.
-- ============================================================

SET DATEFIRST 7;
GO

-- ── RO_T_FERIADOS_UY ──────────────────────────────────────────────────
IF OBJECT_ID('dbo.RO_T_FERIADOS_UY','U') IS NOT NULL DROP TABLE dbo.RO_T_FERIADOS_UY;
GO
CREATE TABLE dbo.RO_T_FERIADOS_UY (
    FECHA       DATE         NOT NULL CONSTRAINT PK_RO_T_FERIADOS_UY PRIMARY KEY,
    DESCRIPCION VARCHAR(100) COLLATE Modern_Spanish_CI_AI NOT NULL
);
GO

INSERT INTO dbo.RO_T_FERIADOS_UY (FECHA, DESCRIPCION) VALUES
/* ---- 2022 ---- */
('2022-01-01', 'Año Nuevo'),
('2022-01-06', 'Reyes (Epifanía)'),
('2022-02-28', 'Carnaval'),
('2022-03-01', 'Carnaval'),
('2022-04-11', 'Semana de Turismo – Lunes Santo'),
('2022-04-12', 'Semana de Turismo – Martes Santo'),
('2022-04-13', 'Semana de Turismo – Miércoles Santo'),
('2022-04-14', 'Semana de Turismo – Jueves Santo'),
('2022-04-15', 'Semana de Turismo – Viernes Santo'),
('2022-04-19', 'Desembarco de los 33 Orientales'),
('2022-05-01', 'Día de los Trabajadores'),
('2022-05-18', 'Batalla de Las Piedras'),
('2022-06-19', 'Natalicio de Artigas'),
('2022-07-18', 'Jura de la Constitución'),
('2022-08-25', 'Declaratoria de la Independencia'),
('2022-10-12', 'Día de las Culturas'),
('2022-11-02', 'Día de los Difuntos'),
('2022-12-25', 'Navidad'),
/* ---- 2023 ---- */
('2023-01-01', 'Año Nuevo'),
('2023-01-06', 'Reyes (Epifanía)'),
('2023-02-20', 'Carnaval'),
('2023-02-21', 'Carnaval'),
('2023-04-03', 'Semana de Turismo – Lunes Santo'),
('2023-04-04', 'Semana de Turismo – Martes Santo'),
('2023-04-05', 'Semana de Turismo – Miércoles Santo'),
('2023-04-06', 'Semana de Turismo – Jueves Santo'),
('2023-04-07', 'Semana de Turismo – Viernes Santo'),
('2023-04-19', 'Desembarco de los 33 Orientales'),
('2023-05-01', 'Día de los Trabajadores'),
('2023-05-18', 'Batalla de Las Piedras'),
('2023-06-19', 'Natalicio de Artigas'),
('2023-07-18', 'Jura de la Constitución'),
('2023-08-25', 'Declaratoria de la Independencia'),
('2023-10-12', 'Día de las Culturas'),
('2023-11-02', 'Día de los Difuntos'),
('2023-12-25', 'Navidad'),
/* ---- 2024 ---- */
('2024-01-01', 'Año Nuevo'),
('2024-01-06', 'Reyes (Epifanía)'),
('2024-02-12', 'Carnaval'),
('2024-02-13', 'Carnaval'),
('2024-03-25', 'Semana de Turismo – Lunes Santo'),
('2024-03-26', 'Semana de Turismo – Martes Santo'),
('2024-03-27', 'Semana de Turismo – Miércoles Santo'),
('2024-03-28', 'Semana de Turismo – Jueves Santo'),
('2024-03-29', 'Semana de Turismo – Viernes Santo'),
('2024-04-19', 'Desembarco de los 33 Orientales'),
('2024-05-01', 'Día de los Trabajadores'),
('2024-05-18', 'Batalla de Las Piedras'),
('2024-06-19', 'Natalicio de Artigas'),
('2024-07-18', 'Jura de la Constitución'),
('2024-08-25', 'Declaratoria de la Independencia'),
('2024-10-12', 'Día de las Culturas'),
('2024-11-02', 'Día de los Difuntos'),
('2024-12-25', 'Navidad'),
/* ---- 2025 ---- */
('2025-01-01', 'Año Nuevo'),
('2025-01-06', 'Reyes (Epifanía)'),
('2025-03-03', 'Carnaval'),
('2025-03-04', 'Carnaval'),
('2025-04-14', 'Semana de Turismo – Lunes Santo'),
('2025-04-15', 'Semana de Turismo – Martes Santo'),
('2025-04-16', 'Semana de Turismo – Miércoles Santo'),
('2025-04-17', 'Semana de Turismo – Jueves Santo'),
('2025-04-18', 'Semana de Turismo – Viernes Santo'),
('2025-04-19', 'Desembarco de los 33 Orientales'),
('2025-05-01', 'Día de los Trabajadores'),
('2025-05-18', 'Batalla de Las Piedras'),
('2025-06-19', 'Natalicio de Artigas'),
('2025-07-18', 'Jura de la Constitución'),
('2025-08-25', 'Declaratoria de la Independencia'),
('2025-10-12', 'Día de las Culturas'),
('2025-11-02', 'Día de los Difuntos'),
('2025-12-25', 'Navidad'),
/* ---- 2026 ---- */
('2026-01-01', 'Año Nuevo'),
('2026-01-06', 'Reyes (Epifanía)'),
('2026-02-16', 'Carnaval'),
('2026-02-17', 'Carnaval'),
('2026-03-30', 'Semana de Turismo – Lunes Santo'),
('2026-03-31', 'Semana de Turismo – Martes Santo'),
('2026-04-01', 'Semana de Turismo – Miércoles Santo'),
('2026-04-02', 'Semana de Turismo – Jueves Santo'),
('2026-04-03', 'Semana de Turismo – Viernes Santo'),
('2026-04-19', 'Desembarco de los 33 Orientales'),
('2026-05-01', 'Día de los Trabajadores'),
('2026-05-18', 'Batalla de Las Piedras'),
('2026-06-19', 'Natalicio de Artigas'),
('2026-07-18', 'Jura de la Constitución'),
('2026-08-25', 'Declaratoria de la Independencia'),
('2026-10-12', 'Día de las Culturas'),
('2026-11-02', 'Día de los Difuntos'),
('2026-12-25', 'Navidad'),
/* ---- 2027 ---- */
('2027-01-01', 'Año Nuevo'),
('2027-01-06', 'Reyes (Epifanía)'),
('2027-02-08', 'Carnaval'),
('2027-02-09', 'Carnaval'),
('2027-03-22', 'Semana de Turismo – Lunes Santo'),
('2027-03-23', 'Semana de Turismo – Martes Santo'),
('2027-03-24', 'Semana de Turismo – Miércoles Santo'),
('2027-03-25', 'Semana de Turismo – Jueves Santo'),
('2027-03-26', 'Semana de Turismo – Viernes Santo'),
('2027-04-19', 'Desembarco de los 33 Orientales'),
('2027-05-01', 'Día de los Trabajadores'),
('2027-05-18', 'Batalla de Las Piedras'),
('2027-06-19', 'Natalicio de Artigas'),
('2027-07-18', 'Jura de la Constitución'),
('2027-08-25', 'Declaratoria de la Independencia'),
('2027-10-12', 'Día de las Culturas'),
('2027-11-02', 'Día de los Difuntos'),
('2027-12-25', 'Navidad');
GO

-- ── RO_T_CALENDARIO_UY ───────────────────────────────────────────────
IF OBJECT_ID('dbo.RO_T_CALENDARIO_UY','U') IS NOT NULL DROP TABLE dbo.RO_T_CALENDARIO_UY;
GO
CREATE TABLE dbo.RO_T_CALENDARIO_UY (
    FECHA       DATE         NOT NULL CONSTRAINT PK_RO_T_CALENDARIO_UY PRIMARY KEY,
    ANIO        SMALLINT     NOT NULL,
    MES         TINYINT      NOT NULL,
    DIA         TINYINT      NOT NULL,
    TRIMESTRE   TINYINT      NOT NULL,
    NOM_DIA     VARCHAR(10)  COLLATE Modern_Spanish_CI_AI NOT NULL,
    NOMBRE_MES  VARCHAR(12)  COLLATE Modern_Spanish_CI_AI NOT NULL,
    DIA_SEMANA  TINYINT      NOT NULL,   -- 1=Dom, 2=Lun ... 7=Sáb (DATEFIRST=7)
    POSIC_ANIO  SMALLINT     NOT NULL,
    ES_FERIADO  BIT          NOT NULL CONSTRAINT DF_CALUY_FERIADO  DEFAULT 0,
    DIA_LABORAL BIT          NOT NULL CONSTRAINT DF_CALUY_LABORAL  DEFAULT 0
);
GO

SET DATEFIRST 7;
GO

WITH Fechas AS (
    SELECT CAST('2022-01-01' AS DATE) AS FECHA
    UNION ALL
    SELECT DATEADD(DAY, 1, FECHA) FROM Fechas WHERE FECHA < '2027-12-31'
)
INSERT INTO dbo.RO_T_CALENDARIO_UY
    (FECHA, ANIO, MES, DIA, TRIMESTRE, NOM_DIA, NOMBRE_MES,
     DIA_SEMANA, POSIC_ANIO, ES_FERIADO, DIA_LABORAL)
SELECT
    FECHA,
    CAST(YEAR(FECHA)  AS SMALLINT),
    CAST(MONTH(FECHA) AS TINYINT),
    CAST(DAY(FECHA)   AS TINYINT),
    CAST(CEILING(MONTH(FECHA) / 3.0) AS TINYINT),
    CASE DATEPART(WEEKDAY, FECHA)
        WHEN 1 THEN 'Domingo'   WHEN 2 THEN 'Lunes'
        WHEN 3 THEN 'Martes'    WHEN 4 THEN 'Miércoles'
        WHEN 5 THEN 'Jueves'    WHEN 6 THEN 'Viernes'
        WHEN 7 THEN 'Sábado'
    END,
    CASE MONTH(FECHA)
        WHEN 1  THEN 'Enero'      WHEN 2  THEN 'Febrero'
        WHEN 3  THEN 'Marzo'      WHEN 4  THEN 'Abril'
        WHEN 5  THEN 'Mayo'       WHEN 6  THEN 'Junio'
        WHEN 7  THEN 'Julio'      WHEN 8  THEN 'Agosto'
        WHEN 9  THEN 'Septiembre' WHEN 10 THEN 'Octubre'
        WHEN 11 THEN 'Noviembre'  WHEN 12 THEN 'Diciembre'
    END,
    CAST(DATEPART(WEEKDAY, FECHA) AS TINYINT),
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
FROM   dbo.RO_T_CALENDARIO_UY c
INNER JOIN dbo.RO_T_FERIADOS_UY f ON c.FECHA = f.FECHA;
GO

DECLARE @cnt INT = (SELECT COUNT(1) FROM dbo.RO_T_CALENDARIO_UY);
PRINT 'RO_T_CALENDARIO_UY poblada: ' + CAST(@cnt AS VARCHAR) + ' filas';
SELECT TOP 5 * FROM dbo.RO_T_CALENDARIO_UY ORDER BY FECHA;
SELECT ANIO, COUNT(*) AS Dias, SUM(CAST(DIA_LABORAL AS INT)) AS Laborales,
       SUM(CAST(ES_FERIADO AS INT)) AS Feriados
FROM dbo.RO_T_CALENDARIO_UY GROUP BY ANIO ORDER BY ANIO;
GO
