<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('borrower_restrictions')) {
            return;
        }

        if (! Schema::hasColumn('borrower_restrictions', 'custody_transaction_id')) {
            Schema::table('borrower_restrictions', function (Blueprint $table): void {
                $table->foreignId('custody_transaction_id')
                    ->nullable()
                    ->after('borrower_user_id')
                    ->constrained('custody_transactions')
                    ->nullOnDelete();

                $table->index(
                    ['borrower_user_id', 'custody_transaction_id', 'status'],
                    'borrower_restriction_custody_status_idx'
                );
            });
        }

        /*
         * Backfill source custody where the existing restriction already has
         * enough linkage to determine it unambiguously. Older PENDING_RETURN /
         * OVERDUE_RETURN rows stored only a human-readable custody number in
         * reason, so that is used only as a conservative legacy fallback.
         * Ambiguous rows stay NULL rather than being attached to the wrong
         * borrowing transaction.
         */
        DB::table('borrower_restrictions')
            ->whereNull('custody_transaction_id')
            ->orderBy('id')
            ->get()
            ->each(function ($restriction): void {
                $custodyId = null;

                if ($restriction->incident_id ?? null) {
                    $custodyId = DB::table('incidents')
                        ->where('id', $restriction->incident_id)
                        ->value('custody_transaction_id');
                }

                if (! $custodyId && ($restriction->penalty_id ?? null)) {
                    $penalty = DB::table('penalties')
                        ->where('id', $restriction->penalty_id)
                        ->first(['custody_transaction_id', 'overdue_case_id', 'incident_id']);

                    $custodyId = $penalty?->custody_transaction_id;

                    if (! $custodyId && $penalty?->overdue_case_id) {
                        $custodyId = DB::table('overdue_cases')
                            ->where('id', $penalty->overdue_case_id)
                            ->value('custody_transaction_id');
                    }

                    if (! $custodyId && $penalty?->incident_id) {
                        $custodyId = DB::table('incidents')
                            ->where('id', $penalty->incident_id)
                            ->value('custody_transaction_id');
                    }
                }

                if (! $custodyId && ($restriction->billing_statement_id ?? null)) {
                    $lines = DB::table('billing_lines')
                        ->where('billing_statement_id', $restriction->billing_statement_id)
                        ->get(['penalty_id', 'incident_id']);

                    foreach ($lines as $line) {
                        if ($line->incident_id) {
                            $custodyId = DB::table('incidents')
                                ->where('id', $line->incident_id)
                                ->value('custody_transaction_id');
                        }

                        if (! $custodyId && $line->penalty_id) {
                            $penalty = DB::table('penalties')
                                ->where('id', $line->penalty_id)
                                ->first(['custody_transaction_id', 'overdue_case_id', 'incident_id']);

                            $custodyId = $penalty?->custody_transaction_id;

                            if (! $custodyId && $penalty?->overdue_case_id) {
                                $custodyId = DB::table('overdue_cases')
                                    ->where('id', $penalty->overdue_case_id)
                                    ->value('custody_transaction_id');
                            }

                            if (! $custodyId && $penalty?->incident_id) {
                                $custodyId = DB::table('incidents')
                                    ->where('id', $penalty->incident_id)
                                    ->value('custody_transaction_id');
                            }
                        }

                        if ($custodyId) {
                            break;
                        }
                    }
                }

                if (! $custodyId && ! empty($restriction->reason)) {
                    $custodies = DB::table('custody_transactions')
                        ->where('borrower_user_id', $restriction->borrower_user_id)
                        ->get(['id', 'custody_no']);

                    $matches = $custodies
                        ->filter(fn ($custody) => $custody->custody_no
                            && str_contains((string) $restriction->reason, (string) $custody->custody_no))
                        ->values();

                    if ($matches->count() === 1) {
                        $custodyId = $matches->first()->id;
                    }
                }

                if ($custodyId) {
                    DB::table('borrower_restrictions')
                        ->where('id', $restriction->id)
                        ->update(['custody_transaction_id' => $custodyId]);
                }
            });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('borrower_restrictions')
            && Schema::hasColumn('borrower_restrictions', 'custody_transaction_id')
        ) {
            Schema::table('borrower_restrictions', function (Blueprint $table): void {
                $table->dropIndex('borrower_restriction_custody_status_idx');
                $table->dropConstrainedForeignId('custody_transaction_id');
            });
        }
    }
};
