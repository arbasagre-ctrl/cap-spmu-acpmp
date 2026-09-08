<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Late-return assessment fields for the existing overdue_cases table.
 *
 * Overdue and Returned Late are different states, and the difference is a date
 * the table could not previously hold:
 *
 *  - actual_return_date       The authoritative physical return date. It cannot
 *                             be derived on the fly: a custody may have several
 *                             return_transactions rows, and custody.closed_at is
 *                             when the whole transaction closed, not when the
 *                             property came back. It is frozen here so the fee
 *                             stops growing the moment the item is returned.
 *
 *  - return_date_source       Which workflow supplied that date. Linen takes the
 *                             Laundry Personnel receipt, everything else takes
 *                             the Return Inspection.
 *
 *  - late_days                The frozen count behind the final fee. Recomputing
 *                             it later from now() is exactly the defect this
 *                             replaces.
 *
 *  - ao_confirmed_by/at       The Action Officer's confirmation of the
 *                             assessment. No existing column records who
 *                             confirmed a late return or when.
 *
 *  - correction_remarks       Why the SPMU Head sent an assessment back to the
 *                             Action Officer, so the case keeps its history
 *                             instead of being deleted and re-raised.
 *
 * No existing table can carry these without overloading a column that already
 * means something else, so they are added here rather than in a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overdue_cases', function (Blueprint $table): void {
            $table->date('actual_return_date')->nullable()->after('overdue_started_at');
            $table->string('return_date_source', 30)->nullable()->after('actual_return_date');
            $table->unsignedSmallInteger('late_days')->nullable()->after('return_date_source');

            $table->foreignId('ao_confirmed_by_user_id')
                ->nullable()
                ->after('late_days')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('ao_confirmed_at')->nullable()->after('ao_confirmed_by_user_id');
            $table->text('correction_remarks')->nullable()->after('ao_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('overdue_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ao_confirmed_by_user_id');

            $table->dropColumn([
                'actual_return_date',
                'return_date_source',
                'late_days',
                'ao_confirmed_at',
                'correction_remarks',
            ]);
        });
    }
};
