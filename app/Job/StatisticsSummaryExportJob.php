<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\StatisticsData;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class StatisticsSummaryExportJob extends AbstractJob
{
    /**
     * @var array
     */
    public $params;

    /**
     * @var string
     */
    public $uuid;

    public function __construct(array $params, string $uuid)
    {
        $this->params = $params;
        $this->uuid = $uuid;
    }

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');

        try {
            // 设置脚本执行时间不限
            set_time_limit(0);
            ini_set('memory_limit', '1024M');

            $projectIds = $this->params['project_ids'] ?? [];
            if (empty($projectIds)) {
                $this->updateProgress($this->uuid, 100);
                return;
            }

            $spreadsheet = new Spreadsheet();

            $detailTypes = [
                '区内明细' => '区内明细',
                '跨区明细' => '跨区明细',
                '手工明细' => '手工明细'
            ];

            $headers = [
                '项目代码',
                '医保分类',
                '清算期',
                '费款所属期',
                '结算id',
                '认定地',
                '镇街',
                '参保地',
                '参保类别',
                '身份证号',
                '姓名',
                '救助身份',
                '就诊地',
                '就诊医疗机构名称',
                '医保就诊类别',
                '医疗救助类别',
                '病种编码',
                '病种名称',
                '入院日期',
                '出院日期',
                '结算日期',
                '费用总额',
                '符合医保报销金额',
                '基本医疗保险报销金额',
                '大病报销金额',
                '大额报销金额',
                '进入医疗救助金额',
                '医疗救助',
                '倾斜救助',
                '扶贫济困金额（元）',
                '渝快保支出金额（元）',
                '个人账户支付金额（元）',
                '个人现金支付金额（元）'
            ];

            // 计算总数量用于进度展示
            $totalCount = StatisticsData::whereIn('project_id', $projectIds)->count();
            if ($totalCount === 0) {
                // No data to export
                $this->updateProgress($this->uuid, 100);
                return;
            }

            // Chunk configuration
            $chunkSize = 1000; // rows per chunk
            $allTasks = [];
            $totalChunksCombined = 0;

            foreach ($detailTypes as $typeName => $importType) {
                $count = StatisticsData::whereIn('project_id', $projectIds)
                    ->where('import_type', $importType)
                    ->count();
                if ($count > 0) {
                    $chunks = (int) ceil($count / $chunkSize);
                    $allTasks[$typeName] = [
                        'import_type' => $importType,
                        'count' => $count,
                        'chunks' => $chunks
                    ];
                    $totalChunksCombined += $chunks;
                }
            }

            if ($totalChunksCombined === 0) {
                $this->updateProgress($this->uuid, 100);
                return;
            }

            // Initialize Redis coordination keys
            $redis = $container->get(\Hyperf\Redis\Redis::class);
            $totalKey = "export:{$this->uuid}:chunks_total";
            $pendingKey = "export:{$this->uuid}:chunks_pending";
            $filesKey = "export:{$this->uuid}:chunks_files";
            $redis->set($totalKey, $totalChunksCombined);
            $redis->set($pendingKey, $totalChunksCombined);
            $redis->del($filesKey);

            $this->updateProgress($this->uuid, 5);

            $driver = $container->get(DriverFactory::class)->get('default');
            foreach ($allTasks as $sheetName => $info) {
                $offset = 0;
                for ($i = 0; $i < $info['chunks']; $i++) {
                    $limit = min($chunkSize, $info['count'] - $offset);
                    $chunkJob = new StatisticsSummaryExportChunkJob(
                        $this->params,
                        $this->uuid,
                        $i, // sequence within sheet
                        $offset,
                        $limit
                    );
                    $chunkJob->sheetName = $sheetName;
                    $chunkJob->importType = $info['import_type'];
                    $driver->push($chunkJob);
                    $offset += $limit;
                }
            }
            return;

            // 保存文件
            // The original export logic has been replaced by chunked processing.
            // This part will not be executed because the job now only dispatches chunk jobs.
            // Keeping the code for reference but it will be unreachable.
            // $writer = new Xlsx($spreadsheet);
            // $runtimePath = BASE_PATH . '/runtime/export/';
            // if (!is_dir($runtimePath)) {
            //     mkdir($runtimePath, 0777, true);
            // }
            // $filename = '统计明细_导出_' . date('Y-m-d_H:i:s') . '_' . $this->uuid . '.xlsx';
            // $fullPath = $runtimePath . $filename;
            // $writer->save($fullPath);
            // $fileSizeMb = round(filesize($fullPath) / (1024 * 1024), 1);
            // $this->finalizeTask($fullPath, $filename, $fileSizeMb);
            // $logger->info("Task {$this->uuid} Export Success: " . $fullPath . " (Size: {$fileSizeMb}MB)");

        } catch (\Throwable $e) {
            $logger->error("Task {$this->uuid} Export Failed: " . $e->getMessage());
            // 进度标记为 -1 表示失败
            $this->updateProgress($this->uuid, -1.00);
            throw $e;
        }
    }

    private function setDetailExcelStyles($sheet, $lastRow): void
    {
        $highestColumn = $sheet->getHighestColumn();
        // 标题样式
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ]
        ]);
        // 表头样式
        $headerRange = 'A3:' . $highestColumn . '3';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0']
            ],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ]);
        // 设置边框
        $dataRange = 'A1:' . $highestColumn . $lastRow;
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
                ]
            ]
        ]);
    }
    /**
     * Finalize task after successful export.
     */
    protected function finalizeTask(string $fullPath, string $filename, float $fileSizeMb): void
    {
        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'download_url' => '/runtime/export/' . $filename,
            'url_at' => date('Y-m-d H:i:s'),
            'file_size' => $fileSizeMb
        ]);
    }

}
