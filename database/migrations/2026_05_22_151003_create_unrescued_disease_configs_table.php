<?php

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class CreateUnrescuedDiseaseConfigsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('unrescued_disease_configs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('disease_code', 50)->default('')->comment('病种编码');
            $table->string('disease_name', 255)->nullable()->comment('病种名称');
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique('disease_code', 'idx_disease_code');
        });
        
        \Hyperf\Database\Schema\Schema::getConnection()->statement("ALTER TABLE `unrescued_disease_configs` comment '未救助台账-救助重大疾病配置表'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unrescued_disease_configs');
    }
}
