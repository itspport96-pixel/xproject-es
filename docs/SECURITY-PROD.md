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
  - `xproject.*`: SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, SHOW VIEW,
    TRIGGER (nada de DDL, ni FILE, ni GRANT). Los tres ultimos los necesita
    `mysqldump` (`bin/regenerate-schema.php`): sin ellos el dump aborta con
    error 1044 "Access denied ... to database 'xproject'". Recipe exacto:
    `GRANT SELECT, INSERT, UPDATE, DELETE, LOCK TABLES, SHOW VIEW, TRIGGER ON \`xproject\`.* TO 'xp_app'@'localhost';`
    (repetir para `@127.0.0.1`).
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

**No rotado a proposit** `ENCRYPTION_KEY`: hay datos ya cifrados en la BD viva
(`mfa_secret` y el nombre publico/email/telefono de tenants se escriben
cifrados con `EncryptionService`); rotarlo sin re-encriptarlos los vuelve
ilegibles. El re-encriptado exige un script de rotacion que descifre con la
clave vieja y recifre con la nueva (pendiente del rediseño de cifrado, ver
seccion 6). `BLIND_INDEX_KEY` tampoco se rota: sostiene el indice ciego vivo
`tenants.nombre_interno_hmac` (lo escribe y consulta `TenantRepository`).

Secuelas del intento de cifrado punto-a-punto revertido, **eliminadas el
22-oct-2026**: `bin/sprint2-backfill.php`, `bin/b2-backfill.php`,
`bin/b3-backfill.php`, `bin/encrypt-audit-backfill.php`,
`bin/revert-users-nombre-encryption.php`, `bin/verify-decrypt.php`,
`docs/ENCRYPTION.md`, los dos backups locales pre-rollback y las columnas y
tablas que ya nadie mantenia (`users.email_hmac` + indice unico, tablas
`secrets_vault` y `secrets_access_log`, ambas vacias). `schema.sql`
regenerado; drift SIN DRIFT.

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
- **Sin CDNs en runtime (v1.19)**: Tailwind se compila a
  `public/css/tailwind.css` y Alpine se retiro del shell; su unica directiva
  (el drop de cuenta) es vanilla en `public/js/ui/account-menu.js`.
- **`'unsafe-eval'` eliminado de `script-src`**: ya nada compila con
  `new Function()` en runtime. El bootstrap de tema sigue inline pero con
  `nonce` por request (tiene que serlo: corre antes del primer pintado para
  evitar FOUC; moverlo a un fichero externalizado anadiria un round-trip en
  el camino critico sin ganar seguridad, porque el nonce ya bloquea la
  inyeccion).
- **`'unsafe-inline'` permanece SOLO en `style-src`**: los modulos pintan
  atributos `style=""` (barras de progreso, previews de marca, QR) y no hay
  nonce para atributos. Quitarlo exige mover esos estilos a clases CSS.
- **Banner `Server` oculto**: en el Apache de Desa, `ServerTokens Prod` y
  `ServerSignature Off` (`conf/extra/httpd-default.conf`); respuesta ahora
  `Server: Apache` sin version ni SO. Es configuracion de maquina, no del
  repo.

Pendiente para Produccion:

- Replicar `ServerTokens Prod` / `ServerSignature Off` en el vhost de Prod.
- Revisar `style-src`: `'unsafe-inline'` solo por estilos inline generados
  por JS; eliminarlo exige convertirlos a clases.

## 5. Politica de borrado y datos

Sin cambios: soft delete con `deleted_at`, auditoria con redaccion de secretos,
contrasenas en bcrypt, integridad estricta (`sql_mode`), sin `have_symlink`.
Mantener el cierre de sprint habitual (`check-schema-drift`,
`check-data-integrity`, suites E2E) como gate de Produccion.

## 6. Aislamiento entre tenants y cifrado de datos (22-oct-2026)

### 6a. Probe cross-tenant real: SIN FUGAS

Sonda contra la BD viva `xproject` (no contra E2E): tenant + usuario + rol
CUSTOM temporal creados por SQL con los 58 permisos del ADMIN BUILT_IN,
login real por `POST /api/auth/login`, y despues GET por id de filas reales
del tenant Acme (product `fc37b8ba`, location `8ba74eee`, user
`40496ba5`, role `52cfef53`, group `041dec9e`). Resultado: los 5 GET
responden **404 de entidad** ("no encontrado"), nunca 200 ni 403 con datos;
los 5 listados (`inventory/products`, `inventory/locations`, `users`,
`roles`, `groups`) responden 200 sin ningun id de Acme. Todo lo creado se
borro al terminar (audit_logs, refresh_tokens, rate_limit_log,
role_assignments, role_permissions, user, role, tenant): la BD viva quedo
identica (4 usuarios, 1 tenant, 0 restos de la sonda).

### 6b. Residual abierto: cifrado de datos y auditoria en claro

Decision: **no se re-implementa el cifrado punto-a-punto ahora**; volvera de
forma organizada con su propio plan (script de rotacion, verificacion de la
cadena de hash y migracion gradual). Hoy en claro en la BD viva: PII de
`users` (nombre, apellido, email, telefono), PII de `tenants` y
`shipments`, y las snapshots de auditoria (`audit_logs.valores_anteriores`,
`valores_nuevos`, `detalles_adicionales`, `ip_address`, `user_agent`) mas la
ip en `rate_limit_log` y `refresh_tokens`. Consecuencia directa: la rotacion
de `ENCRYPTION_KEY` sigue bloqueada hasta ese rediseño (seccion 2). Nota: la
cadena de integridad de `audit_logs` (`hash_integridad`) se calcula sobre el
contenido en claro; cualquier cifrado futuro debe preservarla o migrarla.

Deviation observada durante el probe (no corregida, fuera de alcance): los
404 de `users` y `roles` devuelven el literal `{id}` sin interpolar en el
mensaje ("El usuario con ID '{id}' no existe."); el de `groups` si interpola.

## 7. Runbook de deploy para tenants legacy (v1.19.9)

Tras desplegar en un entorno con tenants creados antes de v1.7.1, ejecutar:

```bash
php bin/seed-transit-locations.php
```

Este script es **idempotente** y siembra las ubicaciones virtuales `TRANSITO` y
`DAÑADOS` para cada tenant que no las tenga. Sin esto:

- `POST /api/inventory/shipments/authorize` responde **404** con error
  `transitLocationMissing` (no puede autorizar envíos sin ubicación de tránsito).
- `POST /api/inventory/returns/register` con `estado_al_devolver = DAÑADO`
  responde **404** con error `damagedZoneMissing` (no puede devolver artículos
  dañados sin zona de dañados).

Los tenants creados desde v1.7.1 en adelante ya reciben estas ubicaciones
automáticamente en `TenantService::create()` vía `TransitLocationService`.
