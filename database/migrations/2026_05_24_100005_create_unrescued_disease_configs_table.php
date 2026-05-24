<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateUnrescuedDiseaseConfigsTable extends Migration
{
    public function up(): void
    {
        Schema::create('unrescued_disease_configs', function (Blueprint $table) {
            $table->id();
            $table->string('disease_code', 64)->comment('病种编码');
            $table->string('disease_name', 128)->comment('病种名称');
            $table->tinyInteger('status')->default(1)->comment('状态:1启用,0停用');
            $table->string('source_batch', 64)->nullable()->comment('来源批次');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index('disease_code', 'idx_unrescued_disease_code');
            $table->index('status', 'idx_unrescued_disease_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unrescued_disease_configs');
    }
}
