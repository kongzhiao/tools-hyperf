<?php
declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

class HelpController extends AbstractController
{
    private const HELP_DIR = BASE_PATH . '/helps';
    private const HIDDEN_HELP_FILES = [
        '仪表板用户操作与核对参考文档.html',
    ];

    public function index(RequestInterface $request)
    {
        $documents = [];
        $files = glob(self::HELP_DIR . '/*.html') ?: [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $filename = basename($file);
            if (in_array($filename, self::HIDDEN_HELP_FILES, true)) {
                continue;
            }

            $documents[] = [
                'filename' => $filename,
                'title' => pathinfo($filename, PATHINFO_FILENAME),
                'url' => '/helps/' . rawurlencode($filename),
                'updated_at' => date('Y-m-d H:i:s', filemtime($file) ?: time()),
                'size' => filesize($file) ?: 0,
            ];
        }

        usort($documents, static fn (array $left, array $right): int => strcmp($left['title'], $right['title']));

        return $this->success($documents);
    }

    public function show(string $filename, ResponseInterface $response)
    {
        $filename = rawurldecode($filename);
        if ($filename !== basename($filename) || !str_ends_with(strtolower($filename), '.html')) {
            return $response->json(['code' => 404, 'message' => '文档不存在'], 404);
        }

        $basePath = realpath(self::HELP_DIR);
        $filePath = realpath(self::HELP_DIR . '/' . $filename);
        if (!$basePath || !$filePath || !str_starts_with($filePath, $basePath . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
            return $response->json(['code' => 404, 'message' => '文档不存在'], 404);
        }

        return $response
            ->raw((string) file_get_contents($filePath))
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
