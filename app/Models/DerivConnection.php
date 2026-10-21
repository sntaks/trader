<?php

namespace App\Models;

use Database\Factories\DerivConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DerivConnection extends Model
{
    /** @use HasFactory<DerivConnectionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'token_type',
        'expires_at',
        'scope',
        'type',
        'master_account_id',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isMaster(): bool
    {
        return $this->type === 'master';
    }

    public function isFollower(): bool
    {
        return $this->type === 'follower';
    }

    public function followers(): HasMany
    {
        return $this->hasMany(CopySetting::class, 'master_connection_id');
    }
}
