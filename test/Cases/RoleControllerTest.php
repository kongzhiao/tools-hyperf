<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use HyperfTest\HttpTestCase;

/**
 * @internal
 * @coversNothing
 */
class RoleControllerTest extends HttpTestCase
{
    public function testIndexRoles()
    {
        $response = $this->get('/roles');
        $json = $response->json();
        $this->assertIsArray($json);
    }

    public function testStoreRole()
    {
        $data = [
            'name' => 'test-role',
            'description' => '测试角色',
        ];
        $response = $this->post('/roles', $data);
        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayHasKey('id', $json);
        $this->assertEquals('test-role', $json['name']);
    }

    public function testUpdateRole()
    {
        $data = [
            'name' => 'update-role',
            'description' => '待更新角色',
        ];
        $created = $this->post('/roles', $data)->json();
        $id = $created['id'] ?? null;
        $this->assertNotNull($id);
        $update = [
            'name' => 'updated-role',
            'description' => '已更新',
        ];
        $response = $this->put("/roles/{$id}", $update);
        $json = $response->json();
        $this->assertEquals('updated-role', $json['name']);
    }

    public function testDeleteRole()
    {
        $data = [
            'name' => 'delete-role',
            'description' => '待删除角色',
        ];
        $created = $this->post('/roles', $data)->json();
        $id = $created['id'] ?? null;
        $this->assertNotNull($id);
        $response = $this->delete("/roles/{$id}");
        $json = $response->json();
        $this->assertArrayHasKey('message', $json);
        $this->assertStringContainsString('删除成功', $json['message']);
    }

    public function testAssignPermissions()
    {
        // 创建角色
        $role = $this->post('/roles', ['name' => 'assign-role', 'description' => '分配权限'])->json();
        $roleId = $role['id'] ?? null;
        $this->assertNotNull($roleId);
        // 创建权限
        $permission = $this->post('/permissions', ['name' => 'assign-perm', 'description' => '分配用'])->json();
        $permId = $permission['id'] ?? null;
        $this->assertNotNull($permId);
        // 分配权限
        $response = $this->post("/roles/{$roleId}/permissions", ['permission_ids' => [$permId]]);
        $json = $response->json();
        $this->assertArrayHasKey('message', $json);
        $this->assertStringContainsString('分配成功', $json['message']);
    }
}
