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
    protected array $fillable = [
        'username',
        'password',
        'nickname',
        'town_id',
        'totp_required',
        'totp_secret',
        'totp_bound_at',
        'totp_reset_at',
        'session_version',
    ];

    protected array $hidden = ['password', 'totp_secret'];
    
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected array $casts = [
        'id' => 'integer',
        'town_id' => 'integer',
        'totp_required' => 'boolean',
        'session_version' => 'integer',
        'totp_bound_at' => 'datetime',
        'totp_reset_at' => 'datetime',
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
        return array_values(array_unique($permissions));
    }

    public function hasAdminCapability(): bool
    {
        if ((int) $this->id === 1) {
            return true;
        }

        return $this->roles()->where('roles.name', '管理员')->exists();
    }

    public function isTotpRequired(): bool
    {
        return (bool) $this->totp_required || $this->hasAdminCapability();
    }

    public function isTotpBound(): bool
    {
        return !empty($this->totp_secret) && !empty($this->totp_bound_at);
    }
    
    /**
     * 获取用户信息（用于 JWT）
     */
    public function toJwtArray()
    {
        $permissions = $this->getPermissions();

        return [
            'id' => $this->id,
            'username' => $this->username,
            'nickname' => $this->nickname,
            'town_id' => $this->town_id,
            'town_name' => $this->town ? $this->town->name : null,
            'totp_required' => $this->isTotpRequired(),
            'totp_bound' => $this->isTotpBound(),
            'admin_capability' => $this->hasAdminCapability(),
            'session_version' => max(1, (int) ($this->session_version ?? 1)),
            'permissions' => $permissions,
            'menus' => Permission::getUserMenus($permissions, (int) $this->id === 1),
        ];
    }
}
