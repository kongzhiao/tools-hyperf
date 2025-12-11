<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceData;
use App\Model\InsuranceLevelConfig;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class ImportInsuranceDataCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('import:insurance-data');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('导入参保数据Excel文件');
        $this->addOption('year', 'y', \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, '指定导入的年份', null);
        $this->addOption('mode', 'm', \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, '导入模式：incremental(增量) 或 full(全量覆盖)', 'incremental');
        $this->addOption('file', 'f', \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, '指定Excel文件路径', null);
    }

    /**
     * 根据代缴类别和代缴金额匹配档次和个人实缴金额
     */
    private function matchLevelAndPersonalAmount(string $paymentCategory, float $paymentAmount, int $year): array
    {
        try {
            // 查找匹配的档次配置（根据代缴类别和代缴金额匹配资助金额）
            $config = InsuranceLevelConfig::where('year', $year)
                ->where('payment_category', $paymentCategory)
                ->where('subsidy_amount', $paymentAmount)
                ->first();

            if ($config) {
                return [
                    'level' => $config->level,
                    'personal_amount' => $config->personal_amount,
                    'level_match_status' => true
                ];
            }

            // 如果没有精确匹配，尝试模糊匹配（只匹配代缴类别）
            $config = InsuranceLevelConfig::where('year', $year)
                ->where('payment_category', $paymentCategory)
                ->first();

            if ($config) {
                return [
                    'level' => $config->level,
                    'personal_amount' => $config->personal_amount,
                    'level_match_status' => true
                ];
            }

            // 如果都没有匹配到，返回默认值
            return [
                'level' => null,
                'personal_amount' => 0,
                'level_match_status' => false
            ];

        } catch (\Exception $e) {
            $this->output->writeln("<error>匹配档次配置失败: " . $e->getMessage() . "</error>");
            return [
                'level' => null,
                'personal_amount' => 0,
                'level_match_status' => false
            ];
        }
    }

    public function handle()
    {
        // 设置脚本执行时间限制
        set_time_limit(600); // 10分钟
        
        $year = $this->input->getOption('year');
        if (!$year) {
            $this->output->writeln('<error>请指定导入的年份，使用 --year 参数</error>');
            return;
        }

        $year = (int) $year;
        $mode = $this->input->getOption('mode');
        
        if (!in_array($mode, ['incremental', 'full'])) {
            $this->output->writeln("<error>导入模式必须是 incremental 或 full</error>");
            return;
        }
        
        $this->output->writeln("开始导入{$year}年参保数据，模式：{$mode}...");

        try {
            // 检查年份是否存在
            if (!\App\Model\InsuranceYear::yearExists($year)) {
                $this->output->writeln("<error>{$year}年不存在，请先创建该年份</error>");
                return;
            }

            // 检查是否已有数据
            $existingCount = InsuranceData::where('year', $year)->count();
            if ($existingCount > 0 && $mode === 'incremental') {
                $this->output->writeln("<info>{$year}年已有{$existingCount}条数据，将进行增量导入</info>");
            } elseif ($existingCount > 0 && $mode === 'full') {
                $this->output->writeln("<info>{$year}年已有{$existingCount}条数据，将进行全量覆盖</info>");
                // 删除现有数据
                InsuranceData::where('year', $year)->delete();
                $this->output->writeln("已清空{$year}年现有数据");
            }

            // 读取Excel文件
            $filePath = $this->input->getOption('file');
            if (!$filePath) {
                // 如果没有指定文件，使用默认文件
                $filePath = BASE_PATH . '/../doc/税务代缴明细汇总参考.xlsx';
            }
            
            if (!file_exists($filePath)) {
                $this->output->writeln('<error>Excel文件不存在: ' . $filePath . '</error>');
                return;
            }

            // 使用Python脚本读取Excel数据
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();
                
                // 获取数据范围（跳过前两行表头）
                $highestRow = $worksheet->getHighestRow();
                $highestColumn = $worksheet->getHighestColumn();
                $dataRange = $worksheet->rangeToArray('A3:' . $highestColumn . $highestRow, null, true, false);
                
                // 获取表头（第3行，索引为2）
                $headers = $worksheet->rangeToArray('A2:' . $highestColumn . '2', null, true, false)[0];
                
                // 过滤空行（姓名和身份证件号码为空的行）
                $data = [];
                foreach ($dataRange as $row) {
                    // 根据表头找到姓名和身份证件号码的列索引
                    $nameIndex = array_search('姓名', $headers);
                    $idNumberIndex = array_search('身份证件号码', $headers);
                    
                    if ($nameIndex !== false && $idNumberIndex !== false && 
                        !empty($row[$nameIndex]) && !empty($row[$idNumberIndex])) {
                        
                        // 构建数据行，使用列标题作为键
                        $rowData = [];
                        foreach ($headers as $index => $header) {
                            if (!empty($header)) {
                                $rowData[$header] = $row[$index] ?? '';
                            }
                        }
                        
                        if (!empty($rowData)) {
                            $data[] = $rowData;
                        }
                    }
                }
                
                if (empty($data)) {
                    $this->output->writeln('<error>Excel文件中没有有效数据</error>');
                    return;
                }
            } catch (\Exception $e) {
                $this->output->writeln('<error>读取Excel文件失败: ' . $e->getMessage() . '</error>');
                return;
            }

            $this->output->writeln('Excel数据读取成功，共 ' . count($data) . ' 条记录');

            $successCount = 0;
            $skipCount = 0;
            $errorCount = 0;
            $matchedCount = 0;
            $unmatchedCount = 0;

            foreach ($data as $row) {
                try {
                    // 检查是否已存在（根据年份和身份证件号码）
                    $existing = InsuranceData::where('year', $year)
                        ->where('id_number', $row['身份证件号码'])
                        ->first();
                    
                    // 匹配档次和个人实缴金额
                    $paymentCategory = $row['代缴类别'] ?? '';
                    $paymentAmount = (float)($row['代缴金额'] ?? 0);
                    $levelMatch = $this->matchLevelAndPersonalAmount($paymentCategory, $paymentAmount, $year);
                    
                    if ($levelMatch['level_match_status']) {
                        $matchedCount++;
                    } else {
                        $unmatchedCount++;
                    }

                    if ($existing) {
                        if ($mode === 'incremental') {
                            // 增量模式：跳过已存在的记录
                            $skipCount++;
                            continue;
                        } else {
                            // 全量模式：更新现有记录
                            $updateData = [
                                'serial_number' => $row['序号'] ?? null,
                                'street_town' => $row['街道乡镇'] ?? '',
                                'name' => $row['姓名'] ?? '',
                                'id_type' => $row['身份证件类型'] ?? '',
                                'person_number' => $row['人员编号'] ?? null,
                                'payment_category' => $row['代缴类别'] ?? '',
                                'payment_amount' => $row['代缴金额'] ?? 0,
                                'payment_date' => $row['个人缴费日期'] ?? null,
                                'level' => $levelMatch['level'],
                                'personal_amount' => $levelMatch['personal_amount'],
                            ];
                            
                            $existing->update($updateData);
                            $successCount++;
                        }
                    } else {
                        // 创建新记录
                        $insuranceData = [
                            'year' => $year,
                            'serial_number' => $row['序号'] ?? null,
                            'street_town' => $row['街道乡镇'] ?? '',
                            'name' => $row['姓名'] ?? '',
                            'id_type' => $row['身份证件类型'] ?? '',
                            'id_number' => $row['身份证件号码'] ?? '',
                            'person_number' => $row['人员编号'] ?? null,
                            'payment_category' => $row['代缴类别'] ?? '',
                            'payment_amount' => $row['代缴金额'] ?? 0,
                            'payment_date' => $row['个人缴费日期'] ?? null,
                            'level' => $levelMatch['level'],
                            'personal_amount' => $levelMatch['personal_amount'],
                            'medical_assistance_category' => null, // 后续匹配
                            'category_match' => null, // 后续匹配
                            'remark' => null,
                        ];

                        InsuranceData::create($insuranceData);
                        $successCount++;
                    }

                    if ($successCount % 1000 === 0) {
                        $this->output->writeln("已处理 {$successCount} 条记录...");
                    }

                } catch (\Exception $e) {
                    $errorCount++;
                    $this->output->writeln('<error>处理记录失败: ' . $e->getMessage() . '</error>');
                }
            }

            $this->output->writeln("导入完成！成功: {$successCount} 条，跳过: {$skipCount} 条，失败: {$errorCount} 条");
            $this->output->writeln("档次匹配情况：匹配成功: {$matchedCount} 条，未匹配: {$unmatchedCount} 条");

        } catch (\Exception $e) {
            $this->output->writeln('<error>导入过程中出错: ' . $e->getMessage() . '</error>');
        }
    }
} 