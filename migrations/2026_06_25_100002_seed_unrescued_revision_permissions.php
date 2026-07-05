<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class SeedUnrescuedRevisionPermissions extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }
        $rootId = $this->upsert(['name' => '未救助台账', 'description' => '未救助台账', 'type' => 'menu', 'parent_id' => 0, 'path' => '/unrescued', 'component' => null, 'icon' => 'FileSearchOutlined', 'sort' => 8]);
        $recordsId = $this->upsert(['name' => '未救助明细', 'description' => '未救助明细', 'type' => 'menu', 'parent_id' => $rootId, 'path' => '/unrescued/records', 'component' => '@/pages/Unrescued/Records', 'icon' => 'FileTextOutlined', 'sort' => 1]);
        $refundId = $this->upsert(['name' => '应补应退明细', 'description' => '应补应退明细', 'type' => 'menu', 'parent_id' => $rootId, 'path' => '/unrescued/refund-records', 'component' => '@/pages/Unrescued/RefundRecords', 'icon' => 'AuditOutlined', 'sort' => 2]);
        $noticeId = $this->upsert(['name' => '下放通知', 'description' => '下放通知', 'type' => 'menu', 'parent_id' => $rootId, 'path' => '/unrescued/notice-records', 'component' => '@/pages/Unrescued/NoticeRecords', 'icon' => 'SendOutlined', 'sort' => 3]);
        $diseaseId = $this->upsert(['name' => '重大疾病编码', 'description' => '重大疾病编码', 'type' => 'menu', 'parent_id' => $rootId, 'path' => '/unrescued/disease-configs', 'component' => '@/pages/Unrescued/DiseaseConfigs', 'icon' => 'MedicineBoxOutlined', 'sort' => 4]);

        foreach ([
            [$recordsId, '未救助明细', ['查看', '导入', '清洗', '导出']],
            [$refundId, '应补应退明细', ['查看', '导入', '清洗', '导出']],
            [$noticeId, '下放通知', ['查看', '导入', '下放', '回填', '报销标记', '备注', '导出']],
            [$diseaseId, '重大疾病编码', ['查看', '创建', '编辑', '删除', '导入']],
        ] as [$parentId, $name, $operations]) {
            foreach ($operations as $idx => $operation) {
                $this->upsert([
                    'name' => "{$name}:{$operation}",
                    'description' => "{$operation}{$name}",
                    'type' => 'operation',
                    'parent_id' => $parentId,
                    'sort' => 70 + $idx,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }
        Db::table('permissions')->whereIn('name', [
            '应补应退明细',
            '下放通知',
            '应补应退明细:查看',
            '应补应退明细:导入',
            '应补应退明细:清洗',
            '应补应退明细:导出',
            '下放通知:查看',
            '下放通知:导入',
            '下放通知:下放',
            '下放通知:回填',
            '下放通知:报销标记',
            '下放通知:备注',
            '下放通知:导出',
        ])->delete();
    }

    private function upsert(array $permission): int
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
