<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('return_transactions')) {
            return;
        }

        if (Schema::hasColumn('return_transactions', 'inspected_by_user_id')) {
            Schema::table('return_transactions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('inspected_by_user_id');
            });
        }

        foreach (['inspected_at', 'receipt_quantities'] as $column) {
            if (! Schema::hasColumn('return_transactions', $column)) {
                continue;
            }

            Schema::table('return_transactions', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('return_transactions')) {
            return;
        }

        if (! Schema::hasColumn('return_transactions', 'receipt_quantities')) {
            Schema::table('return_transactions', function (Blueprint $table): void {
                $table->json('receipt_quantities')->nullable()->after('received_at');
            });
        }

        if (! Schema::hasColumn('return_transactions', 'inspected_by_user_id')) {
            Schema::table('return_transactions', function (Blueprint $table): void {
                $table->foreignId('inspected_by_user_id')
                    ->nullable()
                    ->after('receipt_quantities')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('return_transactions', 'inspected_at')) {
            Schema::table('return_transactions', function (Blueprint $table): void {
                $table->timestamp('inspected_at')->nullable()->after('inspected_by_user_id');
            });
        }
    }
};
