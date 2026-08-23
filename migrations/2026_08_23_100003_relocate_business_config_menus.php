<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class RelocateBusinessConfigMenus extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        Db::transaction(function (): void {
            $businessConfigId = $this->menuId('业务配置');
            $dataVerificationId = $this->menuId('数据核实');
            $onlineSettlementId = $this->menuId('联网结算');
            $categoryConversionId = $this->menuId('类别转换配置');
            $insuranceLevelConfigId = $this->menuId('参保档次配置');
            $categoryQuotaConfigId = $this->menuId('类别额度配置');

            $dataVerificationLastSort = (int) Db::table('permissions')
                ->where('type', 'menu')
                ->where('parent_id', $dataVerificationId)
                ->max('sort');

            Db::table('permissions')->where('id', $categoryConversionId)->update([
                'parent_id' => $dataVerificationId,
                'path' => '/data-verification/category-conversion',
                'sort' => $dataVerificationLastSort + 1,
                'status' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::table('permissions')->where('id', $insuranceLevelConfigId)->update([
                'parent_id' => $dataVerificationId,
                'path' => '/data-verification/insurance-level-config',
                'sort' => $dataVerificationLastSort + 2,
                'status' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            Db::table('permissions')->where('id', $onlineSettlementId)->update([
                'description' => '联网结算',
                'path' => '/yf',
                'component' => null,
                'status' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $onlineSettlementDetailId = $this->upsertOnlineSettlementDetail($onlineSettlementId);
            Db::table('permissions')
                ->where('type', 'operation')
                ->where('parent_id', $onlineSettlementId)
                ->where('name', 'like', '联网结算:%')
                ->update([
                    'parent_id' => $onlineSettlementDetailId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $this->copyRoleAssignments($onlineSettlementId, $onlineSettlementDetailId);

            $onlineSettlementLastSort = (int) Db::table('permissions')
                ->where('type', 'menu')
                ->where('parent_id', $onlineSettlementId)
                ->max('sort');
            Db::table('permissions')->where('id', $categoryQuotaConfigId)->update([
                'parent_id' => $onlineSettlementId,
                'path' => '/yf/category-money-config',
                'sort' => $onlineSettlementLastSort + 1,
                'status' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            Db::table('permissions')->where('id', $businessConfigId)->update([
                'status' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        Db::transaction(function (): void {
            $businessConfigId = $this->menuId('业务配置');
            $dataVerificationId = $this->menuId('数据核实');
            $onlineSettlementId = $this->menuId('联网结算');
            $categoryConversionId = $this->menuId('类别转换配置');
            $insuranceLevelConfigId = $this->menuId('参保档次配置');
            $categoryQuotaConfigId = $this->menuId('类别额度配置');
            $onlineSettlementDetailId = (int) Db::table('permissions')
                ->where('name', '联网结算明细')
                ->where('type', 'menu')
                ->value('id');

            if ($onlineSettlementDetailId > 0) {
                Db::table('permissions')
                    ->where('type', 'operation')
                    ->where('parent_id', $onlineSettlementDetailId)
                    ->where('name', 'like', '联网结算:%')
                    ->update([
                        'parent_id' => $onlineSettlementId,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);

                if (Schema::hasTable('role_permissions')) {
                    Db::table('role_permissions')->where('permission_id', $onlineSettlementDetailId)->delete();
                }
                Db::table('permissions')->where('id', $onlineSettlementDetailId)->delete();
            }

            Db::table('permissions')->where('id', $onlineSettlementId)->update([
                'description' => '联网结算主菜单',
                'path' => '/yf/settlement-online',
                'component' => './YfSettlement/Online',
                'status' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::table('permissions')->where('id', $categoryConversionId)->update([
                'parent_id' => $businessConfigId,
                'path' => '/business-config/config/category-conversion',
                'sort' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::table('permissions')->where('id', $insuranceLevelConfigId)->update([
                'parent_id' => $businessConfigId,
                'path' => '/business-config/config/insurance-level-config',
                'sort' => 2,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::table('permissions')->where('id', $categoryQuotaConfigId)->update([
                'parent_id' => $businessConfigId,
                'path' => '/business-config/config/category-money-config',
                'sort' => 3,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::table('permissions')->where('id', $businessConfigId)->update([
                'status' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    private function menuId(string $name): int
    {
        $id = (int) Db::table('permissions')
            ->where('name', $name)
            ->where('type', 'menu')
            ->value('id');
        if ($id <= 0) {
            throw new RuntimeException("缺少菜单节点：{$name}");
        }

        return $id;
    }

    private function upsertOnlineSettlementDetail(int $parentId): int
    {
        $now = date('Y-m-d H:i:s');
        $existingId = (int) Db::table('permissions')
            ->where('name', '联网结算明细')
            ->where('type', 'menu')
            ->value('id');
        $data = [
            'description' => '联网结算明细',
            'parent_id' => $parentId,
            'path' => '/yf/settlement-online',
            'component' => '@/pages/YfSettlement/Online',
            'icon' => 'GlobalOutlined',
            'sort' => 1,
            'status' => 1,
            'updated_at' => $now,
        ];

        if ($existingId > 0) {
            Db::table('permissions')->where('id', $existingId)->update($data);
            return $existingId;
        }

        return (int) Db::table('permissions')->insertGetId(array_merge($data, [
            'name' => '联网结算明细',
            'type' => 'menu',
            'created_at' => $now,
        ]));
    }

    private function copyRoleAssignments(int $fromPermissionId, int $toPermissionId): void
    {
        if (!Schema::hasTable('role_permissions')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $roleIds = Db::table('role_permissions')
            ->where('permission_id', $fromPermissionId)
            ->pluck('role_id')
            ->toArray();
        foreach ($roleIds as $roleId) {
            Db::table('role_permissions')->updateOrInsert(
                ['role_id' => (int) $roleId, 'permission_id' => $toPermissionId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }
}
