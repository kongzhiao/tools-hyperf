<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Service\CsvReaderService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class CsvReaderServiceTest extends TestCase
{
    /**
     * @dataProvider csvEncodingProvider
     */
    public function testReadsCommonCsvEncodings(string $encoding, string $bom): void
    {
        $source = "医保分类,姓名\n住院,张三\n门诊,李四\n";
        $contents = $encoding === 'UTF-8'
            ? $bom . $source
            : $bom . mb_convert_encoding($source, $encoding, 'UTF-8');
        $path = $this->writeTemporaryFile($contents, '.csv');

        try {
            $rows = $this->readRows($path);
            self::assertSame('住院', $rows[0]['医保分类']);
            self::assertSame('门诊', $rows[1]['医保分类']);
            self::assertSame('张三', $rows[0]['姓名']);
        } finally {
            @unlink($path);
        }
    }

    public static function csvEncodingProvider(): array
    {
        return [
            'utf8' => ['UTF-8', ''],
            'utf8-bom' => ['UTF-8', "\xEF\xBB\xBF"],
            'gb18030' => ['GB18030', ''],
            'utf16le-bom' => ['UTF-16LE', "\xFF\xFE"],
            'utf16be-bom' => ['UTF-16BE', "\xFE\xFF"],
            'utf16le-no-bom' => ['UTF-16LE', ''],
            'utf16be-no-bom' => ['UTF-16BE', ''],
        ];
    }

    /**
     * @dataProvider delimiterProvider
     */
    public function testDetectsCsvDelimiter(string $delimiter): void
    {
        $path = $this->writeTemporaryFile(
            "医保分类{$delimiter}姓名\n住院{$delimiter}张三\n",
            '.csv'
        );

        try {
            $rows = $this->readRows($path);
            self::assertSame('住院', $rows[0]['医保分类']);
            self::assertSame('张三', $rows[0]['姓名']);
        } finally {
            @unlink($path);
        }
    }

    public static function delimiterProvider(): array
    {
        return [[','], [';'], ["\t"]];
    }

    public function testReadsXlsxThroughTheSameImportService(): void
    {
        $path = $this->writeTemporaryFile('', '.xlsx');
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['医保分类', '姓名'],
            ['住院', '张三'],
        ]);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $rows = $this->readRows($path);
            self::assertSame('住院', $rows[0]['医保分类']);
            self::assertSame('张三', $rows[0]['姓名']);
        } finally {
            @unlink($path);
        }
    }

    public function testReadsUtf16CsvInBatches(): void
    {
        $source = "医保分类,姓名\n住院,张三\n门诊,李四\n";
        $path = $this->writeTemporaryFile(
            "\xFF\xFE" . mb_convert_encoding($source, 'UTF-16LE', 'UTF-8'),
            '.csv'
        );
        $rows = [];

        try {
            $result = (new CsvReaderService())->readInBatches(
                $path,
                1,
                static function (array $batch) use (&$rows): void {
                    $rows[] = $batch[0]['data'];
                }
            );
            self::assertSame(2, $result['processed_count']);
            self::assertSame('住院', $rows[0]['医保分类']);
            self::assertSame('门诊', $rows[1]['医保分类']);
        } finally {
            @unlink($path);
        }
    }

    public function testGbkCellThatAlsoLooksLikeUtf8IsStillConvertedAsGbk(): void
    {
        $contents = mb_convert_encoding(
            "医保分类,姓名\n住院,张三\n门诊,扩展",
            'GB18030',
            'UTF-8'
        ) . "\x80\n";
        self::assertFalse(mb_check_encoding($contents, 'GB18030'));
        $path = $this->writeTemporaryFile($contents, '.csv');

        try {
            $rows = $this->readRows($path);
            self::assertSame('住院', $rows[0]['医保分类']);
            self::assertSame('张三', $rows[0]['姓名']);
        } finally {
            @unlink($path);
        }
    }

    private function readRows(string $path): array
    {
        $rows = [];
        (new CsvReaderService())->read(
            $path,
            static function (array $row) use (&$rows): void {
                $rows[] = $row;
            }
        );
        return $rows;
    }

    private function writeTemporaryFile(string $contents, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv_reader_test_') . $extension;
        file_put_contents($path, $contents);
        return $path;
    }
}
