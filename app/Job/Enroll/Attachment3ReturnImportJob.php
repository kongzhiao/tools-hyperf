<?php

declare(strict_types=1);

namespace App\Job\Enroll;

use App\Job\AbstractJob;
use App\Model\Enroll\EnrollImportBatch;
use App\Model\Enroll\EnrollLedger;
use App\Service\CsvReaderService;
use App\Service\Enroll\EnrollLedgerService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;

class Attachment3ReturnImportJob extends AbstractJob
{
    private string $tempFile;

    public function __construct(array $params, string $uuid, string $tempFile)
    {
        parent::__construct($params, $uuid);
        $this->tempFile = $tempFile;
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');
        $service = $container->get(EnrollLedgerService::class);
        $batch = null;

        try {
            $this->startTask();
            if (!file_exists($this->tempFile)) {
                throw new \RuntimeException('导入文件不存在');
            }

            $year = (int) ($this->params['year'] ?? 0);
            $period = $service->normalizePeriod((string) ($this->params['period'] ?? ''));
            if ($year <= 0 || $period === '') {
                throw new \RuntimeException('年份或月份不正确');
            }

            $batch = EnrollImportBatch::create([
                'uuid' => $this->uuid,
                'year' => $year,
                'period' => $period,
                'attachment_type' => 'attachment3_return',
                'file_name' => $this->params['source_file'] ?? '',
                'status' => EnrollImportBatch::STATUS_RUNNING,
                'created_by' => (int) ($this->params['created_by'] ?? 0),
            ]);

            $csvReader = new CsvReaderService();
            $this->assertRequiredHeaders($csvReader->getHeaders($this->tempFile));
            $totalCount = max($csvReader->countRows($this->tempFile) - 1, 1);
            $processed = 0;
            $result = ['matched' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
            $pendingRows = [];

            $logger->info('Enroll attachment3 return import start.', [
                'uuid' => $this->uuid,
                'year' => $year,
                'period' => $period,
                'total_count' => $totalCount,
                'source_file' => $this->params['source_file'] ?? '',
            ]);

            $csvReader->read($this->tempFile, function (array $row, int $rowIndex) use (
                $service,
                $year,
                &$processed,
                $totalCount,
                &$result,
                &$pendingRows
            ) {
                $processed++;
                try {
                    $idCard = $service->pickValue($row, ['身份证号码', '身份证号', '公民身份号码', '身份证', 'id_card']);
                    if ($idCard === '') {
                        $result['skipped']++;
                        if (count($result['errors']) < 30) {
                            $result['errors'][] = '第' . ($rowIndex + 1) . '行: 身份证号码为空';
                        }
                        return;
                    }

                    $data = [];
                    foreach ([
                        'payment_time' => ['缴费时间', '缴费日期', 'payment_time'],
                        'uninsured_reason' => ['未参保原因', 'uninsured_reason'],
                        'insurance_place_remark' => ['资助地或参保地（区外备注）', '区外备注', 'insurance_place_remark'],
                        'death_remark' => ['备注（卫健委死亡时间）', '死亡备注', 'death_remark'],
                        'manual_remark' => ['人工备注', 'manual_remark'],
                    ] as $field => $headers) {
                        $picked = $this->pickOptionalValue($row, $headers);
                        if (!$picked['exists']) {
                            continue;
                        }
                        $value = (string) $picked['value'];
                        if ($field === 'payment_time') {
                            $value = $service->normalizeDateText($value);
                        }
                        $data[$field] = $this->nullable($value);
                    }

                    if ($data === []) {
                        $result['skipped']++;
                        return;
                    }

                    $pendingRows[$idCard] = array_merge($pendingRows[$idCard] ?? [], $data);

                    if (count($pendingRows) >= 800) {
                        $this->flushRows($pendingRows, $year, $result);
                        $pendingRows = [];
                    }
                } catch (\Throwable $e) {
                    if (count($result['errors']) < 30) {
                        $result['errors'][] = '第' . ($rowIndex + 1) . '行: ' . $e->getMessage();
                    }
                }

                if ($processed % 1000 === 0) {
                    $this->updateProgress($this->uuid, min(95, ($processed / $totalCount) * 95));
                }
            });

            $this->flushRows($pendingRows, $year, $result);
            if ($result['updated'] <= 0) {
                throw new \RuntimeException('未匹配到可更新的参保台账记录，请确认年份和身份证号码是否正确');
            }

            $batch->update([
                'total_rows' => $processed,
                'success_rows' => $result['updated'],
                'failed_rows' => count($result['errors']),
                'status' => EnrollImportBatch::STATUS_COMPLETED,
                'message' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ]);

            $logger->info('Enroll attachment3 return import success.', [
                'uuid' => $this->uuid,
                'year' => $year,
                'period' => $period,
                'result' => $result,
            ]);
            $this->finishTask('', 0);
        } catch (\Throwable $e) {
            if ($batch) {
                $batch->update([
                    'status' => EnrollImportBatch::STATUS_FAILED,
                    'message' => $e->getMessage(),
                ]);
            }
            $logger->error('Enroll attachment3 return import failed: ' . $e->getMessage(), [
                'uuid' => $this->uuid,
                'params' => $this->params,
            ]);
            $this->failTask($e, '参保台账附件3回导失败');
        } finally {
            if (file_exists($this->tempFile)) {
                @unlink($this->tempFile);
            }
        }
    }

    private function assertRequiredHeaders(array $headers): void
    {
        $normalizedHeaders = array_map(fn ($header) => $this->normalizeHeader((string) $header), $headers);
        if (!in_array($this->normalizeHeader('身份证号码'), $normalizedHeaders, true) && !in_array($this->normalizeHeader('身份证号'), $normalizedHeaders, true)) {
            throw new \RuntimeException('文件格式不正确，缺少表头：身份证号码');
        }
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/[\x{FEFF}\x{200B}]/u', '', $header) ?? $header;
        $header = preg_replace('/\s+/u', '', $header) ?? $header;
        return trim($header);
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function pickOptionalValue(array $row, array $headers): array
    {
        foreach ($headers as $header) {
            if (array_key_exists($header, $row)) {
                return ['exists' => true, 'value' => trim((string) $row[$header])];
            }
        }

        return ['exists' => false, 'value' => ''];
    }

    private function flushRows(array $rows, int $year, array &$result): void
    {
        if ($rows === []) {
            return;
        }

        $existingRows = EnrollLedger::query()
            ->where('year', $year)
            ->whereIn('id_card', array_keys($rows))
            ->get();
        $existingMap = [];
        foreach ($existingRows as $record) {
            $existingMap[(string) $record->id_card] = (int) $record->id;
        }

        $updateRows = [];
        foreach ($rows as $idCard => $data) {
            $id = $existingMap[$idCard] ?? 0;
            if ($id <= 0) {
                $result['skipped']++;
                continue;
            }
            $result['matched']++;
            $updateRows[] = ['id' => $id, 'data' => $data];
        }

        if ($updateRows === []) {
            return;
        }

        Db::beginTransaction();
        try {
            foreach (array_chunk($updateRows, 300) as $chunk) {
                $this->batchUpdate($chunk);
                $result['updated'] += count($chunk);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    private function batchUpdate(array $rows): void
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row['data']) as $column) {
                $columns[$column] = true;
            }
        }

        $bindings = [];
        $sets = [];
        foreach (array_keys($columns) as $column) {
            $caseSql = "`{$column}` = CASE `id`";
            foreach ($rows as $row) {
                if (!array_key_exists($column, $row['data'])) {
                    continue;
                }
                $caseSql .= ' WHEN ? THEN ?';
                $bindings[] = $row['id'];
                $bindings[] = $row['data'][$column];
            }
            $caseSql .= " ELSE `{$column}` END";
            $sets[] = $caseSql;
        }
        $sets[] = '`updated_at` = ?';
        $bindings[] = date('Y-m-d H:i:s');

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $bindings = array_merge($bindings, $ids);
        Db::update('UPDATE `enroll_ledgers` SET ' . implode(', ', $sets) . " WHERE `id` IN ({$placeholders})", $bindings);
    }
}
