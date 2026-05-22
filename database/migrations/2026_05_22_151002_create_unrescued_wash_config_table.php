<?php

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class CreateUnrescuedWashConfigTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('unrescued_wash_config', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->json('data')->nullable()->comment('清洗规则JSON数据');
            $table->timestamps();
            $table->softDeletes();
        });
        
        \Hyperf\Database\Schema\Schema::getConnection()->statement("ALTER TABLE `unrescued_wash_config` comment '未救助台账-清洗规则表'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unrescued_wash_config');
    }
}
