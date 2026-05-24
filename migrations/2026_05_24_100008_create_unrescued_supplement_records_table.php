<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateUnrescuedSupplementRecordsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unrescued_supplement_records')) {
            return;
        }
        
        Schema::create('unrescued_supplement_records', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_period', 20)->default('')->comment('清算期/月度');
            $table->string('source_batch', 50)->default('')->comment('数据来源批次');
            $table->string('name', 50)->nullable()->comment('姓名');
            $table->string('id_card', 32)->nullable()->comment('身份证号');
            $table->string('priority_identity', 100)->nullable()->comment('对象类别/优先身份');
            $table->unsignedBigInteger('town_id')->default(0)->comment('镇街ID');
            $table->string('street_town', 100)->nullable()->comment('镇街');
            $table->string('insurance_place', 100)->nullable()->comment('参保地');
            $table->string('insurance_category', 100)->nullable()->comment('参加险种');
            $table->string('hospital_name', 255)->nullable()->comment('就诊医疗机构名称');
            $table->string('medical_visit_category', 100)->nullable()->comment('医保就诊类别');
            $table->string('disease_code', 50)->nullable()->comment('疾病编码');
            $table->string('disease_name', 255)->nullable()->comment('疾病名称');
            $table->date('admission_date')->nullable()->comment('入院时间');
            $table->date('discharge_date')->nullable()->comment('出院时间');
            $table->dateTime('settlement_time')->nullable()->comment('结算时间');
            $table->decimal('total_fee', 14, 2)->default(0)->comment('总费用');
            $table->decimal('policy_fee', 14, 2)->default(0)->comment('医保政策范围内费用');
            $table->decimal('pool_fund_pay', 14, 2)->default(0)->comment('统筹报销金额');
            $table->decimal('large_amount_pay', 14, 2)->default(0)->comment('大额报销金额');
            $table->decimal('serious_illness_pay', 14, 2)->default(0)->comment('大病报销金额');
            $table->decimal('medical_assistance_pay', 14, 2)->default(0)->comment('医疗救助金额');
            $table->decimal('yukuaibao_pay', 14, 2)->default(0)->comment('渝快保报销金额');
            $table->decimal('personal_account_pay', 14, 2)->default(0)->comment('个人账户支付金额');
            $table->decimal('personal_cash_pay', 14, 2)->default(0)->comment('个人现金支付金额');
            $table->decimal('calc_medical_assistance_amount', 14, 2)->default(0)->comment('进入医疗救助金额');
            $table->string('status', 50)->default('待处理')->comment('待处理、纳入排查、不纳入排查');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['settlement_period', 'id_card'], 'idx_period_id_card');
            $table->index(['settlement_period', 'town_id'], 'idx_period_town');
            $table->index(['settlement_period', 'status'], 'idx_period_status');
            $table->index('disease_code', 'idx_disease_code');
            $table->comment('未救助补充记录表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unrescued_supplement_records');
    }
}
