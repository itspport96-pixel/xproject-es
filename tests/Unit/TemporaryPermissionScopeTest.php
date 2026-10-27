<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authorization\Repository\PermissionRepositoryInterface;
use App\Core\Authorization\Repository\RoleRepositoryInterface;
use App\Core\Authorization\Service\AuthorizationService;
use App\Core\Authentication\Repository\UserRepositoryInterface;
use App\Shared\Services\RedisService;
use Tests\Support\PlatformControlSqliteTestCase;

/**
 * Ambito de los permisos temporales (v1.19.7, bloque 3).
 *
 * `temporary_permissions.recurso_id` acota el temporal a un recurso concreto,
 * pero `getUserPermissions()` lo ignoraba: un temporal de "unidad_fisica:12345"
 * autorizaba en TODOS los recursos via PermissionMiddleware. Ahora:
 *
 *  - sin ambito: sólo los temporales con `recurso_id IS NULL`;
 *  - con ambito: los globales + los de ese recurso;
 *  - la entrada de cache es UNA por usuario y el ambito se aplica al leer,
 *    de modo que `invalidateCache()` purga todos los ambitos de una vez.
 *
 * AuthorizationService y repositorios REALES sobre SQLite; la cache es un
 * doble en memoria con la semantica que el servicio espera.
 */
final class TemporaryPermissionScopeTest extends PlatformControlSqliteTestCase
{
    private const U = 'u-scope';
    private const P_ROL = 'users.user.view';
    private const P_GLOBAL = 'inventory.product.create';
    private const P_RECURSO = 'inventory.stock.take';
    private const RECURSO = 'unidad_fisica:12345';

    private ScopeCacheRedisService $cache;
    protected AuthorizationService $authz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPermission(self::P_ROL);
        $idGlobal = $this->seedPermission(self::P_GLOBAL);
        $idRecurso = $this->seedPermission(self::P_RECURSO);

        $this->seedRole('role-scope', 'Scope', [self::P_ROL]);
        $this->seedUser(self::U);
        $this->assignRole(self::U, 'role-scope');

        $expira = gmdate('Y-m-d H:i:s', time() + 3600);
        $this->seedTemporaryPermission(self::U, $idGlobal, $expira, true, null);
        $this->seedTemporaryPermission(self::U, $idRecurso, $expira, true, self::RECURSO);

