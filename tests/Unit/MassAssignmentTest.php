<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Authentication\Repository\UserRepository;
use App\Core\Authentication\Service\PasswordHasher;
use App\Core\Authorization\Http\RoleController;
use App\Core\Authorization\Repository\RoleRepository;
use App\Core\Groups\Http\GroupController;
use App\Core\Groups\Repository\UserGroupRepository;
use App\Core\Security\Service\InputSanitizer;
use App\Core\Tenants\Repository\TenantRepositoryInterface;
use App\Core\Tenants\Service\TenantContext;
use App\Core\Users\Exception\UserException;
use App\Core\Users\Service\UserService;
use App\Modules\Inventory\Application\Services\ProductService;
use App\Shared\Persistence\BaseRepository;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\PlatformControlSqliteTestCase;

/**
 * Mass assignment (spec seguridad): los endpoints de escritura sólo aplican
 * los campos de negocio declarados; cualquier otra clave del payload del
 * cliente (tenant_id, id, deleted_at, password, mfa_*, tipo, propietario_id,
 * email_verificado...) se IGNORA y no altera la fila.
 *
 * Contrato por dominio:
 *   - users: UserService::update/updateMe construyen el UPDATE campo a campo
 *     (allowlist por diseño); create() rechaza password en claro y fija id,
 *     estado y email_verificado internamente.
 *   - roles: RoleController::update sólo aplica nombre/descripcion/activo.
 *   - groups: GroupController::update sólo aplica nombre/descripcion/activo.
 *   - products: ProductService::update filtra por sanitizeUpdateData().
 *   - BaseRepository::save/update: allowlist obligatoria getAllowedWriteColumns()
 *     (regla de diseño para futuros repos; ningún repo productivo lo extiende
 *     hoy, pero la base deja de ser una trampa latente).
 */
final class MassAssignmentTest extends PlatformControlSqliteTestCase
{
    // ====================================================================
    // USERS
    // ====================================================================

    public function testUsersUpdateIgnoresStructuralFields(): void
    {
        $userId = $this->seedUser('u-victim');
        $service = $this->userService();

        $result = $service->update($userId, self::TENANT, [
            'nombre' => 'Nombre Nuevo',
            'tenant_id' => 'tenant-evil',
            'id' => 'u-attacker',
            'deleted_at' => '2020-01-01 00:00:00',
            'email_verificado' => 1,
            'password' => 'Attacker-Pass1!',
            'mfa_habilitado' => 1,
            'mfa_secret' => 'SUPERSECRET',
            'ultimo_login' => '2020-01-01 00:00:00',
        ]);

        $row = $this->row('users', $userId);
        $this->assertSame('Nombre Nuevo', $row['nombre']);
        $this->assertSame(self::TENANT, $row['tenant_id']);
        $this->assertNull($row['deleted_at']);
        $this->assertSame(0, (int) $row['email_verificado']);
        $this->assertSame(0, (int) $row['mfa_habilitado']);
        $this->assertNull($row['mfa_secret']);

        $this->assertArrayNotHasKey('password', $result);
        $this->assertArrayNotHasKey('mfa_secret', $result);
    }

    public function testUsersUpdateMeIgnoresStructuralFields(): void
    {
        $userId = $this->seedUser('u-self');
        $service = $this->userService();

        $service->updateMe($userId, self::TENANT, [
            'apellido' => 'Apellido Nuevo',
            'estado' => 'SUSPENDIDO',
            'tenant_id' => 'tenant-evil',
            'password' => 'Attacker-Pass1!',
            'email_verificado' => 1,
            'mfa_secret' => 'SUPERSECRET',
            'deleted_at' => '2020-01-01 00:00:00',
        ]);

        $row = $this->row('users', $userId);
        $this->assertSame('Apellido Nuevo', $row['apellido']);
        $this->assertSame('ACTIVO', $row['estado']);
        $this->assertSame(self::TENANT, $row['tenant_id']);
        $this->assertNull($row['deleted_at']);
        $this->assertSame(0, (int) $row['email_verificado']);
        $this->assertNull($row['mfa_secret']);
    }

