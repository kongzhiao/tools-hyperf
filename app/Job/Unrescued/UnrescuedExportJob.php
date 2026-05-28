<?php

declare(strict_types=1);

namespace App\Job\Unrescued;

use App\Job\AbstractJob;
use App\Model\Unrescued\UnrescuedRecord;
use App\Model\Unrescued\UnrescuedSupplementRecord;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\LoggerFactory;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;

class UnrescuedExportJob extends AbstractJob
{
    public function handle(): void
    {
        $logger = ApplicationContext::getContainer()->get(LoggerFactory::class)->get('default');
        try {
            $this->startTask();

            $service = ApplicationContext::getContainer()->get(UnrescuedRecordService::class);
            $type = (string) ($this->params['type'] ?? 'attachment1');
            $filters = (array) ($this->params['filters'] ?? []);
            $userTownId = (int) ($this->params['user_town_id'] ?? 0);
            $logger->info('Unrescued export start.', [
                'uuid' => $this->uuid,
                'type' => $type,
                'filters' => $filters,
                'user_town_id' => $userTownId,
            ]);

            $filenameMap = [
                'attachment1' => '医疗救助未救助排查明细',
                'attachment2' => '医疗救助未报销台账',
                'attachment3' => '医疗救助未救助通知名单',
                'attachment4' => '医疗救助应补应退排查记录',
            ];
            $title = $filenameMap[$type] ?? '未救助台账导出';
            $filename = $title . '_' . date('YmdHis') . '.csv';
            $storagePath = BASE_PATH . '/public/storage/exports/' . $filename;
            if (!is_dir(dirname($storagePath))) {
                mkdir(dirname($storagePath), 0777, true);
            }

            $options = new Options();
            $writer = new Writer($options);
            $writer->openToFile($storagePath);
            fwrite(fopen($storagePath, 'a'), "\xEF\xBB\xBF");

            if ($type === 'attachment4') {
                $this->exportAttachment4($writer, $service, $filters, $userTownId, $logger);
            } else {
                $this->exportRecordAttachment($writer, $service, $type, $filters, $userTownId, $logger);
            }

            $writer->close();
            $relPath = str_replace(BASE_PATH . '/', '', $storagePath);
            $fileSize = round(filesize($storagePath) / 1024 / 1024, 2);
            $logger->info('Unrescued export progress.', [
                'uuid' => $this->uuid,
                'type' => $type,
                'progress' => 100.00,
            ]);
            $logger->info('Unrescued export success.', [
                'uuid' => $this->uuid,
                'type' => $type,
                'file' => $relPath,
                'file_size_mb' => $fileSize,
            ]);
            $this->finishTask($relPath, $fileSize);
        } catch (\Throwable $e) {
            $logger->error('Unrescued export failed: ' . $e->getMessage(), [
                'uuid' => $this->uuid,
                'params' => $this->params,
            ]);
            $this->failTask($e, '未救助台账导出失败');
        }
    }

    private function exportRecordAttachment(Writer $writer, UnrescuedRecordService $service, string $type, array $filters, int $userTownId, $logger): void
    {
        $headers = $this->recordHeaders($type);
        $writer->addRow(Row::fromValues($headers));

        $query = UnrescuedRecord::query();
        $service->applyFilters($query, $filters);
        $service->applyTownScope($query, $userTownId);
        if (in_array($type, ['attachment2', 'attachment3'], true)) {
            $query->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES);
        }

