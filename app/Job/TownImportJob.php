<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\Task;
use App\Model\Town;
use App\Service\CsvReaderService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;

class TownImportJob extends AbstractJob
{
    private string $tempFile;

    public function __construct(array $params, string $uuid, string $tempFile)
    {
        parent::__construct($params, $uuid);
        $this->tempFile = $tempFile;
    }

    public function handle(): void
    {
        $logger = ApplicationContext::getContainer()->get(LoggerFactory::class)->get('default');

        try {
            $this->startTask();
            if (!file_exists($this->tempFile)) {
                throw new \RuntimeException('导入文件不存在');
            }

            $csvReader = new CsvReaderService();
            $totalCount = max($csvReader->countRows($this->tempFile) - 1, 1);
            $processed = 0;
            $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

            $csvReader->read($this->tempFile, function (array $row, int $rowIndex) use (&$processed, $totalCount, &$result, $logger) {
                $processed++;
                $transactionStarted = false;

                try {
                    $name = $this->pickValue($row, ['镇街名称', '名称', 'name']);
                    $code = $this->pickValue($row, ['镇街编码', '编码', 'code']);
                    $statusText = $this->pickValue($row, ['状态', 'status']);
                    $sortText = $this->pickValue($row, ['排序', 'sort']);
                    $remark = $this->pickValue($row, ['备注', 'remark']);

                    if ($name === '' && $code === '') {
                        $result['skipped']++;
                        return;
                    }
                    if ($name === '') {
                        throw new \RuntimeException('镇街名称不能为空');
                    }

                    $status = in_array($statusText, ['0', '停用', '禁用'], true) ? 0 : 1;
                    $sort = is_numeric($sortText) ? (int) $sortText : 0;

                    $transactionStarted = true;
                    Db::beginTransaction();

                    $query = Town::query();
                    if ($code !== '') {
                        $query->where('code', $code);
                    } else {
                        $query->where('name', $name);
                    }
                    $town = $query->first();

                    $data = [
                        'name' => $name,
                        'code' => $code ?: null,
                        'status' => $status,
                        'sort' => $sort,
                        'remark' => $remark ?: null,
                    ];

                    if ($town) {
                        $town->update($data);
                        $result['updated']++;
                    } else {
                        Town::create($data);
                        $result['created']++;
                    }

                    Db::commit();
                } catch (\Throwable $e) {
                    if ($transactionStarted) {
                        Db::rollBack();
                    }
                    if (count($result['errors']) < 50) {
                        $result['errors'][] = '第' . ($rowIndex + 1) . '行: ' . $e->getMessage();
                    }
                    $logger->warning('Town import row failed: ' . $e->getMessage());
                }

                if ($processed % 100 === 0) {
                    $this->updateProgress($this->uuid, min(($processed / $totalCount) * 100, 99.9));
                }
            });

            $this->finishImportTask($result);
        } catch (\Throwable $e) {
            $this->failTask($e, '镇街导入失败');
        } finally {
            if (file_exists($this->tempFile)) {
                @unlink($this->tempFile);
            }
            $this->releaseLock();
        }
    }

    private function pickValue(array $row, array $headers): string
    {
        foreach ($headers as $header) {
            if (array_key_exists($header, $row) && trim((string) $row[$header]) !== '') {
                return trim((string) $row[$header]);
            }
        }

        return '';
    }

    private function finishImportTask(array $result): void
    {
        $summary = sprintf('(新增%d/更新%d/跳过%d/失败%d)', $result['created'], $result['updated'], $result['skipped'], count($result['errors']));
        $task = Task::where('uuid', $this->uuid)->first();
        $title = $task ? $task->title : '镇街导入_';
        if (!str_contains($title, '(')) {
            $title .= $summary;
        }

        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'status' => Task::STATUS_COMPLETED,
            'title' => $title,
            'url_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
