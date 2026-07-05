<?php

declare(strict_types=1);

namespace App\Controller\Unrescued;

use App\Controller\AbstractController;
use App\Job\Unrescued\Attachment1ImportJob;
use App\Job\Unrescued\Attachment2ImportJob;
use App\Job\Unrescued\UnrescuedExportJob;
use App\Job\Unrescued\WashExecuteJob;
use App\Model\Task;
use App\Model\Unrescued\UnrescuedRecord;
use App\Model\Unrescued\UnrescuedWashConfig;
use App\Service\BusinessFilterOptionService;
use App\Service\OperationLogService;
use App\Service\TaskService;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Logger\LoggerFactory;

/**
 * @Controller(prefix="/api/unrescued/records")
 */
class RecordController extends AbstractController
{
    public function __construct(
        private readonly UnrescuedRecordService $recordService,
        private readonly OperationLogService $operationLogService,
        private readonly BusinessFilterOptionService $filterOptionService,
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
        $query = UnrescuedRecord::query();
        $this->recordService->applyFilters($query, $request->all());
        $this->recordService->applyTownScope($query, $this->currentTownId($request));

        $total = $query->count();
        $this->applyListSorting($query, $request);

        $list = $query->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ], '获取成功');
    }

    /**
     * @RequestMapping(path="/statistics", methods="get")
     */
    public function statistics(RequestInterface $request)
    {
        $base = UnrescuedRecord::query();
        $this->recordService->applyFilters($base, $request->all());
        $this->recordService->applyTownScope($base, $this->currentTownId($request));

        $total = (clone $base)->count();
        $excluded = (clone $base)->where('exclude_status', UnrescuedRecordService::EXCLUDE_YES)->count();
        $toNotice1 = (clone $base)->where('status', UnrescuedRecordService::STATUS_NOTICE_1)->count();
        $toNotice2 = (clone $base)->where('status', UnrescuedRecordService::STATUS_NOTICE_2)->count();
        $matchedObject = (clone $base)->where(function ($query) {
            $query->whereNotNull('priority_identity')
                ->orWhereNotNull('street_town')
                ->orWhere('town_id', '>', 0);
        })->count();
        $matched = (clone $base)->where('match_status', UnrescuedRecordService::MATCHED)->count();
        $unmatched = (clone $base)->where('match_status', UnrescuedRecordService::UNMATCHED)->count();
        $exportCount = (clone $base)
            ->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES)
            ->count();

        return $this->success(compact(
            'total',
            'excluded',
            'toNotice1',
            'toNotice2',
            'matchedObject',
            'matched',
            'unmatched',
            'exportCount'
        ), '获取成功');
    }

    /**
     * @RequestMapping(path="/import-attachment1", methods="post")
     */
    public function importAttachment1(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能导入未救助明细', 403);
        }
        return $this->submitImport($request, Attachment1ImportJob::class, '未救助台账_导入_附件1未救助明细_', 'unrescuedAttachment1Import');
    }

    /**
     * @RequestMapping(path="/import-attachment2", methods="post")
     */
    public function importAttachment2(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能导入救助对象名单', 403);
        }
        return $this->submitImport($request, Attachment2ImportJob::class, '未救助台账_导入_附件2救助对象名单_', 'unrescuedAttachment2Import');
    }

    /**
     * @RequestMapping(path="/wash-config", methods="get")
     */
    public function washConfig()
    {
        $config = $this->activeWashConfig();
        return $this->success($config, '获取成功');
    }

    /**
     * @RequestMapping(path="/wash-options", methods="get")
     */
    public function washOptions(RequestInterface $request)
    {
        $medicalCategories = array_column($this->filterOptionService->listOptions('unrescued', 'medical_category'), 'value');
        $identities = array_column($this->filterOptionService->listOptions('unrescued', 'priority_identity'), 'value');
        $rules = (array) ($this->activeWashConfig()->data ?? []);
        foreach ($rules as $rule) {
            $code = (string) ($rule['code'] ?? '');
            $values = (array) ($rule['values'] ?? []);
            if ($code === 'medical_category_keep') {
                $medicalCategories = array_merge($medicalCategories, $values);
            }
            if ($code === 'identity_exclude') {
                $identities = array_merge($identities, $values);
            }
        }

        return $this->success([
            'medical_categories' => array_values(array_unique(array_filter($medicalCategories))),
            'identities' => array_values(array_unique(array_filter($identities))),
        ], '获取成功');
    }

    /**
     * @RequestMapping(path="/wash-config", methods="post")
     */
    public function saveWashConfig(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能配置清洗规则', 403);
        }

        $rules = $request->input('rules', $request->input('data', []));
        if (!is_array($rules)) {
            return $this->error('清洗规则格式不正确', 400);
        }

        $name = trim((string) $request->input('name', '未救助默认清洗规则'));
        $rules = $this->recordService->normalizeWashRules($rules);

        UnrescuedWashConfig::query()
            ->whereIn('rule_name', ['未救助默认清洗规则', '未救助清洗规则'])
            ->where('is_active', 1)
            ->update(['is_active' => 0]);
        $config = UnrescuedWashConfig::create([
            'version' => date('YmdHis'),
            'name' => $name,
            'rule_name' => '未救助默认清洗规则',
            'data' => $rules,
            'is_active' => 1,
            'created_by' => (int) $request->getAttribute('userId', 0),
        ]);

        $this->syncWashRuleFilterOptions($rules);

        $this->operationLogService->record('未救助明细', '保存清洗规则', 'wash_config', (string) $config->id, '保存未救助清洗规则', ['version' => $config->version]);

        return $this->success($config, '保存成功');
    }

    /**
     * @RequestMapping(path="/wash/execute", methods="post")
     */
    public function executeWash(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能执行清洗', 403);
        }

        $period = $this->recordService->normalizePeriod((string) $request->input('settlement_period', ''));
        if ($period === '') {
            return $this->error('清算期不能为空', 400);
        }

        $config = $this->activeWashConfig();
        $rules = (array) ($config->data ?? []);
        if (!$this->recordService->hasEnabledWashRules($rules)) {
            return $this->error('请先配置并启用至少一条清洗规则', 400);
        }

        $townId = (int) $request->input('town_id', 0);
        $userId = (int) $request->getAttribute('userId', 0);
        $username = (string) $request->getAttribute('username', 'System');
        $lockKey = sprintf('task:lock:%d:unrescuedWash:%s:%d', $userId, $period, $townId);
        $uuid = TaskService::instance()->dispatchTask(
            sprintf('未救助台账_清洗_清洗规则_%s_', $period),
            $userId,
            $username,
            WashExecuteJob::class,
            [[
                'settlement_period' => $period,
                'town_id' => $townId,
                'config_id' => (int) $config->id,
                'created_by' => $userId,
            ]],
            $lockKey
        );

        if ($uuid === false) {
            return $this->error('当前清算期清洗任务正在执行中，请勿重复提交', 400);
        }

        $this->operationLogService->record('未救助明细', '提交清洗', 'wash_task', $uuid, '提交未救助清洗任务', [
            'settlement_period' => $period,
            'town_id' => $townId,
        ]);

        return $this->success(['uuid' => $uuid], '清洗任务已提交，请等待执行完成');
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
            ->where('title', 'like', sprintf('未救助台账\_执行清洗\_%s\_%%', $period))
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
     * 旧流程入口已迁移到下放通知节点。
     */
    public function distribute(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能下放数据', 403);
        }

        $period = $this->recordService->normalizePeriod((string) $request->input('settlement_period', ''));
        $townId = (int) $request->input('town_id', 0);
        if ($period === '' || $townId <= 0) {
            return $this->error('清算期和镇街不能为空', 400);
        }

        $baseQuery = UnrescuedRecord::query()
            ->where('settlement_period', $period)
            ->where('town_id', $townId)
            ->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES);

        $skippedWorkflowRows = (clone $baseQuery)
            ->whereIn('status', UnrescuedRecordService::TOWN_VISIBLE_STATUSES)
            ->count();

        $affected = (clone $baseQuery)
            ->where('status', UnrescuedRecordService::STATUS_TO_NOTICE)
            ->update([
                'status' => UnrescuedRecordService::STATUS_DISTRIBUTED,
                'distributed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->operationLogService->record('未救助明细', '下放', 'unrescued_records', "{$period}:{$townId}", '下放镇街数据', compact('period', 'townId', 'affected', 'skippedWorkflowRows'));
        return $this->success([
            'affected_rows' => $affected,
            'skipped_workflow_rows' => $skippedWorkflowRows,
        ], '下放成功');
    }

    /**
     * 旧流程入口已迁移到下放通知节点。
     */
    public function receive(RequestInterface $request)
    {
        $townId = $this->currentTownId($request);
        if ($townId <= 0) {
            return $this->error('当前账号未绑定镇街', 400);
        }
        $period = $this->recordService->normalizePeriod((string) $request->input('settlement_period', ''));
        if ($period === '') {
            return $this->error('清算期不能为空', 400);
        }

        $affected = UnrescuedRecord::query()
            ->where('settlement_period', $period)
            ->where('town_id', $townId)
            ->where('status', UnrescuedRecordService::STATUS_DISTRIBUTED)
            ->update([
                'status' => UnrescuedRecordService::STATUS_RECEIVED,
                'received_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->operationLogService->record('未救助明细', '接收', 'unrescued_records', "{$period}:{$townId}", '镇街确认接收', compact('period', 'townId', 'affected'));
        return $this->success(['affected_rows' => $affected], '接收成功');
    }

    /**
     * 旧流程入口已迁移到下放通知节点。
     */
    public function notify(RequestInterface $request)
    {
        return $this->batchUpdateRecords($request, [
            'status' => UnrescuedRecordService::STATUS_NOTIFIED,
            'notified_at' => date('Y-m-d H:i:s'),
        ], '标记通知', '标记已通知', [
            UnrescuedRecordService::STATUS_RECEIVED,
            UnrescuedRecordService::STATUS_NOTIFIED,
        ]);
    }

    /**
     * 旧流程入口已迁移到下放通知节点。
     */
    public function unnotify(RequestInterface $request)
    {
        return $this->batchUpdateRecords($request, [
            'status' => UnrescuedRecordService::STATUS_RECEIVED,
            'notified_at' => null,
        ], '撤销通知', '已撤销通知', [
            UnrescuedRecordService::STATUS_NOTIFIED,
        ]);
    }

    /**
     * 旧流程入口已迁移到下放通知节点。
     */
    public function accounts(RequestInterface $request)
    {
        return $this->batchUpdateRecords($request, [
            'bank_name' => trim((string) $request->input('bank_name', '')) ?: null,
            'bank_account_name' => trim((string) $request->input('bank_account_name', '')) ?: null,
            'bank_account_no' => trim((string) $request->input('bank_account_no', '')) ?: null,
        ], '回填账户', '账户回填成功', [
            UnrescuedRecordService::STATUS_RECEIVED,
            UnrescuedRecordService::STATUS_NOTIFIED,
        ]);
    }

    /**
     * 旧流程入口已迁移到下放通知节点。
     */
    public function reimbursement(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能标记报销状态', 403);
        }

        $status = (string) $request->input('reimbursement_status', UnrescuedRecordService::REIMBURSEMENT_PAID);
        return $this->batchUpdateRecords($request, [
            'reimbursement_status' => $status,
            'reimbursed_at' => $status === UnrescuedRecordService::REIMBURSEMENT_PAID ? date('Y-m-d H:i:s') : null,
        ], '标记报销', '报销状态已更新');
    }

    /**
     * @RequestMapping(path="/export", methods="post")
     */
    public function export(RequestInterface $request)
    {
        $logger = ApplicationContext::getContainer()->get(LoggerFactory::class)->get('default');

        $type = (string) $request->input('type', 'unrescued');
        $filters = (array) $request->input('filters', []);
        $exportCheck = $this->checkExportReady($type, $filters, $request);
        if (!$exportCheck['ready']) {
            return $this->error($exportCheck['message'], 400);
        }

        $userId = (int) $request->getAttribute('userId', 0);
        $username = (string) $request->getAttribute('username', 'System');
        $logger->info('Unrescued export submit start.', [
            'type' => $type,
            'filters' => $filters,
            'user_id' => $userId,
            'username' => $username,
        ]);
        $uuid = TaskService::instance()->dispatchTask(
            $this->exportTaskTitle($type),
            $userId,
            $username,
            UnrescuedExportJob::class,
            [[
                'type' => 'unrescued',
                'filters' => $filters,
                'user_town_id' => $this->currentTownId($request),
            ]]
        );

        $logger->info('Unrescued export submit success.', [
            'uuid' => $uuid,
            'type' => $type,
            'user_id' => $userId,
        ]);
        $this->operationLogService->record('未救助明细', '导出', 'unrescued_export', $uuid ?: '', '提交未救助导出任务', ['type' => $type]);
        return $this->success(['uuid' => $uuid], '导出任务已提交，请在任务中心查看进度');
    }

    /**
     * @RequestMapping(path="/{id:\d+}", methods="get")
     */
    public function show(int $id, RequestInterface $request)
    {
        $query = UnrescuedRecord::query()->where('id', $id);
        $this->recordService->applyTownScope($query, $this->currentTownId($request));
        $record = $query->first();
        if (!$record) {
            return $this->error('记录不存在', 404);
        }

        return $this->success($record, '获取成功');
    }

    private function submitImport(RequestInterface $request, string $jobClass, string $title, string $lockName)
    {
        $logger = ApplicationContext::getContainer()->get(LoggerFactory::class)->get('default');
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

        $uploadDir = BASE_PATH . '/storage/uploads/unrescued/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $finalPath = $uploadDir . $lockName . '_' . date('YmdHis') . '_' . uniqid() . '.csv';
        $file->moveTo($finalPath);
        $userId = (int) $request->getAttribute('userId', 0);
        $username = (string) $request->getAttribute('username', 'System');
        $lockKey = sprintf('task:lock:%d:%s', $userId, $lockName);

        $logger->info('Unrescued import submit start.', [
            'title' => $title,
            'job' => $jobClass,
            'settlement_period' => $period,
            'file' => $file->getClientFilename(),
            'user_id' => $userId,
            'username' => $username,
        ]);
        $uuid = TaskService::instance()->dispatchTask(
            $title,
            $userId,
            $username,
            $jobClass,
            [[
                'settlement_period' => $period,
                'source_file' => $file->getClientFilename(),
            ], $finalPath],
            $lockKey
        );

        if ($uuid === false) {
            $logger->warning('Unrescued import submit rejected because task is running.', [
                'title' => $title,
                'settlement_period' => $period,
                'user_id' => $userId,
            ]);
            return $this->error('导入任务正在执行中，请在任务中心查看进度', 400);
        }

        $logger->info('Unrescued import submit success.', [
            'uuid' => $uuid,
            'title' => $title,
            'settlement_period' => $period,
            'user_id' => $userId,
        ]);
        $this->operationLogService->record('未救助明细', '导入', 'unrescued_records', $uuid, $title . '任务提交', [
            'settlement_period' => $period,
            'file' => $file->getClientFilename(),
        ]);

        return $this->success(['uuid' => $uuid], '导入任务已提交，请在任务中心查看进度');
    }

    private function batchUpdateRecords(RequestInterface $request, array $data, string $action, string $message, ?array $allowedStatuses = null)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return $this->error('请选择记录', 400);
        }

        $query = UnrescuedRecord::query()->whereIn('id', $ids);
        $townId = $this->currentTownId($request);
        $this->recordService->applyTownScope($query, $townId);
        if ($allowedStatuses !== null) {
            $query->whereIn('status', $allowedStatuses);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $affected = $query->update($data);

        $this->operationLogService->record('未救助明细', $action, 'unrescued_records', implode(',', $ids), $action, ['affected' => $affected]);
        return $this->success(['affected_rows' => $affected], $message);
    }

    private function activeWashConfig(): UnrescuedWashConfig
    {
        $config = UnrescuedWashConfig::query()
            ->whereIn('rule_name', ['未救助默认清洗规则', '未救助清洗规则'])
            ->where('is_active', 1)
            ->orderByDesc('id')
            ->first();
        if ($config) {
            $config->data = $this->recordService->normalizeWashRules((array) ($config->data ?? []));
            return $config;
        }

        return UnrescuedWashConfig::create([
            'version' => 'default_' . date('YmdHis'),
            'name' => '未救助默认清洗规则',
            'rule_name' => '未救助默认清洗规则',
            'data' => $this->recordService->defaultWashRules(),
            'is_active' => 1,
            'created_by' => 0,
        ]);
    }

    private function applyListSorting($query, RequestInterface $request): void
    {
        $sortField = (string) $request->input('sort_field', '');
        $sortOrder = (string) $request->input('sort_order', '');
        $direction = match ($sortOrder) {
            'asc', 'ascend' => 'asc',
            'desc', 'descend' => 'desc',
            default => '',
        };

        $amountFields = [
            'total_fee',
            'policy_fee',
            'pool_fund_pay',
            'large_amount_pay',
            'serious_illness_pay',
            'used_outpatient_rescue',
            'used_normal_rescue',
            'used_major_rescue',
            'used_large_fee_rescue',
            'calc_reimbursement_amount',
        ];
        $sortableFields = array_merge($amountFields, [
            'settlement_period',
            'sequence_no',
            'name',
            'medical_category',
            'disease_code',
            'disease_name',
            'cert_location',
            'hospital_name',
            'hospital_code',
            'in_out_city',
            'admission_date',
            'discharge_date',
            'settlement_time',
            'street_town',
            'priority_identity',
            'status',
            'exclude_status',
            'exclude_rule_code',
            'reimbursement_status',
            'created_at',
            'updated_at',
            'distributed_at',
            'received_at',
            'notified_at',
            'reimbursed_at',
        ]);

        if ($sortField !== '' && $direction !== '' && in_array($sortField, $sortableFields, true)) {
            if (in_array($sortField, $amountFields, true)) {
                $query->orderByRaw(sprintf('CAST(`%s` AS DECIMAL(18, 2)) %s', $sortField, strtoupper($direction)));
            } elseif ($sortField === 'sequence_no') {
                $query->orderByRaw(sprintf('CAST(`sequence_no` AS UNSIGNED) %s', strtoupper($direction)))
                    ->orderBy('sequence_no', $direction);
            } else {
                $query->orderBy($sortField, $direction);
            }
            $query->orderBy('id');
            return;
        }

        $query->orderByDesc('settlement_period')
            ->orderByRaw('CAST(`sequence_no` AS UNSIGNED) ASC')
            ->orderBy('sequence_no')
            ->orderBy('id');
    }

    private function syncWashRuleFilterOptions(array $rules): void
    {
        $typeByCode = [
            'medical_category_keep' => 'medical_category',
            'identity_exclude' => 'priority_identity',
        ];

        foreach ($rules as $rule) {
            $code = (string) ($rule['code'] ?? '');
            $type = $typeByCode[$code] ?? '';
            if ($type === '') {
                continue;
            }

            foreach ((array) ($rule['values'] ?? []) as $value) {
                $this->filterOptionService->saveOption('unrescued', $type, (string) $value, 'wash_config');
            }
        }
    }

    private function currentTownId(RequestInterface $request): int
    {
        $townId = (int) $request->getAttribute('townId', 0);
        if ($townId > 0) {
            return $townId;
        }

        $userId = (int) $request->getAttribute('userId', 0);
        if ($userId <= 0) {
            return 0;
        }

        $user = \App\Model\User::find($userId);
        return $user ? (int) ($user->town_id ?? 0) : 0;
    }

    private function exportTaskTitle(string $type): string
    {
        return match ($type) {
            default => '未救助台账_导出_未救助明细表_',
        };
    }

    private function checkExportReady(string $type, array $filters, RequestInterface $request): array
    {
        $query = UnrescuedRecord::query();
        $this->recordService->applyFilters($query, $filters);
        $this->recordService->applyTownScope($query, $this->currentTownId($request));
        $query->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES);
        $count = $query->count();

        return [
            'ready' => $count > 0,
            'message' => $count > 0 ? '' : '当前筛选条件下暂无未剔除数据，不能导出未救助明细表',
        ];
    }

    private function applySupplementExportFilters($query, RequestInterface $request, ?array $filters = null): void
    {
        $filters = $filters ?? $request->all();
        $period = $this->recordService->normalizePeriod((string) ($filters['settlement_period'] ?? ''));
        if ($period !== '') {
            $query->where('settlement_period', $period);
        }

        $townId = $this->currentTownId($request);
        if ($townId > 0) {
            $query->where('town_id', $townId);
        } elseif (!empty($filters['town_id'])) {
            $query->where('town_id', (int) $filters['town_id']);
        }
    }
}
