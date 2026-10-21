<?php

namespace App\Models;

use App\Services\DerivApiService;
use Database\Factories\DerivConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DerivConnection extends Model
{
    /** @use HasFactory<DerivConnectionFactory> */
    use HasFactory;

    public const UNATTENDED_REQUIRED_MESSAGE = 'Deriv sign-in sessions expire after an hour. Connect with an API token to copy trade.';

    public const MASTER_UNATTENDED_REQUIRED_MESSAGE = 'This master\'s Deriv sign-in expires after an hour, so their trades can\'t be copied until they connect with an API token.';

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

    /** Whether a bot can rely on this connection for hours: an API token, or a sign-in that can renew itself. */
    public function canRunUnattended(): bool
    {
        return $this->expires_at === null || $this->refresh_token !== null;
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

    /**
     * Remove the connection: stop the owner's running bot and every follower copying it, each with a
     * reason, retire every cached read, then delete the row. Settings and copy trades that pointed at it
     * are kept with the link emptied. Deriv documents no way to revoke a token, so it stays valid there.
     */
    public function disconnect(): void
    {
        CopySetting::query()
            ->where('user_id', $this->user_id)
            ->where('is_running', true)
            ->update(['is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_DISCONNECTED]);

        CopySetting::query()
            ->where('master_connection_id', $this->id)
            ->where('user_id', '!=', $this->user_id)
            ->where('is_running', true)
            ->update(['is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_MASTER_DISCONNECTED]);

        app(DerivApiService::class)->forgetConnection($this);

        $this->delete();
    }

    /** Whether this connection uses a Personal Access Token rather than a Deriv sign-in. */
    public function isPat(): bool
    {
        return in_array($this->token_type, ['pat', 'pat_legacy'], true);
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
