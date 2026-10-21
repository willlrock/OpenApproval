<?php

use App\Models\User;
use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\RequestApproval;

beforeEach(function () {
    seedRoles();
    $this->user = User::factory()->role('superadmin')->create();
    $this->project = Project::create(['name' => 'Admin Test Project']);
    $this->applicant = User::create([
        'telegram_id' => 100001,
        'full_name' => 'Test User',
        'username' => 'testuser',
        'role_id' => \App\Models\Role::where('name', 'requester')->firstOrFail()->id,
        'account_status' => 'approved',
    ]);
});

test('guests cannot access admin routes', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('requests'))->assertRedirect(route('login'));
    $this->get(route('users'))->assertRedirect(route('login'));
    $this->get(route('projects'))->assertRedirect(route('login'));
    $this->get(route('logs'))->assertRedirect(route('login'));
});

test('authenticated user can access dashboard', function () {
    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('authenticated user can access requests page', function () {
    $this->actingAs($this->user)
        ->get(route('requests'))
        ->assertOk();
});

test('authenticated user can access users page', function () {
    $this->actingAs($this->user)
        ->get(route('users'))
        ->assertOk();
});

test('authenticated user can access projects page', function () {
    $this->actingAs($this->user)
        ->get(route('projects'))
        ->assertOk();
});

test('authenticated user can create a project', function () {
    $this->actingAs($this->user)
        ->post(route('projects.store'), ['name' => 'New Project'])
        ->assertRedirect();

    $this->assertDatabaseHas('projects', ['name' => 'New Project']);
});

test('authenticated user can update a project', function () {
    $this->actingAs($this->user)
        ->put(route('projects.update', $this->project->id), ['name' => 'Updated Project'])
        ->assertRedirect();

    expect($this->project->fresh()->name)->toBe('Updated Project');
});

test('authenticated user can delete a project', function () {
    $this->actingAs($this->user)
        ->delete(route('projects.delete', $this->project->id))
        ->assertRedirect();

    $this->assertDatabaseMissing('projects', ['id' => $this->project->id]);
});

test('authenticated user can update telegram user role', function () {
    $this->actingAs($this->user)
        ->put(route('users.update', $this->applicant->id), [
            'role' => 'approver',
            'title' => 'Manager',
        ])
        ->assertRedirect();

    $this->applicant->refresh();
    expect($this->applicant->role)->toBe('approver')
        ->and($this->applicant->title)->toBe('Manager');
});

test('authenticated user can view request detail', function () {
    $request = ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $this->project->id,
        'applicant_id' => $this->applicant->id,
        'name' => 'Detail Test',
        'description' => 'Test',
        'currency' => 'USD',
        'price_per_unit' => 100,
        'quantity' => 1,
        'total_price' => 100,
        'transaction_type' => 'cash',
        'supplier_name' => 'Test Supplier',
        'status' => 'pending_approval',
        'expected_approval_level' => 1,
    ]);

    $this->actingAs($this->user)
        ->get(route('requests.detail', $request->id))
        ->assertOk();
});

test('authenticated admin can deactivate a telegram user', function () {
    $this->actingAs($this->user)
        ->patch(route('users.activation', $this->applicant->id), ['is_active' => false])
        ->assertRedirect();

    expect($this->applicant->fresh()->is_active)->toBeFalse();
});
