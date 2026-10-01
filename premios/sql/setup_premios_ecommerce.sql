-- ═══════════════════════════════════════════════════════════════════════
-- Setup único para la pestaña "Premios Ecommerce" del dashboard /bi/premios/.
-- Correr TODO este script en: POWER_BI_CONTROL (host_apps / conexión 'power'
-- de bi/class/Conexion.php) — la misma base donde vive BI_T_PREMIOS_CONTROL.
--
-- Es idempotente: se puede correr más de una vez sin duplicar tablas ni seeds.
--
-- MODELO
--   PERSONAS  → una fila por persona del área que cobra premio.
--   CONCEPTOS → un renglón de la tabla de esa persona (lo que se le mide).
--   ESCALAS   → los tramos de premio de cada concepto (las "PAUTAS" del Excel).
--   KPIS      → carga manual mensual de los datos que NO existen en ninguna
--               tabla del BI (órdenes VTEX, sesiones, tasa de conversión) más
--               el objetivo de órdenes del mes.
--
-- La facturación (real y objetivo) NO se carga acá: sale de BI_EFICIENCIA_ECOMMERCE,
-- BI_T_ESTADISTICAS_VENTAS_PROPIOS y sistemas.dbo.FP_ObjetivosFinales — ver PremiosEcommerceDB.php.
-- ═══════════════════════════════════════════════════════════════════════

-- ── 1) Personas ────────────────────────────────────────────────────────
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'BI_T_PREMIOS_ECOM_PERSONAS')
BEGIN
    CREATE TABLE BI_T_PREMIOS_ECOM_PERSONAS (
        ID                  INT IDENTITY(1,1) NOT NULL,
        NOMBRE              VARCHAR(100) NOT NULL,
        ORDEN               INT          NOT NULL DEFAULT 1,
        ACTIVO              BIT          NOT NULL DEFAULT 1,
        ACTUALIZADO_POR     VARCHAR(100) NULL,
        FECHA_ACTUALIZACION DATETIME     NULL,
        CONSTRAINT PK_BI_T_PREMIOS_ECOM_PERSONAS PRIMARY KEY (ID),
        CONSTRAINT UQ_BI_T_PREMIOS_ECOM_PERSONAS UNIQUE (NOMBRE)
    );
END
GO

-- Mail de cada persona, para el botón "Enviar mail" de la pestaña (api/enviar_mail_ecommerce.php).
-- Se agregó después de crear la tabla: por eso va como ALTER y no dentro del CREATE.
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_NAME = 'BI_T_PREMIOS_ECOM_PERSONAS' AND COLUMN_NAME = 'EMAIL')
BEGIN
    ALTER TABLE BI_T_PREMIOS_ECOM_PERSONAS ADD EMAIL VARCHAR(150) NULL;
END
GO
-- Se carga desde comercial/supervision/premios/gestionarPremios.php («Ecommerce · Personas»).