        $total = max($query->count(), 1);
        $logger->info('Unrescued export records counted.', [
            'uuid' => $this->uuid,
            'type' => $type,
            'total_count' => $total,
        ]);
        $processed = 0;
        $query->orderBy('settlement_period')->orderBy('sequence_no')->chunk(1000, function ($records) use ($writer, $type, &$processed, $total, $logger) {
            foreach ($records as $record) {
                $writer->addRow(Row::fromValues($this->recordRow($record, $type)));
                $processed++;
            }
            $progress = min(($processed / $total) * 100, 99.9);
            $this->updateProgress($this->uuid, $progress);
            $logger->info('Unrescued export progress.', [
                'uuid' => $this->uuid,
                'type' => $type,
                'processed' => $processed,
                'total_count' => $total,
                'progress' => round($progress, 2),
            ]);
        });
    }

    private function exportAttachment4(Writer $writer, UnrescuedRecordService $service, array $filters, int $userTownId, $logger): void
    {
        $writer->addRow(Row::fromValues([
            '姓名', '身份证号', '对象类别', '镇街', '参保地', '参加险种', '就诊医疗机构名称', '医保就诊类别', '疾病编码',
            '入院时间', '出院时间', '结算时间', '总费用', '医保政策范围内费用', '统筹报销金额', '大额报销金额', '大病报销金额',
            '医疗救助金额', '渝快保报销金额', '个人账户支付金额', '个人现金支付金额', '进入医疗救助金额',
        ]));

        $query = UnrescuedSupplementRecord::query();
        $period = $service->normalizePeriod((string) ($filters['settlement_period'] ?? ''));
        if ($period !== '') {
            $query->where('settlement_period', $period);
        }
        if ($userTownId > 0) {
            $query->where('town_id', $userTownId);
        } elseif (!empty($filters['town_id'])) {
            $query->where('town_id', (int) $filters['town_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $total = max($query->count(), 1);
        $logger->info('Unrescued export records counted.', [
            'uuid' => $this->uuid,
            'type' => 'attachment4',
            'total_count' => $total,
        ]);
        $processed = 0;
        $query->orderBy('settlement_period')->orderByDesc('id')->chunk(1000, function ($records) use ($writer, &$processed, $total, $logger) {
            foreach ($records as $record) {
                $writer->addRow(Row::fromValues([
                    $record->name,
                    $this->idCard((string) $record->id_card),
                    $record->priority_identity,
                    $record->street_town,
                    $record->insurance_place,
                    $record->insurance_category,
                    $record->hospital_name,
                    $record->medical_visit_category,
                    $record->disease_code,
                    $this->dateText($record->admission_date),
                    $this->dateText($record->discharge_date),
                    $this->dateText($record->settlement_time),
                    $this->money($record->total_fee),
                    $this->money($record->policy_fee),
                    $this->money($record->pool_fund_pay),
                    $this->money($record->large_amount_pay),
                    $this->money($record->serious_illness_pay),
                    $this->money($record->medical_assistance_pay),
                    $this->money($record->yukuaibao_pay),
                    $this->money($record->personal_account_pay),
                    $this->money($record->personal_cash_pay),
                    $this->money($record->calc_medical_assistance_amount),
                ]));
                $processed++;
            }
            $progress = min(($processed / $total) * 100, 99.9);
            $this->updateProgress($this->uuid, $progress);
            $logger->info('Unrescued export progress.', [
                'uuid' => $this->uuid,
                'type' => 'attachment4',
                'processed' => $processed,
                'total_count' => $total,
                'progress' => round($progress, 2),
            ]);
        });
    }

    private function recordHeaders(string $type): array
    {
        if ($type === 'attachment2') {
            return [
                '清算期', '姓名', '身份证号', '镇街', '备注', '开户行', '姓名', '账号录入', '对象类别', '医疗类别', '认定地',
                '医药机构名称', '医药机构编码', '市（内）外', '已报销医疗救助', '入院时间', '出院时间', '结算时间',
                '医疗总费用', '医保政策范围费用', '统筹报销金额', '大额报销', '大病报销', '已使用门诊救助金额',
                '已使用普通住院救助金额', '已使用重特大疾病救助金额', '已使用大额费用住院救助', '进入医疗救助金额',
            ];
        }

        if ($type === 'attachment3') {
            return [
                '姓名', '身份证号', '镇街', '身份', '医疗类别', '认定地', '医药机构名称', '医药机构编码', '市（内）外',
                '入院时间', '出院时间', '结算时间', '医疗总费用', '医保政策范围费用', '统筹报销金额', '大额报销', '大病报销',
                '已使用门诊救助金额', '已使用普通住院救助金额', '已使用重特大疾病救助金额', '已使用大额费用住院救助',
                '进入报销金额', '备注',
            ];
        }

        return [
            '清算期', '序号', '姓名', '身份证号', '镇街', '身份', '医疗类别', '认定地', '医药机构名称', '医药机构编码', '市（内）外',
            '入院时间', '出院时间', '结算时间', '医疗总费用', '医保政策范围费用', '统筹报销金额', '大额报销', '大病报销',
            '已使用门诊救助金额', '已使用普通住院救助金额', '已使用重特大疾病救助金额', '已使用大额费用住院救助',
            '进入报销金额', '备注',
        ];
    }

    private function recordRow(UnrescuedRecord $record, string $type): array
    {
        $common = [
            $record->name,
            $this->idCard((string) $record->id_card),
            $record->street_town,
            $record->priority_identity,
            $record->medical_category,
            $record->cert_location,
            $record->hospital_name,
            $record->hospital_code,
            $record->in_out_city,
            $this->dateText($record->admission_date),
            $this->dateText($record->discharge_date),
            $this->dateText($record->settlement_time),
            $this->money($record->total_fee),
            $this->money($record->policy_fee),
            $this->money($record->pool_fund_pay),
            $this->money($record->large_amount_pay),
            $this->money($record->serious_illness_pay),
            $this->money($record->used_outpatient_rescue),
            $this->money($record->used_normal_rescue),
            $this->money($record->used_major_rescue),
            $this->money($record->used_large_fee_rescue),
            $this->money($record->calc_reimbursement_amount),
            $record->remark,
        ];

        if ($type === 'attachment2') {
            return [
                $record->settlement_period,
                $record->name,
                $this->idCard((string) $record->id_card),
                $record->street_town,
                $record->remark,
                $record->bank_name,
                $record->bank_account_name,
                $this->idCard((string) $record->bank_account_no),
                $record->priority_identity,
                $record->medical_category,
                $record->cert_location,
                $record->hospital_name,
                $record->hospital_code,
                $record->in_out_city,
                $record->reimbursement_status === UnrescuedRecordService::REIMBURSEMENT_PAID ? '是' : '否',
                $this->dateText($record->admission_date),
                $this->dateText($record->discharge_date),
                $this->dateText($record->settlement_time),
                $this->money($record->total_fee),
                $this->money($record->policy_fee),
                $this->money($record->pool_fund_pay),
                $this->money($record->large_amount_pay),
                $this->money($record->serious_illness_pay),
                $this->money($record->used_outpatient_rescue),
                $this->money($record->used_normal_rescue),
                $this->money($record->used_major_rescue),
                $this->money($record->used_large_fee_rescue),
                $this->money($record->calc_reimbursement_amount),
            ];
        }

        return $type === 'attachment1' ? array_merge([$record->settlement_period, $record->sequence_no], $common) : $common;
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function idCard(string $value): string
    {
        return $value === '' ? '' : $value . "\t";
    }

    private function dateText(mixed $value): string
    {
        return $value ? (string) $value : '';
    }
}
