<?php

use App\Exceptions\DerivApiException;
use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Livewire\Livewire;

use function Pest\Laravel\mock;

function balanceTestSetting(): CopySetting
{
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    return CopySetting::factory()->create([
        'user_id' => $user->id,
        'follower_account_id' => 'DOT100',
        'start_balance' => null,
    ]);
}

test('the follower account row shows the polled live balance', function () {
    mock(DerivApiService::class)
        ->shouldReceive('getAccounts')
        ->andReturn([['account_id' => 'DOT100', 'currency' => 'USD', 'account_type' => 'demo', 'balance' => 100]])
        ->getMock()
        ->shouldReceive('getBalance')
        ->andReturn(['balance' => ['balance' => 150, 'currency' => 'USD', 'loginid' => 'DOT100']])
        ->getMock()
        ->shouldIgnoreMissing();

    Livewire::actingAs(balanceTestSetting()->user)
        ->test(Dashboard::class)
        ->set('settingsOpen', true)
        ->assertSee('150.00 USD')
        ->assertDontSee('100.00 USD');
});

test('the follower account row falls back to the list balance when the live balance fails', function () {
    mock(DerivApiService::class)
        ->shouldReceive('getAccounts')
        ->andReturn([['account_id' => 'DOT100', 'currency' => 'USD', 'account_type' => 'demo', 'balance' => 100]])
        ->getMock()
        ->shouldReceive('getBalance')
        ->andThrow(new DerivApiException('Deriv is unreachable'))
        ->getMock()
        ->shouldIgnoreMissing();

    Livewire::actingAs(balanceTestSetting()->user)
        ->test(Dashboard::class)
        ->set('settingsOpen', true)
        ->assertSee('100.00 USD');
});
