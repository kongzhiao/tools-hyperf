<?php
declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

/**
 * @Controller
 */
class DownloadController extends AbstractController
{
    /**
     * @RequestMapping(path="/api/download", methods="get")
     */
    public function download(RequestInterface $request, ResponseInterface $response)
    {
        $uuid = $request->input('uuid');
        
        if (!$uuid) {
            return $this->response->json(['code' => 400, 'msg' => '缺少任务编号']);
        }

        $task = \App\Model\Task::where('uuid', $uuid)->first();
        if (!$task) {
            return $this->response->json(['code' => 404, 'msg' => '任务不存在']);
        }

        if ($task->status !== \App\Model\Task::STATUS_COMPLETED || !$task->file_url) {
            return $this->response->json(['code' => 400, 'msg' => '文件尚未就绪或生成失败']);
        }

        // 校验有效期（7天）
        $urlAt = $task->url_at ?: $task->updated_at;
        if (strtotime((string)$urlAt) + 7 * 86400 < time()) {
            return $this->response->json(['code' => 403, 'msg' => '下载链接已过期（有效期7天）']);
        }

        $fileUrl = $task->file_url;
        $fullPath = '';

        // 智能解析路径
        if (strpos($fileUrl, BASE_PATH) === 0) {
            $fullPath = $fileUrl;
        } else {
            // 处理相对路径
            $relPath = ltrim($fileUrl, '/');
            // 尝试直接拼接
            $fullPath = BASE_PATH . '/' . $relPath;
            
            if (!file_exists($fullPath)) {
                // 尝试拼接在 public 下
                if (strpos($relPath, 'public/') !== 0) {
                    $fullPath = BASE_PATH . '/public/' . $relPath;
                }
            }
        }

        if (!file_exists($fullPath)) {
            return $this->response->json(['code' => 404, 'msg' => '物理文件不存在，请重新导出']);
        }

        $filename = basename($fullPath);
        return $this->response->download($fullPath, $filename);
    }
}
