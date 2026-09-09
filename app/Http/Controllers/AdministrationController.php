<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AuditEvent;
use App\Models\SystemSetting;
use App\Models\TechnicalOperation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AdministrationController extends Controller
{
    public function index(Request $request): View
    {
        $mayViewAuditTrail = $request->user()?->access_classification
            === AccessClassification::IctuMaintainer;

        return view('administration.index', [
            'userCount' => User::count(),
            'activeUserCount' => User::where('account_status', 'ACTIVE')->count(),
            'openSettings' => SystemSetting::whereNull('value_json')->count(),
            'mayViewAuditTrail' => $mayViewAuditTrail,
            'recentAudits' => $mayViewAuditTrail
                ? AuditEvent::with('actor')->latest('occurred_at')->limit(10)->get()
                : new Collection,
            'technicalOperations' => TechnicalOperation::latest('started_at')->limit(5)->get(),
        ]);
    }
}
