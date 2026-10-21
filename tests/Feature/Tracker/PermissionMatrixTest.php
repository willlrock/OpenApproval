<?php

use App\Models\Tracker\Project;
use App\Models\Tracker\Space;
use App\Models\Tracker\Workspace;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * §21 permission matrix + §4.3 invitation gate, enforced server-side (§30).
 * The project face hides controls, but these tests prove the API itself rejects
 * unauthorized actions regardless of the UI.
 */

/**
 * Build a workspace + space + project (one todo status, one done status, one
 * task), owned by a fresh Tracker Admin.
 *
 * @return array{admin: User, ws: Workspace, space: Space, project: Project, status: \App\Models\Tracker\Status, doneStatus: \App\Models\Tracker\Status, task: \App\Models\Tracker\Task}
 */
function scaffold(string $visibility = 'workspace'): array
{
    $admin = User::factory()->trackerAdmin()->create();

    $ws = Workspace::create([
        'name' => 'WS',
        'slug' => 'ws-'.uniqid(),
        'created_by' => $admin->id,
        'members_can_create_boards' => true,
    ]);

    $space = $ws->spaces()->create(['name' => 'General', 'color' => '#6366f1', 'position' => 0]);

    $project = Project::create([
        'workspace_id' => $ws->id,
        'space_id' => $space->id,
        'name' => 'Project',
        'visibility' => $visibility,
        'position' => 1,
        'created_by' => $admin->id,
    ]);

    $status = $project->statuses()->create(['name' => 'Todo', 'status_category' => 'todo', 'position' => 1]);
    $doneStatus = $project->statuses()->create(['name' => 'Done', 'status_category' => 'done', 'position' => 2]);

    $task = $project->tasks()->create([
        'status_id' => $status->id,
        'task_number' => 1,
        'title' => 'Task',
        'position' => 1,
        'created_by' => $admin->id,
    ]);

    return compact('admin', 'ws', 'space', 'project', 'status', 'doneStatus', 'task');
}

/** Attach a user to a workspace + project with the given project role. */
function member(array $s, string $projectRole, string $wsRole = 'workspace_member'): User
{
    $u = User::factory()->create();
    $s['ws']->members()->attach($u->id, ['role' => $wsRole]);
    $s['project']->members()->attach($u->id, ['role' => $projectRole]);

    return $u;
}

// ── Invitation gate (§4.3) ──────────────────────────────────────────────────

test('outsider with no workspace sees NotInvited, not project data', function () {
    $s = scaffold();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('tracker.projects.show', $s['project']))
        ->assertInertia(fn (Assert $page) => $page->component('tracker/NotInvited'));
});

test('outsider hitting a mutation API is rejected 403', function () {
    $s = scaffold();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->postJson(route('tracker.tasks.store', $s['project']), ['status_id' => $s['status']->id, 'title' => 'X'])
        ->assertForbidden();
});

test('workspace outsider cannot open a project in another workspace', function () {
    $s = scaffold();
    // Member of *some other* workspace → passes the entry gate, fails project access.
    $other = User::factory()->create();
    Workspace::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'created_by' => $other->id])
        ->members()->attach($other->id, ['role' => 'workspace_member']);

    $this->actingAs($other)
        ->get(route('tracker.projects.show', $s['project']))
        ->assertForbidden();
});

// ── Observer = read-only (§5.3, §21) ────────────────────────────────────────

test('observer can view the project with can_edit=false', function () {
    $s = scaffold();
    $observer = member($s, 'project_observer');

    $this->actingAs($observer)
        ->get(route('tracker.projects.show', $s['project']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tracker/Project')
            ->where('project.can_edit', false));
});

test('observer cannot create a task', function () {
    $s = scaffold();
    $observer = member($s, 'project_observer');

    $this->actingAs($observer)
        ->post(route('tracker.tasks.store', $s['project']), ['status_id' => $s['status']->id, 'title' => 'X'])
        ->assertForbidden();
});

test('observer cannot move a task', function () {
    $s = scaffold();
    $observer = member($s, 'project_observer');

    $this->actingAs($observer)
        ->patchJson(route('tracker.tasks.move', $s['task']), ['status_id' => $s['doneStatus']->id, 'position' => 2])
        ->assertForbidden();
});

test('observer cannot upload an attachment', function () {
    Storage::fake('local');
    $s = scaffold();
    $observer = member($s, 'project_observer');

    $this->actingAs($observer)
        ->post(route('tracker.tasks.attachments.store', $s['task']), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
        ->assertForbidden();
});

// ── Member = edit tasks, not structure (§5.3, §21) ──────────────────────────

test('member can create and move a task', function () {
    $s = scaffold();
    $u = member($s, 'project_member');

    $this->actingAs($u)
        ->post(route('tracker.tasks.store', $s['project']), ['status_id' => $s['status']->id, 'title' => 'New'])
        ->assertRedirect();
    $this->assertDatabaseHas('tracker_tasks', ['project_id' => $s['project']->id, 'title' => 'New']);

    $this->actingAs($u)
        ->patchJson(route('tracker.tasks.move', $s['task']), ['status_id' => $s['doneStatus']->id, 'position' => 2])
        ->assertOk();
    // Moving into a done-category status sets completion (§22).
    $this->assertNotNull($s['task']->fresh()->completed_at);
});

test('member can upload an attachment but cannot reorder statuses', function () {
    Storage::fake('local');
    $s = scaffold();
    $u = member($s, 'project_member');

    $this->actingAs($u)
        ->post(route('tracker.tasks.attachments.store', $s['task']), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
        ->assertOk();
    $this->assertDatabaseHas('tracker_attachments', ['task_id' => $s['task']->id, 'original_name' => 'a.pdf']);

    // Structure management is project_admin only (§21).
    $this->actingAs($u)
        ->patchJson(route('tracker.statuses.reorder', $s['project']), ['order' => [$s['doneStatus']->id, $s['status']->id]])
        ->assertForbidden();
});

// ── Admin = full structure control (§5.3, §21) ──────────────────────────────

test('project admin can reorder statuses', function () {
    $s = scaffold();
    $u = member($s, 'project_admin', 'workspace_admin');

    $this->actingAs($u)
        ->patchJson(route('tracker.statuses.reorder', $s['project']), ['order' => [$s['doneStatus']->id, $s['status']->id]])
        ->assertOk();

    expect($s['doneStatus']->fresh()->position)->toBe(1);
    expect($s['status']->fresh()->position)->toBe(2);
});

// ── Tracker Admin override (§5.4, D10) ──────────────────────────────────────

test('tracker admin can open any project without membership', function () {
    $s = scaffold();
    $superadmin = User::factory()->trackerAdmin()->create();

    $this->actingAs($superadmin)
        ->get(route('tracker.projects.show', $s['project']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('project.can_edit', true));
});

// ── Upload validation (§28, D9) ─────────────────────────────────────────────

test('disallowed file type is rejected', function () {
    Storage::fake('local');
    $s = scaffold();
    $u = member($s, 'project_member');

    $this->actingAs($u)
        ->post(route('tracker.tasks.attachments.store', $s['task']), ['file' => UploadedFile::fake()->create('evil.exe', 10, 'application/octet-stream')])
        ->assertSessionHasErrors('file');
});