        $this->cache = new ScopeCacheRedisService();
        $this->authz = new AuthorizationService(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(RoleRepositoryInterface::class),
            $this->createStub(PermissionRepositoryInterface::class),
            $this->db,
            $this->cache
        );
    }

    // ====================================================================
    // AMBITO EN LA LECTURA
    // ====================================================================

    public function testSinAmbitoElTemporalDeRecursoNoAutoriza(): void
    {
        $permisos = $this->authz->getUserPermissions(self::U, self::TENANT);

        $this->assertContains(self::P_ROL, $permisos, 'El permiso de rol sigue');
        $this->assertContains(self::P_GLOBAL, $permisos, 'El temporal global sigue');
        $this->assertNotContains(
            self::P_RECURSO,
            $permisos,
            'El temporal concedido para un recurso no autoriza sin ambito'
        );
    }

    public function testConAmbitoIncluyeElTemporalDeEseRecurso(): void
    {
        $permisos = $this->authz->getUserPermissions(self::U, self::TENANT, self::RECURSO);

        $this->assertContains(self::P_ROL, $permisos, 'Los permisos de rol no dependen del ambito');
        $this->assertContains(self::P_GLOBAL, $permisos, 'El temporal global autoriza en cualquier ambito');
        $this->assertContains(self::P_RECURSO, $permisos, 'El temporal de ese recurso autoriza en el');
    }

    public function testAmbitoDistintoNoHeredaElTemporalDeOtroRecurso(): void
    {
        $permisos = $this->authz->getUserPermissions(self::U, self::TENANT, 'unidad_fisica:99999');

        $this->assertContains(self::P_ROL, $permisos);
        $this->assertContains(self::P_GLOBAL, $permisos);
        $this->assertNotContains(self::P_RECURSO, $permisos, 'Otro recurso no hereda el temporal');
    }

    // ====================================================================
    // CACHE: LA ENTRADA ES UNA Y EL AMBITO SE APLICA AL LEER
    // ====================================================================

    public function testElAmbitoSeAplicaTambienSobreLaEntradaCacheada(): void
    {
        // Primera lectura sin ambito: llena la cache.
        $sinAmbito = $this->authz->getUserPermissions(self::U, self::TENANT);
        $this->assertNotContains(self::P_RECURSO, $sinAmbito);

        // La lectura con ambito se sirve de esa misma entrada.
        $conAmbito = $this->authz->getUserPermissions(self::U, self::TENANT, self::RECURSO);
        $this->assertContains(self::P_RECURSO, $conAmbito, 'El ambito se aplica sobre la cache');

        // Y volver a leer sin ambito no arrastra el temporal del recurso.
        $otraVez = $this->authz->getUserPermissions(self::U, self::TENANT);
        $this->assertNotContains(
            self::P_RECURSO,
            $otraVez,
            'La entrada cacheada sin ambito no contamina a la lectura global'
        );
        $this->assertSame($sinAmbito, $otraVez, 'Misma entrada, mismo resultado');
    }

    public function testInvalidateCachePurgaTodosLosAmbitosDeUnaVez(): void
    {
        $this->assertContains(
            self::P_RECURSO,
            $this->authz->getUserPermissions(self::U, self::TENANT, self::RECURSO),
            'Cachea ambos ambitos'
        );

        $this->authz->invalidateCache(self::U, self::TENANT);
        $this->pdo->prepare('UPDATE temporary_permissions SET activo = 0 WHERE user_id = ?')
            ->execute([self::U]);

        $this->assertNotContains(
            self::P_RECURSO,
            $this->authz->getUserPermissions(self::U, self::TENANT, self::RECURSO),
            'Revocado: el ambito lo ve de inmediato (sin esperar al TTL)'
        );
        $this->assertNotContains(
            self::P_GLOBAL,
            $this->authz->getUserPermissions(self::U, self::TENANT),
            'Revocado: la lectura sin ambito tambien'
        );
        $this->assertContains(self::P_ROL, $this->authz->getUserPermissions(self::U, self::TENANT));
    }

    public function testEntradaCaducadaSeRecalculaEnTodosLosAmbitos(): void
    {
        $this->assertContains(
            self::P_RECURSO,
            $this->authz->getUserPermissions(self::U, self::TENANT, self::RECURSO)
        );

        // Revoca sin pasar por invalidateCache: sólo la caducidad de la
        // entrada (expira_en) puede refrescarla.
        $this->pdo->prepare('UPDATE temporary_permissions SET activo = 0 WHERE user_id = ?')
            ->execute([self::U]);

        $key = 'auth:perms:' . self::TENANT . ':' . self::U;
        $entrada = json_decode((string) $this->cache->get($key), true);
        $this->assertIsArray($entrada);
        $this->assertArrayHasKey('por_recurso', $entrada, 'Formato v1.19.7 con temporales por ambito');
        $entrada['expira_en'] = gmdate('Y-m-d H:i:s', time() - 1);
        $this->cache->set($key, json_encode($entrada));

        $this->assertNotContains(
            self::P_RECURSO,
            $this->authz->getUserPermissions(self::U, self::TENANT, self::RECURSO),
            'Entrada caducada: se recalcula y el temporal revocado desaparece'
        );
        $this->assertNotContains(
            self::P_GLOBAL,
            $this->authz->getUserPermissions(self::U, self::TENANT)
        );
    }

    public function testEntradaPreviaSinPorRecursoSeIgnora(): void
    {
        // Entrada escrita por una versión anterior (sin `por_recurso`): su
        // `permisos` mezclaba los temporales de recurso con los globales, asi
        // que no se sirve: se recalcula.
        $this->authz->getUserPermissions(self::U, self::TENANT);

        $key = 'auth:perms:' . self::TENANT . ':' . self::U;
        $this->cache->set($key, json_encode([
            'permisos' => [self::P_ROL, self::P_GLOBAL, self::P_RECURSO],
            'expira_en' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]));

        $this->assertNotContains(
            self::P_RECURSO,
            $this->authz->getUserPermissions(self::U, self::TENANT),
            'La entrada anterior no puede autorizar el temporal de recurso'
        );
    }
}

/**
 * RedisService en memoria: `set()` guarda el valor tal cual y `get()` lo
 * devuelve tal cual (el codificado real lo hace el propio RedisService; con
 * este doble AuthorizationService recibe el JSON que el mismo escribio).
 */
final class ScopeCacheRedisService extends RedisService
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct()
    {
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }
}
