<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('laundry_records')) {
            return;
        }

        Schema::create('laundry_records', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('return_line_id')
                ->unique()
                ->constrained('return_lines')
                ->cascadeOnDelete();

            $table->foreignId('form_document_id')
                ->nullable()
                ->constrained('generated_documents')
                ->nullOnDelete();

            $table->foreignId('accomplished_file_id')
                ->nullable()
                ->constrained('stored_files')
                ->nullOnDelete();

            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('uploaded_at')->nullable();

            $table->foreignId('verified_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('worker_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('worker_name')->nullable();
            $table->timestamp('worker_received_at')->nullable();
            $table->timestamp('worker_completed_at')->nullable();

            $table->decimal('quantity_received', 12, 3)->nullable();
            $table->string('received_condition', 60)->nullable();

            $table->decimal('cleaned_quantity', 12, 3)->default(0);
            $table->decimal('damaged_quantity', 12, 3)->default(0);

            $table->text('remarks')->nullable();

            $table->string('status', 64)
                ->default('PENDING')
                ->index();

            $table->timestamp('verified_at')->nullable();
            $table->text('verification_remarks')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive.
        // This migration repairs a missing production table.
    }
};
