<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\OperationLog;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @Controller(prefix="/api/operation-logs")
 */
class OperationLogController extends AbstractController
{
    /**
     * @RequestMapping(path="", methods="get")
     */
    public function index(RequestInterface $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(10, (int) $request->input('page_size', $request->input('limit', 10))));
        $module = trim((string) $request->input('module', ''));
        $action = trim((string) $request->input('action', ''));
        $username = trim((string) $request->input('username', ''));
        $status = trim((string) $request->input('status', ''));
        $keyword = trim((string) $request->input('keyword', ''));
        $startAt = trim((string) $request->input('start_at', ''));
        $endAt = trim((string) $request->input('end_at', ''));

        $query = OperationLog::query();
        if ($module !== '') {
            $query->where('module', $module);
        }
        if ($action !== '') {
            $query->where('action', $action);
        }
        if ($username !== '') {
            $query->where('username', 'like', "%{$username}%");
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($startAt !== '') {
            $query->where('created_at', '>=', $startAt);
        }
        if ($endAt !== '') {
            $query->where('created_at', '<=', $endAt);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('description', 'like', "%{$keyword}%")
                    ->orWhere('target_type', 'like', "%{$keyword}%")
                    ->orWhere('target_id', 'like', "%{$keyword}%")
                    ->orWhere('ip', 'like', "%{$keyword}%")
                    ->orWhere('error_message', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
            ],
        ]);
    }
}
