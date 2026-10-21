<?php

use App\Jobs\CopyTradeJob;
use App\Jobs\PlaceFollowerTradeJob;
use App\Models\CopySetting;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\MasterContractParser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

$masterTrade = [
    'transaction_id' => 111111,
    'contract_type' => 'CALL',
    'symbol' => 'R_50',
    'duration' => 5,
    'duration_unit' => 't',
    'buy_price' => 2.50,
    'shape_version' => MasterContractParser::SHAPE_VERSION,
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

test('does not fan out a payload queued by the old listener', function () {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    makeFollower($master);

    CopyTradeJob::dispatchSync($master->id, legacyListenerTrade());

    Bus::assertNotDispatched(PlaceFollowerTradeJob::class);
});

function recordLosses(CopySetting $setting, int $count): void
{
    foreach (range(1, $count) as $i) {
        CopyTrade::create([
            'user_id' => $setting->user_id,
            'master_connection_id' => $setting->master_connection_id,
            'stake' => 1,
            'profit' => -1,
            'is_win' => false,
            'traded_at' => now()->subMinutes(10 - $i),
        ]);
    }
}

test('clamps a martingale stake to the configured ceiling', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);
    config(['deriv.max_stake' => 50]);

    $master = makeMaster();
    $setting = makeFollower($master, ['stake' => 1, 'stake_multiplier' => 10, 'max_martingale' => 5, 'do_martingale_at' => 1]);
    recordLosses($setting, 3);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertDispatched(PlaceFollowerTradeJob::class, fn ($job) => $job->stake === 50.0);
});

test('clamps a followed master stake to the ceiling', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);
    config(['deriv.max_stake' => 50]);

    $master = makeMaster();
    makeFollower($master, ['follow_master_stake' => true]);

    CopyTradeJob::dispatchSync($master->id, [...$masterTrade, 'buy_price' => 5000]);

    Bus::assertDispatched(PlaceFollowerTradeJob::class, fn ($job) => $job->stake === 50.0);
});

test('never clamps below the deriv minimum stake', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);
    config(['deriv.max_stake' => 0.1]);

    $master = makeMaster();
    makeFollower($master, ['stake' => 1]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertDispatched(PlaceFollowerTradeJob::class, fn ($job) => $job->stake === 0.35);
});

function primePatternOutcomes(int $masterId): void
{
    Redis::del("master_outcomes:{$masterId}", "master_outcomes_count:{$masterId}");
    Redis::lpush("master_outcomes:{$masterId}", 1, 1);
}

test('overlapping master buys fire a pattern once', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    makeFollower($master, ['pattern_enabled' => true, 'follower_pattern' => '11']);
    primePatternOutcomes($master->id);

    CopyTradeJob::dispatchSync($master->id, [...$masterTrade, 'contract_id' => '501']);
    CopyTradeJob::dispatchSync($master->id, [...$masterTrade, 'contract_id' => '502']);

    Bus::assertDispatchedTimes(PlaceFollowerTradeJob::class, 1);

    Redis::del("master_outcomes:{$master->id}");
});

test('claims the pattern lock at dispatch', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    $setting = makeFollower($master, ['pattern_enabled' => true, 'follower_pattern' => '11']);
    primePatternOutcomes($master->id);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    expect(Cache::has(CopyTradeJob::patternConsumedKey($master->id, $setting->user_id)))->toBeTrue();

    Redis::del("master_outcomes:{$master->id}");
});

/** A follower with stop loss 10 that has already lost 5 and has the given stakes still open. */
function followerWithExposure(DerivConnection $master, array $openStakes, array $overrides = []): CopySetting
{
    $setting = makeFollower($master, ['stake' => 3, 'stop_loss' => 10, ...$overrides]);
    $owner = ['user_id' => $setting->user_id, 'master_connection_id' => $master->id];

    CopyTrade::factory()->create([...$owner, 'stake' => 5, 'profit' => -5, 'traded_at' => now()->subMinutes(5)]);

    foreach ($openStakes as $stake) {
        CopyTrade::factory()->open()->create([...$owner, 'stake' => $stake]);
    }

    return $setting;
}

test('caps the stake to the remaining stop-loss budget', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    followerWithExposure($master, [4]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertDispatched(PlaceFollowerTradeJob::class, fn ($job) => $job->stake === 1.0);
});

test('skips but keeps running when the stop-loss budget is spent', function (float $openStake) use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    $setting = followerWithExposure($master, [$openStake]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertNotDispatched(PlaceFollowerTradeJob::class);
    expect($setting->fresh()->is_running)->toBeTrue();
})->with([
    'budget 0' => [5.0],
    'budget 0.34' => [4.66],
]);

test('places at the deriv minimum when the budget is exactly 0.35', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    followerWithExposure($master, [4.65]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertDispatched(PlaceFollowerTradeJob::class, fn ($job) => $job->stake === 0.35);
});

test('failed attempts do not count as open exposure', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    $setting = followerWithExposure($master, []);
    CopyTrade::factory()->failed()->create(['user_id' => $setting->user_id, 'master_connection_id' => $master->id, 'stake' => 5]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertDispatched(PlaceFollowerTradeJob::class, fn ($job) => $job->stake === 3.0);
});

test('no stop loss means no budget cap', function () use ($masterTrade) {
    Bus::fake([PlaceFollowerTradeJob::class]);

    $master = makeMaster();
    followerWithExposure($master, [50], ['stop_loss' => null]);

    CopyTradeJob::dispatchSync($master->id, $masterTrade);

    Bus::assertDispatched(PlaceFollowerTradeJob::class, fn ($job) => $job->stake === 3.0);
});
