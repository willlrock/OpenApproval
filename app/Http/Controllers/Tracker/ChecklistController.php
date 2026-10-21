<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tracker\Concerns\AuthorizesProject;
use App\Models\Tracker\Checklist;
use App\Models\Tracker\ChecklistItem;
use App\Models\Tracker\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChecklistController extends Controller
{
    use AuthorizesProject;

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->requireProjectEdit($request->user(), $task->project);

        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $checklist = $task->checklists()->create([
            'title' => $data['title'],
            'position' => (int) $task->checklists()->max('position') + 1,
        ]);

        return response()->json(['id' => $checklist->id]);
    }

    public function destroy(Request $request, Checklist $checklist): JsonResponse
    {
        $this->requireProjectEdit($request->user(), $checklist->task->project);
        $checklist->delete();

        return response()->json(['ok' => true]);
    }

    public function storeItem(Request $request, Checklist $checklist): JsonResponse
    {
        $this->requireProjectEdit($request->user(), $checklist->task->project);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:1000'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
        ]);

        $item = $checklist->items()->create([
            'content' => $data['content'],
            'assignee_id' => $data['assignee_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'position' => (int) $checklist->items()->max('position') + 1,
        ]);

        return response()->json(['id' => $item->id]);
    }

    public function updateItem(Request $request, ChecklistItem $item): JsonResponse
    {
        $this->requireProjectEdit($request->user(), $item->checklist->task->project);

        $data = $request->validate([
            'content' => ['sometimes', 'string', 'max:1000'],
            'is_completed' => ['sometimes', 'boolean'],
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $item->fill($data)->save();

        return response()->json(['ok' => true]);
    }

    public function destroyItem(Request $request, ChecklistItem $item): JsonResponse
    {
        $this->requireProjectEdit($request->user(), $item->checklist->task->project);
        $item->delete();

        return response()->json(['ok' => true]);
    }
}
