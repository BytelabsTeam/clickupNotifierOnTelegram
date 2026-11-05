<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamTask extends Model
{
    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'assignee_telegram_id',
        'assignee_username',
        'assignee_name',
        'created_by_telegram_id',
        'due_at',
        'completed_at',
        'is_code',
        'score',
        'score_reason',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'scored_at' => 'datetime',
            'is_code' => 'boolean',
            'score' => 'integer',
            'assignee_telegram_id' => 'integer',
            'created_by_telegram_id' => 'integer',
        ];
    }
}
