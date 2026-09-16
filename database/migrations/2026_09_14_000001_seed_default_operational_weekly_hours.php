<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The original operational_weekly_schedules seed (migration
 * 2026_08_27_000100) left open_time/close_time null for every weekday,
 * including the Monday-Friday rows it marks open. Because
 * OperationalCalendarService::isOpenFor() intentionally fails closed
 * without a complete Open Time/Close Time pair, a fresh installation that
 * has never had an Admin visit Operational Configuration has Pickup and
 * Return permanently unavailable.
 *
 * This backfills a default Monday-Friday 8:00 AM-5:00 PM window - the same
 * standard-business-day window InventoryController already assumes
 * elsewhere in this codebase (see its default report date range,
 * 08:00-17:00) - only for rows an Admin has never touched. An Admin-touched
 * row always has both times set together (PolicyController requires both or
 * neither), so the guard below (both columns still null) only ever matches
 * an untouched, still-default row and never overwrites a real
 * configuration - including one an Admin deliberately left blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operational_weekly_schedules')) {
            return;
        }

        DB::table('operational_weekly_schedules')
            ->whereIn('weekday', [1, 2, 3, 4, 5])
            ->whereNull('open_time')
            ->whereNull('close_time')
            ->update([
                'open_time' => '08:00:00',
                'close_time' => '17:00:00',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_weekly_schedules')) {
            return;
        }

        DB::table('operational_weekly_schedules')
            ->whereIn('weekday', [1, 2, 3, 4, 5])
            ->where('open_time', '08:00:00')
            ->where('close_time', '17:00:00')
            ->update([
                'open_time' => null,
                'close_time' => null,
                'updated_at' => now(),
            ]);
    }
};
