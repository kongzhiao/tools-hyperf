<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Enroll\EnrollLedger;
use App\Model\Enroll\EnrollLedgerSnapshot;
use App\Model\InsuranceData;
use App\Model\MedPersonInfo;
use App\Model\MedReimbursementDetail;
use App\Model\StatisticsData;
use App\Model\StatisticsSummary;
use App\Model\Unrescued\UnrescuedNoticeRecord;
use App\Model\Unrescued\UnrescuedRecord;
use App\Model\Unrescued\UnrescuedRefundRecord;
use App\Model\Unrescued\UnrescuedSupplementRecord;
use App\Model\YfSettlement;
use App\Service\SensitiveDataCipher;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

#[Command]
class EncryptSensitiveDataCommand extends HyperfCommand
{
    private const TABLES = [
        'insurance_data' => [InsuranceData::class, ['name', 'id_number', 'person_number']],
        'enroll_ledgers' => [EnrollLedger::class, ['name', 'id_card']],
        'enroll_ledger_snapshots' => [EnrollLedgerSnapshot::class, ['name', 'id_card']],
        'unrescued_records' => [UnrescuedRecord::class, ['name', 'id_card', 'bank_account_name', 'bank_account_no']],
        'unrescued_refund_records' => [UnrescuedRefundRecord::class, ['name', 'id_card']],
        'unrescued_notice_records' => [UnrescuedNoticeRecord::class, [
            'name',
            'id_card',
            'contact_name',
            'contact_phone',
            'bank_account_name',
            'bank_account_no',
        ]],
        'unrescued_supplement_records' => [UnrescuedSupplementRecord::class, ['name', 'id_card']],
        'med_person_info' => [MedPersonInfo::class, ['name', 'id_card']],
        'med_reimbursement_detail' => [MedReimbursementDetail::class, ['bank_account', 'account_name']],
        'statistics_data' => [StatisticsData::class, ['settlement_id', 'name', 'id_number']],
        'statistics_summery' => [StatisticsSummary::class, ['name', 'id_number', 'person_number']],
        'yf_settlements' => [YfSettlement::class, ['name', 'id_card']],
    ];

    private const TABLE_LABELS = [
        'insurance_data' => '参保数据',
        'enroll_ledgers' => '参保台账',
        'enroll_ledger_snapshots' => '参保台账快照',
        'unrescued_records' => '未救助台账',
        'unrescued_refund_records' => '未救助退费记录',
        'unrescued_notice_records' => '未救助通知记录',
        'unrescued_supplement_records' => '未救助补充记录',
        'med_person_info' => '医疗救助人员',
        'med_reimbursement_detail' => '医疗报销明细',
        'statistics_data' => '统计明细',
        'statistics_summery' => '统计汇总',
        'yf_settlements' => '优抚结算',
    ];

    private SensitiveDataCipher $cipher;

    private Redis $redis;

    private LoggerInterface $logger;

    private ConfigInterface $config;

    private string $runId = '';

