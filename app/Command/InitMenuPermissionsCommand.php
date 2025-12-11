<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Permission;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
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
        $this->setDescription('初始化菜单权限数据');
    }

    public function handle()
    {
        $this->output->writeln('开始初始化菜单权限...');

        // 清空现有权限数据
        Permission::truncate();
        $this->output->writeln('已清空现有权限数据');

        // 定义菜单权限数据 - 重新设计结构
        $menuPermissions = [
            // 主菜单
            [
                'name' => '仪表板',
                'description' => '仪表板',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/dashboard',
                'component' => '@/pages/Dashboard',
                'icon' => 'HomeOutlined',
                'sort' => 1,
            ],
            [
                'name' => '用户管理',
                'description' => '用户管理',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/user-management',
                'component' => null,
                'icon' => 'TeamOutlined',
                'sort' => 2,
            ],
            [
                'name' => '业务配置',
                'description' => '业务配置',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/business-config',
                'component' => null,
                'icon' => 'SettingOutlined',
                'sort' => 3,
            ],
            [
                'name' => '数据核实',
                'description' => '数据核实',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/data-verification',
                'component' => null,
                'icon' => 'AuditOutlined',
                'sort' => 4,
            ],
            // 添加统计汇总顶级菜单
            [
                'name' => '统计汇总',
                'description' => '统计汇总',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/statistics-summary',
                'component' => '@/pages/StatisticsSummary',
                'icon' => 'BarChartOutlined',
                'sort' => 6,
            ],
            // 添加救助报销顶级菜单
            [
                'name' => '救助报销',
                'description' => '救助报销',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/medical-assistance',
                'component' => null,
                'icon' => 'MedicineBoxOutlined',
                'sort' => 7,
            ],
            
            // 救助报销子菜单
            [
                'name' => '受理记录',
                'description' => '受理记录',
                'type' => 'menu',
                'parent_id' => 0, // 先创建，后面更新
                'path' => '/medical-assistance/reimbursement',
                'component' => '@/pages/MedicalAssistance/Reimbursement',
                'icon' => 'DollarOutlined',
                'sort' => 1,
            ],
            [
                'name' => '就诊记录',
                'description' => '就诊记录',
                'type' => 'menu',
                'parent_id' => 0, // 先创建，后面更新
                'path' => '/medical-assistance/records',
                'component' => '@/pages/MedicalAssistance/Records',
                'icon' => 'FileTextOutlined',
                'sort' => 2,
            ],
            [
                'name' => '患者管理',
                'description' => '患者管理',
                'type' => 'menu',
                'parent_id' => 0, // 先创建，后面更新
                'path' => '/medical-assistance/patients',
                'component' => '@/pages/MedicalAssistance/Patients',
                'icon' => 'UserOutlined',
                'sort' => 3,
            ],

            // 用户管理子菜单
            [
                'name' => '账户管理',
                'description' => '账户管理',
                'type' => 'menu',
                'parent_id' => 0, // 先创建，后面更新
                'path' => '/user-management/accounts',
                'component' => '@/pages/User',
                'icon' => 'UserOutlined',
                'sort' => 1,
            ],
            [
                'name' => '角色管理',
                'description' => '角色管理',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/user-management/roles',
                'component' => '@/pages/Role',
                'icon' => 'SafetyCertificateOutlined',
                'sort' => 2,
            ],
            [
                'name' => '权限管理',
                'description' => '权限管理',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/user-management/permissions',
                'component' => '@/pages/Permission',
                'icon' => 'KeyOutlined',
                'sort' => 3,
            ],

            // 业务配置子菜单
            [
                'name' => '类别转换配置',
                'description' => '类别转换配置',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/business-config/category-conversion',
                'component' => '@/pages/CategoryConversion',
                'icon' => 'SwapOutlined',
                'sort' => 1,
            ],
            [
                'name' => '参保档次配置',
                'description' => '参保档次配置',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/business-config/insurance-level-config',
                'component' => '@/pages/InsuranceLevelConfig',
                'icon' => 'ToolOutlined',
                'sort' => 2,
            ],

            // 数据核实子菜单
            [
                'name' => '参保数据管理',
                'description' => '参保数据管理',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/data-verification/insurance-data',
                'component' => '@/pages/DataVerification/InsuranceData',
                'icon' => 'FileTextOutlined',
                'sort' => 1,
            ],
            [
                'name' => '身份信息核实',
                'description' => '身份信息核实',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/data-verification/identity-verification',
                'component' => '@/pages/DataVerification/IdentityVerification',
                'icon' => 'UserOutlined',
                'sort' => 2,
            ],
            [
                'name' => '税务数据汇总',
                'description' => '税务数据汇总',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/data-verification/tax-summary',
                'component' => '@/pages/DataVerification/TaxSummary',
                'icon' => 'AccountBookOutlined',
                'sort' => 3,
            ],
            [
                'name' => '参保数据汇总',
                'description' => '参保数据汇总',
                'type' => 'menu',
                'parent_id' => 0,
                'path' => '/data-verification/insurance-summary',
                'component' => '@/pages/DataVerification/InsuranceSummary',
                'icon' => 'BarChartOutlined',
                'sort' => 4,
            ],
        ];

        // 创建权限记录
        $createdPermissions = [];
        foreach ($menuPermissions as $permission) {
            $created = Permission::create($permission);
            $createdPermissions[$created->name] = $created->id;
            $this->output->writeln("创建权限: {$permission['description']} ({$permission['name']})");
        }

        // 更新子菜单的parent_id
        $parentMappings = [
            // 用户管理子菜单
            '账户管理' => '用户管理',
            '角色管理' => '用户管理',
            '权限管理' => '用户管理',

            // 业务配置子菜单
            '类别转换配置' => '业务配置',
            '参保档次配置' => '业务配置',

            // 数据核实子菜单
            '参保数据管理' => '数据核实',
            '身份信息核实' => '数据核实',
            '税务数据汇总' => '数据核实',
            '参保数据汇总' => '数据核实',
            
            // 救助报销子菜单
            '受理记录' => '救助报销',
            '就诊记录' => '救助报销',
            '患者管理' => '救助报销',
        ];

        foreach ($parentMappings as $childName => $parentName) {
            if (isset($createdPermissions[$childName]) && isset($createdPermissions[$parentName])) {
                Permission::where('id', $createdPermissions[$childName])
                    ->update(['parent_id' => $createdPermissions[$parentName]]);
                $this->output->writeln("更新 {$childName} 的父级为 {$parentName}");
            }
        }

        // 创建操作权限 - 使用中文名称
        $operationPermissions = [
            // 账户管理操作权限
            ['name' => '账户管理:查看', 'description' => '查看账户', 'type' => 'operation', 'parent_id' => 0, 'sort' => 1],
            ['name' => '账户管理:创建', 'description' => '创建账户', 'type' => 'operation', 'parent_id' => 0, 'sort' => 2],
            ['name' => '账户管理:编辑', 'description' => '编辑账户', 'type' => 'operation', 'parent_id' => 0, 'sort' => 3],
            ['name' => '账户管理:删除', 'description' => '删除账户', 'type' => 'operation', 'parent_id' => 0, 'sort' => 4],

            // 角色管理操作权限
            ['name' => '角色管理:查看', 'description' => '查看角色', 'type' => 'operation', 'parent_id' => 0, 'sort' => 5],
            ['name' => '角色管理:创建', 'description' => '创建角色', 'type' => 'operation', 'parent_id' => 0, 'sort' => 6],
            ['name' => '角色管理:编辑', 'description' => '编辑角色', 'type' => 'operation', 'parent_id' => 0, 'sort' => 7],
            ['name' => '角色管理:删除', 'description' => '删除角色', 'type' => 'operation', 'parent_id' => 0, 'sort' => 8],
            ['name' => '角色管理:分配权限', 'description' => '分配角色权限', 'type' => 'operation', 'parent_id' => 0, 'sort' => 9],

            // 权限管理操作权限
            ['name' => '权限管理:查看', 'description' => '查看权限', 'type' => 'operation', 'parent_id' => 0, 'sort' => 10],
            ['name' => '权限管理:创建', 'description' => '创建权限', 'type' => 'operation', 'parent_id' => 0, 'sort' => 11],
            ['name' => '权限管理:编辑', 'description' => '编辑权限', 'type' => 'operation', 'parent_id' => 0, 'sort' => 12],
            ['name' => '权限管理:删除', 'description' => '删除权限', 'type' => 'operation', 'parent_id' => 0, 'sort' => 13],

            // 类别转换配置操作权限
            ['name' => '类别转换配置:查看', 'description' => '查看类别转换配置', 'type' => 'operation', 'parent_id' => 0, 'sort' => 16],
            ['name' => '类别转换配置:创建', 'description' => '创建类别转换配置', 'type' => 'operation', 'parent_id' => 0, 'sort' => 17],
            ['name' => '类别转换配置:编辑', 'description' => '编辑类别转换配置', 'type' => 'operation', 'parent_id' => 0, 'sort' => 18],
            ['name' => '类别转换配置:删除', 'description' => '删除类别转换配置', 'type' => 'operation', 'parent_id' => 0, 'sort' => 19],

            // 参保档次配置操作权限
            ['name' => '参保档次配置:查看', 'description' => '查看参保档次配置', 'type' => 'operation', 'parent_id' => 0, 'sort' => 20],
            ['name' => '参保档次配置:创建', 'description' => '创建参保档次配置', 'type' => 'operation', 'parent_id' => 0, 'sort' => 21],
            ['name' => '参保档次配置:编辑', 'description' => '编辑参保档次配置', 'type' => 'operation', 'parent_id' => 0, 'sort' => 22],
            ['name' => '参保档次配置:删除', 'description' => '删除参保档次配置', 'type' => 'operation', 'parent_id' => 0, 'sort' => 23],

            // 参保数据管理操作权限
            ['name' => '参保数据管理:查看', 'description' => '查看参保数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 24],
            ['name' => '参保数据管理:创建', 'description' => '创建参保数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 25],
            ['name' => '参保数据管理:编辑', 'description' => '编辑参保数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 26],
            ['name' => '参保数据管理:删除', 'description' => '删除参保数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 27],
            ['name' => '参保数据管理:导出', 'description' => '导出参保数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 28],
            ['name' => '参保数据管理:导入', 'description' => '导入参保数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 29],

            // 身份信息核实操作权限
            ['name' => '身份信息核实:查看', 'description' => '查看身份信息核实', 'type' => 'operation', 'parent_id' => 0, 'sort' => 30],
            ['name' => '身份信息核实:执行', 'description' => '执行身份信息核实', 'type' => 'operation', 'parent_id' => 0, 'sort' => 31],

            // 税务数据汇总操作权限
            ['name' => '税务数据汇总:查看', 'description' => '查看税务数据汇总', 'type' => 'operation', 'parent_id' => 0, 'sort' => 32],
            ['name' => '税务数据汇总:导出', 'description' => '导出税务数据汇总', 'type' => 'operation', 'parent_id' => 0, 'sort' => 33],

            // 参保数据汇总操作权限
            ['name' => '参保数据汇总:查看', 'description' => '查看参保数据汇总', 'type' => 'operation', 'parent_id' => 0, 'sort' => 34],
            ['name' => '参保数据汇总:导出', 'description' => '导出参保数据汇总', 'type' => 'operation', 'parent_id' => 0, 'sort' => 35],
            
            // 统计汇总操作权限
            ['name' => '统计汇总:查看', 'description' => '查看统计汇总', 'type' => 'operation', 'parent_id' => 0, 'sort' => 38],
            ['name' => '统计汇总:导入', 'description' => '导入统计数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 39],
            ['name' => '统计汇总:导出明细', 'description' => '导出统计数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 40],
            ['name' => '统计汇总:导出统计数据', 'description' => '导出统计数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 41],
            ['name' => '统计汇总:创建', 'description' => '创建统计数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 42],
            ['name' => '统计汇总:编辑', 'description' => '编辑统计数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 43],
            ['name' => '统计汇总:删除', 'description' => '删除统计数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 44],
            ['name' => '统计汇总:清空数据', 'description' => '清空统计数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 45],
            ['name' => '统计汇总:批量删除', 'description' => '批量删除统计数据', 'type' => 'operation', 'parent_id' => 0, 'sort' => 46],


            // 患者管理操作权限
            ['name' => '患者管理:查看', 'description' => '查看患者管理', 'type' => 'operation', 'parent_id' => 0, 'sort' => 46],
            ['name' => '患者管理:创建', 'description' => '创建患者管理', 'type' => 'operation', 'parent_id' => 0, 'sort' => 47],
            ['name' => '患者管理:编辑', 'description' => '编辑患者管理', 'type' => 'operation', 'parent_id' => 0, 'sort' => 48],
            ['name' => '患者管理:删除', 'description' => '删除患者管理', 'type' => 'operation', 'parent_id' => 0, 'sort' => 49],
            ['name' => '患者管理:导出', 'description' => '导出患者管理', 'type' => 'operation', 'parent_id' => 0, 'sort' => 50],
            
            // 就诊记录操作权限
            ['name' => '就诊记录:查看', 'description' => '查看就诊记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 51],
            ['name' => '就诊记录:创建', 'description' => '创建就诊记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 52],
            ['name' => '就诊记录:编辑', 'description' => '编辑就诊记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 53],
            ['name' => '就诊记录:删除', 'description' => '删除就诊记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 54],
            ['name' => '就诊记录:批量删除', 'description' => '批量删除就诊记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 55],
            ['name' => '就诊记录:导出', 'description' => '导出就诊记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 56],
            
            // 受理记录操作权限
            ['name' => '受理记录:查看', 'description' => '查看受理记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 57],
            ['name' => '受理记录:创建', 'description' => '创建受理记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 58],
            ['name' => '受理记录:编辑', 'description' => '编辑受理记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 59],
            ['name' => '受理记录:删除', 'description' => '删除受理记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 60],
            ['name' => '受理记录:导出', 'description' => '导出受理记录', 'type' => 'operation', 'parent_id' => 0, 'sort' => 61],
        ];

        foreach ($operationPermissions as $permission) {
            $created = Permission::create($permission);
            $createdPermissions[$created->name] = $created->id;
            $this->output->writeln("创建操作权限: {$permission['description']} ({$permission['name']})");
        }

        // 更新操作权限的parent_id，将它们归属到对应的菜单权限
        $operationParentMappings = [
            // 账户管理相关操作权限 -> 账户管理
            '账户管理:查看' => '账户管理',
            '账户管理:创建' => '账户管理',
            '账户管理:编辑' => '账户管理',
            '账户管理:删除' => '账户管理',

            // 角色管理相关操作权限 -> 角色管理
            '角色管理:查看' => '角色管理',
            '角色管理:创建' => '角色管理',
            '角色管理:编辑' => '角色管理',
            '角色管理:删除' => '角色管理',
            '角色管理:分配权限' => '角色管理',

            // 权限管理相关操作权限 -> 权限管理
            '权限管理:查看' => '权限管理',
            '权限管理:创建' => '权限管理',
            '权限管理:编辑' => '权限管理',
            '权限管理:删除' => '权限管理',

            // 权限验证工具相关操作权限 -> 权限验证工具
            '权限验证工具:查看' => '权限验证工具',
            '权限验证工具:执行' => '权限验证工具',

            // 类别转换配置相关操作权限 -> 类别转换配置
            '类别转换配置:查看' => '类别转换配置',
            '类别转换配置:创建' => '类别转换配置',
            '类别转换配置:编辑' => '类别转换配置',
            '类别转换配置:删除' => '类别转换配置',

            // 参保档次配置相关操作权限 -> 参保档次配置
            '参保档次配置:查看' => '参保档次配置',
            '参保档次配置:创建' => '参保档次配置',
            '参保档次配置:编辑' => '参保档次配置',
            '参保档次配置:删除' => '参保档次配置',

            // 参保数据管理相关操作权限 -> 参保数据管理
            '参保数据管理:查看' => '参保数据管理',
            '参保数据管理:创建' => '参保数据管理',
            '参保数据管理:编辑' => '参保数据管理',
            '参保数据管理:删除' => '参保数据管理',
            '参保数据管理:导出' => '参保数据管理',
            '参保数据管理:导入' => '参保数据管理',

            // 身份信息核实相关操作权限 -> 身份信息核实
            '身份信息核实:查看' => '身份信息核实',
            '身份信息核实:执行' => '身份信息核实',

            // 税务数据汇总相关操作权限 -> 税务数据汇总
            '税务数据汇总:查看' => '税务数据汇总',
            '税务数据汇总:导出' => '税务数据汇总',

            // 参保数据汇总相关操作权限 -> 参保数据汇总
            '参保数据汇总:查看' => '参保数据汇总',
            '参保数据汇总:导出' => '参保数据汇总',

            // 统计汇总相关操作权限 -> 统计汇总
            '统计汇总:查看' => '统计汇总',
            '统计汇总:导入' => '统计汇总',
            '统计汇总:导出明细' => '统计汇总',
            '统计汇总:导出统计数据' => '统计汇总',
            '统计汇总:创建' => '统计汇总',
            '统计汇总:编辑' => '统计汇总',
            '统计汇总:清空数据' => '统计汇总',
            '统计汇总:批量删除' => '统计汇总',
            '统计汇总:删除' => '统计汇总',            
            
            // 救助报销相关操作权限 -> 救助报销
            '救助报销:查看' => '救助报销',
            '救助报销:创建' => '救助报销',
            '救助报销:编辑' => '救助报销',
            '救助报销:删除' => '救助报销',
            '救助报销:导出' => '救助报销',
            
            // 患者管理相关操作权限 -> 患者管理
            '患者管理:查看' => '患者管理',
            '患者管理:创建' => '患者管理',
            '患者管理:编辑' => '患者管理',
            '患者管理:删除' => '患者管理',
            '患者管理:导出' => '患者管理',
            
            // 就诊记录相关操作权限 -> 就诊记录
            '就诊记录:查看' => '就诊记录',
            '就诊记录:创建' => '就诊记录',
            '就诊记录:编辑' => '就诊记录',
            '就诊记录:删除' => '就诊记录',
            '就诊记录:批量删除' => '就诊记录',
            '就诊记录:导出' => '就诊记录',
            
            // 受理记录相关操作权限 -> 受理记录
            '受理记录:查看' => '受理记录',
            '受理记录:创建' => '受理记录',
            '受理记录:编辑' => '受理记录',
            '受理记录:删除' => '受理记录',
            '受理记录:导出' => '受理记录',
        ];

        foreach ($operationParentMappings as $operationName => $parentMenuName) {
            if (isset($createdPermissions[$operationName]) && isset($createdPermissions[$parentMenuName])) {
                Permission::where('id', $createdPermissions[$operationName])
                    ->update(['parent_id' => $createdPermissions[$parentMenuName]]);
                $this->output->writeln("更新操作权限 {$operationName} 的父级为 {$parentMenuName}");
            }
        }

        $this->output->writeln('菜单权限初始化完成！');
        $this->output->writeln('总计创建了 ' . count($menuPermissions) . ' 个菜单权限和 ' . count($operationPermissions) . ' 个操作权限');
        
        $this->output->writeln('');
        $this->output->writeln('新的菜单结构：');
        $this->output->writeln('├── 仪表板');
        $this->output->writeln('├── 用户管理');
        $this->output->writeln('│   ├── 账户管理');
        $this->output->writeln('│   ├── 角色管理');
        $this->output->writeln('│   └── 权限管理');
        $this->output->writeln('├── 业务配置');
        $this->output->writeln('│   ├── 类别转换配置');
        $this->output->writeln('│   └── 参保档次配置');
        $this->output->writeln('├── 数据核实');
        $this->output->writeln('│   ├── 参保数据管理');
        $this->output->writeln('│   ├── 身份信息核实');
        $this->output->writeln('│   ├── 税务数据汇总');
        $this->output->writeln('│   └── 参保数据汇总');
        $this->output->writeln('├── 统计汇总');
        $this->output->writeln('└── 救助报销');
        $this->output->writeln('    ├── 受理记录');
        $this->output->writeln('    ├── 就诊记录');
        $this->output->writeln('    └── 患者管理');
    }
} 