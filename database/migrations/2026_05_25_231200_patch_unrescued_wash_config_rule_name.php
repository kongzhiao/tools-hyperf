<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class PatchUnrescuedWashConfigRuleName extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('unrescued_wash_configs')) {
            return;
        }

        Schema::table('unrescued_wash_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('unrescued_wash_configs', 'rule_name')) {
                $table->string('rule_name', 100)->default('')->comment('配置名称/兼容旧字段');
            }
        });

        Db::statement("ALTER TABLE `unrescued_wash_configs` MODIFY `rule_name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '配置名称/兼容旧字段'");

        if (Schema::hasColumn('unrescued_wash_configs', 'name')) {
            Db::statement("UPDATE `unrescued_wash_configs` SET `rule_name` = `name` WHERE (`rule_name` IS NULL OR `rule_name` = '') AND `name` IS NOT NULL AND `name` != ''");
            Db::statement("UPDATE `unrescued_wash_configs` SET `name` = `rule_name` WHERE (`name` IS NULL OR `name` = '') AND `rule_name` IS NOT NULL AND `rule_name` != ''");
        }
    }

    public function down(): void
    {
    }
}
