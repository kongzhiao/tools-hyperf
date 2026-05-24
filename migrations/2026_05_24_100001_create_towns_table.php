<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateTownsTable extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('towns')) {
            return;
        }

        Schema::create('towns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->comment('镇街名称');
            $table->string('code', 32)->nullable()->comment('镇街编码');
            $table->tinyInteger('status')->default(1)->comment('状态:1启用,0停用');
            $table->integer('sort')->default(0)->comment('排序');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index('name', 'idx_towns_name');
            $table->index('status', 'idx_towns_status');
            $table->comment('镇街表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('towns');
    }
}
