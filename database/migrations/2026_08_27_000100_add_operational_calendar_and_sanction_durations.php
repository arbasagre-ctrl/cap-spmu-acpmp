<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sanction_rules') && ! Schema::hasColumn('sanction_rules', 'duration_value')) {
            Schema::table('sanction_rules', function (Blueprint $table): void {
                $table->unsignedSmallInteger('duration_value')->nullable()->after('duration_mode');
            });
        }

        if (! Schema::hasTable('operational_weekly_schedules')) {
            Schema::create('operational_weekly_schedules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedTinyInteger('weekday')->unique();
                $table->boolean('is_open')->default(true);
                $table->boolean('accepts_requests')->default(true);
                $table->boolean('allows_pickup')->default(true);
                $table->boolean('allows_return')->default(true);
                $table->time('open_time')->nullable();
                $table->time('close_time')->nullable();
                $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('operational_date_exceptions')) {
            Schema::create('operational_date_exceptions', function (Blueprint $table): void {
                $table->id();
                $table->date('exception_date')->unique();
                $table->string('status', 20)->default('CLOSED');
                $table->boolean('accepts_requests')->nullable();
                $table->boolean('allows_pickup')->nullable();
                $table->boolean('allows_return')->nullable();
                $table->time('open_time')->nullable();
                $table->time('close_time')->nullable();
                $table->string('reason', 500);
                $table->foreignId('configured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('custody_transactions')) {
            $addOriginalDueAt = ! Schema::hasColumn('custody_transactions', 'original_due_at');
            $addAdjustmentReason = ! Schema::hasColumn('custody_transactions', 'due_adjustment_reason');
            $addAdjustedAt = ! Schema::hasColumn('custody_transactions', 'due_adjusted_at');

            if ($addOriginalDueAt || $addAdjustmentReason || $addAdjustedAt) {
                Schema::table('custody_transactions', function (Blueprint $table) use ($addOriginalDueAt, $addAdjustmentReason, $addAdjustedAt): void {
                    if ($addOriginalDueAt) {
                        $table->timestamp('original_due_at')->nullable()->after('due_at');
                    }
                    if ($addAdjustmentReason) {
                        $table->string('due_adjustment_reason', 500)->nullable()->after('original_due_at');
                    }
                    if ($addAdjustedAt) {
                        $table->timestamp('due_adjusted_at')->nullable()->after('due_adjustment_reason');
                    }
                });
            }

            DB::table('custody_transactions')
                ->whereNull('original_due_at')
                ->whereNotNull('due_at')
                ->update(['original_due_at' => DB::raw('due_at')]);
        }

        $now = now();
        foreach (range(1, 7) as $weekday) {
            $open = $weekday <= 5;
            DB::table('operational_weekly_schedules')->updateOrInsert(
                ['weekday' => $weekday],
                [
                    'is_open' => $open,
                    'accepts_requests' => $open,
                    'allows_pickup' => $open,
                    'allows_return' => $open,
                    'open_time' => null,
                    'close_time' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        if (Schema::hasTable('sanction_rules')) {
            $rules = [
                1 => [
                    'sanction_code' => 'WRITTEN_REPRIMAND',
                    'sanction_label' => 'Written Reprimand',
                    'duration_mode' => 'NONE',
                    'duration_value' => null,
                ],
                2 => [
                    'sanction_code' => 'BORROWING_SUSPENSION',
                    'sanction_label' => '1-Month Borrowing Suspension',
                    'duration_mode' => 'MONTHS',
                    'duration_value' => 1,
                ],
                3 => [
                    'sanction_code' => 'BORROWING_SUSPENSION',
                    'sanction_label' => 'Borrowing Suspension Until End of Current Semester',
                    'duration_mode' => 'UNTIL_ACADEMIC_PERIOD_END',
                    'duration_value' => null,
                ],
            ];

            foreach ($rules as $offenseNo => $rule) {
                DB::table('sanction_rules')->updateOrInsert(
                    ['offense_no' => $offenseNo],
                    $rule + [
                        'status' => 'ACTIVE',
                        'effective_from' => now()->toDateString(),
                        'effective_to' => null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('custody_transactions')) {
            $columns = array_values(array_filter([
                Schema::hasColumn('custody_transactions', 'due_adjusted_at') ? 'due_adjusted_at' : null,
                Schema::hasColumn('custody_transactions', 'due_adjustment_reason') ? 'due_adjustment_reason' : null,
                Schema::hasColumn('custody_transactions', 'original_due_at') ? 'original_due_at' : null,
            ]));

            if ($columns !== []) {
                Schema::table('custody_transactions', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }

        Schema::dropIfExists('operational_date_exceptions');
        Schema::dropIfExists('operational_weekly_schedules');

        if (Schema::hasTable('sanction_rules') && Schema::hasColumn('sanction_rules', 'duration_value')) {
            Schema::table('sanction_rules', function (Blueprint $table): void {
                $table->dropColumn('duration_value');
            });
        }
    }
};
