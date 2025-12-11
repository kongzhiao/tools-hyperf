<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class CreateInsuranceDataTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('insurance_data', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 50)->nullable()->comment('序号');
            $table->string('street_town', 100)->comment('街道乡镇');
            $table->string('name', 50)->comment('姓名');
            $table->string('id_type', 50)->comment('身份证件类型');
            $table->string('id_number', 50)->comment('身份证件号码');
            $table->string('person_number', 100)->nullable()->comment('人员编号');
            $table->string('payment_category', 100)->comment('代缴类别');
            $table->decimal('payment_amount', 10, 2)->comment('代缴金额');
            $table->date('payment_date')->nullable()->comment('个人缴费日期');
            
            // 新增字段
            $table->string('level', 50)->nullable()->comment('档次');
            $table->decimal('personal_amount', 10, 2)->default(0)->comment('个人实缴金额');
            $table->string('medical_assistance_category', 100)->nullable()->comment('医疗救助类别');
            $table->string('category_match', 100)->nullable()->comment('类别匹配');
            $table->text('remark')->nullable()->comment('备注');
            
            $table->timestamps();
            
            // 索引
            $table->index('serial_number');
            $table->index('street_town');
            $table->index('name');
            $table->index('id_number');
            $table->index('payment_category');
            $table->index('payment_date');
            $table->index('level');
            $table->index('medical_assistance_category');
            
            // 唯一约束（身份证件号码应该是唯一的）
            $table->unique('id_number', 'uk_id_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurance_data');
    }
} 