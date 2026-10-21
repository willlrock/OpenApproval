<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalRequest extends Model
{
    // Удаление заявки обратимо: строка скрывается из всех выборок, но
    // связанные позиции/согласования/вложения остаются на месте, поэтому
    // заявку можно восстановить целиком.
    use SoftDeletes;

    protected $attributes = ['revision' => 1];

    protected $fillable = [
        'submission_key',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'revision',
        'route_unavailable',
        'route_draft',
        'route_recovery_sequence',
        'approval_paused_at',
        'type',
        'document_kind',
        'effective_date_mode',
        'effective_date',
        'project_id',
        'applicant_id',
        'supply_applicant_id',
        'name',
        'description',
        'currency',
        'price_per_unit',
        'quantity',
        'total_price',
        'transaction_type',
        'supplier_name',
        'supplier_inn',
        'supplier_id',
        'status',
        'expected_approval_level',
        'pdf_url',
        'source_docx_path',
        'preview_pdf_path',
        'source_sha256',
        'applicant_signature_snapshot_path',
        'applicant_signature_sha256',
        'document_number',
        'document_version',
        'finalized_at',
        'deadline_date',
        'factual_delivery_date',
        'payment_commitment_date',
        'payment_commitment_comment',
        'deadline_from_supplier',
        'is_overdue',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'cancelled_by' => 'integer',
        'revision' => 'integer',
        'route_unavailable' => 'array',
        'route_draft' => 'array',
        'route_recovery_sequence' => 'integer',
        'approval_paused_at' => 'datetime',
        'total_price' => 'decimal:2',
        'price_per_unit' => 'decimal:2',
        'quantity' => 'float',
        'deadline_date' => 'datetime',
        'deadline_from_supplier' => 'datetime',
        'factual_delivery_date' => 'datetime',
        'payment_commitment_date' => 'datetime',
        'is_overdue' => 'boolean',
        'document_version' => 'integer',
        'finalized_at' => 'datetime',
        'effective_date' => 'date',
    ];

    public function scopeSearch(\Illuminate\Database\Eloquent\Builder $query, string $search, array $columns = ['name']): \Illuminate\Database\Eloquent\Builder
    {
        $search = trim($search);
        if ($search === '') {
            return $query;
        }
        // PostgreSQL rejects text and overflowing values compared with bigint IDs.
        $digits = preg_match('/^#?([0-9]+)$/D', $search, $matches) ? ltrim($matches[1], '0') : '';
        $id = $digits !== '' && (strlen($digits) < 19 || (strlen($digits) === 19 && strcmp($digits, '9223372036854775807') <= 0))
            ? $digits : null;

        return $query->where(function ($nested) use ($search, $columns, $id) {
            foreach ($columns as $column) {
                $nested->orWhereLike($this->qualifyColumn($column), '%'.$search.'%');
            }
            if ($id !== null) {
                $nested->orWhere($this->qualifyColumn('id'), $id);
            }
        });
    }

    public function scopeFinancial($query)
    {
        return $query->whereIn('status', ['approved', 'paid'])->where('type', '!=', 'management_document');
    }

    public function scopeAwaitingDelivery($query)
    {
        return $query->financial()->whereNotNull('deadline_from_supplier')->whereNull('factual_delivery_date');
    }

    public function scopeOverdueSupply($query)
    {
        return $query->awaitingDelivery()->whereDate('deadline_from_supplier', '<', today());
    }

    public function scopeUnscheduledPayment($query)
    {
        return $query->financial()->where('status', 'approved')->doesntHave('tranches');
    }

    public function scopeOverduePayment($query)
    {
        return $query->financial()->where('status', 'approved')->where(function ($q) {
            $q->whereHas('tranches', fn ($t) => $t->overdue())
                ->orWhere(fn ($r) => $r->doesntHave('tranches')->whereDate('deadline_date', '<', today()));
        });
    }

    public function scopeStuckInApproval($query)
    {
        return $query->whereHas('approvals', fn ($a) => $a->stuck());
    }

    public function isManagementDocument(): bool
    {
        return $this->type === 'management_document';
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function applicant()
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function supplyApplicant()
    {
        return $this->belongsTo(User::class, 'supply_applicant_id');
    }

    public function approvals()
    {
        return $this->hasMany(RequestApproval::class);
    }

    public function attachments()
    {
        return $this->hasMany(RequestAttachment::class)->whereNull('archived_at');
    }

    public function items()
    {
        return $this->hasMany(RequestItem::class);
    }

    public function tranches()
    {
        return $this->hasMany(RequestTranche::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
