<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class PatchUnrescuedNoticeRecordFields extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('unrescued_notice_records')) {
            return;
        }

        Schema::table('unrescued_notice_records', function (Blueprint $table) {
            if (!Schema::hasColumn('unrescued_notice_records', 'disease_name')) {
                $table->string('disease_name', 255)->nullable()->comment('疾病名称')->after('disease_code');
            }
            if (!Schema::hasColumn('unrescued_notice_records', 'system_remark')) {
                $table->string('system_remark', 255)->nullable()->comment('系统备注')->after('reimbursed_at');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('unrescued_notice_records')) {
            return;
        }

        Schema::table('unrescued_notice_records', function (Blueprint $table) {
            if (Schema::hasColumn('unrescued_notice_records', 'system_remark')) {
                $table->dropColumn('system_remark');
            }
            if (Schema::hasColumn('unrescued_notice_records', 'disease_name')) {
                $table->dropColumn('disease_name');
            }
        });
    }
}
