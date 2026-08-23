<?php

declare(strict_types=1);

namespace App\Controller\Unrescued;

use App\Controller\AbstractController;
use App\Job\Unrescued\RefundDetailImportJob;
use App\Job\Unrescued\RefundExportJob;
use App\Job\Unrescued\RefundObjectImportJob;
use App\Job\Unrescued\RefundWashExecuteJob;
use App\Model\Task;
use App\Model\Unrescued\UnrescuedRefundRecord;
use App\Model\Unrescued\UnrescuedWashConfig;
use App\Service\OperationLogService;
use App\Service\TaskService;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @Controller(prefix="/api/unrescued/refund-records")
 */
class RefundRecordController extends AbstractController
{
    public function __construct(
        private readonly UnrescuedRecordService $recordService,
        private readonly OperationLogService $operationLogService,
    ) {
        parent::__construct();
    }

    /**
     * @RequestMapping(path="", methods="get")
     */
    public function index(RequestInterface $request)
    {
        $page = max((int) $request->input('page', 1), 1);
        $pageSize = max((int) $request->input('page_size', $request->input('limit', 20)), 1);
        $query = UnrescuedRefundRecord::query();
        $this->applyFilters($query, $request->all());
        $total = $query->count();
        $query->orderByDesc('settlement_period')->orderByRaw('CAST(`sequence_no` AS UNSIGNED) ASC')->orderBy('sequence_no');
        $list = $query->offset(($page - 1) * $pageSize)->limit($pageSize)->get();
        return $this->success(['list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $pageSize], '获取成功');
    }

    /**
     * @RequestMapping(path="/statistics", methods="get")
     */
    public function statistics(RequestInterface $request)
    {
        $query = UnrescuedRefundRecord::query();
        $this->applyFilters($query, $request->all());
        $total = (clone $query)->count();
        $excluded = (clone $query)->where('exclude_status', UnrescuedRecordService::EXCLUDE_YES)->count();
        $matched = (clone $query)->where('match_status', UnrescuedRecordService::MATCHED)->count();
        $unmatched = (clone $query)->where('match_status', UnrescuedRecordService::UNMATCHED)->count();
        $toNotice1 = (clone $query)->where('status', UnrescuedRecordService::STATUS_NOTICE_1)->count();
        $toNotice2 = (clone $query)->where('status', UnrescuedRecordService::STATUS_NOTICE_2)->count();
        $exportCount = (clone $query)->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES)->count();
        return $this->success(compact('total', 'excluded', 'matched', 'unmatched', 'toNotice1', 'toNotice2', 'exportCount'), '获取成功');
    }

    /**
     * @RequestMapping(path="/import-detail", methods="post")
     */
    public function importDetail(RequestInterface $request)
    {
        return $this->submitImport($request, RefundDetailImportJob::class, '应补应退明细_导入_附件4应补应退明细_', 'refundDetailImport');
    }

    /**
     * @RequestMapping(path="/import-object", methods="post")
     */
    public function importObject(RequestInterface $request)
    {
        return $this->submitImport($request, RefundObjectImportJob::class, '应补应退明细_导入_附件2救助对象名单_', 'refundObjectImport');
    }

    /**
     * @RequestMapping(path="/wash-config", methods="get")
     */
    public function washConfig()
    {
        return $this->success($this->activeWashConfig(), '获取成功');
    }

    /**
     * @RequestMapping(path="/wash-config", methods="post")
     */
    public function saveWashConfig(RequestInterface $request)
    {
        $rules = $request->input('rules', $request->input('data', []));
        if (!is_array($rules)) {
            return $this->error('筛查规则格式不正确', 400);
        }
        $rules = $this->recordService->normalizeWashRules($rules, $this->recordService->refundWashRules());
        UnrescuedWashConfig::query()->where('rule_name', '应补应退清洗规则')->update(['is_active' => 0]);
        $config = UnrescuedWashConfig::create([
            'version' => date('YmdHis'),
            'name' => '应补应退筛查规则',
            'rule_name' => '应补应退清洗规则',
            'data' => $rules,
            'is_active' => 1,
            'created_by' => (int) $request->getAttribute('userId', 0),
        ]);
        return $this->success($config, '保存成功');
    }

    /**
     * @RequestMapping(path="/wash/execute", methods="post")
     */
    public function executeWash(RequestInterface $request)
    {
        $period = $this->recordService->normalizePeriod((string) $request->input('settlement_period', ''));
        if ($period === '') {
            return $this->error('清算期不能为空', 400);
        }
        $config = $this->activeWashConfig();
        $rules = (array) ($config->data ?? []);
        if (!$this->recordService->hasEnabledWashRules($rules)) {
            return $this->error('请先配置并启用至少一条筛查规则', 400);
        }

        $userId = (int) $request->getAttribute('userId', 0);
        $uuid = TaskService::instance()->dispatchTask(
            sprintf('应补应退明细_筛查_%s_', $period),
            $userId,
            (string) $request->getAttribute('username', 'System'),
            RefundWashExecuteJob::class,
            [[
                'settlement_period' => $period,
                'config_id' => (int) $config->id,
                'created_by' => $userId,
            ]],
            sprintf('task:lock:%d:refundWash:%s', $userId, $period)
        );
        if ($uuid === false) {
            return $this->error('当前清算期筛查任务正在执行中，请勿重复提交', 400);
        }
        return $this->success(['uuid' => $uuid], '筛查任务已提交');
    }

    /**
     * @RequestMapping(path="/wash/status", methods="get")
     */
    public function washStatus(RequestInterface $request)
    {
        $period = $this->recordService->normalizePeriod((string) $request->input('settlement_period', ''));
        if ($period === '') {
            return $this->success(null, '获取成功');
        }

        $task = Task::query()
            ->where('uid', (int) $request->getAttribute('userId', 0))
            ->where(function ($query) use ($period) {
                $query->where('title', 'like', sprintf('应补应退明细\_筛查\_%s\_%%', $period))
                    ->orWhere('title', 'like', sprintf('应补应退明细\_清洗\_%s\_%%', $period));
            })
            ->whereIn('status', [Task::STATUS_PENDING, Task::STATUS_RUNNING])
            ->orderByDesc('id')
            ->first();

        return $this->success($task ? [
            'uuid' => $task->uuid,
            'title' => $task->title,
            'progress' => (float) $task->progress,
            'status' => Task::STATUS_MAP[$task->status] ?? 'processing',
            'created_at' => $task->created_at?->toDateTimeString(),
            'updated_at' => $task->updated_at?->toDateTimeString(),
        ] : null, '获取成功');
    }

    /**
     * @RequestMapping(path="/export", methods="post")
     */
    public function export(RequestInterface $request)
    {
        $filters = (array) $request->input('filters', []);
        $query = UnrescuedRefundRecord::query();
        $this->applyFilters($query, $filters);
        $count = $query->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES)->count();
        if ($count <= 0) {
            return $this->error('当前筛选条件下暂无未剔除数据，不能导出应补应退明细表', 400);
        }
        $uuid = TaskService::instance()->dispatchTask(
            '应补应退明细_导出_应补应退明细表_',
            (int) $request->getAttribute('userId', 0),
            (string) $request->getAttribute('username', 'System'),
            RefundExportJob::class,
            [['filters' => $filters]]
        );
        return $this->success(['uuid' => $uuid], '导出任务已提交，请在任务中心查看进度');
    }

