<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\CustodyTransaction;
use App\Models\GatePass;
use App\Models\LaundryJob;
use App\Models\OverdueCase;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\CustodyService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ConditionalProcessingController extends Controller
{
    public function gatePass(
        Request $request,
        GatePass $gatePass,
        ProtectedFileService $files,
        AuditService $audit,
        CustodyService $custodyService
    ): RedirectResponse {
        abort_unless(
            $request->user()->access_classification
                === AccessClassification::SpmuOfficer,
            403,
            'Only the SPMU Action Officer may verify the accomplished physical Gate Pass.'
        );

        /*
         * If this record is already final, return without accepting or storing
         * another file. This makes repeated submissions idempotent.
         */
        if ($gatePass->status === 'VERIFIED') {
            return back()->with(
                'status',
                'This Gate Pass is already verified. No duplicate scan or verification was recorded.'
            );
        }

        $maxKb =
            ((int) SystemSetting::value('max_upload_mb', 5))
            * 1024;

        $data = $request->validate([
            'accomplished_form' => [
                'required',
                'file',
                'mimes:pdf,png,jpg,jpeg,webp',
                'max:'.$maxKb,
            ],
            'guard_name' => [
                'required',
                'string',
                'max:255',
            ],
            'guard_signed_at' => [
                'required',
                'date',
            ],
            'remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $verified = DB::transaction(
            function () use (
                $request,
                $gatePass,
                $files,
                $audit,
                $data
            ): bool {
                $gatePass = GatePass::query()
                    ->lockForUpdate()
                    ->findOrFail($gatePass->id);

                $gatePass->loadMissing('custody.returns');

                if ($gatePass->status === 'VERIFIED') {
                    return false;
                }

                $recordedReturnAt = $gatePass->custody?->returns
                    ?->pluck('received_at')
                    ->filter()
                    ->sortDesc()
                    ->first();

                $physicalReturnRecorded = $recordedReturnAt !== null;

                $guardReleasedAt = Carbon::parse(
                    $data['guard_signed_at'],
                    config('app.timezone')
                );

                $spmuReleasedAt = $gatePass->custody?->released_at;

                if ($spmuReleasedAt
                    && $guardReleasedAt->lt($spmuReleasedAt->copy()->startOfMinute())) {
                    throw ValidationException::withMessages([
                        'guard_signed_at' =>
                            'The off-campus release time cannot be earlier than the SPMU physical release time. Enter the date and time written by the Guard on the accomplished Gate Pass.',
                    ]);
                }

                if ($recordedReturnAt
                    && $guardReleasedAt->gt($recordedReturnAt->copy()->endOfMinute())) {
                    throw ValidationException::withMessages([
                        'guard_signed_at' =>
                            'The off-campus release time cannot be later than the Physical Return Recorded time. Enter the date and time written by the Guard on the accomplished Gate Pass.',
                    ]);
                }

                if (
                    ! $gatePass->custody?->released_at
                    || ! $physicalReturnRecorded
                    || ! in_array(
                        $gatePass->status,
                        ['PENDING', 'READY_FOR_PRINTING'],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'gate_pass' =>
                            'The accomplished Gate Pass can be recorded only after the physical return inspection has been recorded.',
                    ]);
                }

                $file = $files->storeUpload(
                    $data['accomplished_form'],
                    'gate-pass-evidence/'.$gatePass->id,
                    'PAPER_EVIDENCE'
                );

                $gatePass->update([
                    'accomplished_file_id' =>
                        $file->id,

                    'uploaded_by_user_id' =>
                        $request->user()->id,

                    'uploaded_at' =>
                        now(),

                    'guard_name' =>
                        $data['guard_name'],

                    'guard_signed_at' =>
                        $data['guard_signed_at'],

                    'verified_by_user_id' =>
                        $request->user()->id,

                    'verified_at' =>
                        now(),

                    'verification_remarks' =>
                        $data['remarks'] ?? null,

                    'status' =>
                        'VERIFIED',

                    /*
                     * The SPMU Head's approval E-signature and the Action
                     * Officer's issuance E-signature are the authorizations
                     * printed on this Gate Pass. They are deliberately NOT
                     * cleared here: verifying the guard's returned wet-signed
                     * scan must never erase the electronic authorizations that
                     * permitted the off-campus movement in the first place.
                     */
                ]);

                $gatePass
                    ->custody
                    ->lines()
                    ->whereHas(
                        'requestItem',
                        fn ($query) =>
                            $query->where(
                                'use_location',
                                'OFF_CAMPUS'
                            )
                    )
                    ->update([
                        'compliance_status' =>
                            'GATE_PASS_COMPLETED',
                    ]);

                $audit->record(
                    'GATE_PASS_ACCOMPLISHED_VERIFIED',
                    $gatePass,
                    reason:
                        $data['remarks'] ?? null,
                    after: [
                        'stored_file_id' =>
                            $file->id,

                        'guard_name' =>
                            $data['guard_name'],

                        'guard_signed_at' =>
                            $data['guard_signed_at'],

                        'verification_method' =>
                            'SCANNED_WET_SIGNED_FORM',
                    ]
                );

                return true;
            },
            3
        );

        if ($verified) {
            $custody = $gatePass->custody()->first();

            if ($custody) {
                $custodyService->reconcileTransactionStatus($custody);
            }
        }

        $statusMessage = $verified
            ? 'Accomplished Gate Pass recorded and verified.'
            : 'This Gate Pass is already verified. No duplicate scan or verification was recorded.';

        /*
         * Continue the exact transaction automatically. Current Laundry records
         * return to SPMU Return for form-based linen encoding. Only older
         * TURNED_OVER_TO_LAUNDRY records wait for the system's one-time
         * availability reconciliation. No Action Officer step is required.
         */
        $custody = $gatePass->custody()->with('laundryJob.latestEvidence')->first();

        if ($custody) {
            $laundryJob = $custody->laundryJob;

            if ($laundryJob?->status === 'TURNED_OVER_TO_LAUNDRY') {
                return redirect()
                    ->route('laundry.show', $laundryJob)
                    ->with('status', $statusMessage.' This older Laundry record is pending automatic availability reconciliation; no Action Officer action is required.');
            }

            if ($laundryJob?->status === 'FOR_LAUNDRY'
                && $laundryJob->hasVerifiedAccomplishedForm()) {
                return redirect()
                    ->to(route('custody.return.show', $custody).'#return-primary')
                    ->with('status', $statusMessage.' Next: encode the completed Laundry Form in Return.');
            }

            return redirect()
                ->to(route('custody.return.show', $custody).'#return-summary')
                ->with('status', $statusMessage);
        }

        return back()->with('status', $statusMessage);
    }

}