-- ── 2) Conceptos asignados a cada persona ──────────────────────────────
-- ORIGEN_REAL / ORIGEN_OBJETIVO son códigos de un conjunto CERRADO, resuelto por
-- PremiosEcommerceDB::MAPA_ORIGEN. Viven en la tabla (y no en el código) solo para
-- poder reasignar un concepto de canal sin deploy — agregar un código NUEVO sí
-- requiere tocar la clase PHP.
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'BI_T_PREMIOS_ECOM_CONCEPTOS')
BEGIN
    CREATE TABLE BI_T_PREMIOS_ECOM_CONCEPTOS (
        ID              INT IDENTITY(1,1) NOT NULL,
        ID_PERSONA      INT          NOT NULL,
        CANAL           VARCHAR(10)  NULL,      -- 'VTEX' | 'ML' | NULL (combinado VTEX+ML)
        ETIQUETA        VARCHAR(80)  NOT NULL,  -- lo que se muestra en la tabla
        METRICA         VARCHAR(30)  NOT NULL,  -- 'FACTURACION' | 'ORDENES' | 'TASA_CONVERSION'
        TIPO_UMBRAL     VARCHAR(20)  NOT NULL,  -- 'PCT_CUMPLIMIENTO' | 'VALOR_ABSOLUTO'
        ORIGEN_REAL     VARCHAR(30)  NOT NULL,  -- FACT_VTEX|FACT_ML|FACT_VTEX_ML|ORD_VTEX|CONV_VTEX
        ORIGEN_OBJETIVO VARCHAR(30)  NOT NULL,  -- OBJ_VTEX|OBJ_ML|OBJ_VTEX_ML|OBJ_ORD_VTEX|NINGUNO
        ORDEN           INT          NOT NULL DEFAULT 1,
        ACTIVO          BIT          NOT NULL DEFAULT 1,
        CONSTRAINT PK_BI_T_PREMIOS_ECOM_CONCEPTOS PRIMARY KEY (ID),
        CONSTRAINT FK_ECOM_CONCEPTOS_PERSONA FOREIGN KEY (ID_PERSONA)
            REFERENCES BI_T_PREMIOS_ECOM_PERSONAS (ID),
        CONSTRAINT UQ_BI_T_PREMIOS_ECOM_CONCEPTOS UNIQUE (ID_PERSONA, ETIQUETA),
        CONSTRAINT CK_ECOM_CONCEPTOS_TIPO_UMBRAL CHECK (TIPO_UMBRAL IN ('PCT_CUMPLIMIENTO', 'VALOR_ABSOLUTO')),
        CONSTRAINT CK_ECOM_CONCEPTOS_METRICA CHECK (METRICA IN ('FACTURACION', 'ORDENES', 'TASA_CONVERSION'))
    );
    CREATE INDEX IX_ECOM_CONCEPTOS_PERSONA ON BI_T_PREMIOS_ECOM_CONCEPTOS (ID_PERSONA, ORDEN);
END
GO

-- ── 3) Escalas (tramos) de premio por concepto ─────────────────────────
-- Semántica de UMBRAL, según el TIPO_UMBRAL del concepto:
--   PCT_CUMPLIMIENTO → ratio real/objetivo. 1.0000 = 100 % del objetivo.
--   VALOR_ABSOLUTO   → el valor real crudo. 0.8000 = tasa de conversión de 0,80 %.
-- Regla de pago (ver PremiosEcommerceDB::escalonFijo): se busca el tramo MÁS ALTO
-- alcanzado y se paga ese IMPORTE completo, sin prorratear. Si no llega al tramo
-- más bajo, el premio es 0.
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'BI_T_PREMIOS_ECOM_ESCALAS')
BEGIN
    CREATE TABLE BI_T_PREMIOS_ECOM_ESCALAS (
        ID                  INT IDENTITY(1,1) NOT NULL,
        ID_CONCEPTO         INT           NOT NULL,
        UMBRAL              DECIMAL(9,4)  NOT NULL,
        IMPORTE             DECIMAL(18,2) NOT NULL,
        ACTUALIZADO_POR     VARCHAR(100)  NULL,
        FECHA_ACTUALIZACION DATETIME      NULL,
        CONSTRAINT PK_BI_T_PREMIOS_ECOM_ESCALAS PRIMARY KEY (ID),
        CONSTRAINT FK_ECOM_ESCALAS_CONCEPTO FOREIGN KEY (ID_CONCEPTO)
            REFERENCES BI_T_PREMIOS_ECOM_CONCEPTOS (ID) ON DELETE CASCADE,
        CONSTRAINT UQ_BI_T_PREMIOS_ECOM_ESCALAS UNIQUE (ID_CONCEPTO, UMBRAL)
    );
END
GO

