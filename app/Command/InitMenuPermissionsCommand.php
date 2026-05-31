<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Permission;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;

#[Command]
class InitMenuPermissionsCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('init:menu-permissions');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('初始化菜单权限数据（按当前库结构整理，非破坏性 upsert，不指定固定 ID）');
    }

    public function handle()
    {
        $this->output->writeln('开始初始化菜单权限...');
        $this->output->writeln('策略：按权限 name 非破坏性 upsert，保留现有 ID 与角色授权关系，不再强制指定任何 ID');

        $definitions = $this->permissionDefinitions();
        $this->assertUniqueNames($definitions);

        $permissionIds = [];
        $createdMenuCount = 0;
        $updatedMenuCount = 0;
        $createdOperationCount = 0;
        $updatedOperationCount = 0;

        foreach ($definitions as $definition) {
            [$permission, $isCreated] = $this->upsertPermission($definition);
            $permissionIds[$permission->name] = (int) $permission->id;

            if (($definition['type'] ?? '') === 'menu') {
                $isCreated ? $createdMenuCount++ : $updatedMenuCount++;
                $this->output->writeln(($isCreated ? '创建' : '更新') . "菜单: {$definition['name']}");
            } else {
                $isCreated ? $createdOperationCount++ : $updatedOperationCount++;
                $this->output->writeln(($isCreated ? '创建' : '更新') . "操作: {$definition['name']}");
            }
        }

        foreach ($definitions as $definition) {
            $parentName = $definition['parent'] ?? null;
            $parentId = 0;
            if ($parentName) {
                $parentId = $permissionIds[$parentName]
                    ?? (int) Permission::query()->where('name', $parentName)->value('id');
                if ($parentId <= 0) {
                    $this->output->writeln("警告：{$definition['name']} 的父级 {$parentName} 不存在，已保持为顶级节点");
                }
            }

            Permission::query()
                ->where('name', $definition['name'])
                ->update(['parent_id' => $parentId]);
        }

        $this->clearUserPermissionCache();

        $this->output->writeln('菜单权限初始化完成！');
        $this->output->writeln("菜单权限：新增 {$createdMenuCount} 个，更新 {$updatedMenuCount} 个");
        $this->output->writeln("操作权限：新增 {$createdOperationCount} 个，更新 {$updatedOperationCount} 个");
        $this->output->writeln('');
        $this->output->writeln('当前菜单结构：');
        $this->printMenuTree();
    }

    private function upsertPermission(array $definition): array
    {
        $data = [
            'description' => $definition['description'] ?? '',
            'type' => $definition['type'],
            'parent_id' => 0,
            'path' => $definition['path'] ?? null,
            'component' => $definition['component'] ?? null,
            'icon' => $definition['icon'] ?? null,
            'sort' => (int) ($definition['sort'] ?? 0),
            'status' => (int) ($definition['status'] ?? 1),
        ];

        $model = Permission::query()->where('name', $definition['name'])->orderBy('id')->first();
        if ($model) {
            $model->fill($data);
            $model->save();
            return [$model, false];
        }

        $model = new Permission(array_merge(['name' => $definition['name']], $data));
        $model->save();

        return [$model, true];
    }

    private function assertUniqueNames(array $definitions): void
    {
        $names = array_column($definitions, 'name');
        $duplicates = array_unique(array_diff_assoc($names, array_unique($names)));
        if ($duplicates) {
            throw new \RuntimeException('菜单权限定义存在重复 name：' . implode('、', $duplicates));
        }
    }

    private function clearUserPermissionCache(): void
    {
        try {
            $redis = $this->container->get(Redis::class);
            $keys = $redis->keys('user:cache:*');
            if (!$keys) {
                $this->output->writeln('未发现用户权限缓存');
                return;
            }

            foreach ($keys as $key) {
                $redis->del($key);
            }
            $this->output->writeln('已清理用户权限缓存：' . count($keys) . ' 个');
        } catch (\Throwable $e) {
            $this->output->writeln('清理用户权限缓存失败：' . $e->getMessage());
        }
    }

    private function printMenuTree(): void
    {
        $menus = Permission::query()
            ->where('type', 'menu')
            ->orderBy('parent_id')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->toArray();

        $tree = Permission::buildTree($menus);
        foreach ($tree as $menu) {
            $this->printMenuNode($menu);
        }
    }

    private function printMenuNode(array $menu, string $prefix = ''): void
    {
        $status = ((int) ($menu['status'] ?? 1)) === 1 ? '' : '（停用）';
        $this->output->writeln($prefix . '├── ' . $menu['name'] . $status);
        foreach (($menu['children'] ?? []) as $child) {
            $this->printMenuNode($child, $prefix . '│   ');
        }
    }

    /**
     * 该列表按当前 permissions 表整理，不写入固定 ID。
     * 生产/本地已有 ID 会被保留；新环境执行时由数据库自增生成。
     */
    private function permissionDefinitions(): array
    {
        return [
            ['name' => '仪表板', 'description' => '仪表板', 'type' => 'menu', 'parent' => null, 'path' => '/dashboard', 'component' => '@/pages/Dashboard', 'icon' => 'HomeOutlined', 'sort' => 1, 'status' => 1],
            ['name' => '用户管理', 'description' => '用户管理', 'type' => 'menu', 'parent' => null, 'path' => '/user-management', 'component' => null, 'icon' => 'TeamOutlined', 'sort' => 2, 'status' => 1],
            ['name' => '业务配置', 'description' => '业务配置', 'type' => 'menu', 'parent' => null, 'path' => '/business-config', 'component' => null, 'icon' => 'SettingOutlined', 'sort' => 3, 'status' => 1],
            ['name' => '数据核实', 'description' => '数据核实', 'type' => 'menu', 'parent' => null, 'path' => '/data-verification', 'component' => null, 'icon' => 'AuditOutlined', 'sort' => 4, 'status' => 1],
            ['name' => '统计汇总', 'description' => '统计汇总', 'type' => 'menu', 'parent' => null, 'path' => '/statistics-summary', 'component' => '@/pages/StatisticsSummary', 'icon' => 'BarChartOutlined', 'sort' => 6, 'status' => 1],
            ['name' => '救助报销', 'description' => '救助报销', 'type' => 'menu', 'parent' => null, 'path' => '/medical-assistance', 'component' => null, 'icon' => 'MedicineBoxOutlined', 'sort' => 7, 'status' => 1],
            ['name' => '未救助台账', 'description' => '未救助台账', 'type' => 'menu', 'parent' => null, 'path' => '/unrescued', 'component' => null, 'icon' => 'FileSearchOutlined', 'sort' => 8, 'status' => 1],
            ['name' => '联网结算', 'description' => '联网结算主菜单', 'type' => 'menu', 'parent' => null, 'path' => '/yf/settlement-online', 'component' => './YfSettlement/Online', 'icon' => 'GlobalOutlined', 'sort' => 20, 'status' => 1],

            ['name' => '账户管理', 'description' => '账户管理', 'type' => 'menu', 'parent' => '用户管理', 'path' => '/user-management/accounts', 'component' => '@/pages/User', 'icon' => 'UserOutlined', 'sort' => 1, 'status' => 1],
            ['name' => '角色管理', 'description' => '角色管理', 'type' => 'menu', 'parent' => '用户管理', 'path' => '/user-management/roles', 'component' => '@/pages/Role', 'icon' => 'SafetyCertificateOutlined', 'sort' => 2, 'status' => 1],
            ['name' => '权限管理', 'description' => '权限管理', 'type' => 'menu', 'parent' => '用户管理', 'path' => '/user-management/permissions', 'component' => '@/pages/Permission', 'icon' => 'KeyOutlined', 'sort' => 3, 'status' => 1],
            ['name' => '镇街管理', 'description' => '镇街管理', 'type' => 'menu', 'parent' => '用户管理', 'path' => '/user-management/towns', 'component' => '@/pages/Town', 'icon' => 'EnvironmentOutlined', 'sort' => 4, 'status' => 1],

            ['name' => '类别转换配置', 'description' => '类别转换配置', 'type' => 'menu', 'parent' => '业务配置', 'path' => '/business-config/config/category-conversion', 'component' => '@/pages/BussinessConfig/CategoryConversion', 'icon' => 'SwapOutlined', 'sort' => 1, 'status' => 1],
            ['name' => '参保档次配置', 'description' => '参保档次配置', 'type' => 'menu', 'parent' => '业务配置', 'path' => '/business-config/config/insurance-level-config', 'component' => '@/pages/BussinessConfig/InsuranceLevelConfig', 'icon' => 'ToolOutlined', 'sort' => 2, 'status' => 1],
            ['name' => '类别额度配置', 'description' => '类别额度配置', 'type' => 'menu', 'parent' => '业务配置', 'path' => '/business-config/config/category-money-config', 'component' => '@/pages/BussinessConfig/CategoryMoneyConfig', 'icon' => 'SettingOutlined', 'sort' => 3, 'status' => 1],

            ['name' => '参保数据管理', 'description' => '参保数据管理', 'type' => 'menu', 'parent' => '数据核实', 'path' => '/data-verification/insurance-data', 'component' => '@/pages/DataVerification/InsuranceData', 'icon' => 'FileTextOutlined', 'sort' => 1, 'status' => 1],
            ['name' => '身份信息核实', 'description' => '身份信息核实', 'type' => 'menu', 'parent' => '数据核实', 'path' => '/data-verification/identity-verification', 'component' => '@/pages/DataVerification/IdentityVerification', 'icon' => 'UserOutlined', 'sort' => 2, 'status' => 1],
            ['name' => '税务数据汇总', 'description' => '税务数据汇总', 'type' => 'menu', 'parent' => '数据核实', 'path' => '/data-verification/tax-summary', 'component' => '@/pages/DataVerification/TaxSummary', 'icon' => 'AccountBookOutlined', 'sort' => 3, 'status' => 1],
            ['name' => '参保数据汇总', 'description' => '参保数据汇总', 'type' => 'menu', 'parent' => '数据核实', 'path' => '/data-verification/insurance-summary', 'component' => '@/pages/DataVerification/InsuranceSummary', 'icon' => 'BarChartOutlined', 'sort' => 4, 'status' => 1],

            ['name' => '受理记录', 'description' => '受理记录', 'type' => 'menu', 'parent' => '救助报销', 'path' => '/medical-assistance/reimbursement', 'component' => '@/pages/MedicalAssistance/Reimbursement', 'icon' => 'DollarOutlined', 'sort' => 1, 'status' => 1],
            ['name' => '就诊记录', 'description' => '就诊记录', 'type' => 'menu', 'parent' => '救助报销', 'path' => '/medical-assistance/records', 'component' => '@/pages/MedicalAssistance/Records', 'icon' => 'FileTextOutlined', 'sort' => 2, 'status' => 1],
            ['name' => '患者管理', 'description' => '患者管理', 'type' => 'menu', 'parent' => '救助报销', 'path' => '/medical-assistance/patients', 'component' => '@/pages/MedicalAssistance/Patients', 'icon' => 'UserOutlined', 'sort' => 3, 'status' => 1],

            ['name' => '未救助明细', 'description' => '未救助明细', 'type' => 'menu', 'parent' => '未救助台账', 'path' => '/unrescued/records', 'component' => '@/pages/Unrescued/Records', 'icon' => 'FileTextOutlined', 'sort' => 1, 'status' => 1],
            ['name' => '重大疾病编码', 'description' => '重大疾病编码', 'type' => 'menu', 'parent' => '未救助台账', 'path' => '/unrescued/disease-configs', 'component' => '@/pages/Unrescued/DiseaseConfigs', 'icon' => 'MedicineBoxOutlined', 'sort' => 2, 'status' => 1],

            ['name' => '账户管理:查看', 'description' => '查看账户', 'type' => 'operation', 'parent' => '账户管理', 'sort' => 1, 'status' => 1],
            ['name' => '账户管理:创建', 'description' => '创建账户', 'type' => 'operation', 'parent' => '账户管理', 'sort' => 2, 'status' => 1],
            ['name' => '账户管理:编辑', 'description' => '编辑账户', 'type' => 'operation', 'parent' => '账户管理', 'sort' => 3, 'status' => 1],
            ['name' => '账户管理:删除', 'description' => '删除账户', 'type' => 'operation', 'parent' => '账户管理', 'sort' => 4, 'status' => 1],
            ['name' => '角色管理:查看', 'description' => '查看角色', 'type' => 'operation', 'parent' => '角色管理', 'sort' => 5, 'status' => 1],
            ['name' => '角色管理:创建', 'description' => '创建角色', 'type' => 'operation', 'parent' => '角色管理', 'sort' => 6, 'status' => 1],
            ['name' => '角色管理:编辑', 'description' => '编辑角色', 'type' => 'operation', 'parent' => '角色管理', 'sort' => 7, 'status' => 1],
            ['name' => '角色管理:删除', 'description' => '删除角色', 'type' => 'operation', 'parent' => '角色管理', 'sort' => 8, 'status' => 1],
            ['name' => '角色管理:分配权限', 'description' => '分配角色权限', 'type' => 'operation', 'parent' => '角色管理', 'sort' => 9, 'status' => 1],
            ['name' => '权限管理:查看', 'description' => '查看权限', 'type' => 'operation', 'parent' => '权限管理', 'sort' => 10, 'status' => 1],
            ['name' => '权限管理:创建', 'description' => '创建权限', 'type' => 'operation', 'parent' => '权限管理', 'sort' => 11, 'status' => 1],
            ['name' => '权限管理:编辑', 'description' => '编辑权限', 'type' => 'operation', 'parent' => '权限管理', 'sort' => 12, 'status' => 1],
            ['name' => '权限管理:删除', 'description' => '删除权限', 'type' => 'operation', 'parent' => '权限管理', 'sort' => 13, 'status' => 1],
            ['name' => '镇街管理:查看', 'description' => '查看镇街', 'type' => 'operation', 'parent' => '镇街管理', 'sort' => 14, 'status' => 1],
            ['name' => '镇街管理:创建', 'description' => '创建镇街', 'type' => 'operation', 'parent' => '镇街管理', 'sort' => 15, 'status' => 1],
            ['name' => '镇街管理:编辑', 'description' => '编辑镇街', 'type' => 'operation', 'parent' => '镇街管理', 'sort' => 16, 'status' => 1],
            ['name' => '镇街管理:删除', 'description' => '删除镇街', 'type' => 'operation', 'parent' => '镇街管理', 'sort' => 17, 'status' => 1],
            ['name' => '镇街管理:导入', 'description' => '导入镇街', 'type' => 'operation', 'parent' => '镇街管理', 'sort' => 18, 'status' => 1],

            ['name' => '类别转换配置:查看', 'description' => '查看类别转换配置', 'type' => 'operation', 'parent' => '类别转换配置', 'sort' => 16, 'status' => 1],
            ['name' => '类别转换配置:创建', 'description' => '创建类别转换配置', 'type' => 'operation', 'parent' => '类别转换配置', 'sort' => 17, 'status' => 1],
            ['name' => '类别转换配置:编辑', 'description' => '编辑类别转换配置', 'type' => 'operation', 'parent' => '类别转换配置', 'sort' => 18, 'status' => 1],
            ['name' => '类别转换配置:删除', 'description' => '删除类别转换配置', 'type' => 'operation', 'parent' => '类别转换配置', 'sort' => 19, 'status' => 1],
            ['name' => '参保档次配置:查看', 'description' => '查看参保档次配置', 'type' => 'operation', 'parent' => '参保档次配置', 'sort' => 20, 'status' => 1],
            ['name' => '参保档次配置:创建', 'description' => '创建参保档次配置', 'type' => 'operation', 'parent' => '参保档次配置', 'sort' => 21, 'status' => 1],
            ['name' => '参保档次配置:编辑', 'description' => '编辑参保档次配置', 'type' => 'operation', 'parent' => '参保档次配置', 'sort' => 22, 'status' => 1],
            ['name' => '参保档次配置:删除', 'description' => '删除参保档次配置', 'type' => 'operation', 'parent' => '参保档次配置', 'sort' => 23, 'status' => 1],
            ['name' => '类别额度配置:查看', 'description' => '查看类别额度配置', 'type' => 'operation', 'parent' => '类别额度配置', 'sort' => 1, 'status' => 1],
            ['name' => '类别额度配置:创建', 'description' => '创建类别额度配置', 'type' => 'operation', 'parent' => '类别额度配置', 'sort' => 2, 'status' => 1],
            ['name' => '类别额度配置:编辑', 'description' => '编辑类别额度配置', 'type' => 'operation', 'parent' => '类别额度配置', 'sort' => 3, 'status' => 1],
            ['name' => '类别额度配置:删除', 'description' => '删除类别额度配置', 'type' => 'operation', 'parent' => '类别额度配置', 'sort' => 4, 'status' => 1],

            ['name' => '参保数据管理:查看', 'description' => '查看参保数据', 'type' => 'operation', 'parent' => '参保数据管理', 'sort' => 24, 'status' => 1],
            ['name' => '参保数据管理:创建', 'description' => '创建参保数据', 'type' => 'operation', 'parent' => '参保数据管理', 'sort' => 25, 'status' => 1],
            ['name' => '参保数据管理:编辑', 'description' => '编辑参保数据', 'type' => 'operation', 'parent' => '参保数据管理', 'sort' => 26, 'status' => 1],
            ['name' => '参保数据管理:删除', 'description' => '删除参保数据', 'type' => 'operation', 'parent' => '参保数据管理', 'sort' => 27, 'status' => 1],
            ['name' => '参保数据管理:导出', 'description' => '导出参保数据', 'type' => 'operation', 'parent' => '参保数据管理', 'sort' => 28, 'status' => 1],
            ['name' => '参保数据管理:导入', 'description' => '导入参保数据', 'type' => 'operation', 'parent' => '参保数据管理', 'sort' => 29, 'status' => 1],
            ['name' => '身份信息核实:查看', 'description' => '查看身份信息核实', 'type' => 'operation', 'parent' => '身份信息核实', 'sort' => 30, 'status' => 1],
            ['name' => '身份信息核实:执行', 'description' => '执行身份信息核实', 'type' => 'operation', 'parent' => '身份信息核实', 'sort' => 31, 'status' => 1],
            ['name' => '税务数据汇总:查看', 'description' => '查看税务数据汇总', 'type' => 'operation', 'parent' => '税务数据汇总', 'sort' => 32, 'status' => 1],
            ['name' => '税务数据汇总:导出', 'description' => '导出税务数据汇总', 'type' => 'operation', 'parent' => '税务数据汇总', 'sort' => 33, 'status' => 1],
            ['name' => '参保数据汇总:查看', 'description' => '查看参保数据汇总', 'type' => 'operation', 'parent' => '参保数据汇总', 'sort' => 34, 'status' => 1],
            ['name' => '参保数据汇总:导出', 'description' => '导出参保数据汇总', 'type' => 'operation', 'parent' => '参保数据汇总', 'sort' => 35, 'status' => 1],

            ['name' => '统计汇总:查看', 'description' => '查看统计汇总', 'type' => 'operation', 'parent' => '统计汇总', 'sort' => 38, 'status' => 1],
            ['name' => '统计汇总:导入', 'description' => '导入统计数据', 'type' => 'operation', 'parent' => '统计汇总', 'sort' => 39, 'status' => 1],
            ['name' => '统计汇总:导出明细', 'description' => '导出统计数据', 'type' => 'operation', 'parent' => '统计汇总', 'sort' => 40, 'status' => 1],
            ['name' => '统计汇总:导出统计数据', 'description' => '导出统计数据', 'type' => 'operation', 'parent' => '统计汇总', 'sort' => 41, 'status' => 1],
            ['name' => '统计汇总:创建', 'description' => '创建统计数据', 'type' => 'operation', 'parent' => '统计汇总', 'sort' => 42, 'status' => 1],
            ['name' => '统计汇总:编辑', 'description' => '编辑统计数据', 'type' => 'operation', 'parent' => '统计汇总', 'sort' => 43, 'status' => 1],
            ['name' => '统计汇总:删除', 'description' => '删除统计数据', 'type' => 'operation', 'parent' => '统计汇总', 'sort' => 44, 'status' => 1],
            ['name' => '统计汇总:清空数据', 'description' => '清空统计数据', 'type' => 'operation', 'parent' => '统计汇总', 'sort' => 45, 'status' => 1],
            ['name' => '统计汇总:批量删除', 'description' => '批量删除统计数据', 'type' => 'operation', 'parent' => '统计汇总', 'sort' => 46, 'status' => 1],

            ['name' => '患者管理:查看', 'description' => '查看患者管理', 'type' => 'operation', 'parent' => '患者管理', 'sort' => 46, 'status' => 1],
            ['name' => '患者管理:创建', 'description' => '创建患者管理', 'type' => 'operation', 'parent' => '患者管理', 'sort' => 47, 'status' => 1],
            ['name' => '患者管理:编辑', 'description' => '编辑患者管理', 'type' => 'operation', 'parent' => '患者管理', 'sort' => 48, 'status' => 1],
            ['name' => '患者管理:删除', 'description' => '删除患者管理', 'type' => 'operation', 'parent' => '患者管理', 'sort' => 49, 'status' => 1],
            ['name' => '患者管理:导出', 'description' => '导出患者管理', 'type' => 'operation', 'parent' => '患者管理', 'sort' => 50, 'status' => 1],
            ['name' => '就诊记录:查看', 'description' => '查看就诊记录', 'type' => 'operation', 'parent' => '就诊记录', 'sort' => 51, 'status' => 1],
            ['name' => '就诊记录:创建', 'description' => '创建就诊记录', 'type' => 'operation', 'parent' => '就诊记录', 'sort' => 52, 'status' => 1],
            ['name' => '就诊记录:编辑', 'description' => '编辑就诊记录', 'type' => 'operation', 'parent' => '就诊记录', 'sort' => 53, 'status' => 1],
            ['name' => '就诊记录:删除', 'description' => '删除就诊记录', 'type' => 'operation', 'parent' => '就诊记录', 'sort' => 54, 'status' => 1],
            ['name' => '就诊记录:批量删除', 'description' => '批量删除就诊记录', 'type' => 'operation', 'parent' => '就诊记录', 'sort' => 55, 'status' => 1],
            ['name' => '就诊记录:导出', 'description' => '导出就诊记录', 'type' => 'operation', 'parent' => '就诊记录', 'sort' => 56, 'status' => 1],
            ['name' => '受理记录:查看', 'description' => '查看受理记录', 'type' => 'operation', 'parent' => '受理记录', 'sort' => 57, 'status' => 1],
            ['name' => '受理记录:创建', 'description' => '创建受理记录', 'type' => 'operation', 'parent' => '受理记录', 'sort' => 58, 'status' => 1],
            ['name' => '受理记录:编辑', 'description' => '编辑受理记录', 'type' => 'operation', 'parent' => '受理记录', 'sort' => 59, 'status' => 1],
            ['name' => '受理记录:删除', 'description' => '删除受理记录', 'type' => 'operation', 'parent' => '受理记录', 'sort' => 60, 'status' => 1],
            ['name' => '受理记录:导出', 'description' => '导出受理记录', 'type' => 'operation', 'parent' => '受理记录', 'sort' => 61, 'status' => 1],

            ['name' => '联网结算:查看', 'description' => '查看联网结算列表', 'type' => 'operation', 'parent' => '联网结算', 'sort' => 1, 'status' => 1],
            ['name' => '联网结算:导入', 'description' => '导入结算数据', 'type' => 'operation', 'parent' => '联网结算', 'sort' => 2, 'status' => 1],
            ['name' => '联网结算:导出明细', 'description' => '导出联网结算明细数据', 'type' => 'operation', 'parent' => '联网结算', 'sort' => 3, 'status' => 1],
            ['name' => '联网结算:导出台账', 'description' => '导出联网结算台账数据', 'type' => 'operation', 'parent' => '联网结算', 'sort' => 4, 'status' => 1],
            ['name' => '联网结算:标记', 'description' => '标记支付状态', 'type' => 'operation', 'parent' => '联网结算', 'sort' => 5, 'status' => 1],
            ['name' => '联网结算:删除', 'description' => '物理/逻辑删除数据', 'type' => 'operation', 'parent' => '联网结算', 'sort' => 6, 'status' => 1],
            ['name' => '联网结算:重算', 'description' => '重新计算补助金额', 'type' => 'operation', 'parent' => '联网结算', 'sort' => 7, 'status' => 0],

            ['name' => '未救助明细:查看', 'description' => '查看未救助明细', 'type' => 'operation', 'parent' => '未救助明细', 'sort' => 70, 'status' => 1],
            ['name' => '未救助明细:导入', 'description' => '导入未救助明细', 'type' => 'operation', 'parent' => '未救助明细', 'sort' => 71, 'status' => 1],
            ['name' => '未救助明细:清洗', 'description' => '执行清洗', 'type' => 'operation', 'parent' => '未救助明细', 'sort' => 72, 'status' => 1],
            ['name' => '未救助明细:下放', 'description' => '下放镇街', 'type' => 'operation', 'parent' => '未救助明细', 'sort' => 73, 'status' => 1],
            ['name' => '未救助明细:通知', 'description' => '标记通知', 'type' => 'operation', 'parent' => '未救助明细', 'sort' => 74, 'status' => 1],
            ['name' => '未救助明细:账户回填', 'description' => '账户回填', 'type' => 'operation', 'parent' => '未救助明细', 'sort' => 75, 'status' => 1],
            ['name' => '未救助明细:报销标记', 'description' => '报销标记', 'type' => 'operation', 'parent' => '未救助明细', 'sort' => 76, 'status' => 1],
            ['name' => '未救助明细:导出', 'description' => '导出未救助台账', 'type' => 'operation', 'parent' => '未救助明细', 'sort' => 77, 'status' => 1],
            ['name' => '重大疾病编码:查看', 'description' => '查看重大疾病编码', 'type' => 'operation', 'parent' => '重大疾病编码', 'sort' => 78, 'status' => 1],
            ['name' => '重大疾病编码:创建', 'description' => '创建重大疾病编码', 'type' => 'operation', 'parent' => '重大疾病编码', 'sort' => 79, 'status' => 1],
            ['name' => '重大疾病编码:编辑', 'description' => '编辑重大疾病编码', 'type' => 'operation', 'parent' => '重大疾病编码', 'sort' => 80, 'status' => 1],
            ['name' => '重大疾病编码:删除', 'description' => '删除重大疾病编码', 'type' => 'operation', 'parent' => '重大疾病编码', 'sort' => 81, 'status' => 1],
            ['name' => '重大疾病编码:导入', 'description' => '导入重大疾病编码', 'type' => 'operation', 'parent' => '重大疾病编码', 'sort' => 82, 'status' => 1],
        ];
    }
}
