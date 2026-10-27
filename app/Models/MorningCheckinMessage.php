<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MorningCheckinMessage extends Model
{
    protected $fillable = [
        'work_date',
        'chat_id',
        'message_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'message_id' => 'integer',
        ];
    }
}
