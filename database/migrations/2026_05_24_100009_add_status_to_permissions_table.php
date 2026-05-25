<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class AddStatusToPermissionsTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        if (!Schema::hasColumn('permissions', 'status')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->tinyInteger('status')->default(1)->comment('状态:1启用,0停用')->after('sort');
            });
        }

        if (!$this->hasIndex('permissions', 'idx_permissions_status')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->index('status', 'idx_permissions_status');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            if ($this->hasIndex('permissions', 'idx_permissions_status')) {
                $table->dropIndex('idx_permissions_status');
            }

            if (Schema::hasColumn('permissions', 'status')) {
                $table->dropColumn('status');
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $result = Db::select(
            'SELECT COUNT(1) AS count FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        );

        return (int) ($result[0]->count ?? 0) > 0;
    }
}