-- ── 4) Carga manual mensual de KPIs ────────────────────────────────────
-- MES = último día del mes, mismo criterio que BI_T_PREMIOS_CONTROL.MES y que
-- FECHA en BI_T_ESTADISTICAS_VENTAS_PROPIOS.
--
-- Acá van SOLO los dos datos que no existen en ningún sistema propio:
--   TASA_CONVERSION  → se toma del panel de VTEX. En PUNTOS DE PORCENTAJE:
--                      0.8300 = 0,83 %.
--   OBJETIVO_ORDENES → no está en FP_ObjetivosFinales, que solo tiene importes.
--                      Debe fijarse sobre la misma base que el real (ver nota abajo).
--
-- Las ÓRDENES REALES no se cargan acá: salen de BI_EFICIENCIA_ECOMMERCE, la misma
-- tabla del dashboard del sector Ecommerce (pedidos distintos no cancelados, por
-- FECHA_PEDIDO). Hasta 2026-10-01 salían de RO_T_ESTADO_PEDIDOS_ECOMMERCE (Tango),
-- que contaba menos. El OBJETIVO_ORDENES tiene que estar fijado sobre la misma
-- base que el real para que el % de cumplimiento signifique algo.
--
-- SESIONES es opcional: sirve para ponderar la tasa cuando el período abarca
-- varios meses (promedio ponderado por tráfico en vez de promedio simple).
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'BI_T_PREMIOS_ECOM_KPIS')
BEGIN
    CREATE TABLE BI_T_PREMIOS_ECOM_KPIS (
        MES                 DATE          NOT NULL,
        CANAL               VARCHAR(10)   NOT NULL,   -- 'VTEX' | 'ML'
        SESIONES            INT           NULL,
        TASA_CONVERSION     DECIMAL(9,4)  NULL,
        OBJETIVO_ORDENES    DECIMAL(18,4) NULL,
        ACTUALIZADO_POR     VARCHAR(100)  NULL,
        FECHA_ACTUALIZACION DATETIME      NULL,
        CONSTRAINT PK_BI_T_PREMIOS_ECOM_KPIS PRIMARY KEY (MES, CANAL)
    );
END
GO

-- Migración: la primera versión de este script tenía una columna ORDENES de carga
-- manual. Las órdenes ahora salen solas (BI_EFICIENCIA_ECOMMERCE), así que esa columna quedó sin uso. Si
-- se corrió esa versión, se elimina; en una instalación nueva este bloque no hace
-- nada (la tabla ya se creó sin la columna).
IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_NAME = 'BI_T_PREMIOS_ECOM_KPIS' AND COLUMN_NAME = 'ORDENES')
BEGIN
    ALTER TABLE BI_T_PREMIOS_ECOM_KPIS DROP COLUMN ORDENES;
END
GO

-- ═══════════════════════════════════════════════════════════════════════
-- SEED — las dos personas del área y sus PAUTAS, tal como están en el Excel
-- que este tablero reemplaza. Todo con WHERE NOT EXISTS para poder re-correr.
--
-- Las personas nuevas se dan de alta desde comercial/supervision/premios/gestionarPremios.php,
-- eligiendo su PUESTO (ver la sección PUESTOS al final). Los tramos se editan en
-- «Ecommerce · Escalas» de esa misma página. El dashboard solo los lee.
-- ═══════════════════════════════════════════════════════════════════════

INSERT INTO BI_T_PREMIOS_ECOM_PERSONAS (NOMBRE, ORDEN)
SELECT v.NOMBRE, v.ORDEN
FROM (VALUES ('Agustina', 1), ('Vanesa Di Feo', 2)) AS v(NOMBRE, ORDEN)
WHERE NOT EXISTS (
    SELECT 1 FROM BI_T_PREMIOS_ECOM_PERSONAS p WHERE p.NOMBRE = v.NOMBRE
);
GO

-- Conceptos. Notar que Vanesa tiene DOS de facturación (una por canal): como el
-- UNIQUE es (ID_PERSONA, ETIQUETA), la etiqueta lleva el canal — la UI igual
-- muestra CANAL en su propia columna.
INSERT INTO BI_T_PREMIOS_ECOM_CONCEPTOS
    (ID_PERSONA, CANAL, ETIQUETA, METRICA, TIPO_UMBRAL, ORIGEN_REAL, ORIGEN_OBJETIVO, ORDEN)
