<?php
namespace App\Controller;

use App\Model\Role;
use App\Model\Permission;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Container\ContainerInterface;

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
                
                // 构建完整的权限树状结构
                $buildPermissionTree = function($permissions) use ($rolePermissionIds, &$buildPermissionTree) {
                    $tree = [];
                    foreach ($permissions as $permission) {
                        $permissionNode = $permission;
                        
                        // 如果有子权限，递归处理
                        if (isset($permission['children']) && is_array($permission['children'])) {
                            $children = $buildPermissionTree($permission['children']);
                            $permissionNode['children'] = $children;
                        } else {
                            $permissionNode['children'] = [];
                        }
                        
                        // 检查当前权限是否有权限
                        $hasPermission = in_array($permission['id'], $rolePermissionIds);
                        
                        // 检查子权限中是否有权限
                        $hasChildPermission = false;
                        if (isset($permission['children']) && is_array($permission['children'])) {
                            foreach ($permission['children'] as $child) {
                                if (in_array($child['id'], $rolePermissionIds)) {
                                    $hasChildPermission = true;
                                    break;
                                }
                            }
                        }
                        
                        // 如果当前权限有权限，或者子权限中有权限，就包含这个节点
                        if ($hasPermission || $hasChildPermission) {
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
        $markRolePermissions = function(&$permissions) use ($rolePermissionIds, &$markRolePermissions) {
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
        
        // 获取所有权限的树形结构
        $allPermissions = Permission::with('children')->orderBy('sort')->get();
        $tree = Permission::buildTree($allPermissions->toArray());
        
        // 递归获取所有子权限ID
        $getAllChildIds = function($permissionId, $permissions) use (&$getAllChildIds) {
            $childIds = [];
            foreach ($permissions as $permission) {
                if ($permission['id'] == $permissionId) {
                    if (isset($permission['children']) && is_array($permission['children'])) {
                        foreach ($permission['children'] as $child) {
                            $childIds[] = $child['id'];
                            // 递归获取更深层的子权限
                            $grandChildIds = $getAllChildIds($child['id'], $permissions);
                            $childIds = array_merge($childIds, $grandChildIds);
                        }
                    }
                    break;
                }
                // 如果在当前层级没找到，递归查找子层级
                if (isset($permission['children']) && is_array($permission['children'])) {
                    $foundInChildren = $getAllChildIds($permissionId, $permission['children']);
                    if (!empty($foundInChildren)) {
                        return $foundInChildren;
                    }
                }
            }
            return $childIds;
        };
        
        // 扩展权限ID列表，包含所有子权限
        $expandedPermissionIds = [];
        foreach ($permissionIds as $permissionId) {
            $expandedPermissionIds[] = $permissionId;
            $childIds = $getAllChildIds($permissionId, $tree);
            $expandedPermissionIds = array_merge($expandedPermissionIds, $childIds);
        }
        
        // 去重
        $expandedPermissionIds = array_unique($expandedPermissionIds);
        
        $role->permissions()->sync($expandedPermissionIds);
        return ['message' => '分配成功'];
    }
}