    public function __construct(
        ContainerInterface $container,
        SensitiveDataCipher $cipher,
        Redis $redis,
        LoggerFactory $loggerFactory,
        ConfigInterface $config
    ) {
        $this->cipher = $cipher;
        $this->redis = $redis;
        $this->logger = $loggerFactory->get('sensitive-encryption', 'sensitive_encryption');
        $this->config = $config;
        parent::__construct('data:encrypt-sensitive');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('分批加密第一批敏感业务字段并生成盲索引');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, '仅统计待处理数据，不写入');
        $this->addOption('table', null, InputOption::VALUE_REQUIRED, '仅处理指定表');
        $this->addOption('chunk', null, InputOption::VALUE_REQUIRED, '每批处理数量', '500');
        $this->addOption('resume', null, InputOption::VALUE_NONE, '从Redis检查点继续');
        $this->addOption('verify', null, InputOption::VALUE_NONE, '处理后执行密文与盲索引校验');
    }

    public function handle()
    {
        $this->runId = date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $startedAt = microtime(true);
        $selectedTable = trim((string) ($this->input->getOption('table') ?? ''));
        $chunkSize = min(5000, max(1, (int) $this->input->getOption('chunk')));
        $dryRun = (bool) $this->input->getOption('dry-run');
        $resume = (bool) $this->input->getOption('resume');
        $verify = (bool) $this->input->getOption('verify');
        $stdoutLoggerConfig = $this->config->get(StdoutLoggerInterface::class, ['log_level' => []]);
        $this->config->set(StdoutLoggerInterface::class, [
            'log_level' => [
                LogLevel::EMERGENCY,
                LogLevel::ALERT,
                LogLevel::CRITICAL,
                LogLevel::ERROR,
                LogLevel::WARNING,
            ],
        ]);

        try {
            $this->report('info', sprintf(
                '任务开始｜模式：%s｜范围：%s',
                $dryRun ? '预演检查' : '正式加密',
                $selectedTable !== '' ? (self::TABLE_LABELS[$selectedTable] ?? $selectedTable) : '全部业务'
            ));

            if ($selectedTable !== '' && !isset(self::TABLES[$selectedTable])) {
                throw new \InvalidArgumentException('不支持的表名');
            }

            $this->preflight();
            $this->report('info', '加解密和密钥预检通过');

            $tables = $selectedTable === '' ? self::TABLES : [$selectedTable => self::TABLES[$selectedTable]];
            $preparedTables = [];
            $globalTotal = 0;
            $globalProcessed = 0;
            $this->report('info', '正在统计任务总量');
            foreach ($tables as $table => [$modelClass, $fields]) {
                if (!Schema::hasTable($table)) {
                    $this->report('warning', '数据表不存在，已跳过', ['table' => $table]);
                    continue;
                }
                $this->assertColumns($table, $modelClass, $fields);
                $lastId = $resume
                    ? (int) $this->redis->get('data:encrypt-sensitive:checkpoint:' . $table)
                    : 0;
                $tableTotal = (int) $modelClass::query()->where('id', '>', $lastId)->count();
                $preparedTables[$table] = [$modelClass, $fields, $tableTotal];
                $globalTotal += $tableTotal;
            }

            foreach ($preparedTables as $table => [$modelClass, $fields, $tableTotal]) {
                $this->processTable(
                    $table,
                    $modelClass,
                    $fields,
                    $chunkSize,
                    $dryRun,
                    $resume,
                    $verify,
                    $tableTotal,
                    $globalTotal,
                    $globalProcessed
                );
            }

            $this->report('info', sprintf(
                '任务完成｜总进度 100.00%%｜耗时 %.2f 秒',
                $this->elapsedSeconds($startedAt)
            ));
            return 0;
        } catch (Throwable $e) {
            $this->report('error', sprintf(
                '任务失败｜原因：%s｜耗时 %.2f 秒',
                $e->getMessage(),
                $this->elapsedSeconds($startedAt)
            ));
            return 1;
        } finally {
            $this->config->set(StdoutLoggerInterface::class, $stdoutLoggerConfig);
        }
    }

    private function preflight(): void
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('OpenSSL扩展未启用');
        }

        $testValue = 'sensitive-data-preflight';
        $ciphertext = $this->cipher->encrypt($testValue);
        if ($this->cipher->decrypt($ciphertext) !== $testValue) {
            throw new \RuntimeException('公私钥不匹配或加解密自检失败');
        }

        $privateKeyPath = (string) config('security.field_encryption.private_key_path');
        if ((string) config('app_env', 'dev') === 'prod' && is_file($privateKeyPath)) {
            $permissions = fileperms($privateKeyPath);
            if ($permissions !== false && ($permissions & 0077) !== 0) {
                throw new \RuntimeException('生产环境私钥权限必须为600或更严格');
            }
        }
    }

    private function assertColumns(string $table, string $modelClass, array $fields): void
    {
        $model = new $modelClass();
        $blindIndexes = $model->blindIndexColumns();
        foreach ($fields as $field) {
            if (!Schema::hasColumn($table, $field)) {
                throw new \RuntimeException("{$table}.{$field}不存在，请先核对表结构");
            }
            $blindColumn = $blindIndexes[$field] ?? null;
            if ($blindColumn !== null && !Schema::hasColumn($table, $blindColumn)) {
                throw new \RuntimeException("{$table}.{$blindColumn}不存在，请先执行迁移");
            }
        }
    }

    private function processTable(
        string $table,
        string $modelClass,
        array $fields,
        int $chunkSize,
        bool $dryRun,
        bool $resume,
        bool $verify,
        int $total,
        int $globalTotal,
        int &$globalProcessed
    ): void {
        $checkpointKey = 'data:encrypt-sensitive:checkpoint:' . $table;
        $lastId = $resume ? (int) $this->redis->get($checkpointKey) : 0;
        if (!$resume && !$dryRun) {
            $this->redis->del($checkpointKey);
        }

        $processed = 0;
        $fieldModel = new $modelClass();
        $blindIndexes = $fieldModel->blindIndexColumns();
        $selectedColumns = array_values(array_unique(array_merge(
            ['id'],
            $fields,
            array_values(array_intersect_key($blindIndexes, array_flip($fields)))
        )));
        $query = Db::table($table);
        if ($dryRun) {
            $query->select($selectedColumns);
        }
        $query->where('id', '>', $lastId)->orderBy('id');
        if ($total === 0) {
            $this->reportProgress($table, $dryRun, $globalProcessed, $globalTotal, 100.0);
            return;
        }

        $this->reportProgress($table, $dryRun, $globalProcessed, $globalTotal, 0.0);

        $query->chunkById($chunkSize, function ($models) use (
            $table,
            $fields,
            $dryRun,
            $verify,
            $checkpointKey,
            $total,
            $blindIndexes,
            $selectedColumns,
            $globalTotal,
            &$processed,
            &$globalProcessed
        ) {
            $batchLastId = 0;
            $updates = [];
            $expectedById = [];

            foreach ($models as $model) {
                $id = (int) $model->id;
                $batchLastId = max($batchLastId, $id);
                ++$processed;
                $requiresSave = false;
                $expected = [];
                $storageRow = $dryRun ? ['id' => $id] : (array) $model;

                foreach ($fields as $field) {
                    $raw = $model->{$field} ?? null;
                    $storageRow[$field] = $raw;
                    $blindColumn = $blindIndexes[$field] ?? null;
                    if ($blindColumn !== null) {
                        $storageRow[$blindColumn] = $model->{$blindColumn} ?? null;
                    }
                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    $isEncrypted = $this->cipher->isEncrypted((string) $raw);
                    $plaintext = $isEncrypted
                        ? $this->cipher->decrypt((string) $raw)
                        : (string) $raw;
                    $expected[$field] = $plaintext;

                    if (!$isEncrypted) {
                        $requiresSave = true;
                        if (!$dryRun) {
                            $storageRow[$field] = $this->cipher->encrypt($plaintext);
                        }
                    }

                    if ($blindColumn !== null) {
                        $expectedIndex = $this->cipher->blindIndex($plaintext);
                        if ((string) $storageRow[$blindColumn] !== (string) $expectedIndex) {
                            $requiresSave = true;
                            if (!$dryRun) {
                                $storageRow[$blindColumn] = $expectedIndex;
                            }
                        }
                    }
                }

                if ($requiresSave && !$dryRun) {
                    $updates[] = $storageRow;
                    $expectedById[$id] = $expected;
                }
            }

            if (!$dryRun && $updates !== []) {
                Db::transaction(function () use (
                    $table,
                    $updates,
                    $expectedById,
                    $blindIndexes,
                    $selectedColumns,
                    $verify
                ) {
                    $ids = array_keys($expectedById);
                    $existingIds = Db::table($table)
                        ->whereIn('id', $ids)
                        ->lockForUpdate()
                        ->pluck('id')
                        ->map(static fn ($id) => (int) $id)
                        ->all();
                    sort($ids);
                    sort($existingIds);
                    if ($ids !== $existingIds) {
                        throw new \RuntimeException('批次数据在加密期间发生变化，请停止业务写入后使用 --resume 重试');
                    }

                    Db::table($table)->upsert(
                        $updates,
                        ['id'],
                        array_values(array_diff($selectedColumns, ['id']))
                    );

                    if ($verify) {
                        $this->verifyStoredRows($table, $ids, $expectedById, $blindIndexes, $selectedColumns);
                    }
                });
            }
            if (!$dryRun && $batchLastId > 0) {
                $this->redis->set($checkpointKey, (string) $batchLastId);
            }
            $globalProcessed += count($models);

            $percent = min(100, round(($processed / $total) * 100, 2));
            $this->reportProgress($table, $dryRun, $globalProcessed, $globalTotal, $percent);
        }, 'id');

    }

    private function reportProgress(
        string $table,
        bool $dryRun,
        int $globalProcessed,
        int $globalTotal,
        float $businessPercent
    ): void {
        $totalPercent = $globalTotal === 0
            ? 100.0
            : min(100, round(($globalProcessed / $globalTotal) * 100, 2));
        $action = $dryRun ? '检查' : '加密';
        $label = self::TABLE_LABELS[$table] ?? $table;

        $this->report('info', sprintf(
            '正在%s：%s｜总进度 %s｜当前业务进度 %s',
            $action,
            $label,
            $this->formatPercent($totalPercent),
            $this->formatPercent($businessPercent)
        ));
    }

    private function formatPercent(float $percent): string
    {
        return number_format($percent, 2, '.', '') . '%';
    }

    private function verifyStoredRows(
        string $table,
        array $ids,
        array $expectedById,
        array $blindIndexes,
        array $selectedColumns
    ): void
    {
        $storedRows = [];
        foreach (Db::table($table)->whereIn('id', $ids)->get($selectedColumns) as $row) {
            $storedRows[(int) $row->id] = $row;
        }

        foreach ($expectedById as $id => $expected) {
            $row = $storedRows[(int) $id] ?? null;
            if ($row === null) {
                throw new \RuntimeException('加密后记录校验失败');
            }

            foreach ($expected as $field => $plaintext) {
                $raw = (string) ($row->{$field} ?? '');
                if (!$this->cipher->isEncrypted($raw) || $this->cipher->decrypt($raw) !== $plaintext) {
                    throw new \RuntimeException('敏感字段密文校验失败');
                }
                $blindColumn = $blindIndexes[$field] ?? null;
                if ($blindColumn !== null
                    && (string) ($row->{$blindColumn} ?? '') !== (string) $this->cipher->blindIndex($plaintext)) {
                    throw new \RuntimeException('敏感字段盲索引校验失败');
                }
            }
        }
    }

    private function report(string $level, string $message, array $context = []): void
    {
        $context = array_merge(['run_id' => $this->runId], $context);
        $details = [];
        foreach ($context as $key => $value) {
            if ($key === 'run_id') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '是' : '否';
            } elseif (is_float($value)) {
                $value = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
            }
            $details[] = $key . '=' . str_replace(["\r", "\n"], ' ', (string) $value);
        }

        $safeMessage = str_replace(["\r", "\n"], ' ', $message);
        $line = sprintf('[%s] [%s] %s', date('Y-m-d H:i:s'), $this->runId, $safeMessage);
        if ($details !== []) {
            $line .= ' | ' . implode(' | ', $details);
        }

        $tag = match ($level) {
            'error', 'critical', 'alert', 'emergency' => 'error',
            'warning' => 'comment',
            default => 'info',
        };
        $this->output->writeln(sprintf('<%s>%s</%s>', $tag, OutputFormatter::escape($line), $tag));

        try {
            $this->logger->log($level, '[' . $this->runId . '] ' . $safeMessage . ($details !== []
                ? ' | ' . implode(' | ', $details)
                : ''));
        } catch (Throwable $e) {
            $this->output->writeln(sprintf(
                '<error>%s</error>',
                OutputFormatter::escape('写入敏感数据加密进度日志失败：' . $e->getMessage())
            ));
            if ($level !== 'error') {
                throw new \RuntimeException('无法写入敏感数据加密进度日志', 0, $e);
            }
        }
    }

    private function elapsedSeconds(float $startedAt): float
    {
        return round(microtime(true) - $startedAt, 2);
    }
}
