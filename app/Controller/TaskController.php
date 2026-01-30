<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\BusinessException;
use App\Model\Task;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

/**
 * 任务控制器
 */
class TaskController extends AbstractController
{
    /**
     * 获取用户任务列表（支持分页）
     */
    public function index(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $userId = (int) $request->getAttribute('userId');

            // 分页参数
            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('page_size', 10);

            // 状态参数（非必填，默认全部）
            $status = $request->input('status');

            $query = Task::where('uid', $userId);

            // 如果传了状态参数，则按状态筛选
            if (!is_null($status) && $status !== '') {
                $status = (int) $status;
                if (!array_key_exists($status, Task::STATUS_MAP)) {
                    throw new BusinessException(400, '无效的状态参数');
                }
                $query->where('status', $status);
            }

            // 计算总数
            $total = $query->count();

            // 按创建时间倒序并分页
            $tasks = $query->orderBy('created_at', 'desc')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            $list = $tasks->map(function ($task) {
                return [
                    'uuid' => $task->uuid,
                    'title' => $task->title,
                    'progress' => (float) $task->progress,
                    'status' => Task::STATUS_MAP[$task->status] ?? 'processing',
                    'file_url' => $task->file_url,
                    'url_at' => $task->url_at,
                    'file_size' => $task->file_size,
                    'created_at' => $task->created_at?->toDateTimeString(),
                    'updated_at' => $task->updated_at?->toDateTimeString(),
                ];
            });

            return $response->json([
                'code' => 200,
                'message' => '获取成功',
                'data' => [
                    'list' => $list,
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                ]
            ]);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '获取任务列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取未完成任务数量
     */
    public function count(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $userId = (int) $request->getAttribute('userId');

            $count = Task::where('uid', $userId)
                ->whereIn('status', [Task::STATUS_PENDING, Task::STATUS_RUNNING])
                ->count();

            return $response->json([
                'code' => 200,
                'message' => '获取成功',
                'data' => ['count' => $count]
            ]);
        } catch (\Exception $e) {
            throw new BusinessException(500, '获取任务数量失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取单个任务进度
     */
    public function show(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $userId = (int) $request->getAttribute('userId');

            $uuid = $request->input('uuid');
            if (empty($uuid)) {
                throw new BusinessException(400, '任务ID不能为空');
            }

            // 根据 uuid 查询任务
            $task = Task::where('uuid', $uuid)->first();
            if (!$task) {
                throw new BusinessException(404, '任务不存在');
            }

            // 验证是否为本人任务
            if ($task->uid !== $userId) {
                throw new BusinessException(403, '无权访问此任务');
            }

            return $response->json([
                'code' => 200,
                'message' => '获取成功',
                'data' => [
                    'uuid' => $task->uuid,
                    'title' => $task->title,
                    'progress' => (float) $task->progress,
                    'status' => Task::STATUS_MAP[$task->status] ?? 'processing',
                    'file_url' => $task->file_url,
                    'url_at' => $task->url_at,
                    'file_size' => $task->file_size,
                    'created_at' => $task->created_at?->toDateTimeString(),
                    'updated_at' => $task->updated_at?->toDateTimeString(),
                ]
            ]);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '获取任务进度失败: ' . $e->getMessage());
        }
    }
}
