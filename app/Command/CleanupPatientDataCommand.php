<?php

declare(strict_types=1);

namespace App\Command;

use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Hyperf\DbConnection\Db;
use Psr\Container\ContainerInterface;

/**
 * @Command
 */
#[Command]
class CleanupPatientDataCommand extends HyperfCommand
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct('cleanup:patient-data');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Clean up patient data and related records');
    }

    public function handle()
    {
        $this->output->title('Starting patient data cleanup');

        try {
            // 开启事务
            Db::beginTransaction();

            // 1. 获取要删除的患者ID
            $patientIds = Db::table('med_person_info')
                ->whereIn('name', ['刁全树', '许林'])
                ->pluck('id')
                ->toArray();

            if (empty($patientIds)) {
                $this->output->warning('No patients found with the specified names');
                return;
            }

            $this->output->info(sprintf('Found %d patients to clean up', count($patientIds)));

            // 2. 删除报销明细记录
            $reimbursementCount = Db::table('med_reimbursement_detail')
                ->whereIn('person_id', $patientIds)
                ->delete();
            $this->output->info(sprintf('Deleted %d reimbursement records', $reimbursementCount));

            // 3. 删除就诊记录
            $medicalRecordCount = Db::table('med_medical_record')
                ->whereIn('person_id', $patientIds)
                ->delete();
            $this->output->info(sprintf('Deleted %d medical records', $medicalRecordCount));

            // 4. 删除患者信息
            $patientCount = Db::table('med_person_info')
                ->whereIn('id', $patientIds)
                ->delete();
            $this->output->info(sprintf('Deleted %d patient records', $patientCount));

            // 提交事务
            Db::commit();
            $this->output->success('Patient data cleanup completed successfully');

        } catch (\Exception $e) {
            // 回滚事务
            Db::rollBack();
            $this->output->error('Error during cleanup: ' . $e->getMessage());
            $this->output->error('Transaction rolled back');
        }
    }
} 