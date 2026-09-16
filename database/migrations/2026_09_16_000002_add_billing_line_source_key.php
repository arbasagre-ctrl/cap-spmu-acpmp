<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_lines') || Schema::hasColumn('billing_lines', 'source_key')) {
            return;
        }

        /*
         * Existing billing rows intentionally remain NULL: importing or
         * backfilling a business source from historical free-form data would
         * risk collapsing legitimate old lines. New controller-created rows
         * receive a stable source key and are protected by this unique index.
         */
        Schema::table('billing_lines', function (Blueprint $table): void {
            $table->string('source_key', 120)->nullable();
        });

        Schema::table('billing_lines', function (Blueprint $table): void {
            $table->unique('source_key', 'billing_lines_source_key_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('billing_lines') || ! Schema::hasColumn('billing_lines', 'source_key')) {
            return;
        }

        Schema::table('billing_lines', function (Blueprint $table): void {
            $table->dropUnique('billing_lines_source_key_unique');
            $table->dropColumn('source_key');
        });
    }
};
