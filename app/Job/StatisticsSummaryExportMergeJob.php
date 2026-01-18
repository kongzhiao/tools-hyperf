<?php

declare(strict_types=1);

namespace App\Job;

use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use App\Model\Task;

class StatisticsSummaryExportMergeJob extends AbstractJob
{
    /**
     * @var string Task UUID
     */
    public $uuid;

    public function __construct(string $uuid)
    {
        $this->uuid = $uuid;
    }

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');
        try {
            $redis = $container->get(\Hyperf\Redis\Redis::class);
            $filesKey = "export:{$this->uuid}:chunks_files";
            $files = $redis->lrange($filesKey, 0, -1);
            if (empty($files)) {
                throw new \RuntimeException('No chunk files found for merge');
            }
            $finalSpreadsheet = new Spreadsheet();
            $sheetIndex = 0;

            // Group files by sheet name
            $groupedFiles = [];
            foreach ($files as $filename) {
                if (preg_match('/^chunk_(.+)_(\d+)\.csv$/', $filename, $matches)) {
                    $sheetName = $matches[1];
                    $sequence = (int) $matches[2];
                    $groupedFiles[$sheetName][$sequence] = $filename;
                }
            }

            foreach ($groupedFiles as $sheetName => $chunks) {
                ksort($chunks); // Sort by sequence

                if ($sheetIndex === 0) {
                    $finalSheet = $finalSpreadsheet->getActiveSheet();
                } else {
                    $finalSheet = $finalSpreadsheet->createSheet();
                }
                $finalSheet->setTitle($sheetName);

                $finalSheet->setCellValue('A1', $sheetName . '明细统计');
                $finalSheet->mergeCells('A1:AG1');
                // The headers will be included in the first chunk of each sheet (sequence 0)

                $currentRow = 3; // Start after title
                foreach ($chunks as $sequence => $filename) {
                    $fullPath = BASE_PATH . '/runtime/export/chunks/' . $filename;
                    if (!file_exists($fullPath))
                        continue;

                    if (($handle = fopen($fullPath, "r")) !== FALSE) {
                        // Skip BOM if present
                        $bom = fread($handle, 3);
                        if ($bom != "\xEF\xBB\xBF") {
                            rewind($handle);
                        }

                        while (($row = fgetcsv($handle, 0, ",")) !== FALSE) {
                            $finalSheet->fromArray($row, null, 'A' . $currentRow);
                            $currentRow++;
                        }
                        fclose($handle);
                    }
                }
                // Apply styles to this sheet
                $this->applySheetStyles($finalSheet, $currentRow - 1);
                $sheetIndex++;
            }

            // Save final file
            $runtimePath = BASE_PATH . '/runtime/export/';
            if (!is_dir($runtimePath)) {
                mkdir($runtimePath, 0777, true);
            }
            $filename = '统计明细_导出_' . date('Y-m-d_H:i:s') . '_' . $this->uuid . '.xlsx';
            $fullPath = $runtimePath . $filename;
            $writer = new Xlsx($finalSpreadsheet);
            $writer->save($fullPath);

            // Update task as completed
            $fileSizeMb = round(filesize($fullPath) / (1024 * 1024), 1);
            Task::where('uuid', $this->uuid)->update([
                'progress' => 100.00,
                'download_url' => '/runtime/export/' . $filename,
                'url_at' => date('Y-m-d H:i:s'),
                'file_size' => $fileSizeMb,
            ]);

            $logger->info("Task {$this->uuid} Export Merge Success: {$fullPath} (Size: {$fileSizeMb}MB)");

            // Cleanup
            $this->cleanup($files, $redis);
        } catch (\Throwable $e) {
            $logger->error("Task {$this->uuid} Export Merge Failed: " . $e->getMessage());
            // Mark task as failed
            Task::where('uuid', $this->uuid)->update(['progress' => -1.00]);

            // Attempt partial cleanup even on failure? 
            // Better to leave chunks for debugging if it failed, or clean up to save space?
            // Let's at least try to clean redis keys.
            if (isset($redis)) {
                $redis->del("export:{$this->uuid}:chunks_total", "export:{$this->uuid}:chunks_pending", "export:{$this->uuid}:chunks_files");
            }

            throw $e;
        }

    }

    protected function applySheetStyles($sheet, $lastRow): void
    {
        $highestColumn = $sheet->getHighestColumn();
        // Title style
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);
        // Header style
        $headerRange = 'A3:' . $highestColumn . '3';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0']
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
        // Borders
        $dataRange = 'A1:' . $highestColumn . $lastRow;
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN
                ]
            ]
        ]);
    }

    protected function cleanup(array $files, $redis): void
    {
        // Delete chunk files
        foreach ($files as $filename) {
            $fullPath = BASE_PATH . '/runtime/export/chunks/' . $filename;
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
        // Delete redis keys
        $redis->del(
            "export:{$this->uuid}:chunks_total",
            "export:{$this->uuid}:chunks_pending",
            "export:{$this->uuid}:chunks_files"
        );
    }
}
?>