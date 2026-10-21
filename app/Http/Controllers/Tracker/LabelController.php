<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tracker\Concerns\AuthorizesProject;
use App\Models\Tracker\Label;
use App\Models\Tracker\Project;
use App\Models\Tracker\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabelController extends Controller
{
    use AuthorizesProject;

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->requireProjectEdit($request->user(), $project);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32'],
        ]);

        $label = $project->labels()->create([
            'name' => $data['name'],
            'color' => $data['color'] ?? '#9ca3af',
        ]);

        return response()->json(['id' => $label->id, 'name' => $label->name, 'color' => $label->color]);
    }

    public function destroy(Request $request, Label $label): JsonResponse
    {
        $this->requireProjectEdit($request->user(), $label->project);
        $label->delete();

        return response()->json(['ok' => true]);
    }

    public function attach(Request $request, Task $task): JsonResponse
    {
        $this->requireProjectEdit($request->user(), $task->project);

        $data = $request->validate(['label_id' => ['required', 'integer']]);
        // Label must belong to the same project.
        $label = $task->project->labels()->findOrFail($data['label_id']);

        $task->labels()->syncWithoutDetaching([$label->id]);

        return response()->json(['ok' => true]);
    }

    public function detach(Request $request, Task $task, int $label): JsonResponse
    {
        $this->requireProjectEdit($request->user(), $task->project);
        $task->labels()->detach($label);

        return response()->json(['ok' => true]);
    }
}
