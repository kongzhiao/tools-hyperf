<?php

declare(strict_types=1);

namespace App\Service;

use OpenSpout\Reader\CSV\Reader;
use OpenSpout\Reader\CSV\Options;

/**
 * CSV 读取服务
 * 使用 OpenSpout 进行流式读取，适用于大文件处理
 * 支持 UTF-8 和 GBK 编码自动检测
 */
class CsvReaderService
{
    /**
     * 检测文件编码
     * 
     * @param string $filePath 文件路径
     * @return string 检测到的编码 (UTF-8 或 GBK)
     */
    private function detectEncoding(string $filePath): string
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return 'UTF-8';
        }

        // 读取前 8000 字节用于编码检测
        $content = fread($handle, 8000);
        fclose($handle);

        if ($content === false || strlen($content) === 0) {
            return 'UTF-8';
        }

        // 1. 检查 UTF-8 BOM (EF BB BF)
        if (
            strlen($content) >= 3 &&
            ord($content[0]) === 0xEF &&
            ord($content[1]) === 0xBB &&
            ord($content[2]) === 0xBF
        ) {
            return 'UTF-8';
        }

        // 2. 只有当内容完全符合 UTF-8 规范时，才判定为 UTF-8
        // mb_check_encoding 是检查字节流是否符合特定编码的最可靠方式
        if (mb_check_encoding($content, 'UTF-8')) {
            // 额外检查：如果是 ASCII 排列（没有高位字节），也是合法的 UTF-8
            return 'UTF-8';
        }

        // 3. 如果不是 UTF-8，再检测是否为 GBK
        if (mb_check_encoding($content, 'GBK')) {
            return 'GBK';
        }

        // 4. 兜底策略：使用探测函数
        $detected = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
        return $detected ?: 'UTF-8';
    }


    /**
     * 将文件内容从 GBK 转换为 UTF-8
     * 
     * @param string $filePath 原文件路径
     * @return string 转换后的临时文件路径
     */
    private function convertToUtf8(string $filePath): string
    {
        $encoding = $this->detectEncoding($filePath);

        // 如果已经是 UTF-8，直接返回原路径
        if ($encoding === 'UTF-8') {
            return $filePath;
        }

        // 创建临时文件
        $tempPath = sys_get_temp_dir() . '/' . uniqid('csv_utf8_') . '.csv';

        $inputHandle = fopen($filePath, 'r');
        $outputHandle = fopen($tempPath, 'w');

        if (!$inputHandle || !$outputHandle) {
            return $filePath;
        }

        // 写入 UTF-8 BOM
        fwrite($outputHandle, "\xEF\xBB\xBF");

        // 逐行读取并转换编码
        while (($line = fgets($inputHandle)) !== false) {
            $utf8Line = mb_convert_encoding($line, 'UTF-8', $encoding);
            fwrite($outputHandle, $utf8Line);
        }

        fclose($inputHandle);
        fclose($outputHandle);

        return $tempPath;
    }

    /**
     * 读取 CSV 文件并逐行处理
     * 
     * @param string $filePath CSV 文件路径
     * @param callable $callback 行处理回调函数，参数为 (array $row, int $rowIndex, array $headers)
     * @param bool $hasHeader 是否有表头行，默认为 true
     * @param callable|null $progressCallback 进度回调，参数为 (int $processedRows, int $totalRows)
     * @return array 处理结果统计
     */
    public function read(
        string $filePath,
        callable $callback,
        bool $hasHeader = true,
        ?callable $progressCallback = null
    ): array {
        // 自动检测编码并转换为 UTF-8
        $actualPath = $this->convertToUtf8($filePath);
        $isTemp = ($actualPath !== $filePath);

        $options = new Options();
        $options->FIELD_DELIMITER = ',';
        $options->FIELD_ENCLOSURE = '"';
        $options->ENCODING = 'UTF-8';

        $reader = new Reader($options);
        $reader->open($actualPath);

        $headers = [];
        $rowIndex = 0;
        $processedCount = 0;
        $errorCount = 0;
        $errors = [];

        // 只有使用进度回调时才额外统计总行数，避免大文件被重复完整扫描。
        $totalRows = $progressCallback !== null ? $this->countRows($actualPath) : 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowData = $row->toArray();

                // 跳过空行
                if (
                    empty(array_filter($rowData, function ($cell) {
                        return $cell !== null && $cell !== '';
                    }))
                ) {
                    continue;
                }

                // 处理表头
                if ($hasHeader && $rowIndex === 0) {
                    $headers = array_map(function ($cell) {
                        $value = is_string($cell) ? $cell : (string) $cell;
                        // 去除不可见字符，包括 BOM
                        $value = preg_replace('/[\x{FEFF}\x{200B}]/u', '', $value);
                        return trim($value);
                    }, $rowData);
                    $rowIndex++;
                    continue;
                }

                try {
                    // 如果有表头，将数据转换为关联数组
                    if ($hasHeader && !empty($headers)) {
                        $assocRow = [];
                        foreach ($headers as $index => $header) {
                            $assocRow[$header] = $rowData[$index] ?? '';
                        }
                        $callback($assocRow, $rowIndex, $headers);
                    } else {
                        $callback($rowData, $rowIndex, []);
                    }
                    $processedCount++;
                } catch (\Throwable $e) {
                    $errorCount++;
                    $errors[] = [
                        'row' => $rowIndex + 1,
                        'error' => $e->getMessage()
                    ];
                }

                $rowIndex++;

                // 进度回调
                if ($progressCallback !== null && $rowIndex % 100 === 0) {
                    $progressCallback($rowIndex, $totalRows);
                }
            }
        }

        $reader->close();

        // 清理临时文件
        if ($isTemp && file_exists($actualPath)) {
            unlink($actualPath);
        }

        return [
            'total_rows' => $rowIndex - ($hasHeader ? 1 : 0),
            'processed_count' => $processedCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ];
    }


    /**
     * 批量读取 CSV 文件
     * 
     * @param string $filePath CSV 文件路径
     * @param int $batchSize 每批处理的行数
     * @param callable $batchCallback 批次处理回调，参数为 (array $batch, int $batchIndex)
     * @param bool $hasHeader 是否有表头行
     * @param callable|null $progressCallback 进度回调
     * @return array 处理结果统计
     */
    public function readInBatches(
        string $filePath,
        int $batchSize,
        callable $batchCallback,
        bool $hasHeader = true,
        ?callable $progressCallback = null
    ): array {
        // 自动检测编码并转换为 UTF-8
        $actualPath = $this->convertToUtf8($filePath);
        $isTemp = ($actualPath !== $filePath);

        $options = new Options();
        $options->FIELD_DELIMITER = ',';
        $options->FIELD_ENCLOSURE = '"';
        $options->ENCODING = 'UTF-8';

        $reader = new Reader($options);
        $reader->open($actualPath);

        $headers = [];
        $rowIndex = 0;
        $batch = [];
        $batchIndex = 0;
        $processedCount = 0;
        $errorCount = 0;
        $errors = [];

        $totalRows = $this->countRows($actualPath);

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowData = $row->toArray();

                // 跳过空行
                if (
                    empty(array_filter($rowData, function ($cell) {
                        return $cell !== null && $cell !== '';
                    }))
                ) {
                    continue;
                }

                // 处理表头
                if ($hasHeader && $rowIndex === 0) {
                    $headers = array_map(function ($cell) {
                        $value = is_string($cell) ? $cell : (string) $cell;
                        // 去除不可见字符，包括 BOM
                        $value = preg_replace('/[\x{FEFF}\x{200B}]/u', '', $value);
                        return trim($value);
                    }, $rowData);
                    $rowIndex++;
                    continue;
                }

                // 构建数据行
                if ($hasHeader && !empty($headers)) {
                    $assocRow = [];
                    foreach ($headers as $index => $header) {
                        $assocRow[$header] = $rowData[$index] ?? '';
                    }
                    $batch[] = ['data' => $assocRow, 'row' => $rowIndex + 1];
                } else {
                    $batch[] = ['data' => $rowData, 'row' => $rowIndex + 1];
                }

                $rowIndex++;

                // 达到批次大小时处理
                if (count($batch) >= $batchSize) {
                    try {
                        $batchCallback($batch, $batchIndex);
                        $processedCount += count($batch);
                    } catch (\Throwable $e) {
                        $errorCount += count($batch);
                        $errors[] = [
                            'batch' => $batchIndex,
                            'error' => $e->getMessage()
                        ];
                    }

                    $batch = [];
                    $batchIndex++;

                    // 进度回调
                    if ($progressCallback !== null) {
                        $progressCallback($rowIndex, $totalRows);
                    }
                }
            }
        }

        // 处理最后一批
        if (!empty($batch)) {
            try {
                $batchCallback($batch, $batchIndex);
                $processedCount += count($batch);
            } catch (\Throwable $e) {
                $errorCount += count($batch);
                $errors[] = [
                    'batch' => $batchIndex,
                    'error' => $e->getMessage()
                ];
            }
        }

        $reader->close();

        // 清理临时文件
        if ($isTemp && file_exists($actualPath)) {
            unlink($actualPath);
        }

        return [
            'total_rows' => $rowIndex - ($hasHeader ? 1 : 0),
            'processed_count' => $processedCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ];
    }

    /**
     * 检测 CSV 分隔符
     * 
     * @param string $filePath 文件路径
     * @return string 分隔符
     */
    private function detectDelimiter(string $filePath): string
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ',';
        }

        $firstLine = fgets($handle);
        fclose($handle);

        if (!$firstLine) {
            return ',';
        }

        $delimiters = [',', ';', "\t"];
        $counts = [];
        foreach ($delimiters as $delimiter) {
            $counts[$delimiter] = substr_count($firstLine, $delimiter);
        }

        arsort($counts);
        $bestDelimiter = key($counts);

        return $counts[$bestDelimiter] > 0 ? $bestDelimiter : ',';
    }

    /**
     * 获取 CSV 文件的表头
     * 
     * @param string $filePath CSV 文件路径
     * @return array 表头数组
     */
    public function getHeaders(string $filePath): array
    {
        // 自动检测编码并转换为 UTF-8
        $actualPath = $this->convertToUtf8($filePath);
        $isTemp = ($actualPath !== $filePath);

        $delimiter = $this->detectDelimiter($actualPath);

        $options = new Options();
        $options->FIELD_DELIMITER = $delimiter;
        $options->FIELD_ENCLOSURE = '"';
        $options->ENCODING = 'UTF-8';

        $reader = new Reader($options);
        $reader->open($actualPath);

        $headers = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $headers = array_map(function ($cell) {
                    $value = is_string($cell) ? $cell : (string) $cell;
                    // 去除不可见字符，包括 BOM
                    $value = preg_replace('/[\x{FEFF}\x{200B}]/u', '', $value);
                    return trim($value);
                }, $row->toArray());
                break;
            }
            break;
        }

        $reader->close();

        // 清理临时文件
        if ($isTemp && file_exists($actualPath)) {
            unlink($actualPath);
        }

        return $headers;
    }

    /**
     * 统计 CSV 文件行数
     * 
     * @param string $filePath CSV 文件路径
     * @return int 行数
     */
    public function countRows(string $filePath): int
    {
        $count = 0;
        $handle = fopen($filePath, 'r');

        if ($handle) {
            while (fgets($handle) !== false) {
                $count++;
            }
            fclose($handle);
        }

        return $count;
    }

    /**
     * 解析金额字段
     * 
     * @param mixed $value 原始值
     * @return float 解析后的金额
     */
    public static function parseAmount($value): float
    {
        if (empty($value)) {
            return 0.0;
        }
        $value = str_replace(['¥', '￥', ' ', ','], '', (string) $value);
        return round((float) $value, 2);
    }
}
