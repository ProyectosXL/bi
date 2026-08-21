-- ═══════════════════════════════════════════════════════════════════════
-- Setup único para: estado "Controlado" + envío de mails de Premios Comercial.
-- Correr cada bloque en el servidor/base que corresponde (están indicados).
-- ═══════════════════════════════════════════════════════════════════════

-- ── Bloque 1: POWER_BI_CONTROL (host_apps / conexión 'power' de bi/class/Conexion.php) ──
-- Estado "Controlado" por mes + supervisora.
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'BI_T_PREMIOS_CONTROL')
BEGIN
    CREATE TABLE BI_T_PREMIOS_CONTROL (
        MES           DATE         NOT NULL,   -- último día del mes, mismo criterio que FECHA en BI_T_ESTADISTICAS_VENTAS_PROPIOS
        SUPERVISORA   VARCHAR(100) NOT NULL,    -- nombre formateado (Title Case), igual que PremiosDB::formatearNombre()
        CONTROLADO    BIT          NOT NULL DEFAULT 0,
        USUARIO       VARCHAR(100) NULL,
        FECHA_CONTROL DATETIME     NULL,
        CONSTRAINT PK_BI_T_PREMIOS_CONTROL PRIMARY KEY (MES, SUPERVISORA)
    );
END

-- DEPRECADA: mapeo supervisora -> email, pensada originalmente para editarse desde un
-- modal del dashboard. Ya no se usa: el mail ahora se lee directo de
-- RO_T_SUPERVISORAS_COMERCIAL.MAIL (columna agregada por el usuario en esa tabla, en
-- XL-LAKERBIS). Se deja el CREATE por si ya se corrió en algún ambiente y quedaron datos
-- que se quieran conservar — no hace falta correr este bloque en un setup nuevo.
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'BI_T_PREMIOS_SUPERVISORAS_EMAIL')
BEGIN
    CREATE TABLE BI_T_PREMIOS_SUPERVISORAS_EMAIL (
        SUPERVISORA          VARCHAR(100) NOT NULL PRIMARY KEY,
        EMAIL                VARCHAR(200) NOT NULL,
        ACTUALIZADO_POR      VARCHAR(100) NULL,
        FECHA_ACTUALIZACION  DATETIME     NULL
    );
END

-- Orden de despliegue Y visibilidad de supervisoras (tablas y cards), editable desde el
-- modal "Configuración de supervisoras" del dashboard (arrastrar para reordenar, tilde
-- para mostrar/ocultar). Reemplaza tanto la vieja constante ORDEN_SUPERVISORAS como
-- SUPERVISORAS_EXCLUIDAS de PremiosDB.php.
IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'BI_T_PREMIOS_SUPERVISORAS_ORDEN')
BEGIN
    CREATE TABLE BI_T_PREMIOS_SUPERVISORAS_ORDEN (
        SUPERVISORA          VARCHAR(100) NOT NULL PRIMARY KEY,
        ORDEN                INT          NOT NULL,
        VISIBLE              BIT          NOT NULL DEFAULT 1,
        ACTUALIZADO_POR      VARCHAR(100) NULL,
        FECHA_ACTUALIZACION  DATETIME     NULL
    );

    -- Seed: mismo orden que ya pedía Johanna (la constante ORDEN_SUPERVISORAS eliminada
    -- del código). Las supervisoras activas que no aparezcan acá se muestran al final,
    -- en el orden del catálogo (RO_T_SUPERVISORAS_COMERCIAL.ID) — ver PremiosDB::getSupervisoras().
    INSERT INTO BI_T_PREMIOS_SUPERVISORAS_ORDEN (SUPERVISORA, ORDEN) VALUES
    ('Natalia Bontempo', 1),
    ('Elina Costamagna', 2),
    ('Carolina Commendatore', 3),
    ('Sonia Pacifico', 4),
    ('Nahir Actis', 5),
    ('Josefina Pastorino', 6);
END
GO

-- Si la tabla ya existía de un setup anterior (sin la columna VISIBLE), se agrega ahora.
-- El GO de arriba y de abajo son necesarios: si el INSERT de Julieta (que referencia
-- VISIBLE) estuviera en el mismo batch que este ALTER, SQL Server valida los nombres de
-- columna de TODO el batch antes de ejecutar nada — y como la columna todavía no existe
-- al momento de compilar el batch, tira "Invalid column name 'VISIBLE'" aunque el ALTER
-- la hubiera creado un instante después.
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'BI_T_PREMIOS_SUPERVISORAS_ORDEN' AND COLUMN_NAME = 'VISIBLE')
BEGIN
    ALTER TABLE BI_T_PREMIOS_SUPERVISORAS_ORDEN ADD VISIBLE BIT NOT NULL DEFAULT 1;
END
GO

-- Julieta Dalmeida: sigue activa en RO_T_SUPERVISORAS_COMERCIAL (vigente en el resto de
-- los sistemas) pero no debe mostrarse en ESTE dashboard — reemplaza la vieja constante
-- SUPERVISORAS_EXCLUIDAS hardcodeada en PremiosDB.php. Se puede volver a mostrar en
-- cualquier momento desde "Configuración de supervisoras", sin tocar código.
IF NOT EXISTS (SELECT 1 FROM BI_T_PREMIOS_SUPERVISORAS_ORDEN WHERE SUPERVISORA = 'Julieta Dalmeida')
BEGIN
    INSERT INTO BI_T_PREMIOS_SUPERVISORAS_ORDEN (SUPERVISORA, ORDEN, VISIBLE) VALUES ('Julieta Dalmeida', 999, 0);
END
GO

-- ── Bloque 2: base 'central' (host_central / conexión 'central', misma base que usa
--    sistemas/adminNotificaciones) — alta del resumen mensual como TIPO_NOTIFICACION.
--    Reemplazá los 3 emails de ejemplo por los reales antes de correr este bloque.
IF NOT EXISTS (SELECT * FROM RO_T_CONFIGURACION_MAIL WHERE TIPO_NOTIFICACION = 'PREMIOS_RESUMEN_MENSUAL')
BEGIN
    INSERT INTO RO_T_CONFIGURACION_MAIL (TIPO_NOTIFICACION, PROFILE_NAME, EMAIL_SUBJECT)
    VALUES ('PREMIOS_RESUMEN_MENSUAL', 'sistemas', 'Resumen mensual de Premios Comercial');
END

INSERT INTO RO_T_DESTINATARIOS_MAIL (TIPO_NOTIFICACION, EMAIL, ES_COPIA, ACTIVO)
SELECT 'PREMIOS_RESUMEN_MENSUAL', v.EMAIL, 0, 1
FROM (VALUES
    ('natalia.fuentes@xl.com.ar'),   -- TODO: confirmar/reemplazar
    ('juanignacio@xl.com.ar'),       -- TODO: confirmar/reemplazar
    ('johanna@xl.com.ar')            -- TODO: confirmar/reemplazar
) AS v(EMAIL)
WHERE NOT EXISTS (
    SELECT 1 FROM RO_T_DESTINATARIOS_MAIL d
    WHERE d.TIPO_NOTIFICACION = 'PREMIOS_RESUMEN_MENSUAL' AND d.EMAIL = v.EMAIL
);

-- A futuro, esos 3 destinatarios se editan desde el panel de adminNotificaciones
-- (sistemas/adminNotificaciones) sin tocar código ni volver a correr este script.