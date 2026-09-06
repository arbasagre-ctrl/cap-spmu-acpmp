<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_organizational_units')) {
            Schema::create('user_organizational_units', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
                $table->string('assignment_type', 20)->default('ADDITIONAL');
                $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('revoked_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['user_id', 'organizational_unit_id'], 'user_org_unit_unique');
                $table->index(['user_id', 'revoked_at'], 'user_org_unit_active_idx');
            });
        }

        /*
         * Preserve every existing user's current organizational unit as the
         * centralized PRIMARY assignment. This makes the migration safe for
         * existing accounts before ICTU adds any optional additional units.
         */
        DB::table('users')
            ->whereNotNull('organizational_unit_id')
            ->orderBy('id')
            ->get(['id', 'organizational_unit_id', 'created_at', 'updated_at'])
            ->each(function ($user): void {
                DB::table('user_organizational_units')->updateOrInsert(
                    [
                        'user_id' => $user->id,
                        'organizational_unit_id' => $user->organizational_unit_id,
                    ],
                    [
                        'assignment_type' => 'PRIMARY',
                        'assigned_by_user_id' => null,
                        'assigned_at' => $user->created_at ?? now(),
                        'revoked_at' => null,
                        'created_at' => $user->created_at ?? now(),
                        'updated_at' => $user->updated_at ?? now(),
                    ]
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_organizational_units');
    }
};
