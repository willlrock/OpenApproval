<?php

use App\Jobs\Tracker\SendTrackerTelegramJob;
use App\Models\Tracker\Project;
use App\Models\Tracker\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * §20 / D7: tracker notifications mirror to Telegram on the QUEUE (never block
 * the request). Verifies the job is dispatched with the right recipients/type.
 */

function tgProject(): array
{
    $admin = User::factory()->trackerAdmin()->create();
    $ws = Workspace::create(['name' => 'WS', 'slug' => 'ws-'.uniqid(), 'created_by' => $admin->id]);
    $space = $ws->spaces()->create(['name' => 'General', 'color' => '#6366f1', 'position' => 0]);
    $project = Project::create(['workspace_id' => $ws->id, 'space_id' => $space->id, 'name' => 'P', 'visibility' => 'workspace', 'position' => 1, 'created_by' => $admin->id]);
    $status = $project->statuses()->create(['name' => 'Todo', 'status_category' => 'todo', 'position' => 1]);
    $task = $project->tasks()->create(['status_id' => $status->id, 'task_number' => 1, 'title' => 'Task', 'position' => 1, 'created_by' => $admin->id]);

    $member = User::factory()->create();
    $ws->members()->attach($member->id, ['role' => 'workspace_member']);
    $project->members()->attach($member->id, ['role' => 'project_member']);

    return compact('admin', 'ws', 'space', 'project', 'task', 'member');
}

test('assigning a user queues a Telegram notification to that user', function () {
    Queue::fake();
    $s = tgProject();
    $assignee = User::factory()->create();
    $s['ws']->members()->attach($assignee->id, ['role' => 'workspace_member']);
    $s['project']->members()->attach($assignee->id, ['role' => 'project_member']);

    $this->actingAs($s['member'])
        ->postJson(route('tracker.tasks.assignees.store', $s['task']), ['user_id' => $assignee->id])
        ->assertOk();

    Queue::assertPushed(SendTrackerTelegramJob::class, function (SendTrackerTelegramJob $job) use ($assignee) {
        $r = new ReflectionClass($job);
        $ids = tap($r->getProperty('userIds'), fn ($p) => $p->setAccessible(true))->getValue($job);
        $type = tap($r->getProperty('type'), fn ($p) => $p->setAccessible(true))->getValue($job);

        return $type === 'assigned' && in_array($assignee->id, $ids, true);
    });
});

test('the actor never gets a Telegram notification about their own action', function () {
    Queue::fake();
    $s = tgProject();

    // member assigns themselves → notify excludes the actor → no recipients → no job.
    $this->actingAs($s['member'])
        ->postJson(route('tracker.tasks.assignees.store', $s['task']), ['user_id' => $s['member']->id])
        ->assertOk();

    Queue::assertNotPushed(SendTrackerTelegramJob::class);
});

test('the job only sends to recipients who have a telegram_id', function () {
    $withTg = User::factory()->create(['telegram_id' => 123456]);
    $withoutTg = User::factory()->create(['telegram_id' => null]);

    $sent = [];
    $notifier = Mockery::mock(App\Services\DeadlineNotifier::class);
    $notifier->shouldReceive('send')->andReturnUsing(function (User $u) use (&$sent) {
        $sent[] = $u->id;

        return true;
    });

    (new SendTrackerTelegramJob([$withTg->id, $withoutTg->id], 'assigned', ['task_title' => 'X']))
        ->handle($notifier);

    expect($sent)->toBe([$withTg->id]);
});
