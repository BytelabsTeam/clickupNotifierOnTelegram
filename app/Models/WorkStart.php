<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkStart extends Model
{
    protected $fillable = [
        'work_date',
        'telegram_user_id',
        'telegram_username',
        'display_name',
        'email',
        'started_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'telegram_user_id' => 'integer',
            'started_at' => 'datetime',
        ];
    }
}
