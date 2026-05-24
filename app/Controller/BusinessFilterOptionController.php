<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\BusinessFilterOption;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @Controller(prefix="/api/business-filter-options")
 */
class BusinessFilterOptionController extends AbstractController
{
    /**
     * @RequestMapping(path="", methods="get")
     */
    public function index(RequestInterface $request)
    {
        $module = trim((string) $request->input('module', ''));
        $type = trim((string) $request->input('type', ''));

        if ($module === '') {
            return $this->error('业务模块不能为空', 400);
        }

        $query = BusinessFilterOption::query()
            ->where('module', $module)
            ->where('status', 1);

        if ($type !== '') {
            $query->where('type', $type);
        }

        $list = $query->orderBy('type')
            ->orderByDesc('sort')
            ->orderBy('label')
            ->get(['id', 'module', 'type', 'value', 'label'])
            ->groupBy('type');

        return $this->success($list, '获取成功');
    }
}
