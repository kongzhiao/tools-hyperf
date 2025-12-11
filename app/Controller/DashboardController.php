<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\User;
use App\Model\Role;
use App\Model\Permission;
use App\Model\InsuranceData;
use App\Model\CategoryConversion;
use App\Model\InsuranceLevelConfig;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Di\Annotation\Inject;

/**
 * @Controller(prefix="/api/dashboard")
 */
class DashboardController extends AbstractController
{
    /**
     * 获取仪表盘统计数据
     * @RequestMapping(path="/stats", methods="get")
     */
    public function getStats(RequestInterface $request)
    {
        try {
            // 获取用户统计
            $userCount = User::count();
            
            // 获取角色统计
            $roleCount = Role::count();
            
            // 获取权限统计
            $permissionCount = Permission::count();
            
            // 获取菜单权限统计
            $menuPermissionCount = Permission::where('type', 'menu')->count();
            
            // 获取参保数据统计
            $insuranceDataCount = InsuranceData::count();
            
            // 获取类别转换配置统计
            $categoryConversionCount = CategoryConversion::count();
            
            // 获取参保档次配置统计
            $insuranceLevelConfigCount = InsuranceLevelConfig::count();
            
            // 获取系统状态信息
            $systemStatus = [
                'status' => '正常',
                'uptime' => $this->getSystemUptime(),
                'memory_usage' => $this->getMemoryUsage(),
                'disk_usage' => $this->getDiskUsage(),
                'response_time' => $this->getAverageResponseTime(),
            ];

            return $this->response->json([
                'code' => 0,
                'msg' => '获取成功',
                'data' => [
                    'statistics' => [
                        'users' => $userCount,
                        'roles' => $roleCount,
                        'permissions' => $permissionCount,
                        'menus' => $menuPermissionCount,
                        'insurance_data' => $insuranceDataCount,
                        'category_conversions' => $categoryConversionCount,
                        'insurance_level_configs' => $insuranceLevelConfigCount,
                    ],
                    'system_status' => $systemStatus,
                    'recent_activities' => $this->getRecentActivities(),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->response->json([
                'code' => 500,
                'msg' => '获取统计数据失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取系统运行时间
     */
    private function getSystemUptime(): string
    {
        // 这里可以集成真实的系统监控
        // 暂时返回模拟数据
        $uptime = time() - strtotime('2024-01-01 00:00:00');
        $days = floor($uptime / 86400);
        $hours = floor(($uptime % 86400) / 3600);
        $minutes = floor(($uptime % 3600) / 60);
        
        return "{$days}天 {$hours}小时 {$minutes}分钟";
    }

    /**
     * 获取内存使用情况
     */
    private function getMemoryUsage(): array
    {
        // 这里可以集成真实的系统监控
        // 暂时返回模拟数据
        $total = 1024 * 1024 * 1024; // 1GB
        $used = $total * 0.65; // 65%使用率
        
        return [
            'total' => $this->formatBytes($total),
            'used' => $this->formatBytes($used),
            'percentage' => 65,
        ];
    }

    /**
     * 获取磁盘使用情况
     */
    private function getDiskUsage(): array
    {
        // 这里可以集成真实的系统监控
        // 暂时返回模拟数据
        $total = 100 * 1024 * 1024 * 1024; // 100GB
        $used = $total * 0.45; // 45%使用率
        
        return [
            'total' => $this->formatBytes($total),
            'used' => $this->formatBytes($used),
            'percentage' => 45,
        ];
    }

    /**
     * 获取平均响应时间
     */
    private function getAverageResponseTime(): string
    {
        // 这里可以集成真实的性能监控
        // 暂时返回模拟数据
        $responseTime = rand(50, 200); // 50-200ms
        return $responseTime . 'ms';
    }

    /**
     * 格式化字节数
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * 获取最近活动
     */
    private function getRecentActivities(): array
    {
        // 这里可以集成真实的日志系统
        // 暂时返回模拟数据
        return [
            [
                'id' => 1,
                'type' => 'user_login',
                'description' => '用户登录',
                'user' => 'admin',
                'time' => date('Y-m-d H:i:s', time() - 300), // 5分钟前
            ],
            [
                'id' => 2,
                'type' => 'data_import',
                'description' => '参保数据导入',
                'user' => 'admin',
                'time' => date('Y-m-d H:i:s', time() - 1800), // 30分钟前
            ],
            [
                'id' => 3,
                'type' => 'permission_update',
                'description' => '权限配置更新',
                'user' => 'admin',
                'time' => date('Y-m-d H:i:s', time() - 3600), // 1小时前
            ],
            [
                'id' => 4,
                'type' => 'system_backup',
                'description' => '系统备份',
                'user' => 'system',
                'time' => date('Y-m-d H:i:s', time() - 7200), // 2小时前
            ],
        ];
    }
} 