# Changelog

All notable changes to this project will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.17.3] - 2026-10-17

**Topbar mas compacta y padding de botones reducido.** El grupo derecho del header (tema, idioma, cuenta) pasa de `gap-2` (8px) a `gap-1` (4px) (`public/index.php`). `.btn` en `ios.css` reduce el padding horizontal de `1.125rem` a `0.8rem`; al cargarse `ios.css` despues de `components.css`, ese padding es el que aplica a todos los botones, incluidos los iconos del topbar. Verificado en vivo: pagina 200 con `gap-1` servido, `php -l` OK, `check-schema-drift` SIN DRIFT.

## [1.17.2] - 2026-10-16

**Tres arreglos del flujo SSPR y de las pantallas publicas de autenticacion.** (1) El enlace de restablecimiento ya no se quema cuando la password propuesta reutiliza la actual o una del historial: el reuso se valida ANTES de consumir el token, igual que la politica de fortaleza, de modo que el usuario puede reintentar con la misma liga y una password distinta. (2) Los campos de password de login y de restablecimiento incorporan un ojo show/hide (boton dentro de un `.input-group`, SVG inline, `aria-label`/`aria-pressed`). (3) Las paginas publicas (login/forgot/reset) respetan el idioma elegido: `GET /api/locales/{locale}` pasa a ser PUBLICO (solo rate-limit) y el frontend descarga el diccionario tambien pre-sesion; ademas `BUILTIN_ES` se completa con las claves que faltaban (`reset.*`, `forgot.*`, `password.*`, `login.forgot_password`, `login.mfa_*`, `routes.forgot_password`/`reset_password`, `ui.show_password`/`hide_password`) para no pintar claves crudas si la red falla.

### Added
- `UserService::assertPasswordNotReused()` (publico): valida reuso sin aplicar el cambio; delega en el privado `validatePasswordNotReused()`.
- Ojito show/hide en `public/js/modules/auth/reset-password.js` (password_nuevo + password_confirm) y `login.js` (login-password); helper `togglePasswordField()` e iconos SVG eye/eye-off en cada modulo.
- `public/css/components.css`: estilo `.input-addon.btn-eye` (boton-ojo heredando el aspecto del addon).
- `resources/locales/{es,en,pt,fr}.json`: claves nuevas `ui.show_password` / `ui.hide_password` (traducidas en los cuatro idiomas).

### Changed
- `PasswordResetService::resetPassword()`: llama `assertPasswordNotReused()` justo despues de `assertPasswordPolicy()` y ANTES de `consumeToken()`; el reuso ya no quema el enlace.
- `routes/api.php`: `GET /api/locales/{locale}` deja de incluir `AuthenticationMiddleware` (queda solo `RateLimitMiddleware`); la ruta es publica para que login/forgot/reset descarguen el diccionario pre-sesion.
- `public/js/core/i18n.js`: `fetchDictionary()` elimina el guard `hasSession` (ahora la descarga es pre-sesion); `BUILTIN_ES` ampliado con todas las claves de forgot/reset/password/login SSPR/rutas y `ui.show_password`/`hide_password`.
- `tests/Unit/PasswordResetLiveTest.php`: `testNoPermiteReutilizarLaContrasenaAnterior` refuerza que el reuso NO consume el token y que el mismo token sirve despues con otra password.
- `tests/Unit/LocalesRoutesTest.php`: la ruta de diccionarios se asierta ahora como PUBLICA (sin `AuthenticationMiddleware`, con rate-limit).

### Notes
- Verificado end-to-end: `composer test` 992/992; smokes sspr 35 + v1120 157 + profile-mfa 29 + v162 89 + v171 105 OK; `check-i18n` 0 errores (1930 claves en paridad); `check-schema-drift` SIN DRIFT; `check-data-integrity` DATOS CONSISTENTES; `GET /api/locales/es.json` responde 200 SIN token con `reset`/`forgot`/`password`/`ui.show_password` presentes.
- Desviacion reportada sin tocar (regla 4): la password del admin de creacion de tenant sigue sin aplicar SSPR ni politica completa (`TenantService::validateAdminData`, decision previa); ademas los textos de longitud de password del cliente no coinciden con `PasswordPolicy::MIN_LENGTH = 9` (`BUILTIN_ES.login.error_short_password` dice 6 y `resources/locales/*.json` dicen 8; la clave de backend `password_min_length` si dice 9).
- Commiteado en este ciclo junto con los tres arreglos: `AuthPermissionSeeder` asigna `password_reset_self_service` al rol ADMIN (ver 1.17.1).

## [1.17.1] - 2026-10-16

**Correccion del bucle infinito de cambio obligatorio de password de v1.17.0 y pertenencia del permiso SSPR al rol ADMIN.** v1.17.0 trataba cualquier valor no-nulo de `fecha_expiracion_password` como "debe cambiar la password", de modo que una password temporal (reset de administrador o autogenerada) o una caducidad forward ya vencida forzaba el cambio sin salida: cada intento volvia a forzar la pantalla de cambio. El cambio forzado temporal pasa a su propia columna `users.password_change_forced`; `fecha_expiracion_password` queda como lo que es (expiracion forward de la politica de caducidad del tenant, v1.14.1) y solo fuerza cuando esta VENCIDA.

### Added
- Columna `users.password_change_forced` (timestamp NULL, BD viva): vigencia del cambio forzado temporal; `schema.sql` regenerado (`check-schema-drift` SIN DRIFT, 33 tablas).
- `AuthPermissionSeeder`: `ROLE_PERMISSIONS['ADMIN']` recibe `password_reset_self_service` (directamente o heredado via grupos), de modo que los administradores quedan elegibles para el SSPR por defecto; idempotente.

### Fixed
- **Bucle de cambio obligatorio**: `AuthenticationMiddleware` solo fuerza el cambio si `password_change_forced` esta marcada o `fecha_expiracion_password` esta en el PASADO (antes cualquier no-nulo forzaba). `AuthController::login`: la password temporal vencida ni siquiera concede el login (contactar con un administrador), mientras que una caducidad forward vencida concede el login y fuerza el cambio. Verificado en vivo con 5 escenarios.
- `UserService::createUser/changePassword/resetPassword` escriben `password_change_forced` en lugar de reciclar `fecha_expiracion_password`.

### Changed
- `UserRepository::getAuthState` lee ambos marcadores (`UserRepositoryInterface` actualizado); se elimina `updatePassword` (codigo muerto, sin callers). Entity `User` expone `passwordChangeForced`. `PasswordChangeRequiredException` documenta los dos marcadores.
- `tests/Unit/AuthenticationMiddlewareTest.php` reescrito contra MySQL `xproject_e2e` (sin SQLite), 17 tests con regresion "caducidad futura NO fuerza el cambio".

## [1.17.0] - 2026-10-16

**Password Reset SSPR (self-service password reset) por correo transaccional.** El login incorpora un enlace "¿Olvidaste tu contraseña?" siempre visible que abre una pantalla publica para solicitar un enlace de restablecimiento. El permiso `password_reset_self_service` (opt-in, concedido y revocado unicamente por administradores; sin asignar a ningun rol BUILT_IN) decide quien puede recibir y usar el enlace. El flujo: `POST /auth/forgot-password` responde SIEMPRE 200 con el mismo mensaje generico (anti-enumeracion: exista o no el email, este o no autorizado); si el usuario es elegible se crea un token criptografico (32 bytes aleatorios) del que solo se guarda el hash SHA-256, con caducidad de 15 minutos y un solo uso, y se envia por correo un enlace `#/reset-password?token=...`. `POST /auth/reset-password` re-verifica el permiso en el momento de fijar la contraseña, valida la politica de fortaleza (v1.16.0) ANTES de consumir el token, lo consume de forma atombica (`UPDATE ... WHERE used_at IS NULL`), cambia la contraseña via `UserService::changePassword` (politica + historial), revoca todas las sesiones del usuario y notifica al trabajador. La auditoria registra el resultado sin tokens ni contraseñas. La caducidad del token y la politica se validan tambien en cliente.

### Added
- `src/Core/Authentication/Service/PasswordResetService.php`: nucleo SSPR (`requestReset()` anti-enumeracion, `resetPassword()` con re-check de permiso, consumo atomico, delegacion de politica/historial a `UserService`, revocacion de sesiones y auditoria sin secretos; constantes `TOKEN_TTL_MINUTES=15` y `PERMISSION`). Sin `final` (decision: el doble de test sobreescribe `sendMail` para capturar el token sin tocar SMTP).
- Tabla `password_reset_tokens` (BD viva): id char(36) PK, tenant_id FK->tenants, user_id FK->users, token_hash varchar(64) UNIQUE, created_at, expires_at datetime, used_at datetime NULL, ip_address varchar(45); `schema.sql` regenerado (SIN DRIFT, 33 tablas).
- `src/Core/Authentication/Seeder/AuthPermissionSeeder.php`: siembra el permiso `password_reset_self_service` de forma idempotente, sin asignarlo a ningun rol (`ROLE_PERMISSIONS=[]`); conectado en `PlatformSeeder`.
- Endpoints publicos `POST /api/auth/forgot-password` y `POST /api/auth/reset-password` (Audit + RateLimit `:5,900` por ruta; forgot ademas limita 3/900s por email con clave `forgot:email:` y responde igual aunque se supere el limite).
- `public/js/modules/auth/forgot-password.js` y `reset-password.js`: formularios publicos (mensaje de exito SIEMPRE identico en forgot, politica de cliente en reset); registrados en `router.js`/`loader.js` como rutas publicas.
- `tests/Unit/PasswordResetLiveTest.php` (10 tests, MySQL sobre `xproject_e2e`, sin SQLite): gating por permiso, hash SHA-256 (no plaintext), anti-enumeracion, un solo uso, invalidacion de token anterior, caducidad, re-check de permiso tras revocar, politica sin consumir token y no-reutilizacion.
- `tools/smoke/smoke-sspr.mjs` (35 checks): enlace en login, formulario forgot generico (incluido en error de red) y reset (token, politica de cliente, exito + toast, error del backend).

### Changed
- `AuthController`: `forgotPassword()` (siempre 200 generico) y `resetPassword()` (lee `token` + `password_nuevo`; errores especificos 400/403 en el reset), mas `resetContext()`/`resolvePasswordReset()`.
- `MailerService`: nuevo `sendCustom()` y `sendViaSmtp()` privado compartido con `sendTest()` para enviar el correo SSPR con la plantilla del tenant.
- `bootstrap.php`: singleton `PasswordResetService` (database, AuthorizationService, UserService, MailerService, AuditLogger).
- `public/js/modules/auth/login.js`: enlace `#login-forgot-wrap` (`#/forgot-password`) siempre visible; oculto en modo MFA.
- `resources/locales/{es,en,pt,fr}.json`: `login.forgot_password`, `routes.forgot_password`/`reset_password`, bloques `forgot.*` y `reset.*`, y `api_errors.auth.reset_*` (4 claves leidas por `ErrorCatalog`).

### Notes
- Verificado en vivo end-to-end (API real): login admin MFA, GETs `/auth/me`/`/auth/mfa/status`/`/tenant` 200, forgot elegible 200 + token creado, inexistente/invalido 200 generico, reset fuerte 200, reuso 400, debil 400 sin consumir, caducado 400 y sin permiso 403 (`RESET_PERMISSION_REVOKED`); rate limit de ruta demostrado (429 en la 6a llamada) y limpiado; sin restos en la BD de trabajo. `composer test` 990/990; smokes 35+68+29+89+105+157 OK; `check-schema-drift` SIN DRIFT; `check-data-integrity` DATOS CONSISTENTES; `check-i18n` 0 errores.
- Decision documentada: el token se CONSUME aunque el cambio posterior falle por reutilizacion de contraseña (politica), porque el consumo es atombico y previene reintentos con el mismo enlace; la politica se valida antes, asi que una password debil NO consume el token.
- Desviacion reportada sin tocar (decision previa): `TenantService::validateAdminData()` sigue validando solo longitud >=8 para el admin de creacion de tenant (esa creacion aun no existe); no se le aplica la politica ni el SSPR.
- Deuda preexistente reportada sin tocar: claves i18n sin uso en pt/fr y el resto (~220 avisos, ninguno SSPR); 2 deprecations de phpunit.

## [1.16.0] - 2026-10-16

**Refuerzo de la politica de contrasenas: minimo 9 caracteres con mayuscula, minuscula, digito y caracter especial, y prohibicion de reutilizacion mediante historial.** La politica de password pasa de exigir minimo 8 caracteres (con mayuscula, minuscula y digito) a minimo 9 con mayuscula, minuscula, digito y al menos un caracter especial no alfanumerico. Ademas, cada cambio o reset de contrasena guarda el hash saliente en una tabla nueva `password_history`, y la politica rechaza cualquier password que coincida con la contrasena actual o con cualquiera de las anteriores del usuario. El frontend (validacion en cliente del formulario de cambio) aplica las mismas reglas.

### Added
- `src/Shared/Security/PasswordPolicy.php`: politica unica `PasswordPolicy::validate()` (min 9, mayuscula, minuscula, digito, especial `[^A-Za-z0-9]`); devuelve la clave i18n de la primera violacion. Delegada por `UserService::validatePasswordStrength()`.
- Tabla `password_history` (BD viva): id char(36) PK, tenant_id, user_id FK->users(id), password_hash varchar(255), created_at, KEY (user_id, created_at). `schema.sql` regenerado (SIN DRIFT).
- `UserService::validatePasswordNotReused()` (compara contra `users.password` actual y todos los hashes de `password_history`; clave `auth.password_reused`) y `recordPasswordHistory()`; `changePassword()` y `resetPassword()` pasan a transaccion (INSERT hash saliente + UPDATE users).
- Tests `tests/Unit/PasswordPolicyLiveTest.php` (11 tests, MySQL sobre `xproject_e2e`, sin SQLite): politica (5 violaciones + valida), no-reutilizacion (actual/anterior), historial acumula, create debil rechazado y password temporal de admin cumple politica.

### Changed
- `UserService::generateTemporaryPassword()`: ahora garantiza un caracter especial (`!@#$%&*?-.`), 12 caracteres.
- `public/js/modules/auth/password.js`: validacion en cliente min 9 + check de caracter especial.
- `resources/locales/{es,en,pt,fr}.json`: `auth.password_min_length` 8->9, hints actualizados, nuevas claves `auth.password_special`/`auth.password_reused` y `password.errors.special`.
- `tools/smoke/smoke-v1120.mjs`: passwords de fixture con especial; nuevo check "9 sin especial" (157 OK).
- `tools/smoke/smoke-v11358.mjs`: fixture `plataforma_version` a 1.16.0 (68 OK).

### Notes
- Verificado en vivo end-to-end (API real): login admin MFA, creacion de usuario de prueba, password debil (8 sin especial) -> 400, fuerte -> 200, reutilizar la anterior -> 400, misma actual -> 400 y borrado del usuario de prueba. `composer test` 980/980; smokes 68+29+89+105+157 OK; `check-schema-drift` SIN DRIFT; `check-data-integrity` DATOS CONSISTENTES; `check-i18n` 0 errores.
- Desviacion reportada sin tocar (decision): `TenantService::validateAdminData()` sigue validando solo longitud >=8 para la password del admin de creacion de tenant (esa creacion de tenant con admin todavia no existe); no se aplico la nueva politica ahi.
- Deuda preexistente reportada sin tocar: claves `password_*` de pt/fr vacias (solo se anadieron las nuevas, con traducciones propias); 216 avisos de claves i18n sin uso.

## [1.15.0] - 2026-10-16

**MFA opt-in por usuario (TOTP con `spomky-labs/otphp`): enrolamiento en el Perfil > Seguridad y paso de codigo en el login.** Cada usuario puede activar su segundo factor desde la pestaña Seguridad de su Perfil (junto al cambio de contrasena): se genera un secreto TOTP (sha1, 6 digitos, 30 s) que se muestra como codigo manual y como enlace `otpauth://` para el QR, se confirma con un codigo de 6 digitos de la app autenticadora y se entregan 10 codigos de respaldo de un solo uso (hash bcrypt) que se muestran una sola vez. El login pasa a devolver `mfa_required` (HTTP 200 sin tokens) hasta recibir un codigo valido; un codigo invalido responde 401 y no emite tokens. La desactivacion requiere un codigo valido. Todo el frontend del login y del enrolamiento esta i18n en es/en/pt/fr.

### Added
- `MfaService` (`src/Core/Authentication/Service/MfaService.php`): TOTP con `spomky-labs/otphp` ^11.5 (`generateSecret()` con 20 bytes aleatorios en base32 RFC4648 sin padding, `buildProvisionUri()`, `verifyTotp()`, `setup()`, `enable()` con 10 codigos de respaldo en bcrypt, `disable()`, `verify()` que acepta TOTP o consume un codigo de respaldo, `status()` con `backup_codes_remaining`); singleton en `bootstrap.php`.
- Columnas en `users` (BD viva): `mfa_secret` varchar(255) y `mfa_backup_codes` longtext `CHECK(json_valid)`; `schema.sql` regenerado (SIN DRIFT).
- Endpoints `GET /api/auth/mfa/status` y `POST /api/auth/mfa/setup|enable|disable` (Auth + Audit + RateLimit 10/300); rama MFA en el login con rate limit propio (3/300 por email y 5/300 por IP) y limpieza de intentos tras exito.
- `resources/locales/{es,en,pt,fr}.json`: bloque `login.mfa_*` y `profile.mfa_*` completos (pt/fr con traducciones propias).
- `tools/smoke/smoke-profile-mfa.mjs`: smoke jsdom propio del modulo profile (tab Seguridad): lazy-load del estado MFA, setup con QR svg + secreto en `<details>`, enable (flag mutable, codigos de respaldo una sola vez, campo requerido), estado activo (badge, remaining, disable con y sin codigo) y cancel (29 checks).
- `public/js/vendor/qrcode.js`: libreria vendorizada `qrcode-generator` 1.4.4 (Kazuhiko Arase, MIT, UMD) para generar el QR de enrolamiento en cliente; cargada en `public/index.php` con cache-busting `?v=` como el resto del JS (sin CDN: funciona offline y sin depender de terceros).

### Changed
- `AuthController::login()`: con MFA habilitado devuelve 200 `{mfa_required:true}` sin tokens hasta validar el codigo; el codigo se recibe crudo (sin filtrar no-digitos en el controller) para que los codigos de respaldo alfanumericos funcionen.
- `public/js/modules/auth/login.js`: estado `mfaPending`, grupo de codigo oculto/mostrado con `enterMfaMode()`/`exitMfaMode()`, reenvio con `mfa_code`, 401 en modo MFA marca el campo y permanece en pantalla.
- `public/js/modules/profile/profile.js`: la pestaña Seguridad (hasta ahora solo el card de cambio de contrasena) incorpora un card de MFA TOTP servido por `renderMfaCard()`: setup con **QR SVG escaneable** generado en cliente desde la `otpauth_url` del backend — con la libreria vendorizada, caja blanca fija para que se escanee tambien en tema oscuro, con fallback al secreto manual si la libreria no carga —, el secreto base32 y la URI otpauth en un `<details>` colapsable "No puedes escanear?", confirmacion con codigo, lista de codigos de respaldo, estado activo (badge, remaining, disable con codigo) y cancel; el estado MFA se carga de forma perezosa (`loadMfa()`) al entrar en la pestana; acciones `profile-mfa-setup|enable|disable|cancel|backup-done`; reset del estado MFA en `destroy()`. El enrolamiento es servicio personal.
- `public/js/modules/settings/settings.js`: el card y la seccion de enrolamiento MFA se eliminan por completo (estado, `renderMfaSection()`, `loadMfa()`, acciones y resets); la pestaña Seguridad queda solo con caducidad de contrasena y SMTP. Configuracion deja de exponer MFA: un unico punto de gestion, en el Perfil.
- `tools/smoke/smoke-v11358.mjs`: sin MFA (movido al perfil); handler por defecto y escenario S3b eliminados; fixture `plataforma_version` a 1.15.0 (68 checks).

### Notes
- Opt-in por usuario: no hay ajuste global de MFA en el tenant; `GET /auth/me` y `login.user` no exponen `mfa_secret` ni `mfa_backup_codes`. La gestion (activar/desactivar) vive unicamente en el Perfil del usuario; Configuracion no la expone.
- Codigos de respaldo: 10 de un solo uso, se muestran una vez; el TOTP se normaliza a digitos y el respaldo se compara sin guiones ni espacios (hash bcrypt del codigo sin guion).
- Verificado en vivo: setup, enable, login con TOTP (200), login con codigo de respaldo (200), reutilizacion de respaldo (401), codigo invalido (401) y disable; login MFA de `aleon@acme.local` (unico usuario con MFA activo) re-verificado en vivo tras el traslado; `composer test` 969/969; smokes 68+29+89+105 OK; `check-schema-drift` SIN DRIFT; `check-data-integrity` DATOS CONSISTENTES.
- Desviacion reportada sin tocar: `OTHPHP\TOTP` no resuelve via CLI de PHP (`class_exists` = false pese a que el prefijo PSR-4 esta registrado en el autoloader regenerado); bajo Apache/mod_php carga y funciona (login MFA verificado). Afecta solo a scripts temporales de CLI; ningun codigo de producto depende de OTPHP en CLI.
- Deuda preexistente reportada sin tocar: 214 avisos de claves i18n sin uso, advisory de `phpunit/php-jwt`, tests de mensaje dependientes del orden de ejecucion (`BASE_PATH`).

## [1.14.2] - 2026-10-16

**Refactor de almacenamiento: los ajustes del tenant dejan de vivir en el JSON `tenants.configuracion` y pasan a columnas individuales; la columna `configuracion` se elimina.** Todos los datos del formulario de Configuracion que hasta ahora se persistian anidados en la columna `configuracion` (direccion, color primario, moneda, formato de fecha, caducidad de contrasena y el bloque SMTP completo) se guardan ahora cada uno en su propia columna de `tenants`, y la columna `configuracion` se dropea de la tabla. El contrato de API no cambia: `GET/PATCH /api/tenant` siguen exponiendo y aceptando `configuracion` anidada; el backend la compone desde las columnas al leer y la descompone al escribir. Corrige ademas un bug: `MailerService` recibia `smtp.password` ya enmascarada (null) porque `TenantService::findById` devuelve la version publica; ahora lee la entidad cruda via `TenantService::findEntity()` y autentica de verdad contra el SMTP.

### Added
- 12 columnas en `tenants` (todas nullable, anidadas tras `dominio_custom`): `direccion` varchar(500), `color_primario` varchar(7), `moneda` varchar(10), `formato_fecha` varchar(20), `caducidad_password_dias` int unsigned, `smtp_host` varchar(255), `smtp_puerto` int unsigned, `smtp_encryption` varchar(10), `smtp_username` varchar(255), `smtp_password` varchar(1024) (cifrada), `smtp_from_email` varchar(255), `smtp_from_name` varchar(255). Migracion de datos del JSON a columnas y `configuracion` a NULL en la BD viva.
- `TenantService::findEntity(string $id): ?Tenant`: devuelve la entidad cruda (password SMTP sin enmascarar) para uso interno (MailerService).

### Changed
- `TenantRepository`: nuevos helpers `composeConfig()` (reconstruye el array `configuracion` desde las columnas al hidratar) y `decomposeConfig()` (la descompone al persistir); `hydrate()` aplica la composicion en todos los find; `save()`/`update()` escriben las 12 columnas y dejan de usar la columna `configuracion`.
- `MailerService::sendTest()`: usa `findEntity()` y lee la config desde la entidad, no desde el array publico enmascarado.
- `UserService::passwordExpiryFromPolicy()`: lee `caducidad_password_dias` de su columna (antes decodificaba el JSON `configuracion`).
- `tools/smoke/smoke-v11358.mjs`: fixture de version a 1.14.2.

### Notes
- Sin cambios de contrato de API ni de frontend; el modulo settings sigue leyendo/escribiendo `configuracion` anidada.
- Columna `configuracion` eliminada de `tenants` (DROP); `metadatos` sigue siendo JSON propio y no se toca.
- `MailerService`: la password SMTP se limpia de espacios antes de autenticar (`preg_replace('/\s+/','',...)`), porque las App Password de Gmail se pegan con espacios de grupo (`xxxx xxxx xxxx xxxx`) pero el servidor las exige sin ellos en AUTH LOGIN.
- Verificado contra la BD viva (`xproject`): `composer test` 969/969, `check-schema-drift` SIN DRIFT, `check-data-integrity` DATOS CONSISTENTES, smokes 68+89+105 OK; PATCH escribe en columnas (SQL directo) y la API compone `configuracion` desde las columnas.

## [1.14.1] - 2026-10-16

**SMTP con PHPMailer y politica de caducidad de contrasena: formulario de configuracion completa del tenant en 4 pestanas con un unico boton de guardar.** El modulo de Configuracion se reorganiza en General (identidad, perfil y logo), Apariencia, Localizacion (pais ISO 3166-1 alpha-2, zona horaria, idioma, moneda y formato) y Seguridad (caducidad de contrasena, hueco MFA y SMTP); un solo boton global recoge los diffs de todos los formularios y envia un unico PATCH. El password SMTP se cifra en la BD con `EncryptionService`, se oculta en toda respuesta API (flag `password_configurada`) y nunca se reenvia al frontend. Sin cambios de esquema.

### Added
- `POST /api/tenant/smtp/test` (`tenants.tenant.edit` + ADMIN, rate-limitado): envia un correo de prueba con el SMTP del tenant via PHPMailer (`sendTest(tenantId, ?email_destino)`, destino por defecto `email_contacto`); fallo de envio -> `TenantException` 502 con `api_errors.tenants.smtp_send_failed`.
- `src/Core/Tenants/Service/MailerService.php`: transporte SMTP con PHPMailer 7 (descifra `smtp.password` con `EncryptionService::decryptFromStorage`, TLS/SSL segun `smtp.encryption`); singleton registrado en `bootstrap.php`.
- `TenantService`: validacion de la seccion `smtp` (host <=255, puerto 1-65535, encryption en `SMTP_ENCRYPTS`, username <=255, password <=1024, from_email email, from_name <=255) y de `seguridad.caducidad_password_dias` (entero 1-365 o null); `normalizePais()` exige codigo ISO 3166-1 alpha-2 (normaliza a mayusculas; `TenantException::invalidPais`).
- `TenantService::update()`: si el PATCH trae `smtp.password`, se cifra con `EncryptionService::encryptForStorage` antes del merge; null/vacio conserva la guardada.
- `UserService::changePassword()`: aplica la politica del tenant (`seguridad.caducidad_password_dias`) fijando `fecha_expiracion_password` (helper `passwordExpiryFromPolicy()`).
- `resources/locales/{es,en,pt,fr}.json`: `api_errors.tenants.pais_invalid/smtp_not_configured/smtp_no_destino/smtp_send_failed`; bloque `settings.*` ampliado (4 pestanas, boton unico `btn_save_all`, campos SMTP, caducidad, MFA hueco, paises ISO).

### Changed
- `public/js/modules/settings/settings.js`: rediseno a 4 pestanas (general/apariencia/localizacion/seguridad) con **un unico boton global** `settings-save-all` que construye un PATCH combinado (diffs de todos los formularios); pais como `<select>` ISO 3166-1 alpha-2 (249 codigos + `Intl.DisplayNames`); form SMTP con password write-only (placeholder `••••••` si `password_configurada`, vacio = no cambiar) y boton de prueba; caducidad de contrasena 1-365; MFA como hueco declarado (`UI.emptyState`); sin pestaña Resumen ni form de URL de logo (solo subida); el campo `smtp.password` solo se incluye en el payload si el admin escribio una nueva (sin diff espurio).
- `Tenant::toPublicArray()`: enmascara `smtp.password` a null y anade `password_configurada` (flag antes del null).
- `PlatformSeeder`: `TENANT_DATA['pais'] = 'MX'` (dato vivo migrado de `'México'` a `'MX'`).
- `tools/smoke/smoke-v11358.mjs`: reescrito para el nuevo layout (4 pestanas, boton global, select ISO, form SMTP, caducidad, sin stats): 68 checks.

### Notes
- Hueco declarado: MFA/TOTP sin implementar (seccion visual con `UI.emptyState`, sin endpoint ni logica).
- El endpoint `GET /api/tenant/stats` sigue existiendo pero la UI de settings ya no lo consume.

## [1.14.0] - 2026-10-15

**Interfaz estilo iOS (sin transparencias) y modulo de Configuracion del tenant completo: Perfil, Apariencia y Preferencias regionales.** Rediseno visual de toda la app sobre los tokens existentes (sin cambiar estructura ni selectores de las vistas) y cierre del modulo settings con edicion del correo de contacto, telefono, direccion, subida de logo, color de marca aplicado a todos los usuarios del tenant y formatos regionales (moneda y fecha) aplicados en los listados. Sin cambios de esquema: los datos nuevos viven en la columna existente `tenants.configuracion` (JSON).

### Added
- `GET /api/tenant/branding` (cualquier usuario autenticado del tenant): `nombre_publico`, `logo_url`, `zona_horaria`, `idioma_default`, `apariencia`, `regional` (`Tenant::toBrandingArray()`, sin plan ni contacto).
- `POST /api/tenant/logo` (multipart `logo`, `tenants.tenant.edit` + ADMIN, auditado): PNG/JPG/WebP hasta 800 KB validados con `finfo` + `getimagesize` (SVG excluido por XSS); se guarda en `public/uploads/tenants/{tenant_id}/logo-{ts}.{ext}`, borra los logos anteriores y fija `logo_url` a la ruta relativa. `public/uploads/.htaccess` impide ejecutar scripts y listar el directorio; `/public/uploads/*` en `.gitignore` (salvo el `.htaccess`).
- `public/js/core/branding.js` (`TenantBranding`): carga la marca tras login/arranque, cache en `localStorage['xp.branding']`, aplica el color a `--color-primary`/`-hover`/`-foreground`/`--color-info` (recalculado en claro/oscuro, contraste del texto calculado), vista previa sin guardar y evento `branding:changed`.
- `UI.formatDate`, `UI.formatDateTime`, `UI.formatMoney` (`public/js/ui/dom.js`) con el formato de fecha y la moneda del tenant; fechas "naive" de la BD se muestran tal cual (sin conversion de zona), ISO con zona se convierten a `zona_horaria`.
- `public/css/ios.css`: capa visual iOS opaca (large titles, sidebar estilo iPadOS con item activo relleno, tarjetas inset grouped, campos "filled", control segmentado para pestanas, interruptor verde, tablas tipo lista, modales/menus/toasts con radios iOS, boton `is-loading`).
- Configuracion: pestanas Perfil (nombre, correo de contacto, telefono, direccion, logo por subida o URL), Apariencia (8 colores + selector libre + vista previa en vivo + restablecer) y Preferencias regionales (pais, zona horaria con `Intl.supportedValuesOf`, idioma, moneda y formato de fecha con muestra en vivo). Validacion inline (correo, telefono, slug, URL, tamano/tipo de logo), botones con estado de carga y toasts.
- Sidebar: logo o inicial del tenant con el color de marca + nombre de la organizacion.
- `tests/Unit/TenantSettingsTest.php` (14 tests): contacto en `toPublicArray`, `toBrandingArray`, logo, URL de logo, fusion y validacion de `configuracion`.
- `resources/locales/{es,en,pt,fr}.json`: claves `settings.*` nuevas (perfil, apariencia, regional, logo, colores, monedas) y `api_errors.tenants.email_contacto_invalid/logo_url_invalid/configuracion_invalid/logo_file_invalid`.

### Changed
- `public/css/themes.css`: paleta iOS en claro (fondo agrupado `#f2f2f7`, superficies blancas, acento `#0066d6` con AA 5.4:1) y oscuro (fondo negro, `#1c1c1e`/`#2c2c2e`, acento `#0a84ff`); radios 6/10/14/18 px; sombras suaves; pila tipografica SF Pro / Segoe UI Variable.
- `public/index.php`: carga `ios.css` y `core/branding.js`; `TenantBranding.init()` en el arranque; `tailwind.config` con `primary` = `rgb(var(--color-primary))` y radios reconciliados.
- `Tenant::toPublicArray()` incluye `email_contacto` y `telefono` (el correo de contacto ya se puede editar desde la UI).
- `TenantService::update()`: `configuracion` se **fusiona por secciones** (un PATCH parcial no borra el resto; `null` elimina la clave) y se valida (`perfil.direccion` <= 500, `apariencia.color_primario` `#RRGGBB`, `regional.moneda` en catalogo, `regional.formato_fecha` en `FORMATOS_FECHA`).
- Fechas de listados con `UI.formatDate*`: productos, ubicaciones, conteos, envios, entregas, picking, incidencias, usuarios (ultimo acceso, permisos temporales), grupos y auditoria.
- `LocaleDictionaryService`: la clave de Redis incluye el `filemtime` del diccionario (antes un `git pull` con claves nuevas quedaba oculto hasta 1 h por el cache).
- `tools/smoke/smoke-v11358.mjs`: 7 pestanas, Perfil por defecto, `nombre_publico` visible (en la BD viva es texto plano) y escenario S7 (perfil, apariencia, regional y `UI.formatDate`): 61 checks.

### Fixed
- `PATCH /api/tenant` ignoraba `logo_url` (la UI lo enviaba desde 1.13.59 pero nunca se guardaba).
- Filtros de inventario: el boton limpiar caia solo a una segunda linea; cabecera de vista sin separacion entre subtitulo y accion en movil.

### Verification
- `php -l` en los PHP tocados; `composer test`: 958 tests / 6849 aserciones OK; `php bin/check-schema-drift.php` SIN DRIFT; `php bin/check-data-integrity.php` DATOS CONSISTENTES; `php bin/check-i18n.php` 0 errores.
- `node --check` en los JS tocados; los 13 smokes jsdom en verde (smoke-v11358 61/61, v162 89/89, v171 105/105, v193 136/136...).
- E2E en navegador (Playwright) contra PHP 8.4 + MariaDB con el dump de trabajo: perfil, logo, color, regional, permisos de no-admin y revision visual claro/oscuro/movil.

### Deviations (reportadas, no tocadas)
- El engranaje del header se muestra a usuarios sin `tenants.tenant.view` (llega al estado de permiso denegado).
- Los filtros de fecha de auditoria/devoluciones usan `input type=date` nativo (formato del navegador, no el del tenant).
- `Tenant::$nombrePublico` en la BD viva es texto plano ("Acme Corporation"), no el ciphertext residual documentado en 1.13.57.
- Contraste del acento oscuro `#0a84ff` con texto blanco: 3.65:1 (AA para componentes y texto grande; el texto de botones va en 600).

## [1.13.59] - 2026-10-15

**Bloque C frontend del módulo settings: edición inline de la configuración del tenant (sin modales).** Con `tenants.tenant.edit` (fallback rol ADMIN) las pestañas General y Localización pasan de lectura a formulario `UI.formFooter`: General edita `nombre_interno` (validación de slug en cliente con el mismo regex que la entidad backend, `^[a-z0-9][a-z0-9_\-]*[a-z0-9]$`, máx. 255) y Localización edita `pais`, `zona_horaria`, `idioma_default` (select con `profile.lang.*`), `logo_url` (http/https) y `dominio_custom` (vacío = remover → `null`). El envío calcula el diff contra el tenant cargado y hace `PATCH /api/tenant` sólo con los campos cambiados; sin cambios toast `info`, éxito toast `success` + re-render, errores de campo vía `err.data.field`. Sin `tenants.tenant.edit` la vista sigue siendo de solo lectura (mismo `labelValue`). `nombre_publico` sigue sin mostrarse ni editarse (ciphertext residual). Sin cambios de backend ni de esquema.

### Changed
- `public/js/modules/settings/settings.js` (v1.13.59): `canEdit()` con `UI.can('tenants.tenant.edit', ['ADMIN'])`; formularios inline en General y Localización con `UI.formFooter`; `validateNombreInterno`/`validateLogoUrl` en cliente; `diffFields` + `saveForm` (PATCH sólo con cambios, guardado con botón deshabilitado); binding de `submit` en `init`/`onActivate`/`destroy`; select de idioma reutiliza `profile.lang.*` (literales fuera de `t()` eliminados).
- `resources/locales/{es,en,pt,fr}.json`: claves `settings.btn_save/btn_saving/success_update/update_error/error_nothing_changed/field_required/nombre_interno_invalid/nombre_interno_max_length/logo_url_invalid/placeholder_nombre_interno/help_nombre_interno/help_dominio_custom`.
- `tools/smoke/smoke-v11358.mjs`: nuevo escenario S6 (47 checks, antes 32): forms con/sin permiso de edición, slug inválido sin PATCH, toast info sin cambios, PATCH diff de un solo campo y reflejo del valor tras éxito.

### Verification
- `node --check` (settings.js) y JSON de 4 locales OK; `node tools/smoke/smoke-v11358.mjs`: 47/47.
- Regresión: `smoke-v162.mjs` 89/89, `smoke-v171.mjs` 105/105; `php bin/check-i18n.php`: 0 errores (205 avisos preexistentes).
- Vivo (sesión ADMIN): `PATCH /api/tenant` round-trip `zona_horaria` (America/Mexico_City → America/Bogota → America/Mexico_City) 200/200 con el valor restaurado byte a byte; `php bin/check-schema-drift.php` SIN DRIFT.

## [1.13.58] - 2026-10-15

**Bloque B frontend del módulo settings: vista de solo lectura de la configuración del tenant.** Nueva ruta `#/settings` (menú Sistema, rol ADMIN) con el módulo `settings/settings` registrado en el loader. Cinco pestañas (`UI.viewShell` + estado en `?tab=`): General (nombre interno, ID, estado, plan, configuración adicional y versión de plataforma desde `GET /api/tenant`), Localización (país, zona horaria, idioma, logo, dominio), Seguridad y Correo como pantallas de hueco declarado (la política de contraseñas y el SMTP no tienen formulario ni endpoint de edición) y Resumen con `UI.summaryCards` sobre `GET /api/tenant/stats`. Gate de permiso `tenants.tenant.view` (fallback rol ADMIN) con `UI.deniedState`. `nombre_publico` NO se muestra: es ciphertext residual documentado en 1.13.57. Sin cambios de backend ni de esquema.

### Added
- `public/js/modules/settings/settings.js` (nuevo): `SettingsSettingsModule` con `UI.viewShell`, pestañas con `state.tab` + `UI.tabFromParams`/`applyTabState`/`syncTabParam`, estados `UI.loadingState`/`errorState` (con retry)/`deniedState`/`cardsSkeleton` y resumen con `UI.summaryCards`.
- `public/js/core/loader.js`: registro de `settings/settings`.
- `resources/locales/{es,en,pt,fr}.json`: bloque `settings.*` (títulos, pestañas, etiquetas, huecos de seguridad/correo, permiso y contadores).
- `tools/smoke/smoke-v11358.mjs` (nuevo): 32 checks (registro, denied sin permiso, lectura con permiso, ciphertext oculto, tab por params, error+retry).

### Verification
- `node --check` (settings.js, loader.js) y JSON de 4 locales OK.
- `node tools/smoke/smoke-v11358.mjs`: 32/32.
- Regresión de smokes que cargan el loader: `smoke-v162.mjs` 89/89, `smoke-v171.mjs` 105/105.
- `php bin/check-i18n.php`: 0 errores (205 avisos preexistentes).
- Vivo (sesión ADMIN): `GET /api/tenant` y `GET /api/tenant/stats` 200; `php bin/check-schema-drift.php` SIN DRIFT.

## [1.13.57] - 2026-10-15

**Bloque A backend del módulo settings de tenant: identidad interna editable, `configuracion` y versión de plataforma en el GET.** El nombre interno del tenant (`tenants.nombre_interno`) pasa a ser editable por el ADMIN del tenant vía `PATCH /api/tenant`, con el mismo patrón de ceguera de datos que el resto del módulo: se actualiza `nombre_interno` junto a su `nombre_interno_hmac` (HMAC-SHA256, `BlindIndexService`) y la unicidad se comprueba con `existsByNombreInterno($excludeId)` más captura de SQLSTATE 23000 → 409. `GET /api/tenant` añade `nombre_interno`, `configuracion` (hoy `null`, hueco declarado) y `plataforma_version` (leída de `APP_VERSION` en `.env`, sin fallback versionado en el repo). Sin cambios de esquema.

### Added
- `Tenant::actualizarNombreInterno()` y `nombre_interno`/`configuracion` en `toPublicArray()`; validación de slug reutilizada (`^[a-z0-9][a-z0-9_\-]*[a-z0-9]$`, máx. 255) con claves i18n propias.
- `TenantRepositoryInterface::existsByNombreInterno(?string $excludeId)` + implementación; el `UPDATE` del repositorio escribe `nombre_interno` y recalcula `nombre_interno_hmac`.
- `TenantService::update()` con rama `nombre_interno` (lowercase/trim + validación de entidad).
- `TenantController::$allowedFields` con `nombre_interno`; `show()` devuelve `plataforma_version` y propaga `messageKey` de las `TenantException` en el catch de `update()`.
- `resources/locales/{es,en,pt,fr}.json`: `api_errors.tenants.nombre_interno_already_exists` y `nombre_interno_invalid`.
- `.env` (gitignored): `APP_VERSION=1.13.57` y `TEMP_PASSWORD_TTL_DAYS=7` (fijo el fallback implícito de `UserService`).

### Fixed
- `TenantException`: `withMessageKey()`/`getMessageKey()`/`getMessageParams()` no existían pero se invocaban en los factories (fatal latente a la primera llamada real); fluent añadido.
- `Tenant::$nombreInterno` era `readonly` y `actualizarNombreInterno()` murió con 500 en vivo (`Cannot modify readonly property`); ahora es editable como el resto de campos mutables (`id`, `fechaCreacion`, `createdAt` siguen readonly).

### Verification
- `php -l` (6 PHP) y JSON de 4 locales OK; `composer test`: 944 tests / 6818 aserciones OK; `php bin/check-schema-drift.php`: SIN DRIFT (31 tablas); `php bin/check-data-integrity.php`: DATOS CONSISTENTES; `php bin/check-i18n.php`: 0 errores.
- Vivo contra BD viva (Apache, sesión ADMIN `aleon@acme.local`): `GET /api/tenant` y `GET /api/tenant/stats` 200 con los campos nuevos; `PATCH` rename `acme-corp`→`acme-corp-verify`→`acme-corp` 200 con `nombre_interno_hmac` idéntico antes/después (round-trip limpio); `PATCH` con slug inválido 400 traducido por `ErrorCatalog` (verificado en `es` y `en`); `pais` restaurado byte a byte (`HEX` = `4DC3A97869636F`).

### Deviations (reportadas, no tocadas)
- `TenantRepository::save()` (INSERT) no escribe `nombre_interno_hmac`; el índice `uq_tenants_nombre_interno_hmac` es `NULL`-tolerante, pero cualquier alta futura queda sin blind index hasta que se añada.
- En la BD viva `admin@acme.local` ya no es ADMIN (sólo USER+VIEWER; único ADMIN `aleon@acme.local`), desviándose de lo documentado en 1.13.45/1.13.47 (`manager@` además hoy sin rol asignado).
- `GET /api/tenant` sigue exponiendo `nombre_publico` como ciphertext residual (sin descifrado de PII de tenant); el módulo settings no lo mostrará.

## [1.13.56] - 2026-10-15

**Cabecera del modal de rol: acciones "Solo lectura" y "Limpiar" integradas en la linea de campos.** Los dos botones del toolbar (`btn-ghost btn-sm`, ~23px sin fondo) flotaban junto al select "Basar en" (~39px con borde) y rompian la linea de la cabecera. Opcion elegida (de las dos planteadas): mantenerlos en el toolbar —el pie sigue siendo sólo Cerrar/Crear rol— dandoles el mismo alto, fondo y borde que `.input`/`.select`.

### Changed
- `public/css/components.css` — `.pp-toolbar [data-pp-readonly-action]` y `.pp-toolbar [data-pp-clear]`: alto 2.4375rem (mismo que los campos), padding y tipografia de input, fondo `--color-background` y borde `--color-border`; hover sobre el fondo nuevo. Aplica a los modales Nuevo y Editar rol (mismo toolbar).

### Verification
- `node tools/smoke/verify-modal-roles.mjs`: 15/15 (los tres checks de linea base de la cabecera siguen en verde en claro y oscuro; capturas actualizadas).

## [1.13.55] - 2026-10-15

**Corrección de doble scroll en el combobox de modal.** El modal "Anadir miembro" de Grupos aparecía con dos barras de scroll: la del `modal-body` y la de la propia lista de sugerencias. Causa: la lista `.combo-list` (posición absoluta) se salía del recuadro visible del `modal-body` (altura pequeña del modal md), lo que crecía el `scrollHeight` del body y activaba su `overflow-y: auto`.

### Fixed
- `public/js/ui/modal.js` — `bindCombobox`: nueva `positionList()`, invocada al abrir la lista y al pintar sus opciones. Sólo interviene si el contenido no cabe: recorta el `max-height` al hueco visible bajo el control (o lo abre hacia arriba si cabe arriba); sin hueco en ningún lado, ancla la lista al viewport (`position: fixed`, mismo ancho y x que el control) como un desplegable estándar. Se reposiciona con `resize` y con el scroll del `modal-body`; `closeList` limpia los estilos inline.
- `public/css/components.css` — comentario de `.combo-list` actualizado al nuevo contrato.

### Verification
- `node tools/smoke/verify-modal-add-member.mjs`: 15/15 (antes 13; +2 checks de regresión: `modal-body` sin scroll con la lista abierta y tras elegir chip).
- `node tools/smoke/verify-modal-grant.mjs` 14/14 y `node tools/smoke/verify-modal-roles.mjs` 15/15 (los otros dos usan combobox/mismo `modal.js`).
- 12 smokes jsdom: 883/883. `php bin/check-schema-drift.php`: SIN DRIFT.

## [1.13.54] - 2026-10-15

**M3 — Modales "Nuevo rol" y "Editar rol": alineación de la cabecera (sólo presentación).** Corrección de la línea base de `.pp-head-row` en `public/css/components.css`: el `margin-bottom: 1rem` interno del form-group de Nombre, dentro de la rejilla con `align-items: end`, subía el campo 16px respecto al toolbar ("Basar en" + botones). Sin cambios en `roles.js`, de i18n ni de backend.

### Changed
- `.pp-head-row > .form-group { margin-bottom: 0 }`: Nombre, su etiqueta y el toolbar (select "Basar en", "Solo lectura", "Limpiar") comparten ahora línea superior e inferior en ambos modales (create y edit; el modal de Permisos no lleva form-group en la cabecera y no se ve afectado).
- `docs/UI_STANDARD.md` §6.4: nueva regla de cabecera con toolbar.

### Verification
- `node tools/smoke/verify-modal-roles.mjs` (nuevo, Playwright 1366×768, claro/oscuro): 15/15 — etiquetas y líneas base de la cabecera (±2px) en crear y editar, descripción centrada a ancho completo, misma línea izquierda con "Permisos del rol", picker y resumen del pie visibles, casilla "Rol activo" presente y **ciclo autocontenido de rol temporal (alta→editar→borrado) que deja la BD en sus 4 roles BUILT_IN**. La primera versión del chequeo de limpieza daba un falso positivo: `tbody tr` resolvía contra el esqueleto de carga; se espera ahora a que el tbody tenga texto real. Capturas en `tools/smoke/capturas-modales/`.
- Los 12 smokes en verde (883 checks), `php bin/check-schema-drift.php` SIN DRIFT, GETs read-only (`/roles`, `/roles/{id}/permissions`, `/permissions`) con admin: todos 200.

## [1.13.53] - 2026-10-15

**M2 — Modal "Otorgar permiso temporal": layout (sólo presentación).** Reordenación del body de `grantBody` en `public/js/modules/groups/../users/users.js` con espaciado uniforme, alineación de columnas y el par Duración|Fecha como control segmentado. Mismos campos, mismos `name`/handlers (`change` de los radios nativos) y mismo envío; sin cambios de backend ni de i18n.

### Changed
- **Sin columnas fantasma**: `UI.grid(…, 2)` con un solo hijo (Permiso) y `UI.grid(…, 1)` envoltorio (Motivo) pasan a campo plano a ancho completo; ambos miden lo mismo y alinean con el resto de la columna.
- **Beneficiario alineado**: las dos celdas de la rejilla (buscador con botón "Buscar" / select "Usuario") mantienen la misma altura interna (sin `mb-0` asimétrico) y las etiquetas arrancan a la misma altura; `items-center` explícito en la fila input+botón.
- **Segmentado `Duración | Fecha`** (`.segmented > label.segment` en `public/css/components.css`): pista con borde/radio del tema y estado activo por `:has(input:checked)` con acento del tema; los radios nativos se conservan dentro de cada segmento (mismo `name`, `valor` y handler `change` — sólo cambia la presentación, con fallback visible sin `:has()`).
- **Ritmo de campos**: los paneles de horas/fecha salen del `form-group` anidado y quedan a un nivel, con el margen uniforme de `form-group` (1rem) y el hint de duración al final.
- `docs/UI_STANDARD.md` §6.4: dos reglas nuevas (sin columnas fantasma; elección binaria con `.segmented` conservando los radios).

### Desviaciones (reportadas, no corregidas por alcance de layout)
- `users.grant.max_hint` ("Duración máxima según la criticidad del permiso") es **falso**: el backend no tiene máximo por criticidad, sólo la cota global `TEMP_PERMISSION_MAX_DAYS` (default 30 días), no expuesta por ningún endpoint. Texto intacto por decisión de alcance (sólo layout).
- Funcionalidades previstas del plan original de M2 descartadas por decisión del usuario: combobox de beneficiario, badge de criticidad en el permiso, contador de motivo, atajos 1h/4h/8h/24h y duración máxima por criticidad. Quedan fuera de este bloque.

### Verification
- `node tools/smoke/smoke-v1120.mjs` sin modificar: 155/155 (el flujo del modal sigue intacto). Los 12 smokes en verde (883 checks) y `node --check` en los JS tocados.
- `node tools/smoke/verify-modal-grant.mjs` (nuevo, Playwright 1366×768, claro/oscuro): 14/14 — ancho md, celdas iguales y etiquetas alineadas, permiso y motivo a ancho completo, segmentado 2 segmentos con cambio por `change` nativo, sin solapes e hint visible; capturas en `tools/smoke/capturas-modales/`.
- `php bin/check-i18n.php` 0 errores/203 avisos, `php bin/check-schema-drift.php` SIN DRIFT, GETs read-only del módulo (`/users`, `/temporary-permissions`, `/permissions`) con admin: todos 200.

## [1.13.52] - 2026-10-15

**M1 — Modal "Añadir miembro" de Grupos: combobox multi-selección con sugerencias y chips.** Nuevo componente compartido `UI.combobox` + `UI.bindCombobox` en `public/js/ui/modal.js` (documentado en `docs/UI_STANDARD.md` §6.6) y migración del modal de alta de miembros de `public/js/modules/groups/groups.js` (select+buscador manual → combobox con debounce, chips seleccionables y teclado completo). Sin cambios de backend.

### Added
- `UI.combobox({name, label, placeholder, hint, required})`: HTML estándar (label + `input[role=combobox]` con `aria-expanded`/`aria-controls`/`aria-autocomplete`, chips y `ul[role=listbox]` dentro de `.combo-control`, error `data-error` y hint).
- `UI.bindCombobox(root, {fetch, onSelect, isExcluded, loadingLabel, emptyLabel, errorLabel, debounceMs})`: búsqueda con debounce 250 ms, respuestas fuera de secuencia descartadas (`seq`; cerrar la lista invalida las cargas en vuelo), primera opción resaltada (`aria-activedescendant`), **Enter con la lista visible se consume aquí** (elige la resaltada; con la lista cerrada envía el modal), **Esc cierra primero la lista** y el modal sólo con la siguiente Esc, y al elegir se reabre la lista con los candidatos restantes (multi-selección).
- Estilos `.combo-*`, `.chip` y `.chip-remove` en `public/css/components.css`; los chips van dentro de `.combo-control` para que la lista absoluta caiga bajo el conjunto y no tape los botones de quitar.
- Modal de alta de miembros migrado: selección en chips desde el estado del módulo (`combo-remove` con `aria-label`), **alta múltiple con POSTs secuenciales** a `/groups/{id}/members` (el backend acepta `user_id` único), toast plural (`added_multi`), **exclusión de miembros existentes y ya elegidos** (`isExcluded`), foco explícito al abrir, tamaño `md` y pie con `UI.modalFooter` (Cancelar vuelve al detalle, no cierra con la ×). Fallo parcial: retira de los chips los ya añadidos, marca el detalle para recargar al volver y pinta el error inline sin perder el resto de la selección.
- Claves nuevas en los 4 idiomas (`groups.members.combo_label`, `combo_placeholder`, `combo_empty`, `combo_loading`, `add_required`, `added_multi`); eliminadas 6 claves muertas del viejo buscador (`add_select`, `add_placeholder`, `search`, `search_placeholder`, `search_btn`, `search_empty`).

### Changed
- `docs/UI_STANDARD.md`: nuevo §6.6 "Combobox de modal" (errores y verificación pasan a §6.7).
- `tools/smoke/smoke-v1120.mjs`: la sección de añadir miembro se reescribe al nuevo flujo y el fixture de usuarios gana un candidato externo al grupo (155 checks).

### Huecos
- No existe `GET /groups/member-candidates` (reportado, fuera de alcance de este bloque): el combobox usa el endpoint existente `GET /users`, que `routes/api.php` acepta con `users.user.view` **o** `groups.group.manage_members`.

### Verification
- `node tools/smoke/smoke-m1-add-member.mjs` (nuevo): 56/56. Los 12 smokes en verde (883 checks) y `node --check` en los JS tocados.
- `node tools/smoke/verify-modal-add-member.mjs` (nuevo, Playwright 1366×768, tema claro/oscuro): 13/13, capturas en `tools/smoke/capturas-modales/` (la captura destapó un fallo real: la lista absoluta tapaba los chips; se movieron los chips dentro de `.combo-control`).
- `php bin/check-i18n.php` 0 errores/203 avisos, `php bin/check-schema-drift.php` SIN DRIFT, GETs read-only del módulo (groups, members, roles, users, roles-catalogo) con admin: todos 200.

## [1.13.51] - 2026-10-15

**M0 — Estándar de modales de formulario: Esc cierra, Enter envía, foco en el primer campo.** Nuevo apartado §6 de `docs/UI_STANDARD.md` (composición con `modalShell`, escala de tamaños, pie con `UI.modalFooter`, campos `field`/`grid`, teclado y foco) y su implementación en `public/js/ui/modal.js`, instalada una sola vez sobre `document` y válida para todos los modales (incluidos los preexistentes aún sin migrar). Sin cambios de backend.

### Added
- `UI.modalFooter({hintHtml, cancelLabel, cancelAction, submitLabel, submitAction, danger, disabled})` en `public/js/ui/modal.js`: pie estándar (hint `mr-auto` + Cancelar `type="button"`/`close-modal` + primario con `data-modal-send`).
- Comportamiento global en `public/js/ui/modal.js`: **Esc** cierra el modal superior (fase de captura; respeta menú de fila de `view.js`, `<select>` nativo y combobox con `aria-expanded`); **Enter** pulsa el primario del pie desde `input`/`select` dentro del modal (no desde `textarea`, `type=search`, campo con `aria-expanded="true"`/`data-no-enter-submit` ni dentro de `<form>`); **foco** en el primer campo al abrir por primera vez (`MutationObserver`, sin re-enfocar en re-render ni robar el foco que ponga el módulo).
- Tamaño `md` (`SIZES.md` → `.modal-md`) en `modal.js`.

### Changed
- **Anchos de modal** (`public/css/themes.css`): escala estándar sm 480 / md 640 (defecto) / lg 1000 / xl 1200 px (antes 384/512/768/1024); `.modal-pp` (1100×85vh) sin cambios. Nueva clase `.modal-md` en `public/css/components.css`.
- `UI.grid()` a **una columna por defecto** (los llamadas existentes pasaban la columna explícita, sin cambio visual).
- `UI.field()` textarea: clase `input textarea` (min-height/asas) y mínimo 4 filas.
- `docs/UI_STANDARD.md`: nuevo §6 "Estándar de modales de formulario"; la tabla de componentes (§1), las reglas de contenido (§7), el checklist (§8) y la auditoría de adopción (§9) se actualizan.

### Verification
- `node tools/smoke/smoke-modales-m0.mjs` (nuevo): 52/52. Smokes existentes en verde (v162 89/89, v171 105/105 y el resto: 824 checks).
- `node --check public/js/ui/modal.js`, `php bin/check-i18n.php` 0 errores, `php bin/check-schema-drift.php` SIN DRIFT.

## [1.13.50] - 2026-10-15

**.htaccess de la raíz redirige todo a `/public/` en vez de bloquear con 403.** `C:\xampp\htdocs\xp\.htaccess` (añadido/ajustado en el commit, sin entrada al changelog; aquí se registra retroactivamente): con `RewriteBase /xp/`, cualquier request que no empiece por `/public/` redirige a `public/$1` con `R=302` y los ficheros ocultos (`.env`, `.git`, `.htaccess`...) quedan con `Require all denied`.

## [1.13.49] - 2026-10-14

**Fix: selector de unidad física en modal de Reportar Incidencia era una lista expandida.** El `<select>` llevaba `size="6"`, que en HTML renderiza el dropdown como lista visible de 6 filas en vez de un select colapsado. Se quita el atributo para que se comporte como un dropdown normal con buscador.

## [1.13.48] - 2026-10-14

**Skeleton Loading en tablas: sin parpadeos ni layout shift durante la carga.** Se reemplaza el spinner centrado (`UI.loadingState`) por placeholders animados que mantienen la estructura, dimensiones y posiciones de la tabla mientras llegan los datos. Nuevas funciones `UI.tableSkeleton(columns, rows, widths)` (misma estructura que `tableShell` con filas placeholder shimmer) y `UI.cardsSkeleton(count)` (placeholders de tarjetas de resumen). Actualizados 10 módulos: users, groups, roles, counts, deliveries (2 tablas), incidents, movements, picking (2 tablas), reservations y shipments.

### Added
- `UI.tableSkeleton(columnas, filas, widths)` en `public/js/ui/render.js`: genera una tabla con thead real y filas placeholder animadas (clases `.skeleton` existentes en `components.css`). Los anchos por columna se especifican en % para que el skeleton reproduzca el layout real.
- `UI.cardsSkeleton(count)` en `public/js/ui/render.js`: placeholders de tarjetas con la misma altura y estructura que `summaryCards`.

### Changed
- **users.js**: tabla de usuarios y tabla de permisos temporales usan skeleton (8 y 6 filas).
- **groups.js**: tabla de grupos usa skeleton (6 filas).
- **roles.js**: tabla de roles usa skeleton (6 filas).
- **counts.js**: tabla de conteos usa skeleton (8 filas).
- **deliveries.js**: tabla en-ruta e historial usan skeleton (6 y 8 filas).
- **incidents.js**: tabla de incidencias usa skeleton (8 filas).
- **movements.js**: tabla de movimientos usa skeleton (8 filas).
- **picking.js**: tabla de reservas y tabla de picking usan skeleton (6 filas cada una).
- **reservations.js**: tabla de reservas usa skeleton (6 filas).
- **shipments.js**: tabla de envíos usa skeleton (6 filas).

## [1.13.47] - 2026-10-14

**Fix: permisos temporales de usuarios borrados siguen apareciendo.** El query de `TemporaryPermissionRepository` hacía `INNER JOIN users` sin filtrar `u.deleted_at IS NULL`, así que los permisos temporales de un usuario con soft delete seguían listándose. Se añade el filtro al WHERE compartido (`findAll`, `count`, `findById`).

## [1.13.46] - 2026-10-14

**Limpieza: bootstrap sin referencias muertas al cifrado PII.** Se quitan los argumentos `EncryptionService`/`BlindIndexService` que bootstrap seguia pasando a TemporaryPermissionRepository, UserGroupRepository, TenantRepository, PdoAuditPort y AuditLogQueryService — constructores que ya no los aceptan tras el rollback v1.13.44. Se elimina el singleton `BlindIndexService` (sin uso). Se conserva `EncryptionService` (lo usa SecretsVaultService para cifrar secretos) y los ficheros de seguridad (`EncryptionService`, `BlindIndexService`) quedan en el repositorio listos para reactivar cuando se decida.

## [1.13.45] - 2026-10-14

**Fix: mutaciones de roles no se reflejan en la tabla de usuarios sin recargar.** Tras asignar/quitar un rol o quitar de un grupo, el modal se actualizaba pero la columna Roles de la tabla conservaba los datos viejos hasta recargar la pagina completa. Ahora `refreshUserRow` actualiza la fila del usuario en `state.items` con los roles frescos del servidor y re-renderiza la tabla al instante.

## [1.13.44] - 2026-10-14

**Rollback del cifrado PII de usuarios: se revierte a texto plano.** El cifrado AES-256-GCM de PII (email, nombre, apellido, telefono en users; destinatario/conductor en shipments; JSON en audit_logs) se revierte porque rompe busquedas LIKE, ORDER BY y complicaba la plataforma sin beneficio operativo. Los datos en BD viva se descifraron primero (1762 filas) y luego el codigo de cifrado se elimino de todos los repositorios y servicios. La normalizacion BD (junction role_permissions, FKs, indices) se **conserva** — es independiente del cifrado.

### Changed
- **Revertidos a texto plano**: `UserService` (create/update/updateMe sin encryptField), `UserRepository` (findByEmail sin blind index, sin decryptRow), `TemporaryPermissionRepository` (sin decryptEmails), `UserGroupRepository` (sin decrypt en listMembers), `AuthController` (sin EncryptionService), `TenantRepository`, `TenantService`, `ShipmentRepository`, `DeliveryRepository`, `IncidentRepository`, `AuditLogQueryService`, `PdoAuditPort`, `RateLimitRepository`, `SecretsVaultService`, y los QueryServices de Inventory.
- **`UserRepository::getUserPermissions`** ahora usa junction `role_permissions` (conservado de N5e) en vez de `JSON_CONTAINS(r.permisos, ...)` — la columna `roles.permisos` ya no existe.
- **`bootstrap.php`**: UserRepository ya no recibe BlindIndexService/EncryptionService; TemporaryPermissionRepository y UserGroupRepository registrados sin EncryptionService.

### Data
- Descifrados 1762 filas en BD viva: users (6), shipments (29), deliveries (12), tenants (1), audit_logs JSON (1670), audit_logs user_agent (45). Backup pre-rollback en `database/backups/backup-antes-rollback-cifrado.sql`.

## [1.13.43] - 2026-10-14

**Fix: temporary-permissions devuelve emails cifrados; asignacion de rol no refleja cambio sin recargar.** Dos bugs reportados en vivo. (1) `TemporaryPermissionRepository` no descifraba `user_email`/`granted_by_email` (AES-256-GCM) al hidratar filas: la API devolvía el ciphertext base64 en crudo. (2) Al asignar/quitar rol o membresia de grupo al usuario actual de la sesion, el frontend no refrescaba `AuthState` — el sidebar y la UI conservaban los roles viejos hasta recargar la pagina.

### Fixed
- **`TemporaryPermissionRepository`**: constructor acepta `EncryptionService` opcional; `decryptEmails()` descifra `user_email` y `granted_by_email` tras `findAll()` y `findById()`. Fallback al valor en claro si falla. `bootstrap.php` inyecta `EncryptionService` en el container.
- **Frontend roles**: `users.js` (`assignRole`, `removeRole`, `submitRemoveGroup`) y `groups.js` (`addMember`, `removeMember`) llaman `AuthState.refreshUser()` si el usuario afectado es el de la sesion actual — el sidebar y permisos se actualizan sin recargar.

## [1.13.42] - 2026-10-14

**Normalizacion BD (N1-N5): integridad referencial, limpieza de tablas muertas y migracion de roles.permisos JSON a junction role_permissions.** Bloque completo de normalizacion de esquema. Tablas muertas dropeadas (notification_logs, notification_preferences, receiving_documents, receiving_document_lines). FKs anadidas a picking_operations, movements (self-FK), idempotency_keys. secrets_vault UNIQUE pasa a (tenant_id, nombre). roles.permisos (JSON array de identificadores) reemplazado por tabla junction role_permissions (role_id, permission_id) con FKs CASCADE — contrato API `permisos: string[]` sin cambios.

### Added
- **Tabla `role_permissions`** (role_id CHAR(36), permission_id CHAR(36), PK compuesto, FKs CASCADE a roles y permissions). 128 filas migradas desde el JSON de los 4 roles BUILT_IN.
- **FKs**: `picking_operations.actor_id_selector` y `supervisor_id` → users, `movements.movimiento_referencia_id` → movements (self-FK), `idempotency_keys.actor_id` → users.

### Changed
- **Lectura de permisos de rol**: `AuthorizationService::getRolePermissions`, `getPermissionsFromDirectRoles`, `getPermissionsFromGroupRoles`, `UserRepository::getUserPermissions`, `PermissionRepository::isAssignedToAnyRole`, `RoleRepository::findByPermisos` y `findById/findAll/findByTipo` ahora usan JOIN role_permissions. Se elimina `extractPermissionsFromRows` (decodificador JSON muerto).
- **Escritura de permisos de rol**: `RoleRepository::save()` y `update()` sincronizan la junction (delete+insert). Los 3 seeders (Inventory, PlatformControl, Audit) leen de junction para calcular faltantes y anaden via junction.
- **Hydratacion**: `AuthorizationService::fetchRoles` y `RoleRepository` hidratan `permisos` desde junction tras el SELECT. `Role::fromArray()` mantiene soporte in-memory.

### Removed
- **Columna `roles.permisos`** (JSON TEXT). Ya no existe en la BD viva ni en schema.sql.

### Fixed
- Permisos huerfanos `inventory.receiving.plan` y `inventory.receiving.confirm` (sin endpoint activo) removidos de roles y desactivados.

## [1.13.41] - 2026-10-14

**Indexacion BD: indices compuestos (tenant_id, fecha) para list endpoints.** Los listados de movements, shipments, deliveries, incidents, inventory_counts, reservations, picking_operations, return_operations y stock_ledger ordenan y filtran por `tenant_id` + columna de fecha. Sin indice compuesto, cada request ejecutaba un filesort sobre todas las filas del tenant. Ahora MySQL usa `idx_tenant_fecha` y el ordenado se resuelve en el indice. Anadido indice en `roles(tipo, activo)` para el filtro del seeder.

### Added

- **Indices compuestos `idx_tenant_fecha (tenant_id, fecha)`** en 9 tablas: `movements` (timestamp_movimiento), `shipments` (created_at), `deliveries` (timestamp_entrega), `incidents` (timestamp_deteccion), `inventory_counts` (fecha_conteo), `reservations` (created_at), `picking_operations` (created_at), `return_operations` (timestamp_devolucion), `stock_ledger` (ultima_actualizacion).
- **`roles.idx_tipo_activo (tipo, activo)`**: filtro del seeder y queries por tipo de rol.

### Verified

- `EXPLAIN` confirma uso de `idx_tenant_fecha` en movements, shipments, deliveries, stock_ledger (type=ref, Extra=Using index)
- `php bin/check-schema-drift.php` -> SIN DRIFT
- `php bin/check-data-integrity.php` -> DATOS CONSISTENTES
- `composer test` -> 944/944 OK
- `node tools/smoke/smoke-v162.mjs` -> 89 OK, 0 FAIL
- `node tools/smoke/smoke-v171.mjs` -> 105 OK, 0 FAIL
- API en vivo: users, shipments, deliveries, incidents, movements, audit -> todos 200

### Debt (produccion)

- **Rotacion de claves** (`bin/rotate-keys.php`): sin ella, si una clave se compromete, no hay salida. Requisito #1 antes de produccion.
- **Guardia de regresion** (`bin/check-plaintext-pii.php`): sin ella, un campo PII nuevo puede quedar en claro sin detectarse. Anadir al cierre de sprint junto a `check-data-integrity`.

## [1.13.40] - 2026-10-14

**B3 - Cifrado de PII restante: audit_logs JSON, shipments contactos, deliveries, incidents.** Cifra la PII que quedaba sin cifrar tras B2: contactos de shipments, campos de deliveries (destinatario_real, firma, observaciones), texto libre de incidents (descripcion, observaciones) y los JSON de audit_logs (valores_anteriores, valores_nuevos, detalles_adicionales). Los JSON con CHECK json_valid usan wrapper `{"__encrypted":"<base64>"}`. El hash de integridad del audit se calcula sobre claro antes de cifrar; al verificar la cadena hay que descifrar primero.

### Added

- **`bin/b3-backfill.php`**: backfill con `--dry-run`. Cifra shipments contactos, deliveries PII, incidents texto libre, incidents JSON y audit_logs JSON. Ejecutado contra BD viva.
- **`docs/ENCRYPTION.md`**: referencia completa de campos cifrados, algoritmos, claves, wrapper JSON, patrones de lectura, rotacion y recuperacion.

### Changed

- **`PdoAuditPort::encryptSensitiveFields`**: ahora cifra `valores_anteriores`, `valores_nuevos` y `detalles_adicionales` (ademas de ip_address/user_agent). JSON envueltos en wrapper `__encrypted` para cumplir CHECK json_valid.
- **`AuditLogQueryService::present`**: detecta wrapper `__encrypted` y descifra antes de `decodeJson`. Fallback para filas pre-migracion.
- **`ShipmentRepository::PII_FIELDS`** y **`ShipmentQueryService::PII_FIELDS`**: anadidos `destinatario_contacto` y `conductor_contacto`.
- **`DeliveryRepository`**: cifra/descifra `destinatario_real`, `firma_comprobante`, `observaciones`. Constructor acepta `?EncryptionService`.
- **`DeliveryQueryService`**: descifra PII en `mapRow`. Constructor acepta `?EncryptionService`.
- **`IncidentRepository`**: cifra/descifra `descripcion`/`observaciones` (texto) y `resolucion`/`restriccion_resolucion` (JSON wrapper). Constructor acepta `?EncryptionService`.
- **`IncidentQueryService`**: descifra PII en `mapRow`. Constructor acepta `?EncryptionService`.

### Verified

- `composer test` -> 944/944 OK
- `php bin/check-schema-drift.php` -> SIN DRIFT
- `php bin/check-data-integrity.php` -> DATOS CONSISTENTES
- `node tools/smoke/smoke-v162.mjs` -> 89 OK, 0 FAIL
- `node tools/smoke/smoke-v171.mjs` -> 105 OK, 0 FAIL
- API en vivo: login OK, users OK, shipments OK, deliveries OK, incidents OK (descripcion descifrada), audit OK
- BD viva: 0 filas en claro en todos los campos B3

## [1.13.39] - 2026-10-14

**B2 — Cifrado de `users.nombre`/`apellido` + cierre de huecos del Sprint 2.** Los nombres y apellidos de usuarios ahora se cifran AES-256-GCM. ORDER BY y LIKE sobre esas columnas se migran a PHP (filtrado y ordenación tras descifrar). Se cierran huecos detectados en la verificación B1: emails de usuarios que no se cifraron en el backfill del Sprint 2, PII de shipments que el filtro omitía, y el índice único HMAC que no ignoraba usuarios borrados.

### Added

- **`bin/b2-backfill.php`**: backfill con `--dry-run`. Cifra `users.email` (hueco Sprint 2), `users.nombre`/`apellido`, `shipments` PII pendiente, y limpia `email_hmac`/`nombre_interno_hmac` en filas borradas. Ejecutado contra BD viva.

### Changed

- **`UserRepository::decryptRow`** y **`UserService::decryptUserRow`**: ahora descifran también `nombre` y `apellido`.
- **`UserService::findAll`**: búsqueda por texto libre (nombre/apellido) se filtra en PHP tras descifrar (LIKE sobre ciphertext no funciona). Búsqueda por email sigue con HMAC exacto en SQL.
- **`UserGroupRepository::listMembers`**: ORDER BY `nombre`/`apellido` movido de SQL a PHP (`usort` tras descifrar). Constructor acepta `?EncryptionService`.
- **`UserService::delete`**: soft delete pone `email_hmac = NULL` para liberar el índice único `uq_users_tenant_email_hmac`.
- **`TenantRepository::softDelete`**: pone `nombre_interno_hmac = NULL`. **`restore`** recomputa el HMAC.
- **`bootstrap.php`**: inyecta `EncryptionService` en `UserGroupRepository`.

### Verified

- `php bin/check-schema-drift.php` → SIN DRIFT
- `php bin/check-data-integrity.php` → DATOS CONSISTENTES
- `composer test` → 944/944 OK
- `node tools/smoke/smoke-v162.mjs` → 89 OK, 0 FAIL
- `node tools/smoke/smoke-v171.mjs` → 105 OK, 0 FAIL
- API en vivo: login OK, users list con nombre/apellido/email descifrados, búsqueda por email (HMAC) y por nombre (PHP filter) OK, shipments y deliveries OK
- BD viva: 0 filas en claro en users.email/nombre/apellido, shipments PII; 0 fallos de descifrado

## [1.13.38] - 2026-10-14

**Sprint 2 de cifrado: blind index HMAC-SHA256 para búsquedas + AES-256-GCM de PII no consultable.** `users.email` y `tenants.nombre_interno` ahora se buscan por un índice ciego (HMAC-SHA256 con clave separada) en lugar de LIKE sobre el valor en claro. Los teléfonos, contacto de tenant, PII de envíos e IPs de rate limiting/refresh tokens/secrets log se cifran AES-256-GCM. Un cibercriminal con acceso a la BD no puede hacer lookup por email ni ver teléfonos/IPs reales.

### Added

- **`BlindIndexService`** (`src/Core/Security/Service/BlindIndexService.php`): HMAC-SHA256 con clave `BLIND_INDEX_KEY` (separada de `ENCRYPTION_KEY`), hex de 64 caracteres. Normaliza valor (`mb_strtolower(trim())`) antes de hashear.
- **`bin/sprint2-backfill.php`**: backfill de `email_hmac`, `nombre_interno_hmac` y cifrado de PII existente. Ejecutado contra BD viva.
- **`bin/revert-users-nombre-encryption.php`**: revirtió `users.nombre`/`apellido` a claro (el backfill los cifró pero la decisión final fue no cifrarlos por ORDER BY/LIKE).

### Changed

- **`users.email`**: ahora se busca por `email_hmac` (HMAC exacto) en `findByEmail`, login y `deduceTenantIdFromEmail`. Búsqueda LIKE de email degradada a match exacto por HMAC si el término parece email.
- **`tenants.nombre_interno`**: se busca por `nombre_interno_hmac` en `findByNombreInterno` y `existsByNombreInterno`.
- **Cifrado AES-256-GCM añadido a**: `users.email`, `users.telefono`, `tenants.nombre_publico`, `tenants.email_contacto`, `tenants.telefono`, `shipments.destino_direccion`/`destinatario_nombre`/`conductor_nombre`, `rate_limit_log.ip_address`, `refresh_tokens.ip_address`, `secrets_access_log.ip_address`.
- **`TenantRepository`**: constructor acepta `?BlindIndexService` + `?EncryptionService`. `findAll`/`findByEstado`/`findByPlan`/`findOperativos` mueven ORDER BY `nombre_publico` de SQL a PHP (`usort` tras descifrar).
- **`UserService`**: `create`/`update`/`updateMe` calculan `email_hmac` y cifran email/telefono. `findAll` descifra filas y soporta búsqueda HMAC.
- **`TenantService`**: INSERT de admin calcula `email_hmac` y cifra email/telefono.
- **`ShipmentRepository`**: cifra PII al escribir, descifra al leer.
- **`ShipmentQueryService`**: descifra PII en `mapRow` (listado y detalle).
- **`RateLimitRepository`**, **`AuthController::storeRefreshToken`**, **`SecretsVaultService::logAccess`**: cifran IP antes del INSERT.
- **`DashboardService`**: descifra `nombre_publico` al mostrar nombre de tenant.
- **`PlatformSeeder`**: lookups por HMAC, INSERT con email_hmac + cifrado.
- **`bootstrap.php`**: registra `BlindIndexService`; inyecta servicios en `UserRepository`, `TenantRepository`.
- **`.env`**: `BLIND_INDEX_KEY` añadida (clave separada de `ENCRYPTION_KEY`).
- **`schema.sql`**: regenerado. Columnas nuevas: `users.email_hmac CHAR(64)`, `tenants.nombre_interno_hmac CHAR(64)`. Ampliadas: `users.telefono`/`tenants.telefono` → varchar(255), `tenants.email_contacto` → varchar(512), `shipments.destinatario_nombre`/`conductor_nombre` → varchar(512), `rate_limit_log`/`refresh_tokens`/`secrets_access_log.ip_address` → varchar(255). Eliminadas: `users.email_activo` (generated), índices `uq_users_tenant_email_activo`, `idx_tenant_email`, unique `nombre_interno` de tenants. Nuevos: `uq_users_tenant_email_hmac (tenant_id, email_hmac)`, `uq_tenants_nombre_interno_hmac (nombre_interno_hmac)`.

### Notes

- **Campos NO cifrados** (rompen ORDER BY / LIKE): `users.nombre`, `users.apellido`. Se usan LIKE directo en búsquedas y ORDER BY en `UserGroupRepository`.
- **Búsqueda LIKE de email degradada**: LIKE sobre email cifrado no funciona; el search de usuarios usa HMAC exacto si el término parece email, y LIKE sobre nombre/apellido siempre.
- **Fallback seguro**: todos los `decrypt*` intentan descifrar y si el valor no está cifrado (pre-migración) lo devuelven tal cual.

### Verified

- `php bin/check-schema-drift.php` → SIN DRIFT
- `php bin/check-data-integrity.php` → DATOS CONSISTENTES
- `composer test` → 944/944 OK
- `node tools/smoke/smoke-v162.mjs` → 89 OK, 0 FAIL
- `node tools/smoke/smoke-v171.mjs` → 105 OK, 0 FAIL
- API en vivo: login OK, users list con emails/teléfonos descifrados, dashboard muestra nombre de tenant descifrado, create user con cifrado en escritura y búsqueda HMAC/LIKE OK

## [1.13.37] - 2026-10-14

**Cifrado AES-256-GCM de PII en audit_logs (ip_address, user_agent).** Los datos de auditoría ahora se almacenan cifrados en la BD viva; la API los descifra al servirlos. Un cibercriminal con acceso a la BD ve texto cifrado, no IPs ni user agents reales.

### Added

- **`bin/encrypt-audit-backfill.php`**: script de backfill que cifra datos existentes en `audit_logs`. Soporta `--dry-run`. Ejecutado contra la BD viva: 1529 filas cifradas, 0 en claro.
- **`PdoAuditPort::encryptSensitiveFields()`**: cifra `ip_address` y `user_agent` antes del INSERT. El cifrado ocurre DESPUÉS de que `AuditLogger` calcula `hash_integridad`, de modo que el hash protege el contenido lógico y el cifrado protege el almacenamiento.
- **`AuditLogQueryService::decryptField()`**: descifra `ip_address` y `user_agent` al servir la API. Si el valor no está cifrado (datos pre-migración), lo devuelve tal cual (fallback seguro).

### Changed

- **`PdoAuditPort`**: constructor acepta `?EncryptionService $encryption = null`. Si es null (tests SQLite), no cifra.
- **`AuditLogQueryService`**: constructor acepta `?EncryptionService $encryption = null`. Misma lógica de fallback.
- **`bootstrap.php`**: inyecta `EncryptionService` en `AuditPort` y `AuditLogQueryService`.
- **`audit_logs.ip_address`**: columna ampliada de `varchar(45)` a `varchar(255)` (el ciphertext base64 es más largo que la IP en claro).
- **`database/schema/schema.sql`**: regenerado con el ALTER.

### Notes

- **Hash chain intacta**: `hash_integridad` se calcula sobre el contenido en claro; el cifrado es una capa de almacenamiento. La verificación de hash opera sobre el contenido descifrado.
- **Campos NO cifrados en este sprint** (por uso en WHERE/ORDER BY/generated columns): `users.email`, `users.nombre`, `users.apellido`, `tenants.nombre_interno`, `tenants.nombre_publico`. Requieren blind index (HMAC) para búsqueda — Sprint 2.
- **Campos pendientes de cifrar**: `users.telefono`, `tenants.telefono`, `tenants.email_contacto`, `shipments.destino_direccion`, `shipments.destinatario_nombre`, `shipments.conductor_nombre`, `rate_limit_log.ip_address`, `refresh_tokens.ip_address`, `secrets_access_log.ip_address`.

### Verified

- `php bin/check-schema-drift.php` → SIN DRIFT
- `php bin/check-data-integrity.php` → DATOS CONSISTENTES
- `composer test` → 944/944 OK
- `node tools/smoke/smoke-v162.mjs` → 89 OK, 0 FAIL
- `node tools/smoke/smoke-v171.mjs` → 105 OK, 0 FAIL
- API en vivo: `GET /audit/logs` devuelve IPs y user agents descifrados correctamente
- BD viva: 1529/1529 filas con ip_address cifrado, 0 en claro
- Nuevo log generado via API se guarda cifrado en BD

## [1.13.36] - 2026-10-14

**Perfil de usuario con auto-edición de datos personales (nombre, apellido, email).** UI reestructurada con tabs horizontales para escalar a MFA y otras configuraciones futuras. Verificación de email diferida (flag `email_verificado` se resetea al cambiar email; flujo SMTP pendiente de configuración del tenant).

### Added

- **`PATCH /api/users/me`** (`routes/api.php` + `UserController::updateMe`): auto-edición de perfil sin PermissionMiddleware (cualquier usuario autenticado se edita a sí mismo). Sólo admite `nombre`, `apellido`, `email`. Cambio de email requiere `password_actual` (verificación de identidad). Auditado como `user.profile.update`.
- **`UserService::updateMe()`**: valida y actualiza nombre/apellido/email; resetea `email_verificado=0` si el email cambia (verificación diferida). No admite estado, teléfono ni otros campos (eso es para admin vía `PATCH /users/{id}`).
- **`UserService::findByIdWithPassword()`**: obtiene usuario con hash del password para verificación de credenciales (uso interno, nunca en API responses).
- **`UserService::validateNombre()` y `validateApellido()`**: validaciones de longitud y vacío.
- **`UserController::resolveHasher()`**: resuelve `password_hasher` del Container.
- **UI Profile con tabs** (`profile.js`): tres tabs horizontales — Datos personales, Seguridad, Preferencias. Tab personal tiene form de nombre/apellido/email; muestra campo de password actual sólo si el email cambia. Infraestructura preparada para futuro tab MFA.
- **i18n**: 24 claves `profile.*` (tabs, labels, placeholders, help, botones, mensajes) en 4 locales; 3 claves `api_errors.users.*` (no_fields_to_update, password_required_for_email, password_incorrect).

### Changed

- **`profile.js`**: reescrito con patrón de tabs usando `.tabs`/`.tab` de `components.css`. Reutiliza `HttpClient.patch()` (no fetch directo). `AuthState.updateUser()` para refrescar datos del usuario tras guardar.

### Notes

- **Verificación de email diferida**: al cambiar email, `email_verificado` se pone en `false`. El flujo de confirmación por email (SMTP) se implementará cuando el admin configure el servicio de email del tenant. Mientras tanto, el flag sirve como señal para admins de qué emails están sin verificar.
- **Futuro MFA**: la UI de perfil está preparada para añadir un tab `mfa` sin reestructurar.

### Verified

- `composer test` 944/944; `check-i18n` 0 errores; `check-schema-drift` SIN DRIFT; `check-data-integrity` DATOS CONSISTENTES.
- Smoke v162 (89 OK) y v171 (105 OK).
- API en vivo: `PATCH /users/me` con cambio de nombre OK; cambio de email sin password → 400 password_actual required; cambio de email con password OK y `email_verificado=0`.

## [1.13.35] - 2026-10-14

**Migración de mensajes de excepción del backend a catálogo i18n traducido (`api_errors`).** El soporte puede ver los mensajes de error en su idioma. Se elimina el código muerto `ErrorHandler.php` y `tools/i18n-fragments`.

### Added

- **`src/Shared/Services/ErrorCatalog.php`**: traductor de claves `api_errors` desde `resources/locales/{locale}.json` con fallback a `es` y soporte de parámetros `{0}`/`{name}`.
- **`HttpException::withMessageKey()`**: método fluent para adjuntar `messageKey` + `messageParams` a cualquier excepción HTTP.
- **Header `X-Locale`**: `public/js/core/http.js` envía el locale actual en cada petición; `public/index.php` lo resuelve (`X-Locale` → `Accept-Language` → `es`).
- **`api_errors` en 4 locales**: ~200 claves de catálogo (es/en llenos; fr/pt vacíos para conversión manual). Grupos: `common`, `auth`, `users`, `roles`, `permissions`, `groups`, `tenants`, `security`, `inventory.*`.
- **`AuthController::jsonError()`**: acepta `messageKey` opcional y traduce vía `ErrorCatalog` (26 llamadas migradas: login, refresh, authenticate).

### Changed

- **~80 factories de excepciones** migradas en `InventoryException`, `UserException`, `AuthorizationException`, `GroupException`, `SecurityException`, `TenantException`, `TemporaryPermissionException`, `UserPreferencesException`, `PasswordChangeRequiredException`.
- **~70 `throw` en controllers/services/entities** migrados a `->withMessageKey(...)`: UserController, RoleController, PermissionController, GroupController, TenantController, ProductController, LocationController, IncidentController, UserService, TenantService, TenantContext, AuthorizationService, IdempotencyService, User/Role/Permission/Secret entities, AuthController.
- **`UI.errorMessage()`** (`actions.js`): prioriza el `message` traducido del API sobre el mapping genérico por category/status.
- **`check-i18n.php`**: excluye claves `api_errors.*` del aviso de "clave sin uso" (se usan en PHP vía `withMessageKey`, no en `t()` de JS).

### Removed

- **`src/Shared/Http/ErrorHandler.php`**: código muerto (380 líneas, nunca registrado como handler real; el handler está en `public/index.php`).
- **`tools/i18n-fragments/`**: 20 JSON obsoletos de la migración i18n inicial.
- **Pendientes resueltos**: `database/schema/schema.sql` (2 comentarios de columnas reservadas del revert) y `tools/smoke/capturas-permissions/*.png` (restaurados al HEAD).

### Verified

- `composer test` 944/944; `check-i18n` 0 errores; `check-schema-drift` SIN DRIFT; `check-data-integrity` DATOS CONSISTENTES.
- Smoke v162 (89 OK) y v171 (105 OK).
- API en vivo: `GET /inventory/products/99999` con `X-Locale: en` → "Product not found"; `POST /auth/login` inválido con `X-Locale: en` → "Invalid credentials"; `fr` fallback a es.

## [1.13.34] - 2026-10-14

**Reestructuración de idiomas: es + en completos; fr + pt listos pero vacíos.** Escaneo i18n del frontend: 0 literales en español fuera de `t()` (todas las vistas migradas). Se dejan las claves de fr/pt preparadas para conversión manual por el usuario.

### Changed

- **`resources/locales/fr.json` y `pt.json`**: 1515 valores vaciados (claves conservadas, valores `""`). El usuario rellenará las traducciones cuando lo considere.
- **`resources/locales/en.json`**: 3 valores corregidos que aún estaban en español: `inventory.movements.filters.reason_placeholder` → `RELOCATION, ...`, `inventory.deliveries.placeholders.signature` → `https://signature.example/receipt`, `inventory.picking.messages.unit_selected_message` → `{barcode} · {producto}` (placeholder `{producto}` debe coincidir con es.json).
- **`public/js/core/i18n.js`**: `t()` trata valor `""` como "traducción pendiente" y cae al español (DEFAULT_LOCALE) en lugar de devolver cadena vacía.
- **`bin/check-i18n.php`**: la comprobación de placeholders `{..}` se salta cuando el valor en el idioma destino es `""` (pendiente de traducción).

### Verification

- `php bin/check-i18n.php` **OK (0 errores, 6 avisos)**; `composer test` **944/944 OK**; `check-schema-drift` **SIN DRIFT**; `node --check` en `core/i18n.js` OK; `smoke-v162` **89 OK, 0 FAIL**; `smoke-v171` **105 OK, 0 FAIL**; API en vivo `GET /api/locales/{fr,pt}.json` → 1515 claves, 0 con valor; es/en → 1515 con valor. Cache Redis `i18n:locale:*` eliminada.

## [1.13.33] - 2026-10-14

**Revert total del flujo de recepción planificada** (bloques R1, R2, R2B, R3, v1.13.32 y correcciones asociadas). Recepción vuelve al estado previo: un único formulario "Nueva recepción" (CU-001) contra `POST /inventory/receive`. Motivo: se decide descartar el modelo planificación → recepción contra documento (incluida la pestaña de recepción ciega/contra documento y la adhoc ya revertida en 1.13.31) y conservar sólo el CU-001 clásico. Etiqueta de respaldo del estado previo al revert: `pre-revert-recepcion` (apunta a `334bb52`).

### Removed

- **Endpoints de recepción contra documento**: `GET/POST/PATCH /inventory/receiving/documents`, `GET .../pending`, `GET .../{id}`, `POST .../{id}/cancel`, `POST .../{id}/receive` eliminados de `routes.php`; el único endpoint de recepción es `POST /inventory/receive` (CU-001, `inventory.receiving.create`).
- **`ReceivingDocumentController`, `ReceivingDocumentService` y `ReceiveDocumentUseCase`** eliminados; `ReceivingController` restaurado a su versión CU-001 (usa `ReceiveInventoryUseCase`); `InventoryServiceProvider` restaurado sin sus registros.
- **Guard de resolución restringida** de `ResolveIncidentUseCase` y columna `restriccion_resolucion` del INSERT de `IncidentRepository` eliminados (restaurados a su estado pre-R2); `Incident` y `InventoryException` restaurados (sin factories de documento/planificación/cegueza).
- **Frontend**: `public/js/modules/inventory/receiving.js` restaurado al formulario único "Nueva recepción" (CU-001 con `cantidad_esperada`/`cantidad_recibida`, lote, serie, rechazo total, Idempotency-Key); pestañas receive/plan y selector de documentos eliminados. Locales `es|en|pt|fr` restaurados (sección `inventory.receiving` pre-R1). Smoke `tools/smoke/smoke-receiving.mjs` y capturas `capturas-receiving-v11332/` eliminados.
- **Tests**: `ReceivingDocumentRoutesTest`, `ReceivingDocumentServiceTest`, `ReceiveDocumentUseCaseTest`, `InventoryMysqlSmokeTest` y `InventoryMysqlTestCase` eliminados; `ReceivingControllerTest`, `InventoryPermissionSeederTest`, `InventorySqliteTestCase` y `InventoryPermissionSeeder` restaurados (seeder sin `inventory.receiving.plan`; siguen existiendo en BD los permisos `plan`/`create`/`confirm` sembrados, ahora sin endpoint que los exija salvo `create`).
- **Scripts**: `bin/seed-inventory-permissions.php` y `bin/prepare-test-db.php` (creados en R1/R2) eliminados.

### Kept (BD viva, sin tocar datos)

- Tablas **`receiving_documents`** y **`receiving_document_lines`** conservadas con sus filas (3 y 3 antes y después del revert); marcadas en `schema.sql` con el comentario "reservadas, sin uso actual". Permisos `inventory.receiving.plan`, `inventory.receiving.create` y `inventory.receiving.confirm` siguen sembrados en la BD viva; `create` es el único que vuelve a tener endpoint.

### Verification

- `composer test` **944/944 OK** (982 pre-revert menos los 38 tests receiving de R1/R2/v1.13.32); `check-i18n` **0 errores, 6 avisos**; `check-schema-drift` **SIN DRIFT** (34 tablas); `check-data-integrity` **DATOS CONSISTENTES**; `node --check` OK en `receiving.js`; `smoke-v1100` **66 OK, 0 FAIL**; GETs admin de products/stock/incidents → 200 y `/receiving/documents` → 404 (ruta ya inexistente); `POST /inventory/receive` sin `document_id` con `{sku, ubicacion_id, cantidad_esperada, cantidad_recibida}` → **201** (unidad DISPONIBLE + ledger + evento `RECIBIDO`); listado de stock sigue mostrando las unidades históricas (46 de PROD-001 visibles).

## [1.13.32] - 2026-10-14

Flujo definitivo de recepción: **sin recepción ciega y sin reconteo**. Quedan 2 pestañas (Planificación + Recepción contra documento): el receptor elige un documento pendiente, ve la `cantidad_esperada` bloqueada (visible en la UI, rechazada en el payload con 422) y contabiliza lo recibido; cualquier discrepancia cierra la línea con lo contado + incidencia, sin segundo conteo.

### Changed

- **`ReceiveDocumentUseCase` reescrito**: bloqueo `FOR UPDATE` de las líneas del documento; el creador del documento no puede recibirlo (403 `receivingDocumentCreatorCannotReceive`); líneas recibibles `PENDIENTE|RECONTEO`; discrepancia esperada/contada → unidad física con lo contado + incidencia `FALTANTE`/`SOBRE_RECEPCION` (umbral de faltante); `rechazo_total` cierra todas las líneas pendientes en `RECHAZADA` sin crear stock + incidencia `ENTREGA_RECHAZADA` ALTA; auditoría con `numero_documento`, `line_id`, planificador, receptor, esperada y recibida. `cantidad_esperada` en el payload → 422 `receivingExpectedQuantityNotAccepted`; líneas ya cerradas → 409.
- **`GET /inventory/receiving/documents/pending`** (permiso `inventory.receiving.create`): documentos `PLANIFICADA|EN_RECEPCION` creados por otro usuario, con sus líneas y `cantidad_esperada`; registrado en `routes.php` antes de `/receiving/documents/{id}` para ganarle al patrón con parámetro.
- **`ReceivingDocumentService::findPendingForReceiver`**: consulta de pendientes para el receptor con paginación.
- **`POST /inventory/receive` cerrado (CU-001)**: `ReceivingController::receive` responde 401 sin claims y 422 `receivingWithoutDocumentNotAllowed` en cualquier caso; la recepción sólo es contra documento.
- **Auditoría de recepción enriquecida** (`ReceivingDocumentController::auditReceiving`): incluye `creado_por`, `recibido_por`, `rechazo_total` y el detalle de líneas.
- **Frontend `public/js/modules/inventory/receiving.js` reescrito**: 2 pestañas `receive`/`plan` (sin `register`); selector de documentos desde `GET /pending`; detalle con `cantidad_esperada` pintada `readonly`+`disabled`, inputs de lote/serie y checkbox "Rechazo total" (deshabilita las cantidades contadas); envío con `Idempotency-Key` al `POST /documents/{id}/receive` sin `cantidad_esperada`; en el plan-save, 409 de segundo receptor muestra aviso `error_second_receiver`.
- **Enum `receiving_document_lines.estado` ampliado en la BD viva** a `PENDIENTE|RECIBIDA|RECONTEO|RECHAZADA` (ALTER; `RECONTEO` queda como estado legado de líneas, sin uso en el flujo nuevo); `schema.sql` regenerado con `bin/regenerate-schema.php`.
- **Excepciones** de `InventoryException` actualizadas al flujo nuevo (403 creador, 409 línea cerrada/recibida, 422 sin documento y sin `cantidad_esperada`); textos y docblocks sin "recepción ciega".
- **i18n** (`es|en|pt|fr`): sección `inventory.receiving` reestructurada (`tabs.receive/plan`, `receive.*`, `line_states.RECHAZADA`, `plan.messages.error_second_receiver`); eliminadas claves de registro/ciega/reconteo.
- **Tests**: `ReceiveDocumentUseCaseTest` y `ReceivingControllerTest` reescritos (422 cantidad_esperada, 403 creador, faltante/sobre-recepción, rechazo total, idempotencia); `ReceivingDocumentRoutesTest` añade la ruta `pending`; `ReceivingDocumentServiceTest` cubre `findPendingForReceiver`.

### Verification

- `composer test` **982/982 OK**; `check-schema-drift` **SIN DRIFT** (34 tablas); `check-i18n` **0 errores**; `node --check` OK en `receiving.js`; smoke `tools/smoke/smoke-receiving.mjs` **59 ok, 0 FAIL**; `GET /receiving/documents`, `/pending` y detalle con admin → 200. Capturas 1366px en `tools/smoke/capturas-receiving-v11332/`.

### Notes

- **Permisos por tenant (BD viva)**: `inventory.receiving.plan` en roles built-in ADMIN y MANAGER; `inventory.receiving.create` en ADMIN, MANAGER y USER. En `acme-corp`, 4 usuarios activos pueden recibir (aleon ADMIN, manager MANAGER, fgal USER, user USER); 2 de ellos pueden planificar.
- **Desviaciones**: los e2e históricos que aún usaban `POST /inventory/receive` (e2e-v1100/v170/v171/v180/v190/v191 y smoke-v1100) fallen por diseño (CU-001 cerrado); la columna `receiving_documents.intentos` queda sin uso (no destructivo); la concurrencia real se testea como repetición de la misma línea → 409, más el `FOR UPDATE` en código; 20 avisos de `check-i18n` por claves sin uso en el scanner.

## [1.13.31] - 2026-10-13

Reversión del BLOQUE adhoc (R3 + R4). Se descarta la recepción adhoc (recepción sin documento planificado con confirmar/rechazar) por no ser el flujo deseado. El modelo correcto es **planificación → recepción ciega** (R1 + R2, que se conservan): un usuario planifica el documento con `cantidad_esperada` y otro usuario distinto recibe contra él a ciegas.

### Removed

- **Tablas `receiving_adhoc` y `receiving_adhoc_lines`** dropeadas de la BD viva; `schema.sql` regenerado (34 tablas).
- **Permiso `inventory.receiving.adhoc`** eliminado de `permissions` y de los arrays `permisos` de los roles en la BD viva; seeder `InventoryPermissionSeeder` restaurado a su estado sin adhoc.
- **Endpoints adhoc** eliminados de `routes.php`: `POST /inventory/receiving/adhoc`, `POST /{id}/confirm`, `POST /{id}/reject`, y los `GET` de listado/detalle (R4).
- **Casos de uso** `ReceiveAdhocUseCase`, `ConfirmAdhocUseCase`, `RejectAdhocUseCase` y servicio de lectura `ReceivingAdhocQueryService` eliminados; sus registros en `InventoryException` e `InventoryServiceProvider` revertidos.
- **`ReceivingAdhocController`** eliminado.
- **Pestaña "Recepción adhoc"** eliminada de `public/js/modules/inventory/receiving.js` (restaurado a su estado pre-R4, sólo el CU-001 clásico); claves i18n `inventory.receiving.adhoc.*` eliminadas de los 4 locales y del fragmento `receiving.es.json`.
- **Tests** `ReceiveAdhocUseCaseTest` y `ReceivingAdhocQueryServiceTest` eliminados; `InventoryMysqlTestCase` e `InventorySqliteTestCase` restaurados sin fixtures adhoc.
- **Scripts** `tools/e2e/e2e-v11330.ps1`, `tools/e2e/e2e-r4-adhoc.ps1` y `tools/smoke/smoke-r4-adhoc.mjs` eliminados.

### Verification

- `composer test` **981/981 OK** (vuelve al conteo previo a R3); `check-schema-drift` **SIN DRIFT** (34 tablas, 413 columnas); `check-data-integrity` **DATOS CONSISTENTES**; `check-i18n` **0 errores**; `php -l` y `node --check` OK en los PHPs/JS restaurados; sin coincidencias de `adhoc` en `src/`, `public/js/`, `routes/`, `tests/` ni `tools/`.
- Rama de seguridad con el estado previo a la reversión: `backup/adhoc-r3r4-pre-revert` (apunta a `48f7185`).

### Notes

- **Conservado (R1 + R2)**: `receiving_documents`/`receiving_document_lines`, permiso `inventory.receiving.plan`, endpoints `GET/POST/PATCH /receiving/documents` + `/{id}/cancel`, y `POST /receiving/documents/{id}/receive` (recepción ciega con `cantidad_recibida`/`intentos` e incidencia `DISCREPANCIA_CANTIDAD` restringida). Estos endpoints siguen **sin interfaz**: la UI de planificación → recepción ciega es el siguiente paso.
- La BD de E2E (`xproject_e2e`) sigue con datos adhoc de ejecuciones previas; `prepare-e2e-db.php --fresh` la recrea desde el `schema.sql` ya saneado cuando haga falta.

## [1.13.30] - 2026-10-13

BLOQUE R3 (backend): recepción adhoc. **REVERTIDO en 1.13.31**; se documenta como registro histórico.

### Added

- **Tablas `receiving_adhoc` y `receiving_adhoc_lines`** (BD viva + `schema.sql` regenerado): adhoc con `referencia_documento` (única por tenant), estado `PENDIENTE|CONFIRMADA|RECHAZADA`, `creado_por` y `creado_por_confirma` FK a users; líneas con SKU, `cantidad` y `unidad_id` FK a la unidad física.
- **`inventory.receiving.adhoc`** (seeder idempotente, ADMIN y MANAGER; corregido duplicado en ADMIN): permiso de registro adhoc; sembrado en la BD viva.
- **`POST /inventory/receiving/adhoc`** (permiso `inventory.receiving.adhoc`, `Idempotency-Key` obligatoria, RateLimit): registra la recepción adhoc. La referencia del albarán es obligatoria (principio 1); duplicada en el tenant → 409 `receivingAdhocReferenceAlreadyExists`; sin referencia → 400 `requiredField`. Cada línea crea una unidad física en **BLOQUEADO** (ledger + evento `RECIBIDO`), consume capacidad de la ubicación destino y deja el adhoc en PENDIENTE.
- **`POST /inventory/receiving/adhoc/{id}/confirm`** (permiso `inventory.receiving.confirm`, `Idempotency-Key` obligatoria): confirma un adhoc PENDIENTE pasando todas sus unidades de BLOQUEADO a **DISPONIBLE** (transición ya presente en la matriz de transiciones). El usuario que registró no puede confirmar → 422 `receivingAdhocCreatorCannotConfirm`. Cada línea sin unidad física → 422 `receivingAdhocLineHasNoUnit`.
- **`POST /inventory/receiving/adhoc/{id}/reject`** (permiso `inventory.receiving.confirm`, `Idempotency-Key` obligatoria): rechaza un adhoc PENDIENTE. Las unidades permanecen en **BLOQUEADO** y se abre una incidencia `ENTREGA_RECHAZADA` de prioridad **ALTA** con `restriccion_resolucion={creador, rechazador}` (ni el creador ni el rechazador la resuelven). El creador no puede rechazar → 422 (misma semántica de separación de funciones que la confirmación).
- **Auditoría de dominio (`_auditoria`, no filtrada al cliente)**: cada operación registra `adhoc_id`, `referencia_documento`, `ubicacion_codigo` y las líneas (SKU, cantidad, unidad_fisica_id, estados) para trazabilidad completa.
- **Tests de comportamiento sobre MySQL real**: `ReceiveAdhocUseCaseTest` (9 casos: referencia obligatoria, referencia duplicada → 409, unidades nacen BLOQUEADO, creador no confirma, confirmar con segundo usuario pasa a DISPONIBLE, rechazar deja BLOQUEADO + incidencia ALTA, creador no rechaza, idempotencia, aislamiento de tenant). `InventoryMysqlTestCase` se amplía con los 3 use cases adhoc y helper `createReceivingAdhoc`; el fixture SQLite se amplía con las 2 tablas.

### Verification

- `php -l` en los PHPs tocados (3 use cases + controller + rutas + service provider + seeder + exception + 2 tests de soporte + test R3) OK; `composer test` **990/990 OK**; `check-schema-drift` **SIN DRIFT** (36 tablas, 429 columnas); `check-data-integrity` **DATOS CONSISTENTES**. E2E `e2e-v11330.ps1` contra BD aislada `xproject_e2e` (36 tablas, recreada con `--fresh`): **0 fallos** — registro 201, creador no confirma 422, rechazo 200 con unidad BLOQUEADO + incidencia ALTA, confirmación 200 con unidad DISPONIBLE, `GET /receiving/documents` 200, ninguno 500.

### Notes

- **Desviación consciente**: `GET /inventory/receiving/adhoc` no existe (sólo 3 POST); el listado/detalle de adhoc queda para R4 (frontend) si se decide exponerlo. La BD de E2E quedó con datos de la ejecución del E2E; `prepare-e2e-db.php --fresh` la recrea desde `schema.sql` cuando haga falta.
- El módulo no tiene endpoint `restore` ni `include_deleted` (regla 11): el adhoc no se recupera, se recrea.

## [1.13.29] - 2026-10-13

BLOQUE R2 (backend): recepción ciega contra documento. El receptor nunca introduce `cantidad_esperada`; cuenta a ciegas, con un solo reconteo y, en el segundo fallo, abre una incidencia `DISCREPANCIA_CANTIDAD` cuya resolución queda restringida a un tercero (ni receptor ni planificador).

### Added

- **Columnas nuevas en la BD viva + `schema.sql` regenerado**: `receiving_document_lines.cantidad_recibida` (decimal(15,4), NULL hasta que se recibe) y `receiving_document_lines.intentos` (int NOT NULL DEFAULT 0, 1 = primer fallo que dispara reconteo); `incidents.restriccion_resolucion` (longtext JSON, CHECK json_valid) guarda `{receptor, planificador}`.
- **`POST /inventory/receiving/documents/{id}/receive`** (permiso `inventory.receiving.create`, `Idempotency-Key` obligatoria, RateLimit): recibe contra un documento PLANIFICADA/EN_RECEPCION. El payload sólo admite `cantidad_recibida` (≥0) por línea; `cantidad_esperada` en cualquier nivel → 422 `receivingExpectedQuantityNotAccepted`.
- **Principio de separación de funciones**: el creador del documento no puede recibirlo → 422 `receivingDocumentCreatorCannotReceive`.
- **Recepción ciega con un solo reconteo**: al primer mismatch la línea queda RECONTEO (PENDIENTE) y la respuesta no revela esperado ni diferencia; en el segundo fallo se crea la unidad física con la cantidad contada y una incidencia `DISCREPANCIA_CANTIDAD` (prioridad ALTA si la discrepancia alcanza el umbral `RECEPTION_DISCREPANCY_THRESHOLD`, 5% por defecto) con `restriccion_resolucion=[receptor, planificador]`.
- **`ResolveIncidentUseCase`**: guarda de resolución — el actor en `restriccion_resolucion` recibe 422 `incidentResolutionRestricted`; un tercero la resuelve con normalidad.
- **Auditoría interna (`_auditoria`, no filtrada al cliente)**: cada línea registra `numero_documento`, `cantidad_esperada`, `intentos` y `cantidad_recibida` para trazabilidad completa de la recepción ciega.
- **Tests de comportamiento sobre MySQL real**: `ReceiveDocumentUseCaseTest` (14 casos: match, mismatch/reconteo, segundo fallo + incidencia restringida, umbral de prioridad, sobre-recepción, creador no recibe, SKU no perteneciente, línea ya recibida, documento no recibible, idempotencia y aislamiento de tenant). Base nueva `InventoryMysqlTestCase` (BD dedicada `xproject_test` recreada desde `schema.sql` con `bin/prepare-test-db.php`, tenant único por test, sin transacción externa porque los use cases abren la suya). `InventoryMysqlSmokeTest` valida conexión y limpieza. El esquema SQLite de `InventorySqliteTestCase` se amplía con las 3 columnas para que los tests existentes sigan en verde.

### Verification

- `php -l` en 13 PHPs (7 backend + 1 bin + 5 tests) OK; `composer test` **981/981 OK** (suite completa: 14 nuevos en MySQL + los existentes); `check-schema-drift` **SIN DRIFT** (34 tablas, 413 columnas, 149 índices); `check-data-integrity` **DATOS CONSISTENTES**. Verificación de solo lectura contra BD viva con admin: `GET /inventory/receiving/documents` 200, `GET /inventory/receiving/documents/{id}` 200, ninguno 500.

### Notes

- **Desviación consciente (migración pendiente)**: los ~30 tests existentes de recepción/incidencias siguen sobre `InventorySqliteTestCase`; se migran a MySQL en un bloque posterior (regla 14: un bloque a la vez). El fixture SQLite se amplía aquí con las 3 columnas nuevas para no romper la suite.
- El módulo no tiene endpoint `restore` ni `include_deleted` (regla 11): la recepción no se recupera, se recrea.

## [1.13.28] - 2026-10-13

BLOQUE R1 (backend): documentos de recepción planificada. Separa la planificación de la recepción (CU-001): quien planifica introduce `cantidad_esperada` y no puede ser el único receptor; la recepción ciega llega en R2.

### Added

- **Tablas `receiving_documents` y `receiving_document_lines`** (BD viva + `schema.sql` regenerado): documento con `numero_documento` único por tenant, estado `PLANIFICADA|EN_RECEPCION|CERRADA|ANULADA`, `creado_por` FK a users; líneas con SKU, `cantidad_esperada` y estado `PENDIENTE|RECIBIDA` (FK a la unidad de recepción, NULL hasta R2).
- **`inventory.receiving.plan`** (seeder idempotente, ADMIN y MANAGER): permiso de planificación; distinto de `receiving.create` (recepción). Sembrado en la BD viva (1 creado, 2 roles actualizados).
- **`GET/POST /inventory/receiving/documents`**, **`GET/PATCH /inventory/receiving/documents/{id}`**, **`POST /inventory/receiving/documents/{id}/cancel`** (los 5 con `inventory.receiving.plan`): listado paginado con filtros estado/search; creación transaccional (documento + líneas) con validación de SKU activo por tenant y unicidad de número; edición sólo en PLANIFICADA y sin líneas recibidas (audita antes/después); anulación por estado (política de borrado: no se borra, se anula).
- **Separación de funciones en creación**: si en el tenant no hay otro usuario activo con `inventory.receiving.create` distinto del creador → 409 `receivingSecondReceiverRequired` (mensaje i18n en frontend, R4).
- **`ReceivingDocumentService`** (DatabaseService + TenantContext + AuthorizationService) y **`ReceivingDocumentController`** (AuditLogger, patrón LocationController); factories nuevas en `InventoryException` (8 métodos receiving.*).
- **Tests**: `ReceivingDocumentRoutesTest` (5 rutas, permiso, auth/tenant/rate-limit) y `ReceivingDocumentServiceTest` (15 casos SQLite: creación, duplicados, segundo receptor, SKU inactivo/duplicado, edición con líneas recibidas, anulación, aislamiento tenant, listado). Tablas añadidas al esquema SQLite de `InventorySqliteTestCase`.

### Verification

- `php -l` en 6 PHPs OK; `composer test` **963/963 OK**; `check-schema-drift` **SIN DRIFT** (34 tablas, 410 columnas); `check-data-integrity` **DATOS CONSISTENTES**; E2E `e2e-v1139` (política de borrado) todos los casos OK. Verificación end-to-end contra BD viva con admin: `GET /receiving/documents` 200 (vacío), `POST` 201 (PO-TEST-001, PROD-001), `GET /{id}` 200 con línea, `POST /cancel` 200 → ANULADA, `GET` tras cancel 200.

## [1.13.27] - 2026-10-13

Arreglo del selector de unidades del modal "Registrar devolución" (Devoluciones): era un `size="6"` que lo renderizaba como listbox expandido en vez de desplegable, y tras buscar podía conservar una selección oculta.

### Fixed

- **`public/js/modules/inventory/returns.js`**: el `<select>` de unidad pierde el atributo `size="6"` (desplegable normal, como el resto de selects del modal); el buscador en cliente limpia `unidad_fisica_id` si la seleccionada queda fuera del filtro (evita POST con id invisible); `loadStock` vacía la lista antes de recargar (sin unidades del estado anterior durante la carga o tras un error) y el selector queda `disabled` mientras carga. Backend verificado sin cambios: `GET /inventory/stock?estado=ENTREGADO` devuelve las 8 unidades ENTREGADO + 1 DEVUELTO de la BD.

### Verification

- `node --check` OK; `smoke-v184` (entregas + devoluciones) **100 OK, 0 FAIL**. Sólo frontend: sin PHP ni esquema tocados (verificación proporcional).

## [1.13.26] - 2026-10-12

Reparación de las 3 desviaciones anotadas al cierre del BLOQUE F (sólo frontend + locales).

### Changed

- **`public/js/ui/permission-picker.js`**: los textos pasan del wrapper `tr()` al `t()` global de `core/i18n.js` (mismo patrón que el resto de UI); se elimina el helper local. `check-i18n` ya detecta las 22 claves `ui.picker.*` y `roles.perms.mfa`.
- **`resources/locales/{es,en,pt,fr}.json`**: se borran las claves muertas `roles.perms.{readonly,empty,select_all}` (sin uso desde que la matriz a mano se reemplazó por el picker).
- **`resources/locales/{pt,fr}.json`**: el bloque completo `roles.*` (40 claves) deja de estar en español y se traduce a portugués y francés (convención del fichero: pt sin acentos, fr sin apóstrofos); `{nombre}` y `{count}`/`{critical}` se conservan como placeholders.
- **Doble `data-pp-panel` unificado**: el panel interno que pinta el picker deja de llevar `data-pp-panel` (sólo la clase `.pp-panel`); el atributo queda único en el nodo mount del módulo, que es el ancla del estado (`panel.__pp`). Documentado en la cabecera del componente.

### Verification

- `node --check` permission-picker.js OK; JSON válido en los 4 locales; `check-i18n.php` **0 errores** (paridad 1515 claves; avisos 30 → **6**, sólo `theme.*` preexistentes); smokes en verde sin cambios de aserciones: `smoke-v1120` 151, `verify-rowactions-vistas` 128, `verify-permission-picker` (Playwright) 19. Sin PHP ni esquema tocados: sin `composer test` ni drift (verificación proporcional).

## [1.13.25] - 2026-10-12

BLOQUE F (sólo frontend): el modal de roles (crear/editar/ver permisos) usa el nuevo componente compartido `UI.permissionPicker` (maestro-detalle de permisos) con dimensiones correctas, i18n en 4 locales y tema oscuro.

### Added

- **`public/js/ui/permission-picker.js`**: componente `UI.permissionPicker` (`mount`/`getSelected`/`setSelected`) con patrón maestro-detalle: módulos con contador seleccionados/total (en pantalla estrecha, `<select>`), permisos del módulo activo agrupados por recurso con checkbox/código/descripción/badge de riesgo, buscador por código y descripción, filtro de riesgo, "solo seleccionados", "Basar en" otro rol (`onBaseRole` → `GET /roles/{id}/permissions`), "Solo lectura" (sólo `.view`), "Limpiar", escalada (`granted` deshabilita con tooltip los permisos no poseídos; `null` = sin bloqueo, mismo contrato vacío que `UI.can`), auto-marcar `.view` al marcar crear/editar/eliminar del mismo recurso (aviso breve), indeterminate en cabeceras de módulo/recurso y foco en el buscador al montar.
- **`tools/smoke/verify-permission-picker.mjs`** (Playwright, 19 checks): capturas y comprobaciones a 1366px en tema oscuro y claro y a 700px (select de módulos); verifica modal-pp ~1100×85vh, descripción sola a ancho completo sin scrollbars ni resize, único scroll en los paneles, buscador enfocado y superficie oscura/clara según tema. Capturas en `tools/smoke/capturas-permissions/`.

### Changed

- **`public/js/modules/roles/roles.js`**: los modales crear/editar/permisos usan `size: 'pp'` y montan el picker; la descripción va siempre en su propia fila (nunca al par de otro campo) con `textarea-autogrow`; el pie resume `N permisos seleccionados (M criticos)` y avisa si hay CRITICAL; "Editar rol" ahora también gestiona los permisos (`PATCH metadata` + `PATCH /roles/{id}/permissions`); se eliminan la matriz HTML a mano, `perm-all-toggle`, `toggleModulo`/`toggleAll` y `state.grouped`.
- **`public/js/ui/modal.js`**: `SIZES.pp` (`modal-pp`) y `controlClass` opcional en `UI.field` (input/textarea).
- **`public/css/components.css`**: `.modal-pp` (1100px × 85vh, cabecera/pie fijos, `.modal-pp .modal-body` flex), estilos `.pp-*` (toolbar, barra, módulos, panel, recurso, note, summary, critical), `.textarea-autogrow` y responsivo (módulos → select <768px).
- **`public/index.php`**: carga `js/ui/permission-picker.js` tras `ui/actions.js`.
- **`resources/locales/{es,en,pt,fr}.json`**: +22 claves `ui.picker.*` y +2 `roles.perms.{summary,critical_alert}` en los 4 locales.
- **`docs/UI_STANDARD.md`**: §4.2 documenta `UI.permissionPicker` y su uso en modales.
- **`tools/smoke/smoke-v1120.mjs`** (S3) y **`tools/smoke/verify-rowactions-vistas.mjs`** (RA-13, +21 checks): adaptados al picker (fixtures `/permissions` y `/roles/{id}/permissions`, `setCheck`/`setSearch`/`pickerSelected`, escalada, BUILT_IN sin toolbar); `UI_FILES` incluye `ui/permission-picker.js`.

### Verification

- Un solo ciclo de cierre: `php -l` index.php OK; `node --check` 5/5 (picker, modal, roles y los 2 smokes); `check-i18n.php` **0 errores** (paridad 1518 claves en las 4 locales; 30 AVISOs: 20 `ui.picker.*` vía wrapper `tr()`, 6 `theme.*` y 4 `roles.perms.*` preexistentes/muertas); smokes afectados en verde: `smoke-v1120` 151, `verify-rowactions-vistas` 128 (107 previos + 21 RA-13), `verify-permission-picker` 19 (Playwright real contra Apache); `composer test` 944 tests, 6818 assertions, OK (2 deprecations preexistentes); `check-schema-drift.php` SIN DRIFT.

### Notes

- Sólo frontend; ningún endpoint ni esquema tocado. Desviaciones sin tocar (para informe): `roles.perms.{readonly,empty,select_all,mfa}` quedan sin uso directo en `roles.js` (el picker usa `tr()` y sus propias claves `ui.picker.*`); en `pt.json`/`fr.json` el bloque `roles.*` preexistente sigue en español (no se corrige en silencio; las claves nuevas de este bloque sí están traducidas); la captura comprueba tema oscuro con `xp.theme` forzado, sin hardcodes de color añadidos al CSS.

## [1.13.24] - 2026-10-12

BLOQUE D (sólo frontend + locales): `profile.js` deja de tener literales en español fuera de `t()`.

### Changed

- **`profile`**: `LANG_NAMES` con el literal `Español` (profile.js:22) pasa a `LANG_KEYS` con claves `profile.lang.{es,en,pt,fr}` resueltas con `t()` en `langOptionsHtml` (los nombres nativos son iguales en las 4 locales); el `console.warn` de `ensurePasswordModule` (`"[Profile] auth/password no disponible:"`, profile.js:70) pasa a `t('profile.password_module_unavailable')`.
- **`resources/locales/{es,en,pt,fr}.json`**: +5 claves nuevas bajo `profile` (`lang.es/en/pt/fr` y `password_module_unavailable`), traducidas en cada locale (`auth/password no disponible` / `not available` / `não disponible` / `indisponible`).

### Verification

- Un solo ciclo de cierre: `node --check` profile.js OK; `check-i18n.php` **0 errores** (2 literales resueltos; paridad 1496 claves en las 4 locales; 6 AVISOs de claves `theme.*` sin uso, preexistentes); `composer test` 944 tests, 6818 assertions, OK (2 deprecations preexistentes); `check-schema-drift.php` SIN DRIFT. Ningún smoke referencia `profile` (grep dirigido en `tools/smoke/*.mjs`): sin smokes afectados.

## [1.13.23] - 2026-10-12

BLOQUE C (sólo frontend): las acciones de fila de `products`, `locations` y `reservations` se pintan sólo cuando coinciden con lo que el backend acepta (permiso y estado).

### Changed

- **`products`**: la primaria `edit` sólo se pinta con `inventory.item.edit` (nuevos helpers `canEdit`/`canDelete`; fallbacks `['ADMIN', 'MANAGER']`/`['ADMIN']`, igual que los roles BUILT_IN del seeder) y la opción `delete` sólo con `inventory.item.delete`; sin permiso, la acción desaparece y las demás (Stock, Activar/Desactivar) siguen.
- **`locations`**: `edit` y `delete` sólo con `inventory.location.manage` (el único permiso de gestión que exige el backend para PATCH/DELETE); `units`/`activate`/`deactivate` sin cambios.
- **`reservations`**: `release` exige además `estado === 'ACTIVA'`, como el backend (`ReleaseReservationUseCase` devuelve 409 `reservationNotActive` en cualquier otro estado): una reserva CANCELADA/liberada/expirada queda con la celda vacía.
- **`stock`**: historial visible para todos, sin cambios (decisión explícita del alcance).

### Verification

- Un solo ciclo de cierre: `node --check` 4/4 (products, locations, reservations, smoke); smokes afectados en verde: `verify-rowactions-vistas` 107 (7 checks nuevos: sin `item.edit`/`item.delete`/`location.manage` → ausente; reserva CANCELADA → sin release), `smoke-bloque2-permisos` 20, `smoke-v162` 89; `composer test` 944 tests, 6818 assertions, OK (2 deprecations preexistentes); `check-schema-drift.php` SIN DRIFT.

### Notes

- Sólo frontend; ningún endpoint ni esquema tocado. Fuera de alcance y sin tocar: en `products`/`locations`, `activate`/`deactivate` tampoco están filtrados por permiso aunque el backend exige `item.edit`/`location.manage` respectivamente — queda anotado para informe.

## [1.13.22] - 2026-10-12

BLOQUE B (sólo frontend): las 12 vistas que pintaban acciones de fila a mano migran al componente compartido `UI.rowActions` (regla 14 de AGENTS.md / §4.1 de `docs/UI_STANDARD.md`), con opción discreta + menú de tres puntos, grupos, destructivo al final en rojo y permisos filtrados en el módulo.

### Changed

- **`UI.rowActions` ampliado** (`public/js/ui/view.js`): parámetro opcional `attrs` por opción (primaria y de menú) para atributos HTML extra; el módulo escapa los valores. Sólo lo necesita picking, que conserva `data-lote` (handlers y smoke `v171` leen `[data-lote]`); el resto de vistas pasa `id` normal (`data-id`).
- **12 vistas migradas** (mismos `data-action` de siempre, para no romper las aserciones de permisos de los smokes): `audit/logs` (primaria `detail` con `data-id` = índice del row; el handler pasa de `btn.dataset.index` a `btn.dataset.id`), `groups` (`open-detail` + grupos Editar/Eliminar; helper `actionBtn` eliminado), `roles` (`open-permissions` + Editar/Eliminar sólo en roles CUSTOM; `actionBtn` eliminado), `products` (`edit` + Stock + Activar/Desactivar + Eliminar), `locations` (`edit` + Unidades + Activar/Desactivar + Eliminar), `stock` (`move` + Reservar + Bloquear/Desbloquear + Historial), `shipments` (`open-detail` + Autorizar sólo en `PREPARACION`), `incidents` (`open-detail` + Investigar/Resolver según estado y permiso), `counts` (primaria única `open-detail` con etiqueta contextual `view`/`count`/`reconcile`), `picking` (`rowActionsForLote`: `expand-lote` + Completar/Verificar por estado, todos con `attrs data-lote`), `deliveries` (sin permiso `inventory.delivery.confirm` la celda queda vacía; con él, `open-confirm` + Marcar fallida) y `reservations` (sin primaria; menú sólo con Liberar `danger`).
- **Nuevo smoke `tools/smoke/verify-rowactions-vistas.mjs`** (jsdom, 100 checks): por vista verifica celda `td.col-actions`, cabecera `th.col-actions`, primaria correcta (y sus `data-id`/`data-lote`), orden exacto del menú, `aria-haspopup="menu"`/`aria-expanded="false"` en el trigger, opción `danger` al final, guardas de estado (BUILT_IN, `PREPARACION`, `RESUELTA`, lotes, producto inactivo…) y ausencia de la acción cuando se retira su permiso; además, ausencia de errores de consola en cada carga.

### Verification

- `node --check`: 14/14 ficheros (13 JS + el smoke) OK.
- `composer test`: 944 tests, 6818 assertions, OK (2 deprecations PHPUnit preexistentes).
- Smokes jsdom (13 scripts, todos en verde): `v1120` 140, `v193` 136, `v171` 105, `v184` 100, `verify-rowactions-vistas` 100, `v162` 89, `v1100` 66, `v1102` 44, `verify-rowactions-users` 44, `v1103` 34, `bloque1b` 26, `bloque2` 20, `verify-tabs-scroll` 8.
- `check-i18n.php`: 2 errores preexistentes en `profile.js`, fuera del alcance. `check-schema-drift.php`: SIN DRIFT. `check-data-integrity.php`: DATOS CONSISTENTES. GETs de solo lectura con admin (users/roles/groups/permissions/temp + 13 endpoints inventory): 19/19 → 200.

### Notes

- Sólo frontend: ningún endpoint, esquema ni permiso tocado. Desviación pendiente de informe: `users` ya estaba en el patrón (Bloque 2) y no se toca; queda fuera el resto de botones de fila que aún no son menú de tres puntos, si los hubiera, para revisión en informe de cierre.

## [1.13.21] - 2026-10-12

BLOQUE A (sólo frontend): patrón de éxito en los submits de toda la UI — el toast `success` se emite **después** de esperar la recarga real (y sólo si la recarga tuvo éxito), no antes de lanzarla.

### Changed

- **Carga con resultado**: los loaders de `users`, `groups`, `roles` y de los 11 módulos de inventory (`counts`, `incidents`, `locations`, `products`, `reservations`, `returns`, `deliveries`, `shipments`, `stock`, `picking`, `receiving`) devuelven `true`/`false` (y `openDetail` de counts/incidents igual) en lugar de `undefined`: `true` cuando la lista/detalle se refrescó desde el servidor, `false` cuando el `catch` interno ya emitió su propio `danger` y dejó la vista vacía o en estado de error.
- **Reordenación de submits** (≈35 sitios en 14 ficheros): `closeModal()`/cambios locales de estado → `await` de la recarga (`loadX()`, `Promise.all([...])` combinando `&&`, o `switchTab('temp')` que ahora devuelve la promesa de su carga) → `if (recargado) UI.toast('success', ...)`; los avisos informativos del backend (warnings de movimiento, rechazo total de recepción) se muestran siempre, con independencia del resultado de la recarga. Si la recarga falla, el usuario ve el error del propio loader y nunca un "éxito" con la pantalla desactualizada.
- **Sitios sin recarga intactos** (toast inmediato, no aplica): `password.js` (sólo cierra el modal), `picking.js` `unit_selected`, `users.js` copia de clave al portapapeles.

### Verification

- `node --check`: 14/14 ficheros modificados OK.
- `composer test`: 944 tests, 6818 assertions, OK (2 deprecations PHPUnit preexistentes).
- Smokes jsdom (12 scripts, todos en verde): `v1120` 140, `v193` 136, `v171` 105, `v184` 100, `v162` 89, `v1100` 66, `v1102` 44, `v1103` 34, `bloque1b` 26, `bloque2` 20, `verify-rowactions-users` 44, `verify-tabs-scroll` 8.
- `check-i18n.php`: 2 errores preexistentes en `profile.js`, fuera del alcance. `check-schema-drift.php`: SIN DRIFT. GETs de solo lectura con admin (users/roles/groups/permissions/temp + 10 endpoints inventory): 19/19 → 200.

### Notes

- Sólo frontend: ningún endpoint, esquema ni permiso tocado. Desviación pendiente de informe aparte (Block B): las 12 vistas sin `UI.rowActions`.

## [1.13.20] - 2026-10-12

BLOQUE 1B completo (backend + frontend): rol heredado por grupo en el modal de Roles de Usuarios. Borrado protegido con 409 `ROLE_NOT_DIRECT`, procedencia con `group_id`/`group_name`, acción "Quitar del grupo" con confirmación y recarga real.

### Added

- **409 `ROLE_NOT_DIRECT`**: `UserController::removeRole` lanza `UserException::roleNotDirect()` (categoría `conflict`, con `getErrorCode()`); el envelope de `public/index.php` expone `data.error_code` y `UserService::removeRole` devuelve el `rowCount`, de modo que el controller distingue "no existía" de "no estaba asignado directamente". Un rol heredado de grupo ya no devuelve un 200 falso sin borrar nada.
- **Orígenes en `GET /users/{id}/roles`**: `AuthorizationService::getUserRolesWithOrigin`/`fetchRoles` emite `direct` | `temporary` (directo con `fecha_expiracion`) | `group` con `group_id` y `group_name` (mismo contrato que login y `/auth/me`, que pasan de `directo`/`grupo` a `direct`/`group`).
- **Frontend `public/js/modules/users/users.js`**: chip "Por grupo: {group}" (`users.roles.from_group_named`); acción por fila `ask-remove-group` sólo con `groups.group.manage_members` y escalada (`canActOnUser`, nunca sobre un usuario ADMIN salvo ADMIN ni sobre uno mismo); sub-estado de confirmación en el modal (texto con el grupo y la lista de roles que se pierden, pie Cancelar/Quitar); envío por el endpoint existente `DELETE /groups/{id}/members/{userId}` con recarga desde el servidor antes del toast de éxito; el 409 en "Quitar" recarga y avisa con toast warning `not_direct` sin toast de éxito.
- **i18n**: `ui.cancel` y `users.roles.{remove_group,remove_group_confirm,remove_group_success,remove_group_error,not_direct,from_group_named}` en es/en/pt/fr.

### Fixed

- **`tests/Unit/UserPreferencesEndpointTest.php`**: el esquema SQLite local de `user_groups` no tenía `nombre`, columna que `fetchRoles` selecciona; se añade (espejo de la BD viva).

### Verification

- `composer test`: 944 tests, 6818 assertions, OK (2 deprecations PHPUnit preexistentes).
- E2E: `tools/e2e/e2e-v11320.ps1` (nuevo; 4 escenarios: borrado directo 200; rol sólo de grupo 409 + persiste; baja del grupo con `/auth/me` reflejando la invalidación de caché; sin `groups.group.manage_members` → 403 con control positivo del ADMIN) — TODOS LOS CASOS OK. `e2e-v1121` y `e2e-v1133` con el vocabulario `direct`/`group`/`temporary` — en verde.
- Smokes jsdom (12 scripts, todos en verde): `smoke-bloque1b-roles.mjs` (nuevo, 26 checks: chip, acción presente/ausente por permiso/escalada/self, confirmación cancelar y confirmar con recarga real, 409 sin éxito falso) más los 11 preexistentes.
- `check-i18n.php`: paridad de claves en los 4 locales; 2 errores preexistentes en `profile.js`, fuera del alcance. `check-schema-drift.php`: SIN DRIFT. `check-data-integrity.php`: DATOS CONSISTENTES. GETs de solo lectura del módulo con admin (users/roles/groups/permissions/temp/auth): 15/15 → 200.

### Notes

- Desviación a informe: `GroupController::removeMember` no aplica escalada de actor (sólo exige `groups.group.manage_members`); la escalada se aplica en el frontend. Ningún endpoint nuevo: se reutiliza `DELETE /groups/{id}/members/{userId}`.

## [1.13.19] - 2026-10-12

CIERRE de rowActions (BLOQUES 1–2): verificación completa con capturas Playwright, smokes, tests, i18n y drift.

### Verification

- `tools/smoke/verify-rowactions-users.mjs` (nuevo): 44/44 — Users a 1366px y 1920px como admin (`aleon@acme.local`, rol ADMIN) y como manager (`manager@acme.local`): sin scroll horizontal de la tabla, columna de acciones alineada a la derecha, chips sin partir (`white-space: nowrap`), menú de tres puntos accesible (`aria-haspopup`/`aria-expanded`, Esc cierra), grupos separados por línea, Eliminar al final en rojo, menú dentro del viewport (`position: fixed` escapando del `overflow` del `.table-wrapper`), escalada (sin Eliminar/Estado sobre sí mismo; MANAGER sin acciones sobre ADMIN; sin Eliminar sin permiso) y `?tab=` en la URL. Capturas en `tools/smoke/capturas-rowactions/`.
- Smokes jsdom (10 scripts, todos en verde): `v1120` 140 OK, `v162` 89, `v171` 105, `v1100` 66, `v1102` 44, `v1103` 34, `v184` 100, `v193` 136, `bloque2-permisos` 20, más la verificación Playwright.
- `composer test`: 941 tests, 6800 assertions, OK (2 deprecations PHPUnit preexistentes).
- `check-i18n.php`: claves es/en/pt/fr alineadas (1484); 2 errores preexistentes en `profile.js`, fuera del alcance.
- `check-schema-drift.php`: SIN DRIFT (BD viva `xproject` vs `schema.sql`). GETs de solo lectura con admin: `/users`, `/roles`, `/groups`, `/permissions`, `/temporary-permissions` → 200.

### Notes

- Sólo frontend: `routes/api.php`, BD y permisos intactos en este sprint.

## [1.13.18] - 2026-10-12

BLOQUE 2: `UI.rowActions` aplicado a la vista Users (lista de usuarios y permisos temporales), con reglas de escalada y columnas compactas. Sólo frontend.

### Changed

- **`public/js/modules/users/users.js`**: las acciones de fila pasan a `UI.rowActions` con la agrupación pedida — principal **Editar**; menú `[Roles, Ver permisos temporales] | [Cambiar estado, Restablecer contraseña] | [Eliminar]` (rojo, al final, con confirmación). Escalada: sobre el propio usuario no se ofrecen **Eliminar** ni **Cambiar estado**; un actor que no es ADMIN no actúa sobre usuarios con rol ADMIN (`canActOnUser`). Cada opción sólo con su permiso; sin opciones no se pinta el menú. La tabla de permisos temporales también usa `rowActions` (Revocar en rojo). Se eliminó el helper local `actionBtn` para la tabla de usuarios.
- **Columnas**: celda Roles con `cell-clip` (recorta en horizontal con `title` completo) y `white-space: nowrap` en `.badge` (el chip "Por grupo" no se parte); último acceso en `nowrap-cell` con `title` cuando falta espacio.
- **`?tab=` en la URL**: `state.tab` se restaura con `UI.tabFromParams` en `init`/`onActivate` y se refleja con `UI.syncTabParam` al cambiar de pestaña (regla 13 de `AGENTS.md`).

### Fixed

- **Menú recortado por la tabla**: `.dropdown-menu[data-row-menu]` usa `position: fixed` con posición calculada dentro del viewport al abrir, porque `.table-wrapper` tiene `overflow-x: auto` (y el eje Y computa a `auto`, recortando el menú). Se cierra con resize y scroll.

## [1.13.17] - 2026-10-12

BLOQUE 1: componente compartido de acciones de fila en la capa UI. Sólo frontend.

### Added

- **`UI.rowActions({primary, groups, ariaLabel})` en `public/js/ui/view.js`**: acción principal discreta (botón `btn-ghost`) + menú de tres puntos con grupos separados por `dropdown-divider` y la acción destructiva al final en rojo (`.dropdown-item.danger`). Devuelve `''` si no queda ninguna opción. Accesible: abre con clic o teclado (`aria-haspopup="menu"`, `aria-expanded`), cierra con Esc y clic fuera, foco gestionado (primer ítem al abrir; vuelve al disparador con Esc). Delegación global de eventos en `document` (una sola vez). Posicionamiento `fixed` dentro del viewport.
- **CSS**: `.row-actions`, `.row-actions-trigger`, `.dropdown-menu[data-row-menu]` (oculto por defecto; se alterna con `.is-open`), `.table td.truncate-cell`, `.table td.nowrap-cell`, `.table td .cell-clip` y `.badge { white-space: nowrap }`.
- **i18n**: clave `ui.row_actions` en es/en/pt/fr.
- **Documentación**: `docs/UI_STANDARD.md` §4.1 (contrato y ejemplo) y regla 14 en `AGENTS.md` (las acciones de fila se pintan con `UI.rowActions`; no se reimplementan botones de fila a mano).

## [1.13.16] - 2026-10-11

CIERRE del bloque de mantenimiento (BLOQUES 1–4): verificación completa sin cambios de código.

### Verification

- `composer test`: 941 tests, 6800 assertions, OK (2 deprecations PHPUnit preexistentes).
- Smokes: `smoke-bloque2-permisos` 20/20; `verify-tabs-scroll` 8/8; `smoke-v162` 89 OK; `smoke-v171` 105 OK.
- E2E `tools/e2e/e2e-v191.ps1`: TODO OK (BD de trabajo sin escrituras del E2E).
- BD viva: `GET /api/inventory/counts` → 200; `check-schema-drift.php` → SIN DRIFT; `check-data-integrity.php` no re-ejecutado (sin cambios de datos en el bloque).
- `check-i18n.php`: claves es/en/pt/fr alineadas (1481); 2 errores preexistentes en `profile.js` (literal "Español" y consola en español fuera de `t()`), fuera del alcance.

## [1.13.15] - 2026-10-11

BLOQUE 4: la barra de pestañas ya no muestra scroll vertical innecesario. Corregido en el componente compartido CSS (afecta a todas las vistas con pestañas).

### Fixed

- **`.tabs` en `public/css/components.css`**: `overflow-y: hidden` + `flex-wrap: nowrap` + scrollbar oculta cuando todo cabe. Antes `overflow-x: auto` solo dejaba el eje Y en `visible` (computa como `auto` en CSS), de modo que el `margin-bottom: -1px` de `.tab` generaba 1px de overflow y Windows/Chrome pintaba barra vertical en Picking, Shipments, Deliveries, Users, etc.

### Added

- **`tools/smoke/verify-tabs-scroll.mjs`**: verificación Playwright de `.tabs` en Picking, Shipments, Deliveries y Users a 1920px y 768px (`verticalScroll=false` en 8/8). Capturas en `tools/smoke/capturas-tabs/`.

### Notes

- Otros contenedores revisados sin scroll innecesario: `.table-wrapper` (overflow-x sólo si la tabla desborda, legítimo), `.modal-body` y `.sidebar-nav` (overflow-y sólo si el contenido desborda la altura fija, legítimo). No se tocan.

## [1.13.14] - 2026-10-11

BLOQUE 3: los subtítulos de las vistas de inventario ya no muestran los códigos internos de spec (CU-xxx / R3); sólo la descripción funcional. Sólo frontend + i18n.

### Fixed

- **Subtítulos con códigos de spec** en las 4 locales (es/en/pt/fr): movimientos (`CU-002 / R3`), reservas (`CU-003`), picking (`CU-004`), envíos (`CU-005 / CU-006`), entregas (`CU-007 / CU-008`), devoluciones (`CU-009`), incidencias (`CU-010`), conteos (`CU-011`) y el hint de unidades sin marcar en entregas (`CU-008`). Los códigos permanecen en comentarios de los módulos JS y en la spec; no en la UI.

## [1.13.13] - 2026-10-11

BLOQUE 2: si el usuario no tiene un permiso, la interfaz no muestra el elemento ni dispara la petición a la API (evita los "Acceso denegado" del log de Apache). Sólo frontend.

### Fixed

- **`dashboard.js`**: `loadStats` llamaba `GET /tenant/stats` sin comprobar `tenants.tenant.view` (403 en el log para MANAGER); ahora se pide sólo con ese permiso. Las capas `/inventory/dashboard/critical|secondary` se piden sólo con `inventory.stock.view`. El fallback de inventario (`/inventory/products`, `/inventory/locations`) se pide sólo con `inventory.item.view` / `inventory.location.view`. Tarjetas de stats y accesos rápidos filtrados por permiso (`users.user.view` incluido).
- **`products.js` / `locations.js`**: sin `inventory.item.view` / `inventory.location.view` se pinta `deniedState` y no se listan. El botón y el modal de stock (y el resumen por fila, que llama `/inventory/stock`) se condicionan a `inventory.stock.view`.
- **`stock.js`**: sin `inventory.stock.view` se pinta `deniedState` y no se llama la API.
- **`receiving.js`**: sin `inventory.receiving.create` se pinta `deniedState` y no se cargan catálogos.

### Added

- **Claves i18n** `inventory.{products,locations,stock,receiving}.permission.{title,body}` en es/en/pt/fr.
- **`tools/smoke/smoke-bloque2-permisos.mjs`**: 20 aserciones (dashboard sin 403, denied sin petición, sidebar filtra, permiso parcial sin `/inventory/stock`).

## [1.13.12] - 2026-10-11

Mantenimiento de la BD viva: reparación de la corrupción de índices de `inventory_counts` y volcado de datos vitales para poder restaurar una instalación limpia sin ejecutar seeds. Sin cambios de código.

### Fixed

- **`xproject.inventory_counts` corrupta (ERROR 1712)**: `CHECK TABLE` reportaba el B-tree de PRIMARY y los índices `idx_tenant_estado`, `ubicacion_id` y `usuario_responsable_id` como corruptos; la API de conteos (`GET /api/inventory/counts`, `CountQueryService.php:71`) fallaba con `SQLSTATE[HY000] Index inventory_counts is corrupted`. Causa: desfase `.frm`/`.ibd` tras regeneraciones de esquema y apagados forzados de MariaDB (tabla con 2 filas de historial). Reparación: backup forense de `.frm`/`.ibd` en `C:\xampp\backups\xp\corrupta_inventory_counts_20261011_213148\`, `DROP TABLE` + recreación del bloque `CREATE TABLE` de `database/schema/schema.sql` (sólo esa tabla). Verificado: `CHECK TABLE` OK, `check-schema-drift.php` SIN DRIFT, `GET /api/inventory/counts` 200.
- **Tablespace fantasma `xproject/ic_rec`** (SPACE 393, flag DISCARD, sin `.ibd` físico): residual de un repair/DISCARD interrumpido. InnoDB lo ignora en cada arranque (sólo un Note en el log); sin impacto funcional. No hay `.frm` asociado, por lo que no se puede `DROP TABLE`.

### Added

- **Dump de datos vitales** `C:\xampp\backups\xp\vital_xproject_20261011_213650.sql` (39.650 bytes, UTF-8): datos de `tenants`, `users`, `roles` (los 4 `BUILT_IN` + `AUDITOR`), `permissions`, `role_assignments`, `user_groups`, `group_members`, `group_roles`, `products`, `locations` y `stock_units`. Generado con `mysqldump --no-create-info --complete-insert --single-transaction` (el DDL vive en `schema.sql`). Excluye historial/ephemeral (audit_logs, stock_events, stock_ledger, movimientos, envíos, refresh_tokens, etc.). Restore verificado en BD temporal `xproject_vital_check`: 1 tenant, 5 users, 5 roles (ADMIN/MANAGER/USER/VIEWER BUILT_IN), 57 permissions, 3 role_assignments, 2 user_groups, 2 group_members, 2 group_roles, 8 products, 14 locations, 43 stock_units; borrada tras la prueba.
- **Uso para instalación limpia**: `mysql xproject < database\schema\schema.sql` y después `mysql xproject < C:\xampp\backups\xp\vital_xproject_20261011_213650.sql`. Con eso la BD queda operable sin `PlatformSeeder` (que exigía los roles `BUILT_IN` preexistentes).

### Notes

- El dump con credenciales de desarrollo (`admin@acme.local` / hash de `Admin123!`) queda **fuera de htdocs** por la misma política que `script2.ps1`; no se versiona.
- `ic_rec` es un tablespace huérfano en el diccionario InnoDB; si en el futuro se desea un datadir 100 % limpio, requeriría reconstrucción del diccionario (reinstall o `innodb_force_recovery` + dump/reload), fuera del alcance de este mantenimiento.

## [1.13.11] - 2026-10-11

Estándar de formato para vistas: componentes compartidos de estructura en un fichero nuevo (`public/js/ui/view.js`), documentación del estándar (`docs/UI_STANDARD.md`), regla permanente en `AGENTS.md` y aplicación del formato a las tres vistas de logística (Picking, Envíos y Entregas), con dos pestañas cada una. Sólo frontend: sin tocar backend, datos, esquema ni permisos.

### Added

- **`public/js/ui/view.js`** (nuevo): componentes de estructura de vista. `viewShell` (envoltorio con cabecera, barra de pestañas y paneles), `tabsBar`/`tabPanel`/`applyTabState`/`tabFromParams`/`syncTabParam` (pestañas con estado en la URL `?tab=` sobre el hash, sin recargar la ruta), `filtersCard` (tarjeta de filtros con Aplicar/Limpiar en su propia fila), `formFooter` (pista de Idempotency-Key + botón de envío), `errorState` (error de carga con reintento) y `deniedState` (pantalla de sin permiso con CTA al dashboard). Se carga en `public/index.php` justo después de `ui/render.js`.
- **`docs/UI_STANDARD.md`**: el estándar en sí — esqueleto de vista, convención de pestañas, tabla de componentes de `render.js` y `view.js`, reglas de filtros/paginación/estados/i18n, excepciones admitidas, checklist de adopción y auditoría de adopción de todas las vistas (informe, sin modificarlas).
- **Regla 13 en `AGENTS.md`**: toda vista nueva o modificada usa los componentes compartidos de `public/js/ui/`; si un componente no cubre un caso se amplía el componente, no la vista. Al añadir componentes a `ui/view.js` hay que incluir el fichero en `UI_FILES` de los smoke.

### Changed

- **`picking.js`, `shipments.js` y `deliveries.js` al formato estándar**: envoltorio `UI.viewShell` con dos pestañas por vista (`start|list`, `load|list`, `en_ruta|history`), pestaña activa en `state.tab` y reflejada en la URL con `?tab=`, ambos paneles montados en el DOM a la vez con los datos cargados en `init` (cambiar de pestaña alterna la visibilidad y refresca sólo el panel destino, de modo que el DOM sigue localizable en cualquier estado). Resumen con `UI.summaryCards`, filtros con `UI.filtersCard` + `data-filter` (antes selectores propios `data-picking-filter`/`apply-picking-filters`), paginación con `UI.renderPagination` + `data-page` (antes `data-page-picking`), estados de lista con `UI.loadingState`/`UI.emptyState`/`UI.errorState` con botón de reintento (los fallos de carga antes dejaban la tabla en estado vacío) y `UI.deniedState` en lugar de los tres `DENIED_TEMPLATE` propios, pie de ambos formularios con `UI.formFooter`. Se eliminan las cabeceras de sección `h3` (las sustituyen las etiquetas de pestaña) y la cabecera duplicada de la tarjeta de carga.
  - **Gateo por permiso**: sin `inventory.picking.create` / `inventory.shipping.create` la pestaña de creación no se pinta (con una sola pestaña no hay barra) y no se piden sus datos (`loadReservations`, `loadCatalogs`); sin `inventory.*.view` se muestra `deniedState`.
  - **Tras crear** un lote o un envío la vista salta a la pestaña de listado con `applyTab` sin recargar, porque la propia operación ya recarga ambos paneles.
  - El aviso "sin acceso a los envíos" de Entregas se mantiene escrito a mano dentro de `[data-en-ruta-table]`: su traducción lleva `<code>{permiso}</code>` y el smoke lo exige en crudo (excepción documentada en `UI_STANDARD.md`).
- **i18n (4 idiomas)**: nuevas claves `ui.error_title` y `ui.retry` (las usa `UI.errorState`), `inventory.shipments.types.*` (PROPIO, VEHICULO, MENSAJERIA, TERCERO) e `inventory.shipments.item_states.*` (PREPARADA, CARGADA, EN_TRANSITO, ENTREGADA, RECHAZADA, DEVUELTA), que hasta ahora se mostraban como enum crudo en la columna Tipo, en el desplegable del formulario y en los badges de los ítems del detalle. `columns.barcode` de las tres vistas pasa de "Barcode" a "Código de barras" (es, pt) y "Code-barres" (fr).
- **7 smokes**: `UI_FILES` pasa a `['ui/dom.js','ui/render.js','ui/view.js','ui/modal.js','ui/actions.js']` (v1103 no lleva UI_FILES, no aplica).

### Verification

- `node --check` en los cuatro JS tocados (`view.js`, `picking.js`, `shipments.js`, `deliveries.js`).
- 8 smokes: **714 OK / 0 FAIL** (v1100 66, v1102 44, v1103 34, v1120 140, v162 89, v171 105, v184 100, v193 136).
- `composer test`: 941 tests, 6800 aserciones, OK (2 deprecaciones de PHPUnit ya presentes).
- `php bin/check-schema-drift.php`: `SIN DRIFT`. `php bin/check-data-integrity.php`: `DATOS CONSISTENTES`.
- 18 scripts de `tools\e2e`: todos OK.
- `php bin/check-i18n.php`: paridad de claves 1473/1473 en es/en/pt/fr y sin literales españoles nuevos en el código tocado; siguen los 2 errores preexistentes de `profile.js` (ver Notas).

### Notas (desviaciones fuera de alcance, sólo informe)

- `check-i18n` sale con 2 errores preexistentes de `public/js/modules/profile/profile.js` (líneas 22 y 70: literales en español fuera de `t()`), introducidos en `db3f93d` y no tocados en este sprint. Se dejan para no ampliar el alcance.
- `resources/locales/pt.json` y `fr.json` contienen grandes bloques heredados sin traducir (texto español); no se corrigieron.
- Vistas sin migrar al estándar y sus desviaciones concretas (users con pestañas sin estado en la URL, counts/incidents/movements con shell y `DENIED_TEMPLATE` propios, locations/products/stock/returns/receiving/logs con estados, filtros y paginación escritos a mano): detalle en `docs/UI_STANDARD.md`, apartado 8. Ninguna se modificó.
- `check-i18n` sigue emitiendo avisos (no fatales) por las claves `theme.*` sin uso.

## [1.13.10] - 2026-10-11

Validaciones de integridad de datos para que la BD vuelva a rechazar por sí misma cualquier escritura que la rompa. Tras el incidente del 4 de octubre (19 filas con ENUMs en índice 0 porque una carga manual con encoding erróneo las guardó como `''` en modo no estricto), el servidor pasa a modo estricto y se añade un auditor de datos.

### Added

- **`bin/check-data-integrity.php`** (nuevo): audita los DATOS de la BD (no el esquema) y sale 0 en `DATOS CONSISTENTES`, 1 con el detalle. Comprobaciones: ENUMs en índice 0 (todas las columnas `enum` vía `col + 0 = 0`), fechas cero (`0000-00-00[ 00:00:00]`), fechas absurdas (año fuera de 1970-2100), `NULL` en columnas `NOT NULL` sin default (excluyendo auto_increment y generadas), JSON inválido en las columnas con `CHECK json_valid(...)` (detectadas desde `information_schema.CHECK_CONSTRAINTS`, para no falso-positivar en columnas de texto libre) y claves foraneas huérfanas (LEFT JOIN por cada FK, ignorando columnas hijas a NULL por diseño). Uso: `php bin/check-data-integrity.php [--db=nombre]`; por defecto opera sobre la BD que resuelve bootstrap (`E2E_DB_NAME` si está definida, si no la de trabajo), a diferencia de `check-schema-drift.php` que se niega a correr contra E2E.
- **Hook de integridad en `bin/prepare-e2e-db.php`**: al final del preparado (tras sembrar fixtures y `seed-transit-locations`) invoca `check-data-integrity.php` contra la BD de E2E; si detecta violaciones el preparado falla con `exit 1` en lugar de dejar que un E2E rompa a mitad de recorrido.

### Changed

- **Modo estricto del servidor**: `sql_mode` ahora es `STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION` (antes carecía de `STRICT_TRANS_TABLES`). Fijado en dos sitios: `C:\xampp\mysql\bin\my.ini` (persiste entre reinicios, línea `sql_mode=...` de `[mysqld]`) y `SET GLOBAL sql_mode` en caliente. Con ello MariaDB **rechaza** con error (p. ej. `1265 Data truncated`) las inserciones que antes coercía en silencio: ENUM fuera de miembro → `''`, fecha cero, truncado de datos, `NULL` en `NOT NULL`.
- **`DatabaseService::connect`** (`src/Shared/Services/DatabaseService.php`): la conexión PDO de la aplicación fija además su propio modo estricto vía `PDO::MYSQL_ATTR_INIT_COMMAND`, de modo que la app sigue estricta aunque alguien relaje el `GLOBAL` del servidor (defensa en profundidad). Validado: un `INSERT` con ENUM inválido lanza `PDOException`.
- **ENUM `refresh_tokens.revoked_reason` ampliado** con `password_change` y `admin_reset` (ALTER en vivo + `schema.sql` regenerado, drift `SIN DRIFT`): el código ya escribía esos dos motivos en `SessionRevokerService::revokeAllForUser` (reset de contraseña de usuario y reset por administrador), pero no estaban en el ENUM, así que el modo no estricto los guardaba como `''` y el modo estricto pasó a rechazarlos con 500 al activarlo. Es el primer bug latente que la validación destapó. `revokeFamily` ya usaba motivos válidos (`logout`, `reuse`, `inactivity`, `expired`) y `rotateRefreshToken` usa `rotated`.

### Verification

- `php bin/check-data-integrity.php` sobre la BD de trabajo: `DATOS CONSISTENTES`.
- Detector probado contra una BD scratch con datos corruptos (ENUM `ROTO`, fecha `0000-00-00`, año `3000`, FK huérfana): los 4 chequeos reales disparan y sale 1 (el scratch se borró después).
- `INSERT` con ENUM inválido ahora falla: `mysql.exe` → `ERROR 1265 Data truncated`; vía PDO de la app → `PDOException`.
- `php bin/prepare-e2e-db.php --fresh`: completo con el hook en `DATOS CONSISTENTES`.
- `composer test`: **941 tests, 6800 assertions, OK** (2 deprecations preexistentes).
- `php bin/check-schema-drift.php`: **SIN DRIFT** (tras el ALTER del ENUM y regeneración).
- Batería `tools\e2e\`: **18/18 scripts con exit 0** bajo modo estricto (la primera ejecución falló en `e2e-v1110` por el ENUM `revoked_reason` y quedó corregida con el ALTER).

## [1.13.9] - 2026-10-11

Política de borrado para las cinco entidades con soft delete (users, roles, groups, products, locations). Borrar nunca restaura: la única forma de recuperar un registro eliminado es crear uno nuevo con el mismo identificador, que recibe un id distinto. Los registros borrados quedan en la tabla (audit e integridad) pero nunca se listan en la API ni se muestran en la UI.

### Changed

- **Endpoints `POST /{entity}/{id}/restore` eliminados** de las cinco entidades (users, roles, groups, products, locations) en rutas, controllers, services, repositorios, interfaces y entities; los tests que las cubrían se reescriben para el nuevo patrón (borrar y recrear con el mismo identificador).
- **Filtros `include_deleted` eliminados** de `GET /users`, `GET /roles` y `GET /groups`: el listado ya no admite incluir borrados. Los repositorios `findAll` pierden el parámetro `$includeDeleted`; la condición de borrado queda fija a `AND deleted_at IS NULL`. Para inspección interna (tests, migraciones) se añaden `findIncludingDeleted` (por id, devuelve el registro borrado si existe en el tenant) y `findRawIncludingDeleted` (listado crudo que cruza el soft delete, usado para validar el aislamiento entre tenants).
- **UI sin control de borrados** (`users.js`, `roles.js`, `groups.js`): se eliminan el botón de restaurar, el modal de confirmación de restauración, el checkbox "Mostrar eliminados" y los indicadores visuales de registro borrado (`estadoBorradoBadge`, `esBorrado`). Los listados muestran sólo activos; si el backend devolviera un borrado por error, la fila se pinta normalmente (ya no hay estado oculto).
- **Auditoría de borrados con antes y después**: el log de auditoría de `DELETE` (destroy) en las cinco entidades añade ahora `detalles_adicionales.valores_despues` con el snapshot de la fila justo antes de fijar `deleted_at` (mismo formato que `valores_anteriores`). La columna real es `detalles_adicionales` (JSON, no existe `valores_despues` como columna); `AuditLogger::buildRow` decodifica y rechaza claves no conocidas, por lo que el array se guarda anidado dentro de la clave existente.
- **Esquema de BD con columnas generadas STORED** para permitir reutilizar un identificador borrado sin chocar con la restricción UNIQUE: `products.sku_activo`, `locations.codigo_activo`, `roles.nombre_activo`, `users.email_activo` (`uq_*_activo (tenant_id, <col>)` UNIQUE). La columna genera el valor sólo cuando `deleted_at IS NULL` y devuelve `NULL` en caso contrario (MySQL `STORED GENERATED ALWAYS AS (...)`), de modo que el índice único no ve los borrados y se puede volver a crear el mismo SKU / código / nombre / email. Los índices y constraints únicos previos sobre las columnas base se eliminan. `bin/prepare-e2e-db.php` copia ahora las columnas generadas filtrando por `information_schema.COLUMNS.EXTRA NOT LIKE '%GENERATED%'`.
- **i18n**: se eliminan del BUILTIN_ES y de los 4 locales (`es`, `en`, `pt`, `fr`) las claves de restauración y de borrado de users/roles/groups (`actions.restore`, `filters.deleted`, `filter.deleted`, `state.deleted`, `errors.restore`, `restore.*`). Se conservan `locations.deleted`, `products.deleted` y `groups.deleted_role` (usadas en el listado de inventario y en el selector de rol). `i18n.js` pierde las claves `restore.*` del ES embebido.

### Added

- **E2E nuevo `tools\e2e\e2e-v1139.ps1`**: 21 checks de la política de borrado — producto borrado + recreado con el mismo SKU (id nuevo, borrado persiste, duplicado activo 409, mismo SKU permitido en otro tenant, audit `product.delete` con `valores_despues`), producto con stock no se puede borrar (409), ubicación borrada + recreada con el mismo código (id nuevo, duplicado 409, ubicación con movimientos 409), rol BUILT_IN no se puede borrar (403), último admin no se puede borrar (403), grupo borrado + recreado con el mismo nombre (id nuevo).

### Modified

- **`e2e-v1110.ps1`** (sección roles): el test de restauración se reemplaza por el patrón borrar + recrear con el mismo nombre (201, id nuevo).
- **`e2e-v1130.ps1`** (secciones 1-4 y 6): los tests de usuarios, permisos, grupos y roles se reescriben para el nuevo patrón (restore 404, include_deleted ignorado, recrear con el mismo identificador, audit con `detalles_adicionales.valores_despues`).

### Verification

- `php -l` en los 18 PHP tocados: OK.
- `node --check` en los 4 JS modificados: OK.
- `composer test`: **941 tests, 6800 assertions, OK** (2 deprecations preexistentes).
- `php bin/check-schema-drift.php`: **SIN DRIFT**.
- **8/8 smokes jsdom verdes** (714 OK, 0 FAIL).
- Batería `tools\e2e\`: **19/19 scripts con exit 0** (audit, expire, sprint6, v1100-v1139, v162-v191).

## [1.13.8] - 2026-10-11

Tagline del login configurable desde `.env`, siguiendo el mismo patrón que el nombre de plataforma.

### Added

- **`APP_TAGLINE`** (`.env`): nueva variable con el texto bajo el nombre en la pantalla de login; valor por defecto en código `Gestión de Inventario Multi-Tenant` si la variable no existe. Inyectada en `window.AppConfig.appTagline` desde `public/index.php` (json_encode, mismo tratamiento que `APP_NAME`).

### Changed

- **Login** (`public/js/modules/auth/login.js`): el párrafo del tagline usa ahora `AppConfig.appTagline` con fallback a la clave i18n `login.tagline` (las 5 copias existentes en BUILTIN_ES y los 4 locales se conservan como fallback). Antes el texto salía siempre del BUILTIN_ES en español: la API de locales exige sesión, así que en login los JSON no se descargaban.

### Verificación de cierre

`php -l` y `node --check` OK — HTML vivo con `appTagline: "Gestión de Inventario Multi-Tenant"` y `appName: "Project X"` — **8/8 smokes jsdom verdes** (714 OK, 0 FAIL). `.env` sigue fuera de git; commiteables `index.php` y `login.js`.

## [1.13.7] - 2026-10-11

CICLO 5: pulido de header y sidebar aplicado por bloques (A: controles del header, B: logout en el sidebar, C: drop de cuenta extensible y módulo de perfil, D: verificación de cierre), cada uno ejecutado, verificado y aprobado por separado. Sin tocar lógica de negocio, backend, la deuda de settings ni las rutas existentes, y sin librerías ni frameworks nuevos.

### Added

- **Botón de icono de tema en el header** (`public/js/themes/theme-manager.js`, `public/index.php`): el texto "Auto"/"Claro"/"Oscuro" se sustituye por SVG inline del mismo estilo que el sidebar (luna = oscuro, sol = claro, monitor = sistema) que refleja el modo ACTUAL; el ciclo (oscuro → claro → sistema) y la persistencia (`ThemePersistence`, script anti-FOUC) quedan intactos. Title/aria-label ahora desde las claves nuevas `theme.current_*` en los 4 idiomas.
- **Selector de idioma en botón de icono** (`public/js/core/i18n.js`, `public/index.php`): sacado del drop del usuario y ubicado junto al tema como botón globo con el código del idioma al lado; al pulsar abre un menú con los 4 idiomas (nombres nativos) y el actual marcado con `aria-current` + check. Reutiliza `i18n.setLocale()` sin modificarlo; cierra con Escape (devolviendo el foco) y con clic fuera; se re-pinta con `i18n:changed`. Nueva clave `shell.toggle_language` en los 4 idiomas + BUILTIN_ES.
- **Botón de logout en el pie del sidebar** (`public/js/modules/dashboard/sidebar.js`, `public/css/components.css`): icono log-out alineado a la derecha del bloque avatar/nombre/rol, con aria-label y title desde `shell.logout`. El binding es el mismo patrón que el resto del sidebar (re-bind por render) y delega en `AuthState.logout()`, la única implementación de logout. Con el sidebar colapsado se muestra sólo avatar + botón centrados (la info ya la oculta la regla preexistente; `margin-left:auto` se desactiva en colapsado para no romper el layout).
- **Drop de cuenta extensible** (`public/js/ui/account-menu.js`, nuevo; `public/index.php`): el disparador pasa del nombre del usuario a icono de engranaje + etiqueta i18n `shell.account` ("Cuenta"/"Account", sólo icono en pantallas estrechas), con `aria-haspopup`/`aria-expanded`. El contenido se genera desde UNA sola lista `ACCOUNT_ITEMS` (hoy: `[{ id: 'profile', labelKey: 'nav.profile', icon: 'user', route: 'profile' }]`; `permission` opcional por item); el componente sólo la recorre, filtra por permiso y marca el item activo cuando la ruta coincide. Añadir Ayuda u otro item futuro = agregar una entrada a la lista. El mecanismo de apertura sigue siendo el de siempre (Alpine `x-data`/`@click`/`@click.away`) y se le añade `@keydown.escape.window` para cerrar con Escape, que antes no existía.
- **Módulo de perfil** (`public/js/modules/profile/profile.js`, nuevo; registrado en `router.js` y `loader.js` como `profile/profile` → `ProfileProfileModule`): ruta `profile` para cualquier usuario autenticado (`public: false`, sin permiso específico, sin relación con el pendiente `settings/settings`). `UI.pageHeader` + exactamente dos secciones: (a) "Cambiar contraseña" con botón que abre el modal existente de `AuthPasswordModule` (nuevo `openModal()` en su API pública: misma validación, mismo `POST /auth/change-password`, cero lógica de seguridad duplicada; el modo obligatorio sigue intacto vía eventos) y (b) "Idioma" con selector que reutiliza `i18n.setLocale()`.

### Changed

- **Header (`public/index.php`)**: se eliminan del drop del usuario `#user-name`, `#logout-btn` y el `#lang-select` (ya reubicados); el trigger del drop y los botones de tema/idioma usan la clase nueva `.header-icon-btn` (área de click mínima 36px, foco visible, dark mode vía tokens).

### Fixed

- **`shell.toggle_theme` en es/pt/fr**: el valor era inglés ("Toggle theme") en el diccionario ES y PT y neutro en FR; corregido a "Alternar tema" / "Alternar tema" / "Changer de thème". Claves `theme.action_*`/`title_*` quedan sin uso en el header (se mantienen en los diccionarios).

### Deuda conocida

- **`state.js updateHeaderUI` y `password.js mountMenuButton` quedan como no-ops**: buscan `#user-name`/`#logout-btn`, hoy inexistentes en el header (el nombre vive en el sidebar y el cambio de contraseña en Perfil). Ambas funciones se conservan sin tocar por seguridad; el flujo obligatorio de contraseña no depende de ellas.
- **pt.json/fr.json mayoritariamente en español** (defecto preexistente de los diccionarios, no creado en este ciclo): en 1.13.7 sólo se corrigieron/añadieron las claves de los controles nuevos (`toggle_theme`, `toggle_language`, `account`, `nav.profile`, `routes.profile`, `theme.current_*`, `profile.*`).
- **Comportamiento del sidebar colapsado en desktop**: `sidebar-collapsed` sigue ocultando el sidebar entero (`translateX(-100%)`, preexistente); el footer degrada correctamente, pero no hay rail visible de iconos. Si se desea un rail, es un cambio de comportamiento aparte.
- **Servidor Apache local con `/xp/` en 403** (observado al intentar una sonda UI con Chrome headless; la app se verificó con la matriz jsdom y el servidor embebido de E2E). Sin tocar configuración de Apache.
- Se mantienen las deudas ya documentadas en 1.13.6: `settings/settings`, `a { text-decoration }`, ruido EOL de ~100 ficheros y `CHANGELOG.md` fuera de git.

### Verificación de cierre

`node --check` en los 8 JS del ciclo OK — `php -l` en `index.php` OK — JSONs de los 4 locales válidos — **8/8 smokes jsdom verdes** (714 OK, 0 FAIL) — matrices jsdom temporales: header 30/0, sidebar 14/0, drop+perfil 36/0, **matriz final D 35/0** (admin con 18 items de menú y usuario sin permisos sólo con dashboard+products; tema claro/oscuro con ciclo y persistencia; idioma con persistencia local y `PATCH /users/me/preferences`; logout desde el sidebar con `POST /auth/logout` + limpieza + navegación a login; drop con Perfil activo, Escape/clic-fuera y navegación; cambio de contraseña con payload correcto; 0 errores de consola; temporales borradas) — `composer test`: **942 tests, 6815 aserciones, OK** (2 deprecations preexistentes) — `php bin/check-schema-drift.php`: **SIN DRIFT** — lote completo `tools\e2e\`: **17/17 scripts con exit 0**. Commits: `05ebad3` (bloque A), `21e033a` (bloque B), `db3f93d` (bloque C), bloque D sin cambios de código versionables aparte del cierre (una sola línea: Escape en el drop, incluida en este ciclo).

## [1.13.6] - 2026-10-10

CICLO 3: cierre de la deuda heredada de 1.13.4/1.13.5 en tres bloques (settings, persistencia del sidebar y CSS muerto), cada uno ejecutado y aprobado por separado. Sin tocar lógica de negocio, backend ni rutas existentes.

### Added

- **Persistencia del estado de colapso del sidebar** (`public/js/modules/dashboard/sidebar.js`): tras cada clic del usuario en el título de un grupo se guarda el estado mediante el wrapper `Storage` (namespace `xp.`) con la clave `xp.sidebar.section.<sectionKey>`. Precedencia de render: la ruta activa dentro del grupo lo abre siempre; si no, el estado guardado; si no hay valor o la lectura falló, cerrado. Lectura y escritura con `try/catch` propios además del interno del wrapper: sin storage disponible (modo privado, cuota llenada) el sidebar sigue funcionando con fallback a cerrado. La apertura automática por ruta activa no se persiste. Se elimina la memoria de sesión `expandedSections`: la fuente única de verdad es ruta en vivo + `localStorage`.

### Changed

- **Reglas muertas del reset eliminadas de `public/css/style.css`** tras medirlas con Chrome headless sobre el mismo orden de `<head>` de la app (themes → style → components → CDN Tailwind): `h1`-`h6` `font-size` (medido 16px heredado), `font-weight: 600` del bloque de encabezados (medido 400 heredado), `p { margin-bottom }` (medido 0px), `a { color }` (el azul de los enlaces sin clase lo aporta `a:not([class])` de themes.css) y `code`/`pre` `font-family`/`font-size` (medidos como el mono/16px del preflight). Se conservan las propiedades que sí tienen efecto: `color` de headings y `p`, `line-height` de headings, `text-decoration`/`transition` de `a` y `a:hover`. Cabecera de `style.css` actualizada: sin CDN no hay fallback de esas propiedades, el CDN es requisito de la app.

### Fixed

- **Clase `bg-dark` eliminada** (`public/js/modules/audit/logs.js:280`): única referencia en la app y sin definición en ningún CSS propio ni en la config de Tailwind (sólo extiende `primary`, `borderRadius` y `boxShadow`); la clase no surtía efecto y el fondo real del `<pre>` lo aporta la regla `pre { background-color: var(--color-surface-alt) }`. Sin cambio visual.

### Deuda conocida

- **Módulo `settings/settings` sin implementar**: la ruta (`router.js`), el item de menú (`nav.settings`, rol ADMIN), el icono, las claves i18n (4 locales) y el mock del smoke existen, pero nunca hubo archivo `public/js/modules/settings/settings.js` ni API de settings (verificado contra el commit inicial y la historia de git). Por eso `isModuleAvailable('settings')` filtra el item y la sección "Sistema" no se renderiza. Verificado en el Ciclo 3: la sección oculta no deja ningún grupo ni panel vacío visible para ningún rol (ADMIN/MANAGER/USER/VIEWER) y Administración muestra exactamente sus items filtrados por permisos. Decisión: se documenta como deuda, sin registrar ni crear stubs.
- **Alcance pendiente de definir** en dos módulos independientes: (a) Perfil de usuario (datos propios, contraseña, MFA, preferencias); (b) Configuración del tenant para administradores (SMTP, límite de intentos de login, política de contraseñas, timeout de sesión, otros).
- **Requisito previo para ambos**: especificación y endpoints de backend.
- **`a { text-decoration: none }`** en `style.css` también es anulada por el preflight (`inherit`), pero queda fuera del alcance de la deuda listada en 1.13.4 → pendiente.
- **`~100 ficheros con ruido EOL** en el working tree (`core.autocrlf=true`, CRLF↔LF): `git status` los marca como modificados pero el diff real es 0; no entran en ningún commit.
- **`CHANGELOG.md` sigue fuera de git** (`.gitignore`): contiene credenciales de cuentas demo/E2E (`Admin123!`, `Manager123!`) documentadas en entradas antiguas; decisión de no versionarlo hasta sanitizarlo.

### Verificación de cierre

`node --check` en los JS tocados OK — **8/8 smokes jsdom verdes** (714 OK, 0 FAIL) — verificaciones jsdom temporales del Ciclo 3: secciones del sidebar 18/18 y persistencia 19/0 (borradas tras la prueba) — mediciones Chrome headless idénticas antes/después de la limpieza de CSS — `composer test`: **942 tests, 6815 aserciones, OK** (2 deprecations preexistentes) — `php bin/check-schema-drift.php`: **SIN DRIFT** — lote completo `tools\e2e\`: **17/17 scripts con exit 0**. Commits: `f9c3c29` (bloque B), `3b98b09` (bloque C); bloque A sin cambios de código (verificación y documentación).

## [1.13.5] - 2026-10-10

CICLO 2: UI/UX del frontend SPA aplicada por bloques (A: cabeceras, B: botones y permisos, C: sidebar colapsable), cada uno ejecutado, verificado y aprobado por separado. Sin tocar lógica de negocio, router, navegación, backend, rutas, permisos ni base de datos, y sin librerías ni frameworks nuevos.

### Added

- **Cabecera unificada de vistas** (`public/js/ui/render.js`, `UI.pageHeader`): el helper acepta ahora `titleAttrs`, `subtitleAttrs` y `wrapperClass` para que una vista fije atributos (`data-*` de test o anclas) y el espaciado sin reimplementar el marcado. Todas las vistas del SPA usan el patrón único (17 ficheros: audit/logs, dashboard, groups y los módulos de inventory, roles y users); se cierra así la observación de 1.13.4 sobre cabeceras inconsistentes.

### Changed

- **Botón de alta por permiso real en productos y ubicaciones** (`public/js/modules/inventory/products.js`, `locations.js`): helper `can()` que delega en `UI.can` con fallback de roles alineado con `InventoryPermissionSeeder` (`inventory.item.create` → ADMIN/MANAGER, `inventory.location.manage` → sólo ADMIN); el botón crear se renderiza de forma condicional y el `TEMPLATE` constante pasa a `buildTemplate()`.
- **Devoluciones** (`returns.js`): cabecera migrada a `pageHeader` con el botón de registrar marcado con `data-create-perm`; la visibilidad la resuelve el `applyCreatePermission()` existente.
- **Conteos e incidencias** (`counts.js`, `incidents.js`): los botones de cabecera pasan a `btn-sm`, igual que el resto de acciones de la aplicación.
- **Sidebar colapsable** (`public/js/modules/dashboard/sidebar.js`, `public/css/components.css`): los títulos de sección pasan de `<div>` a `<button>` con `aria-expanded`/`aria-controls` y chevron giratorio; los items de cada sección se envuelven en un panel ocultable (`display:none` + atributo `hidden`). Por defecto sólo está expandida la sección que contiene la ruta activa y, al navegar, se expande la del destino: el sidebar deja de desbordar y no aparece scroll. El estado de colapso vive en memoria (no en `localStorage`, fuera del alcance acordado); en modo icono el colapso se desactiva y los items se muestran siempre, ya que los títulos están ocultos.

### Fixed

- **`tools/e2e/e2e-v191.ps1` (falso positivo de cultura)**: el texto esperado del rechazo de hoja vencida se formateaba con la cultura del hilo de PowerShell (`es-ES` → `1,0000`), pero el backend escribe siempre con punto (`sprintf('%.4f')`, locale-independiente desde PHP 8). El script usa ahora `[cultureinfo]::InvariantCulture`, mismo criterio que `e2e-v190.ps1`. Detectado al certificar el cierre; ni el script ni el backend habían cambiado desde el primer commit.

### Verificación de cierre

`node --check` en los JS tocados OK — **8/8 smokes jsdom verdes** (v162 89 OK, v171 105, v184 100, v193 136, v1100 66, v1102 44, v1103 34, v1120 140; 714 OK, 0 FAIL) — verificación jsdom temporal del colapso del sidebar: **26 OK, 0 FAIL** (borrada tras la prueba) — `composer test`: **942 tests, 6815 aserciones, OK** (2 deprecations preexistentes) — `php bin/check-schema-drift.php`: **SIN DRIFT** — lote completo `tools\e2e\`: **17/17 scripts con exit 0**.

### Desviaciones y pendientes heredados (reportados, no corregidos)

- `public/assets/css/dashboard-ui.css` no existe (observación de 1.13.4, sin verificar referencias).
- `settings/settings` no está registrado en `loader.js`, por lo que la sección Sistema del sidebar no se renderiza (observación de 1.13.4).
- `bg-dark` (logs.js) indefinida y reglas muertas del reset documentadas en 1.13.4 siguen pendientes.

## [1.13.4] - 2026-10-09

CICLO 1: auditoría dirigida y centralización de la capa visual global del frontend SPA. Sin tocar lógica de negocio, router, navegación ni permisos, y sin librerías ni frameworks nuevos. El objetivo era que layout, estilos y componentes compartidos quedaran definidos en una sola fuente por tema (claro/oscuro), con una escala de capas única y tokens de color con contraste AA medido en Chrome real.

### Fixed

- **Contraste AA de la paleta clara** (`public/css/themes.css`): `--color-primary` pasa de `#3b82f6` (3.7:1 como texto, 3.1:1 como fondo con texto blanco) a `#2563eb` (5.2:1 en ambos sentidos); `--color-success`/`--color-warning`/`--color-danger`/`--color-info` bajan a los tonos -700/-800 (`#166534`, `#92400e`, `#b91c1c`, `#2563eb`) para que badges, alerts y enlaces de acción pequeños alcancen 4.5:1 sobre su tinte y sobre blanco. Verificado en la app real: `btn-primary.bg=rgb(37,99,235) fg=rgb(255,255,255)`.
- **Contraste del botón primario en dark** (mismo fichero): `--color-primary-foreground` pasa de blanco (2.5:1 sobre `#60a5fa`) a tinta `#0f172a` (7.0:1), y `--color-primary-hover` aclara a `#93c5fd` en lugar de oscurecer, como es habitual en tema oscuro.
- **`--color-text-subtle` en dark** sube de `#64748b` (3.0:1 sobre `#0f172a`) a `#8291a5` (5.6:1), sin igualarla a `--color-text-muted`.
- **Enlaces de contenido sin clase** (`a:not([class])`, p.ej. "Ver firma" en entregas): el preflight de Tailwind los pintaba con color heredado y sin subrayado, indistinguibles del texto plano; ahora reciben el acento y subrayado en hover, sin afectar a `.btn` ni `.dropdown-item` (especificidad 0-1-1).
- **Anillo de foco deforme en elementos redondos**: `:focus-visible` forzaba `border-radius: var(--radius-sm)`, lo que deformaba toggle, avatar y botones pill al recibir foco de teclado; ahora sólo aplica el `box-shadow` del ring y respeta el radio propio del elemento.
- **Sidebar mobile cortado por el header sticky**: la escala de z-index estaba en conflicto (sidebar 30 < header 40), el panel quedaba por debajo del header al desplegarse en móvil. Escala unificada (ver Changed).

### Changed

- **Escala de z-index única en tokens** (`public/css/themes.css`, consumida por `style.css` y `components.css`): `--z-header: 40`, `--z-dropdown: 40`, `--z-overlay: 44`, `--z-sidebar: 45`, `--z-modal: 50`, `--z-toast: 100`. El orden real queda documentado en el propio fichero; el sidebar (45) se superpone ahora al header sticky (40) en móvil. Verificado en dark y light: `sidebar=45 overlay=44 header=40 toast=100`.
- **Selector del header del shell** (`public/css/style.css`): la regla pasa del elemento `header` genérico a `.app-header` (la clase real del markup de `index.php`), para no capturar cabeceras que un módulo renderice dentro del contenido; se conserva la regla de impresión (`header, .app-footer, .no-print`).
- **Padding de `main` como única fuente** (mismo fichero): antes las reglas CSS (1.5rem/2rem) quedaban pisadas por `p-4 md:p-6` del markup, que es lo que realmente se veía; ahora el CSS fija 1rem / 1.5rem ≥768px y el markup no necesita utilidades de padding.
- **Utilidades hover semánticas declaradas** (`public/css/themes.css`): `.hover\:bg-surface-alt:hover` y `.hover\:border-strong:hover`, que el CDN de Tailwind no genera (esos colores no están en su config) y que el markup de dashboard y password ya usaba sin efecto.
- **Hamburgesa del sidebar** (`public/css/components.css`): `.sidebar-toggle` se reduce a `padding` y `border: none`; display, color, cursor, transición y foco ya los aporta `.btn.btn-ghost.btn-sm` que el markup lleva. Se elimina el `display: block` redundante de la media query de 768px. Verificado: `display=inline-flex` (del botón).
- **Configuración de Tailwind** (`public/index.php`): `tailwind.config.colors.primary` sincronizada con los tokens (`DEFAULT: '#2563eb'`, `dark: '#60a5fa'`), de modo que las utilidades `text-primary`/`bg-primary` del CDN coinciden con `--color-primary`.
- **Token de impresión y overlays** (`public/css/themes.css`): `--overlay-modal`, `--overlay-sidebar`, `--toggle-knob`, `--print-background`/`--print-text` pasan de literales en `style.css`/`components.css` a variables, para que claro y oscuro puedan divergir sin duplicar selectores.

### Documentation

- **Cabecera de `public/css/style.css`**: documenta la cascada medida en Chrome real — el `<style>` del CDN de Tailwind se inyecta al final del `<head>` cuando existe `document.body`, por lo que utilidades y preflight ganan en empate de especificidad a los CSS locales; se explica qué reglas del reset (h1-h6, p, a, code/pre) quedan como fallback documentado frente al preflight, y por qué `.hidden` conserva `!important`.
- **Cabecera de `public/css/components.css` y nota en `.form-group`**: mismo contexto de cascada junto a los componentes afectados.

### Notas

- El comentario del bloque de utilidades de `style.css` queda corregido ("Prácticamente").
- `.skip-link` mantiene `z-index: 100` literal (documentado): al recibir foco debe quedar por encima de todo, incluido el toast; acoplarlo a `--z-toast` lo arrastraría si esa capa se reordena.

### Desviaciones y mejoras que siguen (reportadas, no corregidas)

- **`public/assets/css/dashboard-ui.css` no existe** (ni el directorio `public/assets/`): hay que verificar si alguna vista o plantilla lo referencia antes de darlo por cerrado.
- **`settings/settings` no está registrado en `loader.js`**, por lo que el sidebar lo oculta: revisar si el módulo debe publicarse o retirarse.
- **`bg-dark` (logs.js:280) no está definida** en ningún CSS propio ni en la config de Tailwind: la clase no surte efecto; se dejó tal cual por la regla de no borrar selectores sin verificar usos.
- **Reglas muertas del reset documentadas, no eliminadas** (`style.css`): `h1`-`h6` (font-size/weight), `p { margin-bottom }`, `a`/`a:hover` (color), `code`/`pre` (font-size/family) quedan como fallback ante el preflight; se documentaron en la cabecera en lugar de borrarlas.
- **Vistas con patrón de cabecera inconsistente**: `UI.pageHeader` se usa sólo en groups/counts/incidents/roles/users; el resto combina `.max-w-7xl.mx-auto` + `mb-6` + `text-2xl font-bold` a mano. Unificarlo exige tocar vistas una a una, fuera del alcance de este ciclo.

### Verificación de cierre

`node --check` en `sidebar.js` y `router.js` OK → **8/8 smokes jsdom verdes** (v162 89 OK, v171 105, v184 100, v193 136, v1100 66, v1102 44, v1103 34, v1120 140; 714 OK, 0 FAIL) → `composer test`: **942 tests, 6815 aserciones, OK** (2 deprecations preexistentes) → `php bin/check-schema-drift.php`: **SIN DRIFT** → `php -l public/index.php` OK → lote completo `tools\e2e\`: **17/17 scripts con exit 0** → sondas de Chrome (headless) en dark y light sobre los CSS servidos y sobre la app real en `http://localhost/xp/public/#/login`: tokens aplicados (`--color-primary=37 99 235` light / `96 165 250` dark), login renderiza, `btn-primary`/`input`/`h1` correctos, head order inalterado (themes → style → components → Tailwind CDN), sin regresión de `mb-0`/`p-0`/`.hidden`/focus radius. La sonda temporal `public/_probe_real.html` se eliminó.

## [1.13.3] - 2026-10-09

Tercera tanda del sondeo de usuarios/roles/autenticación bajo la misma instrucción de aplicar "las prioritarias a lo que ya tenemos": paginación real de la pestaña de permisos temporales, procedencia de roles en la ficha de usuario, columna Roles del listado de usuarios, mapeo de códigos de error del backend a `t()`, tests de la deuda declarada y cierre de la observación `rate_limit_config` (overrides de límites por endpoint verificados de extremo a extremo). Sin migraciones, sin seeders y sin endpoints nuevos: se usan sólo rutas ya publicadas. MFA real y `recurso_id` con efecto siguen en pendientes.

### Fixed

- **Los errores del frontend perdían el detalle del backend** (`public/js/ui/actions.js`): `handleActionError` leía `err.data.field`, pero las excepciones llegan en el envelope `{success:false, message, data:{field, category}}` y los errores de controller traen `field`/`message` en la raíz, así que casi todo caía en `t('ui.error_generic')`. El helper lee ahora ambos formatos y el nuevo `UI.errorMessage(err)` resuelve `category` → `status` → `message` → fallback, con claves estáticas `errors.*` visibles para `check-i18n`.

### Added

- **Paginación real de `GET /api/temporary-permissions`** (`TemporaryPermissionRepositoryInterface::findAll(..., page, perPage)` + `count()` con `buildWhere()` compartido, `TemporaryPermissionController::index`): `page`/`per_page` (default 1/20, tope 100) sobre exactamente los mismos filtros que el recuento, y respuesta con `temporary_permissions`, `total` y `pagination{total,page,per_page,has_next}` — el contrato que la tabla de usuarios ya esperaba.
- **Procedencia de roles en `GET /api/users/{id}/roles`** (`UserController::getRoles` → `AuthorizationService::getUserRolesWithOrigin`): la misma fuente de login y `/auth/me` (directos + grupos) con `origen` (`directo` | `grupo`) en cada rol; los directos mandan en la deduplicación y el orden sigue siendo por nombre.
- **Columna Roles en `GET /api/users`** (`UserController::index`): cada fila lleva `roles: [{id,nombre,tipo,origen}]` con esa misma fuente y sin el JSON de permisos, para pintar el origen en la tabla sin inflar el payload.
- **Frontend `public/js/modules/users/users.js`**: columna Roles con badge de origen (`roleOriginBadge`/`rolesCell`), paginación real de la pestaña de permisos temporales (`state.temp`, `loadTemp`, `renderTempPagination`) y toasts de error delegados en `UI.errorMessage`.
- **i18n**: 12 claves nuevas ×4 idiomas (es/en/pt/fr); `check-i18n` queda en 1478 claves por locale.
- **Tests de la deuda declarada**: `tests/Unit/TemporaryPermissionControllerTest.php` (36 tests, 137 aserciones), `tests/Unit/RateLimitConfigOverrideTest.php` (6/14), `tests/Unit/AuthControllerLoginTest.php` (5/20), `tests/Unit/UserControllerTest.php` (6/30) y `tests/Unit/AuthenticationMiddlewareTest.php` (15/26); `tests/Support/PlatformControlSqliteTestCase.php` gana las tablas `tenants`, `refresh_tokens` y `rate_limit_config`. El bloqueo por cuenta de login se cubre con dobles Redis en memoria (`LoginInMemoryRedisService`/`LoginInMemoryRedisClient`) dentro del propio fichero de test.
- **`tools/e2e/e2e-v1133.ps1`** (37 casos): paginación de temporales (páginas 1/2/3 y 99 sobre 5 concesiones, límites `page=0` y `per_page=1000`, unión de páginas sin solapes, `total` contra el `COUNT(*)` de la BD, revocación e `include_inactive`), procedencia y deduplicación de roles más la columna `roles[]` (claves exactas y filas sin roles), y el override de `rate_limit_config`.

### Observación cerrada

- **`rate_limit_config` deja de estar sin verificar**: la tabla sigue vacía en la BD viva (no hay overrides en runtime, que es lo esperado), pero su camino queda cubierto de extremo a extremo: `e2e-v1133` siembra un override global `2/300 POR_ENDPOINT` sobre `/api/tenant/stats`, comprueba 200 → 200 → 429 con `X-RateLimit-Limit: 2`, `X-RateLimit-Remaining: 0` y `Retry-After`, verifica la fila de `rate_limit_log` (`limite=2`, `requests_contados=3`, `REJECT`) y retira configuración, log y claves de Redis, con control a 200 antes y después.

### Desviaciones y mejoras que siguen (reportadas, no corregidas)

- **N+1 de roles en `GET /api/users`**: la columna `roles` llama a `getUserRolesWithOrigin` por fila (90 filas ≈ 180 consultas por listado). Es el mismo coste que ya tenía el endpoint aislado, pero multiplicado por el listado; convendría un `UNION` por lote.
- **`pt.json` y `fr.json` llevan el módulo `users` en español**: preexistente, no introducido en esta tanda; `check-i18n` sólo compara claves, no traducciones.
- **2 deprecations preexistentes** en `StockCalculationServiceTest` (DataProvider en doc-comment, estilo PHPUnit antiguo).
- **El toast de error puede perder el detalle del backend**: `UI.errorMessage` cae a `t('ui.error_generic')` cuando la respuesta no trae `message`; el cuerpo completo queda sólo en la consola.
- Pendientes de la fase: MFA real y `recurso_id` de permisos temporales con efecto (sigue siendo la clave de duplicado, sin impacto en autorización).

### Verificacion de cierre

`php -l` en los 11 PHP tocados OK → `composer test`: **942 tests, 6815 aserciones, OK** (2 deprecations preexistentes) → `php bin/check-schema-drift.php`: **SIN DRIFT** (32 tablas, 388 columnas, 143 índices, 33 enums, 73 FK; sin cambios de esquema) → `php bin/check-i18n.php`: **OK, 1478 claves por locale, 0 errores y 0 avisos** → `node --check` en `actions.js` y `users.js` OK → **8/8 smokes jsdom verdes** → lote completo de `tools\e2e\` sobre `xproject_e2e`: **17/17 scripts con exit 0**, incluido el nuevo `e2e-v1133.ps1` (37 casos OK).

## [1.13.2] - 2026-10-09

Segunda tanda del sondeo de usuarios/roles/autenticación bajo la instrucción explícita de aplicar "las prioritarias a lo que ya tenemos; nuevas funciones o migraciones dejarlas en pendiente": guarda del único ADMIN en `PATCH /users/{id}`, rate limit de `change-password` por usuario, bloqueo de login por cuenta y `minlength=8` en el formulario de login. Sin migraciones, sin seeders y sin endpoints nuevos; lo que requiere migración o funciones nuevas (MFA real, `recurso_id` con efecto, paginación real de temporales, roles con procedencia en la ficha de usuario, columna Roles, mapeo de códigos de error a `t()`, tests unitarios nuevos) sigue registrado como pendiente.

### Fixed

- **`PATCH /api/users/{id}` permitía baja del último ADMIN activo** (`UserController::update`): la guarda de "único admin" existía sólo en `updateStatus`; ahora `update` comprueba tras `assertCanActOnUser` que si `estado` pasa a algo distinto de `ACTIVO` sobre un usuario que es ADMIN activo del tenant con `countActiveAdmins <= 1` responda 403 (`UserException` categoría `FORBIDDEN`), dejando el registro intacto. Descubierto al aplicar la escalada de 1.13.1 sobre el propio endpoint.
- **El Router partía los parámetros de middleware por comas antes de construirlo** (`Router::executeMiddleware`): `RateLimitMiddleware:10,300` se descomponía en `new RateLimitMiddleware('10', '300')` y estrellaba el `?RateLimiter` del segundo argumento (TypeError 500 en la ruta, visible en `audit_logs` como fallo de `POST /api/auth/change-password`). Ambos middlewares documentan el contrato de recibir el paramString entero y hacer su propio split (permisos `a,b`, límites `N,S`); el patrón documentado `PermissionMiddleware:a,b` quedaba igual de roto y ahora también funciona. Sólo afectaba a cadenas con comas: las rutas con un único token (`:api`, `:login`, `:critical`, un permiso) se construían igual que antes.

### Added

- **Rate limit de `POST /auth/change-password` por usuario** (`routes/api.php`): la cadena pasa de `Audit, RateLimit:api, Auth` (100/min genérico por IP) a `Audit, Auth, RateLimit:10,300`, de modo que el RateLimit corre después de la autenticación y su clave es `user:<id>`: 10 intentos cada 5 minutos por usuario — se elimina la fuerza bruta al password actual con un token robado sin penalizar a otros usuarios del mismo IP.
- **Bloqueo de login por CUENTA además de por IP** (`AuthController::login` + `RateLimiter::keyForAccount`): clave `email:{tenant}:{email}:login` con `attempt(3, 300)` tras resolver el tenant — el cuarto fallo seguido devuelve 429 aunque vengan de IPs distintas — y limpieza de la clave junto a las existentes cuando el login tiene éxito. El umbral 3 (por debajo del límite IP de `:login`, 5) garantiza que el residuo del contador IP tras el bloqueo por cuenta queda en 4 y el siguiente login con éxito, que limpia todas las claves, no se autobloquea. El error de credenciales sigue siendo 401 y no audita fila nueva en el bloqueo (mismo criterio que el resto de 429 del endpoint).
- **Validación de contraseña del login a 8 caracteres** (`public/js/modules/auth/login.js`): `minlength="6"` → `minlength="8"` y `data.password.length < 6` → `< 8`, alineado con el backend; las 4 claves `login.error_short_password` (es/en/pt/fr) pasan a "8 caracteres".
- **`tools/e2e/e2e-v1132.ps1`**: E2E de la tanda (15 casos): precondición por SQL de quedar exactamente 1 ADMIN activo (desactiva extras y los restaura al final — `manager@acme.local` tiene rol ADMIN y lo usan otros scripts del lote), 403 en la baja del último admin con verificación SQL de que sigue `ACTIVO`, controles positivos (fijar `ACTIVO` y baja de un usuario sin rol ADMIN), `change-password` con 429 en la 11ª petición (10ª incorrecta) y sin 429 antes, login con password incorrecta 401×3 + 429 en el cuarto fallo, login de otra cuenta con 200 tras el bloqueo (prueba de que el bloqueo es por cuenta y su éxito limpia el IP) y persistencia del bloqueo por cuenta (429 en un fallo posterior aun cuando el IP ya está limpio).

### Desviaciones y mejoras que siguen (reportadas, no corregidas)

- **`rate_limit_config` sigue sin filas**: la infra de overrides de límites por endpoint (spec §6.3, `RateLimiter::getEndpointConfig`) existe pero la tabla está vacía en la BD viva; todos los límites aplicados son los de código/.env. Observación de configuración, sin cambio.
- Pendientes de esta fase por requerir migración o funciones nuevas (detalle en la introducción): MFA real, `recurso_id` de permisos temporales con efecto, paginación real y conmutador `include_inactive` de la pestaña temporal, roles con procedencia en la ficha de usuario, columna Roles en la tabla de usuarios, mapeo de códigos de error del backend a `t()` y tests unitarios de `AuthController`/`UserController`/`AuthenticationMiddleware`.

### Verificacion de cierre

`php -l` en los 5 PHP tocados (`UserController`, `routes/api.php`, `RateLimiter`, `AuthController`, `Router`) OK → `composer test`: **906 tests, 6707 aserciones, OK** (2 deprecations preexistentes; el fix del Router no altera ninguna ruta de test) → `php bin/check-schema-drift.php`: **SIN DRIFT** (32 tablas, 388 columnas, 143 índices, 33 enums, 73 FK; sin cambios de esquema) → `php bin/check-i18n.php`: **OK, 1466 claves por locale, 0 errores y 0 avisos** → `node --check public/js/modules/auth/login.js` OK → **8/8 smokes jsdom verdes** → lote completo de `tools\e2e\` sobre `xproject_e2e`: **16/16 scripts con exit 0**, incluido el nuevo `e2e-v1132.ps1` (todos los casos OK). Nota de proceso: el primer intento del E2E destapó el TypeError del Router en `change-password` y un error propio del script (el bucle de logins fallidos hacía 5 intentos en lugar de 4, con lo que el quinto dejaba el contador IP del login lleno y el control de otra cuenta caía en 429 de IP); ambos corregidos antes del cierre.

## [1.13.1] - 2026-10-09

Correcciones de los 3 primeros puntos del sondeo de usuarios/roles/autenticación (petición explícita de continuar con los puestos 1-3, "baratos y sin migración"), sobre la base ya estable de 1.13.0. Sin cambios de esquema, sin seeders y sin tocar frontend.

### Fixed

- **`PATCH /api/users/{id}` aplicaba la escalada y auditaba valores previos** (`UserController::update`): era la única escritura de usuarios sin `assertCanActOnUser` (destroy/status/reset/roles ya la tenían); ahora un poseedor de `users.user.edit` sólo puede editar a usuarios cuyos permisos efectivos posee (y sólo un ADMIN sobre otro ADMIN; las acciones sobre uno mismo siguen permitidas), se comprueba 404 antes que 403 con el `findById` previo y la auditoría `user.update` incluye `valores_anteriores` (antes sólo `valores_nuevos`).
- **`user.delete` y `role.delete` sin auditoría de dominio**: `UserController::destroy` (firma ampliada con `array $request = []`, inyectado por nombre por el Router) escribe `user.delete` con `entidad_id` y los valores previos del usuario; `RoleController::destroy` escribe `role.delete` con los permisos previos del rol. Ambas siguen el patrón de `product.delete` (sólo `valores_anteriores`; los fallos siguen auditándolos el hook del Router).
- **`AuthorizationService::invalidateCacheForRole` ignoraba los portadores vía grupos**: la consulta pasa a `UNION` de `role_assignments` (directos) y `group_members × group_roles × user_groups` del tenant, de modo que al mutar un rol (permisos, estado, baja o restore) los miembros que lo tienen por grupo ven el cambio de inmediato en lugar de conservar permisos cacheados hasta el TTL de 60 s.

### Verificacion de cierre

`php -l` en los 3 PHP tocados OK → `composer test`: **906 tests, 6707 aserciones, OK** (2 deprecations preexistentes) → `php bin/check-schema-drift.php`: **SIN DRIFT** (32 tablas, 388 columnas; sin cambios de esquema) → lote completo de `tools\e2e\` sobre `xproject_e2e`: **15/15 scripts con exit 0**, incluido el nuevo `e2e-v1131.ps1` (28 casos: escalada de `PATCH /users` con control positivo/autocambio/403 sobre ADMIN/404, auditorías `user.update`/`user.delete`/`role.delete` con valores previos verificadas por SQL, e invalidación de cache con portadores directos Y de grupo — ambos 403 inmediatos con el TTL pendiente). Sin tocar JS ni locales: no aplica `node --check`, smokes ni `check-i18n`.

## [1.13.0] - 2026-10-09

Fase **v1.13.0 "Huecos hallados: restauración, listados y cache de permisos (BACKEND + FRONTEND)"** con petición explícita del usuario para trabajar los huecos declarados en 1.12.0/1.12.1 (autorización de backend para este alcance, sin migraciones ni seeders nuevos: se reutilizan los permisos existentes `users.user.delete`, `groups.group.delete` y `roles.role.delete`). Restauración = inverso exacto del borrado (usuarios: `estado='ACTIVO'` + `deleted_at=NULL`; grupos: `deleted_at=NULL` + `activo=1`; roles: `deleted_at=NULL` + `activo=1`). Nada subido a producción; verificación contra la BD real (`xproject_e2e`).

### Added

- **`POST /api/users/{id}/restore`** (`routes/api.php`, `src/Core/Users/Http/UserController.php::restore`): middleware `Audit, Auth, Tenant, PermissionMiddleware:users.user.delete, RateLimit`; `assertCanActOnUser`, `UserService::restore` (404 si no existe, 409 si no está eliminado), invalidación de cache de permisos y auditoría de dominio `user.restore`. `UserService::delete` y `restore` quedan documentados como inversos exactos.
- **`GET /api/users?include_deleted=true`** (`UserController::index` + `UserService::findAll`): lista también los soft-deleteados con su `deleted_at` en la respuesta; exige el mismo permiso que borrar (`users.user.delete` vía `hasPermission`) y devuelve 403 en caso contrario. El filtro se normaliza (`'1'/'true'`) para que `'false'` no active el modo borrados.
- **`POST /api/groups/{id}/restore`** (`routes/api.php`, `GroupController::restore`): permiso `groups.group.delete`, 404 si no existe, 409 si no está eliminado, `invalidateCacheForGroup` y auditoría `group.restore` con `valores_nuevos`.
- **`GET /api/groups?include_deleted=true`** (`GroupController::index`): mismo gate de permiso que borrar.
- **Conteo de miembros en `GET /api/groups`** (fin del N+1): `UserGroupRepository::memberCounts(tenantId)` (una consulta `group_members` unida a `users` sin borrados y al tenant) y `miembros` numérico en cada grupo de la lista.
- **`GET /api/roles?include_deleted=true`** (`RoleController::index` + `RoleRepository::findAll(..., includeDeleted)`): devuelve también los borrados (omitiendo el filtro de `activo`) con gate `roles.role.delete` → 403, para que la UI alcance el `POST /roles/{id}/restore` ya existente. Añadida además la auditoría de dominio `role.restore` (faltaba en el endpoint preexistente).
- **Cache de permisos con caducidad** (`AuthorizationService::getUserPermissions`): la entrada pasa a formato `{permisos, expira_en}` donde `expira_en` es la caducidad del temporal vigente más próximo (`nearestTemporaryExpiry`); si `expira_en` ya venció, la entrada se ignora y se recalcula, de modo que la expiración de un permiso temporal surte efecto sin esperar al TTL de 60 s. El formato legado (array plano) se sigue aceptando y lo retira el TTL (compatibilidad con las precargas de los tests).
- **Frontend `public/js/modules/users/users.js`**: casilla "Mostrar eliminados" (con `users.user.delete`) que envía `include_deleted=true`, badge "Eliminado" en la columna de estado, acción "Restaurar" con modal de confirmación y `POST /users/{id}/restore` (sólo en filas borradas); el buscador de beneficiario del modal de permisos temporales pasa a buscar por texto contra el directorio (`GET /users?search=&per_page=50`) con actualización selectiva del select (sin perder el resto del formulario), eliminando la dependencia del listado estático de 100 usuarios.
- **Frontend `public/js/modules/groups/groups.js`**: casilla "Mostrar eliminados" (gate `groups.group.delete`), badge "Eliminado", acción "Restaurar" con confirmación; la columna Miembros usa ahora el `miembros` del listado y el N+1 queda sólo como fallback si alguna respuesta no trae la clave.
- **Frontend `public/js/modules/roles/roles.js`**: casilla "Ver borrados" (gate `roles.role.delete`), badge "Eliminado" y acción "Restaurar" con confirmación (en filas borradas sólo queda esa acción).
- **i18n** (`resources/locales/{es,en,pt,fr}.json`): +33 claves por locale (`*.filters/filter.deleted`, `*.state.deleted`, `*.actions.restore`, `*.restore.*`, `*.errors.restore` y `users.grant.user_search*`), con `pt`/`fr` = copia de `es` por convención de proyecto y `en` traducido.
- **`tools/e2e/e2e-v1130.ps1`**: E2E de los huecos (51 casos): ciclo completo borrar→listar→`include_deleted`→restore de usuario/grupo/rol con los códigos 204/200/404/409/403, matriz de permisos de `include_deleted` y de los tres restore con un usuario de sólo lectura, `miembros` numérico en `GET /groups`, auditorías `user/group/role.restore` en `audit_logs`, contrato de campos que lee la UI (`deleted_at`, `miembros`) y prueba de la invalidación de cache por caducidad (temporal a +40 s con fecha calculada por `NOW()` de MySQL: 200 cacheado, 403 tras la caducidad con el TTL de 60 s aún pendiente) más `GET /auth/me` sin el permiso.

### Fixed

- **Gate que faltaba en `GET /roles?include_deleted=true`** (`RoleController::index`): el primer pase del E2E destapó que el filtro añadido no iba acompañado del chequeo `roles.role.delete` (usuarios y grupos sí lo tenían); añadido con el mismo patrón (descubierto por la verificación E2E, corregido antes del cierre).

### Desviaciones y mejoras que siguen (reportadas, no corregidas)

- **`PATCH /api/users/{id}` sin escalada de actor** (`UserController::update`): es el único endpoint de escritura de usuarios sin `assertCanActOnUser` (los demás sí lo llaman); un poseedor de `users.user.edit` podría editar a un usuario con más permisos. Además no audita `valores_anteriores`. **(Corregido en 1.13.1.)**
- **`user.delete` y `role.delete` sin auditoría de dominio**: las rutas tienen `AuditMiddleware` (fila genérica de ruta) pero no escriben `auditEvent` con `entidad_id` y valores previos, a diferencia de `restore`; en el borrado de un rol no se registran sus permisos anteriores. **(Corregido en 1.13.1.)**
- **`invalidateCacheForRole` ignora los portadores vía grupos**: sólo limpia `role_assignments`, no `group_roles`, por lo que los miembros que tienen el rol por grupo conservan permisos cacheados hasta 60 s tras restringirlo. **(Corregido en 1.13.1.)**
- **`recurso_id` de los permisos temporales sigue sin evaluarse** al autorizar (se almacena y se valida, pero `getActiveTemporaryPermissions` no filtra por recurso); el modal de concesión ni siquiera lo envía.
- **MFA sigue siendo esqueleto**: `users.mfa_habilitado` y `permissions.requiere_mfa` existen en esquema y seeders pero no hay enrolamiento, verificación ni step-up (`validateMfaIfNeeded` sólo devuelve el flag). Requiere migración si se decide implementar.
- **Mensajes del backend en español fuera de `t()`** (arrastrado declarado): la UI muestra `err.message` del servidor sin clavar por código.
- **Contrato asimetría en restore de roles**: `POST /roles/{id}/restore` sobre un rol no borrado devuelve 404 (`RoleRepository::restore` por `rowCount=0`), no 409 como usuarios y grupos (comportamiento preexistente, documentado y cubierto por el E2E).
- **`GET /users` envuelve doble** la paginación (`data.data` + `data.pagination`) mientras `GET /groups` y `GET /roles` devuelven una capa menos; los consumidores se han de saber el formato (observación de contrato, sin cambio).
- **Pestaña de permisos temporales sin paginación real** y sin conmutador `include_inactive` (el backend ya lo soporta), por lo que los badges de expirado/revocado de la UI sólo se ven filtrando por usuario.
- **Rate limit de `change-password`** = `RateLimitMiddleware:api` (100/min) y bloqueo de login sólo por IP (sin contador por cuenta); validación de contraseña del login con `minlength=6` frente a las 8 que exige el backend. **(Corregido en 1.13.2.)**
- **Tests unitarios nuevos no añadidos** por la instrucción del usuario de esta fase (pruebas en la BD real, E2E como autoridad): los huecos quedan cubiertos por `e2e-v1130.ps1`; deuda de tests de `AuthController`, `UserController` y `AuthenticationMiddleware` registrada en el sondeo.
- **Arrastrados de sprints anteriores aún abiertos**: `AuthenticationContext::getRoles` sin llamadores, equipos (`teams`) inexistente como concepto en el código, `email_verificado`/`avatar_url`/estado `PENDIENTE_CONFIRMACION` sin flujos, 2 PHPUnit deprecations preexistentes.

### Verificacion de cierre

`php -l` en los 10 PHP tocados (repositories, controllers, `UserService`, `AuthorizationService`, `routes/api.php`) OK → `composer test`: **906 tests, 6707 aserciones, OK** (2 deprecaciones preexistentes; +1 test de la ruta `POST /groups/{id}/restore` en `PlatformControlRoutesTest`) → `php bin/check-schema-drift.php`: **SIN DRIFT** (32 tablas, 388 columnas, 143 índices, 33 enums, 73 FK; sin cambios de esquema) → `php bin/check-i18n.php`: **OK, 1466 claves por locale, 0 errores y 0 avisos** → `node --check` en `users.js`, `groups.js` y `roles.js` OK → **8/8 smokes jsdom verdes (714 asserts)** → lote completo de `tools\e2e\` sobre `xproject_e2e`: **14/14 scripts con exit 0**, incluido `e2e-v1130.ps1` (51 OK) → scripts de diagnóstico temporales eliminados y servidor embebido detenido.

## [1.12.1] - 2026-10-09

Corrección **v1.12.1 "Roles de grupo, permisos temporales y selector de roles (BACKEND + FRONTEND)"** con autorización temporal en `AGENTS.md` (frontend `public/js/**`, `resources/locales/*.json`, `tools/smoke/**` y backend limitado a los tres fallos, sin esquema ni rutas nuevos). Tres fallos con evidencia real: usuario cuyo único rol viene de un grupo entraba como "sin rol" con dashboard vacío; el permiso temporal vigente no surtía efecto en la sesión; el selector de roles del modal de Usuarios salía vacío. Fuente única: `AuthorizationService` para `login`, `/auth/me` y `PermissionMiddleware`; el token deja de ser autoridad. Sin TRUNCAR ni BORRAR datos de la BD de trabajo; E2E en `xproject_e2e`.

### Fixed

- **Rol de grupo y permisos temporales en la sesión** (`src/Core/Authorization/Service/AuthorizationService.php`): métodos nuevos `getUserRolesWithOrigin(userId, tenantId)` (roles directos de `role_assignments` + roles de grupos activos, deduplicados con prioridad del directo, cada uno con `origen: 'directo' | 'grupo'`, orden por nombre) y `getActiveTemporaryPermissions(userId)` (`identificador`, `fecha_expiracion`, `motivo` de los temporales vigentes).
- **`src/Core/Authentication/Http/AuthController.php`**: `login`, `refresh` y `me` dejan de leer los claims firmados del token y calculan en cada llamada los roles con origen y los permisos efectivos vía `AuthorizationService` (directos + grupos + temporales). `login` añade `user.permisos`; `/auth/me` responde `roles` (con `origen`), `permisos` y `permisos_temporales` además del `user` con `preferencias`.
- **Autorización por claims eliminada en los guards**: `RoleController::requireAdmin` y `TenantController::requireAdmin` comprueban `AuthorizationService::hasAdminRole` contra la BD en cada petición; quitar el rol ADMIN surte efecto en la siguiente petición, no al caducar el token. `PermissionMiddleware` no cambia (ya usaba `AuthorizationService`).
- **Selector de roles del modal de Usuarios** (`public/js/modules/users/users.js`): `openRoles` carga ahora el catálogo `GET /roles` si `state.roles` está vacío (antes sólo se cargaba al abrir el alta, por lo que el select salía vacío) y re-pinta el modal cuando el catálogo llega; regla de escalada `canAssignRole` (todos los permisos del rol ⊆ permisos efectivos del actor, con comodín `*`) aplicada al selector de asignación y al select de rol del alta.
- **Sesión local siempre fresca** (`public/js/modules/auth/state.js`): `getPermissions()` lee `currentUser.permisos` (calculado por el backend; la derivación antigua queda como fallback), `refreshUser()` fusiona `roles`/`permisos`/`permisos_temporales` de `/auth/me`, y `init()` refresca la sesión al volver a la pestaña (`visibilitychange`/`focus`) y tras un `http:error` 403 (throttle de 1 s, excluye el 403 de `password_change_required` que gestiona `password.js`). El sidebar no requiere cambios: reacciona a los eventos `auth:*` al renderizar.

### Added

- **`tools/e2e/e2e-v1121.ps1`**: E2E de la corrección contra `xproject_e2e` (44 casos): rol de grupo en `login`/`/auth/me`/`GET /users`, control negativo 403, temporal vigente (24 h), expirado sembrado por SQL E2E, revocación y baja del grupo con token vivo, quitar ADMIN con token vivo (rol auxiliar `roles.role.edit` para aislar `requireAdmin`) y contrato de los campos que lee la UI (`user.{id,email,nombre}`, `roles[].nombre`, `permisos[]`, `permisos_temporales[]`, `GET /roles data.roles[]` con `{id,nombre,tipo,permisos}`). Pre-fix: 15 FALLOS (reproducción); post-fix: 44 OK.
- **Tests unitarios adaptados al nuevo contrato**: `RoleControllerGuardTest` y `RoleTenantIsolationTest` siembran la asignación real de ADMIN (el claim ya no es la autoridad) y `UserPreferencesEndpointTest` monta las tablas que consulta `/auth/me` (`roles`, `role_assignments`, `group_members`, `group_roles`, `user_groups`, `permissions`, `temporary_permissions`) y construye `AuthController` con `AuthorizationService`.

### Desviaciones observadas (reportadas, no corregidas)

- **Origen de los permisos temporales en `permisos_temporales`**: los campos `origen` de `roles[]` sólo adoptan `'directo'` o `'grupo'`; el origen temporal se expone aparte en `permisos_temporales[]` (decisión de contrato; la UI no necesita distinguirlo por rol).
- **Punto 4 del plan original (fixtures de contrato grabadas en los smokes) sustituido** por aserciones de contrato con respuestas reales en vivo dentro de `e2e-v1121.ps1`, por instrucción explícita del usuario ("pruebas en la BD real, nada de smokes/SQLITE/mockups nuevos"); no se creó ningún smoke ni test nuevo con mocks.
- **E2E del plan con login previo a la concesión**: las aserciones iniciales de `LOGIN u1`/`LOGIN u2` fallaban porque `New-ActivatedUser` hace login antes de conceder rol/permiso; el script relanza el login tras la concesión (los casos pasan). Un usuario que ya tenía sesión antes de la concesión se resuelve en frontend con los nuevos hooks de refresco.
- **`AuthenticationContext::getRoles` sigue sin llamadores** (arrastre de sprints anteriores; fuera del alcance de la corrección).
- **2 PHPUnit deprecations preexistentes** (fuera de alcance) y `composer test` en modo "OK, but there were issues" por ellas.
- **Arrastrados de sprints anteriores, aún abiertos**: expiración de permisos temporales sin invalidación de cache (sólo TTL 60s), `recurso_id` almacenado pero no evaluado al autorizar, MFA/teams fuera de alcance y mensajes del backend en español fuera de `t()`.

### Verificacion de cierre

`php -l` en los 4 PHP de backend y los 3 de tests tocados OK → `composer test`: **905 tests, 6699 aserciones, OK** (2 deprecaciones preexistentes) → `php bin/check-schema-drift.php`: **SIN DRIFT** (32 tablas, 388 columnas, 143 índices, 33 enums, 73 FK) → `php bin/check-i18n.php`: **OK, 1433 claves por locale, 0 errores y 0 avisos** (sin tocar locales) → `node --check` en `state.js` y `users.js` OK → **8/8 smokes jsdom verdes (714 asserts)**: v1100=66, v1102=44, v1103=34, v162=89, v171=105, v184=100, v193=136, v1120=140 → lote completo de `tools\e2e\` sobre `xproject_e2e`: **13/13 scripts con exit 0**, incluido `e2e-v1121.ps1` (44 OK) → script temporal `_diag-v1121.ps1` eliminado y servidor embebido detenido → sección temporal de autorización retirada de `AGENTS.md`.

## [1.12.0] - 2026-10-08

Sprint **v1.12.0 "UI de control (SOLO FRONTEND)"** con autorización temporal en `AGENTS.md` (`public/js/**`, `resources/locales/*.json`, `tools/smoke/**` y el propio `AGENTS.md`): cuatro pantallas nuevas sobre la API existente de 1.11.x — Usuarios, Roles, Grupos y cambio de contraseña — con router/loader/sidebar, i18n completa en 4 idiomas y smokes jsdom. Sin tocar `src/`, `routes/`, `bin/` ni BD.

### Added

- **`public/js/modules/users/users.js`** (`UsersUsersModule`, ruta `users`): listado con filtros de estado/búsqueda y paginación (`GET /users`), pestañas Usuarios/Temporales, alta con `Idempotency-Key` y modal de contraseña temporal, edición, cambio de estado (`POST /users/{id}/status`), reset de contraseña, asignación/quita de roles (`POST`/`DELETE /users/{id}/roles/{roleId}`), baja (`DELETE /users/{id}`), concesión de permiso temporal (`POST /temporary-permissions` con duración por horas o fecha) y revocación (`DELETE /temporary-permissions/{id}`). Guards UI con `UI.can`: `users.user.{view,create,edit,status,reset,delete}` y `temporary.permission.{view,grant,revoke}` con fallback de rol.
- **`public/js/modules/roles/roles.js`** (`RolesRolesModule`, ruta `roles`): listado con filtro de inactivos (`GET /roles?include_inactive`), alta CUSTOM con matriz de permisos módulo × recurso leída del DOM (`POST /roles {nombre, descripcion, permisos}`), edición, baja (`DELETE /roles/{id}`) y modal de permisos por rol (`GET /roles/{id}/permissions` + `GET /permissions`); roles BUILT_IN o sin `roles.role.edit` se abren en solo lectura (sin botón guardar y casillas deshabilitadas).
- **`public/js/modules/groups/groups.js`** (`GroupsGroupsModule`, ruta `groups`): listado con conteo de miembros (N+1 sobre `GET /groups/{id}/members`, marcador `…` hasta que responde), alta con `Idempotency-Key`, edición y baja; detalle con pestañas Miembros/Roles (`GET /groups/{id}/members` y `/roles`, badge "rol borrado" cuando la asignación trae `role_deleted_at`), búsqueda de usuarios (`GET /users?search=&estado=ACTIVO`), alta/baja de miembros y asignación/retirada de roles de grupo.
- **`public/js/modules/auth/password.js`** (`AuthPasswordModule`, ruta `auth/password`): botón "Cambiar contraseña" en el menú de usuario y modal con la misma validación del backend (8+ mayúscula + minúscula + dígito, distinta de la actual) contra `POST /auth/change-password {password_actual, password_nuevo}`; modo obligatorio disparado por `auth:password-required` (login con `password_change_required`) o por `http:error` 403 con `data.data.category = 'password_change_required'` (sin X, sin cancelar, sólo guardar o cerrar sesión; el cierre se ignora hasta cambiar la clave) y limpieza en `auth:logout`. El modal vive en `#password-modal-root` fuera de `#module-container` para sobrevivir a los cambios de ruta.
- **Wiring**: `router.js` (rutas `users`/`roles`/`groups` con `titleKey` `routes.*`; la ruta `users` apuntaba a `users/users-page`, un módulo inexistente), `loader.js` (registro de `users/users`, `roles/roles`, `groups/groups` y `auth/password`), `sidebar.js` (items `nav.roles` con `roles.role.view` y `nav.groups` con `groups.group.view`), `auth/state.js` (`ModuleLoader.preload('auth/password')`) y `auth/login.js` (emite `auth:password-required {forced: true}` cuando la respuesta trae `password_change_required`).
- **i18n**: 1433 claves por locale (+298: `shell.change_password`, `nav.*`/`routes.*` de roles y grupos y las secciones nuevas `password`, `users`, `roles`, `groups`) en `es`/`en`, con `pt`/`fr` = copia de `es` según la regla; todas las cadenas pasan por `t()` con claves estáticas (helpers `estadoLabel`, `typeBadge`…) para que `check-i18n` no detecte claves dinámicas.
- **Smokes**: `tools/smoke/smoke-v1120.mjs` nuevo (**140 asserts**: registro y wiring, Usuarios, Roles, Grupos, sidebar por permisos y flujo de contraseña) y `tools/smoke/smoke-v1102.mjs` actualizado (rutas `users/users`, `roles/roles` y `groups/groups` como `availableOnly`).

### Fixed

- **Modal obligatorio de contraseña sin botón de guardar** (`password.js`): el pie forzado sólo tenía "Cerrar sesión", lo que hacía imposible completar el cambio obligatorio; ahora incluye además `pw-submit` (sigue sin cancelar ni X, el cierre sigue ignorado hasta cambiar la clave).
- **Doble `GET /users` al conceder un permiso temporal** (`users.js`): `ensurePermissions()` cargaba también el directorio de usuarios y `ensureUsers()` lo cargaba de nuevo en el mismo `Promise.all`; `ensurePermissions` se queda sólo con el catálogo de permisos.

### Huecos declarados (reportados, no corregidos)

- **Sin endpoint de restauración de usuarios ni grupos**: `DELETE /users/{id}` y `DELETE /groups/{id}` hacen soft delete, pero no existe `POST .../restore` en `routes/api.php`; la UI no ofrece "Restaurar" y los borrados no se listan en ningún sitio.
- **Roles borrados no restaurables desde la UI**: existe `POST /roles/{id}/restore`, pero `GET /roles` filtra `deleted_at IS NULL` (`RoleRepository`), así que un rol CUSTOM borrado no aparece en el listado y el restore no es alcanzable (hueco documentado ya en 1.11.x).
- **`GET /groups` sin conteo de miembros**: para pintar la columna Miembros el listado hace N+1 `GET /groups/{id}/members` (sólo `total`); la API no expone un conteo batch.
- **Selector de usuario de la concesión temporal limitado a los primeros 100**: usa `GET /users?per_page=100` sin búsqueda (el modal de añadir miembro de Grupos sí busca); con más de 100 usuarios activos puede no encontrar al beneficiario.
- **Arrastrados de sprints anteriores, aún abiertos**: expiración de permisos temporales sin invalidación de cache (sólo TTL 60s), `recurso_id` almacenado pero no evaluado al autorizar, MFA/teams fuera de alcance y mensajes del backend en español fuera de `t()`.

### Desviaciones observadas (reportadas, no corregidas)

- **`MANAGER` no ve el menú "Usuarios"**: `nav.users` exige `users.user.view` y el rol MANAGER no lo tiene en la BD viva (verificado en sólo lectura con `JSON_CONTAINS` sobre `roles.permisos`); es coherente con el backend pero puede sorprender si se esperaba visible para MANAGER.
- **USER/VIEWER sí ven el menú "Grupos"** (`groups.group.view` está en ambos roles) pero entran a una pantalla de sólo lectura: sin alta/edición/baja ni gestión de miembros, y sin `temporary.permission.view` no ven la pestaña Temporales de Usuarios (que además ya tienen denegada por `users.user.view`).
- **2 PHPUnit deprecations preexistentes** (fuera de alcance) y `composer test` en modo "OK, but there were issues" por ellas.
- **`AGENTS.md`**: además de retirar la sección temporal del sprint v1.12.0, se eliminó la sentencia "Retirar esta sección al cerrar el sprint" que la regla 10 arrastraba de sprints anteriores (instrucción obsoleta; la regla 10 se conserva porque la regla 7 la referencia).

### Verificacion de cierre

Backend intacto: **sin PHP tocados** (nada que lintar) → `composer test`: **905 tests, 6699 aserciones, OK** (2 deprecaciones preexistentes) → `php bin/check-schema-drift.php`: **SIN DRIFT** (32 tablas, 388 columnas, 143 índices, 33 enums, 73 FK) → `php bin/check-i18n.php`: **OK, 1433 claves por locale, 0 errores y 0 avisos** → `node --check` en los 11 JS tocados OK → **8/8 smokes jsdom verdes (714 asserts)**: v1100=66, v1102=44, v1103=34, v162=89, v171=105, v184=100, v193=136 y **v1120=140** → lote completo de `tools\e2e\` sobre `xproject_e2e`: **14/14 scripts con exit 0** (los tabulados `e2e-audit` 14/14 y `e2e-sprint6` 2/2 con los códigos esperados 200/201/400/401/403 y sin filas FALLO; servidor embebido detenido al final) → sección temporal de autorización retirada de `AGENTS.md`.

## [1.11.1] - 2026-10-08

Sprint parche **v1.11.1 "Aislamiento de roles por tenant (SOLO BACKEND)"**. Objetivo: `roles.tenant_id` (NULL = BUILT_IN globales, valor = CUSTOM del tenant), unicidad de nombre por tenant y restringir listado/lectura/CRUD/asignación de roles a BUILT_IN + tenant actual. Sin TRUNCAR ni BORRAR datos; E2E en `xproject_e2e`; BD viva utilizada en sólo lectura para este sprint (la única corrección de datos, la de `manager@acme.local`, la ejecutó el usuario manualmente por SQL — ver Desviaciones).

### Added

- **Esquema (BD viva)**: `ALTER TABLE roles ADD COLUMN tenant_id CHAR(36) NULL DEFAULT NULL AFTER tipo` + `CREATE UNIQUE INDEX idx_roles_tenant_nombre ON roles (tenant_id, nombre)`; `database/schema/schema.sql` regenerado con `bin/regenerate-schema.php` (32 tablas, 388 columnas).
- **`Role` (entity)**: `tenantId` nullable; `create(..., ?string $tenantId)` exige tenant en CUSTOM y fuerza NULL en BUILT_IN; `fromArray`/`toArray` con `tenant_id` (aditivo) y `usableByTenant()`.
- **`RoleRepository(+Interface)`**: `findById`, `findByNombre`, `findAll`, `softDelete`, `restore` y `existsByNombre` con `?string $tenantId` de ámbito visible (BUILT_IN con `tenant_id NULL` + CUSTOM del tenant); `save` persiste `tenant_id` y comprueba la unicidad en ese ámbito.
- **`RoleController`**: las 12 llamadas al repositorio usan el `tenant_id` de los claims; `GET /roles` devuelve BUILT_IN + tenant actual; `POST /roles` sella el tenant y responde 409 por nombre reservado (BUILT_IN) o duplicado en su ámbito; `show`, `update`, `updatePermissions`, `destroy` y `restore` responden 404 sobre roles de otro tenant.
- **Asignación validada por tenant**: `AuthorizationService::assertRoleBelongsToTenant` (404 si el rol no existe o no es visible); `UserController::assignRole` lo invoca antes de la escalada (antes no comprobaba el rol en absoluto) y `GroupController::assignRole` pasa `findById($roleId, $tenantId)`.
- **Seeders**: `PlatformControlPermissionSeeder`, `InventoryPermissionSeeder` y `AuditPermissionSeeder` sincronizan `roles.permisos` filtrando con `AND tipo = 'BUILT_IN'` (defensa frente a futuros CUSTOM homónimos).
- **Tests**: `tests/Unit/RoleTenantIsolationTest.php` (**23 tests**): visibilidad de repo (BUILT_IN + propio, nunca ajeno), unicidad por ámbito, índice único espejo en SQLite (mismo tenant bloquea, otro tenant permite), guards 404 de `RoleController` sobre rol ajeno (show/update/updatePermissions/destroy/restore), store 409/201 según nombre, asignación a usuario (404 rol ajeno / 200 BUILT_IN con auditoría) y a grupo (404), e invalidación de cache por tenant con `SpyRedisService`. Base `PlatformControlSqliteTestCase` con `roles.tenant_id` + `UNIQUE`, `seedRole(..., $tenantId)` y `claims(..., $tenantId)`.

### Huecos declarados (reportados, no corregidos)

- **`viewer@acme.local` y `demo@acme.local` no existen** en la BD viva (en ningún tenant): los objetivos de datos asignados a esos usuarios no se aplicaron y no se creó ningún usuario (instrucción de no tocar datos vivos).
- **Unicidad de nombres BUILT_IN sólo a nivel de aplicación**: el `UNIQUE (tenant_id, nombre)` de MySQL no colisiona filas con `tenant_id NULL` (NULL ≠ NULL), así que el índice por sí solo no bloquearía dos BUILT_IN homónimos; lo cubre `existsByNombre` en ámbito visible y la guarda `tipo = 'BUILT_IN'` de los seeders.
- **`findByTipo`/`getBuiltInRoles`/`findByPermisos`/`count` siguen globales**: sin llamadores externos que requieran tenant; si aparecen, deberán ampliarse con el mismo criterio.
- **Arrastrados de 1.11.0, aún abiertos**: expiración de permisos temporales sin invalidación de cache (sólo TTL 60s), `recurso_id` almacenado pero no evaluado al autorizar, MFA/teams fuera de alcance y mensajes del backend en español fuera de `t()`.

### Desviaciones observadas (reportadas, no corregidas)

- **Corrección de datos manual y sin auditoría**: el cambio de `manager@acme.local` de `ADMIN` a `MANAGER` se ejecutó con SQL directo por parte del usuario, fuera de la API; `audit_logs` no contiene ninguna fila `user.role.assign`/`user.role.remove` (0 filas) y el cambio no queda registrado. La misma corrección vía API habría quedado auditada.
- **Puntos 1 a 5 de la corrección planificada omitidos** por instrucción del usuario tras el dry-run (quitar ADMIN a `manager@`, demostración de la protección de último ADMIN y verificación de auditoría vía API): el resto de datos vivos se consultó sólo en lectura (dry-run con token de ADMIN y `SELECT` de verificación).
- **Invalidación de cache de roles BUILT_IN por tenant**: al mutar un BUILT_IN desde un tenant, `invalidateCacheForRole` sólo borra el cache de los portadores de ese tenant; los portadores de otros tenants esperan el TTL de 60s (comportamiento preexistente, ahora acotado y visible con el aislamiento).
- **2 PHPUnit deprecations preexistentes** (metadata en doc-comment de `StockCalculationServiceTest`, fuera de alcance).

### Verificacion de cierre

`php -l` en los 12 PHP tocados OK (0 fallos) → `composer test`: **905 tests, 6699 aserciones, OK** (23 nuevos; 2 deprecaciones preexistentes) → `php bin/check-schema-drift.php`: **SIN DRIFT** (32 tablas, 388 columnas, 143 índices, 33 enums, 73 FK) → `php bin/check-i18n.php`: OK (1135 claves por locale, 0 errores) → BD E2E recreada con `php bin/prepare-e2e-db.php --db=xproject_e2e --fresh` (propaga el ALTER) y **lote completo de `tools\e2e\` con exit 0: 12/12 scripts** (incluido `e2e-v1110.ps1`, `F2+F3+F4+F5 SMOKE: TODOS LOS CASOS OK`) → servidores embebidos detenidos (8098 de lectura y 8099 de E2E) → estado final de la BD viva verificado en sólo lectura: `admin@`=ADMIN, `aleon@`=ADMIN, `manager@`=MANAGER, `user@`=USER.

## [1.11.0] - 2026-10-08

Sprint **v1.11.0 "Capa de control: usuarios, roles, grupos y permisos temporales (SOLO BACKEND)"** (spec `backups/PLATFORM_CORE_SPECIFICATION.md` §4/§5/§13.2), con los 3 ajustes aprobados por el usuario: (1) escalada ampliada — cambiar estado, resetear password, asignar roles o roles de grupo y otorgar temporales exigen actor con >= permisos efectivos del afectado y sólo ADMIN sobre ADMIN; (2) allowlist del cambio forzado de password con `/api/locales/*` y `/api/users/me/preferences`; (3) `recalculate-capacity.php --apply` con guard `--e2e`. Sin TRUNCAR ni BORRAR datos; E2E en `xproject_e2e`; BD viva sólo lectura salvo siembra idempotente de permisos.

### Added

- **Usuarios (F2)**: `PUT /api/users/{id}/status` (cambio de estado con escalada), `POST /api/users/{id}/reset-password` y `POST /api/users/me/password` (cambio forzado con allowlist de endpoints de autocambio y `SessionRevokerService` para revocar refresh tokens); `UserException` con `field`/`category` para el envelope 4xx. E2E 35/35.
- **Control de acceso (F3)**: `AuthorizationException extends HttpException`; guards en `RoleController` (BUILT_IN no renombrables/borrables, ADMIN no desactivable ni desprovisto de permisos) y en `PermissionController` (catálogo inmutable: sólo seeders escriben); `GET /api/permissions` y `GET /api/permissions/{id}` (`permissions.permission.view`).
- **Grupos (F4)**: módulo `src/Core/Groups/` (entity, repository+interface, controller, exception) con 11 rutas `/api/groups`: CRUD (`groups.group.view/create/edit/delete`), miembros (`manage_members`) y roles de grupo (`manage_roles` con escalada doble: el rol a asignar contenido en el actor y actor >= cada miembro afectado); auditoría de dominio en mutaciones.
- **Permisos temporales (F5)**: `src/Core/Authorization/` con `TemporaryPermission` (entity), `TemporaryPermissionRepository(+Interface)`, `TemporaryPermissionException` (`field`/`category`) y `TemporaryPermissionController`; 3 rutas: `GET/POST /api/temporary-permissions` (`temporary.permission.view/grant`) y `DELETE /api/temporary-permissions/{id}` (`temporary.permission.revoke`, sin escalada por decisión aprobada). POST valida campos (400 `field`), entidad (404/400), temporalidad (futura y <= `TEMP_PERMISSION_MAX_DAYS`, default 30), duplicado vigente (409), escalada (403) y CRITICAL sólo admin-equivalente (403) con rate limit 2/hora por actor (`RateLimiter`, clave `temp_permission_grant_critical:{actorId}`); auditoría `temporary_permission.grant/revoke` (módulo `permisos_temporales`) e invalidación de cache de permisos del beneficiario.
- **Seeder (F6)**: `PlatformControlPermissionSeeder` — 12 permisos (`users.user.status`, `users.password.reset`, `permissions.permission.view`, `groups.group.*` x6, `temporary.permission.*` x3) idempotente y con reparación de drift; reparto aprobado ADMIN=12, MANAGER=10 (sin `groups.group.delete` ni `temporary.permission.grant`), USER=VIEWER=2 (`groups.group.view` + `permissions.permission.view`); totales en BD de E2E: ADMIN=57, MANAGER=42, USER=19, VIEWER=12.
- **E2E**: `tools/e2e/e2e-v1110.ps1` consolidado (antes `_tmp_f2_smoke.ps1`) con F2+F3+F4+F5; F0 añadió guard `--e2e` a `bin/recalculate-capacity.php`, endureció `stop-e2e-server.ps1` y enganchó los resets en `e2e-v191.ps1`/`e2e-v1100.ps1` (lote 22/22). `tools/e2e/clear-rate-limit.php` (nuevo, permanente) limpia la clave `rate_limit:temp_permission_grant_critical:{actor}` y el script la ejecuta **antes** de la sección CRITICAL (aislamiento entre lotes, evita 429 en cascada por residuo de la ejecución anterior) y al final (limpieza); sustituye al `bin/_tmp_rate_clear.php` temporal, que al borrarse en la limpieza de cierre rompió una ejecución del lote.
- **Tests unit (F8)**: `tests/Support/PlatformControlSqliteTestCase.php` (esquema SQLite a imagen de `schema.sql` con `NOW()` registrado y fixtures de usuarios/roles/grupos/temporales) + 5 ficheros y **98 tests**: `PlatformControlRoutesTest` (20: handler, middlewares y permiso exacto de las 16 rutas nuevas; catálogo sin escrituras), `PlatformControlPermissionSeederTest` (13: siembra exacta, idempotencia, drift, reparto por rol, jerarquía y CRITICAL sólo ADMIN), `AuthorizationEscalationTest` (17: `assertCanActOnUser` (superset, déficit, sólo ADMIN sobre ADMIN, self), `assertCanAssignRole`, `hasAdminRole`/`isActiveAdmin` con asignaciones expiradas/cuentas inactivas/borradas, permisos efectivos por roles+grupos+temporales), `TemporaryPermissionControllerTest` (32: campos obligatorios, exclusión mutua fecha/duración, fecha futura y max-days, 404/400 de entidades, orden 400-forma antes que 409-duplicado, escalada 403, CRITICAL 403 no-admin, 401 sin claims, alta 201 con auditoría, listados con filtros y revocación 200/409/404/400) y `RoleControllerGuardTest` (16: 401/403 de autorización, guards de BUILT_IN y ADMIN con repositorio real, operaciones válidas sobre CUSTOM con auditoría).

### Fixed

- **`public/index.php`: `SecurityException` -> 429 + `Retry-After`** (antes caía al 500 genérico): corrige de paso el rate limit de login (5/5min) que devolvía 500 en vez de 429.
- **`composer.json`**: añadido el mapa PSR-4 `App\\Core\\Groups\\` (faltaba y provocaba `Class "App\Core\Groups\Http\GroupController" not found` al enrutar, el `Error` de `Router.php:278` observado en F4).
- **`GroupController::store` idempotente**: el chequeo `existsByNombre` se movió dentro de la transacción de creación; el replay de la misma petición ya no responde 409.
- **`TemporaryPermissionController::store`**: la validación de fechas (400) se ejecuta antes que el chequeo de duplicado (409), como exige el orden de validaciones de la spec.

### Huecos declarados (reportados, no corregidos)

- **Roles globales sin `tenant_id` + `roles.permisos` en JSON** (arrastrado): los roles son globales y su asignación de permisos vive en un JSON denormalizado; el módulo lo hereda y sólo lo documenta.
- **Expiración natural de permisos/temporales no invalida cache**: al expirar no se dispara invalidación; sólo actúa el TTL de 60s de `AuthorizationService`. Afecta a sesiones vivas hasta TTL.
- **`recurso_id` almacenado pero no evaluado** al autorizar: se guarda y se usa para el chequeo de duplicados, pero `hasPermission` no lo filtra (la spec lo deja como extensión).
- **MFA fuera de sprint** (spec §5.4) y **teams fuera de alcance** (§4.3): no implementados.
- **Mensajes del backend en español** fuera de `t()` (patrón del API) y catálogo `pt/fr` sin claves nuevas (el sprint no añade claves i18n: 1135 por locale).

### Desviaciones observadas (reportadas, no corregidas)

- **`MANAGER` con rol `ADMIN` asignado en la BD viva** (arrastre de datos): conviene revisarlo con el equipo antes de endurecer reglas de último ADMIN.
- **429 de CRITICAL no cubierto en unit**: `RateLimiter` es `final` y su `attempt()` necesita Redis real; el límite 2/hora se verifica en E2E (`e2e-v1110.ps1`), no en PHPUnit.
- **`AGENTS.md` sin sección de autorización temporal**: a diferencia de sprints anteriores, no había nada que retirar al cierre (no se añadió ninguna).
- **Verificación JS no aplica**: el sprint es SOLO BACKEND (sin tocar `public/js/**`), la regla 8 (`node --check` + smokes jsdom) queda sin ejecutar a propósito.

### Verificacion de cierre

`php -l` en los 282 PHP de `src,tests,routes,public,bin,bootstrap.php` OK (0 fallos) · `composer test`: **879 tests, 6628 aserciones, OK** (95 nuevos; 2 deprecaciones preexistentes) · `php bin/check-schema-drift.php`: SIN DRIFT (32 tablas, 387 columnas, 142 índices, 33 enums, 73 FK) · `php bin/check-i18n.php`: OK (1135 claves por locale, 0 errores, 0 avisos) · **lote completo de `tools\e2e\` con exit 0: 12/12 scripts** en orden alfabético sobre `xproject_e2e` recreada con `--fresh` (incluido `e2e-v1110.ps1` con `F2+F3+F4+F5 SMOKE: TODOS LOS CASOS OK`; servidor embebido detenido al final) · temporales `bin/_tmp_*.php` borrados (el auxiliar de rate limit se rescata como `tools/e2e/clear-rate-limit.php`, ver Fixed).

## [1.10.3] - 2026-10-06

Sprint **Correccion v1.10.3-A (BACKEND + FRONTEND)** con autorizacion excepcional del usuario (endpoint de preferencias, `tests/**`, `public/js/**`, `tools/smoke/*`, `tools/e2e/*`, `CHANGELOG.md`): cierre del hueco declarado en 1.10.2. Endpoint `GET/PATCH /api/users/me/preferences` sobre `users.preferencias`, preferencias devueltas en login y `/api/auth/me`, y persistencia de la eleccion de idioma del frontend con PATCH manteniendo la prioridad de la eleccion explicita de la sesion. Sin tocar permisos ni migraciones. Al cerrar se retira la seccion de autorizacion temporal de `AGENTS.md`.

### Added

- **`GET /api/users/me/preferences`** (Auth + Tenant + RateLimit:api): devuelve `data.preferencias` (JSON decodificado, `[]` si la columna es NULL o invalida). **`PATCH /api/users/me/preferences`** (AuditMiddleware + Auth + Tenant + RateLimit:api): fusiona claves conocidas, valida `idioma` en `es|en|pt|fr` y `tema` en `dark|light|system` (400 `UserPreferencesException` con `field` y `category=validation`), ignora claves desconocidas, preserva las ya guardadas, no-op (200) si el body no trae claves conocidas; auditoria de dominio `user.preferences.update` con valores anteriores/nuevos. Declaradas antes de `/api/users/{id}` para que `me` no se capture como id; sin PermissionMiddleware (cada usuario lee/escribe las suyas via claims).
- **`src/Core/Users/Exception/UserPreferencesException.php`** (nuevo): extiende `HttpException` con `getField()`/`getCategory()` para que el handler global de `public/index.php` responda 400 en vez del 500 genericos de `RuntimeException` (ver desviaciones).
- **`UserService::getPreferences()/updatePreferences()`** con constantes `PREFERENCIAS_IDIOMA/TEMA/CLAVES`; UPDATE auditable con `updated_at` (sin TRUNCAR ni BORRAR: solo la columna `preferencias` del usuario autenticado).
- **`AuthController`**: `user.preferencias` decodificadas en `login()` y `me()` (helper `decodePreferences()`).
- **Frontend `public/js/core/i18n.js`**: `setLocale()` marca la eleccion explicita de la sesion (`sessionChoice`), la aplica y la confirma con `PATCH /users/me/preferences {idioma}` (con `requiresAuth`, dedupe con `lastPersisted`, y si el servidor no confirma se conserva la eleccion local). `persistIdioma()` emite toast de aviso (`ui.toast_lang_save_title/_failed`) si el PATCH falla. `applyLocale()` (interno) no marca eleccion ni persiste.
- **`public/js/modules/auth/state.js`**: `syncFromUser()` (aplica `preferencias.idioma` del servidor) se invoca SOLO en `setSession()` (login); se elimina de `init()` (restauracion de sesion) y de `updateUser()` (refresco via `/auth/me`) para que `/auth/me` posterior no revierta la eleccion.
- **`resources/locales/{es,en,pt,fr}.json`**: +2 claves de toast (`ui.toast_lang_save_title`, `ui.toast_lang_save_failed`); 1135 claves por locale.
- **Tests backend** (`UserPreferencesRoutesTest`, `UserPreferencesServiceTest`, `UserPreferencesEndpointTest`): 40 tests / 136 aserciones (rutas y permisos, merge/validacion/no-op, auditoria, 400/404/401, login y `/auth/me`).
- **`tools/smoke/smoke-v1103.mjs`** (34 asserts): secuencia exigida — elegir `en` con sesion (PATCH + localStorage), `/auth/me` con `preferencias.idioma=es` y el idioma sigue `en`; fallo de PATCH (eleccion conservada + toast warning + reintento); preferencia del servidor solo en el login; eleccion pre-sesion confirmada en `auth:login` (sin PATCH antes, sin duplicados); `auth:logout` reinicia la eleccion.
- **`tools/smoke/smoke-v1102.mjs`** actualizado (40 → 44 asserts): el stub ahora implementa `PATCH /users/me/preferences`, el caso S6 comprueba la persistencia con sesion y el S5b que la sincronia de login NO escribe en el servidor.
- **`tools/e2e/e2e-v1103.ps1`** (E2E en `xproject_e2e`, ~30 checks): GET/PATCH con la propia sesion, reflejo en `/auth/me` y en un login nuevo, merge, 400 con `field`/`category`, claves desconocidas ignoradas, no-op vacio, 401 sin token, independencia por usuario, `users.preferencias` en la BD de E2E, auditoria por `X-Request-Id` (fila de dominio en el PATCH valido y fila FAILURE del middleware en el invalido) y huella de la BD de trabajo intacta.

### Fixed

- **Hueco 1.10.2 cerrado**: la eleccion de idioma ya no vive solo en `localStorage`; se persiste en `users.preferencias` via PATCH (falla avisada con toast, eleccion local conservada si el servidor no confirma). Prioridad de la sesion: eleccion explicita > `preferencias` del servidor > `localStorage` > `es`, con `syncFromUser` unicamente en el login; `/auth/me` ya no revierte el idioma elegido (bug originalmente evidenciado contra Edge Network: elegir idioma y recibir `/auth/me` dejaba `es.json`).

### Huecos declarados (reportados, no corregidos)

- **Formatos y textos no traducidos (arrastrados de 1.10.2)**: `toLocaleString('es-ES')` fijo en `dashboard.js:160,274`, `locations.js:591` y `products.js:634`; mensajes de error del backend en espanol; `console.*` de desarrollo.

### Desviaciones observadas (reportadas, no corregidas)

- **`UserException` mapea a 500 (preexistente)**: `public/index.php:49` solo baja a 4xx para `HttpException` y las excepciones de autenticacion; `UserException` (extends `RuntimeException`) cae al 500 generico. Por eso el endpoint nuevo usa `UserPreferencesException extends HttpException`; tocar el handler global quedo fuera de la autorizacion. Correccion a proponer en el equipo de backend.
- **`e2e-v1100.ps1` y el residuo de `e2e-v191.ps1` (preexistente, ampliado)**: ademas del fallo dentro de un mismo lote descrito en 1.10.2, el residuo PERSITE entre lotes: si el lote anterior termino con `e2e-v191`, el `e2e-v1100` del lote siguiente falla (`A cupo del almacen vuelve a la linea base (-10)`, obtenido = base + 4) aunque corra en orden alfabetico. Confirmado en este cierre y resuelto para la verificacion recreando la BD con `bin/prepare-e2e-db.php --db=xproject_e2e --fresh` antes del lote; workaround mientras QA/backend no lo arregle.
- **`auth:login` se emite dos veces** (`state.setSession` y `login.js`): manejado en `i18n.js` con dedupe (`lastPersisted`) para no duplicar el PATCH; se reporta por si conviene unificar el emisor.
- **`prepare-e2e-db.php` no resetea identidad si ya hay datos** (`users=3 (ya con datos)`): el E2E nuevo deja `users.preferencias` de aleon/manager en la BD de E2E; es inocuo (el script es idempotente y solo escribe la BD de E2E) pero conviene saberlo.

### Verificacion de cierre

`php -l` en los 6 PHP tocados OK · `node --check` en los 4 JS/MJS modificados OK · los 7 smokes en verde (v1100: 66, v162: 89, v171: 105, v184: 100, v193: 136, v1102: 44, v1103: 34; 574 aserciones, 0 FAIL) · `composer test`: 784 tests, 6079 aserciones, OK (2 deprecaciones preexistentes) · los 11 scripts de `tools/e2e/` con exit 0 en orden alfabetico sobre `xproject_e2e` recreada con `--fresh` (huella de la BD de trabajo sin escrituras; servidor embebido detenido al final) · `php bin/check-schema-drift.php`: SIN DRIFT · `php bin/check-i18n.php`: OK (1135 claves, 0 errores, 0 avisos).

### Sprint Correccion i18n v1.10.3 (FRONTEND) - 2026-10-07

Extension del 1.10.3 con autorizacion excepcional del usuario (`public/**`, `resources/locales/{es,en,pt,fr}.json`, `bin/check-i18n.php`, `tools/smoke/*`, `tools/e2e/e2e-v191.ps1` y `e2e-v1100.ps1`, test de navegador, `CHANGELOG.md`): diagnostico y correccion de la interfaz que seguia en espanol al elegir ingles en Edge. Sin tocar backend, rutas, permisos, BD ni migraciones.

### Diagnostico (causa raiz)

- **(A) JS/CSS cacheados (causa principal)**: `public/.htaccess` sirve los assets con `mod_expires` de 1 semana y los scripts del shell no llevaban `?v=`, de modo que Edge reutilizaba JavaScript antiguo tras desplegar (y el JS antiguo no tenia los cambios de idioma).
- **(B) Literales en espanol fuera de `t()`**: 13 literales y 16 `console.*` en espanol en `public/js`, mas los 15 `title:` fallback del router.
- **(C) Fallback silencioso a `dicts.es`** en `i18n.js` cuando no estaba disponible el diccionario del idioma activo.

### Medicion antes/despues (measure sobre `public/js` + `index.php`)

| | ANTES | DESPUES |
|---|---:|---:|
| `t()` | 1232 | 1233 |
| `data-i18n` fuera de `i18n.js` | 4 | 5 |
| literales ES fuera de `t()` | 13 | 1 (`public/index.php:54`, backend) |
| `console.*` en espanol | 16 | 0 |

### Fixed (sprint i18n)

- **`?v=hash` de cache-busting**: `assetsVersion()` en `public/index.php` (max filemtime de `public/js` + `public/css`) versiona el CSS del shell, los 21 scripts del shell y `AppConfig.assetsVersion`; `loader.js` anade la version en `loadScript()`/`loadStylesheet()` (dedupe de `<link>` por href versionado). Los ficheros absolutos del propio origen se versionan; los de otro origen (CDN) quedan intactos.
- **`public/js/core/loader.js` - rutas de modulos resueltas al cargar**: el manifiesto `registerMany()` se ejecuta al evaluar `loader.js`, antes de que exista `window.AppConfig`, y el fallback fijo `/xp/public` rompia la carga de modulos en el servidor embebido de E2E (shell, API y assets apuntaban a una subcarpeta inexistente); ahora las rutas se resuelven en el momento de cargar (`basePath()`/`resolveModulePath()` perezosos) y `unload()` matchea el `<script>` sin importar el query `?v=`.
- **`public/index.php` - `appUrl()`**: `getenv('APP_URL')` con prioridad sobre `$_ENV['APP_URL']` (con `variables_order=GPCS`, `$_ENV` solo lo rellena dotenv desde `.env`); un servidor embebido/contenedor puede fijar su propia URL y el shell, la API y los `?v=` apuntan a si mismos (los E2E usan `http://127.0.0.1:8099`; sin el fix el shell llamaba a Apache y a la BD de trabajo).
- **Textos fijos migrados**: 19 `console.*` de espanol a ingles (event-bus, loader, router, dashboard, counts, incidents, products, persistence, login); los 15 `title:` fallback de `router.js` a ingles (el titulo real sale de `titleKey`+`t()`); errores internos de `i18n.js` a ingles; `toLocaleString/toLocaleDateString('es-ES')` a `i18n.getLocale()` en `dashboard.js:160,274`, `locations.js:591` y `products.js:634` (cierra el hueco declarado en 1.10.3-A); `aria-label` de la hamburguesa a `Toggle menu`; `shell.guest` en el nombre de usuario y `router.unavailable_title` via `t()` en el fallback de modulos.
- **`resources/locales/fr.json` creado**: el endpoint devolvia 404 para frances (silencioso) porque el fichero no existia en `resources/locales/`; copiado desde el `vendor/fr.json` huerfano. Los 4 idiomas quedan con 1135 claves y 0 faltantes entre si.

### Added (sprint i18n)

- **`bin/check-i18n.php` - seccion 5**: detector de literales/console en espanol fuera de `t()` en `public/js/**/*.js` (ventana de 200 chars, exime comentarios y `public/js/core/i18n.js` por ser el diccionario BUILTIN; exenciones de enums/identificadores/selectores/valores de atributos/args de `indexOf`/fragmentos de regex/rutas; no escanea `public/index.php` porque su mensaje en `public/index.php:54` es backend). Falla con `RESULTADO: N error(es)` y exit 1 si hay literales.
- **Prueba de navegador headless (puppeteer-core, fuera del proyecto)**: 14 checks en verde - `?v=` en los 18 scripts y 3 CSS + `AppConfig.assetsVersion`, login `aleon@acme.local`, es a en con `en.json` 200, `lang="en"`, `Products` visible sin `Productos`, persistencia tras reload con `?v=`, en a fr con `fr.json` 200 y vuelta a es, cero errores de consola.
- **E2E independientes del orden (cierra la desviacion de 1.10.2/1.10.3-A)**: `e2e-v191.ps1` y `e2e-v1100.ps1` comprueban el cupo con SQL crudo `SUM(CEIL(cantidad))` (el mismo criterio que `InventoryPresenceService::cupoUsado()` usa para recalcular `capacidad_usada`), no con delta del contador almacenado, y v1100 anade ademas `capacidad_usada == SQL crudo` tras el resolve; los dos scripts pasan en cualquier orden sobre la BD de E2E sin recrearla.

### Huecos declarados del sprint i18n (reportados, no corregidos)

- **`public/index.php:54`**: mensaje de error de PHP (backend) en espanol, fuera del alcance del sprint; el detector de `check-i18n.php` no escanea `index.php`.
- **Mensajes de error del backend en espanol** (por decision del usuario se mantienen y se reportan).
- **Selector de idioma con opciones endonimas** (`English`, `Português`, `Français`): se mantienen; no son textos traducibles.

### Desviaciones observadas del sprint i18n (reportadas, no corregidas)

- **`vendor/fr.json` huerfano**: originalmente el unico `fr.json` del repositorio estaba en `vendor/` (fuera del alcance del endpoint); se copio a `resources/locales/fr.json` y el original no se borro por la regla de no borrar datos. Conviene retirarlo en backend.
- **`resources/locales/pt.json` casi identico al espanol** (1134/1135 claves en espanol): las claves existen y el idioma funciona, pero el contenido en portugués es mayoritariamente español; traduccion pendiente de contenido (solo se garantiza paridad de claves en este sprint).
- **`mod_expires` de 1 semana en `public/.htaccess`**: no se toco (infraestructura); lo mitiga el `?v=` nuevo y asi se documenta.
- **`auth/login.js` se carga dos veces** (script del shell + `loadScript()` del loader al enrutar): comportamiento preexistente e inocuo (script idempotente, cacheado), se deja como esta.

### Verificacion de cierre del sprint i18n

`php -l` en `public/index.php` y `bin/check-i18n.php` OK · `node --check` en los JS tocados y en los 31 ficheros de `public/js` (0 errores) · `php bin/check-i18n.php`: OK (1135 claves x 4 idiomas, literales 0, exit 0) · medida final `t()=1233, data-i18n=5, literales=1 (backend index.php:54), console=0` · los 7 smokes en verde (v1100: 66, v162: 89, v171: 105, v184: 100, v193: 136, v1102: 44, v1103: 34; 574 aserciones, 0 FAIL) · `composer test`: 784 tests, 6079 aserciones, OK (2 deprecaciones preexistentes) · `php bin/check-schema-drift.php`: SIN DRIFT · los 11 scripts de `tools/e2e/` con exit 0 en orden alfabetico (ademas v191 a v1100 y v1100 a v191 verificados por separado; huella de la BD de trabajo sin escrituras; servidor embebido detenido) · prueba de navegador headless: 14/14 OK.

## [1.10.2] - 2026-10-06

Sprint **GUI de idioma v1.10.2 (FRONTEND)** con autorizacion excepcional del usuario (`public/js/**`, `public/index.php`, `tools/smoke/*`, `CHANGELOG.md`): diagnostico y correccion del selector/cambio de idioma, idioma aplicado al login y re-render de pantallas sin recarga. Sin tocar backend, rutas, permisos, BD ni migraciones. Al cerrar se retira la seccion de autorizacion temporal de `AGENTS.md`.

### Diagnostico (causa raiz)

- La carga inicial de `i18n.js` es pre-sesion: `fetchDictionary()` llamaba a `GET /locales/{locale}.json` sin token, el endpoint devolvia 401 y `http.js` trataba ese 401 como sesion caducada (limpia la sesion y redirige a login); ademas el diccionario completo no se volvia a descargar tras entrar, con lo que los modulos (`t()`) pintaban claves en crudo/parpadeaban mientras el shell (`data-i18n`) se veia a medias. Verificado contra el endpoint real (BD de E2E): 401 sin sesion, 200 con `es.json` (1133 claves) y 304 con `If-None-Match`; 404 fuera de la whitelist `es|en|pt|fr`.

### Fixed

- **`public/js/core/i18n.js`**: `fetchDictionary()` rechaza sin sesion (sin llamada HTTP y sin efectos secundarios del 401); descarga single-flight reutilizada por `ensureLoaded()` (nueva, expuesta en la api); listeners de `auth:login`, `auth:user-updated` y `auth:synced` en `init()` que descargan el diccionario al llegar la sesion; `setLocale()` reutiliza el diccionario ya descargado; `syncFromUser()` con prioridad `users.preferencias.idioma` > `localStorage['lang']` > `es`.
- **`public/js/core/router.js`**: tras `auth:login` espera a `i18n.ensureLoaded()` antes de navegar al dashboard para que la primera pantalla no pinte claves en crudo.
- **`public/js/modules/auth/login.js`**: la navegacion post-login espera `i18n.ensureLoaded()` por el mismo motivo.

### Added

- **`tools/smoke/smoke-v1102.mjs`** (40 asserts, jsdom): ciclo real con la API simulada sobre los diccionarios reales — precarga pre-sesion sin GET de locales (cero llamadas), descarga de `es.json` con el login, selector en el menu de usuario, cambio es→en sin recarga (shell + sidebar + dashboard + `document.title` via `router.refresh`, `localStorage['lang']`, un unico `i18n:changed`, misma `href`), ida y vuelta a stock/auditoria en ambos idiomas, prioridad de idioma al iniciar (guardado, preferencia de usuario y preferencia > localStorage) y que NO se llama a ningun endpoint de preferencias (hueco).

### Huecos declarados (reportados, no corregidos)

- **Preferencias de usuario**: `users.preferencias` existe en la BD viva pero `UserService::update()` no la acepta y no hay endpoint de escritura de preferencias; la eleccion de idioma sigue persistiendo unicamente en `localStorage['lang']`. `syncFromUser()` queda reactivo para cuando el backend exponga el campo. Endpoint a crear en el siguiente sprint.
- **Formatos y textos no traducidos (fuera de alcance)**: `toLocaleString('es-ES')` fijo en `dashboard.js`, `locations.js` y `products.js`; mensajes de error del backend en espanol; `console.*` de desarrollo.

### Desviaciones observadas (reportadas, no corregidas)

- **`tools/e2e/e2e-v1100.ps1` depende del orden de ejecucion**: si corre despues de `e2e-v191.ps1` falla 1 asercion (`A cupo del almacen vuelve a la linea base (-10)`). Causa preexistente y ajena a este sprint (solo frontend): el flujo C de v191 altera `stock_units.cantidad` con SQL crudo (1→5) sin tocar `locations.capacidad_usada` y el reconcile `JUSTIFICAR` no recalcula el cupo, dejando `capacidad_usada = SUM(CEIL(cantidad)) - 4`; el resolve `ENCONTRADA` de v1100 (`ResolveIncidentUseCase`) si recalcula y expone la deriva. En orden alfabetico (v1100 antes que v191), como en cierres previos, los 10 scripts pasan. Correccion a proponer en el equipo de QA/backend (fixture via API o `bin/recalculate-capacity.php`), fuera de la autorizacion de este sprint.

### Verificacion de cierre

`php -l public/index.php` OK · `node --check` en los JS modificados OK · los 6 smokes en verde (v1100: 66, v162: 89, v171: 105, v184: 100, v193: 136, v1102: 40; 536 aserciones, 0 FAIL) · `composer test`: 744 tests, 5943 aserciones, OK (2 deprecaciones preexistentes) · los 10 scripts de `tools/e2e/` con exit 0 en orden alfabetico (BD de E2E `xproject_e2e`, huella de la BD de trabajo sin escrituras) · `php bin/check-schema-drift.php`: SIN DRIFT · `php bin/check-i18n.php`: OK (0 errores, 0 avisos).

## [1.10.1] - 2026-10-05

Sprint **Fundacion i18n v1.10.1 (FRONTEND + un endpoint autorizado)**: migracion de los textos del frontend a `t()`, runtime i18n con diccionario descargado de la API, selector de idioma en el shell, diccionarios es/en/pt/fr y checker de consistencia. Al cerrar se retira la seccion de autorizacion temporal de `AGENTS.md`.

### Added

- **`GET /api/locales/{locale}`** (`LocaleController::show` + `LocaleDictionaryService`, unico endpoint nuevo autorizado): sirve `resources/locales/{locale}.json` envuelto en `{locale, version, dictionary}`; whitelist estricta `es|en|pt|fr` (cualquier otro valor, incluido path traversal, devuelve 404: el nombre de fichero se construye despues de validar con `normalizeLocale()`), cabeceras `ETag` + `X-Dictionary-Version` + `Cache-Control: private, max-age=300, must-revalidate`, `304` ante `If-None-Match`, cache en Redis opcional (sin capa Redis funciona en memoria); autenticado (`AuthenticationMiddleware`) y SIN permiso de modulo, con `RateLimitMiddleware:api`; declarada al final de `routes/api.php`. Test `tests/Unit/LocalesRoutesTest` (registro de ruta, whitelist 404, middlewares encadenados, ETag/304, version del diccionario).
- **Diccionarios `resources/locales/{es,en,pt,fr}.json`**: 1133 claves idénticas entre los cuatro, paridad verificada (claves de mas/faltantes = error) y placeholders `{param}` coincidentes por clave (desajuste = error). `es.json` es la fuente; `en.json` traducido al ingles; `pt.json` y `fr.json` con texto español como marcador/respaldo.
- **Runtime i18n `public/js/core/i18n.js`**: `t(clave, params)` con interpolacion `{param}` y fallback en cascada builtin `es` → diccionario del locale → `es.json` → la propia clave; descarga con `HttpClient.get('/locales/{locale}.json')` y fallo silencioso (401 pre-sesion o red caida no bloquean el bootstrap de la app); `applyDom()` aplica `data-i18n`, `data-i18n-aria`, `data-i18n-placeholder`, fija `document.documentElement.lang` y sincroniza el selector; evento `i18n:changed` en el `EventBus` para que los modulos repinten; `inject()` para inyectar diccionarios en smokes/tests sin red; persistencia de la eleccion en `localStorage`.
- **Selector de idioma en el shell** (`public/index.php`, menu de usuario, `#lang-select` con es/en/pt/fr): cambio en caliente sin recarga via `setLocale()`, ligado en `i18n.js` (`bindSelector`).
- **`bin/check-i18n.php`**: sale con codigo != 0 si (a) falta un diccionario o su JSON es invalido, (b) hay desparidad de claves entre `es.json` y en/pt/fr, (c) los placeholders `{param}` de una clave no coinciden entre idiomas, o (d) el codigo usa una clave que no existe en `es.json`. Extrae las claves usadas de `t(...)`, propiedades `*Key:`, objetos `*_KEYS = {`, atributos `data-i18n*` y llamadas `t(x || 'clave')`; las claves sin uso se listan como aviso no fatal. Estado actual: `RESULTADO: OK (0 errores, 0 avisos)`.
- Migracion de los textos de UI de `public/js/**` y del shell (`public/index.php`) a `t()` sobre el arbol completo de `es.json` (1133 claves usadas = 1133 existentes).

### Changed

- **`public/js/themes/theme-manager.js`**: `ICONS`/`LABELS` renombrados a `ICON_KEYS`/`LABEL_KEYS` para entrar en el patron `*_KEYS` del checker.
- **`public/js/core/i18n.js` (builtin) + `es.json`**: eliminada `login.title`, clave muerta (el titulo de login usa `routes.login`).
- **`resources/locales/es.json`**: voseo normalizado a imperativo neutro en tuteo (31 sustituciones: `Seleccioná`→`Selecciona`, `Elegí`→`Elige`, `Cargá`→`Carga`, `Describí`→`Describe`, `Indicá`→`Indica`, `Marcá`→`Marca`, `Escaneá`/`escribí`/`presioná`, `Revisá`/`actualizá`, `registrá`/`Registrá`, `iniciá`, `Justificá`, `Pasá`) y el formal `Indique`→`Indica` en `inventory.stock.messages.expiration_invalid`; sin formas `vos`/`tenés`/`podés` ni imperativos voseo. Como `es.json` es la fuente, `pt.json` y `fr.json` se regeneraron como copia tras el cambio; `en.json` queda byte-idéntico (sin cambios). Ningún smoke dependía de esos textos (verificado: los 5 smokes en verde).

### Fixed

- **`tools/e2e/stop-e2e-server.ps1`**: el literal `"... $pidFile: ..."` rompia el parseo de PowerShell 5.1 (`Variable reference is not valid`), por lo que el script de parada manual no podia ejecutarse; ahora `${pidFile}`. Bug preexistente fuera del alcance del sprint, corregido por bloquear la herramienta de limpieza documentada en la regla 10.

### Huecos declarados (reportados, no corregidos)

- **Preferencias de usuario**: no hay endpoint para leer ni escribir preferencias de usuario y `/api/auth/me` no expone `preferencias.idioma`; la eleccion de idioma se persiste unicamente en `localStorage`. `i18n.js::syncFromUser()` queda reactivo (se activara si el backend expone el campo) sin simular datos inventados.
- **Textos de error del backend**: los mensajes que genera el backend siguen en español y en algunos casos el frontend hace match literal con ellos (p. ej. `counts.js` con `el ajuste automático`); fuera de alcance por decision del sprint. Los mensajes de `console.*` en español (event-bus, router, counts, incidents, theme-persistence) son de desarrollo, no de UI.

### Verificacion de cierre

`php -l` en los PHP tocados OK · `composer test`: 744 tests, 5943 aserciones, OK (2 deprecaciones preexistentes) · `node --check` en los JS modificados OK · `node tools/smoke/smoke-v162.mjs`: 89 OK / 0 FAIL · `node tools/smoke/smoke-v171.mjs`: 105 OK / 0 FAIL · los 10 scripts de `tools/e2e/` con exit 0 (BD de E2E `xproject_e2e`, huella de la BD de trabajo sin escrituras) · `php bin/check-schema-drift.php`: SIN DRIFT · `php bin/check-i18n.php`: OK (0 errores, 0 avisos).

## [1.10.0] - 2026-10-05

Sprint **Inventory v1.10.0 (BACKEND + FRONTEND)** con autorización excepcional del usuario para tocar frontend: **R3** (línea de tiempo de la unidad y listado de movimientos), **E4** (bloqueo al reportar `UBICACION_DESCONOCIDA`/`INVENTARIO_DESAPARECIDO`, resolver con `resultado` y acciones Bloquear/Desbloquear), **E1** (rechazo total y umbral de discrepancia), **CU-002** (`data.warnings` al mover) y **E8** (`es_correccion`). `modules/dashboard/dashboard.js` queda fuera de alcance; al cerrar se retira la sección de autorización de `AGENTS.md`.

### Added

- **`GET /api/inventory/stock/{id}/history`** (R3, `StockController::history` + `StockHistoryQueryService`): línea de tiempo de UNA unidad, fusión de `stock_events` y `movements` ordenada por fecha descendente, paginada (`page`, `per_page`); permiso `inventory.stock.view`. Declarada después de `/stock/summary` (el router recorre en orden).
- **`GET /api/inventory/movements`** (R3, `MovementController::index` + `MovementQueryService`): listado paginado con `summary` y filtros `unidad_fisica_id`, `ubicacion_origen_id`, `ubicacion_destino_id`, `motivo`, `es_correccion`, `fecha_desde/hasta`, `page`, `per_page` (máx. 100); permiso `inventory.stock.view`.
- **`POST /api/inventory/stock/{id}/block` y `/unblock`** (E4, `BlockStockUseCase`): `X → BLOQUEADO` según la matriz de transiciones y `BLOQUEADO → DISPONIBLE`; sin cambio de ubicación ni de cupo; evento `BLOQUEADO`/`DISPONIBLE` append-only con hash; la `accion` la inyecta el controller desde la URL (el body `accion` es ignorado y opcional); Idempotency-Key opcional; permiso nuevo **`inventory.stock.block`** (HIGH) sembrado de forma idempotente en `InventoryPermissionSeeder` (total 34) y asignado sólo a **ADMIN y MANAGER**.
- **`incidents.prioridad` `enum('NORMAL','ALTA') NOT NULL DEFAULT 'NORMAL'`** en la BD viva (E1); columnas nuevas de `movements`: **`es_correccion` `tinyint(1) NOT NULL DEFAULT 0`** y **`movimiento_referencia_id` `char(36) NULL`** (E8).
- **`data.warnings` en `POST /api/inventory/movements`** (CU-002, `MoveStockUseCase::buildWarnings`): avisos no bloqueantes, hoy `codigo: UNIDAD_RESERVADA` con el texto de la reserva activa y su `referencia_orden` si existe.
- **Frontend `public/js/modules/inventory/movements.js`** (módulo nuevo): pestaña **Movimientos** con resumen, filtros (unidad, origen, destino, motivo, corrección, fechas), badges de `es_correccion`, referencia de corrección y hash; registro en `loader.js` (la ruta ya estaba en `core/router.js`, que no se tocó) e ítem **Movimientos** en `sidebar.js` tras Stock (icono `exchange`, `inventory.stock.view`).
- **`stock.js`**: botón **Historial** en TODAS las filas (requiere `inventory.stock.view`, que ya exige la pantalla): modal con línea de tiempo eventos+movimientos, badges de fuente/tipo/corrección/hash y paginación (prev/next); acciones **Bloquear/Desbloquear** con `BLOCKABLE_STATES = [DISPONIBLE, RESERVADO, EN_PREPARACION, CARGADO, EN_TRANSITO, DAÑADO]`, confirm, `Idempotency-Key` y recarga ante 422/404; `submitMove` convierte `body.warnings` en toasts.
- **`incidents.js`**: columna **Prioridad** en el listado y filas Resultado/Ubicación destino en el detalle; modal Resolver con select `resultado` (sólo para `UBICACION_DESCONOCIDA`/`INVENTARIO_DESAPARECIDO`) y select `ubicacion_destino_id` cargado bajo demanda para `ENCONTRADA` (opcional; se resetea al cambiar `resultado`); header del modal con el tipo de la incidencia.
- **`receiving.js`**: checkbox **Rechazar entrega completa** (`rechazo_total`, deshabilita y limpia `cantidad_recibida`), resultado con badge Rechazada/Parcial/Completa, filas de ubicación/estado final/discrepancia, alertas con `prioridad` y toast de aviso en rechazo.
- **`tools/smoke/smoke-v1100.mjs`** (66 asserts): contrato de history/movements/block/unblock, warnings CU-002, `es_correccion`, `prioridad`, resolve con resultado/destino y rechazo total.
- **`tools/e2e/e2e-v1100.ps1`** (124 asserts, flujos A–H): bloqueo por reporte + resolve `ENCONTRADA`/`NO_ENCONTRADA`, `DAÑO` sin resultado, block/unblock con 403 de USER/422 de transición/replay, rechazo total, umbral 5 % (6 % → ALTA, 0,1 % → NORMAL), warnings con la orden, correcciones+filtros, historial, auditoría y huella de la BD de trabajo.

### Changed

- **`ReportIncidentUseCase`**: `UBICACION_DESCONOCIDA`/`INVENTARIO_DESAPARECIDO` bloquean la unidad (`BLOQUEADO`, conservando ubicación) con evento `BLOQUEADO` y sin movimiento; el resto de tipos no cambia de estado.
- **`ResolveIncidentUseCase`**: `resultado` obligatorio para esos 2 tipos (400 `field=resultado`); `NO_ENCONTRADA` → `PERDIDO` sin ubicación (libera cupo); `ENCONTRADA` → `DISPONIBLE` (con destino se mueve y ajusta cupo, sin destino queda en su ubicación); `resultado`/`ubicacion_destino_id` con contenido en tipos que no lo admiten → 400; `resolucion` guarda `resultado`, `ubicacion_destino_id`, `estado_final` y `actor_resolutor`.
- **`ReceiveInventoryUseCase`**: `rechazo_total=1` → sin unidad, sin ledger y sin evento, `discrepancia_porcentaje = 100`, `recepcion_parcial = false` e incidencia `ENTREGA_RECHAZADA` con prioridad `ALTA`; con `cantidad_recibida` a la vez → 400 `field=cantidad_recibida`; discrepancia >= umbral → `DISCREPANCIA_CANTIDAD` `ALTA`, por debajo → `NORMAL`.
- **`MoveStockUseCase`**: detección E8 (si el último movimiento de la unidad es exactamente el inverso, el nuevo se registra como corrección con `movimiento_referencia_id`) y warnings CU-002.
- **`public/js/core/loader.js`** y **`public/js/modules/dashboard/sidebar.js`**: registro e ítem del módulo Movimientos; icono `exchange` en `ICONS`.
- **`AGENTS.md`**: se retira al cerrar la sección de autorización excepcional del sprint v1.10.0 (regla 4).
- **`tools/e2e/e2e-v180.ps1` y `e2e-v190.ps1`**: el assert `PERM total inventory.*` pasa de `29`/`33` a `34` (recuento con `inventory.stock.block`).

### Desviaciones y huecos (declarados)

- **`es_correccion`/`movimiento_referencia_id` quedan FUERA de `movements.hash_integridad`** (decisión E8): incluirlos invalidaría el hash de las filas existentes; persisten en sus columnas y el evento `MOVIDO` sí los cubre dentro de `datos_operacion`, que entra en el `hash_integridad` de `stock_events`.
- **Las 2 rutas query de R3 usan `inventory.stock.view`**: no existe permiso propio de movimientos; cualquier usuario que vea stock ve su historial y el listado de movimientos.
- **`RECEPTION_DISCREPANCY_THRESHOLD` no está declarado en `.env`**: corre con el default de 5 % (los tests y el E2E usan 6 % y 0,1 %); para cambiarlo hay que añadirlo al `.env`.
- **`ubicacion_destino_id` es opcional para `ENCONTRADA`** en API y UI (sin destino la unidad pasa a `DISPONIBLE` en su ubicación actual); para `NO_ENCONTRADA` llevar destino → 400 `field=ubicacion_destino_id`.
- **`tools/e2e/stop-e2e-server.ps1` está roto de antes** (error de sintaxis en la línea 23: `$pidFile:` entre comillas dobles → `InvalidVariableReferenceWithDrive`); no se tocó por estar fuera de alcance; la parada se hace con `Stop-E2EServer` de `_env.ps1` o despidiendo el PID a mano.
- **Codificación en scripts PowerShell**: Windows PowerShell 5.1 lee UTF-8 sin BOM como ANSI (rompía `DAÑO`); `e2e-v1100.ps1` se guarda con BOM, usa `DA\u00D1O` ASCII en los bodies y `Invoke-Post` envía bytes UTF-8 explícitos con `charset=utf-8`.
- **La BD E2E acumula datos entre ejecuciones** (`_env.ps1` sólo recrea con `--fresh`): los contajes del script son deltas o van acotados a la unidad; `prepare-e2e-db.php --fresh` fue necesario porque la BD previa no tenía las columnas nuevas.
- Siguen abiertos los huecos declarados en **1.8.1/1.8.2/1.8.3/1.8.4/1.9.0/1.9.1/1.9.2/1.9.3** (listado completo en esas entradas).

### Verified (2026-10-05)

- `php -l` en los 40 PHP tocados → **0 errores**; `node --check` en los 25 JS/MJS tocados → **0 errores**; ningún fichero JS con BOM.
- `composer test` → **exit 0, `Tests: 713, Assertions: 5901`** (2 `PHPUnit Deprecations` preexistentes).
- Smoke jsdom: `smoke-v1100.mjs` → **66 OK, 0 FAIL**; `smoke-v162.mjs` → **89**; `smoke-v171.mjs` → **105**; `smoke-v184.mjs` → **100**; `smoke-v193.mjs` → **136** (**496 assertions de UI en verde**).
- `php bin/regenerate-schema.php` + `php bin/check-schema-drift.php` → **`SIN DRIFT`, exit 0** (32 tablas, 381 columnas, 140 índices, 33 enums, 71 FK): el `schema.sql` versionado refleja los ALTER del sprint.
- **E2E `tools/e2e/e2e-v1100.ps1` → `TODO OK`, exit 0, 124 asserts** contra `xproject_e2e` recreada con `bin/prepare-e2e-db.php --db=xproject_e2e --fresh`; la BD de trabajo quedó intacta (huella `13|43|254|12|15f0ef07…` antes y después) y el servidor embebido se detuvo al final (8099 cerrado, PID borrado).
- **Regresión de TODA la batería E2E**: `e2e-audit`, `e2e-expire`, `e2e-sprint6`, `e2e-v162`, `e2e-v170`, `e2e-v171`, `e2e-v180`, `e2e-v190`, `e2e-v191` y `e2e-v1100` → **exit 0 y `TODO OK`** (`e2e-v180` y `e2e-v190` con el recuento de permisos actualizado a 34); los 9 preexistentes corrieron durante el cierre y los tres últimos se re-ejecutaron tras recrear la BD con `--fresh`.
- Roles vivos: **ADMIN 45 y MANAGER 32** permisos con `inventory.stock.block`; USER 17 y VIEWER 10 sin (el E2E verifica 403 con USER en block y unblock).
- **Pendiente de validación humana**: checklist visual en navegador (pestaña Movimientos con filtros, modal Historial de la unidad, Bloquear/Desbloquear con confirm, resolve con resultado/destino, rechazo total en recepción y toasts de warnings al mover).

## [1.9.3] - 2026-10-05

Frontend (**sólo frontend**: sin backend, sin esquema, sin permisos nuevos): **componentes compartidos `public/js/ui/*`** (`window.UI`, 22 funciones) y **UI de Incidencias y Conteos** (`inventory/incidents.js` e `inventory/counts.js`), con `reservations.js` migrado, registro en loader/sidebar y smoke nuevo. `modules/dashboard/dashboard.js` queda fuera de alcance.

### Added

- **`public/js/ui/dom.js`** (48 líneas): `escapeHtml`, `formatNumber`, `toast`.
- **`public/js/ui/render.js`** (143): `pageHeader`, `estadoBadge` (catálogo de badges `success|warning|danger|info|neutral`), `emptyState`, `loadingState`, `tableShell`, `renderPagination`, `summaryCards`.
- **`public/js/ui/modal.js`** (165): `modalShell` (clases `modal-lg|modal-xl` y `GRID_COLS` 1-6), `mountModal`, `closeModal`, `field` (input/textarea/select/checkbox), `grid`, `showFieldError` (sanea comillas y backslashes del nombre de campo), `showFormError`, `clearErrors`.
- **`public/js/ui/actions.js`** (151): `can` (permisos del `AuthState`), `newIdempotencyKey`, `handleActionError` (títulos: Datos inválidos / Sesión expirada / Acceso denegado / No encontrado / Conflicto / Operación rechazada / Error; opts `{fallbackMessage, root, inline, tag, onConflict, reload}`), `downloadCsv` (`HttpClient.get` con `rawResponse: true` + `Accept: text/csv` y re-add del BOM, nunca enlace directo).
- **`public/js/modules/inventory/incidents.js`** (1138 líneas): listado con resumen y filtros (estado, tipo, unidad, fechas), filtro de unidad select↔input, modal de reportar (Idempotency-Key obligatoria, validación de tipo/descripción/2000 caracteres/ubicación condicional a `DISCREPANCIA_CANTIDAD`, selector con los 8 tipos exactos de `Incident::TIPOS_VALIDOS`), modal de detalle con Resolver/Investigar/Cerrar y bloque de resolución, permisos `inventory.incident.{view,report,resolve}` y aviso `DENIED_TEMPLATE` sin `view`.
- **`public/js/modules/inventory/counts.js`** (1197 líneas): listado con resumen y filtros (estado, ubicación, fechas), modal de iniciar (Idempotency-Key obligatoria; al cerrar recarga el listado y abre el detalle del conteo nuevo), detalle en modal XL con hoja teórica (inputs por unidad `data-result`/`data-notas`), registrar resultados, completar (422 de faltantes marcando `[data-error="resultados__<id>"]`), reconciliar (select de acción + justificación por fila, key obligatoria) y descarga de la hoja CSV, permisos `inventory.count.{view,create,reconcile}`.
- **`tools/smoke/smoke-v193.mjs`** (925 líneas, 7 secciones): contrato de las 22 funciones de `window.UI`, incidencias (listado, filtros, permisos, reportar con clave/409/404, detalle/investigar/resolver con 422) y conteos (listado, hoja teórica, resultados con 422 de faltantes, completar, reconciliar con 422 de hoja vencida, iniciar, CSV), más loader/router/sidebar/`index.php`.

### Changed

- **`public/index.php`**: 4 `<script>` de `js/ui/*` (dom, render, modal, actions) tras `core/idle.js` y antes de los scripts del shell; `php -l` en verde.
- **`public/js/core/loader.js`**: `inventory/incidents` y `inventory/counts` añadidos a `registerMany` (las rutas ya estaban declaradas en `core/router.js`, que no se tocó).
- **`public/js/modules/dashboard/sidebar.js`**: iconos `alert` (triángulo) y `clipboard` (feather) en `ICONS`; ítems **Incidencias** (`inventory.incident.view`) y **Conteos** (`inventory.count.view`) tras Devoluciones, con roles `ADMIN|MANAGER|USER|VIEWER`.
- **`public/js/modules/inventory/reservations.js`**: migrado a los componentes compartidos (render de listado/modales, toasts y manejo de errores vía `UI.*`) sin cambiar comportamiento visible.
- **`tools/smoke/smoke-v162.mjs`**: parche `UI_FILES`/`evalUi` para evaluar `public/js/ui/*` antes de `reservations.js` (el módulo toma `const UI = global.UI` al evaluar).

### Desviaciones y huecos (declarados)

- **Los 422 de conteos con detalle sólo en `message` se detectan por regex**: `countMissingResults` (`field: resultados`), `countStaleQuantity` (`field: cantidad`) y `countAdjustmentStateNotAllowed` (`field: estado` + substring) no traen payload estructurado (la API expone `message`, `field` y `category`), así que el módulo combina `field` con una expresión regular sobre `message`. Frágil ante cambios de redacción.
- **Filtro de unidad de incidencias sujeto a `inventory.stock.view`**: se puebla desde `GET /inventory/stock`; sin ese permiso cae a un input libre de id (no existe endpoint de catálogo de unidades accesible sin él).
- **Filtro de ubicación de conteos limitado a 100 filas**: `GET /inventory/locations?activa=true&page=1&per_page=100` (mismo patrón que `returns.js`); con más de 100 ubicaciones activas alguna no aparecerá en el select (fallback a input de id en los otros filtros, aquí no).
- **Sin deep-link `inventory/counts/:id`**: el detalle de conteo sólo vive en su modal (el de incidencias sí muestra `inventory_count_id` como texto).
- **`reservations.js` conserva los títulos legacy de `release`**: no se migró a `handleActionError` porque `smoke-v162` exige textos concretos (`Sin permiso`, 409 de conflicto, 404).
- **`.badge-secondary` no existe en el CSS local** (catálogo real: `success|warning|danger|info|neutral`); los módulos nuevos no lo usan.
- **Sin script E2E nuevo**: v1.9.3 no toca backend; como regresión se ejecutó `tools/e2e/e2e-v191.ps1` sobre `xproject_e2e` recreada.
- Siguen abiertos los huecos declarados en **1.8.1/1.8.2/1.8.3/1.8.4/1.9.0/1.9.1/1.9.2** (listado completo en esas entradas).

### Verified (2026-10-05)

- `node --check` en los JS tocados (`ui/{dom,render,modal,actions}.js`, `incidents.js`, `counts.js`, `reservations.js`, `sidebar.js`, `loader.js`, `smoke-v193.mjs`, `smoke-v162.mjs`) → **0 errores**; ningún fichero con BOM.
- `php -l public/index.php` → **exit 0**.
- Smoke jsdom: `smoke-v193.mjs` → **136 OK, 0 FAIL**; `smoke-v162.mjs` → **89 OK, 0 FAIL**; `smoke-v171.mjs` → **105 OK, 0 FAIL**; `smoke-v184.mjs` → **100 OK, 0 FAIL** (**430 assertions de UI en verde**).
- `composer test` → **exit 0, `Tests: 674, Assertions: 5640`** (base intacta; 2 `PHPUnit Deprecations` preexistentes).
- `php bin/check-schema-drift.php` → **`SIN DRIFT`, exit 0** (32 tablas, 378 columnas, 140 índices, 32 enums, 71 FK): sprint sin tocar esquema.
- **E2E `tools/e2e/e2e-v191.ps1` → `TODO OK`, exit 0** contra `xproject_e2e` recreada con `bin/prepare-e2e-db.php --db=xproject_e2e --fresh`; la BD de trabajo quedó intacta (huella `13|0|43|254|8|842` → `13|0|43|254|15f0ef07…` antes y después) y el servidor embebido se detuvo al final (PID borrado, 8099 cerrado).
- **Sin permisos nuevos, sin rutas nuevas, sin migraciones**: nada que sembrar ni regenerar.
- **Pendiente de validación humana**: checklist visual a 1366 / 1024 / 768 px (menú Incidencias/Conteos, listados, filtros, modales de reporte/detalle/iniciar/conteo, hoja teórica con inputs, tema claro/oscuro).

## [1.9.2] - 2026-10-04

Frontend (**CSS y markup, sin backend, sin esquema, sin permisos**): **tokens únicos** en `:root` + breakpoints únicos (640/768/1024) + eliminación de utilidades locales duplicadas del CDN, y **defectos de layout D1/D2/D3/D9/D11/D12** (cabeceras de acciones desalineadas, 14 tablas recortadas por `overflow-hidden`, modales estrechos para tablas de 7 columnas, `hide-mobile`/`hide-desktop` huérfanos). `modules/dashboard/dashboard.js` queda fuera de alcance.

### Added

- **Tokens nuevos en `themes.css`**: `--font-sans` (tipografía base del `body`), `--sidebar-width: 260px`, `--modal-width*` (`-sm` 24rem / default 32rem / `-lg` 48rem / `-xl` 64rem), `--overlay-modal`, `--overlay-sidebar`, `--toggle-knob`, `--print-background` y `--print-text` (los dos últimos en claro y en oscuro). Escala de breakpoints documentada en la cabecera del fichero (640 / 768 / 1024).
- **`public/css/error.css`** (nuevo): clases de la página de error (`error-page`, `error-card`, `error-title`, `error-message`, `error-detail`), todas sobre tokens; antes la página de error se pintaba con utilidades Tailwind + valores fijos.
- **`components.css`**: `.form-grid` (`repeat(auto-fit, minmax(14rem, 1fr))` con `gap` propio), `.form-grid > .form-group` (sin `margin-bottom` para no sumarlo al `gap`), `.form-grid .span-all`, `.modal-xl` (64rem) y bloque `.col-actions` para `th`/`td` de acciones (D1).
- **`style.css`**: `.card.p-0` y `.card.p-3` como decisión del componente (el relleno de listado/filtros no depende de si el `<style>` del CDN cae antes o después de los ficheros locales); `.hidden { display: none !important }` documentado como la única utilidad conservada (necesario para poder ocultar `.sidebar`).
- **`index.php`**: `renderErrorPage()` reescrita sobre tokens (`/css/themes.css` + `/css/error.css`, `APP_URL` para las rutas relativas, pre-paint de tema en línea); `tailwind.config` extendido con `borderRadius` y `boxShadow` apuntando a los tokens; `<header class="app-header">` y `<main id="main-content">` en lugar de clases utilitarias.
- **`persistence.js`**: `themeColorFromTokens()` lee `--color-background` y escribe el `meta theme-color` en `#rrggbb` (nada si no se puede leer).
- **`router.js`**: `showAppShell()` pasa a clases del shell (`is-public` en `#app`, `hidden` en `#sidebar`, `is-open` siempre retirado al navegar) **sin estilos inline**.

### Changed

- **D1 (cabeceras de acciones desalineadas)**: en 8 listados el `<th>` pasó de `class="text-right"` a `class="col-actions"` y el `<td>` conserva `table-actions` y suma `col-actions` (`stock`, `locations`, `products`, `reservations`, `picking`, `deliveries`, `shipments`, `logs`); en `components.css` se retiró el `th:last-child { text-align: center }` que quedaba pisado por `th.text-right`.
- **D2 (14 tablas recortadas)**: desaparece `overflow-hidden` de las cards de listado y el scroll horizontal vive en `.table-wrapper` (envolvente con `data-*-table`, o la propia card cuando sólo contiene la tabla). Ficheros: `logs`, `deliveries` (listado + 2 tablas de los modales de confirmar/fallo), `locations`, `picking` (listado + panel de reservas), `products`, `reservations`, `returns`, `shipments` (listado + panel de lote + unidades), `stock`.
- **D11/D3 (modales con tablas anchas)**: modal de confirmar/fallo de `deliveries` pasa de `modal-lg` a `modal-xl` y el de detalle de `shipments` de `modal` a `modal-lg`; ambos envuelven su tabla en `.table-wrapper`.
- **D12 (grids `md:` en modales)**: los grids de `stock` (modal Mover y modal Reservar) y del detalle de `shipments` pasan a `.form-grid` con `md:col-span-2` → `span-all`.
- **FASE 1 (tokens únicos)**: `body` se queda sólo con `min-height`/`line-height`/tipografía (el resto de la regla original `padding: 0; margin: 0` ya lo aporta la reset); `header` unificado en flex (`0.75rem 1rem`); `main` con un único `padding: 1rem` (1.5rem desde 768px); el bloque de utilidades locales de `style.css` se reduce a `.hidden` (todo lo demás lo define el CDN con el mismo significado: `flex`, `grid`, `gap-*`, `p-*`, `m-*`, `text-*`, `rounded*`, `shadow*`, `overflow-*`, `hidden`, etc.); se retiran `hide-mobile`/`hide-desktop` (0 usos en markup, D9); el modo impresión usa `rgb(var(--print-background))` / `rgb(var(--print-text))`; `themes.css` fija `body { font-family: var(--font-sans) }`.
- **Overlays y toggle** con tokens (`--overlay-modal`, `--overlay-sidebar`, `--toggle-knob`) en lugar de `rgb(0 0 0 / …)` escritos en cada regla.

### Desviaciones y huecos (declarados)

- **`.form-group mb-0` depende del orden de carga (preexistente, no tocado)**: `components.css` define `.form-group { margin-bottom: 1rem }` con la misma especificidad que la utilidad `.mb-0` del CDN, así que el resultado depende de qué `<style>` se inserte después; la utilidad nunca estuvo en CSS local, así que el comportamiento es idéntico antes y después de este sprint (38 usos en los formularios de filtros).
- **Grids `md:` de otros modales sin convertir a `.form-grid`**: `locations.js:231/248/254`, `products.js:208/237/254`, `returns.js:431`, `deliveries.js:513/658`, `logs.js:306/345`, `receiving.js:44/45/53`, `shipments.js:160/205` (formularios de filtro) y `dashboard.js:133`. Sólo se convirtieron `stock.js` y el detalle de `shipments.js` (los dos ficheros pedidos); el resto queda anotado para el siguiente sprint de UI.
- **`tailwind.config.colors.primary` sigue con hex literales** (`#3b82f6` / `#2563eb` en `index.php:224`): el objeto se evalúa en JS durante el parseo del documento, antes de poder leer `getComputedStyle`; hay que mantenerlo a mano en sincronía con `--color-primary` / `--color-primary-hover`.
- **`meta theme-color` sin color fijo**: `content=""` en `index.php` y lo rellena `persistence.js` desde `--color-background`; si el token no se lee (o JS no corre), la barra del navegador queda sin color en lugar de usar un valor hardcodeado.
- **Pre-paint de tema duplicado**: el script inline que aplica `dark`/`light` está tanto en el `<head>` del SPA como en `renderErrorPage()`; no se extrae a fichero porque la página de error no carga el JS de la aplicación.
- **`box-shadow: 0 0 0 3px rgb(var(--color-danger) / 0.25)`** (`components.css:224`, foco de inputs inválidos): el color sale del token pero la geometría/alfa siguen escritas en la regla (candidata a `--focus-ring-danger`).
- **Sombras y overlays con `rgb(0 0 0 / …)`** viven en `themes.css` porque son los propios valores de los tokens `--shadow-sm|md|lg` y `--overlay-modal|sidebar` (claro y oscuro), no usos sueltos.
- **Overlay móvil ahora por CSS**: antes `showAppShell()` forzaba `overlay.style.display = 'none'` al cambiar de ruta (lo anulaba respecto a `.sidebar.is-open + .sidebar-overlay`); al eliminar los estilos inline el overlay se rige por CSS y se cierra retirando `is-open` (se hace siempre al navegar). Efecto visible: el overlay sólo aparece con el sidebar móvil abierto.
- **`.card.p-0` / `.card.p-3`** son reglas explícitas en `style.css` (reconciliación D8): sin ellas el relleno de las cards dependería del orden entre el `<style>` del CDN y los ficheros locales.
- **Sin script E2E nuevo**: v1.9.2 no toca backend; como regresión se ejecutó `tools/e2e/e2e-v191.ps1` sobre `xproject_e2e` recreada.
- Siguen abiertos los huecos declarados en **1.8.1/1.8.2/1.8.3/1.8.4/1.9.0/1.9.1** (listado completo en esas entradas).

### Verified (2026-10-04)

- `node --check` en **todos** los JS de `public/js` (11 tocados: `core/router.js`, `themes/persistence.js`, `stock`, `locations`, `products`, `reservations`, `picking`, `deliveries`, `shipments`, `returns`, `logs`) → **0 errores**.
- `php -l public/index.php` → **exit 0**.
- Smoke jsdom: `smoke-v162.mjs` → **89 OK, 0 FAIL**; `smoke-v171.mjs` → **105 OK, 0 FAIL**; `smoke-v184.mjs` → **100 OK, 0 FAIL** (**294 assertions de UI en verde**; los tests siguen encontrando `.table-actions` en los `<td>` porque conservan esa clase).
- `composer test` → **exit 0, `Tests: 674, Assertions: 5640`** (base intacta; 2 `PHPUnit Deprecations` preexistentes).
- `php bin/check-schema-drift.php` → **`SIN DRIFT`, exit 0** (32 tablas, 378 columnas, 140 índices, 32 enums, 71 FK): sprint sin tocar esquema.
- **E2E `tools/e2e/e2e-v191.ps1` → `TODO OK`, exit 0** contra `xproject_e2e` recreada con `bin/prepare-e2e-db.php --db=xproject_e2e --fresh`; la BD de trabajo quedó intacta (huella `13|0|43|254|15f0ef07…`) y el servidor embebido se detuvo al final.
- **Sin permisos nuevos, sin rutas nuevas, sin migraciones**: nada que sembrar ni regenerar.
- **Pendiente de validación humana**: checklist visual a 1366 / 1024 / 768 px (listados, tabla de acciones centrada, scroll horizontal de tablas, modales de Entregas en ancho XL y de Envíos en LG, tema claro/oscuro, página de error).

## [1.9.1] - 2026-10-04

Backend (parche de v1.9.0): **PERDIDA y conteos pasan por la matriz de estados** y `AJUSTAR` deja de aplicarse sobre datos obsoletos. **Sin tocar frontend, esquema ni permisos.**

### Added

- **`InventoryException::countStaleQuantity()`**: 422 con `field: cantidad` y `category: business_rule` que describe la unidad física, la `cantidad_esperada` de la hoja y la `cantidad_actual` de la unidad, y recuerda que hay que registrar `JUSTIFICAR` o iniciar un conteo nuevo (el detalle viaja en `message`: la API sólo expone `message`, `field` y `category`).
- **`StateTransitionService`**: `DISPONIBLE → PERDIDO` pasa a ser transición válida (ver desviación más abajo), con su nota en el docblock de la clase.
- **Tests**: `StateTransitionServiceTest` (DISPONIBLE → PERDIDO a válidas; `EN_PREPARACION|CARGADO|EN_TRANSITO → PERDIDO` a inválidas; `testPerdidoEsEstadoTerminalSinDestinos` comprueba los 5 orígenes permitidos y los 5 rechazados), `IncidentReportUseCaseTest` +2 (`PERDIDA` desde `EN_TRANSITO` → 422 sin tocar unidad, incidencias ni eventos; desde `DAÑADO` → 201 con la unidad en `PERDIDO`) y `PhysicalCountUseCaseTest` +1 (hoja vencida → 422 con detalle, nada se toca y `JUSTIFICAR` sigue permitido).
- **`tools/e2e/e2e-v191.ps1`**: E2E de este parche contra `xproject_e2e` (PERDIDA desde DISPONIBLE → 201 con cupo liberado y replay sin duplicar; PERDIDA desde EN_TRANSITO → 422 con `field: estado`, unidad y auditoría intactas; conteo con discrepancia y unidad alterada tras la instantánea → `AJUSTAR` 422 con detalle y `JUSTIFICAR` → `RECONCILIADO`, sin eventos `AJUSTE_CANTIDAD`, auditoría sin duplicados y verificación de sólo lectura de la BD de trabajo).

### Changed

- **`ReportIncidentUseCase::applyUnitEffect()`** para `PERDIDA`: sustituye el chequeo manual (`sólo rechazaba si la unidad ya estaba PERDIDO`) por `$this->states->assertCanTransition($estadoAnterior, StockState::PERDIDO)`. Ahora sólo pasan `DEVUELTO`, `DAÑADO`, `BLOQUEADO` y `DISPONIBLE`; `RESERVADO`, `EN_PREPARACION`, `CARGADO`, `EN_TRANSITO`, `ENTREGADO` y la propia `PERDIDO` devuelven 422 con `field: estado` y no crean incidencia, evento ni ledger (antes cualquier estado distinto de `PERDIDO` dejaba pasar).
- **`ReconcileCountUseCase`**: inyecta `StateTransitionService` (registro en `InventoryServiceProvider` y en `InventorySqliteTestCase::setUp()`), llama a `assertCanTransition($estadoAnterior, PERDIDO)` cuando `AJUSTAR` deja la cantidad final en 0 y, **antes de tocar nada**, compara la cantidad actual de la unidad con `cantidad_esperada` de `lista_teorica`: si difieren, aborta con `countStaleQuantity()` sin crear `StockEvent`, sin mover ledger ni capacidad y sin cerrar el conteo. `JUSTIFICAR` no se ve afectado (no modifica la unidad).
- La verificación de obsolescencia se ejecuta **antes** del chequeo de estado (`DISPONIBLE|DAÑADO`), de modo que una unidad en estado no permitido con hoja vencida responde 422 con `field: cantidad`.

### Desviaciones y huecos (declarados)

- **`DISPONIBLE → PERDIDO` añadida a la matriz §4 (aprobada por el usuario)**: la spec (`backups/INVENTORY_MODULE_SPECIFICATION.md` §4) dice `DISPONIBLE → PERDIDO = NO`, pero CU-010 (reportar `PERDIDA`, caso típico de una pérdida en almacén), CU-011 (reconciliar con cantidad 0) y `accion_marcar_perdido() → PERDIDO` (que no limita el estado de origen) lo exigen. El comportamiento de v1.9.0 que ya la permitía de facto se formaliza aquí y queda documentado en el docblock de `StateTransitionService`; los orígenes no contemplados siguen en 422 estricto.
- **`JUSTIFICAR` no valida obsolescencia**: sólo compara cantidades en `AJUSTAR`, la única acción que escribe; un conteo con hoja vencida se cierra por justificación sin comprobar que la cantidad actual siga siendo la de la instantánea.
- El detalle de la discrepancia (unidad, esperada y actual) viaja en `message`, porque `public/index.php` sólo serializa `message` + `data.field` + `data.category`.
- Siguen abiertos los huecos declarados en **1.8.1/1.8.2/1.8.3/1.8.4/1.9.0** (listado completo en esas entradas).

### Verified (2026-10-04)

- `php -l` en los **9 ficheros tocados**: **9/9 exit 0**.
- `composer test` → **exit 0, `Tests: 674, Assertions: 5640`** (668 → 674; 2 `PHPUnit Deprecations` preexistentes).
- `php bin/check-schema-drift.php` → **`SIN DRIFT`, exit 0** (32 tablas, 378 columnas, 140 índices, 32 enums, 71 claves foráneas): el parche no toca esquema.
- **Sin permisos nuevos**: nada que sembrar (los 33 `inventory.*` de v1.9.0 siguen intactos en la BD viva).
- **E2E `tools/e2e/e2e-v191.ps1` → exit 0** contra `xproject_e2e` (BD recreada con `bin/prepare-e2e-db.php --db=xproject_e2e --fresh`, servidor PHP embebido en 8099): 0 fallos, sin errores SQL, auditoría sin duplicados por clave+operación y servidor detenido al final.
- **La BD de trabajo no se escribe desde el E2E**: huella `incidents|inventory_counts|stock_units|stock_events|products` idéntica antes y después del script (13|0|43|254|15f0ef07…).
- Sin tocar JS/CSS: `node --check` y smoke jsdom no aplican en este parche.

## [1.9.0] - 2026-10-04

Backend: **CU-010 Incidencias** y **CU-011 Conteo físico** (`incidents` / `inventory_counts`), 4 permisos `inventory.*` nuevos (29 → 33), 12 rutas y 9 clases nuevas de aplicación, tests unitarios y E2E aislados. **Sin tocar frontend.**

### Added

- **Dominio**: entidad `InventoryCount` (PENDIENTE/EN_CURSO/COMPLETADO/RECONCILIADO con `lista_teorica`, `resultados`, `discrepancias`, `reconciliado`, `ajustes_aplicados`); `Incident` con `iniciarInvestigacion()`, `resolver()`, `vincularConteo()`, `inventory_count_id` y `resolucion` ya no readonly; `StockEvent::TIPO_AJUSTE_CANTIDAD`; `InventoryException` +11 fábricas; repositorios `IncidentRepository` (+`update`) e `InventoryCountRepository` con sus interfaces, tenant-scoped.
- **CU-010**: `ReportIncidentUseCase` (`Idempotency-Key` obligatorio → 201), `InvestigateIncidentUseCase`, `ResolveIncidentUseCase` e `IncidentQueryService`. Al reportar: DAÑO → unidad DAÑADA (cupo intacto); PERDIDA → unidad PERDIDO con `ubicacion_id` NULL y liberación del cupo; DISCREPANCIA_CANTIDAD → crea un `InventoryCount` PENDIENTE vinculado (`inventory_count_id`); el resto de tipos sólo se investigan. Crea además un `StockEvent` con `hash_integridad` y su fila de ledger. Transiciones: ABIERTA → INVESTIGACION → RESUELTA|NO_RESUELTO (los efectos sobre el stock se producen al reportar, no al resolver).
- **CU-011**: `StartCountUseCase` (instantánea inmutable de `lista_teorica`, ubicación opcional), `RecordCountResultsUseCase` (fusión parcial → EN_CURSO; unidad fuera de la hoja → 422), `CompleteCountUseCase` (unidades sin resultado → 422 con detalle; discrepancias sólo con diferencia distinta de cero), `ReconcileCountUseCase` (`AJUSTAR` sólo en DISPONIBLE/DAÑADO, si no 422 y sólo se admite `JUSTIFICAR`; `StockEvent AJUSTE_CANTIDAD` con hash + ledger + capacidad; cantidad final 0 → PERDIDO + incidencia `INVENTARIO_DESAPARECIDO`; `ajustes_aplicados` con autorizador y fecha; RECONCILIADO al agotar discrepancias) e `InventoryPresenceService` (`snapshot()` y `cupoUsado()` = `SUM(CEIL(cantidad))`).
- **HTTP**: `IncidentController` (index/show/report/investigate/resolve) y `CountController` (index/show/sheet/results/complete/reconcile), con auditoría de dominio escrita tras confirmar la transacción y sin fila nueva en replay; `CountQueryService` (filtros estado/tipo/ubicación/rango de fechas, resumen y paginación con `per_page` ≤ 100, `detail()`, `sheet()` y `sheetCsv()`). `GET /inventory/counts/{id}/sheet?format=csv` devuelve `text/csv` con BOM UTF-8.
- **12 rutas** nuevas en `src/Modules/Inventory/routes.php` (las literales `/incidents/report` y `/counts/start` antes de las de parámetro) con permisos `inventory.incident.{view,report,resolve}` e `inventory.count.{view,create,reconcile}`, más el registro de los 7 use cases y 2 servicios en `InventoryServiceProvider`.
- **4 permisos nuevos** en `InventoryPermissionSeeder` (idempotente): `inventory.incident.view`, `inventory.count.create`, `inventory.count.view`, `inventory.count.reconcile`. Reparto por rol: ADMIN 33, MANAGER 30, USER 17, VIEWER 10.
- **Tests**: `IncidentReportUseCaseTest` (17 tests) y `PhysicalCountUseCaseTest` (19 tests) con aislamiento entre tenants, idempotencia (replay sin duplicar filas ni eventos) y atomicidad (rollback completo); `InventoryPermissionSeederTest` actualizado a 33 permisos y con las nuevas pertenencias por rol; esquema SQLite de `incidents`/`inventory_counts` y cableado de los use cases en el `setUp()` de `InventorySqliteTestCase`.
- **`tools/e2e/e2e-v190.ps1`**: E2E de CU-010 y CU-011 contra `xproject_e2e` (permisos, listados, claves obligatorias, report DAÑO → investigar → resolver con replay y auditoría sin duplicados, conteo con diferencia +1 → `AJUSTAR` con evento/ledger, hoja JSON y CSV, filtros y verificación de sólo lectura de la BD de trabajo).

### Changed

- **`IncidentRepository::update()` y `InventoryCountRepository::update()`** sólo envían los parámetros con placeholder: PDO con emulación desactivada fallaba con `column index out of range` al recibir las columnas extra de `toArray()`.
- **`StartCountUseCase`** calcula la huella de idempotencia sólo con los campos del cliente (`ubicacion_id`, `fecha`): la fecha por defecto la genera el servidor y cambiaba en cada reintento, devolviendo 409 "payload distinto" y rompiendo el replay (R7).
- **`ReconcileCountUseCase::normalize()`** conserva `conteo_id` (antes se perdía y fallaba con `requiredField`); el id viaja siempre por la ruta y lo inyecta `CountController::reconcile()`.

### Desviaciones y huecos (declarados)

- **PERDIDA desde DISPONIBLE queda fuera de la matriz §4** *(corregido y formalizado en 1.9.1)*: `StateTransitionService` sólo admite DISPONIBLE → RESERVADO|EN_PREPARACION|DAÑADO|BLOQUEADO, así que `ReportIncidentUseCase` no llama `assertCanTransition` para el tipo PERDIDA (falla 422 sólo si la unidad ya está PERDIDO) y la transición no queda registrada en la matriz.
- **Discrepancias de conteo**: sólo se guardan las unidades con diferencia distinta de cero; las que coinciden no generan fila ni acción.
- **Nombres de evento reutilizados** (DAÑADO, PERDIDO, INCIDENCIA) más el nuevo `AJUSTE_CANTIDAD`; en las rutas que marcan PERDIDO el ledger conserva `cantidad_fisica` intacta y sólo cambia `estado_actual`.
- **Mapeo de permisos**: `POST /counts/{id}/results` y `/complete` usan `inventory.count.create` (la spec no define un `count.results` propio).
- **CSV del sheet servido con `header()` + `echo` + `exit` en el controlador**: sólo lectura y `AuditMiddleware` no audita GET, así que no se pierde ningún hook; la generación vive en `CountQueryService::sheetCsv()`.
- **Sin módulo de notificaciones**: investigar/resolver incidencias no notifica a nadie (hueco de endpoint, no inventado).
- **Los conteos abiertos no bloquean movimientos de stock**: sólo congelan su `lista_teorica`; el stock sigue moviéndose mientras el conteo está PENDIENTE/EN_CURSO.
- **`Idempotency-Key` opcional en `investigate`/`resolve`** (la spec la exige sólo en `report`; aquí también son obligatorias en `counts/start` y `counts/reconcile`).
- Siguen abiertos los huecos declarados en **1.8.1/1.8.2/1.8.3/1.8.4** (`backups\` sin backups, instalación limpia sin seeders de roles `BUILT_IN`, cross-tenant 404, `bin/seed-transit-locations.php` con el total de tenants mal impreso, `CHANGELOG.md` en `.gitignore` sin repo git, servidor E2E que no se detiene en `exit` tempranos).

### Verified (2026-10-04)

- `php -l` en los **27 ficheros del sprint**: **27/27 exit 0**.
- `composer test` → **exit 0, `Tests: 668, Assertions: 5591`** (632 → 668; 2 `PHPUnit Deprecations` preexistentes).
- `php bin/check-schema-drift.php` → **`SIN DRIFT`, exit 0** (32 tablas, 378 columnas, 140 índices, 32 enums, 71 claves foráneas).
- **Seeder en la BD viva** (regla 9): `permissions_created=4, permissions_updated=0, roles_updated=4`; los 33 permisos `inventory.*` presentes, ordenados y sin duplicados; ADMIN 33 / MANAGER 30 / USER 17 / VIEWER 10 con `incident.view` y `count.view` en los 4 roles, `incident.report` y `count.create` en ADMIN+MANAGER+USER, `incident.resolve` y `count.reconcile` sólo en ADMIN+MANAGER.
- **E2E `tools/e2e/e2e-v190.ps1` → exit 0** contra `xproject_e2e` (BD recreada con `bin/prepare-e2e-db.php --fresh`, servidor PHP embebido en 8099): 0 fallos, auditoría sin duplicados por clave+operación y servidor detenido al final.
- **La BD de trabajo no se escribe desde el E2E**: huella `incidents|inventory_counts|stock_units|stock_events|products` idéntica antes y después del script (13|0|43|254|15f0ef07…).
- Sin tocar JS/CSS: `node --check` y smoke jsdom no aplican en este sprint.

## [1.8.4] - 2026-10-04

Frontend: pantallas de **Entregas** (CU-007 / CU-008) y **Devoluciones** (CU-009) sobre los endpoints ya existentes, con modales de escritura, manejo de errores 400/401/403/404/409/422, corrección del item Reservas del sidebar y suite jsdom propia. **Sin tocar backend, esquema ni permisos.**

### Added

- **`public/js/modules/inventory/deliveries.js` (`window.InventoryDeliveriesModule`)**: tabla de envíos `EN_RUTA` (`GET /inventory/shipments?estado=EN_RUTA&per_page=100`) con las acciones *Confirmar entrega* y *Registrar fallo* (sólo con `inventory.delivery.confirm`), historial paginado con filtros `estado|fecha_desde|fecha_hasta|shipment_id` (`GET /inventory/deliveries`) y 4 tarjetas de resumen. Modal de confirmación sobre `GET /inventory/shipments/{id}` (sólo las unidades `CARGADA`/`EN_TRANSITO`, por defecto todas marcadas): check por unidad, `firma_comprobante` (URL http/https validada en cliente), `observaciones` y `destinatario_real` opcionales, marcar/desmarcar todas, **aviso en vivo de cuántas quedan no marcadas** y selector de `motivo_fallo` (defecto `OTRO`) para esas unidades → `POST /inventory/deliveries/confirm`. Modal de fallo con motivo (`NO_DISPONIBLE|RECHAZO|ACCESO_DENEGADO|OTRO`), observaciones y unidades afectadas (contador en vivo; sin selección = todas las no entregadas, como decide el backend) → `POST /inventory/deliveries/failed`. `Idempotency-Key` con `crypto.randomUUID()` al abrir el modal, reutilizada en cada reintento y **renovada tras el éxito y en un 409**.
- **`public/js/modules/inventory/returns.js` (`window.InventoryReturnsModule`)**: listado paginado con filtros `estado|motivo|fecha_desde|fecha_hasta|shipment_id` (`GET /inventory/returns`) y 5 tarjetas (total, OK, dañadas, deterioradas, perdidas). Modal de alta `POST /inventory/returns/register` con selector de unidad desde `GET /inventory/stock?estado=` (ENTREGADO/DEVUELTO, los dos estados que admite el backend, filtrado otra vez en cliente) más buscador en vivo, motivo (`CAMBIO_CLIENTE|DAÑO|FALLO_ENTREGA|NO_APLICA|OTRO`), `estado_al_devolver` (`OK|DAÑADO|DETERIORADO|PERDIDO`), ubicación destino desde `GET /inventory/locations?activa=true` (**habilitada y obligatoria sólo con `OK`**; con `DAÑADO`/`DETERIORADO` se explica que va a la zona DAÑADOS y con `PERDIDO` que no guarda ubicación) y observaciones. Validación cliente + `Idempotency-Key` con el mismo criterio que entregas.
- **Errores de negocio → toast + error de campo** (`data-error="<field>"` cuando la respuesta trae `field`): 400 *Datos inválidos*, 401 *Sesión expirada*, 403 *Acceso denegado*, 404 *No encontrado*, 409 *Conflicto* (renueva la clave y recarga) y 422 *Operación rechazada*. Los errores viven en estado (`modal.formError` / `modal.errors`) y se repintan con `applyErrors()` tras cada `renderModal()`, de modo que no se pierden al pasar a *Enviando...*.
- **Items de sidebar *Entregas* y *Devoluciones*** (`inventory.delivery.view` e `inventory.return.view`, los 4 roles) con iconos nuevos `checkCircle` y `undo`.
- **`tools/smoke/smoke-v184.mjs` (nuevo, 100 checks)**: 7 bloques — listado/filtros/confirmación, errores 422/409/401, modal de fallo, permisos de entregas, listado/filtros/alta de devoluciones, `DAÑADO` sin destino + 409 + permisos, y shell (loader/router/sidebar con los 3 roles).

### Changed

- **`public/js/core/loader.js`**: registra `inventory/deliveries` e `inventory/returns` (faltaban; **`router.js` ya declaraba ambas rutas**, verificado y sin cambios).
- **`public/js/modules/dashboard/sidebar.js`**: el item *Reservas* pasa de `inventory.reservation.create` a **`inventory.reservation.view`** (los 4 roles lo tienen en la BD; antes un USER con `.view` pero sin `.create` no veía la entrada) y sus roles fallback se amplían a los 4 BUILT_IN. Con esto *Entregas/Devoluciones* siguen el mismo patrón permiso+fallback del resto de la sección.
- **`tools/smoke/smoke-v162.mjs`**: el fixture `USER_PERMS` de la comprobación de sidebar añade `inventory.reservation.view` (la BD sí lo concede a USER); sin esto el check *USER ve Reservas* fallaría con el permiso corregido. **89/89 en verde.**

### Desviaciones y huecos (declarados)

- **La pantalla de Entregas depende de `inventory.shipping.view`**: ni el listado `EN_RUTA` ni el detalle `GET /inventory/shipments/{id}` existen con sólo `inventory.delivery.view` (hueco de endpoint, no inventado: la UI lo declara y pinta *"Sin acceso a los envíos"* si el backend responde 403). Práctico hoy porque ADMIN/MANAGER/USER tienen ambos permisos.
- **El modal de alta de devoluciones necesita `inventory.stock.view` y `inventory.location.view`** para cargar unidades y destinos: no hay endpoint de "unidades devolvibles" propio de `inventory.return.create`. Los 4 roles tienen esos `.view`, así que no es bloqueante en la matriz actual.
- **Tabla EN_RUTA sin paginación en la UI**: se piden 100 filas (`per_page=100`); con más envíos en ruta habría que añadir controles como los del historial.
- **`motivo_fallo` en la confirmación mixta** es una decisión de UI (el backend lo soporta y lo aplica a las unidades no marcadas); la spec no lo pedía explícitamente en la pantalla.
- **Selector de unidades limitado a 100** (`per_page=100` de `GET /inventory/stock`): con más de 100 unidades `ENTREGADO` no aparecerían todas en el modal.
- **Sin cambios de backend, esquema, permisos ni BD**: ninguna `ALTER`/`CREATE`, ningún seeder tocado (los 6 endpoints y sus permisos ya existían en `routes.php`).
- Siguen abiertos los huecos declarados en **1.8.1/1.8.2/1.8.3** (`backups\` sin backups, instalación limpia sin seeders de roles `BUILT_IN`, cross-tenant 404, `bin/seed-transit-locations.php` con el total de tenants mal impreso, `CHANGELOG.md` en `.gitignore` sin repo git, servidor E2E que no se detiene en `exit` tempranos). El de "rutas de deliveries/returns sin módulo UI" **queda resuelto en este sprint**.

### Verified (2026-10-04)

- `node --check` en los **5 JS tocados** (`deliveries.js`, `returns.js`, `loader.js`, `sidebar.js`, `smoke-v184.mjs`): **5/5 exit 0**.
- Smoke jsdom: `smoke-v162.mjs` **89/89**, `smoke-v171.mjs` **105/105**, `smoke-v184.mjs` **100/100**.
- `composer test` → **exit 0, `Tests: 632, Assertions: 5187`** (2 `PHPUnit Deprecations` preexistentes). Ningún PHP tocado, por lo que `php -l` no aplica.
- `php bin/check-schema-drift.php` → **`SIN DRIFT`, exit 0** (32 tablas, 374 columnas, 139 índices, 32 enums, 70 claves foráneas).
- **E2E: `e2e-v180.ps1` exit 0** contra `xproject_e2e` con el servidor PHP embebido (los 6 endpoints de este sprint: `delivery.confirm`, `delivery.failed`, `return.register`), servidor detenido al final y PID borrado.
- **Verificación en la BD viva** (regla 9): los 4 roles tienen `inventory.reservation.view`, `inventory.delivery.view` e `inventory.return.view`; `delivery.confirm` y `return.create` sólo ADMIN/MANAGER (USER 14 permisos, VIEWER 8); unidades devolvibles **`ENTREGADO` 6 con `ubicacion_id` NULL y `DEVUELTO` 0** — el listado de stock las devuelve porque `StockQueryService` hace `LEFT JOIN locations` sin filtrar nulos, así que el selector de devoluciones las muestra tal cual las necesita el backend.
- **Pendiente (no automatizable aquí)**: recorrido manual en navegador de los 3 caminos (confirmar entrega de un envío `EN_RUTA`, registrar un fallo y registrar una devolución `OK` con destino), descritos en el informe de cierre.

## [1.8.3] - 2026-10-04

Backend: R1 de `stock_ledger.cantidad_disponible` (sólo suma unidades `DISPONIBLE`), permisos de escritura fuera del rol USER, origen obligatorio del envío en `LoadShipmentUseCase`, `.gitignore` dejando de ignorar `/database`, **E2E aislados en su propia BD** (`xproject_e2e` + servidor PHP embebido), `smoke-v161.mjs` retirado y verificación completa.

### Added

- **`bin/recalculate-ledger.php`**: recalcula `stock_ledger.cantidad_disponible` contra `stock_units.estado` (JOIN), **dry-run por defecto**, `--tenant=`, `--apply` con una fila de `audit_logs` por corrección (`CLI bin/recalculate-ledger`), corrige también `estado_actual` desalineado y exit 1 sólo si hay errores. **Dry-run en vivo: `Total: 12 diferencia(s), 0 corregida(s), 0 error(es)`** — 6 unidades `ENTREGADO` + 6 `DAÑADO` con `1.0000 → 0.0000`; `estado_actual` ya alineado en todas. **`--apply` ejecutado tras la confirmación del usuario** (ver *Reparación en vivo*).
- **`bin/prepare-e2e-db.php`**: crea `xproject_e2e` (o la de `E2E_DB_NAME`) desde `database/schema/schema.sql` y copia **sólo** `tenants, users, roles, permissions, role_assignments` desde la BD de trabajo (`SET FOREIGN_KEY_CHECKS=0`, porque `tenants`↔`users` forman un ciclo). Fixtures idempotentes que los E2E asumen: producto `PROD-001` y ubicaciones `E2E-ALMACEN`/`E2E-PICK`; `TRANSITO` y `DAÑADOS` los crea el seeder real del dominio (`bin/seed-transit-locations.php`, lanzado como hijo con `E2E_DB_NAME`). Opciones `--db=`, `--fresh` (única forma de recrearla) y `--sin-fixtures`; se niega si el destino es la BD de trabajo.
- **`tools\e2e\_env.ps1`** (dot-source en la línea 2 de los 7 scripts): fija `E2E_DB_NAME`, lanza `prepare-e2e-db.php`, arranca o reutiliza el servidor PHP embebido en `http://127.0.0.1:8099/api` y define `Stop-E2EServer` (se llama al final de cada script) más `$base`, `$e2eDb`, `$e2eRepoRoot` y `$phpExe`. **`tools\e2e\router.php`** (sirve estáticos de `public/` y deriva el resto a `index.php`, añadiendo `Content-Length` desde un buffer de salida: sin él PowerShell 5.1 deja `ErrorDetails.Message` vacío en los 4xx y los E2E que comparan el cuerpo del error fallan) y **`tools\e2e\stop-e2e-server.ps1`** para parar a mano un servidor huérfano (PID en `tools\e2e\.e2e-server.pid`, ignorado por `.gitignore`).
- **Tests nuevos (632 / 5187 assertions, +24)**: `StockCalculationServiceTest` reescrito con `disponible()` por estado (dataProvider sobre los 12 estados de `StockState`, los que no suman devuelven `0.0`, estado inválido → 400 y fórmula original sin estado intacta), `LoadShipmentUseCaseTest` con los 3 casos de origen (derivado de la zona de las unidades, explícito conservado, 422 con dos zonas y rollback completo) y `InventoryPermissionSeederTest` con USER a 14 permisos más `assertNotContains` de los dos retirados.

### Changed

- **R1 en caliente**: `StockCalculationService::disponible()` acepta `?string $estado` y devuelve `0.0` para cualquier estado distinto de `DISPONIBLE` (`StockState::fromString(...)->isDisponible()`, valida no-negativos igual que antes); `crearLedger()` pasa el estado real y `StockLedger::actualizar()` pone el disponible a `0.0` en estados que no suman. `ConfirmDeliveryUseCaseTest` esperaba ahora `cantidad_disponible = 0.0` tras entregar.
- **Rol USER**: `InventoryPermissionSeeder::ROLE_PERMISSIONS['USER']` deja de incluir `inventory.delivery.confirm` e `inventory.return.create` (16 → 14 permisos; los `.view` siguen en los 4 roles) y **la BD viva se corrigió con una actualización puntual y auditable de `roles.permisos`** (`fix-user-perms.php`, guard de exactamente 1 rol USER): `USER 16 → 14`, con `ADMIN` (40) y `MANAGER` (26) intactos y `dc/rc = 0` en `USER` y `VIEWER`.
- **`LoadShipmentUseCase` exige `origen_ubicacion_id`**: recoge las zonas de preparación de las unidades dentro de la transacción (tras comprobar el tenant de cada zona), y si el cliente no envía origen lo deriva con `Shipment::definirOrigen()`; con **dos o más zonas distintas** falla con **422 `InventoryException::shipmentOriginLocationMissing()`** (`field = origen_ubicacion_id`) y la transacción se revierte entera (ni envío, ni ítems, ni unidades cargadas). Un origen explícito ya no se sobrescribe. `AuthorizeShipment` sigue liberando capacidad desde `stock_units.ubicacion_id`, así que la capacidad no cambia con este punto.
- **`bootstrap.php`**: `'database'` usa `getenv('E2E_DB_NAME') ?: $_ENV['DB_DATABASE']`; cualquier proceso que exporte la variable (API embebida y CLI de `bin/`) opera contra la BD de E2E y el resto sigue en la de `.env`. `bin/regenerate-schema.php` y `bin/check-schema-drift.php` **se niegan a arrancar** con `E2E_DB_NAME` definida, para que `schema.sql` y el drift sigan apuntando siempre a la BD de trabajo.
- **Los 7 E2E** (`e2e-audit`, `e2e-expire`, `e2e-sprint6`, `e2e-v162`, `e2e-v170`, `e2e-v171`, `e2e-v180`) sustituyen `$base = 'http://localhost/xp/public/api'` por el preludio y `mysql.exe -u root xproject` por `mysql.exe -u root $e2eDb`; dejan de depender de Apache y de escribir en la BD de trabajo (`e2e-v171` ya no ejecuta `UPDATE locations SET activa = 0` contra el entorno productivo).
- **`.gitignore`**: fuera `/database` (queda versionado `database/schema/schema.sql`, `database/schema/create_tables.ps1` y `database/seeders/PlatformSeeder.php`; `database/migrations/` está vacío) e ignorados los volcados con datos (`/database/backups/`, `/database/dumps/`, `/database/*.sql`, `/database/*.sql.gz`, `*.dump`) más el PID/log del servidor E2E. `bin/*.php` ya no estaban ignorados.
- **`AGENTS.md`**: regla 7 apunta a los E2E de `tools\e2e\` con la BD de E2E y regla 10 nueva documenta el aislamiento (`E2E_DB_NAME`, `prepare-e2e-db.php`, preludio, servidor embebido, guardas de `bin/` y `stop-e2e-server.ps1`).

### Removed

- **`tools\smoke\smoke-v161.mjs` (decisión del punto 6)**: estaba obsoleto porque su S4 seguía esperando `GET /inventory/stock?estado=RESERVADO`, hueco cubierto desde v1.6.2. Se optó por **borrarlo** en lugar de duplicarlo: `smoke-v162.mjs` es la misma suite con S4 reescrita sobre `GET /inventory/reservations` (cabecera "Vencimiento", botón "Liberar", sin alerta de hueco) y ambas suites verificadas en verde. No quedan referencias al fichero borrado en el código.

### Reparación en vivo (2026-10-04, confirmación del usuario)

- `php bin/recalculate-ledger.php --apply` → **`Total: 12 diferencia(s), 12 corregida(s), 0 error(es)`, exit 0**: las 6 unidades `ENTREGADO` y las 6 `DAÑADO` de `acme-corp` pasaron de `cantidad_disponible 1.0000` a `0.0000`, cada corrección con su fila de `audit_logs` (`operacion = CLI bin/recalculate-ledger`, `tipo_cambio = UPDATE`, `resultado = SUCCESS`, `recurso = stock_ledger`).
- **Revalidación en dry-run posterior: `Sin diferencias: todos los ledgers ya cumplen R1 (v1.8.3)`, `Total: 0 diferencia(s)`, exit 0.**
- Estado final de `stock_ledger` (43 filas = `stock_units`): `DISPONIBLE` 20 con `29.0000 / 29.0000`, `EN_PREPARACION` 1 con `0.0000 / 1.0000`, `EN_TRANSITO` 10 con `0.0000 / 14.0000`, `ENTREGADO` 6 con `0.0000 / 6.0000`, `DAÑADO` 6 con `0.0000 / 6.0000`. **0 filas con `cantidad_disponible < 0` o `> cantidad_fisica`** y ninguna fuera de `DISPONIBLE` con disponible distinto de 0.
- No se tocó ningún otro dato: `stock_units 43`, `shipments 28`, `products 8`, `locations 14` e identidad (`permissions 40`, `roles 4`, `role_assignments 3`, `users 3`, `tenants 1`) intactos; `php bin/check-schema-drift.php` sigue en `SIN DRIFT`.

### Desviaciones y huecos (declarados)

- **`bin/seed-transit-locations.php` imprime mal el total de tenants**: `printf(..., count($tenants) * 2)` en la última línea, así que con 1 tenant dice "en 2 tenant(s)". Sólo el mensaje; el trabajo (2 ubicaciones) es correcto. **No se corrigió por estar fuera del alcance.**
- **`e2e-audit` escribe en la BD de E2E con `manager@acme.local`, que en realidad tiene rol ADMIN**: el check de 403 del script usa `tenants.tenant.edit` (sólo ADMIN), no el permiso retirado en el punto 2, por lo que el cambio de rol USER no afecta a ningún E2E.
- **Escrituras propias en `audit_logs` de la BD de trabajo, contadas**: 2 filas `POST /api/inventory/shipments/authorize` (`FAILURE`, 400 por falta de `Idempotency-Key`) de las dos comparaciones manuales de cabeceras contra Apache necesarias para reproducir el fallo de `ErrorDetails` con el servidor embebido (incluidas en el snapshot de 795), y después **795 → 809 = 12 del `--apply` + 2 `auth.login` (`SUCCESS`) de sesiones reales abiertas contra Apache, ajenas a este trabajo**. Ninguna otra escritura: ver *Reparación en vivo*.
- **`CHANGELOG.md` sigue figurando en `.gitignore`** y el repositorio **no tiene `.git`** (no es un repo git): el `.gitignore` corregido no surte efecto hasta que se inicialice. El hueco de "dejar de ignorar `/database`" queda resuelto en el fichero, no verificado con `git status`.
- **El servidor embebido se detiene al final de cada script, no en los `exit` tempranos**: si un E2E aborta antes, queda el proceso; lo recoge el siguiente arranque (reutiliza si responde y lo mata si está colgado) o `powershell tools\e2e\stop-e2e-server.ps1`. Recurso de mantenimiento de los E2E, no de la aplicación.
- Siguen abiertos los huecos declarados en **1.8.1/1.8.2** (`backups\` sin backups, instalación limpia sin seeders de roles `BUILT_IN`, cross-tenant 404, rutas de deliveries/returns sin módulo UI).

### Verified (2026-10-04)

- `php -l` en los **17 PHP tocados** (cálculo de stock, ledger, seeder de permisos, `LoadShipmentUseCase`/`Shipment`/`InventoryException`, `bootstrap.php`, `prepare-e2e-db.php`, los 2 guardas de `bin/`, `router.php` y los 5 tests): **17/17 OK**. `node --check` en `smoke-v162.mjs` y `smoke-v171.mjs`: exit 0.
- `composer test` → **exit 0, `Tests: 632, Assertions: 5187`** (2 `PHPUnit Deprecations` preexistentes, presentes también en tests no tocados).
- `php bin/check-schema-drift.php` → **`SIN DRIFT`, exit 0** (32 tablas, 374 columnas, 139 índices, 32 enums, 70 claves foráneas). Ningún `ALTER`/`CREATE` sobre la BD viva en este sprint.
- **E2E aislados: 7/7 exit 0** contra `xproject_e2e` con servidor PHP embebido (los 7, ninguno en `SKIP`), y `xproject_e2e` acumula sus propias filas (`stock_units 17`, `shipments 11`, `audit_logs 275`, `deliveries 6`, `reservations 19`) mientras la BD de trabajo queda con sus conteos de partida.
- Smoke jsdom: `smoke-v162.mjs` **89/89**, `smoke-v171.mjs` **105/105** (ningún fichero JS de la aplicación modificado en este sprint).
- **Tras el `--apply` de R1**: dry-run de revalidación en **0 diferencias** (exit 0), **0 filas** con `cantidad_disponible < 0`, `> cantidad_fisica` o `<> 0` fuera de `DISPONIBLE`, `composer test` **exit 0 (632 / 5187)** y `php bin/check-schema-drift.php` **`SIN DRIFT`, exit 0**.
- BD viva antes/después de la batería E2E: identidad y negocio sin cambios (ver desviación de las escrituras de `audit_logs`), y snapshot exacto de las 32 tablas idéntico entre la batería final de E2E y el cierre.

## [1.8.2] - 2026-10-04

Corrección de stock (sólo backend): **división de unidades** con `StockUnitSplitService`, capacidad que se libera al salir a la ubicación virtual y se reocupa al volver, eventos de los Sprints 5-8 con cantidades coherentes (cantidad de la unidad en `cantidad_anterior`/`cantidad_posterior`, reservado/disponible/en tránsito en `datos_operacion`), dos comandos CLI de reparación con dry-run y la reparación de la unidad "en limbo" sobre la BD viva.

### Added

- **`StockUnitSplitService`** (`src\Modules\Inventory\Application\Services\`): divide una unidad en dos si la cantidad pedida es **menor** que la de la unidad (`>=` → 422 `unitQuantityMismatch`). La **madre** conserva id y barcode y queda con la resta en la misma ubicación; la **hija** es fila nueva con barcode propio único en el tenant. Escribe ledger de ambas (la madre nunca deja `cantidad_disponible` negativo: `min(actual, nuevaFísica)` en reservada/en tránsito; la hija nace a 0 y con `disponible = cantidad`) y **un evento `DIVIDIDO` por mitad** con `hash_integridad` y el contexto del ledger antes/después. Transaccional reutilizando la transacción abierta si la hay (`DatabaseService::transaction` no es anidable) y **no escribe `audit_logs`** (en HTTP lo hace `AuditMiddleware`, en CLI tras el commit, patrón v1.6.2). **La capacidad de la ubicación no cambia**: dividir no altera la presencia física.
- **`ReserveStockUseCase` usa el servicio**: si `cantidad < unidad`, la reserva, el ledger y el evento recaen sobre la **hija** y la madre queda `DISPONIBLE` con el resto; la respuesta añade `data.division` (ids, barcodes, cantidades y los dos eventos con hash). La reserva igual a la unidad **no divide**. Inyectado en `InventoryServiceProvider`, `InventorySqliteTestCase` y `ReserveStockUseCaseTest`.
- **`bin/recalculate-capacity.php`**: recalcula `locations.capacidad_usada` como `SUM(CEIL(cantidad))` de las unidades presentes (mismo criterio con el que recepción/movimiento/picking/fallo/devolución suman slots; con cantidades enteras equivale a la suma de cantidades), con **0 en las ubicaciones virtuales (`TRANSITO`)**. **Dry-run por defecto** (lista diferencias), `--tenant=`, `--apply` para corregir con una fila de `audit_logs` por ubicación (post-commit) y exit 1 si algún valor esperado excede `capacidad` (se reporta y no se aplica, igual que `Location::setCapacidadUsada`).
- **`bin/fix-unit-limbo.php`**: repara la unidad `8dc0c59b-2281-4ae1-9b56-efed07573837` (10 unidades con una reserva/picking/envío de 5). **Dry-run por defecto** con el plan antes/después; `--apply` pide confirmación por consola (`SI`, o `--yes` para automatización). Precondiciones validadas (cantidad 10, barcode `SU-7PFDXZ-XYMATS7M`, reserva `7c88543c…` de 5), idempotente (si ya existe un evento `DIVIDIDO` sale con 0) y **todo en una transacción**: división 5+5 vía `StockUnitSplitService` (madre `EN_TRANSITO` en `T-E1-EA1`, hija `DISPONIBLE` en `A-E1-EA1` con +5 de capacidad validada), ajuste de `picking_operations` y `shipment_items` a 5, auditoría **post-commit** con ids, hashes y el **plan de reversión en SQL** (reversible por registro).
- **Tests nuevos (608 / 5129 assertions)**: `StockUnitSplitLifecycleTest` (picking/carga/autorización sólo sobre la hija, capacidad de la zona **5 tras iniciar picking y 0 tras autorizar**, madre intocable y aislamiento de tenants en la división) y en `ReserveStockUseCaseTest` el replay de una reserva parcial (no vuelve a dividir) y la atomicidad de la división ante un fallo al escribir el evento (no queda hija, ni reserva, ni idempotency key).

### Changed

- **`StockEvent`** gana `TIPO_DIVIDIDO` (y `TIPOS_VALIDOS`); **`StockLedger`** gana `valoresOperacion()` y `contextoOperacion($antes, $despues)` (claves del después + claves del antes con sufijo `_anterior`), que es lo que ahora guardan los eventos en `datos_operacion`.
- **Eventos de los Sprints 5-8 corregidos** (`ReceiveInventory`, `MoveStock`, `ReserveStock`, `ReleaseReservation`, `ReservationExpirationService`, `StartPicking`, `LoadShipment`, `AuthorizeShipment`, `ConfirmDelivery`, `RegisterDeliveryFailure`, `RegisterReturn`): `cantidad_anterior`/`cantidad_posterior` son **siempre la cantidad de la unidad** (antes había eventos `CARGADO 5→0` o `EN_TRANSITO 0→0` con `datos_operacion.cantidad: 10`), y reservado/disponible/en tránsito viajan en `datos_operacion` con los valores del ledger.
- **Capacidad liberada en el sitio correcto**: `StartPicking` ya no suma slots si el origen es la propia zona de preparación (capacidad fantasma), `LoadShipment` **ya no libera** (la unidad sigue físicamente en la zona hasta que sale) y **`AuthorizeShipment` libera el slot del origen físico** (`ceil(cantidad)`, saltando ubicaciones virtuales y de otros tenants); `RegisterDeliveryFailure` también ignora las virtuales al liberar. `ConfirmDelivery` y `RegisterReturn` ya reocupaban con validación.
- **`ReservationExpirationService` escribe la auditoría después de confirmar la transacción** (antes la escribía dentro, y `AuditLogger` abre la suya).
- **Sin cambios de esquema**: no se ejecutó ningún `ALTER`/`CREATE` sobre la BD viva (`php bin/check-schema-drift.php` → `SIN DRIFT`).

### Reparación en vivo (2026-10-04, decisión del usuario)

- `php bin/fix-unit-limbo.php --apply` → una sola transacción: madre `8dc0c59b…` **5 EN_TRANSITO** en `T-E1-EA1` (barcode `SU-7PFDXZ-XYMATS7M`, reserva `7c88543c…` COMPLETADA de 5 intacta, lote de picking `LOTE-20261003-LNPKSV`), hija `779c99cb-790e-4703-b3eb-fef05496ccba` (**barcode `SU-44KHK2-C796YJG6`, 5 DISPONIBLE en `A-E1-EA1`**), `picking_operations.92769143…` y `shipment_items.a7849ecc…` **10 → 5**, dos eventos `DIVIDIDO` con hash (`7a927bbe…` 10→5, `75c99239…` 0→5), `A-E1-EA1` `capacidad_usada` 0 → 5 y auditoría `CLI bin/fix-unit-limbo` con el plan de reversión.
- `php bin/recalculate-capacity.php` proponía **`P1-MAIN-TGU: 10 → 0`** (10 slots fantasma con 0 unidades); `--apply` lo dejó en 0 y una segunda pasada en dry-run devuelve **`sin diferencias`** (auditoría `CLI bin/recalculate-capacity`).
- No se borró ni truncó ningún dato: en el momento del fix el tenant pasó de 23 a 24 unidades (sólo se añadió la fila de la hija; las corridas E2E posteriores añaden las suyas), la BD sigue con 32 tablas y **ningún `stock_events` se queda sin `hash_integridad`** (252/252 tras las verificaciones).

### Desviaciones y huecos (declarados)

- **El lote citado en el plan (`LOTE-20261003-LNPKSV`) no es el lote de la unidad**: vive en `picking_operations.id_lote_picking`; `stock_units.lote` de la unidad es `DHL:192846502927496`. El script lo muestra en el plan y no lo modifica (los dos registros siguen ligados por `unidad_fisica_id`).
- **`P1-MAIN-TGU` está inactiva (`activa = 0`)** con `capacidad 10` y era el origen *y* la zona de preparación del picking de esa unidad (`ubicacion_origen_id = ubicacion_preparacion_id`), de ahí los 10 slots que nunca se liberaron. Corregido con `recalculate-capacity --apply`; **se dejó inactiva tal cual** (no es de este alcance decidir si se reactiva).
- **`LoadShipmentUseCase` dejó de liberar capacidad** y **`AuthorizeShipment` empezó a liberarla**: es un cambio de comportamiento deliberado (spec de v1.8.2), pero cualquier script externo que asumiera `capacidad_usada = 0` justo después de cargar verá ahora `0` sólo tras autorizar la salida. Los 7 E2E siguen en exit 0.
- Siguen abiertos los huecos declarados en **1.8.1** (instalación limpia sin seeders de roles `BUILT_IN`, `.gitignore` ignorando `/database`, `smoke-v161.mjs` obsoleto, `backups\` sin backups) y los heredados de 1.8.0 (`stock_ledger.cantidad_disponible` R1, cross-tenant 404, rutas de deliveries/returns sin módulo UI).
- Las ejecuciones E2E vuelven a crear filas propias en la BD viva (ubicaciones `AUD*`, unidades, `audit_logs`), efecto colateral ya declarado en 1.8.1.

### Verified (2026-10-04)

- `php -l` en los 38 PHP tocados (12 casos de uso de inventario, dominio, DI, harness y tests, más los 2 `bin/` nuevos): **0 fallos**.
- `composer test` → **`OK (608 tests, 5129 assertions)`**.
- `php bin/check-schema-drift.php` → **`SIN DRIFT`, exit 0** (32 tablas, 374 columnas, 139 índices, 32 enums, 70 claves foráneas).
- E2E en vivo: **7/7 exit 0** antes de la reparación y **7/7 exit 0** después (`e2e-audit`, `e2e-expire`, `e2e-sprint6`, `e2e-v162`, `e2e-v170`, `e2e-v171`, `e2e-v180`), sin ningún check en `SKIP`.
- Smoke jsdom: `smoke-v162.mjs` **89/89**, `smoke-v171.mjs` **105/105** (ningún fichero JS modificado en este sprint).
- Verificación end-to-end de la reparación contra la BD viva: consultas antes/después de `stock_units`, `stock_ledger`, `stock_events`, `reservations`, `picking_operations`, `shipment_items`, `locations` y `audit_logs`, más `recalculate-capacity.php` en dry-run sin diferencias.

## [1.8.1] - 2026-10-04

Mantenimiento (sin funcionalidad nueva): el DDL pasa a ser un fichero generado desde la base de datos viva con verificación de drift, limpieza del proyecto (dumps fuera de htdocs, E2E y smoke dentro de `tools\`), ajustes de los scripts de volcado y nuevas reglas en `AGENTS.md`. Ninguna línea de producto (PHP/JS del backend ni del frontend) se modificó.

### Changed

- **`database/schema/schema.sql` regenerado desde la BD viva** con `mysqldump --no-data --skip-comments --skip-dump-date`, sin `AUTO_INCREMENT=N` ni `DEFINER`, con las 32 tablas en orden de dependencias de claves foráneas: 42.992 bytes / 32 `CREATE TABLE` / 32 `DROP TABLE IF EXISTS`. El fichero anterior (26.792 bytes, 610 líneas) **no era SQL**: era una copia del script PowerShell de creación de tablas, así que `mysql < database/schema/schema.sql` nunca había podido ejecutarse; además le faltaban 5 tablas (`temporary_permissions`, `secrets_vault`, `user_groups`, `group_members`, `group_roles`) y traía enums desactualizados (entre ellos `incidents.tipo` sin `'OTRO'`). Ahora es el único DDL del proyecto y lleva cabecera `GENERADO DESDE LA BD VIVA: no editar a mano`.
- **Nuevo `bin/regenerate-schema.php`**: calcula el orden topológico de las tablas desde `information_schema.KEY_COLUMN_USAGE`, invoca `mysqldump --no-data --skip-comments --skip-dump-date` con ese orden (`MYSQLDUMP_BIN` opcional), elimina `AUTO_INCREMENT=N` y `DEFINER=...` y escribe `database/schema/schema.sql`. Falla con exit 1 si hay ciclo de claves foráneas o no se encuentra `mysqldump`.
- **Nuevo `bin/check-schema-drift.php`**: crea la BD temporal `xproject_schema_check`, carga `schema.sql` en ella y compara con la BD viva vía `information_schema` (tablas con motor y colación, columnas con tipo/nulabilidad/default/extra/juego de caracteres, índices con unicidad y columnas, enums y claves foráneas); elimina la BD temporal y sale con 0 (`SIN DRIFT`) o 1 con el detalle de cada diferencia. Avisa si `schema.sql` trae BOM UTF-8 (rompería `mysql <`).
- **`database/schema/create_tables.ps1` deja de llevar SQL**: ahora es un wrapper que lee `DB_*` de `.env` (parámetros `-DbName`, `-DbUser`, `-DbPassword`, `-MysqlPath` con prioridad) y ejecuta `schema.sql` con `cmd /c "... < schema.sql"` y la contraseña por `MYSQL_PWD`. Como `schema.sql` lleva `DROP TABLE IF EXISTS`, se niega a ejecutar contra la BD de `.env` salvo que se pase `-Force` (evita borrar los datos reales por un `.\create_tables.ps1` a secas). Antes eran 528 líneas de DDL embebido con credenciales hardcodeadas (`platform_user`/`Temp12345!`, ajenas a `.env`) y con `Get-Content | mysql`, que re-codificaba los caracteres no ASCII a la consola.
- **E2E y smoke pasan a formar parte del repo**: `e2e-audit`, `e2e-expire`, `e2e-sprint6`, `e2e-v162`, `e2e-v170`, `e2e-v171`, `e2e-v180` (`.ps1`) y `verify-tenant-transit.php` → `tools\e2e\`; `smoke-v161`, `smoke-v162`, `smoke-v171` (`.mjs`) con `package.json`, `package-lock.json` y `node_modules` (jsdom 30) → `tools\smoke\`. Referencias actualizadas: `e2e-v171.ps1` dejó de apuntar a `%TEMP%\opencode\verify-tenant-transit.php` (ahora `Join-Path $PSScriptRoot`), la regla 8 de `AGENTS.md` y la ruta del E2E en la entrada 1.8.0 de este fichero.
- **`script2.ps1` (backup de BD)**: destino `C:\xampp\backups\xp` (fuera de htdocs), credenciales y host/puerto tomados de `.env` y `mysqldump --result-file` en lugar de `> $Dump`. La redirección de PowerShell 5.1 escribía el volcado en UTF-16LE (BOM `FF FE`), por lo que los dumps antiguos no se podían restaurar con `mysql < fichero.sql`.
- **`script.ps1` (bundle de código fuente)**: ya no empaqueta `.env` (estaba en la lista de extensiones), ningún `.sql` ni nada de `backups\`; añade `backups` y `\.phpunit\.cache` al filtro de rutas y `.mjs` a las extensiones. Selección comprobada: 264 ficheros, 0 `.env`, 0 `.sql`, 0 de `backups\`, 0 de caché, y los 10 de `tools\` incluidos.
- **`AGENTS.md`**: regla 5 reescrita (la BD viva es la fuente de verdad; tras cualquier `ALTER` en vivo regenerar con `php bin/regenerate-schema.php` y ejecutar `php bin/check-schema-drift.php` antes de cerrar el sprint con `SIN DRIFT`; `create_tables.ps1` sólo ejecuta `schema.sql`; nunca editar `schema.sql` a mano; se corrigen además las rutas, que apuntaban a `database/schema.sql` y `scripts/create_tables.ps1`), regla 7 con el chequeo de drift y `tools\e2e\`, regla 8 con `node tools\smoke\smoke-v162.mjs` y regla 9 nueva: verificar tablas, columnas, tipos, índices, enums y datos consultando siempre la BD viva, nunca el fichero.
- **`tools\e2e\*.ps1`: credenciales unificadas con el entorno vivo** (cambio externo, ver desviaciones): `demo@acme.local` → `aleon@acme.local` (ADMIN) como cuenta principal y **todas las cuentas pasan a `Admin123!`** (`manager@acme.local`, hoy con rol ADMIN, y `user@acme.local`, rol USER, dejaron de aceptar `Manager123!` y `User123!`). El segundo actor sigue siendo una **cuenta distinta** (`manager@acme.local`): `StartPickingUseCase` exige que el supervisor del lote sea distinto del selector (422 `pickingSupervisorMustDiffer`), así que no valía apuntar ambos tokens a la misma cuenta. Las etiquetas de salida `MANAGER` pasan a `2do actor`. En `e2e-audit.ps1` el check de 403 de `PATCH /tenant` se ejecuta con `user@acme.local` (única cuenta sin `tenants.tenant.edit`, que sólo tiene ADMIN) y con un body sin datos válidos, de modo que ni siquiera un fallo futuro del permiso llegaría a renombrar el tenant en vivo.
- **Fixtures de los 7 E2E adaptados al dataset vivo** (decisión del usuario, 2026-10-04): el SKU de referencia pasa de `PROD2000` a `PROD-001` y las ubicaciones dejan de estar codificadas en duro (`HOUSEA23`, `312-EDIT`, `A010210`, `AUD20261001195909`) para resolverse en tiempo de ejecución desde `GET /inventory/locations?tipo=ALMACENAMIENTO&activa=1`, prefiriendo la que no tiene capacidad tope y excluyendo las creadas por el propio script. `e2e-v180` relaja su filtro de origen (`capacidad IS NULL` → cualquier ALMACENAMIENTO activa, ordenando primero las sin tope), `FindLocation` de `e2e-v170` filtra `activa=1` (antes podía elegir `P1-MAIN-TGU`, inactiva, y `picking/start` devolvía 422), `e2e-sprint6` crea dos ubicaciones propias de código estable (`S6DEST` sin tope y `S6LLENO` con capacidad 1 rellenada, para seguir cubriendo el 422 de destino sin capacidad) y `e2e-v162`/`e2e-expire` recepcionan su propia unidad cuando no hay ninguna `DISPONIBLE`. No se tocó ningún fixture de `tests/` ni de `tools\smoke\`.

### Removed

- **`backups\backup_xproject_20260929_192742.sql`** (142.288 bytes) movido a `C:\xampp\backups\xp\`: un dump de BD no debe quedar publicable bajo el servidor web. `backups\` queda sólo con `INVENTORY_MODULE_SPECIFICATION.md`, `PLATFORM_CORE_SPECIFICATION.md` y `roadmap.md` (protegidos, no se tocan).
- **El SQL embebido de `create_tables.ps1`** (528 líneas con DDL desactualizado) y **el contenido del antiguo `schema.sql`** (copia de un `.ps1`), sustituidos por el volcado generado desde la BD viva.
- **`.env` y `.sql` de la lista de extensiones de `script.ps1`**, más `backups` y `\.phpunit\.cache` de su filtro de rutas.
- **No se borró ningún fichero del proyecto**: la búsqueda de referencias (todas las clases PHP de `src/`, `bin/`, `database/` y todos los `.js` de `public/`) no encontró código sin referencias, y los directorios vacíos planificados (`config\`, `scripts\sql\`, `database\migrations\`, `storage\{cache,logs,encrypted}\`, `tests\{Feature,Integration}\`) se conservan por diseño.

### Desviaciones y huecos (declarados)

- **El entorno vivo cambió por causas ajenas a este sprint (incidente, 2026-10-03 15:07 → 2026-10-04)**: el usuario `demo@acme.local` dejó de existir en `users` (borrado físico), `manager@acme.local` y `user@acme.local` ya no aceptan su contraseña documentada, el producto `PROD2000` se sustituyó por `PROD-001`, se perdió un segundo tenant (2 → 1) y desaparecieron las filas de `deliveries`/`return_operations`/unidades de las últimas ejecuciones E2E. Sólo queda `aleon@acme.local` (ADMIN, alta 2026-10-01) con contraseña `Admin123!`. Evidencia: `audit_logs` sólo registra actividad hasta el 2026-10-03 15:07:16 y, a partir del 2026-10-04 07:25, únicamente mis propias comprobaciones de login; ningún código del proyecto borra `users` (no hay ningún `DELETE FROM users` ni cascade: `users` no tiene FK hacia `tenants`), y todo lo ejecutado en este sprint contra BD se hizo en temporales (`xproject_schema_check`, `xproject_install_check`, `xproject_wrapper_check`, `xproject_guard_check`, todas dropeadas). Resolución (decisión del usuario, 2026-10-04): `demo@acme.local` **no se repuebla**; la cuenta principal pasa a `aleon@acme.local`, **todas las cuentas usan `Admin123!`** y los fixtures de los 7 E2E se adaptaron al dataset vivo (ver Changed). Los 7 E2E vuelven a terminar en exit 0 y no queda ningún check en `SKIP`. Efectos colaterales de las ejecuciones del 2026-10-04, creados por los propios scripts: ubicaciones nuevas `DANADOS` (seeder `bin/seed-transit-locations.php`), `PICK-E2E`, `AUD20261004085739`, `S6DEST` y `S6LLENO`; productos `AUD-*` (borrados en su propia pasada, `deleted_at`); unidades y filas nuevas en `stock_units`, `stock_ledger`, `audit_logs`, `deliveries` y `return_operations`. **No se modificó ni borró ningún dato preexistente**: el tenant sigue siendo `Acme Corp` y su nombre no cambió en ninguna de las comprobaciones de 403.
- **La instalación limpia NO funciona sólo con `schema.sql` + seeders (HUECO)**: en una BD recién creada, `schema.sql` crea las 32 tablas (exit 0) pero `PlatformSeeder` aborta con `El rol BUILT_IN ADMIN no existe. Verifica el dump inicial.` porque **ningún seeder crea los 4 roles `BUILT_IN`**: `InventoryPermissionSeeder` y `AuditPermissionSeeder` sólo hacen `UPDATE roles SET permisos = ...` sobre roles que ya existen. El "dump inicial" que menciona el seeder no está en el repo (sólo como datos dentro de un volcado completo de BD). Verificado en vivo con `xproject_install_check`: tablas 32, `permissions`/`roles`/`tenants`/`users` = 0, seeder exit 1. Crear ese seeder tocaría permisos y BD → fuera de alcance (reglas 2 y 4); **queda pendiente de decisión**.
- **`backups\backup_xproject_20260929_192742.sql` no es restaurable**: UTF-16LE por la redirección antigua de `script2.ps1`. Se conserva como histórico, no se borra.
- **`.gitignore` ignora `/database`**, con lo que `database/schema/schema.sql` (el DDL canónico del proyecto) quedaría fuera del versionado; tampoco ignora `node_modules` (hoy `tools\smoke\node_modules`, 21,8 MB / 1.868 ficheros); además ignora `script.ps1`, `script2.ps1`, `CHANGELOG.md` y `/backups` (donde viven las 3 especificaciones). No se tocó por estar fuera de las secciones encargadas.
- **`tools\smoke\smoke-v161.mjs` es obsoleto y falla** contra el código actual (espera `GET /inventory/stock?estado=RESERVADO`, pantalla migrada a `GET /inventory/reservations` en v1.6.2); lo cubren `smoke-v162` (89/89) y `smoke-v171` (105/105). No se borró por ser una verificación; **pendiente de decisión**.
- **`backups\` ya no contiene backups** (sólo 3 `.md`): nombre engañoso, se mantiene por estar referenciado en las especificaciones y el roadmap. **Pendiente de decisión**.
- **Drift de esquema declarado en v1.8.0 resuelto**: `incidents.tipo` con los 8 valores y el resto del esquema coinciden con la BD viva tras regenerar el fichero; **no se ejecutó ningún `ALTER` sobre la BD viva** en este sprint.
- Sin tocar (heredados de v1.8.0): `USER` con `inventory.delivery.confirm`/`inventory.return.create`, rutas `#/inventory/deliveries` y `#/inventory/returns` sin módulo, `stock_ledger.cantidad_disponible` (R1) y cross-tenant 404.

### Verified (2026-10-03/04)

- `php -l` sin errores en los PHP tocados (`bin/regenerate-schema.php`, `bin/check-schema-drift.php`, `tools\e2e\verify-tenant-transit.php`, `database\seeders\PlatformSeeder.php`, `bin/seed-transit-locations.php`, `bin/expire-reservations.php`).
- `composer test` en verde: **602 tests, 4994 assertions** (idéntico a v1.8.0: ninguna línea de producto tocada).
- `php bin/check-schema-drift.php` → **`SIN DRIFT`, exit 0** (32 tablas, 374 columnas, 139 índices, 32 enums, 70 claves foráneas). Comprobación negativa: editando `schema.sql` a mano para quitar `'OTRO'` devuelve 10 diferencias y exit 1.
- En vivo (Apache/MySQL): login y `GET /api/inventory/products?page=1&per_page=3` → **HTTP 200** (2026-10-03 con `demo@acme.local` y producto `PROD2000`; re-validado el 2026-10-04 con `aleon@acme.local`/ADMIN y producto `PROD-001`, ver desviaciones).
- E2E desde su nueva ubicación (2026-10-03, con el dataset anterior): `tools\e2e\e2e-v180.ps1` → **`E2E v1.8.0: TODO OK` (exit 0)** y `tools\e2e\e2e-v171.ps1` → **`E2E v1.7.1: TODO OK` (exit 0)** (este último con la referencia a `verify-tenant-transit.php` ya corregida).
- Re-ejecución completa de los 7 E2E el 2026-10-04 tras unificar credenciales y adaptar fixtures: **los 7 en exit 0** — `e2e-v180.ps1` → **`E2E v1.8.0: TODO OK`**, `e2e-v171.ps1` → **`E2E v1.7.1: TODO OK`**, `e2e-v170.ps1` → **`E2E v1.7.0: TODO OK`**, y `e2e-audit`, `e2e-v162`, `e2e-sprint6` y `e2e-expire` sin fallos (incluye el 403 de `PATCH /tenant` con la cuenta USER y `GET /audit/logs` 200 con `manager@acme.local`; ningún check en `SKIP`).
- Smoke jsdom desde `tools\smoke\`: `node --check` limpio en los 3, `smoke-v162.mjs` → **89/89 OK**, `smoke-v171.mjs` → **105/105 OK** (`smoke-v161.mjs` falla, ver huecos).
- DDL instalable: `create_tables.ps1` sobre la BD temporal → **32 tablas, exit 0**, con los 32 `COLUMN_TYPE` de enum idénticos (MD5) a los de la BD viva, y **exit 1 sin `-DbName`** (no toca la BD de `.env`). `script2.ps1` → dump UTF-8 de 697.924 bytes que restaura **32 tablas** en una BD vacía.

## [1.8.0] - 2026-10-03

Sprint 8: **entregas y devoluciones (CU-007, CU-008, CU-009), sólo backend** (el prompt descarta UI). Entidades y repositorios `deliveries`/`return_operations`, tres casos de uso con transacción + idempotencia + auditoría + UTC, dos consultas paginadas, cinco endpoints, dos permisos nuevos, siembra de la zona `DAÑADOS`, validación de `hora_entrega_esperada` (tarea 0), tests y E2E vivo.

### Added

- **Dominio CU-007/008/009**: entidades `Delivery` (estados `PENDIENTE/ENTREGADA/RECHAZADA/PARCIAL`, `create()` con destinatario/firma/observaciones/actor y `timestamp_entrega` UTC) y `ReturnOperation` (motivos `CAMBIO_CLIENTE/DAÑO/FALLO_ENTREGA/NO_APLICA/OTRO`, estados al devolver `OK/DAÑADO/DETERIORADO/PERDIDO`), interfaces `DeliveryRepositoryInterface`/`ReturnOperationRepositoryInterface` y adaptadores PDO `Adapters\Persistence\DeliveryRepository`/`ReturnOperationRepository` (tenant en todo `WHERE`, fallback `rowCount` 0 con `COUNT`). `Shipment` gana `marcarEntregado()`/`marcarFalloEntrega()` (fijan `hora_entrega_real`) y `ShipmentRepository::update()` persiste esa columna.
- **CU-007 - `ConfirmDeliveryUseCase`** (`POST /inventory/deliveries/confirm`, **200**): exige actor (`actor_id_entrega`), `Idempotency-Key`, envío `EN_RUTA` (409) y `unidades` no vacías (422 `deliveryUnitsRequired`, cuyo mensaje remite a `/failed` para el fallo total). Por unidad valida pertenencia al envío (422), ítem y unidad en `EN_TRANSITO` (422) y cantidad exacta (422 `deliveryQuantityMismatch`, sin divisiones). Escribe una fila `deliveries` `ENTREGADA` por unidad, ítem `ENTREGADA`, unidad `ENTREGADO` con **`ubicacion_id = null`** (sale del almacén), ledger `en_transito -c` (física y reservada intactas), liberación de slots del origen (**ignorando ubicaciones virtuales**) y evento `ENTREGADO` con hash de integridad (R2). Las unidades **no** indicadas se procesan con `RegisterDeliveryFailureUseCase` **en la misma transacción** (mixto: envío `ENTREGADO` + `no_entregadas` + incidencia) y al final `shipments` → `ENTREGADO` con `hora_entrega_real` UTC. Rollback total ante cualquier error (R5) y replay idempotente sin segunda fila de auditoría (R7).
- **CU-008 - `RegisterDeliveryFailureUseCase`** (`POST /inventory/deliveries/failed`, **200**): `motivo` obligatorio (`NO_DISPONIBLE|RECHAZO|ACCESO_DENEGADO|OTRO`). Las unidades del envío que siguen `EN_TRANSITO` pasan a ítem `RECHAZADA`, unidad `DEVUELTO` reubicada al **origen del envío** (`shipments.origen_ubicacion_id`; si está vacío, `ubicacion_destino_id` del body; si tampoco, 422 `deliveryReturnLocationMissing`), fila `deliveries` `RECHAZADA`, **una** incidencia `ENTREGA_RECHAZADA` por operación (con el motivo en `datos_operacion` del evento), ledger `en_transito -c` y evento `DEVUELTO` con hash; destino virtual o envío ya sin unidades en tránsito → 422/409. `shipments` → `FALLO_ENTREGA`.
- **CU-009 - `RegisterReturnUseCase`** (`POST /inventory/returns/register`, **201**): unidad `DEVUELTO` o `ENTREGADO` (422 `unitNotReturnable` en cualquier otro estado), `motivo` y `estado_al_devolver` obligatorios y validados contra catálogo (400 con `field`). Destino: `OK` → `ubicacion_destino_id` si se indica; si no, la ubicación actual de la unidad y, si no la tiene (p. ej. `ENTREGADO`), la de origen del último envío; sin ninguna → 400 `ubicacion_destino_id` (además: ubicación inactiva, de otro tenant o virtual → error); `DAÑADO`/`DETERIORADO` → **zona `DAÑADOS`** del tenant (`ubicacion_destino_id` se ignora); `PERDIDO` → sin ubicación. Escribe `return_operations`, unidad `DISPONIBLE|DAÑADO|DETERIORADO|PERDIDO`, incidencia `DAÑO`/`PERDIDA`, ledger **sólo** `estado_actual` (cantidades intactas, R1) y evento `DISPONIBLE|DAÑADO|PERDIDO` con hash. Valida la capacidad del destino (422 `capacidad`) y enlaza automáticamente la `delivery` de la unidad (`delivery_id` opcional en el body).
- **Consultas paginadas** (PDO directo, tenant desde los claims): `DeliveryQueryService::list()` con filtros `estado`, `fecha_desde/hasta`, `shipment_id`, `unidad_fisica_id`, `summary{total,entregadas,rechazadas,pendientes,parciales}` y `per_page` 1-100; `ReturnQueryService::list()` con `estado` (=`estado_al_devolver`), `motivo`, fechas, `shipment_id` (vía `deliveries` → `shipments`) y `unidad_fisica_id`, `summary{total,ok,danadas,deterioradas,perdidas}`. Estado desconocido o fecha no parseable → **400 con `field`**.
- **5 endpoints nuevos** en `src/Modules/Inventory/routes.php` (Auth + Tenant + `Permission` + `RateLimit:api`); los tres POST exigen `Idempotency-Key` (400 con `field: idempotency-key` si falta):

  | Método | Ruta | Permiso |
  |---|---|---|
  | GET | `/api/inventory/deliveries` | `inventory.delivery.view` |
  | POST | `/api/inventory/deliveries/confirm` | `inventory.delivery.confirm` |
  | POST | `/api/inventory/deliveries/failed` | `inventory.delivery.confirm` |
  | GET | `/api/inventory/returns` | `inventory.return.view` |
  | POST | `/api/inventory/returns/register` | `inventory.return.create` |

- **Capa HTTP**: `DeliveryController` (index/confirm/failed) y `ReturnController` (index/register) con los traits `ExtractsIdempotencyKey` y `ReturnsUseCaseResult`. Cada escritura con éxito escribe **una fila de auditoría de dominio después del commit** (`delivery.confirm`, `delivery.failed`, `return.register`; recurso `delivery`/`return`, con clave, `request_id` y código de respuesta en `detalles_adicionales`); un replay no escribe fila nueva ni en el controlador ni en `AuditMiddleware` → **2 filas por clave** (1 dominio + 1 HTTP), verificado en vivo. Los errores los audita `AuditMiddleware`.
- **2 permisos nuevos** en `InventoryPermissionSeeder` (ahora **29** `inventory.*`): `inventory.delivery.view` y `inventory.return.view` (MEDIUM, acción `view`), asignados a los 4 roles BUILT_IN con criterio `*.view`; ejecutado en la BD viva (1ª pasada `created=2 roles_updated=4`, 2ª pasada `created=0`, idempotente). Inventario por rol en BD viva: ADMIN 40 / MANAGER 26 / USER 16 / VIEWER 8 totales (`inventory.*`: 29/25/16/8).
- **Zona `DAÑADOS` por tenant** (spec 3.17): `TransitLocationService::ensureDamagedZoneForTenant()` idempotente, siembra dentro de la transacción de `TenantService::create()`/`createWithOwner()` y CLI `bin/seed-transit-locations.php` extendida (ejecutada en la BD viva: 1 creada, 1 ya existente, 2 tenants).
- **Tarea 0 - `hora_entrega_esperada`**: `LoadShipmentUseCase::normalizeExpectedDeliveryTime()` valida el formato (`Y-m-d[\T]H:i[:s][P]`, `Y-m-d H:i[:s]`, `Y-m-d`, con prefijo `!` y rechazo de días desbordados) y normaliza a `Y-m-d H:i:s` **UTC**; inválida → **400 con `field: hora_entrega_esperada`**, más de 40 caracteres → 400 `fieldMaxLength` (el docblock del método, que decía 422, se corrigió).
- **Tests (+71, total 602 tests / 4994 assertions)**: `ConfirmDeliveryUseCaseTest` (14/120: entrega completa, replay, mixto con CU-008, 404/409/422/400, unidades duplicadas y rollback R5 verificando que ninguna unidad queda `ENTREGADA`), `RegisterDeliveryFailureUseCaseTest` (13/92: fallo total, destino explícito con +3 slots, parcial → envío `EN_RUTA`, 404/409/400/422, replay y destino virtual), `RegisterReturnUseCaseTest` (16/129: `OK` con destino actual/explicito, retorno desde `ENTREGADO` con dos eventos y ítem `DEVUELTA`, `DAÑADO`/`DETERIORADO` → zona `DAÑADOS` con incidencia, `PERDIDO`, replay, 400/404/422 y zona inactiva), `DeliveryReturnQueryTest` (15/69: campos, paginación, tope 100, todos los filtros, `summary`, 400 con `field` y aislamiento de tenant), `DeliveryReturnRoutesTest` (5/64: las 5 rutas con su permiso, middlewares únicos, 403 sin permiso y paso con permiso vía `AuthorizationService`), `LoadShipmentUseCaseTest` +5 (17/102: normalización a UTC, con offset, inválida, día desbordado y longitud >40), `InventoryPermissionSeederTest` actualizado (11/493: 29 permisos, docblock, aserciones de los 4 roles y bloque Sprint 8) y `TransitLocationServiceTest` ampliado (11/59: zona `DAÑADOS` por tenant).
- **Correcciones detectadas por los tests nuevos**: `RegisterReturnUseCase` usaba la constante inexistente `ReturnOperation::ESTADO_DANO` (rompía todas las devoluciones) → `ESTADO_DANADO`; `mb_strtoupper` en el filtro `estado`/`motivo` de `DeliveryQueryService` y `ReturnQueryService` (los literales con `Ñ` no matcheaban con `strtoupper`).

### Desviaciones y huecos (declarados)

- **`database/schema/schema.sql` y `create_tables.ps1` siguen sin `'OTRO'` en `incidents.tipo`** mientras la BD viva sí lo incluye (enum vivo de 8 valores frente a los 7 del script). Preexistente; no se tocaron los scripts de esquema en este sprint (única escritura en BD viva: permisos y zona `DAÑADOS`, ambas idempotentes). **Pendiente de decidir**.
- **`stock_ledger.cantidad_disponible` (R1)** se recalcula como `física - reservada - tránsito`: una unidad `ENTREGADO` (o `DAÑADO`/`PERDIDO`) conserva disponible > 0 aunque ya no esté en el almacén, porque la fórmula no contempla esos estados. Las cantidades no se manipulan (R1 intacto) por decisión.
- **Unidad `ENTREGADO` con `ubicacion_id = null`** y ubicaciones virtuales sin contador: `TRANSITO` no consume capacidad (tampoco la consume la autorización) y `releaseOriginSlot()` las ignora; invariante documentado en el código.
- **CU-007 no acepta `ubicacion_destino_id`**: las unidades no entregadas vuelven al `shipments.origen_ubicacion_id`. Ese campo lo puebla (o no) el cliente en `POST /shipments/load`; si un envío antiguo lo trae vacío, el confirm mixto y el fallo total devuelven 422 `deliveryReturnLocationMissing` (la spec no dice cómo se rellena el origen). Los tests y el E2E lo envían siempre.
- **Cross-tenant responde 404, no 403**: `shipments`, `locations`, `stock_units`, `deliveries` y `return_operations` se consultan con filtro de tenant, así que un id ajeno responde 404 (no se expone su existencia). Criterio heredado de sprints anteriores.
- **`deliveries.estado` sólo genera `ENTREGADA` y `RECHAZADA`**: `PENDIENTE` y `PARCIAL` existen en el enum y en el `summary` pero ninguna escritura las produce hoy (el caso mixto deja el envío `ENTREGADO` con deliveries `RECHAZADA`).
- **CU-008 crea UNA incidencia por operación con `unidad_fisica_id = NULL`**: el motivo concreto va en `datos_operacion` del evento (`deliveries` no tiene columna de motivo); qué unidades fallaron se deduce de las `deliveries` `RECHAZADA` del envío.
- **CU-009 enlaza sólo la `delivery` de la última unidad** (`findLatestByUnidad`) y no recibe `shipment_id`; `delivery_id` es opcional en el body. Decisión de diseño confirmada en el E2E.
- **`DAÑADO`/`DETERIORADO` ignoran `ubicacion_destino_id`** (siempre la zona `DAÑADOS`, sembrada **sin capacidad**, es decir, sin límite); `PERDIDO` deja la unidad sin ubicación.
- **USER conserva `inventory.delivery.confirm` e `inventory.return.create`**: el mapa histórico del seeder ya los incluía para USER (junto con identificadores que aún no existen como permiso: `delivery.reject`, `return.confirm`, `incident.report/resolve`, `stock.adjust`, `receiving.confirm`); al crear esos dos permisos en este sprint la asignación se vuelve efectiva y contradice el criterio shipping pedido para el Sprint 8 (escritura → ADMIN/MANAGER). No se corrigió por la regla 4 (seeder aditivo); **queda pendiente de decisión**. `InventoryPermissionSeederTest` lo documenta.
- **Verificación de formato de `hora_entrega_esperada`**: el error es **400** (`invalidField`/`fieldMaxLength`), no 422 como decía el docblock del método (docblock corregido en este sprint para reflejar el código real).
- **Sin frontend**: `#/inventory/deliveries` y `#/inventory/returns` siguen declaradas en `router.js` **sin** módulo en `loader.js` (hueco heredado, fuera del alcance del prompt). Por lo mismo no procede la verificación JS (`node --check` + smoke jsdom): no se tocó ningún fichero JS.
- **Sin cambios de esquema**: `deliveries` y `return_operations` ya existían (spec 3.15/3.16); `schema.sql` y `create_tables.ps1` no se modificaron.

### Verified (2026-10-03)

- `php -l` sin errores en todos los PHP tocados (módulo `Inventory`, seeder de permisos, CLI de siembra y tests).
- `composer test` en verde: **602 tests, 4994 assertions** (+71 sobre v1.7.1; los 6 ficheros nuevos/actualizados del sprint aportan 74 tests con 967 assertions).
- E2E vivo (`tools\e2e\e2e-v180.ps1`, antes `%TEMP%\opencode\e2e-v180.ps1`, Apache/MySQL, exit 0): **`E2E v1.8.0: TODO OK`**, sin líneas `FALLO`. Cubre: siembra de los 5 permisos y sus roles en BD viva, los 5 endpoints nuevos, `Idempotency-Key` obligatoria (400) y filtros inválidos (400 con `field`); **flujo A** entrega completa (200, envío `ENTREGADO` con `hora_entrega_real`, `hora_entrega_esperada` normalizada a `2026-10-05 12:30:00`, unidad `ENTREGADO` sin ubicación, ledger sin tránsito, evento `ENTREGADO` y 6 eventos con hash de 64 hex) **más su replay** (cuerpo idéntico, sin `_replay` en el JSON, 2 filas de auditoría y 1 sola fila `deliveries`); **flujo B** fallo total (200, envío `FALLO_ENTREGA`, unidad `DEVUELTO` en el origen, incidencia `ENTREGA_RECHAZADA` en el destino, evento `DEVUELTO`) + devolución `OK` (201, unidad `DISPONIBLE` en el destino, `return_operations` enlazando la delivery `RECHAZADA`, evento `DISPONIBLE`, replay igual) y **flujo C** entrega completa + devolución `DAÑADO` (201, unidad `DAÑADO` en la zona `DAÑADOS`, incidencia `DAÑO`, evento `DAÑADO`, envío sigue `ENTREGADO`). Además: paginación y tope `per_page=100`, cero filas de auditoría duplicadas por clave+operación y las tres unidades en su estado final esperado.

## [1.7.1] - 2026-10-03

Mini-sprint de cierre del Sprint 7: los tres huecos de backend declarados en v1.7.0 (A1–A3) y su UI (B5–B8). Backend + frontend.

### Added

- **A1 — Ubicación virtual `TRANSITO` sembrada por tenant** (spec CU-006 §3.13): nuevo servicio `TransitLocationService::ensureForTenant()` (idempotente). Busca la primera ubicación `TRANSITO` activa del tenant; si no existe la crea con `codigo = TRANSITO` cuando está libre y `TRANSITO-<8>` (prefijo del tenant) cuando no (`locations.codigo` es UNIQUE **global**, por eso un código ocupado en otro tenant no impide el alta); si existía inactiva la reactiva (`activa = 1`, con `updated_at` parametrizado). Siembra **dentro de la transacción** de `TenantService::create()` y `createWithOwner()` (rollback completo ante fallo, mensaje `TenantException::transitLocationSeedFailed()`), binding en `InventoryServiceProvider` y CLI nueva `bin/seed-transit-locations.php` para dar de alta los tenants ya existentes.
- **A2 — Reserva consumida al iniciar el picking** (cierra el riesgo residual E7 de v1.7.0): paso 12 de `StartPickingUseCase` — la reserva `ACTIVA` de cada unidad pasa a estado final `COMPLETADA` (`Reservation::completar()`) en la misma transacción que mueve la unidad `RESERVADO → EN_PREPARACION`; sin reserva activa no hay nada que consumir. `ReservationExpirationService` queda **tolerante**: si la reserva ya no está `ACTIVA` porque el picking la consumió, no falla el lote de expiración, devuelve `resultado = OMITIDA` y lo audita (constantes nuevas `RESULTADO_OMITIDA`/`RESULTADO_CANCELADA`, se auditan ambas) — el CLI lo informa en la salida.
- **A3 — Replay de idempotencia sin segunda fila de auditoría**: los **9** use cases con `Idempotency-Key` (`receive`, `reservations` create/release, `move`, `picking` start/complete/verify, `shipment` load/authorize) devuelven la respuesta de replay con el marcador `_replay => true` a través del trait nuevo `Http\Concerns\ReturnsUseCaseResult`; los controllers reenvían la respuesta **sin** escribir su fila de auditoría de dominio, `AuditMiddleware` retorna temprano cuando el handler entrega ese marcador y `Router::sendJsonResponse()` lo retira del `body` antes de responder. Queda el patrón "una fila de auditoría por operación y clave" (declarado como hueco en v1.7.0).
- **B5 — `public/js/modules/inventory/picking.js` (nuevo)**: sección *Nuevo lote* (select de zonas `tipo=PICKING&activa=1` filtradas además por `activa` en cliente, campo de lote con botón Generar, referencia de orden, **escaneo por Enter** que selecciona la reserva cuyo `barcode` coincide y avisa si no está en las visibles), selección múltiple de reservas sobre `GET /inventory/reservations?estado=ACTIVA` (checkboxes, *seleccionar todas*/*limpiar*, contador, hint de `Idempotency-Key`), agrupación por lote con estado derivado, acciones **Completar** (`EN_CURSO` + `inventory.picking.confirm`) y **Verificar** (`COMPLETADA` + `inventory.picking.verify`) con despliegue de unidades, filtros `estado`/`id_lote_picking`, paginación, tarjeta de resumen, vista *Sin permiso* sin `inventory.picking.view`, `handleActionError` con switch de status (400/401/403/404/409/422) y errores en línea, claves idempotentes `loteKeys`/`startKey` renovadas en éxito y 409, e `init`/`onActivate`/`destroy`.
- **B6 — `public/js/modules/inventory/shipments.js` (nuevo)**: alta de envío (tipo `VEHICULO|MENSAJERIA|PROPIO|TERCERO`, origen, referencia externa, destinatario, conductor y contacto, vehículo, hora de entrega y observaciones) con **dos modos de origen**: *Por lote* (lotes `COMPLETADA|VERIFICADA` derivados de `GET /inventory/picking` intersectados con el stock `EN_PREPARACION`) y *Selección manual* (tabla de unidades `EN_PREPARACION` con checkboxes y contador). Validación cliente (422 antes de llamar si falta tipo, destinatario o unidades) → `POST /inventory/shipments/load` con `Idempotency-Key`. Listado con filtros, resumen y paginación; modal de detalle (`GET /inventory/shipments/{id}` con sus ítems) y acción **Autorizar salida** con `global.confirm()` → `POST /inventory/shipments/authorize` + clave por `shipment_id`. Catálogos (tipos, ubicaciones, zonas de preparación) se cargan con `.catch` propio; permisos `inventory.shipping.view|create|confirm`.
- **B7 — Módulos registrados y menú con permisos**: `core/loader.js` registra `inventory/picking` y `inventory/shipments` (con lo que `router.isModuleAvailable()` deja de devolver *Módulo no disponible* para ambas rutas) y `dashboard/sidebar.js` añade los iconos `checkList`/`truck` con los ítems **Picking** y **Envíos**, gated por `inventory.picking.view`/`inventory.shipping.view`. Ambos módulos resuelven permisos con `AuthState.getPermissions()`, que parsea el `roles[].permisos` en JSON, de modo que la fuente de verdad es el permiso y no el nombre del rol.
- **Verificación (B8)**: `node --check` en los 4 JS tocados, smoke jsdom nuevo (`smoke-v171.mjs`: reservas, escaneo, lote, completar/verificar, permisos, envíos, detalle/autorizar, router/loader/sidebar) y E2E vivo (`e2e-v171.ps1`).

### Fixed

- **`SQLSTATE[HY093] Invalid parameter number` al crear la ubicación `TRANSITO`**: el `INSERT` de `TransitLocationService` repetía el mismo placeholder (`:now`) en dos columnas y los prepares nativos de MySQL lo rechazan; sustituido por parámetros distintos (`:created_at`/`:updated_at`) y cubierto por `TransitLocationServiceTest::testNingunaSentenciaRepiteUnPlaceholder()` (test estático sobre todas las sentencias del servicio).
- **`use ...ReturnsUseCaseResult` ausente** en `InventoryServiceProvider` (error fatal al cargar el contenedor al registrar los casos afectados por A3): import añadido junto con el trait.

### Desviaciones y huecos (declarados)

- **Sin cambios de esquema ni de BD en este mini-sprint** (reglas 2/5): `database/schema/schema.sql` y `database/schema/create_tables.ps1` no se tocaron; la siembra de `TRANSITO` es runtime y se ejecutó con la CLI.
- **Los lotes se agrupan en el cliente**: no existe `GET /inventory/picking/{lote}` ni un listado de lotes; la UI pide las operaciones (`per_page=100`) y agrupa por `id_lote_picking`. Con más de 100 operaciones, la paginación corta la vista de lotes (pagina filas, no lotes). Tampoco hay navegación a un detalle de lote.
- **El escaneo por Enter busca sólo en las reservas ya cargadas** (página 1, `per_page=100`): no hay endpoint "reserva por barcode"; un código fuera de esa página muestra el aviso de *no existe en las reservas visibles*.
- **`hora_entrega_esperada` se envía tal cual del `datetime-local`** (`YYYY-MM-DDTHH:mm`); el backend sólo valida longitud (`VARCHAR 40`) y no valida el formato de fecha. No se corrigió (tocaría el backend).
- **Cantidad exacta, no parcial** (decisión #1 del prompt): sigue el 422 `unitQuantityMismatch` de `start` y `load` hasta el Sprint 9.
- **La exigencia de picking `COMPLETADA|VERIFICADA` para cargar** se deriva en cliente a partir de `GET /inventory/picking` (decisión #2), no desde el backend.
- Huecos heredados intactos: `#/inventory/movements`, `users`, `settings`, `deliveries`, `returns`, `incidents` y `counts` siguen declaradas en `router.js` **sin** módulo en `loader.js`; el ítem **Reservas** del sidebar exige `inventory.reservation.create` (v1.6.2).
- **Los 6 permisos ya estaban sembrados en v1.7.0**: no hizo falta tocar `InventoryPermissionSeeder`; verificado en la BD viva — ADMIN y MANAGER los 6, USER `picking.view/create` + `shipping.view/create` (sin `confirm`/`verify`), VIEWER sólo los dos `.view`. Coincide con los guards de la UI.
- **Verificación de la UI por contrato de API en vivo + smoke jsdom**: la ejecución manual en navegador queda para el humano (pasos documentados en el cierre del sprint).

### Verified (2026-10-03)

- `php -l` sin errores en los **8** PHP tocados en el mini-sprint; `composer test` en verde: **531 tests, 4383 assertions** (+14 sobre v1.7.0; incluye `TransitLocationServiceTest`, con 8, y los tests de A2/A3 en `StartPickingUseCaseTest`, `AuthorizeShipmentUseCaseTest`, `PickingShipmentControllerTest`, `ReceivingControllerTest`, `AuditMiddlewareTest` y `RouterAuditHookTest`).
- E2E vivo (`e2e-v171.ps1`, Apache/MySQL, exit 0): **`E2E v1.7.1: TODO OK`**, sin líneas `FALLO` — siembra/reactivación de `TRANSITO-E2E`, recepción, reserva, `picking/start` **y su replay sin segunda fila de auditoría**, `complete`, `verify` con supervisor ≠ selector, `shipment.load` con replay y `authorize` (200 `EN_RUTA` con la ubicación reactivada); en `audit_logs` ninguna clave genera dos filas con la misma `operacion`.
- Contrato en vivo de los endpoints existentes, comprobado fila a fila contra la UI: `GET /picking`, `GET /shipments` (+ `/shipments/{id}`), `GET /reservations`, `GET /locations` (`activa` es **Boolean**) y `GET /stock?estado=EN_PREPARACION` (claves `barcode`, `producto`, `cantidad`, `estado`, `ubicacion`); `AuthState.getPermissions()` parsea correctamente el `permisos` JSON de `GET /auth/me`.
- `node --check` sin errores en `picking.js`, `shipments.js`, `loader.js` y `sidebar.js`; smoke jsdom **105 checks OK / 0 FAIL**.

## [1.7.0] - 2026-10-02

### Added

- **CU-004 — Picking (dominio + aplicación)**: entidades `PickingOperation` (estados `PENDIENTE/EN_CURSO/COMPLETADA/VERIFICADA`, transiciones con estado requerido y guard *supervisor ≠ selector*), repositorio `PickingOperationRepositoryInterface` + adaptador PDO (tenant en todo `WHERE`, fallback `rowCount` 0 con `COUNT`) y tres casos de uso en una sola transacción (R5):
  - `StartPickingUseCase` (`inventory.picking.start`): lote único por tenant (409), zona de preparación existente/activa/tipo `PICKING` (422), unidad **RESERVADO** (422 `unitNotReserved`), cantidad exacta (422 `unitQuantityMismatch`), transición `RESERVADO → EN_PREPARACION` de la matriz §4, capacidad **+1 slot (`ceil(cantidad)`) en preparación y −1 en origen**, fila `picking_operations` `EN_CURSO`, ledger sólo refresca `estado_actual` (R1) y evento `EN_PREPARACION` con hash. Rollback total (incluida la clave de idempotencia) ante cualquier error de la tanda.
  - `CompletePickingUseCase` (`inventory.picking.complete`): `EN_CURSO → COMPLETADA`; lote inexistente/de otro tenant → 404, estado incorrecto → 409 con el estado actual y el esperado.
  - `VerifyPickingUseCase` (`inventory.picking.verify`): `COMPLETADA → VERIFICADA` con `actor_id_supervisor` = usuario autenticado; **supervisor = selector → 422** (`actor_id_supervisor`); sin actor → 400.
- **CU-005 / CU-006 — Transporte (dominio + aplicación)**: entidades `Shipment` (estados `PREPARACION/EN_RUTA/ENTREGADO/FALLO_ENTREGA/DEVUELTO`, `autorizarSalida()` fija `hora_salida` UTC y `actor_id_autoriza_salida`) y `ShipmentItem` (transiciones `PREPARADA → CARGADA → EN_TRANSITO → …`), repositorios `ShipmentRepositoryInterface`/`ShipmentItemRepositoryInterface` + adaptadores PDO, y:
  - `LoadShipmentUseCase` (`inventory.shipment.load`): crea el envío (guardado **antes** de los ítems por la FK) o reutiliza uno `PREPARACION` (404/409); por unidad exige `EN_PREPARACION` (422), picking `COMPLETADA` **o** `VERIFICADA` (422 `unitPickingNotFinished`), zona tipo `PICKING` (422) y cantidad exacta (422); escribe ítem `CARGADA`, mueve la unidad a `CARGADO`, ledger `reservada −c / en_transito +c` (disponible intacto), **cumple la reserva activa** (`COMPLETADA`, para que E7 no intente devolver a `DISPONIBLE` una unidad ya cargada), libera los slots de la zona y emite el evento `CARGADO`.
  - `AuthorizeShipmentUseCase` (`inventory.shipment.authorize`): exige actor, envío `PREPARACION` (409), ítems no vacíos y todos `CARGADA` (422 `shipmentNotFullyLoaded`), destinatario válido (422) y una ubicación virtual `TRANSITO` activa del tenant (404 `transitLocationMissing`); por ítem transiciona `CARGADO → EN_TRANSITO`, lo mueve a `TRANSITO` (sin capacidad: es virtual), ítem → `EN_TRANSITO`, ledger sólo `estado_actual` y evento `EN_TRANSITO`; después `shipment → EN_RUTA` con `hora_salida`.
- **8 endpoints nuevos** en `src/Modules/Inventory/routes.php` (Auth + Tenant + `Permission` + `RateLimit:api`, docblock de permisos ampliado):

  | Método | Ruta | Permiso |
  |---|---|---|
  | GET | `/api/inventory/picking` | `inventory.picking.view` |
  | POST | `/api/inventory/picking/start` | `inventory.picking.create` |
  | POST | `/api/inventory/picking/{id}/complete` | `inventory.picking.confirm` |
  | POST | `/api/inventory/picking/{id}/verify` | `inventory.picking.verify` |
  | GET | `/api/inventory/shipments` | `inventory.shipping.view` |
  | GET | `/api/inventory/shipments/{id}` | `inventory.shipping.view` |
  | POST | `/api/inventory/shipments/load` | `inventory.shipping.create` |
  | POST | `/api/inventory/shipments/authorize` | `inventory.shipping.confirm` |

  (`{id}` de picking = `id_lote_picking`; `{id}` de shipments = `shipment_id`.)
- **Capa HTTP**: `PickingController` (index/start/complete/verify) y `ShipmentController` (index/show/load/authorize) con el trait `ExtractsIdempotencyKey`. **`Idempotency-Key` obligatoria** en `start`, `load` y `authorize` (400 con `field: idempotency-key` si falta) y **opcional** en `complete`/`verify` (ambas están *gated* por estado → 409, mismo criterio de release que en v1.6.2). Cada escritura con éxito escribe **una fila de auditoría de dominio** después del commit (`picking.start|complete|verify` recurso `picking`, `shipment.load|authorize` recurso `shipment`, con `entidad_id`, `request_id`, clave e idempotencia en `detalles_adicionales`); los errores los audita `AuditMiddleware`.
- **Consultas paginadas** (lectura directa por PDO, tenant siempre por los claims): `PickingQueryService::list()` (joins `stock_units/products/locations`, `summary{total,en_curso,completadas,verificadas}`, filtros `estado`/`id_lote_picking`/`fecha_desde`/`fecha_hasta`, `page` ≥ 1, `per_page` 1–100) y `ShipmentQueryService::list()`/`show()` (ítems con `barcode` y `producto`; `summary{total,en_preparacion,en_ruta,entregados}`). Filtro de estado desconocido o fecha no parseable → 400 con `field`.
- **3 permisos nuevos** siembrados de forma idempotente en `InventoryPermissionSeeder` (ahora **27** `inventory.*`): `inventory.picking.verify` (HIGH), `inventory.picking.view` (MEDIUM) y `inventory.shipping.view` (MEDIUM). Roles: **ADMIN 27**, **MANAGER 23**, **USER 14** (+ `picking.view`/`shipping.view`), **VIEWER 6** (+ los mismos dos). Ejecutado en la BD viva: `permissions_created=3, roles_updated=4` y segunda pasada `0/0/0`; permisos ajenos a `inventory` intactos.
- **DI (`InventoryServiceProvider`)**: 3 repositorios, 5 casos de uso y 2 servicios de consulta registrados (regresión del *bug* de v1.6.2 ampliada a los nuevos FQCN en `InventoryServiceProviderTest`).
- **Zona horaria UTC (tarea 0)**: `C:\xampp\php\php.ini` → `date.timezone=UTC` y `C:\xampp\mysql\bin\my.ini` → `default-time-zone="+00:00"` bajo `[mysqld]` (MariaDB 10.4 rechaza `time_zone=` en el option file; `default-time-zone` es el equivalente soportado). Verificado: PHP CLI/Apache y `@@global.time_zone` en `+00:00`. Backups `php.ini.bak-v170` y `my.ini.bak-v170`.
- **Tests (+79, total 517 / 4294 assertions)**: `StartPickingUseCaseTest` (15: feliz con capacidad/ledger/evento, aislamiento de tenant, no `RESERVADO`, cantidad distinta, lote duplicado, zona no `PICKING`/inactiva, capacidad excedida, unidades repetidas, replay y clave con payload distinto, `sin clave` y atomicidad), `PickingLifecycleTest` (12: completar/verificar, 404/409/422 de supervisor, lotes aislados, replay y secuencia CU-004), `LoadShipmentUseCaseTest` (12: envío nuevo/existente, 404/409, unidad no preparada, picking sin completar, fuera de zona `PICKING`, cantidad, repetidas, replay, 409 por payload y atomicidad), `AuthorizeShipmentUseCaseTest` (11: transito/`EN_RUTA`, sin cargar, destinatario, sin `TRANSITO`, 404/409, unidad no cargada, sin actor, replay y atomicidad), `PickingShipmentQueryTest` (11: campos, resumen, filtros, paginación/tope 100, 400 e aislamiento en ambos servicios), `PickingShipmentRoutesTest` (4: 8 rutas con su permiso, middlewares únicos, lectura ≠ escritura y 403 sin permiso) y `PickingShipmentControllerTest` (14: 401, 400 sin clave en `start`/`load`/`authorize`, **una fila de auditoría por escritura y ninguna en fallo**, 409/422 en vivo, listados con tope de 100 y 404). El `InventorySqliteTestCase` monta las tres tablas nuevas (columnas idénticas a `schema.sql`), las zonas `LOC-PICK`/`LOC-PICK-FULL`/`LOC-TRN` y todos los servicios nuevos.

### Fixed

- **`ShipmentRepository::update()` no incluía `origen_ubicacion_id` en el `SET`**: `toArray()` entrega 19 parámetros y el `UPDATE` sólo 18 placeholders, por lo que PDO fallaba con `SQLSTATE[HY000] column index out of range` en **cualquier** `authorize` (el único flujo que actualiza un envío). Corregido añadiendo el placeholder; detectado por `AuthorizeShipmentUseCaseTest` antes de tocar la BD viva.

### Desviaciones y huecos (declarados)

- **Cantidad exacta, no parcial**: la spec CU-004/CU-005 admite `cantidad >= cantidad_a_preparar`, pero una unidad física no se divide; si la cantidad pedida no es exactamente la de la unidad se responde **422 `unitQuantityMismatch`** (field `cantidad`) en `start` y `load`.
- **Ambigüedad "EN_PREPARACION o VERIFICADA"**: para cargar se exige **ambas** cosas —unidad `EN_PREPARACION` **y** picking `COMPLETADA` o `VERIFICADA`—; la spec no aclara si basta una de las dos.
- **No había ubicación `TRANSITO` en el tenant demo**: la spec asume una ubicación virtual `TRANSITO` por tenant y **no existe ningún endpoint ni seeder que la cree**; el E2E la creó con `POST /api/inventory/locations` (endpoint existente, `inventory.location.manage`). Si un tenant no la tiene, `authorize` devuelve **404 `transitLocationMissing`**. No se sembró nada por iniciativa propia.
- **Riesgo residual de expiración (E7)**: si una reserva `ACTIVA` vence mientras su unidad está `EN_PREPARACION` (fase entre `start` y `load`), `ReservationExpirationService` intenta la transición `EN_PREPARACION → DISPONIBLE`, prohibida por la matriz §4, y fallará al completo del lote de expiración. No se corrigió (fuera de alcance: tocaría `ReservationExpirationService`).
- **Replay de idempotencia = segunda fila de auditoría**: el controller audita después del `execute()`, que en replay devuelve la respuesta almacenada sin escribir nada de dominio; por eso una repetición exacta genera una segunda fila `SUCCESS` con la misma `entidad_id`. Es el patrón heredado de `ReceivingController`/`ReservationController` (no se tocó).
- **Supervisor = usuario autenticado**: no hay campo `supervisor` en el cuerpo; el rol lo decide el permiso `inventory.picking.verify` (ADMIN/MANAGER) y la regla *≠ selector* se aplica con el `user_id` de los claims. La spec habla de "supervisor" sin definirlo más.
- **`GET /shipments` no filtra por `referencia_externa`** (no está en la spec); sólo `estado`, `fecha_desde/hasta`, `page`, `per_page`.
- **Permisos de lectura para USER/VIEWER**: `picking.view`/`shipping.view` se asignaron a los cuatro roles (quien ya ve stock puede ver el trabajo en curso); **iniciar/cargar es de ADMIN/MANAGER** (`picking.create`, `shipping.create`), verificar y autorizar sólo ADMIN/MANAGER.
- **Frontero intacto (alcance SOLO BACKEND)**: `#/inventory/picking` y `#/inventory/shipments` siguen declaradas en `router.js` **sin** módulo en `loader.js` (siguen mostrando "Módulo no disponible"); no se tocó ningún fichero JS, por lo que tampoco `node --check` ni smoke.
- En DESA `manager@acme.local` sí autentica con **`Manager123!`** (usuario creado por script en un sprint anterior) —con `Admin123!` da 401—; con eso se cubrió el segundo actor (selector = `demo@acme.local`, supervisor = `manager@acme.local`).

### Verified (2026-10-02)

- `php -l` sin errores en los **~25** PHP tocados; `composer test` en verde: **517 tests, 4294 assertions**.
- E2E vivo (`e2e-v170.ps1`, Apache/MySQL): login `demo` + `manager` → creación de `TRANSITO-E2E` → recepción de 1 unidad → reserva → **`POST /picking/start` 201** (replay con la misma clave devuelve la misma `picking_id`; sin clave → **400**) → `GET /picking` (MANAGER) 200 con la fila del lote → **`complete` 200** y repetido **409** → **`verify` como selector 422** y como MANAGER **200 (`VERIFICADA`, supervisor ≠ selector)** → **`shipments/load` 201** (sin clave → 400) → `GET /shipments` y `/shipments/{id}` 200 → **`authorize` 200 (`EN_RUTA`, `hora_salida`, unidad `EN_TRANSITO` en `TRANSITO-E2E`)**, repetido **409**, sin clave **400**. En BD: `picking_operations` `VERIFICADA` con selector y supervisor distintos, `shipments` `EN_RUTA`, ledger `reservada 0 / en_transito 1 / disponible 0`, 6 filas `SUCCESS` en `audit_logs` (`picking.start` ×2 por el replay, `picking.complete`, `picking.verify`, `shipment.load`, `shipment.authorize`) y 3 claves nuevas en `idempotency_keys`.
- Sin cambios de esquema: `picking_operations`, `shipments` y `shipment_items` de `database/schema/schema.sql` (líneas 307/330/358) y de `database/schema/create_tables.ps1` coinciden columna a columna con la spec 3.12–3.14 y con la BD viva.

## [1.6.2] - 2026-10-02

### Added

- **`GET /api/inventory/reservations`** (nuevo, `inventory.reservation.view`): listado paginado de reservas con `ReservationQueryService` (lectura directa por PDO, acotada siempre al `tenant_id` de los claims) desde `ReservationController::index()`. Filtros `estado` (validado contra `Reservation::ESTADOS_VALIDOS` → 400 con `field`), `producto_id`, `stock_unit_id`, `vencimiento_desde/hasta` (fecha `Y-m-d` ampliada a `00:00:00`/`23:59:59`; no parseable → 400 con `field`), `page` (≥1) y `per_page` (1–100). Respuesta `data.{summary,data,pagination}` con `summary{total,activas,con_expiracion}` y por fila `reserva_id, stock_unit_id, barcode, producto, ubicacion, cantidad, estado, fecha_expiracion, creado_por, fecha_creacion`. Ruta en `routes.php`: `Auth + Tenant + Permission:inventory.reservation.view + RateLimit:api`.
- **Permiso `inventory.reservation.view`**: siembra idempotente en `InventoryPermissionSeeder` (24 permisos `inventory.*`) asignado a **ADMIN, MANAGER, USER y VIEWER** (los roles que ya tenían `inventory.stock.view`). Ejecutado en la BD viva: `permissions_created=1, roles_updated=4` y segunda pasada `0/0/0`.
- **Pantalla Reservas sobre el nuevo endpoint**: `reservations.js` deja de leer `GET /inventory/stock?estado=RESERVADO` y retira el aviso de hueco; ahora pide `GET /inventory/reservations`, muestra columna **Vencimiento** con `fecha_expiracion` real (en rojo si está vencida y aún `ACTIVA`), tarjetas total/activas/con vencimiento, y acción **Liberar** habilitada con `confirm()` → `POST /inventory/reservations/{id}/release` + recarga del listado (toasts diferenciados 403/404/409, con recarga en 404 y 409). `canView()` acepta `inventory.reservation.view`, de modo que VIEWER consulta la pantalla sin posibilidad de liberar.
- **Evento `MOVIDO`**: `StockEvent::TIPOS_VALIDOS` y `MoveStockUseCase` pasan de **TRASLADO** a **MOVIDO** (nombres de la spec) con su test de regresión. En DESA se migraron **5 filas** de `stock_events` de `TRASLADO` a `MOVIDO` con `hash_integridad` recalculado (los 30 hashes se verificaron antes y después; el hash es por fila, no hay cadena entre eventos). Recuento final: `LIBERADO 5, MOVIDO 5, RECIBIDO 14, RESERVADO 6`, 0 `TRASLADO`, 0 hashes inválidos. Sin cambios de esquema (`tipo_evento` es `VARCHAR(50)`).
- **Auditoría de la expiración CLI (E7)**: `ReservationExpirationService` recibe un `AuditLogger` opcional (inyectado por `InventoryServiceProvider` cuando existe) y, **después de confirmar la transacción** (la transacción no se anida), escribe una fila por reserva expirada: `operacion='CLI bin/expire-reservations'`, `recurso='reservations'`, `entidad_id=reserva_id`, `tipo_cambio='EXECUTE'`, `resultado='SUCCESS'`, `valores_nuevos` con estados y motivo E7, `detalles_adicionales{origen:'cli', comando:'bin/expire-reservations.php'}`.
- **Idempotencia de release documentada**: docblocks de `ReservationController::release` y de `routes.php` dejan constancia de que release es **idempotente por estado** (segunda llamada sobre una reserva `CANCELADA` → 409, no replay) y de que por eso `Idempotency-Key` es opcional allí (obligatorio en `POST /reservations`).
- **`AGENTS.md`**: reglas permanentes del repositorio (huecos de endpoint, backend sólo por petición, sin emojis, desviaciones, cambios de BD en los tres destinos, siembra de permisos, cierre con `php -l`/`composer test`/E2E/CHANGELOG y verificación JS con `node --check` + smoke).
- **Tests (+22)**: `ReservationQueryServiceTest` (aislamiento por tenant, filtros, paginación y tope de 100, campos `reserva_id`/`fecha_expiracion`), `ReservationListRoutesTest` (ruta registrada con su permiso, middlewares únicos y **403 sin `inventory.reservation.view`** vía `PermissionMiddleware` + `AuthorizationService` con la caché de permisos falsada), `InventoryServiceProviderTest` (regresión de bindings), `ReservationControllerTest::index…` (401, paginación, tope, 400 por estado y query params ajenos ignorados) y `InventoryPermissionSeederTest` ampliado al permiso nuevo.

### Fixed

- **`InventoryServiceProvider` no registraba `ReservationQueryService` bajo su FQCN** (faltaba el `use`): el binding quedaba en el namespace del propio provider y `Container::get()` lanzaba `No binding found`, por lo que `GET /api/inventory/reservations` devolvía **500** en vivo. Detectado en la verificación E2E, corregido y cubierto por `InventoryServiceProviderTest`.

### Desviaciones y huecos (declarados)

- **Revisión de autorización (C5)**: en `src/Modules/Inventory` y sus rutas **no hay ninguna comprobación de nombre o ID de rol** (todo va por permisos); única mención un comentario en `DashboardController.php:19`. No había nada que corregir.
- El **sidebar no se tocó** (fuera de alcance): el ítem **Reservas** sigue exigiendo `inventory.reservation.create`, así que un VIEWER con `inventory.reservation.view` llega a la pantalla por URL directa pero no le aparece el menú.
- `creado_por` se devuelve como UUID de usuario (no hay join con `users`), por lo que la columna "Creada por" se omitió de la tabla.
- En DESA **no existe ningún usuario sin rol BUILT_IN** (admin/demo `ADMIN`, manager `MANAGER`): el **403 en vivo** del nuevo permiso no pudo reproducirse con los usuarios existentes; queda cubierto por `ReservationListRoutesTest`. Además `manager@acme.local` no autentica con la contraseña documentada (probada, 401).
- Diferencia de zona horaria entre PHP (`Europe/Berlin`) y el sistema: `fecha_expiracion` debe calcularse en la zona de PHP (hallado en el E2E de expiración; no es un bug del módulo).
- Siguen abiertos los huecos heredados de v1.6.1: `#/inventory/movements`, `users`, `settings`, `picking`, `shipments`, `deliveries`, `returns`, `incidents` y `counts` declaradas en `router.js` sin módulo en `loader.js`.

### Verified (2026-10-02)

- `php -l` sin errores en los **14** PHP tocados; `composer test` en verde: **438 tests, 3505 assertions**.
- `node --check` sin errores en `public/js/modules/inventory/reservations.js` y **smoke jsdom 89/89 OK** (`smoke-v162.mjs`, suite S4 reescrita: endpoint correcto sin llamar a `/inventory/stock`, sin aviso de hueco, vencimiento real, Liberar con `confirm()` aceptado/rechazado, 409 y 403 con toast y recarga, VIEWER con/sin permiso).
- Vivos (Apache/MySQL): login 200 → `GET /api/inventory/reservations` **200** (7 reservas, `summary.activas=1`, `con_expiracion=1`, `per_page` respetado, `has_next`) y `?estado=ACTIVA` → 1 → reserva creada y **liberada con 200** (`estado=CANCELADA`), con su fila en `audit_logs` (`POST /api/inventory/reservations/{id}/release`, `SUCCESS`); siembra idempotente en la BD viva; `php bin/expire-reservations.php` → `1 reserva(s) expirada(s)` y fila `CLI bin/expire-reservations` en `audit_logs` con el motivo E7; auditoría histórica confirmada para `POST /api/inventory/movements` (4), `POST /api/inventory/receive` + `receiving.create` (4) y `POST /api/inventory/reservations` (13) + sus releases.

## [1.6.1] - 2026-10-01

### Added

- **Mover stock (UI, Sprint 6 frontend)**: botón **Mover** en cada fila de `#/inventory/stock` (sólo con `inventory.movement.create` y sólo si el estado lo admite: NO `EN_TRANSITO`/`ENTREGADO`/`PERDIDO`) que abre un modal con unidad de solo lectura, selector de **ubicación destino** (activas con espacio, sin tipo `TRANSITO` y distintas de la actual) y campos opcionales `motivo` y `referencia_externa`, según lo que exige `POST /api/inventory/movements`. **Idempotency-Key** con `crypto.randomUUID()` al abrir el modal, reutilizada en cada reintento del mismo envío y renovada tras el éxito (y en 409, ver desviaciones). Envíos 400/401/403/404/409/422 con toast (422 con `field: capacidad` → "Capacidad excedida") y error de campo cuando el backend devuelve `data.field`; al terminar se recargan Stock y los catálogos (`capacidad_usada`).
- **Reservar (UI)**: botón **Reservar** en Stock (sólo con `inventory.reservation.create` y sólo si `estado = DISPONIBLE` con `cantidad_disponible > 0`, siguiendo la matriz §4: `DEVUELTO` no es reservable) que abre un modal con `cantidad` (validada contra lo disponible), `fecha_expiracion` (opcional, debe ser futura) y `referencia_orden`, contra `POST /api/inventory/reservations` con la misma política de Idempotency-Key y manejo de errores.
- **Pantalla Reservas**: `#/inventory/reservations` (módulo `inventory/reservations.js`, ya declarada en `router.js` desde v1.5.x pero antes sin módulo) con listado paginado de las unidades en estado `RESERVADO` (`GET /api/inventory/stock?estado=RESERVADO`), columna de **Vencimiento** y acción **Liberar** con `inventory.reservation.cancel` (ADMIN/MANAGER), más aviso visible del hueco de endpoint (ver desviaciones).
- **Visor de Auditoría (sólo lectura)**: `#/audit` (módulo `audit/logs.js`) contra `GET /api/audit/logs` con filtros de fecha desde/hasta, usuario (UUID), acción, entidad y resultado (SUCCESS/FAILURE), paginación (`page`/`per_page`), tarjetas de resumen (total/exitosos/fallidos) y **modal de detalle** con los valores antes/después en JSON, `razon_fallo`, IP, `request_id`, `duracion_operacion`, `detalles_adicionales`, `hash_integridad` y `prev_hash`. Sólo visible con `audit.log.view` (sin permiso pinta "Sin permiso" y no hace ninguna llamada); directorio de usuarios opcional para mostrar nombre/email cuando existe `users.user.view`.
- **Permisos en la UI** (nombres exactos de la BD y de `routes.php`): botones y menú filtrados con `inventory.movement.create`, `inventory.reservation.create`, `inventory.reservation.cancel` y `audit.log.view`, con fallback a roles (`ADMIN`/`MANAGER`/`USER`) cuando aún no hay permisos cargados.
- **Registro de rutas (los tres ficheros)**: `router.js` → nueva ruta `audit` (módulo `audit/logs`); `loader.js` → registros `inventory/reservations` y `audit/logs` (faltaban: `#/inventory/reservations` devolvía "Módulo no disponible"); `sidebar.js` → ítems **Reservas** (`inventory.reservation.create`) e **Auditoría** (`audit.log.view`) con iconos SVG nuevos (`clock`, `shield`) y roles de respaldo.

### Desviaciones y huecos (declarados, backend intacto)

- **Evento de movimiento**: la spec (`INVENTORY_MODULE_SPECIFICATION.md:273` y `:740`) dice **MOVIDO**; el backend implementa **TRASLADO** (`StockEvent.php:36`, `MoveStockUseCase.php:223`). Reportado, sin tocar backend.
- **HUECO DE ENDPOINT — no existe `GET /api/inventory/reservations`** (verificado en vivo: **404**; sólo existen `POST /reservations` y `POST /reservations/{id}/release`). Consecuencias en la pantalla Reservas: no hay `reserva_id` para ejecutar **Liberar** (el botón se muestra deshabilitado con el motivo) ni `fecha_expiracion` (columna Vencimiento sin dato), y no se puede listar el estado real de las reservas (`ACTIVA`/`CANCELADA`); el listado se apoya en `GET /api/inventory/stock?estado=RESERVADO`. No se inventó ningún endpoint.
- **Idempotency-Key**: además de renovarse tras el éxito, se renueva en **409** (clave ya usada con otra carga útil), igual que `receiving.js` en v1.5.2; sin eso, un reintento con datos corregidos quedaría en bucle de 409. Los reintentos del mismo envío (400/401/403/404/422/red) sí reutilizan la clave.
- El filtro "Usuario" del visor es un campo de texto con el UUID: `MANAGER` no tiene `users.user.view`, así que no hay selector de usuarios disponible para él (el nombre/email se muestra sólo si el directorio de usuarios es accesible).
- El selector de destinos se alimenta de `GET /inventory/locations?page=1&per_page=100` (mismo límite de 100 que `receiving.js`).
- `#/inventory/movements`, `users`, `settings`, `picking`, `shipments`, `deliveries`, `returns`, `incidents` y `counts` siguen declaradas en `router.js` **sin** módulo registrado en `loader.js` (muestran "Módulo no disponible"): sólo `audit` y `inventory/reservations` se registraron en esta versión.

### Verified (2026-10-01)

- `node --check` sin errores en los **20 ficheros JS** de `public/js` (6 tocados: `stock.js`, `reservations.js`, `audit/logs.js`, `router.js`, `loader.js`, `sidebar.js`).
- **Smoke test jsdom: 73/73 OK** (acciones por estado y permiso, modal Mover con destinos filtrados, cabecera `Idempotency-Key`, recarga de stock/capacidad, 400/401/403/404/409/422 con toast, conservación de la clave tras 422 y renovación en 409, validaciones de reserva, pantalla Reservas con hueco y permisos, visor con filtros/detalle/permisos, y registro en router/loader/sidebar para ADMIN, MANAGER/USER y VIEWER).
- `composer test` en verde: **416 tests, 3269 assertions** (backend sin cambios).
- Vivos con Apache/MySQL levantados: assets nuevos y modificados servidos con **200**; `GET /api/inventory/reservations` → **404** (hueco); `GET /api/inventory/stock?estado=RESERVADO` → 200; `GET /api/audit/logs` → 200; `GET /api/inventory/locations` → 200 con `activa` booleano y `tipo`.

## [1.6.0] - 2026-10-01

### Added

- **Movimiento de inventario (Sprint 6, CU-002)**: `POST /api/inventory/movements` (`inventory.movement.create`) ejecuta `MoveStockUseCase` en **una sola transacción** (R5): valida unidad/destino activo, mismo tenant, origen ≠ destino, tipo `TRANSITO` (ubicación virtual, rechazada) y estado que admita movimiento; aplica capacidad (+`field: capacidad` 422 si se excede), escribe la fila inmutable `movements` con `hash_integridad`, actualiza `stock_units.ubicacion_id`, refresca el ledger y crea el evento `TRASLADO` (append-only con hash). **Idempotencia (R7)**: header `Idempotency-Key` obligatorio — misma clave + mismo payload → replay de la respuesta original (mismo `movimiento_id`), payload distinto → 409, sin clave → 400 (`field: idempotency-key`); la key se INSERTA dentro de la transacción y el ROLLBACK la revierte.
- **Reserva de inventario (Sprint 6, CU-003)**: `POST /api/inventory/reservations` (`inventory.reservation.create`) con `ReserveStockUseCase`: exige `Idempotency-Key`, transición válida hacia `RESERVADO`, sin reserva activa (409 doble reserva) y `cantidad_disponible >= cantidad` (422, spec E7/R8); crea la reserva `ACTIVA`, descuenta `cantidad_reservada` del ledger (R1), pasa la unidad a `RESERVADO` y escribe el evento `RESERVADO`. `POST /api/inventory/reservations/{id}/release` (`inventory.reservation.cancel`) cancela con la clave **opcional** (decidido en la revisión; release es idempotente de por sí): reserva → `CANCELADA`, ledger descuenta y evento `LIBERADO`; repetir sobre una reserva no activa → 409.
- **Máquina de estados (Sprint 6.1)**: `StateTransitionService`, única fuente de verdad de la matriz §4 de transiciones válidas; cualquier transición fuera de la matriz se rechaza con 422 (`field: estado`, R8). Lo consultan reserva, liberación, expiración y movimiento.
- **Expiración de reservas (Sprint 6.3, spec E7)**: `ReservationExpirationService` (cancela en lote reservas `ACTIVA` con `fecha_expiracion` pasada, devuelve stock y escribe `LIBERADO`, todo por tenant en una transacción) + comando `php bin/expire-reservations.php [--tenant=<id>] [--limit=100]`.
- **Resumen de stock por producto (Sprint 6, 0b)**: `GET /api/inventory/stock/summary` (`inventory.stock.view`) con agregado en una sola consulta, filtros `producto_id[]` y totales `productos/cantidad_fisica/cantidad_reservada/cantidad_en_transito/cantidad_disponible`.
- **Permisos de la BD viva (singulares, convención CU-002/CU-003)**: `inventory.movement.create`, `inventory.reservation.create`, `inventory.reservation.cancel` (no `movements.*`/`reservations.*`).
- **Bugfix de repositorios**: los `update()` de `ProductRepository`, `LocationRepository` y `StockUnitRepository` reescrivían la fila completa con valores de tenant/timestamps ajenos; ahora sólo actualizan las columnas cambiadas (afectaba a los 3 CRUD).
- **Suite 209 → 361 tests (1867 → 2899 assertions)**: `MoveStockUseCaseTest`, `MovementControllerTest`, `ReserveStockUseCaseTest`, `ReservationControllerTest`, `StockSummaryControllerTest`, `InventoryMovementRoutesTest`, más los casos nuevos de `ReceiveInventoryUseCaseTest`/`StateTransitionServiceTest`.

### Desviaciones respecto a la spec (declaradas)

- Permisos **singulares** (`inventory.movement.*`) mientras el enunciado de permisos usa plurales: se mantiene lo que ya existe en la BD viva.
- Unidad en estado `DEVUELTO` **no es reservable** (matriz §4 no permite `DEVUELTO → RESERVADO`); debe pasar antes por `DISPONIBLE`.
- El evento de movimiento se llama **`TRASLADO`** (no `MOVIDO`).
- El "tipo TRANSITO" de la spec es una **ubicación virtual** que se rechaza como destino.
- `release` de reserva acepta `Idempotency-Key` **opcional** (sólo movimientos y reservas lo exigen).
- Bugfix de los tres `update()` anteriores (no contemplado en la spec, requerido por los CRUD).

### Verified (2026-10-01)

- `php -l` sin errores; `composer test` en verde: `361 tests, 2899 assertions`.
- E2E en vivo contra DESA (Apache/MySQL de XAMPP levantados): login 200 → `stock/summary` 200 (7 productos, 115 uds) y con `producto_id[]` → recepción 201 (unidad `934e7817-…`, barcode `SU-LTPJ3W-CLGEYD5W`, 2 uds en `HOUSEA23`) → movements sin clave 400 / con clave 201 (movimiento `e6969744-…`) / replay 201 con el mismo id / misma clave con payload distinto 409 / destino lleno `A010210` 422 → reservations sin clave 400 / 201 (reserva `ea1f7336-…`) / doble reserva 409 → release 200 `CANCELADA` / repetido 409 → `php bin/expire-reservations.php` → `0 reserva(s) expirada(s) en 1 tenant`.
- Datos en BD: movimiento `REUBICACION` `HOUSEA23 → 312-EDIT` con `hash_integridad` `f5d6f3a4…`, eventos `RECIBIDO/TRASLADO/RESERVADO/LIBERADO` (+1 cada uno), resumen final 117 uds.

## [1.5.3] - 2026-10-01

### Added

- **Bitácora de auditoría (Sprint Auditoría, spec §7.1)**: tabla `audit_logs` (append-only) con todas las columnas de la spec **más** `entidad_id VARCHAR(64)` y `request_id VARCHAR(64)` + índice `idx_resultado (tenant_id, resultado)` (aprobados en la revisión del plan), `timestamp TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` para poder ordenar la cadena de hash sin bifurcar en el mismo segundo, y FK a `tenants` — en `database/schema/schema.sql`, `database/schema/create_tables.ps1` y la BD viva (ALTER aplicado). La columna legada `dispositivo_id` no forma parte del payload que se hashea.
- **`src/Core/Audit/`**: `AuditPort` (primitivas `transaction`/`previousHash`/`insert`, **sin** `update()`/`delete()`), `PdoAuditPort` (SELECT + `SELECT … FOR UPDATE` por tenant en MySQL + INSERT; en SQLite se omite el candado), `AuditLogger` (fila canónica, hash `SHA256(prev || payload_canónico)` con `prev_hash` guardado en `detalles_adicionales`, truncado JSON a 8192, marca temporal con microsegundos y **nunca lanza**: degrada a `error_log`), `SecretRedactor` (claves con fragmentos `password/secret/token/api_key/authorization/credencial/clave/bearer/cookie/csrf…`, JWT `eyJ…`, `Bearer …`/`Basic …`), `AuditMiddleware`, `Service\AuditLogQueryService` y `Http\AuditLogController`.
- **Hook genérico en `Router::dispatch()`** (desviación aprobada): `$request['audit_finish']` se invoca en éxito, en excepción del handler (401/403/404/409/429/errores de negocio) y cuando un middleware posterior falla; `executeMiddleware()` pasa la request **por referencia** para conservar los claims ya inyectados; un fallo dentro del hook jamás rompe la respuesta (`error_log`). `executeHandler()` devuelve el resultado para que el Router conozca el código de estado.
- **Instalación de `AuditMiddleware` PRIMERO** en el `group()` de Inventory y en todas las escrituras de platform (`login`, `refresh`, `logout`, `users` store/update/destroy/assignRole/removeRole, `roles` store/update/destroy/updatePermissions/restore, `tenant` update/activate). Sólo escrituras (GET/HEAD/OPTIONS no instalan hook); genera `request_id` (cabecera `X-Request-Id` o UUID) y lo devuelve en la respuesta. **No** se auditan `ping`/`auth/me` ni las lecturas de platform (ruido).
- **Auditoría de dominio (sólo éxitos; los fallos los aporta el hook)**: `AuthController` (`auth.login` SUCCESS/FAILURE con causa, `auth.refresh.reuse` FAILURE con familia revocada, `auth.logout` — el tenant se resuelve por email porque el login no tiene claims), `UserController` (`user.create/update/role.assign/role.remove`), `RoleController` (`role.create/update/permissions.update` con `valores_anteriores/valores_nuevos`), `ProductController` y `LocationController` (create/update/delete/activate/deactivate/restore con antes/después), `ReceivingController` (`receiving.create` con `unidad_fisica_id` e idempotency key). Dos filas por escritura de inventario (genérica `POST/PUT/PATCH/DELETE /ruta` + dominio), documentado.
- **`GET /api/audit/logs`** (`Auth + Tenant + Permission:audit.log.view + RateLimit:api`): filtros `fecha_desde/fecha_hasta/usuario_id/accion(operacion)/entidad(recurso)/entidad_id/request_id/resultado/page/per_page` (máx. 100, `resultado` inválido → 400), respuesta `data.{summary,data,pagination}` con `prev_hash` expuesto; el tenant **siempre** desde la sesión. Sólo existe la ruta de lectura: sin POST/PUT/DELETE (append-only).
- **Permisos**: `AuditPermissionSeeder` siembra `audit.log.view` (HIGH) y lo añade a **ADMIN** y **MANAGER** (idempotente, repara drift, no siembra `audit.log.export` porque no hay endpoint de exportación); `PlatformSeeder` lo invoca después del seeder de inventory. Ejecutado contra la BD viva: `1 creado, 2 roles actualizados` y segunda pasada `0/0/0`.
- **Bootstrap**: singletons `AuditPort` (`PdoAuditPort`), `AuditLogger` (con `TenantContext`) y `AuditLogQueryService`.
- **Suite 361 → 416 tests (2899 → 3269 assertions)**: `AuditSqliteTestCase` (esquema `audit_logs` real en SQLite + verificador de cadena), `AuditLoggerTest` (cadena, aislamiento por tenant, redacción, degradación, append-only, determinismo, microsegundos), `SecretRedactorTest`, `AuditMiddlewareTest`, `RouterAuditHookTest` (403 registrado por el hook y claims preservados), `AuditLogQueryTest`, `AuditRoutesTest`, `AuditPermissionSeederTest`, `DomainAuditCallsTest`.

### Fixed

- **`date('u')` devuelve `000000` en Windows** (timelib/PHP): colapsaba `audit_logs.timestamp` a resolución de 1s y hacía que `previousHash()` (`ORDER BY timestamp DESC, id DESC`) no coincidiera con el orden real de inserción, rompiendo la verificación de la cadena. `AuditLogger::now()` usa `DateTimeImmutable` (con test de regresión: timestamps naturales estrictamente crecientes con microsegundos y cadena verificable). Las 31 filas de E2E anteriores al fix quedan marcadas como "época pre-fix" en DESA.

### Desviaciones respecto a la spec (declaradas)

- Identificadores de permisos **singulares** (la lista del enunciado los trae en plural).
- El hook de auditoría vive en `Router::dispatch()` (genérico) en vez de sólo en `AuditMiddleware`, y la tabla añade `entidad_id`/`request_id` — ambos aprobados en la revisión del plan.
- El **403 no lleva llamada explícita en `PermissionMiddleware`**: lo audita el hook del Router (el hook ya está instalado porque `AuditMiddleware` va primero), evitando una doble fila.
- `DELETE /api/users/{id}` (`destroy`) audita sólo vía el hook (lista de controllers aprobada).
- `audit.log.export` **no** se siembra (spec §7.1 lo menciona como ejemplo; no hay endpoint de export).
- **429 de login y peticiones anónimas sin tenant deducible no se persisten** (`tenant_id NOT NULL` + FK): login fallido con email desconocido y 401 sin token no generan fila; el login fallido con email conocido sí se audita (tenant resuelto por email).
- Fila de auditoría = genérica (hook) + dominio (controller) en escrituras; sólo éxitos en los controllers.

### Verified (2026-10-01)

- `php -l` sin errores en `src, tests, database, config, routes, bin, public`; `composer test` en verde: `416 tests, 3269 assertions`.
- Seeders contra la BD viva: `audit.log.view` presente en `permissions` (HIGH, activo) y en `roles.permisos` de ADMIN y MANAGER (no en USER/VIEWER); segunda ejecución de `PlatformSeeder` idempotente (`0 creados, 0 corregidos, 0 roles`). `SHOW CREATE TABLE audit_logs` con `timestamp(6)`, `entidad_id`, `request_id` e `idx_resultado`.
- E2E en vivo (22 comprobaciones de API, todas en verde, re-ejecutado tras el fix de microsegundos): login ADMIN 200 / login con contraseña mala 401 / login MANAGER 200 → CRUD de producto 201/200/200 → ubicación 201 → recepción 201 → movimiento 201 → reserva 201 → release 200 `CANCELADA` → POST sin token 401 → `PATCH /tenant` como MANAGER **403** → lectura `GET /products` sin generar filas → `GET /audit/logs` 200 con `total=65 exitosos=57 fallidos=8` y filtros `resultado=FAILURE` (8), `accion=product.create` (4, con `entidad_id`), `entidad=products` (11), `entidad_id` (5), `request_id` (2: hook + dominio), paginación `page=2` (`last_page=33`), rango de fechas (65), `resultado=TODO` → **400**, y **MANAGER con `audit.log.view` → 200**.
- Verificación SQL (script propio): 65 filas con `hash_integridad` y `request_id` completos; **0 filas `GET %`**; **0 passwords en claro** (`Admin123`/`Manager123`/`$2y$` sin aparecer en valores ni detalles); **0 filas anónimas** (desviación declarada); cobertura de operaciones (dominio + hook) de productos, ubicaciones, recepción, movimientos, reservas, release y login OK/FAIL; **cadena SHA256 verificada**: las 34 filas post-fix encadenadas desde la última pre-fix (31 filas pre-fix) con el mismo algoritmo que `AuditLogger::computeHash` y microsegundos reales en todas.

## [1.5.2] - 2026-09-30

### Added

- **UI de recepción (Sprint 5, frontend)**: `public/js/modules/inventory/receiving.js` (`InventoryReceivingModule`, ruta `#/inventory/receiving`) con formulario de producto (selector de activos), ubicación destino (selector de activas con `usada/capacidad` y opciones sin espacio deshabilitadas), cantidad esperada, cantidad recibida opcional, lote y número de serie, validando en cliente lo que exige `POST /api/inventory/receive`. El **Idempotency-Key** se genera con `crypto.randomUUID()` al abrir el formulario, se **reutiliza en cada reintento** (400/401/409/422/500) y sólo se regenera tras un 201 o ante un 409 (clave usada con otra carga útil); la clave visible se muestra en el pie del formulario. Con 201 se pinta el panel de resultado con el barcode `SU-XXXXXX-XXXXXXXX`, SKU, ubicación, cantidades, estado, hash del evento y el aviso de **recepción parcial** con la incidencia `DISCREPANCIA_CANTIDAD`; tras el éxito se recarga el catálogo para reflejar la `capacidad_usada` actualizada. Errores 400 (con `data.field`), 401, 404, 409 y 422 (capacidad excedida, `field: capacidad`) se informan con toast y error en el formulario.
- **UI de stock actual**: `public/js/modules/inventory/stock.js` (`InventoryStockModule`, ruta `#/inventory/stock`) con tarjetas de resumen (unidades, física, reservada, en tránsito, disponible), filtros por producto, ubicación y estado, tabla paginada (barcode, producto, ubicación, cantidad con desglose disponible/reservado/en tránsito, estado) y paginación sobre `GET /api/inventory/stock`.
- **Stock total en productos**: columna "Stock total" en `products.js` con el resumen por producto (`GET /inventory/stock?producto_id=&per_page=1`, caché de 15 s y 5 peticiones en paralelo para no agotar el rate limit `api` de 100 req/min) y botón **Stock** que abre el desglose agrupado por ubicación y estado (máx. 100 unidades, con aviso de truncado) y totales del `summary`.
- **Unidades por ubicación**: botón **Unidades** en `locations.js` con modal `GET /inventory/stock?ubicacion_id=` (barcode, producto, cantidad, estado) más capacidad de la ubicación y totales.
- **Registro de módulos y menú**: `inventory/receiving` e `inventory/stock` en `public/js/core/loader.js`; nueva ruta `inventory/stock` en `public/js/core/router.js` (declarada en el mapa de rutas pero **inexistente** hasta ahora: `#/inventory/stock` devolvía "Módulo no disponible"); en `sidebar.js` los ítems "Recepción" (`inventory.receiving.create`) y "Stock" (`inventory.stock.view`) con sus roles de respaldo, ocultos a quien no tenga el permiso. Verificados contra `roles.permisos` de la BD, no contra `PlatformSeeder.php` (**ese seeder no siembra permisos de inventario**; sólo crea tenant, usuario ADMIN y asigna roles).

### Verified (2026-09-30)

- `composer test` en verde: `209 tests, 1867 assertions` (backend sin cambios) y `node --check` sin errores en los 7 archivos JS tocados.
- E2E en vivo contra DESA (Apache/MySQL de XAMPP levantados para la prueba): recepción 201 con barcode `SU-3GLEYS-J6YMK26J` -> replay con la misma `Idempotency-Key` 201 con idéntica `unidad_fisica_id` (1 fila nueva en BD) -> misma clave con payload distinto **409** con mensaje -> sin clave **400** (`field: idempotency-key`) -> capacidad excedida **422** (`field: capacidad`, sin escritura) -> recepción parcial 201 con `recepcion_parcial: true` e incidencia `DISCREPANCIA_CANTIDAD`.
- `GET /inventory/stock` filtrado por producto (2 unidades / 3 físicas), por ubicación (5 unidades) y por `estado=DISPONIBLE` (total 5); `capacidad_usada` de `A010210` de 12 a 15 (20 de capacidad) tras las recepciones.
- Assets servidos por Apache con 200: `receiving.js`, `stock.js`, `loader.js`, `router.js`, `sidebar.js`.

## [1.5.1] - 2026-09-29

### Fixed

- **Barcode de `stock_units` único por tenant (no global)**: la tabla tenía `UNIQUE (codigo_barcode)` global más un índice redundante `idx_codigo_barcode`, incompatible con la regla "cada tenant puede tener sus propios códigos". Se reemplaza por `UNIQUE KEY uq_stock_units_tenant_barcode (tenant_id, codigo_barcode)` y la columna pasa a `VARCHAR(64) NOT NULL` en `database/schema/schema.sql`, `database/schema/create_tables.ps1` y la BD viva (`DROP INDEX codigo_barcode` + `DROP INDEX idx_codigo_barcode` + `MODIFY` + `ADD UNIQUE`, sin tocar datos). El esquema SQLite de `tests/Support/InventorySqliteTestCase` replica el unique compuesto.
- **Prefijo de barcode fijo `SU` (no derivado del `tenant_id`)**: `BarcodeService` elimina `tenantPrefix()` y todo uso de `tenant_id`; genera `SU-XXXXXX-XXXXXXXX` (6 + 8 caracteres aleatorios con `random_int` sobre el alfabeto sin ambiguos `0/1/O/I/U`) y `matches()` valida el formato sin parámetro de tenant. Se mantiene el reintento hasta `MAX_ATTEMPTS = 5` en `ReceiveInventoryUseCase` y la normalización a mayúsculas/trim en las búsquedas. Los códigos existentes con prefijo `CB22DB-…` (datos de DESA) se dejan tal cual: siguen siendo válidos y únicos por tenant.
- **`StockUnit::validateBarcode()` limita `codigo_barcode` a 64 caracteres** (antes 255), alineando el dominio con la columna de BD; docblocks de `StockUnitRepositoryInterface` (`save()` lanza `PDOException` 23000 ante duplicado) actualizados.
- **Tests**: nuevo formato regex `^SU-[ABCDEFGHJKLMNPQRSTVWXYZ2-9]{6}-[ABCDEFGHJKLMNPQRSTVWXYZ2-9]{8}$`, que dos tenants sí pueden repetir código mientras el mismo tenant no (23000), reintento tras colisión simulada (`tests/Support/CollidingBarcodeRepository`) y fallo `barcodeGenerationFailed` (500) tras los 5 intentos.

### Verified (2026-09-29)

- `SHOW INDEX FROM stock_units`: únicos sólo `PRIMARY` y `uq_stock_units_tenant_barcode (tenant_id, codigo_barcode)`; eliminado `idx_codigo_barcode` y el `UNIQUE (codigo_barcode)` global; columna `varchar(64)`.
- `php -l` sin errores en `src/` y `tests/`; `composer test` en verde: `209 tests, 1867 assertions`.
- E2E en vivo: recepción 201 con barcode `SU-M59WJ2-AQQFXEXJ` (regex `^SU-…{6}-…{8}$` OK), replay con la misma `Idempotency-Key` → 201 con idéntico barcode (1 fila en BD), y los 3 códigos `CB22DB-…` preexistentes intactos (4 unidades, capacidad `12/20` en `A010210`).

## [1.5.0] - 2026-09-29

### Added

- **Recepción de inventario (Sprint 5, CU-001)**: `POST /api/inventory/receive` (`inventory.receiving.create`) ejecuta `ReceiveInventoryUseCase` dentro de **una sola transacción**: crea la unidad física (`StockUnit`, estado `DISPONIBLE` con ubicación destino), su `StockLedger` inicial (R1: `cantidad_disponible = cantidad_fisica - cantidad_reservada - cantidad_en_transito`), el evento `RECIBIDO` (`StockEvent`, bitácora append-only con `hash_integridad` SHA256 verificable), consume capacidad en la ubicación (1 slot por unidad, `ceil(cantidad_recibida)`) y devuelve `unidad_fisica_id` + `codigo_barcode` + confirmación. Valida producto activo, ubicación activa, cantidades > 0 y aislamiento de tenant (404/422).
- **Recepción parcial**: `cantidad_esperada` + `cantidad_recibida` (`recibida <= esperada`); si es menor, además de la unidad con lo recibido se registra la incidencia `DISCREPANCIA_CANTIDAD` (ABIERTA, con reporter) en la misma transacción, y la respuesta lo marca con `recepcion_parcial`.
- **Idempotencia (R7)**: header obligatorio `Idempotency-Key` (64 caracteres, `[A-Za-z0-9._:-]`) y nueva tabla `idempotency_keys` (`UNIQUE(tenant_id, idempotency_key, operacion)`, `request_hash`, `response_json`, `http_status`, índice de `created_at` para la limpieza >7 días) en `database/schema/schema.sql`, `database/schema/create_tables.ps1` y la BD viva. La key se INSERTA **dentro de la transacción de la operación**: misma key + mismo payload (hash canónico de claves ordenadas) → replay de la respuesta original sin ejecutar nada; payload distinto → 409; `ROLLBACK` también revierte la key. Servicio reutilizable `App\Shared\Services\IdempotencyService` (pendiente de movimientos/reservas/envíos).
- **Código de barras**: `BarcodeService` con prefijo del tenant (6 primeros caracteres del id) + parte aleatoria sin caracteres ambiguos (`0/1/O/I/U`), p. ej. `CB22DB-G4K5WM-65K987LV`, con reintentos sobre la columna UNIQUE `codigo_barcode`.
- **Stock actual**: `GET /api/inventory/stock` (`inventory.stock.view`) con `StockQueryService` (resumen de totales + filas por unidad enriquecidas con producto/ubicación), filtros `producto_id`, `ubicacion_id`, `estado`, `search` y paginación.
- **Dominio y puertos nuevos**: entidades `StockUnit`, `StockLedger`, `StockEvent`, `Incident` y VO `StockState`; interfaces/repositorios MySQL `StockUnit/StockLedger/StockEvent/IncidentRepository`; `StockCalculationService` (R1); nuevas fábricas de `InventoryException` (`invalidQuantity`, `availableQuantityCannotBeNegative`, `invalidStockState`, `barcodeGenerationFailed`, `idempotencyKeyRequired/Invalid`). Seam `DatabaseService::setConnection()` para inyectar la conexión en tests.
- **Suite 140 → 206 tests (493 → 1427 assertions)**: `ReceiveInventoryUseCaseTest` (28: happy path, R1, parcial+incidencia, validaciones, capacidad 422, atomicidad con trigger que falla al final de la transacción, aislamiento T1/T2, barcode, idempotencia replay/409/liberación de key, hash e integridad del evento), `ReceivingControllerTest` (401/400 de cabecera/replay/409), `StockQueryServiceTest`, `StockControllerTest`, `BarcodeServiceTest`, `StockCalculationServiceTest`, `InventoryReceivingRoutesTest`. Base `tests/Support/InventorySqliteTestCase` con los repositorios **reales** sobre SQLite (transacciones, rollback y UNIQUE como en producción).

### Verified (2026-09-29)

- Suite completa en verde: `206 tests, 1427 assertions` + `php -l` sin errores en `src/` y `tests/`.
- E2E en vivo contra MySQL: recepción 201 (barcode `CB22DB-…`) → misma key 201 con idéntica unidad/código (1 fila en BD) → mismo key con payload distinto 409 → sin key 400 → sin token 401 → parcial 201 con incidencia `DISCREPANCIA_CANTIDAD`; `GET /inventory/stock` 200 (resumen y filas) con ADMIN y con MANAGER.
- Datos en BD tras la prueba: 3 unidades `DISPONIBLE`, 3 ledger (5/4/1 con `disponible = física`), 3 eventos con hashes únicos de 64 caracteres, capacidad usada `10/20` (= `ceil(5)+ceil(4)+ceil(1)`), 3 claves de idempotencia con `http_status=201`.

## [1.4.0] - 2026-09-27

### Added

- **Dashboard (Sprint 4.5, spec §9)**: `GET /api/inventory/dashboard/critical` (CAPA 1, inmediata) y `GET /api/inventory/dashboard/secondary` (CAPA 2, consultada a los 5 s) con `DashboardService` (consultas SQL con `tenant_id` siempre desde los claims), `DashboardController` (envoltorio `{status,data}` + guardia 401) y rutas bajo `Auth + Tenant + Permission(inventory.stock.view) + RateLimit(api)` — permiso que tienen los cuatro roles, así el dashboard no depende de `tenants.tenant.view` (que sigue siendo sólo ADMIN).
- **Dashboard UI**: tarjeta "Stock resumido" (SKU, producto, unidades, cantidad — máximo 20 filas, sin stock primero) con alertas de productos sin stock / ubicaciones críticas / unidades en stock, y tarjeta "Operación" (ubicaciones disponibles, reservas activas, en tránsito, incidencias abiertas). Cada capa se degrada por separado si su endpoint falla; el timer se limpia en `destroy()`.
- **Rutas huérfanas**: `isModuleRegistered()` + `renderModuleUnavailable()` en el router — una ruta sin módulo registrado muestra la tarjeta "Módulo no disponible" en vez de un error, y se añade `escapeHtml()`.
- **`isDebugEnabled()` en `public/index.php`**: `exception`/`file`/`line` en los payloads de error sólo si `APP_DEBUG` es `true` (defensa en profundidad sobre H6/`APP_ENV`); verificado en vivo con `APP_DEBUG=false` → respuesta `{field, category}`.
- **Suite de pruebas 26 → 140 tests (120 → 493 assertions)**: `InventoryDashboardRoutesTest`, `DashboardServiceTest` y `DashboardControllerTest` (SQLite en memoria: aislamiento T1/T2, contadores, orden, `LIMIT 20`); `ProductServiceTest`, `LocationServiceTest`, `ProductControllerTest` y `LocationControllerTest` para el CRUD del Sprint 4; dobles `tests/Support/InMemoryProductRepository` e `InMemoryLocationRepository` con la misma semántica que los adaptadores MySQL (aislamiento por tenant, soft delete, filtros, orden y paginación).

### Fixed

- **Bucle infinito de redirección login ↔ dashboard** (`RangeError: Maximum call stack size exceeded`): la sesión era rechazada por el servidor pero seguía siendo válida en el cliente. `handleUnauthorized()` ahora llama a `AuthState.reset()` y los seis cambios de hash del router pasan por `safeRedirect()` (máximo 6 saltos en 2 s).
- **Tarjeta "Usuarios activos" enlazaba a la ruta muerta `#/users`**: ahora enlaza a `null` cuando el módulo no está registrado, así la tarjeta deja de aparecer en lugar de romper la navegación.
- **`updateActiveItem()` del sidebar**: la coincidencia exacta tiene prioridad sobre la de prefijo (`item.route + '/'`), por lo que `#/inventory/locations` ya no dejaba marcado el módulo `#/inventory` al recargar.
- Los menús de inventario se quedaban en estado activo tras navegar: `renderItems()` ya no fija la clase `active`; sólo `updateActiveItem()` decide la ruta activa.

### Changed (UI)

- Sidebar: estilos de usuario (`.sidebar-user`, `.sidebar-user-info`, `.sidebar-user-name`, `.sidebar-user-role`) y reglas `.sidebar-collapsed` que dejan únicamente los iconos en escritorio y móvil; el nombre de la plataforma vive sólo en el sidebar (el header no lleva `<h1>`).
- Tablas: `.table th.text-right` para que la última columna numérica no quede centrada por la regla `:last-child`.

### Verified (2026-09-27)

- Endpoints de dashboard 200 con ADMIN y MANAGER sobre los mismos datos tenant-scoped (`productos_activos=3`, `sin_stock=3`, `ubicaciones_activas=2`, `unidades_total=0`), 401 sin token y 403 en `/tenant/stats` para MANAGER sin afectar al dashboard.
- Chrome headless con ADMIN y MANAGER: 0 errores JS, tarjetas nuevas renderizadas tras 5 s, menú `dashboard, inventory, inventory/locations` sin rutas muertas.
- CRUD en vivo contra MySQL: producto 201 → by-sku 200 → PATCH 200 → DELETE 200 (recuento sin residuos) e idem para ubicaciones, con `GET /{id}` → 404 tras el borrado.

## [1.3.0] - 2026-09-26

### Security (Session lifecycle)

- **Refresh token rotation with reuse detection** (`refresh_tokens` table): each login starts a token family (`fam` claim); every refresh marks the old token as used and issues a new one. Presenting a used/revoked refresh token revokes the whole family (token-theft response). Pre-upgrade refresh tokens are rejected — one-time re-login after deploying.
- **Logout revokes the session family and denylists the access token**: `/auth/logout` revokes all refresh tokens of the family and writes `session:act:{fam}=0` (TTL = access lifetime), so a stolen access token dies immediately instead of staying valid up to 1h after logout.
- **Sliding inactivity window enforced per request**: `AuthenticationMiddleware` reads Redis `session:act:{fam}` on every authenticated request — key missing (idle > `SESSION_TIMEOUT`) → 401; denylist marker → 401; active → TTL renewed. Fail-open when Redis is down (policy A, logged).
- **Inactivity gate on refresh**: refresh is rejected and the family revoked when the activity window expired.
- **Frontend idle timer** (`public/js/core/idle.js`): logs the user out after `AppConfig.sessionTimeout` seconds without mouse/keyboard/scroll/touch activity (toast + revoke + redirect). Handles reload-with-session, logout and `auth:unauthorized` events.
- **Session keep-alive**: new `POST /auth/ping` (zero DB — the middleware renews the window) called by the idle timer every `SESSION_TIMEOUT/3` while the session is active; users working without API calls no longer lose the window.
- **`/auth/me` defense in depth**: endpoint re-validates token type and active user state (previously relied solely on the middleware).

### Fixed (Session/infra)

- **`RedisService::ping()` always returned `false`** under Predis (`'PONG' === true`), which silently disabled the new inactivity gate (permanent fail-open).
- **Redis outage caused HTTP 500** on any authenticated request: `get/set/delete/exists/expire/flush/ping` now fail open with `error_log`; the rate limiter degrades to "no block" instead of erroring (F2).
- **Rate-limit double counter on successful login**: both legacy and HTTP key formats are now cleared (F1); the `:meta` key had no TTL (F4) — now expires with the window.
- **Disabled/suspended users kept working up to 1h** (M2): `AuthenticationMiddleware` re-checks `isActive` per request.
- **Logout was a no-op server-side** (M1): handler now receives the request claims and revokes the family.
- **Malformed JSON bodies triggered PHP warnings**: `email`, `password` and `refresh_token` are string-checked before casting.

### Added

- `refresh_tokens` table (in `schema.sql` and live DB): family/rotation/revocation columns, FK cascade to `users`/`tenants`, periodic cleanup of expired rows on login.
- `POST /api/auth/ping` keep-alive endpoint (Auth + rate limit).
- `AppConfig.sessionTimeout` injected from `.env` — single source of truth for the backend gate and the frontend timer.
- `public/js/core/idle.js` registered in the SPA layout.
- **(H2) Admin API surface**: `users.*` (index/show/store/update/destroy/getRoles/assignRole/removeRole), `roles.*` (CRUD + getPermissions/updatePermissions/restore) and `tenant.*` (show/stats/update/activate) routes, each behind `Auth + Tenant + Permission + RateLimit(api)`. 10 new permission rows seeded (`users.user.*`, `roles.role.*`, `tenants.tenant.*`) and added to the ADMIN role (23 → 33 permissions); permission cache cleared.
- **(H3) Test suite**: `phpunit.xml` + `tests/Unit/` (RateLimiter, JwtService, InputSanitizer, InventoryRoutesMiddlewareTest) — 26 tests / 120 assertions via `composer test`.
- **(H7) `AuthorizationService::invalidateCacheForRole()`**: drops `auth:perms:{tenant}:{user}` for every holder of a mutated role.

### Fixed (Security findings H1–H7, 2026-09-26)

- **(H1) `rate_limit_config`/`rate_limit_log` were dead tables**: new `RateLimitRepository` (`findActiveOverride`, `logExceeded`); `RateLimiter::getEndpointConfig()` resolves per-endpoint limits (DB override > syntax override > action default, cached per request, fail-open); `RateLimitMiddleware` honours `tipo_limit` (POR_IP / POR_ENDPOINT / POR_USUARIO) and logs every rejected request. Verified live: override 2/300 → Remaining 1 → 0 → 429 + `rate_limit_log` row.
- **(H2) Claim/param bugs that blocked the new routes**: `requireAdmin()` in `TenantController`/`RoleController` matched the string `'ADMIN'` against the signed claim (an array of role *objects* — never a match); `UserController::getRouteParam()` assumed prefix-less URLs and returned `api` under `/xp/public/…` — replaced by Router-injected `params` (signatures now `array $request, array $params`).
- **(H4) Inventory routes applied `[Auth, Tenant]` twice** (group default + every one of the 20 routes): middleware executed twice per request. Removed per-route duplicates; group-level default is the single enforcement point (guarded by `tests/Unit/InventoryRoutesMiddlewareTest.php`).
- **(H5) Idle timer logged out every tab on any single-tab inactivity**: `idle.js` now broadcasts activity via `localStorage` (`xp:idle:activity`, throttled 1s) and listens for `storage` events — a tab where the user is active resets the peer timer without re-emitting (anti-loop), so only genuinely idle sessions expire.
- **(H7) Permission cache revocation was misdirected**: `RoleController` invalidated only the *admin's own* cache on role mutations, and `assignRole`/`removeRole` never invalidated at all — affected users kept (or kept missing) permissions for up to 60s. Mutations now call `invalidateCacheForRole()` / `invalidateCache()` for the affected principals. Verified E2E: revoking a permission → 403 on the next request (no TTL wait); restoring → 200 immediately.
- **(H6) Verified, no code change**: error payloads include `exception`/`file`/`line` only when `APP_ENV != production` (`public/index.php:62`). Probe with `APP_ENV=production` returns `{field, category}` only; `.env` here stays `development` intentionally for local debugging — switch before deploying.

### Audit (2026-09-26)

- Web exposure verified live: `.env`, `composer.*`, `bootstrap.php`, `schema.sql`, `backups/`, `src/`, `vendor/`, `tests/` → 403 (root `.htaccess` `!^public/` + `FilesMatch "^\."`); directory indexes off; security headers present (CSP, X-Frame-Options, COOP/CORP, Referrer-Policy, Permissions-Policy).
- Attack probes passed: JWT `alg:none` → 401; cross-tenant claims (real user + foreign tenant and the reverse) → 401; SQLi in login body and list query params → no leakage; refresh token used as access → 401; path traversal plain and percent-encoded → 403; unsupported methods → 404; rate limits: login 5/5 min, refresh 10/min, endpoint windows intact.
- Functional smoke: login → me → ping → refresh rotation → logout (access immediately dead) → inventory product CRUD (201 / list / 200 / soft-delete / gone) → suspended user blocked per request (401) → restored (200) → fail-open under simulated Redis outage (login/me/refresh/logout all 200 with logged fail-open).
- Known limitations (documented, not closed): audit/dashboard endpoints are still permission-free (deliberately out of scope for H2 — audit has no UI/API yet); `rate_limit_config` DELAY/CHALLENGE strategies are not implemented (REJECT only, per H1 scope); `APP_ENV=development` exposes `file`/`line` in error payloads by design — verified that `APP_ENV=production` strips them (H6), switch before deploying.

## [1.2.0] - 2026-09-25

### Security

- **Root `.htaccess`**: direct HTTP access to `.env`, `composer.json`, `CHANGELOG.md`, `database/*.sql`, `src/` and `vendor/` outside `/public` now returns 403 (verified).
- **X-Forwarded-For trust gate**: the XFF header is only honored when `REMOTE_ADDR` belongs to `TRUSTED_PROXIES` (empty by default), closing rate-limit/IP-spoofing bypass in `client_ip()`, `Router::getClientIp()`, `RateLimitMiddleware` and `AuthController`.
- **Secrets rotated**: `JWT_SECRET` and `ENCRYPTION_KEY` regenerated (64-char); existing sessions invalidated (re-login required).
- **Request body limit**: payloads over 1 MB are rejected with 413.
- **XSS in error renderer**: `loader.js` `renderError()` escapes `moduleName` and `message`.
- **Duplicate SQL placeholders**: `UserService` search queries used `:search` multiple times with emulation off (500).

### Fixed (Inventory)

- Products and locations list/search: repeated `:search` placeholder caused 500 on search.
- `Product` and `Location` entities now persist `sku`, `unidad_medida`, `codigo` and `tipo` on update (previously silently dropped).
- `metadatos` no longer corrupted by the encode/decode round-trip (`decodeMetadatos` unwrap).
- Duplicate key errors (23000) mapped to 409 with `field` (`skuAlreadyExists`, `locationCodeAlreadyExists`).
- `restore()` reactivates rows (`activo=1` / `activa=1`).
- `hasMovements()` used `:lid` twice → 500 when deleting a location.
- Product/Location controllers no longer swallow exceptions, preserving `field`/`category` in error JSON.
- Pagination controls in `products.js` and `locations.js` resolved the wrong click target.
- New `public/js/core/toast.js` (textContent-only rendering) registered in `index.php`; UI toasts actually display now.
- `schema.sql`/`create_tables.ps1` synced with live schema: products `categoria`, `peso`, `volumen`, `dimensiones` + `idx_tenant_categoria`.
- Unit-of-measure and location-type validation gaps closed in `UserService` (`:search`) and update paths.

### Added (Inventory validations)

- **Products**: `categoria` is mandatory; `KG` requires `peso`; `M3`/`LT` require `volumen` — enforced at entity level on create and update, with inline field errors and form hints.
- **Locations**: `TRANSITO` (virtual) locations cannot have `capacidad`; `capacidad` can never be lower than `capacidad_usada` (422); `tipo` required on update; capacity can now be cleared from the UI (explicit `null`).

### Fixed (UI)

- **Module switch listener leak**: `loader.js` now tracks the active module per container (`activeByContainer`) and calls `destroy()` on the previous one before mounting; cached modules remount via `init()` instead of `onActivate`. After visiting locations and returning to inventory, the "Nuevo producto" button no longer opened the locations modal. `bindEvents()` made idempotent (remove before add) in products and locations.
- **Login platform name hardcoded**: the login `<h1>` now reads `window.AppConfig.appName` (injected from `APP_NAME` in `.env`) with fallback, instead of a hardcoded "XP Platform".
- Modal overflow: the `<form>` wrapping `modal-body`/`modal-footer` is now a flex column (`.modal > form`), so the body scrolls and buttons/alert stay inside the modal; validation errors auto-scroll into view.
- Discontinuous table row separators: `.table-actions` no longer forces `display:flex` on a `<td>` (breaks table-cell layout and misplaces `border-bottom`).
- "Acciones" header centered above the action buttons in products and locations tables.

### Security Notes

- `APP_ENV=development` + `APP_DEBUG=true` still expose `file`/`line` in API error payloads — switch to production before deploying.
- CSP keeps `'unsafe-inline'`/`'unsafe-eval'` (Tailwind CDN + inline bootstrap) — acceptable for beta, plan a strict policy.
- Local XAMPP MySQL uses `root` with no password — environment-only, not for production.

## [1.1.0] - 2026-09-19

### Fixed

- **SQL Injection** in `BaseRepository`: Filter keys are now validated against an abstract `getAllowedFilters()` whitelist before being interpolated into SQL queries (`BaseRepository.php:72,124,303`).
- **SQL Injection** in `DatabaseService`: Table and column identifiers are validated with regex `^[a-zA-Z_][a-zA-Z0-9_]*$` before use in INSERT, UPDATE, DELETE queries (`DatabaseService.php:100-107`).
- **Reflected XSS** in error page: Exception messages are now escaped with `htmlspecialchars()` before rendering in HTML (`index.php:85`).
- **XSS in SPA layout**: `$appName` and `$baseUrl` are escaped with `htmlspecialchars()` for HTML attributes and `json_encode()` for JavaScript context (`index.php:153-158`).
- **JWT Secret fallback**: Removed insecure `'default_secret'` fallback. Application now throws `RuntimeException` if `JWT_SECRET` is not configured (`JwtService.php:24-27`).
- **Unfiltered `$_GET` in UserController**: Filter parameters are now whitelisted to `['page', 'per_page', 'estado', 'search']` before passing to service layer (`UserController.php:35-36`).
- **IP Spoofing**: `client_ip()` helper now validates IPs with `filter_var()` and handles comma-separated `X-Forwarded-For` values (`helpers.php:179-195`).
- **Path Traversal in ConfigService**: Config file names are validated with regex before `require()` to prevent directory traversal (`ConfigService.php:85-87`).
- **Route params polluting `$_GET`**: Removed mutation of `$_GET` superglobal in Router. Route params are now passed only via `$request['params']` and handler arguments (`Router.php:103-105`).
- **Logout without authentication**: Restored `AuthenticationMiddleware` on logout endpoint (`api.php:36-39`).
- **Debug mode enabled by default**: Changed `APP_ENV` default from `'development'` to `'production'` to prevent information disclosure (`bootstrap.php:15`, `index.php`).
- **Silent SecurityHeaders failure**: Security header middleware errors are now always logged, not only in non-production (`index.php:120`).
- **`dd()`/`dump()` XSS and data leak**: Functions now use `var_export()` + `htmlspecialchars()` and are disabled in production mode (`helpers.php:153-182`).
- **Password displayed in seeder output**: Admin password no longer printed to stdout in `PlatformSeeder` (`PlatformSeeder.php:205`).

### Added

- Abstract method `getAllowedFilters(): array` in `BaseRepository` to enforce column whitelisting in subclasses.
- `validateIdentifier()` private method in `DatabaseService` for table/column name validation.
- Security documentation in `routes/api.php` explaining why CSRF is not applied with JWT Bearer tokens.
- Security warning in `PlatformSeeder.php` header comment.

### Security Notes

- CSRF protection is intentionally not applied because the application uses JWT Bearer tokens (not vulnerable to CSRF). If cookie-based authentication is implemented in the future, `CsrfToken` middleware must be enabled on affected routes.
- This version requires `JWT_SECRET` to be set in `.env`. The application will not start without it.

## [1.0.0] - 2026-09-18

### Added

- Initial release of XP Platform.
- Multi-tenant SaaS architecture with domain-driven design.
- JWT-based authentication with access and refresh tokens.
- Role-based authorization with permissions.
- Security headers middleware (CSP, HSTS, X-Frame-Options).
- Rate limiting middleware.
- AES-256-GCM encryption service.
- Input sanitization service.
- Redis-based session and cache layer.
- Custom DI container, router, and service architecture.
