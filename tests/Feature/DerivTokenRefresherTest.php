<?php

use App\Exceptions\DerivAuthException;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Services\DerivTokenRefresher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** A connection whose access token runs out in a minute. */
function expiringConnection(array $attributes = []): DerivConnection
{
    return DerivConnection::factory()->create([
        'access_token' => 'at_old',
        'refresh_token' => 'rt_old',
        'expires_at' => now()->addMinute(),
        ...$attributes,
    ]);
}

function fakeTokenEndpoint(mixed $response): void
{
    Http::fake(['auth.deriv.com/oauth2/token' => $response]);
}

test('refresh stores the new tokens', function () {
    fakeTokenEndpoint(Http::response(['access_token' => 'at_new', 'refresh_token' => 'rt_new', 'expires_in' => 3600]));
    $connection = expiringConnection();

    expect(app(DerivTokenRefresher::class)->refresh($connection))->toBeTrue();

    $connection = $connection->fresh();

    expect($connection->access_token)->toBe('at_new')
        ->and($connection->refresh_token)->toBe('rt_new')
        ->and(abs($connection->expires_at->getTimestamp() - now()->addSeconds(3600)->getTimestamp()))->toBeLessThanOrEqual(5);

    Http::assertSent(fn (Request $request) => $request->url() === DerivTokenRefresher::TOKEN_URL
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'rt_old'
        && $request['client_id'] === config('deriv.app_id'));
});

test('refresh keeps the old refresh token and assumes an hour when fields are missing', function () {
    fakeTokenEndpoint(Http::response(['access_token' => 'at_new']));
    $connection = expiringConnection();

    app(DerivTokenRefresher::class)->refresh($connection);

    $connection = $connection->fresh();

    expect($connection->refresh_token)->toBe('rt_old')
        ->and(abs($connection->expires_at->getTimestamp() - now()->addSeconds(3600)->getTimestamp()))->toBeLessThanOrEqual(5);
});

test('a rejected grant requires reauth', function () {
    fakeTokenEndpoint(Http::response(['error' => 'invalid_grant'], 400));
    $connection = expiringConnection();
    $follower = CopySetting::factory()->create(['master_connection_id' => $connection->id, 'is_running' => true]);
    $own = CopySetting::factory()->create(['user_id' => $connection->user_id, 'is_running' => true]);

    expect(app(DerivTokenRefresher::class)->refresh($connection))->toBeFalse();

    $connection = $connection->fresh();

    expect($connection->isExpired())->toBeTrue()
        ->and($connection->refresh_token)->toBeNull()
        ->and([$follower->fresh()->only('is_running', 'stop_reason'), $own->fresh()->only('is_running', 'stop_reason')])
        ->each->toBe(['is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_REAUTH]);
});

test('a server error keeps the connection and returns false', function () {
    fakeTokenEndpoint(Http::response('', 503));
    $connection = expiringConnection();
    $follower = CopySetting::factory()->create(['master_connection_id' => $connection->id, 'is_running' => true]);

    expect(app(DerivTokenRefresher::class)->refresh($connection))->toBeFalse();

    $connection = $connection->fresh();

    expect($connection->refresh_token)->toBe('rt_old')
        ->and($connection->isExpired())->toBeFalse()
        ->and($follower->fresh()->is_running)->toBeTrue();
});

test('a connection failure keeps the connection and returns false', function () {
    fakeTokenEndpoint(fn () => throw new ConnectionException('timed out'));
    $connection = expiringConnection();

    expect(app(DerivTokenRefresher::class)->refresh($connection))->toBeFalse()
        ->and($connection->fresh()->refresh_token)->toBe('rt_old');
});

test('a stale in-memory connection reuses the token refreshed under the lock', function () {
    fakeTokenEndpoint(Http::response(['access_token' => 'at_wrong']));
    $stale = expiringConnection();
    DerivConnection::whereKey($stale->id)->update(['expires_at' => now()->addHour()]);
    $stale->fresh()->forceFill(['access_token' => 'at_fresh'])->save();

    expect(app(DerivTokenRefresher::class)->refresh($stale))->toBeTrue()
        ->and($stale->access_token)->toBe('at_fresh');

    Http::assertNothingSent();
});

test('ensureFresh leaves a token outside the margin alone', function () {
    fakeTokenEndpoint(Http::response(['access_token' => 'at_new']));
    $connection = expiringConnection(['expires_at' => now()->addHour()]);

    app(DerivTokenRefresher::class)->ensureFresh($connection);

    Http::assertNothingSent();
});

test('ensureFresh refreshes a token inside the margin', function () {
    fakeTokenEndpoint(Http::response(['access_token' => 'at_new']));
    $connection = expiringConnection();

    app(DerivTokenRefresher::class)->ensureFresh($connection);

    expect($connection->access_token)->toBe('at_new');
});

test('ensureFresh leaves a pat alone', function () {
    fakeTokenEndpoint(Http::response(['access_token' => 'at_new']));
    $connection = expiringConnection(['token_type' => 'pat', 'expires_at' => null, 'refresh_token' => null]);

    app(DerivTokenRefresher::class)->ensureFresh($connection);

    Http::assertNothingSent();
});

test('ensureFresh throws when the grant is dead', function () {
    fakeTokenEndpoint(Http::response(['error' => 'invalid_grant'], 401));
    $connection = expiringConnection();

    app(DerivTokenRefresher::class)->ensureFresh($connection);
})->throws(DerivAuthException::class);

test('ensureFresh keeps going on a transient failure', function () {
    fakeTokenEndpoint(Http::response('', 503));
    $connection = expiringConnection();

    app(DerivTokenRefresher::class)->ensureFresh($connection);

    expect($connection->access_token)->toBe('at_old');
});
