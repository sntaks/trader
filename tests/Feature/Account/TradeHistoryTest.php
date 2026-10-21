<?php

use App\Exceptions\DerivApiException;
use App\Livewire\Account\TradeHistory;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Livewire\Livewire;

test('trades page requires authentication', function () {
    $this->get(route('trades'))->assertRedirect(route('login'));
});

test('authenticated users can visit the trades page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('trades'))
        ->assertSuccessful();
});

test('trade history shows connect prompt when no deriv connection', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(TradeHistory::class)
        ->assertSee('Connect your Deriv account first');
});

test('trade history shows profit table transactions', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')->andReturn([['account_id' => 'DOT100']]);
    $mock->shouldReceive('getProfitTable')
        ->once()
        ->andReturn([
            'profit_table' => [
                'count' => 2,
                'transactions' => [
                    ['contract_type' => 'CALL', 'underlying_symbol' => 'R_100', 'buy_price' => '10.00', 'sell_price' => '18.50', 'purchase_time' => 1700000000],
                    ['contract_type' => 'PUT', 'underlying_symbol' => 'frxEURUSD', 'buy_price' => '5.00', 'sell_price' => '0.00', 'purchase_time' => 1700000100],
                ],
            ],
        ]);

    app()->instance(DerivApiService::class, $mock);

    Livewire::actingAs($user)
        ->test(TradeHistory::class)
        ->assertSee('CALL')
        ->assertSee('R_100');
});

test('trade history computes correct analytics', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')->andReturn([['account_id' => 'DOT100']]);
    $mock->shouldReceive('getProfitTable')
        ->andReturn([
            'profit_table' => [
                'count' => 3,
                'transactions' => [
                    ['contract_type' => 'CALL', 'underlying_symbol' => 'R_100', 'buy_price' => '10.00', 'sell_price' => '18.00', 'purchase_time' => 1700000000],
                    ['contract_type' => 'CALL', 'underlying_symbol' => 'R_100', 'buy_price' => '10.00', 'sell_price' => '18.00', 'purchase_time' => 1700000100],
                    ['contract_type' => 'PUT', 'underlying_symbol' => 'R_100', 'buy_price' => '10.00', 'sell_price' => '0.00', 'purchase_time' => 1700000200],
                ],
            ],
        ]);

    app()->instance(DerivApiService::class, $mock);

    $component = Livewire::actingAs($user)->test(TradeHistory::class);

    expect($component->instance()->analytics['total_trades'])->toBe(3)
        ->and($component->instance()->analytics['wins'])->toBe(2)
        ->and($component->instance()->analytics['losses'])->toBe(1)
        ->and($component->instance()->analytics['win_rate'])->toBe(66.7);
});

test('switching to statement tab shows transactions', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')->andReturn([['account_id' => 'DOT100']]);
    $mock->shouldReceive('getProfitTable')->andReturn(['profit_table' => ['transactions' => [], 'count' => 0]]);
    $mock->shouldReceive('getStatement')
        ->once()
        ->andReturn([
            'statement' => [
                'count' => 1,
                'transactions' => [
                    ['action_type' => 'deposit', 'amount' => '1000.00', 'balance_after' => '1000.00', 'transaction_time' => 1700000000],
                ],
            ],
        ]);

    app()->instance(DerivApiService::class, $mock);

    Livewire::actingAs($user)
        ->test(TradeHistory::class)
        ->call('switchTab', 'statement')
        ->assertSet('activeTab', 'statement')
        ->assertSee('deposit');
});

test('trade history gracefully handles api failure', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')->andReturn([['account_id' => 'DOT100']]);
    $mock->shouldReceive('getProfitTable')
        ->andThrow(new DerivApiException('API unavailable'));

    app()->instance(DerivApiService::class, $mock);

    Livewire::actingAs($user)
        ->test(TradeHistory::class)
        ->assertSee('No trades yet');
});
