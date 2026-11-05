/* ============================================================================
   KPIs INNOVACION - AHORROS, TIPOS, PARAMETROS E HISTORIAL
   ----------------------------------------------------------------------------
   Base   : apps / sistemas (la misma de RO_T_TICKETS_PROYECTOS y FP_SECTORES)
   Orden  : 2 de 2. Independiente de kpis_permisos.sql.
   ----------------------------------------------------------------------------
   QUE HACE

   Crea, si no existen, las tablas del KPI "Ahorro / Beneficio Generado":

     RO_T_KPI_AHORRO_TIPOS          catalogo de tipos y su modo de calculo
     RO_T_KPI_AHORROS               un registro por ahorro
     RO_T_KPI_AHORROS_PROVEEDORES   proveedores de un ahorro BAJA_PROVEEDOR
     RO_T_KPI_PARAMETROS            parametros clave/valor del tablero
     RO_T_KPI_AHORROS_HISTORIAL     historial de cambios de ahorros y parametros

   y siembra el catalogo de tipos y los parametros (solo los que falten).

   AUDITORIA: toda tabla editable lleva USUARIO_ALTA/FECHA_ALTA,
   USUARIO_MODIF/FECHA_MODIF y USUARIO_BAJA/FECHA_BAJA. Se graba el username
   del padron (Gestionusuarios); las filas que siembra este script llevan
   'SISTEMA:kpis_ahorros.sql'. Las bajas son logicas (ACTIVO = 0).

   SIN FK HACIA AFUERA: SECTOR_ID apunta a FP_SECTORES.id (Gestionusuarios) y
   PROYECTO_ID a HistorialProyectos.ID (otro servidor). No llevan FK para no
   atar las tablas de Gestionusuarios ni depender de otro servidor; la API
   valida que existan.

   Reejecutable: todo va con IF NOT EXISTS. No borra ni modifica nada existente
   (los parametros ya cargados conservan su valor).
   ========================================================================== */

SET NOCOUNT ON;
GO

/* ---- 1. Catalogo de tipos ---------------------------------------------------
   MODO_CALCULO define que campos se cargan y como se calcula el monto:
     MONTO      se carga el monto (mensual o unico)
     HORAS      horas por mes x costo hora; el monto se calcula y se guarda
     PROVEEDOR  se calcula en cada consulta con lo facturado en Tango (CPA04)
   No se edita desde la pantalla en esta etapa. */
IF OBJECT_ID('dbo.RO_T_KPI_AHORRO_TIPOS', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_KPI_AHORRO_TIPOS
    (
        ID              INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RO_T_KPI_AHORRO_TIPOS PRIMARY KEY,
        CODIGO          VARCHAR(30)   NOT NULL CONSTRAINT UQ_RO_T_KPI_AHORRO_TIPOS_CODIGO UNIQUE,
        NOMBRE          NVARCHAR(100) NOT NULL,
        MODO_CALCULO    VARCHAR(10)   NOT NULL
            CONSTRAINT CK_RO_T_KPI_AHORRO_TIPOS_MODO CHECK (MODO_CALCULO IN ('PROVEEDOR', 'HORAS', 'MONTO')),
        ORDEN           INT           NOT NULL CONSTRAINT DF_RO_T_KPI_AHORRO_TIPOS_ORDEN DEFAULT (0),
        ACTIVO          BIT           NOT NULL CONSTRAINT DF_RO_T_KPI_AHORRO_TIPOS_ACTIVO DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NOT NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_KPI_AHORRO_TIPOS_FALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NULL,
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL
    );
    PRINT 'Creada RO_T_KPI_AHORRO_TIPOS.';
END
ELSE
    PRINT 'RO_T_KPI_AHORRO_TIPOS ya existe: no se toca.';
GO

DECLARE @TIPOS TABLE (CODIGO VARCHAR(30), NOMBRE NVARCHAR(100), MODO VARCHAR(10), ORDEN INT);
INSERT INTO @TIPOS VALUES
('BAJA_PROVEEDOR',     N'Baja de proveedor',                  'PROVEEDOR', 10),
('HORAS_LIBERADAS',    N'Horas liberadas',                    'HORAS',     20),
('COSTO_EVITADO',      N'Costo evitado',                      'MONTO',     30),
('RENEGOCIACION',      N'Renegociación / reducción de costo', 'MONTO',     40),
('INGRESO_HABILITADO', N'Ingreso habilitado',                 'MONTO',     50),
('OTRO',               N'Otro',                               'MONTO',     60);

INSERT INTO dbo.RO_T_KPI_AHORRO_TIPOS (CODIGO, NOMBRE, MODO_CALCULO, ORDEN, USUARIO_ALTA)
SELECT T.CODIGO, T.NOMBRE, T.MODO, T.ORDEN, 'SISTEMA:kpis_ahorros.sql'
FROM @TIPOS T
WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_KPI_AHORRO_TIPOS X WHERE X.CODIGO = T.CODIGO);

