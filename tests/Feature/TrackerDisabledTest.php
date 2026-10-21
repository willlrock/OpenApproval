<?php

use App\Jobs\Tracker\SendTrackerTelegramJob;
use App\Models\Tracker\Workspace;
use App\Models\User;
use App\Services\DeadlineNotifier;
use App\Services\Tracker\TrackerService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['tracker.enabled' => false]);
    $this->withoutVite();
    Storage::fake('local');
    Queue::fake();

    $this->admin = User::factory()->trackerAdmin()->create(['telegram_id' => 991122]);
    $this->workspace = Workspace::create([
        'name' => 'Retained workspace', 'slug' => 'retained', 'created_by' => $this->admin->id,
    ]);
    $this->workspace->members()->attach($this->admin, ['role' => 'workspace_admin']);
    $this->project = $this->workspace->projects()->create([
        'name' => 'Retained project', 'visibility' => 'private', 'created_by' => $this->admin->id,
    ]);
    $status = $this->project->statuses()->create([
        'name' => 'Todo', 'status_category' => 'todo', 'position' => 1,
    ]);
    $this->task = $this->project->tasks()->create([
        'title' => 'Retained task', 'status_id' => $status->id,
        'task_number' => 1, 'position' => 1, 'created_by' => $this->admin->id,
    ]);
    Storage::disk('local')->put('tracker/retained.txt', 'Retained attachment');
    $this->attachment = $this->task->attachments()->create([
        'original_name' => 'retained.txt', 'path' => 'tracker/retained.txt',
        'mime' => 'text/plain', 'size' => 19, 'uploaded_by' => $this->admin->id,
    ]);
});

test('disabled tracker rejects pages and API operations even for an administrator', function (string $method, string $path) {
    $path = strtr($path, [
        '{workspace}' => (string) $this->workspace->id,
        '{project}' => (string) $this->project->id,
        '{task}' => (string) $this->task->id,
        '{attachment}' => (string) $this->attachment->id,
        '{user}' => (string) $this->admin->id,
    ]);
    $this->actingAs($this->admin)->json($method, $path, [
        'name' => 'Unwanted workspace', 'workspace_id' => $this->workspace->id,
        'status_id' => $this->task->status_id, 'title' => 'Unwanted task', 'body' => 'Unwanted comment',
    ])->assertNotFound();

    $this->assertDatabaseCount('tracker_workspaces', 1);
    $this->assertDatabaseCount('tracker_projects', 1);
    $this->assertDatabaseCount('tracker_tasks', 1);
    $this->assertDatabaseCount('tracker_comments', 0);
    $this->assertDatabaseCount('tracker_notifications', 0);
    $this->assertDatabaseHas('tracker_tasks', ['id' => $this->task->id, 'title' => 'Retained task']);
    $this->assertDatabaseHas('tracker_workspace_user', ['workspace_id' => $this->workspace->id, 'user_id' => $this->admin->id]);
    $this->assertDatabaseHas('tracker_attachments', ['id' => $this->attachment->id]);
    Storage::disk('local')->assertExists('tracker/retained.txt');
    Queue::assertNothingPushed();
})->with([
    ['GET', '/tracker'],
    ['GET', '/tracker/my-tasks'],
    ['GET', '/tracker/notifications'],
    ['GET', '/tracker/projects/{project}'],
    ['GET', '/tracker/tasks/{task}'],
    ['GET', '/tracker/tasks/{task}/attachments/{attachment}'],
    ['POST', '/tracker/workspaces'],
    ['POST', '/tracker/projects'],
    ['POST', '/tracker/projects/{project}/tasks'],
    ['PUT', '/tracker/tasks/{task}'],
    ['PATCH', '/tracker/tasks/{task}/complete'],
    ['POST', '/tracker/tasks/{task}/comments'],
    ['DELETE', '/tracker/tasks/{task}/attachments/{attachment}'],
    ['DELETE', '/tracker/workspaces/{workspace}/members/{user}'],
]);

test('disabled tracker hides navigation context without querying its tables on the dashboard', function () {
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (str_contains($query->sql, 'tracker_')) {
            $queries[] = $query->sql;
        }
    });

    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.tracker.enabled', false)
            ->where('auth.user.tracker.has_access', false)
            ->where('auth.user.tracker.is_admin', false)
            ->where('auth.user.tracker.workspaces', [])
            ->where('auth.user.tracker.tree', null));

    expect($queries)->toBeEmpty();
});

test('disabled tracker creates neither notifications nor queued deliveries', function () {
    app(TrackerService::class)->notify([$this->admin->id], 'assigned', [
        'task_id' => $this->task->id, 'task_title' => $this->task->title,
    ]);

    $this->assertDatabaseCount('tracker_notifications', 0);
    Queue::assertNothingPushed();
});

test('tracker delivery queued before retirement sends nothing when executed after disabling', function () {
    config(['tracker.enabled' => true]);
    $queued = serialize(new SendTrackerTelegramJob([$this->admin->id], 'assigned', [
        'task_id' => $this->task->id, 'task_title' => $this->task->title,
    ]));
    config(['tracker.enabled' => false]);
    $notifier = Mockery::mock(DeadlineNotifier::class);
    $notifier->shouldNotReceive('send');

    unserialize($queued)->handle($notifier);
});
