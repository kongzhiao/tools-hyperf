<?php

declare(strict_types=1);

namespace App\Controller\Unrescued;

use App\Controller\AbstractController;
use App\Job\Unrescued\Attachment1ImportJob;
use App\Job\Unrescued\Attachment2ImportJob;
use App\Job\Unrescued\UnrescuedExportJob;
use App\Model\Unrescued\UnrescuedRecord;
use App\Model\Unrescued\UnrescuedSupplementRecord;
use App\Model\Unrescued\UnrescuedWashConfig;
use App\Model\Unrescued\UnrescuedWashLog;
use App\Service\BusinessFilterOptionService;
use App\Service\OperationLogService;
use App\Service\TaskService;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;

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
        $list = $query->orderByDesc('settlement_period')
            ->orderBy('sequence_no')
            ->offset(($page - 1) * $pageSize)
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
        $toNotice = (clone $base)->where('status', UnrescuedRecordService::STATUS_TO_NOTICE)->count();
        $distributed = (clone $base)->whereIn('status', [
            UnrescuedRecordService::STATUS_DISTRIBUTED,
            UnrescuedRecordService::STATUS_RECEIVED,
            UnrescuedRecordService::STATUS_NOTIFIED,
        ])->count();
        $paid = (clone $base)->where('reimbursement_status', UnrescuedRecordService::REIMBURSEMENT_PAID)->count();
        $pendingReceive = (clone $base)->where('status', UnrescuedRecordService::STATUS_DISTRIBUTED)->count();
        $matchedObject = (clone $base)->where(function ($query) {
            $query->whereNotNull('priority_identity')
                ->orWhereNotNull('street_town')
                ->orWhere('town_id', '>', 0);
        })->count();
        $exportAttachment1Count = (clone $base)->count();
        $exportAttachment2Count = (clone $base)
            ->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES)
            ->where(function ($query) {
                $query->whereNotNull('priority_identity')
                    ->orWhereNotNull('street_town')
                    ->orWhere('town_id', '>', 0);
            })
            ->count();
        $exportAttachment3Count = $exportAttachment2Count;

        $supplementQuery = UnrescuedSupplementRecord::query();
        $this->applySupplementExportFilters($supplementQuery, $request);
        $exportAttachment4Count = $supplementQuery->count();

        return $this->success(compact(
            'total',
            'excluded',
            'toNotice',
            'distributed',
            'paid',
            'pendingReceive',
            'matchedObject',
            'exportAttachment1Count',
            'exportAttachment2Count',
            'exportAttachment3Count',
            'exportAttachment4Count'
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
        return $this->submitImport($request, Attachment1ImportJob::class, '未救助台账_附件1导入_', 'unrescuedAttachment1Import');
    }

    /**
     * @RequestMapping(path="/import-attachment2", methods="post")
     */
    public function importAttachment2(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能导入救助对象名单', 403);
        }
        return $this->submitImport($request, Attachment2ImportJob::class, '未救助台账_附件2导入_', 'unrescuedAttachment2Import');
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
        $query = UnrescuedRecord::query();
        $this->recordService->applyTownScope($query, $this->currentTownId($request));
        $period = $this->recordService->normalizePeriod((string) $request->input('settlement_period', ''));
        if ($period !== '') {
            $query->where('settlement_period', $period);
        }

        $medicalCategories = (clone $query)
            ->whereNotNull('medical_category')
            ->distinct()
            ->orderBy('medical_category')
            ->pluck('medical_category')
            ->filter()
            ->values();

        $configuredMedicalCategories = array_column($this->filterOptionService->listOptions('unrescued', 'medical_category'), 'value');
        $medicalCategories = array_values(array_unique(array_filter(array_merge($configuredMedicalCategories, $medicalCategories->toArray()))));

        $identities = (clone $query)
            ->whereNotNull('priority_identity')
            ->distinct()
            ->orderBy('priority_identity')
            ->pluck('priority_identity')
            ->filter()
            ->values();

        $configuredIdentities = array_column($this->filterOptionService->listOptions('unrescued', 'priority_identity'), 'value');
        $identities = array_values(array_unique(array_filter(array_merge($configuredIdentities, $identities->toArray()))));

        return $this->success([
            'medical_categories' => $medicalCategories,
            'identities' => $identities,
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

        UnrescuedWashConfig::query()->where('is_active', 1)->update(['is_active' => 0]);
        $config = UnrescuedWashConfig::create([
            'version' => date('YmdHis'),
            'name' => trim((string) $request->input('name', '未救助默认清洗规则')),
            'data' => $rules,
            'is_active' => 1,
            'created_by' => (int) $request->getAttribute('userId', 0),
        ]);

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
        if (!$this->hasEnabledWashRules($rules)) {
            return $this->error('请先配置并启用至少一条清洗规则', 400);
        }
        $query = UnrescuedRecord::query()->where('settlement_period', $period);
        if ($this->currentTownId($request) > 0) {
            $query->where('town_id', $this->currentTownId($request));
        } elseif ($request->input('town_id')) {
            $query->where('town_id', (int) $request->input('town_id'));
        }

        $records = $query->get();
        $summary = [];
        $excluded = 0;

        Db::beginTransaction();
        try {
            foreach ($records as $record) {
                $matched = $this->recordService->matchWashRule($record, $rules);
                if ($matched) {
                    $record->update([
                        'exclude_status' => UnrescuedRecordService::EXCLUDE_YES,
                        'exclude_rule_code' => (string) ($matched['code'] ?? ''),
                        'remark' => (string) ($matched['remark'] ?? ''),
                    ]);
                    $code = (string) ($matched['code'] ?? 'unknown');
                    $summary[$code] = ($summary[$code] ?? 0) + 1;
                    $excluded++;
                } else {
                    $record->update([
                        'exclude_status' => UnrescuedRecordService::EXCLUDE_NO,
                        'exclude_rule_code' => null,
                        'remark' => null,
                    ]);
                }
            }

            $log = UnrescuedWashLog::create([
                'settlement_period' => $period,
                'config_id' => (int) $config->id,
                'batch_no' => date('YmdHis') . mt_rand(1000, 9999),
                'total_count' => $records->count(),
                'excluded_count' => $excluded,
                'kept_count' => $records->count() - $excluded,
                'summary' => $summary,
                'created_by' => (int) $request->getAttribute('userId', 0),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            return $this->error('执行清洗失败：' . $e->getMessage(), 500);
        }

        $this->operationLogService->record('未救助明细', '执行清洗', 'wash_log', (string) $log->id, '执行未救助清洗', [
            'settlement_period' => $period,
            'excluded_count' => $excluded,
            'kept_count' => $records->count() - $excluded,
        ]);

        return $this->success([
            'total_count' => $records->count(),
            'excluded_count' => $excluded,
            'kept_count' => $records->count() - $excluded,
            'summary' => $summary,
        ], '清洗完成');
    }

    /**
     * @RequestMapping(path="/distribute", methods="post")
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

        $affected = UnrescuedRecord::query()
            ->where('settlement_period', $period)
            ->where('town_id', $townId)
            ->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES)
            ->update([
                'status' => UnrescuedRecordService::STATUS_DISTRIBUTED,
                'distributed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->operationLogService->record('未救助明细', '下放', 'unrescued_records', "{$period}:{$townId}", '下放镇街数据', compact('period', 'townId', 'affected'));
        return $this->success(['affected_rows' => $affected], '下放成功');
    }

    /**
     * @RequestMapping(path="/receive", methods="post")
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
     * @RequestMapping(path="/notify", methods="post")
     */
    public function notify(RequestInterface $request)
    {
        return $this->batchUpdateRecords($request, [
            'status' => UnrescuedRecordService::STATUS_NOTIFIED,
            'notified_at' => date('Y-m-d H:i:s'),
        ], '标记通知', '标记已通知');
    }

    /**
     * @RequestMapping(path="/accounts", methods="post")
     */
    public function accounts(RequestInterface $request)
    {
        return $this->batchUpdateRecords($request, [
            'bank_name' => trim((string) $request->input('bank_name', '')) ?: null,
            'bank_account_name' => trim((string) $request->input('bank_account_name', '')) ?: null,
            'bank_account_no' => trim((string) $request->input('bank_account_no', '')) ?: null,
        ], '回填账户', '账户回填成功');
    }

    /**
     * @RequestMapping(path="/reimbursement", methods="post")
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
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能导出数据', 403);
        }

        $type = (string) $request->input('type', 'attachment1');
        if (!in_array($type, ['attachment1', 'attachment2', 'attachment3', 'attachment4'], true)) {
            return $this->error('导出类型不正确', 400);
        }

        $filters = (array) $request->input('filters', []);
        $exportCheck = $this->checkExportReady($type, $filters, $request);
        if (!$exportCheck['ready']) {
            return $this->error($exportCheck['message'], 400);
        }

        $userId = (int) $request->getAttribute('userId', 0);
        $username = (string) $request->getAttribute('username', 'System');
        $uuid = TaskService::instance()->dispatchTask(
            '未救助台账_导出_',
            $userId,
            $username,
            UnrescuedExportJob::class,
            [[
                'type' => $type,
                'filters' => $filters,
                'user_town_id' => $this->currentTownId($request),
            ]]
        );

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
            return $this->error('导入任务正在执行中，请在任务中心查看进度', 400);
        }

        $this->operationLogService->record('未救助明细', '导入', 'unrescued_records', $uuid, $title . '任务提交', [
            'settlement_period' => $period,
            'file' => $file->getClientFilename(),
        ]);

        return $this->success(['uuid' => $uuid], '导入任务已提交，请在任务中心查看进度');
    }

    private function batchUpdateRecords(RequestInterface $request, array $data, string $action, string $message)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return $this->error('请选择记录', 400);
        }

        $query = UnrescuedRecord::query()->whereIn('id', $ids);
        $this->recordService->applyTownScope($query, $this->currentTownId($request));
        $data['updated_at'] = date('Y-m-d H:i:s');
        $affected = $query->update($data);

        $this->operationLogService->record('未救助明细', $action, 'unrescued_records', implode(',', $ids), $action, ['affected' => $affected]);
        return $this->success(['affected_rows' => $affected], $message);
    }

    private function activeWashConfig(): UnrescuedWashConfig
    {
        $config = UnrescuedWashConfig::query()
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
            'data' => $this->recordService->defaultWashRules(),
            'is_active' => 1,
            'created_by' => 0,
        ]);
    }

    private function hasEnabledWashRules(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (($rule['enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
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

    private function checkExportReady(string $type, array $filters, RequestInterface $request): array
    {
        if ($type === 'attachment4') {
            $query = UnrescuedSupplementRecord::query();
            $this->applySupplementExportFilters($query, $request, $filters);
            $count = $query->count();
            return [
                'ready' => $count > 0,
                'message' => $count > 0 ? '' : '暂无应退应补排查记录，不能导出应退应补排查记录',
            ];
        }

        $query = UnrescuedRecord::query();
        $this->recordService->applyFilters($query, $filters);
        $this->recordService->applyTownScope($query, $this->currentTownId($request));

        if ($type === 'attachment1') {
            $count = $query->count();
            return [
                'ready' => $count > 0,
                'message' => $count > 0 ? '' : '请先导入未救助明细，再导出排查明细',
            ];
        }

        $query->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES)
            ->where(function ($subQuery) {
                $subQuery->whereNotNull('priority_identity')
                    ->orWhereNotNull('street_town')
                    ->orWhere('town_id', '>', 0);
            });
        $count = $query->count();

        return [
            'ready' => $count > 0,
            'message' => $count > 0 ? '' : '请先导入救助对象名单并确保存在未剔除数据，再执行该导出',
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
