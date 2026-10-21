<?php

use App\Jobs\CopyTradeJob;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Services\MasterStreamProcessor;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/** High IDs keep these tests away from Redis keys used by local development data. */
const STREAM_TEST_CONNECTION_ID = 990001;

function forgetStreamKeys(): void
{
    $id = STREAM_TEST_CONNECTION_ID;

    Redis::del("master_outcomes:{$id}", "master_outcomes_count:{$id}", "master_buy_price:{$id}:777");
}

function streamBuy(array $overrides = []): array
{
    return ['msg_type' => 'transaction', 'transaction' => array_merge([
        'action' => 'buy',
        'contract_id' => 777,
        'transaction_id' => 888,
        'amount' => -10,
        'symbol' => 'R_50',
    ], $overrides)];
}

function streamSell(float $amount): array
{
    return ['msg_type' => 'transaction', 'transaction' => ['action' => 'sell', 'contract_id' => 777, 'amount' => $amount]];
}

function streamContract(int $reqId, array $contract = []): array
{
    return ['msg_type' => 'proposal_open_contract', 'req_id' => $reqId, 'proposal_open_contract' => array_merge([
        'contract_id' => 777,
        'contract_type' => 'DIGITOVER',
        'underlying' => 'R_50',
        'tick_count' => 5,
        'barrier' => '3',
    ], $contract)];
}

beforeEach(function () {
    forgetStreamKeys();
    Bus::fake([CopyTradeJob::class]);
});

afterEach(fn () => forgetStreamKeys());

test('requests contract details for a master buy', function () {
    $outgoing = (new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID))->process(streamBuy());

    expect($outgoing)->toHaveCount(1)
        ->and($outgoing[0]['proposal_open_contract'])->toBe(1)
        ->and($outgoing[0]['contract_id'])->toBe(777)
        ->and($outgoing[0]['req_id'])->not->toBe(MasterStreamProcessor::TRANSACTION_REQ_ID)
        ->and($outgoing[0]['req_id'])->not->toBe(MasterStreamProcessor::PING_REQ_ID);
});

test('dispatches a copy when contract details arrive', function () {
    $processor = new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID);
    $reqId = $processor->process(streamBuy())[0]['req_id'];

    expect($processor->process(streamContract($reqId)))->toBe([]);

    Bus::assertDispatched(CopyTradeJob::class, fn (CopyTradeJob $job) => $job->masterConnectionId === STREAM_TEST_CONNECTION_ID
        && $job->masterTrade['symbol'] === 'R_50'
        && $job->masterTrade['contract_type'] === 'DIGITOVER'
        && $job->masterTrade['duration'] === 5
        && $job->masterTrade['duration_unit'] === 't'
        && $job->masterTrade['barrier'] === '3'
        && $job->masterTrade['buy_price'] === 10.0);
});

test('skips the copy when contract details return an error', function () {
    $processor = new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID);
    $reqId = $processor->process(streamBuy())[0]['req_id'];

    $outgoing = $processor->process([
        'msg_type' => 'proposal_open_contract',
        'req_id' => $reqId,
        'error' => ['code' => 'ContractNotFound', 'message' => 'Not found'],
    ]);

    expect($outgoing)->toBe([]);
    Bus::assertNotDispatched(CopyTradeJob::class);
});

test('skips uncopyable contracts', function () {
    $processor = new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID);
    $reqId = $processor->process(streamBuy())[0]['req_id'];

    $processor->process(streamContract($reqId, ['contract_type' => 'MULTUP']));

    Bus::assertNotDispatched(CopyTradeJob::class);
});

test('ignores contract details nobody asked for', function () {
    (new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID))->process(streamContract(12345));

    Bus::assertNotDispatched(CopyTradeJob::class);
});

test('throws when the transaction subscription fails', function () {
    (new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID))->process([
        'msg_type' => 'transaction',
        'req_id' => MasterStreamProcessor::TRANSACTION_REQ_ID,
        'error' => ['code' => 'AuthorizationRequired', 'message' => 'Please log in.'],
    ]);
})->throws(RuntimeException::class);

test('ignores a buy without contract id', function () {
    $outgoing = (new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID))->process(streamBuy(['contract_id' => null]));

    expect($outgoing)->toBe([]);
    Bus::assertNotDispatched(CopyTradeJob::class);
});

test('records an early sell at a loss as a loss', function () {
    $processor = new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID);
    $processor->process(streamBuy(['amount' => -10]));
    $processor->process(streamSell(4));

    expect(Redis::lrange('master_outcomes:'.STREAM_TEST_CONNECTION_ID, 0, 0))->toBe(['0'])
        ->and(Redis::get('master_outcomes_count:'.STREAM_TEST_CONNECTION_ID))->toBe('1');
});

test('records a profitable sell as a win', function () {
    $processor = new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID);
    $processor->process(streamBuy(['amount' => -10]));
    $processor->process(streamSell(19));

    expect(Redis::lrange('master_outcomes:'.STREAM_TEST_CONNECTION_ID, 0, 0))->toBe(['1']);
});

test('remembers buy prices across listener restarts', function () {
    (new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID))->process(streamBuy(['amount' => -10]));
    (new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID))->process(streamSell(19));

    expect(Redis::lrange('master_outcomes:'.STREAM_TEST_CONNECTION_ID, 0, 0))->toBe(['1']);
});

test('records nothing but still releases pattern locks when the buy price is unknown', function () {
    $master = DerivConnection::factory()->master()->create(['id' => STREAM_TEST_CONNECTION_ID]);
    $setting = CopySetting::factory()->create(['master_connection_id' => $master->id, 'is_running' => true]);
    $lockKey = CopyTradeJob::patternConsumedKey($master->id, $setting->user_id);
    Cache::put($lockKey, true, 600);

    (new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID))->process(streamSell(19));

    expect(Redis::llen('master_outcomes:'.STREAM_TEST_CONNECTION_ID))->toBe(0)
        ->and(Redis::get('master_outcomes_count:'.STREAM_TEST_CONNECTION_ID))->toBeNull()
        ->and(Cache::has($lockKey))->toBeFalse();
});

test('copy fan-out goes on the high queue', function () {
    $processor = new MasterStreamProcessor(STREAM_TEST_CONNECTION_ID);
    $reqId = $processor->process(streamBuy())[0]['req_id'];

    $processor->process(streamContract($reqId));

    Bus::assertDispatched(CopyTradeJob::class, fn (CopyTradeJob $job) => $job->queue === 'high');
});
