<?php

namespace App\Models;

use App\Notifications\Auth\VerifyEmailNotification;
use App\Services\DerivApiService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = [
        'account_no',
        'name',
        'username',
        'email',
        'phone',
        'password',
        'google_id',
        'avatar',
        'onboarding_completed_at',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    public function derivConnection(): HasOne
    {
        return $this->hasOne(DerivConnection::class);
    }

    public function hasDerivConnected(): bool
    {
        return $this->derivConnection()->exists();
    }

    public function copySetting(): HasOne
    {
        return $this->hasOne(CopySetting::class);
    }

    /**
     * The account to show by default: the copy setting's follower account when it is listed and active,
     * otherwise the first active account.
     *
     * @param  list<array<string, mixed>>  $accounts  As returned by DerivApiService::getAccounts().
     */
    public function preferredDerivAccountId(array $accounts): ?string
    {
        $accountIds = array_column(DerivApiService::activeAccounts($accounts), 'account_id');
        $followerAccountId = $this->copySetting?->follower_account_id;

        return in_array($followerAccountId, $accountIds, true) ? $followerAccountId : ($accountIds[0] ?? null);
    }

    /**
     * Lock out whoever set up this account before its email was verified: a Google sign-in proved
     * the mailbox belongs to someone else. Replaces the password with a random one nobody knows,
     * clears two-factor, rotates the remember token, ends every stored session, and removes the Deriv
     * connection and copy setting they attached; trade history stays. The password gets a random hash
     * rather than null because AuthenticateSession skips users without one, and the changed hash is
     * what logs out other sessions under any session driver. The owner can set a password via
     * "Forgot password".
     */
    public function secureAfterTakeover(): void
    {
        $this->derivConnection?->disconnect();
        $this->copySetting()->delete();

        $this->forceFill([
            'password' => Hash::make(Str::random(64)),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        DB::table(config('session.table', 'sessions'))->where('user_id', $this->id)->delete();
    }

    public function copyTrades(): HasMany
    {
        return $this->hasMany(CopyTrade::class);
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function profileAvatar(): string
    {
        return $this->avatar ?? $this->gravatar();
    }

    public function gravatar(int $size = 80): string
    {
        $hash = md5(strtolower(trim($this->email)));

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d=robohash";
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }
}
