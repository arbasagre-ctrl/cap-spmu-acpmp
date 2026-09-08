<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * physical_signatures_confirmed was previously validated on the release
     * form and then discarded — never persisted anywhere. This column gives
     * the Action Officer's physical-handover attestation an explicit,
     * queryable record on the custody transaction itself, distinct from the
     * (intentionally absent) release E-signature.
     */
    public function up(): void
    {
        if (
            Schema::hasTable('custody_transactions')
            && ! Schema::hasColumn('custody_transactions', 'physical_handover_attested_at')
        ) {
            Schema::table('custody_transactions', function (Blueprint $table): void {
                $table->timestamp('physical_handover_attested_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        /*
         * Conservative, matching the sibling release-signature migration:
         * rolling back must not destroy a captured handover attestation.
         */
    }
};
