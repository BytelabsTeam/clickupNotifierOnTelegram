<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskScore extends Model
{
    protected $fillable = [
        'task_id',
        'email',
        'username',
        'name',
        'task_name',
        'task_description',
        'is_code',
        'score',
        'reason',
        'completed_at',
        'month',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_code' => 'boolean',
            'score' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
