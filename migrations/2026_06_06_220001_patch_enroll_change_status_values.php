<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class PatchEnrollChangeStatusValues extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('enroll_ledgers')) {
            return;
        }

        Db::table('enroll_ledgers')
            ->where('change_status', '正常')
            ->update(['change_status' => '新增']);
    }

    public function down(): void
    {
    }
}
