<?php

use App\Exceptions\DerivApiException;
use App\Jobs\CopyTradeJob;
use App\Jobs\PlaceFollowerTradeJob;
use App\Jobs\SettleCopyTradeJob;
use App\Models\CopySetting;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use App\Services\MasterContractParser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

$masterTrade = [
    'contract_id' => '222222',
    'transaction_id' => 111111,
    'symbol' => 'R_50',
    'contract_type' => 'CALL',
    'duration' => 5,
    'duration_unit' => 't',
    'barrier' => null,
    'barrier2' => null,
    'buy_price' => 1.0,
    'shape_version' => MasterContractParser::SHAPE_VERSION,
];

function makeFollowerConnection(): DerivConnection
{
    $user = User::factory()->create();

    return DerivConnection::factory()->create(['user_id' => $user->id, 'type' => 'follower']);
}

function makeJob(DerivConnection $conn, int $masterConnectionId, array $trade, float $stake = 1.00, array $overrides = []): PlaceFollowerTradeJob
{
    return new PlaceFollowerTradeJob(
        followerConnectionId: $conn->id,
        masterConnectionId: $masterConnectionId,
        masterTrade: $trade,
        stake: $stake,
        userId: $conn->user_id,
        followerAccountId: array_key_exists('followerAccountId', $overrides) ? $overrides['followerAccountId'] : 'DOT100',
        markPatternConsumed: $overrides['markPatternConsumed'] ?? false,
        markWaitTrigger: $overrides['markWaitTrigger'] ?? false,
    );
}

test('places a trade and records CopyTrade', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')
        ->once()
        ->andReturn(['buy' => ['transaction_id' => 99999, 'contract_id' => 'CTR-001']]);

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    $this->assertDatabaseHas('copy_trades', [
        'user_id' => $followerConn->user_id,
        'master_connection_id' => $masterConn->id,
        'master_trx_id' => 111111,
        'follower_trx_id' => 99999,
        'symbol' => 'R_50',
        'contract_type' => 'CALL',
        'stake' => 1.00,
    ]);
});

test('dispatches SettleCopyTradeJob when contract_id is present', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')
        ->once()
        ->andReturn(['buy' => ['transaction_id' => 1, 'contract_id' => 'CTR-XYZ']]);

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    Bus::assertDispatched(SettleCopyTradeJob::class, fn ($job) => $job->contractId === 'CTR-XYZ');
});

test('does not dispatch SettleCopyTradeJob when contract_id is absent', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')
        ->once()
        ->andReturn(['buy' => ['transaction_id' => 1]]);

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    Bus::assertNotDispatched(SettleCopyTradeJob::class);
});

test('skips and logs when follower connection is expired', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $user = User::factory()->create();
    $expiredConn = DerivConnection::factory()->expired()->create(['user_id' => $user->id, 'type' => 'follower']);
    $masterConn = DerivConnection::factory()->master()->create();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldNotReceive('buyContract');

    makeJob($expiredConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    $this->assertDatabaseMissing('copy_trades', ['user_id' => $user->id]);
});

test('records a failed copy when buyContract throws', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')
        ->once()
        ->andThrow(new Exception('WS unavailable'));

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    $this->assertDatabaseHas('copy_trades', ['user_id' => $followerConn->user_id, 'status' => CopyTrade::STATUS_FAILED, 'failure_reason' => 'WS unavailable']);
});

test('skips a trade with the legacy payload shape', function () {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldNotReceive('buyContract');

    $legacyTrade = legacyListenerTrade();

    makeJob($followerConn, $masterConn->id, $legacyTrade)->handle(app(DerivApiService::class));

    $this->assertDatabaseMissing('copy_trades', ['user_id' => $followerConn->user_id]);
});

test('passes the follower account currency to the buy', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->once()->andReturn('EUR');
    $mock->shouldReceive('buyContract')
        ->once()
        ->withArgs(fn ($connection, array $params) => $params['currency'] === 'EUR')
        ->andReturn(['buy' => ['transaction_id' => 1, 'contract_id' => 'CTR-EUR']]);

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    $this->assertDatabaseHas('copy_trades', ['user_id' => $followerConn->user_id, 'follower_contract_id' => 'CTR-EUR']);
});

test('forwards both range barriers', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')
        ->once()
        ->withArgs(fn ($connection, array $params) => $params['barrier'] === '+1.2' && $params['barrier2'] === '-1.2')
        ->andReturn(['buy' => ['transaction_id' => 1, 'contract_id' => 'CTR-RANGE']]);

    $rangeTrade = [...$masterTrade, 'contract_type' => 'EXPIRYRANGE', 'barrier' => '+1.2', 'barrier2' => '-1.2'];

    makeJob($followerConn, $masterConn->id, $rangeTrade)->handle(app(DerivApiService::class));

    $this->assertDatabaseHas('copy_trades', ['user_id' => $followerConn->user_id, 'barrier' => '+1.2']);
});

test('does not trade when the follower currency cannot be resolved', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andThrow(new DerivApiException('Account not found'));
    $mock->shouldNotReceive('buyContract');

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    $this->assertDatabaseHas('copy_trades', ['user_id' => $followerConn->user_id, 'status' => CopyTrade::STATUS_FAILED, 'failure_reason' => 'Account not found']);
});

