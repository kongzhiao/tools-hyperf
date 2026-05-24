<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateUnrescuedWashConfigsTable extends Migration
{
    public function up(): void
    {
        Schema::create('unrescued_wash_configs', function (Blueprint $table) {
            $table->id();
            $table->string('rule_name', 128)->comment('规则名称');
            $table->string('rule_type', 64)->comment('规则类型');
            $table->json('conditions')->nullable()->comment('规则条件');
            $table->tinyInteger('status')->default(1)->comment('状态:1启用,0停用');
            $table->integer('sort')->default(0)->comment('排序');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['rule_type', 'status'], 'idx_unrescued_wash_type_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unrescued_wash_configs');
    }
}
