<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddFailureReasonToTaskTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('task')) {
            return;
        }

        Schema::table('task', function (Blueprint $table) {
            if (!Schema::hasColumn('task', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('status')->comment('失败原因');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('task')) {
            return;
        }

        Schema::table('task', function (Blueprint $table) {
            if (Schema::hasColumn('task', 'failure_reason')) {
                $table->dropColumn('failure_reason');
            }
        });
    }
}
