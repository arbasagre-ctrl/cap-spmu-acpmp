<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Jobs\SendSmsNotification;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\Sanction;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsNotificationChannel
{
    /**
     * Returns a deliberately short, non-sensitive SMS only for borrower
     * actions that need prompt attention. Null means the event stays in the
     * existing in-system/email channels only.
     */
    public function messageFor(string $eventCode, User $recipient, ?Model $source): ?string
    {
        $eventCode = strtoupper($eventCode);

        if ($eventCode === 'ACCOUNT_ACCESS_DISABLED') {
            return 'CSPC SPMU: Your account access has been disabled. Please contact ICTU for assistance.';
        }

        if (! $this->isBorrower($recipient)) {
            return null;
        }

        if ($eventCode === 'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED' && $source instanceof Incident) {
            return match (strtoupper((string) $source->status)) {
                'COMPLIANCE_RSLDDP_PENDING', 'RSLDDP_AWAITING_UPLOAD' =>
                    'CSPC SPMU: Your accountability requires an RSLDDP. Please check My Obligations.',
                'COMPLIANCE_REQUIRED' =>
                    'CSPC SPMU: An accountability case requires your action. Please check My Obligations.',
                default => null,
            };
        }

        if ($eventCode === 'EVIDENCE_REJECTED') {
            return $source instanceof GeneratedDocument
                && strtoupper((string) $source->document_type) === 'GATE_PASS'
                ? 'CSPC SPMU: Your Gate Pass requires correction. Please check My Requests.'
                : null;
        }

        if ($eventCode === 'ADMINISTRATIVE_SANCTION_RECORDED') {
            return $source instanceof Sanction
                && strtoupper((string) $source->sanction_code) === 'BORROWING_SUSPENSION'
                ? 'CSPC SPMU: A borrowing suspension is active on your account. Please check My Obligations.'
                : null;
        }

        return match ($eventCode) {
            'REQUEST_RETURNED_FOR_REVISION' =>
                'CSPC SPMU: Your borrowing request needs revision. Please check My Requests.',
            'REQUEST_APPROVED' =>
                'CSPC SPMU: Your borrowing request has been approved. Please check My Requests for the next step.',
            'REQUEST_REJECTED' =>
                'CSPC SPMU: Your borrowing request was not approved. Please check My Requests.',
            'REQUEST_CANCELLED' =>
                'CSPC SPMU: Your borrowing request has been cancelled. Please check My Requests.',
            'PICKUP_SCHEDULED' =>
                'CSPC SPMU: Your pickup schedule has been confirmed or updated. Please check My Requests for the date and time.',
            'PICKUP_EXPIRED' =>
                'CSPC SPMU: You missed your pickup schedule. Please reschedule or cancel your request.',
            'RETURN_DUE_TOMORROW', 'RETURN_DUE_TODAY' =>
                'CSPC SPMU: Your borrowed items are due soon. Please check My Requests.',
            'BORROWING_OVERDUE' =>
                'CSPC SPMU: Your borrowed item is overdue. Please return it and check My Obligations.',
            'ACCOUNTABILITY_OPENED', 'INCIDENT_RECORDED' =>
                'CSPC SPMU: An accountability case requires your action. Borrowing access is temporarily restricted. Please check My Obligations.',
            'ACCOUNTABILITY_BILLING_STATEMENT_ISSUED', 'LATE_RETURN_BILLING_STATEMENT_ISSUED' =>
                'CSPC SPMU: Payment is required for your accountability case. Please check My Obligations.',
            'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED' =>
                'CSPC SPMU: Your accountability case is resolved. Its borrowing restriction is lifted; you may submit a request if needed.',
            'BORROWING_SUSPENSION_LIFTED' =>
                'CSPC SPMU: Your borrowing suspension has ended. You may submit a request if needed.',
            default => null,
        };
    }

    /**
     * Stage one delivery per notification event/recipient. The database
     * constraint is the final protection against duplicate queue work.
     */
    public function stage(NotificationEvent $event, User $recipient, string $message): void
    {
        $address = $this->normalizedMobile($recipient->mobile_no);
        $deduplicationKey = 'sms:'.$event->id.':'.$recipient->id;
        try {
            $delivery = NotificationDelivery::query()->firstOrCreate(
                [
                    'notification_event_id' => $event->id,
                    'recipient_user_id' => $recipient->id,
                    'channel' => 'SMS',
                ],
                [
                    'sms_deduplication_key' => $deduplicationKey,
                    'address_snapshot' => $address ?: $recipient->mobile_no,
                    'attempt_no' => 0,
                    'provider' => $this->provider(),
                    'attempted_at' => now(),
                    'delivery_status' => 'PENDING',
                    'provider_response' => null,
                ]
            );
        } catch (QueryException $exception) {
            /* A concurrent sender won the unique delivery race. */
            Log::info('SPMU SMS delivery already staged concurrently', [
                'event' => $event->event_code,
                'recipient_user_id' => $recipient->id,
            ]);

            return;
        }

        if (! $delivery->wasRecentlyCreated) {
            return;
        }

        if ($skipReason = $this->skipReason($recipient, $address)) {
            $this->markSkipped($delivery, $skipReason);

            return;
        }

        try {
            SendSmsNotification::dispatch($delivery->id, $message, $event->event_code)->afterCommit();

            Log::info('SPMU SMS delivery queued', [
                'delivery_id' => $delivery->id,
                'event' => $event->event_code,
                'recipient_user_id' => $recipient->id,
            ]);
        } catch (Throwable $exception) {
            $delivery->update([
                'attempt_no' => 1,
                'attempted_at' => now(),
                'delivery_status' => 'FAILED',
                'provider_response' => 'SMS queue dispatch failed: '.mb_substr($exception->getMessage(), 0, 900),
            ]);

            Log::warning('SPMU SMS queue dispatch failed', [
                'delivery_id' => $delivery->id,
                'event' => $event->event_code,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @return array{status:string, provider:?string, response:string, address:?string} */
    public function send(User $recipient, string $message, string $eventCode): array
    {
        $address = $this->normalizedMobile($recipient->mobile_no);

        if ($skipReason = $this->skipReason($recipient, $address)) {
            return [
                'status' => 'SKIPPED',
                'provider' => $this->provider(),
                'response' => $skipReason,
                'address' => $address,
            ];
        }

        try {
            if (strtolower((string) $this->provider()) === 'semaphore') {
                $payload = [
                    'apikey' => (string) config('services.sms.token'),
                    'number' => $address,
                    'message' => $message,
                ];

                if (filled($sender = $this->senderName())) {
                    $payload['sendername'] = $sender;
                }

                $response = Http::timeout(10)
                    ->acceptJson()
                    ->asForm()
                    ->post((string) config('services.sms.webhook_url'), $payload);
            } else {
                $request = Http::timeout(10)->acceptJson()->withToken((string) config('services.sms.token'));
                $payload = [
                    'to' => $address,
                    'message' => $message,
                    'event_code' => $eventCode,
                ];

                if (filled($sender = $this->senderName())) {
                    $payload['sender'] = $sender;
                }

                $response = $request->post((string) config('services.sms.webhook_url'), $payload);
            }

            return [
                'status' => $response->successful() ? 'SENT' : 'FAILED',
                'provider' => $this->provider(),
                'response' => 'HTTP '.$response->status(),
                'address' => $address,
            ];
        } catch (Throwable $exception) {
            Log::warning('SPMU SMS delivery failed', [
                'event' => $eventCode,
                'recipient_user_id' => $recipient->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => 'FAILED',
                'provider' => $this->provider(),
                'response' => mb_substr($exception->getMessage(), 0, 900),
                'address' => $address,
            ];
        }
    }

    private function markSkipped(NotificationDelivery $delivery, string $reason): void
    {
        $delivery->update([
            'attempt_no' => 0,
            'attempted_at' => now(),
            'delivery_status' => 'SKIPPED',
            'provider_response' => $reason,
        ]);

        Log::info('SPMU SMS delivery skipped', [
            'delivery_id' => $delivery->id,
            'reason' => $reason,
        ]);
    }

    private function skipReason(User $recipient, ?string $address): ?string
    {
        if (! $this->enabled()) {
            return 'SMS is disabled by system configuration.';
        }

        if (blank($this->provider()) || blank(config('services.sms.webhook_url')) || blank(config('services.sms.token'))) {
            return 'SMS provider credentials are not configured.';
        }

        if (! $address) {
            return 'Recipient mobile number is missing or invalid.';
        }

        if (! (bool) data_get($recipient->notification_preferences, 'sms', false)) {
            return 'Recipient has disabled SMS notifications.';
        }

        return null;
    }

    private function enabled(): bool
    {
        $configured = SystemSetting::value('sms_enabled');

        return $configured === null
            ? (bool) config('services.sms.enabled', false)
            : filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }

    private function provider(): ?string
    {
        $provider = SystemSetting::value('sms_provider') ?: config('services.sms.provider');

        return filled($provider) ? trim((string) $provider) : null;
    }

    private function senderName(): ?string
    {
        $sender = SystemSetting::value('sms_sender_name') ?: config('services.sms.sender_name');

        return filled($sender) ? trim((string) $sender) : null;
    }

    private function normalizedMobile(?string $mobile): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $mobile) ?: '';

        if (preg_match('/^09\d{9}$/', $digits)) {
            return '+63'.substr($digits, 1);
        }

        if (preg_match('/^639\d{9}$/', $digits)) {
            return '+'.$digits;
        }

        return null;
    }

    private function isBorrower(User $recipient): bool
    {
        return $recipient->access_classification === AccessClassification::BorrowerOnly;
    }
}
