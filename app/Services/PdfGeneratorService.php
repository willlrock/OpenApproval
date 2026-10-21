<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;

class PdfGeneratorService
{
    public function generateProtocol(ApprovalRequest $request): string
    {
        $request->load(['project', 'applicant', 'approvals.approver', 'attachments', 'items', 'tranches']);

        return $this->generateFromBlade($request);
    }

    protected function generateFromBlade(ApprovalRequest $request): string
    {
        $qrData     = $this->generateQrCode($request);
        $signatures = $this->collectSignatures($request);

        $pdf = Pdf::loadView('pdf.request', compact('request', 'qrData', 'signatures'));

        $filename = "protocols/req_{$request->id}_" . \Illuminate\Support\Str::uuid() . ".pdf";
        if (! Storage::disk('local')->put($filename, $pdf->output())) {
            throw new \RuntimeException('Не удалось сохранить протокол.');
        }

        $request->update(['pdf_url' => $filename]);

        return $filename;
    }

    protected function generateQrCode(ApprovalRequest $request): string
    {
        $verificationUrl = url("/verify/{$request->id}/" . sha1($request->id . $request->created_at . config('app.key')));

        $renderer = new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd());
        $writer   = new Writer($renderer);
        $svg      = $writer->writeString($verificationUrl);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    protected function collectSignatures(ApprovalRequest $request): array
    {
        $signatures = [];

        foreach ($request->approvals->sortBy('level') as $approval) {
            $signatureBase64 = null;

            if ($approval->status === 'approved' && $approval->signature_snapshot_path) {
                $signatureBase64 = app(PdfSignatureRenderer::class)->dataUri(
                    $approval->signature_snapshot_path,
                    $approval->signature_sha256,
                    $approval->approver?->signature_path,
                    'Подпись протокола'
                );
            }

            $signatures[$approval->id] = $signatureBase64;
        }

        return $signatures;
    }
}
