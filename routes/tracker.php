<?php

use App\Http\Controllers\Tracker\AttachmentController;
use App\Http\Controllers\Tracker\ChecklistController;
use App\Http\Controllers\Tracker\InvitationController;
use App\Http\Controllers\Tracker\LabelController;
use App\Http\Controllers\Tracker\NotificationController;
use App\Http\Controllers\Tracker\ProjectController;
use App\Http\Controllers\Tracker\StatusController;
use App\Http\Controllers\Tracker\TaskController;
use App\Http\Controllers\Tracker\TrackerController;
use App\Http\Controllers\Tracker\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
 | Retired Task Tracker module. Routes remain registered for reference and
 | regression tests; tracker.access rejects requests unless TRACKER_ENABLED=true.
 | Same web guard + session as the dashboard.
 |
 | Vocabulary (TZ v2 §3): Board→Project, List→Status, Card→Task.
 */
Route::middleware(['auth', 'verified', 'tracker.access'])
    ->prefix('tracker')
    ->name('tracker.')
    ->group(function () {
        Route::get('/', [TrackerController::class, 'home'])->name('home');
        Route::get('my-tasks', [TrackerController::class, 'myTasksPage'])->name('my-tasks');

        Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

        // Workspace member management (§4.3, §21).
        Route::middleware('workspace.member')->group(function () {
            Route::post('workspaces/{workspace}/invitations', [InvitationController::class, 'store'])->name('workspaces.invitations.store');
            Route::delete('workspaces/{workspace}/members/{user}', [InvitationController::class, 'destroyMember'])->name('workspaces.members.destroy');
        });

        // Project-scoped (membership via project.member middleware).
        Route::middleware('project.member')->group(function () {
            Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::post('projects/{project}/statuses', [StatusController::class, 'store'])->name('statuses.store');
            Route::patch('projects/{project}/statuses/reorder', [StatusController::class, 'reorder'])->name('statuses.reorder');
            Route::post('projects/{project}/tasks', [TaskController::class, 'store'])->name('tasks.store');
            Route::post('projects/{project}/labels', [LabelController::class, 'store'])->name('labels.store');
        });

        // Task-scoped (project resolved from the task; authorized in controller).
        Route::get('go/task/{task}', [TaskController::class, 'go'])->name('tasks.go');
        Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
        Route::patch('tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
        Route::patch('tasks/{task}/complete', [TaskController::class, 'toggleComplete'])->name('tasks.complete');

        Route::post('tasks/{task}/assignees', [TaskController::class, 'addAssignee'])->name('tasks.assignees.store');
        Route::delete('tasks/{task}/assignees/{user}', [TaskController::class, 'removeAssignee'])->name('tasks.assignees.destroy');
        Route::post('tasks/{task}/watch', [TaskController::class, 'toggleWatch'])->name('tasks.watch');

        Route::post('tasks/{task}/comments', [TaskController::class, 'addComment'])->name('tasks.comments.store');
        Route::delete('tasks/{task}/comments/{comment}', [TaskController::class, 'deleteComment'])->name('tasks.comments.destroy');

        Route::post('tasks/{task}/labels', [LabelController::class, 'attach'])->name('tasks.labels.attach');
        Route::delete('tasks/{task}/labels/{label}', [LabelController::class, 'detach'])->name('tasks.labels.detach');
        Route::delete('labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');

        Route::post('tasks/{task}/attachments', [AttachmentController::class, 'store'])->name('tasks.attachments.store');
        Route::get('tasks/{task}/attachments/{attachment}', [AttachmentController::class, 'download'])->name('tasks.attachments.download');
        Route::delete('tasks/{task}/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('tasks.attachments.destroy');

        Route::post('tasks/{task}/checklists', [ChecklistController::class, 'store'])->name('tasks.checklists.store');
        Route::delete('checklists/{checklist}', [ChecklistController::class, 'destroy'])->name('checklists.destroy');
        Route::post('checklists/{checklist}/items', [ChecklistController::class, 'storeItem'])->name('checklists.items.store');
        Route::put('checklist-items/{item}', [ChecklistController::class, 'updateItem'])->name('checklist-items.update');
        Route::delete('checklist-items/{item}', [ChecklistController::class, 'destroyItem'])->name('checklist-items.destroy');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    });
