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

    /** Whether this session can be renewed without the owner: an expiring token that came with a refresh token. */
    public function canRefresh(): bool
    {
        return $this->refresh_token !== null && $this->expires_at !== null;
    }

    /**
     * Deriv will no longer accept this connection: mark it expired so every page and trade path asks for a
     * reconnect, and stop each running bot that copies from it or belongs to its owner.
     *
     * @return int The number of bots stopped.
     */
    public function requireReauth(): int
    {
        $this->update(['expires_at' => now(), 'refresh_token' => null]);

        return CopySetting::query()
            ->where(fn ($query) => $query->where('master_connection_id', $this->id)->orWhere('user_id', $this->user_id))
            ->where('is_running', true)
            ->update(['is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_REAUTH]);
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
