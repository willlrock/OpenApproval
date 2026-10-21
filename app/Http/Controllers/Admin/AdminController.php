<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequestsRequest;
use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\RequestApproval;
use App\Models\User;
use App\Services\ApprovalRequestAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use SergiX44\Nutgram\Nutgram;

class AdminController extends Controller
{
    public function requests(SearchRequestsRequest $request)
    {
        $user = auth()->user();
        $role = $user->role;

        $query = ApprovalRequest::with(['project', 'applicant', 'approvals.approver']);

        // Корзина удалённых заявок — только финансовым администраторам.
        $isAdmin = in_array($role, ['admin', 'superadmin']);
        $showDeleted = $isAdmin && $request->boolean('deleted');
        if ($showDeleted) {
            $query->onlyTrashed();
        }

        // Role-based scope
        if (! $isAdmin) {
            app(ApprovalRequestAccess::class)->scope($query, $user);
        }

        match ($request->get('attention')) {
            'overdue-payment' => $query->overduePayment(),
            'overdue-supply' => $query->overdueSupply(),
            'stuck-approval' => $query->stuckInApproval(),
            default => null,
        };

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $query->search($request->string('search')->toString(), ['name', 'description', 'supplier_name']);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $sortField = $request->get('sort', 'created_at');
        $sortDir = $request->get('direction', 'desc');
        $allowedSorts = ['id', 'created_at', 'total_price', 'status', 'name'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDir);
        }

        $requests = $query->paginate(15)->withQueryString()->through(fn ($r) => $this->formatRequest($r) + [
            'can_delete' => ! $showDeleted && $this->canDeleteRequest($r, $user),
            'deleted_at' => $r->deleted_at?->format('d.m.Y H:i'),
        ]);

        $projects = $user->canViewAllRequests()
            ? Project::orderBy('name')->get(['id', 'name'])
            : $user->projects()->orderBy('name')->get(['projects.id', 'projects.name']);

