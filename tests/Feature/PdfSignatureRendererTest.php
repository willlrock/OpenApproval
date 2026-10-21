<?php

use App\Models\ApprovalRequest;
use App\Services\ManagementDocumentService;
use App\Services\PdfGeneratorService;
use App\Services\PdfSignatureRenderer;
use App\Services\SignatureCleanerService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function pdfSignaturePng(bool $clean): string
{
    $image = imagecreatetruecolor(80, 40);
    imagefill($image, 0, 0, imagecolorallocate($image, $clean ? 255 : 230, $clean ? 255 : 225, $clean ? 255 : 220));
    imageline($image, 10, 30, 70, 10, imagecolorallocate($image, 0, 0, $clean ? 0 : 180));
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

function pdfCleanedSignatureFixture(int $id): array
{
    $batch = (string) Str::uuid();
    $snapshot = "snapshots/{$id}.png";
    $profile = "signatures/cleaned-v1/{$batch}/{$id}.png";
    $entryPath = "signature-cleanup/{$batch}/entries/{$id}.json";
    $original = pdfSignaturePng(false);
    $cleaned = pdfSignaturePng(true);
    $hash = hash('sha256', $original);
    $entry = ['user_id' => $id, 'original_sha256' => $hash,
        'cleaned_path' => $profile, 'cleaned_sha256' => hash('sha256', $cleaned)];
    Storage::disk('local')->put($snapshot, $original);
    Storage::disk('local')->put($profile, $cleaned);
    Storage::disk('local')->put($entryPath, json_encode($entry));

    return compact('snapshot', 'profile', 'entryPath', 'original', 'cleaned', 'hash', 'entry');
}

beforeEach(function () {
    seedRoles();
    Storage::fake('local');
    $this->mock(SignatureCleanerService::class)->shouldNotReceive('clean');
});

test('PDF reuses the existing verified cleanup without rewriting the signature snapshot', function () {
    $f = pdfCleanedSignatureFixture(42);
    $uri = app(PdfSignatureRenderer::class)->dataUri($f['snapshot'], $f['hash'], $f['profile']);

    expect($uri)->toBe('data:image/png;base64,'.base64_encode($f['cleaned']))
        ->and(Storage::disk('local')->get($f['snapshot']))->toBe($f['original'])
        ->and(Storage::disk('local')->allFiles())->toHaveCount(3);
});

test('new snapshots already containing the cleaned profile are displayed directly', function () {
    $f = pdfCleanedSignatureFixture(42);
    Storage::disk('local')->put($f['snapshot'], $f['cleaned']);

    expect(app(PdfSignatureRenderer::class)->dataUri($f['snapshot'], hash('sha256', $f['cleaned']), $f['profile']))
        ->toBe('data:image/png;base64,'.base64_encode($f['cleaned']));
});

test('a later replacement or an unverified cleaned file cannot replace a frozen signature', function (string $problem) {
    $f = pdfCleanedSignatureFixture(42);
    switch ($problem) {
        case 'different signature':
            $f['entry']['original_sha256'] = hash('sha256', 'different signature');
            break;
        case 'corrupt cleaned file':
            Storage::disk('local')->put($f['profile'], 'corrupted');
            break;
        case 'wrong cleaned hash':
            $f['entry']['cleaned_sha256'] = hash('sha256', 'different output');
            break;
        case 'invalid hash types':
            $f['entry']['original_sha256'] = 123;
            $f['entry']['cleaned_sha256'] = [];
            break;
        case 'wrong cleaned path':
            $f['entry']['cleaned_path'] = 'another-user.png';
            break;
        case 'missing entry':
            Storage::disk('local')->delete($f['entryPath']);
            break;
        case 'malformed entry':
            Storage::disk('local')->put($f['entryPath'], '{broken json');
            break;
        case 'missing cleaned file':
            Storage::disk('local')->delete($f['profile']);
            break;
    }
    if (! in_array($problem, ['missing entry', 'malformed entry'])) {
        Storage::disk('local')->put($f['entryPath'], json_encode($f['entry']));
    }

    expect(app(PdfSignatureRenderer::class)->dataUri($f['snapshot'], $f['hash'], $f['profile']))
        ->toBe('data:image/png;base64,'.base64_encode($f['original']));
})->with(['different signature', 'corrupt cleaned file', 'wrong cleaned hash', 'invalid hash types', 'wrong cleaned path', 'missing entry', 'malformed entry', 'missing cleaned file']);

test('snapshot integrity is checked before using its cleaned version', function () {
    $f = pdfCleanedSignatureFixture(42);
    Storage::disk('local')->put($f['snapshot'], 'tampered snapshot');

    expect(fn () => app(PdfSignatureRenderer::class)->dataUri($f['snapshot'], $f['hash'], $f['profile']))
        ->toThrow(RuntimeException::class, 'Подпись повреждена.');
});

test('new expense PDFs use cleaned applicant and approver images while existing PDFs stay intact', function () {
    $author = makeUserWithRole('requester');
    $approver = makeUserWithRole('approver');
    $applicantSignature = pdfCleanedSignatureFixture($author->id);
    $approverSignature = pdfCleanedSignatureFixture($approver->id);
    $author->update(['signature_path' => $applicantSignature['profile']]);
    $approver->update(['signature_path' => $approverSignature['profile']]);
    $request = ApprovalRequest::create(['applicant_id' => $author->id, 'project_id' => makeProject()->id,
        'type' => 'approval', 'name' => 'Future PDF', 'status' => 'approved', 'currency' => 'UZS', 'total_price' => 100,
        'applicant_signature_snapshot_path' => $applicantSignature['snapshot'], 'applicant_signature_sha256' => $applicantSignature['hash'],
        'pdf_url' => 'protocols/original.pdf']);
    Storage::disk('local')->put('protocols/original.pdf', 'existing PDF');
    $approval = $request->approvals()->create(['approver_id' => $approver->id, 'level' => 1, 'status' => 'approved',
        'signature_snapshot_path' => $approverSignature['snapshot'], 'signature_sha256' => $approverSignature['hash']]);
    $service = app(PdfGeneratorService::class);
    $request->load('project', 'applicant', 'approvals.approver', 'items', 'tranches');
    $signatures = (new ReflectionMethod($service, 'collectSignatures'))->invoke($service, $request);
    $cleanedUri = 'data:image/png;base64,'.base64_encode($applicantSignature['cleaned']);

    expect($signatures[$approval->id])->toBe($cleanedUri)
        ->and(view('pdf.request', compact('request', 'signatures'))->render())->toContain($cleanedUri)
        ->not->toContain(base64_encode($applicantSignature['original']));
    $pdf = $service->generateProtocol($request);
    expect(Storage::disk('local')->get($pdf))->toStartWith('%PDF')
        ->and(Storage::disk('local')->get('protocols/original.pdf'))->toBe('existing PDF')
        ->and($request->fresh()->applicant_signature_sha256)->toBe($applicantSignature['hash'])
        ->and($approval->fresh()->signature_sha256)->toBe($approverSignature['hash'])
        ->and(Storage::disk('local')->get($applicantSignature['snapshot']))->toBe($applicantSignature['original'])
        ->and(Storage::disk('local')->get($approverSignature['snapshot']))->toBe($approverSignature['original']);

    $management = app(ManagementDocumentService::class);
    expect((new ReflectionMethod($management, 'collectSnapshotSignatures'))->invoke($management, $request))
        ->toBe([$approval->id => $cleanedUri]);
    expect((new ReflectionMethod($management, 'snapshotDataUri'))->invoke($management,
        $applicantSignature['snapshot'], $applicantSignature['hash'], $applicantSignature['profile']))->toBe($cleanedUri);
});
