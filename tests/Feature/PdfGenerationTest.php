<?php

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\User;
use App\Models\RequestApproval;
use App\Services\PdfGeneratorService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    Storage::fake('local');

    $this->project = Project::create(['name' => 'PDF Test Project']);
    $this->applicant = User::create([
        'telegram_id' => 500001,
        'full_name' => 'PDF Tester',
        'username' => 'pdftester',
        'role_id' => \App\Models\Role::where('name', 'requester')->firstOrFail()->id,
        'account_status' => 'approved',
    ]);
    $this->approver = User::create([
        'telegram_id' => 500002,
        'full_name' => 'PDF Approver',
        'username' => 'pdfapprover',
        'role_id' => \App\Models\Role::where('name', 'approver')->firstOrFail()->id,
        'account_status' => 'approved',
        'title' => 'Director',
    ]);
});

test('PDF protocol is generated successfully', function () {
    $request = ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $this->project->id,
        'applicant_id' => $this->applicant->id,
        'name' => 'PDF Generation Test',
        'description' => 'Testing PDF output',
        'currency' => 'USD',
        'price_per_unit' => 100,
        'quantity' => 5,
        'total_price' => 500,
        'transaction_type' => 'bank',
        'supplier_name' => 'PDF Supplier',
        'status' => 'approved',
        'expected_approval_level' => 1,
    ]);

    RequestApproval::create([
        'approval_request_id' => $request->id,
        'approver_id' => $this->approver->id,
        'level' => 1,
        'status' => 'approved',
        'action_date' => now(),
    ]);

    $service = new PdfGeneratorService();
    $filename = $service->generateProtocol($request);

    expect($filename)->toStartWith('protocols/req_')
        ->and($filename)->toEndWith('.pdf');

    Storage::disk('local')->assertExists($filename);

    $request->refresh();
    expect($request->pdf_url)->toBe($filename);
});

test('PDF protocol includes QR code data', function () {
    $request = ApprovalRequest::create([
        'type' => 'approval',
        'project_id' => $this->project->id,
        'applicant_id' => $this->applicant->id,
        'name' => 'QR Test',
        'description' => 'Testing QR',
        'currency' => 'RUB',
        'price_per_unit' => 1000,
        'quantity' => 1,
        'total_price' => 1000,
        'transaction_type' => 'cash',
        'supplier_name' => 'QR Supplier',
        'status' => 'approved',
        'expected_approval_level' => 1,
    ]);

    $service = new PdfGeneratorService();

    // Use reflection to test the protected generateQrCode method
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('generateQrCode');
    $method->setAccessible(true);

    $qrData = $method->invoke($service, $request);

    expect($qrData)->toStartWith('data:image/svg+xml;base64,')
        ->and(strlen($qrData))->toBeGreaterThan(100);
});
