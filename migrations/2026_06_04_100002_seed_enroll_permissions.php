<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class SeedEnrollPermissions extends Migration
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

        $recordsId = $this->upsertPermission([
            'name' => '参保台账明细',
            'description' => '参保台账明细',
            'type' => 'menu',
            'parent_id' => $rootId,
            'path' => '/enroll/ledgers',
            'component' => '@/pages/Enroll/Ledgers',
            'icon' => 'FileTextOutlined',
            'sort' => 1,
        ]);

        $configsId = $this->upsertPermission([
            'name' => '参保配置',
            'description' => '参保配置',
            'type' => 'menu',
            'parent_id' => $rootId,
            'path' => '/enroll/configs',
            'component' => '@/pages/Enroll/Configs',
            'icon' => 'SettingOutlined',
            'sort' => 2,
        ]);

        $importsId = $this->upsertPermission([
            'name' => '参保导入记录',
            'description' => '参保导入记录',
            'type' => 'menu',
            'parent_id' => $rootId,
            'path' => '/enroll/import-batches',
            'component' => '@/pages/Enroll/ImportBatches',
            'icon' => 'HistoryOutlined',
            'sort' => 3,
        ]);

        foreach ($this->recordOperations($recordsId) as $permission) {
            $this->upsertPermission($permission);
        }
        foreach ($this->configOperations($configsId) as $permission) {
            $this->upsertPermission($permission);
        }
        foreach ($this->importOperations($importsId) as $permission) {
            $this->upsertPermission($permission);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        Db::table('permissions')->whereIn('name', $this->permissionNames())->delete();
    }

    private function recordOperations(int $parentId): array
    {
        return [
            ['name' => '参保台账明细:查看', 'description' => '查看参保台账明细', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 90],
            ['name' => '参保台账明细:导入', 'description' => '导入参保台账数据', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 91],
            ['name' => '参保台账明细:导出', 'description' => '导出参保台账', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 92],
            ['name' => '参保台账明细:编辑', 'description' => '编辑参保台账', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 93],
            ['name' => '参保台账明细:删除', 'description' => '删除参保台账', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 94],
        ];
    }

    private function configOperations(int $parentId): array
    {
        return [
            ['name' => '参保配置:查看', 'description' => '查看参保配置', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 95],
            ['name' => '参保配置:导入', 'description' => '导入参保配置', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 96],
            ['name' => '参保配置:创建', 'description' => '创建参保配置', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 97],
            ['name' => '参保配置:编辑', 'description' => '编辑参保配置', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 98],
            ['name' => '参保配置:删除', 'description' => '删除参保配置', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 99],
        ];
    }

    private function importOperations(int $parentId): array
    {
        return [
            ['name' => '参保导入记录:查看', 'description' => '查看参保导入记录', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 100],
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

    private function permissionNames(): array
    {
        return [
            '参保台账',
            '参保台账明细',
            '参保配置',
            '参保导入记录',
            '参保台账明细:查看',
            '参保台账明细:导入',
            '参保台账明细:导出',
            '参保台账明细:编辑',
            '参保台账明细:删除',
            '参保配置:查看',
            '参保配置:导入',
            '参保配置:创建',
            '参保配置:编辑',
            '参保配置:删除',
            '参保导入记录:查看',
        ];
    }
}
