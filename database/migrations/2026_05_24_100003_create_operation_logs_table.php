<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateOperationLogsTable extends Migration
{
    public function up(): void
    {
        Schema::create('operation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('操作人ID');
            $table->string('username', 64)->nullable()->comment('操作人账号');
            $table->string('module', 64)->comment('业务模块');
            $table->string('action', 64)->comment('操作类型');
            $table->string('target_type', 64)->nullable()->comment('对象类型');
            $table->string('target_id', 64)->nullable()->comment('对象ID');
            $table->string('description', 255)->nullable()->comment('操作说明');
            $table->json('params')->nullable()->comment('操作参数');
            $table->string('ip', 64)->nullable()->comment('IP地址');
            $table->string('user_agent', 255)->nullable()->comment('客户端信息');
            $table->string('status', 20)->default('success')->comment('状态:success,failed');
            $table->text('error_message')->nullable()->comment('错误信息');
            $table->timestamps();

            $table->index(['module', 'action'], 'idx_operation_logs_module_action');
            $table->index('user_id', 'idx_operation_logs_user_id');
            $table->index('created_at', 'idx_operation_logs_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_logs');
    }
}
