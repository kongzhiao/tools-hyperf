<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateUnrescuedSupplementRecordsTable extends Migration
{
    public function up(): void
    {
        Schema::create('unrescued_supplement_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('record_id')->comment('未救助明细ID');
            $table->string('settlement_period', 20)->comment('结算年月/导入月份');
            $table->string('name', 64)->nullable()->comment('姓名');
            $table->string('id_card', 32)->nullable()->comment('身份证号');
            $table->unsignedBigInteger('town_id')->nullable()->comment('镇街ID');
            $table->string('town_name', 64)->nullable()->comment('镇街名称');
            $table->decimal('should_amount', 14, 2)->default(0)->comment('应补/应退金额');
            $table->decimal('actual_amount', 14, 2)->default(0)->comment('实际处理金额');
            $table->tinyInteger('type')->default(1)->comment('类型:1应补,2应退');
            $table->tinyInteger('status')->default(0)->comment('状态:0待处理,1已处理');
            $table->timestamp('handled_at')->nullable()->comment('处理时间');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index('record_id', 'idx_unrescued_supplement_record_id');
            $table->index(['settlement_period', 'town_id'], 'idx_unrescued_supplement_period_town');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unrescued_supplement_records');
    }
}
