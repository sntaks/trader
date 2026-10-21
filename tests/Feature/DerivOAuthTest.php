<?php

use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('unauthenticated users are redirected from connect route', function () {
    $this->get(route('deriv.connect'))->assertRedirect(route('login'));
});

test('redirect stores pkce state in session and redirects to deriv', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('deriv.connect'));

    $response->assertRedirectContains('auth.deriv.com/oauth2/auth');
    $response->assertRedirectContains('code_challenge');
    $response->assertRedirectContains('response_type=code');

    expect(session('deriv_oauth_state'))->not->toBeNull()
        ->and(session('deriv_code_verifier'))->not->toBeNull();
});

test('callback stores connection after successful token exchange', function () {
    $user = User::factory()->create();

    Http::fake([
        'auth.deriv.com/oauth2/token' => Http::response([
            'access_token' => 'ory_at_fake_token_12345',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'trade account_manage',
        ], 200),
    ]);

    $state = 'test-state-value';

    $this->actingAs($user)
        ->withSession([
            'deriv_oauth_state' => $state,
            'deriv_code_verifier' => 'test-verifier',
        ])
        ->get(route('deriv.callback', ['code' => 'auth-code-123', 'state' => $state]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success');

    expect($user->derivConnection)->not->toBeNull()
        ->and($user->hasDerivConnected())->toBeTrue();
});

test('callback rejects mismatched state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([
            'deriv_oauth_state' => 'correct-state',
            'deriv_code_verifier' => 'verifier',
        ])
        ->get(route('deriv.callback', ['code' => 'auth-code', 'state' => 'wrong-state']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');

    expect($user->hasDerivConnected())->toBeFalse();
});

test('callback handles deriv authorization denied', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('deriv.callback', [
            'error' => 'access_denied',
            'error_description' => 'User cancelled',
        ]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');
});

test('callback handles token exchange failure', function () {
    $user = User::factory()->create();

    Http::fake([
        'auth.deriv.com/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $state = 'test-state';

    $this->actingAs($user)
        ->withSession([
            'deriv_oauth_state' => $state,
            'deriv_code_verifier' => 'verifier',
        ])
        ->get(route('deriv.callback', ['code' => 'bad-code', 'state' => $state]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');
});

test('disconnect removes deriv connection', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('deriv.disconnect'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success');

    expect($user->hasDerivConnected())->toBeFalse();
});

test('pat connection stores token and redirects to account page', function () {
    $user = User::factory()->create();

    Http::fake([
        'api.derivws.com/trading/v1/options/accounts' => Http::response([
            'data' => [['account_id' => 'CR123', 'currency' => 'USD']],
        ], 200),
    ]);

    $this->actingAs($user)
        ->post(route('deriv.connect.pat'), ['pat_token' => 'a1b2c3d4e5f6g7h8i9j0'])
        ->assertRedirect(route('account'))
        ->assertSessionHas('success');

    $conn = $user->fresh()->derivConnection;
    expect($conn)->not->toBeNull()
        ->and($conn->token_type)->toBe('pat')
        ->and($conn->expires_at)->toBeNull()
        ->and($conn->scope)->toBeNull();
});

test('pat connection rejects invalid token', function () {
    $user = User::factory()->create();

    Http::fake([
        'api.derivws.com/trading/v1/options/accounts' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $this->actingAs($user)
        ->post(route('deriv.connect.pat'), ['pat_token' => 'invalid-token-here'])
        ->assertRedirect(route('account'))
        ->assertSessionHas('error');

    expect($user->hasDerivConnected())->toBeFalse();
});

test('pat connection requires token field', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('deriv.connect.pat'), ['pat_token' => ''])
        ->assertSessionHasErrors('pat_token');
});

test('pat connection updates existing connection', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    Http::fake([
        'api.derivws.com/trading/v1/options/accounts' => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($user)
        ->post(route('deriv.connect.pat'), ['pat_token' => 'new-pat-token-1234567']);

    expect($user->derivConnection()->count())->toBe(1)
        ->and($user->fresh()->derivConnection->token_type)->toBe('pat');
});

test('callback updates existing connection on reconnect', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id, 'access_token' => 'old-token']);

    Http::fake([
        'auth.deriv.com/oauth2/token' => Http::response([
            'access_token' => 'ory_at_new_token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),
    ]);

    $state = 'test-state';

    $this->actingAs($user)
        ->withSession([
            'deriv_oauth_state' => $state,
            'deriv_code_verifier' => 'verifier',
        ])
        ->get(route('deriv.callback', ['code' => 'new-code', 'state' => $state]));

    expect($user->derivConnection()->count())->toBe(1);
});

test('pat connection reports a busy deriv during a cooldown without calling it', function () {
    $user = User::factory()->create();
    Http::fake();
    app(DerivRateLimiter::class)->coolDown(60);

    $this->actingAs($user)
        ->post(route('deriv.connect.pat'), ['pat_token' => 'a1b2c3d4e5f6g7h8i9j0'])
        ->assertRedirect(route('account'))
        ->assertSessionHas('error', 'Deriv is busy right now — try again in a minute.');

    Http::assertNothingSent();
    expect($user->hasDerivConnected())->toBeFalse();
});

test('pat connection starts a cooldown when deriv answers 429', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    Http::fake([
        'api.derivws.com/trading/v1/options/accounts' => Http::response(['errors' => [['code' => 'RateLimit', 'message' => 'Too many requests']]], 429, ['Retry-After' => '45']),
    ]);

    $this->actingAs($user)
        ->post(route('deriv.connect.pat'), ['pat_token' => 'a1b2c3d4e5f6g7h8i9j0'])
        ->assertSessionHas('error', 'Deriv is busy right now — try again in a minute.');

    expect(Cache::get(DerivRateLimiter::COOLDOWN_KEY))->toBe(now()->addSeconds(45)->timestamp);
});

test('redirect requests only the configured scopes', function () {
    $user = User::factory()->create();

    $location = $this->actingAs($user)->get(route('deriv.connect'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('trade');
});

/** Run the OAuth callback with a faked token response. */
function completeOAuthCallback(User $user, array $tokenResponse): void
{
    Http::fake(['auth.deriv.com/oauth2/token' => Http::response($tokenResponse, 200)]);

    test()->actingAs($user)
        ->withSession(['deriv_oauth_state' => 'state-1', 'deriv_code_verifier' => 'verifier-1'])
        ->get(route('deriv.callback', ['code' => 'code-1', 'state' => 'state-1']))
        ->assertRedirect(route('dashboard'));
}

test('callback stores the refresh token encrypted', function () {
    $user = User::factory()->create();

    completeOAuthCallback($user, ['access_token' => 'ory_at_1', 'refresh_token' => 'ory_rt_abc', 'expires_in' => 3600]);

    $connection = $user->fresh()->derivConnection;

    expect($connection->refresh_token)->toBe('ory_rt_abc')
        ->and(DB::table('deriv_connections')->value('refresh_token'))->not->toBe('ory_rt_abc')->not->toBeNull();
});

test('callback without a refresh token stores null', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id, 'refresh_token' => 'ory_rt_old']);

    completeOAuthCallback($user, ['access_token' => 'ory_at_1', 'expires_in' => 3600]);

    expect($user->fresh()->derivConnection->refresh_token)->toBeNull();
});
