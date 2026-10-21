<?php

namespace App\Services;

use App\Exceptions\DerivRateLimitedException;
use App\Models\DerivConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Keeps every REST request to Deriv inside its published limits (per IP per minute and per
 * ten minutes, per user per minute). Each WebSocket session costs one such request for its OTP.
 *
 * Trade calls may use `trade_share` of each limit and wait briefly for a slot; reads may use only
 * `read_share`, so page loads can never take the budget trades need. Counting is check-then-hit,
 * so concurrent workers can overshoot by a few requests; the shares leave room for that.
 */
class DerivRateLimiter
{
    public const PRIORITY_TRADE = 'trade';

    public const PRIORITY_READ = 'read';

    /** Holds the Unix time a Deriv-requested cooldown ends. */
    public const COOLDOWN_KEY = 'deriv-rest:cooldown';

    private const POLL_MILLISECONDS = 250;

    /**
     * Take one REST slot, or throw when none is free (after waiting up to `trade_wait_seconds` for trades).
     *
     * @throws DerivRateLimitedException
     */
    public function acquire(?DerivConnection $connection, string $priority): void
    {
        $isTrade = $priority === self::PRIORITY_TRADE;
        $deadline = now()->addSeconds($isTrade ? (int) $this->config('trade_wait_seconds') : 0);

        while (true) {
            $cooldownLeft = $this->cooldownRemaining();

            if ($cooldownLeft === 0 && $this->hasRoom($connection, $priority)) {
                foreach ($this->windows($connection, $priority) as [$key, , $decay]) {
                    RateLimiter::hit($key, $decay);
                }

                return;
            }

            if (! $isTrade || now()->addSeconds($cooldownLeft)->greaterThan($deadline) || now()->greaterThanOrEqualTo($deadline)) {
                throw new DerivRateLimitedException($isTrade
                    ? 'Deriv rate limit reached — copy skipped.'
                    : 'Deriv is busy right now — try again shortly.');
            }

            Sleep::for(self::POLL_MILLISECONDS)->milliseconds();
        }
    }

    /** Stop all Deriv REST traffic for Deriv's `Retry-After`, or the default, capped at `max_cooldown_seconds`. */
    public function coolDown(?int $retryAfterSeconds): void
    {
        $seconds = min(
            $retryAfterSeconds && $retryAfterSeconds > 0 ? $retryAfterSeconds : (int) $this->config('default_cooldown_seconds'),
            (int) $this->config('max_cooldown_seconds'),
        );

        Cache::put(self::COOLDOWN_KEY, now()->addSeconds($seconds)->timestamp, $seconds);
    }

    private function cooldownRemaining(): int
    {
        $endsAt = Cache::get(self::COOLDOWN_KEY);

        return $endsAt ? max(0, (int) $endsAt - now()->timestamp) : 0;
    }

    private function hasRoom(?DerivConnection $connection, string $priority): bool
    {
        foreach ($this->windows($connection, $priority) as [$key, $allowed]) {
            if (RateLimiter::attempts($key) >= $allowed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}> Cache key, requests allowed for this priority, window in seconds.
     */
    private function windows(?DerivConnection $connection, string $priority): array
    {
        $share = (float) $this->config($priority === self::PRIORITY_TRADE ? 'trade_share' : 'read_share');

        $windows = [
            ['deriv-rest:ip:1m', (int) floor($this->config('ip_per_minute') * $share), 60],
            ['deriv-rest:ip:10m', (int) floor($this->config('ip_per_ten_minutes') * $share), 600],
        ];

        if ($connection) {
            $windows[] = [
                "deriv-rest:conn:{$connection->id}:1m",
                (int) floor($this->config('connection_per_minute') * (float) $this->config('trade_share')),
                60,
            ];
        }

        return $windows;
    }

    private function config(string $key): mixed
    {
        return config("deriv.rate_limits.{$key}");
    }
}
