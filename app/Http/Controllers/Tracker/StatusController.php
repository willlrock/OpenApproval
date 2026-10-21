<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tracker\Concerns\AuthorizesProject;
use App\Models\Tracker\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    use AuthorizesProject;

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->requireProjectEdit($request->user(), $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status_category' => ['nullable', 'in:backlog,todo,in_progress,done'],
            'color' => ['nullable', 'string', 'max:32'],
        ]);

        $category = $data['status_category'] ?? 'todo';
        $project->statuses()->create([
            'name' => $data['name'],
            'status_category' => $category,
            'color' => $data['color'] ?? $this->categoryColor($category),
            'position' => (int) $project->statuses()->max('position') + 1,
        ]);

        return back();
    }

    /**
     * Persist a new left-to-right ordering of the project's statuses.
     */
    public function reorder(Request $request, Project $project): JsonResponse
    {
        $this->requireProjectAdmin($request->user(), $project);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        // Only touch statuses that actually belong to this project.
        $ownIds = $project->statuses()->pluck('id')->all();
        $position = 1;
        foreach ($data['order'] as $statusId) {
            if (in_array((int) $statusId, $ownIds, true)) {
                $project->statuses()->whereKey($statusId)->update(['position' => $position++]);
            }
        }

        return response()->json(['ok' => true]);
    }

    private function categoryColor(string $category): string
    {
        return match ($category) {
            'backlog' => '#94a3b8',
            'in_progress' => '#f59e0b',
            'done' => '#22c55e',
            default => '#3b82f6',
        };
    }
}
