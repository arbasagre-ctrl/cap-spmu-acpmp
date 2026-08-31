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
