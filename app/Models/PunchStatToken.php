<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PunchStatToken extends Model
{
    protected $fillable = [
        'token',
        'created_by_telegram_user_id',
        'created_by_username',
        'expires_at',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_by_telegram_user_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function isValid(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
