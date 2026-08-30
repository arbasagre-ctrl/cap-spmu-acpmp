<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('laundry_jobs')) {
            return;
        }

        /*
         * Old IN_PROCESS / READY_FOR_SPMU_RETURN records still need the new
         * borrower-side SPMU Return Inspection before the system can treat the
         * linen as physically accounted. Keep them in FOR_LAUNDRY so that step
         * is not skipped accidentally.
         */
        DB::table('laundry_jobs')
            ->whereIn('status', ['IN_PROCESS', 'READY_FOR_SPMU_RETURN'])
            ->update([
                'status' => 'FOR_LAUNDRY',
                'completed_at' => null,
                'updated_at' => now(),
            ]);

        /*
         * These legacy states were reached only after final SPMU physical
         * acceptance, so no new borrower action is required. Under the revised
         * flow they can be treated as internally completed.
         */
        $completedCustodyIds = DB::table('laundry_jobs')
            ->whereIn('status', ['AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'])
            ->pluck('custody_transaction_id');

        /*
         * COALESCE(completed_at, NOW()) is not portable (SQLite has no
         * NOW()). Achieve the same effect with two plain, bound updates
         * instead of a raw engine-specific SQL function.
         */
        DB::table('laundry_jobs')
            ->whereIn('status', ['AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'])
            ->whereNull('completed_at')
            ->update(['completed_at' => now()]);

        DB::table('laundry_jobs')
            ->whereIn('status', ['AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'])
            ->update([
                'status' => 'LAUNDRY_COMPLETED',
                'updated_at' => now(),
            ]);

        if (Schema::hasTable('custody_lines') && Schema::hasTable('request_items') && Schema::hasTable('inventory_items')) {
            foreach ($completedCustodyIds as $custodyId) {
                $linenLineIds = DB::table('custody_lines')
                    ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
                    ->join('inventory_items', 'inventory_items.id', '=', 'request_items.inventory_item_id')
                    ->where('custody_lines.custody_transaction_id', $custodyId)
                    ->where('inventory_items.laundry_required', true)
                    ->pluck('custody_lines.id');

                if ($linenLineIds->isNotEmpty()) {
                    DB::table('custody_lines')
                        ->whereIn('id', $linenLineIds)
                        ->update([
                            'compliance_status' => 'LAUNDRY_COMPLETED',
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        /*
         * If a legacy custody was held open only by the old final-form Laundry
         * stage, release that hold. Other incidents, overdue cases, Gate Pass
         * evidence, and incomplete physical returns still keep it open.
         */
        if (! Schema::hasTable('custody_transactions') || ! Schema::hasTable('custody_lines')) {
            return;
        }

        foreach ($completedCustodyIds as $custodyId) {
            $hasOutstanding = DB::table('custody_lines')
                ->where('custody_transaction_id', $custodyId)
                ->whereColumn('returned_quantity', '<', 'actual_released_quantity')
                ->exists();

            if ($hasOutstanding) {
                continue;
            }

            $hasIncident = Schema::hasTable('incidents')
                && DB::table('incidents')
                    ->where('custody_transaction_id', $custodyId)
                    ->whereNotIn('status', ['RESOLVED', 'CLOSED'])
                    ->exists();

            $hasOverdue = Schema::hasTable('overdue_cases')
                && DB::table('overdue_cases')
                    ->where('custody_transaction_id', $custodyId)
                    ->where('status', '!=', 'RESOLVED')
                    ->exists();

            $hasGatePass = Schema::hasTable('gate_passes')
                && DB::table('gate_passes')
                    ->where('custody_transaction_id', $custodyId)
                    ->where('status', '!=', 'VERIFIED')
                    ->exists();

            if (! $hasIncident && ! $hasOverdue && ! $hasGatePass) {
                DB::table('custody_transactions')
                    ->where('id', $custodyId)
                    ->whereNull('closed_at')
                    ->update(['closed_at' => now()]);

                DB::table('custody_transactions')
                    ->where('id', $custodyId)
                    ->update([
                        'status' => 'CLOSED',
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        /*
         * Status alignment is intentionally not reversed. Recreating the old
         * multi-stage borrower-blocking Laundry workflow would be ambiguous and
         * could reopen already settled borrower transactions.
         */
    }
};
