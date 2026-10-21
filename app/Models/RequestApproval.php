<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestApproval extends Model
{
    protected $fillable = [
        'approval_request_id',
        'approver_id',
        'level',
        'status',
        'comment',
        'signature_snapshot_path',
        'signature_sha256',
        'action_date',
        'notified_at',
    ];

    protected $casts = [
        'action_date' => 'datetime',
        'notified_at' => 'datetime',
    ];

    // Earlier levels are created with the route, but their action date activates this level.
    public static function stageStartedSql(): string
    {
        return "COALESCE((SELECT MAX(previous.action_date) FROM request_approvals previous WHERE previous.approval_request_id = request_approvals.approval_request_id AND previous.level < request_approvals.level AND previous.status = 'approved'), request_approvals.created_at)";
    }

    public function scopeStuck($query)
    {
        return $query->where('request_approvals.status', 'pending')
            ->whereRaw(self::stageStartedSql().' <= ?', [now()->subHours((int) config('deadlines.stuck_after_hours', 24))])
            ->whereHas('approvalRequest', fn ($r) => $r->where('status', 'pending_approval')
                ->whereColumn('expected_approval_level', 'request_approvals.level'));
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
