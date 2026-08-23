<?php

declare(strict_types=1);

namespace App\Service\Unrescued;

use App\Model\Town;
use App\Model\Unrescued\UnrescuedDiseaseConfig;
use App\Model\Unrescued\UnrescuedRecord;
use Carbon\Carbon;

class UnrescuedRecordService
{
    public const STATUS_PENDING = '待处理';
    public const STATUS_NO_AMOUNT = '无救助金额';
    public const STATUS_NOTICE_1 = '拟通知1';
    public const STATUS_NOTICE_2 = '拟通知2';
    public const STATUS_NO_NOTICE = self::STATUS_NOTICE_1;
    public const STATUS_TO_NOTICE = self::STATUS_NOTICE_2;
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
    public const MATCHED = '已匹配';
    public const UNMATCHED = '未匹配';
    public const PRIORITY_WASH_RULE_CODE = 'outpatient_major_disease';

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

    private function compareAmount(string|float|int $left, string|float|int $right): int
    {
        $left = $this->parseAmount($left);
        $right = $this->parseAmount($right);
        if (function_exists('bccomp')) {
            return bccomp($left, $right, 2);
        }

        return (float) $left <=> (float) $right;
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
        if ($this->compareAmount($amount, 0) <= 0) {
            return self::STATUS_NO_AMOUNT;
        }

        if ($this->compareAmount($amount, 300) <= 0) {
            return self::STATUS_NOTICE_1;
        }

        return self::STATUS_NOTICE_2;
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

    public function buildTownLookupMap(): array
    {
        $map = [
            'exact' => [],
            'normalized' => [],
            'contains' => [],
        ];

        $towns = Town::query()
            ->select(['id', 'name', 'code'])
            ->get();

        foreach ($towns as $town) {
            $id = (int) $town->id;
            foreach ([(string) $town->name, (string) $town->code] as $value) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }

                $map['exact'][$value] = $id;
                $normalized = $this->normalizeTownName($value);
                if ($normalized !== '') {
                    $map['normalized'][$normalized] = $id;
                    $map['contains'][$normalized] = $id;
                }
            }
        }

        uksort($map['contains'], static fn (string $left, string $right) => mb_strlen($right) <=> mb_strlen($left));

