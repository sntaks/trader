<?php

use App\Exceptions\DerivApiException;
use App\Jobs\PlaceFollowerTradeJob;
use App\Jobs\SettleCopyTradeJob;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Illuminate\Support\Facades\Bus;

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
        followerAccountId: null,
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

test('logs error and does not create CopyTrade when buyContract throws', function () use ($masterTrade) {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldReceive('accountCurrency')->andReturn('USD');
    $mock->shouldReceive('buyContract')
        ->once()
        ->andThrow(new Exception('WS unavailable'));

    makeJob($followerConn, $masterConn->id, $masterTrade)->handle(app(DerivApiService::class));

    $this->assertDatabaseMissing('copy_trades', ['user_id' => $followerConn->user_id]);
});

test('skips a trade with the legacy payload shape', function () {
    Bus::fake([SettleCopyTradeJob::class]);

    $masterConn = DerivConnection::factory()->master()->create();
    $followerConn = makeFollowerConnection();

    $mock = $this->mock(DerivApiService::class);
    $mock->shouldNotReceive('buyContract');

    $legacyTrade = ['transaction_id' => 111111, 'contract_type' => 'CALL', 'underlying' => 'R_50', 'duration' => 5];

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

    $this->assertDatabaseMissing('copy_trades', ['user_id' => $followerConn->user_id]);
});
