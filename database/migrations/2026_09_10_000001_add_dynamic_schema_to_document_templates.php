<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_templates') || Schema::hasColumn('document_templates', 'dynamic_schema')) {
            return;
        }

        Schema::table('document_templates', function (Blueprint $table): void {
            // The existing content_template TEXT column intentionally holds
            // compact lifecycle metadata. A complete Office layout schema can
            // exceed that limit, so it is kept separately and additively.
            $table->longText('dynamic_schema')->nullable()->after('content_template');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('document_templates') && Schema::hasColumn('document_templates', 'dynamic_schema')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->dropColumn('dynamic_schema');
            });
        }
    }
};
