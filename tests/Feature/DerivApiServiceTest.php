<?php

use App\Exceptions\DerivApiException;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use WebSocket\Client;

beforeEach(function () {
    Http::fake([
        'api.derivws.com/trading/v1/options/accounts' => Http::response(['data' => [
            ['account_id' => 'DOT100', 'currency' => 'USD', 'account_type' => 'demo', 'status' => 'active'],
            ['account_id' => 'DOT200', 'currency' => 'USD', 'account_type' => 'real', 'status' => 'active'],
        ]]),
        'api.derivws.com/trading/v1/options/accounts/*/otp' => fn (Request $request) => basename(dirname($request->url())) === 'DOT999'
            ? Http::response(['errors' => [['status' => 404, 'code' => 'AccountNotFound', 'message' => 'Account not found']]], 404)
            : Http::response(['data' => ['url' => 'wss://api.derivws.com/trading/v1/options/ws/demo?otp='.basename(dirname($request->url()))]]),
    ]);
});

/**
 * A DerivApiService whose sockets are fakes. Every socket answers from one shared queue of responses
 * (a Throwable in the queue is thrown by receive()), and each socket must be closed exactly once.
 *
 * @param  list<array<string, mixed>|Throwable>  $responses
 * @return array{0: DerivApiService, 1: ArrayObject<string, list<mixed>>}
 */
function fakeDerivSocket(array $responses, int $sockets = 1): array
{
    $record = new ArrayObject(['urls' => [], 'sent' => []]);
    $queue = new ArrayObject($responses);

    $service = Mockery::mock(DerivApiService::class)->makePartial()->shouldAllowMockingProtectedMethods();

    $service->shouldReceive('openSocket')->times($sockets)->andReturnUsing(function (string $url) use ($record, $queue) {
        $record['urls'] = [...$record['urls'], $url];

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('text')->andReturnUsing(function (string $payload) use ($record) {
            $record['sent'] = [...$record['sent'], json_decode($payload, true)];
        });
        $client->shouldReceive('receive')->andReturnUsing(function () use ($queue) {
            $items = $queue->getArrayCopy();
            $next = array_shift($items);
            $queue->exchangeArray($items);

            if ($next instanceof Throwable) {
                throw $next;
            }

            return json_encode($next ?? []);
        });
        $client->shouldReceive('close')->once();

        return $client;
    });

    return [$service, $record];
}

test('ws calls open an otp session for the named account', function () {
    [$service, $record] = fakeDerivSocket([
        ['proposal' => ['id' => 'p1']],
        ['buy' => ['contract_id' => 11, 'transaction_id' => 22]],
    ]);

    $service->buyContract(DerivConnection::factory()->create(), [
        'contract_type' => 'CALL',
        'symbol' => 'R_50',
        'duration' => 5,
        'duration_unit' => 't',
        'stake' => 1.0,
        'currency' => 'USD',
        'follower_account_id' => 'DOT200',
    ]);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/accounts/DOT200/otp'));
    expect($record['urls'])->toBe(['wss://api.derivws.com/trading/v1/options/ws/demo?otp=DOT200']);
});

test('closes the socket when the call errors', function () {
    [$service] = fakeDerivSocket([['error' => ['message' => 'Bad']]]);

    expect(fn () => $service->getBalance(DerivConnection::factory()->create()))
        ->toThrow(DerivApiException::class, 'Bad');
});

test('closes the socket when receive throws', function () {
    [$service] = fakeDerivSocket([new RuntimeException('boom')]);

    expect(fn () => $service->getBalance(DerivConnection::factory()->create()))
        ->toThrow(DerivApiException::class, 'Deriv WebSocket unavailable: boom');
});

test('clearCache refreshes every cached call', function () {
    [$service] = fakeDerivSocket([
        ['profit_table' => ['transactions' => [['contract_id' => 1]]]],
        ['profit_table' => ['transactions' => [['contract_id' => 2]]]],
    ], sockets: 2);
    $connection = DerivConnection::factory()->create();

    $first = $service->getProfitTable($connection, 20, 0);
    $cached = $service->getProfitTable($connection, 20, 0);
    $service->clearCache($connection);
    $fresh = $service->getProfitTable($connection, 20, 0);

    expect($cached)->toBe($first)
        ->and($fresh['profit_table']['transactions'][0]['contract_id'])->toBe(2);
});

test('otp failure surfaces the deriv message', function () {
    [$service] = fakeDerivSocket([], sockets: 0);

    expect(fn () => $service->getOtpUrl(DerivConnection::factory()->create(), 'DOT999'))
        ->toThrow(DerivApiException::class, 'Account not found');
});
