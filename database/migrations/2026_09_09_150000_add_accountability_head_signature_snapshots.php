<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('incidents') && ! Schema::hasColumn('incidents', 'head_decision_signature_snapshot_id')) {
            Schema::table('incidents', function (Blueprint $table): void {
                $table->foreignId('head_decision_signature_snapshot_id')
                    ->nullable()
                    ->after('reported_by_user_id')
                    ->constrained('signature_snapshots')
                    ->nullOnDelete();
                $table->foreignId('head_decided_by_user_id')
                    ->nullable()
                    ->after('head_decision_signature_snapshot_id')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamp('head_decided_at')->nullable()->after('head_decided_by_user_id');
            });
        }

        if (Schema::hasTable('sanctions') && ! Schema::hasColumn('sanctions', 'signature_snapshot_id')) {
            Schema::table('sanctions', function (Blueprint $table): void {
                $table->foreignId('signature_snapshot_id')
                    ->nullable()
                    ->after('confirmed_by_user_id')
                    ->constrained('signature_snapshots')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sanctions') && Schema::hasColumn('sanctions', 'signature_snapshot_id')) {
            Schema::table('sanctions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('signature_snapshot_id');
            });
        }

        if (Schema::hasTable('incidents') && Schema::hasColumn('incidents', 'head_decision_signature_snapshot_id')) {
            Schema::table('incidents', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('head_decision_signature_snapshot_id');
                $table->dropConstrainedForeignId('head_decided_by_user_id');
                $table->dropColumn('head_decided_at');
            });
        }
    }
};
