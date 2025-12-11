<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceYear;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class TestInsuranceYearsCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('test:insurance-years');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('测试保险年份管理');
    }

    public function handle()
    {
        $this->output->writeln('测试保险年份管理...');

        try {
            // 检查年份管理表
            $allYears = InsuranceYear::all();
            $this->output->writeln('年份管理表中的所有记录:');
            foreach ($allYears as $year) {
                $this->output->writeln("- ID: {$year->id}, 年份: {$year->year}, 描述: {$year->description}, 激活: " . ($year->is_active ? '是' : '否'));
            }

            // 检查活跃年份
            $activeYears = InsuranceYear::getAllActiveYears();
            $this->output->writeln('活跃年份: ' . json_encode($activeYears));

            // 检查2026年是否存在
            $year2026Exists = InsuranceYear::yearExists(2026);
            $this->output->writeln('2026年是否存在: ' . ($year2026Exists ? '是' : '否'));

        } catch (\Exception $e) {
            $this->output->writeln('<error>测试失败: ' . $e->getMessage() . '</error>');
        }
    }
} 