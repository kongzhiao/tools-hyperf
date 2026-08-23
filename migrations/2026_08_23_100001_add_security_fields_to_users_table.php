<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddSecurityFieldsToUsersTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'totp_required')) {
                $table->boolean('totp_required')->default(false)->after('password')->comment('是否强制启用TOTP');
            }
            if (!Schema::hasColumn('users', 'totp_secret')) {
                $table->string('totp_secret', 512)->nullable()->after('totp_required')->comment('加密后的TOTP密钥');
            }
            if (!Schema::hasColumn('users', 'totp_bound_at')) {
                $table->timestamp('totp_bound_at')->nullable()->after('totp_secret')->comment('TOTP绑定时间');
            }
            if (!Schema::hasColumn('users', 'totp_reset_at')) {
                $table->timestamp('totp_reset_at')->nullable()->after('totp_bound_at')->comment('TOTP重置时间');
            }
            if (!Schema::hasColumn('users', 'session_version')) {
                $table->unsignedInteger('session_version')->default(1)->after('totp_reset_at')->comment('会话失效版本');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['totp_required', 'totp_secret', 'totp_bound_at', 'totp_reset_at', 'session_version'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
