<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->string('compliance_action', 32)
                ->nullable()
                ->after('requires_rslddp');
        });

        // Preserve in-progress compliance cases created before this field
        // existed. New Head decisions record the exact action explicitly.
        DB::table('incidents')
            ->whereIn('status', ['COMPLIANCE_REQUIRED', 'COMPLIANCE_RSLDDP_PENDING'])
            ->whereNull('compliance_action')
            ->whereIn('incident_type', ['DAMAGED', 'DAMAGE'])
            ->update(['compliance_action' => 'REPAIR']);

        DB::table('incidents')
            ->whereIn('status', ['COMPLIANCE_REQUIRED', 'COMPLIANCE_RSLDDP_PENDING'])
            ->whereNull('compliance_action')
            ->whereIn('incident_type', ['MISSING', 'LOST', 'LOSS', 'STOLEN', 'DESTROYED'])
            ->update(['compliance_action' => 'REPLACEMENT']);
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropColumn('compliance_action');
        });
    }
};
