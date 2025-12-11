<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceData;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class TestSummaryCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('test:summary');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('测试统计逻辑');
    }

    public function handle()
    {
        $this->output->writeln('开始测试统计逻辑...');
        
        $year = 2025;
        
        // 获取所有代缴类别
        $categories = InsuranceData::query()
            ->select('payment_category')
            ->where('year', $year)
            ->whereNotNull('payment_category')
            ->where('payment_category', '!=', '')
            ->distinct()
            ->pluck('payment_category')
            ->toArray();

        $this->output->writeln("找到的代缴类别: " . implode(', ', $categories));

        // 获取所有档次
        $levels = InsuranceData::query()
            ->select('level')
            ->where('year', $year)
            ->whereNotNull('level')
            ->where('level', '!=', '')
            ->distinct()
            ->pluck('level')
            ->toArray();

        $this->output->writeln("找到的档次: " . implode(', ', $levels));

        // 获取所有镇街
        $streetTowns = InsuranceData::query()
            ->select('street_town')
            ->where('year', $year)
            ->whereNotNull('street_town')
            ->where('street_town', '!=', '')
            ->distinct()
            ->pluck('street_town')
            ->toArray();

        $this->output->writeln("找到的镇街数量: " . count($streetTowns));

        // 测试一个具体的统计查询
        $testStreetTown = $streetTowns[0] ?? '';
        $testCategory = $categories[0] ?? '';
        $testLevel = $levels[0] ?? '';

        if ($testStreetTown && $testCategory && $testLevel) {
            $this->output->writeln("测试统计查询:");
            $this->output->writeln("镇街: $testStreetTown");
            $this->output->writeln("代缴类别: $testCategory");
            $this->output->writeln("档次: $testLevel");

            $levelStats = InsuranceData::query()
                ->selectRaw('COUNT(*) as count, SUM(payment_amount) as amount')
                ->where('year', $year)
                ->where('street_town', $testStreetTown)
                ->where('payment_category', $testCategory)
                ->where('level', $testLevel)
                ->first();

            $this->output->writeln("查询结果:");
            $this->output->writeln("数量: " . ($levelStats->count ?? 0));
            $this->output->writeln("金额: " . ($levelStats->amount ?? 0));

            // 检查原始数据
            $rawData = InsuranceData::query()
                ->where('year', $year)
                ->where('street_town', $testStreetTown)
                ->where('payment_category', $testCategory)
                ->where('level', $testLevel)
                ->limit(5)
                ->get(['payment_amount', 'personal_amount', 'level']);

            $this->output->writeln("原始数据样本:");
            foreach ($rawData as $item) {
                $this->output->writeln("代缴金额: {$item->payment_amount}, 个人实缴金额: {$item->personal_amount}, 档次: {$item->level}");
            }
        }

        // 检查总体数据
        $totalCount = InsuranceData::query()
            ->where('year', $year)
            ->count();

        $totalAmount = InsuranceData::query()
            ->where('year', $year)
            ->sum('payment_amount');

        $this->output->writeln("总体统计:");
        $this->output->writeln("总记录数: $totalCount");
        $this->output->writeln("总代缴金额: $totalAmount");

        $this->output->writeln('测试完成！');
    }
} 