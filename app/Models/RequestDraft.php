<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestDraft extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'payload', 'version', 'submitted_request_id', 'source_request_id', 'is_revision', 'source_revision', 'management_preview_path', 'preview_version', 'revision_files_initialized'];

    protected $casts = ['revision_files_initialized' => 'boolean', 'payload' => 'array', 'version' => 'integer', 'is_revision' => 'boolean', 'source_revision' => 'integer', 'preview_version' => 'integer'];

    public function files()
    {
        return $this->hasMany(RequestDraftFile::class);
    }
}