SELECT p.ID, v.CANAL, v.ETIQUETA, v.METRICA, v.TIPO_UMBRAL, v.ORIGEN_REAL, v.ORIGEN_OBJETIVO, v.ORDEN
FROM (VALUES
    -- Agustina: la facturación es el AGREGADO de los dos canales (obj VTEX + obj ML).
    ('Agustina',      NULL,   'FACT E COMM',      'FACTURACION',     'PCT_CUMPLIMIENTO', 'FACT_VTEX_ML', 'OBJ_VTEX_ML',  1),
    ('Agustina',      'VTEX', 'T.CONV vtex',      'TASA_CONVERSION', 'VALOR_ABSOLUTO',   'CONV_VTEX',    'NINGUNO',      2),
    -- Vanesa: los cuatro conceptos separados por canal.
    ('Vanesa Di Feo', 'VTEX', 'FACTURACION VTEX', 'FACTURACION',     'PCT_CUMPLIMIENTO', 'FACT_VTEX',    'OBJ_VTEX',     1),
    ('Vanesa Di Feo', 'VTEX', 'ORDENES (OC)',     'ORDENES',         'PCT_CUMPLIMIENTO', 'ORD_VTEX',     'OBJ_ORD_VTEX', 2),
    ('Vanesa Di Feo', 'VTEX', 'TASA CONVERSION',  'TASA_CONVERSION', 'VALOR_ABSOLUTO',   'CONV_VTEX',    'NINGUNO',      3),
    ('Vanesa Di Feo', 'ML',   'FACTURACION ML',   'FACTURACION',     'PCT_CUMPLIMIENTO', 'FACT_ML',      'OBJ_ML',       4)
) AS v(PERSONA, CANAL, ETIQUETA, METRICA, TIPO_UMBRAL, ORIGEN_REAL, ORIGEN_OBJETIVO, ORDEN)
INNER JOIN BI_T_PREMIOS_ECOM_PERSONAS p ON p.NOMBRE = v.PERSONA
WHERE NOT EXISTS (
    SELECT 1 FROM BI_T_PREMIOS_ECOM_CONCEPTOS c
    WHERE c.ID_PERSONA = p.ID AND c.ETIQUETA = v.ETIQUETA
);
GO

