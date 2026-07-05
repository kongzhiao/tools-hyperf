<?php

declare(strict_types=1);

namespace App\Job\Enroll;

use App\Job\AbstractJob;
use App\Model\Enroll\EnrollConfig;
use App\Model\Enroll\EnrollLedger;
use App\Service\Enroll\EnrollLedgerService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\LoggerFactory;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;

class EnrollExportJob extends AbstractJob
{
    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');
        $service = $container->get(EnrollLedgerService::class);

        try {
            $this->startTask();
            $type = (string) ($this->params['type'] ?? 'attachment1');
            $filters = (array) ($this->params['filters'] ?? []);
            $townName = (string) ($this->params['town_name'] ?? '');
            $title = match ($type) {
                'attachment2' => '参保台账_导出_对比结果',
                'attachment3' => '参保台账_导出_特殊对象资助参保台账',
                default => '参保台账_导出_汇总名单',
            };

            $filename = $title . '_' . $this->uuid . '.csv';
            $storagePath = BASE_PATH . '/public/storage/exports/' . $filename;
            if (!is_dir(dirname($storagePath))) {
                mkdir(dirname($storagePath), 0777, true);
            }

            $logger->info('Enroll export start.', [
                'uuid' => $this->uuid,
                'type' => $type,
                'filters' => $filters,
                'town_name' => $townName,
            ]);

            $options = new Options();
            $writer = new Writer($options);
            $writer->openToFile($storagePath);
            fwrite(fopen($storagePath, 'a'), "\xEF\xBB\xBF");
            $subsidyIdentityHeaders = $type === 'attachment1'
                ? $this->subsidyIdentityHeaders($service, $filters)
                : [];
            $writer->addRow(Row::fromValues($this->headers($type, $subsidyIdentityHeaders)));

            $query = EnrollLedger::query();
            $service->applyFilters($query, $filters);
            $service->applyTownReviewVisibleScope($query, $townName);
            if ($type === 'attachment2') {
                $query->whereIn('change_status', [
                    EnrollLedgerService::CHANGE_NEW,
                    EnrollLedgerService::CHANGE_CHANGED,
                    EnrollLedgerService::CHANGE_CANCELLED,
                ]);
            }
            $total = max((clone $query)->count(), 1);
            $processed = 0;

            $query->orderBy('town_name')
                ->orderBy('id_card')
                ->chunk(1000, function ($rows) use ($writer, $type, $subsidyIdentityHeaders, &$processed, $total) {
                    foreach ($rows as $row) {
                        $processed++;
                        $writer->addRow(Row::fromValues($this->row($row->toArray(), $type, $subsidyIdentityHeaders, $processed)));
                    }
                    $this->updateProgress($this->uuid, min(99, ($processed / $total) * 100));
                });

            $writer->close();
            $relPath = str_replace(BASE_PATH . '/', '', $storagePath);
            $fileSize = round(filesize($storagePath) / 1024 / 1024, 2);
            $logger->info('Enroll export success.', [
                'uuid' => $this->uuid,
                'type' => $type,
                'file' => $relPath,
            ]);
            $this->finishTask($relPath, $fileSize);
        } catch (\Throwable $e) {
            $logger->error('Enroll export failed: ' . $e->getMessage(), [
                'uuid' => $this->uuid,
                'params' => $this->params,
            ]);
            $this->failTask($e, '参保台账导出失败');
        }
    }

    private function subsidyIdentityHeaders(EnrollLedgerService $service, array $filters): array
    {
        $year = (int) ($filters['year'] ?? date('Y'));
        $headers = [];
        foreach ($service->configRows($year, EnrollConfig::TYPE_SUBSIDY) as $config) {
            $identity = trim((string) ($config['identity_name'] ?? ''));
            if ($identity !== '' && !in_array($identity, $headers, true)) {
                $headers[] = $identity;
            }
        }

        return $headers;
    }

    private function headers(string $type, array $subsidyIdentityHeaders = []): array
    {
        if ($type === 'attachment2') {
            return [
                '增量形式',
                '姓名',
                '身份证号码',
                '资助参保身份',
                '纳入资助时间',
                '身份取消时间',
            ];
        }
        if ($type === 'attachment3') {
            return [
                '序号',
                '纳入资助时间',
                '身份取消时间',
                '身份变更情况',
                '镇（街）',
                '村（居）',
                '姓名',
                '身份证号码',
                '医疗救助身份',
                '资助参保身份',
                '是否参保',
                '参保类别',
                '居民医保缴费金额',
                '缴费时间',
                '是否符合资助',
                '是否获得资助',
                '获得资助身份类别',
                '资助方式',
                '税务第一批请款税务清款情况',
                '资助金额',
                '资助地或参保地（区外备注）',
                '备注（卫健委死亡时间）',
                '未参保原因',
                '人工备注',
            ];
        }

        return array_merge([
            '姓名',
            '身份证号码',
            '镇（街）',
            '村（居）',
            '资助参保身份',
        ], $subsidyIdentityHeaders);
    }

    private function row(array $data, string $type, array $subsidyIdentityHeaders = [], int $sequence = 0): array
    {
        if ($type === 'attachment2') {
            return [
                $data['change_status'] ?? '',
                $data['name'] ?? '',
                $data['id_card'] ?? '',
                $data['subsidy_identity'] ?? '',
                $this->displayYearMonthText($data['included_month'] ?? ''),
                $this->displayYearMonthText($data['cancel_month'] ?? ''),
            ];
        }
        if ($type === 'attachment3') {
            return [
                $sequence,
                $this->displayYearMonthText($data['included_month'] ?? ''),
                $this->displayYearMonthText($data['cancel_month'] ?? ''),
                $data['change_status'] ?? '',
                $data['town_name'] ?? '',
                $data['village_name'] ?? '',
                $data['name'] ?? '',
                $data['id_card'] ?? '',
                $data['medical_identity'] ?? '',
                $data['subsidy_identity'] ?? '',
                $data['is_insured'] ?? '',
                $this->displayInsuranceCategory($data['insurance_category'] ?? null),
                $data['resident_payment_amount'] ?? '0.00',
                $data['payment_time'] ?? '',
                $data['is_eligible_for_subsidy'] ?? '',
                $data['is_subsidy_obtained'] ?? '',
                $data['subsidy_identity_obtained'] ?? '',
                $data['subsidy_method'] ?? '',
                $data['tax_first_request_amount'] ?? '0.00',
                $data['subsidy_amount'] ?? '0.00',
                $data['insurance_place_remark'] ?? '',
                $data['death_remark'] ?? '',
                $data['uninsured_reason'] ?? '',
                $data['manual_remark'] ?? '',
            ];
        }

        return array_merge([
            $data['name'] ?? '',
            $data['id_card'] ?? '',
            $data['town_name'] ?? '',
            $data['village_name'] ?? '',
            $data['subsidy_identity'] ?? '',
        ], $this->subsidyIdentityMarks($data, $subsidyIdentityHeaders));
    }

    private function displayInsuranceCategory(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : EnrollLedgerService::INSURANCE_CATEGORY_UNMATCHED;
    }

    private function displayYearMonthText(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return $value;
        }

        return '="' . $value . '"';
    }

    private function subsidyIdentityMarks(array $data, array $headers): array
    {
        $matched = [];
        $records = $data['subsidy_identity_records'] ?? [];
        if (is_string($records)) {
            $decoded = json_decode($records, true);
            $records = is_array($decoded) ? $decoded : [];
        }
        if (is_array($records)) {
            foreach ($records as $record) {
                $identity = trim((string) ($record['identity'] ?? ''));
                if ($identity !== '') {
                    $matched[$identity] = true;
                }
            }
        }

        return array_map(fn ($identity) => isset($matched[$identity]) ? '√' : '', $headers);
    }
}
