<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceLevelConfig;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;

#[Command]
class ImportInsuranceLevelConfigCommand extends HyperfCommand
{
    protected ?string $name = 'import:insurance-level-config';

    public function configure()
    {
        parent::configure();
        $this->setDescription('导入参保资助档次配置数据');
    }

    public function handle()
    {
        $this->output->writeln('开始导入参保资助档次配置数据...');

        // 2025年数据（基于Excel分析结果）
        $data = [
            [
                'year' => 2025,
                'payment_category' => '民政城乡低保对象',
                'level' => '居民一档',
                'subsidy_amount' => 360.00,
                'personal_amount' => 40.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '民政城乡低保对象',
                'level' => '居民二档',
                'subsidy_amount' => 400.00,
                'personal_amount' => 375.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '民政城乡孤儿',
                'level' => '居民一档',
                'subsidy_amount' => 400.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '民政城乡孤儿',
                'level' => '居民二档',
                'subsidy_amount' => 400.00,
                'personal_amount' => 375.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '民政在乡重点优抚对象(不含1-6级残疾军人)',
                'level' => '居民一档',
                'subsidy_amount' => 400.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局、区退役军人事务局',
            ],
            [
                'year' => 2025,
                'payment_category' => '民政在乡重点优抚对象(不含1-6级残疾军人)',
                'level' => '居民二档',
                'subsidy_amount' => 400.00,
                'personal_amount' => 375.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局、区退役军人事务局',
            ],
            [
                'year' => 2025,
                'payment_category' => '民政城乡重度(一、二级)残疾人员',
                'level' => '居民一档',
                'subsidy_amount' => 280.00,
                'personal_amount' => 120.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '民政家庭经济困难大学生',
                'level' => '大学生一档',
                'subsidy_amount' => 380.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '民政家庭经济困难大学生',
                'level' => '大学生二档',
                'subsidy_amount' => 420.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '特困人员',
                'level' => '居民一档',
                'subsidy_amount' => 400.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '事实无人抚养儿童',
                'level' => '居民一档',
                'subsidy_amount' => 400.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '边缘易致贫户',
                'level' => '居民一档',
                'subsidy_amount' => 280.00,
                'personal_amount' => 120.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '脱贫不稳定户',
                'level' => '居民一档',
                'subsidy_amount' => 280.00,
                'personal_amount' => 120.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '低保边缘户',
                'level' => '居民一档',
                'subsidy_amount' => 280.00,
                'personal_amount' => 120.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '返贫致贫人口',
                'level' => '居民一档',
                'subsidy_amount' => 280.00,
                'personal_amount' => 120.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '突发严重困难户',
                'level' => '居民一档',
                'subsidy_amount' => 280.00,
                'personal_amount' => 120.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '因病致贫家庭重病患者',
                'level' => '居民一档',
                'subsidy_amount' => 280.00,
                'personal_amount' => 120.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局',
            ],
            [
                'year' => 2025,
                'payment_category' => '烈士遗属',
                'level' => '居民二档',
                'subsidy_amount' => 775.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局、区退役军人事务局',
            ],
            [
                'year' => 2025,
                'payment_category' => '因公牺牲军人遗属',
                'level' => '居民二档',
                'subsidy_amount' => 775.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局、区退役军人事务局',
            ],
            [
                'year' => 2025,
                'payment_category' => '病故军人遗属',
                'level' => '居民二档',
                'subsidy_amount' => 775.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局、区退役军人事务局',
            ],
            [
                'year' => 2025,
                'payment_category' => '在乡复原军人',
                'level' => '居民二档',
                'subsidy_amount' => 775.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局、区退役军人事务局',
            ],
            [
                'year' => 2025,
                'payment_category' => '民政在乡老复员军人',
                'level' => '居民二档',
                'subsidy_amount' => 775.00,
                'personal_amount' => 0.00,
                'effective_period' => '2024.10.1-2025.6.30',
                'payment_department' => '区医保局、区退役军人事务局',
            ],
        ];

        $successCount = 0;
        $skipCount = 0;

        foreach ($data as $item) {
            // 检查是否已存在
            $exists = InsuranceLevelConfig::where('year', $item['year'])
                ->where('payment_category', $item['payment_category'])
                ->where('level', $item['level'])
                ->exists();

            if ($exists) {
                $skipCount++;
                continue;
            }

            try {
                InsuranceLevelConfig::create($item);
                $successCount++;
                $this->output->writeln("✓ 导入: {$item['payment_category']} - {$item['level']}");
            } catch (\Exception $e) {
                $this->output->writeln("✗ 导入失败: {$item['payment_category']} - {$item['level']} - {$e->getMessage()}");
            }
        }

        $this->output->writeln("导入完成！成功: {$successCount} 条，跳过: {$skipCount} 条");
    }
} 