-- Escalas (las "PAUTAS" del Excel).
INSERT INTO BI_T_PREMIOS_ECOM_ESCALAS (ID_CONCEPTO, UMBRAL, IMPORTE, ACTUALIZADO_POR, FECHA_ACTUALIZACION)
SELECT c.ID, v.UMBRAL, v.IMPORTE, 'seed', GETDATE()
FROM (VALUES
    -- ── Agustina ──
    -- ECOMM OBJ FACT: 100 % / 95 % / 90 %
    ('Agustina',      'FACT E COMM',      1.0000, 115000),
    ('Agustina',      'FACT E COMM',      0.9500,  85000),
    ('Agustina',      'FACT E COMM',      0.9000,  55000),
    -- ECOMM OBJ CONV: tasa de conversión en puntos de %, absoluta (no vs objetivo)
    ('Agustina',      'T.CONV vtex',      0.9000, 100000),
    ('Agustina',      'T.CONV vtex',      0.7000,  80000),
    ('Agustina',      'T.CONV vtex',      0.6000,  65000),
    ('Agustina',      'T.CONV vtex',      0.5000,  55000),
    -- ── Vanesa Di Feo ──
    -- VTEX OBJ FACTURACION: 100 % / 90 %
    ('Vanesa Di Feo', 'FACTURACION VTEX', 1.0000, 250000),
    ('Vanesa Di Feo', 'FACTURACION VTEX', 0.9000, 200000),
    -- VTEX OBJ PEDIDOS: objetivo del mes / 10 % sobre objetivo / 11 % o mayor
    ('Vanesa Di Feo', 'ORDENES (OC)',     1.1100, 300000),
    ('Vanesa Di Feo', 'ORDENES (OC)',     1.1000, 280000),
    ('Vanesa Di Feo', 'ORDENES (OC)',     1.0000, 250000),
    -- VTEX TASA CONVERSION: absoluta, en puntos de %
    ('Vanesa Di Feo', 'TASA CONVERSION',  1.0000, 280000),
    ('Vanesa Di Feo', 'TASA CONVERSION',  0.9000, 260000),
    ('Vanesa Di Feo', 'TASA CONVERSION',  0.8000, 240000),
    ('Vanesa Di Feo', 'TASA CONVERSION',  0.7000, 220000),
    ('Vanesa Di Feo', 'TASA CONVERSION',  0.6000, 200000),
    -- MERCADO LIBRE OBJ FACT: 100 % / 90 %
    ('Vanesa Di Feo', 'FACTURACION ML',   1.0000, 250000),
    ('Vanesa Di Feo', 'FACTURACION ML',   0.9000, 200000)
) AS v(PERSONA, ETIQUETA, UMBRAL, IMPORTE)
INNER JOIN BI_T_PREMIOS_ECOM_PERSONAS p ON p.NOMBRE = v.PERSONA
INNER JOIN BI_T_PREMIOS_ECOM_CONCEPTOS c ON c.ID_PERSONA = p.ID AND c.ETIQUETA = v.ETIQUETA
WHERE NOT EXISTS (
    SELECT 1 FROM BI_T_PREMIOS_ECOM_ESCALAS e
    WHERE e.ID_CONCEPTO = c.ID AND e.UMBRAL = v.UMBRAL
);
GO

-- KPIs manuales de julio 2026, tomados de la captura del Excel — sirven para
-- validar el cálculo end-to-end (ver la tabla de verificación del README).
-- ML no lleva fila: no se le mide ni conversión ni objetivo de órdenes.
--
-- OJO con el 3491: es el objetivo tal como estaba en la planilla, fijado contra el
-- conteo del panel de VTEX, no contra los pedidos de BI_EFICIENCIA_ECOMMERCE que
-- usa el tablero — está pendiente que el área confirme que vale para esa base.
INSERT INTO BI_T_PREMIOS_ECOM_KPIS
    (MES, CANAL, SESIONES, TASA_CONVERSION, OBJETIVO_ORDENES, ACTUALIZADO_POR, FECHA_ACTUALIZACION)
SELECT '2026-07-31', 'VTEX', NULL, 0.8300, 3491, 'seed', GETDATE()
WHERE NOT EXISTS (
    SELECT 1 FROM BI_T_PREMIOS_ECOM_KPIS k WHERE k.MES = '2026-07-31' AND k.CANAL = 'VTEX'
);
GO

