<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\MedPersonInfo;
use App\Model\MedMedicalRecord;
use App\Service\CsvReaderService;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;

/**
 * 医疗救助数据导入任务
 * 使用 OpenSpout 流式读取 CSV 文件
 */
class MedicalAssistanceImportJob extends AbstractJob
{
    public string $tempFile;

    public function __construct(array $params, string $uuid, string $tempFile)
    {
        parent::__construct($params, $uuid);
        $this->tempFile = $tempFile;
    }

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');

        try {
            $this->startTask();

            if (!file_exists($this->tempFile)) {
                throw new \Exception("导入文件不存在: " . $this->tempFile);
            }

            $csvReader = new CsvReaderService();
            $result = [
                'patients' => ['imported' => 0, 'skipped' => 0, 'errors' => []],
                'medical_records' => ['imported' => 0, 'skipped' => 0, 'errors' => []],
            ];

            // 表头字段映射
            $patientMappings = [
                'name' => ['姓名', '患者姓名', '姓名（必填）'],
                'id_card' => ['身份证号', '身份证号码', '身份证号（必填）'],
                'insurance_area' => ['参保地', '参保地区', '参保所属地区']
            ];

            $recordMappings = [
                'hospital_name' => ['就诊医疗机构名称', '医疗机构名称', '医院名称'],
                'visit_type' => ['医保就诊类别', '就诊类别', '医保类别'],
                'admission_date' => ['入院时间', '入院日期'],
                'discharge_date' => ['出院时间', '出院日期'],
                'settlement_date' => ['结算时间', '结算日期'],
                'total_cost' => ['总费用', '医疗总费用'],
                'policy_covered_cost' => ['医保政策范围内费用', '政策范围内费用'],
                'pool_reimbursement_amount' => ['统筹报销金额', '统筹基金支付'],
                'large_amount_reimbursement_amount' => ['大额报销金额', '大额医疗费用补助'],
                'critical_illness_reimbursement_amount' => ['大病报销金额', '大病保险支付'],
                'medical_assistance_amount' => ['医疗救助金额', '医疗救助支付'],
                'excess_reimbursement_amount' => ['渝快保报销金额', '渝快保支付']
            ];

            $totalRows = $csvReader->countRows($this->tempFile) - 1; // 减去表头
            $processedRows = 0;

            // 逐行处理数据
            $csvReader->read(
                $this->tempFile,
                function ($rowData, $rowIndex, $headers) use (&$result, &$processedRows, $totalRows, $patientMappings, $recordMappings, $logger) {
                    try {
                        Db::beginTransaction();

                        // 提取患者信息
                        $patientData = $this->extractData($rowData, $patientMappings);

                        // 验证必填字段
                        if (empty($patientData['name']) || empty($patientData['id_card'])) {
                            throw new \Exception('姓名和身份证号不能为空');
                        }

                        // 检查患者是否已存在
                        $patient = MedPersonInfo::findByIdCard($patientData['id_card']);
                        if (!$patient) {
                            $patient = MedPersonInfo::create($patientData);
                            $result['patients']['imported']++;
                        } else {
                            $result['patients']['skipped']++;
                        }

                        // 提取就诊记录信息
                        $recordData = $this->extractRecordData($rowData, $recordMappings);

                        // 验证必填字段
                        if (empty($recordData['hospital_name'])) {
                            throw new \Exception('就诊医疗机构名称不能为空');
                        }

                        $recordData['person_id'] = $patient->id;
                        $recordData['processing_status'] = 'unreimbursed';

                        MedMedicalRecord::create($recordData);
                        $result['medical_records']['imported']++;

                        Db::commit();

                    } catch (\Throwable $e) {
                        Db::rollBack();
                        $result['patients']['errors'][] = "第" . ($rowIndex + 1) . "行：" . $e->getMessage();
                        $logger->warning("Row {$rowIndex} import failed: " . $e->getMessage());
                    }

                    $processedRows++;

                    // 更新进度
                    if ($processedRows % 50 === 0 && $totalRows > 0) {
                        $progress = ($processedRows / $totalRows) * 100;
                        $this->updateProgress($this->uuid, min($progress, 99));
                    }
                },
                true,
                null
            );

            // 清理文件
            if (file_exists($this->tempFile)) {
                unlink($this->tempFile);
            }

            // 计算成功率
            $totalPatients = $result['patients']['imported'] + $result['patients']['skipped'];
            $totalRecords = $result['medical_records']['imported'] + $result['medical_records']['skipped'];
            $total = $totalPatients + $totalRecords;
            $successRate = $total > 0
                ? round(($result['patients']['imported'] + $result['medical_records']['imported']) / $total * 100, 2)
                : 0;

            $result['summary'] = [
                'total_patients' => $totalPatients,
                'total_medical_records' => $totalRecords,
                'success_rate' => $successRate
            ];

            $this->finalizeImportTask($result);
            $logger->info("Task {$this->uuid} Import Success.", $result);

        } catch (\Throwable $e) {
            $logger->error("Task {$this->uuid} Import Failed: " . $e->getMessage());
            $this->failTask($e);
            throw $e;
        }
    }

    /**
     * 提取数据字段
     */
    private function extractData(array $rowData, array $mappings): array
    {
        $data = [];
        foreach ($mappings as $field => $possibleHeaders) {
            foreach ($possibleHeaders as $header) {
                if (isset($rowData[$header]) && $rowData[$header] !== '') {
                    $data[$field] = trim((string) $rowData[$header]);
                    break;
                }
            }
        }
        return $data;
    }

    /**
     * 提取就诊记录数据
     */
    private function extractRecordData(array $rowData, array $mappings): array
    {
        $data = [];
        $amountFields = [
            'total_cost',
            'policy_covered_cost',
            'pool_reimbursement_amount',
            'large_amount_reimbursement_amount',
            'critical_illness_reimbursement_amount',
            'medical_assistance_amount',
            'excess_reimbursement_amount'
        ];
        $dateFields = ['admission_date', 'discharge_date', 'settlement_date'];

        foreach ($mappings as $field => $possibleHeaders) {
            foreach ($possibleHeaders as $header) {
                if (isset($rowData[$header])) {
                    $value = trim((string) $rowData[$header]);

                    if (in_array($field, $amountFields)) {
                        $data[$field] = CsvReaderService::parseAmount($value);
                    } elseif (in_array($field, $dateFields)) {
                        $data[$field] = $this->parseDate($value);
                    } else {
                        $data[$field] = $value;
                    }
                    break;
                }
            }
        }

        // 设置默认值
        foreach ($amountFields as $field) {
            if (!isset($data[$field])) {
                $data[$field] = 0;
            }
        }

        return $data;
    }

    /**
     * 解析日期
     */
    private function parseDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d',
            'Y/m/d',
            'Y年m月d日',
            'd/m/Y',
            'm/d/Y'
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /**
     * 完成导入任务
     */
    protected function finalizeImportTask(array $result): void
    {
        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'url_at' => date('Y-m-d H:i:s'),
            'status' => \App\Model\Task::STATUS_COMPLETED
        ]);
        $this->releaseLock();
    }
}
