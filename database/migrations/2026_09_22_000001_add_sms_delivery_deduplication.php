<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            /*
             * Nullable rows preserve legacy email/system delivery history.
             * New SMS rows receive a deterministic key, so this enforces SMS
             * deduplication without a risky migration over old data.
             */
            $table->string('sms_deduplication_key', 80)->nullable()->unique(
                'notification_delivery_sms_deduplication_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropUnique('notification_delivery_sms_deduplication_uq');
            $table->dropColumn('sms_deduplication_key');
        });
    }
};
