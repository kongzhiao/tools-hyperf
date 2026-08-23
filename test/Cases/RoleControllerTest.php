<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Model\Permission;
use App\Model\Role;
use HyperfTest\HttpTestCase;

/**
 * @internal
 * @coversNothing
 */
class RoleControllerTest extends HttpTestCase
{
    public function testIndexRoles(): void
    {
        $json = $this->get('/api/roles', [], $this->authenticatedHeaders())->json();
        self::assertSame(0, $json['code'] ?? null);
        self::assertIsArray($json['data'] ?? null);
    }

    public function testStoreRole(): void
    {
        $name = $this->uniqueName('test-role');
        try {
            $json = $this->post('/api/roles', [
                'name' => $name,
                'description' => '测试角色',
            ], $this->authenticatedHeaders())->json();

            self::assertSame(0, $json['code'] ?? null);
            self::assertSame($name, $json['data']['name'] ?? null);
        } finally {
            Role::query()->where('name', $name)->delete();
        }
    }

    public function testUpdateRole(): void
    {
        $name = $this->uniqueName('update-role');
        $updatedName = $this->uniqueName('updated-role');
        try {
            $created = $this->post('/api/roles', [
                'name' => $name,
                'description' => '待更新角色',
            ], $this->authenticatedHeaders())->json();
            $id = $created['data']['id'] ?? null;
            self::assertNotNull($id);

            $json = $this->put("/api/roles/{$id}", [
                'name' => $updatedName,
                'description' => '已更新',
            ], $this->authenticatedHeaders())->json();

            self::assertSame(0, $json['code'] ?? null);
            self::assertSame($updatedName, $json['data']['name'] ?? null);
        } finally {
            Role::query()->whereIn('name', [$name, $updatedName])->delete();
        }
    }

    public function testDeleteRole(): void
    {
        $name = $this->uniqueName('delete-role');
        try {
            $created = $this->post('/api/roles', [
                'name' => $name,
                'description' => '待删除角色',
            ], $this->authenticatedHeaders())->json();
            $id = $created['data']['id'] ?? null;
            self::assertNotNull($id);

            $json = $this->delete("/api/roles/{$id}", [], $this->authenticatedHeaders())->json();
            self::assertSame(0, $json['code'] ?? null);
            self::assertSame('删除成功', $json['msg'] ?? null);
            self::assertFalse(Role::query()->whereKey($id)->exists());
        } finally {
            Role::query()->where('name', $name)->delete();
        }
    }

    public function testAssignPermissions(): void
    {
        $roleName = $this->uniqueName('assign-role');
        $permissionName = $this->uniqueName('assign-perm');
        $role = null;
        $permission = null;
        try {
            $roleJson = $this->post('/api/roles', [
                'name' => $roleName,
                'description' => '分配权限',
            ], $this->authenticatedHeaders())->json();
            $permissionJson = $this->post('/api/permissions', [
                'name' => $permissionName,
                'description' => '分配用',
            ], $this->authenticatedHeaders())->json();
            $role = Role::find($roleJson['data']['id'] ?? 0);
            $permission = Permission::find($permissionJson['data']['id'] ?? 0);
            self::assertNotNull($role);
            self::assertNotNull($permission);

            $json = $this->post("/api/roles/{$role->id}/permissions", [
                'permission_ids' => [$permission->id],
            ], $this->authenticatedHeaders())->json();
            self::assertSame(0, $json['code'] ?? null);
            self::assertTrue($role->permissions()->whereKey($permission->id)->exists());
        } finally {
            $role?->permissions()->detach();
            $role?->delete();
            $permission?->delete();
            Role::query()->where('name', $roleName)->delete();
            Permission::query()->where('name', $permissionName)->delete();
        }
    }

    public function testOrdinaryRoleCannotBeRenamedToAdministrator(): void
    {
        $name = $this->uniqueName('protected-role');
        try {
            $created = $this->post('/api/roles', [
                'name' => $name,
                'description' => '角色保护测试',
            ], $this->authenticatedHeaders())->json();
            $id = $created['data']['id'] ?? null;
            self::assertNotNull($id);

            $json = $this->put("/api/roles/{$id}", [
                'name' => '管理员',
                'description' => '不应成功',
            ], $this->authenticatedHeaders())->json();
            self::assertSame(403, $json['code'] ?? null);
            self::assertSame($name, Role::find($id)?->name);
        } finally {
            Role::query()->where('name', $name)->delete();
        }
    }

    public function testAdministratorRoleCannotBeRenamedDeletedOrDuplicated(): void
    {
        $administratorRole = Role::query()->where('name', '管理员')->first();
        $createdForTest = false;
        if (!$administratorRole) {
            $administratorRole = Role::create([
                'name' => '管理员',
                'description' => '受保护角色测试',
            ]);
            $createdForTest = true;
        }

        try {
            $rename = $this->put("/api/roles/{$administratorRole->id}", [
                'name' => $this->uniqueName('renamed-admin'),
            ], $this->authenticatedHeaders())->json();
            self::assertSame(403, $rename['code'] ?? null);

            $delete = $this->delete(
                "/api/roles/{$administratorRole->id}",
                [],
                $this->authenticatedHeaders()
            )->json();
            self::assertSame(403, $delete['code'] ?? null);

            $duplicate = $this->post('/api/roles', [
                'name' => '管理员',
                'description' => '不应创建',
            ], $this->authenticatedHeaders())->json();
            self::assertSame(400, $duplicate['code'] ?? null);
            self::assertSame(1, Role::query()->where('name', '管理员')->count());
        } finally {
            if ($createdForTest) {
                $administratorRole->users()->detach();
                $administratorRole->permissions()->detach();
                $administratorRole->delete();
            }
        }
    }

    private function uniqueName(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }
}
