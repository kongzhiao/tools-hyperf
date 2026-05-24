<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddTownIdToUsersTable extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('town_id')->nullable()->after('nickname')->comment('所属镇街ID,为空表示全局账号');
            $table->index('town_id', 'idx_users_town_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_town_id');
            $table->dropColumn('town_id');
        });
    }
}
