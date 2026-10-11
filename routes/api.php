<?php
declare(strict_types=1);

/**
 * Rutas de la API
 *
 * Este archivo es cargado por el Router desde public/index.php.
 * $router está disponible en el scope global de este archivo.
 *
 * NOTA DE SEGURIDAD - CSRF:
 * CSRF no se aplica actualmente porque la autenticación usa JWT Bearer tokens,
 * que no son vulnerables a ataques CSRF (el header Authorization no se envía
 * automáticamente por el navegador). Si se implementa auth basada en cookies
 * httpOnly, se debe habilitar CsrfToken middleware en las rutas afectadas.
 */

use App\Core\Audit\AuditMiddleware;
use App\Core\Audit\Http\AuditLogController;
use App\Core\Authentication\Http\AuthController;
use App\Core\Authentication\Http\Middleware\AuthenticationMiddleware;
use App\Core\Authorization\Http\PermissionController;
use App\Core\Authorization\Http\RoleController;
use App\Core\Authorization\Http\TemporaryPermissionController;
use App\Core\Authorization\Middleware\PermissionMiddleware;
use App\Core\Groups\Http\GroupController;
use App\Core\Security\Middleware\RateLimitMiddleware;
use App\Core\Tenants\Http\TenantController;
use App\Core\Tenants\Middleware\TenantMiddleware;
use App\Core\Users\Http\UserController;
use App\Shared\Http\LocaleController;

/** @var App\Shared\Router\Router $router */

// ============================================
// AUTH - Rutas públicas (no requieren token)
// ============================================

// Login: rate limit estricto (5 intentos / 5 min) para prevenir brute force
$router->post('/api/auth/login', [AuthController::class, 'login'], [
    AuditMiddleware::class,
    RateLimitMiddleware::class . ':login',
]);

// Refresh: rate limit medio (10 req / 1 min)
$router->post('/api/auth/refresh', [AuthController::class, 'refresh'], [
    AuditMiddleware::class,
    RateLimitMiddleware::class . ':critical',
]);

// ============================================
// AUTH - Rutas protegidas (requieren token)
// ============================================

// Logout: rate limit estándar (100 req / 1 min)
$router->post('/api/auth/logout', [AuthController::class, 'logout'], [
    AuditMiddleware::class,
    RateLimitMiddleware::class . ':api',
    AuthenticationMiddleware::class,
]);

// Me: rate limit estándar (100 req / 1 min)
$router->get('/api/auth/me', [AuthController::class, 'me'], [
    RateLimitMiddleware::class . ':api',
    AuthenticationMiddleware::class,
]);

// Ping de sesión (keep-alive): renueva la ventana de inactividad vía middleware
$router->post('/api/auth/ping', [AuthController::class, 'ping'], [
    RateLimitMiddleware::class . ':api',
    AuthenticationMiddleware::class,
]);

// Cambio de contraseña (v1.11.0): única vía de salida del cambio forzado;
// está en la allowlist del AuthenticationMiddleware (password_change_required)
// v1.13.2: perfil crítico y POR USUARIO (RateLimit va después de Auth para
// que la clave sea user:<id>): 10 intentos / 5 min en lugar del genérico
// 100/min de `api` — evita la fuerza bruta al password actual con un token.
$router->post('/api/auth/change-password', [AuthController::class, 'changePassword'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    RateLimitMiddleware::class . ':10,300',
]);

// ============================================
// USERS - Gestión de usuarios del tenant
// ============================================
// Permisos (seeded en BD): users.user.view/create/edit/delete
// Convención: ver/listar → view, crear → create, editar/roles → edit, eliminar → delete.

// GET /api/users
// OR: users.user.view (pantalla Usuarios) o groups.group.manage_members
// (el modal de alta de miembro de grupos necesita buscar usuarios).
$router->get('/api/users', [UserController::class, 'index'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':users.user.view,groups.group.manage_members',
    RateLimitMiddleware::class . ':api',
]);

