<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use Illuminate\Support\Facades\DB;

class RequestHistory
{
    /** Caller holds the parent request lock. */
    public function archive(ApprovalRequest $request): void
    {
        $snapshot = $request->load(['items', 'approvals' => fn ($q) => $q->orderBy('level'), 'approvals.approver', 'tranches', 'attachments', 'project'])->toArray();
        DB::table('request_revisions')->insert([
            'approval_request_id' => $request->id, 'version' => $request->revision,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
