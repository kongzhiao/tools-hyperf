<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\StatisticsData;
use Hyperf\Logger\LoggerFactory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Hyperf\Context\ApplicationContext;

class StatisticsSummaryImportJob extends AbstractJob
{
    public $params;
    public $uuid;
    public $tempFile;

    public function __construct(array $params, string $uuid, string $tempFile)
    {
        $this->params = $params;
        $this->uuid = $uuid;
        $this->tempFile = $tempFile;
    }

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');

        try {
            if (!file_exists($this->tempFile)) {
                throw new \Exception("导入文件不存在: " . $this->tempFile);
            }

            $projectId = $this->params['project_id'];
            $importType = $this->params['import_type'];
            $importBatch = date('YmdHis');

            $spreadsheet = IOFactory::load($this->tempFile);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            $headers = array_shift($rows);
            $totalRows = count($rows);

            if ($totalRows === 0) {
                $this->finalizeImportTask();
                return;
            }

            $batchSize = 500;
            $chunks = array_chunk($rows, $batchSize);
            $processedCount = 0;

            foreach ($chunks as $chunk) {
                foreach ($chunk as $row) {
                    if (empty(array_filter($row))) {
                        continue;
                    }

                    $rowData = [];
                    foreach ($headers as $index => $header) {
                        $rowData[$header] = $row[$index] ?? '';
                    }

                    StatisticsData::create([
                        'project_id' => $projectId,
                        'import_type' => $importType,
                        'import_batch' => $importBatch,
                        'medical_category' => $rowData['医保分类'] ?? '',
                        'settlement_period' => $rowData['清算期'] ?? '',
                        'fee_period' => $rowData['费款所属期'] ?? '',
                        'settlement_id' => $rowData['结算id'] ?? '',
                        'certification_place' => $rowData['认定地'] ?? '',
                        'street_town' => $rowData['镇街'] ?? '',
                        'insurance_place' => $rowData['参保地'] ?? '',
                        'insurance_category' => $rowData['参保类别'] ?? '',
                        'id_number' => $rowData['身份证号'] ?? '',
                        'name' => $rowData['姓名'] ?? '',
                        'assistance_identity' => $rowData['救助身份'] ?? '',
                        'visit_place' => $rowData['就诊地'] ?? '',
                        'medical_institution' => $rowData['就诊医疗机构名称'] ?? '',
                        'medical_visit_category' => $rowData['医保就诊类别'] ?? '',
                        'medical_assistance_category' => $rowData['医疗救助类别'] ?? '',
                        'disease_code' => $rowData['病种编码'] ?? '',
                        'disease_name' => $rowData['病种名称'] ?? '',
                        'admission_date' => $rowData['入院日期'] ?? '',
                        'discharge_date' => $rowData['出院日期'] ?? '',
                        'settlement_date' => $rowData['结算日期'] ?? '',
                        'total_cost' => $this->parseAmount($rowData['费用总额'] ?? 0),
                        'eligible_reimbursement' => $this->parseAmount($rowData['符合医保报销金额'] ?? 0),
                        'basic_medical_reimbursement' => $this->parseAmount($rowData['基本医疗保险报销金额'] ?? 0),
                        'serious_illness_reimbursement' => $this->parseAmount($rowData['大病报销金额'] ?? 0),
                        'large_amount_reimbursement' => $this->parseAmount($rowData['大额报销金额'] ?? 0),
                        'medical_assistance_amount' => $this->parseAmount($rowData['进入医疗救助金额'] ?? 0),
                        'medical_assistance' => $this->parseAmount($rowData['医疗救助'] ?? 0),
                        'tilt_assistance' => $this->parseAmount($rowData['倾斜救助'] ?? 0),
                        'poverty_relief_amount' => $this->parseAmount($rowData['扶贫济困金额（元）'] ?? 0),
                        'yukuaibao_amount' => $this->parseAmount($rowData['渝快保支出金额（元）'] ?? 0),
                        'personal_account_amount' => $this->parseAmount($rowData['个人账户支付金额（元）'] ?? 0),
                        'personal_cash_amount' => $this->parseAmount($rowData['个人现金支付金额（元）'] ?? 0),
                    ]);
                }

                $processedCount += count($chunk);
                $progress = ($processedCount / $totalRows) * 100;
                $this->updateProgress($this->uuid, $progress);
            }

            // 清理文件
            if (file_exists($this->tempFile)) {
                unlink($this->tempFile);
            }

            $this->finalizeImportTask();
            $logger->info("Task {$this->uuid} Import Success.");

        } catch (\Throwable $e) {
            $logger->error("Task {$this->uuid} Import Failed: " . $e->getMessage());
            $this->updateProgress($this->uuid, -1.00);
            throw $e;
        }
    }

    private function parseAmount($value): float
    {
        if (empty($value))
            return 0.0;
        $value = str_replace(['¥', '￥', ' ', ','], '', (string) $value);
        return round((float) $value, 2);
    }

    /**
     * Finalize import task after successful import.
     */
    protected function finalizeImportTask(): void
    {
        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'url_at' => date('Y-m-d H:i:s')
        ]);
    }

}

