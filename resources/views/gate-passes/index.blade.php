@extends('layouts.app', ['title' => 'Gate Pass'])
@section('content')
@php
    $statuses = $gatePasses->pluck('status')->filter()->unique()->sort()->values();
@endphp
<section class="page-heading">
    <div>
        <p class="eyebrow">SPMU Action Officer</p>
        <h1>Gate Pass</h1>
        <p>Off-campus borrowings appear here. A Gate Pass stays pending after Head approval, is finalized by the Action Officer at Physical Release, then is printed by SPMU for the borrower and later archived after guard processing.</p>
    </div>
</section>

<section class="content-area gate-pass-browser" data-gate-pass-browser>
    <style>
        .gate-pass-toolbar{display:grid;grid-template-columns:minmax(260px,1fr) 190px 170px;gap:10px;align-items:end;margin-bottom:12px;padding:14px;border:1px solid var(--border);border-radius:12px;background:var(--surface)}
        .gate-pass-toolbar label{margin:0;min-width:0}.gate-pass-toolbar input,.gate-pass-toolbar select{width:100%}.gate-pass-toolbar select,.gate-pass-toolbar .search-input-shell{margin-top:6px}
        .gate-pass-list{display:grid;gap:9px}.gate-pass-record[hidden]{display:none!important}.gate-pass-record{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,.9fr) auto;gap:18px;align-items:center;padding:13px 15px;border:1px solid var(--border);border-left:3px solid var(--primary);border-radius:11px;background:var(--surface);color:inherit;text-decoration:none}
        .gate-pass-record strong,.gate-pass-record small{display:block}.gate-pass-record small{margin-top:3px;color:var(--text-muted)}.gate-pass-action{display:flex;align-items:center;gap:10px;white-space:nowrap}.gate-pass-empty{padding:28px;text-align:center;border:1px dashed var(--border);border-radius:12px;color:var(--text-muted)}
        @media(max-width:800px){.gate-pass-toolbar{grid-template-columns:1fr}.gate-pass-record{grid-template-columns:1fr}.gate-pass-action{justify-content:space-between}}
    </style>

    <div class="gate-pass-toolbar">
        <label>Search
            <span class="search-input-shell">
                <span class="search-input-icon" aria-hidden="true"><x-icon name="search" /></span>
                <input type="search" placeholder="Request, borrower, custody, destination..." data-gate-pass-search autocomplete="off">
            </span>
        </label>
        <label>Status
            <select data-gate-pass-status>
                <option value="">All Statuses</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}">{{ str($status)->replace('_',' ')->title() }}</option>
                @endforeach
            </select>
        </label>
        <label>Sort
            <select data-gate-pass-sort>
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
            </select>
        </label>
    </div>

    <div class="gate-pass-list" data-gate-pass-list>
        @foreach($gatePasses as $gatePass)
            @php
                $custody = $gatePass->custody;
                $version = $custody?->request?->currentVersion;
                $search = strtolower(trim(implode(' ', [
                    $custody?->request?->request_no,
                    $custody?->custody_no,
                    $custody?->borrower?->full_name,
                    $gatePass->destination,
                    $gatePass->purpose,
                    $gatePass->status,
                ])));
            @endphp
            <a class="gate-pass-record ui-pressable"
               href="{{ route('gate-passes.show', $gatePass) }}"
               data-gate-pass-record
               data-search="{{ $search }}"
               data-status="{{ $gatePass->status }}"
               data-date="{{ optional($gatePass->updated_at)->timestamp ?? 0 }}">
                <span>
                    <strong>{{ $custody?->request?->request_no ?: 'Borrowing request' }}</strong>
                    <small>{{ $custody?->borrower?->full_name }} · {{ $custody?->custody_no }}</small>
                </span>
                <span>
                    <strong>{{ $gatePass->destination ?: ($version?->location ?: 'Off-campus destination') }}</strong>
                    <small>{{ $gatePass->guard_name ? 'Guard: '.$gatePass->guard_name : 'Guard details pending' }}</small>
                </span>
                <span class="gate-pass-action">
                    <x-status-badge :status="$gatePass->status" />
                    <strong>View details <x-icon name="chevron-right" size="15" /></strong>
                </span>
            </a>
        @endforeach
    </div>

    <div class="gate-pass-empty" data-gate-pass-empty @if($gatePasses->isNotEmpty()) hidden @endif>
        <strong>{{ $gatePasses->isEmpty() ? 'No off-campus Gate Pass records.' : 'No Gate Pass record matches the selected filters.' }}</strong>
    </div>
</section>

<script>
(() => {
    const root = document.querySelector('[data-gate-pass-browser]');
    if (!root) return;
    const list = root.querySelector('[data-gate-pass-list]');
    const records = Array.from(root.querySelectorAll('[data-gate-pass-record]'));
    const search = root.querySelector('[data-gate-pass-search]');
    const status = root.querySelector('[data-gate-pass-status]');
    const sort = root.querySelector('[data-gate-pass-sort]');
    const empty = root.querySelector('[data-gate-pass-empty]');
    const render = () => {
        const q = (search?.value || '').trim().toLowerCase();
        const s = status?.value || '';
        const dir = sort?.value === 'oldest' ? 1 : -1;
        records.sort((a,b) => dir * (Number(b.dataset.date||0)-Number(a.dataset.date||0))).forEach(r => list.appendChild(r));
        let visible = 0;
        records.forEach(r => {
            const show = (!q || (r.dataset.search||'').includes(q)) && (!s || r.dataset.status === s);
            r.hidden = !show; if (show) visible++;
        });
        if (empty) empty.hidden = visible !== 0;
    };
    search?.addEventListener('input', render); status?.addEventListener('change', render); sort?.addEventListener('change', render); render();
})();
</script>
@endsection
