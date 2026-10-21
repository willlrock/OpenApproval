<?php

use App\Models\Tracker\Workspace;
use App\Models\User;

/**
 * TZ v2 §3/§16: projects live under a Space. Creating a project must land it in
 * the workspace's default space and make the creator a project_admin.
 */

test('creating a project places it under the workspace default space', function () {
    $admin = User::factory()->trackerAdmin()->create();
    $ws = Workspace::create(['name' => 'WS', 'slug' => 'ws-'.uniqid(), 'created_by' => $admin->id]);
    $space = $ws->spaces()->create(['name' => 'General', 'color' => '#6366f1', 'position' => 0]);

    $this->actingAs($admin)
        ->post(route('tracker.projects.store'), ['workspace_id' => $ws->id, 'name' => 'Ops'])
        ->assertRedirect();

    $this->assertDatabaseHas('tracker_projects', [
        'workspace_id' => $ws->id,
        'space_id' => $space->id,
        'name' => 'Ops',
    ]);

    // Creator is a project_admin and default statuses were seeded.
    $project = $ws->projects()->where('name', 'Ops')->firstOrFail();
    expect($project->roleFor($admin))->toBe('project_admin');
    expect($project->statuses()->count())->toBe(3);
});

test('a project with no explicit space auto-creates a default space', function () {
    $admin = User::factory()->trackerAdmin()->create();
    $ws = Workspace::create(['name' => 'WS2', 'slug' => 'ws-'.uniqid(), 'created_by' => $admin->id]);

    $this->actingAs($admin)
        ->post(route('tracker.projects.store'), ['workspace_id' => $ws->id, 'name' => 'Solo'])
        ->assertRedirect();

    expect($ws->spaces()->count())->toBeGreaterThanOrEqual(1);
    $project = $ws->projects()->where('name', 'Solo')->firstOrFail();
    expect($project->space_id)->not->toBeNull();
});
