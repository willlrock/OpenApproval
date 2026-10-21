<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestAttachment extends Model
{
    protected $fillable = [
        'approval_request_id',
        'file_type',
        'file_path',
        'telegram_file_id',
        'original_name',
        'file_size',
    ];

    protected $casts = ['archived_at' => 'datetime', 'file_size' => 'integer'];

    public function getDisplayNameAttribute(): string
    {
        return self::safeName($this->original_name, (int) $this->id, $this->file_type);
    }

    public static function safeName(?string $name, int $id, string $type): string
    {
        $name = basename(str_replace('\\', '/', $name ?? ''));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return $name !== '' && ! in_array($name, ['.', '..'], true)
            ? $name : 'Вложение №'.$id.'.'.$type;
    }

    public function approvalRequest()
    {
        return $this->belongsTo(ApprovalRequest::class);
    }
}
