<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROPERTY_REASONS = ['DAMAGED', 'LOST_MISSING', 'STOLEN', 'DESTROYED'];

    public function up(): void
    {
        // Idempotent: a prior run of this migration may already have added
        // this column via DDL that MariaDB auto-commits immediately, even
        // though the failing backfill below left the migration unrecorded.
        if (! Schema::hasColumn('borrower_violations', 'violation_source')) {
            Schema::table('borrower_violations', function (Blueprint $table): void {
                // Permanently nullable. Late Return and Property Accountability must
                // never be forced back into one row to avoid an ambiguous backfill -
                // any row this migration cannot deterministically classify is left
                // NULL for good (see spmu:audit-legacy-borrower-violations). New code
                // always writes a concrete value, so a legacy NULL row is inert.
                $table->string('violation_source', 30)->nullable()->after('violation_code');
            });
        }

        $this->backfillDeterministicRows();

        /*
         * custody_transaction_id carries a foreign key
         * (borrower_violations_custody_transaction_id_foreign ->
         * custody_transactions.id), and borrower_violation_custody_code_unique
         * - the old 2-column unique index - is currently the ONLY index
         * whose leftmost column is custody_transaction_id, so InnoDB/MariaDB
         * is relying on it as that FK's supporting index. Dropping it before
         * another such index exists fails with error 1553. The new 3-column
         * unique index also starts with custody_transaction_id, so creating
         * it FIRST gives the FK a valid supporting index throughout, and only
         * then is it safe to drop the old one.
         */
        Schema::table('borrower_violations', function (Blueprint $table): void {
            if (! Schema::hasIndex('borrower_violations', 'borrower_violation_custody_code_source_unique')) {
                $table->unique(
                    ['custody_transaction_id', 'violation_code', 'violation_source'],
                    'borrower_violation_custody_code_source_unique'
                );
            }
        });

        Schema::table('borrower_violations', function (Blueprint $table): void {
            if (Schema::hasIndex('borrower_violations', 'borrower_violation_custody_code_unique')) {
                $table->dropUnique('borrower_violation_custody_code_unique');
            }
        });
    }

    /**
     * Classifies every existing row using exactly the two deterministic rules
     * spmu:audit-legacy-borrower-violations already reports (property reason or
     * linked Incident -> PROPERTY_ACCOUNTABILITY; reasons exactly ['LATE_RETURN']
     * with no linked Incident -> LATE_RETURN). Anything else stays NULL.
     */
    private function backfillDeterministicRows(): void
    {
        $custodyIdsWithIncident = DB::table('incidents')
            ->whereNotNull('custody_transaction_id')
            ->pluck('custody_transaction_id')
            ->unique()
            ->flip();

        // Only NULL rows are considered, both so a re-run after a partial
        // failure is cheap and so it can never overwrite a value another
        // process already wrote.
        DB::table('borrower_violations')->whereNull('violation_source')->orderBy('id')->chunkById(200, function ($violations) use ($custodyIdsWithIncident): void {
            foreach ($violations as $violation) {
                $details = json_decode((string) $violation->details_json, true) ?: [];
                $reasons = is_array($details['reasons'] ?? null)
                    ? array_map(fn ($reason) => strtoupper((string) $reason), $details['reasons'])
                    : [];
                $incidentIds = is_array($details['incident_ids'] ?? null) ? $details['incident_ids'] : [];

                $hasPropertyReason = array_intersect($reasons, self::PROPERTY_REASONS) !== [];
                $hasLinkedIncident = $incidentIds !== []
                    || ($violation->custody_transaction_id !== null
                        && $custodyIdsWithIncident->has($violation->custody_transaction_id));

                $source = match (true) {
                    $hasPropertyReason || $hasLinkedIncident => 'PROPERTY_ACCOUNTABILITY',
                    $reasons === ['LATE_RETURN'] => 'LATE_RETURN',
                    default => null,
                };

                if ($source !== null) {
                    // DB::table() returns a base Query Builder, which has no
                    // whereKey() (that is an Eloquent Builder method). Calling
                    // it here silently fell through Laravel's dynamic
                    // where{Column}() resolver, which snake-cased "Key" into
                    // a literal `where key = ...` clause against a
                    // nonexistent column - this is what a previous run failed
                    // on. Use the real column name instead.
                    DB::table('borrower_violations')->where('id', $violation->id)->update(['violation_source' => $source]);
                }
            }
        });
    }

    public function down(): void
    {
        // Same FK-support ordering constraint as up(): recreate the old
        // index before dropping the new one, not the other way round.
        Schema::table('borrower_violations', function (Blueprint $table): void {
            $table->unique(['custody_transaction_id', 'violation_code'], 'borrower_violation_custody_code_unique');
        });

        Schema::table('borrower_violations', function (Blueprint $table): void {
            $table->dropUnique('borrower_violation_custody_code_source_unique');
            $table->dropColumn('violation_source');
        });
    }
};
