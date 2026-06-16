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
use App\Model\Town;
use App\Model\User;
use App\Service\Enroll\EnrollLedgerService;
use App\Service\OperationLogService;
use App\Service\TaskService;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Logger\LoggerFactory;

class LedgerController extends AbstractController
{
    public function __construct(
        private readonly EnrollLedgerService $ledgerService,
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
        $this->ledgerService->applyTownScope($query, $this->currentTownName($request));

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
        $this->ledgerService->applyTownScope($base, $this->currentTownName($request));

        $total = (clone $base)->count();
        $newCount = (clone $base)->where('change_status', EnrollLedgerService::CHANGE_NEW)->count();
        $changedCount = (clone $base)->where('change_status', EnrollLedgerService::CHANGE_CHANGED)->count();
        $cancelledCount = (clone $base)->where('change_status', EnrollLedgerService::CHANGE_CANCELLED)->count();
        $insuredCount = (clone $base)->where('is_insured', '是')->count();
        $eligibleCount = (clone $base)->where('is_eligible_for_subsidy', '是')->count();

        return $this->success(compact(
            'total',
            'newCount',
            'changedCount',
            'cancelledCount',
            'insuredCount',
            'eligibleCount'
        ), '获取成功');
    }

    public function options(RequestInterface $request)
    {
        $year = (int) $request->input('year', date('Y'));
        $townName = $this->currentTownName($request);
        $base = EnrollLedger::query()->where('year', $year);
        $this->ledgerService->applyTownScope($base, $townName);

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
            'insurance_categories' => $pluckDistinct('insurance_category'),
        ], '获取成功');
    }

    public function show(int $id, RequestInterface $request)
    {
        $query = EnrollLedger::query()->where('id', $id);
        $this->ledgerService->applyTownScope($query, $this->currentTownName($request));
        $record = $query->first();
        if (!$record) {
            return $this->error('记录不存在', 404);
        }

        return $this->success($record, '获取成功');
    }

    public function update(int $id, RequestInterface $request)
    {
        $query = EnrollLedger::query()->where('id', $id);
        $this->ledgerService->applyTownScope($query, $this->currentTownName($request));
        $record = $query->first();
        if (!$record) {
            return $this->error('记录不存在', 404);
        }

        $allowed = [
            'payment_time',
            'uninsured_reason',
            'insurance_place_remark',
            'death_remark',
            'manual_remark',
        ];
        $data = array_intersect_key($request->all(), array_flip($allowed));
        $record->update($data);

        $this->operationLogService->record('参保台账明细', '编辑', 'enroll_ledgers', (string) $id, '编辑参保台账字段', [
            'fields' => array_keys($data),
        ]);

        return $this->success($record->refresh(), '保存成功');
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

        return $this->submitImport($request, Attachment3ImportJob::class, '参保台账_附件3导入_', 'enrollAttachment3Import');
    }

    public function importAttachment4(RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能导入参保台账附件4', 403);
        }

        return $this->submitImport($request, SupplementImportJob::class, '参保台账_附件4导入_', 'enrollAttachment4Import', 'attachment4_verify');
    }

    public function importAttachment5(RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能导入参保台账附件5', 403);
        }

        return $this->submitImport($request, SupplementImportJob::class, '参保台账_附件5导入_', 'enrollAttachment5Import', 'attachment5_tax');
    }

    public function importAttachment6(RequestInterface $request)
    {
        if ($this->currentTownName($request) !== '') {
            return $this->error('镇街账号不能导入参保台账附件6', 403);
        }

        return $this->submitImport($request, SupplementImportJob::class, '参保台账_附件6导入_', 'enrollAttachment6Import', 'attachment6_death');
    }

    public function importAttachment3Return(RequestInterface $request)
    {
        return $this->submitImport($request, Attachment3ReturnImportJob::class, '参保台账_附件3回导_', 'enrollAttachment3ReturnImport', 'attachment3_return');
    }

    public function export(RequestInterface $request)
    {
        $type = (string) $request->input('type', 'attachment1');
        $filters = $request->all();
        $userId = (int) $request->getAttribute('userId', 0);
        $username = (string) $request->getAttribute('username', 'System');

        $uuid = TaskService::instance()->dispatchTask(
            '参保台账_导出_',
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
