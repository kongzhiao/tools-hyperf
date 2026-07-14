<?php

declare(strict_types=1);

namespace App\Service;

use OpenSpout\Reader\CSV\Reader;
use OpenSpout\Reader\CSV\Options;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return 'UTF-8';
        }

        // 读取前 1MB 用于编码检测。部分 CSV 表头是纯 ASCII，数据行才出现中文，
        // 采样太小容易把 GBK/GB18030 文件误判为 UTF-8。
        $content = fread($handle, 1024 * 1024);
        fclose($handle);

        if ($content === false || strlen($content) === 0) {
            return 'UTF-8';
        }

        // BOM 判断必须优先于内容探测，UTF-32 BOM 也必须先于 UTF-16 BOM。
        if (strncmp($content, "\x00\x00\xFE\xFF", 4) === 0) {
            return 'UTF-32BE';
        }
        if (strncmp($content, "\xFF\xFE\x00\x00", 4) === 0) {
            return 'UTF-32LE';
        }
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            return 'UTF-8';
        }
        if (strncmp($content, "\xFF\xFE", 2) === 0) {
            return 'UTF-16LE';
        }
        if (strncmp($content, "\xFE\xFF", 2) === 0) {
            return 'UTF-16BE';
        }

        // 某些系统导出的 UTF-16 CSV 没有 BOM。利用 ASCII 表头、分隔符和换行
        // 在 UTF-16 字节流中会产生规律 NUL 字节的特征做兜底判断。
        $utf16Encoding = $this->detectUtf16WithoutBom($content);
        if ($utf16Encoding !== null) {
            return $utf16Encoding;
        }

        // 2. 只有当内容完全符合 UTF-8 规范时，才判定为 UTF-8
        // mb_check_encoding 是检查字节流是否符合特定编码的最可靠方式
        if (mb_check_encoding($content, 'UTF-8')) {
            // 额外检查：如果是 ASCII 排列（没有高位字节），也是合法的 UTF-8
            return 'UTF-8';
        }

        // 3. 如果整段不是 UTF-8，优先根据表头判断中文代码页。
        // 实际政务 CSV 偶尔包含少量 CP936 扩展字节，导致整段严格校验失败；
        // 此时若回退为 UTF-8，像“住院”的 GBK 字节 D7 A1 D4 BA 又碰巧是
        // 合法 UTF-8，会被保留成其他 Unicode 字符。表头是稳定且可信的判据。
        $firstLineLength = strcspn($content, "\r\n");
        $headerSample = substr($content, 0, max(1, $firstLineLength));
        if (mb_check_encoding($headerSample, 'GB18030')) {
            return 'GB18030';
        }
        if (mb_check_encoding($headerSample, 'BIG5')) {
            return 'BIG5';
        }

        // 表头无法判定时再尝试整段严格校验。
        if (mb_check_encoding($content, 'GB18030')) {
            return 'GB18030';
        }
        if (mb_check_encoding($content, 'BIG5')) {
            return 'BIG5';
        }

        // 4. 兜底策略：使用探测函数
        $detected = mb_detect_encoding($content, ['UTF-8', 'GB18030', 'GBK', 'GB2312', 'BIG5'], true);
        if ($detected !== false) {
            return $detected;
        }

        throw new \RuntimeException('无法识别 CSV 字符集，请使用 UTF-8、GB18030/GBK、BIG5 或 UTF-16 编码');
    }

    private function detectUtf16WithoutBom(string $content): ?string
    {
        $length = strlen($content);
        if ($length < 4) {
            return null;
        }

        $sampleLength = min($length, 8192);
        $evenNulls = 0;
        $oddNulls = 0;
        for ($index = 0; $index < $sampleLength; $index++) {
            if ($content[$index] !== "\0") {
                continue;
            }
            if ($index % 2 === 0) {
                $evenNulls++;
            } else {
                $oddNulls++;
            }
        }

        $pairCount = max(1, intdiv($sampleLength, 2));
        $evenRatio = $evenNulls / $pairCount;
        $oddRatio = $oddNulls / $pairCount;
        if ($oddRatio >= 0.2 && $oddRatio >= $evenRatio * 3) {
            return 'UTF-16LE';
        }
        if ($evenRatio >= 0.2 && $evenRatio >= $oddRatio * 3) {
            return 'UTF-16BE';
        }

        return null;
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

        $inputHandle = fopen($filePath, 'rb');
        $outputHandle = fopen($tempPath, 'wb');

        if (!$inputHandle || !$outputHandle) {
            if (is_resource($inputHandle)) {
                fclose($inputHandle);
            }
            if (is_resource($outputHandle)) {
                fclose($outputHandle);
            }
            @unlink($tempPath);
            return $filePath;
        }

        // 写入 UTF-8 BOM
        fwrite($outputHandle, "\xEF\xBB\xBF");

        try {
            if (in_array($encoding, ['UTF-16LE', 'UTF-16BE', 'UTF-32LE', 'UTF-32BE'], true)) {
                $this->convertFixedWidthUnicodeStream($inputHandle, $outputHandle, $encoding);
            } else {
                // GB18030/GBK 的多字节字符不会跨越换行符，逐行转换可避免大文件进内存。
                while (($line = fgets($inputHandle)) !== false) {
                    $utf8Line = $this->convertStringToUtf8($line, $encoding);
                    if (fwrite($outputHandle, $utf8Line) === false) {
                        throw new \RuntimeException('CSV 临时转码文件写入失败');
                    }
                }
            }
        } catch (\Throwable $throwable) {
            fclose($inputHandle);
            fclose($outputHandle);
            @unlink($tempPath);
            throw $throwable;
        }

        fclose($inputHandle);
        fclose($outputHandle);

        return $tempPath;
    }

    /**
     * UTF-16/32 的换行符本身包含 NUL 字节，不能用 fgets() 切行。
     * 这里按完整代码单元分块，避免半个字符落到下一块导致乱码。
     *
     * @param resource $inputHandle
     * @param resource $outputHandle
     */
    private function convertFixedWidthUnicodeStream($inputHandle, $outputHandle, string $encoding): void
    {
        $unitSize = strpos($encoding, 'UTF-32') === 0 ? 4 : 2;
        $buffer = '';

        while (!feof($inputHandle)) {
            $chunk = fread($inputHandle, 8192);
            if ($chunk === false) {
                throw new \RuntimeException('CSV 原始文件读取失败');
            }
            $buffer .= $chunk;
            $processLength = strlen($buffer) - (strlen($buffer) % $unitSize);

            // UTF-16 代理对必须作为一个整体交给 mbstring。
            if ($unitSize === 2 && $processLength >= 2) {
                $lastUnit = substr($buffer, $processLength - 2, 2);
                $codeUnit = $encoding === 'UTF-16LE'
                    ? unpack('v', $lastUnit)[1]
                    : unpack('n', $lastUnit)[1];
                if ($codeUnit >= 0xD800 && $codeUnit <= 0xDBFF) {
                    $processLength -= 2;
                }
            }

            if ($processLength === 0) {
                continue;
            }
            $source = substr($buffer, 0, $processLength);
            $buffer = substr($buffer, $processLength);
            $converted = $this->convertStringToUtf8($source, $encoding);
            if (fwrite($outputHandle, $converted) === false) {
                throw new \RuntimeException('CSV 临时转码文件写入失败');
            }
        }

        if ($buffer !== '') {
            throw new \RuntimeException(sprintf('CSV 包含不完整的 %s 字符', $encoding));
        }
    }

    /**
     * 将任意 CSV 单元格规范为合法 UTF-8 文本。
     */
    private function normalizeCellValue(mixed $cell): string
    {
        $value = is_string($cell) ? $cell : (string) $cell;
        $value = $this->convertStringToUtf8($value);

        // 去除不可见字符，包括 UTF-8 BOM 和零宽空格。
        $cleaned = preg_replace('/[\x{FEFF}\x{200B}]/u', '', $value);
        if ($cleaned === null) {
            $cleaned = str_replace(["\xEF\xBB\xBF", "\xE2\x80\x8B"], '', $value);
        }

        return trim($cleaned);
    }

    /**
     * 转换字符串编码。GBK 文件里偶尔会混入扩展字符，优先按 GB18030/GBK 兼容处理。
     */
    private function convertStringToUtf8(string $value, ?string $encoding = null): string
    {
        if ($value === '') {
            return '';
        }

        if ($encoding === null || $encoding === '') {
            if (mb_check_encoding($value, 'UTF-8')) {
                return $value;
            }
            $encoding = mb_detect_encoding(
                $value,
                ['UTF-16LE', 'UTF-16BE', 'GB18030', 'GBK', 'GB2312', 'BIG5'],
                true
            ) ?: 'GB18030';
        }

        if (strtoupper($encoding) === 'UTF-8') {
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new \RuntimeException('CSV 包含无效的 UTF-8 字节');
            }
            return $value;
        }

        $converted = mb_convert_encoding($value, 'UTF-8', $encoding);
        if (!mb_check_encoding($converted, 'UTF-8')) {
            throw new \RuntimeException(sprintf('CSV 从 %s 转换为 UTF-8 失败', $encoding));
        }

        return $converted;
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
        if ($this->isSpreadsheetFile($filePath)) {
            return $this->readSpreadsheet($filePath, $callback, $hasHeader, $progressCallback);
        }

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
                    $headers = array_map(fn ($cell) => $this->normalizeCellValue($cell), $rowData);
                    $rowIndex++;
                    continue;
                }

                try {
                    // 如果有表头，将数据转换为关联数组
                    if ($hasHeader && !empty($headers)) {
                        $assocRow = [];
                        foreach ($headers as $index => $header) {
                            $assocRow[$header] = $this->normalizeCellValue($rowData[$index] ?? '');
                        }
                        $callback($assocRow, $rowIndex, $headers);
                    } else {
                        $callback(array_map(fn ($cell) => $this->normalizeCellValue($cell), $rowData), $rowIndex, []);
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
        if ($this->isSpreadsheetFile($filePath)) {
            return $this->readSpreadsheetInBatches(
                $filePath,
                $batchSize,
                $batchCallback,
                $hasHeader,
                $progressCallback
            );
        }

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
                    $headers = array_map(fn ($cell) => $this->normalizeCellValue($cell), $rowData);
                    $rowIndex++;
                    continue;
                }

                // 构建数据行
                if ($hasHeader && !empty($headers)) {
                    $assocRow = [];
                    foreach ($headers as $index => $header) {
                        $assocRow[$header] = $this->normalizeCellValue($rowData[$index] ?? '');
                    }
                    $batch[] = ['data' => $assocRow, 'row' => $rowIndex + 1];
                } else {
                    $batch[] = ['data' => array_map(fn ($cell) => $this->normalizeCellValue($cell), $rowData), 'row' => $rowIndex + 1];
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
        if ($this->isSpreadsheetFile($filePath)) {
            $spreadsheet = IOFactory::load($filePath);
            try {
                $row = $spreadsheet->getActiveSheet()->rangeToArray(
                    'A1:' . $spreadsheet->getActiveSheet()->getHighestColumn() . '1',
                    null,
                    true,
                    true,
                    false
                )[0] ?? [];
                return array_map(fn ($cell) => $this->normalizeCellValue($cell), $row);
            } finally {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            }
        }

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
                $headers = array_map(fn ($cell) => $this->normalizeCellValue($cell), $row->toArray());
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
        if ($this->isSpreadsheetFile($filePath)) {
            $spreadsheet = IOFactory::load($filePath);
            try {
                return $spreadsheet->getActiveSheet()->getHighestDataRow();
            } finally {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            }
        }

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

    private function isSpreadsheetFile(string $filePath): bool
    {
        return in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true);
    }

    private function readSpreadsheet(
        string $filePath,
        callable $callback,
        bool $hasHeader,
        ?callable $progressCallback
    ): array {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $headers = [];
        $processedCount = 0;
        $errorCount = 0;
        $errors = [];

        try {
            for ($rowNumber = 1; $rowNumber <= $highestRow; $rowNumber++) {
                $rowData = $sheet->rangeToArray(
                    'A' . $rowNumber . ':' . $highestColumn . $rowNumber,
                    null,
                    true,
                    true,
                    false
                )[0] ?? [];
                if (empty(array_filter($rowData, static fn ($cell) => $cell !== null && $cell !== ''))) {
                    continue;
                }

                if ($hasHeader && $rowNumber === 1) {
                    $headers = array_map(fn ($cell) => $this->normalizeCellValue($cell), $rowData);
                    continue;
                }

                try {
                    $normalizedRow = array_map(fn ($cell) => $this->normalizeCellValue($cell), $rowData);
                    if ($hasHeader && $headers !== []) {
                        $normalizedValues = array_slice(
                            array_pad($normalizedRow, count($headers), ''),
                            0,
                            count($headers)
                        );
                        $normalizedRow = array_combine(
                            $headers,
                            $normalizedValues
                        );
                    }
                    $callback($normalizedRow, $rowNumber - 1, $headers);
                    $processedCount++;
                } catch (\Throwable $throwable) {
                    $errorCount++;
                    $errors[] = ['row' => $rowNumber, 'error' => $throwable->getMessage()];
                }

                if ($progressCallback !== null && $rowNumber % 100 === 0) {
                    $progressCallback($rowNumber, $highestRow);
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return [
            'total_rows' => max(0, $highestRow - ($hasHeader ? 1 : 0)),
            'processed_count' => $processedCount,
            'error_count' => $errorCount,
            'errors' => $errors,
        ];
    }

    private function readSpreadsheetInBatches(
        string $filePath,
        int $batchSize,
        callable $batchCallback,
        bool $hasHeader,
        ?callable $progressCallback
    ): array {
        $batch = [];
        $batchIndex = 0;
        $processedCount = 0;
        $batchErrorCount = 0;
        $errors = [];

        $result = $this->readSpreadsheet(
            $filePath,
            function (array $row, int $rowIndex) use (
                &$batch,
                &$batchIndex,
                &$processedCount,
                &$batchErrorCount,
                &$errors,
                $batchSize,
                $batchCallback
            ): void {
                $batch[] = ['data' => $row, 'row' => $rowIndex + 1];
                if (count($batch) < $batchSize) {
                    return;
                }
                try {
                    $batchCallback($batch, $batchIndex);
                    $processedCount += count($batch);
                } catch (\Throwable $throwable) {
                    $batchErrorCount += count($batch);
                    $errors[] = ['batch' => $batchIndex, 'error' => $throwable->getMessage()];
                }
                $batch = [];
                $batchIndex++;
            },
            $hasHeader,
            $progressCallback
        );

        if ($batch !== []) {
            try {
                $batchCallback($batch, $batchIndex);
                $processedCount += count($batch);
            } catch (\Throwable $throwable) {
                $batchErrorCount += count($batch);
                $errors[] = ['batch' => $batchIndex, 'error' => $throwable->getMessage()];
            }
        }

        return [
            'total_rows' => $result['total_rows'],
            'processed_count' => $processedCount,
            'error_count' => min($result['total_rows'], $batchErrorCount),
            'errors' => array_merge($result['errors'], $errors),
        ];
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
