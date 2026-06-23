<?php

declare(strict_types=1);

namespace App\Job\Enroll;

use App\Job\AbstractJob;
use App\Model\Enroll\EnrollConfig;
use App\Model\Enroll\EnrollImportBatch;
use App\Model\Enroll\EnrollLedger;
use App\Service\BusinessFilterOptionService;
use App\Service\CsvReaderService;
use App\Service\Enroll\EnrollLedgerService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;

class Attachment3ImportJob extends AbstractJob
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
        $filterOptionService = $container->get(BusinessFilterOptionService::class);

        $batch = null;
        try {
            $this->startTask();
            if (!file_exists($this->tempFile)) {
                throw new \RuntimeException('导入文件不存在');
            }

            $year = (int) ($this->params['year'] ?? 0);
            $period = $service->normalizePeriod((string) ($this->params['period'] ?? ''));
            if ($year <= 0 || $period === '') {
                throw new \RuntimeException('年份和月份不能为空');
            }

            $sourceBatch = date('YmdHis');
            $batch = EnrollImportBatch::create([
                'uuid' => $this->uuid,
                'year' => $year,
                'period' => $period,
                'attachment_type' => 'attachment3_full_list',
                'file_name' => $this->params['source_file'] ?? '',
                'baseline_snapshot_batch' => $sourceBatch,
                'status' => EnrollImportBatch::STATUS_RUNNING,
                'created_by' => (int) ($this->params['created_by'] ?? 0),
            ]);

            $csvReader = new CsvReaderService();
            $this->assertRequiredHeaders($csvReader->getHeaders($this->tempFile));
            $totalCount = max($csvReader->countRows($this->tempFile) - 1, 1);
            $processed = 0;
            $monthRows = [];
            $errors = [];
            $unmatchedSubsidyIdentities = [];
            $medicalConfigs = $service->configRows($year, EnrollConfig::TYPE_MEDICAL);
            $subsidyConfigs = $service->configRows($year, EnrollConfig::TYPE_SUBSIDY);

            $logger->info('Enroll attachment3 import start.', [
                'uuid' => $this->uuid,
                'year' => $year,
                'period' => $period,
                'total_count' => $totalCount,
                'source_file' => $this->params['source_file'] ?? '',
            ]);

            $csvReader->read($this->tempFile, function (array $row, int $rowIndex) use (
                $service,
                $year,
                $period,
                $medicalConfigs,
                $subsidyConfigs,
                &$processed,
                $totalCount,
                &$monthRows,
                &$errors,
                &$unmatchedSubsidyIdentities,
                $filterOptionService
            ) {
                $processed++;
                $idCard = $service->pickValue($row, ['身份证号码', '身份证号', '公民身份号码', '身份证', 'id_card']);
                if ($idCard === '') {
                    if (count($errors) < 20) {
                        $errors[] = ['row' => $rowIndex + 1, 'message' => '身份证号码为空'];
                    }
                    return;
                }

                $rawIdentityValue = $service->pickValue($row, ['医疗救助身份', '特殊人员身份', '身份类别', '救助身份', '身份', 'raw_identity']);
                if ($rawIdentityValue === '') {
                    if (count($errors) < 20) {
                        $errors[] = ['row' => $rowIndex + 1, 'message' => '医疗救助身份为空'];
                    }
                    return;
                }
                $rawIdentities = $service->normalizeIdentityList($rawIdentityValue);
                if ($rawIdentities === []) {
                    if (count($errors) < 20) {
                        $errors[] = ['row' => $rowIndex + 1, 'message' => '医疗救助身份无有效值'];
                    }
                    return;
                }
                $medicalRecords = $service->resolveIdentityRecords($rawIdentities, $medicalConfigs);
                $subsidyRecords = $service->resolveIdentityRecords($rawIdentities, $subsidyConfigs);
                foreach ($subsidyRecords as $record) {
                    if ((int) ($record['priority'] ?? 9999) >= 9999) {
                        $identity = (string) ($record['identity'] ?? '');
                        if ($identity !== '') {
                            $unmatchedSubsidyIdentities[$identity] = ($unmatchedSubsidyIdentities[$identity] ?? 0) + 1;
                        }
                    }
                }

                $data = [
                    'year' => $year,
                    'id_card' => $idCard,
                    'name' => $service->pickValue($row, ['姓名', 'name']) ?: null,
                    'town_name' => $service->pickValue($row, ['镇（街）', '镇(街)', '镇街', '街镇', '乡镇街道', 'town_name']) ?: null,
                    'village_name' => $service->pickValue($row, ['村（居）', '村(居)', '村居', '村社', '社区', 'village_name']) ?: null,
                    'included_month' => $service->normalizeDateText($service->pickValue($row, ['纳入资助时间', '纳入时间', 'included_month'])) ?: $period,
                    'payment_time' => $service->normalizeDateText($service->pickValue($row, ['缴费时间', '缴费日期', 'payment_time'])) ?: null,
                    'raw_identity' => implode('、', $rawIdentities),
                    'medical_identity_records' => $medicalRecords,
                    'medical_identity' => $service->preferredIdentity($medicalRecords),
                    'subsidy_identity_records' => $subsidyRecords,
                    'subsidy_identity' => $service->preferredIdentity($subsidyRecords),
                    'last_attachment3_period' => $period,
                    'last_payment_time_period' => $period,
                ];

                if (!isset($monthRows[$idCard])) {
                    $monthRows[$idCard] = $data;
                } else {
                    $monthRows[$idCard] = $this->mergeDuplicatePerson($monthRows[$idCard], $data, $service);
                }

                if ($data['town_name']) {
                    $filterOptionService->saveOption(EnrollLedgerService::MODULE, 'town_name', $data['town_name'], $period);
                }
                foreach ($rawIdentities as $identity) {
                    $filterOptionService->saveOption(EnrollLedgerService::MODULE, 'raw_identity', $identity, $period);
                }

                if ($processed % 1000 === 0) {
                    $this->updateProgress($this->uuid, min(80, ($processed / $totalCount) * 80));
                }
            });

            if ($monthRows === []) {
                throw new \RuntimeException('未读取到有效附件3数据，请确认文件表头和内容是否正确');
            }
            if ($errors !== []) {
                $firstError = $errors[0]['message'] ?? '存在错误行';
                throw new \RuntimeException('附件3存在错误行，已停止导入，避免误判取消人员。首个错误：' . $firstError);
            }
            $service->saveBeforeImportSnapshots($year, $period, $sourceBatch);
            $existingMap = $this->buildExistingMap($year);
            $result = $this->persistMonthRows($service, $year, $period, $sourceBatch, $monthRows, $existingMap);
            $result['errors'] = $errors;
            arsort($unmatchedSubsidyIdentities);
            $result['unmatched_subsidy_identities'] = array_slice($unmatchedSubsidyIdentities, 0, 30, true);

            $batch->update([
                'total_rows' => $processed,
                'success_rows' => $result['created'] + $result['updated'] + $result['cancelled'],
                'failed_rows' => count($errors),
                'status' => EnrollImportBatch::STATUS_COMPLETED,
                'message' => json_encode($result, JSON_UNESCAPED_UNICODE),
            ]);

            $logger->info('Enroll attachment3 import success.', [
                'uuid' => $this->uuid,
                'year' => $year,
                'period' => $period,
                'result' => $result,
            ]);

            $this->updateProgress($this->uuid, 100);
            $this->finishTask('', 0);
        } catch (\Throwable $e) {
            if ($batch) {
                $batch->update([
                    'status' => EnrollImportBatch::STATUS_FAILED,
                    'message' => $e->getMessage(),
                ]);
            }
            $logger->error('Enroll attachment3 import failed: ' . $e->getMessage(), [
                'uuid' => $this->uuid,
                'params' => $this->params,
            ]);
            $this->failTask($e, '参保台账附件3导入失败');
        } finally {
            if (file_exists($this->tempFile)) {
                @unlink($this->tempFile);
            }
        }
    }

    private function assertRequiredHeaders(array $headers): void
    {
        $requiredGroups = [
            '身份证号码' => ['身份证号码', '身份证号', '公民身份号码', '身份证', 'id_card'],
            '医疗救助身份' => ['医疗救助身份', '特殊人员身份', '身份类别', '救助身份', '身份', 'raw_identity'],
        ];
        $normalizedHeaders = array_map(fn ($header) => $this->normalizeHeader((string) $header), $headers);
        $missing = [];
        foreach ($requiredGroups as $label => $aliases) {
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
            throw new \RuntimeException('文件格式不正确，请使用附件3导入模板。缺少表头：' . implode('、', $missing));
        }
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/[\x{FEFF}\x{200B}]/u', '', $header) ?? $header;
        $header = preg_replace('/\s+/u', '', $header) ?? $header;
        return trim($header);
    }

    private function buildExistingMap(int $year): array
    {
        $map = [];
        EnrollLedger::query()
            ->where('year', $year)
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$map) {
                foreach ($rows as $row) {
                    $idCard = (string) $row->id_card;
                    if ($idCard !== '' && !isset($map[$idCard])) {
                        $map[$idCard] = $row->toArray();
                    }
                }
            });

        return $map;
    }

    private function persistMonthRows(EnrollLedgerService $service, int $year, string $period, string $sourceBatch, array $monthRows, array $existingMap): array
    {
        $result = ['created' => 0, 'updated' => 0, 'cancelled' => 0];
        $importedIdCards = array_keys($monthRows);

        Db::beginTransaction();
        try {
            foreach ($monthRows as $idCard => $data) {
                $existing = $existingMap[$idCard] ?? null;
                $data['change_status'] = $this->decideChangeStatus($existing, $data);
                $data['cancel_month'] = null;
                $calculationData = array_merge($existing ?? [], $data);
                $calculationData = $service->calculateInsuranceFields($calculationData);
                foreach (['is_eligible_for_subsidy', 'is_subsidy_obtained', 'subsidy_method'] as $field) {
                    if (array_key_exists($field, $calculationData)) {
                        $data[$field] = $calculationData[$field];
                    }
                }
                $created = $service->upsertLedgerByProgramRule($data);
                $created ? $result['created']++ : $result['updated']++;
            }

            $cancelIdCards = array_values(array_diff(array_keys($existingMap), $importedIdCards));
            foreach (array_chunk($cancelIdCards, 1000) as $chunk) {
                if (!$chunk) {
                    continue;
                }
                $affected = EnrollLedger::query()
                    ->where('year', $year)
                    ->whereIn('id_card', $chunk)
                    ->where('change_status', '!=', EnrollLedgerService::CHANGE_CANCELLED)
                    ->update([
                        'change_status' => EnrollLedgerService::CHANGE_CANCELLED,
                        'cancel_month' => $period,
                        'last_attachment3_period' => $period,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                $result['cancelled'] += $affected;
            }

            $service->replaceAfterImportSnapshotsFromLedgers($year, $period, $sourceBatch);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }

        return $result;
    }

    private function decideChangeStatus(?array $existing, array $data): string
    {
        if (!$existing) {
            return EnrollLedgerService::CHANGE_NEW;
        }

        if ((string) ($existing['change_status'] ?? '') === EnrollLedgerService::CHANGE_CANCELLED) {
            return EnrollLedgerService::CHANGE_CHANGED;
        }

        if ((string) ($existing['medical_identity'] ?? '') !== (string) ($data['medical_identity'] ?? '')) {
            return EnrollLedgerService::CHANGE_CHANGED;
        }

        $status = (string) ($existing['change_status'] ?? '');
        return in_array($status, [
            EnrollLedgerService::CHANGE_NEW,
            EnrollLedgerService::CHANGE_CHANGED,
            EnrollLedgerService::CHANGE_CANCELLED,
        ], true) ? $status : EnrollLedgerService::CHANGE_NEW;
    }

    private function mergeDuplicatePerson(array $old, array $new, EnrollLedgerService $service): array
    {
        foreach (['name', 'town_name', 'village_name', 'included_month', 'payment_time'] as $field) {
            if (($new[$field] ?? null) !== null && ($new[$field] ?? '') !== '') {
                $old[$field] = $new[$field];
            }
        }

        $rawIdentities = array_values(array_unique(array_filter(array_merge(
            $service->normalizeIdentityList((string) ($old['raw_identity'] ?? '')),
            $service->normalizeIdentityList((string) ($new['raw_identity'] ?? ''))
        ))));
        $old['raw_identity'] = implode('、', $rawIdentities);

        $old['medical_identity_records'] = $this->mergeRecords($old['medical_identity_records'] ?? [], $new['medical_identity_records'] ?? []);
        $old['medical_identity'] = $service->preferredIdentity($old['medical_identity_records']);
        $old['subsidy_identity_records'] = $this->mergeRecords($old['subsidy_identity_records'] ?? [], $new['subsidy_identity_records'] ?? []);
        $old['subsidy_identity'] = $service->preferredIdentity($old['subsidy_identity_records']);

        return $old;
    }

    private function mergeRecords(array $left, array $right): array
    {
        $map = [];
        foreach (array_merge($left, $right) as $record) {
            $identity = (string) ($record['identity'] ?? '');
            if ($identity === '') {
                continue;
            }
            if (!isset($map[$identity]) || (int) ($record['priority'] ?? 9999) < (int) ($map[$identity]['priority'] ?? 9999)) {
                $map[$identity] = $record;
            }
        }
        $records = array_values($map);
        usort($records, fn ($a, $b) => ((int) ($a['priority'] ?? 9999) <=> (int) ($b['priority'] ?? 9999)) ?: strcmp((string) $a['identity'], (string) $b['identity']));
        return $records;
    }
}
