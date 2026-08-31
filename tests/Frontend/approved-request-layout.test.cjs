const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const page = read('resources/views/requests/show.blade.php');
const heading = read('resources/views/requests/partials/operational-heading.blade.php');
const details = read('resources/views/requests/partials/operational-details.blade.php');
const styles = read('resources/views/requests/partials/operational-styles.blade.php');
const tracker = read('resources/views/components/request-progress-tracker.blade.php');

test('compact request layout is limited to SPMU custody records outside approval review', () => {
    assert.match(page, /\$isOperationalRequestLayout = \$isSpmu && \(bool\) \$custody && ! \$isUnderSpmuReview;/);
    assert.match(page, /@elseif\(\$isOperationalRequestLayout\)\s+@include\('requests\.partials\.operational-details'\)\s+@elseif\(!\$isUnderSpmuReview\)/);
    assert.match(page, /data-spmu-verification-workspace/);
    assert.match(page, /data-borrower-request-tabs/);
});

test('heading uses live request identity, workflow status and the existing custody route', () => {
    assert.match(heading, /\{\{ \$borrowingRequest->request_no \}\}/);
    assert.match(heading, /\{\{ \$v->purpose_event \}\}/);
    assert.match(heading, /<x-status-badge :status="\$detailStatus" :label="\$detailStatusLabel"/);
    assert.match(heading, /route\('custody\.show', \$borrowingRequest->custody\)/);
    assert.match(heading, /Open Custody Record/);
    assert.match(heading, /\$detailStatus === 'READY_FOR_RELEASE' => 'Proceed to physical release'/);
    assert.match(heading, /\$requestIsCompleted => 'Review the custody record'/);
    assert.match(heading, /\['OBLIGATION_OPEN', 'INCIDENT_OPEN'\]/);
    assert.doesNotMatch(heading, /BR-2026|Borrower Demo|Ruby Foundation/);
});

test('operational layout moves rather than duplicates the custody action', () => {
    assert.match(page, /@if\(\$borrowingRequest->custody && !\$isBorrower && !\$isOperationalRequestLayout\)/);
    assert.equal((heading.match(/route\('custody\.show'/g) || []).length, 1);
    assert.doesNotMatch(details, /route\('custody\.show'/);
});

test('borrowing information and document rows retain live data and protected links', () => {
    for (const field of ['full_name', 'unit_name', 'purpose_event', 'location', 'schedule_date', 'needed_from', 'return_date', 'return_due_at']) {
        assert.ok(details.includes(field), `Missing live field: ${field}`);
    }
    assert.match(details, /\$currentDocs->sortBy/);
    assert.match(details, /\$doc->version_no/);
    assert.match(details, /\$doc->verification_status/);
    assert.match(details, /route\('files\.show', \$doc->file, false\)/);
    assert.match(details, /target="_blank" rel="noopener"/);
    assert.match(details, /No current scanned supporting document/);
});

test('requested items retain approved, pending and requested quantities with their original units', () => {
    assert.match(details, /@forelse\(\$v->items as \$item\)/);
    assert.match(details, /\$item->description_snapshot/);
    assert.match(details, /\$item->requested_quantity \+ 0/);
    assert.match(details, /\$item->approved_quantity === null \? 'Not approved yet'/);
    assert.match(details, /\$item->unit_snapshot/);
    assert.match(details, /\$item->use_location/);
    assert.match(details, /No requested items/);
});

test('activity history is a native collapsed disclosure and retains the full audit table', () => {
    const opening = page.match(/<details\b[^>]*request-activity-history[^>]*>/)?.[0];
    assert.ok(opening);
    assert.doesNotMatch(opening, /\bopen(?:\s|=|>)/);
    assert.match(page, /<summary class="request-activity-summary">/);
    assert.match(page, /statusHistory->sortByDesc\('changed_at'\)->first\(\)/);
    assert.match(page, /@forelse\(\$borrowingRequest->statusHistory as \$history\)/);
    for (const field of ['changed_at', 'from_status', 'to_status', 'actor?->full_name', 'reason']) {
        assert.ok(page.includes(`$history->${field}`));
    }
    assert.match(styles, /\.request-activity-history\[open\] \.request-history-hide/);
    assert.match(styles, /\.request-activity-history\[open\] \.request-history-show/);
});

test('tracker opts in to compact presentation without removing workflow interactions', () => {
    assert.match(tracker, /'compact' => false/);
    assert.match(page, /:compact="\$isOperationalRequestLayout"/);
    assert.match(tracker, /\$compact \? 'visually-hidden' : ''/);
    assert.match(tracker, /@unless\(\$compact\)\s+<p class="request-tracker__hint">/);
    for (const hook of ['data-workflow-step', 'data-workflow-title', 'data-workflow-meta', 'data-workflow-description', 'aria-current="step"']) {
        assert.ok(tracker.includes(hook), `Missing existing tracker hook: ${hook}`);
    }
    assert.match(tracker, /<x-workflow-tracker-interactions/);
    assert.match(tracker, /\$steps\[3\]\['label'\] = \$isApproved \? 'Reviewed'/);
});

test('cards stretch equally, tables scroll, and smaller screens reflow', () => {
    assert.match(styles, /\.request-operational-page \.request-operational-grid\s*\{[^}]*align-items: stretch/s);
    assert.match(styles, /@media \(max-width: 1000px\)[\s\S]*grid-template-columns: minmax\(0, 1fr\)/);
    assert.match(styles, /\.request-operational-page \.table-wrap \{ overflow-x: auto/);
    assert.match(styles, /\.request-operational-page \.request-tracker__rail\s*\{[^}]*min-width: 900px/s);
    assert.match(styles, /@media \(prefers-reduced-motion: reduce\)/);
    assert.doesNotMatch(styles, /(?:^|\n)\.card\s*\{/);
});

test('new display partials do not submit forms or mutate workflow data', () => {
    for (const source of [heading, details]) {
        assert.doesNotMatch(source, /<form\b|<input\b|->(?:save|update|delete|create)\(/);
    }
});
