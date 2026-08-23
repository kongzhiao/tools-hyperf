<?php

declare(strict_types=1);

namespace App\Model\Concerns;

use App\Service\SensitiveDataCipher;
use Hyperf\Context\ApplicationContext;

trait HasEncryptedAttributes
{
    /**
     * Hyperf 默认序列化直接读取原始 attributes，不会调用 getAttributeValue()。
     * 在模型转换为数组/JSON/API响应时，统一将已配置字段恢复为业务明文。
     */
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        foreach ($this->encryptedFieldNames() as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = $this->getAttributeValue($field);
            }
        }

        return $attributes;
    }

    public function setAttribute($key, $value)
    {
        if (in_array((string) $key, $this->encryptedFieldNames(), true)) {
            $cipher = $this->sensitiveDataCipher();
            $indexColumn = $this->blindIndexColumns()[(string) $key] ?? null;

            if ($indexColumn !== null && !$cipher->isEncrypted((string) ($value ?? ''))) {
                parent::setAttribute($indexColumn, $cipher->blindIndex($value === null ? null : (string) $value));
            }

            $value = $cipher->encrypt($value === null ? null : (string) $value);
        }

        return parent::setAttribute($key, $value);
    }

    public function getAttributeValue($key)
    {
        $value = parent::getAttributeValue($key);
        if (!in_array((string) $key, $this->encryptedFieldNames(), true) || !is_string($value)) {
            return $value;
        }

        return $this->sensitiveDataCipher()->decrypt($value);
    }

    public function scopeWhereBlind($query, string $field, ?string $value)
    {
        $column = $this->blindIndexColumn($field);
        if ($value === null || $value === '') {
            return $query->where(function ($subQuery) use ($field) {
                $subQuery->whereNull($field)->orWhere($field, '');
            });
        }

        $index = $this->sensitiveDataCipher()->blindIndex($value);
        return $query->where(function ($subQuery) use ($column, $index, $field, $value) {
            $subQuery->where($column, $index)->orWhere($field, $value);
        });
    }

    public function scopeWhereBlindIn($query, string $field, array $values)
    {
        $values = array_values(array_unique(array_filter($values, static fn ($value) => $value !== null && $value !== '')));
        if ($values === []) {
            return $query->whereRaw('1 = 0');
        }

        $column = $this->blindIndexColumn($field);
        $indexes = array_values(array_unique(array_filter(array_map(function ($value) {
            return $this->sensitiveDataCipher()->blindIndex($value === null ? null : (string) $value);
        }, $values))));

        return $query->where(function ($subQuery) use ($column, $indexes, $field, $values) {
            $subQuery->whereIn($column, $indexes)->orWhereIn($field, $values);
        });
    }

    /**
     * 为必须使用底层批量写入的场景生成数据库实际存储值。
     */
    public function prepareAttributesForStorage(array $attributes): array
    {
        $model = $this->newInstance();
        foreach ($attributes as $key => $value) {
            $model->setAttribute((string) $key, $value);
        }

        return $model->getAttributes();
    }

    public function blindIndexFor(string $field, ?string $value): ?string
    {
        $this->blindIndexColumn($field);
        return $this->sensitiveDataCipher()->blindIndex($value);
    }

    public function encryptedFieldNames(): array
    {
        return property_exists($this, 'encrypts') && is_array($this->encrypts) ? $this->encrypts : [];
    }

    public function blindIndexColumns(): array
    {
        return property_exists($this, 'blindIndexes') && is_array($this->blindIndexes) ? $this->blindIndexes : [];
    }

    private function blindIndexColumn(string $field): string
    {
        $column = $this->blindIndexColumns()[$field] ?? null;
        if (!$column) {
            throw new \InvalidArgumentException('字段未配置盲索引: ' . $field);
        }
        return $column;
    }

    private function sensitiveDataCipher(): SensitiveDataCipher
    {
        return ApplicationContext::getContainer()->get(SensitiveDataCipher::class);
    }
}
