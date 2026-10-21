<?php

use App\Exceptions\DerivApiException;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

use function Pest\Laravel\mock;

function strandedCopyTrade(User $user, string $accountId, string $contractId, int $minutesAgo = 10): CopyTrade
{
    return CopyTrade::factory()->open()->create([
        'user_id' => $user->id,
        'follower_account_id' => $accountId,
        'follower_contract_id' => $contractId,
        'stake' => 1.00,
        'traded_at' => now()->subMinutes($minutesAgo),
    ]);
}

function followerWithConnection(): User
{
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    return $user;
}

test('the sweeper settles a trade the job gave up on', function () {
    $this->freezeTime();
    $copyTrade = strandedCopyTrade(followerWithConnection(), 'DOT200', '555');

    mock(DerivApiService::class)
        ->shouldReceive('getProfitTableSince')
        ->once()
        ->with(Mockery::type(DerivConnection::class), 'DOT200', now()->subMinutes(10)->timestamp - 60)
        ->andReturn([['contract_id' => 555, 'buy_price' => 1, 'sell_price' => 1.95]]);

    $this->artisan('copy-trades:settle')->assertSuccessful();

    expect($copyTrade->fresh())
        ->is_win->toBeTrue()
        ->profit->toEqual('0.95')
        ->payout->toEqual('1.95');
});

test('the sweeper reads profit_table once per account', function () {
    $user = followerWithConnection();
    strandedCopyTrade($user, 'DOT100', '1');
    strandedCopyTrade($user, 'DOT100', '2');
    strandedCopyTrade($user, 'DOT200', '3');

    $mock = mock(DerivApiService::class);
    $mock->shouldReceive('getProfitTableSince')->once()->with(Mockery::any(), 'DOT100', Mockery::any())->andReturn([]);
    $mock->shouldReceive('getProfitTableSince')->once()->with(Mockery::any(), 'DOT200', Mockery::any())->andReturn([]);

    $this->artisan('copy-trades:settle')->assertSuccessful();
});

test('the sweeper leaves unmatched and fresh trades open', function () {
    $user = followerWithConnection();
    $unmatched = strandedCopyTrade($user, 'DOT100', '1');
    $fresh = strandedCopyTrade($user, 'DOT100', '2', minutesAgo: 0);

    mock(DerivApiService::class)
        ->shouldReceive('getProfitTableSince')
        ->once()
        ->andReturn([['contract_id' => 2, 'buy_price' => 1, 'sell_price' => 0]]);

    $this->artisan('copy-trades:settle')->assertSuccessful();

    expect($unmatched->fresh()->is_win)->toBeNull()
        ->and($fresh->fresh()->is_win)->toBeNull();
});

test('the sweeper skips failed, pending and settled trades', function () {
    $user = followerWithConnection();
    CopyTrade::factory()->failed()->create(['user_id' => $user->id, 'traded_at' => now()->subHour()]);
    CopyTrade::factory()->open()->create(['user_id' => $user->id, 'status' => CopyTrade::STATUS_PENDING, 'traded_at' => now()->subHour()]);
    CopyTrade::factory()->create(['user_id' => $user->id, 'traded_at' => now()->subHour()]);

    mock(DerivApiService::class)->shouldNotReceive('getProfitTableSince');

    $this->artisan('copy-trades:settle')->assertSuccessful();
});

test('one failing account does not stop the others', function () {
    $broken = strandedCopyTrade(followerWithConnection(), 'DOT100', '1');
    $healthy = strandedCopyTrade(followerWithConnection(), 'DOT200', '2');

    $mock = mock(DerivApiService::class);
    $mock->shouldReceive('getProfitTableSince')->with(Mockery::any(), 'DOT100', Mockery::any())->andThrow(new DerivApiException('Rate limited'));
    $mock->shouldReceive('getProfitTableSince')->with(Mockery::any(), 'DOT200', Mockery::any())
        ->andReturn([['contract_id' => 2, 'buy_price' => 1, 'sell_price' => 0]]);

    $this->artisan('copy-trades:settle')->assertSuccessful();

    expect($broken->fresh()->is_win)->toBeNull()
        ->and($healthy->fresh()->is_win)->toBeFalse();
});

test('the sweeper is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command ?? '', 'copy-trades:settle'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
