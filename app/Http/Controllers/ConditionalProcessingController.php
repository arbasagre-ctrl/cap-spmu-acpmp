<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\GatePass;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\LaundryJob;
use App\Models\LaundryRecord;
use App\Models\OverdueCase;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\CustodyService;
use App\Services\DocumentService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

                $gatePass->loadMissing('custody');

                if ($gatePass->status === 'VERIFIED') {
                    return false;
                }

                if (
                    ! $gatePass->custody?->released_at
                    || ! in_array(
                        $gatePass->status,
                        ['PENDING', 'READY_FOR_PRINTING'],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'gate_pass' =>
                            'The accomplished Gate Pass can be verified only after the approved items have been physically released.',
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

        return back()->with(
            'status',
            $verified
                ? 'Accomplished Gate Pass recorded and verified.'
                : 'This Gate Pass is already verified. No duplicate scan or verification was recorded.'
        );
    }

    public function laundry(Request $request, LaundryRecord $laundry, AuditService $audit, DocumentService $documents): RedirectResponse
    {
        $laundry->loadMissing('returnLine.custodyLine.requestItem.inventoryItem', 'returnLine.custodyLine.custody');
        $data = $request->validate([
            'worker_name' => ['required', 'string', 'max:255'],
            'worker_received_at' => ['required', 'date'],
            'worker_completed_at' => ['required', 'date', 'after_or_equal:worker_received_at'],
            'cleaned_quantity' => ['required', 'integer', 'min:0'],
            'damaged_quantity' => ['required', 'integer', 'min:0'],
        ]);
        $documentId = $laundry->form_document_id ?: DB::table('generated_documents')
            ->where('subject_type', CustodyTransaction::class)
            ->where('subject_id', $laundry->returnLine->custodyLine->custody_transaction_id)
            ->where('document_type', 'LAUNDRY_FORM')->where('status', 'FINAL')->value('id');
        $verifiedEvidence = $documentId && DB::table('evidence_submissions')->where('generated_document_id', $documentId)->where('verification_status', 'VERIFIED')->exists();
        if (! $verifiedEvidence) {
            throw ValidationException::withMessages(['laundry' => 'Verify the uploaded signed Laundry Form evidence before final physical inspection.']);
        }
        $expected = (float) $laundry->returnLine->quantity_received;
        if (abs(((float) $data['cleaned_quantity'] + (float) $data['damaged_quantity']) - $expected) > 0.0001) {
            throw ValidationException::withMessages(['cleaned_quantity' => "Cleaned plus damaged quantities must equal the returned linen quantity of {$expected}."]);
        }

        $verified = DB::transaction(function () use ($laundry, $request, $data, $documentId, $audit, $documents): bool {
            $laundry = LaundryRecord::query()->lockForUpdate()->findOrFail($laundry->id);
            $laundry->loadMissing('returnLine.custodyLine.requestItem.inventoryItem', 'returnLine.custodyLine.custody');
            if ($laundry->status === 'VERIFIED') {
                return false;
            }
            if ($laundry->status !== 'EVIDENCE_VERIFIED_PENDING_PHYSICAL_CHECK') {
                throw ValidationException::withMessages(['laundry' => 'Laundry completion requires verified evidence for the current form before physical inspection.']);
            }
            $itemId = $laundry->returnLine->custodyLine->requestItem->inventory_item_id;
            $transactionId = DB::table('inventory_transactions')->insertGetId([
                'actor_user_id' => $request->user()->id,
                'transaction_type' => 'LAUNDRY_COMPLETION',
                'source_type' => LaundryRecord::class,
                'source_id' => $laundry->id,
                'reason' => 'Signed form evidence and physical linen condition independently verified.',
                'correlation_id' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ([['AVAILABLE', (float) $data['cleaned_quantity']], ['DAMAGED_MAINTENANCE', (float) $data['damaged_quantity']]] as [$state, $quantity]) {
                if ($quantity <= 0) {
                    continue;
                }
                DB::table('inventory_transaction_lines')->insert([
                    'inventory_transaction_id' => $transactionId,
                    'inventory_item_id' => $itemId,
                    'from_state' => 'LAUNDRY',
                    'to_state' => $state,
                    'quantity' => $quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $laundry->update([
                'form_document_id' => $documentId,
                'verified_by_user_id' => $request->user()->id,
                'worker_name' => $data['worker_name'],
                'worker_received_at' => $data['worker_received_at'],
                'worker_completed_at' => $data['worker_completed_at'],
                'cleaned_quantity' => $data['cleaned_quantity'],
                'damaged_quantity' => $data['damaged_quantity'],
                'status' => 'VERIFIED',
                'verified_at' => now(),
            ]);
            $laundry->returnLine->custodyLine->update(['item_status' => (float) $data['damaged_quantity'] > 0 ? 'INCIDENT_PENDING' : 'RETURNED', 'compliance_status' => 'LAUNDRY_COMPLETED']);
            $custody = $laundry->returnLine->custodyLine->custody;
            if ((float) $data['damaged_quantity'] > 0) {
                $incident = Incident::query()->create([
                    'incident_no' => 'INC-LAUNDRY-'.now()->format('YmdHis').'-'.$laundry->id,
                    'custody_transaction_id' => $custody->id,
                    'borrower_user_id' => $custody->borrower_user_id,
                    'reported_by_user_id' => $request->user()->id,
                    'incident_type' => 'DAMAGED',
                    'reported_at' => now(),
                    'status' => 'OPEN',
                    'remarks' => 'Damage confirmed during final laundry inspection.',
                ]);
                IncidentLine::query()->create([
                    'incident_id' => $incident->id,
                    'custody_line_id' => $laundry->returnLine->custody_line_id,
                    'quantity' => $data['damaged_quantity'],
                    'observed_condition' => 'DAMAGED_AFTER_LAUNDRY',
                    'disposition_state' => 'DAMAGED_MAINTENANCE',
                ]);
                BorrowerRestriction::query()->firstOrCreate([
                    'borrower_user_id' => $custody->borrower_user_id,
                    'incident_id' => $incident->id,
                    'status' => 'ACTIVE',
                ], [
                    'restriction_type' => 'UNRESOLVED_INCIDENT',
                    'reason' => 'Unresolved laundry damage incident '.$incident->incident_no.'.',
                    'effective_from' => now(),
                    'imposed_by_user_id' => $request->user()->id,
                ]);
                if (SystemSetting::value('rslddp_template_status') === 'APPROVED') {
                    $documents->rslddp($incident->fresh());
                }
            }
            app(CustodyService::class)->reconcileTransactionStatus($custody);
            $audit->record('LAUNDRY_PHYSICAL_VERIFICATION', $laundry, after: $data);

            return true;
        }, 3);

        return back()->with('status', $verified
            ? 'Laundry evidence and physical condition verified. Clean linen returned to Available.'
            : 'Laundry completion was already verified. No duplicate ledger entry or incident was created.');
    }
}
