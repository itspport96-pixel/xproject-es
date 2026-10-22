# Seguridad: mejoras pendientes para Produccion

Diagnostico de referencia: 22-oct-2026, sobre Desa (XAMPP, MariaDB 10.4, Apache,
PHP 8.4.27). Verificacion en vivo contra `xproject_e2e` (servidor E2E 8099) y
lectura de codigo.

**Estado en Desa (22-oct-2026): secciones 1, 2, 3 y 4 IMPLEMENTADAS** (ver
"1a", y los bloques 2-4 abajo). En Produccion falta replicar el patron de
usuarios MySQL y la configuracion de entorno/cabeceras (los cambios de codigo
— allowlist de BaseRepository, tests de mass assignment, CSP con nonce — viajan
al despliegue con el propio repo).

Resumen del estatus: mass assignment **mitigado y con tests por modulo** (seccion
3); entorno endurecido (production, debug off, JWT_SECRET rotado); CSP sin
`unsafe-inline` en `script-src` (nonce por request) y banner `Server` de Apache
oculto.

## 1. Base de datos (prioridad alta)

### 1a. Implementado en Desa (22-oct-2026)

La aplicacion ya **no** conecta como root. Usuarios creados y `.env` apuntando
al nuevo usuario de aplicacion:

- `xp_app` (`@localhost` y `@127.0.0.1`, password en `.env` `DB_PASSWORD`): es
  el usuario de la aplicacion (lectura y escritura con la misma unica
  conexion PDO; la app no distingue usuarios por tipo de operacion, eso
  exigiria dos pools de conexion y cambio de codigo). Privilegios:
  - `xproject.*`: SELECT, INSERT, UPDATE, DELETE (nada de DDL, ni FILE, ni
    GRANT).
  - `xproject_e2e.*`: ademas CREATE, DROP, ALTER, INDEX (lo necesita
    `bin/prepare-e2e-db.php` y las suites E2E).
  - `xproject_schema_check.*`: ademas CREATE, DROP, ALTER, INDEX (BD temporal
    que crea y borra `bin/check-schema-drift.php` en cada cierre).
- `xp_read` (`@localhost` y `@127.0.0.1`, misma password): **solo SELECT**
  sobre `xproject.*` y `xproject_e2e.*`; para consultas externas (BI, DBA,
  clientes de datos). La plataforma no lo usa; queda a disposicion.

Verificacion realizada con los usuarios nuevos: `check-schema-drift` SIN DRIFT,
`check-data-integrity` DATOS CONSISTENTES, `composer test` 1057 tests OK, login
y GET de la API reales con Apache 200, `prepare-e2e-db --fresh` completo y
suite `e2e-auth` 3/3 OK. Ademas: `xp_app` no puede crear BDs ajenas ni hacer
ALTER sobre `xproject`; `xp_read` no puede escribir (denegado).

Nota de mantenimiento: durante la implementacion se repararon dos tablas de
sistema de MariaDB que estaban corruptas en Desa (`mysql.db` via REPAIR TABLE y
`mysql.proxies_priv` via `mysqlcheck --repair`); tras la reparacion los GRANT
se aplicaron limpios. Si un GRANT futuro falla con error 1030/1034, revisar
esas tablas con `mysqlcheck -u root --check mysql`.

### 1b. Pendiente para Produccion

En Produccion, replicar este patron (misma estructura, password distinta y
fuerte generada en cada entorno):

1. Crear `xp_app` con los mismos privilegios de `xproject.*` (sin necesidad de
   `xproject_e2e` ni `xproject_schema_check` si esos scripts no corren alli;
   `check-schema-drift` si se ejecuta en Prod necesita su BD temporal).
2. Cambiar el `.env` de Produccion a ese usuario y password; verificar
   arranque (`php bin/check-schema-drift.php` y `composer test` contra esa BD).
3. Poner password a los `root` restantes o eliminar los aliases que no se usen
   (`root@127.0.0.1`, `root@::1`) y a `pma@localhost` si phpMyAdmin no se
   expone.
4. Crear `xp_read` (solo SELECT) si BI/consulta externa va a tocar la BD.
5. Desactivar `local_infile` si no hay ningun proceso que use
   `LOAD DATA LOCAL INFILE` (`SET GLOBAL local_infile = OFF` + `my.ini`).
6. Considerar `require_secure_transport=ON` y TLS si el trafico MySQL sale de
   localhost (no aplica si MariaDB y la app corren en la misma maquina y no hay
   replicacion externa).

## 2. Configuracion del entorno

### Implementado en Desa (22-oct-2026)

El `.env` de Desa quedo en `APP_ENV=production` y `APP_DEBUG=false` (sin stack
traces en respuestas de error ni en la pagina de error). `JWT_SECRET` rotado
(invalida las sesiones JWT previas: re-login; verificado login + GETs reales
tras la rotacion). `git ls-files .env` vacio: el fichero sigue fuera de git y
nunca se commitea.

