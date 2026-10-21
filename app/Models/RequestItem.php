<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestItem extends Model
{
    protected $fillable = [
        'approval_request_id',
        'name',
        'description',
        'price_per_unit',
        'quantity',
        'total_price',
    ];

    protected $casts = [
        'quantity' => 'float',
    ];

    public function approvalRequest()
    {
        return $this->belongsTo(ApprovalRequest::class);
    }
}
