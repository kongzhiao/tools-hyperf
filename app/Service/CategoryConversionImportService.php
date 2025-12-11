<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\CategoryConversion;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Hyperf\Di\Annotation\Inject;

class CategoryConversionImportService
{
    /**
     * 生成导入模板
     */
    public function generateTemplate(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // 设置表头
        $headers = [
            'A1' => '医保数据导出对象口径',
            'B1' => '税务代缴数据口径',
            'C1' => '国家字典值名称'
        ];

        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }

        // 设置表头样式
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1890FF'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ];

        $sheet->getStyle('A1:C1')->applyFromArray($headerStyle);

        // 设置列宽
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(30);

        // 添加示例数据
        $examples = [
            ['特困人员-7131', '特困人员', '特困人员'],
            ['低保中重残重病人员-7019', '民政城乡低保对象', '低保对象'],
            ['城乡孤儿-7041', '民政城乡孤儿', '城乡孤儿'],
            ['事实无人抚养儿童-7141', '事实无人抚养儿童', '事实无人抚养儿童'],
            ['在乡老复员军人-7090', '在乡复原军人', '民政在乡老复员军人'],
        ];

        $row = 2;
        foreach ($examples as $example) {
            $sheet->setCellValue("A{$row}", $example[0]);
            $sheet->setCellValue("B{$row}", $example[1]);
            $sheet->setCellValue("C{$row}", $example[2]);
            $row++;
        }

        // 设置数据区域样式
        $dataStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];

        $sheet->getStyle('A2:C' . ($row - 1))->applyFromArray($dataStyle);

        // 添加说明
        $sheet->setCellValue('A' . ($row + 1), '说明：');
        $sheet->setCellValue('A' . ($row + 2), '1. 税务代缴数据口径为必填项，不能为空');
        $sheet->setCellValue('A' . ($row + 3), '2. 医保数据导出对象口径和国家字典值名称至少填写一项');
        $sheet->setCellValue('A' . ($row + 4), '3. 当遇到医保数据导出对象口径或国家字典值名称时，会自动替换为对应的税务代缴数据口径');

        // 保存文件
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'template_');
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * 解析Excel文件
     */
    public function parseExcelFile(string $filePath): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        // 跳过表头
        array_shift($rows);

        $parsedData = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // Excel行号从1开始，跳过表头
            $medicalExportStandard = trim($row[0] ?? '');
            $taxStandard = trim($row[1] ?? '');
            $nationalDictName = trim($row[2] ?? '');

            $rowErrors = $this->validateRow($taxStandard, $medicalExportStandard, $nationalDictName, $rowNumber);

            if (!empty($rowErrors)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'errors' => $rowErrors
                ];
            }

            if (!empty($taxStandard)) {
                $parsedData[] = [
                    'tax_standard' => $taxStandard,
                    'medical_export_standard' => !empty($medicalExportStandard) ? $medicalExportStandard : null,
                    'national_dict_name' => !empty($nationalDictName) ? $nationalDictName : null,
                    'row' => $rowNumber
                ];
            }
        }

        return [
            'data' => $parsedData,
            'errors' => $errors,
            'total_rows' => count($rows),
            'valid_rows' => count($parsedData),
            'error_rows' => count($errors)
        ];
    }

    /**
     * 验证单行数据
     */
    private function validateRow(string $taxStandard, string $medicalExportStandard, string $nationalDictName, int $rowNumber): array
    {
        $errors = [];

        // 验证必填字段
        if (empty($taxStandard)) {
            $errors[] = '税务代缴数据口径不能为空';
        }

        // 验证至少有一个映射字段
        if (empty($medicalExportStandard) && empty($nationalDictName)) {
            $errors[] = '医保数据导出对象口径和国家字典值名称至少填写一项';
        }

        // 验证数据长度
        if (strlen($taxStandard) > 255) {
            $errors[] = '税务代缴数据口径长度不能超过255个字符';
        }

        if (strlen($medicalExportStandard) > 255) {
            $errors[] = '医保数据导出对象口径长度不能超过255个字符';
        }

        if (strlen($nationalDictName) > 255) {
            $errors[] = '国家字典值名称长度不能超过255个字符';
        }

        return $errors;
    }

    /**
     * 批量导入数据
     */
    public function batchImport(array $data): array
    {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($data as $item) {
            try {
                // 检查是否已存在相同的记录
                $exists = CategoryConversion::where('tax_standard', $item['tax_standard'])
                    ->where('medical_export_standard', $item['medical_export_standard'])
                    ->where('national_dict_name', $item['national_dict_name'])
                    ->exists();

                if ($exists) {
                    $errorCount++;
                    $errors[] = [
                        'row' => $item['row'] ?? 'unknown',
                        'error' => '该转换规则已存在'
                    ];
                    continue;
                }

                // 创建新记录
                CategoryConversion::create([
                    'tax_standard' => $item['tax_standard'],
                    'medical_export_standard' => $item['medical_export_standard'],
                    'national_dict_name' => $item['national_dict_name'],
                ]);

                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $errors[] = [
                    'row' => $item['row'] ?? 'unknown',
                    'error' => '导入失败: ' . $e->getMessage()
                ];
            }
        }

        return [
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ];
    }

    /**
     * 验证导入数据
     */
    public function validateImportData(array $data): array
    {
        $validData = [];
        $errors = [];

        foreach ($data as $item) {
            $rowErrors = $this->validateRow(
                $item['tax_standard'] ?? '',
                $item['medical_export_standard'] ?? '',
                $item['national_dict_name'] ?? '',
                $item['row'] ?? 0
            );

            if (!empty($rowErrors)) {
                $errors[] = [
                    'row' => $item['row'] ?? 'unknown',
                    'errors' => $rowErrors
                ];
            } else {
                $validData[] = $item;
            }
        }

        return [
            'valid_data' => $validData,
            'errors' => $errors,
            'total_count' => count($data),
            'valid_count' => count($validData),
            'error_count' => count($errors)
        ];
    }
} 