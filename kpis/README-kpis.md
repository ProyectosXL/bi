# Tablero de KPIs de Innovación (`bi/kpis`)

Tablero de indicadores del área de Innovación: cinco KPIs con datos, un KPI
todavía en desarrollo y un **Cumplimiento General Ponderado** que resume a los
que tienen datos. Todo se filtra por un rango de meses (desde / hasta).

```
kpis/
├── index.php              Pantalla: tarjetas, Cumplimiento General y modales
├── api/kpis.php           Endpoint único (?action=...): autoriza y despacha
├── api/ahorros.php        Funciones del KPI de Ahorro (lo incluye kpis.php)
├── class/AuthKpis.php     Login, permisos de lectura/edición y mapa de acciones
├── js/kpis.js             Tarjetas, Cumplimiento General, detalles, instructivos, importación
├── js/ahorros.js          Modales de ahorros, carga/edición, historial y parámetros
├── css/kpis.css
└── sql/
    ├── kpis_permisos.sql      Alta de la clave kpis.editar (Gestionusuarios)
    ├── kpis_ahorros.sql       Tablas de ahorros, tipos, parámetros e historial
    └── ahorros_validacion.sql Consulta de solo lectura sobre Tango (referencia)
```

Stack: PHP con `sqlsrv`, jQuery 3.7, Bootstrap 5.3 y SheetJS (solo para leer el
Excel de tickets). Conexiones de `class/conexion.php`:

| Conexión | Base | Qué se usa |
|---|---|---|
| `central` | Tango (`LAKER_SA`) | `HistorialProyectos`, `CPA04`, `CPA01`, `CPA21` |
| `apps` | `sistemas` | `RO_T_TICKETS_PROYECTOS`, `FP_PROCESSES`, `RO_T_KPI_*`, `FP_SECTORES` |

---

## 1. Acceso y permisos

El tablero pide usuario y permiso. Lo resuelve `class/AuthKpis.php`, una copia de
la lógica de `finanzas/cashflow/Class/AuthCashflow.php` (sin compartir código).

- **Usuario.** Se busca en el padrón de Gestionusuarios (`FP_SOF_USUARIOS`, vía
  `Gestionusuarios/config/Database.php`) con el username de la sesión, en
  cualquiera de sus variantes: `username`, `usuario`, `nodo_usuario_activo` o
  `fp_auth_user['username']`. Así sigue funcionando durante la migración de
  login. Si no está Gestionusuarios o no hay usuario, **falla cerrada**: no se ve
  nada.
- **Administrador.** Sector Proyectos y además `es_admin`, `rol_id = 1`, rol
  `CONTROLTOTAL` o el usuario `FRANCOPERTUS`. Ve y edita todo sin permisos
  asignados (misma regla que el Cashflow).
- **Permisos** (se buscan por clave, sin ningún `modulo_id` escrito):

  | Clave | Para qué |
  |---|---|
  | `dei.item.control_de_gestion_y_metricas_kpis_innovacion` | **Lectura.** Ya existía (módulo DEI): es la que hace aparecer el tablero en el centralizador DEI. |
  | `kpis.editar` | **Edición.** Importar tickets, cargar / editar / dar de baja ahorros y editar parámetros. La crea `sql/kpis_permisos.sql`. |

  **Editar implica leer**, como en el Cashflow: sin la clave de lectura se niega
  la edición aunque el rol tenga `kpis.editar`.
- **Quién lo ve hoy.** La clave de lectura está asignada solo al rol
  `CONTROLTOTAL`. Con la protección activa, el tablero lo ven ese rol y los
  administradores. El resto de los accesos se asigna desde Gestionusuarios.
- **Asignación.** En Gestionusuarios los permisos se asignan **solo por rol**
  (`FP_ROL_PERMISO`). **No existe asignación por usuario.** La tabla
  `FP_USUARIOS_SCOPE` es de alcance (`tipo_scope`/`valor_scope`), no de
  permisos, está vacía y no la usa ningún código. Para darle edición a alguien,
  hay que asignarle `kpis.editar` (y la clave de lectura) a su rol.
- **La seguridad la da el servidor.** `index.php` pone `data-puede-editar` en
  `<body>`, y el CSS (`.kpi-solo-edicion`) y el JS ocultan los controles de
  edición. Pero cada acción de la API se valida en el servidor:

  | Caso | `index.php` | API |
  |---|---|---|
  | Sin usuario | "Tenés que iniciar sesión" + link a `/bi/sistemas/login.php` | 401 |
  | Sin permiso de lectura | "No tenés acceso a este tablero" | 403 |
  | Escritura sin `kpis.editar` | (los botones no se muestran) | 403 "No tenés permiso para editar…" |
  | Acción no declarada | — | 403 |

