<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ApprovalParticipantPolicy;
use App\Services\ApprovalRouteRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ProjectAdminController extends Controller
{
    public function projects()
    {
        $projects = Project::withCount(['approvalRequests as total_count'])
            ->withCount(['approvalRequests as approved_count' => fn ($q) => $q->where('status', 'approved')])
            ->with(['departments:id,project_id,name,is_active'])
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'company_name' => $p->company_name,
                'inn' => $p->inn,
                'bank_account' => $p->bank_account,
                'bank_mfo' => $p->bank_mfo,
                'aliases' => $p->aliases,
                'is_active' => $p->is_active,
                'total_count' => $p->total_count,
                'approved_count' => $p->approved_count,
                'created_at' => $p->created_at?->format('d.m.Y'),
                'departments' => $p->departments->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'is_active' => $d->is_active,
                ]),
            ]);

        return Inertia::render('Dashboard/Projects', [
            'projects' => $projects,
        ]);
    }

    public function storeProject(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:projects,name',
            'company_name' => 'nullable|string|max:255',
            'inn' => 'nullable|string|max:32',
            'bank_account' => 'nullable|string|max:64',
            'bank_mfo' => 'nullable|string|max:32',
            'aliases' => 'nullable|string|max:2000',
        ]);

        Project::create([
            'name' => $request->name,
            'company_name' => $request->filled('company_name') ? $request->company_name : null,
            'inn' => $request->filled('inn') ? preg_replace('/\D+/', '', $request->inn) : null,
            'bank_account' => $request->filled('bank_account') ? preg_replace('/\D+/', '', $request->bank_account) : null,
            'bank_mfo' => $request->filled('bank_mfo') ? preg_replace('/\D+/', '', $request->bank_mfo) : null,
            'aliases' => $request->filled('aliases') ? $request->aliases : null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Проект создан');
    }

    public function updateProject(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:projects,name,'.$id,
            'company_name' => 'nullable|string|max:255',
            'inn' => 'nullable|string|max:32',
            'bank_account' => 'nullable|string|max:64',
            'bank_mfo' => 'nullable|string|max:32',
            'aliases' => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($request, $id) {
            app(ApprovalParticipantPolicy::class)->lockWorkflow();
            $actor = $request->user()->fresh();
            abort_unless($actor?->isApproved() && $actor->hasRole(['admin', 'superadmin']), 403);
            $project = Project::lockForUpdate()->findOrFail($id);
            $project->update([
                'name' => $request->name,
                'company_name' => $request->filled('company_name') ? $request->company_name : null,
                'inn' => $request->filled('inn') ? preg_replace('/\D+/', '', $request->inn) : null,
                'bank_account' => $request->filled('bank_account') ? preg_replace('/\D+/', '', $request->bank_account) : null,
                'bank_mfo' => $request->filled('bank_mfo') ? preg_replace('/\D+/', '', $request->bank_mfo) : null,
                'aliases' => $request->filled('aliases') ? $request->aliases : null,
                'is_active' => $request->boolean('is_active', $project->is_active),
            ]);
            app(ApprovalRouteRecovery::class)->projectAccessChanged($project, $actor);
        });

        return back()->with('success', 'Проект обновлён');
    }

    public function deleteProject($id)
    {
        app(\App\Services\DirectoryDeletion::class)->project((int) $id);

        return back()->with('success', 'Проект удалён');
    }
}
