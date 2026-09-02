<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\RequestSupportingDocument;
use App\Models\RequestVersion;
use App\Services\InventoryService;
use App\Services\ProtectedFileService;
use App\Services\RequestWorkflowService;
use App\Support\OrganizationalStructure;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BorrowingRequestController extends Controller
{
    /**
     * The canonical office/unit list, grouped by division.
     *
     * Kept as a thin accessor so the existing call sites and the request form
     * contract stay identical; OrganizationalStructure owns the data.
     *
     * @return array<string, list<string>>
     */
    private static function officeUnitsByDivision(): array
    {
        return OrganizationalStructure::unitsByDivision();
    }

    /**
     * Reverse-lookup: given a borrower's home organizational-unit name,
     * find which division key (if any) contains a matching office/unit
     * entry, so a brand-new request can be prefilled instead of forcing
     * the borrower to retype what is already on their profile.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function divisionAndOfficeUnitFor(?string $unitName): array
    {
        return OrganizationalStructure::divisionAndUnitFor($unitName);
    }

    public function index(Request $request): View
    {
        $query = BorrowingRequest::query()
            ->with(['borrower', 'currentVersion.items', 'custody'])
            ->latest();

        $workspace = strtoupper(
            (string) $request->session()->get('active_workspace')
        );

        if ($workspace === 'BORROWER') {
            $query->where(
                'borrower_user_id',
                $request->user()->id
            );
        } elseif (
            $workspace === 'SPMU'
            && $request->user()->access_classification === AccessClassification::SpmuOfficer
        ) {
            $query->where(function ($query): void {
                $query->where('status', RequestStatus::UnderSpmu)
                    ->orWhereNotNull('final_approved_at');
            });
        }

        /*
         * The borrower workspace renders the paginated My Requests list.
         * Staff record views keep the full unpaginated table.
         */
        return view(
            'requests.index',
            [
                'requests' => $workspace === 'BORROWER'
                    ? $query->paginate(10)->withQueryString()
                    : $query->get(),
            ]
        );
    }

    public function create(Request $request): View
    {
        /*
         * Prefill Division / Office from the borrower's own profile so they
         * are not asked to retype information already on file. This is only
         * a default: the field stays editable and is still snapshotted onto
         * the request version, since a specific borrowing can legitimately
         * be attributed to a different unit than the borrower's home office.
         */
        $borrower = $request->user()->loadMissing('organizationalUnit');
        [$prefillDivisionCode, $prefillOfficeUnit] = self::divisionAndOfficeUnitFor(
            $borrower->organizationalUnit?->unit_name
        );

        return view(
            'requests.form',
            [
                'borrowingRequest' => new BorrowingRequest,
                'version' => new RequestVersion,

                /*
                 * Online request submission and physical pickup/release are
                 * separate availabilities. This is informational only and
                 * never blocks the form.
                 */
                'pickupAvailability' => $this->pickupAvailability(),

                'officeUnitsByDivision' => self::officeUnitsByDivision(),
                'prefillDivisionCode' => $prefillDivisionCode,
                'prefillOfficeUnit' => $prefillOfficeUnit,

                'items' => InventoryItem::with(['unit', 'category'])
                    ->where('active', true)
                    ->where('borrowable', true)
                    ->where('condition_code', 'SERVICEABLE')
                    ->orderBy('unique_description')
                    ->get(),
            ]
        );
    }

    /**
     * Informational pickup/release availability for the borrower form.
     *
     * @return array{available: bool, next: ?\Carbon\CarbonImmutable}
     */
    private function pickupAvailability(): array
    {
        $calendar = app(\App\Services\OperationalCalendarService::class);

        return [
            'available' => $calendar->isOpenFor(
                \App\Services\OperationalCalendarService::PICKUP,
                now(),
                true
            ),
            'next' => $calendar->nextPickupWindow(now()),
        ];
    }

    public function store(
        Request $request,
        InventoryService $inventory,
        ProtectedFileService $files,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        $data = $this->validateRequest($request);

        if ($request->input('intent') === 'submit') {
            $this->validateESignatureConfirmation($request);
        }

        $user = $request->user();

        if ($user->activeRestrictions()->exists()) {
            throw ValidationException::withMessages([
                'request' =>
                    'An active borrowing restriction prevents a new request.',
            ]);
        }

        $isSubmission = $request->input('intent') === 'submit';

        /*
         * DUPLICATE-REQUEST PROTECTION
         * ----------------------------
         * Creation, supporting-document upload, and submission are ONE atomic
         * unit. Submission can still fail validation for reasons that are only
         * known at that point (missing scanned letter, active restriction,
         * outstanding custody, closed pickup date, item no longer serviceable).
         *
         * Previously the request row was already committed when that happened,
         * so the borrower was returned to the form with an orphaned DRAFT and
         * every corrected retry created another borrowing request. Rolling the
         * whole unit back means a failed submission leaves no request at all,
         * and the borrower's retry creates exactly one.
         */
        $borrowingRequest = DB::transaction(
            function () use (
                $data,
                $user,
                $inventory,
                $request,
                $files,
                $workflow,
                $isSubmission
            ): BorrowingRequest {
                $borrowingRequest =
                    BorrowingRequest::query()->create([
                        'request_no' =>
                            'BR-'
                            .now()->format('YmdHis')
                            .'-'
                            .$user->id,

                        'borrower_user_id' =>
                            $user->id,

                        'accountable_unit_id' =>
                            $user->organizational_unit_id,

                        'current_version_no' =>
                            1,

                        'status' =>
                            RequestStatus::Draft,
                    ]);

                $version =
                    $borrowingRequest
                        ->versions()
                        ->create(
                            $this->versionData(
                                $data,
                                $user->id,
                                1
                            )
                        );

                $this->saveItems(
                    $version,
                    $data,
                    $inventory
                );

                $borrowingRequest->load('currentVersion');

                $this->syncSupportingDocuments(
                    $request,
                    $borrowingRequest,
                    $files,
                    (bool) (
                        $data['represents_student_activity']
                        ?? false
                    )
                );

                if ($isSubmission) {
                    $workflow->submit(
                        $borrowingRequest->fresh(),
                        $request->user(),
                        $request->boolean('confirm_e_signature')
                    );
                }

                return $borrowingRequest;
            },
            3
        );

        if ($isSubmission) {
            return redirect()
                ->route('requests.show', $borrowingRequest)
                ->with(
                    'status',
                    'Request version E-signed and submitted with the required scanned document(s) to SPMU for verification.'
                );
        }

        return redirect()
            ->route('requests.show', $borrowingRequest)
            ->with(
                'status',
                'Draft saved. You can continue editing and submit when the required scanned document(s) are complete.'
            );
    }

    public function show(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): View {
        $canVerify = $this->canVerifyRequest($request, $borrowingRequest);
        $canDecide = $this->canDecideApproval($request, $borrowingRequest);

        $this->authorizeRequest(
            $request,
            $borrowingRequest,
            $canVerify,
            $canDecide
        );

        $reviewMode = match (true) {
            $canVerify => 'ACTION_OFFICER_VERIFICATION',
            $canDecide => 'HEAD_DECISION',
            default => null,
        };

        $approvalStage =
            $this->approvalStage(
                $borrowingRequest->status
            );

        $borrowingRequest->load([
            'borrower.organizationalUnit',
            'accountableUnit',

            'currentVersion.items.inventoryItem.unit',

            'currentVersion.approvalSteps.approver',

            'currentVersion.documents.downloads',

            'currentVersion.supportingDocuments.file',
            'currentVersion.approvalSteps',

            'currentVersion.supportingDocuments.uploader',

            'statusHistory.actor',

            'custody.lines.requestItem.inventoryItem',
            'custody.returns',
            'custody.laundryJob.latestEvidence.file',
            'custody.gatePass.accomplishedFile',
        ]);

        return view(
            'requests.show',
            compact(
                'borrowingRequest',
                'canDecide',
                'canVerify',
                'reviewMode',
                'approvalStage'
            )
        );
    }

    public function edit(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): View {
        abort_unless(
            $borrowingRequest->borrower_user_id
                === $request->user()->id
            && in_array(
                $borrowingRequest->status,
                [
                    RequestStatus::Draft,
                    RequestStatus::ReturnedForRevision,
                ],
                true
            ),
            403
        );

        $borrowingRequest->load([
            'currentVersion.items',
            'currentVersion.supportingDocuments.file',
            'currentVersion.approvalSteps',
        ]);

        return view(
            'requests.form',
            [
                'borrowingRequest' =>
                    $borrowingRequest,

                'version' =>
                    $borrowingRequest->currentVersion,

                'officeUnitsByDivision' => self::officeUnitsByDivision(),
                'prefillDivisionCode' => null,
                'prefillOfficeUnit' => null,

                'items' =>
                    InventoryItem::with(['unit', 'category'])
                        ->where('active', true)
                        ->where('borrowable', true)
                        ->where('condition_code', 'SERVICEABLE')
                        ->orderBy(
                            'unique_description'
                        )
                        ->get(),
            ]
        );
    }

    public function update(
        Request $request,
        BorrowingRequest $borrowingRequest,
        InventoryService $inventory,
        ProtectedFileService $files,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        abort_unless(
            $borrowingRequest->borrower_user_id
                === $request->user()->id
            && in_array(
                $borrowingRequest->status,
                [
                    RequestStatus::Draft,
                    RequestStatus::ReturnedForRevision,
                ],
                true
            ),
            403
        );

        $data =
            $this->validateRequest($request);

        if ($request->input('intent') === 'submit') {
            $this->validateESignatureConfirmation($request);
        }

        DB::transaction(
            function () use (
                $borrowingRequest,
                $data,
                $request,
                $inventory
            ): void {
                $versionNo =
                    $borrowingRequest->status
                        === RequestStatus::ReturnedForRevision
                    ? $borrowingRequest->current_version_no + 1
                    : $borrowingRequest->current_version_no;

                if (
                    $borrowingRequest->status
                    === RequestStatus::ReturnedForRevision
                ) {
                    /*
                     * Returned requests create a new version.
                     *
                     * Old scanned approved documents are NOT
                     * copied to the new version automatically.
                     *
                     * The borrower must attach the corrected
                     * approved document(s) before resubmission.
                     */
                    $version =
                        $borrowingRequest
                            ->versions()
                            ->create(
                                $this->versionData(
                                    $data,
                                    $request->user()->id,
                                    $versionNo
                                )
                            );

                    $borrowingRequest->update([
                        'current_version_no' =>
                            $versionNo,

                        'status' =>
                            RequestStatus::Draft,
                    ]);
                } else {
                    $version =
                        $borrowingRequest
                            ->currentVersion;

                    $version->update(
                        $this->versionData(
                            $data,
                            $request->user()->id,
                            $versionNo
                        )
                    );

                    $version
                        ->items()
                        ->delete();
                }

                $this->saveItems(
                    $version,
                    $data,
                    $inventory
                );
            },
            3
        );

        $borrowingRequest->refresh();

        $borrowingRequest->load(
            'currentVersion'
        );

        $this->syncSupportingDocuments(
            $request,
            $borrowingRequest,
            $files,
            (bool) (
                $data['represents_student_activity']
                ?? false
            )
        );

        if ($request->input('intent') === 'submit') {
            $workflow->submit(
                $borrowingRequest->fresh(),
                $request->user(),
                $request->boolean('confirm_e_signature')
            );

            return redirect()
                ->route('requests.show', $borrowingRequest)
                ->with(
                    'status',
                    'Request version E-signed and submitted with the required scanned document(s) to SPMU for verification.'
                );
        }

        return redirect()
            ->route('requests.show', $borrowingRequest)
            ->with(
                'status',
                'Draft changes saved.'
            );
    }

    public function submit(
        Request $request,
        BorrowingRequest $borrowingRequest,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        $request->validate([
            'borrower_acknowledgement' => [
                'required',
                'accepted',
            ],
        ], [
            'borrower_acknowledgement.accepted' =>
                'Read and accept the Borrower Certification and Acknowledgement before submitting to SPMU.',
        ]);
        $this->validateESignatureConfirmation($request);

        $workflow->submit(
            $borrowingRequest,
            $request->user(),
            $request->boolean('confirm_e_signature')
        );

        return back()->with(
            'status',
            'Request version E-signed and submitted with the scanned approved document(s) to SPMU for verification. No inventory reservation has been created yet.'
        );
    }

    private function validateESignatureConfirmation(Request $request): void
    {
        $request->validate([
            'confirm_e_signature' => ['required', 'accepted'],
        ], [
            'confirm_e_signature.accepted' => 'Confirm that you want to apply your registered E-signature before submitting.',
            'confirm_e_signature.required' => 'Confirm that you want to apply your registered E-signature before submitting.',
        ]);

        $hasCurrentSignature = $request->user()
            ->currentSignature()
            ->whereHas('file')
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', now());
            })
            ->exists();

        if (! $hasCurrentSignature) {
            throw ValidationException::withMessages([
                'signature' => 'Register an E-signature in Account Settings before submitting this request.',
            ]);
        }
    }


    public function recoverDraftDocument(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): RedirectResponse {
        abort_unless(
            $borrowingRequest->borrower_user_id === $request->user()->id,
            403
        );

        return back()->with(
            'status',
            'The current workflow does not generate a Borrowing Request Letter. Upload the already approved and fully signed scanned Borrowing Request Letter instead.'
        );
    }


    public function cancel(
        Request $request,
        BorrowingRequest $borrowingRequest,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $workflow->cancel(
            $borrowingRequest,
            $request->user(),
            $data['reason']
        );

        return back()->with(
            'status',
            'Request cancelled. Any approved but unreleased allocation has been restored to Available inventory, and any pending pickup schedule/documents are no longer active.'
        );
    }

    public function reviewCancellation(
        Request $request,
        BorrowingRequest $borrowingRequest,
        RequestWorkflowService $workflow
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => [
                'required',
                Rule::in([
                    'APPROVED',
                    'REJECTED',
                ]),
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $workflow->reviewCancellation(
            $borrowingRequest,
            $request->user(),
            $data['decision'],
            $data['remarks'] ?? null
        );

        return back()->with(
            'status',
            $data['decision'] === 'APPROVED'
                ? 'Cancellation confirmed by SPMU. The unreleased reservation was restored to Available inventory.'
                : 'Cancellation request rejected. The existing reservation remains active.'
        );
    }

    private function validateRequest(
        Request $request
    ): array {
        /*
         * Date-only UI contract.
         *
         * Current/legacy Blade files may still submit:
         * - needed_from
         * - return_due_at
         *
         * New UI may submit the clearer names:
         * - schedule_date
         * - return_date
         *
         * Both are accepted here so UI work can be merged
         * without changing the backend workflow. Any time
         * portion submitted by the old datetime-local UI is
         * intentionally ignored.
         */
        $request->merge([
            /*
             * CREATE_REQUEST_EVENT_DETAILS_COMPAT
             *
             * The revised Borrower form collects Event Details only.
             * purpose_event remains a legacy stored field used by older
             * reports/views, so derive it server-side instead of asking
             * the borrower to enter the same information twice.
             */
            'purpose_event' =>
                $request->input('purpose_event')
                ?: mb_substr(
                    trim(
                        (string) $request->input('event_details')
                    ),
                    0,
                    255
                ),
            'schedule_date' =>
                $request->input('schedule_date')
                ?: $request->input('needed_from'),

            'return_date' =>
                $request->input('return_date')
                ?: $request->input('return_due_at'),
        ]);

        $data = $request->validate([
            'purpose_event' => [
                'required',
                'string',
                'max:255',
            ],

            'location' => [
                'required',
                'string',
                'max:255',
            ],

            'division_code' => [
                'required',
                Rule::in(OrganizationalStructure::divisionCodes()),
            ],

            'office_unit' => [
                'required',
                'string',
                'max:255',
            ],

            'schedule_date' => [
                'required',
                'date',
            ],

            'return_date' => [
                'required',
                'date',
            ],

            /*
             * Legacy field names remain accepted while the
             * existing Borrower UI is still being replaced.
             */
            'needed_from' => [
                'nullable',
                'date',
            ],

            'return_due_at' => [
                'nullable',
                'date',
            ],

            'student_organization' => [
                'nullable',
                'string',
                'max:255',
            ],

            'represents_student_activity' => [
                'nullable',
                'boolean',
            ],

            'represented_program_department' => [
                'nullable',
                'string',
                'max:255',
            ],
            /*
             * Year Level is intentionally not collected by the revised form.
             */
            'represented_year_level' => [
                'nullable',
                'string',
                'max:40',
            ],

            'event_details' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'off_campus' => [
                'nullable',
                'boolean',
            ],

            'intent' => [
                'nullable',
                Rule::in(['draft', 'submit']),
            ],

            'borrower_acknowledgement' => [
                'nullable',
                'boolean',
            ],

            /*
             * Supporting documents are optional while
             * saving a DRAFT.
             *
             * RequestWorkflowService::submit()
             * enforces the required document set before
             * SPMU can receive the request.
             */
            'approved_request_letter' => [
                'nullable',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp',
                'max:10240',
            ],

            'permission_to_conduct_letter' => [
                'nullable',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp',
                'max:10240',
            ],

            'item_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'item_ids.*' => [
                'required',
                'integer',
                'exists:inventory_items,id',
            ],

            'quantities' => [
                'required',
                'array',
            ],

            'quantities.*' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'locations' => [
                'nullable',
                'array',
            ],

            'locations.*' => [
                'nullable',
                Rule::in([
                    'ON_CAMPUS',
                    'OFF_CAMPUS',
                ]),
            ],
        ]);

        $timezone =
            config('app.timezone')
            ?: 'Asia/Manila';

        $scheduleDate = Carbon::parse(
            $data['schedule_date'],
            $timezone
        )->startOfDay();

        $returnDate = Carbon::parse(
            $data['return_date'],
            $timezone
        )->startOfDay();

        /*
         * Preserve the existing business rule that the
         * borrowing date must be a future calendar date.
         * The comparison is now DATE-ONLY, not time-based.
         */
        if (
            ! $scheduleDate->gt(
                now($timezone)->startOfDay()
            )
        ) {
            throw ValidationException::withMessages([
                'schedule_date' =>
                    'Schedule Date must be after today.',
            ]);
        }

        /*
         * Preserve the existing rule that Return Date is
         * after Schedule Date, but compare calendar dates
         * only.
         */
        if (! $returnDate->gt($scheduleDate)) {
            throw ValidationException::withMessages([
                'return_date' =>
                    'Return Date must be after Schedule Date.',
            ]);
        }


        if (! in_array(
            $data['office_unit'],
            self::officeUnitsByDivision()[$data['division_code']] ?? [],
            true
        )) {
            throw ValidationException::withMessages([
                'office_unit' =>
                    'Choose an Office / Academic Unit / Research Unit that belongs to the selected Division.',
            ]);
        }

        if (
            ($data['intent'] ?? 'draft') === 'submit'
            && ! $request->boolean('borrower_acknowledgement')
        ) {
            throw ValidationException::withMessages([
                'borrower_acknowledgement' =>
                    'Read and accept the Borrower Certification and Acknowledgement before submitting to SPMU.',
            ]);
        }

        return $data;
    }

    private function versionData(
        array $data,
        int $userId,
        int $versionNo
    ): array {
        return [
            'version_no' =>
                $versionNo,

            'purpose_event' =>
                $data['purpose_event'],

            'location' =>
                $data['location'],

            'division_code' =>
                $data['division_code'],

            'office_unit' =>
                $data['office_unit'],

            /*
             * Canonical date-only fields.
             */
            'schedule_date' =>
                Carbon::parse(
                    $data['schedule_date'],
                    config('app.timezone')
                        ?: 'Asia/Manila'
                )->toDateString(),

            'return_date' =>
                Carbon::parse(
                    $data['return_date'],
                    config('app.timezone')
                        ?: 'Asia/Manila'
                )->toDateString(),

            /*
             * Legacy timestamp columns remain populated for
             * existing inventory/calendar/report code. Their
             * time portions are normalized and are not user-
             * selected times.
             */
            'needed_from' =>
                Carbon::parse(
                    $data['schedule_date'],
                    config('app.timezone')
                        ?: 'Asia/Manila'
                )->startOfDay(),

            'return_due_at' =>
                Carbon::parse(
                    $data['return_date'],
                    config('app.timezone')
                        ?: 'Asia/Manila'
                )->endOfDay(),

            'represents_student_activity' =>
                (bool) (
                    $data[
                        'represents_student_activity'
                    ] ?? false
                ),

            'student_organization' =>
                null,

            'represented_program_department' =>
                null,

            'represented_year_level' =>
                null,

            'event_details' =>
                null,

            'off_campus' =>
                collect(
                    $data['locations'] ?? []
                )->contains(
                    'OFF_CAMPUS'
                ),

            'remarks' =>
                $data['remarks'] ?? null,

            'created_by_user_id' =>
                $userId,
        ];
    }

    private function saveItems(
        RequestVersion $version,
        array $data,
        InventoryService $inventory
    ): void {
        $selected = 0;

        $selectedItemIds = collect($data['item_ids'])
            ->filter(
                fn ($itemId) =>
                    (int) (
                        $data['quantities'][$itemId]
                        ?? 0
                    ) > 0
            )
            ->values();

        $hasOffCampusSelection =
            $selectedItemIds->contains(
                fn ($itemId) =>
                    strtoupper(
                        (string) (
                            $data['locations'][$itemId]
                            ?? 'ON_CAMPUS'
                        )
                    ) === 'OFF_CAMPUS'
            );

        if (
            $hasOffCampusSelection
            && $selectedItemIds->count() > 1
        ) {
            throw ValidationException::withMessages([
                'locations' =>
                    'An Off-Campus item must be the only selected item in the request.',
            ]);
        }

        foreach (
            $data['item_ids']
            as $itemId
        ) {
            $quantity =
                (int) (
                    $data['quantities'][$itemId]
                    ?? 0
                );

            if ($quantity <= 0) {
                continue;
            }

            $item =
                InventoryItem::with(['unit', 'category'])
                    ->where(
                        'active',
                        true
                    )
                    ->where(
                        'borrowable',
                        true
                    )
                    ->where(
                        'condition_code',
                        'SERVICEABLE'
                    )
                    ->findOrFail(
                        $itemId
                    );

            /*
             * Draft-time availability check.
             *
             * This provides immediate borrower feedback.
             *
             * IMPORTANT:
             * This does NOT create a reservation.
             */
            $balance =
                $inventory->availability(
                    $item,
                    Carbon::parse(
                        $data['schedule_date'],
                        config('app.timezone')
                            ?: 'Asia/Manila'
                    )->startOfDay(),
                    Carbon::parse(
                        $data['return_date'],
                        config('app.timezone')
                            ?: 'Asia/Manila'
                    )->endOfDay()
                );

            if (
                $quantity
                > $balance['available']
            ) {
                throw ValidationException::withMessages([
                    'quantities' =>
                        "{$item->unique_description} has only {$balance['available']} available for the complete period.",
                ]);
            }

            $location =
                strtoupper(
                    (string) (
                        $data[
                            'locations'
                        ][$item->id]
                        ?? 'ON_CAMPUS'
                    )
                );

            if (
                ! $item->off_campus_allowed
                && $location !== 'ON_CAMPUS'
            ) {
                throw ValidationException::withMessages([
                    'locations' =>
                        "{$item->unique_description} is restricted to On-Campus use.",
                ]);
            }

            if (
                ! in_array(
                    $location,
                    [
                        'ON_CAMPUS',
                        'OFF_CAMPUS',
                    ],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'locations' =>
                        'Choose a valid campus location for each selected item.',
                ]);
            }

            RequestItem::query()->create([
                'request_version_id' =>
                    $version->id,

                'inventory_item_id' =>
                    $item->id,

                'description_snapshot' =>
                    $item->unique_description,

                'unit_snapshot' =>
                    $item->unit->unit_name,

                'requested_quantity' =>
                    $quantity,

                'use_location' =>
                    $location,
            ]);

            $selected++;
        }

        if ($selected === 0) {
            throw ValidationException::withMessages([
                'items' =>
                    'Enter a quantity greater than zero for at least one item.',
            ]);
        }
    }

    private function syncSupportingDocuments(
        Request $request,
        BorrowingRequest $borrowingRequest,
        ProtectedFileService $files,
        bool $representsStudentActivity
    ): void {
        $version = $borrowingRequest->currentVersion;

        if (! $version) {
            throw ValidationException::withMessages([
                'documents' => 'The current request version could not be found.',
            ]);
        }

        if ($request->hasFile('approved_request_letter')) {
            $this->storeSupportingDocument(
                $borrowingRequest,
                $version,
                $request->file('approved_request_letter'),
                RequestSupportingDocument::TYPE_REQUEST_LETTER,
                $files,
                $request->user()->id
            );
        }

        if (
            $representsStudentActivity
            && $request->hasFile('permission_to_conduct_letter')
        ) {
            $this->storeSupportingDocument(
                $borrowingRequest,
                $version,
                $request->file('permission_to_conduct_letter'),
                RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT,
                $files,
                $request->user()->id
            );
        }

        /*
        * If a draft is changed from student activity to
        * regular borrowing, do NOT delete the historical PTC.
        *
        * Mark the current PTC as superseded so the audit trail
        * is preserved.
        */
        if (! $representsStudentActivity) {
            $version
                ->supportingDocuments()
                ->where(
                    'document_type',
                    RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
                )
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'superseded_at' => now(),
                ]);
        }
    }

    private function storeSupportingDocument(
        BorrowingRequest $borrowingRequest,
        RequestVersion $version,
        UploadedFile $upload,
        string $documentType,
        ProtectedFileService $files,
        int $uploaderId
    ): void {
        $storedFile = $files->storeUpload(
            $upload,
            'request-supporting-documents/'
                .$borrowingRequest->id
                .'/version-'
                .$version->version_no,
            'REQUEST_SUPPORTING_DOCUMENT'
        );

        /*
        * Determine the next document version.
        */
        $latestVersion = (int) RequestSupportingDocument::query()
            ->where('request_version_id', $version->id)
            ->where('document_type', $documentType)
            ->max('version_no');

        $nextVersion = $latestVersion + 1;

        /*
        * Preserve the old uploaded document.
        *
        * Never overwrite or delete historical request evidence.
        */
        RequestSupportingDocument::query()
            ->where('request_version_id', $version->id)
            ->where('document_type', $documentType)
            ->where('is_current', true)
            ->update([
                'is_current' => false,
                'superseded_at' => now(),
            ]);

        RequestSupportingDocument::query()->create([
            'request_id' => $borrowingRequest->id,
            'request_version_id' => $version->id,
            'document_type' => $documentType,
            'version_no' => $nextVersion,
            'stored_file_id' => $storedFile->id,
            'uploaded_by_user_id' => $uploaderId,
            'uploaded_at' => now(),

            'verification_status' =>
                RequestSupportingDocument::STATUS_PENDING,

            'verified_by_user_id' => null,
            'verified_at' => null,
            'verification_remarks' => null,

            'is_current' => true,
            'superseded_at' => null,
        ]);
    }

    private function authorizeRequest(
        Request $request,
        BorrowingRequest $borrowingRequest,
        bool $canVerify,
        bool $canDecide
    ): void {
        $user = $request->user();

        $workspace = strtoupper(
            (string) $request->session()->get('active_workspace')
        );

        $isBorrowerOwner = $workspace === 'BORROWER'
            && $borrowingRequest->borrower_user_id === $user->id;

        $isSpmuHead = $workspace === 'SPMU'
            && $user->access_classification === AccessClassification::SpmuHead;

        $isDelegatedOfficer = $workspace === 'SPMU'
            && $user->access_classification === AccessClassification::SpmuOfficer
            && $user->activeDelegationFor('SPMU') !== null
            && $borrowingRequest->status === RequestStatus::UnderSpmu;

        $isOperationalOfficer = $workspace === 'SPMU'
            && $user->access_classification === AccessClassification::SpmuOfficer
            && (
                $borrowingRequest->final_approved_at !== null
                || $borrowingRequest->status === RequestStatus::UnderSpmu
                || $canVerify
            );

        abort_unless(
            $isBorrowerOwner
            || $isSpmuHead
            || $isDelegatedOfficer
            || $isOperationalOfficer
            || $canVerify
            || $canDecide,
            403
        );
    }

    private function canVerifyRequest(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): bool {
        if (
            $borrowingRequest->status !== RequestStatus::UnderSpmu
            || $request->user()->primaryWorkspace() !== 'SPMU'
            || $request->user()->access_classification !== AccessClassification::SpmuOfficer
        ) {
            return false;
        }

        $borrowingRequest->loadMissing('currentVersion.approvalSteps');

        $step = $borrowingRequest->currentVersion?->approvalSteps
            ?->where('sequence_no', 1)
            ->first(fn ($item) => (string) $item->stage_code->value === 'SPMU');

        return $step && in_array($step->decision, ['PENDING', 'RECEIVED'], true);
    }

    private function canDecideApproval(
        Request $request,
        BorrowingRequest $borrowingRequest
    ): bool {
        if ($borrowingRequest->status !== RequestStatus::UnderSpmu) {
            return false;
        }

        $user = $request->user();

        if ($user->primaryWorkspace() !== 'SPMU') {
            return false;
        }

        $hasFinalAuthority =
            $user->access_classification === AccessClassification::SpmuHead
            || (
                $user->access_classification === AccessClassification::SpmuOfficer
                && $user->activeDelegationFor('SPMU') !== null
            );

        if (! $hasFinalAuthority) {
            return false;
        }

        $borrowingRequest->loadMissing('currentVersion.approvalSteps');

        $step = $borrowingRequest->currentVersion?->approvalSteps
            ?->where('sequence_no', 2)
            ->first(fn ($item) => (string) $item->stage_code->value === 'SPMU');

        return $step && in_array($step->decision, ['PENDING', 'RECEIVED'], true);
    }

    private function approvalStage(
        RequestStatus $status
    ): ?string {
        return $status === RequestStatus::UnderSpmu
            ? 'SPMU'
            : null;
    }
}
