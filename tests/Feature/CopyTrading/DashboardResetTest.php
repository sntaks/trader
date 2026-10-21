<?php

use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Livewire\Livewire;

use function Pest\Laravel\mock;

/** A stopped follower with three trades from an hour ago. */
function followerWithPastTrades(array $setting = []): CopySetting
{
    mock(DerivApiService::class)->shouldIgnoreMissing();
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    $copySetting = CopySetting::factory()->create([
        'user_id' => $user->id,
        'is_running' => false,
        'session_started_at' => now()->subHours(2),
        ...$setting,
    ]);

    foreach (['111111', '222222', '333333'] as $contractId) {
        CopyTrade::factory()->create([
            'user_id' => $user->id,
            'master_connection_id' => $copySetting->master_connection_id,
            'master_contract_id' => $contractId,
            'follower_contract_id' => "F{$contractId}",
            'traded_at' => now()->subHour(),
        ]);
    }

    return $copySetting;
}

test('reset keeps trade rows', function (string $action) {
    $setting = followerWithPastTrades();

    Livewire::actingAs($setting->user)->test(Dashboard::class)->call($action);

    expect(CopyTrade::query()->where('user_id', $setting->user_id)->count())->toBe(3);
})->with(['performReset', 'performFullReset']);

test('reset hides earlier trades from the dashboard', function (string $action) {
    $setting = followerWithPastTrades();

    $component = Livewire::actingAs($setting->user)->test(Dashboard::class);

    expect($component->viewData('trades')->total())->toBe(3);

    $component->call($action);

    expect($component->instance()->stats['trade_count'])->toBe(0)
        ->and($component->viewData('trades')->total())->toBe(0);
})->with(['performReset', 'performFullReset']);

test('reset keeps trades but scopes the stop-loss budget to the new session', function () {
    $setting = followerWithPastTrades(['stop_loss' => 10]);
    $pending = CopyTrade::factory()->create([
        'user_id' => $setting->user_id,
        'master_connection_id' => $setting->master_connection_id,
        'is_win' => null,
        'profit' => null,
        'status' => CopyTrade::STATUS_PLACED,
        'traded_at' => now()->subMinutes(5),
    ]);

    Livewire::actingAs($setting->user)->test(Dashboard::class)->call('performReset');

    expect($setting->fresh()->stopLossBudget())->toBe(10.0)
        ->and($pending->fresh())->not->toBeNull();
});
