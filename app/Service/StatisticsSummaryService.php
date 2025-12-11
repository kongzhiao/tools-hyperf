<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\StatisticsSummary;
use Hyperf\HttpServer\Contract\Upload\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class StatisticsSummaryService
{
    /**
     * 从Excel文件导入数据
     */
    public function importFromExcel(UploadedFile $file, string $projectCode, string $dataType): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) < 2) {
            throw new \Exception('Excel文件数据为空');
        }

        // 获取表头
        $headers = $rows[0];
        $dataRows = array_slice($rows, 1);

        // 生成导入批次号
        $importBatch = 'BATCH_' . date('YmdHis') . '_' . uniqid();

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($dataRows as $index => $row) {
            try {
                // 跳过空行
                if (empty(array_filter($row))) {
                    continue;
                }

                $data = $this->mapRowToData($row, $headers, $projectCode, $dataType, $importBatch);
                
                if ($data) {
                    StatisticsSummary::create($data);
                    $successCount++;
                }
            } catch (\Exception $e) {
                $errorCount++;
                $errors[] = "第" . ($index + 2) . "行: " . $e->getMessage();
            }
        }

        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors,
            'import_batch' => $importBatch
        ];
    }

    /**
     * 将Excel行数据映射到数据库字段
     */
    private function mapRowToData(array $row, array $headers, string $projectCode, string $dataType, string $importBatch): array
    {
        $data = [
            'project_code' => $projectCode,
            'data_type' => $dataType,
            'import_batch' => $importBatch,
        ];

        // 字段映射关系
        $fieldMapping = [
            '街道/乡镇' => 'street_town',
            '姓名' => 'name',
            '证件类型' => 'id_type',
            '证件号码' => 'id_number',
            '医保分类' => 'medical_category',
            '费用总额' => 'total_cost',
            '符合医保报销金额' => 'eligible_reimbursement',
            '基本医疗保险报销金额' => 'basic_medical_reimbursement',
            '大病报销金额' => 'serious_illness_reimbursement',
            '大额报销金额' => 'large_amount_reimbursement',
            '医疗救助' => 'medical_assistance',
            '倾斜救助' => 'tilt_assistance',
            '个人编号' => 'person_number',
            '代缴类别' => 'payment_category',
            '代缴金额' => 'payment_amount',
            '代缴日期' => 'payment_date',
            '档次' => 'level',
            '个人支付' => 'personal_amount',
            '医疗救助类别' => 'medical_assistance_category',
            '备注' => 'remark',
        ];

        foreach ($headers as $index => $header) {
            if (isset($fieldMapping[$header]) && isset($row[$index])) {
                $field = $fieldMapping[$header];
                $value = trim($row[$index]);

                // 处理特殊字段
                switch ($field) {
                    case 'id_type':
                        $data[$field] = $value ?: '身份证';
                        break;
                    case 'payment_date':
                        $data[$field] = $this->parseDate($value);
                        break;
                    case 'total_cost':
                    case 'eligible_reimbursement':
                    case 'basic_medical_reimbursement':
                    case 'serious_illness_reimbursement':
                    case 'large_amount_reimbursement':
                    case 'medical_assistance':
                    case 'tilt_assistance':
                    case 'payment_amount':
                    case 'personal_amount':
                        $data[$field] = $this->parseDecimal($value);
                        break;
                    default:
                        $data[$field] = $value;
                        break;
                }
            }
        }

        // 验证必填字段
        if (empty($data['name'])) {
            throw new \Exception('姓名不能为空');
        }
        if (empty($data['id_number'])) {
            throw new \Exception('证件号码不能为空');
        }
        if (empty($data['payment_category'])) {
            throw new \Exception('代缴类别不能为空');
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

        // 如果是Excel日期格式，转换为Y-m-d格式
        if (is_numeric($value)) {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
            return $date->format('Y-m-d');
        }

        // 尝试解析常见日期格式
        $formats = ['Y-m-d', 'Y/m/d', 'd/m/Y', 'm/d/Y', 'Ymd'];
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * 解析小数
     */
    private function parseDecimal($value): float
    {
        if (empty($value)) {
            return 0.00;
        }

        // 移除千分位分隔符和货币符号
        $value = preg_replace('/[^\d.-]/', '', $value);
        
        return (float) $value;
    }
} 