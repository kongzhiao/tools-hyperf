<?php
namespace App\Controller;

use App\Model\Role;
use App\Model\Permission;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Container\ContainerInterface;
use Hyperf\Redis\Redis;
use Hyperf\Context\ApplicationContext;

/**
 * @Controller(prefix="/roles")
 */
class RoleController extends AbstractController
{
    /**
     * @OA\Get(
     *     path="/roles",
     *     operationId="listRoles",
     *     summary="角色列表",
     *     @OA\Response(response=200, description="成功")
     * )
     * @RequestMapping(path="", methods="get")
     */
    public function index()
    {
        $roles = Role::where('id', '!=', 1)->get();

        // 为每个角色构建权限树形结构
        foreach ($roles as $role) {
            // 获取角色的权限ID列表 - 使用关系查询
            $rolePermissions = $role->permissions;
            $rolePermissionIds = $rolePermissions->pluck('id')->toArray();

            if (!empty($rolePermissionIds)) {
                // 获取所有权限并构建树形结构
                $allPermissions = Permission::with('children')->orderBy('sort')->get();
                $tree = Permission::buildTree($allPermissions->toArray());

                // 定义一个递归函数，检查某个权限及其所有后代中是否有任何一个被选中
                $hasAnyDescendantPermission = function ($permission, $rolePermissionIds) use (&$hasAnyDescendantPermission) {
                    // 检查当前节点
                    if (in_array($permission['id'], $rolePermissionIds)) {
                        return true;
                    }
                    // 检查所有子节点
                    if (isset($permission['children']) && is_array($permission['children'])) {
                        foreach ($permission['children'] as $child) {
                            if ($hasAnyDescendantPermission($child, $rolePermissionIds)) {
                                return true;
                            }
                        }
                    }
                    return false;
                };

                // 构建完整的权限树状结构
                $buildPermissionTree = function ($permissions) use ($rolePermissionIds, &$buildPermissionTree, $hasAnyDescendantPermission) {
                    $tree = [];
                    foreach ($permissions as $permission) {
                        // 只要当前节点或其子孙节点中有被选中的，就保留该分支
                        if ($hasAnyDescendantPermission($permission, $rolePermissionIds)) {
                            $permissionNode = $permission;

                            // 递归处理子节点
                            if (isset($permission['children']) && is_array($permission['children'])) {
                                $permissionNode['children'] = $buildPermissionTree($permission['children']);
                            } else {
                                $permissionNode['children'] = [];
                            }

                            $tree[] = $permissionNode;
                        }
                    }
                    return $tree;
                };

                $role->permissions = $buildPermissionTree($tree);
            } else {
                $role->permissions = [];
            }
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $roles
        ]);
    }

    /**
     * @OA\Get(
     *     path="/roles/{id}/permissions",
     *     operationId="getRolePermissions",
     *     summary="获取角色权限",
     *     @OA\Parameter(name="id", in="path", required=true, description="角色ID"),
     *     @OA\Response(response=200, description="成功")
     * )
     * @RequestMapping(path="/roles/{id}/permissions", methods="get")
     */
    public function getPermissions($id)
    {
        $role = Role::find($id);

        if (!$role) {
            return $this->response->json([
                'code' => 404,
                'msg' => '角色不存在'
            ]);
        }

        // 获取角色的权限ID列表
        $rolePermissionIds = $role->permissions()->pluck('permissions.id')->toArray();

        // 获取所有权限并构建树形结构
        $allPermissions = Permission::with('children')->orderBy('sort')->get();
        $tree = Permission::buildTree($allPermissions->toArray());

        // 标记角色拥有的权限
        $markRolePermissions = function (&$permissions) use ($rolePermissionIds, &$markRolePermissions) {
            foreach ($permissions as &$permission) {
                $permission['has_permission'] = in_array($permission['id'], $rolePermissionIds);
                if (isset($permission['children']) && is_array($permission['children'])) {
                    $markRolePermissions($permission['children']);
                }
            }
        };
        $markRolePermissions($tree);

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $tree
        ]);
    }

    /**
     * @OA\Post(
     *     path="/roles",
     *     operationId="createRole",
     *     summary="创建角色",
     *     @OA\RequestBody(
     *         required=true,
     *         description="角色数据",
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", description="角色名称"),
     *             @OA\Property(property="description", type="string", description="角色描述")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string")
     *         )
     *     )
     * )
     * @RequestMapping(path="", methods="post")
     */
    public function store(RequestInterface $request)
    {
        $data = $request->all();
        $role = Role::create($data);
        return $role ? $role->toArray() : [];
    }

    /**
     * @OA\Put(
     *     path="/roles/{id}",
     *     operationId="updateRole",
     *     summary="更新角色",
     *     @OA\Parameter(name="id", in="path", required=true, description="角色ID"),
     *     @OA\RequestBody(
     *         required=true,
     *         description="角色数据",
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", description="角色名称"),
     *             @OA\Property(property="description", type="string", description="角色描述")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string")
     *         )
     *     )
     * )
     * @RequestMapping(path="/roles/{id}", methods="put")
     */
    public function update($id, RequestInterface $request)
    {
        $role = Role::findOrFail($id);
        $role->update($request->all());
        return $role ? $role->toArray() : [];
    }

    /**
     * @OA\Delete(
     *     path="/roles/{id}",
     *     operationId="deleteRole",
     *     summary="删除角色",
     *     @OA\Parameter(name="id", in="path", required=true, description="角色ID"),
     *     @OA\Response(response=200, description="删除成功")
     * )
     * @RequestMapping(path="/roles/{id}", methods="delete")
     */
    public function destroy($id)
    {
        $role = Role::findOrFail($id);
        $role->delete();
        return ['message' => '删除成功'];
    }

    /**
     * @OA\Post(
     *     path="/roles/{id}/permissions",
     *     operationId="assignRolePermissions",
     *     summary="分配角色权限",
     *     @OA\Parameter(name="id", in="path", required=true, description="角色ID"),
     *     @OA\RequestBody(
     *         required=true,
     *         description="权限ID数组",
     *         @OA\JsonContent(
     *             required={"permission_ids"},
     *             @OA\Property(
     *                 property="permission_ids",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 description="权限ID数组"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="分配成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="分配成功")
     *         )
     *     )
     * )
     * @RequestMapping(path="/roles/{id}/permissions", methods="post")
     */
    public function assignPermissions($id, RequestInterface $request)
    {
        $permissionIds = $request->input('permission_ids', []);
        $role = Role::findOrFail($id);

        // 移除自动将父节点权限扩展到所有子节点的逻辑
        // 因为前端通过 Tree 组件提交的 ID 列表已经包含了级联关系（或未级联的精确选择）
        // 这里直接同步，以支持精细化排除某个子操作。

        $role->permissions()->sync($permissionIds);

        // 核心：清除所有拥有该角色的用户的 Redis 缓存，实现权限即时生效
        try {
            $redis = $this->redis ?? ApplicationContext::getContainer()->get(Redis::class);
            $userIds = $role->users()->pluck('users.id')->toArray();
            foreach ($userIds as $userId) {
                $redis->del('user:cache:' . $userId);
            }
        } catch (\Exception $e) {
            error_log('Redis clear failed in RoleController::assignPermissions: ' . $e->getMessage());
        }

        return ['message' => '分配成功'];
    }
}