PRINT CONCAT('Tipos de ahorro: ', @@ROWCOUNT, ' nuevos.');
GO

/* ---- 2. Ahorros -------------------------------------------------------------
   MONTO: en modo MONTO es el monto cargado (mensual o unico segun
   PERIODICIDAD); en modo HORAS es HORAS_MES x COSTO_HORA, calculado y
   guardado; en modo PROVEEDOR queda NULL (se calcula en cada consulta).
   UNICO: el monto entero cae en el mes de FECHA_DESDE. */
IF OBJECT_ID('dbo.RO_T_KPI_AHORROS', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_KPI_AHORROS
    (
        ID              INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RO_T_KPI_AHORROS PRIMARY KEY,
        DESCRIPCION     NVARCHAR(300)  NOT NULL,
        TIPO_ID         INT            NOT NULL
            CONSTRAINT FK_RO_T_KPI_AHORROS_TIPO REFERENCES dbo.RO_T_KPI_AHORRO_TIPOS (ID),
        SECTOR_ID       INT            NOT NULL,   -- FP_SECTORES.id (sin FK, ver encabezado)
        PROYECTO_ID     INT            NULL,       -- HistorialProyectos.ID en central (sin FK)
        PERIODICIDAD    VARCHAR(10)    NOT NULL
            CONSTRAINT CK_RO_T_KPI_AHORROS_PERIODICIDAD CHECK (PERIODICIDAD IN ('MENSUAL', 'UNICO')),
        FECHA_DESDE     DATE           NOT NULL,
        FECHA_HASTA     DATE           NOT NULL,
        MONTO           DECIMAL(18,2)  NULL,
        HORAS_MES       DECIMAL(9,2)   NULL,
        COSTO_HORA      DECIMAL(18,2)  NULL,
        OBSERVACIONES   NVARCHAR(1000) NULL,
        ACTIVO          BIT            NOT NULL CONSTRAINT DF_RO_T_KPI_AHORROS_ACTIVO DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)    NOT NULL,
        FECHA_ALTA      DATETIME       NOT NULL CONSTRAINT DF_RO_T_KPI_AHORROS_FALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)    NULL,
        FECHA_MODIF     DATETIME       NULL,
        USUARIO_BAJA    VARCHAR(50)    NULL,
        FECHA_BAJA      DATETIME       NULL,
        CONSTRAINT CK_RO_T_KPI_AHORROS_FECHAS CHECK (FECHA_HASTA >= FECHA_DESDE)
    );
    CREATE INDEX IX_RO_T_KPI_AHORROS_VIGENCIA ON dbo.RO_T_KPI_AHORROS (ACTIVO, FECHA_DESDE, FECHA_HASTA);
    PRINT 'Creada RO_T_KPI_AHORROS.';
END
ELSE
    PRINT 'RO_T_KPI_AHORROS ya existe: no se toca.';
GO

