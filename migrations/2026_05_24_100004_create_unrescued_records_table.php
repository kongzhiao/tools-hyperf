<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateUnrescuedRecordsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unrescued_records')) {
            return;
        }

        Schema::create('unrescued_records', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_period', 20)->default('')->comment('清算期，格式如202601');
            $table->string('sequence_no', 50)->default('')->comment('附件1序号');
            $table->string('source_batch', 50)->default('')->comment('附件1导入批次');
            $table->string('name', 50)->nullable()->comment('姓名');
            $table->string('id_card', 32)->nullable()->comment('身份证号');
            $table->string('medical_category', 100)->nullable()->comment('医疗类别');
            $table->string('disease_code', 50)->nullable()->comment('病种编码');
            $table->string('disease_name', 255)->nullable()->comment('病种名称');
            $table->string('cert_location', 100)->nullable()->comment('认定地');
            $table->string('hospital_name', 255)->nullable()->comment('医药机构名称');
            $table->string('hospital_code', 100)->nullable()->comment('医药机构编码');
            $table->string('in_out_city', 50)->nullable()->comment('市内/市外');
            $table->date('admission_date')->nullable()->comment('入院时间');
            $table->date('discharge_date')->nullable()->comment('出院时间');
            $table->dateTime('settlement_time')->nullable()->comment('结算时间');
            $table->decimal('total_fee', 14, 2)->default(0)->comment('医疗总费用');
            $table->decimal('policy_fee', 14, 2)->default(0)->comment('医保政策范围费用');
            $table->decimal('pool_fund_pay', 14, 2)->default(0)->comment('统筹报销金额');
            $table->decimal('large_amount_pay', 14, 2)->default(0)->comment('大额报销');
            $table->decimal('serious_illness_pay', 14, 2)->default(0)->comment('大病报销');
            $table->decimal('used_outpatient_rescue', 14, 2)->default(0)->comment('已使用门诊救助金额');
            $table->decimal('used_normal_rescue', 14, 2)->default(0)->comment('已使用普通住院救助金额');
            $table->decimal('used_major_rescue', 14, 2)->default(0)->comment('已使用重特大疾病救助金额');
            $table->decimal('used_large_fee_rescue', 14, 2)->default(0)->comment('已使用大额费用住院救助');
            $table->decimal('calc_reimbursement_amount', 14, 2)->default(0)->comment('进入报销金额');
            $table->unsignedBigInteger('town_id')->default(0)->comment('匹配到的镇街ID');
            $table->string('street_town', 100)->nullable()->comment('镇街名称，来源附件2');
            $table->string('village', 100)->nullable()->comment('村社');
            $table->string('priority_identity', 100)->nullable()->comment('优先身份/对象类别');
            $table->string('status', 50)->default('待处理')->comment('待处理、无救助金额、不通知、拟通知、已下放、已接收、已通知');
            $table->string('reimbursement_status', 20)->default('未报销')->comment('未报销、已报销');
            $table->string('exclude_status', 20)->default('未剔除')->comment('未剔除、已剔除');
            $table->string('exclude_rule_code', 100)->nullable()->comment('命中的清洗规则编码');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->dateTime('distributed_at')->nullable()->comment('下放时间');
            $table->dateTime('received_at')->nullable()->comment('镇街接收时间');
            $table->dateTime('notified_at')->nullable()->comment('镇街通知时间');
            $table->dateTime('reimbursed_at')->nullable()->comment('标记已报销时间');
            $table->string('bank_name', 100)->nullable()->comment('开户行');
            $table->string('bank_account_name', 100)->nullable()->comment('户名');
            $table->string('bank_account_no', 100)->nullable()->comment('银行账号');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['settlement_period', 'sequence_no'], 'idx_period_sequence');
            $table->index(['settlement_period', 'id_card'], 'idx_period_id_card');
            $table->index(['settlement_period', 'town_id', 'status'], 'idx_period_town_status');
            $table->index(['settlement_period', 'status'], 'idx_period_status');
            $table->index('exclude_status', 'idx_exclude_status');
            $table->index('reimbursement_status', 'idx_reimbursement_status');
            $table->comment('未救助记录表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unrescued_records');
    }
}
