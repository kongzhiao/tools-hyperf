<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddStatusToPermissionsTable extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->tinyInteger('status')->default(1)->comment('状态:1启用,0停用')->after('sort');
            $table->index('status', 'idx_permissions_status');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex('idx_permissions_status');
            $table->dropColumn('status');
        });
    }
}