    private function submitImport(RequestInterface $request, string $jobClass, string $title, string $lockName)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->error('无效的文件', 400);
        }
        if (strtolower((string) $file->getExtension()) !== 'csv') {
            return $this->error('当前阶段仅支持 CSV 文件', 400);
        }
        $period = $this->recordService->normalizePeriod((string) $request->input('settlement_period', ''));
        if ($period === '') {
            return $this->error('清算期不能为空', 400);
        }
        $dir = BASE_PATH . '/storage/uploads/unrescued/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . $lockName . '_' . date('YmdHis') . '_' . uniqid() . '.csv';
        $file->moveTo($path);
        $userId = (int) $request->getAttribute('userId', 0);
        $uuid = TaskService::instance()->dispatchTask(
            $title,
            $userId,
            (string) $request->getAttribute('username', 'System'),
            $jobClass,
            [['settlement_period' => $period, 'source_file' => $file->getClientFilename()], $path],
            sprintf('task:lock:%d:%s', $userId, $lockName)
        );
        if ($uuid === false) {
            return $this->error('导入任务正在执行中，请在任务中心查看进度', 400);
        }
        $this->operationLogService->record('应补应退明细', '导入', 'unrescued_refund_records', $uuid, $title . '任务提交', ['settlement_period' => $period]);
        return $this->success(['uuid' => $uuid], '导入任务已提交，请在任务中心查看进度');
    }

    private function activeWashConfig(): UnrescuedWashConfig
    {
        $config = UnrescuedWashConfig::query()
            ->where('rule_name', '应补应退清洗规则')
            ->where('is_active', 1)
            ->orderByDesc('id')
            ->first();
        if ($config) {
            $config->data = $this->recordService->normalizeWashRules(
                (array) ($config->data ?? []),
                $this->recordService->refundWashRules()
            );
            $config->name = '应补应退筛查规则';
            return $config;
        }
        return UnrescuedWashConfig::create([
            'version' => 'refund_default_' . date('YmdHis'),
            'name' => '应补应退筛查规则',
            'rule_name' => '应补应退清洗规则',
            'data' => $this->recordService->refundWashRules(),
            'is_active' => 1,
            'created_by' => 0,
        ]);
    }

    private function applyFilters($query, array $filters): void
    {
        $period = $this->recordService->normalizePeriod((string) ($filters['settlement_period'] ?? ''));
        if ($period !== '') {
            $query->where('settlement_period', $period);
        }
        foreach (['status', 'exclude_status', 'match_status', 'town_id', 'medical_category'] as $field) {
            $values = $this->recordService->filterValues($filters[$field] ?? null);
            if ($values === []) {
                continue;
            }
            if (count($values) === 1) {
                $query->where($field, $values[0]);
            } else {
                $query->whereIn($field, $values);
            }
        }
        foreach (['disease_code', 'disease_name', 'hospital_name'] as $field) {
            $values = $this->recordService->filterValues($filters[$field] ?? null);
            if ($values !== []) {
                $query->where(function ($sub) use ($field, $values) {
                    foreach ($values as $value) {
                        $sub->orWhere($field, 'like', "%{$value}%");
                    }
                });
            }
        }
        $this->recordService->applyDiseaseKeywordFilter($query, $filters['disease_keyword'] ?? null);
        $identities = $this->recordService->filterValues($filters['priority_identity'] ?? null);
        if ($identities !== []) {
            if (count($identities) === 1) {
                $query->where('priority_identity', $identities[0]);
            } else {
                $query->whereIn('priority_identity', $identities);
            }
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($sub) use ($keyword) {
                $sub->whereBlind('name', $keyword)
                    ->orWhere(function ($idQuery) use ($keyword) {
                        $idQuery->whereBlind('id_card', $keyword);
                    })
                    ->orWhere('sequence_no', 'like', "%{$keyword}%");
            });
        }
    }
}