- **Acciones de la API.** Todas están declaradas en `AuthKpis::LECTURAS` o
  `AuthKpis::ESCRITURAS`. Una acción que no está en ninguna de las dos listas se
  rechaza. **Si agregás un `case` al `switch` de `api/kpis.php`, declaralo ahí.**

  | Lectura | Escritura (`kpis.editar`) |
  |---|---|
  | `kpi-resumen`, `kpi-detalle`, `tickets-usuarios-3m` | `importar-tickets` |
  | `ahorros-listado`, `ahorros-catalogos`, `ahorros-historial` | `ahorro-preview-proveedores` (cálculo previo, paso de una escritura) |
  | `buscar-proveedores`, `buscar-proyectos` | `ahorro-guardar`, `ahorro-baja`, `parametros-guardar` |

- El usuario logueado se muestra arriba a la derecha, con la marca "Edición" si
  puede editar.

---

## 2. Los KPIs

| # | KPI | Categoría | Peso | Objetivo | Fuente |
|---|---|---|---|---|---|
| 1 | Completados vs Plan | Ejecución | 20% | 60% | `HistorialProyectos` (central) |
| 2 | % Cumplimiento SLA | Ejecución | 20% | 85% | `HistorialProyectos` (central) |
| 3 | % Tickets dentro de SLA | Soporte | 15% | 90% | `RO_T_TICKETS_PROYECTOS` (apps) |
| 4 | % Procesos Relevados vs Plan | Mejora Continua | 5% | 2 procesos/mes | `FP_PROCESSES` (apps) |
| 5 | Ahorro / Beneficio Generado | Impacto | 15% | Parámetro `OBJETIVO_AHORRO_ANUAL` | `RO_T_KPI_*` (apps) + Tango (central) |
| — | % Costo del Área | Impacto | 25% | > 100% | **En desarrollo**, sin datos |

1. **Completados vs Plan.** Proyectos con Fecha Límite en el rango, sin los
   pausados. Cerrados (Estado Terminado con Finalización Real) ÷ total × 100.
   Drill-down "Ver proyectos".
2. **% Cumplimiento SLA.** Sobre los cerrados del rango: los que terminaron en o
   antes de la Fecha Límite ÷ cerrados × 100.
3. **% Tickets dentro de SLA.** Tickets con `CERRADO = 1` y Fecha Tarea en el
   rango. Cumplen si las horas hábiles entre Fecha Tarea y Fecha Cierre son 48 o
   menos (sin fines de semana ni feriados, según
   `POWER_BI_CONTROL.dbo.RO_T_CALENDARIO`). El drill-down tiene una solapa por
   usuario, con los resueltos de los últimos 3 meses.
   - **Importar Excel (Tickets)** (requiere `kpis.editar`): se lee el Excel en el
     navegador con SheetJS. Los períodos (mes de Fecha Tarea) que trae el archivo
     **se borran y se reemplazan completos**. Un ticket repetido se queda con la
     tarea más reciente. La tabla `RO_T_TICKETS_PROYECTOS` la crea la propia API
     si no existe (`asegurarTablaTickets()`), y es la única que se crea así.
4. **% Procesos Relevados vs Plan.** Procesos con `score_actual >= 2.0` creados
   en el rango ÷ (2 × meses del rango).
5. **Ahorro / Beneficio Generado.** Ver la sección 3.

Cada tarjeta tiene un popover "¿Cómo se calcula?" y un **Instructivo**. Los
instructivos están en `INSTRUCTIVOS_KPI` de `js/kpis.js`.

### Cumplimiento General Ponderado

`calcularCumplimientoGeneral()` en `js/kpis.js`, sobre `KPIS_GENERAL`:

```
logro de cada KPI = min(actual ÷ objetivo, 1) × 100
general = Σ(logro × peso) ÷ Σ(peso de los KPIs con dato)
```

Solo entran los KPIs con dato. El de Ahorro entra **solo si
`OBJETIVO_AHORRO_ANUAL` > 0**, comparando el ahorro del rango con el objetivo
del rango. La leyenda dice qué falta: sin objetivo, "Ahorro/Beneficio Generado
sin objetivo definido y % Costo del Área aún sin datos"; con objetivo, solo
"% Costo del Área".

