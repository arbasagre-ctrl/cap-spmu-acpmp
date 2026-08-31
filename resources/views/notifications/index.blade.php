@extends('layouts.app', ['title' => 'Notifications'])
@section('content')
<section class="page-heading"><div><p class="eyebrow">Account updates</p><h1>My notifications</h1><p>Review approval, deadline, release, return, evidence, and accountability updates addressed to you.</p></div><form method="post" action="{{ route('notifications.read-all') }}">@csrf<button class="button secondary">Mark all as read</button></form></section>

<section class="content-area">
    <div class="notification-list borrower-notifications">
    @forelse($notifications as $notification)
        @php
            $eventLabel = str($notification->event->event_code)->replace('_',' ')->lower()->title();
            $message = $notification->event->payload_snapshot_json['message'] ?? $notification->provider_response ?? 'A system update was recorded.';
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
    <div class="top-gap">{{ $notifications->links() }}</div>
</section>
@endsection
