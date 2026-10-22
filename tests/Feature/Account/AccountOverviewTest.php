<?php

use App\Exceptions\DerivApiException;
use App\Livewire\Account\Overview;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Livewire\Livewire;

test('account page requires authentication', function () {
    $this->get(route('account'))->assertRedirect(route('login'));
});

test('authenticated users can visit the account page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('account'))
        ->assertSuccessful();
});

test('account overview shows connect prompt when no deriv connection', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->assertSee('No Deriv account connected');
});

test('account overview shows accounts from rest api', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')
        ->andReturn([
            ['account_id' => 'CR12345', 'currency' => 'USD', 'is_demo' => false, 'balance' => 5000.00],
            ['account_id' => 'VRTC12345', 'currency' => 'USD', 'is_demo' => true, 'balance' => 10000.00],
        ]);
    $mock->shouldReceive('getBalance')
        ->andReturn(['balance' => ['balance' => 5000.00, 'currency' => 'USD', 'loginid' => 'CR12345']]);

    app()->instance(DerivApiService::class, $mock);

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->assertSee('CR12345')
        ->assertSee('VRTC12345')
        ->assertSee('Real Accounts')
        ->assertSee('Demo Accounts');
});

test('account overview sets api error when rest call fails', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')
        ->andThrow(new DerivApiException('Token expired or invalid. Please reconnect your Deriv account.'));
    $mock->shouldReceive('getBalance')->andReturn([]);

    app()->instance(DerivApiService::class, $mock);

    $component = Livewire::actingAs($user)->test(Overview::class);

    expect($component->get('apiError'))->toBe('Token expired or invalid. Please reconnect your Deriv account.');
    $component->assertSee('reconnect your Deriv account');
});

test('account overview separates demo and real accounts', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')
        ->andReturn([
            ['account_id' => 'CR111', 'currency' => 'USD', 'is_demo' => false],
            ['account_id' => 'VRTC222', 'currency' => 'USD', 'is_demo' => true],
            ['account_id' => 'CR333', 'currency' => 'EUR', 'is_demo' => false],
        ]);
    $mock->shouldReceive('getBalance')->andReturn([]);

    app()->instance(DerivApiService::class, $mock);

    $component = Livewire::actingAs($user)->test(Overview::class);

    expect($component->instance()->realAccounts)->toHaveCount(2)
        ->and($component->instance()->demoAccounts)->toHaveCount(1);
});

test('account overview lists only options accounts', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')
        ->andReturn([
            ['account_id' => 'CR111', 'currency' => 'USD', 'account_type' => 'real'],
            ['account_id' => 'VRTC222', 'currency' => 'USD', 'account_type' => 'demo'],
        ]);
    $mock->shouldReceive('getBalance')->andReturn([]);

    app()->instance(DerivApiService::class, $mock);

    $component = Livewire::actingAs($user)->test(Overview::class);

    expect($component->instance()->realAccounts)->toHaveCount(1)
        ->and($component->instance()->demoAccounts)->toHaveCount(1);

    $component->assertSee('CR111')->assertSee('VRTC222')->assertDontSee('CFD');
});

test('reset demo balance calls service and shows success', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')->andReturn([]);
    $mock->shouldReceive('getBalance')->andReturn([]);
    $mock->shouldReceive('resetDemoBalance')
        ->once()
        ->with(Mockery::type(DerivConnection::class), 'VRTC12345');
    $mock->shouldReceive('clearCache')->andReturn(null);

    app()->instance(DerivApiService::class, $mock);

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->call('resetDemoBalance', 'VRTC12345')
        ->assertSet('resetSuccess', 'Demo balance reset to $10,000 successfully.');
});

test('refresh clears cache', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('clearCache')->once();
    $mock->shouldReceive('getAccounts')->andReturn([]);
    $mock->shouldReceive('getBalance')->andReturn([]);

    app()->instance(DerivApiService::class, $mock);

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->call('refresh');
});

test('overview shows the balance of the selected account', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')->andReturn([
        ['account_id' => 'DOT100', 'currency' => 'USD', 'account_type' => 'demo', 'status' => 'active'],
        ['account_id' => 'DOT200', 'currency' => 'USD', 'account_type' => 'real', 'status' => 'active'],
    ]);
    $mock->shouldReceive('getBalance')->with(Mockery::any(), 'DOT100')
        ->andReturn(['balance' => ['balance' => 10, 'currency' => 'USD', 'loginid' => 'DOT100']]);
    $mock->shouldReceive('getBalance')->with(Mockery::any(), 'DOT200')
        ->andReturn(['balance' => ['balance' => 2500.5, 'currency' => 'USD', 'loginid' => 'DOT200']]);

    app()->instance(DerivApiService::class, $mock);

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->set('accountId', 'DOT200')
        ->assertHasNoErrors()
        ->assertSee('2,500.50');
});

test('the selected account row shows the live balance instead of the cached list balance', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')->andReturn([
        ['account_id' => 'DOT100', 'currency' => 'USD', 'account_type' => 'demo', 'status' => 'active', 'balance' => 100],
    ]);
    $mock->shouldReceive('getBalance')
        ->andReturn(['balance' => ['balance' => 150, 'currency' => 'USD', 'loginid' => 'DOT100']]);

    app()->instance(DerivApiService::class, $mock);

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->assertSee('150.00')
        ->assertDontSee('100.00');
});

test('the selected account row falls back to the list balance when the live balance fails', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')->andReturn([
        ['account_id' => 'DOT100', 'currency' => 'USD', 'account_type' => 'demo', 'status' => 'active', 'balance' => 100],
    ]);
    $mock->shouldReceive('getBalance')->andThrow(new DerivApiException('Deriv is unreachable'));

    app()->instance(DerivApiService::class, $mock);

    Livewire::actingAs($user)
        ->test(Overview::class)
        ->assertSee('100.00');
});
