<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class SeedTownPermissions extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $userManagementId = $this->upsertPermission([
            'name' => '用户管理',
            'description' => '用户管理',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/user-management',
            'component' => null,
            'icon' => 'UserOutlined',
            'sort' => 2,
        ]);

        $townId = $this->upsertPermission([
            'name' => '镇街管理',
            'description' => '镇街管理',
            'type' => 'menu',
            'parent_id' => $userManagementId,
            'path' => '/user-management/towns',
            'component' => '@/pages/Town',
            'icon' => 'EnvironmentOutlined',
            'sort' => 4,
        ]);

        foreach ($this->townOperations($townId) as $permission) {
            $this->upsertPermission($permission);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        Db::table('permissions')
            ->whereIn('name', array_merge(['镇街管理'], array_column($this->townOperations(0), 'name')))
            ->delete();
    }

    private function townOperations(int $parentId): array
    {
        return [
            ['name' => '镇街管理:查看', 'description' => '查看镇街', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 14],
            ['name' => '镇街管理:创建', 'description' => '创建镇街', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 15],
            ['name' => '镇街管理:编辑', 'description' => '编辑镇街', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 16],
            ['name' => '镇街管理:删除', 'description' => '删除镇街', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 17],
            ['name' => '镇街管理:导入', 'description' => '导入镇街', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 18],
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
}