-- ═══════════════════════════════════════════════════════════════════════
-- OBJETIVOS DE ÓRDENES 2026 — de la planilla anual del área, los 12 meses de
-- los dos canales. Evita tener que tipearlos uno por uno en el modal y hace que
-- los períodos largos ("Año actual") calculen bien desde el arranque.
--
-- A Mercado Libre hoy no se le mide ningún concepto de órdenes (Vanesa solo cobra
-- facturación en ese canal), pero los objetivos existen en la planilla y la tabla
-- los soporta, así que se cargan igual.
--
-- El objetivo DIARIO que trae la planilla no se guarda: es exactamente el mensual
-- dividido por los días del mes (verificado en los 24 casos), así que es derivable.
--
-- ⚠ Estos objetivos están fijados contra el conteo de órdenes del PANEL DE VTEX.
-- El tablero mide el real con los pedidos de BI_EFICIENCIA_ECOMMERCE (la tabla del
-- dashboard del área), no con el panel. Ver README.
--
-- Idempotente y NO destructivo: si una fila ya tiene OBJETIVO_ORDENES cargado
-- (porque alguien lo editó desde el modal), no se pisa.
-- ═══════════════════════════════════════════════════════════════════════
;WITH OBJ (MES, CANAL, OBJETIVO_ORDENES) AS (
    SELECT CAST(v.MES AS DATE), v.CANAL, v.OBJ
    FROM (VALUES
        -- VTEX
        ('2026-01-31', 'VTEX', 2579), ('2026-02-28', 'VTEX', 2297),
        ('2026-03-31', 'VTEX', 2585), ('2026-04-30', 'VTEX', 2182),
        ('2026-05-31', 'VTEX', 4743), ('2026-06-30', 'VTEX', 2618),
        ('2026-07-31', 'VTEX', 3491), ('2026-08-31', 'VTEX', 2755),
        ('2026-09-30', 'VTEX', 3055), ('2026-10-31', 'VTEX', 5123),
        ('2026-11-30', 'VTEX', 4362), ('2026-12-31', 'VTEX', 4398),
        -- Mercado Libre
        ('2026-01-31', 'ML',   1006), ('2026-02-28', 'ML',    588),
        ('2026-03-31', 'ML',    600), ('2026-04-30', 'ML',    600),
        ('2026-05-31', 'ML',   1003), ('2026-06-30', 'ML',    642),
        ('2026-07-31', 'ML',    920), ('2026-08-31', 'ML',    714),
        ('2026-09-30', 'ML',    899), ('2026-10-31', 'ML',   1296),
        ('2026-11-30', 'ML',   1014), ('2026-12-31', 'ML',   1993)
    ) AS v(MES, CANAL, OBJ)
)
MERGE BI_T_PREMIOS_ECOM_KPIS AS t
USING OBJ AS s ON t.MES = s.MES AND t.CANAL = s.CANAL
WHEN MATCHED AND t.OBJETIVO_ORDENES IS NULL THEN
    UPDATE SET OBJETIVO_ORDENES = s.OBJETIVO_ORDENES,
               ACTUALIZADO_POR = 'seed-objetivos-2026', FECHA_ACTUALIZACION = GETDATE()
WHEN NOT MATCHED THEN
    INSERT (MES, CANAL, OBJETIVO_ORDENES, ACTUALIZADO_POR, FECHA_ACTUALIZACION)
    VALUES (s.MES, s.CANAL, s.OBJETIVO_ORDENES, 'seed-objetivos-2026', GETDATE());
GO

-- ═══════════════════════════════════════════════════════════════════════
-- PUESTOS — plantilla de "qué se le mide" a cada persona según su puesto.
-- Agustina (Analista) y Vanesa (Jefatura) cobran conceptos distintos por el puesto
-- que ocupan: al dar de alta a alguien desde
-- comercial/supervision/premios/gestionarPremios.php se elige el puesto y se le COPIAN sus conceptos y
-- tramos a CONCEPTOS/ESCALAS. La liquidación sigue leyendo los conceptos propios de
-- cada persona: el puesto es solo el molde del alta — editar la plantilla no toca a
-- las personas ya dadas de alta (sus tramos se ajustan en «Ecommerce · Escalas»).
-- ═══════════════════════════════════════════════════════════════════════
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'BI_T_PREMIOS_ECOM_PUESTOS')
BEGIN
    CREATE TABLE BI_T_PREMIOS_ECOM_PUESTOS (
        ID     INT IDENTITY(1,1) NOT NULL,
        NOMBRE VARCHAR(100) NOT NULL,
        ORDEN  INT          NOT NULL DEFAULT 1,
        ACTIVO BIT          NOT NULL DEFAULT 1,
        CONSTRAINT PK_BI_T_PREMIOS_ECOM_PUESTOS PRIMARY KEY (ID),
        CONSTRAINT UQ_BI_T_PREMIOS_ECOM_PUESTOS UNIQUE (NOMBRE)
    );
END
GO

