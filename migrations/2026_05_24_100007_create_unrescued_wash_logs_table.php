<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateUnrescuedWashLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('unrescued_wash_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('record_id')->comment('未救助明细ID');
            $table->unsignedBigInteger('rule_id')->nullable()->comment('清洗规则ID');
            $table->string('rule_name', 128)->nullable()->comment('规则名称');
            $table->string('wash_reason', 255)->comment('剔除原因');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人ID');
            $table->string('operator_name', 64)->nullable()->comment('操作人');
            $table->timestamps();

            $table->index('record_id', 'idx_unrescued_wash_logs_record_id');
            $table->index('rule_id', 'idx_unrescued_wash_logs_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unrescued_wash_logs');
    }
}
