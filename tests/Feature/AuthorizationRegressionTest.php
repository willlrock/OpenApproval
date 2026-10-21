<?php

use App\Exports\TranchesExport;
use App\Models\ApprovalRequest;
use App\Models\RequestApproval;
use App\Models\RequestAttachment;
use App\Models\RequestTranche;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

test('a management document survives deletion and restoration with its private files', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    $admin = makeUserWithRole('admin');
    $request = regressionRequest($admin, makeProject('Document trash'), [
        'type' => 'management_document',
        'document_kind' => 'order',
        'status' => 'rejected',
        'source_docx_path' => 'management_documents/source/trash.docx',
        'preview_pdf_path' => 'management_documents/previews/trash.pdf',
        'applicant_signature_snapshot_path' => 'management_documents/signatures/trash.png',
    ]);
    $paths = [$request->source_docx_path, $request->preview_pdf_path, $request->applicant_signature_snapshot_path];
    foreach ($paths as $path) {
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, 'preserved-content');
    }

    $this->actingAs($admin)->delete(route('requests.delete', $request->id))->assertRedirect();
    $this->assertSoftDeleted('approval_requests', ['id' => $request->id]);
    foreach ($paths as $path) {
        expect(\Illuminate\Support\Facades\Storage::disk('local')->get($path))->toBe('preserved-content');
    }

    $this->post(route('requests.restore', $request->id))->assertRedirect();
    expect($request->fresh()->trashed())->toBeFalse();
    foreach ($paths as $path) {
        expect(\Illuminate\Support\Facades\Storage::disk('local')->get($path))->toBe('preserved-content');
    }
});

function regressionRequest(User $applicant, \App\Models\Project $project, array $attributes = []): ApprovalRequest
{
    return ApprovalRequest::create(array_merge([
        'type' => 'approval',
        'project_id' => $project->id,
        'applicant_id' => $applicant->id,
        'name' => 'Scoped request',
        'status' => 'approved',
        'transaction_type' => 'bank',
        'currency' => 'UZS',
        'total_price' => 1000,
        'expected_approval_level' => 1,
    ], $attributes));
}

test('finance user without projects cannot see requests from every project', function () {
    $treasurer = makeUserWithRole('treasurer');
    $applicant = makeUserWithRole('requester');
    $request = regressionRequest($applicant, makeProject('Hidden project'));

    $this->actingAs($treasurer)
        ->get(route('finance.requests.detail', $request))
        ->assertNotFound();
});

test('dashboard attachment and export endpoints enforce request scope', function () {
    $approver = makeUserWithRole('approver');
    $applicant = makeUserWithRole('requester');
    $allowedProject = makeProject('Allowed');
    $hiddenProject = makeProject('Hidden');
    $approver->projects()->attach($allowedProject);

    $allowedRequest = regressionRequest($applicant, $allowedProject);
    RequestApproval::create([
        'approval_request_id' => $allowedRequest->id,
        'approver_id' => $approver->id,
        'level' => 1,
        'status' => 'approved',
    ]);

    $hiddenRequest = regressionRequest($applicant, $hiddenProject);
    $attachment = RequestAttachment::create([
        'approval_request_id' => $hiddenRequest->id,
        'file_type' => 'pdf',
        'telegram_file_id' => 'secret-file',
    ]);

    $this->actingAs($approver)
        ->get(route('requests.attachment.download', [$hiddenRequest, $attachment]))
        ->assertNotFound();

    $this->actingAs($approver)
        ->get(route('requests.export-items', $hiddenRequest))
        ->assertNotFound();
});

test('finance user cannot update a tranche outside assigned projects', function () {
    $accountant = makeUserWithRole('accountant');
    $applicant = makeUserWithRole('requester');
    $allowedProject = makeProject('Allowed finance');
    $hiddenProject = makeProject('Hidden finance');
    $accountant->projects()->attach($allowedProject);

    $hiddenRequest = regressionRequest($applicant, $hiddenProject);
    $tranche = RequestTranche::create([
        'approval_request_id' => $hiddenRequest->id,
        'amount' => 1000,
        'percent' => 100,
        'status' => 'pending',
    ]);

    $this->actingAs($accountant)
        ->put(route('finance.tranches.update', [$hiddenRequest, $tranche]), ['status' => 'paid'])
        ->assertNotFound();

    expect($tranche->fresh()->status)->toBe('pending');
});

test('an approved request cannot be rejected by a stale web action', function () {
    $approver = makeUserWithRole('approver');
    $applicant = makeUserWithRole('requester');
    $project = makeProject('Approval state');
    $approver->projects()->attach($project);
    $request = regressionRequest($applicant, $project);
    $approval = RequestApproval::create([
        'approval_request_id' => $request->id,
        'approver_id' => $approver->id,
        'level' => 1,
        'status' => 'approved',
        'action_date' => now(),
    ]);

    $this->actingAs($approver)
        ->post(route('requests.reject', $request), ['reason' => 'Late rejection'])
        ->assertSessionHasErrors('error');

    expect($request->fresh()->status)->toBe('approved')
        ->and($approval->fresh()->status)->toBe('approved');
});

test('finance export contains only assigned projects', function () {
    $accountant = makeUserWithRole('accountant');
    $applicant = makeUserWithRole('requester');
    $allowedProject = makeProject('Allowed export');
    $hiddenProject = makeProject('Hidden export');
    $accountant->projects()->attach($allowedProject);

    $allowedRequest = regressionRequest($applicant, $allowedProject);
    $hiddenRequest = regressionRequest($applicant, $hiddenProject);
    $allowedTranche = RequestTranche::create([
        'approval_request_id' => $allowedRequest->id,
        'amount' => 1000,
        'percent' => 100,
        'status' => 'pending',
    ]);
    RequestTranche::create([
        'approval_request_id' => $hiddenRequest->id,
        'amount' => 1000,
        'percent' => 100,
        'status' => 'pending',
    ]);

    expect((new TranchesExport($accountant))->query()->pluck('request_tranches.id')->all())
        ->toBe([$allowedTranche->id]);
});
