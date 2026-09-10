@extends('layouts.app', ['title' => 'Audit Trail'])
@section('content')
@php
    $groupedEvents = $events->groupBy(function ($event) {
        if ($event->occurred_at->isToday()) {
            return 'Today';
        }

        if ($event->occurred_at->isYesterday()) {
            return 'Yesterday';
        }

        return $event->occurred_at->format('d M Y');
    });

    $actorOptions = $events
        ->map(fn ($event) => $event->actor?->full_name ?: 'System')
        ->filter()
        ->unique()
        ->sort()
        ->values();

    $categoryOptions = [
        'ACCOUNT' => 'Account',
        'BORROWING' => 'Borrowing',
        'APPROVAL' => 'Approval',
        'RELEASE_RETURN' => 'Release / Return',
        'LAUNDRY_GATEPASS' => 'Laundry / Gate Pass',
        'ACCOUNTABILITY' => 'Accountability',
        'SETTINGS' => 'Settings',
        'SECURITY_SYSTEM' => 'Security / System',
        'OTHER' => 'Other',
    ];
@endphp

<section class="page-heading ictu-audit-heading">
    <div>
        <p class="eyebrow">System administration</p>
        <h1>Audit Trail</h1>
        <p>Review immutable records of account, system, and transaction activity.</p>
    </div>
</section>

