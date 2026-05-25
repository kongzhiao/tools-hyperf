<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class PatchUnrescuedStageFourToSevenFields extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unrescued_records')) {
            if (Schema::hasColumn('unrescued_records', 'status')) {
                Db::statement("ALTER TABLE `unrescued_records` MODIFY `status` varchar(50) NOT NULL DEFAULT '待处理' COMMENT '待处理、无救助金额、不通知、拟通知、已下放、已接收、已通知'");
            }

            Schema::table('unrescued_records', function (Blueprint $table) {
                $this->addColumnIfMissing($table, 'source_batch', fn () => $table->string('source_batch', 50)->default('')->comment('附件1导入批次'));
                $this->addColumnIfMissing($table, 'medical_category', fn () => $table->string('medical_category', 100)->nullable()->comment('医疗类别'));
                $this->addColumnIfMissing($table, 'cert_location', fn () => $table->string('cert_location', 100)->nullable()->comment('认定地'));
                $this->addColumnIfMissing($table, 'hospital_code', fn () => $table->string('hospital_code', 100)->nullable()->comment('医药机构编码'));
                $this->addColumnIfMissing($table, 'in_out_city', fn () => $table->string('in_out_city', 50)->nullable()->comment('市内/市外'));
                $this->addColumnIfMissing($table, 'settlement_time', fn () => $table->dateTime('settlement_time')->nullable()->comment('结算时间'));
                $this->addColumnIfMissing($table, 'total_fee', fn () => $table->decimal('total_fee', 14, 2)->default(0)->comment('医疗总费用'));
                $this->addColumnIfMissing($table, 'policy_fee', fn () => $table->decimal('policy_fee', 14, 2)->default(0)->comment('医保政策范围费用'));
                $this->addColumnIfMissing($table, 'pool_fund_pay', fn () => $table->decimal('pool_fund_pay', 14, 2)->default(0)->comment('统筹报销金额'));
                $this->addColumnIfMissing($table, 'large_amount_pay', fn () => $table->decimal('large_amount_pay', 14, 2)->default(0)->comment('大额报销'));
                $this->addColumnIfMissing($table, 'serious_illness_pay', fn () => $table->decimal('serious_illness_pay', 14, 2)->default(0)->comment('大病报销'));
                $this->addColumnIfMissing($table, 'used_outpatient_rescue', fn () => $table->decimal('used_outpatient_rescue', 14, 2)->default(0)->comment('已使用门诊救助金额'));
                $this->addColumnIfMissing($table, 'used_normal_rescue', fn () => $table->decimal('used_normal_rescue', 14, 2)->default(0)->comment('已使用普通住院救助金额'));
                $this->addColumnIfMissing($table, 'used_major_rescue', fn () => $table->decimal('used_major_rescue', 14, 2)->default(0)->comment('已使用重特大疾病救助金额'));
                $this->addColumnIfMissing($table, 'used_large_fee_rescue', fn () => $table->decimal('used_large_fee_rescue', 14, 2)->default(0)->comment('已使用大额费用住院救助'));
                $this->addColumnIfMissing($table, 'calc_reimbursement_amount', fn () => $table->decimal('calc_reimbursement_amount', 14, 2)->default(0)->comment('进入报销金额'));
                $this->addColumnIfMissing($table, 'street_town', fn () => $table->string('street_town', 100)->nullable()->comment('镇街名称，来源附件2'));
                $this->addColumnIfMissing($table, 'village', fn () => $table->string('village', 100)->nullable()->comment('村社'));
                $this->addColumnIfMissing($table, 'priority_identity', fn () => $table->string('priority_identity', 100)->nullable()->comment('优先身份/对象类别'));
                $this->addColumnIfMissing($table, 'reimbursement_status', fn () => $table->string('reimbursement_status', 20)->default('未报销')->comment('未报销、已报销'));
                $this->addColumnIfMissing($table, 'exclude_status', fn () => $table->string('exclude_status', 20)->default('未剔除')->comment('未剔除、已剔除'));
                $this->addColumnIfMissing($table, 'exclude_rule_code', fn () => $table->string('exclude_rule_code', 100)->nullable()->comment('命中的清洗规则编码'));
                $this->addColumnIfMissing($table, 'bank_account_name', fn () => $table->string('bank_account_name', 100)->nullable()->comment('户名'));
                $this->addColumnIfMissing($table, 'bank_account_no', fn () => $table->string('bank_account_no', 100)->nullable()->comment('银行账号'));
            });
        }

        if (Schema::hasTable('unrescued_wash_configs')) {
            Schema::table('unrescued_wash_configs', function (Blueprint $table) {
                $this->addColumnIfMissing($table, 'version', fn () => $table->string('version', 50)->default('')->comment('规则版本号'));
                $this->addColumnIfMissing($table, 'name', fn () => $table->string('name', 100)->default('')->comment('配置名称'));
                $this->addColumnIfMissing($table, 'rule_name', fn () => $table->string('rule_name', 100)->default('')->comment('配置名称/兼容旧字段'));
                $this->addColumnIfMissing($table, 'data', fn () => $table->json('data')->nullable()->comment('清洗规则JSON'));
                $this->addColumnIfMissing($table, 'is_active', fn () => $table->tinyInteger('is_active')->default(1)->comment('是否启用'));
                $this->addColumnIfMissing($table, 'created_by', fn () => $table->unsignedBigInteger('created_by')->default(0)->comment('创建用户ID'));
            });
        }

        if (Schema::hasTable('unrescued_wash_logs')) {
            Schema::table('unrescued_wash_logs', function (Blueprint $table) {
                $this->addColumnIfMissing($table, 'settlement_period', fn () => $table->string('settlement_period', 20)->default('')->comment('清算期'));
                $this->addColumnIfMissing($table, 'config_id', fn () => $table->unsignedBigInteger('config_id')->default(0)->comment('使用的清洗配置ID'));
                $this->addColumnIfMissing($table, 'batch_no', fn () => $table->string('batch_no', 50)->default('')->comment('清洗批次'));
                $this->addColumnIfMissing($table, 'total_count', fn () => $table->integer('total_count')->default(0)->comment('参与清洗数量'));
                $this->addColumnIfMissing($table, 'excluded_count', fn () => $table->integer('excluded_count')->default(0)->comment('剔除数量'));
                $this->addColumnIfMissing($table, 'kept_count', fn () => $table->integer('kept_count')->default(0)->comment('保留数量'));
                $this->addColumnIfMissing($table, 'summary', fn () => $table->json('summary')->nullable()->comment('按规则统计的命中结果'));
                $this->addColumnIfMissing($table, 'created_by', fn () => $table->unsignedBigInteger('created_by')->default(0)->comment('操作用户ID'));
            });
        }

        if (Schema::hasTable('unrescued_supplement_records')) {
            if (Schema::hasColumn('unrescued_supplement_records', 'status')) {
                Db::statement("ALTER TABLE `unrescued_supplement_records` MODIFY `status` varchar(50) NOT NULL DEFAULT '待处理' COMMENT '待处理、纳入排查、不纳入排查'");
            }

            Schema::table('unrescued_supplement_records', function (Blueprint $table) {
                $this->addColumnIfMissing($table, 'source_batch', fn () => $table->string('source_batch', 50)->default('')->comment('数据来源批次'));
                $this->addColumnIfMissing($table, 'priority_identity', fn () => $table->string('priority_identity', 100)->nullable()->comment('对象类别/优先身份'));
                $this->addColumnIfMissing($table, 'street_town', fn () => $table->string('street_town', 100)->nullable()->comment('镇街'));
                $this->addColumnIfMissing($table, 'insurance_place', fn () => $table->string('insurance_place', 100)->nullable()->comment('参保地'));
                $this->addColumnIfMissing($table, 'insurance_category', fn () => $table->string('insurance_category', 100)->nullable()->comment('参加险种'));
                $this->addColumnIfMissing($table, 'hospital_name', fn () => $table->string('hospital_name', 255)->nullable()->comment('就诊医疗机构名称'));
                $this->addColumnIfMissing($table, 'medical_visit_category', fn () => $table->string('medical_visit_category', 100)->nullable()->comment('医保就诊类别'));
                $this->addColumnIfMissing($table, 'disease_code', fn () => $table->string('disease_code', 50)->nullable()->comment('疾病编码'));
                $this->addColumnIfMissing($table, 'disease_name', fn () => $table->string('disease_name', 255)->nullable()->comment('疾病名称'));
                $this->addColumnIfMissing($table, 'admission_date', fn () => $table->date('admission_date')->nullable()->comment('入院时间'));
                $this->addColumnIfMissing($table, 'discharge_date', fn () => $table->date('discharge_date')->nullable()->comment('出院时间'));
                $this->addColumnIfMissing($table, 'settlement_time', fn () => $table->dateTime('settlement_time')->nullable()->comment('结算时间'));
                $this->addColumnIfMissing($table, 'total_fee', fn () => $table->decimal('total_fee', 14, 2)->default(0)->comment('总费用'));
                $this->addColumnIfMissing($table, 'policy_fee', fn () => $table->decimal('policy_fee', 14, 2)->default(0)->comment('医保政策范围内费用'));
                $this->addColumnIfMissing($table, 'pool_fund_pay', fn () => $table->decimal('pool_fund_pay', 14, 2)->default(0)->comment('统筹报销金额'));
                $this->addColumnIfMissing($table, 'large_amount_pay', fn () => $table->decimal('large_amount_pay', 14, 2)->default(0)->comment('大额报销金额'));
                $this->addColumnIfMissing($table, 'serious_illness_pay', fn () => $table->decimal('serious_illness_pay', 14, 2)->default(0)->comment('大病报销金额'));
                $this->addColumnIfMissing($table, 'medical_assistance_pay', fn () => $table->decimal('medical_assistance_pay', 14, 2)->default(0)->comment('医疗救助金额'));
                $this->addColumnIfMissing($table, 'yukuaibao_pay', fn () => $table->decimal('yukuaibao_pay', 14, 2)->default(0)->comment('渝快保报销金额'));
                $this->addColumnIfMissing($table, 'personal_account_pay', fn () => $table->decimal('personal_account_pay', 14, 2)->default(0)->comment('个人账户支付金额'));
                $this->addColumnIfMissing($table, 'personal_cash_pay', fn () => $table->decimal('personal_cash_pay', 14, 2)->default(0)->comment('个人现金支付金额'));
                $this->addColumnIfMissing($table, 'calc_medical_assistance_amount', fn () => $table->decimal('calc_medical_assistance_amount', 14, 2)->default(0)->comment('进入医疗救助金额'));
            });
        }
    }

    public function down(): void
    {
    }

    private function addColumnIfMissing(Blueprint $table, string $column, callable $callback): void
    {
        if (!Schema::hasColumn($table->getTable(), $column)) {
            $callback();
        }
    }
}
