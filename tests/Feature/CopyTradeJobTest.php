<?php

use App\Jobs\CopyTradeJob;
use App\Jobs\PlaceFollowerTradeJob;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;

$masterTrade = [
    'transaction_id' => 111111,
    'contract_type' => 'CALL',
    'symbol' => 'R_50',
    'duration' => 5,
    'duration_unit' => 't',
    'buy_price' => 2.50,
];

function makeMaster(): DerivConnection
{
    return DerivConnection::factory()->master()->create();
}

function makeFollower(DerivConnection $master, array $settingOverrides = []): CopySetting
{
    $followerUser = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $followerUser->id, 'type' => 'follower']);

    return CopySetting::factory()->create(array_merge([
        'user_id' => $followerUser->id,
        'master_connection_id' => $master->id,
        'pattern_enabled' => false,
        'is_active' => true,
        'is_running' => true,
        'stake' => 1.00,
        'follow_master_stake' => false,
    ], $settingOverrides));
}

test('dispatches PlaceFollowerTradeJob for an active follower', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    $setting = makeFollower($master);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertDispatched(PlaceFollowerTradeJob::class, function ($job) use ($master, $setting) {
        return $job->masterConnectionId === $master->id
            && $job->userId === $setting->user_id
            && $job->stake === 1.00;
    });
});

test('dispatches with follower stake when follow_master_stake is false', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    makeFollower($master, ['stake' => 3.50, 'follow_master_stake' => false]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertDispatched(PlaceFollowerTradeJob::class, fn ($job) => $job->stake === 3.50);
});

test('dispatches with master buy_price as stake when follow_master_stake is true', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    makeFollower($master, ['stake' => 1.00, 'follow_master_stake' => true]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertDispatched(PlaceFollowerTradeJob::class, fn ($job) => $job->stake === 2.50);
});

test('does not dispatch for follower with expired token', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    $followerUser = User::factory()->create();
    DerivConnection::factory()->expired()->create(['user_id' => $followerUser->id, 'type' => 'follower']);
    CopySetting::factory()->create([
        'user_id' => $followerUser->id,
        'master_connection_id' => $master->id,
        'is_active' => true,
        'is_running' => true,
        'pattern_enabled' => false,
    ]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertNotDispatched(PlaceFollowerTradeJob::class);
});

test('does not dispatch for follower with inactive settings', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    makeFollower($master, ['is_active' => false]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertNotDispatched(PlaceFollowerTradeJob::class);
});

test('dispatches when pattern matches master Redis outcomes', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    makeFollower($master, [
        'pattern_enabled' => true,
        'follower_pattern' => '11',
    ]);

    Redis::del("master_outcomes:{$master->id}");
    Redis::lpush("master_outcomes:{$master->id}", 1, 1);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertDispatched(PlaceFollowerTradeJob::class);

    Redis::del("master_outcomes:{$master->id}");
});

test('does not dispatch when pattern does not match master Redis outcomes', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    makeFollower($master, [
        'pattern_enabled' => true,
        'follower_pattern' => '111',
    ]);

    // 2 wins then 1 loss — pattern "110" does not match "111"
    Redis::del("master_outcomes:{$master->id}");
    Redis::lpush("master_outcomes:{$master->id}", 0, 1, 1);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertNotDispatched(PlaceFollowerTradeJob::class);

    Redis::del("master_outcomes:{$master->id}");
});

test('does not dispatch when traded symbol is filtered out', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    makeFollower($master, [
        'filter_markets' => ['1HZ100V', '1HZ10V'], // R_50 not in list
    ]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertNotDispatched(PlaceFollowerTradeJob::class);
});

test('selecting only a trade type no longer blocks every trade', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    makeFollower($master, ['synthetic_indices' => ['DM']]);

    CopyTradeJob::dispatchSync($master->id, [...$masterTrade, 'contract_type' => 'DIGITMATCH', 'barrier' => '5']);

    Bus::assertDispatched(PlaceFollowerTradeJob::class);
});

test('a selected forex pair lets its deriv symbol through', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    makeFollower($master, ['forex_pairs' => ['EUR/USD']]);

    CopyTradeJob::dispatchSync($master->id, [...$masterTrade, 'symbol' => 'frxEURUSD']);

    Bus::assertDispatched(PlaceFollowerTradeJob::class);
});
