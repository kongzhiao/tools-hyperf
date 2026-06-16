<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class PatchEnrollSnapshotsPaymentTime extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('enroll_ledger_snapshots')) {
            return;
        }

        Schema::table('enroll_ledger_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('enroll_ledger_snapshots', 'included_month')) {
                $table->string('included_month', 20)->nullable()->after('village_name')->comment('纳入资助时间');
            }
            if (!Schema::hasColumn('enroll_ledger_snapshots', 'payment_time')) {
                $table->string('payment_time', 30)->nullable()->after('included_month')->comment('缴费时间');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('enroll_ledger_snapshots')) {
            return;
        }

        Schema::table('enroll_ledger_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('enroll_ledger_snapshots', 'payment_time')) {
                $table->dropColumn('payment_time');
            }
            if (Schema::hasColumn('enroll_ledger_snapshots', 'included_month')) {
                $table->dropColumn('included_month');
            }
        });
    }
}
