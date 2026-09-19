<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('incidents') && ! Schema::hasColumn('incidents', 'requires_rslddp')) {
            Schema::table('incidents', function (Blueprint $table): void {
                $table->boolean('requires_rslddp')->default(false)->after('rslddp_reference');
                $table->foreignId('rslddp_evidence_submission_id')
                    ->nullable()
                    ->after('requires_rslddp')
                    ->constrained('evidence_submissions')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('billing_statements') && ! Schema::hasColumn('billing_statements', 'source')) {
            Schema::table('billing_statements', function (Blueprint $table): void {
                /*
                 * Null means SPMU-generated (Late Return Billing Statement, or
                 * any legacy property billing issued before this migration) -
                 * unchanged behavior. 'ACCOUNTING_OFFICE' marks a Billing
                 * Statement prepared externally by Accounting and only
                 * uploaded/recorded here.
                 */
                $table->string('source', 30)->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('incidents') && Schema::hasColumn('incidents', 'rslddp_evidence_submission_id')) {
            Schema::table('incidents', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('rslddp_evidence_submission_id');
                $table->dropColumn('requires_rslddp');
            });
        }

        if (Schema::hasTable('billing_statements') && Schema::hasColumn('billing_statements', 'source')) {
            Schema::table('billing_statements', function (Blueprint $table): void {
                $table->dropColumn('source');
            });
        }
    }
};