// GET /api/users/me/preferences (v1.10.3): preferencias del propio usuario.
// Sin PermissionMiddleware de modulo: cada usuario lee/sucribe las suyas
// (claims de tenant + user_id). Declarada antes de /api/users/{id} para que
// "me" no pueda capturarse como id.
$router->get('/api/users/me/preferences', [UserController::class, 'mePreferences'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    RateLimitMiddleware::class . ':api',
]);

// PATCH /api/users/me/preferences (v1.10.3): cambio de preferencias,
// auditado (AuditMiddleware + auditoría de dominio en el controller).
$router->patch('/api/users/me/preferences', [UserController::class, 'updateMePreferences'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    RateLimitMiddleware::class . ':api',
]);

// GET /api/users/{id}
$router->get('/api/users/{id}', [UserController::class, 'show'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':users.user.view',
    RateLimitMiddleware::class . ':api',
]);

// POST /api/users
$router->post('/api/users', [UserController::class, 'store'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':users.user.create',
    RateLimitMiddleware::class . ':api',
]);

// POST /api/users/{id}/status (v1.11.0): alta/baja de estado con motivo
$router->post('/api/users/{id}/status', [UserController::class, 'updateStatus'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':users.user.status',
    RateLimitMiddleware::class . ':api',
]);

// POST /api/users/{id}/password/reset (v1.11.0): password temporal + revoca sesiones
$router->post('/api/users/{id}/password/reset', [UserController::class, 'resetPassword'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':users.password.reset',
    RateLimitMiddleware::class . ':api',
]);

// PATCH /api/users/{id}
$router->patch('/api/users/{id}', [UserController::class, 'update'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':users.user.edit',
    RateLimitMiddleware::class . ':api',
]);

// DELETE /api/users/{id}
$router->delete('/api/users/{id}', [UserController::class, 'destroy'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':users.user.delete',
    RateLimitMiddleware::class . ':api',
]);

// GET /api/users/{id}/roles
$router->get('/api/users/{id}/roles', [UserController::class, 'getRoles'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':users.user.view',
    RateLimitMiddleware::class . ':api',
]);

// POST /api/users/{id}/roles
$router->post('/api/users/{id}/roles', [UserController::class, 'assignRole'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':users.user.edit',
    RateLimitMiddleware::class . ':api',
]);

// DELETE /api/users/{id}/roles/{roleId}
$router->delete('/api/users/{id}/roles/{roleId}', [UserController::class, 'removeRole'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':users.user.edit',
    RateLimitMiddleware::class . ':api',
]);

// ============================================
// ROLES - Gestión de roles del tenant
// ============================================
// Permisos (seeded en BD): roles.role.view/create/edit/delete

// GET /api/roles
// OR: roles.role.view (pantalla Roles) o groups.group.manage_roles
// (el modal de asignar rol de grupos necesita el catalogo de roles).
$router->get('/api/roles', [RoleController::class, 'index'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':roles.role.view,groups.group.manage_roles',
    RateLimitMiddleware::class . ':api',
]);

// GET /api/roles/{id}
$router->get('/api/roles/{id}', [RoleController::class, 'show'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':roles.role.view',
    RateLimitMiddleware::class . ':api',
]);

// POST /api/roles
$router->post('/api/roles', [RoleController::class, 'store'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':roles.role.create',
    RateLimitMiddleware::class . ':api',
]);

// PATCH /api/roles/{id}
$router->patch('/api/roles/{id}', [RoleController::class, 'update'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':roles.role.edit',
    RateLimitMiddleware::class . ':api',
]);

// DELETE /api/roles/{id}
$router->delete('/api/roles/{id}', [RoleController::class, 'destroy'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':roles.role.delete',
    RateLimitMiddleware::class . ':api',
]);

