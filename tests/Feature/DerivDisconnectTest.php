<?php

use App\Exceptions\DerivRateLimitedException;
use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use App\Services\DerivPatConnector;
use App\Services\DerivRateLimiter;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\mock;

/** Fake Deriv's accounts endpoint with a list the test can change between calls. */
function fakeAccountsList(ArrayObject $accounts): void
{
    Http::fake(['api.derivws.com/trading/v1/options/accounts' => fn () => Http::response(['data' => $accounts->getArrayCopy()])]);
}

test('disconnect stops the owner\'s running bot', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'is_running' => true]);

    $this->actingAs($user)
        ->delete(route('deriv.disconnect'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Deriv account disconnected. To revoke access completely, also delete the token in your Deriv account settings.');

    expect($setting->fresh()?->only('is_running', 'stop_reason'))
        ->toBe(['is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_DISCONNECTED])
        ->and($user->hasDerivConnected())->toBeFalse();
});

test('disconnect leaves a stopped bot\'s reason alone', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'is_running' => false, 'stop_reason' => 'take_profit']);

    $this->actingAs($user)->delete(route('deriv.disconnect'));

    expect($setting->fresh()->stop_reason)->toBe('take_profit');
});

test('disconnect retires cached and stale reads', function () {
    $accounts = new ArrayObject([['account_id' => 'CR1']]);
    fakeAccountsList($accounts);
    $user = User::factory()->create();
    $connection = DerivConnection::factory()->create(['user_id' => $user->id, 'token_type' => 'pat', 'expires_at' => null, 'refresh_token' => null]);
    $api = app(DerivApiService::class);
    $api->getAccounts($connection);

    $this->actingAs($user)->delete(route('deriv.disconnect'));

    app(DerivRateLimiter::class)->coolDown(60);

    expect(fn () => $api->getAccounts($connection))->toThrow(DerivRateLimitedException::class);
});

test('reconnecting clears cached and stale reads', function () {
    $accounts = new ArrayObject([['account_id' => 'CR1']]);
    fakeAccountsList($accounts);
    $user = User::factory()->create();
    $connection = DerivConnection::factory()->create(['user_id' => $user->id, 'token_type' => 'pat', 'expires_at' => null, 'refresh_token' => null]);
    $api = app(DerivApiService::class);
    $api->getAccounts($connection);

    $accounts->exchangeArray([['account_id' => 'CR2']]);
    $reconnected = app(DerivPatConnector::class)->connect($user, 'another-token-123456');

    expect($reconnected->id)->toBe($connection->id)
        ->and($api->getAccounts($reconnected))->toBe([['account_id' => 'CR2']]);

    app(DerivRateLimiter::class)->coolDown(60);
    $api->clearCache($reconnected);

    expect($api->getAccounts($reconnected))->toBe([['account_id' => 'CR2']]);
});

test('signing in again clears cached reads', function () {
    $accounts = new ArrayObject([['account_id' => 'CR1']]);
    fakeAccountsList($accounts);
    $user = User::factory()->create();
    $connection = DerivConnection::factory()->create(['user_id' => $user->id]);
    app(DerivApiService::class)->getAccounts($connection);

    $accounts->exchangeArray([['account_id' => 'CR2']]);
    Http::fake(['auth.deriv.com/oauth2/token' => Http::response(['access_token' => 'ory_at_2', 'expires_in' => 3600])]);

    $this->actingAs($user)
        ->withSession(['deriv_oauth_state' => 's', 'deriv_code_verifier' => 'v'])
        ->get(route('deriv.callback', ['code' => 'c', 'state' => 's']));

    expect(app(DerivApiService::class)->getAccounts($connection->fresh()))->toBe([['account_id' => 'CR2']]);
});

test('the stop notice explains a disconnect', function () {
    mock(DerivApiService::class)->shouldIgnoreMissing();
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    CopySetting::factory()->create(['user_id' => $user->id, 'is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_DISCONNECTED]);

    Livewire::actingAs($user)->test(Dashboard::class)
        ->assertSee('Deriv Account Disconnected')
        ->assertSee('Your Deriv account was disconnected. Reconnect it to copy trade.');
});
