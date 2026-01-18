<?php

declare(strict_types=1);

namespace App\Job;

use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use App\Model\StatisticsData;
use App\Model\Task;

class StatisticsSummaryExportChunkJob extends AbstractJob
{
    /**
     * @var array Parameters for the export (e.g., filters)
     */
    public $params;
    /**
     * @var string Task UUID
     */
    public $uuid;
    /**
     * @var int Chunk sequence (starting from 0)
     */
    public $sequence;
    /**
     * @var int Offset for the query
     */
    public $offset;
    /**
     * @var int Limit for the query
     */
    public $limit;
    /**
     * @var string Sheet Name
     */
    public $sheetName;
    /**
     * @var string Import Type
     */
    public $importType;

    public function __construct(array $params, string $uuid, int $sequence, int $offset, int $limit)
    {
        $this->params = $params;
        $this->uuid = $uuid;
        $this->sequence = $sequence;
        $this->offset = $offset;
        $this->limit = $limit;
    }

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');
        try {
            // Fetch data for this chunk
            $query = StatisticsData::query()
                ->where('import_type', $this->importType)
                ->with(['project']);
            // Apply any filters from $this->params if needed (omitted for brevity)
            $rows = $query->offset($this->offset)->limit($this->limit)->get();

            $records = [];
            foreach ($rows as $item) {
                // Formatting data exactly like the original job
                $records[] = [
                    $item->project->code ?? '',
                    $item->medical_category,
                    $item->settlement_period,
                    $item->fee_period,
                    $item->settlement_id,
                    $item->certification_place,
                    $item->street_town,
                    $item->insurance_place,
                    $item->insurance_category,
                    $item->id_number,
                    $item->name,
                    $item->assistance_identity,
                    $item->visit_place,
                    $item->medical_institution,
                    $item->medical_visit_category,
                    $item->medical_assistance_category,
                    $item->disease_code,
                    $item->disease_name,
                    $item->admission_date,
                    $item->discharge_date,
                    $item->settlement_date,
                    $item->total_cost,
                    $item->eligible_reimbursement,
                    $item->basic_medical_reimbursement,
                    $item->serious_illness_reimbursement,
                    $item->large_amount_reimbursement,
                    $item->medical_assistance_amount,
                    $item->medical_assistance,
                    $item->tilt_assistance,
                    $item->poverty_relief_amount,
                    $item->yukuaibao_amount,
                    $item->personal_account_amount,
                    $item->personal_cash_amount,
                ];
            }

            $runtimePath = BASE_PATH . '/runtime/export/chunks/';
            if (!is_dir($runtimePath)) {
                mkdir($runtimePath, 0777, true);
            }

            $filename = "chunk_{$this->sheetName}_{$this->sequence}.csv";
            $fullPath = $runtimePath . $filename;

            $fp = fopen($fullPath, 'w');
            // Add BOM for UTF-8 Excel support
            fwrite($fp, "\xEF\xBB\xBF");

            // Write header (only for first chunk)
            if ($this->sequence === 0) {
                fputcsv($fp, ['项目代码', '医保分类', '清算期', '费款所属期', '结算id', '认定地', '镇街', '参保地', '参保类别', '身份证号', '姓名', '救助身份', '就诊地', '就诊医疗机构名称', '医保就诊类别', '医疗救助类别', '病种编码', '病种名称', '入院日期', '出院日期', '结算日期', '费用总额', '符合医保报销金额', '基本医疗保险报销金额', '大病报销金额', '大额报销金额', '进入医疗救助金额', '医疗救助', '倾斜救助', '扶贫济困金额（元）', '渝快保支出金额（元）', '个人账户支付金额（元）', '个人现金支付金额（元）']);
            }

            foreach ($records as $row) {
                fputcsv($fp, array_values($row));
            }
            fclose($fp);

            // Store chunk path in Redis list for later merging
            $redis = $container->get(\Hyperf\Redis\Redis::class);
            $listKey = "export:{$this->uuid}:chunks_files";
            // Use LPUSH with sequence prefix to keep order when retrieving via LRANGE and sorting
            $redis->lpush($listKey, $filename);

            // Decrement pending counter
            $counterKey = "export:{$this->uuid}:chunks_pending";
            $remaining = $redis->decr($counterKey);
            // Update progress proportionally (5% base + processed/total * 90%)
            $totalKey = "export:{$this->uuid}:chunks_total";
            $total = (int) $redis->get($totalKey);
            $base = 5;
            $progress = $base + (($total - $remaining) / $total) * 90;
            $this->updateProgress($this->uuid, $progress);

            // If this was the last chunk, dispatch merge job
            if ($remaining <= 0) {
                $mergeJob = new StatisticsSummaryExportMergeJob($this->uuid);
                $driver = $container->get(DriverFactory::class)->get('default');
                $driver->push($mergeJob);
            }
        } catch (\Throwable $e) {
            $logger->error("Chunk {$this->sequence} of task {$this->uuid} failed: " . $e->getMessage());
            $this->updateProgress($this->uuid, -1.00);
            throw $e;
        }
    }
}
?>