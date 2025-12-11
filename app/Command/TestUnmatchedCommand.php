<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceData;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

/**
 * @Command
 */
class TestUnmatchedCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('test:unmatched');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('测试未匹配记录查询');
    }

    public function handle()
    {
        $this->output->writeln('测试未匹配记录查询...');
        
        try {
            // 查询2025年未匹配的记录
            $unmatched2025 = InsuranceData::where('year', 2025)->whereNull('level')->count();
            $this->output->writeln("2025年未匹配记录数: {$unmatched2025}");
            
            // 查询2026年未匹配的记录
            $unmatched2026 = InsuranceData::where('year', 2026)->whereNull('level')->count();
            $this->output->writeln("2026年未匹配记录数: {$unmatched2026}");
            
            // 查询所有未匹配的记录
            $unmatchedAll = InsuranceData::whereNull('level')->count();
            $this->output->writeln("所有未匹配记录数: {$unmatchedAll}");
            
            // 查询前几条未匹配的记录
            $unmatchedRecords = InsuranceData::whereNull('level')->limit(3)->get();
            $this->output->writeln("前3条未匹配记录:");
            foreach ($unmatchedRecords as $record) {
                $this->output->writeln("ID: {$record->id}, 年份: {$record->year}, 姓名: {$record->name}, 代缴类别: {$record->payment_category}, 代缴金额: {$record->payment_amount}, 档次: " . ($record->level ?? 'NULL'));
            }
            
        } catch (\Exception $e) {
            $this->output->writeln('<error>查询失败: ' . $e->getMessage() . '</error>');
        }
    }
} 