    public function testUsersCreateRejectsPasswordInClear(): void
    {
        $service = $this->userService();

        try {
            $service->create([
                'tenant_id' => self::TENANT,
                'email' => 'nuevo@example.test',
                'nombre' => 'Nuevo',
                'apellido' => 'Usuario',
                'password' => 'Attacker-Pass1!',
            ]);
            $this->fail('Se esperaba UserException por password en claro');
        } catch (UserException $e) {
            $this->assertSame(400, $e->getCode());
        }

        $this->assertSame(0, $this->countRows('users'));
    }

    public function testUsersCreateIgnoresClientStructuralFields(): void
    {
        $service = $this->userService();

        $result = $service->create([
            'tenant_id' => self::TENANT,
            'email' => 'creado@example.test',
            'nombre' => 'Creado',
            'apellido' => 'Cliente',
            'id' => 'u-client-id',
            'estado' => 'SUSPENDIDO',
            'email_verificado' => 1,
            'mfa_habilitado' => 1,
            'mfa_secret' => 'CLIENTSECRET',
            'deleted_at' => '2020-01-01 00:00:00',
        ]);

        $this->assertNotSame('u-client-id', $result['id']);
        $row = $this->row('users', $result['id']);
        $this->assertSame(self::TENANT, $row['tenant_id']);
        $this->assertSame('ACTIVO', $row['estado']);
        $this->assertSame(0, (int) $row['email_verificado']);
        $this->assertSame(0, (int) $row['mfa_habilitado']);
        $this->assertNull($row['mfa_secret']);
        $this->assertNull($row['deleted_at']);
        $this->assertArrayNotHasKey('password', $result);
    }

    // ====================================================================
    // ROLES
    // ====================================================================

    public function testRoleUpdateIgnoresStructuralFields(): void
    {
        $this->seedUser('u-admin');
        $adminRoleId = $this->seedRole('role-admin', 'ADMIN', [], 'BUILT_IN');
        $this->assignRole('u-admin', $adminRoleId);
        $this->seedRole('role-c1', 'Editor', [], 'CUSTOM', true, self::TENANT);

        $controller = new RoleController(
            new RoleRepository($this->db),
            $this->authz,
            null
        );

        $controller->update(
            $this->request([
                'body' => [
                    'nombre' => 'Editor Renombrado',
                    'tipo' => 'BUILT_IN',
                    'tenant_id' => 'tenant-evil',
                    'id' => 'role-attacker',
                    'deleted_at' => '2020-01-01 00:00:00',
                    'descripcion' => null,
                ],
            ]),
            ['id' => 'role-c1']
        );

        $row = $this->row('roles', 'role-c1');
        $this->assertSame('EDITOR RENOMBRADO', $row['nombre']);
        $this->assertSame('CUSTOM', $row['tipo']);
        $this->assertSame(self::TENANT, $row['tenant_id']);
        $this->assertNull($row['deleted_at']);
    }

    // ====================================================================
    // GROUPS
    // ====================================================================

    public function testGroupUpdateIgnoresStructuralFields(): void
    {
        $this->seedGroup('grp-1');

        $controller = new GroupController(
            new UserGroupRepository($this->db),
            new UserRepository($this->db),
            new RoleRepository($this->db),
            $this->authz
        );

        $controller->update(
            $this->request([
                'body' => [
                    'nombre' => 'Grupo Renombrado',
                    'tenant_id' => 'tenant-evil',
                    'id' => 'grp-attacker',
                    'propietario_id' => 'u-attacker',
                    'deleted_at' => '2020-01-01 00:00:00',
                ],
            ]),
            ['id' => 'grp-1']
        );

        $row = $this->row('user_groups', 'grp-1');
        $this->assertSame('Grupo Renombrado', $row['nombre']);
        $this->assertSame(self::TENANT, $row['tenant_id']);
        $this->assertNull($row['propietario_id']);
        $this->assertNull($row['deleted_at']);
    }

    // ====================================================================
    // PRODUCTS (inventory)
    // ====================================================================

