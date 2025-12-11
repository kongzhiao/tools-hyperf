<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceYear;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class InitInsuranceYearsCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('init:insurance-years');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('初始化保险年份数据');
    }

    public function handle()
    {
        $this->output->writeln('开始初始化保险年份数据...');

        try {
            // 初始化2025年
            if (!InsuranceYear::yearExists(2025)) {
                InsuranceYear::createYear(2025, '2025年度参保数据');
                $this->output->writeln('✓ 成功创建2025年');
            } else {
                $this->output->writeln('✓ 2025年已存在');
            }

            // 初始化2026年
            if (!InsuranceYear::yearExists(2026)) {
                InsuranceYear::createYear(2026, '2026年度参保数据');
                $this->output->writeln('✓ 成功创建2026年');
            } else {
                $this->output->writeln('✓ 2026年已存在');
            }

            // 显示所有年份
            $years = InsuranceYear::getAllActiveYears();
            $this->output->writeln('当前所有年份: ' . json_encode($years));

        } catch (\Exception $e) {
            $this->output->writeln('<error>初始化失败: ' . $e->getMessage() . '</error>');
        }
    }
} 