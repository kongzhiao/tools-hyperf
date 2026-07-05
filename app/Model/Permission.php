<?php
declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Permission extends Model
{
    protected ?string $table = 'permissions';
    
    protected array $fillable = [
        'name',
        'description',
        'type',
        'parent_id',
        'path',
        'component',
        'icon',
        'sort',
        'status'
    ];

    protected array $casts = [
        'parent_id' => 'integer',
        'sort' => 'integer',
        'status' => 'integer'
    ];

    // 获取子权限
    public function children()
    {
        return $this->hasMany(Permission::class, 'parent_id', 'id')->orderBy('sort');
    }

    // 获取父权限
    public function parent()
    {
        return $this->belongsTo(Permission::class, 'parent_id');
    }

    // 获取角色
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions', 'permission_id', 'role_id');
    }

    // 构建树形结构
    public static function buildTree($permissions, $parentId = 0)
    {
        $tree = [];
        foreach ($permissions as $permission) {
            if ($permission['parent_id'] == $parentId) {
                $children = self::buildTree($permissions, $permission['id']);
                if ($children) {
                    $permission['children'] = $children;
                }
                $tree[] = $permission;
            }
        }
        return $tree;
    }

    // 获取菜单权限（type = 'menu'）
    public static function getMenus()
    {
        return self::where('type', 'menu')->orderBy('sort')->get();
    }

    // 获取操作权限（type = 'operation'）
    public static function getOperations()
    {
        return self::where('type', 'operation')->orderBy('sort')->get();
    }

    // 获取用户可访问的菜单
    public static function getUserMenus($userPermissions, $isAdmin = false)
    {
        $menuQuery = self::where('type', 'menu')
            ->orderBy('parent_id')
            ->orderBy('sort')
            ->orderBy('id');

        if (!$isAdmin) {
            $menuQuery->where('status', 1);
        }

        $menus = $menuQuery->get()->toArray();
        if ($isAdmin || in_array('*', $userPermissions, true)) {
            return self::buildTree($menus);
        }

        $permissionMap = array_flip($userPermissions);
        $menusById = [];
        foreach ($menus as $menu) {
            $menusById[(int) $menu['id']] = $menu;
        }

        $operationParents = [];
        $operationQuery = self::where('type', 'operation');
        if (!$isAdmin) {
            $operationQuery->where('status', 1);
        }
        $operations = $operationQuery->get(['name', 'parent_id'])->toArray();
        foreach ($operations as $operation) {
            if (isset($permissionMap[$operation['name']])) {
                $operationParents[(int) $operation['parent_id']] = true;
            }
        }

        $allowedIds = [];
        foreach ($menus as $menu) {
            $id = (int) $menu['id'];
            if (!isset($permissionMap[$menu['name']]) && !isset($operationParents[$id])) {
                continue;
            }

            $current = $menu;
            while ($current) {
                $id = (int) $current['id'];
                $allowedIds[$id] = true;
                $parentId = (int) ($current['parent_id'] ?? 0);
                $current = $parentId > 0 ? ($menusById[$parentId] ?? null) : null;
            }
        }

        $accessibleMenus = array_values(array_filter($menus, static function ($menu) use ($allowedIds) {
            return isset($allowedIds[(int) $menu['id']]);
        }));

        return self::buildTree($accessibleMenus);
    }
}
