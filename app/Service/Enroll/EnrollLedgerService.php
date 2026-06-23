<?php

declare(strict_types=1);

namespace App\Service\Enroll;

use App\Model\Enroll\EnrollConfig;
use App\Model\Enroll\EnrollIdentityAmountConfig;
use App\Model\Enroll\EnrollLedger;
use App\Model\Enroll\EnrollLedgerSnapshot;
use Hyperf\Database\Model\Builder;
use Hyperf\DbConnection\Db;

class EnrollLedgerService
{
    public const MODULE = 'enroll';
    public const CHANGE_NEW = '新增';
    public const CHANGE_CHANGED = '变更';
    public const CHANGE_CANCELLED = '取消';
    public const INSURANCE_CATEGORY_UNMATCHED = '未匹配';

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

    public function normalizePeriod(string $period): string
    {
        $period = trim($period);
        if ($period === '') {
            return '';
        }

        if (preg_match('/^(\d{4})[-\/年.]?(\d{1,2})/', $period, $matches)) {
            return $matches[1] . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        }

        if (preg_match('/^(\d{4})(\d{2})$/', $period, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }

        return $period;
    }

    public function normalizeDateText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})[-\/年.](\d{1,2})(?:[-\/月.](\d{1,2}))?/u', $value, $matches)) {
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            if (isset($matches[3]) && $matches[3] !== '') {
                return $matches[1] . '-' . $month . '-' . str_pad($matches[3], 2, '0', STR_PAD_LEFT);
            }

            return $matches[1] . '-' . $month;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }

        if (preg_match('/^(\d{4})(\d{2})$/', $value, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }

        return $value;
    }

    public function monthPart(?string $value): string
    {
        $value = $this->normalizeDateText((string) $value);
        if (preg_match('/^(\d{4}-\d{2})/', $value, $matches)) {
            return $matches[1];
        }

        return '';
    }

    public function periodYear(string $period): int
    {
        $period = $this->normalizePeriod($period);
        if (preg_match('/^(\d{4})-/', $period, $matches)) {
            return (int) $matches[1];
        }

        return (int) date('Y');
    }

    public function applyFilters(Builder $query, array $filters): void
    {
        $year = (int) ($filters['year'] ?? 0);
        if ($year > 0) {
            $query->where('year', $year);
        }

        $period = $this->normalizePeriod((string) ($filters['period'] ?? $filters['last_attachment3_period'] ?? ''));
        if ($period !== '') {
            $query->where('last_attachment3_period', $period);
        }

        foreach ([
            'town_name',
            'medical_identity',
            'subsidy_identity',
            'change_status',
            'is_insured',
            'is_eligible_for_subsidy',
            'is_subsidy_obtained',
            'subsidy_method',
        ] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $query->where($field, $value);
            }
        }

        $insuranceCategory = trim((string) ($filters['insurance_category'] ?? ''));
        if ($insuranceCategory === self::INSURANCE_CATEGORY_UNMATCHED) {
            $query->where(function ($subQuery) {
                $subQuery->whereNull('insurance_category')
                    ->orWhere('insurance_category', '')
                    ->orWhere('insurance_category', self::INSURANCE_CATEGORY_UNMATCHED);
            });
        } elseif ($insuranceCategory !== '') {
            $query->where('insurance_category', $insuranceCategory);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($subQuery) use ($keyword) {
                $subQuery->where('name', 'like', "%{$keyword}%")
                    ->orWhere('id_card', 'like', "%{$keyword}%")
                    ->orWhere('village_name', 'like', "%{$keyword}%");
            });
        }
    }

    public function applyTownScope(Builder $query, ?string $townName): void
    {
        $townName = trim((string) $townName);
        if ($townName !== '') {
            $query->where('town_name', $townName);
        }
    }

    public function configRows(int $year, string $type): array
    {
        return EnrollConfig::query()
            ->where('year', $year)
            ->where('config_type', $type)
            ->where('status', 1)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public function saveConfigByProgramRule(array $data): bool
    {
        $query = EnrollConfig::query()
            ->where('year', (int) $data['year'])
            ->where('config_type', (string) $data['config_type'])
            ->where('identity_name', (string) $data['identity_name']);

        if (($data['config_type'] ?? '') === EnrollConfig::TYPE_SUBSIDY) {
            $query->where('insurance_level', (string) ($data['insurance_level'] ?? ''));
        }

        $existing = $query->orderBy('id')->first();
        if ($existing) {
            if (!$this->hasModelChanges($existing, $data)) {
                return false;
            }
            $existing->update($data);
            return true;
        }

        EnrollConfig::create($data);
        return true;
    }

    public function saveIdentityAmountConfigByProgramRule(array $data): bool
    {
        $existing = EnrollIdentityAmountConfig::query()
            ->where('year', (int) $data['year'])
            ->where('special_identity', (string) $data['special_identity'])
            ->where('paid_amount', $this->parseAmount($data['paid_amount'] ?? 0))
            ->orderBy('id')
            ->first();

        if ($existing) {
            if (!$this->hasModelChanges($existing, $data)) {
                return false;
            }
            $existing->update($data);
            return true;
        }

        EnrollIdentityAmountConfig::create($data);
        return true;
    }

    private function hasModelChanges($model, array $data): bool
    {
        foreach ($data as $field => $value) {
            if ($this->normalizeComparableValue($model->{$field} ?? null) !== $this->normalizeComparableValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparableValue($value): string
    {
        if ($value === null) {
            return '__NULL__';
        }
        if (is_array($value)) {
            return json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
        }

        return trim((string) $value);
    }

    public function normalizeIdentityList(string $value): array
    {
        $value = trim($value);
        if ($value === '' || in_array($value, ['-', '无', '暂无', '无配置'], true)) {
            return [];
        }

        $parts = preg_split('/[、,，;；|\/\s]+/u', $value) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts))));
    }

    public function resolveIdentityRecords(array $rawIdentities, array $configs): array
    {
        $records = [];
        $configMap = [];
        foreach ($configs as $config) {
            $name = trim((string) ($config['identity_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $item = [
                'identity' => $name,
                'priority' => (int) ($config['priority'] ?? 9999),
            ];
            $configMap[$name] = $item;
            $configMap[$this->identityMatchKey($name)] = $item;
            foreach ($this->identityAliases($name) as $alias) {
                $configMap[$alias] = $item;
                $configMap[$this->identityMatchKey($alias)] = $item;
            }

            $includedIdentities = $config['included_identities'] ?? [];
            if (is_string($includedIdentities)) {
                $decoded = json_decode($includedIdentities, true);
                $includedIdentities = is_array($decoded) ? $decoded : $this->normalizeIdentityList($includedIdentities);
            }
            if (!is_array($includedIdentities)) {
                $includedIdentities = [];
            }
            foreach ($includedIdentities as $alias) {
                $alias = trim((string) $alias);
                if ($alias === '') {
                    continue;
                }
                if (!isset($configMap[$alias]) || (int) $configMap[$alias]['priority'] > $item['priority']) {
                    $configMap[$alias] = $item;
                    $configMap[$this->identityMatchKey($alias)] = $item;
                }
            }
        }

        foreach ($rawIdentities as $identity) {
            $identity = trim((string) $identity);
            if ($identity === '') {
                continue;
            }

            $matched = $configMap[$identity] ?? $configMap[$this->identityMatchKey($identity)] ?? [
                'identity' => $identity,
                'priority' => 9999,
            ];
            $records[$matched['identity']] = $matched;
        }

        usort($records, fn ($left, $right) => ($left['priority'] <=> $right['priority']) ?: strcmp($left['identity'], $right['identity']));
        return array_values($records);
    }

    private function identityMatchKey(string $identity): string
    {
        $identity = trim($identity);
        $identity = str_replace(['（', '）', '，', '、', '－', '—', '～', '~'], ['(', ')', ',', ',', '-', '-', '-', '-'], $identity);
        $identity = str_replace(['一、二级', '一,二级', '1~2级', '1—2级', '1－2级'], ['1-2级', '1-2级', '1-2级', '1-2级', '1-2级'], $identity);
        $identity = preg_replace('/\s+/u', '', $identity) ?? $identity;

        return mb_strtolower($identity);
    }

    private function identityAliases(string $identity): array
    {
        $groups = [
            ['低保对象', '民政城乡低保对象', '低保中重病重残对象', '低保中重残重病人员-7019'],
            ['城乡重度（1-2级）残疾人员', '城乡重度(1-2级)残疾人员', '城乡重度（1~2级）残疾人员', '城乡重度(1~2级)残疾人员', '民政城乡重度(一、二级)残疾人员', '城乡重度（1-2级）残疾人员-7069'],
            ['事实无人抚养儿童', '事实无人抚养儿童-7141'],
        ];

        $key = $this->identityMatchKey($identity);
        foreach ($groups as $group) {
            $groupKeys = array_map(fn ($item) => $this->identityMatchKey($item), $group);
            if (in_array($key, $groupKeys, true)) {
                return array_values(array_diff($group, [$identity]));
            }
        }

        return [];
    }

    public function preferredIdentity(array $records): ?string
    {
        return $records[0]['identity'] ?? null;
    }

    public function calculateInsuranceFields(array $data): array
    {
        $data = $this->calculateInsuranceCategory($data);
        $category = $data['insurance_category'] ?? null;
        if ($category !== null && $category !== '') {
            $insuredValues = ['居民一档', '居民二档', '大学生一档', '大学生二档', '需核实'];
            $data['is_insured'] = in_array((string) $category, $insuredValues, true) ? '是' : '否';
            if ($data['is_insured'] === '是') {
                $data['uninsured_reason'] = '无';
            } else {
                $data['uninsured_reason'] = null;
            }
        }

        $data = $this->calculateSubsidyEligibility($data);
        $eligible = (string) ($data['is_eligible_for_subsidy'] ?? '');
        if ($eligible === '待核实') {
            $data['is_subsidy_obtained'] = '待核实';
            $data['subsidy_method'] = null;
        } elseif ($eligible !== '') {
            $subsidyAmount = (float) ($data['subsidy_amount'] ?? 0);
            $data['is_subsidy_obtained'] = ($eligible === '是' && $subsidyAmount > 0) ? '是' : '否';
            $data['subsidy_method'] = $data['is_subsidy_obtained'] === '是' ? '系统资助' : '事后资助';
        }

        return $data;
    }

    private function calculateInsuranceCategory(array $data): array
    {
        $year = (int) ($data['year'] ?? 0);
        $identity = trim((string) ($data['subsidy_identity'] ?? ''));
        if ($year <= 0 || $identity === '') {
            return $data;
        }

        $personalAmount = $this->parseAmount($data['resident_payment_amount'] ?? 0);
        $subsidyAmount = $this->parseAmount($data['subsidy_amount'] ?? 0);
        $hasAmountInput = ((float) $personalAmount > 0)
            || ((float) $subsidyAmount > 0)
            || !empty($data['last_attachment5_period']);
        if (!$hasAmountInput) {
            return $data;
        }

        $configs = EnrollConfig::query()
            ->where('year', $year)
            ->where('config_type', EnrollConfig::TYPE_SUBSIDY)
            ->where('status', 1)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($configs as $config) {
            if (!$this->identityMatchesConfiguredIdentity($identity, (string) $config->identity_name, $config->included_identities ?? [])) {
                continue;
            }
            if (
                $this->parseAmount($config->personal_amount ?? 0) === $personalAmount
                && $this->parseAmount($config->subsidy_amount ?? 0) === $subsidyAmount
            ) {
                $data['insurance_category'] = $this->normalizeInsuranceLevel((string) ($config->insurance_level ?? ''));
                return $data;
            }
        }

        $data['insurance_category'] = '需核实';
        return $data;
    }

    private function normalizeInsuranceLevel(string $level): string
    {
        $level = trim($level);
        if ($level === '') {
            return '需核实';
        }
        if (str_contains($level, '居民') || str_contains($level, '大学生') || $level === '需核实') {
            return $level;
        }
        if (in_array($level, ['一档', '二档'], true)) {
            return '居民' . $level;
        }

        return $level;
    }

    private function calculateSubsidyEligibility(array $data): array
    {
        if (empty($data['payment_time'])) {
            $data['is_eligible_for_subsidy'] = '待核实';
            return $data;
        }

        $includedMonth = $this->monthPart((string) ($data['included_month'] ?? ''));
        $paymentMonth = $this->monthPart((string) ($data['payment_time'] ?? ''));
        if ($includedMonth === '' || $paymentMonth === '') {
            $data['is_eligible_for_subsidy'] = '待核实';
            return $data;
        }

        if ($includedMonth !== $paymentMonth) {
            $data['is_eligible_for_subsidy'] = '否';
            return $data;
        }

        $year = (int) ($data['year'] ?? 0);
        $identity = trim((string) ($data['subsidy_identity_obtained'] ?? ''));
        if ($year <= 0 || $identity === '') {
            $data['is_eligible_for_subsidy'] = '否';
            return $data;
        }

        $paidAmount = $this->parseAmount($data['resident_payment_amount'] ?? 0);
        $amountConfigs = EnrollIdentityAmountConfig::query()
            ->where('year', $year)
            ->where('status', 1)
            ->where('paid_amount', $paidAmount)
            ->get()
            ->filter(fn ($config) => $this->identityMatchesConfiguredIdentity(
                $identity,
                (string) $config->special_identity,
                $config->included_identities ?? []
            ));

        $data['is_eligible_for_subsidy'] = $amountConfigs->isNotEmpty() ? '是' : '否';
        return $data;
    }

    private function identityMatchesConfiguredIdentity(string $identity, string $mainIdentity, mixed $includedIdentities = []): bool
    {
        if ($this->identityEquivalent($identity, $mainIdentity)) {
            return true;
        }

        foreach ($this->normalizeIncludedIdentities($includedIdentities) as $includedIdentity) {
            if ($this->identityEquivalent($identity, $includedIdentity)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeIncludedIdentities(mixed $includedIdentities): array
    {
        if (is_string($includedIdentities)) {
            $decoded = json_decode($includedIdentities, true);
            if (is_array($decoded)) {
                $includedIdentities = $decoded;
            } else {
                return $this->normalizeIdentityList($includedIdentities);
            }
        }
        if (!is_array($includedIdentities)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $includedIdentities
        ), fn ($item) => $item !== '')));
    }

    private function identityEquivalent(string $left, string $right): bool
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || $right === '') {
            return false;
        }
        if ($this->identityMatchKey($left) === $this->identityMatchKey($right)) {
            return true;
        }

        $leftAliases = array_map(fn ($item) => $this->identityMatchKey($item), $this->identityAliases($left));
        $rightAliases = array_map(fn ($item) => $this->identityMatchKey($item), $this->identityAliases($right));
        return in_array($this->identityMatchKey($right), $leftAliases, true)
            || in_array($this->identityMatchKey($left), $rightAliases, true);
    }

    public function saveBeforeImportSnapshots(int $year, string $period, string $sourceBatch): void
    {
        EnrollLedgerSnapshot::query()
            ->where('period', $period)
            ->where('snapshot_type', EnrollLedgerSnapshot::TYPE_BEFORE_IMPORT)
            ->delete();

        EnrollLedger::query()
            ->where('year', $year)
            ->orderBy('id')
            ->chunk(1000, function ($ledgers) use ($year, $period, $sourceBatch) {
                $rows = [];
                $now = date('Y-m-d H:i:s');
                foreach ($ledgers as $ledger) {
                    $rows[] = $this->snapshotRow($ledger->toArray(), $year, $period, EnrollLedgerSnapshot::TYPE_BEFORE_IMPORT, $sourceBatch, $now);
                }
                if ($rows) {
                    Db::table('enroll_ledger_snapshots')->insert($rows);
                }
            });
    }

    public function replaceAfterImportSnapshots(int $year, string $period, string $sourceBatch, array $rows): void
    {
        EnrollLedgerSnapshot::query()
            ->where('period', $period)
            ->where('snapshot_type', EnrollLedgerSnapshot::TYPE_AFTER_IMPORT)
            ->delete();

        foreach (array_chunk($rows, 1000) as $chunk) {
            $now = date('Y-m-d H:i:s');
            $insertRows = [];
            foreach ($chunk as $row) {
                $insertRows[] = $this->snapshotRow($row, $year, $period, EnrollLedgerSnapshot::TYPE_AFTER_IMPORT, $sourceBatch, $now);
            }
            if ($insertRows) {
                Db::table('enroll_ledger_snapshots')->insert($insertRows);
            }
        }
    }

    public function replaceAfterImportSnapshotsFromLedgers(int $year, string $period, string $sourceBatch): void
    {
        EnrollLedgerSnapshot::query()
            ->where('period', $period)
            ->where('snapshot_type', EnrollLedgerSnapshot::TYPE_AFTER_IMPORT)
            ->delete();

        EnrollLedger::query()
            ->where('year', $year)
            ->orderBy('id')
            ->chunk(1000, function ($ledgers) use ($year, $period, $sourceBatch) {
                $rows = [];
                $now = date('Y-m-d H:i:s');
                foreach ($ledgers as $ledger) {
                    $rows[] = $this->snapshotRow($ledger->toArray(), $year, $period, EnrollLedgerSnapshot::TYPE_AFTER_IMPORT, $sourceBatch, $now);
                }
                if ($rows) {
                    Db::table('enroll_ledger_snapshots')->insert($rows);
                }
            });
    }

    public function upsertLedgerByProgramRule(array $data): bool
    {
        $existing = EnrollLedger::query()
            ->where('year', (int) $data['year'])
            ->where('id_card', (string) $data['id_card'])
            ->orderBy('id')
            ->first();

        if ($existing) {
            $existing->update($data);
            return false;
        }

        EnrollLedger::create($data);
        return true;
    }

    private function snapshotRow(array $data, int $year, string $period, string $type, string $sourceBatch, string $now): array
    {
        return [
            'year' => $year,
            'period' => $period,
            'snapshot_type' => $type,
            'id_card' => (string) ($data['id_card'] ?? ''),
            'name' => $data['name'] ?? null,
            'town_name' => $data['town_name'] ?? null,
            'village_name' => $data['village_name'] ?? null,
            'included_month' => $data['included_month'] ?? null,
            'payment_time' => $data['payment_time'] ?? null,
            'raw_identity' => $data['raw_identity'] ?? null,
            'medical_identity_records' => json_encode($data['medical_identity_records'] ?? [], JSON_UNESCAPED_UNICODE),
            'medical_identity' => $data['medical_identity'] ?? null,
            'subsidy_identity_records' => json_encode($data['subsidy_identity_records'] ?? [], JSON_UNESCAPED_UNICODE),
            'subsidy_identity' => $data['subsidy_identity'] ?? null,
            'source_batch' => $sourceBatch,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
