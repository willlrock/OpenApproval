<?php

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();

    $this->project = Project::create(['name' => 'Verify Project']);
    $this->applicant = User::create([
        'telegram_id' => 400001,
        'full_name' => 'Verify User',
        'username' => 'verifyuser',
        'role_id' => \App\Models\Role::where('name', 'requester')->firstOrFail()->id,
        'account_status' => 'approved',
    ]);
    $this->assertDatabaseHas('users', [
        'telegram_id' => 400001,
        'full_name' => 'Verify User',
        'username' => 'verifyuser',
        'role_id' => \App\Models\Role::where('name', 'requester')->firstOrFail()->id,
        'account_status' => 'approved',
    ]);
    $this->request = ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $this->project->id,
        'applicant_id' => $this->applicant->id,
        'name' => 'Verify Test Request',
        'description' => 'Testing verification',
        'currency' => 'USD',
        'price_per_unit' => 250,
        'quantity' => 2,
        'total_price' => 500,
        'transaction_type' => 'bank',
        'supplier_name' => 'Test Supplier',
        'status' => 'approved',
        'expected_approval_level' => 1,
    ]);
});

test('verification route returns 200 with valid hash', function () {
    $hash = sha1($this->request->id . $this->request->created_at . config('app.key'));

    $this->get("/verify/{$this->request->id}/{$hash}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Verify')
            ->has('request')
            ->where('verified', true)
            ->where('request.id', $this->request->id)
            ->where('request.name', 'Verify Test Request')
            ->where('request.status', 'approved')
        );
});

test('verification route returns 403 with invalid hash', function () {
    $this->get("/verify/{$this->request->id}/invalidhash123")
        ->assertForbidden();
});

test('verification route returns 404 for non-existent request', function () {
    $hash = sha1('99999' . now() . config('app.key'));

    $this->get("/verify/99999/{$hash}")
        ->assertNotFound();
});
