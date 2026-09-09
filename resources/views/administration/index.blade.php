@extends('layouts.app', ['title' => 'Administration'])
@section('content')
<section class="page-heading">
    <div>
        <p class="eyebrow">Restricted administration</p>
        <h1>System administration</h1>
        <p>ICTU controls technical accounts and delegated authority; SPMU controls approved operational configuration. Finalized transactions remain immutable.</p>
    </div>
    <div class="actions">
        @if(session('active_workspace') === 'ICTU')
            @if(Route::has('administration.users.index'))
                <a class="button primary ui-pressable" href="{{ route('administration.users.index') }}">Manage users</a>
            @endif
            @if(Route::has('administration.delegations.index'))
                <a class="button secondary ui-pressable" href="{{ route('administration.delegations.index') }}">Delegations</a>
            @endif
        @endif
        @if(Route::has('administration.settings.index'))
            <a class="button secondary ui-pressable" href="{{ route('administration.settings.index') }}">Configuration</a>
        @endif
    </div>
</section>

<section class="stat-grid admin-kpi-grid">
    <article class="kpi-card kpi-card-inline admin-kpi-card @if(Route::has('administration.users.index')) stat-card-link ui-pressable @endif">
        <span class="kpi-label">All accounts</span>
        <strong class="kpi-value">{{ $userCount }}</strong>
        @if(Route::has('administration.users.index'))
            <a class="dashboard-view-all" href="{{ route('administration.users.index') }}">Manage users <x-icon name="chevron-right" size="12" /></a>
        @endif
    </article>

    <article class="kpi-card kpi-card-inline admin-kpi-card @if(Route::has('administration.settings.index')) stat-card-link ui-pressable @endif">
        <span class="kpi-label">Active accounts</span>
        <strong class="kpi-value">{{ $activeUserCount }}</strong>
        @if(Route::has('administration.settings.index'))
            <a class="dashboard-view-all" href="{{ route('administration.settings.index') }}">Review configuration <x-icon name="chevron-right" size="12" /></a>
        @endif
    </article>

    <article class="kpi-card kpi-card-inline admin-kpi-card @if($mayViewAuditTrail && Route::has('reports.audit')) stat-card-link ui-pressable @endif">
        <span class="kpi-label">Open configuration values</span>
        <strong class="kpi-value">{{ $openSettings }}</strong>
        @if($mayViewAuditTrail && Route::has('reports.audit'))
            <a class="dashboard-view-all" href="{{ route('reports.audit') }}">Open audit trail <x-icon name="chevron-right" size="12" /></a>
        @endif
    </article>
</section>

<section class="content-grid @unless($mayViewAuditTrail) content-grid-single @endunless">
    @if($mayViewAuditTrail)
    <div class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Administrative evidence</p>
                <h2>Recent attributable actions</h2>
            </div>
            @if(Route::has('reports.audit'))
                <a class="button secondary small ui-pressable" href="{{ route('reports.audit') }}">Full audit</a>
            @endif
        </div>
        <div class="timeline">
            @forelse($recentAudits as $event)
                <article>
                    <span>{{ $event->occurred_at->format('M d') }}</span>
                    <div>
                        <strong>{{ $event->action_code }}</strong>
                        <p>{{ $event->actor?->full_name ?: 'System' }}</p>
                        <small>{{ class_basename($event->record_type) }} #{{ $event->record_id }} · {{ $event->occurred_at->format('g:i A') }}</small>
                    </div>
                </article>
            @empty
                <p class="empty-state">No attributable administrative actions recorded.</p>
            @endforelse
        </div>
    </div>
    @endif

    <div class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Operations</p>
                <h2>ICTU technical operations</h2>
            </div>
        </div>
        <p>Development backup uses the local database. Institutional Docker deployment requires ICTU-managed database backups, encryption, retention, and restore testing.</p>
        @if(session('active_workspace') === 'ICTU' && Route::has('administration.backup'))
            <form method="post" action="{{ route('administration.backup') }}">
                @csrf
                <button class="button primary ui-pressable">Download local database backup</button>
            </form>
        @endif
        <div class="timeline top-gap">
            @forelse($technicalOperations as $operation)
                <article>
                    <span>{{ $operation->started_at->format('M d') }}</span>
                    <div>
                        <strong>{{ str_replace('_',' ',$operation->operation_type) }}</strong>
                        <p>{{ $operation->details }}</p>
                    </div>
                </article>
            @empty
                <p class="empty-state">No technical operations recorded.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
