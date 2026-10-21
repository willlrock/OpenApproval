<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramBroadcast extends Model
{
    protected $fillable = [
        'created_by', 'admin_chat_id', 'lang', 'text', 'recipients', 'status',
        'cursor', 'sent_count', 'failed_count', 'report_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'admin_chat_id' => 'integer',
            'cursor' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'report_sent_at' => 'datetime',
        ];
    }
}
