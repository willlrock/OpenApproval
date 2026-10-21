<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'inn', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function approvalRequests()
    {
        return $this->hasMany(ApprovalRequest::class);
    }
}
