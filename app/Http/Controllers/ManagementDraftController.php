<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\RequestDraft;
use App\Services\ApprovalRequestCreation;
use App\Services\ManagementDocumentCreation;
use App\Services\ManagementDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ManagementDraftController extends Controller
{
    public function store(Request $http)
    {
        $draft = RequestDraft::create(['id' => (string) Str::uuid(), 'user_id' => $http->user()->id, 'payload' => ['type' => 'management_document', 'title' => '', 'project_id' => '', 'document_kind' => 'order', 'effective_date_mode' => 'approval_date', 'effective_date' => '', 'approvers' => [], 'source_file_id' => '']]);

        return to_route('request-drafts.edit', $draft->id);
    }

    public function create()
    {
        return Inertia::render('RequestDrafts/ManagementStart');
    }

    public function edit(Request $http, string $id)
    {
        $d = RequestDraft::where('user_id', $http->user()->id)->findOrFail($id);
        $projects = app(ApprovalRequestCreation::class)->projects($http->user());

        return Inertia::render('RequestDrafts/Management', ['draft' => $d->only(['id', 'version', 'payload', 'preview_version']), 'files' => $d->files()->get(['id', 'name', 'file_type']), 'projects' => $projects->map->only(['id', 'name']), 'signers' => $projects->mapWithKeys(fn ($p) => [$p->id => app(ManagementDocumentCreation::class)->signers($p->id, $http->user()->id)->map->only(['id', 'full_name', 'title'])])]);
    }

    public function preview(Request $http, string $id)
    {
        $http->validate(['version' => 'required|integer']);

        return DB::transaction(function () use ($http, $id) {
            $d = RequestDraft::where('user_id', $http->user()->id)->lockForUpdate()->findOrFail($id);
            abort_unless(! $d->submitted_request_id && $d->version === (int) $http->version, 409);
            $data = app(ManagementDocumentCreation::class)->validate($http->user(), $d);
            $service = app(ManagementDocumentService::class);
            try {
                $path = $service->createPreview($data['source_path'], $service->previewSystemValues($data['document_kind'], $data['title'], \App\Models\Project::findOrFail($data['project_id'])->name, $data['effective_date_mode'], $data['effective_date'] ? \Carbon\Carbon::parse($data['effective_date']) : null));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Web document preview failed', ['draft_id' => $id, 'error' => $e->getMessage()]);
                throw \Illuminate\Validation\ValidationException::withMessages(['preview' => 'Не удалось преобразовать DOCX. Проверьте файл; если ошибка повторится, администратору нужно проверить конвертер документов. Черновик сохранён.']);
            }
            $d->update(['management_preview_path' => $path, 'preview_version' => $d->version]);

            return response()->json(['url' => route('management-drafts.preview-file', $id)]);
        });
    }

    public function previewFile(Request $http, string $id)
    {
        $d = RequestDraft::where('user_id', $http->user()->id)->findOrFail($id);
        abort_unless($d->management_preview_path && $d->preview_version === $d->version && Storage::disk('local')->exists($d->management_preview_path), 404);

        return response()->file(Storage::disk('local')->path($d->management_preview_path), ['Content-Type' => 'application/pdf']);
    }

    public function template(string $kind)
    {
        abort_unless(in_array($kind, ['order', 'resolution']), 404);

        return response()->download(app(ManagementDocumentService::class)->templatePath($kind));
    }

    public function document(Request $http, int $id)
    {
        $r = ApprovalRequest::where('applicant_id', $http->user()->id)->where('type', 'management_document')->findOrFail($id);
        $path = $r->pdf_url ?: $r->preview_pdf_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }
}
