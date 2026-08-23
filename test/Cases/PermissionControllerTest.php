<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Model\Permission;
use HyperfTest\HttpTestCase;

/**
 * @internal
 * @coversNothing
 */
class PermissionControllerTest extends HttpTestCase
{
    public function testStorePermission(): void
    {
        $name = $this->uniqueName('test-permission');
        try {
            $json = $this->post('/api/permissions', [
                'name' => $name,
                'description' => '测试权限',
            ], $this->authenticatedHeaders())->json();

            self::assertSame(0, $json['code'] ?? null);
            self::assertSame($name, $json['data']['name'] ?? null);
        } finally {
            Permission::query()->where('name', $name)->delete();
        }
    }

    public function testUpdatePermission(): void
    {
        $name = $this->uniqueName('update-permission');
        $updatedName = $this->uniqueName('updated-permission');
        try {
            $created = $this->post('/api/permissions', [
                'name' => $name,
                'description' => '待更新权限',
            ], $this->authenticatedHeaders())->json();
            $id = $created['data']['id'] ?? null;
            self::assertNotNull($id);

            $json = $this->put("/api/permissions/{$id}", [
                'name' => $updatedName,
                'description' => '已更新',
            ], $this->authenticatedHeaders())->json();

            self::assertSame(0, $json['code'] ?? null);
            self::assertSame($updatedName, $json['data']['name'] ?? null);
        } finally {
            Permission::query()->whereIn('name', [$name, $updatedName])->delete();
        }
    }

    public function testDeletePermission(): void
    {
        $name = $this->uniqueName('delete-permission');
        try {
            $created = $this->post('/api/permissions', [
                'name' => $name,
                'description' => '待删除权限',
            ], $this->authenticatedHeaders())->json();
            $id = $created['data']['id'] ?? null;
            self::assertNotNull($id);

            $json = $this->delete("/api/permissions/{$id}", [], $this->authenticatedHeaders())->json();
            self::assertSame(0, $json['code'] ?? null);
            self::assertSame('删除成功', $json['msg'] ?? null);
            self::assertFalse(Permission::query()->whereKey($id)->exists());
        } finally {
            Permission::query()->where('name', $name)->delete();
        }
    }

    private function uniqueName(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(6));
    }
}
