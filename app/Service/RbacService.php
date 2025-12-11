<?php
namespace App\Service;

use App\Model\User;

class RbacService
{
    public function getUserRoles(int $userId): array
    {
        $user = User::find($userId);
        return $user ? $user->roles()->pluck('name')->toArray() : [];
    }

    public function getUserPermissions(int $userId): array
    {
        $user = User::find($userId);
        $permissions = [];
        if ($user) {
            foreach ($user->roles as $role) {
                $permissions = array_merge($permissions, $role->permissions()->pluck('name')->toArray());
            }
        }
        return array_unique($permissions);
    }

    public function checkPermission(int $userId, string $permission): bool
    {
        return in_array($permission, $this->getUserPermissions($userId));
    }
}
