<?php
namespace App\Command;

use App\Model\User;
use App\Model\Role;
use App\Model\Permission;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Throwable;

class InitAdminCommand extends HyperfCommand
{
    public function __construct()
    {
        parent::__construct();
        $this->setName('init:admin');
        $this->setDescription('初始化默认admin账户并赋予所有权限');
    }
    public function configure()
    {
        parent::configure();
        $this->setDescription('初始化默认admin账户并赋予所有权限');
    }

    public function handle()
    {
        Db::beginTransaction();
        try {
            // 1. 创建admin用户
            $admin = User::firstOrCreate([
                'username' => 'admin'
            ], [
                'password' => password_hash('123456', PASSWORD_DEFAULT),
                'nickname' => '系统管理员'
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
            $this->line('默认账户admin创建并赋权成功', 'info');
        } catch (Throwable $e) {
            Db::rollBack();
            $this->line($e->getMessage(), 'error');
        }
    }
}
