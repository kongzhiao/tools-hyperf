<?php

declare(strict_types=1);

namespace App\Service\Unrescued;

use App\Model\Town;
use App\Model\Unrescued\UnrescuedRecord;
use Carbon\Carbon;

class UnrescuedRecordService
{
    public const STATUS_PENDING = '待处理';
    public const STATUS_NO_AMOUNT = '无救助金额';
    public const STATUS_NO_NOTICE = '不通知';
    public const STATUS_TO_NOTICE = '拟通知';
    public const STATUS_DISTRIBUTED = '已下放';
    public const STATUS_RECEIVED = '已接收';
    public const STATUS_NOTIFIED = '已通知';
    public const TOWN_VISIBLE_STATUSES = [
        self::STATUS_DISTRIBUTED,
        self::STATUS_RECEIVED,
        self::STATUS_NOTIFIED,
    ];

    public const REIMBURSEMENT_UNPAID = '未报销';
    public const REIMBURSEMENT_PAID = '已报销';

    public const EXCLUDE_NO = '未剔除';
    public const EXCLUDE_YES = '已剔除';

    public function pickValue(array $row, array $headers, string $default = ''): string
    {
        foreach ($headers as $header) {
            if (array_key_exists($header, $row)) {
                $value = trim((string) $row[$header]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return $default;
    }

    public function parseAmount(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        $value = str_replace(['¥', '￥', ',', ' '], '', trim((string) $value));
        if ($value === '' || $value === '--') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    public function bcSub(string $left, string $right): string
    {
        if (function_exists('bcsub')) {
            return bcsub($left, $right, 2);
        }

        return number_format(((float) $left) - ((float) $right), 2, '.', '');
    }

    public function calcReimbursementAmount(array $data): string
    {
        $amount = $this->parseAmount($data['policy_fee'] ?? 0);
        foreach (['pool_fund_pay', 'large_amount_pay', 'serious_illness_pay'] as $field) {
            $amount = $this->bcSub($amount, $this->parseAmount($data[$field] ?? 0));
        }

        return $amount;
    }

    public function calcSupplementAmount(array $data): string
    {
        $amount = $this->parseAmount($data['policy_fee'] ?? 0);
        foreach (['pool_fund_pay', 'large_amount_pay', 'serious_illness_pay', 'medical_assistance_pay', 'yukuaibao_pay'] as $field) {
            $amount = $this->bcSub($amount, $this->parseAmount($data[$field] ?? 0));
        }

        return $amount;
    }

    public function decideStatus(string|float|int $amount): string
    {
        $amount = (float) $amount;
        if ($amount <= 0) {
            return self::STATUS_NO_AMOUNT;
        }

        if ($amount <= 300) {
            return self::STATUS_NO_NOTICE;
        }

        return self::STATUS_TO_NOTICE;
    }

    public function shouldKeepWorkflowStatus(?string $status): bool
    {
        return in_array($status, [
            self::STATUS_DISTRIBUTED,
            self::STATUS_RECEIVED,
            self::STATUS_NOTIFIED,
        ], true);
    }

    public function normalizePeriod(string $period): string
    {
        $period = trim($period);
        if ($period === '') {
            return '';
        }

        if (preg_match('/^(\d{4})[^\d]?(\d{1,2})/', $period, $matches)) {
            return $matches[1] . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        }

        if (preg_match('/^\d{6}$/', $period)) {
            return $period;
        }

        return $period;
    }

    public function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '--' || $value === '0' || $value === '0000-00-00') {
            return null;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public function parseDateTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '--' || $value === '0') {
            return null;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]} 00:00:00";
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolveTownId(?string $townName): int
    {
        $townName = trim((string) $townName);
        if ($townName === '') {
            return 0;
        }

        $town = Town::query()
            ->where('name', $townName)
            ->orWhere('code', $townName)
            ->first();

        if ($town) {
            return (int) $town->id;
        }

        $normalizedInput = $this->normalizeTownName($townName);
        if ($normalizedInput === '') {
            return 0;
        }

        $towns = Town::query()
            ->select(['id', 'name', 'code'])
            ->get();

        foreach ($towns as $item) {
            $normalizedName = $this->normalizeTownName((string) $item->name);
            $normalizedCode = $this->normalizeTownName((string) $item->code);
            if ($normalizedInput === $normalizedName || ($normalizedCode !== '' && $normalizedInput === $normalizedCode)) {
                return (int) $item->id;
            }
        }

        return 0;
    }

    private function normalizeTownName(string $name): string
    {
        $name = preg_replace('/\s+/u', '', trim($name)) ?: '';
        $name = str_replace(['镇人民政府', '街道办事处', '街道办', '办事处'], '', $name);
        return preg_replace('/(镇|乡|街道)$/u', '', $name) ?: $name;
    }

    public function applyFilters($query, array $filters): void
    {
        $period = $this->normalizePeriod((string) ($filters['settlement_period'] ?? ''));
        if ($period !== '') {
            $query->where('settlement_period', $period);
        }

        foreach (['status', 'exclude_status', 'reimbursement_status', 'town_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        $identity = trim((string) ($filters['priority_identity'] ?? ''));
        if ($identity !== '') {
            $query->where('priority_identity', 'like', "%{$identity}%");
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('name', 'like', "%{$keyword}%")
                    ->orWhere('id_card', 'like', "%{$keyword}%")
                    ->orWhere('sequence_no', 'like', "%{$keyword}%");
            });
        }
    }

    public function applyTownScope($query, int $userTownId): void
    {
        if ($userTownId > 0) {
            $query->where('town_id', $userTownId)
                ->whereIn('status', self::TOWN_VISIBLE_STATUSES);
        }
    }

    public function defaultWashRules(): array
    {
        return [
            [
                'code' => 'medical_category_keep',
                'name' => '医疗类别保留',
                'field' => 'medical_category',
                'action' => 'keep',
                'operator' => 'in',
                'values' => [],
                'remark' => '门诊救助',
                'enabled' => false,
            ],
            [
                'code' => 'hospital_keyword_exclude',
                'name' => '机构名称关键字剔除',
                'field' => 'hospital_name',
                'action' => 'exclude',
                'operator' => 'contains',
                'values' => [],
                'remark' => '对象类别不符',
                'enabled' => false,
            ],
            [
                'code' => 'pool_equals_policy',
                'name' => '统筹报销等于政策范围费用',
                'field' => 'pool_fund_pay',
                'action' => 'exclude',
                'operator' => '=',
                'compare_field' => 'policy_fee',
                'remark' => '无救助金额',
                'enabled' => false,
            ],
            [
                'code' => 'large_equals_policy',
                'name' => '大额报销等于政策范围费用',
                'field' => 'large_amount_pay',
                'action' => 'exclude',
                'operator' => '=',
                'compare_field' => 'policy_fee',
                'remark' => '无救助金额',
                'enabled' => false,
            ],
            [
                'code' => 'serious_equals_policy',
                'name' => '大病报销等于政策范围费用',
                'field' => 'serious_illness_pay',
                'action' => 'exclude',
                'operator' => '=',
                'compare_field' => 'policy_fee',
                'remark' => '无救助金额',
                'enabled' => false,
            ],
            [
                'code' => 'normal_rescue_limit',
                'name' => '普通住院救助额度已满',
                'field' => 'used_normal_rescue',
                'action' => 'exclude',
                'operator' => '=',
                'value' => '6000.00',
                'remark' => '无救助额度',
                'enabled' => false,
            ],
            [
                'code' => 'major_rescue_limit',
                'name' => '重特大疾病救助额度已满',
                'field' => 'used_major_rescue',
                'action' => 'exclude',
                'operator' => '=',
                'value' => '100000.00',
                'remark' => '无救助额度',
                'enabled' => false,
            ],
            [
                'code' => 'large_fee_rescue_limit',
                'name' => '大额费用住院救助额度已满',
                'field' => 'used_large_fee_rescue',
                'action' => 'exclude',
                'operator' => '=',
                'value' => '60000.00',
                'remark' => '无救助额度',
                'enabled' => false,
            ],
            [
                'code' => 'identity_exclude',
                'name' => '身份类别剔除',
                'field' => 'priority_identity',
                'action' => 'exclude',
                'operator' => 'contains',
                'values' => [],
                'remark' => '对象类别不符',
                'enabled' => false,
            ],
        ];
    }

    public function normalizeWashRules(array $savedRules): array
    {
        $savedByCode = [];
        foreach ($savedRules as $rule) {
            if (!empty($rule['code'])) {
                $savedByCode[(string) $rule['code']] = $rule;
            }
        }

        $normalized = [];
        foreach ($this->defaultWashRules() as $defaultRule) {
            $saved = $savedByCode[$defaultRule['code']] ?? [];
            $rule = array_merge($defaultRule, $saved);

            if (empty($rule['action'])) {
                $rule['action'] = ($rule['type'] ?? '') === 'not_in' ? 'keep' : 'exclude';
            }
            if (empty($rule['operator'])) {
                $rule['operator'] = match ((string) ($rule['type'] ?? '')) {
                    'contains', 'in' => 'contains',
                    'not_in' => 'in',
                    'amount_equals', 'amount_equals_field' => '=',
                    default => $defaultRule['operator'] ?? '=',
                };
            }

            $normalized[] = $rule;
        }

        return $normalized;
    }

    public function matchWashRule(UnrescuedRecord $record, array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (($rule['enabled'] ?? true) === false) {
                continue;
            }

            $field = (string) ($rule['field'] ?? '');
            $value = (string) ($record->{$field} ?? '');
            $type = (string) ($rule['type'] ?? '');
            $operator = (string) ($rule['operator'] ?? $type);
            $action = (string) ($rule['action'] ?? 'exclude');
            $matched = false;

            if ($type === 'not_in') {
                $matched = !in_array($value, (array) ($rule['values'] ?? []), true);
            } elseif ($type === 'in') {
                foreach ((array) ($rule['values'] ?? []) as $needle) {
                    if ($needle !== '' && str_contains($value, (string) $needle)) {
                        $matched = true;
                        break;
                    }
                }
            } elseif ($type === 'contains') {
                foreach ((array) ($rule['values'] ?? []) as $needle) {
                    if ($needle !== '' && str_contains($value, (string) $needle)) {
                        $matched = true;
                        break;
                    }
                }
            } elseif ($type === 'amount_equals') {
                $matched = abs((float) $record->{$field} - (float) ($rule['value'] ?? 0)) < 0.005;
            } elseif ($type === 'amount_equals_field') {
                $compareField = (string) ($rule['compare_field'] ?? '');
                $matched = abs((float) $record->{$field} - (float) $record->{$compareField}) < 0.005 && (float) $record->{$compareField} > 0;
            } elseif (in_array($operator, ['in', 'contains', 'not_contains'], true)) {
                $matched = $operator === 'not_contains';
                foreach ((array) ($rule['values'] ?? []) as $needle) {
                    if ($needle === '') {
                        continue;
                    }
                    $hit = $operator === 'in' ? $value === (string) $needle : str_contains($value, (string) $needle);
                    if ($operator === 'not_contains' && $hit) {
                        $matched = false;
                        break;
                    }
                    if ($operator !== 'not_contains' && $hit) {
                        $matched = true;
                        break;
                    }
                }
            } elseif (in_array($operator, ['=', '>', '<', '>=', '<='], true)) {
                $left = (float) $record->{$field};
                $right = array_key_exists('compare_field', $rule) && $rule['compare_field'] !== ''
                    ? (float) $record->{(string) $rule['compare_field']}
                    : (float) ($rule['value'] ?? 0);
                $matched = match ($operator) {
                    '=' => abs($left - $right) < 0.005,
                    '>' => $left > $right,
                    '<' => $left < $right,
                    '>=' => $left >= $right,
                    '<=' => $left <= $right,
                    default => false,
                };
            }

            $shouldExclude = $action === 'keep' ? !$matched : $matched;
            if ($shouldExclude) {
                return $rule;
            }
        }

        return null;
    }
}
