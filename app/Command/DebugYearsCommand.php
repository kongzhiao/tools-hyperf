<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceData;
use App\Model\InsuranceYear;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class DebugYearsCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('debug:years');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('调试年份获取方法');
    }

    public function handle()
    {
        $this->output->writeln('调试年份获取方法...');

        try {
            // 测试年份管理表
            $managedYears = InsuranceYear::getAllActiveYears();
            $this->output->writeln('管理表年份: ' . json_encode($managedYears));

            // 测试数据表年份
            $dataYears = InsuranceData::distinct()->pluck('year')->sort()->values()->toArray();
            $this->output->writeln('数据表年份: ' . json_encode($dataYears));

            // 测试合并结果
            $allYears = array_unique(array_merge($managedYears, $dataYears));
            sort($allYears);
            $this->output->writeln('合并后年份: ' . json_encode($allYears));

            // 测试getAllYears方法
            $result = InsuranceData::getAllYears();
            $this->output->writeln('getAllYears结果: ' . json_encode($result));

        } catch (\Exception $e) {
            $this->output->writeln('<error>调试失败: ' . $e->getMessage() . '</error>');
        }
    }
} 