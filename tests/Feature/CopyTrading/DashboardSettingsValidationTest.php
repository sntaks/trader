<?php

use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Livewire\Livewire;

use function Pest\Laravel\mock;

beforeEach(function () {
    mock(DerivApiService::class)
        ->shouldReceive('getAccounts')
        ->andReturn([['account_id' => 'DOT100', 'currency' => 'USD']])
        ->getMock()
        ->shouldIgnoreMissing();
});

function settingsTestSetting(): CopySetting
{
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    return CopySetting::factory()->create([
        'user_id' => $user->id,
        'follower_account_id' => 'DOT100',
        'stake' => 1,
        'take_profit' => 10,
        'stop_loss' => 10,
    ]);
}

test('saveSettings rejects out-of-range values', function (string $property, mixed $value, string $errorKey) {
    $setting = settingsTestSetting();

    Livewire::actingAs($setting->user)
        ->test(Dashboard::class)
        ->set($property, $value)
        ->call('saveSettings')
        ->assertHasErrors($errorKey);

    expect($setting->fresh())
        ->stake->toEqual('1.00')
        ->take_profit->toEqual('10.00')
        ->stop_loss->toEqual('10.00');
})->with([
    'zero take profit' => ['takeProfit', 0, 'takeProfit'],
    'zero stop loss' => ['stopLoss', 0, 'stopLoss'],
    'negative max compound' => ['maxCompound', -1, 'maxCompound'],
    'huge max compound' => ['maxCompound', 51, 'maxCompound'],
    'huge wait for loss' => ['waitForLoss', 51, 'waitForLoss'],
    'unknown martingale action' => ['ifHitMaxMartingale', 'yolo', 'ifHitMaxMartingale'],
    'unknown market' => ['filterMarkets', ['BTCUSD'], 'filterMarkets.0'],
    'unknown trade type' => ['syntheticIndices', ['HILO'], 'syntheticIndices.0'],
    'unknown forex pair' => ['forexPairs', ['XXX/YYY'], 'forexPairs.0'],
    'stake above the ceiling' => ['stake', 1000.01, 'stake'],
]);

test('saveSettings accepts in-range values', function () {
    $setting = settingsTestSetting();

    Livewire::actingAs($setting->user)
        ->test(Dashboard::class)
        ->set('stake', 1000.0)
        ->set('takeProfit', 5.0)
        ->set('maxCompound', 50)
        ->set('waitForLoss', 50)
        ->set('ifHitMaxMartingale', 'continue')
        ->set('filterMarkets', ['R_50'])
        ->set('syntheticIndices', ['DM'])
        ->set('forexPairs', ['EUR/USD'])
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect($setting->fresh())
        ->stake->toEqual('1000.00')
        ->if_hit_max_martingale->toBe('continue');
});

test('performReset validates settings before saving them', function () {
    $setting = settingsTestSetting();
    CopyTrade::create(['user_id' => $setting->user_id, 'master_connection_id' => $setting->master_connection_id, 'is_win' => true, 'traded_at' => now()]);

    Livewire::actingAs($setting->user)
        ->test(Dashboard::class)
        ->call('openResetModal')
        ->set('takeProfit', 0)
        ->call('performReset')
        ->assertHasErrors('takeProfit')
        ->assertSet('showResetModal', true);

    expect(CopyTrade::where('user_id', $setting->user_id)->count())->toBe(1)
        ->and($setting->fresh()->take_profit)->toEqual('10.00');
});
