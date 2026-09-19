@extends('layouts.app', ['title' => 'Notifications'])
@section('content')
<section class="page-heading"><div><p class="eyebrow">Account updates</p><h1>My notifications</h1><p>Review approval, deadline, release, return, evidence, and accountability updates addressed to you.</p></div><form method="post" action="{{ route('notifications.read-all') }}">@csrf<button class="button secondary">Mark all as read</button></form></section>

<section class="content-area">
    <div class="notification-list borrower-notifications">
    @forelse($notifications as $notification)
        @php
            $eventCode = strtoupper((string) $notification->event->event_code);
            $eventLabel = [
                'REQUEST_APPROVED' => 'Borrowing Request Approved',
                'REQUEST_RETURNED_FOR_REVISION' => 'Request Revision Required',
                'REQUEST_REJECTED' => 'Borrowing Request Not Approved',
                'REQUEST_CANCELLED' => 'Borrowing Request Cancelled',
                'CANCELLATION_REJECTED' => 'Cancellation Request Not Approved',
                'PICKUP_SCHEDULED' => 'Pickup Schedule Updated',
                'PICKUP_EXPIRED' => 'Pickup Schedule Missed',
                'PICKUP_RESCHEDULE_REQUESTED' => 'Pickup Reschedule Requested',
                'ITEMS_RELEASED' => 'Borrowed Items Released',
                'RETURN_DUE_TOMORROW' => 'Return Due Tomorrow',
                'RETURN_DUE_TODAY' => 'Return Due Today',
                'RETURN_INSPECTED' => 'Return Inspected',
                'TRANSACTION_CLOSED' => 'Borrowing Transaction Completed',
                'BORROWING_OVERDUE' => 'Return Overdue',
                'LATE_RETURN_NOTICE_ISSUED' => 'Late Return Notice',
                'LATE_RETURN_BILLING_STATEMENT_ISSUED' => 'Late Return Billing Statement',
                'ACCOUNTABILITY_OPENED' => 'Property Accountability Case Opened',
                'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED' => 'Property Accountability Decision',
                'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED' => 'Property Accountability Resolved',
                'PAYMENT_VERIFIED' => 'Payment Confirmed',
                'RECEIPT_VERIFIED' => 'Payment Confirmed',
                'EVIDENCE_VERIFIED' => 'Supporting Document Verified',
                'EVIDENCE_REJECTED' => 'Supporting Document Needs Replacement',
                'ADMINISTRATIVE_SANCTION_RECORDED' => 'Administrative Sanction Notice',
                'EARLY_RETURN_REQUESTED' => 'Early Return Request',
            ][$eventCode] ?? str($eventCode)->replace('_',' ')->lower()->title();
            $message = $notification->event->payload_snapshot_json['message'] ?? $notification->provider_response ?? 'An update was recorded.';
            $target = $targets[$notification->id] ?? null;
        @endphp
        <article class="notification-item {{ $notification->read_at ? '' : 'unread' }} {{ $target ? 'is-linked' : '' }}">
            @if($target)
                {{--
                    The whole row is the link. It is stretched over the card
                    rather than wrapping it so the "Mark as read" form stays a
                    valid, separately clickable control inside the same item.
                --}}
                <a class="notification-open" href="{{ route('notifications.open', $notification) }}">
                    <span class="visually-hidden">Open {{ $eventLabel }}: {{ $message }}</span>
                </a>
            @endif
            <div class="notification-date"><strong>{{ optional($notification->attempted_at)->format('d') }}</strong><span>{{ optional($notification->attempted_at)->format('M Y') }}</span></div>
            <div class="notification-copy"><div class="notification-heading"><h2>{{ $eventLabel }}</h2>@if(!$notification->read_at)<x-status-badge status="UNREAD" label="New" />@endif</div><p>{{ $message }}</p><small>{{ optional($notification->attempted_at)->format('d M Y, g:i A') ?: 'Time not recorded' }}</small></div>
            @if(!$notification->read_at)<form method="post" action="{{ route('notifications.read',$notification) }}">@csrf<button class="button ghost small">Mark as read</button></form>@endif
        </article>
    @empty
        <div class="empty-state borrower-empty-state"><div><strong>No notifications yet.</strong><span>Updates addressed to your account will appear here.</span></div></div>
    @endforelse
    </div>
    <div class="top-gap">{{ $notifications->links('partials.pagination') }}</div>
</section>
@endsection
