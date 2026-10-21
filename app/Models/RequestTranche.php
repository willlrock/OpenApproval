<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestTranche extends Model
{
    protected $fillable = [
        'approval_request_id',
        'amount',
        'percent',
        'due_date',
        'comment',
        'failure_reason',
        'status',
        'payment_proof',
        'payment_proof_telegram_id',
        'paid_at',
        'promised_date',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'paid_at' => 'datetime',
        'promised_date' => 'date',
        'amount' => 'decimal:2',
        'percent' => 'decimal:2',
    ];

    public function scopeUnpaid($query)
    {
        return $query->where('request_tranches.status', '!=', 'paid')
            ->whereHas('approvalRequest', fn ($r) => $r->financial()->where('status', 'approved'));
    }

    public static function effectiveDeadlineSql(): string
    {
        return 'COALESCE(request_tranches.due_date, (SELECT deadline_date FROM approval_requests WHERE approval_requests.id = request_tranches.approval_request_id))';
    }

    public function scopeOverdue($query)
    {
        return $query->unpaid()->whereRaw('DATE('.self::effectiveDeadlineSql().') < ?', [today()->toDateString()]);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }
}