-- Mismas columnas (y mismos CHECK) que BI_T_PREMIOS_ECOM_CONCEPTOS.
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'BI_T_PREMIOS_ECOM_PUESTO_CONCEPTOS')
BEGIN
    CREATE TABLE BI_T_PREMIOS_ECOM_PUESTO_CONCEPTOS (
        ID              INT IDENTITY(1,1) NOT NULL,
        ID_PUESTO       INT          NOT NULL,
        CANAL           VARCHAR(10)  NULL,
        ETIQUETA        VARCHAR(80)  NOT NULL,
        METRICA         VARCHAR(30)  NOT NULL,
        TIPO_UMBRAL     VARCHAR(20)  NOT NULL,
        ORIGEN_REAL     VARCHAR(30)  NOT NULL,
        ORIGEN_OBJETIVO VARCHAR(30)  NOT NULL,
        ORDEN           INT          NOT NULL DEFAULT 1,
        CONSTRAINT PK_BI_T_PREMIOS_ECOM_PUESTO_CONCEPTOS PRIMARY KEY (ID),
        CONSTRAINT FK_ECOM_PUESTO_CONCEPTOS_PUESTO FOREIGN KEY (ID_PUESTO)
            REFERENCES BI_T_PREMIOS_ECOM_PUESTOS (ID),
        CONSTRAINT UQ_BI_T_PREMIOS_ECOM_PUESTO_CONCEPTOS UNIQUE (ID_PUESTO, ETIQUETA),
        CONSTRAINT CK_ECOM_PUESTO_CONCEPTOS_TIPO_UMBRAL CHECK (TIPO_UMBRAL IN ('PCT_CUMPLIMIENTO', 'VALOR_ABSOLUTO')),
        CONSTRAINT CK_ECOM_PUESTO_CONCEPTOS_METRICA CHECK (METRICA IN ('FACTURACION', 'ORDENES', 'TASA_CONVERSION'))
    );
END
GO

IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'BI_T_PREMIOS_ECOM_PUESTO_ESCALAS')
BEGIN
    CREATE TABLE BI_T_PREMIOS_ECOM_PUESTO_ESCALAS (
        ID                  INT IDENTITY(1,1) NOT NULL,
        ID_PUESTO_CONCEPTO  INT           NOT NULL,
        UMBRAL              DECIMAL(9,4)  NOT NULL,
        IMPORTE             DECIMAL(18,2) NOT NULL,
        CONSTRAINT PK_BI_T_PREMIOS_ECOM_PUESTO_ESCALAS PRIMARY KEY (ID),
        CONSTRAINT FK_ECOM_PUESTO_ESCALAS_CONCEPTO FOREIGN KEY (ID_PUESTO_CONCEPTO)
            REFERENCES BI_T_PREMIOS_ECOM_PUESTO_CONCEPTOS (ID) ON DELETE CASCADE,
        CONSTRAINT UQ_BI_T_PREMIOS_ECOM_PUESTO_ESCALAS UNIQUE (ID_PUESTO_CONCEPTO, UMBRAL)
    );
END
GO

-- Puesto de cada persona. NULL = persona cargada antes de que existieran los puestos.
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_NAME = 'BI_T_PREMIOS_ECOM_PERSONAS' AND COLUMN_NAME = 'ID_PUESTO')
BEGIN
    ALTER TABLE BI_T_PREMIOS_ECOM_PERSONAS ADD ID_PUESTO INT NULL
        CONSTRAINT FK_ECOM_PERSONAS_PUESTO REFERENCES BI_T_PREMIOS_ECOM_PUESTOS (ID);
END
GO

