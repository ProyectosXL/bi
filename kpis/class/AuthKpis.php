<?php
/**
 * AuthKpis
 * Autenticación y permisos del Tablero de KPIs de Innovación (bi/kpis).
 *
 * Copia la lógica de finanzas/cashflow/Class/AuthCashflow.php (rama develop)
 * sin compartir código con ese repo: el padrón, la regla de administrador,
 * leer y editar como permisos separados, y un mapa único de acciones.
 */

/**
 * El padrón de usuarios vive en OTRO módulo de htdocs (Gestionusuarios), no en
 * este repo.
 *
 * El require va condicionado: un checkout de bi sin Gestionusuarios al lado es
 * un caso real, y un require_once directo mataría el pedido con un fatal.
 *
 * FALLA CERRADA: sin el padrón no hay usuario, así que puede() y puedeEditar()
 * devuelven false para todo, index.php muestra el aviso de iniciar sesión y la
 * API responde 401. Nadie entra de más; lo que se pierde es la aplicación, no
 * el control de acceso.
 */
define('AUTH_KPIS_PADRON', __DIR__ . '/../../../Gestionusuarios/config/Database.php');

if (file_exists(AUTH_KPIS_PADRON)) {
    require_once AUTH_KPIS_PADRON;
}

/**
 * Las dos negativas, separadas porque piden cosas distintas a quien las
 * recibe: sin usuario hay que volver a entrar (401); sin permiso hay que
 * pedirlo en Gestionusuarios (403). api/kpis.php es el único lugar que las
 * traduce a HTTP.
 */
class AuthKpisSinUsuario extends Exception {}

class AuthKpisSinPermiso extends Exception {}

class AuthKpis {

    /** Largo de las columnas USUARIO_* de las tablas del módulo (= FP_SOF_USUARIOS.username) */
    const LARGO_USUARIO = 50;

    /**
     * Permiso de LECTURA. Ya existía (módulo DEI): es la clave que hace
     * aparecer el tablero en el centralizador. Quien hoy lo ve ahí, lo sigue
     * viendo acá.
     */
    const CLAVE_LECTURA = 'dei.item.control_de_gestion_y_metricas_kpis_innovacion';

    /**
     * Permiso de EDICIÓN: importar tickets, cargar/editar/dar de baja ahorros y
     * editar parámetros. Lo da de alta sql/kpis_permisos.sql.
     */
    const CLAVE_EDICION = 'kpis.editar';

    /**
     * QUÉ EXIGE CADA ACCIÓN DE api/kpis.php. Es la ÚNICA fuente.
     *
     * Una acción que no está en ninguna de las dos listas se rechaza: así un
     * case nuevo del switch no puede quedar abierto por olvido.
     *
     * Las vistas previas que solo existen como paso de una escritura (por
     * ejemplo, el cálculo de proveedores antes de guardar un ahorro) van en
     * ESCRITURAS, igual que en el Cashflow: la pantalla no se las ofrece a
     * quien no puede guardar.
     */
    const ESCRITURAS = [
        'importar-tickets',
        // Ahorros: el cálculo previo de proveedores es el primer paso de guardar.
        'ahorro-preview-proveedores',
        'ahorro-guardar',
        'ahorro-baja',
        'parametros-guardar'
    ];

    /** Las acciones que solo leen. Ver ESCRITURAS. */
    const LECTURAS = [
        'kpi-resumen',
        'kpi-detalle',
        'tickets-usuarios-3m',
        'ahorros-listado',
        'ahorros-catalogos',
        'ahorros-historial',
        'buscar-proveedores',
        'buscar-proyectos'
    ];

    private static $inicializado = false;
    private static $usuario = null;
    private static $permisos = [];
    private static $esAdmin = false;

