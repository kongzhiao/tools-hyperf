<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\InsuranceData;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Di\Annotation\Inject;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class TaxSummaryController extends AbstractController
{
    /**
     * 获取税务汇总数据
     */
    public function getData(RequestInterface $request)
    {
        $year = (int) $request->input('year', date('Y'));
        
        try {
            // 按代缴类别分组统计
            $summaryData = InsuranceData::query()
                ->selectRaw('
                    payment_category as category,
                    COUNT(*) as count,
                    SUM(payment_amount) as amount
                ')
                ->where('year', $year)
                ->whereNotNull('payment_category')
                ->where('payment_category', '!=', '')
                ->groupBy('payment_category')
                ->orderBy('amount', 'desc')
                ->get()
                ->toArray();

            // 计算总计
            $totalCount = array_sum(array_column($summaryData, 'count'));
            $totalAmount = array_sum(array_column($summaryData, 'amount'));

            return $this->success([
                'data' => $summaryData,
                'total_count' => $totalCount,
                'total_amount' => $totalAmount,
                'year' => $year,
            ]);
        } catch (\Exception $e) {
            return $this->error('获取税务汇总数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出税务汇总数据
     */
    public function export(RequestInterface $request)
    {
        $year = (int) $request->input('year', date('Y'));
        
        try {
            // 获取汇总数据
            $summaryData = InsuranceData::query()
                ->selectRaw('
                    payment_category as category,
                    COUNT(*) as count,
                    SUM(payment_amount) as amount
                ')
                ->where('year', $year)
                ->whereNotNull('payment_category')
                ->where('payment_category', '!=', '')
                ->groupBy('payment_category')
                ->orderBy('amount', 'desc')
                ->get()
                ->toArray();

            // 计算总计
            $totalCount = array_sum(array_column($summaryData, 'count'));
            $totalAmount = array_sum(array_column($summaryData, 'amount'));

            // 创建Excel文件
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // 设置标题
            $sheet->setCellValue('A1', $year . '年税务代缴汇总表');
            $sheet->mergeCells('A1:D1');
            
            // 设置表头
            $headers = ['序号', '代缴类别', '人数', '代缴金额（元）'];
            foreach ($headers as $index => $header) {
                $column = chr(65 + $index); // A, B, C, D, E
                $sheet->setCellValue($column . '3', $header);
            }

            // 填充数据
            $row = 4;
            foreach ($summaryData as $index => $item) {
                $percentage = $totalAmount > 0 ? ($item['amount'] / $totalAmount * 100) : 0;
                
                $sheet->setCellValue('A' . $row, $index + 1);
                $sheet->setCellValue('B' . $row, $item['category']);
                $sheet->setCellValue('C' . $row, (int)$item['count']);
                $sheet->setCellValue('D' . $row, (float)$item['amount']);
                
                $row++;
            }

            // 添加合计行
            $sheet->setCellValue('A' . $row, '合计');
            $sheet->setCellValue('B' . $row, '-');
            $sheet->setCellValue('C' . $row, (int)$totalCount);
            $sheet->setCellValue('D' . $row, (float)$totalAmount);

            // 设置样式
            $this->setExcelStyles($sheet, $row);

            // 输出文件
            $writer = new Xlsx($spreadsheet);
            $filename = $year . '年税务代缴汇总表.xlsx';
            
            // 输出到临时文件
            $tempFile = tempnam(sys_get_temp_dir(), 'tax_summary_');
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
     * 设置Excel样式
     */
    private function setExcelStyles($sheet, $lastRow): void
    {
        // 标题样式
        $sheet->getStyle('A1:E1')->applyFromArray([
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
        $sheet->getStyle('A3:E3')->applyFromArray([
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
        $sheet->getStyle('A4:E' . ($lastRow - 1))->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        // 合计行样式
        $sheet->getStyle('A' . $lastRow . ':E' . $lastRow)->applyFromArray([
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
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(12);

        // 设置数字格式
        $sheet->getStyle('C4:C' . $lastRow)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('D4:D' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
    }
} 