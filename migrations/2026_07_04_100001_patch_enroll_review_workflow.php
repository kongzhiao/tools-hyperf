<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

class PatchEnrollReviewWorkflow extends Migration
{
    public function up(): void
    {
        $this->patchLedgers();
        $this->createReviewBatches();
        $this->createReviewItems();
        $this->seedUninsuredReasons();
        $this->seedReviewPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('enroll_review_items');
        Schema::dropIfExists('enroll_review_batches');

        if (Schema::hasTable('enroll_ledgers')) {
            Schema::table('enroll_ledgers', function (Blueprint $table) {
                foreach ([
                    'review_status',
                    'current_review_batch_id',
                    'payment_amount_check_status',
                    'payment_amount_check_remark',
                    'town_last_filled_at',
                    'town_last_filled_by',
                    'town_submitted_at',
                    'town_submit_status',
                    'town_remark',
                    'town_death_time',
                    'town_resident_payment_amount',
                    'town_uninsured_reason',
                    'town_is_insured',
                ] as $column) {
                    if (Schema::hasColumn('enroll_ledgers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('business_filter_options')) {
            Db::table('business_filter_options')
                ->where('module', 'enroll')
                ->where('type', 'uninsured_reason')
                ->where('source_batch', 'enroll_default')
                ->delete();
        }

        if (Schema::hasTable('permissions')) {
            Db::table('permissions')
                ->whereIn('name', ['参保台账明细:下放', '参保台账明细:收回', '参保台账明细:下放批次'])
                ->delete();
        }
    }

    private function patchLedgers(): void
    {
        if (!Schema::hasTable('enroll_ledgers')) {
            return;
        }

        Schema::table('enroll_ledgers', function (Blueprint $table) {
            if (!Schema::hasColumn('enroll_ledgers', 'town_is_insured')) {
                $table->string('town_is_insured', 20)->nullable()->after('manual_remark')->comment('镇街填报是否参保：是/否');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'town_uninsured_reason')) {
                $table->string('town_uninsured_reason', 255)->nullable()->after('town_is_insured')->comment('镇街填报未参保原因');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'town_resident_payment_amount')) {
                $table->decimal('town_resident_payment_amount', 14, 2)->nullable()->after('town_uninsured_reason')->comment('镇街填报居民医保缴费金额');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'town_death_time')) {
                $table->string('town_death_time', 30)->nullable()->after('town_resident_payment_amount')->comment('镇街填报死亡时间');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'town_remark')) {
                $table->string('town_remark', 500)->nullable()->after('town_death_time')->comment('镇街人员备注');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'town_submit_status')) {
                $table->string('town_submit_status', 30)->default('未填报')->after('town_remark')->comment('镇街填报状态：未填报、已填报');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'town_submitted_at')) {
                $table->dateTime('town_submitted_at')->nullable()->after('town_submit_status')->comment('镇街填报时间');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'town_last_filled_by')) {
                $table->unsignedBigInteger('town_last_filled_by')->default(0)->after('town_submitted_at')->comment('最后填报人用户ID');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'town_last_filled_at')) {
                $table->dateTime('town_last_filled_at')->nullable()->after('town_last_filled_by')->comment('最后填报时间');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'payment_amount_check_status')) {
                $table->string('payment_amount_check_status', 30)->default('未填报')->after('town_last_filled_at')->comment('缴费金额核查状态：未填报、一致、待核查');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'payment_amount_check_remark')) {
                $table->string('payment_amount_check_remark', 255)->nullable()->after('payment_amount_check_status')->comment('缴费金额核查说明');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'current_review_batch_id')) {
                $table->unsignedBigInteger('current_review_batch_id')->default(0)->after('payment_amount_check_remark')->comment('当前下放批次ID');
            }
            if (!Schema::hasColumn('enroll_ledgers', 'review_status')) {
                $table->string('review_status', 30)->default('未下放')->after('current_review_batch_id')->comment('下放状态：未下放、待填报、已填报、已收回');
            }

            $table->index(['year', 'review_status'], 'idx_year_review_status');
            $table->index(['current_review_batch_id'], 'idx_current_review_batch');
            $table->index(['year', 'town_submit_status'], 'idx_year_town_submit_status');
            $table->index(['year', 'payment_amount_check_status'], 'idx_year_payment_check_status');
        });
    }

    private function createReviewBatches(): void
    {
        if (Schema::hasTable('enroll_review_batches')) {
            return;
        }

        Schema::create('enroll_review_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_no', 50)->default('')->comment('系统生成下放批次号');
            $table->integer('year')->comment('台账年份');
            $table->string('period', 20)->nullable()->comment('下放关联月份');
            $table->json('town_names')->nullable()->comment('下放镇街，JSON数组');
            $table->string('dispatch_mode', 30)->default('town')->comment('下放方式：town按镇街、manual手动选择、filter筛选结果');
            $table->json('filter_snapshot')->nullable()->comment('下放时筛选条件快照');
            $table->integer('total_count')->default(0)->comment('下放记录数');
            $table->string('status', 30)->default('已下放')->comment('状态：已下放、已收回');
            $table->unsignedBigInteger('created_by')->default(0)->comment('创建人用户ID');
            $table->string('created_by_name', 80)->nullable()->comment('创建人名称');
            $table->dateTime('dispatched_at')->nullable()->comment('下放时间');
            $table->dateTime('recalled_at')->nullable()->comment('收回时间');
            $table->string('remark', 500)->nullable()->comment('备注');
            $table->timestamps();

            $table->unique('batch_no', 'uk_batch_no');
            $table->index(['year', 'status'], 'idx_year_status');
            $table->index(['created_by'], 'idx_created_by');
            $table->comment('参保台账镇街核实下放批次表');
        });
    }

    private function createReviewItems(): void
    {
        if (Schema::hasTable('enroll_review_items')) {
            return;
        }

        Schema::create('enroll_review_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id')->default(0)->comment('下放批次ID');
            $table->unsignedBigInteger('ledger_id')->default(0)->comment('参保台账ID');
            $table->string('town_name', 120)->nullable()->comment('镇街名称');
            $table->string('status', 30)->default('待填报')->comment('状态：待填报、已填报、已收回');
            $table->dateTime('submitted_at')->nullable()->comment('填报时间');
            $table->dateTime('recalled_at')->nullable()->comment('收回时间');
            $table->timestamps();

            $table->index(['batch_id', 'status'], 'idx_batch_status');
            $table->index(['ledger_id'], 'idx_ledger_id');
            $table->index(['town_name', 'status'], 'idx_town_status');
            $table->comment('参保台账镇街核实下放明细表');
        });
    }

    private function seedUninsuredReasons(): void
    {
        if (!Schema::hasTable('business_filter_options')) {
            return;
        }

        foreach (['死亡', '服刑', '服役', '拒参', '失踪', '无法联系', '已注销户口', '户口迁出', '其他'] as $index => $reason) {
            Db::table('business_filter_options')->updateOrInsert(
                [
                    'module' => 'enroll',
                    'type' => 'uninsured_reason',
                    'value' => $reason,
                ],
                [
                    'label' => $reason,
                    'status' => 1,
                    'sort' => 100 - $index,
                    'source_batch' => 'enroll_default',
                    'remark' => '参保台账默认未参保原因',
                    'deleted_at' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            );
        }
    }

    private function seedReviewPermissions(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $parentId = (int) Db::table('permissions')->where('name', '参保台账明细')->value('id');
        if ($parentId <= 0) {
            return;
        }

        foreach ([
            ['name' => '参保台账明细:下放', 'description' => '下放参保台账给镇街核实', 'sort' => 95],
            ['name' => '参保台账明细:收回', 'description' => '收回参保台账镇街填报权限', 'sort' => 96],
            ['name' => '参保台账明细:下放批次', 'description' => '查看参保台账下放批次', 'sort' => 97],
        ] as $permission) {
            $data = [
                'name' => $permission['name'],
                'description' => $permission['description'],
                'type' => 'operation',
                'parent_id' => $parentId,
                'sort' => $permission['sort'],
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if (Schema::hasColumn('permissions', 'status')) {
                $data['status'] = 1;
            }

            $existing = Db::table('permissions')->where('name', $permission['name'])->first();
            if ($existing) {
                Db::table('permissions')->where('id', $existing->id)->update($data);
                continue;
            }

            $data['created_at'] = date('Y-m-d H:i:s');
            Db::table('permissions')->insert($data);
        }
    }
}
