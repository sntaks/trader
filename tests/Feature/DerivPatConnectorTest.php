<?php

use App\Exceptions\DerivApiException;
use App\Exceptions\DerivAuthException;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivPatConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('connector stores a pat connection', function () {
    Http::fake(['api.derivws.com/*' => Http::response(['data' => []])]);
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id, 'refresh_token' => 'rt_old']);

    $connection = app(DerivPatConnector::class)->connect($user, 'a1b2c3d4e5f6g7h8i9j0');

    expect($connection->only('token_type', 'expires_at', 'scope', 'refresh_token'))
        ->toBe(['token_type' => 'pat', 'expires_at' => null, 'scope' => null, 'refresh_token' => null])
        ->and($connection->access_token)->toBe('a1b2c3d4e5f6g7h8i9j0')
        ->and(DerivConnection::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('connector rejects a token deriv refuses without trying another api', function () {
    Http::fake(['api.derivws.com/*' => Http::response(null, 401)]);
    $user = User::factory()->create();

    expect(fn () => app(DerivPatConnector::class)->connect($user, 'a1b2c3d4e5f6g7h8i9j0'))
        ->toThrow(DerivAuthException::class, DerivPatConnector::REJECTED_MESSAGE);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.derivws.com/trading/v1/options/accounts'));
    expect(DerivConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('connector reports deriv errors in their own words', function () {
    Http::fake(['api.derivws.com/*' => Http::response(['errors' => [['message' => 'Token lacks the trade scope']]], 403)]);

    expect(fn () => app(DerivPatConnector::class)->connect(User::factory()->create(), 'a1b2c3d4e5f6g7h8i9j0'))
        ->toThrow(DerivApiException::class, 'Token lacks the trade scope');
});

test('connector allows five attempts a minute per user', function () {
    Http::fake(['api.derivws.com/*' => Http::response(null, 401)]);
    $connector = app(DerivPatConnector::class);
    $user = User::factory()->create();

    foreach (range(1, DerivPatConnector::MAX_ATTEMPTS) as $attempt) {
        expect($connector->availableIn($user))->toBe(0);

        try {
            $connector->connect($user, 'a1b2c3d4e5f6g7h8i9j0');
        } catch (DerivAuthException) {
        }
    }

    expect($connector->availableIn($user))->toBeGreaterThan(0)
        ->and($connector->availableIn(User::factory()->create()))->toBe(0);
});
