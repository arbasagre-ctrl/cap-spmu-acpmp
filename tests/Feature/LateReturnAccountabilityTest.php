<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\BorrowerRestriction;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\LaundryJob;
use App\Models\OverdueCase;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\LateReturnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The late-return lifecycle.
 *
 * Overdue and Returned Late are separate states and must stay separate: a
 * borrower who has not returned anything cannot be assessed a final fee, and a
 * borrower who has returned late must not keep accruing one.
 *
 * These tests drive LateReturnService directly and through its HTTP actions.
 * They deliberately build custody rows rather than replaying the whole
 * borrowing workflow, which CompleteWorkflowTest already covers.
 */
class LateReturnAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    private LateReturnService $lateReturns;

    private Carbon $expectedReturn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lateReturns = app(LateReturnService::class);

        SystemSetting::query()->updateOrCreate(
            ['setting_key' => 'daily_overdue_tariff'],
            [
                'value_json' => 75,
                'data_type' => 'MONEY',
                'group_code' => 'PENALTY',
                'description' => 'Fixture tariff.',
                'status' => 'ACTIVE',
            ]
        );

        $this->expectedReturn = Carbon::create(2026, 9, 1)->endOfDay();

        /* Ten days after the expected return, so "today" is well past due. */
        Carbon::setTestNow(Carbon::create(2026, 9, 11, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * A user carrying both the classification and the SPMU role row, because
     * the accountability routes authorise on the assigned role, not on the
     * classification alone.
     */
    private function user(AccessClassification $classification): User
    {
        $user = User::factory()->create(['access_classification' => $classification]);

        $isSpmu = in_array($classification, [
            AccessClassification::SpmuHead,
            AccessClassification::SpmuOfficer,
        ], true);

        if ($isSpmu) {
            $role = Role::query()->firstOrCreate(
                ['role_code' => UserRole::Spmu->value],
                ['role_name' => 'SPMU', 'active' => true]
            );

            $user->roles()->syncWithoutDetaching([
                $role->id => ['assigned_at' => now()],
            ]);
        }

        return $user->fresh();
    }

    private function borrower(): User
    {
        return $this->user(AccessClassification::BorrowerOnly);
    }

    private function officer(): User
    {
        return $this->user(AccessClassification::SpmuOfficer);
    }

    private function spmuHead(): User
    {
        return $this->user(AccessClassification::SpmuHead);
    }

    /**
     * A released custody carrying one line, optionally linen.
     */
    private function custody(User $borrower, bool $linen = false): CustodyTransaction
    {
        $category = \App\Models\InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'LATE'],
            ['category_name' => 'Late Fixture', 'active' => true]
        );

        $measure = \App\Models\UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        $item = \App\Models\InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => ($linen ? 'Linen ' : 'Equipment ').fake()->unique()->numberBetween(1, 99999),
            'total_quantity' => 50,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => $linen,
            'provisional' => false,
            'active' => true,
        ]);

        $unit = \App\Models\OrganizationalUnit::query()->firstOrCreate(
            ['unit_code' => 'LATEFIX'],
            ['unit_name' => 'Late Fixture Unit', 'unit_type' => 'OFFICE', 'active' => true]
        );

        $request = \App\Models\BorrowingRequest::query()->create([
            'request_no' => 'BR-LATE-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $unit->id,
            'current_version_no' => 1,
            'status' => \App\Enums\RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = \App\Models\RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Late return fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'College of Computer Studies',
            'schedule_date' => '2026-08-28',
            'return_date' => $this->expectedReturn->toDateString(),
            'needed_from' => Carbon::create(2026, 8, 28)->startOfDay(),
            'return_due_at' => $this->expectedReturn,
        ]);

        $requestItem = \App\Models\RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => 5,
            'approved_quantity' => 5,
        ]);

        $allocation = \App\Models\Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => Carbon::create(2026, 8, 28)->startOfDay(),
            'period_end' => $this->expectedReturn,
            'allocated_quantity' => 5,
            'released_quantity' => 5,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => Carbon::create(2026, 8, 28),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-LATE-'.fake()->unique()->numberBetween(1000, 999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'OVERDUE',
            'due_at' => $this->expectedReturn,
            'released_at' => Carbon::create(2026, 8, 28, 9),
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 5,
            'quantity_to_receive' => 5,
            'actual_released_quantity' => 5,
            'returned_quantity' => 0,
        ]);

        return $custody->fresh(['lines.requestItem.inventoryItem']);
    }

    /** An open overdue case, as the deadline job would have created it. */
    private function overdueCase(CustodyTransaction $custody): OverdueCase
    {
        return OverdueCase::query()->create([
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'grace_expires_at' => $custody->due_at,
            'overdue_started_at' => $this->expectedReturn->copy()->addDay()->startOfDay(),
            'offense_level' => 1,
            'rate_snapshot' => 75,
            'accrued_amount' => 750,
            'status' => LateReturnService::STATUS_OVERDUE,
        ]);
    }

    /** Record the physical return of every line on a given date. */
    private function recordReturn(CustodyTransaction $custody, Carbon $receivedAt, User $receiver): void
    {
        $custody->lines->each(fn (CustodyLine $line) => $line->update([
            'returned_quantity' => $line->actual_released_quantity,
        ]));

        $returnId = DB::table('return_transactions')->insertGetId([
            'return_no' => 'RT-LATE-'.fake()->unique()->numberBetween(1000, 999999),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $receiver->id,
            'return_type' => 'OVERDUE',
            'received_at' => $receivedAt,
            'status' => 'INSPECTED',
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);

        foreach ($custody->lines as $line) {
            DB::table('return_lines')->insert([
                'return_transaction_id' => $returnId,
                'custody_line_id' => $line->id,
                'quantity_received' => $line->actual_released_quantity,
                'condition_code' => 'SERVICEABLE',
                'disposition_state' => 'AVAILABLE',
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);
        }
    }

    /* ================================================================== */
    /* Non-linen                                                          */
    /* ================================================================== */

    public function test_an_unreturned_overdue_item_has_no_final_assessment(): void
    {
        $custody = $this->custody($this->borrower());
        $case = $this->overdueCase($custody);

        $assessment = $this->lateReturns->assessment($case);

        $this->assertTrue($assessment['is_estimate'], 'An unreturned item can only be estimated.');
        $this->assertNull($assessment['actual_return_date']);
        $this->assertNull($case->actual_return_date);
        $this->assertSame(LateReturnService::STATUS_OVERDUE, $case->status);
    }

    public function test_an_on_time_return_opens_no_late_return_case(): void
    {
        $custody = $this->custody($this->borrower());
        $officer = $this->officer();

        $this->recordReturn($custody, Carbon::create(2026, 9, 1, 10), $officer);

        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        $this->assertNull($case, 'A return on the expected date is not a late return.');
        $this->assertDatabaseCount('overdue_cases', 0);
    }

    public function test_a_late_return_is_classified_for_action_officer_confirmation(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();

        /* Returned 04 Sep against an expected 01 Sep: three late days. */
        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);

        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        $this->assertNotNull($case);
        $this->assertSame(LateReturnService::STATUS_FOR_AO_CONFIRMATION, $case->status);
        $this->assertSame('2026-09-04', $case->actual_return_date->toDateString());
        $this->assertSame('RETURN_INSPECTION', $case->return_date_source);
        $this->assertSame(3, (int) $case->late_days);
        $this->assertSame(225.0, (float) $case->accrued_amount, '3 late days x 75.');
    }

    public function test_the_fee_stops_growing_after_the_physical_return(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        /* A fortnight later the frozen figures must be unchanged. */
        Carbon::setTestNow(Carbon::create(2026, 9, 25, 9));

        $assessment = $this->lateReturns->assessment($case->fresh());

        $this->assertFalse($assessment['is_estimate']);
        $this->assertSame(3, $assessment['late_days'], 'Late days are frozen at the return date.');
        $this->assertSame(225.0, $assessment['amount']);
    }

    public function test_confirmation_is_refused_while_the_item_is_still_unreturned(): void
    {
        $custody = $this->custody($this->borrower());
        $case = $this->overdueCase($custody);

        $this->expectException(ValidationException::class);

        $this->lateReturns->confirm($case, $this->officer());
    }

    public function test_confirmation_forwards_the_assessment_to_the_head(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        $confirmed = $this->lateReturns->confirm($case, $officer);

        $this->assertSame(LateReturnService::STATUS_FOR_HEAD_APPROVAL, $confirmed->status);
        $this->assertSame($officer->id, $confirmed->ao_confirmed_by_user_id);
        $this->assertNotNull($confirmed->ao_confirmed_at);
    }

    public function test_confirming_twice_does_not_forward_twice(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        $first = $this->lateReturns->confirm($case, $officer);
        $confirmedAt = $first->ao_confirmed_at;

        $second = $this->lateReturns->confirm($first, $this->officer());

        $this->assertSame(LateReturnService::STATUS_FOR_HEAD_APPROVAL, $second->status);
        $this->assertEquals($confirmedAt, $second->ao_confirmed_at, 'The first confirmation stands.');
    }

    public function test_the_head_cannot_bill_before_the_officer_confirms(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->spmuHead())
            ->post(route('overdue.bill', $case), ['basis' => 'Premature.'])
            ->assertSessionHasErrors('overdue');

        $this->assertDatabaseCount('billing_statements', 0);
    }

    public function test_the_head_cannot_bill_a_case_that_is_still_overdue(): void
    {
        $custody = $this->custody($this->borrower());
        $case = $this->overdueCase($custody);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->spmuHead())
            ->post(route('overdue.bill', $case), ['basis' => 'Still out.'])
            ->assertSessionHasErrors('overdue');

        $this->assertDatabaseCount('billing_statements', 0);
    }

    public function test_returning_for_correction_preserves_the_case_and_sends_it_back(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);
        $this->lateReturns->confirm($case, $officer);

        $returned = $this->lateReturns->returnForCorrection(
            $case->fresh(),
            $this->spmuHead(),
            'The recorded return date needs rechecking.'
        );

        $this->assertSame(LateReturnService::STATUS_FOR_AO_CONFIRMATION, $returned->status);
        $this->assertNull($returned->ao_confirmed_at);
        $this->assertStringContainsString('rechecking', $returned->correction_remarks);
        /* The case is preserved with its history, not replaced. */
        $this->assertDatabaseCount('overdue_cases', 1);
    }

    /* ================================================================== */
    /* Linen                                                              */
    /* ================================================================== */

    /** The date Laundry Personnel received the linen, not the AO's date. */
    private function laundryJob(CustodyTransaction $custody, ?Carbon $workerReceivedAt, ?Carbon $formVerifiedAt = null): LaundryJob
    {
        return LaundryJob::query()->create([
            'custody_transaction_id' => $custody->id,
            'status' => 'TURNED_OVER_TO_LAUNDRY',
            'worker_name' => 'Laundry Personnel',
            'worker_received_at' => $workerReceivedAt,
            'form_verified_at' => $formVerifiedAt,
        ]);
    }

    public function test_linen_uses_the_laundry_received_date_as_the_physical_return_date(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $this->overdueCase($custody);
        $officer = $this->officer();

        /*
         * Laundry received the linen on 03 Sep. The accomplished form only
         * reached the Action Officer on 05 Sep. The borrower is charged for
         * two late days, not four.
         */
        $this->laundryJob(
            $custody,
            Carbon::create(2026, 9, 3, 11),
            Carbon::create(2026, 9, 5, 16)
        );

        $this->recordReturn($custody, Carbon::create(2026, 9, 5, 16), $officer);

        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns', 'laundryJob']), $officer);

        $this->assertNotNull($case);
        $this->assertSame('2026-09-03', $case->actual_return_date->toDateString());
        $this->assertSame('LAUNDRY_RECEIPT', $case->return_date_source);
        $this->assertSame(2, (int) $case->late_days, 'Internal forwarding delay adds no late days.');
        $this->assertSame(150.0, (float) $case->accrued_amount);
    }

    public function test_a_later_action_officer_attestation_adds_no_late_days(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $this->overdueCase($custody);
        $officer = $this->officer();

        $job = $this->laundryJob($custody, Carbon::create(2026, 9, 3, 11));
        $this->recordReturn($custody, Carbon::create(2026, 9, 3, 11), $officer);

        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns', 'laundryJob']), $officer);
        $before = (int) $case->late_days;

        /* The Action Officer attests the form a week later. */
        Carbon::setTestNow(Carbon::create(2026, 9, 10, 9));
        $job->update(['form_verified_at' => now(), 'form_verified_by_user_id' => $officer->id]);

        $after = $this->lateReturns->assess($custody->fresh(['lines', 'returns', 'laundryJob']), $officer);

        $this->assertSame($before, (int) $after->late_days);
        $this->assertSame('2026-09-03', $after->actual_return_date->toDateString());
    }

    public function test_linen_without_a_laundry_receipt_stays_awaiting_return(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $case = $this->overdueCase($custody);
        $officer = $this->officer();

        /* No laundry job at all: the linen has not reached Laundry yet. */
        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);

        $assessed = $this->lateReturns->assess($custody->fresh(['lines', 'returns', 'laundryJob']), $officer);

        $this->assertNull($assessed, 'Linen has no authoritative return date until Laundry receives it.');
        $this->assertSame(LateReturnService::STATUS_OVERDUE, $case->fresh()->status);
    }

    public function test_late_linen_still_requires_officer_confirmation_before_the_head(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $this->overdueCase($custody);
        $officer = $this->officer();

        $this->laundryJob($custody, Carbon::create(2026, 9, 3, 11));
        $this->recordReturn($custody, Carbon::create(2026, 9, 3, 11), $officer);

        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns', 'laundryJob']), $officer);

        $this->assertSame(LateReturnService::STATUS_FOR_AO_CONFIRMATION, $case->status);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->spmuHead())
            ->post(route('overdue.bill', $case), ['basis' => 'Too early.'])
            ->assertSessionHasErrors('overdue');
    }

    /* ================================================================== */
    /* Laundry Received Date attested by the Action Officer               */
    /* ================================================================== */

    public function test_a_digital_laundry_receipt_is_authoritative_and_never_overwritten(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $officer = $this->officer();

        $job = $this->laundryJob($custody, Carbon::create(2026, 9, 3, 11));

        /* An attempted attestation of a different date must not replace it. */
        $this->lateReturns->recordLaundryReceipt(
            $custody->fresh(['laundryJob']),
            Carbon::create(2026, 9, 8),
            $officer
        );

        $this->assertSame(
            '2026-09-03',
            $job->fresh()->worker_received_at->toDateString(),
            'Laundry Personnel remain authoritative for their own receipt.'
        );
    }

    public function test_linen_without_a_receipt_date_needs_the_officer_to_attest_it(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $this->laundryJob($custody, null);

        $this->assertTrue($this->lateReturns->needsLaundryReceiptDate($custody->fresh(['laundryJob'])));

        /* No authoritative date means no assessment can be produced. */
        $officer = $this->officer();
        $this->recordReturn($custody, Carbon::create(2026, 9, 5, 16), $officer);

        $this->assertNull(
            $this->lateReturns->assess($custody->fresh(['lines', 'returns', 'laundryJob']), $officer)
        );
    }

    public function test_the_attested_form_date_becomes_the_authoritative_return_date(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $this->overdueCase($custody);
        $this->laundryJob($custody, null);
        $officer = $this->officer();

        /* The RECEIVED BY portion of the form reads 03 Sep. */
        $this->lateReturns->recordLaundryReceipt(
            $custody->fresh(['laundryJob']),
            Carbon::create(2026, 9, 3),
            $officer
        );

        $this->recordReturn($custody, Carbon::create(2026, 9, 5, 16), $officer);

        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns', 'laundryJob']), $officer);

        $this->assertSame('2026-09-03', $case->actual_return_date->toDateString());
        $this->assertSame('LAUNDRY_RECEIPT', $case->return_date_source);
        $this->assertSame(2, (int) $case->late_days, 'Two late days, not four.');
    }

    public function test_the_attested_date_is_recorded_in_the_audit_history(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $this->laundryJob($custody, null);
        $officer = $this->officer();

        $this->lateReturns->recordLaundryReceipt(
            $custody->fresh(['laundryJob']),
            Carbon::create(2026, 9, 3),
            $officer
        );

        $this->assertDatabaseHas('audit_events', [
            'action_code' => 'LAUNDRY_RECEIVED_DATE_ATTESTED',
        ]);

        /* Who attested it, when, and that it came from the signed form. */
        $entry = DB::table('audit_events')
            ->where('action_code', 'LAUNDRY_RECEIVED_DATE_ATTESTED')
            ->latest('id')
            ->first();

        $payload = json_decode($entry->after_json, true);

        $this->assertSame('2026-09-03', $payload['laundry_received_at']);
        $this->assertSame('ACCOMPLISHED_LAUNDRY_FORM', $payload['source']);
        $this->assertSame($officer->id, $payload['attested_by_user_id']);
        $this->assertNotEmpty($payload['attested_at']);
    }

    public function test_a_future_laundry_received_date_is_refused(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $this->laundryJob($custody, null);

        $this->expectException(ValidationException::class);

        $this->lateReturns->recordLaundryReceipt(
            $custody->fresh(['laundryJob']),
            now()->addDay(),
            $this->officer()
        );
    }

    public function test_a_laundry_received_date_before_release_is_refused(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $this->laundryJob($custody, null);

        /* Released 28 Aug; the linen cannot have come back on 20 Aug. */
        $this->expectException(ValidationException::class);

        $this->lateReturns->recordLaundryReceipt(
            $custody->fresh(['laundryJob']),
            Carbon::create(2026, 8, 20),
            $this->officer()
        );
    }

    public function test_the_return_form_asks_for_the_date_only_when_it_is_missing(): void
    {
        $withReceipt = $this->custody($this->borrower(), linen: true);
        $this->laundryJob($withReceipt, Carbon::create(2026, 9, 3, 11));

        $withoutReceipt = $this->custody($this->borrower(), linen: true);
        $this->laundryJob($withoutReceipt, null);

        $this->assertFalse($this->lateReturns->needsLaundryReceiptDate($withReceipt->fresh(['laundryJob'])));
        $this->assertTrue($this->lateReturns->needsLaundryReceiptDate($withoutReceipt->fresh(['laundryJob'])));
    }

    public function test_non_linen_never_asks_for_a_laundry_received_date(): void
    {
        $custody = $this->custody($this->borrower());

        $this->assertFalse($this->lateReturns->isLinen($custody));
        $this->assertFalse($this->lateReturns->needsLaundryReceiptDate($custody));
    }

    /* ================================================================== */
    /* HTTP actions                                                       */
    /* ================================================================== */

    public function test_the_officer_confirms_through_the_http_action(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('overdue.confirm-late-return', $case))
            ->assertSessionHasNoErrors();

        $this->assertSame(LateReturnService::STATUS_FOR_HEAD_APPROVAL, $case->fresh()->status);
    }

    public function test_the_head_cannot_use_the_officer_confirmation_action(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->spmuHead())
            ->post(route('overdue.confirm-late-return', $case))
            ->assertForbidden();
    }

    public function test_the_head_approves_and_the_fee_form_is_generated_once(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();
        $head = $this->spmuHead();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);
        $this->lateReturns->confirm($case, $officer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->post(route('overdue.bill', $case), ['basis' => 'Three late calendar days at the configured tariff.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(LateReturnService::STATUS_AWAITING_PAYMENT, $case->fresh()->status);
        $this->assertDatabaseHas('billing_statements', ['total_amount' => 225]);
        $this->assertDatabaseCount('billing_statements', 1);

        /* A repeated approval must not raise a second charge. */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->post(route('overdue.bill', $case->fresh()), ['basis' => 'Duplicate submission.'])
            ->assertSessionHasErrors('overdue');

        $this->assertDatabaseCount('billing_statements', 1);
        $this->assertDatabaseCount('penalties', 1);
    }

    /* ================================================================== */
    /* End-to-end: late return through to a settled payment               */
    /* ================================================================== */

    /**
     * Drive a case to Head-approved so the payment stage can be exercised.
     *
     * @return array{0: \App\Models\OverdueCase, 1: \App\Models\BillingStatement, 2: User, 3: User}
     */
    private function approvedLateReturn(): array
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower);
        $this->overdueCase($custody);
        $officer = $this->officer();
        $head = $this->spmuHead();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);
        $this->lateReturns->confirm($case, $officer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->post(route('overdue.bill', $case->fresh()), [
                'basis' => 'Three late calendar days at the configured tariff.',
            ])
            ->assertSessionHasNoErrors();

        $billing = \App\Models\BillingStatement::query()
            ->where('borrower_user_id', $borrower->id)
            ->firstOrFail();

        return [$case->fresh(), $billing, $officer, $head];
    }

    public function test_the_whole_flow_reaches_paid_and_resolved(): void
    {
        [$case, $billing, $officer] = $this->approvedLateReturn();

        $this->assertSame(LateReturnService::STATUS_AWAITING_PAYMENT, $case->status);
        $this->assertSame('ISSUED', $billing->status);

        /* Nothing is resolved while the payment is still outstanding. */
        $this->assertNotSame(LateReturnService::STATUS_RESOLVED, $case->status);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('payments.store', $billing), [
                'evidence' => \Illuminate\Http\UploadedFile::fake()->create('official-receipt.pdf', 20, 'application/pdf'),
                'official_receipt_no' => 'OR-2026-000123',
                'receipt_date' => '2026-09-08',
                'amount' => 225,
                'remarks' => 'Cashier receipt presented by the borrower.',
            ])
            ->assertSessionHasNoErrors();

        /* The existing payment fields carry the evidence. */
        $payment = \App\Models\Payment::query()
            ->where('billing_statement_id', $billing->id)
            ->firstOrFail();

        $this->assertSame('OR-2026-000123', $payment->official_receipt_no);
        $this->assertSame('2026-09-08', $payment->receipt_date->toDateString());
        $this->assertSame(225.0, (float) $payment->amount);
        $this->assertNotNull($payment->evidence_file_id);
        $this->assertSame($officer->id, $payment->recorded_by_user_id);
        $this->assertNotNull($payment->verified_by_user_id);
        $this->assertNotNull($payment->verified_at);

        $this->assertSame('SETTLED', $billing->fresh()->status);
        $this->assertSame(LateReturnService::STATUS_RESOLVED, $case->fresh()->status);

        $this->assertDatabaseHas('penalties', [
            'overdue_case_id' => $case->id,
            'status' => 'SETTLED',
        ]);

        /* The restriction raised for this billing is lifted. */
        $this->assertDatabaseHas('borrower_restrictions', [
            'billing_statement_id' => $billing->id,
            'status' => 'LIFTED',
        ]);
    }

    public function test_a_payment_without_receipt_evidence_is_refused(): void
    {
        [, $billing, $officer] = $this->approvedLateReturn();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('payments.store', $billing), [
                'official_receipt_no' => 'OR-2026-000123',
                'receipt_date' => '2026-09-08',
                'amount' => 225,
            ])
            ->assertSessionHasErrors('evidence');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('ISSUED', $billing->fresh()->status);
    }

    public function test_a_payment_without_a_receipt_number_or_date_is_refused(): void
    {
        [, $billing, $officer] = $this->approvedLateReturn();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('payments.store', $billing), [
                'evidence' => \Illuminate\Http\UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
                'amount' => 225,
            ])
            ->assertSessionHasErrors(['official_receipt_no', 'receipt_date']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_future_receipt_date_is_refused(): void
    {
        [, $billing, $officer] = $this->approvedLateReturn();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('payments.store', $billing), [
                'evidence' => \Illuminate\Http\UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
                'official_receipt_no' => 'OR-2026-000123',
                'receipt_date' => now()->addWeek()->toDateString(),
                'amount' => 225,
            ])
            ->assertSessionHasErrors('receipt_date');
    }

    public function test_an_unrelated_open_restriction_is_not_cleared_by_this_payment(): void
    {
        [, $billing, $officer] = $this->approvedLateReturn();

        $borrower = $billing->borrower_user_id;

        /* A separate, unrelated obligation on the same borrower. */
        BorrowerRestriction::query()->create([
            'borrower_user_id' => $borrower,
            'restriction_type' => 'PENDING_RETURN',
            'reason' => 'A different custody is still outstanding.',
            'effective_from' => now(),
            'status' => 'ACTIVE',
        ]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('payments.store', $billing), [
                'evidence' => \Illuminate\Http\UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
                'official_receipt_no' => 'OR-2026-000999',
                'receipt_date' => '2026-09-08',
                'amount' => 225,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('SETTLED', $billing->fresh()->status);

        /* Settling this billing must not clear an unrelated restriction. */
        $this->assertDatabaseHas('borrower_restrictions', [
            'borrower_user_id' => $borrower,
            'restriction_type' => 'PENDING_RETURN',
            'status' => 'ACTIVE',
        ]);
    }

    /* ================================================================== */
    /* Rendered states on Accountability Oversight                        */
    /* ================================================================== */

    private function accountability(User $actor)
    {
        return $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($actor)
            ->get(route('accountability.index'));
    }

    public function test_an_overdue_case_shows_an_estimate_and_no_approval_control(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);

        $this->accountability($this->officer())
            ->assertOk()
            ->assertSee('Overdue - Item Not Returned')
            ->assertSee('Estimated Fee So Far')
            ->assertSee('Still overdue', false)
            ->assertSee('A final Late Return Fee Form cannot be issued yet.')
            ->assertSee('Awaiting borrower return')
            /* No confirmation or approval control while it is merely overdue. */
            ->assertDontSee('Confirm Late Return')
            ->assertDontSee('Approve Late Return Assessment');
    }

    public function test_a_detected_late_return_offers_confirmation_to_the_officer_only(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        $this->accountability($officer)
            ->assertOk()
            ->assertSee('Late Return - For AO Confirmation')
            ->assertSee('Final Late Return Fee')
            ->assertSee('Actual Return')
            ->assertSee('Late Days')
            ->assertSee('Confirm Late Return');

        /* The Head sees the case but cannot approve before confirmation. */
        $this->accountability($this->spmuHead())
            ->assertOk()
            ->assertSee('Late Return - For AO Confirmation')
            ->assertDontSee('Approve Late Return Assessment');
    }

    public function test_a_confirmed_assessment_offers_approval_to_the_head_only(): void
    {
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $officer = $this->officer();

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $case = $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);
        $this->lateReturns->confirm($case, $officer);

        $this->accountability($this->spmuHead())
            ->assertOk()
            ->assertSee('For Head Approval')
            ->assertSee('Approve Late Return Assessment')
            ->assertSee('Return for Correction');

        $this->accountability($officer)
            ->assertOk()
            ->assertSee('For Head Approval')
            ->assertDontSee('Approve Late Return Assessment');
    }

    public function test_an_approved_case_reads_as_awaiting_payment(): void
    {
        [, , $officer] = $this->approvedLateReturn();

        $this->accountability($officer)
            ->assertOk()
            ->assertSee('Approved - Awaiting Payment')
            ->assertSee('The Late Return Fee Form has been issued.');
    }

    public function test_linen_awaiting_its_laundry_receipt_shows_the_attestation_field(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $this->laundryJob($custody, null);
        $officer = $this->officer();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('custody.return.show', $custody))
            ->assertOk()
            ->assertSee('Laundry Received Date')
            ->assertSee("Confirm the date shown in the Laundry Form's RECEIVED BY section.", false);
    }

    public function test_linen_with_a_recorded_receipt_shows_the_date_read_only(): void
    {
        $custody = $this->custody($this->borrower(), linen: true);
        $this->laundryJob($custody, Carbon::create(2026, 9, 3, 11));

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->officer())
            ->get(route('custody.return.show', $custody))
            ->assertOk()
            ->assertSee('Laundry Received Date')
            ->assertSee('03 Sep 2026')
            /* No input is offered when Laundry already recorded it. */
            ->assertDontSee('name="laundry_received_date"', false);
    }

    /* ================================================================== */
    /* Resolved history                                                   */
    /* ================================================================== */

    /** Take a case all the way to paid and resolved through the real actions. */
    private function paidAndResolved(): array
    {
        [$case, $billing, $officer, $head] = $this->approvedLateReturn();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('payments.store', $billing), [
                'evidence' => \Illuminate\Http\UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
                'official_receipt_no' => 'OR-2026-0000998877',
                'receipt_date' => '2026-09-08',
                'amount' => 225,
            ])
            ->assertSessionHasNoErrors();

        return [$case->fresh(), $billing->fresh(), $officer, $head];
    }

    public function test_a_paid_case_appears_in_resolved_history_with_its_payment_record(): void
    {
        [$case, $billing, $officer] = $this->paidAndResolved();

        $this->assertSame('SETTLED', $billing->status);
        $this->assertSame(LateReturnService::STATUS_RESOLVED, $case->status);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('accountability.index', ['view' => 'resolved']));

        $response->assertOk()
            ->assertSee('Resolved Accountability')
            ->assertSee('Resolved - Paid')
            ->assertSee('OR-2026-0000998877')
            ->assertSee('08 Sep 2026')
            ->assertSee('225.00')
            ->assertSee($officer->full_name)
            ->assertSee('Verified By')
            ->assertSee('Verified At');
    }

    public function test_resolved_history_is_read_only(): void
    {
        [, , $officer, $head] = $this->paidAndResolved();

        foreach ([$officer, $head] as $actor) {
            $this->withSession(['active_workspace' => 'SPMU'])
                ->actingAs($actor)
                ->get(route('accountability.index', ['view' => 'resolved']))
                ->assertOk()
                ->assertDontSee('Confirm Late Return')
                ->assertDontSee('Approve Late Return Assessment')
                ->assertDontSee('Return for Correction')
                ->assertDontSee('Verify and Mark as Paid');
        }
    }

    public function test_a_resolved_case_leaves_the_active_queues_and_counters(): void
    {
        [, $billing, $officer] = $this->paidAndResolved();

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('accountability.index'));

        $response->assertOk()
            /* Gone from the open billing queue. */
            ->assertDontSee($billing->billing_no)
            /* And from the active late-return queue. */
            ->assertDontSee('Late Return - For AO Confirmation')
            ->assertDontSee('Approved - Awaiting Payment');
    }

    public function test_a_resolved_case_does_not_reach_the_head_review_queue(): void
    {
        [, , , $head] = $this->paidAndResolved();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('accountability.index', ['view' => 'head_review']))
            ->assertOk()
            ->assertDontSee('For Head Approval')
            ->assertDontSee('Approve Late Return Assessment');
    }

    public function test_an_active_case_stays_in_the_active_queue_while_another_is_resolved(): void
    {
        [, , $officer] = $this->paidAndResolved();

        /* A second, still-active case on a different custody. */
        $custody = $this->custody($this->borrower());
        $this->overdueCase($custody);
        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('accountability.index'))
            ->assertOk()
            ->assertSee('Late Return - For AO Confirmation')
            ->assertSee('Confirm Late Return');
    }

    public function test_a_waived_billing_is_never_relabelled_as_paid(): void
    {
        [, $billing, , $head] = $this->approvedLateReturn();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->post(route('billings.waive', $billing), [
                'reason' => 'Waived under an approved administrative exception.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('WAIVED', $billing->fresh()->status);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('accountability.index', ['view' => 'resolved']))
            ->assertOk()
            ->assertSee('Waived')
            ->assertDontSee('Resolved - Paid');
    }

    public function test_resolved_history_shows_a_zero_state_when_nothing_is_closed(): void
    {
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->officer())
            ->get(route('accountability.index', ['view' => 'resolved']))
            ->assertOk()
            ->assertSee('No resolved accountability cases yet.')
            ->assertSee('Cases appear here after payment verification');
    }

    public function test_overdue_and_returned_late_are_never_the_same_state(): void
    {
        $overdueCustody = $this->custody($this->borrower());
        $overdueCase = $this->overdueCase($overdueCustody);

        $lateCustody = $this->custody($this->borrower());
        $this->overdueCase($lateCustody);
        $officer = $this->officer();
        $this->recordReturn($lateCustody, Carbon::create(2026, 9, 4, 14), $officer);
        $lateCase = $this->lateReturns->assess($lateCustody->fresh(['lines', 'returns']), $officer);

        $this->assertNotSame($overdueCase->status, $lateCase->status);
        $this->assertSame('Overdue - Item Not Returned', LateReturnService::label($overdueCase->status));
        $this->assertSame('Late Return - For AO Confirmation', LateReturnService::label($lateCase->status));

        /* Only the returned one carries a frozen assessment. */
        $this->assertNull($overdueCase->actual_return_date);
        $this->assertNotNull($lateCase->actual_return_date);
    }

    public function test_the_outstanding_property_restriction_gives_way_to_a_settlement_restriction(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower);
        $this->overdueCase($custody);
        $officer = $this->officer();

        BorrowerRestriction::query()->create([
            'borrower_user_id' => $borrower->id,
            'restriction_type' => 'PENDING_RETURN',
            'reason' => 'Property outstanding.',
            'effective_from' => now(),
            'status' => 'ACTIVE',
        ]);

        $this->recordReturn($custody, Carbon::create(2026, 9, 4, 14), $officer);
        $this->lateReturns->assess($custody->fresh(['lines', 'returns']), $officer);

        $this->assertDatabaseHas('borrower_restrictions', [
            'borrower_user_id' => $borrower->id,
            'restriction_type' => 'OVERDUE_RETURN',
            'status' => 'ACTIVE',
        ]);
    }
}
