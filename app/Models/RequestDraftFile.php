<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestDraftFile extends Model
{
    protected $fillable = ['request_draft_id', 'name', 'path', 'file_type', 'telegram_file_id'];
}
