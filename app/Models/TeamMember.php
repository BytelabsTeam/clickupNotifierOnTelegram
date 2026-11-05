<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'telegram_username',
        'github_login',
        'display_name',
        'is_admin',
        'is_active',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
