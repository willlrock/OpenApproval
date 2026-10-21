<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\DepartmentAdminController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\ProjectAdminController;
use App\Http\Controllers\Admin\RegistrationAdminController;
use App\Http\Controllers\Admin\SignatureAdminController;
use App\Http\Controllers\Admin\SuppliersController;
use App\Http\Controllers\Admin\SupplyController;
use App\Http\Controllers\Admin\UserAdminController;
use App\Models\ApprovalRequest;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', function (\SergiX44\Nutgram\Nutgram $bot) {
    $bot->run();

    return response()->noContent();
})->name('nutgram.webhook')->middleware(\App\Http\Middleware\VerifyTelegramWebhook::class)->withoutMiddleware([
    \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
]);

Route::get('/', function () {
    $user = auth()->user();
    if (! $user) {
        return redirect()->route('login');
    }

    if ($user->role === 'supplier') {
        return redirect()->route('supply.dashboard');
    }

    return redirect()->to($user->canViewApprovalDashboard() ? '/dashboard' : '/request-drafts');
})->name('home');

Route::get('verify/{id}/{hash}', function ($id, $hash) {
    $request = ApprovalRequest::with(['project', 'applicant', 'approvals.approver'])->find($id);

    if (! $request) {
        abort(404, 'Заявка не найдена');
    }

    $expectedHash = sha1($request->id.$request->created_at.config('app.key'));
    if (! hash_equals($expectedHash, $hash)) {
        abort(403, 'Неверный код проверки');
    }

    return inertia('Verify', [
        'request' => [
            'id' => $request->id,
            'type' => $request->type,
            'document_kind' => $request->document_kind,
            'document_number' => $request->document_number,
            'effective_date_label' => $request->isManagementDocument()
                ? app(\App\Services\ManagementDocumentService::class)->effectiveDateLabel(
                    $request->effective_date_mode,
                    $request->effective_date,
                    $request->finalized_at
                )
                : null,
            'name' => $request->name,
            'status' => $request->status,
            'total_price' => $request->total_price,
            'currency' => $request->currency,
            'project' => $request->project?->name,
            'applicant' => $request->applicant?->full_name,
            'created_at' => $request->created_at->format('d.m.Y H:i'),
            'approvals' => $request->approvals->sortBy('level')->map(fn ($a) => [
                'level' => $a->level,
                'status' => $a->status,
                'approver' => $a->approver?->full_name,
                'action_date' => $a->action_date
                    ? \Carbon\Carbon::parse($a->action_date)->format('d.m.Y H:i')
                    : null,
            ])->values(),
        ],
        'verified' => true,
    ]);
})->name('verify');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::controller(\App\Http\Controllers\ManagementDraftController::class)->group(function () {
        Route::get('management-drafts/create', 'create')->name('management-drafts.create');
        Route::post('management-drafts', 'store')->middleware('throttle:30,1')->name('management-drafts.store');
        Route::post('management-drafts/{id}/preview', 'preview')->middleware('throttle:5,1')->name('management-drafts.preview');
        Route::get('management-drafts/{id}/preview', 'previewFile')->name('management-drafts.preview-file');
        Route::get('management-templates/{kind}', 'template')->name('management-drafts.template');
        Route::get('management-submissions/{id}/document', 'document')->name('management-drafts.document');
    });
    Route::controller(\App\Http\Controllers\RequestDraftController::class)->group(function () {
        Route::get('request-drafts', 'index')->name('request-drafts.index');
        Route::post('request-drafts', 'store')->middleware('throttle:30,1')->name('request-drafts.store');
        Route::delete('request-drafts/{id}', 'destroy')->name('request-drafts.destroy');
        Route::post('request-submissions/{id}/revise', 'revise')->name('request-submissions.revise');
        Route::get('request-drafts/{id}', 'edit')->name('request-drafts.edit');
        Route::put('request-drafts/{id}', 'update')->name('request-drafts.update');
        Route::post('request-drafts/{id}/submit', 'submit')->middleware('throttle:20,1')->name('request-drafts.submit');
        Route::post('request-drafts/{id}/files', 'upload')->middleware('throttle:30,1')->name('request-drafts.upload');
        Route::get('request-drafts/{id}/files/{fileId}', 'file')->name('request-drafts.file');
        Route::delete('request-drafts/{id}/files/{fileId}', 'removeFile')->name('request-drafts.remove-file');
        Route::post('request-drafts/{id}/files/{fileId}/analyze', 'analyze')->middleware('throttle:5,1')->name('request-drafts.analyze');
        Route::get('request-submissions/{id}', 'show')->name('request-submissions.show');
        Route::get('request-submissions/{id}/files/{fileId}', 'submittedFile')->name('request-submissions.file');
        Route::post('request-submissions/{id}/copy', 'copy')->middleware('throttle:20,1')->name('request-submissions.copy');
    });

    Route::get('requests/{id}/attachments/{fileId}', [\App\Http\Controllers\RequestFilesController::class, 'attachment'])->name('requests.attachment');
    Route::get('requests/{id}/protocol', [\App\Http\Controllers\RequestFilesController::class, 'protocol'])->name('requests.protocol');
    Route::get('requests/{id}/tranches/{trancheId}/proof', [\App\Http\Controllers\RequestFilesController::class, 'proof'])->name('requests.proof');

    Route::get('requests/{id}/history/{version}/files/{fileId}', [\App\Http\Controllers\RequestRevisionController::class, 'file'])->name('requests.history-file');
    Route::controller(\App\Http\Controllers\ApprovalRouteRecoveryController::class)->group(function () {
        Route::get('requests/{id}/approval-route', 'edit')->name('requests.approval-route.edit');
        Route::put('requests/{id}/approval-route', 'save')->name('requests.approval-route.save');
        Route::post('requests/{id}/approval-route/submit', 'submit')->name('requests.approval-route.submit');
    });

    Route::get('requests/{id}/history', [\App\Http\Controllers\RequestRevisionController::class, 'history'])->name('requests.history');
    Route::post('requests/{id}/request-changes', [\App\Http\Controllers\RequestRevisionController::class, 'requestChanges'])->name('requests.request-changes');

    // ─── Дашборд + Заявки (все роли с доступом к панели + точечный allowlist) ──
    Route::middleware('approval.dashboard')->group(function () {
        Route::get('dashboard', [\App\Http\Controllers\DashboardController::class, 'dashboard'])->name('dashboard');
        Route::get('dashboard/requests', [AdminController::class, 'requests'])->name('requests');
        Route::get('dashboard/my-requests', [AdminController::class, 'myRequests'])->name('my-requests');
        Route::get('dashboard/my-approvals', [AdminController::class, 'myApprovals'])->name('my-approvals');
        Route::get('dashboard/requests/{id}', [AdminController::class, 'requestDetail'])->name('requests.detail');
        Route::post('dashboard/requests/{id}/approve', [AdminController::class, 'approveRequestWeb'])->name('requests.approve');
        Route::post('dashboard/requests/{id}/reject', [AdminController::class, 'rejectRequestWeb'])->name('requests.reject');
        Route::post('dashboard/requests/{id}/add-approver', [AdminController::class, 'addApproverWeb'])->name('requests.add-approver');
        Route::delete('dashboard/requests/{id}', [AdminController::class, 'deleteRequestWeb'])->name('requests.delete');
        Route::get('dashboard/requests/{id}/export-items', [AdminController::class, 'exportRequestItems'])->name('requests.export-items');
        Route::get('dashboard/requests/{requestId}/attachments/{attachmentId}/download', [AdminController::class, 'downloadAttachment'])->name('requests.attachment.download');
        Route::get('dashboard/requests/{id}/document-preview', [AdminController::class, 'downloadManagementDocumentPreview'])->name('requests.document.preview');
        Route::get('dashboard/requests/{id}/document-final', [AdminController::class, 'downloadManagementDocumentFinal'])->name('requests.document.final');
    });

    // ─── Финансовый кабинет (казначей + бухгалтер + admins) ──────────────
    Route::middleware('role:superadmin,admin,accountant,treasurer')->group(function () {
        Route::get('finance/requests', [FinanceController::class, 'requests'])->name('finance.requests');
        Route::get('finance/requests/{id}', [FinanceController::class, 'requestDetail'])->name('finance.requests.detail');
        Route::put('finance/requests/{requestId}/tranches/{trancheId}', [FinanceController::class, 'updateTranche'])->name('finance.tranches.update');
    });

    // ─── Поставщики (superadmin + admin + supplier) ───────────────────────
    Route::middleware('role:superadmin,admin,supplier')->group(function () {
        Route::get('dashboard/suppliers', [SuppliersController::class, 'index'])->name('suppliers');
        Route::post('dashboard/suppliers', [SuppliersController::class, 'store'])->name('suppliers.store');
        Route::put('dashboard/suppliers/{id}', [SuppliersController::class, 'update'])->name('suppliers.update');
        Route::delete('dashboard/suppliers/{id}', [SuppliersController::class, 'destroy'])->name('suppliers.destroy');
        Route::post('dashboard/suppliers/import', [SuppliersController::class, 'importExcel'])->name('suppliers.import');
        Route::post('dashboard/requests/{requestId}/attach-supplier', [SuppliersController::class, 'attachToRequest'])->name('suppliers.attach');
    });

    // ─── Управление (только Финансовый директор + Суперадмин) ─────────────
    Route::middleware('role:superadmin,admin')->group(function () {
        Route::get('dashboard/users', [UserAdminController::class, 'users'])->name('users');
        Route::post('dashboard/users', [UserAdminController::class, 'storeUser'])->name('users.store');
        Route::put('dashboard/users/{id}', [UserAdminController::class, 'updateUser'])->name('users.update');
        Route::patch('dashboard/users/{id}/activation', [UserAdminController::class, 'updateActivation'])->name('users.activation');
        Route::get('dashboard/users/{id}/signature', [SignatureAdminController::class, 'show'])->name('users.signature.show');
        Route::post('dashboard/users/{id}/signature', [SignatureAdminController::class, 'uploadSignature'])->name('users.signature.upload');
        Route::delete('dashboard/users/{id}/signature', [SignatureAdminController::class, 'deleteSignature'])->name('users.signature.delete');
        Route::get('dashboard/registrations', [RegistrationAdminController::class, 'registrations'])->name('registrations');
        Route::post('dashboard/registrations/{id}/approve', [RegistrationAdminController::class, 'approveRegistration'])->name('registrations.approve');
        Route::post('dashboard/registrations/{id}/reject', [RegistrationAdminController::class, 'rejectRegistration'])->name('registrations.reject');
        Route::get('dashboard/departments', [DepartmentAdminController::class, 'departments'])->name('departments');
        Route::post('dashboard/departments', [DepartmentAdminController::class, 'storeDepartment'])->name('departments.store');
        Route::put('dashboard/departments/{id}', [DepartmentAdminController::class, 'updateDepartment'])->name('departments.update');
        Route::delete('dashboard/departments/{id}', [DepartmentAdminController::class, 'deleteDepartment'])->name('departments.delete');
        Route::get('dashboard/projects', [ProjectAdminController::class, 'projects'])->name('projects');
        Route::post('dashboard/projects', [ProjectAdminController::class, 'storeProject'])->name('projects.store');
        Route::put('dashboard/projects/{id}', [ProjectAdminController::class, 'updateProject'])->name('projects.update');
        Route::delete('dashboard/projects/{id}', [ProjectAdminController::class, 'deleteProject'])->name('projects.delete');
        Route::post('dashboard/requests/{id}/restore', [AdminController::class, 'restoreRequestWeb'])->name('requests.restore');
        Route::get('dashboard/logs', [AdminController::class, 'logs'])->name('logs');
        Route::get('dashboard/system-logs', \App\Http\Controllers\Admin\SystemLogsController::class)->name('system-logs');
    });

    Route::get('supply/approvers', [SupplyController::class, 'approvers'])->middleware('role:supplier,superadmin,admin,approver')->name('supply.approvers');

    // ─── Директор снабжения + Суперадмин ─────────────────────────────────
    Route::middleware('role:supplier,superadmin')->group(function () {
        Route::get('supply', [SupplyController::class, 'dashboard'])->name('supply.dashboard');
        Route::get('supply/requests', [SupplyController::class, 'requests'])->name('supply.requests');
        Route::get('supply/requests/{id}', [SupplyController::class, 'requestDetail'])->name('supply.requests.detail');
        Route::put('supply/requests/{requestId}/items/{itemId}', [SupplyController::class, 'updateItemQuantity'])->name('supply.items.update');
        Route::post('supply/requests/{id}/convert', [SupplyController::class, 'convert'])->name('supply.requests.convert');
        Route::post('supply/requests/{id}/cancel', [SupplyController::class, 'cancel'])->name('supply.requests.cancel');
        Route::get('supply/requests/{id}/export-items', [SupplyController::class, 'exportRequestItems'])->name('supply.requests.export-items');
        Route::get('supply/suppliers/search', [SupplyController::class, 'searchSuppliers'])->name('supply.suppliers.search');
        Route::post('supply/suppliers', [SupplyController::class, 'storeSupplier'])->name('supply.suppliers.store');
    });
});

require __DIR__.'/settings.php';
