<?php

declare(strict_types=1);

namespace App\Job\Unrescued;

use App\Job\AbstractJob;
use App\Model\Unrescued\UnrescuedNoticeRecord;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;

class NoticeExportJob extends AbstractJob
{
    public function handle(): void
    {
        try {
            $this->startTask();
            $service = ApplicationContext::getContainer()->get(UnrescuedRecordService::class);
            $filters = (array) ($this->params['filters'] ?? []);
            $userTownId = (int) ($this->params['user_town_id'] ?? 0);
            $filename = '未救助台账_导出_通知明细_' . $this->uuid . '.csv';
            $path = BASE_PATH . '/public/storage/exports/' . $filename;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            $writer = new Writer(new Options());
            $writer->openToFile($path);
            fwrite(fopen($path, 'a'), "\xEF\xBB\xBF");
            $headers = [
                '清算期', '序号', '姓名', '身份证号', '对象类别', '镇街', '参保地', '参加险种', '就诊医疗机构名称',
                '医保就诊类别', '疾病编码', '疾病名称', '入院时间', '出院时间', '结算时间', '总费用', '医保政策范围内费用',
                '统筹报销金额', '大额报销金额', '大病报销金额', '医疗救助金额', '渝快保报销金额', '个人账户支付金额',
                '个人现金支付金额',
            ];
            if ($userTownId <= 0) {
                $headers[] = '进入报销金额';
            }
            $headers = array_merge($headers, ['系统备注', '下放状态', '报销状态', '联系人', '联系方式', '开户行', '户名', '银行账号', '镇街备注', '接收时间', '通知时间']);
            if ($userTownId <= 0) {
                $headers[] = '管理员备注';
            }
            $writer->addRow(Row::fromValues($headers));

            $query = UnrescuedNoticeRecord::query();
            $this->applyFilters($query, $service, $filters, $userTownId);
            $total = max((clone $query)->count(), 1);
            $processed = 0;
            $query->orderBy('settlement_period')->orderBy('sequence_no')->chunk(1000, function ($records) use ($writer, &$processed, $total, $userTownId) {
                foreach ($records as $record) {
                    $row = [
                        $record->settlement_period,
                        $record->sequence_no,
                        $record->name,
                        $this->idCard((string) $record->id_card),
                        $record->priority_identity,
                        $record->street_town,
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
                    ];
                    if ($userTownId <= 0) {
                        $row[] = $this->money($record->calc_reimbursement_amount);
                    }
                    $row = array_merge($row, [
                        $record->system_remark,
                        $record->status,
                        $record->reimbursement_status,
                        $record->contact_name,
                        $record->contact_phone,
                        $record->bank_name,
                        $record->bank_account_name,
                        $record->bank_account_no,
                        $record->town_remark,
                        (string) ($record->received_at ?? ''),
                        (string) ($record->notified_at ?? ''),
                    ]);
                    if ($userTownId <= 0) {
                        $row[] = $record->admin_remark;
                    }
                    $writer->addRow(Row::fromValues($row));
                    $processed++;
                }
                $this->updateProgress($this->uuid, min(($processed / $total) * 100, 99.9));
            });
            $writer->close();
            $relPath = str_replace(BASE_PATH . '/', '', $path);
            $this->finishTask($relPath, round(filesize($path) / 1024 / 1024, 2));
        } catch (\Throwable $e) {
            $this->failTask($e, '通知明细导出失败');
        }
    }

    private function applyFilters($query, UnrescuedRecordService $service, array $filters, int $userTownId): void
    {
        $period = $service->normalizePeriod((string) ($filters['settlement_period'] ?? ''));
        if ($period !== '') {
            $query->where('settlement_period', $period);
        }
        if ($userTownId > 0) {
            $query->where('town_id', $userTownId)
                ->whereIn('status', [
                    '已接收',
                    '已通知',
                ]);
        } elseif (($filters['town_id'] ?? '') !== '') {
            $query->where('town_id', (int) $filters['town_id']);
        }
        foreach (['status', 'reimbursement_status', 'medical_category'] as $field) {
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
                $sub->where('name', 'like', "%{$keyword}%")
                    ->orWhere('id_card', 'like', "%{$keyword}%")
                    ->orWhere('sequence_no', 'like', "%{$keyword}%");
            });
        }
        foreach (['hospital_name', 'disease_code', 'disease_name', 'contact_name'] as $field) {
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
        $remark = trim((string) ($filters['remark'] ?? ''));
        if ($remark !== '') {
            $query->where(function ($sub) use ($remark, $userTownId) {
                $sub->where('system_remark', 'like', "%{$remark}%")
                    ->orWhere('town_remark', 'like', "%{$remark}%");
                if ($userTownId <= 0) {
                    $sub->orWhere('admin_remark', 'like', "%{$remark}%");
                }
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
