<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\RequestApproval;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class ManagementDocumentService
{
    private const SYSTEM_TOKENS = [
        '{{DOCUMENT_KIND}}',
        '{{DOCUMENT_NUMBER}}',
        '{{APPROVAL_DATE}}',
        '{{DOCUMENT_TITLE}}',
        '{{PROJECT}}',
        '{{EFFECTIVE_DATE}}',
    ];

    public function storeSource(string $contents, string $originalName): array
    {
        $safeStem = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'document';
        $relativePath = 'management_documents/source/'.now()->format('Y/m').'/'.
            Str::uuid()."_{$safeStem}.docx";

        if (! Storage::disk('local')->put($relativePath, $contents)) {
            throw new RuntimeException('Не удалось сохранить загруженный документ.');
        }

        return [
            'path' => $relativePath,
            'sha256' => hash('sha256', $contents),
        ];
    }

    /**
     * Build a frozen PDF preview. Bot-owned values replace protected template
     * tokens, while input highlighting is removed from the printable copy.
     */
    public function createPreview(string $sourceRelativePath, array $systemValues): string
    {
        $sourcePath = Storage::disk('local')->path($sourceRelativePath);
        if (! is_file($sourcePath)) {
            throw new RuntimeException('Исходный Word-файл не найден.');
        }

        $workingDirectory = storage_path('app/private/management_documents/conversion/'.Str::uuid());
        File::ensureDirectoryExists($workingDirectory);

        try {
            $printablePath = $workingDirectory.DIRECTORY_SEPARATOR.'document.docx';
            $this->preparePrintableDocx($sourcePath, $printablePath, $systemValues);
            $convertedPath = $this->convertDocxToPdf($printablePath, $workingDirectory);

            $previewRelativePath = 'management_documents/previews/'.now()->format('Y/m').'/'.Str::uuid().'.pdf';
            if (! Storage::disk('local')->put($previewRelativePath, file_get_contents($convertedPath))) {
                throw new RuntimeException('Не удалось сохранить предпросмотр документа.');
            }

            return $previewRelativePath;
        } finally {
            File::deleteDirectory($workingDirectory);
        }
    }

    public function previewSystemValues(
        string $kind,
        string $title,
        string $projectName,
        string $effectiveDateMode,
        ?CarbonInterface $effectiveDate = null
    ): array {
        return [
            '{{DOCUMENT_KIND}}' => mb_strtoupper($this->kindLabel($kind)),
            '{{DOCUMENT_NUMBER}}' => 'будет присвоен после утверждения',
            '{{APPROVAL_DATE}}' => 'будет определена автоматически',
            '{{DOCUMENT_TITLE}}' => $title,
            '{{PROJECT}}' => $projectName,
            '{{EFFECTIVE_DATE}}' => $this->effectiveDateLabel($effectiveDateMode, $effectiveDate),
        ];
    }

    public function captureApplicantSignature(ApprovalRequest $request): void
    {
        $request->loadMissing('applicant');
        $snapshot = $this->copyUserSignature(
            $request->applicant,
            "management_documents/signatures/request_{$request->id}/applicant_".Str::uuid()
        );

        $request->update([
            'applicant_signature_snapshot_path' => $snapshot['path'],
            'applicant_signature_sha256' => $snapshot['sha256'],
        ]);
    }

    public function captureSignature(RequestApproval $approval): void
    {
        $approval->loadMissing('approver');
        $snapshot = $this->copyUserSignature(
            $approval->approver,
            "management_documents/signatures/request_{$approval->approval_request_id}/".
                "level_{$approval->level}_".Str::uuid()
        );

        $approval->update([
            'signature_snapshot_path' => $snapshot['path'],
            'signature_sha256' => $snapshot['sha256'],
        ]);
    }

    public function finalize(ApprovalRequest $request): string
    {
        $request->loadMissing(['project', 'applicant', 'approvals.approver']);

        if (! $request->isManagementDocument() || ! $request->source_docx_path) {
            throw new RuntimeException('Не найден зафиксированный исходный документ.');
        }
        if (! $request->applicant_signature_snapshot_path) {
            throw new RuntimeException('Не зафиксирована подпись инициатора.');
        }
        $this->assertPrivateFileHash($request->source_docx_path, $request->source_sha256, 'исходного документа');
        $this->assertPrivateFileHash(
            $request->applicant_signature_snapshot_path,
            $request->applicant_signature_sha256,
            'подписи инициатора'
        );

        foreach ($request->approvals as $approval) {
            if ($approval->status !== 'approved' || ! $approval->signature_snapshot_path) {
                throw new RuntimeException('Не все подписи документа зафиксированы.');
            }
            $this->assertPrivateFileHash(
                $approval->signature_snapshot_path,
                $approval->signature_sha256,
                "подписи уровня {$approval->level}"
            );
        }

        $documentNumber = $request->document_number ?: $this->makeDocumentNumber($request);
        $finalizedAt = now();
        $systemValues = $this->finalSystemValues($request, $documentNumber, $finalizedAt);
        $qrData = $this->generateQrCode($request);
        $signatures = $this->collectSnapshotSignatures($request);
        $applicantSignature = $this->snapshotDataUri($request->applicant_signature_snapshot_path, $request->applicant_signature_sha256, $request->applicant?->signature_path);

        $approvalSheet = Pdf::loadView('pdf.management-document-signatures', compact(
            'request',
            'documentNumber',
            'finalizedAt',
            'qrData',
            'signatures',
            'applicantSignature'
        ))->setPaper('a4');

        $workingDirectory = storage_path('app/private/management_documents/finalization/'.Str::uuid());
        File::ensureDirectoryExists($workingDirectory);
        $sheetPath = $workingDirectory.DIRECTORY_SEPARATOR.'approval-sheet.pdf';
        file_put_contents($sheetPath, $approvalSheet->output());

        $sourcePath = Storage::disk('local')->path($request->source_docx_path);
        $finalRelativePath = "management_documents/final/{$request->id}/v{$request->document_version}_final.pdf";
        $finalPath = Storage::disk('local')->path($finalRelativePath);
        File::ensureDirectoryExists(dirname($finalPath));

        try {
            $printablePath = $workingDirectory.DIRECTORY_SEPARATOR.'approved-document.docx';
            $this->preparePrintableDocx($sourcePath, $printablePath, $systemValues);
            $bodyPath = $this->convertDocxToPdf($printablePath, $workingDirectory);

            $process = new Process([
                config('management_documents.pdfunite_binary'),
                $bodyPath,
                $sheetPath,
                $finalPath,
            ]);
            $process->setTimeout(config('management_documents.conversion_timeout'));
            $process->run();

            if (! $process->isSuccessful() || ! is_file($finalPath) || filesize($finalPath) === 0) {
                throw new RuntimeException('Не удалось объединить документ с листом подписей. '.trim($process->getErrorOutput()));
            }
        } finally {
            File::deleteDirectory($workingDirectory);
        }

        $request->update([
            'document_number' => $documentNumber,
            'finalized_at' => $finalizedAt,
            'pdf_url' => $finalRelativePath,
            'status' => 'approved',
        ]);

        return $finalRelativePath;
    }

    public function templatePath(?string $kind): string
    {
        return config('management_documents.template_paths.'.($kind === 'resolution' ? 'resolution' : 'order'));
    }

    public function deleteFiles(ApprovalRequest $request): void
    {
        if (! $request->isManagementDocument()) {
            return;
        }

        $privateFiles = array_values(array_filter([
            $request->source_docx_path,
            $request->preview_pdf_path,
            $request->applicant_signature_snapshot_path,
            ...$request->approvals()->pluck('signature_snapshot_path')->all(),
        ]));
        if ($privateFiles) {
            Storage::disk('local')->delete($privateFiles);
        }
        if ($request->pdf_url) {
            Storage::disk('local')->delete($request->pdf_url);
        }
    }

    public function deletePrivatePaths(array $paths): void
    {
        $paths = array_values(array_filter($paths, fn ($path) => is_string($path) && $path !== ''));
        if ($paths) {
            Storage::disk('local')->delete($paths);
        }
    }

    public function privatePath(string $relativePath): string
    {
        return Storage::disk('local')->path($relativePath);
    }

    public function kindLabel(?string $kind): string
    {
        return match ($kind) {
            'resolution' => 'Постановление руководства',
            default => 'Приказ руководства',
        };
    }

    public function effectiveDateLabel(?string $mode, ?CarbonInterface $date = null, ?CarbonInterface $approvalDate = null): string
    {
        if ($mode === 'specific_date' && $date) {
            return 'С '.$date->format('d.m.Y');
        }

        return $approvalDate
            ? 'Со дня утверждения ('.$approvalDate->format('d.m.Y').')'
            : 'Со дня утверждения';
    }

    private function finalSystemValues(ApprovalRequest $request, string $documentNumber, CarbonInterface $finalizedAt): array
    {
        return [
            '{{DOCUMENT_KIND}}' => mb_strtoupper($this->kindLabel($request->document_kind)),
            '{{DOCUMENT_NUMBER}}' => $documentNumber,
            '{{APPROVAL_DATE}}' => $finalizedAt->format('d.m.Y'),
            '{{DOCUMENT_TITLE}}' => $request->name,
            '{{PROJECT}}' => $request->project?->name ?? '—',
            '{{EFFECTIVE_DATE}}' => $this->effectiveDateLabel(
                $request->effective_date_mode,
                $request->effective_date,
                $finalizedAt
            ),
        ];
    }

    private function preparePrintableDocx(string $sourcePath, string $outputPath, array $systemValues): void
    {
        foreach (self::SYSTEM_TOKENS as $token) {
            if (! array_key_exists($token, $systemValues)) {
                throw new RuntimeException("Не задано системное поле шаблона {$token}.");
            }
        }

        if (! copy($sourcePath, $outputPath)) {
            throw new RuntimeException('Не удалось подготовить рабочую копию Word-файла.');
        }

        $zip = new ZipArchive;
        if ($zip->open($outputPath) !== true) {
            throw new RuntimeException('Word-файл повреждён или имеет неверный формат.');
        }

        try {
            $xmlEntries = [];
            $allXml = '';
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (! preg_match('#^word/(document|header\d+|footer\d+)\.xml$#', $name)) {
                    continue;
                }
                $xml = $zip->getFromIndex($index);
                if ($xml === false) {
                    continue;
                }
                $xmlEntries[$name] = $xml;
                $allXml .= $xml;
            }

            foreach (self::SYSTEM_TOKENS as $token) {
                if (! str_contains($allXml, $token)) {
                    throw new RuntimeException('Используйте выданный ботом шаблон: отсутствует системное поле '.$token.'.');
                }
            }
            if (str_contains($allXml, '[[') || str_contains($allXml, ']]')) {
                throw new RuntimeException('В шаблоне остались незаполненные поля. Замените весь текст в синих подсказках.');
            }

            foreach ($xmlEntries as $name => $xml) {
                foreach ($systemValues as $token => $value) {
                    $xml = str_replace($token, htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $xml);
                }
                $xml = preg_replace('/<w:highlight\b[^>]*\/>/u', '', $xml) ?? $xml;
                $zip->addFromString($name, $xml);
            }
        } finally {
            $zip->close();
        }
    }

    private function convertDocxToPdf(string $docxPath, string $workingDirectory): string
    {
        $profileUri = 'file:///'.str_replace('\\', '/', $workingDirectory.DIRECTORY_SEPARATOR.'lo-profile-'.Str::uuid());
        $process = new Process([
            config('management_documents.soffice_binary'),
            '--headless',
            "-env:UserInstallation={$profileUri}",
            '--convert-to',
            'pdf',
            '--outdir',
            $workingDirectory,
            $docxPath,
        ]);
        $process->setTimeout(config('management_documents.conversion_timeout'));
        $process->run();

        $convertedPath = $workingDirectory.DIRECTORY_SEPARATOR.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';
        if (! $process->isSuccessful() || ! is_file($convertedPath)) {
            throw new RuntimeException('Не удалось преобразовать Word-файл в PDF. '.trim($process->getErrorOutput()));
        }

        return $convertedPath;
    }

    private function copyUserSignature(?User $user, string $snapshotStem): array
    {
        $signaturePath = $user?->signature_path;
        if (! $signaturePath || ! Storage::disk('local')->exists($signaturePath)) {
            throw new RuntimeException('У пользователя отсутствует сохранённая подпись.');
        }

        $contents = Storage::disk('local')->get($signaturePath);
        $extension = strtolower(pathinfo($signaturePath, PATHINFO_EXTENSION)) ?: 'png';
        $snapshotPath = "{$snapshotStem}.{$extension}";

        if (! Storage::disk('local')->put($snapshotPath, $contents)) {
            throw new RuntimeException('Не удалось зафиксировать подпись пользователя.');
        }

        return [
            'path' => $snapshotPath,
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function makeDocumentNumber(ApprovalRequest $request): string
    {
        $prefix = $request->document_kind === 'resolution' ? 'ПС' : 'ПР';

        return sprintf('%s-%s-%06d', $prefix, now()->format('Y'), $request->id);
    }

    private function generateQrCode(ApprovalRequest $request): string
    {
        $verificationUrl = url("/verify/{$request->id}/".sha1($request->id.$request->created_at.config('app.key')));
        $renderer = new ImageRenderer(new RendererStyle(180), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($verificationUrl);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function collectSnapshotSignatures(ApprovalRequest $request): array
    {
        $result = [];

        foreach ($request->approvals->sortBy('level') as $approval) {
            $path = $approval->signature_snapshot_path;
            if (! $path || ! Storage::disk('local')->exists($path)) {
                throw new RuntimeException("Не найден снимок подписи уровня {$approval->level}.");
            }

            $result[$approval->id] = $this->snapshotDataUri($path, $approval->signature_sha256, $approval->approver?->signature_path);
        }

        return $result;
    }

    private function snapshotDataUri(string $path, ?string $expectedHash, ?string $profilePath): string
    {
        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Не найден зафиксированный снимок подписи.');
        }

        return app(PdfSignatureRenderer::class)->dataUri($path, $expectedHash, $profilePath)
            ?? throw new RuntimeException('Не найден зафиксированный снимок подписи.');
    }

    private function assertPrivateFileHash(?string $path, ?string $expectedHash, string $label): void
    {
        if (! $path || ! $expectedHash || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException("Не удалось проверить целостность {$label}.");
        }

        $actualHash = hash('sha256', Storage::disk('local')->get($path));
        if (! hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException("Нарушена целостность {$label}.");
        }
    }
}
