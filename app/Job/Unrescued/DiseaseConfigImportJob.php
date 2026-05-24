<?php

declare(strict_types=1);

namespace App\Job\Unrescued;

use App\Job\AbstractJob;
use App\Model\Task;
use App\Model\Unrescued\UnrescuedDiseaseConfig;
use App\Service\CsvReaderService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;

class DiseaseConfigImportJob extends AbstractJob
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
            $result = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
            $sourceBatch = date('YmdHis');

            $csvReader->read($this->tempFile, function (array $row, int $rowIndex) use (&$processed, $totalCount, &$result, $sourceBatch, $logger) {
                $processed++;

                try {
                    $code = $this->pickValue($row, ['病种编码', '编码', '重大疾病编码', 'disease_code']);
                    $name = $this->pickValue($row, ['病种名称', '名称', '重大疾病名称', 'disease_name']);

                    if ($code === '' && $name === '') {
                        $result['skipped']++;
                        return;
                    }
                    if ($code === '' || $name === '') {
                        throw new \RuntimeException('病种编码或病种名称为空');
                    }

                    $transactionStarted = true;
                    Db::beginTransaction();
                    $item = UnrescuedDiseaseConfig::query()
                        ->where('disease_code', $code)
                        ->where('disease_name', $name)
                        ->first();
                    if ($item) {
                        $item->update([
                            'status' => 1,
                            'source_batch' => $sourceBatch,
                        ]);
                        $result['updated']++;
                    } else {
                        UnrescuedDiseaseConfig::create([
                            'disease_code' => $code,
                            'disease_name' => $name,
                            'status' => 1,
                            'source_batch' => $sourceBatch,
                        ]);
                        $result['created']++;
                    }
                    Db::commit();
                } catch (\Throwable $e) {
                    if (!empty($transactionStarted)) {
                        Db::rollBack();
                    }
                    if (count($result['errors']) < 50) {
                        $result['errors'][] = '第' . ($rowIndex + 1) . '行: ' . $e->getMessage();
                    }
                    $logger->warning('Unrescued disease config import row failed: ' . $e->getMessage());
                }

                if ($processed % 100 === 0) {
                    $this->updateProgress($this->uuid, min(($processed / $totalCount) * 100, 99.9));
                }
            });

            $this->finishImportTask($result);
        } catch (\Throwable $e) {
            $this->failTask($e, '重大疾病编码导入失败');
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
        $summary = sprintf(
            '(新增%d/更新%d/跳过%d/失败%d)',
            $result['created'],
            $result['updated'],
            $result['skipped'],
            count($result['errors'])
        );

        $task = Task::where('uuid', $this->uuid)->first();
        $title = $task ? $task->title : '未救助台账_重大疾病编码导入_';
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
