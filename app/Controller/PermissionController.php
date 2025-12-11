<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Permission;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @Controller(prefix="/permissions")
 */
class PermissionController extends AbstractController
{
    /** @RequestMapping(path="", methods="get") */
    public function index()
    {
        $permissions = Permission::with('children')->where('id', '!=', 1)->orderBy('sort')->get();
        $tree = Permission::buildTree($permissions->toArray());
        
        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $tree
        ]);
    }

    /** @RequestMapping(path="", methods="post") */
    public function store(RequestInterface $request)
    {
        $data = $request->all();
        
        $permission = Permission::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'type' => $data['type'] ?? 'operation',
            'parent_id' => $data['parent_id'] ?? 0,
            'path' => $data['path'] ?? null,
            'component' => $data['component'] ?? null,
            'icon' => $data['icon'] ?? null,
            'sort' => $data['sort'] ?? 0,
        ]);

        return $this->response->json([
            'code' => 0,
            'msg' => '创建成功',
            'data' => $permission
        ]);
    }

    /** @RequestMapping(path="/{id}", methods="get") */
    public function show($id)
    {
        $permission = Permission::with('children')->find($id);
        
        if (!$permission) {
            return $this->response->json([
                'code' => 1,
                'msg' => '权限不存在'
            ]);
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $permission
        ]);
    }

    /** @RequestMapping(path="/{id}", methods="put") */
    public function update($id, RequestInterface $request)
    {
        $permission = Permission::find($id);
        
        if (!$permission) {
            return $this->response->json([
                'code' => 1,
                'msg' => '权限不存在'
            ]);
        }

        $data = $request->all();
        
        $permission->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'type' => $data['type'] ?? 'operation',
            'parent_id' => $data['parent_id'] ?? 0,
            'path' => $data['path'] ?? null,
            'component' => $data['component'] ?? null,
            'icon' => $data['icon'] ?? null,
            'sort' => $data['sort'] ?? 0,
        ]);

        return $this->response->json([
            'code' => 0,
            'msg' => '更新成功',
            'data' => $permission
        ]);
    }

    /** @RequestMapping(path="/{id}", methods="delete") */
    public function destroy($id)
    {
        $permission = Permission::find($id);
        
        if (!$permission) {
            return $this->response->json([
                'code' => 1,
                'msg' => '权限不存在'
            ]);
        }

        // 检查是否有子权限
        $children = Permission::where('parent_id', $id)->count();
        if ($children > 0) {
            return $this->response->json([
                'code' => 1,
                'msg' => '该权限下有子权限，无法删除'
            ]);
        }

        $permission->delete();

        return $this->response->json([
            'code' => 0,
            'msg' => '删除成功'
        ]);
    }

    /** @RequestMapping(path="/menus", methods="get") */
    public function getMenus()
    {
        $menus = Permission::getMenus();
        $tree = Permission::buildTree($menus->toArray());
        
        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $tree
        ]);
    }

    /** @RequestMapping(path="/operations", methods="get") */
    public function getOperations()
    {
        $operations = Permission::getOperations();
        
        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $operations
        ]);
    }

    /** @RequestMapping(path="/user/menus", methods="get") */
    public function getUserMenus(RequestInterface $request)
    {
        // 从JWT中获取用户权限
        $token = $request->getHeaderLine('Authorization');
        $token = str_replace('Bearer ', '', $token);

        if (!$token) {
            return $this->response->json([
                'code' => 401,
                'msg' => 'Token 不能为空'
            ]);
        }

        try {
            $jwtSecret = env('JWT_SECRET', 'your-secret-key');
            $payload = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($jwtSecret, 'HS256'));
            
            $user = \App\Model\User::query()->with('roles.permissions')->find($payload->user_id);
            
            if (!$user) {
                return $this->response->json([
                    'code' => 401,
                    'msg' => '用户不存在'
                ]);
            }

            // 获取用户权限
            $userPermissions = $user->getPermissions();
            
            // 获取用户可访问的菜单
            $menus = Permission::getUserMenus($userPermissions);

            return $this->response->json([
                'code' => 0,
                'msg' => '获取成功',
                'data' => $menus
            ]);

        } catch (\Exception $e) {
            return $this->response->json([
                'code' => 401,
                'msg' => 'Token 无效'
            ]);
        }
    }

    /** @RequestMapping(path="/validate", methods="post") */
    public function validatePermissions(RequestInterface $request)
    {
        $data = $request->all();
        $permissionNames = $data['permissions'] ?? [];
        
        if (empty($permissionNames)) {
            return $this->response->json([
                'code' => 1,
                'msg' => '请提供要验证的权限名称'
            ]);
        }

        // 查询权限是否存在
        $existingPermissions = Permission::whereIn('name', $permissionNames)->get();
        $existingNames = $existingPermissions->pluck('name')->toArray();
        
        $missingPermissions = array_diff($permissionNames, $existingNames);
        $validPermissions = array_intersect($permissionNames, $existingNames);
        
        $result = [
            'valid' => $validPermissions,
            'missing' => array_values($missingPermissions),
            'total_requested' => count($permissionNames),
            'total_valid' => count($validPermissions),
            'total_missing' => count($missingPermissions),
            'is_all_valid' => empty($missingPermissions)
        ];
        
        return $this->response->json([
            'code' => 0,
            'msg' => '验证完成',
            'data' => $result
        ]);
    }

    /** @RequestMapping(path="/generate", methods="post") */
    public function generateMissingPermissions(RequestInterface $request)
    {
        $data = $request->all();
        $permissionNames = $data['permissions'] ?? [];
        
        if (empty($permissionNames)) {
            return $this->response->json([
                'code' => 1,
                'msg' => '请提供要生成的权限名称'
            ]);
        }

        $createdPermissions = [];
        $existingPermissions = [];
        $errors = [];

        foreach ($permissionNames as $permissionName) {
            try {
                // 检查权限是否已存在
                $existing = Permission::where('name', $permissionName)->first();
                if ($existing) {
                    $existingPermissions[] = $permissionName;
                    continue;
                }

                // 生成权限描述
                $description = $this->generatePermissionDescription($permissionName);
                
                // 创建权限
                $permission = Permission::create([
                    'name' => $permissionName,
                    'description' => $description,
                    'type' => 'operation',
                    'parent_id' => 0,
                    'sort' => Permission::max('sort') + 1
                ]);

                $createdPermissions[] = [
                    'name' => $permissionName,
                    'description' => $description,
                    'id' => $permission->id
                ];
            } catch (\Exception $e) {
                $errors[] = [
                    'name' => $permissionName,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '权限生成完成',
            'data' => [
                'created' => $createdPermissions,
                'existing' => $existingPermissions,
                'errors' => $errors,
                'total_requested' => count($permissionNames),
                'total_created' => count($createdPermissions),
                'total_existing' => count($existingPermissions),
                'total_errors' => count($errors)
            ]
        ]);
    }

    /**
     * 根据权限名称生成描述
     */
    private function generatePermissionDescription($permissionName)
    {
        $descriptions = [
            'dashboard' => '访问仪表盘',
            'data-verification' => '访问数据核实模块',
            'insurance-data' => '访问参保数据',
            'insurance-summary' => '访问参保汇总',
            'tax-summary' => '访问税务汇总',
            'identity-verification' => '访问身份核实',
            'system-management' => '访问系统管理',
            'user-management' => '访问用户管理',
            'role-management' => '访问角色管理',
            'permission-management' => '访问权限管理',
            'insurance-level-config' => '访问参保档次配置',
            'category-conversion' => '访问类别转换',
        ];

        // 处理操作权限
        if (strpos($permissionName, ':') !== false) {
            $parts = explode(':', $permissionName);
            $module = $parts[0];
            $action = $parts[1];
            
            $actionDescriptions = [
                'view' => '查看',
                'create' => '创建',
                'edit' => '编辑',
                'delete' => '删除',
                'export' => '导出',
                'import' => '导入',
                'verify' => '核实',
                'assign-permissions' => '分配权限'
            ];

            $actionDesc = $actionDescriptions[$action] ?? $action;
            $moduleDesc = $descriptions[$module] ?? $module;
            
            return $actionDesc . $moduleDesc;
        }

        return $descriptions[$permissionName] ?? $permissionName;
    }
}
