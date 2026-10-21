<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\ApprovalRequestAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function dashboard(Request $http)
    {
        $user = auth()->user();
        $role = $user->role;
        $filters = $http->validate(['project_id' => 'nullable|integer', 'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => ['nullable', 'date_format:Y-m-d', ...($http->filled('date_from') ? ['after_or_equal:date_from'] : [])]]);
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $scopeFilter = function ($query) use ($user, $filters) {
            app(ApprovalRequestAccess::class)->scope($query, $user);

            return app(\App\Services\DashboardMetrics::class)->filter($query, $filters);
        };

        // Single query for status counts
        $statusCounts = ApprovalRequest::query()
            ->tap($scopeFilter)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN status = 'revision_requested' THEN 1 ELSE 0 END) as revision_requested,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'pending_supply' THEN 1 ELSE 0 END) as supply
            ")->first();

        $totalUsers = User::count();
        $approverCount = User::whereHas('roleRelation', function ($q) {
            $q->whereIn('name', ['admin', 'approver', 'supplier', 'superadmin', 'accountant', 'treasurer']);
        })->count();

        // Monthly stats — single query instead of 18 separate queries
        $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();
        $monthExpression = \Illuminate\Support\Facades\DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "TO_CHAR(created_at, 'YYYY-MM')";
        $monthlyRaw = ApprovalRequest::where('created_at', '>=', $sixMonthsAgo)
            ->tap($scopeFilter)
            ->selectRaw("{$monthExpression} as month_key, status, COUNT(*) as cnt")
            ->groupBy('month_key', 'status')
            ->get()
            ->groupBy('month_key');

        $monthlyStats = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $key = $date->format('Y-m');
            $group = $monthlyRaw->get($key, collect());
            $monthlyStats[] = [
                'month' => $date->translatedFormat('M Y'),
                'total' => $group->sum('cnt'),
                'approved' => $group->whereIn('status', ['approved', 'paid'])->sum('cnt'),
                'rejected' => $group->where('status', 'rejected')->sum('cnt'),
            ];
        }

        // Sums by currency
        $currencySums = ApprovalRequest::query()->financial()
            ->tap($scopeFilter)
            ->whereNotNull('currency')
            ->selectRaw('currency, SUM(total_price) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->toArray();

        // Per-project stats — pre-load approved sums to avoid N+1
        $projectApprovedSums = ApprovalRequest::query()->financial()
            ->tap($scopeFilter)
            ->whereNotNull('currency')
            ->selectRaw('project_id, currency, SUM(total_price) as total')
            ->groupBy('project_id', 'currency')
            ->get()
            ->groupBy('project_id');

        $projectStatsQuery = Project::whereIn('id', $scopeFilter(ApprovalRequest::query())->select('project_id'))
            ->withCount(['approvalRequests as total_count' => $scopeFilter])
            ->withCount(['approvalRequests as approved_count' => fn ($q) => $scopeFilter($q)->whereIn('status', ['approved', 'paid'])])
            ->withCount(['approvalRequests as rejected_count' => fn ($q) => $scopeFilter($q)->where('status', 'rejected')])
            ->withCount(['approvalRequests as pending_count' => fn ($q) => $scopeFilter($q)->where('status', 'pending_approval')]);

        $projectStats = $projectStatsQuery->get()
            ->map(function ($project) use ($projectApprovedSums) {
                $sums = $projectApprovedSums->get($project->id, collect());
                $approvedSum = $sums->pluck('total', 'currency')->toArray();

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'total_count' => $project->total_count,
                    'approved_count' => $project->approved_count,
                    'rejected_count' => $project->rejected_count,
                    'pending_count' => $project->pending_count,
                    'approved_sums' => $approvedSum,
                ];
            });

        // Recent activity
        $recentRequests = ApprovalRequest::with(['project', 'applicant'])
            ->tap($scopeFilter)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'type' => $r->type, 'status' => $r->status, 'total_price' => $r->total_price, 'currency' => $r->currency, 'project' => $r->project?->name, 'applicant' => $r->applicant?->full_name, 'created_at' => $r->created_at->format('d.m.Y H:i')]);

        return Inertia::render('Dashboard/Dashboard', [
            ...app(\App\Services\DashboardMetrics::class)->forUser($user, $filters),
            'filters' => $filters,
            'projects' => Project::whereIn('id', app(ApprovalRequestAccess::class)->scope(ApprovalRequest::query(), $user)->select('project_id'))->orderBy('name')->get(['id', 'name']),
            'role' => $role,
            'myQueue' => $scopeFilter(ApprovalRequest::query())->where('status', 'pending_approval')->whereHas('approvals', fn ($q) => $q->where('approver_id', $user->id)->where('status', 'pending')->whereColumn('level', 'approval_requests.expected_approval_level'))->oldest()->limit(10)->get(['id', 'name', 'created_at']),
            'stats' => [
                'totalRequests' => (int) $statusCounts->total,
                'approvedRequests' => (int) $statusCounts->approved,
                'paidRequests' => (int) $statusCounts->paid,
                'revisionRequests' => (int) $statusCounts->revision_requested,
                'rejectedRequests' => (int) $statusCounts->rejected,
                'pendingRequests' => (int) $statusCounts->pending,
                'supplyRequests' => (int) $statusCounts->supply,
                'totalUsers' => $totalUsers,
                'approverCount' => $approverCount,
            ],
            'monthlyStats' => $monthlyStats,
            'currencySums' => $currencySums,
            'projectStats' => $projectStats,
            'recentRequests' => $recentRequests,
        ]);
    }
}
