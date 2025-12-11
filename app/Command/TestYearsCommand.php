<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\InsuranceData;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class TestYearsCommand extends HyperfCommand
{
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('test:years');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('测试年份数据');
    }

    public function handle()
    {
        $this->output->writeln('检查上传限制配置...');
        
        $this->output->writeln('PHP配置:');
        $this->output->writeln('- upload_max_filesize: ' . ini_get('upload_max_filesize'));
        $this->output->writeln('- post_max_size: ' . ini_get('post_max_size'));
        $this->output->writeln('- max_file_uploads: ' . ini_get('max_file_uploads'));
        $this->output->writeln('- memory_limit: ' . ini_get('memory_limit'));
        
        $this->output->writeln('');
        $this->output->writeln('Swoole配置:');
        $this->output->writeln('- package_max_length: ' . (100 * 1024 * 1024) . ' bytes (100MB)');
        $this->output->writeln('- upload_tmp_dir: ' . BASE_PATH . '/runtime/temp');
        
        $this->output->writeln('');
        $this->output->writeln('建议:');
        $this->output->writeln('- 如果文件上传失败，请检查文件大小是否超过限制');
        $this->output->writeln('- 当前配置支持最大100MB的文件上传');
    }
} 