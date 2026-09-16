<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\LaundryJobLine;
use App\Models\OverdueCase;
use App\Models\RequestItem;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\Sanction;
use App\Models\StoredFile;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Linen is physically inspected by Laundry Personnel, who record the actual
 * received quantity and condition on the same travelling printed Laundry Form
 * and wet-sign "Received by". The SPMU Action Officer is the system verifier /
 * encoder for linen: the accomplished form must be uploaded and verified
 * before the linen return can be finalised.
 *
 * Non-linen is unchanged and remains a direct Action Officer inspection.
 */
class LinenReturnInspectionFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        // A configured open return weekday keeps the operational calendar out
        // of these assertions.
        $this->travelTo(
            Carbon::now()->next(Carbon::MONDAY)->setTime(10, 0)
        );
    }

    public function test_non_linen_return_does_not_require_a_laundry_form(): void
    {
        ['custody' => $custody, 'lines' => $lines] = $this->outstandingCustody(linen: false);

        $this->recordReturn($custody, [
            $lines['non_linen']->id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            2.0,
            (float) $lines['non_linen']->fresh()->returned_quantity
        );

        $this->assertSame(
            1,
            ReturnTransaction::query()
                ->where('custody_transaction_id', $custody->id)
                ->count()
        );
    }

    public function test_linen_return_is_blocked_when_the_accomplished_form_is_missing(): void
    {
        ['custody' => $custody, 'lines' => $lines] = $this->outstandingCustody(linen: true);

        $this->recordReturn($custody, [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasErrors('laundry_form');

        // Nothing may be finalised while the documentary basis is missing.
        $this->assertSame(
            0.0,
            (float) $lines['linen']->fresh()->returned_quantity
        );

        $this->assertSame(
            0,
            ReturnTransaction::query()
                ->where('custody_transaction_id', $custody->id)
                ->count()
        );
    }

    public function test_linen_all_good_return_succeeds_once_the_signed_form_is_uploaded(): void
    {
        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(linen: true);

        $this->uploadAccomplishedForm($job)->assertSessionHasNoErrors();

        $this->recordReturn($custody, [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            3.0,
            (float) $lines['linen']->fresh()->returned_quantity
        );

        // An all-good linen return opens no accountability case.
        $this->assertSame(
            0,
            Incident::query()
                ->where('custody_transaction_id', $custody->id)
                ->count()
        );
    }

    public function test_linen_adverse_finding_opens_a_case_without_any_sanction(): void
    {
        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(linen: true);

        $this->uploadAccomplishedForm($job)->assertSessionHasNoErrors();

        $this->recordReturn(
            $custody,
            [$lines['linen']->id => ['FINE' => 2, 'DAMAGED' => 1]],
            [
                'remarks' => 'One table cloth reported stained by Laundry Personnel upon physical return.',
            ]
        )->assertSessionHasNoErrors();

        $incident = Incident::query()
            ->where('custody_transaction_id', $custody->id)
            ->first();

        $this->assertNotNull($incident);
        $this->assertSame('OPEN', $incident->status);
        $this->assertSame('DAMAGED', $incident->incident_type);

        // A finding is not guilt: no offense is counted and no sanction is
        // applied before the SPMU Head confirms the case.
        $this->assertSame(
            0,
            Sanction::query()
                ->where('borrower_user_id', $custody->borrower_user_id)
                ->count()
        );
    }

    public function test_verifying_the_form_alone_does_not_finalize_the_physical_return(): void
    {
        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(linen: true);

        $this->uploadAccomplishedForm($job)->assertSessionHasNoErrors();

        $job->refresh();
        $line = $lines['linen']->fresh();

        // The form is verified documentary basis only.
        $this->assertNotNull($job->form_verified_at);
        $this->assertNotNull($job->latest_evidence_submission_id);

        // Verification changes no custody quantity and completes no return.
        $this->assertSame(0.0, (float) $line->returned_quantity);
        $this->assertSame(3.0, (float) $line->actual_released_quantity);
        $this->assertSame('RELEASED_PENDING_RETURN', $line->item_status);
        $this->assertSame('ACTIVE', $custody->fresh()->status);
        $this->assertNull($custody->fresh()->closed_at);

        $this->assertSame(
            0,
            ReturnTransaction::query()
                ->where('custody_transaction_id', $custody->id)
                ->count()
        );

        // Verification alone never implies the linen reached the Laundry Area
        // as a completed turnover.
        $this->assertSame('FOR_LAUNDRY', $job->status);
    }

    public function test_upload_while_for_laundry_does_not_require_a_confirmation_checkbox(): void
    {
        ['job' => $job] = $this->outstandingCustody(linen: true);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->classificationUser(AccessClassification::SpmuOfficer))
            ->post(route('laundry.spmu.upload-form', $job), [
                'evidence' => UploadedFile::fake()->create(
                    'accomplished-laundry-form.pdf',
                    20,
                    'application/pdf'
                ),
                'laundry_received_on' => now()->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertNotNull($job->form_verified_at);
        $this->assertNotNull($job->latest_evidence_submission_id);
    }

    public function test_return_inspection_proceeds_exactly_once_after_the_form_is_verified(): void
    {
        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(linen: true);

        $this->uploadAccomplishedForm($job)->assertSessionHasNoErrors();

        $this->recordReturn($custody, [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasNoErrors();

        // A second submission for the same line must not duplicate anything:
        // the controller drops already-accounted lines before the service runs.
        $this->recordReturn($custody, [
            $lines['linen']->id => ['FINE' => 3],
        ]);

        $this->assertSame(3.0, (float) $lines['linen']->fresh()->returned_quantity);

        // Idempotent: no duplicate return record, return line, or finding.
        $this->assertSame(
            1,
            ReturnTransaction::query()
                ->where('custody_transaction_id', $custody->id)
                ->count()
        );

        $this->assertSame(
            1,
            ReturnLine::query()
                ->where('custody_line_id', $lines['linen']->id)
                ->count()
        );

        $this->assertSame(
            0,
            Incident::query()
                ->where('custody_transaction_id', $custody->id)
                ->count()
        );

        // The recorded quantity is the issued quantity, not a doubled one.
        $this->assertSame(
            3.0,
            (float) ReturnLine::query()
                ->where('custody_line_id', $lines['linen']->id)
                ->sum('quantity_received')
        );
    }

    public function test_mixed_custody_records_non_linen_and_linen_as_separate_complete_return_branches(): void
    {
        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(
            linen: true,
            nonLinen: true
        );

        $this->uploadAccomplishedForm($job)->assertSessionHasNoErrors();

        // The two physical channels keep different authoritative timestamps,
        // so they may not be collapsed into one ReturnTransaction.
        $this->recordReturn($custody, [
            $lines['linen']->id => ['FINE' => 3],
            $lines['non_linen']->id => ['FINE' => 2],
        ])->assertSessionHasErrors('return');

        $this->assertSame(0.0, (float) $lines['linen']->fresh()->returned_quantity);
        $this->assertSame(0.0, (float) $lines['non_linen']->fresh()->returned_quantity);

        // Complete the AO-inspected non-linen branch first.
        $this->recordReturn($custody, [
            $lines['non_linen']->id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        // Then encode the complete linen branch from the accomplished form.
        $this->recordReturn($custody, [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3.0, (float) $lines['linen']->fresh()->returned_quantity);
        $this->assertSame(2.0, (float) $lines['non_linen']->fresh()->returned_quantity);

        $this->assertSame(
            2,
            ReturnTransaction::query()
                ->where('custody_transaction_id', $custody->id)
                ->count()
        );

        // Not double-counted: exactly one ReturnLine per branch, each for the
        // exact quantity submitted - never a duplicate row and never the
        // released quantity applied twice.
        $this->assertSame(1, ReturnLine::query()->where('custody_line_id', $lines['linen']->id)->count());
        $this->assertSame(
            3.0,
            (float) ReturnLine::query()->where('custody_line_id', $lines['linen']->id)->sum('quantity_received')
        );
        $this->assertSame(1, ReturnLine::query()->where('custody_line_id', $lines['non_linen']->id)->count());

        // The linen ReturnLine is recorded against LAUNDRY, not directly
        // AVAILABLE - restoration to Available only happens through the
        // authorized Laundry completion path this test already drove via
        // uploadAccomplishedForm() beforehand, never as a side effect of the
        // non-linen branch or of encoding by itself.
        $linenReturnLine = ReturnLine::query()->where('custody_line_id', $lines['linen']->id)->firstOrFail();
        $this->assertSame('LAUNDRY', $linenReturnLine->disposition_state);

        $jobLine = LaundryJobLine::query()->where('laundry_job_id', $job->id)->firstOrFail();
        $this->assertSame('LAUNDRY_COMPLETED', $job->fresh()->status);
        $this->assertSame(3, (int) $jobLine->fresh()->completed_quantity);
    }

    public function test_repeated_linen_return_submission_does_not_duplicate_quantity(): void
    {
        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(
            linen: true,
            nonLinen: false
        );

        $this->uploadAccomplishedForm($job)->assertSessionHasNoErrors();

        $this->recordReturn($custody, [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3.0, (float) $lines['linen']->fresh()->returned_quantity);

        // Replaying the exact same submission is caught by
        // PreventDuplicateSubmission (an identical fingerprint within its
        // completed-action window) before the request ever reaches the
        // controller/service again - it redirects back with a "status" notice
        // rather than an 'errors' bag, and applies nothing a second time.
        $this->recordReturn($custody, [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'This action was already completed. No duplicate transaction was created.');

        $this->assertSame(3.0, (float) $lines['linen']->fresh()->returned_quantity);
        $this->assertSame(1, ReturnLine::query()->where('custody_line_id', $lines['linen']->id)->count());
        $this->assertSame(
            3.0,
            (float) ReturnLine::query()->where('custody_line_id', $lines['linen']->id)->sum('quantity_received')
        );
    }

    public function test_adverse_non_linen_finding_creates_incident_without_waiting_for_linen_completion(): void
    {
        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(
            linen: true,
            nonLinen: true
        );

        $this->uploadAccomplishedForm($job)->assertSessionHasNoErrors();

        // Complete only the non-linen branch, with an adverse finding. The
        // linen branch is left entirely untouched.
        $this->recordReturn($custody, [
            $lines['non_linen']->id => ['DAMAGED' => 2],
        ], [
            'evidence_files' => [$lines['non_linen']->id => UploadedFile::fake()->create('damage.pdf', 10, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2.0, (float) $lines['non_linen']->fresh()->returned_quantity);
        $this->assertSame(0.0, (float) $lines['linen']->fresh()->returned_quantity);

        // Accountability for the non-linen damage fires immediately - it must
        // not wait for the still-outstanding linen branch to be completed.
        $this->assertSame(
            1,
            Incident::query()
                ->where('custody_transaction_id', $custody->id)
                ->where('incident_type', 'DAMAGED')
                ->count()
        );

        $this->assertDatabaseHas('borrower_restrictions', [
            'custody_transaction_id' => $custody->id,
            'status' => 'ACTIVE',
        ]);

        // The linen branch, still outstanding, has not been auto-completed or
        // auto-restored by the unrelated non-linen incident.
        $this->assertSame('FOR_LAUNDRY', $job->fresh()->status);
    }

    public function test_non_linen_branch_cannot_split_outstanding_item_types_across_returns(): void
    {
        ['custody' => $custody, 'lines' => $lines] = $this->outstandingCustody(linen: false);

        $version = $custody->request->currentVersion;

        /*
         * inventoryItem(false) deterministically returns the same item
         * outstandingCustody() already used for $lines['non_linen']; a
         * second, genuinely distinct item type is required here so
         * request_items' (request_version_id, inventory_item_id) unique
         * constraint isn't violated.
         */
        $secondItem = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->where('laundry_required', false)
            ->whereKeyNot($this->inventoryItem(false)->id)
            ->firstOrFail();

        $secondLine = $this->custodyLine(
            $custody,
            $version,
            $secondItem,
            1
        );

        // Completing only one of two outstanding non-linen item types is a
        // partial branch return and must be rejected.
        $this->recordReturn($custody, [
            $lines['non_linen']->id => ['FINE' => 2],
        ])->assertSessionHasErrors('return');

        $this->assertSame(0.0, (float) $lines['non_linen']->fresh()->returned_quantity);
        $this->assertSame(0.0, (float) $secondLine->fresh()->returned_quantity);

        $this->recordReturn($custody, [
            $lines['non_linen']->id => ['FINE' => 2],
            $secondLine->id => ['FINE' => 1],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2.0, (float) $lines['non_linen']->fresh()->returned_quantity);
        $this->assertSame(1.0, (float) $secondLine->fresh()->returned_quantity);
    }

    public function test_mixed_custody_allows_non_linen_inspection_while_laundry_form_is_pending_without_partial_status(): void
    {
        ['custody' => $custody, 'lines' => $lines] = $this->outstandingCustody(
            linen: true,
            nonLinen: true
        );

        // The pending linen document must not disable the whole inspection.
        $this->recordReturn($custody, [
            $lines['non_linen']->id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2.0, (float) $lines['non_linen']->fresh()->returned_quantity);
        $this->assertSame(0.0, (float) $lines['linen']->fresh()->returned_quantity);

        // No PARTIALLY_RETURNED state is introduced. The single overall
        // transaction simply stays in Return Processing until linen can be
        // encoded from the accomplished Laundry Form.
        $this->assertSame('RETURN_PROCESSING', $custody->fresh()->status);
        $this->assertNotSame('PARTIALLY_RETURNED', $custody->fresh()->status);

        // Linen itself is still protected until the signed form arrives.
        $this->recordReturn($custody, [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasErrors('laundry_form');
    }

    public function test_linen_return_uses_actual_laundry_receipt_date_when_worker_delivers_form_two_days_later(): void
    {
        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(
            linen: true,
            nonLinen: true
        );

        $actualReturnDate = $custody->due_at->copy()->startOfDay();

        // Non-linen is recorded on the due date while the Laundry Worker still
        // has the physical form.
        $this->recordReturn($custody, [
            $lines['non_linen']->id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        // Two days later the offline Laundry Worker delivers the accomplished
        // form to SPMU. The Action Officer transcribes the original receipt date.
        $this->travelTo(now()->addDays(2)->setTime(10, 0));

        $this->uploadAccomplishedForm($job, $actualReturnDate->toDateString())
            ->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertNull($job->worker_name);
        $this->assertSame(
            $actualReturnDate->toDateString(),
            $job->worker_received_at?->toDateString()
        );

        $this->recordReturn($custody->fresh(), [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasNoErrors();

        $linenReturn = ReturnTransaction::query()
            ->where('custody_transaction_id', $custody->id)
            ->latest('id')
            ->firstOrFail();

        // Administrative delay does not make the borrower late.
        $this->assertSame('NORMAL', $linenReturn->return_type);
        $this->assertSame(
            $actualReturnDate->toDateString(),
            $linenReturn->received_at?->toDateString()
        );

        // A delayed paper delivery does not create a late-return accountability
        // when the Laundry Worker's actual RECEIVED BY date was on time.
        $this->assertFalse(
            OverdueCase::query()
                ->where('custody_transaction_id', $custody->id)
                ->where('status', '!=', 'RESOLVED')
                ->exists()
        );
    }

    public function test_mixed_custody_lateness_uses_the_later_authoritative_physical_return_date(): void
    {
        /*
         * setUp() pins "now" to a Monday. CustodyService::receiveReturn()
         * runs synchronizeCustodyDueDate() first, which pushes a due_at
         * falling on a closed day forward to the next open day - so
         * "yesterday" (Sunday, closed) would silently collapse back onto
         * this same Monday, making the return look on-time instead of one
         * day late. Move to a Wednesday so "yesterday" (Tuesday) is a
         * genuinely open day and the intended one-day gap survives sync.
         */
        $this->travelTo(Carbon::now()->next(Carbon::WEDNESDAY)->setTime(10, 0));

        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(
            linen: true,
            nonLinen: true
        );

        $custody->update([
            'released_at' => now()->subDays(2)->setTime(9, 0),
            'due_at' => now()->subDay()->endOfDay(),
        ]);

        $lateFee = SystemSetting::query()
            ->where('setting_key', 'daily_overdue_tariff')
            ->firstOrFail();
        $lateFee->value_json = 50;
        $lateFee->save();

        /*
         * Laundry received its COMPLETE linen channel on the due date, but the
         * COMPLETE AO-inspected non-linen channel comes back one day later.
         * This is not a quantity/item-type partial return: mixed custody has two
         * distinct authoritative physical channels and each channel is complete.
         */
        $this->uploadAccomplishedForm(
            $job->fresh(),
            $custody->due_at->copy()->startOfDay()->toDateString()
        )->assertSessionHasNoErrors();

        $this->recordReturn($custody->fresh(), [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasNoErrors();

        $this->recordReturn($custody->fresh(), [
            $lines['non_linen']->id => ['FINE' => 2],
        ])->assertSessionHasNoErrors();

        $case = OverdueCase::query()
            ->where('custody_transaction_id', $custody->id)
            ->firstOrFail();

        $this->assertSame(now()->toDateString(), $case->actual_return_date?->toDateString());
        $this->assertSame('MIXED_RETURN_COMPLETION', $case->return_date_source);
        $this->assertSame(1, (int) $case->late_days);
        $this->assertSame(50.0, (float) $case->accrued_amount);
    }

    public function test_actual_linen_return_date_can_be_after_due_date_once_that_date_has_arrived(): void
    {
        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(linen: true);

        // Simulate a form that reaches SPMU two days after the actual
        // physical linen return. The custody was due three days ago and the
        // Laundry Worker received the linen one day late (two days ago). The
        // calendar must accept that real date, and Accountability Processing
        // must charge only that one late day — never the later encoding date.
        $custody->update([
            'released_at' => now()->subDays(4)->setTime(9, 0),
            'due_at' => now()->subDays(3)->endOfDay(),
        ]);

        $actualReturnDate = now()->subDays(2)->toDateString();

        $lateFee = SystemSetting::query()
            ->where('setting_key', 'daily_overdue_tariff')
            ->firstOrFail();
        $lateFee->value_json = 50;
        $lateFee->save();

        $this->uploadAccomplishedForm($job->fresh(), $actualReturnDate)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $actualReturnDate,
            $job->fresh()->worker_received_at?->toDateString()
        );

        $this->recordReturn($custody->fresh(), [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasNoErrors();

        $linenReturn = ReturnTransaction::query()
            ->where('custody_transaction_id', $custody->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('OVERDUE', $linenReturn->return_type);
        $this->assertSame($actualReturnDate, $linenReturn->received_at?->toDateString());

        // The late actual Laundry receipt date is not only a display label.
        // Completing the SPMU return encoding must immediately hand the case
        // to Accountability Processing even if the daily overdue scheduler has
        // not run yet.
        $overdue = OverdueCase::query()
            ->where('custody_transaction_id', $custody->id)
            ->firstOrFail();

        $this->assertSame('RETURNED_PENDING_SETTLEMENT', $overdue->status);
        $this->assertSame(50.0, (float) $overdue->rate_snapshot);
        $this->assertSame(50.0, (float) $overdue->accrued_amount);
        $this->assertSame(
            $custody->due_at->copy()->startOfDay()->toDateString(),
            $overdue->grace_expires_at?->toDateString()
        );
        $this->assertSame(
            $custody->due_at->copy()->addDay()->startOfDay()->toDateString(),
            $overdue->overdue_started_at?->toDateString()
        );
        $this->assertSame('OBLIGATION_OPEN', $custody->fresh()->status);

        $this->assertDatabaseHas('borrower_restrictions', [
            'borrower_user_id' => $custody->borrower_user_id,
            'restriction_type' => 'OVERDUE_RETURN',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_action_officer_can_encode_a_much_later_actual_linen_return_date_and_accountability_uses_all_late_days(): void
    {
        /*
         * setUp() pins "now" to a Monday, and 15 days before a Monday is
         * also a Sunday - a closed day that synchronizeCustodyDueDate()
         * would push forward to the next open day (this same Monday),
         * quietly shrinking the intended 12-day gap to 11. Move to a
         * Wednesday so "15 days ago" lands on a Tuesday, a genuinely open
         * weekday, and the full 12-day gap survives the due-date sync.
         */
        $this->travelTo(Carbon::now()->next(Carbon::WEDNESDAY)->setTime(10, 0));

        ['custody' => $custody, 'lines' => $lines, 'job' => $job] = $this->outstandingCustody(linen: true);

        // The expected return was 15 days ago, but the Laundry Worker actually
        // received the linen only 3 days ago. The Action Officer is encoding the
        // accomplished form today, so the exact RECEIVED BY date is 12 calendar
        // days late. The calendar/backend must not cap the entry at the due date
        // or replace it with today's SPMU upload date.
        $custody->update([
            'released_at' => now()->subDays(20)->setTime(9, 0),
            'due_at' => now()->subDays(15)->endOfDay(),
        ]);

        $actualReturnDate = now()->subDays(3)->toDateString();

        $lateFee = SystemSetting::query()
            ->where('setting_key', 'daily_overdue_tariff')
            ->firstOrFail();
        $lateFee->value_json = 25;
        $lateFee->save();

        $this->uploadAccomplishedForm($job->fresh(), $actualReturnDate)
            ->assertSessionHasNoErrors();

        $this->recordReturn($custody->fresh(), [
            $lines['linen']->id => ['FINE' => 3],
        ])->assertSessionHasNoErrors();

        $linenReturn = ReturnTransaction::query()
            ->where('custody_transaction_id', $custody->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('OVERDUE', $linenReturn->return_type);
        $this->assertSame($actualReturnDate, $linenReturn->received_at?->toDateString());

        $overdue = OverdueCase::query()
            ->where('custody_transaction_id', $custody->id)
            ->firstOrFail();

        $this->assertSame('RETURNED_PENDING_SETTLEMENT', $overdue->status);
        $this->assertSame(25.0, (float) $overdue->rate_snapshot);
        $this->assertSame(300.0, (float) $overdue->accrued_amount);
        $this->assertSame('OBLIGATION_OPEN', $custody->fresh()->status);
    }

    public function test_future_linen_return_date_is_rejected(): void
    {
        ['job' => $job] = $this->outstandingCustody(linen: true);

        $this->uploadAccomplishedForm($job, now()->addDay()->toDateString())
            ->assertSessionHasErrors('laundry_received_on');

        $this->assertNull($job->fresh()->worker_received_at);
        $this->assertNull($job->fresh()->form_verified_at);
    }

    public function test_borrower_cannot_finalize_the_spmu_return_inspection(): void
    {
        ['custody' => $custody, 'lines' => $lines] = $this->outstandingCustody(linen: false);

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($custody->borrower)
            ->post(route('custody.return', $custody), [
                'accounting' => [$lines['non_linen']->id => ['FINE' => 2]],
            ])
            ->assertForbidden();

        $this->assertSame(
            0.0,
            (float) $lines['non_linen']->fresh()->returned_quantity
        );
    }

    public function test_the_same_laundry_form_document_is_reused_for_the_return(): void
    {
        ['custody' => $custody, 'job' => $job] = $this->outstandingCustody(linen: true);

        $documentId = $job->generated_document_id;

        $this->uploadAccomplishedForm($job)->assertSessionHasNoErrors();

        $job->refresh();

        // The travelling release form is verified in place; no second
        // return-only Laundry Form is generated.
        $this->assertSame($documentId, $job->generated_document_id);
        $this->assertNotNull($job->form_verified_at);

        $this->assertSame(
            1,
            GeneratedDocument::query()
                ->where('subject_type', CustodyTransaction::class)
                ->where('subject_id', $custody->id)
                ->where('document_type', 'LAUNDRY_FORM')
                ->count()
        );
    }

    /**
     * POST the SPMU return inspection as the Action Officer.
     */
    private function recordReturn(
        CustodyTransaction $custody,
        array $accounting,
        array $extra = []
    ) {
        return $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->classificationUser(AccessClassification::SpmuOfficer))
            ->post(
                route('custody.return', $custody),
                array_merge(['accounting' => $accounting], $extra)
            );
    }

    private function uploadAccomplishedForm(LaundryJob $job, ?string $laundryReceivedOn = null)
    {
        return $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->classificationUser(AccessClassification::SpmuOfficer))
            ->post(route('laundry.spmu.upload-form', $job), [
                'evidence' => UploadedFile::fake()->create(
                    'accomplished-laundry-form.pdf',
                    20,
                    'application/pdf'
                ),
                'laundry_received_on' => $laundryReceivedOn ?: now()->toDateString(),
            ]);
    }

    /**
     * Build a released custody whose lines are still fully outstanding, in the
     * same shape CustodyService::release() leaves behind.
     *
     * @return array{custody: CustodyTransaction, lines: array<string, CustodyLine>, job: ?LaundryJob}
     */
    private function outstandingCustody(bool $linen, bool $nonLinen = false): array
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);

        $request = BorrowingRequest::create([
            'request_no' => 'BR-LINEN-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Linen return inspection test',
            'location' => 'CSPC Campus',
            'needed_from' => now()->subDay(),
            'return_due_at' => now()->endOfDay(),
            'event_details' => 'Borrower returns linen through the Laundry Area first.',
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        $custody = CustodyTransaction::create([
            'custody_no' => 'CUS-LINEN-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'ACTIVE',
            'released_at' => now()->subHours(6),
            'due_at' => now()->endOfDay(),
        ]);

        $lines = [];
        $job = null;

        if ($linen) {
            $lines['linen'] = $this->custodyLine(
                $custody,
                $version,
                $this->inventoryItem(true),
                3
            );

            $document = $this->laundryFormDocument($custody, $version);

            $job = LaundryJob::create([
                'custody_transaction_id' => $custody->id,
                'generated_document_id' => $document->id,
                'status' => 'FOR_LAUNDRY',
            ]);

            LaundryJobLine::create([
                'laundry_job_id' => $job->id,
                'custody_line_id' => $lines['linen']->id,
                'issued_quantity' => 3,
                'affected_quantity' => 0,
            ]);
        }

        if ($nonLinen || ! $linen) {
            $lines['non_linen'] = $this->custodyLine(
                $custody,
                $version,
                $this->inventoryItem(false),
                2
            );
        }

        return [
            'custody' => $custody->fresh(['lines', 'borrower']),
            'lines' => $lines,
            'job' => $job,
        ];
    }

    private function custodyLine(
        CustodyTransaction $custody,
        $version,
        InventoryItem $item,
        int $quantity
    ): CustodyLine {
        $requestItem = RequestItem::create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => $quantity,
            'approved_quantity' => $quantity,
            'use_location' => 'ON_CAMPUS',
        ]);

        $allocation = Allocation::create([
            'request_item_id' => $requestItem->id,
            'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at,
            'allocated_quantity' => $quantity,
            'released_quantity' => $quantity,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => now()->subDay(),
        ]);

        return CustodyLine::create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => $quantity,
            'quantity_to_receive' => $quantity,
            'actual_released_quantity' => $quantity,
            'returned_quantity' => 0,
            'release_condition' => 'SERVICEABLE',
            'item_status' => 'RELEASED_PENDING_RETURN',
            'compliance_status' => $item->laundry_required
                ? 'LAUNDRY_FORM_READY'
                : 'NOT_REQUIRED',
        ]);
    }

    private function laundryFormDocument(
        CustodyTransaction $custody,
        $version
    ): GeneratedDocument {
        $bytes = '%PDF-1.4 travelling laundry form';
        $path = 'tests/laundry/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, $bytes);

        $storedFile = StoredFile::create([
            'uploaded_by_user_id' => null,
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'laundry-form.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => 'CONTROLLED_DOCUMENT',
        ]);

        return GeneratedDocument::create([
            'stored_file_id' => $storedFile->id,
            'request_version_id' => $version->id,
            'subject_type' => CustodyTransaction::class,
            'subject_id' => $custody->id,
            'document_no' => 'DOC-LINEN-'.uniqid(),
            'document_type' => 'LAUNDRY_FORM',
            'version_no' => 1,
            'sha256' => $storedFile->sha256,
            'status' => 'FINAL',
            'generated_at' => now(),
        ]);
    }

    private function inventoryItem(bool $laundryRequired): InventoryItem
    {
        return InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->where('laundry_required', $laundryRequired)
            ->firstOrFail();
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }
}
