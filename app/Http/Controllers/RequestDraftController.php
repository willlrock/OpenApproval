<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\RequestDraft;
use App\Models\RequestDraftFile;
use App\Models\Supplier;
use App\Services\ApprovalRequestCreation;
use App\Services\PdfImport\PaymentRequestPdfImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class RequestDraftController extends Controller
{
    public function index(Request $http)
    {
        return Inertia::render('RequestDrafts/Index', [
            'drafts' => RequestDraft::where('user_id', $http->user()->id)->whereNull('submitted_request_id')->latest('updated_at')->get()->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->payload['title'] ?? $d->payload['items'][0]['name'] ?? 'Новая заявка', 'type' => $d->payload['type'] ?? 'approval', 'version' => $d->version, 'updated_at' => $d->updated_at->format('d.m.Y H:i'),
            ]),
            'submitted' => ApprovalRequest::where('applicant_id', $http->user()->id)->latest()->paginate(15)->through(fn ($r) => $r->only(['id', 'name', 'type', 'status', 'total_price', 'currency'])),
        ]);
    }

    public function destroy(Request $http, string $id)
    {
        DB::transaction(function () use ($http, $id) {
            $draft = $this->locked($http, $id);
            $paths = $draft->files()->pluck('path')->all();
            $draft->files()->delete();
            $draft->delete();
            $this->deleteUnreferencedFiles($paths);
        });

        return to_route('request-drafts.index')->with('success', 'Черновик удалён.');
    }

    public function revise(Request $http, int $id)
    {
        $draft = DB::transaction(function () use ($http, $id) {
            $source = ApprovalRequest::where('applicant_id', $http->user()->id)->lockForUpdate()->findOrFail($id);
            abort_unless(in_array($source->status, ['rejected', 'revision_requested']) && in_array($source->type, ['approval', 'supply']) && ! $source->supply_applicant_id && ! $source->tranches()->where('status', 'paid')->exists(), 409);
            $existing = RequestDraft::where('user_id', $http->user()->id)->where('source_request_id', $id)->where('is_revision', true)->where('source_revision', $source->revision)->whereNull('submitted_request_id')->first();
            if ($existing) {
                return $existing;
            }

            $draft = RequestDraft::create(['id' => (string) Str::uuid(), 'user_id' => $http->user()->id, 'source_request_id' => $id, 'is_revision' => true, 'source_revision' => $source->revision, 'payload' => [
                ...$source->only(['type', 'project_id', 'currency', 'transaction_type', 'supplier_id', 'supplier_name', 'supplier_inn']),
                'deadline_date' => $source->deadline_date?->toDateString(),
                'items' => $source->items->map->only(['name', 'description', 'quantity', 'price_per_unit'])->all(),
                'approvers' => $source->approvals->sortBy('level')->pluck('approver_id')->values()->all(),
                'tranches' => $source->tranches->map(fn ($t) => ['amount' => $t->amount, 'due_date' => $t->due_date?->toDateString(), 'comment' => $t->comment])->all(),
            ]]);
            $this->initializeRevisionFiles($draft);

            return $draft;
        });

        return to_route('request-drafts.edit', $draft->id);
    }

    public function store(Request $http)
    {
        $data = $http->validate(['type' => 'sometimes|required|in:approval,supply']);
        $draft = RequestDraft::create(['id' => (string) Str::uuid(), 'user_id' => $http->user()->id, 'payload' => [
            'type' => $data['type'] ?? 'approval', 'project_id' => '', 'currency' => 'UZS', 'transaction_type' => 'bank',
            'supplier_id' => '', 'supplier_name' => '', 'supplier_inn' => '', 'deadline_date' => '',
            'items' => [['name' => '', 'description' => '', 'quantity' => '1', 'price_per_unit' => '']], 'approvers' => [], 'tranches' => [],
        ]]);

        return to_route('request-drafts.edit', $draft->id);
    }

    public function edit(Request $http, string $id, ApprovalRequestCreation $creation)
    {
        $draft = DB::transaction(function () use ($http, $id) {
            $draft = RequestDraft::where('user_id', $http->user()->id)->lockForUpdate()->findOrFail($id);
            $this->initializeRevisionFiles($draft);

            return $draft;
        });
        if ($draft->submitted_request_id) {
            return to_route('requests.detail', $draft->submitted_request_id);
        }
        if (($draft->payload['type'] ?? '') === 'management_document') {
            return app(ManagementDraftController::class)->edit($http, $id);
        }
        $projects = $creation->projects($http->user());

        return Inertia::render('RequestDrafts/Edit', [
            'draft' => $this->present($draft), 'projects' => $projects->map->only(['id', 'name']),
            'approversByProject' => $projects->mapWithKeys(fn ($p) => [$p->id => $creation->approvers($p->id, $http->user()->id)->map->only(['id', 'full_name', 'title'])]),
            'supplyApproversByProject' => $projects->mapWithKeys(fn ($p) => [$p->id => $creation->supplyApprovers($p->id, $http->user()->id)->map->only(['id', 'full_name', 'title'])]),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'inn']),
        ]);
    }

    public function update(Request $http, string $id)
    {
        $data = $http->validate(['version' => 'required|integer|min:1', 'payload' => 'required|array',
            'payload.items' => 'sometimes|array|max:100', 'payload.approvers' => 'sometimes|array|max:30', 'payload.tranches' => 'sometimes|array|max:50']);
        $data['payload'] = \Illuminate\Support\Arr::only($http->input('payload'), ['type', 'project_id', 'currency', 'transaction_type', 'supplier_id', 'supplier_name', 'supplier_inn', 'deadline_date', 'items', 'approvers', 'tranches', 'title', 'document_kind', 'effective_date_mode', 'effective_date', 'source_file_id']);
        abort_if(strlen(json_encode($data['payload'])) > 150000, 422, 'Черновик слишком большой.');

        return DB::transaction(function () use ($http, $id, $data) {
            $draft = $this->locked($http, $id);
            $draft->update(['payload' => $data['payload'], 'version' => $draft->version + 1]);

            return response()->json($this->present($draft));
        });
    }

    public function upload(Request $http, string $id)
    {
        $http->validate(['version' => 'required|integer|min:1', 'file' => 'required|file|max:15360|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx']);
        $path = null;
        try {
            return DB::transaction(function () use ($http, $id, &$path) {
                $draft = $this->locked($http, $id);
                abort_if($draft->files()->count() >= 20, 422, 'Не больше 20 вложений.');
                $file = $http->file('file');
                try {
                    $path = $file->store('attachments/drafts/'.$draft->id, 'local');
                    if (! $path || ! Storage::disk('local')->exists($path)) {
                        throw \League\Flysystem\UnableToWriteFile::atLocation('attachments/drafts/'.$draft->id);
                    }
                } catch (\League\Flysystem\FilesystemException) {
                    Log::warning('Draft attachment storage failed', ['draft_id' => $draft->id]);
                    abort(503, 'Не удалось сохранить вложение. Повторите загрузку. Если ошибка повторится, обратитесь к администратору.');
                }
                $draft->files()->create(['name' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'path' => $path, 'file_type' => $file->extension()]);
                $draft->update(['version' => $draft->version + 1]);

                return response()->json($this->present($draft));
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    public function removeFile(Request $http, string $id, int $fileId)
    {
        return DB::transaction(function () use ($http, $id, $fileId) {
            $draft = $this->locked($http, $id);
            $file = $draft->files()->findOrFail($fileId);
            $path = $file->path;
            $file->delete();
            $draft->update(['version' => $draft->version + 1]);
            $this->deleteUnreferencedFiles([$path]);

            return response()->json($this->present($draft));
        });
    }

    public function file(Request $http, string $id, int $fileId)
    {
        $file = $this->owned($http, $id)->files()->findOrFail($fileId);

        return app(\App\Services\TelegramFileCache::class)->download($file->path, $file->telegram_file_id, $file->name);
    }

    public function analyze(Request $http, string $id, int $fileId, PaymentRequestPdfImportService $import, ApprovalRequestCreation $creation)
    {
        $file = $this->owned($http, $id)->files()->findOrFail($fileId);
        abort_unless($file->file_type === 'pdf', 422);
        try {
            $result = $import->analyze(Storage::disk('local')->path($file->path), $creation->projects($http->user()));
        } catch (\Throwable) {
            return response()->json(['message' => 'Не удалось прочитать PDF. Файл сохранён; заполните поля вручную.'], 422);
        }
        $parsed = $result['parsed'];
        $match = $result['match'];

        return response()->json(['suggestion' => [
            'project_id' => $match['project']?->id ?? null,
            'supplier_name' => $match['supplier_party']['name'] ?? null,
            'supplier_inn' => $match['supplier_party']['inn'] ?? null,
            'currency' => $parsed->currency,
            'amount' => $parsed->suggestedPaymentAmount ?? $parsed->totalAmount,
            'name' => trim(($parsed->documentType ?? 'Оплата по PDF').' '.($parsed->documentNumber ?? '')),
        ], 'message' => 'Проверьте распознанные данные. Импорт заменит позиции одной суммой из документа.']);
    }

    public function submit(Request $http, string $id, ApprovalRequestCreation $creation)
    {
        $http->validate(['version' => 'required|integer|min:1']);
        $requestId = DB::transaction(function () use ($http, $id, $creation) {
            app(\App\Services\ApprovalParticipantPolicy::class)->lockWorkflow();
            $draft = RequestDraft::where('user_id', $http->user()->id)->lockForUpdate()->findOrFail($id);
            if ($draft->submitted_request_id) {
                return $draft->submitted_request_id;
            }
            abort_unless($draft->version === (int) $http->input('version'), 409, 'Черновик изменён в другой вкладке. Обновите страницу.');
            $this->initializeRevisionFiles($draft);
            if ($draft->is_revision) {
                $source = ApprovalRequest::where('applicant_id', $http->user()->id)->lockForUpdate()->findOrFail($draft->source_request_id);
                abort_unless($source->revision === $draft->source_revision, 409, 'Заявка уже исправлена. Откройте её заново.');
            }
            $request = ($draft->payload['type'] ?? '') === 'management_document'
                ? app(\App\Services\ManagementDocumentCreation::class)->fromDraft($http->user(), $draft)
                : $creation->create($http->user(), $draft->payload, $draft->files->map(fn ($f) => ['file_type' => $f->file_type, 'file_path' => $f->path ?: null, 'telegram_file_id' => $f->telegram_file_id, 'original_name' => $f->name, 'file_size' => $f->path && Storage::disk('local')->exists($f->path) ? Storage::disk('local')->size($f->path) : null])->all(), $draft->is_revision ? $draft->source_request_id : null, $draft->is_revision ? $draft->source_revision : null, (bool) $draft->is_revision);
            $draft->update(['submitted_request_id' => $request->id, 'version' => $draft->version + 1]);

            return $request->id;
        });

        return response()->json(['url' => route('requests.detail', $requestId)]);
    }

    public function show(Request $http, int $id)
    {
        ApprovalRequest::where('applicant_id', $http->user()->id)->findOrFail($id);

        return to_route('requests.detail', $id);
    }

    public function submittedFile(Request $http, int $id, int $fileId)
    {
        $request = ApprovalRequest::where('applicant_id', $http->user()->id)->findOrFail($id);
        $file = $request->attachments()->findOrFail($fileId);

        return app(\App\Services\TelegramFileCache::class)->download($file->file_path, $file->telegram_file_id, $file->display_name);
    }

    public function copy(Request $http, int $id)
    {
        $source = ApprovalRequest::where('applicant_id', $http->user()->id)->whereIn('type', ['approval', 'supply'])->with(['items', 'approvals', 'attachments', 'tranches'])->findOrFail($id);
        $draft = DB::transaction(function () use ($source, $http) {
            $draft = RequestDraft::create(['id' => (string) Str::uuid(), 'user_id' => $http->user()->id, 'source_request_id' => $source->id, 'payload' => [
                ...$source->only(['type', 'project_id', 'currency', 'transaction_type', 'supplier_id', 'supplier_name', 'supplier_inn']),
                'deadline_date' => '', 'items' => $source->items->map->only(['name', 'description', 'quantity', 'price_per_unit'])->all(),
                'approvers' => $source->approvals->sortBy('level')->pluck('approver_id')->values()->all(), 'tranches' => [],
            ]]);
            foreach ($source->attachments as $file) {
                if ($file->telegram_file_id || ($file->file_path && Storage::disk('local')->exists($file->file_path))) {
                    $draft->files()->create(['name' => $file->display_name, 'path' => $file->file_path ?: '',
                        'file_type' => $file->file_type, 'telegram_file_id' => $file->telegram_file_id]);
                }
            }

            return $draft;
        });

        return to_route('request-drafts.edit', $draft->id);
    }

    private function initializeRevisionFiles(RequestDraft $draft): void
    {
        if (! $draft->is_revision || $draft->submitted_request_id || $draft->revision_files_initialized) {
            return;
        }
        $source = ApprovalRequest::where('applicant_id', $draft->user_id)->lockForUpdate()->findOrFail($draft->source_request_id);
        abort_unless($source->revision === $draft->source_revision, 409, 'Исходная версия уже изменилась.');
        foreach ($source->attachments as $file) {
            $draft->files()->create(['name' => $file->display_name,
                'path' => $file->file_path ?: '', 'file_type' => $file->file_type, 'telegram_file_id' => $file->telegram_file_id]);
        }
        $draft->update(['revision_files_initialized' => true]);
        $draft->unsetRelation('files');
    }

    private function owned(Request $http, string $id): RequestDraft
    {
        return RequestDraft::where('user_id', $http->user()->id)->findOrFail($id);
    }

    private function deleteUnreferencedFiles(array $paths): void
    {
        DB::afterCommit(function () use ($paths) {
            foreach (array_unique(array_filter($paths)) as $path) {
                // Query attachment rows directly: archived rows back the revision history,
                // and attachments of soft-deleted requests must remain restorable.
                if (RequestDraftFile::where('path', $path)->exists()
                    || \App\Models\RequestAttachment::where('file_path', $path)->exists()
                    || ApprovalRequest::withTrashed()->where('source_docx_path', $path)->exists()) {
                    continue;
                }
                try {
                    if (! Storage::disk('local')->delete($path)) {
                        Log::warning('Draft attachment cleanup failed', ['path' => $path]);
                    }
                } catch (\League\Flysystem\FilesystemException) {
                    Log::warning('Draft attachment cleanup failed', ['path' => $path]);
                }
            }
        });
    }

    private function locked(Request $http, string $id): RequestDraft
    {
        $http->validate(['version' => 'required|integer|min:1']);
        $draft = RequestDraft::where('user_id', $http->user()->id)->lockForUpdate()->findOrFail($id);
        abort_if($draft->submitted_request_id || $draft->version !== (int) $http->input('version'), 409, 'Черновик уже отправлен или изменён в другой вкладке. Обновите страницу.');

        return $draft;
    }

    private function present(RequestDraft $draft): array
    {
        return ['id' => $draft->id, 'payload' => $draft->payload, 'version' => $draft->version, 'updated_at' => $draft->updated_at->toIso8601String(),
            'files' => $draft->files()->get()->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'file_type' => $f->file_type, 'url' => $f->path || $f->telegram_file_id ? route('request-drafts.file', [$draft->id, $f->id]) : null]),
            'source_id' => $draft->source_request_id, 'is_revision' => $draft->is_revision];
    }
}