        return Inertia::render('Dashboard/Requests', [
            'requests' => $requests,
            'projects' => $projects,
            'filters' => $request->only(['status', 'project_id', 'type', 'search', 'date_from', 'date_to', 'sort', 'direction', 'deleted', 'attention']),
            'showingDeleted' => $showDeleted,
            'canSeeDeleted' => $isAdmin,
            'deletedCount' => $isAdmin ? ApprovalRequest::onlyTrashed()->count() : 0,
        ]);
    }

    public function requestDetail(Request $http, int $id)
    {
        $user = auth()->user();
        $role = $user->role;

        $query = ApprovalRequest::with([
            'project',
            'applicant',
            'supplyApplicant',
            'approvals' => fn ($q) => $q->orderBy('level'),
            'approvals.approver',
            'attachments',
            'items',
            'supplier',
            'tranches',
        ]);

        // Role-based scope — non-admins can only view requests in their scope
        if (! in_array($role, ['admin', 'superadmin'])) {
            app(ApprovalRequestAccess::class)->scope($query, $user);
        }

        $request = $query->findOrFail($id);

        $permissions = app(\App\Services\RequestActionPermissions::class)->forRequest($request, $user);
        $deadlineHistory = \Illuminate\Support\Facades\DB::table('tranche_deadline_changes')
            ->whereIn('request_tranche_id', $request->tranches->pluck('id'))
            ->leftJoin('users', 'users.id', '=', 'tranche_deadline_changes.user_id')
            ->orderByDesc('tranche_deadline_changes.id')
            ->get(['request_tranche_id', 'previous_date', 'promised_date', 'reason', 'tranche_deadline_changes.created_at', 'user_id', 'users.full_name as user_name'])
            ->groupBy('request_tranche_id');
        $section = $http->query('section', 'overview');
        $available = ['overview', 'approval', 'documents'];
        if (! $request->isManagementDocument()) {
            $available[] = 'payments';
        }
        if ($request->type === 'supply' || $request->supply_applicant_id) {
            $available[] = 'supply';
        }
        $back = $http->query('back', '/dashboard/requests');
        $backPath = is_string($back) ? parse_url($back, PHP_URL_PATH) : null;
        if (! is_string($back) || ! str_starts_with($back, '/') || str_starts_with($back, '//')
            || ! in_array($backPath, ['/dashboard/requests', '/dashboard/my-requests', '/dashboard/my-approvals', '/supply/requests', '/supply', '/finance/requests'], true)) {
            $back = '/dashboard/requests';
        }

        // Determine if this web user can approve/reject at the current level
        $canApprove = false;
        $currentApprovalId = null;
        if ($request->status === 'pending_approval' && $request->expected_approval_level) {
            $myApproval = $request->approvals
                ->where('level', $request->expected_approval_level)
                ->where('approver_id', $user->id)
                ->where('status', 'pending')
                ->first();
            if ($myApproval && app(\App\Services\ApprovalParticipantPolicy::class)->canDecide($request, $user)) {
                $canApprove = true;
                $currentApprovalId = $myApproval->id;
            }
        }

        return Inertia::render('Dashboard/RequestDetail', [
            'section' => in_array($section, $available, true) ? $section : 'overview',
            'back_url' => $back,
            'request' => [
                ...$permissions,
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
                'description' => $request->description,
                'currency' => $request->currency,
                'price_per_unit' => $request->price_per_unit,
                'quantity' => $request->quantity,
                'total_price' => $request->total_price,
                'transaction_type' => $request->transaction_type,
                'supplier_name' => $request->supplier_name,
                'supplier_inn' => $request->supplier_inn,
                'supplier_id' => $request->supplier_id,
                'supplier' => $request->supplier ? [
                    'id' => $request->supplier->id,
                    'name' => $request->supplier->name,
                    'inn' => $request->supplier->inn,
                ] : null,
                'status' => $request->status,
                'expected_approval_level' => $request->expected_approval_level,
                'pdf_url' => $request->pdf_url
                    ? ($request->isManagementDocument()
                        ? route('requests.document.final', $request->id)
                        : route('requests.protocol', $request->id))
                    : null,
                'document_preview_url' => $request->isManagementDocument() && $request->preview_pdf_path
                    ? route('requests.document.preview', $request->id)
                    : null,
                'deadline_date' => $request->deadline_date?->format('d.m.Y'),
                'deadline_date_iso' => $request->deadline_date?->toDateString(),
                'factual_delivery_date' => $request->factual_delivery_date?->toDateString(),
                'deadline_from_supplier' => $request->deadline_from_supplier?->format('d.m.Y'),
                'created_at' => $request->created_at->format('d.m.Y H:i'),
                'updated_at' => $request->updated_at->format('d.m.Y H:i'),
                'can_approve' => $canApprove,
                'can_request_changes' => $canApprove && ! $request->isManagementDocument() && ! $request->supply_applicant_id,
                'revision' => $request->revision,
                'can_delete' => $this->canDeleteRequest($request, $user),
                'can_copy' => $request->applicant_id === $user->id && in_array($request->type, ['approval', 'supply']),
                'can_revise' => $request->applicant_id === $user->id && in_array($request->status, ['rejected', 'revision_requested'])
                    && in_array($request->type, ['approval', 'supply']) && ! $request->supply_applicant_id && ! $request->tranches->contains('status', 'paid'),
                'source_id' => \App\Models\RequestDraft::where('submitted_request_id', $request->id)->where('is_revision', false)->value('source_request_id'),
                'current_approval_id' => $currentApprovalId,
                'project' => $request->project ? [
                    'id' => $request->project->id,
                    'name' => $request->project->name,
                ] : null,
                'applicant' => $request->applicant ? [
                    'id' => $request->applicant->id,
                    'full_name' => $request->applicant->full_name,
                    'username' => $request->applicant->username,
                    'phone' => $request->applicant->phone,
                    'title' => $request->applicant->title,
                ] : null,
                'supply_applicant' => $request->supplyApplicant ? [
                    'id' => $request->supplyApplicant->id, 'full_name' => $request->supplyApplicant->full_name,
                    'username' => $request->supplyApplicant->username, 'title' => $request->supplyApplicant->title,
                    'phone' => $request->supplyApplicant->phone,
                ] : null,
                'approvals' => $request->approvals->map(fn ($a) => [
                    'id' => $a->id,
                    'level' => $a->level,
                    'status' => $a->status,
                    'comment' => $a->comment,
                    'action_date' => $a->action_date ? Carbon::parse($a->action_date)->format('d.m.Y H:i') : null,
                    'approver' => $a->approver ? [
                        'id' => $a->approver->id,
                        'full_name' => $a->approver->full_name,
                        'username' => $a->approver->username,
                        'title' => $a->approver->title,
                    ] : null,
                ]),
                'attachments' => $request->attachments->map(fn ($att) => [
                    'id' => $att->id,
                    'name' => $att->display_name,
                    'file_size' => $att->file_size,
                    'file_type' => $att->file_type,
                    'telegram_file_id' => $att->telegram_file_id,
                    'url' => route('requests.attachment', [$request->id, $att->id]),
                ]),
                'items' => $request->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'price_per_unit' => $item->price_per_unit,
                    'total_price' => $item->total_price,
                ]),
                'tranches' => $request->tranches->sortBy('id')->values()->map(fn ($tranche) => [
                    'id' => $tranche->id,
                    'amount' => $tranche->amount,
                    'status' => $tranche->status,
                    'due_date' => $tranche->due_date?->format('d.m.Y'),
                    'promised_date' => $tranche->promised_date?->toDateString(),
                    'percent' => $tranche->percent,
                    'payment_proof' => $tranche->payment_proof,
                    'can_manage' => $permissions['can_manage_payments'] && $tranche->status !== 'paid',
                    'deadline_history' => $deadlineHistory->get($tranche->id, collect()),
                    'paid_at' => $tranche->paid_at?->format('d.m.Y H:i'),
                    'comment' => $tranche->comment,
                    'failure_reason' => $tranche->failure_reason,
                    'payment_proof_url' => $tranche->payment_proof && (str_starts_with($tranche->payment_proof, 'payment_proofs/') || $tranche->payment_proof_telegram_id)
                        ? route('requests.proof', [$request->id, $tranche->id]) : null,
                ]),
            ],
        ]);
    }

    public function downloadManagementDocumentPreview($id)
    {
        $user = auth()->user();
        $query = ApprovalRequest::query();
        if (! in_array($user->role, ['admin', 'superadmin'])) {
            app(ApprovalRequestAccess::class)->scope($query, $user);
        }

        $document = $query->findOrFail($id);
        abort_unless($document->isManagementDocument() && $document->preview_pdf_path, 404);
        abort_unless(Storage::disk('local')->exists($document->preview_pdf_path), 404);

        return Storage::disk('local')->download(
            $document->preview_pdf_path,
            "document_{$document->id}_preview.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }

    public function downloadManagementDocumentFinal($id)
    {
        $user = auth()->user();
        $query = ApprovalRequest::query();
        if (! in_array($user->role, ['admin', 'superadmin'])) {
            app(ApprovalRequestAccess::class)->scope($query, $user);
        }

        $document = $query->findOrFail($id);
        abort_unless($document->isManagementDocument() && $document->status === 'approved' && $document->pdf_url, 404);
        abort_unless(Storage::disk('local')->exists($document->pdf_url), 404);

        return Storage::disk('local')->download(
            $document->pdf_url,
            "document_{$document->id}_approved.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }

    public function approveRequestWeb(Request $request, $id)
    {
        $request->validate(['comment' => 'nullable|string|max:2000', 'revision' => 'sometimes|integer|min:1']);
        app(\App\Services\ApprovalWorkflow::class)->approve((int) $id, $request->user(), $request->input('comment'), (int) $request->input('revision', 1));

        return back()->with('success', 'Заявка согласована. Уведомления поставлены в очередь.');
    }

    public function rejectRequestWeb(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:2000', 'revision' => 'sometimes|integer|min:1']);
        try {
            app(\App\Services\ApprovalWorkflow::class)->reject((int) $id, $request->user(), (string) $request->input('reason', ''), (int) $request->input('revision', 1));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if ($e->getStatusCode() !== 409) {
                throw $e;
            }

            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return back()->with('success', 'Заявка отклонена');
    }

    /**
     * Добавить согласующего на последний уровень (текущий согласующий расширяет цепочку).
     */
    public function addApproverWeb(Request $request, $id)
    {
        $request->validate([
            'approver_id' => 'required|integer|exists:users,id',
            'revision' => 'sometimes|integer|min:1',
        ]);

        $user = auth()->user();

        [, , $newLevel] = app(\App\Services\ApprovalWorkflow::class)
            ->addApprover((int) $id, $user, $request->integer('approver_id'), $request->integer('revision', 1));

        return back()->with('success', "Согласующий добавлен уровнем {$newLevel}");
    }

    public function logs(Request $request)
    {
        $query = RequestApproval::with(['approver', 'approvalRequest.project', 'approvalRequest.applicant'])
            ->whereNotNull('action_date');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('action_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('action_date', '<=', $request->date_to);
        }

        $logs = $query->orderByDesc('action_date')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($log) => [
                'id' => $log->id,
                'level' => $log->level,
                'status' => $log->status,
                'comment' => $log->comment,
                'action_date' => $log->action_date ? Carbon::parse($log->action_date)->format('d.m.Y H:i') : null,
                'approver' => $log->approver ? [
                    'id' => $log->approver->id,
                    'full_name' => $log->approver->full_name,
                    'title' => $log->approver->title,
                ] : null,
                'request' => $log->approvalRequest ? [
                    'id' => $log->approvalRequest->id,
                    'name' => $log->approvalRequest->name,
                    'project' => $log->approvalRequest->project?->name,
                    'applicant' => $log->approvalRequest->applicant?->full_name,
                ] : null,
            ]);

        return Inertia::render('Dashboard/Logs', [
            'logs' => $logs,
            'filters' => $request->only(['status', 'date_from', 'date_to']),
        ]);
    }

    /**
     * Download attachment file via Telegram API or from local storage.
     */
    public function downloadAttachment($requestId, $attachmentId)
    {
        $request = app(ApprovalRequestAccess::class)->scope(ApprovalRequest::query(), auth()->user())->findOrFail($requestId);
        $file = $request->attachments()->findOrFail($attachmentId);

        return app(\App\Services\TelegramFileCache::class)->download($file->file_path, $file->telegram_file_id, $file->display_name);
    }

    /**
     * Export request items as Excel file.
     */
    public function exportRequestItems($id)
    {
        $user = auth()->user();
        $query = ApprovalRequest::with('items');
        if (! in_array($user->role, ['admin', 'superadmin'])) {
            app(ApprovalRequestAccess::class)->scope($query, $user);
        }
        $request = $query->findOrFail($id);

        if ($request->items->isEmpty()) {
            return back()->withErrors(['error' => 'У заявки нет позиций для экспорта.']);
        }

        $export = new \App\Exports\RequestItemsExport($request);
        $filename = "request_{$id}_items.xlsx";

        return \Maatwebsite\Excel\Facades\Excel::download($export, $filename);
    }

    /**
     * Scope approval requests based on user role.
     *
     * - approver: only requests where the user is an assigned approver (within their projects)
     * - accountant: only approved requests with bank transfer (within their projects)
     * - treasurer: all approved requests — both cash and bank ("бухгалтерия") (within their projects)
     */
    public function myApprovals(SearchRequestsRequest $request)
    {
        $user = auth()->user();

        // Active: requests waiting for this user's approval at current level
        $activeQuery = ApprovalRequest::with(['project', 'applicant', 'approvals.approver'])
            ->where('status', 'pending_approval')
            ->whereHas('approvals', function ($q) use ($user) {
                $q->where('approver_id', $user->id)
                    ->where('status', 'pending');
            })
            ->whereColumn('expected_approval_level', '=',
                ApprovalRequest::raw('(SELECT level FROM request_approvals WHERE request_approvals.approval_request_id = approval_requests.id AND request_approvals.approver_id = '.(int) $user->id.' AND request_approvals.status = \'pending\' LIMIT 1)')
            );

        // History: requests this user has approved or rejected
        $historyQuery = RequestApproval::with(['approvalRequest.project', 'approvalRequest.applicant'])
            ->where('approver_id', $user->id)
            ->whereIn('status', ['approved', 'rejected']);

        // Filters
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $activeQuery->search($search);
            $historyQuery->whereHas('approvalRequest', fn ($q) => $q->search($search));
        }

        $tab = $request->get('tab', 'active');

        $active = $activeQuery->orderByDesc('created_at')
            ->paginate(15, ['*'], 'active_page')
            ->withQueryString()
            ->through(fn ($r) => $this->formatRequest($r));

        $history = $historyQuery->orderByDesc('action_date')
            ->paginate(15, ['*'], 'history_page')
            ->withQueryString()
            ->through(function ($approval) {
                $r = $approval->approvalRequest;

                return [
                    'id' => $r?->id,
                    'type' => $r?->type,
                    'name' => $r?->name,
                    'currency' => $r?->currency,
                    'total_price' => $r?->total_price,
                    'status' => $r?->status,
                    'project' => $r?->project?->name,
                    'applicant' => $r?->applicant?->full_name,
                    'approval_status' => $approval->status,
                    'approval_date' => $approval->action_date?->format('d.m.Y H:i'),
                    'approval_comment' => $approval->comment,
                    'level' => $approval->level,
                ];
            });

        return Inertia::render('Dashboard/MyApprovals', [
            'active' => $active,
            'history' => $history,
            'filters' => $request->only(['search', 'tab']),
            'activeCount' => $activeQuery->count(),
        ]);
    }

    /**
     * Web-аналог кнопки «Мои заявки» в боте — заявки, поданные текущим пользователем.
     */
    public function myRequests(SearchRequestsRequest $request)
    {
        $user = auth()->user();

        $query = ApprovalRequest::with(['project', 'applicant', 'approvals.approver'])
            ->where('applicant_id', $user->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $query->search($request->string('search')->toString(), ['name', 'supplier_name']);
        }

        $requests = $query->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn ($r) => $this->formatRequest($r) + [
                'can_delete' => $this->canDeleteRequest($r, $user),
            ]);

        return Inertia::render('Dashboard/MyRequests', [
            'requests' => $requests,
            'filters' => $request->only(['status', 'type', 'search']),
        ]);
    }

    /**
     * Web-аналог кнопки «Удалить заявку» в боте. Удалять может заявитель свою
     * неутверждённую заявку, а также admin/superadmin — любую неутверждённую.
     */
    public function deleteRequestWeb($id)
    {
        $user = auth()->user();

        $approvalRequest = ApprovalRequest::with(['approvals.approver'])->findOrFail($id);

        if (! $this->canDeleteRequest($approvalRequest, $user)) {
            return back()->withErrors(['error' => $approvalRequest->status === 'approved'
                ? 'Утверждённую заявку удалить нельзя'
                : 'Удалять можно только свои заявки']);
        }

        $currentApproval = $approvalRequest->approvals
            ->where('level', $approvalRequest->expected_approval_level)
            ->where('status', 'pending')
            ->first();

        $requestId = $approvalRequest->id;
        $wasPending = $approvalRequest->status === 'pending_approval';
        $isOwn = $approvalRequest->applicant_id === $user->id;

        // Мягкое удаление: позиции, согласования, вложения и транши остаются
        // в БД, поэтому заявку можно восстановить целиком.
        // Файлы управленческих документов также сохраняются для восстановления.
        app(\App\Services\RequestDeletion::class)->delete($approvalRequest->id, $user);

        if ($wasPending && $currentApproval?->approver?->telegram_id) {
            try {
                $bot = app(Nutgram::class);
                $aprLang = $currentApproval->approver->lang ?? 'ru';
                app(\App\Services\TelegramNotificationMessage::class)->sendTranslated(
                    $bot, 'bot.del_approver_notice', ['id' => $requestId, 'name' => $user->full_name ?? ''], $aprLang,
                    $currentApproval->approver->telegram_id
                );
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to notify approver about deleted request: '.$e->getMessage());
            }
        }

        return redirect()
            ->route($isOwn ? 'my-requests' : 'requests')
            ->with('success', "Заявка #{$requestId} удалена");
    }

    /**
     * Восстановление удалённой заявки. Доступно только admin/superadmin —
     * заявитель свою удалённую заявку уже не видит.
     */
    public function restoreRequestWeb($id)
    {
        $approvalRequest = ApprovalRequest::onlyTrashed()->findOrFail($id);
        $approvalRequest->restore();

        return redirect()
            ->route('requests', ['deleted' => 1])
            ->with('success', "Заявка #{$approvalRequest->id} восстановлена");
    }

    /**
     * Заявку можно удалить, пока она не утверждена: заявителю — свою,
     * admin/superadmin — любую.
     */
    protected function canDeleteRequest(ApprovalRequest $request, User $user): bool
    {
        if (! in_array($request->status, ['pending_supply', 'pending_approval', 'rejected', 'cancelled'], true)) {
            return false;
        }

        return $request->applicant_id === $user->id
            || in_array($user->role, ['admin', 'superadmin']);
    }

    protected function formatRequest($r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'name' => $r->name,
            'description' => $r->description,
            'currency' => $r->currency,
            'total_price' => $r->total_price,
            'status' => $r->status,
            'supplier_name' => $r->supplier_name,
            'transaction_type' => $r->transaction_type,
            'created_at' => $r->created_at->format('d.m.Y H:i'),
            'project' => $r->project?->name,
            'applicant' => $r->applicant?->full_name,
            'approvals_count' => $r->approvals?->count() ?? 0,
            'approved_count' => $r->approvals?->where('status', 'approved')->count() ?? 0,
        ];
    }
}
