<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\SmsNotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSmsNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $deliveryId,
        public readonly string $message,
        public readonly string $eventCode,
    ) {}

    public function handle(SmsNotificationChannel $sms): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);

        if (! $delivery || $delivery->channel !== 'SMS' || $delivery->delivery_status !== 'PENDING') {
            return;
        }

        $recipient = User::query()->find($delivery->recipient_user_id);
        if (! $recipient) {
            $delivery->update([
                'attempt_no' => 1,
                'attempted_at' => now(),
                'delivery_status' => 'SKIPPED',
                'provider_response' => 'Recipient account is no longer available.',
            ]);

            return;
        }

        $result = $sms->send($recipient, $this->message, $this->eventCode);

        $delivery->update([
            'address_snapshot' => $result['address'] ?: $delivery->address_snapshot,
            'attempt_no' => 1,
            'provider' => $result['provider'],
            'attempted_at' => now(),
            'delivery_status' => $result['status'],
            'provider_response' => $result['response'],
        ]);

        Log::info('SPMU SMS delivery processed', [
            'delivery_id' => $delivery->id,
            'event' => $this->eventCode,
            'status' => $result['status'],
        ]);
    }
}