// GET /api/roles/{id}/permissions
$router->get('/api/roles/{id}/permissions', [RoleController::class, 'getPermissions'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':roles.role.view',
    RateLimitMiddleware::class . ':api',
]);

// PATCH /api/roles/{id}/permissions
$router->patch('/api/roles/{id}/permissions', [RoleController::class, 'updatePermissions'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':roles.role.edit',
    RateLimitMiddleware::class . ':api',
]);

// ============================================
// PERMISSIONS - Catálogo de permisos (v1.11.0)
// ============================================
// Permisos (seeded por PlatformControlPermissionSeeder):
// permissions.permission.view → ADMIN, MANAGER, USER, VIEWER.
// Sólo lectura: el alta/baja de permisos la hacen los seeders (spec §5.2).

// GET /api/permissions
$router->get('/api/permissions', [PermissionController::class, 'index'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':permissions.permission.view',
    RateLimitMiddleware::class . ':api',
]);

// GET /api/permissions/{id}
$router->get('/api/permissions/{id}', [PermissionController::class, 'show'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':permissions.permission.view',
    RateLimitMiddleware::class . ':api',
]);

// ============================================
// GROUPS - Grupos de usuarios (v1.11.0, spec §4.2)
// ============================================
// Permisos (sembrados por PlatformControlPermissionSeeder):
// groups.group.view/create/edit/delete/manage_members/manage_roles.
// POST /groups/{id}/roles aplica escalada de actor >= rol y >= cada miembro.

// GET /api/groups
$router->get('/api/groups', [GroupController::class, 'index'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.view',
    RateLimitMiddleware::class . ':api',
]);

// GET /api/groups/{id}
$router->get('/api/groups/{id}', [GroupController::class, 'show'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.view',
    RateLimitMiddleware::class . ':api',
]);

// GET /api/groups/{id}/members
$router->get('/api/groups/{id}/members', [GroupController::class, 'members'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.view',
    RateLimitMiddleware::class . ':api',
]);

// GET /api/groups/{id}/roles
$router->get('/api/groups/{id}/roles', [GroupController::class, 'roles'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.view',
    RateLimitMiddleware::class . ':api',
]);

// POST /api/groups
$router->post('/api/groups', [GroupController::class, 'store'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.create',
    RateLimitMiddleware::class . ':api',
]);

// PATCH /api/groups/{id}
$router->patch('/api/groups/{id}', [GroupController::class, 'update'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.edit',
    RateLimitMiddleware::class . ':api',
]);

// DELETE /api/groups/{id}
$router->delete('/api/groups/{id}', [GroupController::class, 'destroy'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.delete',
    RateLimitMiddleware::class . ':api',
]);

// POST /api/groups/{id}/members
$router->post('/api/groups/{id}/members', [GroupController::class, 'addMember'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.manage_members',
    RateLimitMiddleware::class . ':api',
]);

// DELETE /api/groups/{id}/members/{userId}
$router->delete('/api/groups/{id}/members/{userId}', [GroupController::class, 'removeMember'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.manage_members',
    RateLimitMiddleware::class . ':api',
]);

// POST /api/groups/{id}/roles
$router->post('/api/groups/{id}/roles', [GroupController::class, 'assignRole'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.manage_roles',
    RateLimitMiddleware::class . ':api',
]);

// DELETE /api/groups/{id}/roles/{roleId}
$router->delete('/api/groups/{id}/roles/{roleId}', [GroupController::class, 'removeRole'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':groups.group.manage_roles',
    RateLimitMiddleware::class . ':api',
]);

// ============================================
// TEMPORARY PERMISSIONS - Permisos temporales (v1.11.0, spec §5.3)
// ============================================
// Permisos (sembrados por PlatformControlPermissionSeeder):
// temporary.permission.view → ADMIN, MANAGER, USER, VIEWER
// temporary.permission.grant → sólo ADMIN
// temporary.permission.revoke → ADMIN, MANAGER
// POST aplica escalada (actor >= beneficiario, sólo ADMIN sobre ADMIN) y,
// si el permiso otorgado es CRITICAL, exige rol ADMIN vigente y rate limit
// de 2 intentos/hora (aplicado dentro del controller, por actor).

