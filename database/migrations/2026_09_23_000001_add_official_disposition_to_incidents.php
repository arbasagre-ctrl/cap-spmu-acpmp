<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            // MONETARY_SETTLEMENT | REPAIR | REPLACEMENT | RETURN_RECOVERY | OTHER —
            // recorded from the accomplished RSLDDP's stated external decision.
            $table->string('official_disposition', 30)->nullable()->after('rslddp_evidence_submission_id');
            $table->decimal('official_disposition_amount', 12, 2)->nullable()->after('official_disposition');
            $table->text('official_disposition_details')->nullable()->after('official_disposition_amount');
            $table->foreignId('official_disposition_recorded_by_user_id')->nullable()
                ->after('official_disposition_details')->constrained('users')->nullOnDelete();
            $table->timestamp('official_disposition_recorded_at')->nullable()->after('official_disposition_recorded_by_user_id');

            // ACCEPTED | NOT_ACCEPTED — REPAIR/REPLACEMENT/RETURN_RECOVERY AO
            // verification outcome only; MONETARY_SETTLEMENT never uses this.
            $table->string('compliance_verification_status', 20)->nullable()->after('official_disposition_recorded_at');
            $table->text('compliance_verification_remarks')->nullable()->after('compliance_verification_status');
            $table->foreignId('compliance_verified_by_user_id')->nullable()
                ->after('compliance_verification_remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('compliance_verified_at')->nullable()->after('compliance_verified_by_user_id');

            // NULL = legacy incident, never touched by the new Confirm/Clear
            // decision endpoint. 'V2' = processed by the new final workflow. This
            // is the sole discriminator that lets an incident already awaiting
            // accomplished-RSLDDP upload under the old flow keep flowing through
            // the unmodified legacy status names/methods after this deploy.
            $table->string('accountability_flow_version', 20)->nullable()->after('compliance_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('official_disposition_recorded_by_user_id');
            $table->dropConstrainedForeignId('compliance_verified_by_user_id');
            $table->dropColumn([
                'official_disposition',
                'official_disposition_amount',
                'official_disposition_details',
                'official_disposition_recorded_at',
                'compliance_verification_status',
                'compliance_verification_remarks',
                'compliance_verified_at',
                'accountability_flow_version',
            ]);
        });
    }
};
