<?php
namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class UserRole extends Model
{
    protected ?string $table = 'user_roles';
    protected array $fillable = ['user_id', 'role_id'];
    public bool $timestamps = false;
}
