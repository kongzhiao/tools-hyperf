<?php

declare(strict_types=1);

namespace App\Controller\Unrescued;

use App\Controller\AbstractController;
use App\Job\Unrescued\NoticeExportJob;
use App\Job\Unrescued\NoticeImportJob;
use App\Model\Unrescued\UnrescuedNoticeRecord;
use App\Service\OperationLogService;
use App\Service\TaskService;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @Controller(prefix="/api/unrescued/notice-records")
 */
class NoticeRecordController extends AbstractController
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
        $townId = $this->currentTownId($request);
        $query = UnrescuedNoticeRecord::query();
        $this->applyFilters($query, $request->all(), $townId);
        $total = $query->count();
        $this->applySort($query, $request->all());
        $list = $query->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();
        if ($townId > 0) {
            $list->each(function ($item) {
                unset($item->calc_reimbursement_amount);
                unset($item->admin_remark);
            });
        }
        return $this->success(['list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $pageSize], '获取成功');
    }

    /**
     * @RequestMapping(path="/statistics", methods="get")
     */
    public function statistics(RequestInterface $request)
    {
        $townId = $this->currentTownId($request);
        $query = UnrescuedNoticeRecord::query();
        $this->applyFilters($query, $request->all(), $townId);
        $total = (clone $query)->count();
        $pending = (clone $query)->where('status', UnrescuedNoticeRecord::STATUS_PENDING)->count();
        $distributed = (clone $query)->where('status', UnrescuedNoticeRecord::STATUS_DISTRIBUTED)->count();
        $received = (clone $query)->where('status', UnrescuedNoticeRecord::STATUS_RECEIVED)->count();
        $notified = (clone $query)->where('status', UnrescuedNoticeRecord::STATUS_NOTIFIED)->count();
        $paid = (clone $query)->where('reimbursement_status', UnrescuedRecordService::REIMBURSEMENT_PAID)->count();
        $unpaid = (clone $query)->where('reimbursement_status', UnrescuedRecordService::REIMBURSEMENT_UNPAID)->count();
        return $this->success(compact('total', 'pending', 'distributed', 'received', 'notified', 'paid', 'unpaid'), '获取成功');
    }

    /**
     * @RequestMapping(path="/import", methods="post")
     */
    public function import(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能导入通知明细', 403);
        }
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
        $path = $dir . 'noticeImport_' . date('YmdHis') . '_' . uniqid() . '.csv';
        $file->moveTo($path);
        $userId = (int) $request->getAttribute('userId', 0);
        $uuid = TaskService::instance()->dispatchTask(
            '下放通知_导入_通知明细_',
            $userId,
            (string) $request->getAttribute('username', 'System'),
            NoticeImportJob::class,
            [['settlement_period' => $period, 'source_file' => $file->getClientFilename()], $path],
            sprintf('task:lock:%d:noticeImport', $userId)
        );
        if ($uuid === false) {
            return $this->error('导入任务正在执行中，请在任务中心查看进度', 400);
        }
        return $this->success(['uuid' => $uuid], '导入任务已提交，请在任务中心查看进度');
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
        $townIds = $request->input('town_ids', []);
        if ($period === '' || !is_array($townIds) || empty($townIds)) {
            return $this->error('清算期和下放镇街不能为空', 400);
        }
        $townIds = array_values(array_unique(array_map('intval', $townIds)));
        $pendingQuery = UnrescuedNoticeRecord::query()
            ->where('settlement_period', $period)
            ->where('status', UnrescuedNoticeRecord::STATUS_PENDING);
        $pendingTotal = (clone $pendingQuery)->count();
        $unmatchedTownCount = (clone $pendingQuery)->where('town_id', 0)->count();
        $selectedPendingCount = (clone $pendingQuery)->whereIn('town_id', $townIds)->count();
        $otherTownCount = max($pendingTotal - $unmatchedTownCount - $selectedPendingCount, 0);
        $affected = UnrescuedNoticeRecord::query()
            ->where('settlement_period', $period)
            ->whereIn('town_id', $townIds)
            ->where('status', UnrescuedNoticeRecord::STATUS_PENDING)
            ->update([
                'status' => UnrescuedNoticeRecord::STATUS_DISTRIBUTED,
                'distributed_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        $summary = compact('period', 'townIds', 'affected', 'pendingTotal', 'selectedPendingCount', 'unmatchedTownCount', 'otherTownCount');
        $this->operationLogService->record('下放通知', '批量下放', 'unrescued_notice_records', $period, '批量下放通知明细', $summary);
        if ($affected <= 0) {
            $reasons = [];
            if ($pendingTotal <= 0) {
                $reasons[] = '当前清算期暂无待下放数据';
            }
            if ($unmatchedTownCount > 0) {
                $reasons[] = "{$unmatchedTownCount} 条待下放数据未匹配到镇街，请先修正镇街或补充镇街字典";
            }
            if ($otherTownCount > 0) {
                $reasons[] = "{$otherTownCount} 条待下放数据属于未选择的镇街";
            }
            if ($pendingTotal > 0 && $selectedPendingCount <= 0 && $reasons === []) {
                $reasons[] = '所选镇街下暂无待下放数据';
            }

            return $this->error('未下放任何数据：' . implode('；', $reasons), 400);
        }

        return $this->success($summary, "下放成功：{$affected} 条");
    }

    /**
     * @RequestMapping(path="/undistribute", methods="post")
     */
    public function undistribute(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能撤销下放', 403);
        }
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return $this->error('请选择记录', 400);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $selectedCount = UnrescuedNoticeRecord::query()->whereIn('id', $ids)->count();
        $receivedOrNotifiedCount = UnrescuedNoticeRecord::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [UnrescuedNoticeRecord::STATUS_RECEIVED, UnrescuedNoticeRecord::STATUS_NOTIFIED])
            ->count();
        $affected = UnrescuedNoticeRecord::query()
            ->whereIn('id', $ids)
            ->where('status', UnrescuedNoticeRecord::STATUS_DISTRIBUTED)
            ->update([
                'status' => UnrescuedNoticeRecord::STATUS_PENDING,
                'distributed_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        $skippedCount = max($selectedCount - $affected, 0);
        $summary = compact('selectedCount', 'affected', 'skippedCount', 'receivedOrNotifiedCount');
        $this->operationLogService->record('下放通知', '撤销下放', 'unrescued_notice_records', implode(',', $ids), '管理员撤销下放通知明细', $summary);

        if ($affected <= 0) {
            $message = $receivedOrNotifiedCount > 0
                ? '未撤销任何数据：所选数据已被镇街接收或通知，不能撤销下放'
                : '未撤销任何数据：请选择状态为已下放且尚未接收的记录';
            return $this->error($message, 400);
        }

        return $this->success($summary, "撤销下放成功：{$affected} 条");
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

        $affected = UnrescuedNoticeRecord::query()
            ->where('settlement_period', $period)
            ->where('town_id', $townId)
            ->where('status', UnrescuedNoticeRecord::STATUS_DISTRIBUTED)
            ->update([
                'status' => UnrescuedNoticeRecord::STATUS_RECEIVED,
                'received_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->operationLogService->record('下放通知', '接收', 'unrescued_notice_records', "{$period}:{$townId}", '镇街确认接收', compact('period', 'townId', 'affected'));
        return $this->success(['affected_rows' => $affected], '接收成功');
    }

    /**
     * @RequestMapping(path="/receive/status", methods="get")
     */
    public function receiveStatus(RequestInterface $request)
    {
        $townId = $this->currentTownId($request);
        if ($townId <= 0) {
            return $this->success(['pending_count' => 0], '获取成功');
        }
        $period = $this->recordService->normalizePeriod((string) $request->input('settlement_period', ''));
        if ($period === '') {
            return $this->success(['pending_count' => 0], '获取成功');
        }

        $pendingCount = UnrescuedNoticeRecord::query()
            ->where('settlement_period', $period)
            ->where('town_id', $townId)
            ->where('status', UnrescuedNoticeRecord::STATUS_DISTRIBUTED)
            ->count();

        return $this->success(['pending_count' => $pendingCount], '获取成功');
    }

    /**
     * @RequestMapping(path="/notify", methods="post")
     */
    public function notify(RequestInterface $request)
    {
        return $this->batchUpdateRecords($request, [
            'status' => UnrescuedNoticeRecord::STATUS_NOTIFIED,
            'notified_at' => date('Y-m-d H:i:s'),
        ], '标记通知', '标记已通知', [
            UnrescuedNoticeRecord::STATUS_RECEIVED,
            UnrescuedNoticeRecord::STATUS_NOTIFIED,
        ]);
    }

    /**
     * @RequestMapping(path="/unnotify", methods="post")
     */
    public function unnotify(RequestInterface $request)
    {
        return $this->batchUpdateRecords($request, [
            'status' => UnrescuedNoticeRecord::STATUS_RECEIVED,
            'notified_at' => null,
        ], '撤销通知', '已撤销通知', [
            UnrescuedNoticeRecord::STATUS_NOTIFIED,
        ]);
    }

    /**
     * @RequestMapping(path="/feedback", methods="post")
     */
    public function feedback(RequestInterface $request)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return $this->error('请选择记录', 400);
        }
        $townId = $this->currentTownId($request);
        $query = UnrescuedNoticeRecord::query()->whereIn('id', $ids);
        if ($townId > 0) {
            $query->where('town_id', $townId)
                ->whereIn('status', [
                    UnrescuedNoticeRecord::STATUS_RECEIVED,
                    UnrescuedNoticeRecord::STATUS_NOTIFIED,
                ]);
        }
        $feedback = [
            'contact_name' => trim((string) $request->input('contact_name', '')) ?: null,
            'contact_phone' => trim((string) $request->input('contact_phone', '')) ?: null,
            'bank_name' => trim((string) $request->input('bank_name', '')) ?: null,
            'bank_account_name' => trim((string) $request->input('bank_account_name', '')) ?: null,
            'bank_account_no' => trim((string) $request->input('bank_account_no', '')) ?: null,
            'town_remark' => trim((string) $request->input('town_remark', '')) ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $affected = $query->update((new UnrescuedNoticeRecord())->prepareAttributesForStorage($feedback));
        return $this->success(['affected_rows' => $affected], '回填成功');
    }

    /**
     * @RequestMapping(path="/admin-remark", methods="post")
     */
    public function adminRemark(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能填写管理员备注', 403);
        }
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return $this->error('请选择记录', 400);
        }
        $affected = UnrescuedNoticeRecord::query()->whereIn('id', $ids)->update([
            'admin_remark' => trim((string) $request->input('admin_remark', '')) ?: null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->success(['affected_rows' => $affected], '管理员备注已更新');
    }

    /**
     * @RequestMapping(path="/reimbursement", methods="post")
     */
    public function reimbursement(RequestInterface $request)
    {
        if ($this->currentTownId($request) > 0) {
            return $this->error('镇街账号不能标记报销状态', 403);
        }
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return $this->error('请选择记录', 400);
        }
        $status = (string) $request->input('reimbursement_status', UnrescuedRecordService::REIMBURSEMENT_PAID);
        $affected = UnrescuedNoticeRecord::query()->whereIn('id', $ids)->update([
            'reimbursement_status' => $status,
            'reimbursed_at' => $status === UnrescuedRecordService::REIMBURSEMENT_PAID ? date('Y-m-d H:i:s') : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->success(['affected_rows' => $affected], '报销状态已更新');
    }

    /**
     * @RequestMapping(path="/export", methods="post")
     */
    public function export(RequestInterface $request)
    {
        $uuid = TaskService::instance()->dispatchTask(
            '下放通知_导出_通知明细_',
            (int) $request->getAttribute('userId', 0),
            (string) $request->getAttribute('username', 'System'),
            NoticeExportJob::class,
            [[
                'filters' => (array) $request->input('filters', []),
                'user_town_id' => $this->currentTownId($request),
            ]]
        );
        return $this->success(['uuid' => $uuid], '导出任务已提交，请在任务中心查看进度');
    }

    private function applyFilters($query, array $filters, int $userTownId): void
    {
        $period = $this->recordService->normalizePeriod((string) ($filters['settlement_period'] ?? ''));
        if ($period !== '') {
            $query->where('settlement_period', $period);
        }
        if ($userTownId > 0) {
            $query->where('town_id', $userTownId)
                ->whereIn('status', [
                    UnrescuedNoticeRecord::STATUS_RECEIVED,
                    UnrescuedNoticeRecord::STATUS_NOTIFIED,
                ]);
        } elseif (($filters['town_id'] ?? '') !== '') {
            $query->where('town_id', (int) $filters['town_id']);
        }
        foreach (['status', 'reimbursement_status', 'medical_category'] as $field) {
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
        foreach (['hospital_name', 'disease_code', 'disease_name'] as $field) {
            $values = $this->recordService->filterValues($filters[$field] ?? null);
            if ($values !== []) {
                $query->where(function ($sub) use ($field, $values) {
                    foreach ($values as $value) {
                        $sub->orWhere($field, 'like', "%{$value}%");
                    }
                });
            }
        }
        $contactNames = $this->recordService->filterValues($filters['contact_name'] ?? null);
        if ($contactNames !== []) {
            $query->whereBlindIn('contact_name', $contactNames);
        }
        $this->recordService->applyDiseaseKeywordFilter($query, $filters['disease_keyword'] ?? null);
        $remark = trim((string) ($filters['remark'] ?? ''));
        if ($remark !== '') {
            $query->where(function ($sub) use ($remark, $userTownId) {
                $sub->where('system_remark', 'like', "%{$remark}%")
                    ->orWhere('town_remark', 'like', "%{$remark}%");
                if ($userTownId <= 0) {
                    $sub->orWhere('admin_remark', 'like', "%{$remark}%");
                }
            });
        }
    }

    private function applySort($query, array $filters): void
    {
        $field = (string) ($filters['sort_field'] ?? '');
        $order = (string) ($filters['sort_order'] ?? '');
        $allowed = [
            'settlement_period',
            'sequence_no',
            'name',
            'priority_identity',
            'hospital_name',
            'medical_category',
            'disease_code',
            'disease_name',
            'admission_date',
            'discharge_date',
            'settlement_time',
            'total_fee',
            'policy_fee',
            'pool_fund_pay',
            'large_amount_pay',
            'serious_illness_pay',
            'medical_assistance_pay',
            'yukuaibao_pay',
            'personal_account_pay',
            'personal_cash_pay',
            'calc_reimbursement_amount',
            'status',
            'reimbursement_status',
            'system_remark',
            'contact_name',
            'contact_phone',
            'bank_name',
            'bank_account_name',
            'bank_account_no',
            'town_remark',
            'admin_remark',
            'distributed_at',
            'received_at',
            'notified_at',
            'reimbursed_at',
            'created_at',
            'updated_at',
        ];
        if ($field !== '' && in_array($field, $allowed, true)) {
            $query->orderBy($field, $order === 'ascend' ? 'asc' : 'desc');
            return;
        }
        $query->orderByDesc('settlement_period')
            ->orderByRaw('CAST(`sequence_no` AS UNSIGNED) ASC')
            ->orderBy('sequence_no');
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

    private function batchUpdateRecords(RequestInterface $request, array $data, string $action, string $message, ?array $allowedStatuses = null)
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return $this->error('请选择记录', 400);
        }

        $query = UnrescuedNoticeRecord::query()->whereIn('id', $ids);
        $townId = $this->currentTownId($request);
        if ($townId > 0) {
            $query->where('town_id', $townId);
        }
        if ($allowedStatuses !== null) {
            $query->whereIn('status', $allowedStatuses);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $affected = $query->update((new UnrescuedNoticeRecord())->prepareAttributesForStorage($data));

        $this->operationLogService->record('下放通知', $action, 'unrescued_notice_records', implode(',', $ids), $action, ['affected' => $affected]);
        return $this->success(['affected_rows' => $affected], $message);
    }
}
