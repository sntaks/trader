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

/**
 * Create $count trades for the setting's user, one minute apart, the oldest first, with follower
 * transaction ids TRX-1 (oldest) to TRX-$count (newest).
 */
function sessionTrades(CopySetting $setting, int $count): void
{
    foreach (range(1, $count) as $position) {
        CopyTrade::factory()->create([
            'user_id' => $setting->user_id,
            'master_connection_id' => $setting->master_connection_id,
            'follower_trx_id' => "TRX-{$position}",
            'traded_at' => now()->subMinutes($count - $position + 1),
        ]);
    }
}

/** @return list<int> The row numbers the trade table renders, top to bottom. */
function renderedRowNumbers(string $html): array
{
    preg_match_all('/colWidths\.num\}px`">\s*(\d+)\s*<\/td>/', $html, $matches);

    return array_map('intval', $matches[1]);
}

test('the trade list shows the newest trade first', function () {
    $setting = tradesTestSetting();
    sessionTrades($setting, 3);

    Livewire::actingAs($setting->user)->test(Dashboard::class)
        ->assertSeeInOrder(['TRX-3', 'TRX-2', 'TRX-1']);
});

test('row numbers count down across pages from the session total', function () {
    $setting = tradesTestSetting();
    sessionTrades($setting, 27);

    $component = Livewire::actingAs($setting->user)->test(Dashboard::class);

    expect(renderedRowNumbers($component->html()))->toBe(range(27, 3));

    $component->call('gotoPage', 2);

    expect(renderedRowNumbers($component->html()))->toBe([2, 1])
        ->and($component->html())->toContain('TRX-2');
});
