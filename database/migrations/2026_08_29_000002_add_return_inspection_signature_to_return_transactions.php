<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('return_transactions')
            && ! Schema::hasColumn('return_transactions', 'inspection_signature_snapshot_id')) {
            Schema::table('return_transactions', function (Blueprint $table): void {
                $table->foreignId('inspection_signature_snapshot_id')
                    ->nullable()
                    ->after('received_by_user_id')
                    ->constrained('signature_snapshots')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('return_transactions')
            && Schema::hasColumn('return_transactions', 'inspection_signature_snapshot_id')) {
            Schema::table('return_transactions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('inspection_signature_snapshot_id');
            });
        }
    }
};
