/* ============================================================================
   KPIs INNOVACION - PERMISO DE EDICION EN GESTIONUSUARIOS
   ----------------------------------------------------------------------------
   Base   : apps / sistemas (la de Gestionusuarios: FP_MODULOS, FP_PERMISOS,
            FP_ROL_PERMISO)
   Orden  : 1 de 2. Independiente de kpis_ahorros.sql (misma base, otras tablas).
   ----------------------------------------------------------------------------
   QUE HACE

   Da de alta la clave kpis.editar en FP_PERMISOS, colgada del MISMO modulo
   que la clave de lectura que ya existe:

       dei.item.control_de_gestion_y_metricas_kpis_innovacion  (lectura, ya existe)
       kpis.editar                                             (edicion, nueva)

   El modulo_id se resuelve por la clave de lectura: no hay ningun id escrito.
   El PHP (kpis/class/AuthKpis.php) busca los permisos por estas dos claves.

   Si la clave de lectura no existe, lo informa y no crea nada.

   NO asigna kpis.editar a ningun rol. Hoy edita solo el administrador
   (sector Proyectos + es_admin / rol_id 1 / CONTROLTOTAL / FRANCOPERTUS), que
   entra a todo sin permisos asignados. El resto se asigna por rol desde
   Gestionusuarios (no existe asignacion por usuario).

   Reejecutable: no borra ni modifica nada existente.

   SI NO SE CORRE: el tablero funciona igual; editar solo lo puede el
   administrador, y no hay clave para asignarle edicion a otro rol.
   ========================================================================== */

SET NOCOUNT ON;
GO

DECLARE @CLAVE_LECTURA VARCHAR(100) = 'dei.item.control_de_gestion_y_metricas_kpis_innovacion';
DECLARE @CLAVE_EDICION VARCHAR(100) = 'kpis.editar';
DECLARE @MODULO INT;
DECLARE @N INT;

SELECT @N = COUNT(*), @MODULO = MIN(modulo_id)
FROM dbo.FP_PERMISOS
WHERE clave = @CLAVE_LECTURA;

IF @N = 0
BEGIN
    PRINT CONCAT('AVISO: no existe la clave de lectura ', @CLAVE_LECTURA,
                 '. No se crea nada: revisala en Gestionusuarios.');
END
ELSE IF @N > 1
BEGIN
    PRINT CONCAT('AVISO: la clave ', @CLAVE_LECTURA, ' existe ', @N,
                 ' veces. No se crea nada, para no adivinar el modulo.');
END
ELSE IF EXISTS (SELECT 1 FROM dbo.FP_PERMISOS WHERE clave = @CLAVE_EDICION)
BEGIN
    PRINT CONCAT('La clave ', @CLAVE_EDICION, ' ya existe: no se toca.');

    IF EXISTS (SELECT 1 FROM dbo.FP_PERMISOS WHERE clave = @CLAVE_EDICION AND modulo_id <> @MODULO)
        PRINT CONCAT('ATENCION: ', @CLAVE_EDICION, ' cuelga de un modulo distinto al de la clave de lectura (',
                     @MODULO, '). No se toca; revisala en Gestionusuarios.');
END
ELSE
BEGIN
    INSERT INTO dbo.FP_PERMISOS (modulo_id, clave, nombre, descripcion)
    VALUES (@MODULO, @CLAVE_EDICION,
            'Editar KPIs Innovación',
            '[Edición] Importar tickets, cargar ahorros y editar parámetros del tablero de KPIs de Innovación');

    PRINT CONCAT('Creada la clave ', @CLAVE_EDICION, ' en el modulo ', @MODULO, '. No se asigna a ningun rol.');
END
GO

/* ---- Control: que roles tienen hoy cada clave ---- */
SELECT p.clave, p.modulo_id, r.id AS rol_id, r.codigo AS rol_codigo, r.nombre AS rol_nombre
FROM dbo.FP_PERMISOS p
LEFT JOIN dbo.FP_ROL_PERMISO rp ON rp.permiso_id = p.id
LEFT JOIN dbo.FP_ROLES_PERMISOS_MAP r ON r.id = rp.rol_id
WHERE p.clave IN ('dei.item.control_de_gestion_y_metricas_kpis_innovacion', 'kpis.editar')
ORDER BY p.clave, r.codigo;
GO
