<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\YfSettlement;
use App\Service\YfSettlementService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Di\Annotation\Inject;

class YfSettlementController extends AbstractController
{
    /**
     * @var YfSettlementService
     */
    protected $service;

    public function __construct(
        \Psr\Container\ContainerInterface $container,
        \Hyperf\HttpServer\Contract\RequestInterface $request,
        \Hyperf\HttpServer\Contract\ResponseInterface $response,
        YfSettlementService $service
    ) {
        parent::__construct($container, $request, $response);
        $this->service = $service;
    }

    /**
     * 获取结算明细列表
     */
    public function index(RequestInterface $request)
    {
        $pageInput = $request->input('page', null);
        $page = $pageInput !== null ? (int) $pageInput : 1;
        $pageSizeInput = $request->input('page_size', null);
        $pageSize = $pageSizeInput !== null ? (int) $pageSizeInput : 20;

        $filters = $request->all();

        $query = YfSettlement::query();
        $this->service->applyFilters($query, $filters);

        $total = $query->count();
        $list = $query->orderBy('period_belong', 'desc')
            ->orderBy('id', 'desc')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 获取统计数据
     */
    public function getStatistics(RequestInterface $request)
    {
        $filters = $request->all();
        $stats = $this->service->getStatistics($filters);
        return $this->success($stats);
    }

    /**
     * 标记支付 (单条)
     */
    public function markPay(RequestInterface $request, int $id)
    {
        $settlement = YfSettlement::find($id);
        if (!$settlement) {
            return $this->error('记录不存在');
        }

        if ($settlement->pay_status === -1) {
            return $this->error('该记录为“不需支付”状态，无法标记');
        }

        $statusInput = $request->input('pay_status', null);
        $status = $statusInput !== null ? (int) $statusInput : 1;
        $payAtInput = $request->input('pay_at', null);
        $payAt = $payAtInput !== null ? (string) $payAtInput : date('Y-m-d H:i:s');
        $remarkInput = $request->input('remark', null);
        $remark = $remarkInput !== null ? (string) $remarkInput : '';

        $settlement->pay_status = $status;
        $settlement->pay_at = $payAt;
        if ($remark !== null) {
            $settlement->remark = $remark;
        }
        $settlement->save();

        return $this->success($settlement, '标注成功');
    }

    /**
     * 批量标记支付
     */
    public function batchMarkPay(RequestInterface $request)
    {
        $ids = $request->input('ids');
        if (empty($ids) || !is_array($ids)) {
            return $this->error('请选择有效的记录');
        }

        $statusInput = $request->input('pay_status', null);
        $status = $statusInput !== null ? (int) $statusInput : 1;
        $payAtInput = $request->input('pay_at', null);
        $payAt = $payAtInput !== null ? (string) $payAtInput : date('Y-m-d H:i:s');
        $remarkInput = $request->input('remark', null);
        $remark = $remarkInput !== null ? (string) $remarkInput : '';

        // 过滤掉状态为 -1 (不需支付) 的记录
        $affectedRows = YfSettlement::whereIn('id', $ids)
            ->where('pay_status', '!=', -1)
            ->update([
                'pay_status' => $status,
                'pay_at' => $payAt,
                'remark' => $remark,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

        return $this->success(['affected_rows' => $affectedRows], '批量标注成功');
    }

    /**
     * 导入结算数据
     */
    public function import(RequestInterface $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->error('无效的文件');
        }

        try {
            // 保存文件供异步 Job 处理
            $uploadDir = BASE_PATH . '/storage/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $newFileName = 'yf_settlement_import_' . time() . '_' . uniqid() . '.csv';
            $finalPath = $uploadDir . $newFileName;
            $file->moveTo($finalPath);

            // 投递异步任务
            $userId = (int) $this->request->getAttribute('userId', 0);
            $username = (string) $this->request->getAttribute('username', 'System');

            $lockKey = sprintf('task:lock:%d:importYfSettlement', $userId);
            $uuid = \App\Service\TaskService::instance()->dispatchTask(
                '优抚联网结算_导入_',
                $userId,
                $username,
                \App\Job\YfSettlementImportJob::class,
                [
                    [], // 基础参数
                    $finalPath
                ],
                $lockKey
            );

            if ($uuid === false) {
                return $this->error('导入任务正在执行中，请在任务中心查看进度');
            }

            return $this->success([
                'uuid' => $uuid
            ], '导入任务已提交，请在任务中心查看进度');
        } catch (\Exception $e) {
            return $this->error('导入提交失败：' . $e->getMessage());
        }
    }

    /**
     * 导出结算明细 (异步方式)
     */
    public function export(RequestInterface $request)
    {
        $filters = $request->all();
        $userId = (int) $this->request->getAttribute('userId', 0);
        $username = (string) $this->request->getAttribute('username', 'System');

        $uuid = \App\Service\TaskService::instance()->dispatchTask(
            '优抚联网结算_明细导出_',
            $userId,
            $username,
            \App\Job\YfSettlementExportJob::class,
            [
                ['filters' => $filters]
            ]
        );

        if ($uuid === false) {
            return $this->error('导出任务已在执行中');
        }

        return $this->success(['uuid' => $uuid], '导出任务已提交，请在任务中心查看进度');
    }

    /**
     * 重新计算现有数据
     */
    public function recalculate(RequestInterface $request)
    {
        $filters = $request->all();
        $this->service->recalculateSettlements($filters);

        return $this->success(null, '重新计算任务已提交，系统正在后台核算，请稍后刷新列表查看结果');
    }

    /**
     * 导出结算台账 (异步方式)
     */
    public function exportLedger(RequestInterface $request)
    {
        $filters = $request->all();
        $userId = (int) $this->request->getAttribute('userId', 0);
        $username = (string) $this->request->getAttribute('username', 'System');

        $uuid = \App\Service\TaskService::instance()->dispatchTask(
            '优抚联网结算_台账导出_',
            $userId,
            $username,
            \App\Job\YfSettlementLedgerExportJob::class,
            [
                ['filters' => $filters]
            ]
        );

        if ($uuid === false) {
            return $this->error('导出任务已在执行中');
        }

        return $this->success(['uuid' => $uuid], '导出任务已提交，请在任务中心查看进度');
    }

    /**
     * 删除单条结算记录
     */
    public function destroy(int $id)
    {
        $settlement = YfSettlement::find($id);
        if (!$settlement) {
            return $this->error('记录不存在');
        }

        $settlement->delete();

        return $this->success(null, '删除成功');
    }

    /**
     * 批量删除结算记录
     */
    public function batchDestroy(RequestInterface $request)
    {
        $ids = $request->input('ids');
        if (empty($ids) || !is_array($ids)) {
            return $this->error('请选择有效的记录');
        }

        $count = YfSettlement::destroy($ids);

        return $this->success(['count' => $count], '成功删除 ' . $count . ' 条记录');
    }
}