    public static function init() {
        if (self::$inicializado) {
            return;
        }

        // Sin el padrón no hay a quién preguntarle: se queda sin usuario.
        if (!class_exists('\GestionUsuarios\Config\Database')) {
            self::$inicializado = true;
            return;
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        // Las mismas variantes de sesión que lee AuthCashflow: así sigue
        // funcionando durante la migración de login.
        $username = $_SESSION['username'] ?? $_SESSION['usuario'] ?? $_SESSION['nodo_usuario_activo']
            ?? ($_SESSION['fp_auth_user']['username'] ?? null);

        if (!$username) {
            self::$inicializado = true;
            return;
        }

        try {
            $db = \GestionUsuarios\Config\Database::getInstance()->connectApps();
            if (!$db) {
                self::$inicializado = true;
                return;
            }

            $sql = "SELECT u.id, u.username, u.nombre_completo, u.rol_id, u.sector_id,
                           s.codigo AS sector_codigo, s.nombre AS sector_nombre,
                           r.nombre AS rol_nombre, r.codigo AS rol_codigo, r.es_admin
                    FROM FP_SOF_USUARIOS u
                    LEFT JOIN FP_SECTORES s ON s.id = u.sector_id
                    LEFT JOIN FP_ROLES_PERMISOS_MAP r ON r.id = u.rol_id
                    WHERE UPPER(TRIM(u.username)) = UPPER(TRIM(?)) AND u.activo = 1";

            $stmt = sqlsrv_query($db, $sql, [trim($username)]);
            if ($stmt && ($user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC))) {
                // Misma regla de administrador que el Cashflow, sin cambios.
                $esSectorProyectos = ((int)($user['sector_id'] ?? 0) === 7)
                    || (strtoupper(trim($user['sector_codigo'] ?? '')) === 'PROYECTOS')
                    || (stripos($user['sector_nombre'] ?? '', 'Proyectos') !== false);

                self::$esAdmin = $esSectorProyectos && (
                    !empty($user['es_admin'])
                    || (int)($user['es_admin'] ?? 0) === 1
                    || (int)($user['rol_id'] ?? 0) === 1
                    || strtoupper(trim($user['rol_codigo'] ?? '')) === 'CONTROLTOTAL'
                    || strtoupper(trim($user['username'] ?? '')) === 'FRANCOPERTUS'
                );

                self::$usuario = [
                    'id'            => $user['id'],
                    'username'      => $user['username'],
                    'nombre'        => $user['nombre_completo'],
                    'rol_id'        => $user['rol_id'],
                    'rol_nombre'    => $user['rol_nombre'] ?? 'Sin Rol Asignado',
                    'sector_id'     => $user['sector_id'],
                    'sector_nombre' => $user['sector_nombre'] ?? 'Sin Sector',
                    'es_admin'      => self::$esAdmin
                ];

                // Solo las dos claves del tablero, por clave: sin ningún
                // modulo_id escrito en el código.
                if (!empty($user['rol_id'])) {
                    $sqlP = "SELECT p.clave
                             FROM FP_ROL_PERMISO rp
                             JOIN FP_PERMISOS p ON p.id = rp.permiso_id
                             WHERE rp.rol_id = ? AND p.clave IN (?, ?)";
                    $stmtP = sqlsrv_query($db, $sqlP, [$user['rol_id'], self::CLAVE_LECTURA, self::CLAVE_EDICION]);
                    if ($stmtP) {
                        while ($rowP = sqlsrv_fetch_array($stmtP, SQLSRV_FETCH_ASSOC)) {
                            self::$permisos[$rowP['clave']] = true;
                        }
                    }
                }
            }
        } catch (\Throwable $t) {
            // Silencioso: ante un error del padrón queda sin usuario (falla cerrada).
            error_log('AuthKpis::init: ' . $t->getMessage());
        }

        self::$inicializado = true;
    }

    /**
     * Cierra la sesión para que el resto del pedido no la tenga bloqueada: el
     * tablero pide varias cosas en paralelo. Se llama DESPUÉS de autorizar.
     */
    public static function liberarSesion() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function usuario() {
        self::init();
        return self::$usuario;
    }

    public static function esAdmin() {
        self::init();
        return self::$esAdmin;
    }

    public static function estaAutenticado() {
        self::init();
        return self::$usuario !== null;
    }

    /** Si el usuario actual puede ver el tablero. */
    public static function puede() {
        self::init();
        return self::permiteVer(self::$permisos, self::$esAdmin);
    }

    /** Si el usuario actual puede editar (importar tickets, ahorros, parámetros). */
    public static function puedeEditar() {
        self::init();
        return self::permiteEditar(self::$permisos, self::$esAdmin);
    }

