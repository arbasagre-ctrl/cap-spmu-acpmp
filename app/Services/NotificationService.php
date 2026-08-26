<?php

namespace App\Services;

use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\LaundryJob;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationService
{
    /** @param iterable<User> $recipients */
    public function send(
        string $eventCode,
        iterable $recipients,
        string $message,
        ?Model $source = null,
        array $channels = ['SYSTEM', 'EMAIL', 'SMS']
    ): NotificationEvent {
        $event = NotificationEvent::query()->create([
            'event_code' => $eventCode,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'created_by_user_id' => auth()->id(),
            'payload_snapshot_json' => [
                'message' => $message,
            ],
            'occurred_at' => now(),
        ]);

        foreach ($recipients as $recipient) {
            foreach ($channels as $channel) {
                $address = match ($channel) {
                    'EMAIL' => $recipient->email,
                    'SMS' => $recipient->mobile_no,
                    default => (string) $recipient->id,
                };

                [$status, $provider, $providerResponse] = $this->deliver(
                    $channel,
                    $address,
                    $message,
                    $eventCode,
                    $recipient,
                    $source
                );

                NotificationDelivery::query()->create([
                    'notification_event_id' => $event->id,
                    'recipient_user_id' => $recipient->id,
                    'channel' => $channel,
                    'address_snapshot' => $address,
                    'attempt_no' => 1,
                    'provider' => $provider,
                    'attempted_at' => now(),
                    'delivery_status' => $status,
                    'provider_response' => $providerResponse,
                ]);
            }
        }

        Log::info("SPMU notification {$eventCode}: {$message}");

        return $event;
    }

    /**
     * @return array{string, ?string, string}
     */
    private function deliver(
        string $channel,
        ?string $address,
        string $message,
        string $eventCode,
        User $recipient,
        ?Model $source
    ): array {
        if ($channel === 'SYSTEM') {
            return [
                'SENT',
                'system',
                'Stored in the authenticated in-system notification record.',
            ];
        }

        if (blank($address)) {
            return [
                'FAILED',
                strtolower($channel),
                'Recipient address is not configured.',
            ];
        }

        if ($channel === 'EMAIL') {
            try {
                $email = $this->buildEmail(
                    $eventCode,
                    $message,
                    $recipient,
                    $source
                );

                Mail::html($email['html'], function (Message $mail) use ($address, $email): void {
                    $mail->to($address)->subject($email['subject']);
                });

                return [
                    'SENT',
                    (string) config('mail.default'),
                    'Accepted by the configured Laravel mail transport using the professional institutional email template.',
                ];
            } catch (Throwable $exception) {
                Log::warning('SPMU email delivery failed', [
                    'event' => $eventCode,
                    'error' => $exception->getMessage(),
                ]);

                return [
                    'FAILED',
                    (string) config('mail.default'),
                    mb_substr($exception->getMessage(), 0, 1000),
                ];
            }
        }

        $provider = SystemSetting::value('sms_provider') ?: config('services.sms.provider');
        $url = config('services.sms.webhook_url');

        if (blank($provider) || blank($url)) {
            return [
                'FAILED',
                $provider,
                'SMS provider/webhook is not configured; system and email delivery remain available.',
            ];
        }

        try {
            $request = Http::timeout(10)->acceptJson();

            if (filled(config('services.sms.token'))) {
                $request = $request->withToken((string) config('services.sms.token'));
            }

            $response = $request->post($url, [
                'to' => $address,
                'message' => $message,
                'event_code' => $eventCode,
            ]);

            return [
                $response->successful() ? 'SENT' : 'FAILED',
                (string) $provider,
                'HTTP '.$response->status().' '.mb_substr($response->body(), 0, 900),
            ];
        } catch (Throwable $exception) {
            Log::warning('SPMU SMS delivery failed', [
                'event' => $eventCode,
                'error' => $exception->getMessage(),
            ]);

            return [
                'FAILED',
                (string) $provider,
                mb_substr($exception->getMessage(), 0, 1000),
            ];
        }
    }

    /**
     * @return array{
     *     subject:string,
     *     html:string
     * }
     */
    private function buildEmail(
        string $eventCode,
        string $message,
        User $recipient,
        ?Model $source
    ): array {
        $profile = $this->eventProfile($eventCode);
        $data = $this->sourceData($eventCode, $recipient, $source);

        $reference = $data['reference'];

        $subject = '[SPMU-ACPMP] '.$profile['title'];
        if ($reference) {
            $subject .= ' | '.$reference;
        }

        $recipientName = e($recipient->full_name ?: 'Recipient');
        $title = e($profile['title']);
        $summary = nl2br(e(
            $this->emailSummary(
                $eventCode,
                $message,
                $recipient,
                $source
            )
        ));
        $referenceLine = $reference
            ? '<p style="margin:8px 0 0;font-size:13px;line-height:1.6;color:#486581;"><strong>Reference:</strong> '.e($reference).'</p>'
            : '';

        $detailsRows = '';
        foreach ($data['details'] as $label => $value) {
            $detailsRows .=
                '<tr>'
                .'<td style="padding:8px 0 8px 0;width:220px;vertical-align:top;font-size:14px;line-height:1.6;color:#486581;"><strong>'.e($label).'</strong></td>'
                .'<td style="padding:8px 0 8px 14px;vertical-align:top;font-size:14px;line-height:1.6;color:#243b53;">'.e($value).'</td>'
                .'</tr>';
        }

        $detailsBlock = $detailsRows === ''
            ? ''
            : '
                <h2 style="margin:28px 0 10px;font-size:15px;line-height:1.4;color:#102a43;font-weight:700;">Request Information</h2>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                    '.$detailsRows.'
                </table>
            ';

        $itemsRows = '';
        foreach ($data['items'] as $item) {
            $itemsRows .=
                '<tr>'
                .'<td style="padding:10px 8px 10px 0;border-bottom:1px solid #e6edf5;font-size:14px;line-height:1.55;color:#243b53;">'.e($item['name']).'</td>'
                .'<td style="padding:10px 8px;text-align:right;border-bottom:1px solid #e6edf5;font-size:14px;line-height:1.55;color:#243b53;">'.e($item['quantity']).'</td>'
                .'<td style="padding:10px 8px;border-bottom:1px solid #e6edf5;font-size:14px;line-height:1.55;color:#486581;">'.e($item['unit'] ?: '—').'</td>'
                .'<td style="padding:10px 0 10px 8px;border-bottom:1px solid #e6edf5;font-size:14px;line-height:1.55;color:#486581;">'.e($item['context'] ?: '—').'</td>'
                .'</tr>';
        }

        $itemsBlock = $itemsRows === ''
            ? ''
            : '
                <h2 style="margin:28px 0 10px;font-size:15px;line-height:1.4;color:#102a43;font-weight:700;">Relevant Items</h2>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th align="left" style="padding:9px 8px 9px 0;border-bottom:2px solid #d9e2ec;font-size:13px;color:#334e68;">Item</th>
                            <th align="right" style="padding:9px 8px;border-bottom:2px solid #d9e2ec;font-size:13px;color:#334e68;">'.e($data['quantityLabel']).'</th>
                            <th align="left" style="padding:9px 8px;border-bottom:2px solid #d9e2ec;font-size:13px;color:#334e68;">Unit</th>
                            <th align="left" style="padding:9px 0 9px 8px;border-bottom:2px solid #d9e2ec;font-size:13px;color:#334e68;">Use / Context</th>
                        </tr>
                    </thead>
                    <tbody>
                        '.$itemsRows.'
                    </tbody>
                </table>
            ';

        $nextBlock = $profile['next']
            ? '
                <p style="margin:28px 0 0;font-size:14px;line-height:1.75;color:#243b53;">
                    <strong>What happens next?</strong><br>'
                    .e($profile['next']).'
                </p>
            '
            : '';

        $actionBlock = '';
        if ($data['actionUrl'] && $data['actionLabel']) {
            $actionBlock = '
                <p style="margin:28px 0 0;font-size:14px;line-height:1.75;color:#243b53;">
                    You may review the transaction here:
                    <a href="'.e($data['actionUrl']).'" style="color:#1769e0;text-decoration:underline;font-weight:700;">'.e($data['actionLabel']).'</a>
                </p>
            ';
        }

        $footerTimestamp = now()
            ->timezone(config('app.timezone') ?: 'Asia/Manila')
            ->format('d F Y, g:i A');

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{$this->escape($subject)}</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#172033;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff;">
        <tr>
            <td align="center" style="padding:24px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:760px;border-collapse:collapse;">
                    <tr>
                        <td style="padding:0 0 18px 0;border-bottom:3px solid #0b2854;">
                            <div style="font-size:28px;line-height:1.15;font-weight:800;color:#0b2854;">SPMU-ACPMP</div>
                            <div style="margin-top:6px;font-size:14px;line-height:1.55;font-weight:700;color:#243b53;">
                                Supply and Property Management Unit
                            </div>
                            <div style="font-size:14px;line-height:1.55;color:#486581;">
                                Camarines Sur Polytechnic Colleges
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px 0 0 0;">
                            <div style="font-size:13px;line-height:1.5;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#486581;">
                                Official Notification
                            </div>

                            <h1 style="margin:10px 0 0;font-size:31px;line-height:1.25;font-weight:800;color:#102a43;">
                                {$title}
                            </h1>

                            {$referenceLine}

                            <p style="margin:28px 0 0;font-size:16px;line-height:1.8;color:#243b53;">
                                Dear {$recipientName},
                            </p>

                            <p style="margin:10px 0 0;font-size:16px;line-height:1.85;color:#243b53;">
                                {$summary}
                            </p>

                            {$detailsBlock}

                            {$itemsBlock}

                            {$nextBlock}

                            {$actionBlock}

                            <p style="margin:34px 0 0;font-size:12px;line-height:1.65;color:#829ab1;">
                                Notification recorded {$this->escape($footerTimestamp)} ({$this->escape(config('app.timezone') ?: 'Asia/Manila')}).
                            </p>

                            <p style="margin:28px 0 0;padding-top:18px;border-top:1px solid #d9e2ec;font-size:12px;line-height:1.75;color:#627d98;">
                                This is an automated official transaction notification from SPMU-ACPMP.
                                Please do not reply directly to this message.
                                For questions regarding the transaction, coordinate with the Supply and Property Management Unit through official institutional channels.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        return [
            'subject' => $subject,
            'html' => $html,
        ];
    }

    /**
     * @return array{
     *     title:string,
     *     next:?string
     * }
     */
    private function eventProfile(string $eventCode): array
    {
        $title = match ($eventCode) {
            'REQUEST_SUBMITTED' => 'Borrowing Request Submitted',
            'REQUEST_APPROVED' => 'Borrowing Request Approved',
            'REQUEST_RETURNED_FOR_REVISION' => 'Action Required: Request Revision',
            'REQUEST_REJECTED' => 'Borrowing Request Not Approved',
            'REQUEST_CANCELLED' => 'Borrowing Request Cancelled',
            'PICKUP_SCHEDULED' => 'Pickup Schedule Confirmed',
            'PICKUP_EXPIRED' => 'Pickup Reservation Expired',
            'ITEMS_RELEASED' => 'Borrowed Items Released',
            'LINEN_FOR_LAUNDRY' => 'Laundry Processing Required',
            'LAUNDRY_USED_LINEN_RECEIVED' => 'Used Linen Received by Laundry',
            'LAUNDRY_READY_FOR_PICKUP', 'LAUNDRY_PROCESSING_COMPLETED' => 'Laundry Processing Completed',
            'LAUNDRY_FORM_PENDING_SPMU_VERIFICATION' => 'Laundry Form Requires SPMU Review',
            'RETURN_RECORDED' => 'Return Recorded',
            'RETURN_INSPECTED' => 'Return Inspected',
            'TRANSACTION_CLOSED' => 'Borrowing Transaction Completed',
            'OVERDUE', 'RETURN_OVERDUE' => 'Return Overdue',
            'EVIDENCE_VERIFIED' => 'Supporting Evidence Verified',
            'EVIDENCE_REJECTED' => 'Action Required: Replace Supporting Evidence',
            default => $this->humanize($eventCode),
        };

        $next = match ($eventCode) {
            'REQUEST_SUBMITTED' => 'SPMU will review the request and scanned supporting documents. Submission does not reserve inventory until the request is verified and approved.',
            'REQUEST_APPROVED' => 'The approved quantities are now reserved. Please wait for the separate pickup schedule notification before going to SPMU for release.',
            'REQUEST_RETURNED_FOR_REVISION' => 'Open the request, review the SPMU remarks, correct the required information or documents, and resubmit the updated request.',
            'REQUEST_REJECTED' => 'No inventory reservation was created. Please review the recorded reason and coordinate with SPMU if clarification is needed.',
            'PICKUP_SCHEDULED' => 'Proceed to SPMU within the confirmed pickup window and bring the required physical documents. Property is issued only after all release requirements are completed.',
            'PICKUP_EXPIRED' => 'Coordinate with SPMU if the borrowing requirement is still active. An expired pickup reservation is not treated as a completed issuance.',
            'ITEMS_RELEASED' => 'Please keep the issued property in proper custody and return all items on or before the expected return date. Follow applicable Gate Pass or Laundry requirements when relevant.',
            'LINEN_FOR_LAUNDRY' => 'Laundry personnel should await the borrower’s used linen and physical Laundry Form after the activity, complete the laundry process, and bring the cleaned linen and the same physical form directly to SPMU for final acceptance.',
            'LAUNDRY_USED_LINEN_RECEIVED' => 'Laundry processing is in progress. After cleaning, the Laundry Worker should bring the cleaned linen and the same physical Laundry Form directly to SPMU for final acceptance and signature.',
            'LAUNDRY_READY_FOR_PICKUP', 'LAUNDRY_PROCESSING_COMPLETED' => 'The cleaned linen and the physical Laundry Form should be brought directly to SPMU for final inspection and form completion.',
            'RETURN_RECORDED' => 'SPMU has recorded the returned property. Any remaining obligations, discrepancies, or follow-up processing will continue through the appropriate workflow.',
            'RETURN_INSPECTED' => 'SPMU has completed the inspection of the returned property. If no additional obligations remain, the transaction may proceed to completion.',
            'TRANSACTION_CLOSED' => 'All required obligations for this borrowing transaction have been completed and the transaction has been closed.',
            'OVERDUE', 'RETURN_OVERDUE' => 'Please return the outstanding property or coordinate with SPMU immediately. Any accountability action follows approved institutional policy and authorized SPMU action.',
            'EVIDENCE_REJECTED' => 'Please review the recorded reason and submit the correct replacement supporting evidence through the applicable transaction workflow.',
            default => null,
        };

        return [
            'title' => $title,
            'next' => $next,
        ];
    }

    /**
     * @return array{
     *     reference:?string,
     *     details:array<string,string>,
     *     items:array<int,array{
     *         name:string,
     *         quantity:string,
     *         unit:string,
     *         context:string
     *     }>,
     *     quantityLabel:string,
     *     actionLabel:?string,
     *     actionUrl:?string
     * }
     */
    private function sourceData(
        string $eventCode,
        User $recipient,
        ?Model $source
    ): array {
        $data = [
            'reference' => null,
            'details' => [],
            'items' => [],
            'quantityLabel' => 'Quantity',
            'actionLabel' => null,
            'actionUrl' => null,
        ];

        if (! $source) {
            return $data;
        }

        if ($source instanceof BorrowingRequest) {
            $source->loadMissing([
                'borrower.organizationalUnit',
                'accountableUnit',
                'currentVersion.items.inventoryItem.unit',
            ]);

            $version = $source->currentVersion;

            $data['reference'] = (string) $source->request_no;
            $data['details']['Request Number'] = (string) $source->request_no;

            if ($recipient->id !== $source->borrower_user_id && $source->borrower?->full_name) {
                $data['details']['Borrower'] = (string) $source->borrower->full_name;
            }

            if ($version?->purpose_event) {
                $data['details']['Event Details'] = (string) $version->purpose_event;
            }

            if ($version?->location) {
                $data['details']['Location'] = (string) $version->location;
            }

            $schedule = $version?->getAttribute('schedule_date') ?: $version?->getAttribute('needed_from');
            $return = $version?->getAttribute('return_date') ?: $version?->getAttribute('return_due_at');

            if ($schedule) {
                $data['details']['Schedule Date'] = $this->date($schedule);
            }

            if ($return) {
                $data['details']['Expected Return Date'] = $this->date($return);
            }

            $unit = $source->accountableUnit?->unit_name ?: $source->borrower?->organizationalUnit?->unit_name;
            if ($unit) {
                $data['details']['Office / Department'] = (string) $unit;
            }

            if ($version?->represents_student_activity) {
                $data['details']['Student Activity'] = 'Yes';
            }

            if ($eventCode === 'REQUEST_APPROVED') {
                $data['details']['Inventory Status'] = 'Approved quantities reserved';
            }

            foreach ($version?->items ?? collect() as $item) {
                $useApproved = $eventCode === 'REQUEST_APPROVED' && $item->approved_quantity !== null;
                $quantity = $useApproved ? $item->approved_quantity : $item->requested_quantity;

                $data['items'][] = [
                    'name' => (string) ($item->description_snapshot ?: $item->inventoryItem?->unique_description ?: 'Item'),
                    'quantity' => $this->qty($quantity),
                    'unit' => (string) ($item->unit_snapshot ?: ''),
                    'context' => $this->humanize((string) ($item->use_location ?: '')),
                ];
            }

            $data['quantityLabel'] = $eventCode === 'REQUEST_APPROVED'
                ? 'Approved Quantity'
                : 'Requested Quantity';

            $data['actionLabel'] = $eventCode === 'REQUEST_RETURNED_FOR_REVISION'
                ? 'Review and revise the request'
                : 'View the request';

            $data['actionUrl'] = route('requests.show', $source);

            return $data;
        }

        if ($source instanceof CustodyTransaction) {
            $source->loadMissing([
                'borrower.organizationalUnit',
                'request.currentVersion',
                'lines.requestItem.inventoryItem.unit',
                'laundryJob',
            ]);

            $version = $source->request?->currentVersion;

            $data['reference'] = (string) $source->custody_no;
            $data['details']['Custody Number'] = (string) $source->custody_no;

            if ($source->request?->request_no) {
                $data['details']['Request Number'] = (string) $source->request->request_no;
            }

            if ($recipient->id !== $source->borrower_user_id && $source->borrower?->full_name) {
                $data['details']['Borrower'] = (string) $source->borrower->full_name;
            }

            if ($version?->purpose_event) {
                $data['details']['Event Details'] = (string) $version->purpose_event;
            }

            if ($version?->location) {
                $data['details']['Location'] = (string) $version->location;
            }

            $schedule = $version?->getAttribute('schedule_date') ?: $version?->getAttribute('needed_from');
            $return = $version?->getAttribute('return_date')
                ?: $version?->getAttribute('return_due_at')
                ?: $source->due_at;

            if ($schedule) {
                $data['details']['Schedule Date'] = $this->date($schedule);
            }

            if ($return) {
                $data['details']['Expected Return Date'] = $this->date($return);
            }

            if ($eventCode === 'PICKUP_SCHEDULED' && $source->scheduled_release_at) {
                $data['details']['Pickup Date & Time'] = $this->dateTime($source->scheduled_release_at);
            }

            if ($eventCode === 'PICKUP_SCHEDULED' && $source->pickup_expires_at) {
                $data['details']['Claim Until'] = $this->dateTime($source->pickup_expires_at);
            }

            if ($eventCode === 'ITEMS_RELEASED' && $source->released_at) {
                $data['details']['Released At'] = $this->dateTime($source->released_at);
            }

            $data['quantityLabel'] = match ($eventCode) {
                'PICKUP_SCHEDULED' => 'Approved Quantity',
                'ITEMS_RELEASED', 'LINEN_FOR_LAUNDRY' => 'Issued Quantity',
                'RETURN_RECORDED', 'RETURN_INSPECTED', 'OVERDUE', 'RETURN_OVERDUE', 'TRANSACTION_CLOSED' => 'Outstanding Quantity',
                default => 'Quantity',
            };

            foreach ($source->lines as $line) {
                $requestItem = $line->requestItem;
                $inventoryItem = $requestItem?->inventoryItem;

                if ($eventCode === 'LINEN_FOR_LAUNDRY' && ! $inventoryItem?->laundry_required) {
                    continue;
                }

                $outstanding = max(
                    0,
                    (float) ($line->actual_released_quantity ?? 0) - (float) ($line->returned_quantity ?? 0)
                );

                $quantity = match ($eventCode) {
                    'PICKUP_SCHEDULED' => $line->approved_quantity,
                    'ITEMS_RELEASED', 'LINEN_FOR_LAUNDRY' => $line->actual_released_quantity,
                    'RETURN_RECORDED', 'RETURN_INSPECTED', 'OVERDUE', 'RETURN_OVERDUE', 'TRANSACTION_CLOSED' => $outstanding,
                    default => $line->actual_released_quantity ?: $line->approved_quantity,
                };

                if (
                    (float) $quantity <= 0
                    && in_array(
                        $eventCode,
                        ['ITEMS_RELEASED', 'LINEN_FOR_LAUNDRY', 'RETURN_RECORDED', 'RETURN_INSPECTED', 'TRANSACTION_CLOSED'],
                        true
                    )
                ) {
                    continue;
                }

                $data['items'][] = [
                    'name' => (string) ($requestItem?->description_snapshot ?: $inventoryItem?->unique_description ?: 'Item'),
                    'quantity' => $this->qty($quantity),
                    'unit' => (string) ($requestItem?->unit_snapshot ?: ''),
                    'context' => $this->humanize((string) ($requestItem?->use_location ?: '')),
                ];
            }

            if ($eventCode === 'LINEN_FOR_LAUNDRY' && $source->laundryJob) {
                $data['actionLabel'] = 'Open the laundry request';
                $data['actionUrl'] = route('laundry.show', $source->laundryJob);
            } else {
                $data['actionLabel'] = 'View the borrowing transaction';
                $data['actionUrl'] = route('custody.show', $source);
            }

            return $data;
        }

        if ($source instanceof LaundryJob) {
            $source->loadMissing([
                'custody.borrower',
                'custody.request.currentVersion',
                'lines.custodyLine.requestItem',
            ]);

            $custody = $source->custody;

            $data['reference'] = $custody?->custody_no;

            if ($custody?->custody_no) {
                $data['details']['Custody Number'] = (string) $custody->custody_no;
            }

            if ($custody?->request?->request_no) {
                $data['details']['Request Number'] = (string) $custody->request->request_no;
            }

            if ($custody?->borrower?->full_name) {
                $data['details']['Borrower'] = (string) $custody->borrower->full_name;
            }

            if ($custody?->request?->currentVersion?->purpose_event) {
                $data['details']['Event Details'] = (string) $custody->request->currentVersion->purpose_event;
            }

            if ($source->status) {
                $data['details']['Laundry Status'] = $this->humanize((string) $source->status);
            }

            if ($source->worker_received_at) {
                $data['details']['Received by Laundry'] = $this->dateTime($source->worker_received_at);
            }

            if ($source->worker_completed_at) {
                $data['details']['Laundry Completed'] = $this->dateTime($source->worker_completed_at);
            }

            foreach ($source->lines as $line) {
                $requestItem = $line->custodyLine?->requestItem;
                $quantity = $line->completed_quantity ?? $line->received_quantity ?? $line->issued_quantity;

                $data['items'][] = [
                    'name' => (string) ($requestItem?->description_snapshot ?: 'Linen item'),
                    'quantity' => $this->qty($quantity),
                    'unit' => (string) ($requestItem?->unit_snapshot ?: ''),
                    'context' => 'Laundry',
                ];
            }

            $data['quantityLabel'] = 'Laundry Quantity';

            $isBorrower = $custody && (int) $recipient->id === (int) $custody->borrower_user_id;

            $data['actionLabel'] = $isBorrower
                ? 'View the borrowing transaction'
                : 'View the laundry request';

            $data['actionUrl'] = $isBorrower && $custody
                ? route('custody.show', $custody)
                : route('laundry.show', $source);

            return $data;
        }

        foreach (['request_no', 'custody_no', 'incident_no', 'billing_no', 'statement_no'] as $field) {
            if ($source->getAttribute($field)) {
                $data['reference'] = (string) $source->getAttribute($field);
                $data['details']['Reference'] = $data['reference'];
                break;
            }
        }

        if ($source->getAttribute('status')) {
            $data['details']['Status'] = $this->humanize((string) $source->getAttribute('status'));
        }

        return $data;
    }

    /**
     * Email-only institutional wording.
     *
     * Existing SYSTEM and SMS messages remain concise and unchanged.
     */
    private function emailSummary(
        string $eventCode,
        string $fallbackMessage,
        User $recipient,
        ?Model $source
    ): string {
        $isBorrower = false;

        if ($source instanceof BorrowingRequest) {
            $isBorrower =
                (int) $recipient->id
                ===
                (int) $source->borrower_user_id;
        } elseif ($source instanceof CustodyTransaction) {
            $isBorrower =
                (int) $recipient->id
                ===
                (int) $source->borrower_user_id;
        } elseif ($source instanceof LaundryJob) {
            $isBorrower =
                $source->custody
                && (int) $recipient->id
                    ===
                    (int) $source->custody->borrower_user_id;
        }

        $reason =
            $this->extractNotificationReason(
                $fallbackMessage
            );

        return match ($eventCode) {
            /*
             * -----------------------------------------------------
             * BORROWING REQUEST
             * -----------------------------------------------------
             */

            'REQUEST_SUBMITTED' =>
                $isBorrower
                    ? 'Your borrowing request and the required scanned documents have been submitted to the Supply and Property Management Unit for verification. No inventory has been reserved at this stage. Reservation occurs only after SPMU verifies and approves the request.'
                    : 'A borrowing request and its required scanned documents have been submitted for SPMU verification. No inventory has been reserved at this stage.',

            'REQUEST_APPROVED' =>
                $isBorrower
                    ? 'Your borrowing request has been reviewed and approved by the Supply and Property Management Unit. The approved quantities shown below are now reserved for the approved borrowing period.'
                    : 'The borrowing request has been reviewed and approved by SPMU. The approved quantities shown below are now reserved for the approved borrowing period.',

            'REQUEST_RETURNED_FOR_REVISION' =>
                'SPMU has returned your borrowing request for revision. Please review the required corrections, update the request or supporting documents as necessary, and resubmit the revised request for verification.'
                .$this->reasonParagraph($reason),

            'REQUEST_REJECTED' =>
                'SPMU has completed its review and the borrowing request was not approved. No inventory reservation has been created for this request.'
                .$this->reasonParagraph($reason),

            'REQUEST_CANCELLED' =>
                'This borrowing request has been cancelled. Any unreleased inventory allocation associated with the request is no longer being held for release.',

            /*
             * -----------------------------------------------------
             * PICKUP / RELEASE
             * -----------------------------------------------------
             */

            'PICKUP_SCHEDULED' =>
                $isBorrower
                    ? 'SPMU has confirmed the pickup schedule for your approved borrowing request. Please review the pickup information below and proceed to the Supply and Property Management Unit within the confirmed pickup window.'
                    : 'A pickup schedule has been confirmed for the approved borrowing transaction. The borrower has been provided with the applicable pickup information.',

            'PICKUP_EXPIRED' =>
                $isBorrower
                    ? 'The confirmed pickup window for this borrowing transaction has expired without completion of physical issuance. Please coordinate with SPMU if the borrowing requirement is still active.'
                    : 'The confirmed pickup window expired before physical issuance was completed. The transaction remains subject to the appropriate SPMU follow-up.',

            'ITEMS_RELEASED' =>
                $isBorrower
                    ? 'SPMU has physically released the listed property to you. The quantities shown below reflect the actual quantities issued and are now under your custody until they are physically returned and accepted by SPMU.'
                    : 'The listed property has been physically released to the borrower. The quantities shown below reflect the actual quantities issued under this custody transaction.',

            /*
             * -----------------------------------------------------
             * RETURN
             * -----------------------------------------------------
             */

            'RETURN_RECORDED' =>
                $this->returnRecordedSummary(
                    $source
                ),

            'RETURN_INSPECTED' =>
                $this->returnInspectedSummary(
                    $source
                ),

            'TRANSACTION_CLOSED' =>
                'All required return and post-return obligations for this borrowing transaction have been completed. The transaction is now officially closed in SPMU-ACPMP.',

            /*
             * -----------------------------------------------------
             * OVERDUE
             * -----------------------------------------------------
             */

            'OVERDUE',
            'RETURN_OVERDUE' =>
                $isBorrower
                    ? 'Our records show that one or more issued items remain outstanding beyond the expected return date. Please return the outstanding property or coordinate with SPMU as soon as possible. Any accountability action will follow approved institutional policy and authorized SPMU action.'
                    : 'One or more issued items under this custody transaction remain outstanding beyond the expected return date. Follow-up should proceed in accordance with approved SPMU policy.',

            /*
             * -----------------------------------------------------
             * LAUNDRY
             * -----------------------------------------------------
             */

            'LINEN_FOR_LAUNDRY' =>
                'A borrowing transaction containing laundry-required linen has been physically released. The listed linen items are expected for Laundry processing after the activity. Receive the used linen together with the physical Laundry Form and process the linen according to the approved Laundry workflow.',

            'LAUNDRY_USED_LINEN_RECEIVED' =>
                $isBorrower
                    ? 'Laundry personnel have confirmed receipt of the used linen associated with your borrowing transaction. The linen is now undergoing Laundry processing.'
                    : 'The used linen associated with this transaction has been physically received by Laundry personnel and is now undergoing Laundry processing.',

            'LAUNDRY_PROCESSING_COMPLETED',
            'LAUNDRY_READY_FOR_PICKUP' =>
                'Laundry processing has been completed for the listed linen items. The Laundry Worker must bring the cleaned linen and the same physical Laundry Form directly to SPMU for final quantity and condition inspection.',

            'LAUNDRY_FORM_PENDING_SPMU_VERIFICATION' =>
                'The completed Laundry Form and related linen transaction are ready for SPMU review. Final settlement remains pending until the required physical acceptance and document verification are completed.',

            'LAUNDRY_FORM_VERIFIED',
            'LAUNDRY_COMPLETED' =>
                'SPMU has completed the required Laundry Form verification and final linen processing requirements for this transaction.',

            /*
             * -----------------------------------------------------
             * SUPPORTING EVIDENCE
             * -----------------------------------------------------
             */

            'EVIDENCE_VERIFIED' =>
                'The supporting evidence submitted for this transaction has been reviewed and verified by SPMU.',

            'EVIDENCE_REJECTED' =>
                'The submitted supporting evidence could not be accepted. Please review the recorded reason and provide the correct replacement evidence through the applicable transaction workflow.'
                .$this->reasonParagraph($reason),

            /*
             * -----------------------------------------------------
             * INCIDENT / ACCOUNTABILITY
             * -----------------------------------------------------
             */

            'INCIDENT_RECORDED',
            'ACCOUNTABILITY_OPENED' =>
                'A property accountability concern has been recorded for this borrowing transaction. The affected property and relevant findings are documented in the transaction record. Further action will follow the applicable institutional and SPMU policy.',

            'ACCOUNTABILITY_RESOLVED' =>
                'The recorded property accountability concern for this transaction has been reviewed and resolved in accordance with the applicable SPMU process.',

            /*
             * -----------------------------------------------------
             * PAYMENT / SETTLEMENT EVIDENCE
             * -----------------------------------------------------
             */

            'PAYMENT_RECORDED',
            'RECEIPT_RECORDED' =>
                'SPMU has recorded the submitted official payment or settlement evidence associated with this accountability transaction. Final settlement remains subject to the required verification.',

            'PAYMENT_VERIFIED',
            'RECEIPT_VERIFIED' =>
                'The submitted official payment or settlement evidence has been verified and recorded as part of the accountability resolution process.',

            /*
             * -----------------------------------------------------
             * GENERIC FALLBACK
             * -----------------------------------------------------
             */

            default =>
                $this->cleanFallbackEmailMessage(
                    $fallbackMessage
                ),
        };
    }

    /**
     * Professional wording for a recorded physical return.
     */
    private function returnRecordedSummary(
        ?Model $source
    ): string {
        if (
            $source instanceof CustodyTransaction
            && strtoupper((string) $source->status)
                === 'CLOSED'
        ) {
            return 'SPMU has recorded and accepted the returned property for this borrowing transaction. All issued items have been accounted for, and the custody transaction is now closed.';
        }

        return 'SPMU has recorded the returned property and the results of the physical return inspection. Any remaining items, Laundry processing, accountability concerns, or other unresolved obligations will remain open until the applicable requirements are completed.';
    }

    /**
     * Professional wording for physical inspection.
     */
    private function returnInspectedSummary(
        ?Model $source
    ): string {
        if (
            $source instanceof CustodyTransaction
            && strtoupper((string) $source->status)
                === 'CLOSED'
        ) {
            return 'SPMU has completed the physical inspection of the returned property. All items issued under this borrowing transaction have been accounted for, and the custody record is now closed.';
        }

        return 'SPMU has completed the physical inspection of the returned property and recorded the verified quantities and conditions. Any remaining property or post-return obligation will continue through the applicable workflow until resolved.';
    }

    /**
     * Extract an existing Reason: value from the concise operational
     * message so reviewer remarks are preserved in the email.
     */
    private function extractNotificationReason(
        string $message
    ): ?string {
        if (
            preg_match(
                '/\bReason:\s*(.+)$/isu',
                trim($message),
                $matches
            )
        ) {
            $reason =
                trim(
                    (string)
                    ($matches[1] ?? '')
                );

            return $reason !== ''
                ? $reason
                : null;
        }

        return null;
    }

    /**
     * Add reviewer remarks without exposing system-style wording.
     */
    private function reasonParagraph(
        ?string $reason
    ): string {
        if (! $reason) {
            return '';
        }

        return "\n\nSPMU review remarks: "
            .$reason;
    }

    /**
     * Improve unknown/future event wording without changing the
     * underlying notification event or audit record.
     */
    private function cleanFallbackEmailMessage(
        string $message
    ): string {
        $clean =
            trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    $message
                )
                ?? $message
            );

        if ($clean === '') {
            return 'An update has been recorded for this SPMU-ACPMP transaction. Please review the transaction information below for the applicable details.';
        }

        return $clean;
    }
    private function date(mixed $value): string
    {
        return Carbon::parse(
            $value,
            config('app.timezone') ?: 'Asia/Manila'
        )->format('d F Y');
    }

    private function dateTime(mixed $value): string
    {
        return Carbon::parse(
            $value,
            config('app.timezone') ?: 'Asia/Manila'
        )->format('d F Y, g:i A');
    }

    private function qty(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $formatted = number_format((float) $value, 3, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function humanize(string $value): string
    {
        return str($value)
            ->replace(['_', '-'], ' ')
            ->lower()
            ->title()
            ->toString();
    }

    private function escape(mixed $value): string
    {
        return e((string) $value);
    }
}