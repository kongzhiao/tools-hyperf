<?php

declare(strict_types=1);

namespace App\Job\Unrescued;

use App\Job\AbstractJob;
use App\Model\Unrescued\UnrescuedRefundRecord;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;

class RefundExportJob extends AbstractJob
{
    public function handle(): void
    {
        try {
            $this->startTask();
            $service = ApplicationContext::getContainer()->get(UnrescuedRecordService::class);
            $filters = (array) ($this->params['filters'] ?? []);
            $filename = '未救助台账_导出_应补应退明细表_' . $this->uuid . '.csv';
            $path = BASE_PATH . '/public/storage/exports/' . $filename;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            $writer = new Writer(new Options());
            $writer->openToFile($path);
            fwrite(fopen($path, 'a'), "\xEF\xBB\xBF");
            $writer->addRow(Row::fromValues([
                '清算期', '序号', '姓名', '身份证号', '匹配状态', '对象类别', '镇街', '村社', '参保地', '参加险种',
                '就诊医疗机构名称', '医保就诊类别', '疾病编码', '疾病名称', '入院时间', '出院时间', '结算时间',
                '总费用', '医保政策范围内费用', '统筹报销金额', '大额报销金额', '大病报销金额', '医疗救助金额',
                '渝快保报销金额', '个人账户支付金额', '个人现金支付金额', '进入报销金额', '状态', '剔除状态', '备注',
            ]));

            $query = UnrescuedRefundRecord::query();
            $this->applyFilters($query, $service, $filters);
            $query->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES);
            $total = max((clone $query)->count(), 1);
            $processed = 0;
            $query->orderBy('settlement_period')->orderBy('sequence_no')->chunk(1000, function ($records) use ($writer, &$processed, $total) {
                foreach ($records as $record) {
                    $writer->addRow(Row::fromValues([
                        $record->settlement_period,
                        $record->sequence_no,
                        $record->name,
                        $this->idCard((string) $record->id_card),
                        $record->match_status,
                        $record->priority_identity,
                        $record->street_town,
                        $record->village,
                        $record->insurance_place,
                        $record->insurance_category,
                        $record->hospital_name,
                        $record->medical_category,
                        $record->disease_code,
                        $record->disease_name,
                        (string) ($record->admission_date ?? ''),
                        (string) ($record->discharge_date ?? ''),
                        (string) ($record->settlement_time ?? ''),
                        $this->money($record->total_fee),
                        $this->money($record->policy_fee),
                        $this->money($record->pool_fund_pay),
                        $this->money($record->large_amount_pay),
                        $this->money($record->serious_illness_pay),
                        $this->money($record->medical_assistance_pay),
                        $this->money($record->yukuaibao_pay),
                        $this->money($record->personal_account_pay),
                        $this->money($record->personal_cash_pay),
                        $this->money($record->calc_reimbursement_amount),
                        $record->status,
                        $record->exclude_status,
                        $record->remark,
                    ]));
                    $processed++;
                }
                $this->updateProgress($this->uuid, min(($processed / $total) * 100, 99.9));
            });
            $writer->close();
            $relPath = str_replace(BASE_PATH . '/', '', $path);
            $this->finishTask($relPath, round(filesize($path) / 1024 / 1024, 2));
        } catch (\Throwable $e) {
            $this->failTask($e, '应补应退明细导出失败');
        }
    }

    private function applyFilters($query, UnrescuedRecordService $service, array $filters): void
    {
        $period = $service->normalizePeriod((string) ($filters['settlement_period'] ?? ''));
        if ($period !== '') {
            $query->where('settlement_period', $period);
        }
        foreach (['status', 'exclude_status', 'match_status', 'town_id', 'medical_category'] as $field) {
            $values = $service->filterValues($filters[$field] ?? null);
            if ($values === []) {
                continue;
            }
            if (count($values) === 1) {
                $query->where($field, $values[0]);
            } else {
                $query->whereIn($field, $values);
            }
        }
        foreach (['disease_code', 'disease_name', 'hospital_name'] as $field) {
            $values = $service->filterValues($filters[$field] ?? null);
            if ($values !== []) {
                $query->where(function ($sub) use ($field, $values) {
                    foreach ($values as $value) {
                        $sub->orWhere($field, 'like', "%{$value}%");
                    }
                });
            }
        }
        $service->applyDiseaseKeywordFilter($query, $filters['disease_keyword'] ?? null);
        $identities = $service->filterValues($filters['priority_identity'] ?? null);
        if ($identities !== []) {
            if (count($identities) === 1) {
                $query->where('priority_identity', $identities[0]);
            } else {
                $query->whereIn('priority_identity', $identities);
            }
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($sub) use ($keyword) {
                $sub->whereBlind('name', $keyword)
                    ->orWhere(function ($idQuery) use ($keyword) {
                        $idQuery->whereBlind('id_card', $keyword);
                    })
                    ->orWhere('sequence_no', 'like', "%{$keyword}%");
            });
        }
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function idCard(string $value): string
    {
        return $value === '' ? '' : $value . "\t";
    }
}
