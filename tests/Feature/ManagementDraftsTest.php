<?php

use App\Models\ApprovalRequest;
use App\Models\RequestDraft;
use App\Services\ManagementDocumentService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    seedRoles();
    $this->withoutVite();
    fakeBot();
    Storage::fake('local');
    Storage::fake('public');
    $this->author = makeUserWithRole('requester', ['signature_path' => 'signatures/author.png']);
    $this->signer = makeUserWithRole('approver', ['signature_path' => 'signatures/signer.png']);
    Storage::disk('local')->put('signatures/author.png', 'signature');
    Storage::disk('local')->put('signatures/signer.png', 'signature');
    $this->project = makeProject('Documents');
    $this->author->projects()->attach($this->project);
    $this->signer->projects()->attach($this->project);
    $this->actingAs($this->author);
    $this->draft = RequestDraft::create(['id' => (string) Str::uuid(), 'user_id' => $this->author->id, 'payload' => []]);
    Storage::disk('local')->put('attachments/source.docx', 'docx');
    $file = $this->draft->files()->create(['name' => 'source.docx', 'path' => 'attachments/source.docx', 'file_type' => 'docx']);
    $this->draft->update(['payload' => ['type' => 'management_document', 'title' => 'Приказ о назначении', 'project_id' => $this->project->id, 'document_kind' => 'order', 'effective_date_mode' => 'approval_date', 'effective_date' => '', 'approvers' => [$this->signer->id], 'source_file_id' => $file->id]]);
    $service = Mockery::mock(ManagementDocumentService::class)->makePartial();
    $service->shouldReceive('createPreview')->andReturnUsing(function () {
        Storage::disk('local')->put('management_documents/preview.pdf', '%PDF-1.4');

        return 'management_documents/preview.pdf';
    });
    app()->instance(ManagementDocumentService::class, $service);
});
test('management drafts require a current preview and submit idempotently with frozen signature', function () {
    $d = $this->draft;
    $this->get(route('request-drafts.edit', $d->id))->assertOk();
    $this->postJson(route('request-drafts.submit', $d->id), ['version' => 1])->assertUnprocessable();
    $this->postJson(route('management-drafts.preview', $d->id), ['version' => 1])->assertOk();
    $this->get(route('management-drafts.preview-file', $d->id))->assertOk();
    $payload = $d->payload;
    $payload['title'] = 'Исправленный приказ';
    $this->putJson(route('request-drafts.update', $d->id), ['version' => 1, 'payload' => $payload])->assertOk();
    $this->postJson(route('request-drafts.submit', $d->id), ['version' => 2])->assertUnprocessable();
    $this->get(route('management-drafts.preview-file', $d->id))->assertNotFound();
    $this->postJson(route('management-drafts.preview', $d->id), ['version' => 2])->assertOk();
    $this->postJson(route('request-drafts.submit', $d->id), ['version' => 2])->assertOk();
    $this->postJson(route('request-drafts.submit', $d->id), ['version' => 2])->assertOk();
    $r = ApprovalRequest::firstOrFail();
    expect(ApprovalRequest::count())->toBe(1)->and($r->name)->toBe('Исправленный приказ')->and($r->type)->toBe('management_document')->and($r->applicant_signature_snapshot_path)->not->toBeNull()->and($r->tranches()->count())->toBe(0);
    $this->get(route('management-drafts.document', $r->id))->assertDownload();
});
test('management source preview and submission recheck ownership project and signatures', function () {
    $d = $this->draft;
    $this->actingAs(makeUserWithRole('admin'))->postJson(route('management-drafts.preview', $d->id), ['version' => 1])->assertNotFound();
    $this->actingAs($this->author);
    Storage::disk('local')->delete('signatures/signer.png');
    $this->postJson(route('management-drafts.preview', $d->id), ['version' => 1])->assertUnprocessable();
    Storage::disk('local')->put('signatures/signer.png', 'signature');
    $this->postJson(route('management-drafts.preview', $d->id), ['version' => 1])->assertOk();
    $this->author->projects()->detach();
    $this->postJson(route('request-drafts.submit', $d->id), ['version' => 1])->assertUnprocessable();
    expect(ApprovalRequest::count())->toBe(0);
});
