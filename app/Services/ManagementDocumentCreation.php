<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\RequestDraft;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManagementDocumentCreation
{
    public function signers(int $projectId, ?int $authorId = null)
    {
        return app(ApprovalParticipantPolicy::class)->eligible($projectId, [$authorId], true)->orderBy('id')->get();
    }

    public function validate(User $actor, RequestDraft $draft): array
    {
        abort_unless($actor->fresh()->isApproved(), 403);
        if (! $actor->signature_path || ! Storage::disk('local')->exists($actor->signature_path)) {
            throw ValidationException::withMessages(['signature' => 'Добавьте подпись в профиле перед отправкой приказа.']);
        }
        $input = $draft->payload;
        $input['effective_date'] = ($input['effective_date'] ?? null) ?: null;
        $data = Validator::make($input, [
            'type' => 'required|in:management_document', 'title' => 'required|string|max:255',
            'project_id' => ['required', 'integer', Rule::in(app(ApprovalRequestCreation::class)->projects($actor)->pluck('id')->all())],
            'document_kind' => 'required|in:order,resolution', 'effective_date_mode' => 'required|in:approval_date,specific_date',
            'effective_date' => 'nullable|required_if:effective_date_mode,specific_date|date_format:Y-m-d|after_or_equal:today',
            'approvers' => 'required|array|min:1|max:30', 'approvers.*' => 'required|integer|distinct', 'source_file_id' => 'required|integer',
        ])->validate();
        $allowed = $this->signers((int) $data['project_id'], $actor->id);
        if (collect($data['approvers'])->diff($allowed->pluck('id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['approvers' => 'Выберите действующих подписантов проекта с подписью.']);
        }
        foreach ($allowed->whereIn('id', $data['approvers']) as $signer) {
            if (! Storage::disk('local')->exists($signer->signature_path)) {
                throw ValidationException::withMessages(['approvers' => 'У одного из подписантов отсутствует файл подписи.']);
            }
        }
        $file = $draft->files()->find($data['source_file_id']);
        if (! $file || $file->file_type !== 'docx' || ! Storage::disk('local')->exists($file->path)) {
            throw ValidationException::withMessages(['source_file_id' => 'Загрузите исходный DOCX и выберите его.']);
        }
        $data['source_path'] = $file->path;

        return $data;
    }

    public function fromDraft(User $actor, RequestDraft $draft): ApprovalRequest
    {
        $d = $this->validate($actor, $draft);
        if ($draft->preview_version !== $draft->version || ! $draft->management_preview_path || ! Storage::disk('local')->exists($draft->management_preview_path)) {
            throw ValidationException::withMessages(['preview' => 'Сформируйте и проверьте предпросмотр текущей версии.']);
        }

        return $this->createFrozen($actor, $d, $d['source_path'], $draft->management_preview_path, $draft->id);
    }

    public function createFrozen(User $actor, array $input, string $source, string $preview, string $submissionKey): ApprovalRequest
    {
        return DB::transaction(function () use ($actor, $input, $source, $preview, $submissionKey) {
            app(ApprovalParticipantPolicy::class)->lockParticipants([$actor->id, ...(array) ($input['approvers'] ?? [])]);
            $actor = User::lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->isApproved(), 403);
            $existing = ApprovalRequest::withTrashed()->where('submission_key', $submissionKey)->first();
            if ($existing) {
                abort_unless($existing->applicant_id === $actor->id && ! $existing->trashed(), 409);

                return $existing;
            }
            $data = Validator::make($input + ['submission_key' => $submissionKey], [
                'submission_key' => 'required|uuid', 'title' => 'required|string|max:255',
                'project_id' => ['required', 'integer', Rule::in(app(ApprovalRequestCreation::class)->projects($actor)->pluck('id')->all())],
                'document_kind' => 'required|in:order,resolution', 'effective_date_mode' => 'required|in:approval_date,specific_date',
                'effective_date' => 'nullable|required_if:effective_date_mode,specific_date|date_format:Y-m-d|after_or_equal:today',
                'approvers' => 'required|array|min:1|max:30', 'approvers.*' => 'required|integer|distinct',
            ])->validate();
            app(ApprovalParticipantPolicy::class)->assertChain((int) $data['project_id'], $data['approvers'], [$actor->id], true);
            abort_unless(Storage::disk('local')->exists($source) && Storage::disk('local')->exists($preview), 422, 'Исходный документ или предпросмотр отсутствует.');
            foreach ($this->signers((int) $data['project_id'], $actor->id)->whereIn('id', $data['approvers']) as $signer) {
                abort_unless(Storage::disk('local')->exists($signer->signature_path), 422, 'Файл подписи отсутствует.');
            }
            $r = ApprovalRequest::create([
                'submission_key' => $submissionKey, 'type' => 'management_document', 'document_kind' => $data['document_kind'],
                'effective_date_mode' => $data['effective_date_mode'], 'effective_date' => $data['effective_date_mode'] === 'specific_date' ? $data['effective_date'] : null,
                'project_id' => $data['project_id'], 'applicant_id' => $actor->id, 'name' => $data['title'], 'status' => 'pending_approval', 'expected_approval_level' => 1,
                'source_docx_path' => $source, 'source_sha256' => hash_file('sha256', Storage::disk('local')->path($source)),
                'preview_pdf_path' => $preview, 'document_version' => 1,
            ]);
            foreach ($data['approvers'] as $i => $id) {
                $r->approvals()->create(['approver_id' => $id, 'level' => $i + 1, 'status' => 'pending']);
            }
            app(ManagementDocumentService::class)->captureApplicantSignature($r);
            $r->attachments()->create(['file_type' => 'pdf', 'file_path' => $preview, 'original_name' => 'Предпросмотр документа.pdf', 'file_size' => Storage::disk('local')->size($preview)]);
            app(RequestCreationNotifier::class)->schedule($r);

            return $r;
        });
    }
}
