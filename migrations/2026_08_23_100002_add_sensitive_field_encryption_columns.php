<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class AddSensitiveFieldEncryptionColumns extends Migration
{
    private const TABLES = [
        'insurance_data' => [
            'lengths' => ['name' => 3072, 'id_number' => 3072, 'person_number' => 3072],
            'indexes' => ['name_bidx' => 'name_bidx', 'id_number_bidx' => 'id_number_bidx'],
            'drop_index_columns' => ['name', 'id_number'],
            'new_indexes' => [
                'idx_insurance_name_bidx' => ['name_bidx'],
                'idx_insurance_id_bidx' => ['id_number_bidx'],
                'uk_insurance_year_id_bidx' => ['year', 'id_number_bidx', 'unique' => true],
            ],
        ],
        'enroll_ledgers' => [
            'lengths' => ['name' => 1024, 'id_card' => 512],
            'indexes' => ['name_bidx' => 'name_bidx', 'id_card_bidx' => 'id_card_bidx'],
            'drop_index_columns' => ['id_card'],
            'new_indexes' => [
                'idx_year_id_card_bidx' => ['year', 'id_card_bidx'],
                'idx_year_name_bidx' => ['year', 'name_bidx'],
            ],
        ],
        'enroll_ledger_snapshots' => [
            'lengths' => ['name' => 1024, 'id_card' => 512],
            'indexes' => ['name_bidx' => 'name_bidx', 'id_card_bidx' => 'id_card_bidx'],
            'drop_index_columns' => ['id_card'],
            'new_indexes' => [
                'idx_period_type_id_bidx' => ['period', 'snapshot_type', 'id_card_bidx'],
                'idx_period_type_name_bidx' => ['period', 'snapshot_type', 'name_bidx'],
            ],
        ],
        'unrescued_records' => [
            'lengths' => [
                'name' => 1024,
                'id_card' => 512,
                'bank_account_name' => 2048,
                'bank_account_no' => 2048,
            ],
            'indexes' => ['name_bidx' => 'name_bidx', 'id_card_bidx' => 'id_card_bidx'],
            'drop_index_columns' => ['id_card'],
            'new_indexes' => [
                'idx_period_id_card_bidx' => ['settlement_period', 'id_card_bidx'],
                'idx_period_name_bidx' => ['settlement_period', 'name_bidx'],
            ],
        ],
        'unrescued_refund_records' => [
            'lengths' => ['name' => 1024, 'id_card' => 512],
            'indexes' => ['name_bidx' => 'name_bidx', 'id_card_bidx' => 'id_card_bidx'],
            'drop_index_columns' => ['id_card'],
            'new_indexes' => [
                'idx_period_id_card_bidx' => ['settlement_period', 'id_card_bidx'],
                'idx_period_name_bidx' => ['settlement_period', 'name_bidx'],
            ],
        ],
        'unrescued_notice_records' => [
            'lengths' => [
                'name' => 1024,
                'id_card' => 512,
                'contact_name' => 2048,
                'contact_phone' => 2048,
                'bank_account_name' => 2048,
                'bank_account_no' => 2048,
            ],
            'indexes' => [
                'name_bidx' => 'name_bidx',
                'id_card_bidx' => 'id_card_bidx',
                'contact_name_bidx' => 'contact_name_bidx',
            ],
            'drop_index_columns' => ['id_card'],
            'new_indexes' => [
                'idx_period_id_card_bidx' => ['settlement_period', 'id_card_bidx'],
                'idx_period_name_bidx' => ['settlement_period', 'name_bidx'],
                'idx_notice_contact_name_bidx' => ['contact_name_bidx'],
            ],
        ],
        'unrescued_supplement_records' => [
            'lengths' => ['name' => 1024, 'id_card' => 512],
            'indexes' => ['name_bidx' => 'name_bidx', 'id_card_bidx' => 'id_card_bidx'],
            'drop_index_columns' => ['id_card'],
            'new_indexes' => [
                'idx_period_id_card_bidx' => ['settlement_period', 'id_card_bidx'],
                'idx_period_name_bidx' => ['settlement_period', 'name_bidx'],
            ],
        ],
        'med_person_info' => [
            'lengths' => ['name' => 1024, 'id_card' => 512],
            'indexes' => ['name_bidx' => 'name_bidx', 'id_card_bidx' => 'id_card_bidx'],
            'drop_index_columns' => ['name', 'id_card'],
            'new_indexes' => [
                'idx_med_person_name_bidx' => ['name_bidx'],
                'idx_med_person_id_bidx' => ['id_card_bidx'],
            ],
        ],
        'med_reimbursement_detail' => [
            'lengths' => ['bank_account' => 1024, 'account_name' => 1024],
            'indexes' => ['account_name_bidx' => 'account_name_bidx'],
            'drop_index_columns' => ['account_name'],
            'new_indexes' => ['idx_med_account_name_bidx' => ['account_name_bidx']],
        ],
        'statistics_data' => [
            'lengths' => ['settlement_id' => 3072, 'name' => 3072, 'id_number' => 3072],
            'indexes' => ['name_bidx' => 'name_bidx', 'id_number_bidx' => 'id_number_bidx'],
            'drop_index_columns' => ['name', 'id_number'],
            'new_indexes' => [
                'idx_statistics_name_bidx' => ['name_bidx'],
                'idx_statistics_id_bidx' => ['id_number_bidx'],
            ],
        ],
        'statistics_summery' => [
            'lengths' => ['name' => 3072, 'id_number' => 3072, 'person_number' => 3072],
            'indexes' => ['name_bidx' => 'name_bidx', 'id_number_bidx' => 'id_number_bidx'],
            'drop_index_columns' => ['name', 'id_number'],
            'new_indexes' => [
                'idx_statistics_summary_name_bidx' => ['name_bidx'],
                'idx_statistics_summary_id_bidx' => ['id_number_bidx'],
            ],
        ],
        'yf_settlements' => [
            'lengths' => ['name' => 512, 'id_card' => 512],
            'indexes' => ['name_bidx' => 'name_bidx', 'id_card_bidx' => 'id_card_bidx'],
            'drop_index_columns' => ['id_card'],
            'new_indexes' => [
                'idx_yf_id_period_bidx' => ['id_card_bidx', 'period_belong'],
                'idx_yf_name_bidx' => ['name_bidx'],
            ],
        ],
    ];

    private const ORIGINAL_LENGTHS = [
        'insurance_data' => ['name' => 255, 'id_number' => 255, 'person_number' => 255],
        'enroll_ledgers' => ['name' => 80, 'id_card' => 32],
        'enroll_ledger_snapshots' => ['name' => 80, 'id_card' => 32],
        'unrescued_records' => ['name' => 50, 'id_card' => 32, 'bank_account_name' => 100, 'bank_account_no' => 100],
        'unrescued_refund_records' => ['name' => 50, 'id_card' => 32],
        'unrescued_notice_records' => [
            'name' => 50,
            'id_card' => 32,
            'contact_name' => 100,
            'contact_phone' => 100,
            'bank_account_name' => 100,
            'bank_account_no' => 100,
        ],
        'unrescued_supplement_records' => ['name' => 50, 'id_card' => 32],
        'med_person_info' => ['name' => 50, 'id_card' => 18],
        'med_reimbursement_detail' => ['bank_account' => 50, 'account_name' => 50],
        'statistics_data' => ['settlement_id' => 255, 'name' => 255, 'id_number' => 255],
        'statistics_summery' => ['name' => 255, 'id_number' => 255, 'person_number' => 255],
        'yf_settlements' => ['name' => 20, 'id_card' => 20],
    ];

    private const ORIGINAL_INDEXES = [
        'insurance_data' => [
            'uk_year_id_number' => ['year', 'id_number', 'unique' => true],
            'insurance_data_id_number_index' => ['id_number'],
            'insurance_data_name_index' => ['name'],
        ],
        'enroll_ledgers' => ['idx_year_id_card' => ['year', 'id_card']],
        'enroll_ledger_snapshots' => ['idx_period_type_id_card' => ['period', 'snapshot_type', 'id_card']],
        'unrescued_records' => ['idx_period_id_card' => ['settlement_period', 'id_card']],
        'unrescued_refund_records' => ['idx_period_id_card' => ['settlement_period', 'id_card']],
        'unrescued_notice_records' => ['idx_period_id_card' => ['settlement_period', 'id_card']],
        'unrescued_supplement_records' => ['idx_period_id_card' => ['settlement_period', 'id_card']],
        'statistics_data' => [
            'statistics_data_id_number_index' => ['id_number'],
            'statistics_data_name_index' => ['name'],
        ],
        'statistics_summery' => ['statistics_summary_id_number_index' => ['id_number']],
        'yf_settlements' => ['idx_id_card_period' => ['id_card', 'period_belong']],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $definition) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($definition['indexes'] as $column) {
                $this->addBlindIndexColumn($table, $column);
            }
            $this->dropIndexesContaining($table, $definition['drop_index_columns']);
            foreach ($definition['lengths'] as $column => $length) {
                if (Schema::hasColumn($table, $column)) {
                    $this->changeVarcharLength($table, $column, $length);
                }
            }
            foreach ($definition['new_indexes'] as $name => $columns) {
                $unique = (bool) ($columns['unique'] ?? false);
                unset($columns['unique']);
                $this->addIndex($table, $name, array_values($columns), $unique);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $definition) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if ($this->containsEncryptedValues($table, array_keys($definition['lengths']))) {
                throw new RuntimeException('检测到已加密业务数据，禁止直接回滚敏感字段迁移，请先恢复明文备份');
            }

            foreach (array_keys($definition['new_indexes']) as $name) {
                $this->dropIndex($table, $name);
            }
            foreach (self::ORIGINAL_LENGTHS[$table] as $column => $length) {
                if (Schema::hasColumn($table, $column)) {
                    $this->changeVarcharLength($table, $column, $length);
                }
            }
            foreach ($definition['indexes'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Db::statement(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
                }
            }
            foreach (self::ORIGINAL_INDEXES[$table] ?? [] as $name => $columns) {
                $unique = (bool) ($columns['unique'] ?? false);
                unset($columns['unique']);
                $this->addIndex($table, $name, array_values($columns), $unique);
            }
        }
    }

    private function addBlindIndexColumn(string $table, string $column): void
    {
        if (!Schema::hasColumn($table, $column)) {
            Db::statement(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `%s` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
                $table,
                $column
            ));
        }
    }

    private function changeVarcharLength(string $table, string $column, int $length): void
    {
        $rows = Db::select(
            'SELECT IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, CHARACTER_SET_NAME, COLLATION_NAME '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        if (!$rows) {
            return;
        }

        $meta = (array) $rows[0];
        $sql = sprintf('ALTER TABLE `%s` MODIFY COLUMN `%s` VARCHAR(%d)', $table, $column, $length);
        if (!empty($meta['CHARACTER_SET_NAME'])) {
            $sql .= ' CHARACTER SET ' . $meta['CHARACTER_SET_NAME'];
        }
        if (!empty($meta['COLLATION_NAME'])) {
            $sql .= ' COLLATE ' . $meta['COLLATION_NAME'];
        }
        $sql .= ($meta['IS_NULLABLE'] ?? 'YES') === 'YES' ? ' NULL' : ' NOT NULL';
        if ($meta['COLUMN_DEFAULT'] !== null) {
            $sql .= ' DEFAULT ' . Db::connection()->getPdo()->quote((string) $meta['COLUMN_DEFAULT']);
        }
        if (($meta['COLUMN_COMMENT'] ?? '') !== '') {
            $sql .= ' COMMENT ' . Db::connection()->getPdo()->quote((string) $meta['COLUMN_COMMENT']);
        }
        Db::statement($sql);
    }

    private function dropIndexesContaining(string $table, array $columns): void
    {
        $indexes = [];
        foreach (Db::select(sprintf('SHOW INDEX FROM `%s`', $table)) as $row) {
            $data = (array) $row;
            $name = (string) ($data['Key_name'] ?? $data['key_name'] ?? '');
            $column = (string) ($data['Column_name'] ?? $data['column_name'] ?? '');
            if ($name !== '' && $name !== 'PRIMARY') {
                $indexes[$name][] = $column;
            }
        }

        foreach ($indexes as $name => $indexColumns) {
            if (array_intersect($columns, $indexColumns)) {
                $this->dropIndex($table, $name);
            }
        }
    }

    private function addIndex(string $table, string $name, array $columns, bool $unique = false): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }
        $columnSql = implode(', ', array_map(static fn (string $column) => '`' . $column . '`', $columns));
        Db::statement(sprintf(
            'ALTER TABLE `%s` ADD %sINDEX `%s` (%s)',
            $table,
            $unique ? 'UNIQUE ' : '',
            $name,
            $columnSql
        ));
    }

    private function dropIndex(string $table, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            Db::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $name));
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        foreach (Db::select(sprintf('SHOW INDEX FROM `%s`', $table)) as $row) {
            $data = (array) $row;
            $keyName = (string) ($data['Key_name'] ?? $data['key_name'] ?? '');
            if ($keyName === $name) {
                return true;
            }
        }

        return false;
    }

    private function containsEncryptedValues(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                continue;
            }
            $rows = Db::select(sprintf(
                "SELECT 1 FROM `%s` WHERE `%s` LIKE 'ENC1:%%' LIMIT 1",
                $table,
                $column
            ));
            if ($rows) {
                return true;
            }
        }
        return false;
    }
}
