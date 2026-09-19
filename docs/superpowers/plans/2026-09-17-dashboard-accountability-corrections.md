# Dashboard Accountability Corrections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align dashboard-card drilldowns and accountability case counts with the corrected workflow while preserving current compliance and legacy compatibility paths.

**Architecture:** Introduce a read-only `AccountabilityCaseService` that returns canonical current case rows and counts using `Incident:id` and `OverdueCase:id` identities. Reuse the service in Dashboard, Accountability, and Borrower Obligation code; add explicit, validated `kpi` filters to destination controllers; render existing payment metadata on the incident row. No database schema change is needed.

**Tech Stack:** Laravel, PHP 8, Blade, PHPUnit Feature tests, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-09-17-dashboard-accountability-corrections-design.md`

## Global Constraints

- Preserve `COMPLIANCE_REQUIRED`, `COMPLIANCE_RSLDDP_PENDING`, and the repair/replacement/compliance workflow.
- Do not redesign Analytics or Inventory or alter their approved tab UI.
- Keep `FOR_BILLING`, `BILLING_PENDING`, historical Compliance Notice/Written Reprimand documents, historical AO-confirmation rows, and legacy generic-property download actions operational.
- Do not alter broad ICTU support-file/document authorization; record no policy change for it.
- Direct destination visits without `kpi` must retain the existing All view.
- AO remains the only role that records cashier receipts; no payment/evidence record may be duplicated.
- Do not add a migration unless implementation reveals an unavailable required field.

---

### Task 1: Establish canonical accountability case projection

**Files:**
- Create: `app/Services/AccountabilityCaseService.php`
- Modify: `app/Http/Controllers/DashboardController.php:365-411`
- Modify: `resources/views/accountability/index.blade.php:11-93, 615-626, 867-983`
- Test: `tests/Feature/DashboardAccountabilityCaseTest.php`

**Interfaces:**
- Produces `AccountabilityCaseService::openCases(Collection $incidents, Collection $overdues, Collection $billings, Collection $restrictions): Collection`.
- Produces `AccountabilityCaseService::countOpenCases(...): int`.
- Each returned row has `key`, `kind`, `subject`, `linked_billing_ids`, `linked_restriction_ids`, and `standalone` keys.

- [ ] **Step 1: Write failing feature tests for each canonical identity**

```php
public function test_same_custody_property_incident_and_late_return_are_two_cases(): void
{
    [$custody, $incident, $overdue] = $this->openIncidentAndOverdueOnOneCustody();

    $cases = app(AccountabilityCaseService::class)->openCases(
        collect([$incident]), collect([$overdue]), collect(), collect()
    );

    $this->assertCount(2, $cases);
    $this->assertSame(['incident:'.$incident->id, 'overdue:'.$overdue->id], $cases->pluck('key')->sort()->values()->all());
}

public function test_billing_and_restriction_linked_to_one_incident_do_not_add_cases(): void
{
    [$incident, $billing, $restriction] = $this->incidentWithBillingAndRestriction();

    $this->assertSame(1, app(AccountabilityCaseService::class)->countOpenCases(
        collect([$incident]), collect(), collect([$billing]), collect([$restriction])
    ));
}
```

- [ ] **Step 2: Run the focused test in Docker and confirm it fails because the service does not exist**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter=DashboardAccountabilityCaseTest`

Expected: failure resolving `AccountabilityCaseService` or its missing method.

- [ ] **Step 3: Implement the minimal projection**

```php
final class AccountabilityCaseService
{
    public function openCases(Collection $incidents, Collection $overdues, Collection $billings, Collection $restrictions): Collection
    {
        // Add every non-terminal Incident and every unresolved OverdueCase independently.
        // Mark all linked billing/restriction ids claimed.
        // Add a standalone billing/restriction only if it has no link to any represented case.
    }

    public function countOpenCases(Collection $incidents, Collection $overdues, Collection $billings, Collection $restrictions): int
    {
        return $this->openCases($incidents, $overdues, $billings, $restrictions)->count();
    }
}
```

- [ ] **Step 4: Replace custody-id deduplication in dashboard/accountability summary with the projection count**

Use the same loaded collections already passed to the Accountability view. Make the summary card and visible-row chip take values from the projection, not `custody_transaction_id` de-duplication or raw-row arithmetic.

