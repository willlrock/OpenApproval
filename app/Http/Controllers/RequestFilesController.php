<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Services\ApprovalRequestAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RequestFilesController extends Controller
{
    public function attachment(Request $http, int $id, int $fileId, ApprovalRequestAccess $access)
    {
        $request = $access->scope(ApprovalRequest::query(), $http->user())->findOrFail($id);
        $file = $request->attachments()->findOrFail($fileId);

        return app(\App\Services\TelegramFileCache::class)->download($file->file_path, $file->telegram_file_id, $file->display_name);
    }

    public function protocol(Request $httpRequest, int $id, ApprovalRequestAccess $access)
    {
        $request = $access->scope(ApprovalRequest::query(), $httpRequest->user())->findOrFail($id);
        abort_unless($request->pdf_url && ! $request->isManagementDocument(), 404);
        abort_unless(Storage::disk('local')->exists($request->pdf_url), 404);

        return Storage::disk('local')->download($request->pdf_url, "request_{$id}.pdf");
    }

    public function proof(Request $httpRequest, int $id, int $trancheId, ApprovalRequestAccess $access)
    {
        $request = $access->scope(ApprovalRequest::query(), $httpRequest->user())->findOrFail($id);
        $tranche = $request->tranches()->findOrFail($trancheId);
        abort_unless($tranche->payment_proof, 404);
        $local = str_starts_with($tranche->payment_proof, 'payment_proofs/');
        abort_unless($local || $tranche->payment_proof_telegram_id, 404);

        return app(\App\Services\TelegramFileCache::class)->download($local ? $tranche->payment_proof : null,
            $local ? null : $tranche->payment_proof_telegram_id, 'payment-proof-'.$tranche->id);
    }
}