// GET /api/temporary-permissions?user_id=&include_inactive=
$router->get('/api/temporary-permissions', [TemporaryPermissionController::class, 'index'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':temporary.permission.view',
    RateLimitMiddleware::class . ':api',
]);

// POST /api/temporary-permissions
$router->post('/api/temporary-permissions', [TemporaryPermissionController::class, 'store'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':temporary.permission.grant',
    RateLimitMiddleware::class . ':api',
]);

// DELETE /api/temporary-permissions/{id}
$router->delete('/api/temporary-permissions/{id}', [TemporaryPermissionController::class, 'revoke'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':temporary.permission.revoke',
    RateLimitMiddleware::class . ':api',
]);

// ============================================
// TENANT - Gestión del tenant actual (solo ADMIN)
// ============================================
// Permisos (seeded en BD): tenants.tenant.view/edit
// El controller valida además rol ADMIN vía claims (defense in depth).

// GET /api/tenant
$router->get('/api/tenant', [TenantController::class, 'show'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':tenants.tenant.view',
    RateLimitMiddleware::class . ':api',
]);

// GET /api/tenant/stats
$router->get('/api/tenant/stats', [TenantController::class, 'stats'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':tenants.tenant.view',
    RateLimitMiddleware::class . ':api',
]);

// PATCH /api/tenant
$router->patch('/api/tenant', [TenantController::class, 'update'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':tenants.tenant.edit',
    RateLimitMiddleware::class . ':api',
]);

// POST /api/tenant/activate
$router->post('/api/tenant/activate', [TenantController::class, 'activate'], [
    AuditMiddleware::class,
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':tenants.tenant.edit',
    RateLimitMiddleware::class . ':api',
]);

// ============================================
// AUDIT LOG (spec §7.1)
// ============================================
// Lectura de la bitácora de auditoría. Sólo lectura: no existe endpoint de
// escritura/edición (append-only desde AuditMiddleware y AuditLogger).
// Permisos (seeded por AuditPermissionSeeder): audit.log.view → ADMIN, MANAGER.

// ============================================
// I18N (v1.10.1, spec Internacionalizacion)
// ============================================
// Diccionarios de traduccion: resources/locales/{es,en,pt,fr}.json con cache
// en Redis, ETag y version del diccionario. Autenticado SI, sin permiso de
// modulo (el contenido es el mismo para toda la aplicacion); cualquier
// idioma fuera de la whitelist => 404 (sin path traversal: el nombre de
// fichero se construye despues de validar, ver LocaleDictionaryService).
// Frontend: GET /api/locales/es.json (la extension .json es opcional).
$router->get('/api/locales/{locale}', [LocaleController::class, 'show'], [
    RateLimitMiddleware::class . ':api',
    AuthenticationMiddleware::class,
]);

// GET /api/audit/logs?fecha_desde=&fecha_hasta=&usuario_id=&accion=&entidad=&resultado=&page=&per_page=
$router->get('/api/audit/logs', [AuditLogController::class, 'index'], [
    AuthenticationMiddleware::class,
    TenantMiddleware::class,
    PermissionMiddleware::class . ':audit.log.view',
    RateLimitMiddleware::class . ':api',
]);

// ============================================
// INVENTORY MODULE (Sprint 4)
// ============================================
// Carga las rutas del módulo Inventory desde su archivo dedicado.
// El archivo src/Modules/Inventory/routes.php define todas las rutas
// de productos y ubicaciones con sus middlewares (Auth, Tenant, Permission, RateLimit).
$router->loadRoutes(BASE_PATH . '/src/Modules/Inventory/routes.php');