- [ ] **Step 5: Run focused tests and confirm the five required count scenarios pass**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter=DashboardAccountabilityCaseTest`

Expected: incident-only, overdue-only, linked incident details, linked overdue details, same-custody dual case, and standalone legacy case tests pass.

### Task 2: Harden borrower obligation grouping

**Files:**
- Modify: `app/Services/BorrowerObligationService.php:178-212, 340-404, 564-733`
- Test: `tests/Feature/BorrowerObligationGroupingTest.php`

**Interfaces:**
- Consumes canonical parent identities from incident and overdue records.
- Produces exactly one obligation row per open `Incident:id` and `OverdueCase:id`; all linked billings/restrictions are claimed.

- [ ] **Step 1: Add a failing regression test for multiple linked historical records**

```php
public function test_multiple_open_billings_and_restrictions_linked_to_one_incident_are_one_obligation(): void
{
    [$borrower, $incident, $billings, $restrictions] = $this->incidentWithMultipleOpenLinkedRecords();

    $rows = app(BorrowerObligationService::class)->obligationRows($borrower->id);

    $this->assertCount(1, collect($rows)->where('category', 'property')->all());
    $this->assertCount(1, $rows);
}
```

- [ ] **Step 2: Run the focused regression test and confirm it fails from an extra standalone row**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter=BorrowerObligationGroupingTest`

Expected: assertion reports more than one obligation.

- [ ] **Step 3: Claim every linked record before standalone loops**

For each incident and overdue case, collect every matching open billing id and every active restriction linked either directly to the case or to any of its linked billings. Store the complete id sets in `claimedBillingIds` and `claimedRestrictionIds`; retain the existing standalone loops only for ids not in those sets.

- [ ] **Step 4: Run borrower grouping tests**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter=BorrowerObligationGroupingTest`

Expected: existing grouping tests and the new multi-record regression pass.

### Task 3: Add exact KPI destination scopes

**Files:**
- Modify: `resources/views/dashboard.blade.php:215-249, 347-378`
- Modify: `app/Http/Controllers/CustodyController.php:24-32`
- Modify: `resources/views/custody/index.blade.php`
- Modify: `app/Http/Controllers/AccountabilityController.php:40-99`
- Modify: `resources/views/accountability/index.blade.php`
- Modify: `app/Http/Controllers/UserAdministrationController.php:24-36`
- Modify: `app/Http/Controllers/DelegationController.php:17-24`
- Modify: `app/Http/Controllers/ReportController.php:615-627`
- Test: `tests/Feature/DashboardKpiDestinationTest.php`

**Interfaces:**
- All affected destinations consume `?kpi=<allowed-value>`.
- Supported custody values: `active_borrowings`, `upcoming_pickup`, `returns_due`, `active_custodies`.
- Supported accountability value: `active_restrictions`.
- Supported ICTU values: `active_accounts`, `inactive_accounts`, `failed_notifications`, `active_delegations`.

- [ ] **Step 1: Write failing card-to-destination tests**

```php
public function test_borrower_active_borrowings_kpi_only_renders_released_outstanding_custodies(): void
{
    [$borrower, $active, $closed] = $this->borrowerWithActiveAndClosedCustody();

    $response = $this->actingAs($borrower)->withSession(['active_workspace' => 'BORROWER'])
        ->get(route('custody.index', ['kpi' => 'active_borrowings']));

    $response->assertSee($active->custody_no)->assertDontSee($closed->custody_no);
}

public function test_ictu_failed_notifications_kpi_only_renders_failed_deliveries(): void
{
    [$ictu, $failed, $sent] = $this->ictuWithFailedAndSentDelivery();

    $this->actingAs($ictu)->withSession(['active_workspace' => 'ICTU'])
        ->get(route('reports.notifications', ['kpi' => 'failed_notifications']))
        ->assertSee($failed->id)->assertDontSee($sent->id);
}
```

- [ ] **Step 2: Run destination tests and confirm they fail because `kpi` is ignored**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter=DashboardKpiDestinationTest`

Expected: non-matching records remain visible.

- [ ] **Step 3: Implement validated server scopes and card links**

In every listed controller, accept only its documented `kpi` values, add the matching existing predicate before `get()`, and pass the selected KPI to the view for an accurate result label. In the dashboard, emit only those supported values. Do not change the no-`kpi` path.

- [ ] **Step 4: Route physical-return borrower actions before accountability actions**

```php
$actionHref = $custody?->status === 'OVERDUE'
    ? route('custody.show', $custody)
    : ($hasAccountabilityMatter ? route('accountability.index') : route('requests.show', $record));
```

Keep “View Obligations” only when the immediate action is not a physical return.

- [ ] **Step 5: Run destination tests and the existing custody/accountability tests**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter='DashboardKpiDestinationTest|LateReturnAccountabilityTest|RoleWorkspaceSmokeTest'`

Expected: scoped routes display exactly their card subsets; direct routes retain All records.

### Task 4: Render current RSLDDP payment evidence for Head/Admin

**Files:**
- Modify: `resources/views/accountability/index.blade.php:1838-1926`
- Test: `tests/Feature/RsldppWorkflowTest.php`

**Interfaces:**
- Consumes existing `$incidentBilling->payments` eager-loaded by `AccountabilityController::index`.
- Renders only payment data already stored on the billing; evidence target is `route('files.show', $payment->evidence_file_id, false)`.

- [ ] **Step 1: Add a failing RSLDDP receipt-detail test**

```php
public function test_head_sees_confirmed_cashier_receipt_details_before_rslddp_resolution(): void
{
    [$head, $incident, $billing, $payment] = $this->rslddpCaseForResolutionWithConfirmedPayment();

    $this->actingAs($head)->withSession(['active_workspace' => 'SPMU'])
        ->get(route('accountability.index'))
        ->assertSee($payment->official_receipt_no)
        ->assertSee($payment->receipt_date->format('d M Y'))
        ->assertSee(number_format((float) $payment->amount, 2))
        ->assertSee(route('files.show', $payment->evidence_file_id, false), false)
        ->assertSee('Verify & Resolve');
}
```

- [ ] **Step 2: Run the test and confirm receipt metadata is absent**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter=RsldppWorkflowTest`

