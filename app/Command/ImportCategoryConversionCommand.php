<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\CategoryConversion;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

/**
 * @Command
 */
class ImportCategoryConversionCommand extends HyperfCommand
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        parent::__construct('import:category-conversion');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('导入类别转换数据');
    }

    public function handle()
    {
        $this->output->writeln('开始导入类别转换数据...');
        
        // 基于分析结果，手动创建数据
        $data = [
            ['特困人员-7131', '特困人员', '特困人员'],
            ['低保中重残重病人员-7019', '民政城乡低保对象', '低保对象'],
            ['城乡孤儿-7041', '民政城乡孤儿', '城乡孤儿'],
            ['事实无人抚养儿童-7141', '事实无人抚养儿童', '事实无人抚养儿童'],
            ['在乡老复员军人-7090', '在乡复原军人', '民政在乡老复员军人'],
            ['在乡重点优抚对象-7052', '民政在乡重点优抚对象(不含1-6级残疾军人)', '在乡重点优抚对象（不含1-6级残疾军人）'],
            ['城乡重度（1~2级）残疾人员-7069', '民政城乡重度(一、二级)残疾人员', '城乡重度（1-2级）残疾人员'],
            ['突发严重困难户-7205', '突发严重困难户', '突发严重困难户'],
            ['脱贫不稳定户-7203', '脱贫不稳定户', '脱贫不稳定户'],
            ['边缘易致贫户-7204', '边缘易致贫户', '边缘易致贫户'],
            ['返贫致贫人口-7202', '返贫致贫人口', '返贫致贫人口'],
            ['低保边缘户-7201', '低保边缘户', '低保边缘户'],
            ['因病致贫家庭重病患者-7110', '因病致贫家庭重病患者', '因病致贫家庭重病患者'],
        ];

        $importedCount = 0;
        $skippedCount = 0;

        foreach ($data as $row) {
            $medicalExportStandard = trim($row[0]);
            $taxStandard = trim($row[1]);
            $nationalDictName = trim($row[2]);

            // 检查是否已存在相同的记录
            $exists = CategoryConversion::where('tax_standard', $taxStandard)
                ->where('medical_export_standard', $medicalExportStandard)
                ->where('national_dict_name', $nationalDictName)
                ->exists();

            if ($exists) {
                $skippedCount++;
                continue;
            }

            // 创建记录
            CategoryConversion::create([
                'tax_standard' => $taxStandard,
                'medical_export_standard' => $medicalExportStandard,
                'national_dict_name' => $nationalDictName,
            ]);

            $importedCount++;
        }

        $this->output->writeln("导入完成！");
        $this->output->writeln("成功导入: {$importedCount} 条记录");
        $this->output->writeln("跳过重复: {$skippedCount} 条记录");
    }
} 