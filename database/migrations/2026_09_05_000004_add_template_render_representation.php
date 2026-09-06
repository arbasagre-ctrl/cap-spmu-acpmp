<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_templates') || Schema::hasColumn('document_templates', 'render_stored_file_id')) {
            return;
        }

        Schema::table('document_templates', function (Blueprint $table): void {
            $table->foreignId('render_stored_file_id')
                ->nullable()
                ->after('stored_file_id')
                ->constrained('stored_files')
                ->nullOnDelete();
        });

        /* Existing PDF source layouts render from their preserved source. Office
         * documents are intentionally not guessed here: their immutable PDF
         * rendition is created only by the production converter at registration. */
        $pdfSourceIds = DB::table('stored_files')
            ->where(function ($query): void {
                $query->where('mime_type', 'application/pdf')
                    ->orWhere('original_name', 'like', '%.pdf');
            })
            ->pluck('id');

        if ($pdfSourceIds->isNotEmpty()) {
            DB::table('document_templates')
                ->where('source_mode', 'OFFICIAL_LAYOUT')
                ->whereNull('render_stored_file_id')
                ->whereIn('stored_file_id', $pdfSourceIds)
                ->update(['render_stored_file_id' => DB::raw('stored_file_id')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('document_templates') && Schema::hasColumn('document_templates', 'render_stored_file_id')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('render_stored_file_id');
            });
        }
    }
};
