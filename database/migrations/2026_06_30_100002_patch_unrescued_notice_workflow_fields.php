<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class PatchUnrescuedNoticeWorkflowFields extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('unrescued_notice_records')) {
            return;
        }

        Schema::table('unrescued_notice_records', function (Blueprint $table) {
            if (!Schema::hasColumn('unrescued_notice_records', 'received_at')) {
                $table->dateTime('received_at')->nullable()->comment('镇街接收时间')->after('distributed_at');
            }
            if (!Schema::hasColumn('unrescued_notice_records', 'notified_at')) {
                $table->dateTime('notified_at')->nullable()->comment('镇街通知时间')->after('received_at');
            }
            if (!Schema::hasColumn('unrescued_notice_records', 'bank_name')) {
                $table->string('bank_name', 100)->nullable()->comment('开户行')->after('contact_phone');
            }
            if (!Schema::hasColumn('unrescued_notice_records', 'bank_account_name')) {
                $table->string('bank_account_name', 100)->nullable()->comment('户名')->after('bank_name');
            }
            if (!Schema::hasColumn('unrescued_notice_records', 'bank_account_no')) {
                $table->string('bank_account_no', 100)->nullable()->comment('银行账号')->after('bank_account_name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('unrescued_notice_records')) {
            return;
        }

        Schema::table('unrescued_notice_records', function (Blueprint $table) {
            foreach (['bank_account_no', 'bank_account_name', 'bank_name', 'notified_at', 'received_at'] as $column) {
                if (Schema::hasColumn('unrescued_notice_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
