<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectScoringDoc extends Model
{
    protected $fillable = [
        'repo',
        'content',
        'generated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }
}
