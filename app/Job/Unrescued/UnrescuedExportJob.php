<?php

declare(strict_types=1);

namespace App\Job\Unrescued;

use App\Job\AbstractJob;
use App\Model\Unrescued\UnrescuedRecord;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\LoggerFactory;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;

class UnrescuedExportJob extends AbstractJob
{
    private const WASH_RULE_NAMES = [
        'medical_category_keep' => '医疗类别保留',
        'hospital_keyword_exclude' => '机构名称关键字剔除',
        'pool_equals_policy' => '统筹报销等于政策范围费用',
        'large_equals_policy' => '大额报销等于政策范围费用',
        'serious_equals_policy' => '大病报销等于政策范围费用',
        'normal_rescue_limit' => '普通住院救助额度已满',
        'major_rescue_limit' => '重特大疾病救助额度已满',
        'large_fee_rescue_limit' => '大额费用住院救助额度已满',
        'identity_exclude' => '身份类别剔除',
    ];

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
                'unrescued' => '未救助台账_导出_未救助明细表',
            ];
            $title = $filenameMap[$type] ?? '未救助台账导出';
            $filename = $title . '_' . $this->uuid . '.csv';
            $storagePath = BASE_PATH . '/public/storage/exports/' . $filename;
            if (!is_dir(dirname($storagePath))) {
                mkdir(dirname($storagePath), 0777, true);
            }

            $options = new Options();
            $writer = new Writer($options);
            $writer->openToFile($storagePath);
            fwrite(fopen($storagePath, 'a'), "\xEF\xBB\xBF");

            $this->exportRecordAttachment($writer, $service, 'unrescued', $filters, $userTownId, $logger);

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
        $query->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES);

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

    private function recordHeaders(string $type): array
    {
        return [
            '清算期', '序号', '姓名', '身份证号', '匹配状态', '镇街', '村社', '身份', '医疗类别', '病种编码', '病种名称', '认定地', '医药机构名称', '医药机构编码', '市（内）外',
            '入院时间', '出院时间', '结算时间', '医疗总费用', '医保政策范围费用', '统筹报销金额', '大额报销', '大病报销',
            '已使用门诊救助金额', '已使用普通住院救助金额', '已使用重特大疾病救助金额', '已使用大额费用住院救助',
            '进入报销金额', '状态', '剔除状态', '备注',
        ];
    }

    private function recordRow(UnrescuedRecord $record, string $type): array
    {
        return [
            $record->settlement_period,
            $record->sequence_no,
            $record->name,
            $this->idCard((string) $record->id_card),
            $record->match_status,
            $record->street_town,
            $record->village,
            $record->priority_identity,
            $record->medical_category,
            $record->disease_code,
            $record->disease_name,
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
            $record->status,
            $record->exclude_status,
            $this->exportRemark($record),
        ];
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

    private function exportRemark(UnrescuedRecord $record): string
    {
        $remark = trim((string) ($record->remark ?? ''));
        if ($remark !== '') {
            return $remark;
        }

        $ruleCode = trim((string) ($record->exclude_rule_code ?? ''));
        if ($ruleCode !== '') {
            return self::WASH_RULE_NAMES[$ruleCode] ?? $ruleCode;
        }

        return trim((string) ($record->status ?? ''));
    }
}
