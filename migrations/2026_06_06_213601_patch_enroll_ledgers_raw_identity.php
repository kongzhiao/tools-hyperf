<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class PatchEnrollLedgersRawIdentity extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('enroll_ledgers')) {
            return;
        }

        Schema::table('enroll_ledgers', function (Blueprint $table) {
            if (!Schema::hasColumn('enroll_ledgers', 'raw_identity')) {
                $table->string('raw_identity', 255)->nullable()->after('payment_time')->comment('附件3原始医疗救助身份');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('enroll_ledgers')) {
            return;
        }

        Schema::table('enroll_ledgers', function (Blueprint $table) {
            if (Schema::hasColumn('enroll_ledgers', 'raw_identity')) {
                $table->dropColumn('raw_identity');
            }
        });
    }
}