Expected: assertion for the receipt number fails.

- [ ] **Step 3: Add a read-only payment-evidence block to both current monetary stages**

Render receipt/reference, date, confirmed amount, `recordedBy->full_name`, recorded timestamp, and the existing protected file link for each verified payment. Do not add a form, model write, route, or role exception.

- [ ] **Step 4: Run the RSLDDP workflow suite**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter=RsldppWorkflowTest`

Expected: receipt detail test and existing role-gate/RSLDDP workflow tests pass.

### Task 5: Context-label current late-return billing and hide unauthorized administration navigation

**Files:**
- Modify: `app/Services/DocumentService.php:2820-2851, 3071-3100, 3749-3810`
- Modify: `resources/views/documents/accountability/billing-statement.blade.php:19-30`
- Modify: `resources/views/administration/index.blade.php:24-39`
- Test: `tests/Feature/LateReturnAccountabilityTest.php`
- Test: `tests/Feature/AccountSettingsRoleTest.php`

**Interfaces:**
- `DocumentService::billingStatement()` determines title from a non-void line whose penalty has an `overdue_case_id`.
- Current late-return documents display `Late Return Billing Statement`; legacy property documents preserve generic existing terminology.

- [ ] **Step 1: Add failing title and navigation tests**

```php
public function test_new_late_return_billing_renders_a_late_return_billing_statement_title(): void
{
    $billing = $this->issuedLateReturnBilling();

    $html = app(DocumentService::class)->billingStatementHtmlForTest($billing);

    $this->assertStringContainsString('Late Return Billing Statement', $html);
    $this->assertStringNotContainsString('<h1>Billing Statement / Assessment Notice</h1>', $html);
}

public function test_head_administration_page_does_not_offer_ictu_user_management(): void
{
    $head = $this->spmuHead();

    $this->actingAs($head)->withSession(['active_workspace' => 'SPMU'])
        ->get(route('administration.index'))
        ->assertDontSee(route('administration.users.index'), false);
}
```

- [ ] **Step 2: Run focused tests and confirm the title/link failures**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter='LateReturnAccountabilityTest|AccountSettingsRoleTest'`

Expected: generic title and unauthorized navigation assertion failures.

- [ ] **Step 3: Pass a document title through existing billing render data and gate the Administration link by ICTU workspace**

Use a late-return relationship check only when producing a newly rendered document. Keep the generic title for legacy property billing. Change the Administration summary-card link condition from route existence to ICTU workspace/authorization; do not alter user route middleware.

- [ ] **Step 4: Run focused tests**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter='LateReturnAccountabilityTest|AccountSettingsRoleTest'`

Expected: late-return title, legacy-property label, and Head navigation tests pass.

### Task 6: Integrate, build, and verify the deployment path

**Files:**
- Modify only files identified by Tasks 1-5.
- Test: full PHPUnit and frontend suite configured by Docker.

- [ ] **Step 1: Inspect migration status before creating any migration**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan migrate:status`

Expected: no schema change is needed for case projection, KPI filters, receipt rendering, or document title context.

- [ ] **Step 2: Run all corrected-area tests together**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test --filter='DashboardAccountabilityCaseTest|DashboardKpiDestinationTest|BorrowerObligationGroupingTest|RsldppWorkflowTest|LateReturnAccountabilityTest|AccountSettingsRoleTest'`

Expected: zero failures.

- [ ] **Step 3: Run the complete Docker suite**

Run: `docker compose -f docker-compose.test.yml run --rm test php artisan test`

Expected: zero failures; record tests, assertions, failures, and skips from the command output.

- [ ] **Step 4: Rebuild application and scheduler services**

Run: `docker compose up -d --build app scheduler`

Expected: both services start without a build error.

- [ ] **Step 5: Verify health endpoint**

Run: `docker compose exec -T app php artisan about && curl -fsS -o /dev/null -w '%{http_code}' http://localhost/up`

Expected: `/up` prints `200`.

- [ ] **Step 6: Inspect the final diff without resetting existing user changes**

Run: `git diff --check && git status --short`

Expected: no whitespace errors; report only files changed by this implementation separately from the pre-existing dirty worktree.
