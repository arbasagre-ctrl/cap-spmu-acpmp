<?php

namespace App\Http\Controllers;

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

        /*
         * Laundry operations have no borrower-facing screen, so the branch
         * turns on what the viewer may actually open rather than on which
         * workspace tab they happen to be in.
         */
        $hasSpmuAccess = (bool) auth()->user()?->hasWorkspace('SPMU');

        return match ($event->source_type) {
            BorrowingRequest::class => $this->requestUrl($sourceId),
            CustodyTransaction::class => $this->custodyUrl($sourceId),
            LaundryJob::class => $this->laundryUrl($sourceId, $hasSpmuAccess),
            GeneratedDocument::class => $this->documentUrl($sourceId),
            BillingStatement::class, Incident::class => route('accountability.index'),
            default => null,
        };
    }

    private function requestUrl(int $requestId): ?string
    {
        $borrowingRequest = BorrowingRequest::query()->find($requestId);

        return $borrowingRequest
            ? route('requests.show', $borrowingRequest)
            : null;
    }

    private function custodyUrl(int $custodyId): ?string
    {
        $custody = CustodyTransaction::query()->find($custodyId);

        return $custody
            ? route('custody.show', $custody)
            : null;
    }

    private function laundryUrl(int $jobId, bool $hasSpmuAccess): ?string
    {
        $job = LaundryJob::query()->find($jobId);

        if (! $job) {
            return null;
        }

        if ($hasSpmuAccess) {
            return route('laundry.show', $job);
        }

        return $job->custody_transaction_id
            ? $this->custodyUrl((int) $job->custody_transaction_id)
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
