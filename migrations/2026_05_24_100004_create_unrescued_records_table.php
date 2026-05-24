<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateUnrescuedRecordsTable extends Migration
{
    public function up(): void
    {
        Schema::create('unrescued_records', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_period', 20)->comment('结算年月/导入月份');
            $table->string('sequence_no', 64)->comment('附件1序号');
            $table->string('name', 64)->nullable()->comment('姓名');
            $table->string('id_card', 32)->nullable()->comment('身份证号');
            $table->unsignedBigInteger('town_id')->nullable()->comment('镇街ID');
            $table->string('town_name', 64)->nullable()->comment('镇街名称');
            $table->string('assistance_identity', 128)->nullable()->comment('救助身份/人员类别');
            $table->string('medical_type', 64)->nullable()->comment('就医类型');
            $table->string('hospital_name', 128)->nullable()->comment('医疗机构');
            $table->string('disease_code', 64)->nullable()->comment('病种编码');
            $table->string('disease_name', 128)->nullable()->comment('病种名称');
            $table->date('admission_date')->nullable()->comment('入院日期');
            $table->date('discharge_date')->nullable()->comment('出院日期');
            $table->date('settlement_date')->nullable()->comment('结算日期');
            $table->decimal('total_amount', 14, 2)->default(0)->comment('医疗费总额');
            $table->decimal('eligible_amount', 14, 2)->default(0)->comment('符合范围金额');
            $table->decimal('fund_pay_amount', 14, 2)->default(0)->comment('医保基金支付金额');
            $table->decimal('assistance_amount', 14, 2)->default(0)->comment('医疗救助金额');
            $table->decimal('self_pay_amount', 14, 2)->default(0)->comment('个人自付金额');
            $table->decimal('estimated_assistance_amount', 14, 2)->default(0)->comment('测算应救助金额');
            $table->decimal('suggest_refund_amount', 14, 2)->default(0)->comment('建议应退金额');
            $table->decimal('suggest_reissue_amount', 14, 2)->default(0)->comment('建议应补金额');
            $table->string('bank_name', 128)->nullable()->comment('开户行');
            $table->string('bank_account', 64)->nullable()->comment('银行卡号');
            $table->string('contact_phone', 32)->nullable()->comment('联系电话');
            $table->tinyInteger('status')->default(0)->comment('状态:0拟通知,1已分发,2已通知,3已报销,9已剔除');
            $table->string('wash_reason', 255)->nullable()->comment('剔除原因');
            $table->timestamp('distributed_at')->nullable()->comment('分发时间');
            $table->timestamp('received_at')->nullable()->comment('领取时间');
            $table->timestamp('notified_at')->nullable()->comment('通知时间');
            $table->timestamp('reimbursed_at')->nullable()->comment('报销时间');
            $table->unsignedBigInteger('import_task_id')->nullable()->comment('导入任务ID');
            $table->string('source_file', 255)->nullable()->comment('来源文件');
            $table->json('raw_data')->nullable()->comment('原始行数据');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['settlement_period', 'sequence_no'], 'idx_unrescued_period_sequence');
            $table->index(['settlement_period', 'id_card'], 'idx_unrescued_period_id_card');
            $table->index(['town_id', 'status'], 'idx_unrescued_town_status');
            $table->index('status', 'idx_unrescued_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unrescued_records');
    }
}
