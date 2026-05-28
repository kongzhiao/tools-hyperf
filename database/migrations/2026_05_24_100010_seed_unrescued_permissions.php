<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class SeedUnrescuedPermissions extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $rootId = $this->upsertPermission([
            'name' => '未救助台账',
            'description' => '未救助台账',
            'type' => 'menu',
            'parent_id' => 0,
            'path' => '/unrescued',
            'component' => null,
            'icon' => 'FileSearchOutlined',
            'sort' => 8,
        ]);

        $recordsId = $this->upsertPermission([
            'name' => '未救助明细',
            'description' => '未救助明细',
            'type' => 'menu',
            'parent_id' => $rootId,
            'path' => '/unrescued/records',
            'component' => '@/pages/Unrescued/Records',
            'icon' => 'FileTextOutlined',
            'sort' => 1,
        ]);

        $diseaseId = $this->upsertPermission([
            'name' => '重大疾病编码',
            'description' => '重大疾病编码',
            'type' => 'menu',
            'parent_id' => $rootId,
            'path' => '/unrescued/disease-configs',
            'component' => '@/pages/Unrescued/DiseaseConfigs',
            'icon' => 'MedicineBoxOutlined',
            'sort' => 2,
        ]);

        foreach ($this->recordOperations($recordsId) as $permission) {
            $this->upsertPermission($permission);
        }

        foreach ($this->diseaseOperations($diseaseId) as $permission) {
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
            ['name' => '未救助明细:查看', 'description' => '查看未救助明细', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 70],
            ['name' => '未救助明细:导入', 'description' => '导入未救助明细', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 71],
            ['name' => '未救助明细:清洗', 'description' => '执行清洗', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 72],
            ['name' => '未救助明细:下放', 'description' => '下放镇街', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 73],
            ['name' => '未救助明细:通知', 'description' => '标记通知', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 74],
            ['name' => '未救助明细:账户回填', 'description' => '账户回填', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 75],
            ['name' => '未救助明细:报销标记', 'description' => '报销标记', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 76],
            ['name' => '未救助明细:导出', 'description' => '导出未救助台账', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 77],
        ];
    }

    private function diseaseOperations(int $parentId): array
    {
        return [
            ['name' => '重大疾病编码:查看', 'description' => '查看重大疾病编码', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 78],
            ['name' => '重大疾病编码:创建', 'description' => '创建重大疾病编码', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 79],
            ['name' => '重大疾病编码:编辑', 'description' => '编辑重大疾病编码', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 80],
            ['name' => '重大疾病编码:删除', 'description' => '删除重大疾病编码', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 81],
            ['name' => '重大疾病编码:导入', 'description' => '导入重大疾病编码', 'type' => 'operation', 'parent_id' => $parentId, 'sort' => 82],
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
            '未救助台账',
            '未救助明细',
            '重大疾病编码',
            '未救助明细:查看',
            '未救助明细:导入',
            '未救助明细:清洗',
            '未救助明细:下放',
            '未救助明细:通知',
            '未救助明细:账户回填',
            '未救助明细:报销标记',
            '未救助明细:导出',
            '重大疾病编码:查看',
            '重大疾病编码:创建',
            '重大疾病编码:编辑',
            '重大疾病编码:删除',
            '重大疾病编码:导入',
        ];
    }
}