**No rotados a proposit** (requieren plan de migracion, no un sed):
`ENCRYPTION_KEY` y `BLIND_INDEX_KEY` — hay datos ya cifrados y emails/indexados
en la BD viva; rotarlos sin re-encriptar los vuelve ilegibles. Protocolo de
rotacion para Produccion: (1) generar clave nueva, (2) re-encriptar con
`bin/encrypt-audit-backfill.php` (o el flujo equivalente del modulo) antes de
cortar, (3) recalcular blind index de users; en Desa se documenta pero no se
ejecuta.

### Pendiente para Produccion

- Mismo endurecimiento de entorno: `APP_ENV=production`, `APP_DEBUG=false`,
  `JWT_SECRET` propio (nunca el de Desa), y rotacion de credenciales SMTP.
- El repo no tiene pipeline de despliegue: en el servidor de Produccion,
  asegurar que el artefacto **no** incluye `backups/`, `tools/e2e/`, `.git/`
  ni ningun `.env` (el `.env` de Prod se coloca aparte, fuera del webroot
  accesible y fuera del repo).

## 3. Mass assignment (mitigado, con tests)

Todos los endpoints de escritura probados (users, roles, groups, products) con
payloads que incluian `password`, `es_admin`, `role`, `tipo`, `tenant_id`,
`id`, `deleted_at`, `email_verificado` y `permisos` en PATCHs que no los
admited: o se rechazaban con 400 o se ignoraban; en BD quedaban siempre los
valores forzados por el servidor (tenant real, id generado, `deleted_at` NULL).
El patron actual es: controller extrae campos individuales o el servicio
filtra con allowlist (`UserService`, `sanitizeUpdateData` de inventory) y los
repositorios escriben con SQL de columna fija.

### Implementado en Desa (22-oct-2026): los 2 pendientes cerrados

1. `BaseRepository::save()/update()` ya **no** escriben claves arbitrarias:
   `getAllowedWriteColumns()` es obligatorio (abstract) y
   `filterWritableColumns()` descarta toda columna fuera de la allowlist y
   los campos estructurales (`id`, `tenant_id`, `created_at`, `updated_at`,
   `deleted_at`); un `tenant_id` distinto en `update()` sigue rechazandose
   con `TenantException` (403). Ningun repositorio productivo extiende la
   clase hoy: el cambio es sin efecto runtime y deja de ser trampa latente.
2. Tests de mass assignment por modulo: `tests/Unit/MassAssignmentTest.php`
   (10 casos, SQLite en memoria con los repositorios/servidores REALES):
   `UserService::update/updateMe/create`, `RoleController::update`,
   `GroupController::update`, `ProductService::update` y las dos ramas de
   `BaseRepository` (allowlist + rechazo de tenant ajeno). Cada caso mete en
   el payload campos prohibidos y asserta que la fila no cambia en BD.

Residual (menor, valorar): una suite E2E contra `xproject_e2e` que repita los
payloads por HTTP (el PHPUnit actual ya cubre el contrato de la capa de
servicio/controller; la capa HTTP de rutas/middleware no añade escritura
directa).

## 4. Cabeceras HTTP y frontend

Presentes y validados: CSP, `X-Frame-Options: SAMEORIGIN`,
`X-Content-Type-Options`, `Referrer-Policy`, COOP/CORP, Permissions-Policy,
`X-Request-Id` y rate limit de login (`X-RateLimit-Limit: 5`).

### Implementado en Desa (22-oct-2026)

- **CSP endurecida**: `script-src` ya **no** incluye `'unsafe-inline'`. El
  entry point (`public/index.php`) genera un nonce por request
  (`bin2hex(random_bytes(16))`), se lo pasa a `SecurityHeadersMiddleware`
  (que lo apende a `script-src`) y los 4 scripts inline del shell/pagina de
  error lo repiten como `<script nonce="...">`. Los `<script src>` locales y
  CDN no lo necesitan. Verificado en navegador real (Playwright: picker,
  rowactions y shell con 211+136+24 checks, 0 violaciones CSP).
- **`'unsafe-eval'` se mantiene, acotado y documentado**: lo exigen el
  Tailwind Play CDN (JIT en cliente) y Alpine CDN (compiler de directrices
  `x-*`). Eliminarlo requiere el pendiente siguiente.
- **Banner `Server` oculto**: en el Apache de Desa, `ServerTokens Prod` y
  `ServerSignature Off` (`conf/extra/httpd-default.conf`); respuesta ahora
  `Server: Apache` sin version ni SO. Es configuracion de maquina, no del
  repo.

Pendiente para Produccion (y para cerrar del todo `unsafe-eval`):

- Replicar `ServerTokens Prod` / `ServerSignature Off` en el vhost de Prod.
- Sustituir el Tailwind Play CDN por un build estatico (o CSS propio) y
  externalizar Alpine: con eso se puede quitar `'unsafe-eval'` de
  `script-src`. Si el frontend lo permite, revisar tambien `style-src`
  (`'unsafe-inline'` sigue por estilos inline generados por JS).

## 5. Politica de borrado y datos

Sin cambios: soft delete con `deleted_at`, auditoria con redaccion de secretos,
contrasenas en bcrypt, integridad estricta (`sql_mode`), sin `have_symlink`.
Mantener el cierre de sprint habitual (`check-schema-drift`,
`check-data-integrity`, suites E2E) como gate de Produccion.
