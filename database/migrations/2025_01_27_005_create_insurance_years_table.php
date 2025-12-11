<?php

declare(strict_types=1);

use Hyperf\Database\Schema\Schema;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Migrations\Migration;

class CreateInsuranceYearsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('insurance_years', function (Blueprint $table) {
            $table->id();
            $table->integer('year')->unique()->comment('年份');
            $table->string('description')->nullable()->comment('年份描述');
            $table->boolean('is_active')->default(true)->comment('是否激活');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('insurance_years');
    }
} 