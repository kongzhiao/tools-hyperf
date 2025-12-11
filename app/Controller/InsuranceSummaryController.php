<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\InsuranceData;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\DbConnection\Db;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class InsuranceSummaryController extends AbstractController
{
    /**
     * 获取参保汇总数据
     */
    public function getData(RequestInterface $request)
    {
        $year = (int) $request->input('year', date('Y'));
        
        try {
            // 一次性获取所有统计数据，使用GROUP BY优化查询
            $summaryStats = InsuranceData::query()
                ->select([
                    'street_town',
                    'payment_category', 
                    'level',
                    Db::raw('COUNT(*) as count'),
                    Db::raw('SUM(payment_amount) as amount')
                ])
                ->where('year', $year)
                ->whereNotNull('street_town')
                ->where('street_town', '!=', '')
                ->whereNotNull('payment_category')
                ->where('payment_category', '!=', '')
                ->whereNotNull('level')
                ->where('level', '!=', '')
                ->groupBy(['street_town', 'payment_category', 'level'])
                ->get();

            // 从配置表获取所有代缴类别和档次（确保数据完整性）
            $configData = \App\Model\InsuranceLevelConfig::getByYear($year);
            $categories = $configData->pluck('payment_category')->unique()->sort()->values()->toArray();
            $levels = $configData->pluck('level')->unique()->sort()->values()->toArray();
            
            // 构建categories与levels的完整关系结构，供前端生成表格头部使用
            $categoriesLevelsMapping = [];
            foreach ($configData as $config) {
                $category = $config->payment_category;
                $level = $config->level;
                
                if (!isset($categoriesLevelsMapping[$category])) {
                    $categoriesLevelsMapping[$category] = [
                        'category' => $category,
                        'levels' => [],
                        'total_levels' => 0
                    ];
                }
                
                if (!in_array($level, $categoriesLevelsMapping[$category]['levels'])) {
                    $categoriesLevelsMapping[$category]['levels'][] = $level;
                    $categoriesLevelsMapping[$category]['total_levels']++;
                }
            }
            
            // 对每个类别的档次进行排序
            foreach ($categoriesLevelsMapping as &$categoryData) {
                sort($categoryData['levels']);
            }
            
            // 从统计数据中获取镇街信息
            $streetTowns = [];
            
            // 按镇街、代缴类别和档次汇总数据
            $summaryData = [];
            $totalCount = 0;
            $totalAmount = 0;

            // 将统计数据按镇街分组
            $groupedStats = [];
            foreach ($summaryStats as $stat) {
                $streetTown = $stat->street_town;
                $category = $stat->payment_category;
                $level = $stat->level;
                
                // 收集所有镇街
                if (!in_array($streetTown, $streetTowns)) {
                    $streetTowns[] = $streetTown;
                }
                
                // 按镇街分组
                if (!isset($groupedStats[$streetTown])) {
                    $groupedStats[$streetTown] = [];
                }
                if (!isset($groupedStats[$streetTown][$category])) {
                    $groupedStats[$streetTown][$category] = [];
                }
                
                $groupedStats[$streetTown][$category][$level] = [
                    'count' => (int)$stat->count,
                    'amount' => (float)$stat->amount,
                ];
            }

            // 排序（categories和levels已经从配置表获取并排序）
            sort($streetTowns);

            // 构建返回数据结构
            foreach ($streetTowns as $streetTown) {
                $streetData = [
                    'street_town' => $streetTown,
                    'categories' => [],
                    'total_count' => 0,
                    'total_amount' => 0,
                ];

                foreach ($categories as $category) {
                    $categoryData = [
                        'count' => 0,
                        'amount' => 0,
                        'levels' => []
                    ];

                    // 按档次分别统计
                    foreach ($levels as $level) {
                        $levelData = $groupedStats[$streetTown][$category][$level] ?? ['count' => 0, 'amount' => 0];
                        
                        if ($levelData['count'] > 0) { // 只添加有数据的档次
                            $categoryData['levels'][$level] = $levelData;
                        }

                        $categoryData['count'] += $levelData['count'];
                        $categoryData['amount'] += $levelData['amount'];
                    }

                    $streetData['categories'][$category] = $categoryData;
                    $streetData['total_count'] += $categoryData['count'];
                    $streetData['total_amount'] += $categoryData['amount'];
                }

                $summaryData[] = $streetData;
                $totalCount += $streetData['total_count'];
                $totalAmount += $streetData['total_amount'];
            }

            return $this->success([
                'data' => $summaryData,
                'categories' => $categories,
                'levels' => $levels,
                'categories_levels_mapping' => array_values($categoriesLevelsMapping),
                'total_count' => $totalCount,
                'total_amount' => $totalAmount,
                'year' => $year,
            ]);
        } catch (\Exception $e) {
            return $this->error('获取参保汇总数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出参保汇总数据
     */
    public function export(RequestInterface $request)
    {
        $year = (int) $request->input('year', date('Y'));
        
        try {
            // 使用与getData相同的优化查询逻辑
            $summaryStats = InsuranceData::query()
                ->select([
                    'street_town',
                    'payment_category', 
                    'level',
                    Db::raw('COUNT(*) as count'),
                    Db::raw('SUM(payment_amount) as amount')
                ])
                ->where('year', $year)
                ->whereNotNull('street_town')
                ->where('street_town', '!=', '')
                ->whereNotNull('payment_category')
                ->where('payment_category', '!=', '')
                ->whereNotNull('level')
                ->where('level', '!=', '')
                ->groupBy(['street_town', 'payment_category', 'level'])
                ->get();

            // 从配置表获取所有代缴类别和档次（确保数据完整性）
            $configData = \App\Model\InsuranceLevelConfig::getByYear($year);
            $categories = $configData->pluck('payment_category')->unique()->sort()->values()->toArray();
            $levels = $configData->pluck('level')->unique()->sort()->values()->toArray();
            
            // 构建categories与levels的完整关系结构，供前端生成表格头部使用
            $categoriesLevelsMapping = [];
            foreach ($configData as $config) {
                $category = $config->payment_category;
                $level = $config->level;
                
                if (!isset($categoriesLevelsMapping[$category])) {
                    $categoriesLevelsMapping[$category] = [
                        'category' => $category,
                        'levels' => [],
                        'total_levels' => 0
                    ];
                }
                
                if (!in_array($level, $categoriesLevelsMapping[$category]['levels'])) {
                    $categoriesLevelsMapping[$category]['levels'][] = $level;
                    $categoriesLevelsMapping[$category]['total_levels']++;
                }
            }
            
            // 对每个类别的档次进行排序
            foreach ($categoriesLevelsMapping as &$categoryData) {
                sort($categoryData['levels']);
            }
            
            // 从统计数据中获取镇街信息
            $streetTowns = [];
            
            // 按镇街、代缴类别和档次汇总数据
            $summaryData = [];
            $totalCount = 0;
            $totalAmount = 0;

            // 将统计数据按镇街分组
            $groupedStats = [];
            foreach ($summaryStats as $stat) {
                $streetTown = $stat->street_town;
                $category = $stat->payment_category;
                $level = $stat->level;
                
                // 收集所有镇街
                if (!in_array($streetTown, $streetTowns)) {
                    $streetTowns[] = $streetTown;
                }
                
                // 按镇街分组
                if (!isset($groupedStats[$streetTown])) {
                    $groupedStats[$streetTown] = [];
                }
                if (!isset($groupedStats[$streetTown][$category])) {
                    $groupedStats[$streetTown][$category] = [];
                }
                
                $groupedStats[$streetTown][$category][$level] = [
                    'count' => (int)$stat->count,
                    'amount' => (float)$stat->amount,
                ];
            }

            // 排序（categories和levels已经从配置表获取并排序）
            sort($streetTowns);

            // 构建返回数据结构
            foreach ($streetTowns as $streetTown) {
                $streetData = [
                    'street_town' => $streetTown,
                    'categories' => [],
                    'total_count' => 0,
                    'total_amount' => 0,
                ];

                foreach ($categories as $category) {
                    $categoryData = [
                        'count' => 0,
                        'amount' => 0,
                        'levels' => []
                    ];

                    // 按档次分别统计
                    foreach ($levels as $level) {
                        $levelData = $groupedStats[$streetTown][$category][$level] ?? ['count' => 0, 'amount' => 0];
                        
                        if ($levelData['count'] > 0) { // 只添加有数据的档次
                            $categoryData['levels'][$level] = $levelData;
                        }

                        $categoryData['count'] += $levelData['count'];
                        $categoryData['amount'] += $levelData['amount'];
                    }

                    $streetData['categories'][$category] = $categoryData;
                    $streetData['total_count'] += $categoryData['count'];
                    $streetData['total_amount'] += $categoryData['amount'];
                }

                $summaryData[] = $streetData;
                $totalCount += $streetData['total_count'];
                $totalAmount += $streetData['total_amount'];
            }

            // 创建Excel文件
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // 设置标题
            $sheet->setCellValue('A1', $year . '年参保数据汇总表');
            $colCount = count($categories) * (count($levels) * 2) + 2; // 镇街列 + 每个类别每个档次的人数金额列 + 总计列
            
            // 计算最后一列的标识
            $lastCol = $this->getColumnByIndex($colCount - 1);
            
            // 调试信息
            error_log("ColCount: $colCount, LastCol: $lastCol, Levels: " . implode(',', $levels));
            
            try {
                $sheet->mergeCells('A1:' . $lastCol . '1');
            } catch (\Exception $e) {
                error_log("Merge cells error: " . $e->getMessage());
                // 如果合并失败，使用默认的合并范围
                $sheet->mergeCells('A1:Z1');
            }

            // 设置表头
            $sheet->setCellValue('A3', '镇街');
            
            // 为每个代缴类别添加表头
            $colIndex = 1;
            foreach ($categories as $category) {
                $currentCol = $this->getColumnByIndex($colIndex);
                $nextCol = $this->getColumnByIndex($colIndex + (count($levels) * 2) - 1);
                
                $sheet->setCellValue($currentCol . '2', $category);
                try {
                    $sheet->mergeCells($currentCol . '2:' . $nextCol . '2');
                } catch (\Exception $e) {
                    error_log("Merge header error: $currentCol to $nextCol - " . $e->getMessage());
                }
                
                // 为每个档次添加子表头
                foreach ($levels as $level) {
                    $sheet->setCellValue($this->getColumnByIndex($colIndex) . '3', $level . '人数');
                    $sheet->setCellValue($this->getColumnByIndex($colIndex + 1) . '3', $level . '金额');
                    $colIndex += 2;
                }
            }

            // 添加总计列
            $currentCol = $this->getColumnByIndex($colIndex);
            $nextCol = $this->getColumnByIndex($colIndex + 1);
            $sheet->setCellValue($currentCol . '2', '总计');
            try {
                $sheet->mergeCells($currentCol . '2:' . $nextCol . '2');
            } catch (\Exception $e) {
                error_log("Merge total header error: $currentCol to $nextCol - " . $e->getMessage());
            }
            $sheet->setCellValue($currentCol . '3', '人数');
            $sheet->setCellValue($nextCol . '3', '金额（元）');

            // 填充数据
            $row = 4;
            foreach ($summaryData as $item) {
                $sheet->setCellValue('A' . $row, $item['street_town']);
                
                $colIndex = 1;
                foreach ($categories as $category) {
                    $categoryData = $item['categories'][$category];
                    
                    foreach ($levels as $level) {
                        $levelData = $categoryData['levels'][$level] ?? ['count' => 0, 'amount' => 0];
                        $sheet->setCellValue($this->getColumnByIndex($colIndex) . $row, (int)$levelData['count']);
                        $sheet->setCellValue($this->getColumnByIndex($colIndex + 1) . $row, (float)$levelData['amount']);
                        $colIndex += 2;
                    }
                }

                $currentCol = $this->getColumnByIndex($colIndex);
                $nextCol = $this->getColumnByIndex($colIndex + 1);
                $sheet->setCellValue($currentCol . $row, (int)$item['total_count']);
                $sheet->setCellValue($nextCol . $row, (float)$item['total_amount']);
                $row++;
            }

            // 添加合计行
            $sheet->setCellValue('A' . $row, '总计');
            
            $colIndex = 1;
            foreach ($categories as $category) {
                foreach ($levels as $level) {
                    $categoryTotalCount = 0;
                    $categoryTotalAmount = 0;
                    
                    foreach ($summaryData as $item) {
                        if (isset($item['categories'][$category]['levels'][$level])) {
                            $categoryTotalCount += $item['categories'][$category]['levels'][$level]['count'];
                            $categoryTotalAmount += $item['categories'][$category]['levels'][$level]['amount'];
                        }
                    }
                    
                    $sheet->setCellValue($this->getColumnByIndex($colIndex) . $row, (int)$categoryTotalCount);
                    $sheet->setCellValue($this->getColumnByIndex($colIndex + 1) . $row, (float)$categoryTotalAmount);
                    $colIndex += 2;
                }
            }
            
            $currentCol = $this->getColumnByIndex($colIndex);
            $nextCol = $this->getColumnByIndex($colIndex + 1);
            $sheet->setCellValue($currentCol . $row, (int)$totalCount);
            $sheet->setCellValue($nextCol . $row, (float)$totalAmount);

            // 设置样式
            $this->setExcelStyles($sheet, $row, count($categories), count($levels));

            // 输出文件
            $writer = new Xlsx($spreadsheet);
            $filename = $year . '年参保数据汇总表.xlsx';
            
            // 输出到临时文件
            $tempFile = tempnam(sys_get_temp_dir(), 'insurance_summary_');
            $writer->save($tempFile);
            
            $content = file_get_contents($tempFile);
            unlink($tempFile);
            
            return $this->success([
                'filename' => $filename,
                'content' => base64_encode($content),
                'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ]);
            
        } catch (\Exception $e) {
            return $this->error('导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 根据索引获取列标识
     */
    private function getColumnByIndex(int $index): string
    {
        // 简化的列标识计算，只支持A-Z和AA-ZZ
        if ($index < 26) {
            return chr(65 + $index);
        } elseif ($index < 52) {
            return 'A' . chr(65 + ($index - 26));
        } elseif ($index < 78) {
            return 'B' . chr(65 + ($index - 52));
        } elseif ($index < 104) {
            return 'C' . chr(65 + ($index - 78));
        } else {
            // 如果超过104列，返回Z
            return 'Z';
        }
    }

    /**
     * 设置Excel样式
     */
    private function setExcelStyles($sheet, $lastRow, $categoryCount, $levelCount): void
    {
        $colCount = $categoryCount * ($levelCount * 2) + 2;

        // 计算最后一列的标识
        $lastCol = $this->getColumnByIndex($colCount - 1);

        // 标题样式
        $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // 表头样式
        $sheet->getStyle('A2:' . $lastCol . '3')->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E6F3FF'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
        ]);

        // 数据行样式
        $sheet->getStyle('A4:' . $lastCol . ($lastRow - 1))->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        // 合计行样式
        $sheet->getStyle('A' . $lastRow . ':' . $lastCol . $lastRow)->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F0F0F0'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        // 设置列宽
        $sheet->getColumnDimension('A')->setWidth(15); // 镇街列
        for ($i = 1; $i < $colCount; $i++) {
            $col = $this->getColumnByIndex($i);
            $sheet->getColumnDimension($col)->setWidth(12);
        }

        // 设置数字格式
        for ($i = 1; $i < $colCount; $i++) {
            $col = $this->getColumnByIndex($i);
            if ($i % 2 == 1) { // 人数列（奇数列，从B开始）
                $sheet->getStyle($col . '4:' . $col . $lastRow)->getNumberFormat()->setFormatCode('#,##0');
            } else { // 金额列（偶数列）
                $sheet->getStyle($col . '4:' . $col . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
            }
        }

        // 确保金额列的数据类型正确
        for ($i = 1; $i < $colCount; $i += 2) { // 只处理金额列（奇数列，从B开始）
            $col = $this->getColumnByIndex($i);
            for ($row = 4; $row <= $lastRow; $row++) {
                $cellValue = $sheet->getCell($col . $row)->getValue();
                if (is_numeric($cellValue)) {
                    $sheet->getCell($col . $row)->setValue((float)$cellValue);
                }
            }
        }
    }
} 