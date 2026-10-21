<?php

namespace App\Services;

use App\Exceptions\DerivAuthException;
use App\Models\DerivConnection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Keeps Deriv OAuth access tokens alive with the refresh-token grant.
 *
 * Deriv does not document refresh tokens, so a connection is refreshed only when sign-in returned one.
 * Refresh tokens may rotate on use, so each refresh runs under a per-connection lock and re-reads the
 * row first: a caller holding an old copy picks up the token another process just obtained instead of
 * spending a refresh token that is no longer valid.
 */
class DerivTokenRefresher
{
    public const TOKEN_URL = 'https://auth.deriv.com/oauth2/token';

    /** Refresh a token this close to expiry, or already past it. */
    public const REFRESH_MARGIN_SECONDS = 300;

    /** Lifetime assumed when Deriv's response leaves out `expires_in`. */
    private const DEFAULT_LIFETIME_SECONDS = 3600;

    private const LOCK_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 10;

    /** Refresh the token if it is about to expire; the call goes ahead on a transient failure. */
    public function ensureFresh(DerivConnection $connection): void
    {
        if (! $connection->canRefresh() || ! $this->withinMargin($connection)) {
            return;
        }

        if (! $this->refresh($connection) && $connection->isExpired()) {
            throw new DerivAuthException('The Deriv sign-in has ended. Reconnect your Deriv account.');
        }
    }

    /**
     * Obtain a new access token. Returns false when it could not; a grant Deriv rejects also ends the
     * connection's bots, while a timeout, 429 or server error leaves everything for the next attempt.
     * The passed model is reloaded in place, and a token another process renewed beyond $withinSeconds is left alone.
     */
    public function refresh(DerivConnection $connection, int $withinSeconds = self::REFRESH_MARGIN_SECONDS): bool
    {
        try {
            return Cache::lock("deriv:refresh:{$connection->id}", self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn (): bool => $this->refreshLocked($connection, $withinSeconds));
        } catch (LockTimeoutException) {
            $connection->refresh();

            return ! $this->withinMargin($connection, $withinSeconds);
        }
    }

    private function refreshLocked(DerivConnection $connection, int $withinSeconds): bool
    {
        $connection->refresh();

        if (! $connection->canRefresh()) {
            return false;
        }

        if (! $this->withinMargin($connection, $withinSeconds)) {
            return true;
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'client_id' => config('deriv.app_id'),
                'refresh_token' => $connection->refresh_token,
            ]);
        } catch (ConnectionException $e) {
            Log::warning("DerivTokenRefresher: could not reach Deriv for connection #{$connection->id} — {$e->getMessage()}");

            return false;
        }

        if (in_array($response->status(), [400, 401], true)) {
            $stopped = $connection->requireReauth();
            Log::error("DerivTokenRefresher: Deriv rejected the refresh token for connection #{$connection->id} ({$response->json('error', $response->status())}). Stopped {$stopped} bot(s); the owner must reconnect.");

            return false;
        }

        if ($response->failed() || ! $response->json('access_token')) {
            Log::warning("DerivTokenRefresher: refresh failed for connection #{$connection->id} with HTTP {$response->status()}.");

            return false;
        }

        $connection->update([
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') ?? $connection->refresh_token,
            'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? self::DEFAULT_LIFETIME_SECONDS)),
            'scope' => $response->json('scope') ?? $connection->scope,
        ]);

        return true;
    }

    private function withinMargin(DerivConnection $connection, int $seconds = self::REFRESH_MARGIN_SECONDS): bool
    {
        return $connection->expires_at !== null
            && $connection->expires_at->lte(now()->addSeconds($seconds));
    }
}
