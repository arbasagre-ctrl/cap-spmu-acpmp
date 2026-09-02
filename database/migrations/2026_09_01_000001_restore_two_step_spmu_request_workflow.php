<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One request version has two SPMU records:
         *
         * 1. Action Officer verification (not approval)
         * 2. SPMU Head review and final decision
         *
         * The sequence constraint continues to prevent duplicate workflow
         * steps. Removing only the stage-code constraint allows both records
         * to remain under the existing SPMU stage and preserves history.
         */
        Schema::table('approval_steps', function (Blueprint $table): void {
            $table->dropUnique(
                'approval_steps_request_version_id_stage_code_unique'
            );
        });
    }

    public function down(): void
    {
        /*
         * Do not restore the old one-SPMU-row constraint. A database that has
         * processed the corrected workflow can legitimately contain both
         * verification and decision rows for the same request version.
         */
    }
};