---

## 3. KPI "Ahorro / Beneficio Generado"

### Tarjeta

- **Valor:** ahorro devengado en los meses del rango.
- **Debajo:** proyección del año calendario del mes "hasta" (suma de sus 12
  meses) y % de logro contra el objetivo del rango.
- **Objetivo del rango:** `OBJETIVO_AHORRO_ANUAL ÷ 12 × meses del rango`. La
  tarjeta muestra el anual como "$ X anual" o, si es 0, "Sin definir".
- **Botones:** "Ver ahorros" e "Instructivo"; con `kpis.editar` también
  "Parámetros".

### Tipos de ahorro (`RO_T_KPI_AHORRO_TIPOS`)

El modo de cálculo define qué se carga. El catálogo no se edita desde la pantalla.

| Código | Nombre | Modo | Se carga |
|---|---|---|---|
| `BAJA_PROVEEDOR` | Baja de proveedor | PROVEEDOR | Proveedores de Tango y su fecha de baja. El monto se calcula. |
| `HORAS_LIBERADAS` | Horas liberadas | HORAS | Horas por mes × costo hora (precargado con `COSTO_HORA_DEFAULT`). El monto se guarda. |
| `COSTO_EVITADO` | Costo evitado | MONTO | Monto |
| `RENEGOCIACION` | Renegociación / reducción de costo | MONTO | Monto |
| `INGRESO_HABILITADO` | Ingreso habilitado | MONTO | Monto |
| `OTRO` | Otro | MONTO | Monto |

**Obligatorios:** descripción, tipo, sector, periodicidad, fecha desde y fecha
hasta (con hasta ≥ desde). Además:
- MONTO: monto > 0.
- HORAS: horas > 0 y costo hora > 0.
- PROVEEDOR: al menos un proveedor con su fecha de baja.

El motivo es obligatorio al editar y al dar de baja.

- **Sector:** de `FP_SECTORES` (activos). Si se elige un proyecto, se precarga el
  sector del proyecto cuando `HistorialProyectos.Sector` coincide con el código o
  el nombre de un sector de `FP_SECTORES` (sin mayúsculas, acentos ni símbolos).
  Si no coincide, se elige a mano.
- **Proyecto:** opcional, de `HistorialProyectos`, con búsqueda por ID o nombre.

### Devengado por mes

- **MENSUAL:** el monto mensual en cada mes entre el mes de "desde" y el de
  "hasta", inclusive. Meses enteros, sin prorrateo.
- **UNICO:** el monto entero en el mes de "desde".
- **PROVEEDOR:** siempre mensual. Suma el promedio de cada proveedor desde su mes
  de inicio del ahorro, sin pasar el mes de "hasta". Al cargar, "desde" se
  completa con la baja más temprana y "hasta" con el 31/12 de ese año; las dos se
  pueden cambiar.

### Cálculo "Baja de proveedor" (`calcularBajaProveedor()` en `api/ahorros.php`)

Se calcula **en cada consulta**, con lo facturado en Tango a ese momento. No se
guarda; solo queda un snapshot informativo en el historial del alta.

- **Comprobantes:** `CPA04` unido a `CPA21` por `T_COMP`, clases `F` (factura),
  `NC` y `ND`. El signo sale de `CPA21.CRE_DEB`: C resta (notas de crédito) y D
  suma. Las O/P no están en `CPA21`, así que no entran.
- **Excluidos:** los `T_COMP` del parámetro `TANGO_TCOMP_EXCLUIDOS` (hoy
  `NCD,NDC,AJC,AJD,AJU`: diferencias de cambio y ajustes contables) y `ESTADO =
  'ANU'`. Tango borra de `CPA04` los comprobantes anulados (quedan en
  `CPA_AUDITORIA_BAJA_COMPROBANTE`), así que ese filtro es una defensa. El estado
  `***` son compras al contado y **sí entran**.
- **Importe en pesos:**
  - `CPA01.COND_IVA = 'RS'`: `IMPORTE_TO`, que ya está en pesos aunque el
    comprobante sea en moneda extranjera.
  - Cualquier otra condición: `IMPORTE_NE + IMPORTE_EX`, sin IVA ni percepciones,
    × `COTIZACION` si `MON_CTE = 0`. En moneda extranjera esas columnas están en
    la moneda del comprobante; `IMPORTE_TO` no.