<section class="content-area ictu-audit-area">
    <div class="card ictu-audit-toolbar" aria-label="Audit trail filters">
        <div class="ictu-audit-search">
            <label for="audit-search">Search</label>
            <input
                id="audit-search"
                type="search"
                placeholder="Search actor, action, record, reason..."
                autocomplete="off"
            >
        </div>

        <div>
            <label for="audit-date-filter">Date</label>
            <select id="audit-date-filter">
                <option value="ALL">All dates</option>
                <option value="TODAY">Today</option>
                <option value="YESTERDAY">Yesterday</option>
                <option value="7_DAYS">Last 7 days</option>
                <option value="30_DAYS">Last 30 days</option>
            </select>
        </div>

        <div>
            <label for="audit-category-filter">Category</label>
            <select id="audit-category-filter">
                <option value="ALL">All categories</option>
                @foreach($categoryOptions as $categoryCode => $categoryLabel)
                    <option value="{{ $categoryCode }}">{{ $categoryLabel }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="audit-actor-filter">Actor</label>
            <select id="audit-actor-filter">
                <option value="ALL">All actors</option>
                @foreach($actorOptions as $actorOption)
                    <option value="{{ $actorOption }}">{{ $actorOption }}</option>
                @endforeach
            </select>
        </div>

        <button class="button secondary ictu-audit-reset" type="button" id="audit-reset">
            Reset
        </button>
    </div>

    <div class="ictu-audit-result-row">
        <span id="audit-result-count">{{ $events->count() }} recorded event{{ $events->count() === 1 ? '' : 's' }}</span>
        <span class="ictu-audit-append-only">Append-only record</span>
    </div>

    @if($events->isEmpty())
        <div class="card empty-state">No attributable administrative actions recorded.</div>
    @else
        <div class="audit-list ictu-audit-list" id="audit-list">
            @foreach($groupedEvents as $dateLabel => $eventsForDay)
                <section class="audit-date-group ictu-audit-date-group" data-audit-group>
                    <h2>{{ $dateLabel }}</h2>

                    @foreach($eventsForDay as $event)
                        @php
                            $actionCode = strtolower((string) $event->action_code);

                            $actionLabel = match ($actionCode) {
                                'user.updated', 'user_account_updated' => 'User Account Updated',
                                'user.created', 'user_account_created' => 'User Account Created',
                                'system_setting.updated' => 'System Setting Updated',
                                'user.activated' => 'Account Activated',
                                'user.deactivated' => 'Account Deactivated',
                                'borrower_restriction.created' => 'Borrowing Restriction Opened',
                                'borrower_restriction.lifted' => 'Borrowing Restriction Lifted',
                                default => ucwords(str_replace(['.', '_'], [' ', ' '], (string) $event->action_code)),
                            };

                            $actorName = $event->actor?->full_name ?: 'System';
                            $recordType = class_basename((string) $event->record_type);
                            $recordName = trim($recordType . ' #' . $event->record_id);

                            $category = match (true) {
                                str_contains($actionCode, 'approval'),
                                str_contains($actionCode, 'approve'),
                                str_contains($actionCode, 'reject'),
                                str_contains($actionCode, 'verify') => 'APPROVAL',

                                str_contains($actionCode, 'laundry'),
                                str_contains($actionCode, 'gate_pass'),
                                str_contains($actionCode, 'gatepass') => 'LAUNDRY_GATEPASS',

                                str_contains($actionCode, 'billing'),
                                str_contains($actionCode, 'payment'),
                                str_contains($actionCode, 'restriction'),
                                str_contains($actionCode, 'incident'),
                                str_contains($actionCode, 'offense'),
                                str_contains($actionCode, 'sanction'),
                                str_contains($actionCode, 'penalty'),
                                str_contains($actionCode, 'accountability') => 'ACCOUNTABILITY',

                                str_contains($actionCode, 'issuance'),
                                str_contains($actionCode, 'release'),
                                str_contains($actionCode, 'return'),
                                str_contains($actionCode, 'custody') => 'RELEASE_RETURN',

                                str_contains($actionCode, 'request'),
                                str_contains($actionCode, 'borrow') => 'BORROWING',

                                str_contains($actionCode, 'user'),
                                str_contains($actionCode, 'account'),
                                str_contains($actionCode, 'profile'),
                                str_contains($actionCode, 'role'),
                                str_contains($actionCode, 'delegation') => 'ACCOUNT',

                                str_contains($actionCode, 'setting'),
                                str_contains($actionCode, 'config'),
                                str_contains($actionCode, 'policy') => 'SETTINGS',

                                str_contains($actionCode, 'login'),
                                str_contains($actionCode, 'auth'),
                                str_contains($actionCode, 'security'),
                                str_contains($actionCode, 'notification'),
                                $event->actor_user_id === null => 'SECURITY_SYSTEM',

                                default => 'OTHER',
                            };

                            $categoryLabel = $categoryOptions[$category] ?? 'Other';
                            $searchText = strtolower(implode(' ', array_filter([
                                $actionLabel,
                                $event->action_code,
                                $actorName,
                                $recordName,
                                $event->reason,
                                $categoryLabel,
                            ])));
                        @endphp

                        <article
                            class="audit-item ictu-audit-item"
                            data-audit-item
                            data-audit-search="{{ $searchText }}"
                            data-audit-category="{{ $category }}"
                            data-audit-actor="{{ $actorName }}"
                            data-audit-date="{{ $event->occurred_at->format('Y-m-d') }}"
                        >
                            <div class="audit-icon ictu-audit-icon">
                                <x-icon name="reports" size="16" />
                            </div>

                            <div class="audit-content ictu-audit-content">
                                <div class="audit-main-row ictu-audit-main-row">
                                    <div class="ictu-audit-action-wrap">
                                        <strong>{{ $actionLabel }}</strong>
                                        <span class="ictu-audit-category">{{ $categoryLabel }}</span>
                                    </div>

                                    <time
                                        class="audit-time"
                                        datetime="{{ $event->occurred_at->toIso8601String() }}"
                                    >
                                        {{ $event->occurred_at->format('g:i A') }}
                                    </time>
                                </div>

                                <div class="audit-meta-row ictu-audit-meta-row">
                                    <span>{{ $actorName }}</span>
                                    <span aria-hidden="true">•</span>
                                    <span>{{ $recordName }}</span>
                                </div>

                                @if(filled($event->reason))
                                    <p class="ictu-audit-reason">{{ $event->reason }}</p>
                                @endif

                                <details class="ictu-audit-details">
                                    <summary>View details</summary>

                                    <div class="ictu-audit-details-panel">
                                        <dl class="ictu-audit-detail-grid">
                                            <div>
                                                <dt>Action</dt>
                                                <dd>{{ $actionLabel }}</dd>
                                            </div>
                                            <div>
                                                <dt>Action Code</dt>
                                                <dd><code>{{ $event->action_code }}</code></dd>
                                            </div>
                                            <div>
                                                <dt>Actor</dt>
                                                <dd>{{ $actorName }}</dd>
                                            </div>
                                            <div>
                                                <dt>Record</dt>
                                                <dd>{{ $recordName }}</dd>
                                            </div>
                                            <div>
                                                <dt>Date &amp; Time</dt>
                                                <dd>{{ $event->occurred_at->format('F j, Y • g:i:s A') }}</dd>
                                            </div>
                                            <div>
                                                <dt>Origin</dt>
                                                <dd>{{ filled($event->origin_ip) ? $event->origin_ip : 'System / application' }}</dd>
                                            </div>

                                            @if(filled($event->reason))
                                                <div class="ictu-audit-detail-wide">
                                                    <dt>Reason</dt>
                                                    <dd>{{ $event->reason }}</dd>
                                                </div>
                                            @endif

                                            @if(filled($event->correlation_id))
                                                <div class="ictu-audit-detail-wide">
                                                    <dt>Technical Reference</dt>
                                                    <dd><code>{{ $event->correlation_id }}</code></dd>
                                                </div>
                                            @endif
                                        </dl>

                                        @if(!empty($event->before_json) || !empty($event->after_json))
                                            <div class="ictu-audit-change-grid">
                                                @if(!empty($event->before_json))
                                                    <section>
                                                        <h3>Before</h3>
                                                        <pre>{{ json_encode($event->before_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                    </section>
                                                @endif

                                                @if(!empty($event->after_json))
                                                    <section>
                                                        <h3>After</h3>
                                                        <pre>{{ json_encode($event->after_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                                    </section>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </details>
                            </div>
                        </article>
                    @endforeach
                </section>
            @endforeach
        </div>

        <div class="card empty-state ictu-audit-no-results" id="audit-no-results" hidden>
            No audit events match the selected filters.
        </div>
    @endif
</section>

<style>
.ictu-audit-heading {
    padding-bottom: 10px;
}

.ictu-audit-area {
    display: grid;
    gap: 16px;
}

.ictu-audit-toolbar {
    display: grid;
    grid-template-columns: minmax(280px, 1.7fr) repeat(3, minmax(150px, .75fr)) auto;
    gap: 12px;
    align-items: end;
    padding: 16px;
}

.ictu-audit-toolbar label {
    display: block;
    margin-bottom: 6px;
    font-size: .82rem;
    font-weight: 700;
    color: var(--text-muted, #52677f);
}

.ictu-audit-toolbar input,
.ictu-audit-toolbar select {
    width: 100%;
    min-height: 44px;
}

.ictu-audit-reset {
    min-height: 44px;
    white-space: nowrap;
}

.ictu-audit-result-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    color: var(--text-muted, #60738a);
    font-size: .86rem;
}

.ictu-audit-append-only {
    padding: 5px 9px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface, #fff);
    font-weight: 700;
}

.ictu-audit-date-group {
    margin: 0;
}

.ictu-audit-date-group > h2 {
    margin: 4px 0 8px;
    font-size: 1rem;
    font-weight: 700;
}

.ictu-audit-item {
    align-items: flex-start;
    padding: 18px 0;
}

.ictu-audit-icon {
    margin-top: 1px;
}

.ictu-audit-content {
    min-width: 0;
}

.ictu-audit-main-row {
    align-items: flex-start;
    gap: 14px;
}

.ictu-audit-action-wrap {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.ictu-audit-action-wrap > strong {
    text-transform: none;
}

.ictu-audit-category {
    display: inline-flex;
    align-items: center;
    min-height: 23px;
    padding: 3px 8px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface-subtle, #f4f7fb);
    color: var(--text-muted, #536980);
    font-size: .72rem;
    font-weight: 700;
}

.ictu-audit-meta-row {
    margin-top: 4px;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.ictu-audit-reason {
    margin: 8px 0 0;
    color: var(--text-muted, #5f738b);
    line-height: 1.5;
}

.ictu-audit-details {
    margin-top: 9px;
}

.ictu-audit-details > summary {
    width: fit-content;
    cursor: pointer;
    color: var(--interactive, #1769e0);
    font-size: .84rem;
    font-weight: 700;
    list-style: none;
}

.ictu-audit-details > summary::-webkit-details-marker {
    display: none;
}

.ictu-audit-details > summary::after {
    content: "›";
    display: inline-block;
    margin-left: 6px;
    transition: transform .15s ease;
}

.ictu-audit-details[open] > summary::after {
    transform: rotate(90deg);
}

.ictu-audit-details-panel {
    margin-top: 12px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-subtle, #f7f9fc);
}

.ictu-audit-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px 20px;
    margin: 0;
}

.ictu-audit-detail-grid > div {
    min-width: 0;
}

.ictu-audit-detail-grid dt {
    margin-bottom: 3px;
    color: var(--text-muted, #60738a);
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .035em;
}

.ictu-audit-detail-grid dd {
    margin: 0;
    word-break: break-word;
}

.ictu-audit-detail-grid code {
    font-size: .82rem;
}

.ictu-audit-detail-wide {
    grid-column: 1 / -1;
}

.ictu-audit-change-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-top: 14px;
}

.ictu-audit-change-grid section {
    min-width: 0;
}

.ictu-audit-change-grid h3 {
    margin: 0 0 6px;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .035em;
}

.ictu-audit-change-grid pre {
    max-height: 300px;
    margin: 0;
    padding: 12px;
    overflow: auto;
    border-radius: 9px;
    background: var(--surface, #fff);
    border: 1px solid var(--border);
    white-space: pre-wrap;
    word-break: break-word;
    font-size: .76rem;
    line-height: 1.45;
}

.ictu-audit-no-results {
    text-align: center;
}

@media (max-width: 1100px) {
    .ictu-audit-toolbar {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ictu-audit-search {
        grid-column: 1 / -1;
    }

    .ictu-audit-reset {
        width: 100%;
    }
}

@media (max-width: 700px) {
    .ictu-audit-toolbar {
        grid-template-columns: 1fr;
    }

    .ictu-audit-search {
        grid-column: auto;
    }

    .ictu-audit-result-row {
        align-items: flex-start;
        flex-direction: column;
    }

    .ictu-audit-detail-grid,
    .ictu-audit-change-grid {
        grid-template-columns: 1fr;
    }

    .ictu-audit-detail-wide {
        grid-column: auto;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('audit-search');
    const dateFilter = document.getElementById('audit-date-filter');
    const categoryFilter = document.getElementById('audit-category-filter');
    const actorFilter = document.getElementById('audit-actor-filter');
    const reset = document.getElementById('audit-reset');
    const count = document.getElementById('audit-result-count');
    const noResults = document.getElementById('audit-no-results');

    const items = Array.from(document.querySelectorAll('[data-audit-item]'));
    const groups = Array.from(document.querySelectorAll('[data-audit-group]'));

    const startOfToday = () => {
        const date = new Date();
        date.setHours(0, 0, 0, 0);
        return date;
    };

    const matchesDate = (value, filter) => {
        if (filter === 'ALL') return true;

        const eventDate = new Date(`${value}T00:00:00`);
        const today = startOfToday();
        const diffDays = Math.floor((today - eventDate) / 86400000);

        if (filter === 'TODAY') return diffDays === 0;
        if (filter === 'YESTERDAY') return diffDays === 1;
        if (filter === '7_DAYS') return diffDays >= 0 && diffDays <= 6;
        if (filter === '30_DAYS') return diffDays >= 0 && diffDays <= 29;

        return true;
    };

    const applyFilters = () => {
        const query = (search?.value || '').trim().toLowerCase();
        const selectedDate = dateFilter?.value || 'ALL';
        const selectedCategory = categoryFilter?.value || 'ALL';
        const selectedActor = actorFilter?.value || 'ALL';

        let visible = 0;

        items.forEach((item) => {
            const matchesSearch =
                !query || (item.dataset.auditSearch || '').includes(query);

            const matchesCategory =
                selectedCategory === 'ALL'
                || item.dataset.auditCategory === selectedCategory;

            const matchesActor =
                selectedActor === 'ALL'
                || item.dataset.auditActor === selectedActor;

            const dateMatches = matchesDate(
                item.dataset.auditDate || '',
                selectedDate
            );

            const show =
                matchesSearch
                && matchesCategory
                && matchesActor
                && dateMatches;

            item.hidden = !show;
            if (show) visible += 1;
        });

        groups.forEach((group) => {
            const hasVisibleItem = Array.from(
                group.querySelectorAll('[data-audit-item]')
            ).some((item) => !item.hidden);

            group.hidden = !hasVisibleItem;
        });

        if (count) {
            count.textContent = `${visible} recorded event${visible === 1 ? '' : 's'}`;
        }

        if (noResults) {
            noResults.hidden = visible !== 0;
        }
    };

    [search, dateFilter, categoryFilter, actorFilter].forEach((control) => {
        control?.addEventListener(
            control === search ? 'input' : 'change',
            applyFilters
        );
    });

    reset?.addEventListener('click', () => {
        if (search) search.value = '';
        if (dateFilter) dateFilter.value = 'ALL';
        if (categoryFilter) categoryFilter.value = 'ALL';
        if (actorFilter) actorFilter.value = 'ALL';
        applyFilters();
        search?.focus();
    });

    applyFilters();
});
</script>
@endsection
