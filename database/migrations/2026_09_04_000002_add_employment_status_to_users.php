<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'employment_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('employment_status', 30)
                    ->nullable()
                    ->after('employment_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'employment_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('employment_status');
            });
        }
    }
};
