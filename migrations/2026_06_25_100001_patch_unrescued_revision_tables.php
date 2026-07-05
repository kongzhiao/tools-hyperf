<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class PatchUnrescuedRevisionTables extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unrescued_records')) {
            Schema::table('unrescued_records', function (Blueprint $table) {
                if (!Schema::hasColumn('unrescued_records', 'match_status')) {
                    $table->string('match_status', 20)->default('未匹配')->comment('匹配状态：未匹配、已匹配')->after('priority_identity');
                    $table->index(['settlement_period', 'match_status'], 'idx_period_match_status');
                }
            });
        }

        if (!Schema::hasTable('unrescued_refund_records')) {
            Schema::create('unrescued_refund_records', function (Blueprint $table) {
                $table->id();
                $table->string('settlement_period', 20)->default('')->comment('清算期/月度');
                $table->string('sequence_no', 50)->default('')->comment('附件4序号');
                $table->string('source_batch', 50)->default('')->comment('导入批次');
                $table->string('name', 50)->nullable()->comment('姓名');
                $table->string('id_card', 32)->nullable()->comment('身份证号');
                $table->string('priority_identity', 100)->nullable()->comment('对象类别/优先身份');
                $table->unsignedBigInteger('town_id')->default(0)->comment('镇街ID');
                $table->string('street_town', 100)->nullable()->comment('镇街');
                $table->string('village', 100)->nullable()->comment('村社');
                $table->string('match_status', 20)->default('未匹配')->comment('匹配状态：未匹配、已匹配');
                $table->string('insurance_place', 100)->nullable()->comment('参保地');
                $table->string('insurance_category', 100)->nullable()->comment('参加险种');
                $table->string('hospital_name', 255)->nullable()->comment('就诊医疗机构名称');
                $table->string('medical_category', 100)->nullable()->comment('医保就诊类别');
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
                $table->decimal('calc_reimbursement_amount', 14, 2)->default(0)->comment('进入报销金额');
                $table->string('status', 50)->default('待处理')->comment('待处理、无救助金额、拟通知1、拟通知2');
                $table->string('exclude_status', 20)->default('未剔除')->comment('未剔除、已剔除');
                $table->string('exclude_rule_code', 100)->nullable()->comment('命中的清洗规则编码');
                $table->string('remark', 255)->nullable()->comment('备注');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['settlement_period', 'sequence_no'], 'idx_period_sequence');
                $table->index(['settlement_period', 'id_card'], 'idx_period_id_card');
                $table->index(['settlement_period', 'town_id'], 'idx_period_town');
                $table->index(['settlement_period', 'status'], 'idx_period_status');
                $table->index(['settlement_period', 'match_status'], 'idx_period_match_status');
                $table->index(['settlement_period', 'exclude_status'], 'idx_period_exclude');
                $table->index('disease_code', 'idx_disease_code');
                $table->comment('未救助台账-应补应退明细表');
            });
        }

        if (!Schema::hasTable('unrescued_notice_records')) {
            Schema::create('unrescued_notice_records', function (Blueprint $table) {
                $table->id();
                $table->string('settlement_period', 20)->default('')->comment('清算期/月度');
                $table->string('sequence_no', 50)->default('')->comment('导入序号');
                $table->string('source_batch', 50)->default('')->comment('导入批次');
                $table->string('name', 50)->nullable()->comment('姓名');
                $table->string('id_card', 32)->nullable()->comment('身份证号');
                $table->string('priority_identity', 100)->nullable()->comment('对象类别/优先身份');
                $table->unsignedBigInteger('town_id')->default(0)->comment('镇街ID');
                $table->string('street_town', 100)->nullable()->comment('镇街');
                $table->string('insurance_place', 100)->nullable()->comment('参保地');
                $table->string('insurance_category', 100)->nullable()->comment('参加险种');
                $table->string('hospital_name', 255)->nullable()->comment('就诊医疗机构名称');
                $table->string('medical_category', 100)->nullable()->comment('医保就诊类别');
                $table->string('disease_code', 50)->nullable()->comment('疾病编码');
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
                $table->decimal('calc_reimbursement_amount', 14, 2)->default(0)->comment('进入报销金额');
                $table->string('status', 50)->default('待下放')->comment('待下放、已下放');
                $table->string('reimbursement_status', 20)->default('未报销')->comment('未报销、已报销');
                $table->dateTime('distributed_at')->nullable()->comment('下放时间');
                $table->dateTime('reimbursed_at')->nullable()->comment('标记已报销时间');
                $table->string('system_remark', 255)->nullable()->comment('系统备注');
                $table->string('contact_name', 100)->nullable()->comment('联系人');
                $table->string('contact_phone', 100)->nullable()->comment('联系方式');
                $table->string('town_remark', 255)->nullable()->comment('镇街备注');
                $table->string('admin_remark', 255)->nullable()->comment('管理员备注');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['settlement_period', 'sequence_no'], 'idx_period_sequence');
                $table->index(['settlement_period', 'town_id', 'status'], 'idx_period_town_status');
                $table->index(['settlement_period', 'id_card'], 'idx_period_id_card');
                $table->index(['settlement_period', 'reimbursement_status'], 'idx_period_reimbursement');
                $table->comment('未救助台账-下放通知明细表');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('unrescued_notice_records');
        Schema::dropIfExists('unrescued_refund_records');
        if (Schema::hasTable('unrescued_records') && Schema::hasColumn('unrescued_records', 'match_status')) {
            Schema::table('unrescued_records', function (Blueprint $table) {
                $table->dropIndex('idx_period_match_status');
                $table->dropColumn('match_status');
            });
        }
    }
}
