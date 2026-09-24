<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * SMS is out of scope. The original 2026_09_22_000001 migration already ran
 * on shared/live environments, so it is left untouched (editing an
 * already-applied migration would make those environments inconsistent with
 * a fresh install). This follow-up drops the now-unused SMS-only column,
 * deletes the obsolete sms_provider configuration row, and strips the
 * stale "sms" key out of existing users' notification_preferences.
 * Existing notification_deliveries rows (including historical channel=SMS
 * records) are audit history and are preserved untouched - only the
 * deduplication column, the dead configuration row, and the dead
 * per-user preference key are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('notification_deliveries', 'sms_deduplication_key')) {
            Schema::table('notification_deliveries', function (Blueprint $table): void {
                $table->dropUnique('notification_delivery_sms_deduplication_uq');
                $table->dropColumn('sms_deduplication_key');
            });
        }

        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->where('setting_key', 'sms_provider')->delete();
        }

        if (Schema::hasColumn('users', 'notification_preferences')) {
            DB::table('users')
                ->whereNotNull('notification_preferences')
                ->orderBy('id')
                ->chunkById(200, function ($users): void {
                    foreach ($users as $user) {
                        $preferences = json_decode((string) $user->notification_preferences, true);

                        if (! is_array($preferences) || ! array_key_exists('sms', $preferences)) {
                            continue;
                        }

                        unset($preferences['sms']);

                        DB::table('users')
                            ->where('id', $user->id)
                            ->update(['notification_preferences' => json_encode($preferences)]);
                    }
                });
        }
    }

    public function down(): void
    {
        /*
         * The sms_provider row and each user's original "sms" preference
         * value are dead configuration with no recoverable prior state, so
         * this only reverses the schema change. Re-running the original
         * 2026_09_22_000001 migration's intent is not restored here.
         */
        if (! Schema::hasColumn('notification_deliveries', 'sms_deduplication_key')) {
            Schema::table('notification_deliveries', function (Blueprint $table): void {
                $table->string('sms_deduplication_key', 80)->nullable()->unique(
                    'notification_delivery_sms_deduplication_uq'
                );
            });
        }
    }
};
