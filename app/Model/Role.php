<?php
namespace App\Model;

use Hyperf\DbConnection\Model\Model;


class Role extends Model
{
    protected ?string $table = 'roles';
    protected array $fillable = ['name', 'description'];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }
}