- **Universo:** comprobantes con `FECHA_EMIS` desde el 01/01 del año de la baja y
  anteriores a la fecha de baja.
- **Meses considerados:** de enero al mes del último comprobante del universo,
  inclusive. Son meses transcurridos, no meses con factura.
- **Promedio mensual:** total neto ÷ meses considerados.
- **Inicio del ahorro:** el mes siguiente al del último comprobante.
- **Ahorro proyectado del año:** promedio × meses desde el inicio hasta diciembre.
- **Avisos:**
  - "Sin facturación en el año previa a la baja": el proveedor aporta 0.
  - "Facturó después de la baja": se muestra el detalle de esos comprobantes, que
    no se descuentan del ahorro.

La regla se definió con los datos de `sql/ahorros_validacion.sql` (OGMAGA y
OGQUIP desde el 01/01/2026, más el universo de tipos y estados).

### Pantallas

- **Ver ahorros** (todos):
  - resumen arriba: ahorro del rango, proyección, objetivo, % de logro y apertura
    por tipo;
  - tabla de ahorros; en los de proveedor, la fila se expande y muestra el
    cálculo de cada proveedor;
  - switch "Ver dados de baja".
- **Con `kpis.editar`:**
  - "Nuevo ahorro";
  - por fila, "Editar" y "Dar de baja" (con motivo) y el ícono de historial (quién
    cambió qué, cuándo y por qué);
  - modal "Parámetros": objetivo anual y costo hora por defecto, con motivo e
    historial.

### Tablas (base `apps` / `sistemas`)

Todas las tablas editables llevan auditoría (`USUARIO_ALTA`, `FECHA_ALTA`,
`USUARIO_MODIF`, `FECHA_MODIF`, `USUARIO_BAJA`, `FECHA_BAJA`) con el username del
padrón. Una escritura sin usuario se rechaza. Las bajas son lógicas (`ACTIVO =
0`).

| Tabla | Contenido |
|---|---|
| `RO_T_KPI_AHORRO_TIPOS` | Catálogo de tipos y su `MODO_CALCULO` |
| `RO_T_KPI_AHORROS` | Un registro por ahorro. `SECTOR_ID` → `FP_SECTORES.id` y `PROYECTO_ID` → `HistorialProyectos.ID`, los dos sin FK (otra app y otro servidor) |
| `RO_T_KPI_AHORROS_PROVEEDORES` | Proveedores de un ahorro de baja de proveedor: `COD_PROVEE` y `FECHA_BAJA_PROVEEDOR`. `FECHA_BAJA` es la baja lógica del registro. |
| `RO_T_KPI_PARAMETROS` | `OBJETIVO_AHORRO_ANUAL`, `COSTO_HORA_DEFAULT` (se editan en pantalla) y `TANGO_TCOMP_EXCLUIDOS` (solo por SQL) |
| `RO_T_KPI_AHORROS_HISTORIAL` | Altas, ediciones y bajas de ahorros y parámetros: valor anterior y nuevo en JSON, motivo, usuario y fecha |

---

## 4. Scripts SQL

Los scripts **no se ejecutan desde la aplicación**. Los dos de instalación son
reejecutables (`IF NOT EXISTS`) y no borran ni modifican nada existente.

| Orden | Script | Base | Qué hace |
|---|---|---|---|
| 1 | `sql/kpis_permisos.sql` | `apps` / `sistemas` (Gestionusuarios) | Crea `kpis.editar` en el módulo de la clave de lectura, que resuelve por clave. No lo asigna a ningún rol. Si la clave de lectura no existe, lo informa y no crea nada. |
| 2 | `sql/kpis_ahorros.sql` | `apps` / `sistemas` | Crea las 5 tablas `RO_T_KPI_*` y siembra tipos y parámetros. |
| — | `sql/ahorros_validacion.sql` | `central` (Tango) | Solo lectura. Es la consulta con la que se definió la regla de comprobantes; sirve para revisarla si cambia algo en Tango. |

Los dos primeros son independientes entre sí; el orden es solo el sugerido.

**Si no se corren:**
- **sin `kpis_permisos.sql`:** el tablero funciona, pero solo el administrador
  puede editar;
- **sin `kpis_ahorros.sql`:** la tarjeta de Ahorro muestra "No se pudo calcular
  el ahorro" y las demás funcionan igual.

> Estado al 05/11/2026: `kpis_ahorros.sql` ya se corrió en XL-APPS / `sistemas`
> (con autorización, para probar). `kpis_permisos.sql` está pendiente.

