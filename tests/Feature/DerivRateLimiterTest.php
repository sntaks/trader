<?php

use App\Exceptions\DerivRateLimitedException;
use App\Models\DerivConnection;
use App\Services\DerivRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    $this->freezeSecond();

    config([
        'deriv.rate_limits.ip_per_minute' => 10,
        'deriv.rate_limits.ip_per_ten_minutes' => 1000,
        'deriv.rate_limits.connection_per_minute' => 1000,
        'deriv.rate_limits.trade_share' => 0.95,
        'deriv.rate_limits.read_share' => 0.6,
        'deriv.rate_limits.trade_wait_seconds' => 5,
        'deriv.rate_limits.default_cooldown_seconds' => 30,
        'deriv.rate_limits.max_cooldown_seconds' => 120,
    ]);
});

function limiter(): DerivRateLimiter
{
    return app(DerivRateLimiter::class);
}

function acquireTimes(int $times, string $priority, ?DerivConnection $connection = null): void
{
    foreach (range(1, $times) as $ignored) {
        limiter()->acquire($connection, $priority);
    }
}

test('reads stop at their share of the budget', function () {
    acquireTimes(6, DerivRateLimiter::PRIORITY_READ);

    expect(fn () => limiter()->acquire(null, DerivRateLimiter::PRIORITY_READ))
        ->toThrow(DerivRateLimitedException::class);

    Sleep::assertNeverSlept();
});

test('trade calls may use the headroom reads cannot', function () {
    acquireTimes(6, DerivRateLimiter::PRIORITY_READ);
    acquireTimes(3, DerivRateLimiter::PRIORITY_TRADE);

    Sleep::assertNeverSlept();
});

test('a full trade budget waits then fails', function () {
    acquireTimes(9, DerivRateLimiter::PRIORITY_TRADE);
    $start = now();

    expect(fn () => limiter()->acquire(null, DerivRateLimiter::PRIORITY_TRADE))
        ->toThrow(DerivRateLimitedException::class, 'Deriv rate limit reached — copy skipped.');

    expect($start->diffInSeconds(now()))->toBeGreaterThanOrEqual(5.0)->toBeLessThan(5.5);
});

test('a trade gets a slot that frees up while it waits', function () {
    acquireTimes(9, DerivRateLimiter::PRIORITY_TRADE);
    $this->travel(57)->seconds();

    limiter()->acquire(null, DerivRateLimiter::PRIORITY_TRADE);

    Sleep::assertSlept(fn () => true, times: 12);
});

test('the per-connection limit applies to every priority', function () {
    config(['deriv.rate_limits.connection_per_minute' => 2]);
    $connection = DerivConnection::factory()->create();

    limiter()->acquire($connection, DerivRateLimiter::PRIORITY_READ);

    expect(fn () => limiter()->acquire($connection, DerivRateLimiter::PRIORITY_READ))->toThrow(DerivRateLimitedException::class);

    limiter()->acquire(DerivConnection::factory()->create(), DerivRateLimiter::PRIORITY_READ);
});

test('the ten-minute window is enforced', function () {
    config(['deriv.rate_limits.ip_per_minute' => 1000, 'deriv.rate_limits.ip_per_ten_minutes' => 10]);

    acquireTimes(6, DerivRateLimiter::PRIORITY_READ);
    $this->travel(2)->minutes();

    expect(fn () => limiter()->acquire(null, DerivRateLimiter::PRIORITY_READ))->toThrow(DerivRateLimitedException::class);

    $this->travel(9)->minutes();

    limiter()->acquire(null, DerivRateLimiter::PRIORITY_READ);
});

test('cooldown', function (string $priority, int $cooldownSeconds, bool $succeeds, bool $sleeps) {
    limiter()->coolDown($cooldownSeconds);

    $attempt = fn () => limiter()->acquire(null, $priority);

    if ($succeeds) {
        $attempt();
    } else {
        expect($attempt)->toThrow(DerivRateLimitedException::class);
    }

    $sleeps ? Sleep::assertSlept(fn () => true, times: 8) : Sleep::assertNeverSlept();
})->with([
    'a read during a cooldown throws' => [DerivRateLimiter::PRIORITY_READ, 2, false, false],
    'a trade throws at once when the cooldown outlasts its wait' => [DerivRateLimiter::PRIORITY_TRADE, 60, false, false],
    'a trade waits out a short cooldown' => [DerivRateLimiter::PRIORITY_TRADE, 2, true, true],
]);

test('cooldown honours retry-after within the cap', function (?int $retryAfter, int $expectedSeconds) {
    limiter()->coolDown($retryAfter);

    expect(Cache::get(DerivRateLimiter::COOLDOWN_KEY))->toBe(now()->addSeconds($expectedSeconds)->timestamp);
})->with([
    'given' => [10, 10],
    'missing' => [null, 30],
    'capped' => [600, 120],
]);
