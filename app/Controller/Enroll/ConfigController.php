<?php

declare(strict_types=1);

namespace App\Controller\Enroll;

use App\Controller\AbstractController;
use App\Model\Enroll\EnrollConfig;
use App\Model\Enroll\EnrollIdentityAmountConfig;
use App\Service\CsvReaderService;
use App\Service\Enroll\EnrollLedgerService;
use App\Service\OperationLogService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Contract\RequestInterface;

class ConfigController extends AbstractController
{
    public function __construct(
        private readonly EnrollLedgerService $ledgerService,
        private readonly OperationLogService $operationLogService,
    ) {
        parent::__construct();
    }

    public function index(RequestInterface $request)
    {
        $year = (int) $request->input('year', date('Y'));
        $type = (string) $request->input('type', EnrollConfig::TYPE_SUBSIDY);
        $page = max((int) $request->input('page', 1), 1);
        $pageSize = max((int) $request->input('page_size', 20), 1);

        if ($type === 'identity_amount') {
            $query = EnrollIdentityAmountConfig::query()->where('year', $year);
            $total = $query->count();
            $list = $query->orderBy('sort')->orderBy('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
        } else {
            $query = EnrollConfig::query()->where('year', $year)->where('config_type', $type);
            $total = $query->count();
            $list = $query->orderBy('priority')->orderBy('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
        }

        return $this->success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ], '获取成功');
    }

    public function store(RequestInterface $request)
    {
        $type = (string) $request->input('type', $request->input('config_type', EnrollConfig::TYPE_SUBSIDY));
        $data = $this->buildConfigData($request, $type);

        if ($type === 'identity_amount') {
            $this->ledgerService->saveIdentityAmountConfigByProgramRule($data);
        } else {
            $this->ledgerService->saveConfigByProgramRule($data);
        }

        $this->operationLogService->record('参保配置', '保存', 'enroll_config', null, '保存参保配置', [
            'year' => $data['year'],
            'type' => $type,
        ]);

        return $this->success(null, '保存成功');
    }

    public function update(int $id, RequestInterface $request)
    {
        $type = (string) $request->input('type', $request->input('config_type', ''));
        $model = $type === 'identity_amount'
            ? EnrollIdentityAmountConfig::find($id)
            : EnrollConfig::find($id);

        if (!$model) {
            return $this->error('配置不存在', 404);
        }

        $data = $this->sanitizeUpdateData($request, $type, $model);
        $model->update($data);
        $this->operationLogService->record('参保配置', '编辑', 'enroll_config', (string) $id, '编辑参保配置', [
            'type' => $type,
        ]);

        return $this->success($model->refresh(), '保存成功');
    }

    private function sanitizeUpdateData(RequestInterface $request, string $type, $model): array
    {
        $data = $request->all();
        unset($data['id'], $data['type'], $data['created_at'], $data['updated_at'], $data['deleted_at']);

        if ($type === 'identity_amount') {
            $included = $this->normalizeRequestIdentityList($data['included_identities'] ?? $model->included_identities ?? []);
            return [
                'year' => (int) ($data['year'] ?? $model->year ?? date('Y')),
                'special_identity' => trim((string) ($data['special_identity'] ?? $model->special_identity ?? '')),
                'included_identities' => $included,
                'paid_amount' => $this->ledgerService->parseAmount($data['paid_amount'] ?? $model->paid_amount ?? 0),
                'status' => (int) ($data['status'] ?? $model->status ?? 1),
                'sort' => (int) ($data['sort'] ?? $model->sort ?? 0),
                'remark' => $data['remark'] ?? $model->remark ?? null,
            ];
        }

        $configType = $data['config_type'] ?? $type;
        $configType = $configType !== ''
            ? $configType
            : (string) ($model->config_type ?? EnrollConfig::TYPE_SUBSIDY);

        $included = $this->normalizeRequestIdentityList($data['included_identities'] ?? $model->included_identities ?? []);

        $base = [
            'year' => (int) ($data['year'] ?? $model->year ?? date('Y')),
            'config_type' => $configType,
            'priority' => (int) ($data['priority'] ?? $model->priority ?? 0),
            'identity_name' => trim((string) ($data['identity_name'] ?? $model->identity_name ?? '')),
            'status' => (int) ($data['status'] ?? $model->status ?? 1),
            'remark' => $data['remark'] ?? $model->remark ?? null,
        ];

        if ($configType === EnrollConfig::TYPE_MEDICAL) {
            return $base + [
                'insurance_level' => null,
                'subsidy_standard' => null,
                'personal_amount' => 0,
                'subsidy_amount' => 0,
                'included_identities' => $included,
            ];
        }

        return $base + [
            'insurance_level' => $data['insurance_level'] ?? $model->insurance_level ?? null,
            'subsidy_standard' => $data['subsidy_standard'] ?? $model->subsidy_standard ?? null,
            'personal_amount' => $this->ledgerService->parseAmount($data['personal_amount'] ?? $model->personal_amount ?? 0),
            'subsidy_amount' => $this->ledgerService->parseAmount($data['subsidy_amount'] ?? $model->subsidy_amount ?? 0),
            'included_identities' => $included,
        ];
    }

