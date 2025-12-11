<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace HyperfTest\Cases;

use HyperfTest\HttpTestCase;

/**
 * @internal
 * @coversNothing
 */
class ExampleTest extends HttpTestCase
{
    public function testExample()
    {
        $response = $this->get('/');
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('method', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertEquals('GET', $data['method']);
        $this->assertStringContainsString('Hello', $data['message']);
    }
}
