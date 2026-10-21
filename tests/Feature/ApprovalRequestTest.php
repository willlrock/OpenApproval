<?php

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\RequestApproval;
use App\Models\RequestAttachment;

beforeEach(function () {
    seedRoles();
    $this->project = Project::create(['name' => 'Test Project']);
    $this->applicant = User::create([
        'telegram_id' => 111111,
        'full_name' => 'Ivan Ivanov',
        'username' => 'ivan',
        'role_id' => \App\Models\Role::where('name', 'requester')->firstOrFail()->id,
        'account_status' => 'approved',
    ]);
    $this->approver = User::create([
        'telegram_id' => 222222,
        'full_name' => 'Petr Petrov',
        'username' => 'petr',
        'role_id' => \App\Models\Role::where('name', 'approver')->firstOrFail()->id,
        'account_status' => 'approved',
        'title' => 'Финансовый директор',
    ]);
});

test('can create an approval request', function () {
    $request = ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $this->project->id,
        'applicant_id' => $this->applicant->id,
        'name' => 'Test Expense',
        'description' => 'Test description',
        'currency' => 'USD',
        'price_per_unit' => 100.50,
        'quantity' => 3,
        'total_price' => 301.50,
        'transaction_type' => 'bank',
        'supplier_name' => 'Acme Corp',
        'status' => 'pending_approval',
        'expected_approval_level' => 1,
    ]);

    expect($request)->toBeInstanceOf(ApprovalRequest::class)
        ->and($request->name)->toBe('Test Expense')
        ->and((float) $request->total_price)->toBe(301.50)
        ->and($request->status)->toBe('pending_approval');
});

test('approval request has relationships', function () {
    $request = ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $this->project->id,
        'applicant_id' => $this->applicant->id,
        'name' => 'Relationship Test',
        'description' => 'Test',
        'currency' => 'USD',
        'price_per_unit' => 50,
        'quantity' => 2,
        'total_price' => 100,
        'transaction_type' => 'cash',
        'supplier_name' => 'Supplier',
        'status' => 'pending_approval',
        'expected_approval_level' => 1,
    ]);

    RequestApproval::create([
        'approval_request_id' => $request->id,
        'approver_id' => $this->approver->id,
        'level' => 1,
        'status' => 'pending',
    ]);

    RequestAttachment::create([
        'approval_request_id' => $request->id,
        'file_type' => 'pdf',
        'telegram_file_id' => 'fake_file_id_123',
    ]);

    $request->refresh();

    expect($request->project)->toBeInstanceOf(Project::class)
        ->and($request->project->name)->toBe('Test Project')
        ->and($request->applicant)->toBeInstanceOf(User::class)
        ->and($request->applicant->first_name)->toBe('Ivan')
        ->and($request->approvals)->toHaveCount(1)
        ->and($request->attachments)->toHaveCount(1);
});

test('can create a supply request', function () {
    $request = ApprovalRequest::create([
        'type' => 'supply',
        'project_id' => $this->project->id,
        'applicant_id' => $this->applicant->id,
        'name' => 'Office Supplies',
        'description' => 'Need paper and pens',
        'quantity' => 10,
        'status' => 'pending_supply',
    ]);

    expect($request->type)->toBe('supply')
        ->and($request->status)->toBe('pending_supply')
        ->and($request->currency)->toBeNull()
        ->and($request->price_per_unit)->toBeNull();
});

test('sequential approval flow works correctly', function () {
    $approver2 = User::create([
        'telegram_id' => 333333,
        'full_name' => 'Maria Sidorova',
        'username' => 'maria',
        'role_id' => \App\Models\Role::where('name', 'admin')->firstOrFail()->id,
        'account_status' => 'approved',
    ]);

    $request = ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $this->project->id,
        'applicant_id' => $this->applicant->id,
        'name' => 'Multi-level Approval',
        'description' => 'Needs two approvals',
        'currency' => 'RUB',
        'price_per_unit' => 1000,
        'quantity' => 1,
        'total_price' => 1000,
        'transaction_type' => 'bank',
        'supplier_name' => 'Vendor',
        'status' => 'pending_approval',
        'expected_approval_level' => 1,
    ]);

    $approval1 = RequestApproval::create([
        'approval_request_id' => $request->id,
        'approver_id' => $this->approver->id,
        'level' => 1,
        'status' => 'pending',
    ]);

    $approval2 = RequestApproval::create([
        'approval_request_id' => $request->id,
        'approver_id' => $approver2->id,
        'level' => 2,
        'status' => 'pending',
    ]);

    // Level 1 approves
    $approval1->update(['status' => 'approved', 'action_date' => now()]);
    $request->update(['expected_approval_level' => 2]);

    expect($request->fresh()->expected_approval_level)->toBe(2);

    // Level 2 approves
    $approval2->update(['status' => 'approved', 'action_date' => now()]);
    $request->update(['status' => 'approved']);

    expect($request->fresh()->status)->toBe('approved');
});

test('rejection flow works correctly', function () {
    $request = ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $this->project->id,
        'applicant_id' => $this->applicant->id,
        'name' => 'To Be Rejected',
        'description' => 'This will be rejected',
        'currency' => 'USD',
        'price_per_unit' => 500,
        'quantity' => 1,
        'total_price' => 500,
        'transaction_type' => 'cash',
        'supplier_name' => 'Vendor',
        'status' => 'pending_approval',
        'expected_approval_level' => 1,
    ]);

    $approval = RequestApproval::create([
        'approval_request_id' => $request->id,
        'approver_id' => $this->approver->id,
        'level' => 1,
        'status' => 'pending',
    ]);

    // Reject
    $approval->update([
        'status' => 'rejected',
        'comment' => 'Too expensive',
        'action_date' => now(),
    ]);
    $request->update(['status' => 'rejected']);

    $approval->refresh();
    $request->refresh();

    expect($request->status)->toBe('rejected')
        ->and($approval->status)->toBe('rejected')
        ->and($approval->comment)->toBe('Too expensive');
});
