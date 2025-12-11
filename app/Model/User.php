<?php

declare (strict_types=1);
namespace App\Model;

/**
 */
class User extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string|null
     */
    protected ?string $table = 'users';
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected array $fillable = ['username', 'password', 'nickname'];
    
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = [];
    
    /**
     * 用户角色关联
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }
    
    /**
     * 获取用户权限
     */
    public function getPermissions()
    {
        $permissions = [];
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $permissions[] = $permission->name;
            }
        }
        return array_unique($permissions);
    }
    
    /**
     * 获取用户信息（用于 JWT）
     */
    public function toJwtArray()
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'permissions' => $this->getPermissions(),
        ];
    }
}