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
            $table->string('version', 50)->default('')->comment('规则版本号');
            $table->string('name', 100)->default('')->comment('配置名称');
            $table->json('data')->nullable()->comment('清洗规则JSON');
            $table->tinyInteger('is_active')->default(1)->comment('是否启用');
            $table->unsignedBigInteger('created_by')->default(0)->comment('创建用户ID');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'created_at'], 'idx_active_created');
            $table->index('version', 'idx_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unrescued_wash_configs');
    }
}
