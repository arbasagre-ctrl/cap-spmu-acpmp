<?php

namespace Tests\Feature;

use App\Jobs\SendSmsNotification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmsNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /* Keep staged SMS deliveries pending until each test invokes the job. */
        Queue::fake();
    }

    public function test_actionable_borrower_event_stages_one_normalized_sms_delivery_for_a_duplicate_recipient(): void
    {
        $this->configureSms();
        $borrower = $this->smsEnabledBorrower(['mobile_no' => '0917 000 0000']);

        $event = app(NotificationService::class)->send(
            'REQUEST_APPROVED',
            collect([$borrower, $borrower]),
            'Detailed approval message that must not become the SMS body.',
            null,
            ['SYSTEM', 'EMAIL']
        );

        $this->assertDatabaseCount('notification_deliveries', 3);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_event_id' => $event->id,
            'recipient_user_id' => $borrower->id,
            'channel' => 'SMS',
            'address_snapshot' => '+639170000000',
            'delivery_status' => 'PENDING',
        ]);
    }

    public function test_sms_disabled_records_a_skipped_delivery_without_blocking_other_channels(): void
    {
        $this->configureSms(enabled: false);
        $borrower = $this->smsEnabledBorrower();

        $event = app(NotificationService::class)->send(
            'REQUEST_RETURNED_FOR_REVISION',
            collect([$borrower]),
            'Detailed revision reason.',
            null,
            ['SYSTEM']
        );

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_event_id' => $event->id,
            'channel' => 'SYSTEM',
            'delivery_status' => 'SENT',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_event_id' => $event->id,
            'channel' => 'SMS',
            'delivery_status' => 'SKIPPED',
        ]);
    }

    public function test_missing_sms_credentials_mobile_number_or_preference_records_skipped_delivery(): void
    {
        $borrower = $this->smsEnabledBorrower();

        $this->configureSms(provider: null, webhook: null);
        $missingCredentials = app(NotificationService::class)->send('REQUEST_REJECTED', collect([$borrower]), 'Detailed rejection.', null, ['SYSTEM']);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_event_id' => $missingCredentials->id,
            'channel' => 'SMS',
            'delivery_status' => 'SKIPPED',
        ]);

        $this->configureSms();
        $noMobile = $this->smsEnabledBorrower(['mobile_no' => null]);
        $noMobileEvent = app(NotificationService::class)->send('REQUEST_REJECTED', collect([$noMobile]), 'Detailed rejection.', null, ['SYSTEM']);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_event_id' => $noMobileEvent->id,
            'channel' => 'SMS',
            'delivery_status' => 'SKIPPED',
        ]);

        $optedOut = $this->smsEnabledBorrower([
            'notification_preferences' => ['system' => true, 'email' => true, 'sms' => false],
        ]);
        $optedOutEvent = app(NotificationService::class)->send('REQUEST_REJECTED', collect([$optedOut]), 'Detailed rejection.', null, ['SYSTEM']);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_event_id' => $optedOutEvent->id,
            'channel' => 'SMS',
            'delivery_status' => 'SKIPPED',
        ]);
    }

    public function test_provider_failure_is_recorded_without_throwing(): void
    {
        $this->configureSms();
        $borrower = $this->smsEnabledBorrower();
        $event = app(NotificationService::class)->send('REQUEST_APPROVED', collect([$borrower]), 'Detailed approval.', null, ['SYSTEM']);
        $delivery = $this->smsDelivery($event->id);

        Http::fake([
            'https://sms.example.test/*' => Http::response('Provider unavailable', 503),
        ]);

        $job = new SendSmsNotification(
            $delivery->id,
            'CSPC SPMU: Test message.',
            'REQUEST_APPROVED'
        );

        app()->call([$job, 'handle']);

        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $delivery->id,
            'delivery_status' => 'FAILED',
        ]);
        Http::assertSentCount(1);
    }

    public function test_semaphore_sends_its_required_form_payload_without_bearer_authentication(): void
    {
        $this->configureSms(
            provider: 'semaphore',
            webhook: 'https://api.semaphore.co/api/v4/messages'
        );
        $borrower = $this->smsEnabledBorrower(['mobile_no' => '0917 123 4567']);
        $event = app(NotificationService::class)->send(
            'REQUEST_APPROVED',
            collect([$borrower]),
            'Detailed approval.',
            null,
            ['SYSTEM']
        );
        $delivery = $this->smsDelivery($event->id);

        Http::fake([
            'https://api.semaphore.co/api/v4/messages' => Http::response(['message_id' => 'demo-1'], 200),
        ]);

        $job = new SendSmsNotification(
            $delivery->id,
            'CSPC SPMU: Test message.',
            'REQUEST_APPROVED'
        );

        app()->call([$job, 'handle']);

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.semaphore.co/api/v4/messages'
                && $request->isForm()
                && $request['apikey'] === 'test-token'
                && $request['number'] === '+639171234567'
                && $request['message'] === 'CSPC SPMU: Test message.'
                && $request['sendername'] === 'CSPC SPMU'
                && ! $request->hasHeader('Authorization');
        });
        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $delivery->id,
            'delivery_status' => 'SENT',
        ]);
    }

    public function test_only_final_accountability_resolution_stages_sms_while_ao_verification_does_not(): void
    {
        $this->configureSms();
        $borrower = $this->smsEnabledBorrower();

        $verification = app(NotificationService::class)->send(
            'PROPERTY_ACCOUNTABILITY_COMPLIANCE_VERIFIED',
            collect([$borrower]),
            'AO verification is awaiting final review.',
            null,
            ['SYSTEM']
        );
        $resolution = app(NotificationService::class)->send(
            'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
            collect([$borrower]),
            'Final Head/Admin resolution completed.',
            null,
            ['SYSTEM']
        );

        $this->assertDatabaseMissing('notification_deliveries', [
            'notification_event_id' => $verification->id,
            'channel' => 'SMS',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_event_id' => $resolution->id,
            'channel' => 'SMS',
            'delivery_status' => 'PENDING',
        ]);
    }

    public function test_pickup_and_return_action_events_stage_sms_but_internal_events_do_not(): void
    {
        $this->configureSms();
        $borrower = $this->smsEnabledBorrower();

        foreach (['REQUEST_CANCELLED', 'PICKUP_SCHEDULED', 'PICKUP_EXPIRED', 'BORROWING_OVERDUE', 'BORROWING_SUSPENSION_LIFTED'] as $eventCode) {
            $event = app(NotificationService::class)->send($eventCode, collect([$borrower]), 'Detailed workflow message.', null, ['SYSTEM']);
            $this->assertDatabaseHas('notification_deliveries', [
                'notification_event_id' => $event->id,
                'channel' => 'SMS',
                'delivery_status' => 'PENDING',
            ]);
        }

        $internal = app(NotificationService::class)->send('REQUEST_VERIFIED', collect([$borrower]), 'Internal workflow message.', null, ['SYSTEM']);
        $this->assertDatabaseMissing('notification_deliveries', [
            'notification_event_id' => $internal->id,
            'channel' => 'SMS',
        ]);
    }

    private function configureSms(bool $enabled = true, ?string $provider = 'demo-webhook', ?string $webhook = 'https://sms.example.test/send'): void
    {
        config()->set('services.sms.enabled', $enabled);
        config()->set('services.sms.provider', $provider);
        config()->set('services.sms.webhook_url', $webhook);
        config()->set('services.sms.token', 'test-token');
        config()->set('services.sms.sender_name', 'CSPC SPMU');
    }

    private function smsEnabledBorrower(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'mobile_no' => '09170000000',
            'notification_preferences' => ['system' => true, 'email' => true, 'sms' => true],
        ]);
    }

    private function smsDelivery(int $eventId): NotificationDelivery
    {
        return NotificationDelivery::query()
            ->where('notification_event_id', $eventId)
            ->where('channel', 'SMS')
            ->firstOrFail();
    }
}
