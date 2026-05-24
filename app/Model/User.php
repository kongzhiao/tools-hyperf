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
    protected array $fillable = ['username', 'password', 'nickname', 'town_id'];
    
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = [
        'id' => 'integer',
        'town_id' => 'integer',
    ];
    
    /**
     * 用户角色关联
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    /**
     * 所属镇街。为空表示不按镇街限制数据范围。
     */
    public function town()
    {
        return $this->belongsTo(Town::class, 'town_id');
    }
    
    /**
     * 获取用户权限
     */
    public function getPermissions()
    {
        if ($this->id == 1) {
            return Permission::all()->pluck('name')->toArray();
        }
        
        $permissions = [];
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                if (($permission->status ?? 1) == 1) {
                    $permissions[] = $permission->name;
                }
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
            'town_id' => $this->town_id,
            'town_name' => $this->town ? $this->town->name : null,
            'permissions' => $this->getPermissions(),
        ];
    }
}
