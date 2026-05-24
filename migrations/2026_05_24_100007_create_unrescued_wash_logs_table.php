<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateUnrescuedWashLogsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unrescued_wash_logs')) {
            return;
        }
        
        Schema::create('unrescued_wash_logs', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_period', 20)->default('')->comment('清算期');
            $table->unsignedBigInteger('config_id')->default(0)->comment('使用的清洗配置ID');
            $table->string('batch_no', 50)->default('')->comment('清洗批次');
            $table->integer('total_count')->default(0)->comment('参与清洗数量');
            $table->integer('excluded_count')->default(0)->comment('剔除数量');
            $table->integer('kept_count')->default(0)->comment('保留数量');
            $table->json('summary')->nullable()->comment('按规则统计的命中结果');
            $table->unsignedBigInteger('created_by')->default(0)->comment('操作用户ID');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['settlement_period', 'batch_no'], 'idx_period_batch');
            $table->index('config_id', 'idx_config_id');
            $table->comment('未救助清洗执行日志表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unrescued_wash_logs');
    }
}
