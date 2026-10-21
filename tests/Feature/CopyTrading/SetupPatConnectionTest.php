<?php

use App\Livewire\CopyTrading\Setup;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivPatConnector;
use App\Services\DerivRateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('setup shows deriv connection prompt when no account connected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->assertSee('Connect Your Deriv Account')
        ->assertSee('OAuth2')
        ->assertSee('Personal Access Token')
        ->assertSee('new Deriv accounts')
        ->assertSee('legacy Deriv accounts');
});

test('setup does not show connection prompt when already connected', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->assertDontSee('Connect Your Deriv Account');
});

test('connectViaPat saves as pat when new rest api accepts the token', function () {
    $user = User::factory()->create();

    Http::fake([
        'api.derivws.com/*' => Http::response(['data' => [
            ['account_id' => 'VRTC1234567', 'account_type' => 'demo', 'balance' => 10000, 'currency' => 'USD'],
        ]], 200),
    ]);

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->set('patToken', 'validtoken12345')
        ->call('connectViaPat')
        ->assertHasNoErrors()
        ->assertSet('patSuccess', 'Deriv account connected successfully.')
        ->assertSet('patToken', '');

    $this->assertDatabaseHas('deriv_connections', [
        'user_id' => $user->id,
        'token_type' => 'pat',
    ]);
});

test('connectViaPat shows the token-rejected message when the new api returns 401', function () {
    $user = User::factory()->create();

    Http::fake([
        'api.derivws.com/*' => Http::response(null, 401),
    ]);

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->set('patToken', 'badtoken12345')
        ->call('connectViaPat')
        ->assertSet('patError', DerivPatConnector::REJECTED_MESSAGE)
        ->assertSet('patSuccess', null);

    Http::assertSentCount(1);
    $this->assertDatabaseMissing('deriv_connections', ['user_id' => $user->id]);
});

test('connectViaPat validates token is required', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->set('patToken', '')
        ->call('connectViaPat')
        ->assertHasErrors(['patToken' => 'required']);
});

test('connectViaPat updates an existing connection', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id, 'token_type' => 'Bearer']);

    Http::fake([
        'api.derivws.com/*' => Http::response(['data' => []], 200),
    ]);

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->set('patToken', 'newpattoken12345')
        ->call('connectViaPat')
        ->assertSet('patSuccess', 'Deriv account connected successfully.');

    $this->assertDatabaseCount('deriv_connections', 1);
    $this->assertDatabaseHas('deriv_connections', [
        'user_id' => $user->id,
        'token_type' => 'pat',
    ]);
});

test('connectViaPat is throttled after five attempts', function () {
    $user = User::factory()->create();
    Http::fake(['api.derivws.com/*' => Http::response(null, 401)]);
    $component = Livewire::actingAs($user)->test(Setup::class);

    foreach (range(1, DerivPatConnector::MAX_ATTEMPTS) as $attempt) {
        $component->set('patToken', 'badtoken12345')->call('connectViaPat');
    }

    $component->set('patToken', 'badtoken12345')->call('connectViaPat');

    expect($component->get('patError'))->toStartWith('Too many attempts — try again in');
    Http::assertSentCount(DerivPatConnector::MAX_ATTEMPTS);
});

test('pat connect reports a busy deriv', function () {
    $user = User::factory()->create();
    Http::fake(['api.derivws.com/*' => Http::response(['data' => []], 200)]);
    app(DerivRateLimiter::class)->coolDown(60);

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->set('patToken', 'validtoken12345')
        ->call('connectViaPat')
        ->assertSet('patError', 'Deriv is busy right now — try again in a minute.');

    Http::assertNothingSent();
    expect(DerivConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('pat connect on a deriv 429 starts a cooldown', function () {
    $this->freezeSecond();
    $user = User::factory()->create();
    Http::fake(['api.derivws.com/*' => Http::response(['errors' => [['code' => 'RateLimit', 'message' => 'Too many requests']]], 429)]);

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->set('patToken', 'validtoken12345')
        ->call('connectViaPat')
        ->assertSet('patError', 'Deriv is busy right now — try again in a minute.');

    expect(Cache::get(DerivRateLimiter::COOLDOWN_KEY))->toBe(now()->addSeconds(30)->timestamp);
});
