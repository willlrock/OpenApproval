<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PrivatizeRequestFiles extends Command
{
    protected $signature = 'requests:privatize-files';

    protected $description = 'Move legacy request files to private storage, verifying each copy before removing its public copy';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        foreach (['protocols', 'payment_proofs', 'attachments', 'exports', 'signatures'] as $directory) {
            foreach ($public->allFiles($directory) as $path) {
                $contents = $public->get($path);
                if ($private->exists($path) && ! hash_equals(hash('sha256', $contents), hash('sha256', $private->get($path)))) {
                    $this->error("Private file differs: {$path}");

                    return self::FAILURE;
                }
                if (! $private->exists($path) && ! $private->put($path, $contents)) {
                    return self::FAILURE;
                }
                if (! hash_equals(hash('sha256', $contents), hash('sha256', $private->get($path)))) {
                    return self::FAILURE;
                }
                if (! $public->delete($path)) {
                    return self::FAILURE;
                }
            }
        }
        // Existing protocols stay untouched. Freeze the currently available legacy
        // signatures once so later payment updates cannot substitute a new image.
        \App\Models\RequestApproval::where('status', 'approved')->whereNull('signature_snapshot_path')
            ->whereHas('approvalRequest', fn ($q) => $q->withTrashed()->where('type', '!=', 'management_document'))
            ->with('approver')->chunkById(100, function ($approvals) {
                foreach ($approvals as $approval) {
                    $signature = $approval->approver?->signature_path;
                    if ($signature && Storage::disk('local')->exists($signature)) {
                        app(\App\Services\ManagementDocumentService::class)->captureSignature($approval);
                    }
                }
            });
        \App\Models\ApprovalRequest::withTrashed()->where('type', '!=', 'management_document')
            ->whereNull('applicant_signature_snapshot_path')->with('applicant')->chunkById(100, function ($requests) {
                foreach ($requests as $request) {
                    $signature = $request->applicant?->signature_path;
                    if ($signature && Storage::disk('local')->exists($signature)) {
                        app(\App\Services\ManagementDocumentService::class)->captureApplicantSignature($request);
                    }
                }
            });
        $this->info('Request files are private.');

        return self::SUCCESS;
    }
}
