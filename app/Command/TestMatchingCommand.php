<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceLevelConfig;
use App\Model\InsuranceData;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class TestMatchingCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('test:matching');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('测试档次匹配逻辑');
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
                    'matched' => true
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
                    'matched' => true
                ];
            }

            // 如果都没有匹配到，返回默认值
            return [
                'level' => null,
                'personal_amount' => 0,
                'matched' => false
            ];

        } catch (\Exception $e) {
            $this->output->writeln("<error>匹配档次配置失败: " . $e->getMessage() . "</error>");
            return [
                'level' => null,
                'personal_amount' => 0,
                'matched' => false
            ];
        }
    }

    public function handle()
    {
        $this->output->writeln('开始测试档次匹配逻辑...');
        
        // 检查配置数据
        $this->output->writeln('=== 检查参保资助档次配置 ===');
        $configs = InsuranceLevelConfig::all();
        $configCount = count($configs);
        if ($configCount === 0) {
            $this->output->writeln('<error>没有找到参保资助档次配置数据</error>');
            $this->output->writeln('请先在"参保资助档次配置"页面添加配置数据');
            return;
        }
        
        $this->output->writeln("找到 {$configCount} 条配置数据:");
        foreach ($configs as $config) {
            $this->output->writeln("- 年份: {$config->year}, 代缴类别: {$config->payment_category}, 档次: {$config->level}, 资助金额: {$config->subsidy_amount}, 个人实缴金额: {$config->personal_amount}");
        }
        
        // 检查保险数据
        $this->output->writeln('\n=== 检查保险数据 ===');
        $insuranceData = InsuranceData::limit(5)->get();
        if ($insuranceData->isEmpty()) {
            $this->output->writeln('<error>没有找到保险数据</error>');
            return;
        }
        
        $this->output->writeln("找到 {$insuranceData->count()} 条保险数据样本:");
        foreach ($insuranceData as $data) {
            $this->output->writeln("- 年份: {$data->year}, 代缴类别: {$data->payment_category}, 代缴金额: {$data->payment_amount}, 档次: {$data->level}, 个人实缴金额: {$data->personal_amount}");
        }
        
        // 测试匹配逻辑
        $this->output->writeln('\n=== 测试匹配逻辑 ===');
        foreach ($insuranceData as $data) {
            $match = $this->matchLevelAndPersonalAmount($data->payment_category, (float)$data->payment_amount, $data->year);
            $status = $match['matched'] ? '匹配成功' : '匹配失败';
            $this->output->writeln("测试: 代缴类别={$data->payment_category}, 代缴金额={$data->payment_amount}, 年份={$data->year} -> {$status}");
            if ($match['matched']) {
                $this->output->writeln("  匹配结果: 档次={$match['level']}, 个人实缴金额={$match['personal_amount']}");
            }
        }
        
        // 统计匹配情况
        $this->output->writeln('\n=== 统计匹配情况 ===');
        $totalCount = InsuranceData::count();
        $matchedCount = InsuranceData::whereNotNull('level')->count();
        $unmatchedCount = InsuranceData::whereNull('level')->count();
        
        $this->output->writeln("总数据量: {$totalCount}");
        $this->output->writeln("已匹配: {$matchedCount}");
        $this->output->writeln("未匹配: {$unmatchedCount}");
        $this->output->writeln("匹配率: " . round(($matchedCount / $totalCount) * 100, 2) . "%");
        
        // 按年份统计
        $this->output->writeln('\n=== 按年份统计 ===');
        $yearStats = InsuranceData::selectRaw('year, COUNT(*) as total, COUNT(level) as matched, COUNT(*) - COUNT(level) as unmatched')
            ->groupBy('year')
            ->get();
            
        foreach ($yearStats as $stat) {
            $matchRate = $stat->total > 0 ? round(($stat->matched / $stat->total) * 100, 2) : 0;
            $this->output->writeln("{$stat->year}年: 总数={$stat->total}, 已匹配={$stat->matched}, 未匹配={$stat->unmatched}, 匹配率={$matchRate}%");
        }
    }
} 