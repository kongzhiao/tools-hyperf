<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\BusinessFilterOption;

class BusinessFilterOptionService
{
    public function saveOption(string $module, string $type, ?string $value, ?string $sourceBatch = null): void
    {
        $value = trim((string) $value);
        if ($module === '' || $type === '' || $value === '') {
            return;
        }

        $option = BusinessFilterOption::query()
            ->where('module', $module)
            ->where('type', $type)
            ->where('value', $value)
            ->first();

        $data = [
            'label' => $value,
            'status' => 1,
            'source_batch' => $sourceBatch,
        ];

        if ($option) {
            $option->update($data);
            return;
        }

        BusinessFilterOption::create([
            'module' => $module,
            'type' => $type,
            'value' => $value,
            'label' => $value,
            'status' => 1,
            'sort' => 0,
            'source_batch' => $sourceBatch,
        ]);
    }

    public function listOptions(string $module, string $type): array
    {
        return BusinessFilterOption::query()
            ->where('module', $module)
            ->where('type', $type)
            ->where('status', 1)
            ->orderByDesc('sort')
            ->orderBy('label')
            ->get(['id', 'module', 'type', 'value', 'label'])
            ->toArray();
    }
}
