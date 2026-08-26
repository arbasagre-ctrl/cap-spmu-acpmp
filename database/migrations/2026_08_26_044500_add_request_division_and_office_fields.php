<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('request_versions')) {
            return;
        }

        if (! Schema::hasColumn('request_versions', 'division_code')) {
            Schema::table('request_versions', function (Blueprint $table): void {
                $table->string('division_code', 60)
                    ->nullable()
                    ->after('location')
                    ->index();
            });
        }

        if (! Schema::hasColumn('request_versions', 'office_unit')) {
            Schema::table('request_versions', function (Blueprint $table): void {
                $table->string('office_unit')
                    ->nullable()
                    ->after('division_code')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. These fields are request history metadata.
    }
};
