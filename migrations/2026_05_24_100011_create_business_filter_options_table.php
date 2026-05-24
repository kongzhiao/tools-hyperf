<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateBusinessFilterOptionsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_filter_options')) {
            return;
        }

        Schema::create('business_filter_options', function (Blueprint $table) {
            $table->id();
            $table->string('module', 100)->default('')->comment('业务模块，如unrescued');
            $table->string('type', 100)->default('')->comment('选项类型，如medical_category、priority_identity');
            $table->string('value', 255)->default('')->comment('选项值');
            $table->string('label', 255)->default('')->comment('显示名称');
            $table->tinyInteger('status')->default(1)->comment('状态：1启用，0禁用');
            $table->integer('sort')->default(0)->comment('排序');
            $table->string('source_batch', 50)->nullable()->comment('来源批次');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['module', 'type', 'value'], 'idx_module_type_value');
            $table->index(['module', 'type', 'status'], 'idx_module_type_status');
            $table->comment('业务筛选表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_filter_options');
    }
}
