<?php

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class CreateUnrescuedRecordsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('unrescued_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sequence_no', 50)->default('')->comment('序号 (附件1唯一匹配键)');
            $table->string('name', 50)->nullable()->comment('姓名');
            $table->string('id_card', 18)->nullable()->comment('身份证号 (附件2关联键)');
            $table->string('medical_category', 100)->nullable()->comment('医疗类别');
            $table->string('disease_code', 50)->nullable()->comment('病种编码');
            $table->string('disease_name', 255)->nullable()->comment('病种名称');
            $table->string('cert_location', 100)->nullable()->comment('认定地');
            $table->string('hospital_name', 255)->nullable()->comment('医药机构名称');
            $table->string('hospital_code', 100)->nullable()->comment('医药机构编码');
            $table->string('in_out_city', 50)->nullable()->comment('市（内）外');
            $table->date('admission_date')->nullable()->comment('入院时间');
            $table->date('discharge_date')->nullable()->comment('出院时间');
            $table->dateTime('settlement_time')->nullable()->comment('结算时间');
            $table->decimal('total_fee', 14, 2)->default(0.00)->comment('医疗总费用');
            $table->decimal('policy_fee', 14, 2)->default(0.00)->comment('医保政策范围费用');
            $table->decimal('pool_fund_pay', 14, 2)->default(0.00)->comment('统筹报销金额');
            $table->decimal('large_amount_pay', 14, 2)->default(0.00)->comment('大额报销');
            $table->decimal('serious_illness_pay', 14, 2)->default(0.00)->comment('大病报销');
            $table->decimal('used_outpatient_rescue', 14, 2)->default(0.00)->comment('已使用门诊救助金额');
            $table->decimal('used_normal_rescue', 14, 2)->default(0.00)->comment('已使用普通住院救助金额');
            $table->decimal('used_major_rescue', 14, 2)->default(0.00)->comment('已使用重特大疾病救助金额');
            $table->decimal('used_large_fee_rescue', 14, 2)->default(0.00)->comment('已使用大额费用住院救助');
            $table->decimal('calc_reimbursement_amount', 14, 2)->default(0.00)->comment('进入报销金额 (系统基于公式计算)');
            $table->string('settlement_period', 20)->nullable()->comment('清算期 (如202601)');
            $table->string('street_town', 100)->nullable()->comment('镇街 (来源附件2)');
            $table->string('village', 100)->nullable()->comment('村社 (来源附件2)');
            $table->string('priority_identity', 100)->nullable()->comment('优先身份/对象类别 (来源附件2)');
            $table->string('status', 50)->default('待处理')->comment('状态(待处理、无救助金额、不通知、待通知、已下放、已接收、已通知)');
            $table->string('reimbursement_status', 20)->default('未报销')->comment('报销状态 (未报销、已报销)');
            $table->string('exclude_status', 20)->default('未剔除')->comment('剔除状态 (未剔除、已剔除)');
            $table->string('remark', 255)->nullable()->comment('备注 (主要用于记录被剔除的原因)');
            $table->string('bank_name', 100)->nullable()->comment('开户行 (镇街回填)');
            $table->string('bank_account_name', 100)->nullable()->comment('户名 (镇街回填)');
            $table->string('bank_account_no', 100)->nullable()->comment('账号录入 (镇街回填)');
            
            $table->timestamps();
            $table->softDeletes();
            
            // 索引
            $table->unique('sequence_no', 'idx_sequence_no');
            $table->index('id_card', 'idx_id_card');
            $table->index('street_town', 'idx_street_town_standalone'); // 独立镇街索引，方便全局模块复用过滤
            $table->index(['street_town', 'status'], 'idx_street_town_status');
            $table->index('settlement_period', 'idx_settlement_period');
        });
        
        \Hyperf\Database\Schema\Schema::getConnection()->statement("ALTER TABLE `unrescued_records` comment '未救助台账-明细主表'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unrescued_records');
    }
}
