<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Town;
use App\Model\User;
use App\Job\TownImportJob;
use App\Service\OperationLogService;
use App\Service\TaskService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @Controller(prefix="/api/towns")
 */
class TownController extends AbstractController
{
    /**
     * @Inject
     * @var OperationLogService
     */
    protected OperationLogService $operationLogService;

    /**
     * @RequestMapping(path="", methods="get")
     */
    public function index(RequestInterface $request)
    {
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', $request->input('limit', 10));
        $name = trim((string) $request->input('name', ''));
        $status = $request->input('status');

        $query = Town::query()->withCount('users');
        if ($name !== '') {
            $query->where('name', 'like', "%{$name}%");
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $total = $query->count();
        $list = $query->orderByDesc('status')
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

    /**
     * @RequestMapping(path="/options", methods="get")
     */
    public function options()
    {
        $list = Town::query()
            ->where('status', 1)
            ->orderBy('sort')
            ->orderByDesc('id')
            ->get(['id', 'name', 'code']);

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $list,
        ]);
    }

    /**
     * @RequestMapping(path="", methods="post")
     */
    public function store(RequestInterface $request)
    {
        $data = $this->validatedData($request);
        if ($data['name'] === '') {
            return $this->response->json(['code' => 400, 'msg' => '镇街名称不能为空']);
        }

        $town = Town::create($data);
        $this->operationLogService()->record('镇街管理', '创建', 'town', (string) $town->id, '创建镇街', $data);

        return $this->response->json(['code' => 0, 'msg' => '创建成功', 'data' => $town]);
    }

    /**
     * @RequestMapping(path="/{id:\d+}", methods="put")
     */
    public function update(int $id, RequestInterface $request)
    {
        $town = Town::find($id);
        if (!$town) {
            return $this->response->json(['code' => 404, 'msg' => '镇街不存在']);
        }

        $data = $this->validatedData($request);
        if ($data['name'] === '') {
            return $this->response->json(['code' => 400, 'msg' => '镇街名称不能为空']);
        }

        $town->update($data);
        $this->operationLogService()->record('镇街管理', '编辑', 'town', (string) $id, '编辑镇街', $data);

        return $this->response->json(['code' => 0, 'msg' => '更新成功', 'data' => $town->refresh()]);
    }

    /**
     * @RequestMapping(path="/{id:\d+}", methods="delete")
     */
    public function destroy(int $id)
    {
        $town = Town::find($id);
        if (!$town) {
            return $this->response->json(['code' => 404, 'msg' => '镇街不存在']);
        }

        if (User::query()->where('town_id', $id)->exists()) {
            return $this->response->json(['code' => 400, 'msg' => '该镇街已绑定用户，请先调整用户所属镇街']);
        }

        $town->delete();
        $this->operationLogService()->record('镇街管理', '删除', 'town', (string) $id, '删除镇街', $town->toArray());

        return $this->response->json(['code' => 0, 'msg' => '删除成功']);
    }

    /**
     * @RequestMapping(path="/import", methods="post")
     */
    public function import(RequestInterface $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->response->json(['code' => 400, 'msg' => '无效的文件']);
        }

        if (strtolower((string) $file->getExtension()) !== 'csv') {
            return $this->response->json(['code' => 400, 'msg' => '仅支持 CSV 文件']);
        }

        try {
            $uploadDir = BASE_PATH . '/storage/uploads/towns/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $finalPath = $uploadDir . 'town_import_' . date('YmdHis') . '_' . uniqid() . '.csv';
            $file->moveTo($finalPath);

            $userId = (int) $request->getAttribute('userId', 0);
            $username = (string) $request->getAttribute('username', 'System');
            $uuid = TaskService::instance()->dispatchTask(
                '镇街管理_导入_',
                $userId,
                $username,
                TownImportJob::class,
                [
                    ['source_file' => $file->getClientFilename()],
                    $finalPath,
                ],
                sprintf('task:lock:%d:townImport', $userId)
            );

            if ($uuid === false) {
                return $this->response->json(['code' => 400, 'msg' => '导入任务正在执行中，请在任务中心查看进度']);
            }

            $this->operationLogService()->record('镇街管理', '导入', 'town', $uuid, '提交镇街导入任务', [
                'file' => $file->getClientFilename(),
            ]);

            return $this->response->json(['code' => 0, 'msg' => '导入任务已提交，请在任务中心查看进度', 'data' => ['uuid' => $uuid]]);
        } catch (\Throwable $e) {
            return $this->response->json(['code' => 500, 'msg' => '导入提交失败：' . $e->getMessage()]);
        }
    }

    private function validatedData(RequestInterface $request): array
    {
        return [
            'name' => trim((string) $request->input('name', '')),
            'code' => trim((string) $request->input('code', '')) ?: null,
            'status' => (int) $request->input('status', 1),
            'sort' => (int) $request->input('sort', 0),
            'remark' => trim((string) $request->input('remark', '')) ?: null,
        ];
    }

    private function operationLogService(): OperationLogService
    {
        if (isset($this->operationLogService)) {
            return $this->operationLogService;
        }

        return ApplicationContext::getContainer()->get(OperationLogService::class);
    }
}