/* ---- 3. Proveedores de un ahorro BAJA_PROVEEDOR -----------------------------
   Uno o mas por ahorro, con su codigo Tango (CPA01.COD_PROVEE) y la fecha de
   baja que se carga a mano (FECHA_BAJA_PROVEEDOR; FECHA_BAJA es la columna de
   auditoria de la baja logica del registro, como en las demas tablas). Un proveedor activo no se repite en el mismo
   ahorro (indice unico filtrado). */
IF OBJECT_ID('dbo.RO_T_KPI_AHORROS_PROVEEDORES', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_KPI_AHORROS_PROVEEDORES
    (
        ID              INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RO_T_KPI_AHORROS_PROVEEDORES PRIMARY KEY,
        AHORRO_ID       INT          NOT NULL
            CONSTRAINT FK_RO_T_KPI_AHORROS_PROVEEDORES_AHORRO REFERENCES dbo.RO_T_KPI_AHORROS (ID),
        COD_PROVEE      VARCHAR(20)  NOT NULL,
        FECHA_BAJA_PROVEEDOR DATE    NOT NULL,  -- la baja del proveedor (FECHA_BAJA es la del registro)
        ACTIVO          BIT          NOT NULL CONSTRAINT DF_RO_T_KPI_AHORROS_PROVEEDORES_ACTIVO DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)  NOT NULL,
        FECHA_ALTA      DATETIME     NOT NULL CONSTRAINT DF_RO_T_KPI_AHORROS_PROVEEDORES_FALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)  NULL,
        FECHA_MODIF     DATETIME     NULL,
        USUARIO_BAJA    VARCHAR(50)  NULL,
        FECHA_BAJA      DATETIME     NULL
    );
    CREATE UNIQUE INDEX UX_RO_T_KPI_AHORROS_PROVEEDORES_ACTIVO
        ON dbo.RO_T_KPI_AHORROS_PROVEEDORES (AHORRO_ID, COD_PROVEE) WHERE ACTIVO = 1;
    PRINT 'Creada RO_T_KPI_AHORROS_PROVEEDORES.';
END
ELSE
    PRINT 'RO_T_KPI_AHORROS_PROVEEDORES ya existe: no se toca.';
GO

/* ---- 4. Parametros del tablero ----------------------------------------------
   OBJETIVO_AHORRO_ANUAL y COSTO_HORA_DEFAULT se editan desde el modal
   Parametros (con motivo e historial). TANGO_TCOMP_EXCLUIDOS solo por SQL:
   tipos de comprobante de compras (CPA21.T_COMP) que no cuentan para el
   calculo de baja de proveedor, separados por coma. */