    public function testProductUpdateIgnoresStructuralFields(): void
    {
        $repository = new InMemoryProductRepository(self::TENANT);
        $service = $this->productService($repository);

        $created = $service->create([
            'sku' => 'SKU-1',
            'nombre' => 'Producto Base',
            'categoria' => 'General',
            'unidad_medida' => 'UNIDAD',
        ]);

        $service->update($created['id'], [
            'sku' => 'SKU-1',
            'nombre' => 'Producto Editado',
            'tenant_id' => 'tenant-evil',
            'id' => 'prod-attacker',
            'deleted_at' => '2020-01-01 00:00:00',
            'activo' => false,
            'created_at' => '2000-01-01 00:00:00',
            'email_verificado' => 1,
        ]);

        $stored = $repository->stored($created['id']);
        $this->assertNotNull($stored);
        $this->assertSame('Producto Editado', $stored->nombre());
        $this->assertSame(self::TENANT, $stored->tenantId());
        $this->assertTrue($stored->activo());
        $this->assertNull($repository->stored('prod-attacker'));
    }

    // ====================================================================
    // BaseRepository (allowlist de escritura)
    // ====================================================================

    public function testBaseRepositorySaveDropsNonAllowlistedColumns(): void
    {
        $repo = $this->widgetRepository();

        $id = $repo->save([
            'nombre' => 'Widget',
            'borrado' => 1,
            'deleted_at' => '2020-01-01 00:00:00',
            'tenant_id' => 'tenant-evil',
        ]);

        $row = $this->row('mr_widgets', $id);
        $this->assertSame('Widget', $row['nombre']);
        $this->assertSame(0, (int) $row['borrado']);
        $this->assertNull($row['deleted_at']);
        $this->assertSame(self::TENANT, $row['tenant_id']);
    }

    public function testBaseRepositoryUpdateDropsNonAllowlistedColumns(): void
    {
        $repo = $this->widgetRepository();
        $id = $repo->save(['nombre' => 'Original']);

        $updated = $repo->update($id, [
            'nombre' => 'Cambiado',
            'borrado' => 1,
            'deleted_at' => '2020-01-01 00:00:00',
            // Mismo tenant que el contexto: se descarta (no es writable);
            // un tenant DISTINTO se rechaza con TenantException (403).
            'tenant_id' => self::TENANT,
            'id' => 'mr-attacker',
        ]);

        $this->assertTrue($updated);
        $row = $this->row('mr_widgets', $id);
        $this->assertSame('Cambiado', $row['nombre']);
        $this->assertSame(0, (int) $row['borrado']);
        $this->assertNull($row['deleted_at']);
        $this->assertSame(self::TENANT, $row['tenant_id']);
        $this->assertNull($this->row('mr_widgets', 'mr-attacker'));
    }

    public function testBaseRepositoryUpdateRejectsForeignTenant(): void
    {
        $repo = $this->widgetRepository();
        $id = $repo->save(['nombre' => 'Original']);

        $this->expectException(\App\Core\Tenants\Exception\TenantException::class);
        $repo->update($id, ['nombre' => 'No Aplica', 'tenant_id' => 'tenant-evil']);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    private function userService(): UserService
    {
        return new UserService(
            new UserRepository($this->db),
            new PasswordHasher(['rounds' => 4]),
            $this->db
        );
    }

    private function productService(InMemoryProductRepository $repository): ProductService
    {
        $tenantContext = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $tenantContext->setTenantId(self::TENANT);

        return new ProductService($repository, $tenantContext, new InputSanitizer());
    }

    /** Repo mínimo con la allowlist de una sola columna ('nombre'). */
    private function widgetRepository(): BaseRepository
    {
        $this->pdo->exec(
            'CREATE TABLE mr_widgets (
                id TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                nombre TEXT,
                borrado INTEGER DEFAULT 0,
                deleted_at TEXT,
                created_at TEXT,
                updated_at TEXT
            )'
        );

        $tenantContext = new TenantContext($this->createStub(TenantRepositoryInterface::class));
        $tenantContext->setTenantId(self::TENANT);

        return new class($this->db, $tenantContext) extends BaseRepository {
            protected function getTableName(): string
            {
                return 'mr_widgets';
            }

            protected function getEntityClass(): ?string
            {
                return null;
            }

            protected function getAllowedFilters(): array
            {
                return ['nombre'];
            }

            protected function getAllowedWriteColumns(): array
            {
                return ['nombre'];
            }
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $table, string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
