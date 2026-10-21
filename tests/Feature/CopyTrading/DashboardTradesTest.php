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
    mock(DerivApiService::class)->shouldIgnoreMissing();
});

function tradesTestSetting(): CopySetting
{
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    return CopySetting::factory()->create(['user_id' => $user->id]);
}

test('failed copies show their reason in the trade table', function () {
    $setting = tradesTestSetting();
    CopyTrade::factory()->failed('Insufficient balance.')->create(['user_id' => $setting->user_id, 'master_connection_id' => $setting->master_connection_id]);

    Livewire::actingAs($setting->user)
        ->test(Dashboard::class)
        ->assertSee('Failed')
        ->assertSee('Insufficient balance.');
});

test('pending copies are labelled pending', function () {
    $setting = tradesTestSetting();
    CopyTrade::factory()->open()->create(['user_id' => $setting->user_id, 'master_connection_id' => $setting->master_connection_id, 'status' => CopyTrade::STATUS_PENDING]);

    Livewire::actingAs($setting->user)
        ->test(Dashboard::class)
        ->assertSee('Pending');
});

test('failed copies do not count toward stats', function () {
    $setting = tradesTestSetting();
    $owner = ['user_id' => $setting->user_id, 'master_connection_id' => $setting->master_connection_id];
    CopyTrade::factory()->create([...$owner, 'stake' => 2, 'profit' => -2]);
    CopyTrade::factory()->failed()->create([...$owner, 'stake' => 5]);

    $stats = Livewire::actingAs($setting->user)->test(Dashboard::class)->instance()->stats;

    expect($stats['total_stake'])->toBe(2.0)
        ->and($stats['trade_count'])->toBe(1);
});
