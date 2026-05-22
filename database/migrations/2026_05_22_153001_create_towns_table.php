<?php

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class CreateTownsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('towns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 100)->default('')->comment('镇街名称');
            $table->tinyInteger('status')->default(1)->comment('状态: 1正常, 0禁用');
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique('name', 'idx_town_name');
        });
        
        \Hyperf\Database\Schema\Schema::getConnection()->statement("ALTER TABLE `towns` comment '系统组织架构-镇街管理表'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('towns');
    }
}