        return $map;
    }

    public function resolveTownIdFromMap(?string $townName, array $townLookupMap): int
    {
        $townName = trim((string) $townName);
        if ($townName === '') {
            return 0;
        }

        if (isset($townLookupMap['exact'][$townName])) {
            return (int) $townLookupMap['exact'][$townName];
        }

        $normalized = $this->normalizeTownName($townName);
        if ($normalized !== '' && isset($townLookupMap['normalized'][$normalized])) {
            return (int) $townLookupMap['normalized'][$normalized];
        }

        foreach (($townLookupMap['contains'] ?? []) as $candidate => $id) {
            if ($candidate !== '' && mb_strpos($normalized, (string) $candidate) !== false) {
                return (int) $id;
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

        foreach (['status', 'exclude_status', 'reimbursement_status', 'match_status', 'town_id', 'medical_category', 'exclude_rule_code', 'in_out_city'] as $field) {
            $values = $this->filterValues($filters[$field] ?? null);
            if ($values === []) {
                continue;
            }
            if (count($values) === 1) {
                $query->where($field, $values[0]);
            } else {
                $query->whereIn($field, $values);
            }
        }

        foreach (['disease_code', 'disease_name'] as $field) {
            $values = $this->filterValues($filters[$field] ?? null);
            if ($values !== []) {
                $query->where(function ($subQuery) use ($field, $values) {
                    foreach ($values as $value) {
                        $subQuery->orWhere($field, 'like', "%{$value}%");
                    }
                });
            }
        }
        $this->applyDiseaseKeywordFilter($query, $filters['disease_keyword'] ?? null);

        $identities = $this->filterValues($filters['priority_identity'] ?? null);
        if ($identities !== []) {
            if (count($identities) === 1) {
                $query->where('priority_identity', $identities[0]);
            } else {
                $query->whereIn('priority_identity', $identities);
            }
        }

        $hospitalName = trim((string) ($filters['hospital_name'] ?? ''));
        if ($hospitalName !== '') {
            $query->where('hospital_name', 'like', "%{$hospitalName}%");
        }

        $remark = trim((string) ($filters['remark'] ?? $filters['remark_keyword'] ?? ''));
        if ($remark !== '') {
            $query->where('remark', 'like', "%{$remark}%");
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->whereBlind('name', $keyword)
                    ->orWhere(function ($idQuery) use ($keyword) {
                        $idQuery->whereBlind('id_card', $keyword);
                    })
                    ->orWhere('sequence_no', 'like', "%{$keyword}%");
            });
        }
    }

    public function filterValues(mixed $value): array
    {
        if (is_array($value)) {
            $values = $value;
        } else {
            $text = trim((string) $value);
            if ($text === '') {
                return [];
            }
            $values = str_contains($text, ',') ? explode(',', $text) : [$text];
        }

        $values = array_map(static fn ($item) => trim((string) $item), $values);
        return array_values(array_filter(array_unique($values), static fn ($item) => $item !== ''));
    }

    public function applyDiseaseKeywordFilter($query, mixed $value): void
    {
        $values = $this->filterValues($value);
        if ($values === []) {
            return;
        }

        $query->where(function ($subQuery) use ($values) {
            foreach ($values as $keyword) {
                $subQuery->orWhere('disease_code', 'like', "%{$keyword}%")
                    ->orWhere('disease_name', 'like', "%{$keyword}%");
            }
        });
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
                'code' => self::PRIORITY_WASH_RULE_CODE,
                'name' => '门诊重大疾病匹配',
                'field' => 'medical_category',
                'action' => 'keep',
                'operator' => 'compound',
                'medical_categories' => ['门诊慢特病', '造口袋门诊'],
                'disease_codes' => ['M00500'],
                'remark' => '门诊重大疾病匹配',
                'condition_text' => '进入报销金额 > 300，且医疗类别和病种编码命中配置',
                'enabled' => true,
            ],
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

    public function refundWashRules(): array
    {
        return array_merge($this->defaultWashRules(), [
            [
                'code' => 'total_fee_offset_pair',
                'name' => '总费用正负抵消',
                'field' => 'total_fee',
                'action' => 'exclude',
                'operator' => 'custom',
                'remark' => '正负费用抵消',
                'condition_text' => '同一清算期 + 同一身份证号 + 总费用绝对值相同，正负成对剔除',
                'enabled' => true,
            ],
            [
                'code' => 'medical_assistance_positive',
                'name' => '医疗救助金额大于0',
                'field' => 'medical_assistance_pay',
                'action' => 'exclude',
                'operator' => '>',
                'value' => '0.00',
                'remark' => '已享受医疗救助',
                'condition_text' => '医疗救助金额 > 0',
                'enabled' => true,
            ],
        ]);
    }

    public function normalizeWashRules(array $savedRules, ?array $defaultRules = null): array
    {
        if (isset($savedRules['rules']) && is_array($savedRules['rules'])) {
            $savedRules = $savedRules['rules'];
        }

        $defaultRules ??= $this->defaultWashRules();
        $savedByCode = [];
        foreach ($savedRules as $rule) {
            if (!empty($rule['code'])) {
                $savedByCode[(string) $rule['code']] = $rule;
            }
        }

        $normalized = [];
        foreach ($defaultRules as $defaultRule) {
            $saved = $savedByCode[$defaultRule['code']] ?? [];
            $rule = array_merge($defaultRule, $saved);

            if ($defaultRule['code'] === self::PRIORITY_WASH_RULE_CODE) {
                if (($rule['remark'] ?? '') === '门诊重大疾病匹配，标记为拟通知2') {
                    $rule['remark'] = $defaultRule['remark'];
                }
                if (($rule['condition_text'] ?? '') === '医疗类别命中配置，且病种编码命中指定编码或已启用的重大疾病编码库') {
                    $rule['condition_text'] = $defaultRule['condition_text'];
                }
            }

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

    public function hasEnabledWashRules(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            if (filter_var($rule['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }

    public function priorityWashRule(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if ((string) ($rule['code'] ?? '') === self::PRIORITY_WASH_RULE_CODE) {
                return $rule;
            }
        }

        return null;
    }

    public function withoutPriorityWashRule(array $rules): array
    {
        return array_values(array_filter(
            $rules,
            static fn (array $rule): bool => (string) ($rule['code'] ?? '') !== self::PRIORITY_WASH_RULE_CODE
        ));
    }

    public function enabledMajorDiseaseCodes(): array
    {
        return UnrescuedDiseaseConfig::query()
            ->where('status', 1)
            ->pluck('disease_code')
            ->map(fn ($code) => $this->normalizeDiseaseCode((string) $code))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function matchesPriorityWashRule(object $record, ?array $rule, array $enabledMajorDiseaseCodes): bool
    {
        if (!$rule || !filter_var($rule['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        if ($this->compareAmount((string) ($record->calc_reimbursement_amount ?? '0'), 300) <= 0) {
            return false;
        }

        $medicalCategory = trim((string) ($record->medical_category ?? ''));
        $medicalCategories = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($rule['medical_categories'] ?? [])
        )));
        if ($medicalCategory === '' || !in_array($medicalCategory, $medicalCategories, true)) {
            return false;
        }

        $diseaseCode = $this->normalizeDiseaseCode((string) ($record->disease_code ?? ''));
        if ($diseaseCode === '') {
            return false;
        }

        $configuredCodes = array_map(
            fn ($code): string => $this->normalizeDiseaseCode((string) $code),
            (array) ($rule['disease_codes'] ?? [])
        );
        $libraryCodes = array_map(
            fn ($code): string => $this->normalizeDiseaseCode((string) $code),
            $enabledMajorDiseaseCodes
        );

        return in_array($diseaseCode, array_unique(array_filter(array_merge($configuredCodes, $libraryCodes))), true);
    }

    public function priorityWashAction(?array $rule): string
    {
        return (string) ($rule['action'] ?? 'keep') === 'exclude' ? 'exclude' : 'keep';
    }

    public function screeningStatus(object $record): string
    {
        $currentStatus = (string) ($record->status ?? '');
        if ($this->shouldKeepWorkflowStatus($currentStatus)) {
            return $currentStatus;
        }

        return $this->decideStatus((string) ($record->calc_reimbursement_amount ?? '0'));
    }

    public function normalizeDiseaseCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public function matchWashRule(object $record, array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (!filter_var($rule['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
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
