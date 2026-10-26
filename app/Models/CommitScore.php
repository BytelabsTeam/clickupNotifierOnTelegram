<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommitScore extends Model
{
    protected $fillable = [
        'sha',
        'repo',
        'login',
        'email',
        'name',
        'message',
        'score',
        'committed_at',
        'month',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'committed_at' => 'datetime',
        ];
    }
}