-- Seed de puestos: la plantilla se COPIA de los conceptos y tramos vigentes de la
-- persona que hoy ocupa cada puesto, para que las pautas queden idénticas a lo que
-- ya se liquida y no haya que mantenerlas dos veces en este script.
INSERT INTO BI_T_PREMIOS_ECOM_PUESTOS (NOMBRE, ORDEN)
SELECT v.NOMBRE, v.ORDEN
FROM (VALUES ('Analista Ecommerce', 1), ('Jefatura Ecommerce', 2)) AS v(NOMBRE, ORDEN)
WHERE NOT EXISTS (SELECT 1 FROM BI_T_PREMIOS_ECOM_PUESTOS p WHERE p.NOMBRE = v.NOMBRE);
GO

;WITH MOLDE (PUESTO, PERSONA) AS (
    SELECT 'Analista Ecommerce', 'Agustina' UNION ALL
    SELECT 'Jefatura Ecommerce', 'Vanesa Di Feo'
)
INSERT INTO BI_T_PREMIOS_ECOM_PUESTO_CONCEPTOS
    (ID_PUESTO, CANAL, ETIQUETA, METRICA, TIPO_UMBRAL, ORIGEN_REAL, ORIGEN_OBJETIVO, ORDEN)
SELECT pu.ID, c.CANAL, c.ETIQUETA, c.METRICA, c.TIPO_UMBRAL, c.ORIGEN_REAL, c.ORIGEN_OBJETIVO, c.ORDEN
FROM MOLDE m
INNER JOIN BI_T_PREMIOS_ECOM_PUESTOS pu ON pu.NOMBRE = m.PUESTO
INNER JOIN BI_T_PREMIOS_ECOM_PERSONAS pe ON pe.NOMBRE = m.PERSONA
INNER JOIN BI_T_PREMIOS_ECOM_CONCEPTOS c ON c.ID_PERSONA = pe.ID AND c.ACTIVO = 1
WHERE NOT EXISTS (
    SELECT 1 FROM BI_T_PREMIOS_ECOM_PUESTO_CONCEPTOS x
    WHERE x.ID_PUESTO = pu.ID AND x.ETIQUETA = c.ETIQUETA
);
GO

;WITH MOLDE (PUESTO, PERSONA) AS (
    SELECT 'Analista Ecommerce', 'Agustina' UNION ALL
    SELECT 'Jefatura Ecommerce', 'Vanesa Di Feo'
)
INSERT INTO BI_T_PREMIOS_ECOM_PUESTO_ESCALAS (ID_PUESTO_CONCEPTO, UMBRAL, IMPORTE)
SELECT pc.ID, e.UMBRAL, e.IMPORTE
FROM MOLDE m
INNER JOIN BI_T_PREMIOS_ECOM_PUESTOS pu ON pu.NOMBRE = m.PUESTO
INNER JOIN BI_T_PREMIOS_ECOM_PUESTO_CONCEPTOS pc ON pc.ID_PUESTO = pu.ID
INNER JOIN BI_T_PREMIOS_ECOM_PERSONAS pe ON pe.NOMBRE = m.PERSONA
INNER JOIN BI_T_PREMIOS_ECOM_CONCEPTOS c ON c.ID_PERSONA = pe.ID AND c.ETIQUETA = pc.ETIQUETA
INNER JOIN BI_T_PREMIOS_ECOM_ESCALAS e ON e.ID_CONCEPTO = c.ID
WHERE NOT EXISTS (
    SELECT 1 FROM BI_T_PREMIOS_ECOM_PUESTO_ESCALAS x
    WHERE x.ID_PUESTO_CONCEPTO = pc.ID AND x.UMBRAL = e.UMBRAL
);
GO

UPDATE pe SET ID_PUESTO = pu.ID
FROM BI_T_PREMIOS_ECOM_PERSONAS pe
INNER JOIN (VALUES ('Agustina', 'Analista Ecommerce'), ('Vanesa Di Feo', 'Jefatura Ecommerce')) AS v(PERSONA, PUESTO)
        ON v.PERSONA = pe.NOMBRE
INNER JOIN BI_T_PREMIOS_ECOM_PUESTOS pu ON pu.NOMBRE = v.PUESTO
WHERE pe.ID_PUESTO IS NULL;
GO
