<?php

use App\Http\Controllers\AccountabilityController;
use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BorrowingRequestController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ConditionalProcessingController;
use App\Http\Controllers\CustodyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DelegationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\GatePassController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LaundryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfilePictureController;
use App\Http\Controllers\PolicyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TechnicalOperationController;
use App\Http\Controllers\UserAdministrationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])
        ->name('login');

    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function (): void {

    /*
    |--------------------------------------------------------------------------
    | Dashboard / Authentication
    |--------------------------------------------------------------------------
    */

    Route::get('/dashboard', DashboardController::class)
        ->name('dashboard');

    Route::post('/logout', [LoginController::class, 'destroy'])
        ->name('logout');


    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');

    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
        ->name('notifications.read-all');

    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
        ->name('notifications.read');


    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    Route::get('/profile', [ProfileController::class, 'show'])
        ->name('profile.show');

    Route::put('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::get('/profile/picture', [ProfilePictureController::class, 'show'])
        ->name('profile.picture.show');

    Route::post('/profile/picture', [ProfilePictureController::class, 'update'])
        ->name('profile.picture.update');

    Route::delete('/profile/picture', [ProfilePictureController::class, 'destroy'])
        ->name('profile.picture.destroy');

    Route::get('/protected-files/{file}', [DocumentController::class, 'protectedFile'])
        ->name('files.show');


    /*
    |--------------------------------------------------------------------------
    | Inventory - Borrower + SPMU
    |--------------------------------------------------------------------------
    |
    | Borrower:
    | - Read-only inventory visibility
    | - Current borrowable availability
    | - No create / edit / reserve / hold
    |
    | SPMU:
    | - Full operational inventory access
    |
    */

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/inventory', [InventoryController::class, 'index'])
            ->name('inventory.index');

        Route::get('/inventory-availability', [InventoryController::class, 'availabilityData'])
            ->name('inventory.availability');
        Route::get('/inventory/{inventory}', [InventoryController::class, 'show'])
            ->whereNumber('inventory')
            ->name('inventory.show');
    });


    /*
    |--------------------------------------------------------------------------
    | Inventory Management - SPMU Only
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:SPMU')->group(function (): void {

        Route::get('/inventory/create', [InventoryController::class, 'create'])
            ->name('inventory.create');

        Route::post('/inventory', [InventoryController::class, 'store'])
            ->name('inventory.store');

        Route::get('/inventory/{inventory}/edit', [InventoryController::class, 'edit'])
            ->name('inventory.edit');

        Route::put('/inventory/{inventory}', [InventoryController::class, 'update'])
            ->name('inventory.update');
    });


    /*
    |--------------------------------------------------------------------------
    | Borrowing Calendar
    |--------------------------------------------------------------------------
    |
    | Active borrower/SPMU operational calendar only.
    | GSU/VPAF are not borrowing approvers in the current workflow.
    |
    */

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/calendar', [CalendarController::class, 'index'])
            ->name('calendar.index');
    });


    /*
    |--------------------------------------------------------------------------
    | Borrowing Request - Create / Store
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:BORROWER')->group(function (): void {

        Route::get('/requests/create', [BorrowingRequestController::class, 'create'])
            ->name('requests.create');

        Route::post('/requests', [BorrowingRequestController::class, 'store'])
            ->name('requests.store');
    });


    /*
    |--------------------------------------------------------------------------
    | Borrowing Requests - Active Workflow View
    |--------------------------------------------------------------------------
    |
    | Borrower and SPMU only. GSU/VPAF approval stages are retired from the
    | active workflow; historical database values may remain for compatibility.
    */

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/requests', [BorrowingRequestController::class, 'index'])
            ->name('requests.index');

        Route::get('/requests/{borrowingRequest}', [BorrowingRequestController::class, 'show'])
            ->name('requests.show');
    });


    /*
    |--------------------------------------------------------------------------
    | Borrowing Request - Borrower Actions
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:BORROWER')->group(function (): void {

        Route::get('/requests/{borrowingRequest}/edit', [BorrowingRequestController::class, 'edit'])
            ->name('requests.edit');

        Route::put('/requests/{borrowingRequest}', [BorrowingRequestController::class, 'update'])
            ->name('requests.update');

        Route::post(
            '/requests/{borrowingRequest}/recover-draft-document',
            [BorrowingRequestController::class, 'recoverDraftDocument']
        )->name('requests.recover-draft-document');

        Route::post('/requests/{borrowingRequest}/submit', [BorrowingRequestController::class, 'submit'])
            ->name('requests.submit');
    });


    /*
    |--------------------------------------------------------------------------
    | Request Cancellation
    |--------------------------------------------------------------------------
    */

    Route::post('/requests/{borrowingRequest}/cancel', [BorrowingRequestController::class, 'cancel'])
        ->middleware('workspace:BORROWER,SPMU')
        ->name('requests.cancel');

    Route::post(
        '/requests/{borrowingRequest}/cancellation/review',
        [BorrowingRequestController::class, 'reviewCancellation']
    )
        ->middleware('workspace:SPMU')
        ->name('requests.cancellation.review');


    /*
    |--------------------------------------------------------------------------
    | Approval Workflow - SPMU Only
    |--------------------------------------------------------------------------
    |
    | New submissions are verified/decided by SPMU. GSU/VPAF are not active
    | in-system approval stages.
    */

    Route::middleware('workspace:SPMU')->group(function (): void {

        Route::get('/approvals', [ApprovalController::class, 'index'])
            ->name('approvals.index');

        Route::post('/approvals/{borrowingRequest}', [ApprovalController::class, 'decide'])
            ->name('approvals.decide');
    });


    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');


    /*
    |--------------------------------------------------------------------------
    | Custody - Borrower + SPMU
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:BORROWER,SPMU')->group(function (): void {

        Route::get('/custody', [CustodyController::class, 'index'])
            ->name('custody.index');

        Route::get('/custody/{custody}', [CustodyController::class, 'show'])
            ->name('custody.show');
    });

    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/release', [CustodyController::class, 'releaseIndex'])
            ->name('custody.release.index');

        Route::get('/release/{custody}', [CustodyController::class, 'releaseShow'])
            ->name('custody.release.show');

        Route::get('/return', [CustodyController::class, 'returnIndex'])
            ->name('custody.return.index');

        Route::get('/return/{custody}', [CustodyController::class, 'returnShow'])
            ->name('custody.return.show');
    });

    Route::post('/custody/{custody}/schedule-pickup', [CustodyController::class, 'schedulePickup'])
        ->middleware('workspace:SPMU')
        ->name('custody.schedule-pickup');

    Route::post('/custody/{custody}/quantities', [CustodyController::class, 'quantities'])
        ->middleware('workspace:SPMU')
        ->name('custody.quantities');

    Route::post('/custody/{custody}/prepare', [CustodyController::class, 'prepare'])
        ->middleware('workspace:SPMU')
        ->name('custody.prepare');

    Route::post('/custody/{custody}/acknowledge', [CustodyController::class, 'acknowledge'])
        ->middleware('workspace:BORROWER')
        ->name('custody.acknowledge');

    Route::post('/custody/{custody}/release', [CustodyController::class, 'release'])
        ->middleware('workspace:SPMU')
        ->name('custody.release');

    Route::post('/custody/{custody}/return', [CustodyController::class, 'receiveReturn'])
        ->middleware('workspace:SPMU')
        ->name('custody.return');


    /*
    |--------------------------------------------------------------------------
    | Evidence
    |--------------------------------------------------------------------------
    */

    Route::post('/documents/{document}/evidence', [EvidenceController::class, 'store'])
        ->middleware('workspace:BORROWER,SPMU')
        ->name('evidence.store');

    Route::post('/evidence/{evidence}/verify', [EvidenceController::class, 'verify'])
        ->middleware('workspace:SPMU')
        ->name('evidence.verify');


    /*
    |--------------------------------------------------------------------------
    | Gate Pass - SPMU Action Officer
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/gate-passes', [GatePassController::class, 'index'])
            ->name('gate-passes.index');

        Route::get('/gate-passes/{gatePass}', [GatePassController::class, 'show'])
            ->name('gate-passes.show');

        Route::post(
            '/gate-passes/{gatePass}/verify',
            [ConditionalProcessingController::class, 'gatePass']
        )->name('gate-passes.verify');
    });



    /*
    |--------------------------------------------------------------------------
    | Simple Laundry Worker Portal
    |--------------------------------------------------------------------------
    |
    | Laundry Worker responsibility:
    | - receive used linen + the borrower-signed physical Laundry Form
    | - record actual receipt and laundry completion details
    | - bring cleaned linen + the same form directly to SPMU
    | - upload the fully signed form after SPMU final acceptance
    |
    | The Borrower does not collect cleaned linen or encode laundry quantities.
    |
    */

    Route::middleware('workspace:LAUNDRY')->group(function (): void {
        Route::get('/laundry', [LaundryController::class, 'index'])
            ->name('laundry.index');

        Route::get('/laundry/completed', [LaundryController::class, 'completed'])
            ->name('laundry.completed');

        Route::get('/laundry/{laundryJob}', [LaundryController::class, 'show'])
            ->name('laundry.show');

        Route::post('/laundry/{laundryJob}/receive', [LaundryController::class, 'receive'])
            ->name('laundry.receive');

        Route::post('/laundry/{laundryJob}/complete-processing', [LaundryController::class, 'completeProcessing'])
            ->name('laundry.complete-processing');

    });

    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/spmu/laundry', [LaundryController::class, 'spmuIndex'])
            ->name('laundry.spmu.index');

        Route::get('/spmu/laundry/{laundryJob}', [LaundryController::class, 'spmuShow'])
            ->name('laundry.spmu.show');

        Route::post('/spmu/laundry/{laundryJob}/upload-form', [LaundryController::class, 'upload'])
            ->name('laundry.spmu.upload-form');
    });



    /*
    |--------------------------------------------------------------------------
    | Laundry Processing
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/laundry/{laundry}/verify',
        [ConditionalProcessingController::class, 'laundry']
    )
        ->middleware('workspace:SPMU')
        ->name('laundry.verify');


    /*
    |--------------------------------------------------------------------------
    | Accountability
    |--------------------------------------------------------------------------
    */

    Route::get('/accountability', [AccountabilityController::class, 'index'])
        ->middleware('workspace:BORROWER,SPMU')
        ->name('accountability.index');

    Route::post('/incidents/{incident}/bill', [AccountabilityController::class, 'billIncident'])
        ->middleware('workspace:SPMU')
        ->name('incidents.bill');

    Route::post('/incidents/{incident}/resolve', [AccountabilityController::class, 'resolveIncident'])
        ->middleware('workspace:SPMU')
        ->name('incidents.resolve');

    Route::post('/overdue/{overdue}/bill', [AccountabilityController::class, 'billOverdue'])
        ->middleware('workspace:SPMU')
        ->name('overdue.bill');

    Route::post('/billings/{billing}/payments', [AccountabilityController::class, 'recordPayment'])
        ->middleware('workspace:SPMU')
        ->name('payments.store');

    Route::post('/payments/{payment}/verify', [AccountabilityController::class, 'verifyPayment'])
        ->middleware('workspace:SPMU')
        ->name('payments.verify');

    Route::post('/billings/{billing}/waive', [AccountabilityController::class, 'waive'])
        ->middleware('workspace:SPMU')
        ->name('billings.waive');

    Route::post('/accountability/violations/{violation}/review', [AccountabilityController::class, 'reviewViolation'])
        ->middleware('workspace:SPMU')
        ->name('accountability.violations.review');


    /*
    |--------------------------------------------------------------------------
    | Academic Period Configuration
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:SPMU')->group(function (): void {
        Route::get('/administration/policies', [PolicyController::class, 'index'])
            ->name('policies.index');

        Route::post(
            '/administration/policies/academic-periods',
            [PolicyController::class, 'storeAcademicPeriod']
        )->name('policies.academic-periods.store');

        Route::put(
            '/administration/policies/academic-periods/{period}',
            [PolicyController::class, 'updateAcademicPeriod']
        )->name('policies.academic-periods.update');

        Route::put(
            '/administration/policies/sanctions/{offenseNo}',
            [PolicyController::class, 'updateSanctionRule']
        )->whereNumber('offenseNo')->name('policies.sanctions.update');

        Route::put(
            '/administration/policies/offense-application',
            [PolicyController::class, 'updateOffenseApplication']
        )->name('policies.offense-application.update');


        Route::put(
            '/administration/policies/weekly-schedule/{weekday}',
            [PolicyController::class, 'updateWeeklySchedule']
        )->whereNumber('weekday')->name('policies.weekly-schedule.update');

        Route::post(
            '/administration/policies/date-exceptions',
            [PolicyController::class, 'storeDateException']
        )->name('policies.date-exceptions.store');

        Route::delete(
            '/administration/policies/date-exceptions/{exception}',
            [PolicyController::class, 'destroyDateException']
        )->name('policies.date-exceptions.destroy');
    });


    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    */

    Route::middleware('workspace:SPMU')->group(function (): void {

        Route::get('/reports', [ReportController::class, 'index'])
            ->name('reports.index');

        Route::get('/reports/export/{type}', [ReportController::class, 'export'])
            ->name('reports.export');
    });

    Route::get('/reports/audit', [ReportController::class, 'audit'])
        ->middleware('workspace:SPMU,ICTU')
        ->name('reports.audit');

    Route::get('/reports/notifications', [ReportController::class, 'notifications'])
        ->middleware('workspace:SPMU,ICTU')
        ->name('reports.notifications');


    /*
    |--------------------------------------------------------------------------
    | Administration - SPMU + ICTU
    |--------------------------------------------------------------------------
    */

    Route::prefix('administration')
        ->name('administration.')
        ->middleware('workspace:SPMU,ICTU')
        ->group(function (): void {

            Route::get('/', [AdministrationController::class, 'index'])
                ->name('index');

            Route::get('/settings', [SettingController::class, 'index'])
                ->name('settings.index');

            Route::put('/settings/{setting}', [SettingController::class, 'update'])
                ->name('settings.update');


            Route::post('/document-templates/{type}', [DocumentTemplateController::class, 'store'])
                ->where('type', 'billing-statement|gate-pass|laundry-form')
                ->name('document-templates.store');
        });


    /*
    |--------------------------------------------------------------------------
    | ICTU Administration
    |--------------------------------------------------------------------------
    */

    Route::prefix('administration')
        ->name('administration.')
        ->middleware('workspace:ICTU')
        ->group(function (): void {

            Route::resource('users', UserAdministrationController::class)
                ->except(['show', 'destroy']);

            Route::post('/backup', [TechnicalOperationController::class, 'backup'])
                ->name('backup');

            Route::get('/delegations', [DelegationController::class, 'index'])
                ->name('delegations.index');

            Route::post('/delegations', [DelegationController::class, 'store'])
                ->name('delegations.store');

            Route::post(
                '/delegations/{delegation}/revoke',
                [DelegationController::class, 'revoke']
            )->name('delegations.revoke');
        });
});