<?php

use App\Exceptions\DerivApiException;
use App\Livewire\CopyTrading\Setup;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivLegacyApiService;
use App\Services\DerivRateLimiter;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\mock;

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

test('connectViaPat falls back to legacy ws api when rest api returns 401', function () {
    $user = User::factory()->create();

    Http::fake([
        'api.derivws.com/*' => Http::response(null, 401),
    ]);

    mock(DerivLegacyApiService::class)
        ->shouldReceive('authorize')
        ->once()
        ->andReturn(['authorize' => ['loginid' => 'VRTC6948800', 'account_list' => []]]);

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->set('patToken', 'legacytoken12345')
        ->call('connectViaPat')
        ->assertSet('patSuccess', 'Deriv account connected successfully.')
        ->assertSet('patToken', '');

    $this->assertDatabaseHas('deriv_connections', [
        'user_id' => $user->id,
        'token_type' => 'pat_legacy',
    ]);
});

test('connectViaPat shows error when both rest and legacy apis reject the token', function () {
    $user = User::factory()->create();

    Http::fake([
        'api.derivws.com/*' => Http::response(null, 401),
    ]);

    mock(DerivLegacyApiService::class)
        ->shouldReceive('authorize')
        ->once()
        ->andThrow(new DerivApiException('InvalidToken'));

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->set('patToken', 'badtoken12345')
        ->call('connectViaPat')
        ->assertSet('patError', 'Invalid token. Your token was not accepted by Deriv. Please check it and try again.')
        ->assertSet('patSuccess', null);

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

test('connectViaPat updates existing connection when user reconnects via legacy', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id, 'token_type' => 'Bearer']);

    Http::fake([
        'api.derivws.com/*' => Http::response(null, 401),
    ]);

    mock(DerivLegacyApiService::class)
        ->shouldReceive('authorize')
        ->once()
        ->andReturn(['authorize' => ['loginid' => 'CR4629260', 'account_list' => []]]);

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->set('patToken', 'legacynewtoken12')
        ->call('connectViaPat')
        ->assertSet('patSuccess', 'Deriv account connected successfully.');

    $this->assertDatabaseCount('deriv_connections', 1);
    $this->assertDatabaseHas('deriv_connections', [
        'user_id' => $user->id,
        'token_type' => 'pat_legacy',
    ]);
});

test('followerAccounts uses legacy service for pat_legacy connections', function () {
    $user = User::factory()->create();
    $connection = DerivConnection::factory()->create([
        'user_id' => $user->id,
        'token_type' => 'pat_legacy',
    ]);

    $accounts = [
        ['account_id' => 'CR4629260', 'currency' => 'USD', 'account_type' => 'real', 'balance' => 150.0, 'landing_company_name' => 'svg'],
        ['account_id' => 'VRTC6948800', 'currency' => 'USD', 'account_type' => 'demo', 'balance' => 10024.99, 'landing_company_name' => 'virtual'],
    ];

    mock(DerivLegacyApiService::class)
        ->shouldReceive('getAccounts')
        ->once()
        ->with(Mockery::on(fn ($c) => $c->id === $connection->id))
        ->andReturn($accounts);

    $component = Livewire::actingAs($user)->test(Setup::class);

    expect($component->instance()->followerAccounts)->toBe($accounts);
});

test('pat connect reports a busy deriv instead of trying the legacy api', function () {
    $user = User::factory()->create();
    Http::fake(['api.derivws.com/*' => Http::response(['data' => []], 200)]);
    mock(DerivLegacyApiService::class)->shouldNotReceive('authorize');
    app(DerivRateLimiter::class)->coolDown(60);

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->set('patToken', 'validtoken12345')
        ->call('connectViaPat')
        ->assertSet('patError', 'Deriv is busy right now — try again in a minute.');

    Http::assertNothingSent();
    expect(DerivConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
