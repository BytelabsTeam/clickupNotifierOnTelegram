<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkSession extends Model
{
    protected $fillable = [
        'work_date',
        'telegram_user_id',
        'telegram_username',
        'display_name',
        'email',
        'punched_in_at',
        'punched_out_at',
        'duration_seconds',
        'source',
        'last_reminded_at',
        'reminder_count',
        'auto_closed',
        'telegram_message_id',
        'telegram_chat_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'telegram_user_id' => 'integer',
            'punched_in_at' => 'datetime',
            'punched_out_at' => 'datetime',
            'duration_seconds' => 'integer',
            'last_reminded_at' => 'datetime',
            'reminder_count' => 'integer',
            'auto_closed' => 'boolean',
            'telegram_message_id' => 'integer',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('punched_out_at');
    }

    public function isOpen(): bool
    {
        return $this->punched_out_at === null;
    }
}
