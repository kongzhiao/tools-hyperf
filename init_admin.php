<?php
// 用于初始化默认admin账户、超级管理员角色及权限分配
require_once __DIR__ . '/vendor/autoload.php';

use App\Model\User;
use App\Model\Role;
use App\Model\Permission;
use Hyperf\DbConnection\Db;

// 兼容非Swoole CLI环境
if (!function_exists('di')) {
    function di($id = null) {
        static $container;
        if (!$container) {
            $container = require __DIR__ . '/config/container.php';
        }
        return $id ? $container->get($id) : $container;
    }
}

Db::beginTransaction();
try {
    // 1. 创建admin用户
    $admin = User::firstOrCreate([
        'username' => 'admin'
    ], [
        'password' => password_hash('123456', PASSWORD_DEFAULT)
    ]);

    // 2. 创建超级管理员角色
    $role = Role::firstOrCreate([
        'name' => '超级管理员'
    ], [
        'description' => '拥有所有权限'
    ]);

    // 3. 赋予admin角色
    $admin->roles()->syncWithoutDetaching([$role->id]);

    // 4. 查询所有权限
    $permissions = Permission::all();

    // 5. 赋予角色所有权限
    $role->permissions()->sync($permissions->pluck('id')->toArray());

    Db::commit();
    echo "默认账户admin创建并赋权成功\n";
} catch (Throwable $e) {
    Db::rollBack();
    echo $e->getMessage() . "\n";
    exit(1);
}
