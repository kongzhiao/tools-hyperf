<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class PatchEnrollReviewOperationPermissions extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $rootId = $this->upsertPermission([
            'name' => '参保台账',
            'description' => '参保台账',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/enroll',
            'component' => null,
            'icon' => 'SolutionOutlined',
            'sort' => 9,
        ]);

        $ledgerId = $this->upsertPermission([
            'name' => '参保台账明细',
            'description' => '参保台账明细',
            'type' => 'menu',
            'parent_id' => $rootId,
            'path' => '/enroll/ledgers',
            'component' => '@/pages/Enroll/Ledgers',
            'icon' => 'FileTextOutlined',
            'sort' => 1,
        ]);

        $permissionIds = [];
        foreach ($this->operationPermissions($ledgerId) as $permission) {
            $permissionIds[] = $this->upsertPermission($permission);
        }

        $this->grantToSuperAdmin($permissionIds);
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $names = array_column($this->operationPermissions(0), 'name');
        $ids = Db::table('permissions')->whereIn('name', $names)->pluck('id')->toArray();
        if ($ids !== [] && Schema::hasTable('role_permissions')) {
            Db::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }
        Db::table('permissions')->whereIn('name', $names)->delete();
    }

    private function operationPermissions(int $parentId): array
    {
        return [
            ['name' => '参保台账明细:下放', 'description' => '下放参保台账给镇街核实', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 95],
            ['name' => '参保台账明细:收回', 'description' => '收回参保台账镇街填报权限', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 96],
            ['name' => '参保台账明细:下放批次', 'description' => '查看参保台账下放批次', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 97],
        ];
    }

    private function upsertPermission(array $permission): int
    {
        $now = date('Y-m-d H:i:s');
        $data = array_merge([
            'description' => $permission['description'] ?? $permission['name'],
            'type' => $permission['type'] ?? 'operation',
            'parent_id' => $permission['parent_id'] ?? 0,
            'path' => $permission['path'] ?? null,
            'component' => $permission['component'] ?? null,
            'icon' => $permission['icon'] ?? null,
            'sort' => $permission['sort'] ?? 0,
            'updated_at' => $now,
        ], $permission);

        if (Schema::hasColumn('permissions', 'status')) {
            $data['status'] = $permission['status'] ?? 1;
        }

        $existing = Db::table('permissions')->where('name', $permission['name'])->first();
        if ($existing) {
            Db::table('permissions')->where('id', $existing->id)->update($data);
            return (int) $existing->id;
        }

        $data['created_at'] = $now;
        return (int) Db::table('permissions')->insertGetId($data);
    }

    private function grantToSuperAdmin(array $permissionIds): void
    {
        if ($permissionIds === [] || !Schema::hasTable('roles') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $roleIds = Db::table('roles')
            ->whereIn('name', ['超级管理员', '管理员'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->toArray();

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                Db::table('role_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => (int) $permissionId,
                ], []);
            }
        }
    }
}
