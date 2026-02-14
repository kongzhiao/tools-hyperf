<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class CreateYfSettlementsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('yf_settlements', function (Blueprint $table) {
            $table->id();

            // 导入原始字段
            $table->string('name', 20)->comment('姓名');
            $table->string('id_card', 20)->comment('身份证号');
            $table->string('category', 50)->comment('优抚类别');
            $table->string('insurance_type', 20)->nullable()->comment('医保类别');
            $table->string('medical_category', 50)->nullable()->comment('救助类别/医疗类别');
            $table->string('period_clearing', 10)->nullable()->comment('医保局清算期');
            $table->string('period_belong', 10)->comment('费款所属期(YYYYMM)');
            $table->integer('year')->nullable()->comment('所属年度');
            $table->integer('month')->nullable()->comment('所属月份');
            $table->string('visit_address', 100)->nullable()->comment('就诊地');
            $table->string('hospital_name', 100)->nullable()->comment('就诊医疗机构名称');
            $table->string('disease_name', 100)->nullable()->comment('病种名称');
            $table->date('admission_date')->nullable()->comment('入院日期');
            $table->date('discharge_date')->nullable()->comment('出院日期');
            $table->date('settlement_date')->nullable()->comment('结算日期');
            $table->decimal('total_amount', 12, 2)->default(0.00)->comment('医疗费总额');
            $table->decimal('eligible_amount', 12, 2)->default(0.00)->comment('符合医保范围金额');
            $table->decimal('fund_pay', 12, 2)->default(0.00)->comment('基本医疗基金支出');
            $table->decimal('serious_illness_pay', 12, 2)->default(0.00)->comment('大病补充医疗保险支出');
            $table->decimal('large_amount_pay', 12, 2)->default(0.00)->comment('大额补充医疗保险支出');
            $table->decimal('enter_medical_assistance', 12, 2)->default(0.00)->comment('进入医疗救助金额');
            $table->decimal('medical_assistance', 12, 2)->default(0.00)->comment('医疗救助金额');
            $table->decimal('slant_assistance', 12, 2)->default(0.00)->comment('倾斜救助金额');
            $table->decimal('poverty_assistance', 12, 2)->default(0.00)->comment('扶贫济困金额');
            $table->decimal('yukaibao_pay', 12, 2)->default(0.00)->comment('渝快保支出金额');
            $table->decimal('personal_account_pay', 12, 2)->default(0.00)->comment('个人账户支付金额');
            $table->decimal('personal_cash_pay', 12, 2)->default(0.00)->comment('个人现金支付金额');

            // 自动计算字段
            $table->decimal('ins_assist_total', 12, 2)->default(0.00)->comment('医保报销和医疗救助金额');
            $table->decimal('yf_eligible_amount', 12, 2)->default(0.00)->comment('符合优抚住院医疗补助计算金额');
            $table->decimal('annual_quota', 12, 2)->default(0.00)->comment('年度补助金额');
            $table->decimal('used_amount', 12, 2)->default(0.00)->comment('已使用金额');
            $table->decimal('current_subsidy', 12, 2)->default(0.00)->comment('本次补助金额');
            $table->decimal('remaining_amount', 12, 2)->default(0.00)->comment('剩余金额');

            // 业务管理字段
            $table->tinyint('pay_status')->default(0)->comment('支付状态: -1不需支付, 0待支付, 1已支付');
            $table->timestamp('pay_at')->nullable()->comment('支付时间');
            $table->string('remark', 255)->nullable()->comment('备注');

            $table->timestamps();

            // 索引优化
            $table->index(['id_card', 'period_belong'], 'idx_id_card_period');
            $table->index(['year', 'month'], 'idx_year_month');
            $table->index('pay_status', 'idx_pay_status');
            $table->index('created_at', 'idx_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('yf_settlements');
    }
}
