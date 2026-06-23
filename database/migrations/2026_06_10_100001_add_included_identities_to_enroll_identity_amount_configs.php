<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddIncludedIdentitiesToEnrollIdentityAmountConfigs extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('enroll_identity_amount_configs')) {
            return;
        }

        Schema::table('enroll_identity_amount_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('enroll_identity_amount_configs', 'included_identities')) {
                $table->json('included_identities')->nullable()->after('special_identity')->comment('包含参保身份，JSON数组');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('enroll_identity_amount_configs')) {
            return;
        }

        Schema::table('enroll_identity_amount_configs', function (Blueprint $table) {
            if (Schema::hasColumn('enroll_identity_amount_configs', 'included_identities')) {
                $table->dropColumn('included_identities');
            }
        });
    }
}
