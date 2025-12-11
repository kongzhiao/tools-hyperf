<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\CategoryConversion;
use App\Model\InsuranceData;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class VerifyIdentityCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('verify:identity');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('验证身份类别');
        $this->addOption('year', 'y', \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, '指定验证的年份', null);
        $this->addOption('file', 'f', \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, '指定Excel文件路径', null);
    }

    /**
     * 根据资助参保身份匹配payment_category
     */
    private function matchPaymentCategory(string $identityCategory): ?string
    {
        try {
            // 在category_conversions表中查找tax_standard
            $conversion = CategoryConversion::where('tax_standard', $identityCategory)
                ->orWhere('tax_standard', 'like', "%{$identityCategory}%")
                ->first();

            if ($conversion) {
                return $conversion->medical_export_standard;
            }

            // 如果没有找到，尝试模糊匹配
            $conversion = CategoryConversion::where('tax_standard', 'like', "%{$identityCategory}%")
                ->first();

            if ($conversion) {
                return $conversion->medical_export_standard;
            }

            return null;

        } catch (\Exception $e) {
            $this->output->writeln("<error>匹配身份类别失败: " . $e->getMessage() . "</error>");
            return null;
        }
    }

    public function handle()
    {
        // 设置脚本执行时间限制
        set_time_limit(600); // 10分钟
        
        $year = $this->input->getOption('year');
        if (!$year) {
            $this->output->writeln('<error>请指定验证的年份，使用 --year 参数</error>');
            return;
        }

        $year = (int) $year;
        $filePath = $this->input->getOption('file');
        
        if (!$filePath) {
            $this->output->writeln('<error>请指定Excel文件路径，使用 --file 参数</error>');
            return;
        }
        
        if (!file_exists($filePath)) {
            $this->output->writeln("<error>文件不存在: {$filePath}</error>");
            return;
        }
        
        $this->output->writeln("开始验证{$year}年身份类别...");

        try {
            // 读取Excel文件
            $command = "cd " . BASE_PATH . "/.. && source venv/bin/activate && python3 analyze_insurance_data.py --file=" . escapeshellarg($filePath) . " --mode=identity_verification";
            $output = [];
            $returnCode = 0;

            exec($command . " 2>&1", $output, $returnCode);

            if ($returnCode !== 0) {
                $this->output->writeln('<error>读取Excel文件失败</error>');
                $this->output->writeln('错误信息: ' . implode("\n", $output));
                return;
            }

            $this->output->writeln('Python输出: ' . implode("\n", $output));
            $jsonOutput = implode("\n", $output);
            $data = json_decode($jsonOutput, true);
            if (!$data) {
                $this->output->writeln('<error>解析Excel数据失败</error>');
                $this->output->writeln('JSON错误: ' . json_last_error_msg());
                $this->output->writeln('JSON长度: ' . strlen($jsonOutput));
                return;
            }

            $this->output->writeln('Excel数据读取成功，共 ' . count($data) . ' 条记录');

            $totalCount = 0;
            $matchedCount = 0;
            $unmatchedCount = 0;
            $errorCount = 0;

            foreach ($data as $row) {
                try {
                    $totalCount++;
                    
                    $idNumber = $row['身份证'] ?? '';
                    $identityCategory = $row['资助参保身份'] ?? '';
                    $name = $row['姓名'] ?? '';

                    if (empty($idNumber)) {
                        $this->outputResult([
                            'id_number' => $idNumber,
                            'name' => $name,
                            'original_category' => $identityCategory,
                            'matched_category' => '',
                            'status' => 'error',
                            'message' => '身份证号为空',
                        ]);
                        $errorCount++;
                        continue;
                    }

                    if (empty($identityCategory)) {
                        $this->outputResult([
                            'id_number' => $idNumber,
                            'name' => $name,
                            'original_category' => $identityCategory,
                            'matched_category' => '',
                            'status' => 'error',
                            'message' => '资助参保身份为空',
                        ]);
                        $errorCount++;
                        continue;
                    }

                    // 匹配payment_category
                    $matchedCategory = $this->matchPaymentCategory($identityCategory);

                    if ($matchedCategory) {
                        // 查找对应的保险数据记录
                        $insuranceData = InsuranceData::where('year', $year)
                            ->where('id_number', $idNumber)
                            ->first();

                        if ($insuranceData) {
                            // 更新category_match字段
                            $insuranceData->update([
                                'category_match' => '已匹配',
                                'medical_assistance_category' => $matchedCategory,
                            ]);

                            $this->outputResult([
                                'id_number' => $idNumber,
                                'name' => $name,
                                'original_category' => $identityCategory,
                                'matched_category' => $matchedCategory,
                                'status' => 'matched',
                            ]);
                            $matchedCount++;
                        } else {
                            $this->outputResult([
                                'id_number' => $idNumber,
                                'name' => $name,
                                'original_category' => $identityCategory,
                                'matched_category' => $matchedCategory,
                                'status' => 'unmatched',
                                'message' => '未找到对应的保险数据记录',
                            ]);
                            $unmatchedCount++;
                        }
                    } else {
                        $this->outputResult([
                            'id_number' => $idNumber,
                            'name' => $name,
                            'original_category' => $identityCategory,
                            'matched_category' => '',
                            'status' => 'unmatched',
                            'message' => '未找到匹配的身份类别',
                        ]);
                        $unmatchedCount++;
                    }

                    if ($totalCount % 100 === 0) {
                        $this->output->writeln("已处理 {$totalCount} 条记录...");
                    }

                } catch (\Exception $e) {
                    $errorCount++;
                    $this->outputResult([
                        'id_number' => $idNumber ?? '',
                        'name' => $name ?? '',
                        'original_category' => $identityCategory ?? '',
                        'matched_category' => '',
                        'status' => 'error',
                        'message' => '处理失败: ' . $e->getMessage(),
                    ]);
                }
            }

            $this->output->writeln("验证完成！总处理: {$totalCount} 条，匹配成功: {$matchedCount} 条，未匹配: {$unmatchedCount} 条，错误: {$errorCount} 条");

        } catch (\Exception $e) {
            $this->output->writeln('<error>验证过程中出错: ' . $e->getMessage() . '</error>');
        }
    }

    /**
     * 输出验证结果
     */
    private function outputResult(array $result): void
    {
        $this->output->writeln('RESULT:' . json_encode($result, JSON_UNESCAPED_UNICODE));
    }
} 