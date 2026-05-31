<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\BusinessFilterOption;
use App\Service\OperationLogService;
use Hyperf\Context\ApplicationContext;
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
        $pageInput = $request->input('page');
        if ($pageInput !== null && $pageInput !== '') {
            return $this->pageList($request);
        }

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

    /**
     * @RequestMapping(path="", methods="post")
     */
    public function store(RequestInterface $request)
    {
        try {
            $data = $this->validatedData($request);
        } catch (\InvalidArgumentException $e) {
            return $this->response->json(['code' => 400, 'msg' => $e->getMessage()]);
        }
        $exists = BusinessFilterOption::query()
            ->where('module', $data['module'])
            ->where('type', $data['type'])
            ->where('value', $data['value'])
            ->exists();
        if ($exists) {
            return $this->response->json(['code' => 400, 'msg' => '同模块、同类型下该选项值已存在']);
        }

        $item = BusinessFilterOption::create($data);
        $this->operationLogService()->record('业务筛选项', '创建', 'business_filter_option', (string) $item->id, '创建业务筛选项', $data);

        return $this->response->json(['code' => 0, 'msg' => '创建成功', 'data' => $item]);
    }

    /**
     * @RequestMapping(path="/{id:\d+}", methods="put")
     */
    public function update(int $id, RequestInterface $request)
    {
        $item = BusinessFilterOption::query()->find($id);
        if (!$item) {
            return $this->response->json(['code' => 404, 'msg' => '业务筛选项不存在']);
        }

        try {
            $data = $this->validatedData($request);
        } catch (\InvalidArgumentException $e) {
            return $this->response->json(['code' => 400, 'msg' => $e->getMessage()]);
        }
        $exists = BusinessFilterOption::query()
            ->where('module', $data['module'])
            ->where('type', $data['type'])
            ->where('value', $data['value'])
            ->where('id', '<>', $id)
            ->exists();
        if ($exists) {
            return $this->response->json(['code' => 400, 'msg' => '同模块、同类型下该选项值已存在']);
        }

        $item->update($data);
        $this->operationLogService()->record('业务筛选项', '编辑', 'business_filter_option', (string) $id, '编辑业务筛选项', $data);

        return $this->response->json(['code' => 0, 'msg' => '更新成功', 'data' => $item->refresh()]);
    }

    /**
     * @RequestMapping(path="/{id:\d+}", methods="delete")
     */
    public function destroy(int $id)
    {
        $item = BusinessFilterOption::query()->find($id);
        if (!$item) {
            return $this->response->json(['code' => 404, 'msg' => '业务筛选项不存在']);
        }

        $snapshot = $item->toArray();
        $item->delete();
        $this->operationLogService()->record('业务筛选项', '删除', 'business_filter_option', (string) $id, '删除业务筛选项', $snapshot);

        return $this->response->json(['code' => 0, 'msg' => '删除成功']);
    }

    private function pageList(RequestInterface $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(10, (int) $request->input('page_size', $request->input('limit', 10))));
        $module = trim((string) $request->input('module', ''));
        $type = trim((string) $request->input('type', ''));
        $status = $request->input('status');
        $keyword = trim((string) $request->input('keyword', ''));

        $query = BusinessFilterOption::query();
        if ($module !== '') {
            $query->where('module', $module);
        }
        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('value', 'like', "%{$keyword}%")
                    ->orWhere('label', 'like', "%{$keyword}%")
                    ->orWhere('remark', 'like', "%{$keyword}%")
                    ->orWhere('source_batch', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list = $query->orderBy('module')
            ->orderBy('type')
            ->orderByDesc('sort')
            ->orderByDesc('id')
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

    private function validatedData(RequestInterface $request): array
    {
        $module = trim((string) $request->input('module', ''));
        $type = trim((string) $request->input('type', ''));
        $value = trim((string) $request->input('value', ''));
        $label = trim((string) $request->input('label', ''));

        if ($module === '' || $type === '' || $value === '') {
            throw new \InvalidArgumentException('业务模块、选项类型、选项值不能为空');
        }

        return [
            'module' => $module,
            'type' => $type,
            'value' => $value,
            'label' => $label !== '' ? $label : $value,
            'status' => (int) $request->input('status', 1),
            'sort' => (int) $request->input('sort', 0),
            'source_batch' => trim((string) $request->input('source_batch', '')) ?: null,
            'remark' => trim((string) $request->input('remark', '')) ?: null,
        ];
    }

    private function operationLogService(): OperationLogService
    {
        return ApplicationContext::getContainer()->get(OperationLogService::class);
    }
}
