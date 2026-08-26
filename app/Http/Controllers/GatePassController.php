<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\GatePass;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GatePassController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeOfficer($request);

        return view('gate-passes.index', [
            'gatePasses' => GatePass::query()
                ->with([
                    'custody.borrower',
                    'custody.request.currentVersion',
                    'passDocument',
                    'accomplishedFile',
                ])
                ->latest('updated_at')
                ->get(),
        ]);
    }

    public function show(Request $request, GatePass $gatePass): View
    {
        $this->authorizeOfficer($request);

        $gatePass->load([
            'custody.borrower',
            'custody.request.currentVersion',
            'custody.lines.requestItem.inventoryItem.unit',
            'passDocument',
            'accomplishedFile',
            'uploadedBy',
            'verifiedBy',
        ]);

        return view('gate-passes.show', compact('gatePass'));
    }

    private function authorizeOfficer(Request $request): void
    {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuOfficer,
            403,
            'Gate Pass processing is an SPMU Action Officer operation.'
        );
    }
}
