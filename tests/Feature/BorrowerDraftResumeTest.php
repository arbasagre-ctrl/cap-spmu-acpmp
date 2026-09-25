<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A DRAFT has not entered the workflow, so the submitted-request detail page
 * (progress timeline, "Ready to submit to SPMU", Review/Edit Draft button) is
 * the wrong destination for it. Every borrower entry point must land on the
 * existing request editor instead, reopened at the stage the borrower stopped
 * on. Non-DRAFT statuses must keep rendering the detail page unchanged.
 */
class BorrowerDraftResumeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function borrower(): User
    {
        return User::query()
            ->where(
                'access_classification',
                AccessClassification::BorrowerOnly->value
            )
            ->firstOrFail();
    }

    /**
     * @param  string  $filled  'empty', 'details' or 'complete' - how much of
     *                          the draft the borrower already saved.
     */
    private function draft(
        User $borrower,
        string $filled,
        RequestStatus $status = RequestStatus::Draft
    ): BorrowingRequest {
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-DRAFT-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => $status,
        ]);

        $scheduleDate = now()->addDay()->startOfDay();
        $returnDate = now()->addDays(2)->startOfDay();

        /*
         * purpose_event, location, needed_from and return_due_at are NOT NULL
         * on request_versions, so a barely-started draft carries blank strings
         * rather than missing columns. A blank purpose_event is what leaves
         * stage one incomplete.
         */
        $attributes = [
            'version_no' => 1,
            'created_by_user_id' => $borrower->id,
            'purpose_event' => '',
            'location' => '',
            'needed_from' => $scheduleDate,
            'return_due_at' => $returnDate,
        ];

        if ($filled !== 'empty') {
            $attributes = array_merge($attributes, [
                'purpose_event' => 'Draft resume coverage',
                'event_details' => 'Derived resume stage.',
                'location' => 'CSPC Campus',
                'schedule_date' => $scheduleDate->toDateString(),
                'return_date' => $returnDate->toDateString(),
                'off_campus' => false,
                'represents_student_activity' => false,
            ]);
        }

        $version = $request->versions()->create($attributes);

        if ($filled === 'complete') {
            $item = InventoryItem::query()
                ->with('unit')
                ->where('active', true)
                ->where('borrowable', true)
                ->where('condition_code', 'SERVICEABLE')
                ->firstOrFail();

            RequestItem::create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $item->id,
                'description_snapshot' => $item->unique_description,
                'unit_snapshot' => $item->unit->unit_name,
                'requested_quantity' => 1,
                'use_location' => 'ON_CAMPUS',
            ]);
        }

        return $request;
    }

    public function test_opening_a_draft_detail_url_redirects_the_borrower_to_the_editor(): void
    {
        $borrower = $this->borrower();
        $draft = $this->draft($borrower, 'details');

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('requests.show', $draft))
            ->assertRedirect(route('requests.edit', $draft));
    }

    public function test_draft_editor_renders_the_request_form_and_not_the_progress_page(): void
    {
        $borrower = $this->borrower();
        $draft = $this->draft($borrower, 'details');

        $response = $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('requests.edit', $draft))
            ->assertOk();

        $response->assertSee('request-stepper', false);
        $response->assertDontSee('Ready to submit to SPMU', false);
    }

    public function test_my_requests_draft_row_links_straight_to_the_editor(): void
    {
        $borrower = $this->borrower();
        $draft = $this->draft($borrower, 'details');

        $response = $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('requests.index'))
            ->assertOk();

        $response->assertSee(route('requests.edit', $draft), false);
        $response->assertSee('Resume Draft', false);
        $response->assertDontSee(
            'href="'.route('requests.show', $draft).'"',
            false
        );
    }

    public function test_empty_draft_resumes_on_the_request_details_stage(): void
    {
        $borrower = $this->borrower();
        $draft = $this->draft($borrower, 'empty');

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('requests.edit', $draft))
            ->assertOk()
            ->assertSee('const resumeStage = 1;', false);
    }

    public function test_draft_with_details_but_no_items_resumes_on_the_item_stage(): void
    {
        $borrower = $this->borrower();
        $draft = $this->draft($borrower, 'details');

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('requests.edit', $draft))
            ->assertOk()
            ->assertSee('const resumeStage = 2;', false);
    }

    public function test_complete_but_unsubmitted_draft_opens_the_final_review_stage(): void
    {
        $borrower = $this->borrower();
        $draft = $this->draft($borrower, 'complete');

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('requests.edit', $draft))
            ->assertOk()
            ->assertSee('const resumeStage = 3;', false);
    }

    public function test_draft_values_and_items_survive_the_redirect(): void
    {
        $borrower = $this->borrower();
        $draft = $this->draft($borrower, 'complete');

        $response = $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->followingRedirects()
            ->get(route('requests.show', $draft))
            ->assertOk();

        $response->assertSee('Draft resume coverage', false);

        $this->assertSame(
            1,
            RequestItem::query()
                ->where(
                    'request_version_id',
                    $draft->fresh()->currentVersion->id
                )
                ->count()
        );
    }

    public function test_a_borrower_cannot_open_another_borrowers_draft(): void
    {
        $owner = $this->borrower();
        $draft = $this->draft($owner, 'details');

        $other = User::factory()->create([
            'organizational_unit_id' => $owner->organizational_unit_id,
        ]);

        $this->actingAs($other)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('requests.show', $draft))
            ->assertForbidden();

        $this->actingAs($other)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->get(route('requests.edit', $draft))
            ->assertForbidden();
    }

    public function test_non_draft_requests_still_render_the_detail_page(): void
    {
        $borrower = $this->borrower();

        $statuses = [
            RequestStatus::Submitted,
            RequestStatus::UnderSpmu,
            RequestStatus::ReturnedForRevision,
            RequestStatus::ApprovedReadyForRelease,
            RequestStatus::Cancelled,
            RequestStatus::Rejected,
        ];

        foreach ($statuses as $status) {
            $request = $this->draft($borrower, 'complete', $status);

            $this->actingAs($borrower)
                ->withSession(['active_workspace' => 'BORROWER'])
                ->get(route('requests.show', $request))
                ->assertOk();
        }
    }
}
