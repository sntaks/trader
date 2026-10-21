<?php

use App\Exceptions\DerivApiException;
use App\Jobs\SettleCopyTradeJob;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;

use function Pest\Laravel\mock;

function openCopyTradeOn(string $accountId = 'DOT200'): CopyTrade
{
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    return CopyTrade::factory()->open()->create([
        'user_id' => $user->id,
        'follower_contract_id' => '555',
        'follower_account_id' => $accountId,
        'stake' => 1.00,
    ]);
}

function settle(CopyTrade $copyTrade): SettleCopyTradeJob
{
    $job = (new SettleCopyTradeJob($copyTrade->id))->withFakeQueueInteractions();
    $job->handle(app(DerivApiService::class));

    return $job;
}

test('settles on the account the copy was bought on', function () {
    $copyTrade = openCopyTradeOn('DOT200');

    mock(DerivApiService::class)
        ->shouldReceive('getContractDetails')
        ->once()
        ->with(Mockery::type(DerivConnection::class), 'DOT200', '555')
        ->andReturn(['is_sold' => 1, 'status' => 'lost', 'profit' => '-1.00', 'buy_price' => '1.00', 'sell_price' => '0']);

    settle($copyTrade);

    expect($copyTrade->fresh())
        ->is_win->toBeFalse()
        ->profit->toEqual('-1.00')
        ->payout->toEqual('0.00');
});

test('records a win from the deriv profit field', function () {
    $copyTrade = openCopyTradeOn();

    mock(DerivApiService::class)
        ->shouldReceive('getContractDetails')
        ->andReturn(['is_sold' => 1, 'status' => 'won', 'profit' => '1.85', 'buy_price' => '1.00', 'sell_price' => '2.85']);

    settle($copyTrade)->assertNotReleased();

    expect($copyTrade->fresh())
        ->is_win->toBeTrue()
        ->profit->toEqual('1.85')
        ->payout->toEqual('2.85');
});

test('settles a cancelled contract from its profit', function () {
    $copyTrade = openCopyTradeOn();

    mock(DerivApiService::class)
        ->shouldReceive('getContractDetails')
        ->andReturn(['is_sold' => 1, 'status' => 'cancelled', 'profit' => '-0.05', 'buy_price' => '1.00', 'sell_price' => '0.95']);

    settle($copyTrade);

    expect($copyTrade->fresh())
        ->is_win->toBeFalse()
        ->profit->toEqual('-0.05');
});

test('records a loss from sell minus buy when profit is absent', function () {
    $copyTrade = openCopyTradeOn();

    mock(DerivApiService::class)
        ->shouldReceive('getContractDetails')
        ->andReturn(['is_sold' => 1, 'status' => 'sold', 'buy_price' => '1.00', 'sell_price' => '0.40']);

    settle($copyTrade);

    expect($copyTrade->fresh())
        ->is_win->toBeFalse()
        ->profit->toEqual('-0.60')
        ->payout->toEqual('0.40');
});

test('leaves the trade open on an unsettled or empty response', function (array $response) {
    $copyTrade = openCopyTradeOn();

    mock(DerivApiService::class)->shouldReceive('getContractDetails')->andReturn($response);

    settle($copyTrade)->assertReleased();

    expect($copyTrade->fresh())
        ->is_win->toBeNull()
        ->profit->toBeNull();
})->with([
    'empty' => [[]],
    'open' => [['is_sold' => 0, 'status' => 'open']],
    'sold without prices' => [['is_sold' => 1]],
    'expired but not yet settled' => [['is_sold' => 0, 'is_expired' => 1, 'status' => 'open']],
]);

test('leaves the trade open when the lookup fails', function () {
    $copyTrade = openCopyTradeOn();

    mock(DerivApiService::class)->shouldReceive('getContractDetails')->andThrow(new DerivApiException('Deriv WebSocket unavailable'));

    settle($copyTrade)->assertReleased();

    expect($copyTrade->fresh()->is_win)->toBeNull();
});

test('retries three times with growing backoff', function () {
    $job = new SettleCopyTradeJob(1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 30, 60]);
});

test('expected expiry', function (array $masterTrade, int $seconds) {
    expect((int) SettleCopyTradeJob::expectedExpiry($masterTrade)->totalSeconds)->toBe($seconds);
})->with([
    '5 ticks on R_50' => [['symbol' => 'R_50', 'duration' => 5, 'duration_unit' => 't'], 10],
    '5 ticks on 1HZ100V' => [['symbol' => '1HZ100V', 'duration' => 5, 'duration_unit' => 't'], 5],
    '2 minutes' => [['symbol' => 'R_50', 'duration' => 2, 'duration_unit' => 'm'], 120],
    '1 hour' => [['symbol' => 'R_50', 'duration' => 1, 'duration_unit' => 'h'], 3600],
]);
