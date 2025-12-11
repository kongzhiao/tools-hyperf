<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use HyperfTest\HttpTestCase;

/**
 * @internal
 * @coversNothing
 */
class PermissionControllerTest extends HttpTestCase
{
    public function testStorePermission()
    {
        $data = [
            'name' => 'test-permission',
            'description' => '测试权限',
        ];
        $response = $this->post('/permissions', $data);
        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayHasKey('id', $json);
        $this->assertEquals('test-permission', $json['name']);
    }

    public function testUpdatePermission()
    {
        // 先创建
        $data = [
            'name' => 'update-permission',
            'description' => '待更新权限',
        ];
        $created = $this->post('/permissions', $data)->json();
        $id = $created['id'] ?? null;
        $this->assertNotNull($id);
        // 更新
        $update = [
            'name' => 'updated-permission',
            'description' => '已更新',
        ];
        $response = $this->put("/permissions/{$id}", $update);
        $json = $response->json();
        $this->assertEquals('updated-permission', $json['name']);
    }

    public function testDeletePermission()
    {
        // 先创建
        $data = [
            'name' => 'delete-permission',
            'description' => '待删除权限',
        ];
        $created = $this->post('/permissions', $data)->json();
        $id = $created['id'] ?? null;
        $this->assertNotNull($id);
        // 删除
        $response = $this->delete("/permissions/{$id}");
        $json = $response->json();
        $this->assertArrayHasKey('message', $json);
        $this->assertStringContainsString('删除成功', $json['message']);
    }
}