IF OBJECT_ID('dbo.RO_T_KPI_PARAMETROS', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_KPI_PARAMETROS
    (
        CLAVE           VARCHAR(50)   NOT NULL CONSTRAINT PK_RO_T_KPI_PARAMETROS PRIMARY KEY,
        VALOR           NVARCHAR(500) NOT NULL,
        DESCRIPCION     NVARCHAR(255) NULL,
        ACTIVO          BIT           NOT NULL CONSTRAINT DF_RO_T_KPI_PARAMETROS_ACTIVO DEFAULT (1),
        USUARIO_ALTA    VARCHAR(50)   NOT NULL,
        FECHA_ALTA      DATETIME      NOT NULL CONSTRAINT DF_RO_T_KPI_PARAMETROS_FALTA DEFAULT (GETDATE()),
        USUARIO_MODIF   VARCHAR(50)   NULL,
        FECHA_MODIF     DATETIME      NULL,
        USUARIO_BAJA    VARCHAR(50)   NULL,
        FECHA_BAJA      DATETIME      NULL
    );
    PRINT 'Creada RO_T_KPI_PARAMETROS.';
END
ELSE
    PRINT 'RO_T_KPI_PARAMETROS ya existe: no se toca.';
GO

DECLARE @PARAMS TABLE (CLAVE VARCHAR(50), VALOR NVARCHAR(500), DESCRIPCION NVARCHAR(255));
INSERT INTO @PARAMS VALUES
('OBJETIVO_AHORRO_ANUAL', N'0',
 N'Objetivo anual de ahorro en pesos. 0 = sin definir (el KPI no entra en el Cumplimiento General).'),
('COSTO_HORA_DEFAULT', N'0',
 N'Costo hora en pesos que se precarga en los ahorros de tipo Horas liberadas (editable en cada ahorro).'),
('TANGO_TCOMP_EXCLUIDOS', N'NCD,NDC,AJC,AJD,AJU',
 N'Tipos de comprobante de compras (CPA21.T_COMP) excluidos del cálculo de baja de proveedor: diferencias de cambio y ajustes contables.');

INSERT INTO dbo.RO_T_KPI_PARAMETROS (CLAVE, VALOR, DESCRIPCION, USUARIO_ALTA)
SELECT P.CLAVE, P.VALOR, P.DESCRIPCION, 'SISTEMA:kpis_ahorros.sql'
FROM @PARAMS P
WHERE NOT EXISTS (SELECT 1 FROM dbo.RO_T_KPI_PARAMETROS X WHERE X.CLAVE = P.CLAVE);

PRINT CONCAT('Parametros: ', @@ROWCOUNT, ' nuevos.');
GO

/* ---- 5. Historial de cambios ------------------------------------------------
   ENTIDAD_ID es el ID del ahorro o la CLAVE del parametro. VALOR_ANTERIOR y
   VALOR_NUEVO van en JSON; en el ALTA de un ahorro BAJA_PROVEEDOR,
   VALOR_NUEVO incluye un snapshot informativo del calculo por proveedor.
   El motivo es obligatorio salvo en ALTA. No lleva columnas de auditoria
   propias: ES la auditoria, y no se edita. */
IF OBJECT_ID('dbo.RO_T_KPI_AHORROS_HISTORIAL', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.RO_T_KPI_AHORROS_HISTORIAL
    (
        ID              INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_RO_T_KPI_AHORROS_HISTORIAL PRIMARY KEY,
        ENTIDAD         VARCHAR(20)   NOT NULL
            CONSTRAINT CK_RO_T_KPI_AHORROS_HISTORIAL_ENTIDAD CHECK (ENTIDAD IN ('AHORRO', 'PARAMETRO')),
        ENTIDAD_ID      VARCHAR(50)   NOT NULL,
        ACCION          VARCHAR(10)   NOT NULL
            CONSTRAINT CK_RO_T_KPI_AHORROS_HISTORIAL_ACCION CHECK (ACCION IN ('ALTA', 'EDICION', 'BAJA')),
        VALOR_ANTERIOR  NVARCHAR(MAX) NULL,
        VALOR_NUEVO     NVARCHAR(MAX) NULL,
        MOTIVO          NVARCHAR(500) NULL,
        USUARIO         VARCHAR(50)   NOT NULL,
        FECHA           DATETIME      NOT NULL CONSTRAINT DF_RO_T_KPI_AHORROS_HISTORIAL_FECHA DEFAULT (GETDATE()),
        CONSTRAINT CK_RO_T_KPI_AHORROS_HISTORIAL_MOTIVO
            CHECK (ACCION = 'ALTA' OR LEN(LTRIM(RTRIM(ISNULL(MOTIVO, N'')))) > 0)
    );
    CREATE INDEX IX_RO_T_KPI_AHORROS_HISTORIAL_ENTIDAD
        ON dbo.RO_T_KPI_AHORROS_HISTORIAL (ENTIDAD, ENTIDAD_ID, FECHA);
    PRINT 'Creada RO_T_KPI_AHORROS_HISTORIAL.';
END
ELSE
    PRINT 'RO_T_KPI_AHORROS_HISTORIAL ya existe: no se toca.';
GO

PRINT 'Tablas de ahorros del tablero de KPIs listas.';
GO
