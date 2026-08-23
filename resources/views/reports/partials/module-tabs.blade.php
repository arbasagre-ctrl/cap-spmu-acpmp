<style>
    .reports-module-tabs {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px;
        border: 1px solid var(--border, #d7e0ea);
        border-radius: 11px;
        background: var(--surface-subtle, #f7f9fc);
    }

    .reports-module-tabs a {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 38px;
        padding: 7px 15px;
        border-radius: 8px;
        color: var(--text-muted, #64748b);
        font-size: 13px;
        font-weight: 800;
        text-decoration: none;
    }

    .reports-module-tabs a:hover {
        background: var(--surface, #fff);
        color: var(--text, #18324a);
    }

    .reports-module-tabs a.is-active {
        background: var(--surface, #fff);
        color: var(--primary, #1769e0);
        box-shadow: 0 2px 8px rgba(15, 42, 67, .08);
    }

    @media (max-width: 520px) {
        .reports-module-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            width: 100%;
        }
    }
</style>

<nav class="reports-module-tabs" aria-label="Reports and Analytics sections">
    <a
        class="ui-pressable {{ $activeTab === 'analytics' ? 'is-active' : '' }}"
        href="{{ route('reports.index', [
            'tab' => 'analytics',
            'academic_period' => $periodSelection,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]) }}"
        @if($activeTab === 'analytics') aria-current="page" @endif
    >
        Analytics
    </a>

    <a
        class="ui-pressable {{ $activeTab === 'reports' ? 'is-active' : '' }}"
        href="{{ route('reports.index', [
            'tab' => 'reports',
            'academic_period' => $periodSelection,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]) }}"
        @if($activeTab === 'reports') aria-current="page" @endif
    >
        Reports
    </a>
</nav>
