<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tracker\Concerns\AuthorizesProject;
use App\Models\Tracker\Attachment;
use App\Models\Tracker\Task;
use App\Services\Tracker\TrackerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    use AuthorizesProject;

    /**
     * Allowed upload extensions (§28, D9): images / PDF / office / text / zip.
     */
    private const ALLOWED = 'jpg,jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,zip';

    public function __construct(private TrackerService $tracker)
    {
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $project = $task->project;
        $this->requireProjectEdit($request->user(), $project);

        $request->validate([
            'file' => ['required', 'file', 'max:25600', 'mimes:'.self::ALLOWED],
        ]);

        $file = $request->file('file');
        // Randomized stored name; original name kept only in metadata (§28).
        $path = $file->store("tracker/attachments/{$project->id}/{$task->id}", 'local');

        $attachment = $task->attachments()->create([
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        $this->tracker->activity($project, $task, $request->user()->id, 'attachment_added', [
            'name' => $attachment->original_name,
        ]);

        return response()->json(['id' => $attachment->id]);
    }

    public function download(Request $request, Task $task, Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->task_id === $task->id, 404);
        $this->requireProjectView($request->user(), $task->project);

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    public function destroy(Request $request, Task $task, Attachment $attachment): JsonResponse
    {
        abort_unless($attachment->task_id === $task->id, 404);

        $project = $task->project;
        $isOwner = $attachment->uploaded_by === $request->user()->id;
        $isAdmin = $this->projectRole($request->user(), $project) === 'project_admin';
        abort_unless($isOwner || $isAdmin, 403);

        Storage::disk('local')->delete($attachment->path);
        $name = $attachment->original_name;
        $attachment->delete();

        $this->tracker->activity($project, $task, $request->user()->id, 'attachment_removed', ['name' => $name]);

        return response()->json(['ok' => true]);
    }
}
