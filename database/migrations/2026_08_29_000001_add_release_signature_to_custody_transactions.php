<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The SPMU Action Officer is the designated signatory for the physical
     * issuance ("Issued by") of approved property.
     *
     * This is a distinct accountability from:
     * - the borrower's request certification E-signature
     *   (request_versions.borrower_signature_snapshot_id)
     * - the SPMU Head's approval E-signature
     *   (approval_steps.signature_snapshot_id)
     * - the borrower's handwritten receipt signature on the printed
     *   Borrower Slip, which stays a wet signature
     *
     * It therefore gets its own column rather than reusing the dormant
     * borrower acknowledgement column.
     */
    public function up(): void
    {
        if (
            Schema::hasTable('custody_transactions')
            && Schema::hasTable('signature_snapshots')
            && ! Schema::hasColumn('custody_transactions', 'released_by_signature_snapshot_id')
        ) {
            Schema::table('custody_transactions', function (Blueprint $table): void {
                $table->foreignId('released_by_signature_snapshot_id')
                    ->nullable()
                    ->constrained('signature_snapshots')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        /*
         * Intentionally conservative, matching the other workflow migrations
         * in this project. Rolling back must not destroy captured signature
         * evidence for property that was already physically issued.
         */
    }
};