---

## 5. Pruebas

Checklist funcional: qué hago en la pantalla y qué tiene que pasar.

1. Entro al tablero sin estar logueado: veo "Tenés que iniciar sesión" con el
   link al login, y no veo datos.
2. Entro con un usuario sin la clave de lectura: veo "No tenés acceso a este
   tablero", y no veo datos.
3. Entro con un usuario que ve el tablero (clave de lectura) pero no tiene
   `kpis.editar`:
   - veo todos los KPIs y "Ver ahorros";
   - no veo "Importar Excel", "Nuevo ahorro", "Editar", "Dar de baja", el
     historial por fila ni "Parámetros".
   
   *Hoy ningún usuario real tiene ese perfil: todos los que tienen la clave de
   lectura son administradores. Para probarlo, hay que asignar la clave de
   lectura a un rol que no sea administrador.*
4. Con ese mismo usuario intento importar tickets forzando la llamada a
   `api/kpis.php?action=importar-tickets`: el sistema lo rechaza con "No tenés
   permiso para editar el tablero de KPIs".
5. Entro con mi usuario (administrador): veo y puedo usar todos los botones de
   edición, y mi usuario aparece arriba a la derecha con la marca "Edición".
6. Cargo un ahorro "Baja de proveedor" con OGMAGA y OGQUIP y sus fechas de baja:
   - la periodicidad queda fija en Mensual;
   - "desde" se completa con la baja más temprana y "hasta" con el 31/12;
   - antes de guardar veo, por proveedor, el total facturado neto, los meses
     considerados, el promedio y desde qué mes empieza el ahorro;
   - los números coinciden con Tango. Con baja el 01/11/2026: OGMAGA $
     14.832.000 en 10 meses, $ 1.483.200 por mes; OGQUIP $ 8.130.370 en 10
     meses, $ 813.037 por mes.
7. Un proveedor con notas de crédito en el año: el total facturado aparece neto
   de las notas. Ejemplo: SAUNIC con baja el 01/10/2026 da $ 320.559.842,85 =
   facturas − NCI − NCP.
8. Un proveedor que no es RS: el promedio está sin IVA (OGQUIP, RI). Uno RS:
   está con IVA (OGMAGA). Las facturas en dólares de OGQUIP de enero y febrero
   aparecen en pesos.
9. Cargo un ahorro "Horas liberadas" de 10 horas por mes a $ 10.000 la hora,
   mensual de enero a diciembre:
   - el formulario muestra en vivo $ 100.000 por mes;
   - con el filtro de enero a marzo, la tarjeta muestra $ 300.000.
10. Cargo un ahorro "Costo evitado" único de $ 500.000 en marzo: suma solo cuando
    el filtro incluye marzo.
11. Intento guardar un ahorro sin sector, o con fecha hasta anterior a fecha
    desde: no me deja y me dice qué falta.
12. Edito un ahorro sin poner motivo: no me deja. Con motivo, el cambio queda en
    el historial con mi usuario, la fecha, el motivo y qué campos cambiaron.
13. Elijo un proyecto asociado cuyo sector existe en Gestionusuarios (por ejemplo
    "Comercial"): se precarga el sector. Si no existe (por ejemplo "Dirección"),
    me avisa que lo elija a mano.
14. Doy de baja un ahorro (con motivo): deja de sumar en la tarjeta y solo lo veo
    con el switch "Ver dados de baja", tachado y con quién lo dio de baja.
15. Con el objetivo en 0:
    - la tarjeta dice "Sin definir" y "Sin objetivo definido";
    - el Cumplimiento General no cambia respecto de antes de este desarrollo;
    - la leyenda dice que el Ahorro está sin objetivo definido.
16. Cargo un objetivo anual en Parámetros (con motivo): la tarjeta muestra el % de
    logro y el Cumplimiento General pasa a incluir el Ahorro con peso 15. La
    leyenda solo menciona "% Costo del Área". El cambio queda en el historial de
    Parámetros.
17. Un proveedor que facturó después de su fecha de baja (OGMAGA u OGQUIP con
    baja el 01/11/2026): veo el aviso "Facturó después de la baja" y el detalle
    de esos comprobantes.
18. Un proveedor sin facturas en el año antes de la baja: veo el aviso "Sin
    facturación en el año previa a la baja" y aporta $ 0.
19. Los cuatro KPIs anteriores, sus drill-downs y la importación de tickets (con
    `kpis.editar`) funcionan igual que antes.
