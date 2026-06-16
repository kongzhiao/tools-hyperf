<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateEnrollTables extends Migration
{
    public function up(): void
    {
        $this->createEnrollConfigs();
        $this->createEnrollIdentityAmountConfigs();
        $this->createEnrollLedgers();
        $this->createEnrollLedgerSnapshots();
        $this->createEnrollImportBatches();
    }

    public function down(): void
    {
        Schema::dropIfExists('enroll_import_batches');
        Schema::dropIfExists('enroll_ledger_snapshots');
        Schema::dropIfExists('enroll_ledgers');
        Schema::dropIfExists('enroll_identity_amount_configs');
        Schema::dropIfExists('enroll_configs');
    }

    private function createEnrollConfigs(): void
    {
        if (Schema::hasTable('enroll_configs')) {
            return;
        }

        Schema::create('enroll_configs', function (Blueprint $table) {
            $table->id();
            $table->integer('year')->comment('配置年份');
            $table->string('config_type', 30)->default('subsidy')->comment('配置类型：subsidy资助参保身份配置，medical医疗救助身份配置');
            $table->integer('priority')->default(0)->comment('优先级，值越小优先级越高');
            $table->string('identity_name', 150)->default('')->comment('身份名称');
            $table->string('insurance_level', 80)->nullable()->comment('资助档次/参保档次');
            $table->string('subsidy_standard', 120)->nullable()->comment('资助标准');
            $table->decimal('personal_amount', 14, 2)->default(0)->comment('个人实缴金额');
            $table->decimal('subsidy_amount', 14, 2)->default(0)->comment('资助代缴金额');
            $table->json('included_identities')->nullable()->comment('包含参保身份，JSON数组');
            $table->tinyInteger('status')->default(1)->comment('状态：1启用，0停用');
            $table->string('remark', 500)->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['year', 'config_type'], 'idx_year_config_type');
            $table->index(['year', 'config_type', 'identity_name'], 'idx_year_type_identity');
            $table->index(['year', 'config_type', 'priority'], 'idx_year_type_priority');
            $table->comment('参保身份配置表');
        });
    }

    private function createEnrollIdentityAmountConfigs(): void
    {
        if (Schema::hasTable('enroll_identity_amount_configs')) {
            return;
        }

        Schema::create('enroll_identity_amount_configs', function (Blueprint $table) {
            $table->id();
            $table->integer('year')->comment('配置年份');
            $table->string('special_identity', 150)->default('')->comment('特殊人员身份');
            $table->json('included_identities')->nullable()->comment('包含参保身份，JSON数组');
            $table->decimal('paid_amount', 14, 2)->default(0)->comment('实缴金额');
            $table->tinyInteger('status')->default(1)->comment('状态：1启用，0停用');
            $table->integer('sort')->default(0)->comment('排序值');
            $table->string('remark', 500)->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['year', 'special_identity'], 'idx_year_special_identity');
            $table->index(['year', 'paid_amount'], 'idx_year_paid_amount');
            $table->comment('身份实缴金额配置表');
        });
    }

    private function createEnrollLedgers(): void
    {
        if (Schema::hasTable('enroll_ledgers')) {
            return;
        }

        Schema::create('enroll_ledgers', function (Blueprint $table) {
            $table->id();
            $table->integer('year')->comment('台账年份');
            $table->string('id_card', 32)->default('')->comment('身份证号码，年度内人员主匹配字段');
            $table->string('name', 80)->nullable()->comment('姓名');
            $table->string('town_name', 120)->nullable()->comment('镇街名称');
            $table->string('village_name', 120)->nullable()->comment('村居名称');
            $table->string('included_month', 20)->nullable()->comment('纳入资助时间，YYYY-MM');
            $table->string('cancel_month', 20)->nullable()->comment('身份取消时间，YYYY-MM');
            $table->string('change_status', 30)->default('新增')->comment('身份变更情况：新增、变更、取消');
            $table->json('medical_identity_records')->nullable()->comment('医疗救助身份记录，JSON');
            $table->string('medical_identity', 150)->nullable()->comment('当前优先医疗救助身份');
            $table->json('subsidy_identity_records')->nullable()->comment('资助参保身份记录，JSON');
            $table->string('subsidy_identity', 150)->nullable()->comment('当前优先资助参保身份');
            $table->decimal('resident_payment_amount', 14, 2)->default(0)->comment('居民医保缴费金额');
            $table->string('payment_time', 30)->nullable()->comment('缴费时间');
            $table->string('raw_identity', 255)->nullable()->comment('附件3原始医疗救助身份');
            $table->decimal('subsidy_amount', 14, 2)->default(0)->comment('资助金额');
            $table->string('subsidy_identity_obtained', 150)->nullable()->comment('获得资助身份类别');
            $table->string('tax_request_batch', 80)->nullable()->comment('税务请款批次');
            $table->decimal('tax_first_request_amount', 14, 2)->default(0)->comment('税务第一批请款金额或清款情况');
            $table->string('insurance_category', 120)->nullable()->comment('参保类别');
            $table->string('is_insured', 20)->nullable()->comment('是否参保：是/否');
            $table->string('uninsured_reason', 255)->nullable()->comment('未参保原因');
            $table->string('is_eligible_for_subsidy', 20)->nullable()->comment('是否符合资助：是/否');
            $table->string('is_subsidy_obtained', 20)->nullable()->comment('是否获得资助：是/否');
            $table->string('subsidy_method', 80)->nullable()->comment('资助方式');
            $table->string('insurance_place_remark', 255)->nullable()->comment('资助地或参保地（区外备注）');
            $table->string('death_remark', 255)->nullable()->comment('备注（卫健委死亡时间）');
            $table->string('manual_remark', 500)->nullable()->comment('人工备注');
            $table->string('last_attachment3_period', 20)->nullable()->comment('最近一次附件3导入月份');
            $table->string('last_attachment4_period', 20)->nullable()->comment('最近一次附件4导入月份');
            $table->string('last_attachment5_period', 20)->nullable()->comment('最近一次附件5导入月份');
            $table->string('last_attachment6_period', 20)->nullable()->comment('最近一次附件6导入月份');
            $table->string('last_payment_time_period', 20)->nullable()->comment('最近一次通过附件3写入缴费时间的月份');
            $table->timestamps();

            $table->index(['year', 'id_card'], 'idx_year_id_card');
            $table->index(['year', 'last_attachment3_period'], 'idx_year_period');
            $table->index(['year', 'town_name'], 'idx_year_town');
            $table->index(['year', 'medical_identity'], 'idx_year_medical_identity');
            $table->index(['year', 'subsidy_identity'], 'idx_year_subsidy_identity');
            $table->index(['year', 'change_status'], 'idx_year_change_status');
            $table->index(['year', 'is_insured'], 'idx_year_is_insured');
            $table->comment('参保台账主表');
        });
    }

    private function createEnrollLedgerSnapshots(): void
    {
        if (Schema::hasTable('enroll_ledger_snapshots')) {
            return;
        }

        Schema::create('enroll_ledger_snapshots', function (Blueprint $table) {
            $table->id();
            $table->integer('year')->comment('台账年份');
            $table->string('period', 20)->comment('快照月份，YYYY-MM');
            $table->string('snapshot_type', 30)->default('after_import')->comment('快照类型：before_import导入前基线，after_import导入后结果');
            $table->string('id_card', 32)->default('')->comment('身份证号码');
            $table->string('name', 80)->nullable()->comment('姓名');
            $table->string('town_name', 120)->nullable()->comment('镇街名称');
            $table->string('village_name', 120)->nullable()->comment('村居名称');
            $table->string('included_month', 20)->nullable()->comment('纳入资助时间');
            $table->string('payment_time', 30)->nullable()->comment('缴费时间');
            $table->string('raw_identity', 255)->nullable()->comment('附件3原始医疗救助身份');
            $table->json('medical_identity_records')->nullable()->comment('当月医疗救助身份记录，JSON');
            $table->string('medical_identity', 150)->nullable()->comment('当月优先医疗救助身份');
            $table->json('subsidy_identity_records')->nullable()->comment('当月资助参保身份记录，JSON');
            $table->string('subsidy_identity', 150)->nullable()->comment('当月优先资助参保身份');
            $table->string('source_batch', 50)->nullable()->comment('来源批次');
            $table->timestamps();

            $table->index(['period', 'snapshot_type', 'id_card'], 'idx_period_type_id_card');
            $table->index(['year', 'period'], 'idx_year_period');
            $table->index(['year', 'town_name'], 'idx_year_town');
            $table->comment('参保台账月度快照表');
        });
    }

    private function createEnrollImportBatches(): void
    {
        if (Schema::hasTable('enroll_import_batches')) {
            return;
        }

        Schema::create('enroll_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 64)->nullable()->comment('任务UUID，对应task.uuid');
            $table->integer('year')->nullable()->comment('业务年份');
            $table->string('period', 20)->nullable()->comment('业务月份，YYYY-MM');
            $table->string('attachment_type', 50)->default('')->comment('附件类型');
            $table->string('file_name', 255)->nullable()->comment('上传文件名');
            $table->string('baseline_snapshot_batch', 50)->nullable()->comment('导入前基线快照批次');
            $table->integer('total_rows')->default(0)->comment('总行数');
            $table->integer('success_rows')->default(0)->comment('成功行数');
            $table->integer('failed_rows')->default(0)->comment('失败行数');
            $table->string('status', 30)->default('pending')->comment('导入状态');
            $table->text('message')->nullable()->comment('导入结果说明或失败原因');
            $table->unsignedBigInteger('created_by')->default(0)->comment('导入人用户ID');
            $table->timestamps();

            $table->index(['uuid'], 'idx_uuid');
            $table->index(['year', 'period', 'attachment_type'], 'idx_year_period_type');
            $table->index(['attachment_type', 'status'], 'idx_type_status');
            $table->comment('参保台账导入批次表');
        });
    }
}
