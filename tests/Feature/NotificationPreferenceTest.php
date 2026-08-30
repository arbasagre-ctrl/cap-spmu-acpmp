<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NotificationService must respect a recipient's own notification
 * preferences (Account Settings > Notification preferences) rather than
 * unconditionally sending every channel it is asked to.
 */
class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_skips_a_channel_the_recipient_has_disabled(): void
    {
        $recipient = User::factory()->create([
            'notification_preferences' => [
                'system' => true,
                'email' => false,
                'sms' => false,
            ],
        ]);

        $this->actingAs($recipient);

        $event = app(NotificationService::class)->send(
            'TRANSACTION_CLOSED',
            collect([$recipient]),
            'Test message.',
            null,
            ['SYSTEM', 'EMAIL']
        );

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_event_id' => $event->id,
            'recipient_user_id' => $recipient->id,
            'channel' => 'SYSTEM',
        ]);

        $this->assertDatabaseMissing('notification_deliveries', [
            'notification_event_id' => $event->id,
            'recipient_user_id' => $recipient->id,
            'channel' => 'EMAIL',
        ]);
    }

    public function test_send_still_delivers_a_channel_the_recipient_never_configured(): void
    {
        $recipient = User::factory()->create([
            'notification_preferences' => null,
        ]);

        $this->actingAs($recipient);

        $event = app(NotificationService::class)->send(
            'TRANSACTION_CLOSED',
            collect([$recipient]),
            'Test message.',
            null,
            ['SYSTEM', 'EMAIL']
        );

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_event_id' => $event->id,
            'recipient_user_id' => $recipient->id,
            'channel' => 'SYSTEM',
        ]);

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_event_id' => $event->id,
            'recipient_user_id' => $recipient->id,
            'channel' => 'EMAIL',
        ]);
    }
}
