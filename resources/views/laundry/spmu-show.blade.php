@extends('layouts.app', ['title' => 'Laundry Final Acceptance'])
@section('content')
@php
    $totalReceived = (float) $job->lines->sum(fn($line) => (float) ($line->received_quantity ?? 0));
    $canArchive = in_array($job->status, ['AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'], true);
@endphp
<section class="page-heading">
    <div>
        <p class="eyebrow">SPMU Action Officer · Laundry Final Acceptance</p>
        <h1>{{ $job->custody->custody_no }}</h1>
        <p>{{ $job->custody->borrower->full_name }} · Request {{ $job->custody->request->request_no }}</p>
    </div>
    <a class="button secondary ui-pressable" href="{{ route('laundry.spmu.index') }}">Back to Laundry cases</a>
</section>
@if(session('status'))
<section class="content-area"><div class="callout success">{{ session('status') }}</div></section>
@endif
@if($errors->any())
<section class="content-area"><div class="callout danger"><strong>Please review the form.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></section>
@endif
<section class="content-area">
    <div class="content-grid laundry-final-grid">
        <article class="card">
            <div class="card-header">
                <div><p class="eyebrow">Document archive</p><h2>Fully accomplished Laundry Form</h2></div>
                <x-status-badge :status="$job->status" />
            </div>
            @if($job->status === 'READY_FOR_SPMU_RETURN')
                <div class="callout warning">
                    <strong>Final physical inspection is required first.</strong>
                    <p>Receive and inspect the cleaned linen in the Return workflow, then archive the signed form here.</p>
                </div>
                <a class="button primary ui-pressable" href="{{ route('custody.return.show', $job->custody) }}">Open final return inspection</a>
            @elseif($canArchive)
                @if($job->status === 'FORM_REPLACEMENT_REQUIRED')
                    <div class="callout warning"><strong>Replacement scan required.</strong><p>Upload a clear and readable scan/photo of the fully signed form.</p></div>
                @endif
                <form method="post" action="{{ route('laundry.spmu.upload-form', $job) }}" enctype="multipart/form-data" class="form-grid top-gap">
                    @csrf
                    <label>
                        Accomplished Laundry Form
                        <small>PDF, PNG, JPG, JPEG, or WebP. Required physical signatures must be readable.</small>
                        <input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required>
                    </label>
                    <button class="button primary ui-pressable"><x-icon name="upload" /> Upload &amp; archive final form</button>
                </form>
            @elseif($job->status === 'LAUNDRY_COMPLETED')
                <div class="callout success"><strong>Laundry record completed.</strong><p>The final accomplished form has been archived.</p></div>
            @endif

            @if($job->latestEvidence?->file)
                <div class="top-gap">
                    <a class="button secondary small ui-pressable" href="{{ route('files.show', $job->latestEvidence->file, false) }}" target="_blank" rel="noopener">View uploaded final form</a>
                </div>
            @endif

            @if($job->document)
                <div class="top-gap">
                    <p class="meta">Generated borrower-printable Laundry Form</p>
                    <a class="button secondary small ui-pressable" href="{{ route('documents.download', $job->document) }}" target="_blank" rel="noopener">View generated form</a>
                </div>
            @endif
        </article>

        <article class="card">
            <div class="card-header"><div><p class="eyebrow">Recorded by Laundry</p><h2>Laundry details</h2></div></div>
            <dl class="detail-list">
                <dt>Laundry Worker</dt><dd>{{ $job->worker_name ?: 'Not recorded' }}</dd>
                <dt>Date received</dt><dd>{{ optional($job->worker_received_at)->format('d M Y, g:i A') ?: 'Not recorded' }}</dd>
                <dt>Date completed</dt><dd>{{ optional($job->worker_completed_at)->format('d M Y, g:i A') ?: 'Not recorded' }}</dd>
                <dt>Total items received</dt><dd>{{ $totalReceived + 0 }}</dd>
            </dl>
            <div class="table-wrap top-gap">
                <table>
                    <thead><tr><th>Item</th><th>Received</th><th>Condition / issue</th><th>Completed</th></tr></thead>
                    <tbody>
                    @forelse($job->lines as $line)
                        <tr>
                            <td>{{ $line->custodyLine?->requestItem?->inventoryItem?->unique_description ?? 'Item' }}</td>
                            <td>{{ ($line->received_quantity ?? 0) + 0 }}</td>
                            <td>{{ str($line->issue_type ?? 'NONE')->replace('_',' ')->title() }}</td>
                            <td>{{ ($line->completed_quantity ?? 0) + 0 }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No linen lines recorded.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($job->worker_remarks)<p class="meta top-gap"><strong>Laundry remarks:</strong> {{ $job->worker_remarks }}</p>@endif
        </article>
    </div>
</section>
<style>
.laundry-final-grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:start;gap:18px}
@media(max-width:900px){.laundry-final-grid{grid-template-columns:1fr}}
</style>
@endsection
