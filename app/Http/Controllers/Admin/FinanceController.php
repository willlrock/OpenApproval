<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequestsRequest;
use App\Models\ApprovalRequest;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function requests(SearchRequestsRequest $request)
    {
        $user = auth()->user();
        $role = $user->role;

        $query = app(\App\Services\ApprovalRequestAccess::class)->financialQuery(
            ApprovalRequest::with(['project', 'applicant', 'tranches', 'supplier']), $user
        );

        // Казначей и администраторы могут дополнительно отфильтровать тип операции.
        if (in_array($role, ['treasurer', 'admin', 'superadmin'], true)
            && in_array($request->transaction_type, ['cash', 'bank'], true)) {
            $query->where('transaction_type', $request->transaction_type);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('tranche_status')) {
            $query->whereHas('tranches', function ($q) use ($request) {
                $q->where('status', $request->tranche_status);
            });
        }
        if ($request->filled('search')) {
            $query->search($request->string('search')->toString(), ['name', 'supplier_name']);
        }

        $requests = $query->orderBy('deadline_date')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'currency' => $r->currency,
                'total_price' => $r->total_price,
                'transaction_type' => $r->transaction_type,
                'supplier_name' => $r->supplier_name,
                'deadline_date' => $r->deadline_date?->format('d.m.Y'),
                'created_at' => $r->created_at->format('d.m.Y'),
                'project' => $r->project?->name,
                'applicant' => $r->applicant?->full_name,
                'tranches_total' => $r->tranches->count(),
                'tranches_paid' => $r->tranches->where('status', 'paid')->count(),
                'tranches_pending' => $r->tranches->where('status', 'pending')->count(),
                'status' => $r->status,
            ]);

        $projects = in_array($role, ['admin', 'superadmin'])
            ? Project::orderBy('name')->get(['id', 'name'])
            : $user->projects()->orderBy('name')->get(['projects.id', 'projects.name']);

        return Inertia::render('Finance/Requests', [
            'requests' => $requests,
            'projects' => $projects,
            'filters' => $request->only(['project_id', 'tranche_status', 'search', 'transaction_type']),
            'role' => $role,
        ]);
    }

    public function requestDetail(Request $http, int $id)
    {
        $request = app(\App\Services\ApprovalRequestAccess::class)->financialQuery(ApprovalRequest::query(), $http->user())->findOrFail($id);

        return redirect()->route('requests.detail', [
            'id' => $request->id, 'section' => 'payments',
            'back' => $http->query('back', '/finance/requests'),
        ]);
    }

    public function updateTranche(Request $request, $requestId, $trancheId)
    {
        $request->validate([
            'status' => 'required|in:pending,paid,failed',
            'payment_proof' => 'nullable|string|max:500',
            'payment_proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
            'due_date' => 'nullable|date_format:d.m.Y',
            'promised_date' => 'nullable|date_format:Y-m-d',
            'reschedule_reason' => 'nullable|string|max:1000',
            'comment' => 'nullable|string|max:500',
            'failure_reason' => 'nullable|string|max:1000',
            'amount' => 'nullable|numeric|min:0.01',
        ]);

        $data = $request->only(['status', 'amount', 'comment', 'failure_reason', 'payment_proof', 'promised_date', 'reschedule_reason']);
        if ($request->filled('due_date')) {
            $data['due_date'] = Carbon::createFromFormat('d.m.Y', $request->due_date);
        }
        [$approvalRequest, $tranche, $oldStatus, $oldRequestStatus] = app(\App\Services\TrancheWorkflow::class)
            ->update($request->user(), (int) $requestId, (int) $trancheId, $data, $request->file('payment_proof_file'));

        return back()->with('success', 'Транш обновлён');
    }
}