    /**
     * El atributo que index.php pone en <body> para que el JS y el CSS sepan
     * si mostrar los controles de edición.
     *
     * ES COMODIDAD, NO SEGURIDAD: lo que frena a quien no puede editar es el
     * 403 de la API, no este atributo.
     *
     * @return string ' data-puede-editar="1"' o ' data-puede-editar="0"'
     */
    public static function atributoEdicion() {
        return ' data-puede-editar="' . (self::puedeEditar() ? '1' : '0') . '"';
    }

    /** Regla pura de lectura. */
    public static function permiteVer(array $permisos, $esAdmin) {
        if ($esAdmin) {
            return true;
        }
        return isset($permisos[self::CLAVE_LECTURA]);
    }

    /**
     * Regla pura de edición.
     *
     * EDITAR IMPLICA LEER, igual que en el Cashflow: sin la clave de lectura se
     * niega aunque el rol tenga kpis.editar. Quien no ve el tablero no tiene
     * desde dónde editarlo, y una clave de edición suelta es casi seguro un
     * error de asignación en Gestionusuarios, no una intención.
     */
    public static function permiteEditar(array $permisos, $esAdmin) {
        if ($esAdmin) {
            return true;
        }
        return self::permiteVer($permisos, false) && isset($permisos[self::CLAVE_EDICION]);
    }

    /**
     * El username del padrón, tal como se graba en las columnas USUARIO_*.
     * Sale SOLO del padrón: un nombre que no está en Gestionusuarios no firma
     * ninguna fila.
     */
    public static function username() {
        self::init();
        return self::recortarUsuario(self::$usuario['username'] ?? null);
    }

    public static function recortarUsuario($valor) {
        $valor = trim((string) $valor);
        return ($valor === '') ? null : mb_substr($valor, 0, self::LARGO_USUARIO);
    }

    /**
     * El usuario que recibe una función de escritura, validado. Defensa en
     * profundidad: ninguna escritura graba una fila sin saber quién la hizo.
     */
    public static function usuarioDeEscritura($usuario) {
        $u = self::recortarUsuario($usuario);
        if ($u === null) {
            throw new AuthKpisSinUsuario('No se puede guardar un cambio sin saber quién lo hace: falta el usuario.');
        }
        return $u;
    }

    /** El username, o AuthKpisSinUsuario (401). */
    public static function exigirUsuario() {
        $u = self::username();
        if ($u === null) {
            throw new AuthKpisSinUsuario('No se pudo identificar tu usuario en el padrón. '
                . 'Volvé a iniciar sesión y probá de nuevo.');
        }
        return $u;
    }

    /** Usuario y permiso de lectura. Devuelve el username. */
    public static function exigirLectura() {
        $u = self::exigirUsuario();
        if (!self::puede()) {
            throw new AuthKpisSinPermiso('No tenés acceso a este tablero (' . self::CLAVE_LECTURA . '). '
                . 'Pedilo en Gestión de Usuarios.');
        }
        return $u;
    }

    /** Usuario y permiso de edición. Devuelve el username. */
    public static function exigirEdicion() {
        $u = self::exigirUsuario();
        if (!self::puedeEditar()) {
            throw new AuthKpisSinPermiso('No tenés permiso para editar el tablero de KPIs ('
                . self::CLAVE_EDICION . '). Podés consultarlo, pero el cambio no se guardó.');
        }
        return $u;
    }

    /**
     * La guarda de la API: exige lo que el mapa dice para la acción.
     * Devuelve el username en los dos casos. Una acción no declarada se rechaza.
     *
     * @throws AuthKpisSinUsuario|AuthKpisSinPermiso
     */
    public static function exigirAccion($accion) {
        if (in_array($accion, self::LECTURAS, true)) {
            return self::exigirLectura();
        }
        if (in_array($accion, self::ESCRITURAS, true)) {
            return self::exigirEdicion();
        }
        throw new AuthKpisSinPermiso('La acción "' . $accion . '" no está declarada en el mapa de permisos '
            . 'del tablero (kpis/class/AuthKpis.php), así que no se ejecuta.');
    }
}
