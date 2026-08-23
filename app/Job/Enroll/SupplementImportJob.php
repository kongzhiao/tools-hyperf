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

class SupplementImportJob extends AbstractJob
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
            $attachmentType = (string) ($this->params['attachment_type'] ?? '');
            if ($year <= 0 || $period === '' || !in_array($attachmentType, ['attachment4_verify', 'attachment5_tax', 'attachment6_death'], true)) {
                throw new \RuntimeException('年份、月份或附件类型不正确');
            }

            $batch = EnrollImportBatch::create([
                'uuid' => $this->uuid,
                'year' => $year,
                'period' => $period,
                'attachment_type' => $attachmentType,
                'file_name' => $this->params['source_file'] ?? '',
                'status' => EnrollImportBatch::STATUS_RUNNING,
                'created_by' => (int) ($this->params['created_by'] ?? 0),
            ]);

            $csvReader = new CsvReaderService();
            $this->assertRequiredHeaders($csvReader->getHeaders($this->tempFile), $attachmentType);
            $totalCount = max($csvReader->countRows($this->tempFile) - 1, 1);
            $processed = 0;
            $result = ['matched' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
            $pendingRows = [];
            $chunkSize = 800;

            $logger->info('Enroll supplement import start.', [
                'uuid' => $this->uuid,
                'year' => $year,
                'period' => $period,
                'attachment_type' => $attachmentType,
                'total_count' => $totalCount,
                'source_file' => $this->params['source_file'] ?? '',
            ]);

            $csvReader->read($this->tempFile, function (array $row, int $rowIndex) use (
                $service,
                $year,
                $period,
                $attachmentType,
                &$processed,
                $totalCount,
                &$result,
                &$pendingRows,
                $chunkSize
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

                    $data = match ($attachmentType) {
                        'attachment4_verify' => $this->buildAttachment4Data($row, $period, $service),
                        'attachment5_tax' => $this->buildAttachment5Data($row, $period, $service),
                        'attachment6_death' => $this->buildAttachment6Data($row, $period, $service),
                    };

                    $pendingRows[$idCard] = $this->mergePendingData($pendingRows[$idCard] ?? [], $data, $attachmentType, $service);
                    if (count($pendingRows) >= $chunkSize) {
                        $this->flushRows($pendingRows, $year, $result, $service, $attachmentType);
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

            $this->flushRows($pendingRows, $year, $result, $service, $attachmentType);
            if ($result['updated'] <= 0) {
                throw new \RuntimeException('未匹配到可更新的参保台账记录，请确认年份、月份和身份证号码是否正确');
            }
            $batch->update([
                'total_rows' => $processed,
                'success_rows' => $result['updated'],
                'failed_rows' => count($result['errors']),
                'status' => EnrollImportBatch::STATUS_COMPLETED,
                'message' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ]);

            $logger->info('Enroll supplement import success.', [
                'uuid' => $this->uuid,
                'year' => $year,
                'period' => $period,
                'attachment_type' => $attachmentType,
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
            $logger->error('Enroll supplement import failed: ' . $e->getMessage(), [
                'uuid' => $this->uuid,
                'params' => $this->params,
            ]);
            $this->failTask($e, '参保台账补充附件导入失败');
        } finally {
            if (file_exists($this->tempFile)) {
                @unlink($this->tempFile);
            }
        }
    }

    private function assertRequiredHeaders(array $headers, string $attachmentType): void
    {
        $groups = match ($attachmentType) {
            'attachment4_verify' => [
                '身份证号码' => ['身份证号', '身份证号码', '公民身份号码', '身份证'],
                '个人缴费金额' => ['个人缴费金额', '居民医保缴费金额'],
            ],
            'attachment5_tax' => [
                '身份证号码' => ['身份证号码', '身份证号', '公民身份号码', '身份证'],
                '代缴金额' => ['代缴金额', '资助金额'],
                '代缴类别' => ['代缴类别', '获得资助身份类别', '身份类别'],
            ],
            default => [
                '身份证号码' => ['身份证号码', '身份证号', '公民身份号码', '身份证'],
                '死亡时间' => ['死亡时间', 'death_time'],
            ],
        };
        $normalizedHeaders = array_map(fn ($header) => $this->normalizeHeader((string) $header), $headers);
        $missing = [];
        foreach ($groups as $label => $aliases) {
            $found = false;
            foreach ($aliases as $alias) {
                if (in_array($this->normalizeHeader($alias), $normalizedHeaders, true)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException('文件格式不正确，缺少表头：' . implode('、', $missing));
        }
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/[\x{FEFF}\x{200B}]/u', '', $header) ?? $header;
        $header = preg_replace('/\s+/u', '', $header) ?? $header;
        return trim($header);
    }

    private function buildAttachment4Data(array $row, string $period, EnrollLedgerService $service): array
    {
        $remark = $this->buildInsurancePlaceRemark($row, $service);
        $data = [
            'resident_payment_amount' => $service->parseAmount($service->pickValue($row, ['个人缴费金额', '居民医保缴费金额', 'personal_amount'])),
            'insurance_place_remark' => $remark !== '' ? $remark : null,
            'last_attachment4_period' => $period,
        ];

        return $data;
    }

    private function buildAttachment5Data(array $row, string $period, EnrollLedgerService $service): array
    {
        $amount = $service->parseAmount($service->pickValue($row, ['代缴金额', '资助金额', 'subsidy_amount']));
        $batch = $service->pickValue($row, ['请款批次', '批次', 'tax_request_batch']);
        $data = [
            'subsidy_amount' => $amount,
            'subsidy_identity_obtained' => $service->pickValue($row, ['代缴类别', '获得资助身份类别', '特殊人员身份类别', '身份类别']) ?: null,
            'tax_request_batch' => $batch ?: null,
            'last_attachment5_period' => $period,
        ];
        if (str_contains($batch, '第一')) {
            $data['tax_first_request_amount'] = $amount;
        }

        return $data;
    }

    private function buildAttachment6Data(array $row, string $period, EnrollLedgerService $service): array
    {
        $deathTime = $service->normalizeDateText($service->pickValue($row, ['死亡时间', 'death_time']));
        if ($deathTime === '') {
            throw new \RuntimeException('死亡时间为空');
        }

        return [
            'death_remark' => $deathTime,
            'uninsured_reason' => '死亡',
            'last_attachment6_period' => $period,
        ];
    }

    private function buildInsurancePlaceRemark(array $row, EnrollLedgerService $service): string
    {
        $parts = [];
        foreach ([
            '居民医保所属区划' => $service->pickValue($row, ['居民医保所属区划']),
            '职工医保所属区划' => $service->pickValue($row, ['职工医保所属区划']),
        ] as $label => $value) {
            $value = trim($value);
            if ($value !== '' && !str_contains($value, '江津')) {
                $parts[] = $label . '：' . $value;
            }
        }

        return implode('；', $parts);
    }

    private function mergePendingData(array $old, array $new, string $attachmentType, EnrollLedgerService $service): array
    {
        if ($attachmentType === 'attachment5_tax') {
            $oldAmount = (float) ($old['subsidy_amount'] ?? 0);
            $newAmount = (float) ($new['subsidy_amount'] ?? 0);
            $old['subsidy_amount'] = $service->parseAmount($oldAmount + $newAmount);
            if (isset($new['tax_first_request_amount'])) {
                $oldFirst = (float) ($old['tax_first_request_amount'] ?? 0);
                $old['tax_first_request_amount'] = $service->parseAmount($oldFirst + (float) $new['tax_first_request_amount']);
            }
        }

        foreach ($new as $field => $value) {
            if ($attachmentType === 'attachment5_tax' && in_array($field, ['subsidy_amount', 'tax_first_request_amount'], true)) {
                continue;
            }
            if (in_array($field, ['insurance_place_remark', 'subsidy_identity_obtained', 'tax_request_batch'], true)) {
                $old[$field] = $value !== '' ? $value : null;
                continue;
            }
            if ($value !== null && $value !== '') {
                $old[$field] = $value;
            }
        }

        return $old;
    }

    private function flushRows(array $rows, int $year, array &$result, EnrollLedgerService $service, string $attachmentType): void
    {
        if ($rows === []) {
            return;
        }

        $existingRows = EnrollLedger::query()
            ->where('year', $year)
            ->whereBlindIn('id_card', array_keys($rows))
            ->get();
        $existingMap = [];
        foreach ($existingRows as $record) {
            $existingMap[(string) $record->id_card] = $record->toArray();
        }

        $updateRows = [];
        foreach ($rows as $idCard => $data) {
            $existing = $existingMap[$idCard] ?? null;
            if (!$existing) {
                $result['skipped']++;
                continue;
            }
            $result['matched']++;
            $calculated = $service->calculateInsuranceFields(array_merge($existing, $data));
            foreach ([
                'insurance_category',
                'is_insured',
                'uninsured_reason',
                'is_eligible_for_subsidy',
                'is_subsidy_obtained',
                'subsidy_method',
            ] as $field) {
                if (array_key_exists($field, $calculated)) {
                    $data[$field] = $calculated[$field];
                }
            }
            if ($this->hasTownReviewInput($existing)) {
                $reviewCalculated = $service->calculateTownReviewFields(array_merge($existing, $data));
                foreach ([
                    'insurance_category',
                    'is_insured',
                    'uninsured_reason',
                    'is_eligible_for_subsidy',
                    'is_subsidy_obtained',
                    'subsidy_method',
                    'payment_amount_check_status',
                    'payment_amount_check_remark',
                ] as $field) {
                    if (array_key_exists($field, $reviewCalculated)) {
                        $data[$field] = $reviewCalculated[$field];
                    }
                }
            }
            if ($attachmentType === 'attachment6_death') {
                $data['is_insured'] = '否';
                $data['uninsured_reason'] = '死亡';
                $data['insurance_category'] = null;
                $data['is_eligible_for_subsidy'] = '否';
                $data['is_subsidy_obtained'] = '否';
                $data['subsidy_method'] = null;
            }
            $updateRows[] = [
                'id' => (int) $existing['id'],
                'data' => $data,
            ];
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

    private function hasTownReviewInput(array $data): bool
    {
        return trim((string) ($data['town_is_insured'] ?? '')) !== ''
            || (($data['town_resident_payment_amount'] ?? null) !== null && trim((string) $data['town_resident_payment_amount']) !== '');
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
