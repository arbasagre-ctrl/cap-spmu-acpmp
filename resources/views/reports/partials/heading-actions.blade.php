<div class="reporting-heading-actions">
    @if(auth()->user()?->hasRole('SPMU') || auth()->user()?->hasRole('ICTU'))
        <a class="button secondary" href="{{ route('reports.audit') }}"><x-icon name="reports" size="16" />Audit Trail</a>
        <a class="button secondary" href="{{ route('reports.notifications') }}"><x-icon name="notifications" size="17" />Delivery</a>
    @endif
</div>
