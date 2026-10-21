<?php

use App\Models\ApprovalRequest;
use App\Services\RequestEffects;
use App\Services\TrancheWorkflow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToWriteFile;

beforeEach(function () {
    seedRoles();
    Storage::fake('local');
    $this->actor = makeUserWithRole('admin');
    $this->request = ApprovalRequest::create([
        'type' => 'approval', 'name' => 'Payment evidence', 'status' => 'approved',
        'project_id' => makeProject()->id, 'applicant_id' => makeUserWithRole('requester')->id,
        'total_price' => 100, 'currency' => 'UZS', 'transaction_type' => 'bank',
    ]);
    $this->tranche = $this->request->tranches()->create(['amount' => 100, 'status' => 'pending']);
});

test('failed proof writes leave payment and notifications unchanged and allow a retry', function (string $failure) {
    $proof = Mockery::mock(UploadedFile::class);
    $write = $proof->shouldReceive('store')->once()->with('payment_proofs', 'local');
    match ($failure) {
        'false' => $write->andReturn(false),
        'exception' => $write->andThrow(UnableToWriteFile::atLocation('payment_proofs')),
        'missing file' => $write->andReturn('payment_proofs/missing.pdf'),
    };

    try {
        app(TrancheWorkflow::class)->update($this->actor, $this->request->id, $this->tranche->id, ['status' => 'paid'], $proof);
        $this->fail('An unsuccessful upload must be reported.');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('payment_proof_file');
    }
    expect($this->tranche->fresh()->status)->toBe('pending')
        ->and($this->tranche->fresh()->paid_at)->toBeNull()
        ->and($this->tranche->fresh()->payment_proof)->toBeNull()
        ->and($this->request->fresh()->status)->toBe('approved')
        ->and(DB::table('request_effects')->count())->toBe(0);

    $this->actingAs($this->actor)->putJson(route('finance.tranches.update', [$this->request->id, $this->tranche->id]), [
        'status' => 'paid', 'payment_proof_file' => UploadedFile::fake()->create('receipt.pdf', 1, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();
    $tranche = $this->tranche->fresh();
    expect($tranche->status)->toBe('paid')->and($this->request->fresh()->status)->toBe('paid');
    Storage::disk('local')->assertExists($tranche->payment_proof);
    $this->get(route('requests.proof', [$this->request->id, $tranche->id]))->assertDownload();
})->with(['false', 'exception', 'missing file']);

test('a later transaction failure removes the new proof without touching the old proof', function () {
    Storage::disk('local')->put('payment_proofs/old.pdf', 'old evidence');
    $this->tranche->update(['payment_proof' => 'payment_proofs/old.pdf']);
    $this->mock(RequestEffects::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Database write failed'));
    expect(fn () => app(TrancheWorkflow::class)->update(
        $this->actor, $this->request->id, $this->tranche->id, ['status' => 'paid'],
        UploadedFile::fake()->create('new.pdf', 1, 'application/pdf'),
    ))->toThrow(RuntimeException::class, 'Database write failed');
    expect($this->tranche->fresh()->status)->toBe('pending')
        ->and($this->tranche->fresh()->payment_proof)->toBe('payment_proofs/old.pdf')
        ->and($this->request->fresh()->status)->toBe('approved')
        ->and(Storage::disk('local')->allFiles('payment_proofs'))->toBe(['payment_proofs/old.pdf']);
});
