<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class AddInsuranceDataIndexes extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('insurance_data', function (Blueprint $table) {
            // 添加year字段索引
            $table->index('year', 'idx_year');
            
            // 添加复合索引，优化汇总查询性能
            $table->index(['year', 'street_town', 'payment_category', 'level'], 'idx_summary_query');
            
            // 添加其他常用查询的复合索引
            $table->index(['year', 'street_town'], 'idx_year_street');
            $table->index(['year', 'payment_category'], 'idx_year_category');
            $table->index(['year', 'level'], 'idx_year_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('insurance_data', function (Blueprint $table) {
            $table->dropIndex('idx_year');
            $table->dropIndex('idx_summary_query');
            $table->dropIndex('idx_year_street');
            $table->dropIndex('idx_year_category');
            $table->dropIndex('idx_year_level');
        });
    }
}
