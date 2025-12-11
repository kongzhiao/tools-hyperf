<?php
namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class RolePermission extends Model
{
    protected ?string $table = 'role_permissions';
    protected array $fillable = ['role_id', 'permission_id'];
    public bool $timestamps = false;
}