    public function destroy(int $id, RequestInterface $request)
    {
        $type = (string) $request->input('type', $request->input('config_type', ''));
        $model = $type === 'identity_amount'
            ? EnrollIdentityAmountConfig::find($id)
            : EnrollConfig::find($id);

        if (!$model) {
            return $this->error('配置不存在', 404);
        }

        $model->delete();
        $this->operationLogService->record('参保配置', '删除', 'enroll_config', (string) $id, '删除参保配置', [
            'type' => $type,
        ]);

        return $this->success(null, '删除成功');
    }

    public function cloneYear(RequestInterface $request)
    {
        $fromYear = (int) $request->input('from_year', 0);
        $toYear = (int) $request->input('to_year', 0);
        $overwrite = (bool) $request->input('overwrite', false);
        $types = $this->normalizeCloneTypes($request->input('types', [
            EnrollConfig::TYPE_SUBSIDY,
            EnrollConfig::TYPE_MEDICAL,
            'identity_amount',
        ]));
        if ($fromYear <= 0 || $toYear <= 0 || $fromYear === $toYear) {
            return $this->error('请选择正确的来源年份和目标年份', 400);
        }
        if ($types === []) {
            return $this->error('请至少选择一项要克隆的配置', 400);
        }

        Db::beginTransaction();
        try {
            $configTypes = array_values(array_intersect($types, [EnrollConfig::TYPE_SUBSIDY, EnrollConfig::TYPE_MEDICAL]));
            if ($overwrite) {
                if ($configTypes !== []) {
                    EnrollConfig::query()
                        ->where('year', $toYear)
                        ->whereIn('config_type', $configTypes)
                        ->delete();
                }
                if (in_array('identity_amount', $types, true)) {
                    EnrollIdentityAmountConfig::query()->where('year', $toYear)->delete();
                }
            }

            $count = 0;
            if ($configTypes !== []) {
                foreach (EnrollConfig::query()->where('year', $fromYear)->whereIn('config_type', $configTypes)->get() as $config) {
                    $data = $config->toArray();
                    unset($data['id'], $data['created_at'], $data['updated_at'], $data['deleted_at']);
                    $data['year'] = $toYear;
                    $this->ledgerService->saveConfigByProgramRule($data);
                    $count++;
                }
            }
            if (in_array('identity_amount', $types, true)) {
                foreach (EnrollIdentityAmountConfig::query()->where('year', $fromYear)->get() as $config) {
                    $data = $config->toArray();
                    unset($data['id'], $data['created_at'], $data['updated_at'], $data['deleted_at']);
                    $data['year'] = $toYear;
                    $this->ledgerService->saveIdentityAmountConfigByProgramRule($data);
                    $count++;
                }
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            return $this->error('克隆失败：' . $e->getMessage(), 500);
        }

        $this->operationLogService->record('参保配置', '克隆年度配置', 'enroll_config', null, '克隆参保年度配置', compact('fromYear', 'toYear', 'overwrite', 'types', 'count'));
        return $this->success(['count' => $count, 'types' => $types], '克隆成功');
    }

    private function normalizeCloneTypes($types): array
    {
        if (is_string($types)) {
            $types = preg_split('/[、,，;；|\\s]+/u', $types) ?: [];
        }
        if (!is_array($types)) {
            return [];
        }

        $allowed = [EnrollConfig::TYPE_SUBSIDY, EnrollConfig::TYPE_MEDICAL, 'identity_amount'];
        return array_values(array_unique(array_filter(array_map(
            fn ($type) => trim((string) $type),
            $types
        ), fn ($type) => in_array($type, $allowed, true))));
    }

    public function import(RequestInterface $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->error('无效的文件', 400);
        }
        if (strtolower((string) $file->getExtension()) !== 'csv') {
            return $this->error('当前阶段仅支持 CSV 文件', 400);
        }

        $year = (int) $request->input('year', date('Y'));
        $attachmentType = (string) $request->input('attachment_type', 'attachment1_config');
        $uploadDir = BASE_PATH . '/storage/uploads/enroll/configs/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $filePath = $uploadDir . $attachmentType . '_' . date('YmdHis') . '_' . uniqid() . '.csv';
        $file->moveTo($filePath);

        $csvReader = new CsvReaderService();
        $headers = $csvReader->getHeaders($filePath);
        $missingHeaders = $this->missingRequiredImportHeaders($headers, $attachmentType);
        if ($missingHeaders !== []) {
            $debugHeaders = $headers !== [] ? implode('、', $headers) : '[未识别到表头]';
            return $this->error(
                '文件格式不正确，请使用对应导入模板。缺少表头：' . implode('、', $missingHeaders) . '。当前识别到的表头为：' . $debugHeaders,
                400
            );
        }

        $result = ['total' => 0, 'success' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $csvReader->read($filePath, function (array $row, int $rowIndex) use ($year, $attachmentType, &$result) {
            try {
                $result['total']++;
                if ($attachmentType === 'attachment7_amount_config') {
                    $saved = $this->ledgerService->saveIdentityAmountConfigByProgramRule($this->buildIdentityAmountImportData($row, $year));
                } else {
                    $saved = $this->ledgerService->saveConfigByProgramRule($this->buildIdentityImportData($row, $year, $attachmentType));
                }
                if ($saved) {
                    $result['success']++;
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                if (count($result['errors']) < 20) {
                    $result['errors'][] = ['row' => $rowIndex + 1, 'message' => $e->getMessage()];
                }
            }
        });

        if ($result['success'] === 0 && $result['skipped'] === 0 && $result['failed'] > 0) {
            $firstError = $result['errors'][0]['message'] ?? '无有效数据';
            return $this->error('导入失败：' . $firstError, 400);
        }

        $this->operationLogService->record('参保配置', '导入', 'enroll_config', null, '导入参保配置', [
            'year' => $year,
            'attachment_type' => $attachmentType,
            'result' => $result,
        ]);

        return $this->success($result, '导入完成');
    }

    private function missingRequiredImportHeaders(array $headers, string $attachmentType): array
    {
        $requiredGroups = match ($attachmentType) {
            'attachment2_config' => [
                '优先级/排序' => ['优先级', '排序', 'priority'],
                '医疗救助身份' => ['医疗救助身份'],
                '包含参保身份' => ['包含参保身份', '包含身份', 'included_identities'],
            ],
            'attachment7_amount_config' => [
                '特殊人员身份' => ['特殊人员身份', '身份类别', 'special_identity'],
                '实缴金额' => ['实缴金额', '个人实缴金额', 'paid_amount'],
            ],
            default => [
                '优先级/排序' => ['优先级', '排序', 'priority'],
                '资助参保身份' => ['资助参保身份'],
                '资助档次' => ['资助档次', '参保档次', '档次', 'insurance_level'],
                '资助标准' => ['资助标准', 'subsidy_standard'],
                '个人实缴金额' => ['个人实缴金额', '居民医保缴费金额', 'personal_amount'],
                '资助代缴金额' => ['资助代缴金额', '资助金额', 'subsidy_amount'],
            ],
        };

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

        return $missing;
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/[\x{FEFF}\x{200B}]/u', '', $header) ?? $header;
        $header = preg_replace('/\s+/u', '', $header) ?? $header;
        return trim($header);
    }

    private function buildConfigData(RequestInterface $request, string $type): array
    {
        $included = $this->normalizeRequestIdentityList($request->input('included_identities', []));

        if ($type === 'identity_amount') {
            return [
                'year' => (int) $request->input('year', date('Y')),
                'special_identity' => trim((string) $request->input('special_identity', '')),
                'included_identities' => $included,
                'paid_amount' => $this->ledgerService->parseAmount($request->input('paid_amount', 0)),
                'status' => (int) $request->input('status', 1),
                'sort' => (int) $request->input('sort', 0),
                'remark' => $request->input('remark'),
            ];
        }

        $base = [
            'year' => (int) $request->input('year', date('Y')),
            'config_type' => $type,
            'priority' => (int) $request->input('priority', 0),
            'identity_name' => trim((string) $request->input('identity_name', '')),
            'status' => (int) $request->input('status', 1),
            'remark' => $request->input('remark'),
        ];

        if ($type === EnrollConfig::TYPE_MEDICAL) {
            return $base + [
                'insurance_level' => null,
                'subsidy_standard' => null,
                'personal_amount' => 0,
                'subsidy_amount' => 0,
                'included_identities' => $included,
            ];
        }

        return $base + [
            'insurance_level' => $request->input('insurance_level'),
            'subsidy_standard' => $request->input('subsidy_standard'),
            'personal_amount' => $this->ledgerService->parseAmount($request->input('personal_amount', 0)),
            'subsidy_amount' => $this->ledgerService->parseAmount($request->input('subsidy_amount', 0)),
            'included_identities' => $included,
        ];
    }

    private function normalizeRequestIdentityList(mixed $value): array
    {
        if (is_string($value)) {
            return $this->ledgerService->normalizeIdentityList($value);
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $value
        ), fn ($item) => $item !== '')));
    }

    private function buildIdentityImportData(array $row, int $year, string $attachmentType): array
    {
        $type = $attachmentType === 'attachment2_config' ? EnrollConfig::TYPE_MEDICAL : EnrollConfig::TYPE_SUBSIDY;
        $identity = $this->ledgerService->pickValue($row, ['资助参保身份', '医疗救助身份', '身份名称', '身份', 'identity_name']);
        if ($identity === '') {
            throw new \RuntimeException('身份名称不能为空');
        }

        $base = [
            'year' => $year,
            'config_type' => $type,
            'priority' => (int) $this->ledgerService->pickValue($row, ['优先级', '排序', 'priority'], '0'),
            'identity_name' => $identity,
            'status' => 1,
        ];

        if ($type === EnrollConfig::TYPE_MEDICAL) {
            return $base + [
                'insurance_level' => null,
                'subsidy_standard' => null,
                'personal_amount' => 0,
                'subsidy_amount' => 0,
                'included_identities' => $this->ledgerService->normalizeIdentityList($this->ledgerService->pickValue($row, ['包含参保身份', '包含身份', 'included_identities'])),
            ];
        }

        return $base + [
            'insurance_level' => $this->ledgerService->pickValue($row, ['资助档次', '参保档次', '档次', 'insurance_level']) ?: null,
            'subsidy_standard' => $this->ledgerService->pickValue($row, ['资助标准', 'subsidy_standard']) ?: null,
            'personal_amount' => $this->ledgerService->parseAmount($this->ledgerService->pickValue($row, ['个人实缴金额', '居民医保缴费金额', 'personal_amount'])),
            'subsidy_amount' => $this->ledgerService->parseAmount($this->ledgerService->pickValue($row, ['资助代缴金额', '资助金额', 'subsidy_amount'])),
        ];
    }

    private function buildIdentityAmountImportData(array $row, int $year): array
    {
        $identity = $this->ledgerService->pickValue($row, ['特殊人员身份', '身份类别', '身份', 'special_identity']);
        if ($identity === '') {
            throw new \RuntimeException('特殊人员身份不能为空');
        }

        return [
            'year' => $year,
            'special_identity' => $identity,
            'paid_amount' => $this->ledgerService->parseAmount($this->ledgerService->pickValue($row, ['实缴金额', '个人实缴金额', 'paid_amount'])),
            'status' => 1,
            'sort' => (int) $this->ledgerService->pickValue($row, ['排序', 'sort'], '0'),
        ];
    }
}
