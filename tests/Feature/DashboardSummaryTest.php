<?php

use App\Livewire\Dashboard\Summary;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Livewire\Livewire;

use function Pest\Laravel\mock;

test('summary reads the follower account by default', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    CopySetting::factory()->create(['user_id' => $user->id, 'follower_account_id' => 'DOT200']);

    $mock = mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')->andReturn([
        ['account_id' => 'DOT100', 'account_type' => 'demo', 'status' => 'active'],
        ['account_id' => 'DOT200', 'account_type' => 'real', 'status' => 'active'],
    ]);
    $mock->shouldReceive('getBalance')->with(Mockery::any(), 'DOT200')
        ->andReturn(['balance' => ['balance' => 42, 'currency' => 'USD', 'loginid' => 'DOT200']]);
    $mock->shouldReceive('getProfitTable')->with(Mockery::any(), 'DOT200', 20, 0)
        ->andReturn(['profit_table' => ['transactions' => [], 'count' => 0]]);

    Livewire::actingAs($user)
        ->test(Summary::class)
        ->assertSet('accountId', 'DOT200')
        ->assertSee('42.00');
});

test('summary switches to the selected account', function () {
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    $mock = mock(DerivApiService::class);
    $mock->shouldReceive('getAccounts')->andReturn([
        ['account_id' => 'DOT100', 'account_type' => 'demo', 'status' => 'active'],
        ['account_id' => 'DOT200', 'account_type' => 'real', 'status' => 'active'],
    ]);
    $mock->shouldReceive('getBalance')->with(Mockery::any(), 'DOT100')->andReturn(['balance' => ['balance' => 1]]);
    $mock->shouldReceive('getBalance')->with(Mockery::any(), 'DOT200')->andReturn(['balance' => ['balance' => 77]]);
    $mock->shouldReceive('getProfitTable')->andReturn(['profit_table' => ['transactions' => [], 'count' => 0]]);

    Livewire::actingAs($user)
        ->test(Summary::class)
        ->assertSet('accountId', 'DOT100')
        ->set('accountId', 'DOT200')
        ->assertHasNoErrors()
        ->assertSee('77.00');
});
