<?php

declare(strict_types=1);

namespace App\Controller\Enroll;

use App\Controller\AbstractController;
use App\Job\Enroll\Attachment3ImportJob;
use App\Job\Enroll\Attachment3ReturnImportJob;
use App\Job\Enroll\EnrollExportJob;
use App\Job\Enroll\SupplementImportJob;
use App\Model\Enroll\EnrollImportBatch;
use App\Model\Enroll\EnrollLedger;
use App\Model\Enroll\EnrollReviewBatch;
use App\Model\Enroll\EnrollReviewItem;
use App\Model\Town;
use App\Model\User;
use App\Service\BusinessFilterOptionService;
use App\Service\Enroll\EnrollLedgerService;
use App\Service\OperationLogService;
use App\Service\TaskService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Logger\LoggerFactory;

class LedgerController extends AbstractController
{
    public function __construct(
        private readonly EnrollLedgerService $ledgerService,
        private readonly BusinessFilterOptionService $filterOptionService,
        private readonly OperationLogService $operationLogService,
    ) {
        parent::__construct();
    }

    public function index(RequestInterface $request)
    {
        $page = max((int) $request->input('page', 1), 1);
        $pageSize = max((int) $request->input('page_size', 20), 1);
        $query = EnrollLedger::query();
        $this->ledgerService->applyFilters($query, $request->all());
        $this->ledgerService->applyTownReviewVisibleScope($query, $this->currentTownName($request));

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

    public function statistics(RequestInterface $request)
    {
        $base = EnrollLedger::query();
        $this->ledgerService->applyFilters($base, $request->all());
        $this->ledgerService->applyTownReviewVisibleScope($base, $this->currentTownName($request));

        $total = (clone $base)->count();
        $newCount = (clone $base)->where('change_status', EnrollLedgerService::CHANGE_NEW)->count();
        $changedCount = (clone $base)->where('change_status', EnrollLedgerService::CHANGE_CHANGED)->count();
        $cancelledCount = (clone $base)->where('change_status', EnrollLedgerService::CHANGE_CANCELLED)->count();
        $insuredCount = (clone $base)->where('is_insured', '是')->count();
        $eligibleCount = (clone $base)->where('is_eligible_for_subsidy', '是')->count();
        $reviewActiveCount = (clone $base)->whereIn('review_status', [
            EnrollLedgerService::REVIEW_PENDING,
            EnrollLedgerService::REVIEW_FILLED,
        ])->count();
        $reviewPendingCount = (clone $base)->where('review_status', EnrollLedgerService::REVIEW_PENDING)->count();
        $reviewFilledCount = (clone $base)->where('review_status', EnrollLedgerService::REVIEW_FILLED)->count();
        $paymentPendingCount = (clone $base)->where('payment_amount_check_status', EnrollLedgerService::PAYMENT_CHECK_PENDING)->count();
        $reviewRecalledCount = (clone $base)->where('review_status', EnrollLedgerService::REVIEW_RECALLED)->count();

        return $this->success(compact(
            'total',
            'newCount',
            'changedCount',
            'cancelledCount',
            'insuredCount',
            'eligibleCount',
            'reviewActiveCount',
            'reviewPendingCount',
            'reviewFilledCount',
            'paymentPendingCount',
            'reviewRecalledCount'
        ), '获取成功');
    }

    public function options(RequestInterface $request)
    {
        $year = (int) $request->input('year', date('Y'));
        $townName = $this->currentTownName($request);
        $base = EnrollLedger::query()->where('year', $year);
        $this->ledgerService->applyTownReviewVisibleScope($base, $townName);

        $pluckDistinct = function (string $field) use ($base) {
            return (clone $base)
                ->whereNotNull($field)
                ->where($field, '!=', '')
                ->distinct()
                ->orderBy($field)
                ->pluck($field)
                ->toArray();
        };

        return $this->success([
            'town_names' => $townName !== '' ? [$townName] : $pluckDistinct('town_name'),
            'medical_identities' => $pluckDistinct('medical_identity'),
            'subsidy_identities' => $pluckDistinct('subsidy_identity'),
            'change_statuses' => [
                EnrollLedgerService::CHANGE_NEW,
                EnrollLedgerService::CHANGE_CHANGED,
                EnrollLedgerService::CHANGE_CANCELLED,
            ],
            'insurance_categories' => $this->withUnmatchedInsuranceCategory($base, $pluckDistinct('insurance_category')),
            'subsidy_methods' => $pluckDistinct('subsidy_method'),
            'review_statuses' => [
                EnrollLedgerService::REVIEW_NOT_DISPATCHED,
                EnrollLedgerService::REVIEW_PENDING,
                EnrollLedgerService::REVIEW_FILLED,
                EnrollLedgerService::REVIEW_RECALLED,
            ],
            'town_submit_statuses' => [
                EnrollLedgerService::TOWN_STATUS_NOT_FILLED,
                EnrollLedgerService::TOWN_STATUS_FILLED,
            ],
            'payment_amount_check_statuses' => [
                EnrollLedgerService::PAYMENT_CHECK_NOT_FILLED,
                EnrollLedgerService::PAYMENT_CHECK_MATCHED,
                EnrollLedgerService::PAYMENT_CHECK_PENDING,
                EnrollLedgerService::PAYMENT_CHECK_CONFIRMED,
            ],
            'uninsured_reasons' => $this->uninsuredReasonOptions(),
            'resident_payment_amounts' => $this->residentPaymentAmountOptions($year),
        ], '获取成功');
    }

    private function uninsuredReasonOptions(): array
    {
        $configured = array_column(
            $this->filterOptionService->listOptions(EnrollLedgerService::MODULE, 'uninsured_reason'),
            'value'
        );
        $reasons = $configured !== [] ? $configured : $this->ledgerService->defaultUninsuredReasons();

        return array_values(array_unique(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $reasons
        ), fn ($value) => $value !== '')));
    }

    private function residentPaymentAmountOptions(int $year): array
    {
        $amounts = [];
        foreach ($this->filterOptionService->listOptions(EnrollLedgerService::MODULE, 'resident_payment_amount') as $option) {
            $amount = $this->ledgerService->parseAmount($option['value'] ?? 0);
            if ((float) $amount >= 0) {
                $amounts[$amount] = true;
            }
        }

        $values = array_keys($amounts);
        usort($values, fn ($left, $right) => (float) $left <=> (float) $right);
        return $values;
    }

    private function withUnmatchedInsuranceCategory($base, array $categories): array
    {
        $hasUnmatched = (clone $base)
            ->where(function ($query) {
                $query->whereNull('insurance_category')
                    ->orWhere('insurance_category', '');
            })
            ->exists();

        if ($hasUnmatched && !in_array(EnrollLedgerService::INSURANCE_CATEGORY_UNMATCHED, $categories, true)) {
            array_unshift($categories, EnrollLedgerService::INSURANCE_CATEGORY_UNMATCHED);
        }

        return $categories;
    }

    public function show(int $id, RequestInterface $request)
    {
        $query = EnrollLedger::query()->where('id', $id);
        $this->ledgerService->applyTownReviewVisibleScope($query, $this->currentTownName($request));
        $record = $query->first();
        if (!$record) {
            return $this->error('记录不存在', 404);
        }

        return $this->success($record, '获取成功');
    }

    public function update(int $id, RequestInterface $request)
    {
        $townName = $this->currentTownName($request);
        $query = EnrollLedger::query()->where('id', $id);
        $this->ledgerService->applyTownScope($query, $townName);
        $record = $query->first();
        if (!$record) {
            return $this->error('记录不存在', 404);
        }

        if ($townName !== '' && !$this->canTownFill($record->toArray())) {
            return $this->error('该记录未下放或已收回，镇街账号不能填报', 403);
        }

        $input = $request->all();
        try {
            $townData = $this->extractTownFillData($input, $record->toArray());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        }
        $adminData = [];
        if ($townName === '') {
            $adminRemark = $input['manual_remark'] ?? $input['admin_remark'] ?? null;
            if ($adminRemark !== null) {
                $adminData['manual_remark'] = $this->nullableText((string) $adminRemark);
            }
        }

        if ($townData === [] && $adminData === []) {
            return $this->error('没有可更新的字段', 400);
        }

        $now = date('Y-m-d H:i:s');
        $userId = (int) $request->getAttribute('userId', 0);
        $submitStatus = null;
        if ($townData !== []) {
            $submitStatus = $this->resolveTownSubmitStatus($record->toArray(), $input);
            $townData['town_submit_status'] = $submitStatus;
            $townData['town_submitted_at'] = $now;
            $townData['town_last_filled_by'] = $userId;
            $townData['town_last_filled_at'] = $now;
            if ((int) ($record->current_review_batch_id ?? 0) > 0) {
                $townData['review_status'] = EnrollLedgerService::REVIEW_FILLED;
            }

            $calculated = $this->ledgerService->calculateTownReviewFields(array_merge($record->toArray(), $townData));
            foreach ([
                'insurance_category',
                'is_insured',
                'uninsured_reason',
                'is_eligible_for_subsidy',
                'is_subsidy_obtained',
                'subsidy_method',
                'payment_amount_check_status',
                'payment_amount_check_remark',
            ] as $field) {
                if (array_key_exists($field, $calculated)) {
                    $townData[$field] = $calculated[$field];
                }
            }
        }

        $data = array_merge($townData, $adminData);
        Db::beginTransaction();
        try {
            $record->update($data);
            if ($townData !== [] && (int) ($record->current_review_batch_id ?? 0) > 0 && $submitStatus !== null) {
                $this->updateReviewItemAfterFill((int) $record->current_review_batch_id, $id, $submitStatus, $now);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $this->operationLogService->record('参保台账明细', '编辑', 'enroll_ledgers', (string) $id, '编辑参保台账字段', [
            'fields' => array_keys($data),
        ]);

        return $this->success($record->refresh(), '保存成功');
    }

    public function confirmPaymentCheck(int $id, RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能标记缴费核查状态', 403);
        }

        $record = EnrollLedger::query()->where('id', $id)->first();
        if (!$record) {
            return $this->error('记录不存在', 404);
        }

        if ((string) ($record->payment_amount_check_status ?? '') !== EnrollLedgerService::PAYMENT_CHECK_PENDING) {
            return $this->error('只有待核查记录可以标记已核查', 400);
        }

        $remark = $this->nullableText((string) $request->input('remark', ''));
        $record->update([
            'payment_amount_check_status' => EnrollLedgerService::PAYMENT_CHECK_CONFIRMED,
            'payment_amount_check_remark' => $remark ?: ($record->payment_amount_check_remark ?? null),
        ]);

        $this->operationLogService->record('参保台账明细', '缴费核查', 'enroll_ledgers', (string) $id, '标记缴费金额已核查', [
            'name' => $record->name ?? '',
            'id_card' => $record->id_card ?? '',
            'remark' => $remark,
        ]);

        return $this->success($record->refresh(), '已标记为已核查');
    }

    private function canTownFill(array $record): bool
    {
        return (int) ($record['current_review_batch_id'] ?? 0) > 0
            && in_array((string) ($record['review_status'] ?? ''), [
                EnrollLedgerService::REVIEW_PENDING,
                EnrollLedgerService::REVIEW_FILLED,
            ], true);
    }

    private function extractTownFillData(array $input, array $record = []): array
    {
        $aliases = [
            'town_is_insured' => ['town_is_insured', 'is_insured'],
            'town_uninsured_reason' => ['town_uninsured_reason', 'uninsured_reason'],
            'town_resident_payment_amount' => ['town_resident_payment_amount', 'resident_payment_amount'],
            'town_death_time' => ['town_death_time', 'death_time', 'death_remark'],
            'town_remark' => ['town_remark'],
        ];

        $data = [];
        foreach ($aliases as $field => $keys) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $input)) {
                    $data[$field] = $input[$key];
                    break;
                }
            }
        }

        if (array_key_exists('town_is_insured', $data)) {
            $value = trim((string) $data['town_is_insured']);
            if (!in_array($value, ['是', '否', ''], true)) {
                throw new \InvalidArgumentException('是否参保只能填写是或否');
            }
            $data['town_is_insured'] = $value !== '' ? $value : null;
            if ($value === '否' && !array_key_exists('town_resident_payment_amount', $data)) {
                $data['town_resident_payment_amount'] = null;
            }
            if ($value === '是' && !array_key_exists('town_uninsured_reason', $data)) {
                $data['town_uninsured_reason'] = null;
            }
        }
        if (array_key_exists('town_uninsured_reason', $data)) {
            $data['town_uninsured_reason'] = $this->nullableText((string) $data['town_uninsured_reason']);
            $allowedReasons = $this->uninsuredReasonOptions();
            if ($data['town_uninsured_reason'] !== null && $allowedReasons !== [] && !in_array($data['town_uninsured_reason'], $allowedReasons, true)) {
                throw new \InvalidArgumentException('未参保原因不在配置项中');
            }
        }
        if (array_key_exists('town_resident_payment_amount', $data)) {
            $amount = trim((string) $data['town_resident_payment_amount']);
            $data['town_resident_payment_amount'] = $amount === '' ? null : $this->ledgerService->parseAmount($amount);
            $allowedAmounts = $this->residentPaymentAmountOptions((int) ($record['year'] ?? date('Y')));
            if ($data['town_resident_payment_amount'] !== null && $allowedAmounts !== [] && !in_array($data['town_resident_payment_amount'], $allowedAmounts, true)) {
                throw new \InvalidArgumentException('缴费金额不在配置项中');
            }
        }
        if (array_key_exists('town_death_time', $data)) {
            $data['town_death_time'] = $this->nullableText($this->ledgerService->normalizeDateText((string) $data['town_death_time']));
        }
        if (array_key_exists('town_remark', $data)) {
            $data['town_remark'] = $this->nullableText((string) $data['town_remark']);
        }

        return $data;
    }

    private function resolveTownSubmitStatus(array $record, array $input): string
    {
        return EnrollLedgerService::TOWN_STATUS_FILLED;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y'], true);
    }

    private function updateReviewItemAfterFill(int $batchId, int $ledgerId, string $status, string $now): void
    {
        $items = EnrollReviewItem::query()
            ->where('ledger_id', $ledgerId)
            ->whereIn('status', [EnrollReviewItem::STATUS_PENDING, EnrollReviewItem::STATUS_FILLED])
            ->get(['id']);
        if ($items->isEmpty()) {
            return;
        }

        EnrollReviewItem::query()
            ->whereIn('id', $items->pluck('id')->toArray())
            ->update([
                'status' => EnrollReviewItem::STATUS_FILLED,
                'submitted_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function nullableText(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function intList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,，\s]+/u', $value) ?: [];
        }
        if (!is_array($value)) {
            $value = [$value];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($item) => (int) $item,
            $value
        ), fn ($item) => $item > 0)));
    }

    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,，、\s]+/u', $value) ?: [];
        }
        if (!is_array($value)) {
            $value = [$value];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $value
        ), fn ($item) => $item !== '')));
    }

    private function hasMeaningfulFilters(array $filters): bool
    {
        foreach (['keyword', 'town_name', 'medical_identity', 'subsidy_identity', 'change_status', 'is_insured', 'insurance_category', 'review_status', 'payment_amount_check_status'] as $field) {
            if (trim((string) ($filters[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function makeReviewBatchNo(): string
    {
        return 'ER' . date('YmdHis') . substr(str_replace('.', '', uniqid('', true)), -6);
    }

    public function destroy(int $id, RequestInterface $request)
    {
        $query = EnrollLedger::query()->where('id', $id);
        $this->ledgerService->applyTownScope($query, $this->currentTownName($request));
        $record = $query->first();
        if (!$record) {
            return $this->error('记录不存在', 404);
        }

        $snapshot = $record->toArray();
        $record->delete();

        $this->operationLogService->record('参保台账明细', '删除', 'enroll_ledgers', (string) $id, '删除参保台账明细', $snapshot);

        return $this->success(null, '删除成功');
    }

    public function importAttachment3(RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能导入参保台账附件3', 403);
        }

        return $this->submitImport($request, Attachment3ImportJob::class, '参保台账_导入_附件3全量明细_', 'enrollAttachment3Import');
    }

    public function importAttachment4(RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能导入参保台账附件4', 403);
        }

        return $this->submitImport($request, SupplementImportJob::class, '参保台账_导入_附件4参保核实_', 'enrollAttachment4Import', 'attachment4_verify');
    }

    public function importAttachment5(RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能导入参保台账附件5', 403);
        }

        return $this->submitImport($request, SupplementImportJob::class, '参保台账_导入_附件5税务请款_', 'enrollAttachment5Import', 'attachment5_tax');
    }

    public function importAttachment6(RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能导入参保台账附件6', 403);
        }

        return $this->submitImport($request, SupplementImportJob::class, '参保台账_导入_附件6死亡名单_', 'enrollAttachment6Import', 'attachment6_death');
    }

    public function importAttachment3Return(RequestInterface $request)
    {
        return $this->submitImport($request, Attachment3ReturnImportJob::class, '参保台账_导入_附件3人工调整回导_', 'enrollAttachment3ReturnImport', 'attachment3_return');
    }

    public function export(RequestInterface $request)
    {
        $type = (string) $request->input('type', 'attachment1');
        $filters = $request->all();
        $userId = (int) $request->getAttribute('userId', 0);
        $username = (string) $request->getAttribute('username', 'System');

        $uuid = TaskService::instance()->dispatchTask(
            $this->exportTaskTitle($type),
            $userId,
            $username,
            EnrollExportJob::class,
            [[
                'type' => $type,
                'filters' => $filters,
                'town_name' => $this->currentTownName($request),
            ]]
        );

        $this->operationLogService->record('参保台账明细', '导出', 'enroll_export', $uuid ?: '', '提交参保台账导出任务', [
            'type' => $type,
            'filters' => $filters,
        ]);

        return $this->success(['uuid' => $uuid], '导出任务已提交，请在任务中心查看进度');
    }

    public function dispatch(RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能下放参保台账', 403);
        }

        $year = (int) $request->input('year', date('Y'));
        $filters = (array) $request->input('filters', []);
        $filters = array_merge($request->all(), $filters, ['year' => $year]);
        $ids = $this->intList($request->input('ids', $request->input('ledger_ids', [])));
        $townNames = $this->stringList($request->input('town_names', $request->input('town_name', [])));
        $allTowns = $this->truthy($request->input('all_towns', false));
        $hasMeaningfulFilters = $this->hasMeaningfulFilters($filters);

        if ($ids === [] && !$allTowns && $townNames === [] && !$hasMeaningfulFilters) {
            return $this->error('请选择下放镇街、下放记录或筛选条件', 400);
        }

        $query = EnrollLedger::query();
        $this->ledgerService->applyFilters($query, $filters);
        $query->where(function ($subQuery) {
            $subQuery->whereNull('current_review_batch_id')
                ->orWhere('current_review_batch_id', 0);
        });
        $query->whereNotExists(function ($subQuery) {
            $subQuery->select(Db::raw(1))
                ->from('enroll_review_items as review_items')
                ->whereRaw('review_items.ledger_id = enroll_ledgers.id')
                ->whereIn('review_items.status', [
                    EnrollReviewItem::STATUS_PENDING,
                    EnrollReviewItem::STATUS_FILLED,
                ]);
        });
        if ($ids !== []) {
            $query->whereIn('id', $ids);
            $mode = EnrollReviewBatch::MODE_MANUAL;
        } else {
            if (!$allTowns && $townNames !== []) {
                $query->whereIn('town_name', $townNames);
            }
            $mode = $hasMeaningfulFilters ? EnrollReviewBatch::MODE_FILTER : EnrollReviewBatch::MODE_TOWN;
        }

        $ledgers = $query->get(['id', 'town_name']);
        $total = $ledgers->count();
        if ($total <= 0) {
            return $this->error('没有匹配到可下放的参保台账记录，可能记录已在下放中', 400);
        }

        $now = date('Y-m-d H:i:s');
        $userId = (int) $request->getAttribute('userId', 0);
        $username = (string) $request->getAttribute('username', 'System');
        $batch = null;
        $actualTownNames = [];

        Db::beginTransaction();
        try {
            $batch = EnrollReviewBatch::create([
                'batch_no' => $this->makeReviewBatchNo(),
                'year' => $year,
                'period' => $this->ledgerService->normalizePeriod((string) ($request->input('period', $filters['period'] ?? ''))) ?: null,
                'town_names' => $townNames,
                'dispatch_mode' => $mode,
                'filter_snapshot' => $filters,
                'total_count' => $total,
                'status' => EnrollReviewBatch::STATUS_DISPATCHED,
                'created_by' => $userId,
                'created_by_name' => $username,
                'dispatched_at' => $now,
                'remark' => $this->nullableText((string) $request->input('remark', '')),
            ]);

            $ledgers->chunk(500)->each(function ($rows) use ($batch, $now, &$actualTownNames) {
                $itemRows = [];
                $ledgerIds = [];
                foreach ($rows as $row) {
                    $ledgerIds[] = (int) $row->id;
                    $townName = (string) ($row->town_name ?? '');
                    if ($townName !== '') {
                        $actualTownNames[$townName] = true;
                    }
                    $itemRows[] = [
                        'batch_id' => (int) $batch->id,
                        'ledger_id' => (int) $row->id,
                        'town_name' => $townName ?: null,
                        'status' => EnrollReviewItem::STATUS_PENDING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($itemRows !== []) {
                    Db::table('enroll_review_items')->insert($itemRows);
                    EnrollLedger::query()->whereIn('id', $ledgerIds)->update([
                        'current_review_batch_id' => (int) $batch->id,
                        'review_status' => EnrollLedgerService::REVIEW_PENDING,
                        'town_submit_status' => EnrollLedgerService::TOWN_STATUS_NOT_FILLED,
                        'town_submitted_at' => null,
                        'updated_at' => $now,
                    ]);
                }
            });

            $batch->update(['town_names' => array_values(array_keys($actualTownNames))]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $this->operationLogService->record('参保台账明细', '下放', 'enroll_review_batches', (string) $batch->id, '下放参保台账给镇街核实', [
            'batch_no' => $batch->batch_no,
            'total_count' => $total,
            'town_names' => array_values(array_keys($actualTownNames)),
        ]);

        return $this->success($batch->refresh(), '下放成功');
    }

    public function recall(RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能收回参保台账下放', 403);
        }

        $batchId = (int) $request->input('batch_id', 0);
        $ids = $this->intList($request->input('ids', $request->input('ledger_ids', [])));
        if ($batchId <= 0 && $ids === []) {
            return $this->error('请选择要收回的批次或记录', 400);
        }

        $now = date('Y-m-d H:i:s');
        $affected = 0;
        Db::beginTransaction();
        try {
            if ($batchId > 0) {
                $itemQuery = EnrollReviewItem::query()
                    ->where('batch_id', $batchId)
                    ->where('status', '!=', EnrollReviewItem::STATUS_RECALLED);
                $ledgerIds = $itemQuery->pluck('ledger_id')->toArray();
                $affected = count($ledgerIds);
                if ($ledgerIds !== []) {
                    EnrollReviewItem::query()->where('batch_id', $batchId)->update([
                        'status' => EnrollReviewItem::STATUS_RECALLED,
                        'recalled_at' => $now,
                        'updated_at' => $now,
                    ]);
                    EnrollLedger::query()
                        ->whereIn('id', $ledgerIds)
                        ->where('current_review_batch_id', $batchId)
                        ->update([
                            'current_review_batch_id' => 0,
                            'review_status' => EnrollLedgerService::REVIEW_RECALLED,
                            'updated_at' => $now,
                        ]);
                }
                EnrollReviewBatch::query()->where('id', $batchId)->update([
                    'status' => EnrollReviewBatch::STATUS_RECALLED,
                    'recalled_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $ledgers = EnrollLedger::query()
                    ->whereIn('id', $ids)
                    ->get(['id']);
                $ledgerIds = $ledgers->pluck('id')->map(fn ($id) => (int) $id)->toArray();
                $batchIds = [];
                if ($ledgerIds !== []) {
                    $activeItems = EnrollReviewItem::query()
                        ->whereIn('ledger_id', $ledgerIds)
                        ->whereIn('status', [EnrollReviewItem::STATUS_PENDING, EnrollReviewItem::STATUS_FILLED])
                        ->get(['id', 'batch_id']);
                    foreach ($activeItems as $item) {
                        $batchIds[(int) $item->batch_id] = true;
                    }
                    if (!$activeItems->isEmpty()) {
                        EnrollReviewItem::query()
                            ->whereIn('id', $activeItems->pluck('id')->toArray())
                            ->update([
                                'status' => EnrollReviewItem::STATUS_RECALLED,
                                'recalled_at' => $now,
                                'updated_at' => $now,
                            ]);
                    }
                }
                $affected = count($ledgerIds);
                if ($affected > 0) {
                    EnrollLedger::query()
                        ->whereIn('id', $ledgerIds)
                        ->update([
                            'current_review_batch_id' => 0,
                            'review_status' => EnrollLedgerService::REVIEW_RECALLED,
                            'updated_at' => $now,
                        ]);
                }
                foreach (array_keys($batchIds) as $relatedBatchId) {
                    $this->refreshReviewBatchStatus((int) $relatedBatchId, $now);
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        $this->operationLogService->record('参保台账明细', '收回下放', 'enroll_review_batches', (string) $batchId, '收回参保台账镇街核实权限', [
            'batch_id' => $batchId,
            'ids' => $ids,
            'affected' => $affected,
        ]);

        return $this->success(['affected_rows' => $affected], '收回成功');
    }

    private function refreshReviewBatchStatus(int $batchId, string $now): void
    {
        if ($batchId <= 0) {
            return;
        }

        $total = EnrollReviewItem::query()->where('batch_id', $batchId)->count();
        if ($total <= 0) {
            return;
        }
        $recalled = EnrollReviewItem::query()
            ->where('batch_id', $batchId)
            ->where('status', EnrollReviewItem::STATUS_RECALLED)
            ->count();

        $status = $recalled <= 0
            ? EnrollReviewBatch::STATUS_DISPATCHED
            : ($recalled >= $total ? EnrollReviewBatch::STATUS_RECALLED : EnrollReviewBatch::STATUS_PARTIAL_RECALLED);

        EnrollReviewBatch::query()->where('id', $batchId)->update([
            'status' => $status,
            'recalled_at' => $status === EnrollReviewBatch::STATUS_RECALLED ? $now : null,
            'updated_at' => $now,
        ]);
    }

    public function reviewBatches(RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能查看下放批次', 403);
        }

        $page = max((int) $request->input('page', 1), 1);
        $pageSize = max((int) $request->input('page_size', 20), 1);
        $query = EnrollReviewBatch::query();
        $year = (int) $request->input('year', 0);
        if ($year > 0) {
            $query->where('year', $year);
        }
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $keyword = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('batch_no', 'like', "%{$keyword}%")
                    ->orWhere('created_by_name', 'like', "%{$keyword}%")
                    ->orWhere('remark', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();
        $batchIds = $list->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        $countMap = [];
        if ($batchIds !== []) {
            $countRows = Db::table('enroll_review_items')
                ->selectRaw(
                    "batch_id,
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS filled_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS recalled_count",
                    [
                        EnrollReviewItem::STATUS_PENDING,
                        EnrollReviewItem::STATUS_FILLED,
                        EnrollReviewItem::STATUS_RECALLED,
                    ]
                )
                ->whereIn('batch_id', $batchIds)
                ->groupBy('batch_id')
                ->get();
            foreach ($countRows as $row) {
                $countMap[(int) $row->batch_id] = [
                    'total_count' => (int) $row->total_count,
                    'pending_count' => (int) $row->pending_count,
                    'filled_count' => (int) $row->filled_count,
                    'recalled_count' => (int) $row->recalled_count,
                    'active_count' => max(0, (int) $row->total_count - (int) $row->recalled_count),
                ];
            }
        }
        $list = $list->map(function ($batch) use ($countMap) {
            $data = $batch->toArray();
            $counts = $countMap[(int) $batch->id] ?? [
                'pending_count' => 0,
                'filled_count' => 0,
                'recalled_count' => 0,
                'active_count' => 0,
            ];
            return array_merge($data, $counts);
        })->toArray();

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ], '获取成功');
    }

    public function reviewBatchItems(int $batchId, RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能查看下放批次明细', 403);
        }

        $page = max((int) $request->input('page', 1), 1);
        $pageSize = max((int) $request->input('page_size', 20), 1);
        $query = EnrollReviewItem::query()->where('batch_id', $batchId);
        $status = trim((string) $request->input('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }
        $townName = trim((string) $request->input('town_name', ''));
        if ($townName !== '') {
            $query->where('town_name', $townName);
        }

        $total = $query->count();
        $items = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();
        $ledgerIds = $items->pluck('ledger_id')->toArray();
        $ledgerMap = [];
        if ($ledgerIds !== []) {
            foreach (EnrollLedger::query()->whereIn('id', $ledgerIds)->get() as $ledger) {
                $ledgerMap[(int) $ledger->id] = $ledger->toArray();
            }
        }
        $list = $items->map(function ($item) use ($ledgerMap) {
            $data = $item->toArray();
            $data['ledger'] = $ledgerMap[(int) $item->ledger_id] ?? null;
            return $data;
        })->toArray();

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ], '获取成功');
    }

    public function importBatches(RequestInterface $request)
    {
        $page = max((int) $request->input('page', 1), 1);
        $pageSize = max((int) $request->input('page_size', 20), 1);
        $query = EnrollImportBatch::query();
        $year = (int) $request->input('year', 0);
        if ($year > 0) {
            $query->where('year', $year);
        }
        $type = trim((string) $request->input('attachment_type', ''));
        if ($type !== '') {
            $query->where('attachment_type', $type);
        }

        $total = $query->count();
        $list = $query->orderByDesc('id')
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

    private function submitImport(RequestInterface $request, string $jobClass, string $title, string $lockName, string $attachmentType = 'attachment3_full_list')
    {
        $logger = ApplicationContext::getContainer()->get(LoggerFactory::class)->get('default');
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->error('无效的文件', 400);
        }
        if (strtolower((string) $file->getExtension()) !== 'csv') {
            return $this->error('当前阶段仅支持 CSV 文件', 400);
        }

        $period = $this->ledgerService->normalizePeriod((string) $request->input('period', $request->input('settlement_period', '')));
        if ($period === '') {
            return $this->error('月份不能为空', 400);
        }
        $year = (int) $request->input('year', $this->ledgerService->periodYear($period));

        $uploadDir = BASE_PATH . '/storage/uploads/enroll/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $finalPath = $uploadDir . $lockName . '_' . date('YmdHis') . '_' . uniqid() . '.csv';
        $file->moveTo($finalPath);
        $userId = (int) $request->getAttribute('userId', 0);
        $username = (string) $request->getAttribute('username', 'System');
        $lockKey = sprintf('task:lock:%d:%s:%s', $userId, $lockName, $period);

        $logger->info('Enroll import submit start.', [
            'title' => $title,
            'job' => $jobClass,
            'year' => $year,
            'period' => $period,
            'file' => $file->getClientFilename(),
            'user_id' => $userId,
        ]);

        $uuid = TaskService::instance()->dispatchTask(
            $title,
            $userId,
            $username,
            $jobClass,
            [[
                'year' => $year,
                'period' => $period,
                'attachment_type' => $attachmentType,
                'source_file' => $file->getClientFilename(),
                'created_by' => $userId,
            ], $finalPath],
            $lockKey
        );

        if ($uuid === false) {
            if (is_file($finalPath)) {
                @unlink($finalPath);
            }
            return $this->error('导入任务正在执行中，请在任务中心查看进度', 400);
        }

        $this->operationLogService->record('参保台账明细', '导入', 'enroll_ledgers', $uuid, $title . '任务提交', [
            'year' => $year,
            'period' => $period,
            'attachment_type' => $attachmentType,
            'file' => $file->getClientFilename(),
        ]);

        return $this->success(['uuid' => $uuid], '导入任务已提交，请在任务中心查看进度');
    }

    private function currentTownName(RequestInterface $request): string
    {
        $townName = trim((string) ($request->getAttribute('townName', '') ?: ''));
        if ($townName !== '') {
            return $townName;
        }

        $userId = (int) $request->getAttribute('userId', 0);
        if ($userId <= 0) {
            return '';
        }

        $user = User::find($userId);
        $townId = $user ? (int) ($user->town_id ?? 0) : 0;
        if ($townId <= 0) {
            return '';
        }

        return (string) (Town::query()->where('id', $townId)->value('name') ?? '');
    }

    private function exportTaskTitle(string $type): string
    {
        return match ($type) {
            'attachment2' => '参保台账_导出_对比结果_',
            'attachment3' => '参保台账_导出_特殊对象资助参保台账_',
            default => '参保台账_导出_汇总名单_',
        };
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

        $allowed = [
            'resident_payment_amount',
            'subsidy_amount',
            'tax_first_request_amount',
            'included_month',
            'cancel_month',
            'payment_time',
            'updated_at',
        ];

        if ($direction !== '' && in_array($sortField, $allowed, true)) {
            $query->orderBy($sortField, $direction);
            return;
        }

        $query->orderByDesc('updated_at')->orderByDesc('id');
    }
}
