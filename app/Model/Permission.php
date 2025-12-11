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
        'sort'
    ];

    protected array $casts = [
        'parent_id' => 'integer',
        'sort' => 'integer'
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
        return $this->belongsToMany(Role::class, 'permission_role', 'permission_id', 'role_id');
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
    public static function getUserMenus($userPermissions)
    {
        $menus = self::where('type', 'menu')->with('children')->get();
        $accessibleMenus = [];

        foreach ($menus as $menu) {
            // 检查用户是否有该菜单的权限
            $hasPermission = false;
            
            // 检查菜单本身
            if (in_array($menu->name, $userPermissions) || in_array('*', $userPermissions)) {
                $hasPermission = true;
            }
            
            // 检查子菜单
            if ($menu->children) {
                $accessibleChildren = [];
                foreach ($menu->children as $child) {
                    // 检查子菜单本身是否有权限
                    if (in_array($child->name, $userPermissions) || in_array('*', $userPermissions)) {
                        $accessibleChildren[] = $child;
                    } else {
                        // 检查子菜单的操作权限（type = 'operation'）
                        $childOperations = self::where('parent_id', $child->id)
                            ->where('type', 'operation')
                            ->get();
                        
                        $hasOperationPermission = false;
                        foreach ($childOperations as $operation) {
                            if (in_array($operation->name, $userPermissions) || in_array('*', $userPermissions)) {
                                $hasOperationPermission = true;
                                break;
                            }
                        }
                        
                        if ($hasOperationPermission) {
                            $accessibleChildren[] = $child;
                        }
                    }
                }
                
                if (!empty($accessibleChildren)) {
                    $menu->children = $accessibleChildren;
                    // 如果有可访问的子菜单，父菜单也应该显示
                    $hasPermission = true;
                }
            }
            
            if ($hasPermission) {
                $accessibleMenus[] = $menu->toArray();
            }
        }

        return self::buildTree($accessibleMenus);
    }
}
