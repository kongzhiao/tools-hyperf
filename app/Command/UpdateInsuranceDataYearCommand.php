<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceData;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class UpdateInsuranceDataYearCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('update:insurance-data-year');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('更新参保数据的年份字段');
    }

    public function handle()
    {
        $this->output->writeln('开始更新参保数据年份...');

        try {
            // 更新所有记录的年份为2025
            $count = InsuranceData::where('year', 2024)->update(['year' => 2025]);
            
            $this->output->writeln("成功更新 {$count} 条记录的年份为2025");
            
            // 显示统计信息
            $totalCount = InsuranceData::count();
            $year2025Count = InsuranceData::where('year', 2025)->count();
            
            $this->output->writeln("总记录数: {$totalCount}");
            $this->output->writeln("2025年记录数: {$year2025Count}");
            
        } catch (\Exception $e) {
            $this->output->writeln('<error>更新失败: ' . $e->getMessage() . '</error>');
        }
    }
} 