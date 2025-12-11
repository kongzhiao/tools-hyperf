<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceData;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;

/**
 * @Command
 */
class CheckLevelDataCommand extends HyperfCommand
{
    protected ?string $name = 'check:level-data';

    public function configure()
    {
        parent::configure();
        $this->setDescription('检查数据库中实际的档次数据');
    }

    public function handle()
    {
        $this->line('检查数据库中实际的档次数据...');

        // 检查所有不同的档次值
        $levels = InsuranceData::query()
            ->select('level')
            ->whereNotNull('level')
            ->where('level', '!=', '')
            ->distinct()
            ->pluck('level')
            ->toArray();

        $this->line('数据库中存在的档次值:');
        foreach ($levels as $level) {
            $this->line("- $level");
        }

        // 检查每个档次的记录数量
        foreach ($levels as $level) {
            $count = InsuranceData::query()
                ->where('level', $level)
                ->count();
            $this->line("档次 '$level' 的记录数量: $count");
        }

        // 检查没有档次的记录数量
        $nullCount = InsuranceData::query()
            ->whereNull('level')
            ->orWhere('level', '')
            ->count();
        $this->line("没有档次的记录数量: $nullCount");

        // 检查2025年的数据
        $this->line("\n2025年的档次数据:");
        $levels2025 = InsuranceData::query()
            ->select('level')
            ->where('year', 2025)
            ->whereNotNull('level')
            ->where('level', '!=', '')
            ->distinct()
            ->pluck('level')
            ->toArray();

        foreach ($levels2025 as $level) {
            $count = InsuranceData::query()
                ->where('year', 2025)
                ->where('level', $level)
                ->count();
            $this->line("2025年档次 '$level' 的记录数量: $count");
        }

        $this->line('检查完成！');
    }
} 