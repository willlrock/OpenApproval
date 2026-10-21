<?php

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\RequestApproval;
use App\Models\Role;
use App\Models\User;
use App\Services\ManagementDocumentService;
use Illuminate\Support\Facades\Storage;

test('management document stores its frozen source metadata', function () {
    $role = Role::firstOrCreate(['name' => 'approver'], ['display_name' => 'Approver']);
    $project = Project::create(['name' => 'Head office', 'is_active' => true]);
    $applicant = User::create([
        'full_name' => 'Document Applicant',
        'phone' => '+998900000101',
        'role_id' => $role->id,
        'account_status' => 'approved',
    ]);

    $request = ApprovalRequest::create([
        'type' => 'management_document',
        'document_kind' => 'order',
        'effective_date_mode' => 'specific_date',
        'effective_date' => '2026-09-15',
        'project_id' => $project->id,
        'applicant_id' => $applicant->id,
        'name' => 'Remote work rules',
        'status' => 'pending_approval',
        'expected_approval_level' => 1,
        'source_docx_path' => 'management_documents/source/test.docx',
        'preview_pdf_path' => 'management_documents/previews/test.pdf',
        'source_sha256' => str_repeat('a', 64),
    ]);

    // Reload database defaults before asserting the persisted source metadata.
    $request->refresh();

    expect($request->isManagementDocument())->toBeTrue()
        ->and($request->document_version)->toBe(1)
        ->and($request->effective_date->format('d.m.Y'))->toBe('15.09.2026')
        ->and($request->source_sha256)->toHaveLength(64);
});

test('applicant signature is frozen when the document is submitted', function () {
    Storage::fake('public');
    Storage::fake('local');

    $role = Role::firstOrCreate(['name' => 'approver'], ['display_name' => 'Approver']);
    $project = Project::create(['name' => 'Management', 'is_active' => true]);
    $applicant = User::create([
        'full_name' => 'Order Applicant',
        'phone' => '+998900000103',
        'role_id' => $role->id,
        'account_status' => 'approved',
        'signature_path' => 'signatures/applicant.png',
    ]);
    Storage::disk('local')->put('signatures/applicant.png', 'applicant-signature-v1');

    $request = ApprovalRequest::create([
        'type' => 'management_document',
        'document_kind' => 'order',
        'effective_date_mode' => 'approval_date',
        'project_id' => $project->id,
        'applicant_id' => $applicant->id,
        'name' => 'Work schedule',
        'status' => 'pending_approval',
        'expected_approval_level' => 1,
    ]);

    app(ManagementDocumentService::class)->captureApplicantSignature($request);
    $request->refresh();
    Storage::disk('local')->put('signatures/applicant.png', 'applicant-signature-v2');

    expect($request->applicant_signature_sha256)->toBe(hash('sha256', 'applicant-signature-v1'))
        ->and(Storage::disk('local')->get($request->applicant_signature_snapshot_path))->toBe('applicant-signature-v1');
});

test('signature is copied to an immutable private snapshot', function () {
    Storage::fake('public');
    Storage::fake('local');

    $role = Role::firstOrCreate(['name' => 'approver'], ['display_name' => 'Approver']);
    $project = Project::create(['name' => 'Operations', 'is_active' => true]);
    $signer = User::create([
        'full_name' => 'Final Signer',
        'phone' => '+998900000102',
        'role_id' => $role->id,
        'account_status' => 'approved',
        'signature_path' => 'signatures/signer.png',
    ]);
    Storage::disk('local')->put('signatures/signer.png', 'signature-version-one');

    $request = ApprovalRequest::create([
        'type' => 'management_document',
        'document_kind' => 'resolution',
        'project_id' => $project->id,
        'applicant_id' => $signer->id,
        'name' => 'Operations resolution',
        'status' => 'pending_approval',
        'expected_approval_level' => 1,
    ]);
    $approval = RequestApproval::create([
        'approval_request_id' => $request->id,
        'approver_id' => $signer->id,
        'level' => 1,
        'status' => 'pending',
    ]);

    app(ManagementDocumentService::class)->captureSignature($approval);
    $approval->refresh();
    Storage::disk('local')->put('signatures/signer.png', 'signature-version-two');

    expect($approval->signature_sha256)->toBe(hash('sha256', 'signature-version-one'))
        ->and(Storage::disk('local')->get($approval->signature_snapshot_path))->toBe('signature-version-one');
});

test('the fillable Word template is shipped with the application', function () {
    $service = app(ManagementDocumentService::class);

    foreach (['order', 'resolution'] as $kind) {
        $path = $service->templatePath($kind);
        expect(is_file($path))->toBeTrue()
            ->and(filesize($path))->toBeGreaterThan(10_000);
    }
});

test('effective date labels distinguish approval date and a fixed date', function () {
    $service = app(ManagementDocumentService::class);

    expect($service->effectiveDateLabel('approval_date'))->toBe('Со дня утверждения')
        ->and($service->effectiveDateLabel('specific_date', \Carbon\Carbon::parse('2026-09-15')))->toBe('С 15.09.2026');
});
