<?php

declare(strict_types=1);

namespace App\Controller\Unrescued;

use App\Controller\AbstractController;
use App\Job\Unrescued\DiseaseConfigImportJob;
use App\Model\Unrescued\UnrescuedDiseaseConfig;
use App\Service\OperationLogService;
use App\Service\TaskService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @Controller(prefix="/api/unrescued/disease-configs")
 */
class DiseaseConfigController extends AbstractController
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
        $keyword = trim((string) $request->input('keyword', ''));
        $status = $request->input('status');

        $query = UnrescuedDiseaseConfig::query();
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('disease_code', 'like', "%{$keyword}%")
                    ->orWhere('disease_name', 'like', "%{$keyword}%");
            });
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
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

    /**
     * @RequestMapping(path="", methods="post")
     */
    public function store(RequestInterface $request)
    {
        $data = $this->validatedData($request);
        if ($data['disease_code'] === '' || $data['disease_name'] === '') {
            return $this->response->json(['code' => 400, 'msg' => '病种编码和病种名称不能为空']);
        }

        $item = UnrescuedDiseaseConfig::create($data);
        $this->operationLogService()->record('重大疾病编码', '创建', 'disease_config', (string) $item->id, '创建重大疾病编码', $data);

        return $this->response->json(['code' => 0, 'msg' => '创建成功', 'data' => $item]);
    }

    /**
     * @RequestMapping(path="/{id:\d+}", methods="put")
     */
    public function update(int $id, RequestInterface $request)
    {
        $item = UnrescuedDiseaseConfig::find($id);
        if (!$item) {
            return $this->response->json(['code' => 404, 'msg' => '编码不存在']);
        }

        $data = $this->validatedData($request);
        if ($data['disease_code'] === '' || $data['disease_name'] === '') {
            return $this->response->json(['code' => 400, 'msg' => '病种编码和病种名称不能为空']);
        }

        $item->update($data);
        $this->operationLogService()->record('重大疾病编码', '编辑', 'disease_config', (string) $id, '编辑重大疾病编码', $data);

        return $this->response->json(['code' => 0, 'msg' => '更新成功', 'data' => $item->refresh()]);
    }

    /**
     * @RequestMapping(path="/{id:\d+}", methods="delete")
     */
    public function destroy(int $id)
    {
        $item = UnrescuedDiseaseConfig::find($id);
        if (!$item) {
            return $this->response->json(['code' => 404, 'msg' => '编码不存在']);
        }

        $item->delete();
        $this->operationLogService()->record('重大疾病编码', '删除', 'disease_config', (string) $id, '删除重大疾病编码', $item->toArray());

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

        $extension = strtolower((string) $file->getExtension());
        if ($extension !== 'csv') {
            return $this->response->json(['code' => 400, 'msg' => '当前阶段仅支持 CSV 文件']);
        }

        try {
            $uploadDir = BASE_PATH . '/storage/uploads/unrescued/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $finalPath = $uploadDir . 'disease_config_' . date('YmdHis') . '_' . uniqid() . '.csv';
            $file->moveTo($finalPath);

            $userId = (int) $request->getAttribute('userId', 0);
            $username = (string) $request->getAttribute('username', 'System');
            $lockKey = sprintf('task:lock:%d:unrescuedDiseaseImport', $userId);

            $uuid = TaskService::instance()->dispatchTask(
                '未救助台账_重大疾病编码导入_',
                $userId,
                $username,
                DiseaseConfigImportJob::class,
                [
                    ['source_file' => $file->getClientFilename()],
                    $finalPath,
                ],
                $lockKey
            );

            if ($uuid === false) {
                return $this->response->json(['code' => 400, 'msg' => '导入任务正在执行中，请在任务中心查看进度']);
            }

            $this->operationLogService()->record('重大疾病编码', '导入', 'disease_config', $uuid, '提交重大疾病编码导入任务', [
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
            'disease_code' => trim((string) $request->input('disease_code', '')),
            'disease_name' => trim((string) $request->input('disease_name', '')),
            'status' => (int) $request->input('status', 1),
            'source_batch' => trim((string) $request->input('source_batch', '')) ?: null,
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
