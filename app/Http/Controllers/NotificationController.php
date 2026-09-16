<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BillingStatement;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\LaundryJob;
use App\Models\NotificationDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = NotificationDelivery::with('event')
            ->where('recipient_user_id', $request->user()->id)
            ->where('channel', 'SYSTEM')
            ->latest('attempted_at')
            ->paginate(30);

        return view('notifications.index', [
            'notifications' => $notifications,

            /*
             * Only notifications whose record still resolves are rendered as
             * links, so the list never offers a dead click.
             */
            'targets' => $notifications->getCollection()
                ->mapWithKeys(fn (NotificationDelivery $delivery): array => [
                    $delivery->id => $this->targetFor($delivery),
                ]),
        ]);
    }

    /**
     * Open the record a notification refers to.
     *
     * Reading the notification is what opening it means, so the click marks
     * it read on the way through instead of leaving the borrower to press a
     * second button for it.
     */
    public function open(Request $request, NotificationDelivery $notification): RedirectResponse
    {
        abort_unless($notification->recipient_user_id === $request->user()->id && $notification->channel === 'SYSTEM', 403);

        $notification->update(['read_at' => $notification->read_at ?: now()]);

        return redirect()->to(
            $this->targetFor($notification) ?: route('notifications.index')
        );
    }

    public function read(Request $request, NotificationDelivery $notification): RedirectResponse
    {
        abort_unless($notification->recipient_user_id === $request->user()->id && $notification->channel === 'SYSTEM', 403);
        $notification->update(['read_at' => $notification->read_at ?: now()]);

        return back()->with('status', 'Notification marked as read.');
    }

    public function readAll(Request $request): RedirectResponse
    {
        NotificationDelivery::query()
            ->where('recipient_user_id', $request->user()->id)
            ->where('channel', 'SYSTEM')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('status', 'All notifications marked as read.');
    }

    /**
     * Resolve the screen a notification points at.
     *
     * The event already records the model it was raised from, so the target
     * is derived from that rather than parsed back out of the message text.
     * The same source can belong to different screens per workspace: laundry
     * operations are SPMU-only, for instance, so a borrower is taken to the
     * custody record that owns the job. Sources with no borrower- or
     * SPMU-facing record of their own resolve to their working list, and
     * anything unresolvable stays unlinked rather than 404ing on click.
     */
    private function targetFor(NotificationDelivery $notification): ?string
    {
        $event = $notification->event;

        if (! $event || ! $event->source_type || ! $event->source_id) {
            return null;
        }

        $sourceId = (int) $event->source_id;

        $viewer = auth()->user();

        return match ($event->source_type) {
            BorrowingRequest::class => $this->requestUrl($sourceId, $viewer),
            CustodyTransaction::class => $this->custodyUrl($sourceId, $viewer),
            LaundryJob::class => $this->laundryUrl($sourceId, $viewer),
            GeneratedDocument::class => $this->documentUrl($sourceId),
            BillingStatement::class, Incident::class => route('accountability.index'),
            default => null,
        };
    }

    private function requestUrl(int $requestId, $viewer): ?string
    {
        if (! $viewer) {
            return null;
        }

        $borrowingRequest = BorrowingRequest::query()
            ->with('currentVersion.approvalSteps')
            ->find($requestId);

        if (! $borrowingRequest) {
            return null;
        }

        if ((int) $borrowingRequest->borrower_user_id === (int) $viewer->id) {
            return route('requests.show', $borrowingRequest);
        }

        if ($viewer->access_classification === AccessClassification::SpmuOfficer) {
            if ($borrowingRequest->final_approved_at !== null) {
                return route('requests.show', $borrowingRequest);
            }

            if ($borrowingRequest->status === RequestStatus::UnderSpmu) {
                $steps = $borrowingRequest->currentVersion?->approvalSteps ?? collect();
                $canVerify = $steps->contains(
                    fn ($step) => (int) $step->sequence_no === 1
                        && in_array((string) $step->decision, ['PENDING', 'RECEIVED'], true)
                );
                $canDecideAsDelegate = $viewer->activeDelegationFor('SPMU') !== null
                    && $steps->contains(
                        fn ($step) => (int) $step->sequence_no === 2
                            && in_array((string) $step->decision, ['PENDING', 'RECEIVED'], true)
                    );

                if ($canVerify || $canDecideAsDelegate) {
                    return route('requests.show', $borrowingRequest);
                }
            }

            return null;
        }

        if ($viewer->access_classification === AccessClassification::SpmuHead) {
            $steps = $borrowingRequest->currentVersion?->approvalSteps ?? collect();

            if ($borrowingRequest->status !== RequestStatus::UnderSpmu) {
                $hasSpmuHistory = $borrowingRequest->final_approved_at !== null
                    || $steps->contains(fn ($step) => (string) $step->stage_code === 'SPMU');

                return $hasSpmuHistory ? route('requests.show', $borrowingRequest) : null;
            }

            $canDecide = $steps->contains(
                fn ($step) => (int) $step->sequence_no === 2
                    && in_array((string) $step->decision, ['PENDING', 'RECEIVED'], true)
            );

            return $canDecide ? route('requests.show', $borrowingRequest) : null;
        }

        return null;
    }

    private function custodyUrl(int $custodyId, $viewer = null): ?string
    {
        $viewer ??= auth()->user();
        $custody = CustodyTransaction::query()->find($custodyId);

        if (! $custody || ! $viewer) {
            return null;
        }

        $isOwner = (int) $custody->borrower_user_id === (int) $viewer->id;
        $isSpmu = in_array(
            $viewer->access_classification,
            [AccessClassification::SpmuOfficer, AccessClassification::SpmuHead],
            true
        );

        return ($isOwner || $isSpmu)
            ? route('custody.show', $custody)
            : null;
    }

    private function laundryUrl(int $jobId, $viewer): ?string
    {
        $job = LaundryJob::query()->find($jobId);

        if (! $job || ! $viewer) {
            return null;
        }

        if ($viewer->access_classification === AccessClassification::SpmuOfficer) {
            return route('laundry.show', $job);
        }

        return $job->custody_transaction_id
            ? $this->custodyUrl((int) $job->custody_transaction_id, $viewer)
            : null;
    }

    /**
     * Evidence notifications are raised against the generated document, which
     * is filed under the custody transaction it was produced for.
     */
    private function documentUrl(int $documentId): ?string
    {
        $document = GeneratedDocument::query()->find($documentId);

        if (! $document || $document->subject_type !== CustodyTransaction::class) {
            return null;
        }

        return $this->custodyUrl((int) $document->subject_id);
    }
}
