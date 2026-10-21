<?php

use App\Models\ApprovalRequest;
use App\Models\Supplier;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    fakeBot();
    Storage::fake('local');
});

test('every approved role can enter the web requests panel', function (string $role) {
    $this->actingAs(makeUserWithRole($role));
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('requests'))->assertOk();
    $this->get(route('my-requests'))->assertOk();
})->with(['requester', 'supplier', 'approver', 'accountant', 'treasurer', 'admin', 'superadmin']);

test('unapproved accounts remain blocked from the panel', function (string $status) {
    $this->actingAs(makeUserWithRole('supplier', ['account_status' => $status]));
    $this->get(route('dashboard'))->assertForbidden();
    $this->get(route('requests'))->assertForbidden();
})->with(['pending', 'rejected']);

function crmRequest($owner, $project, array $attributes = []): ApprovalRequest
{
    return ApprovalRequest::create(array_merge([
        'applicant_id' => $owner->id, 'project_id' => $project->id,
        'type' => 'approval', 'name' => 'CRM access request', 'status' => 'pending_approval',
        'currency' => 'UZS', 'transaction_type' => 'bank', 'total_price' => 100,
        'expected_approval_level' => 1,
    ], $attributes));
}

test('requester panel and file routes do not reveal another applicants requests', function () {
    $user = makeUserWithRole('requester');
    $project = makeProject('Shared project');
    $user->projects()->attach($project);
    $own = crmRequest($user, $project);
    $other = crmRequest(makeUserWithRole('requester'), $project, ['pdf_url' => 'protocols/other.pdf']);
    Storage::disk('local')->put('protocols/other.pdf', 'private');
    $this->actingAs($user)->get(route('requests'))->assertInertia(fn ($page) => $page
        ->has('requests.data', 1)->where('requests.data.0.id', $own->id));
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('stats.totalRequests', 1));
    $this->get(route('requests.detail', $other->id))->assertNotFound();
    $this->get(route('requests.protocol', $other->id))->assertNotFound();
    $this->get(route('users'))->assertForbidden();
});

test('director global read access does not grant request mutations or administration', function () {
    $director = makeUserWithRole('supplier');
    $project = makeProject('Unassigned project');
    $request = crmRequest(makeUserWithRole('requester'), $project, ['status' => 'pending_supply', 'type' => 'supply']);
    $supplier = Supplier::create(['name' => 'Counterparty']);

    $this->actingAs($director)->get(route('requests'))->assertInertia(fn ($page) => $page
        ->has('requests.data', 1)->where('requests.data.0.can_delete', false)
        ->where('projects.0.id', $project->id));
    $this->get(route('requests.detail', $request->id))->assertOk();
    $this->post(route('suppliers.attach', $request->id), ['supplier_id' => $supplier->id])->assertNotFound();
    $this->delete(route('requests.delete', $request->id))->assertSessionHasErrors('error');
    $this->get(route('users'))->assertForbidden();
    $this->get(route('system-logs'))->assertForbidden();
    $this->get(route('finance.requests'))->assertForbidden();
    expect($request->fresh()->supplier_id)->toBeNull()->and($request->fresh()->status)->toBe('pending_supply');
});
