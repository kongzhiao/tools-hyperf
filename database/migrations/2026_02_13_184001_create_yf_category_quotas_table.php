<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class CreateYfCategoryQuotasTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('yf_category_quotas', function (Blueprint $table) {
            $table->id();
            $table->integer('year')->comment('年度');
            $table->string('category', 100)->comment('优抚类别');
            $table->decimal('quota_amount', 12, 2)->default(0.00)->comment('优抚住院医疗补助金额（人/元/年）');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();

            $table->index(['year', 'category'], 'idx_year_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('yf_category_quotas');
    }
}