test('records a pending claim before buying', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')->once()->andReturnUsing(function () use ($followerConn) {
        expect(CopyTrade::where('user_id', $followerConn->user_id)->sole())
            ->status->toBe(CopyTrade::STATUS_PENDING)
            ->master_contract_id->toBe('222222');

        return ['buy' => ['transaction_id' => 99999, 'contract_id' => 'CTR-001']];
    });

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    expect(CopyTrade::where('user_id', $followerConn->user_id)->sole())
        ->status->toBe(CopyTrade::STATUS_PLACED)
        ->follower_contract_id->toBe('CTR-001');
});

test('a second job for the same master contract does not buy', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')->once()->andReturn(['buy' => ['transaction_id' => 99999, 'contract_id' => 'CTR-001']]);

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));
    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    expect(CopyTrade::where('user_id', $followerConn->user_id)->count())->toBe(1);
});

test('records a failed buy with the reason', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')->once()->andThrow(new DerivApiException('Insufficient balance.'));

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    expect(CopyTrade::where('user_id', $followerConn->user_id)->sole())
        ->status->toBe(CopyTrade::STATUS_FAILED)
        ->failure_reason->toBe('Insufficient balance.');
    Bus::assertNotDispatched(SettleCopyTradeJob::class);
});

test('records a missing master field as a failed attempt', function () use ($masterTrade) {
    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldNotReceive('buyContract');

    makeJob($followerConn, $masterConn->id, [...$masterTrade, 'duration' => null])->handle(app(DerivApiService::class));

    expect(CopyTrade::where('user_id', $followerConn->user_id)->sole())
        ->status->toBe(CopyTrade::STATUS_FAILED)
        ->failure_reason->toContain('duration');
});

test('existing rows count as placed', function () {
    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $trade = CopyTrade::create(['user_id' => $followerConn->user_id, 'master_connection_id' => $masterConn->id]);

    expect($trade->fresh()->status)->toBe(CopyTrade::STATUS_PLACED);
});

test('a failed buy releases the pattern lock', function () use ($masterTrade) {
    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();
    $lockKey = CopyTradeJob::patternConsumedKey($masterConn->id, $followerConn->user_id);
    Cache::put($lockKey, true, 600);

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')->once()->andThrow(new DerivApiException('Insufficient balance.'));

    makeJob($followerConn, $masterConn->id, $masterTrade, overrides: ['markPatternConsumed' => true])->handle(app(DerivApiService::class));

    expect(Cache::has($lockKey))->toBeFalse();
});

test('a placed buy keeps the pattern lock', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);
    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();
    $lockKey = CopyTradeJob::patternConsumedKey($masterConn->id, $followerConn->user_id);
    Cache::put($lockKey, true, 600);

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')->once()->andReturn(['buy' => ['transaction_id' => 1, 'contract_id' => 'C1']]);

    makeJob($followerConn, $masterConn->id, $masterTrade, overrides: ['markPatternConsumed' => true])->handle(app(DerivApiService::class));

    expect(Cache::has($lockKey))->toBeTrue();
});

/** Stop loss 10 with 5 already lost and the given stake held by another in-flight copy. */
function budgetedFollower(DerivConnection $masterConn, float $heldStake): DerivConnection
{
    $followerConn = makeFollowerConnection();
    $owner = ['user_id' => $followerConn->user_id, 'master_connection_id' => $masterConn->id];

    CopySetting::factory()->create([...$owner, 'stop_loss' => 10, 'is_running' => true]);
    CopyTrade::factory()->create([...$owner, 'stake' => 5, 'profit' => -5, 'traded_at' => now()->subMinute()]);
    CopyTrade::factory()->open()->create([...$owner, 'stake' => $heldStake, 'status' => CopyTrade::STATUS_PENDING]);

    return $followerConn;
}

test('reduces the stake when overlapping copies already hold part of the stop-loss budget', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);
    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = budgetedFollower($masterConn, 3);

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')->once()
        ->withArgs(fn ($conn, array $params) => $params['stake'] === 2.0)
        ->andReturn(['buy' => ['transaction_id' => 1, 'contract_id' => 'C1']]);

    makeJob($followerConn, $masterConn->id, $masterTrade, 3.0)->handle(app(DerivApiService::class));

    expect((float) CopyTrade::where('master_contract_id', '222222')->sole()->stake)->toBe(2.0);
});

test('skips the buy when overlapping copies hold the whole stop-loss budget', function () use ($masterTrade) {
    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = budgetedFollower($masterConn, 5);

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldNotReceive('buyContract');

    makeJob($followerConn, $masterConn->id, $masterTrade, 3.0)->handle(app(DerivApiService::class));

    expect(CopyTrade::where('master_contract_id', '222222')->sole())
        ->status->toBe(CopyTrade::STATUS_FAILED)
        ->failure_reason->toContain('stop-loss');
});

test('keeps long failure reasons within the column', function () use ($masterTrade) {
    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')->once()->andThrow(new Exception(str_repeat('x', 300)));

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    expect(mb_strlen(CopyTrade::where('user_id', $followerConn->user_id)->sole()->failure_reason))->toBeLessThanOrEqual(255);
});

test('places no trade without a follower account', function () use ($masterTrade) {
    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldNotReceive('buyContract');
    $mock->shouldNotReceive('accountCurrency');

    makeJob($followerConn, $masterConn->id, $masterTrade, overrides: ['followerAccountId' => null])->handle(app(DerivApiService::class));

    expect(CopyTrade::sole())
        ->status->toBe(CopyTrade::STATUS_FAILED)
        ->failure_reason->toBe('No follower account selected.');
});
