<?php

use App\Exceptions\DerivApiException;
use App\Exceptions\DerivAuthException;
use App\Exceptions\DerivRateLimitedException;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\DerivRateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use WebSocket\Client;

beforeEach(function () {
    $this->otpResponses = new ArrayObject([
        'DOT999' => Http::response(['errors' => [['status' => 404, 'code' => 'AccountNotFound', 'message' => 'Account not found']]], 404),
    ]);
    $otpResponses = $this->otpResponses;

    Http::fake([
        'api.derivws.com/trading/v1/options/accounts' => Http::response(['data' => [
            ['account_id' => 'DOT100', 'currency' => 'USD', 'account_type' => 'demo', 'status' => 'active'],
            ['account_id' => 'DOT200', 'currency' => 'USD', 'account_type' => 'real', 'status' => 'active'],
        ]]),
        'api.derivws.com/trading/v1/options/accounts/*/otp' => fn (Request $request) => $otpResponses[basename(dirname($request->url()))]
            ?? Http::response(['data' => ['url' => 'wss://api.derivws.com/trading/v1/options/ws/demo?otp='.basename(dirname($request->url()))]]),
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

    $service = Mockery::mock(DerivApiService::class, [app(DerivRateLimiter::class)])->makePartial()->shouldAllowMockingProtectedMethods();

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

    expect(fn () => $service->getBalance(DerivConnection::factory()->create(), 'DOT100'))
        ->toThrow(DerivApiException::class, 'Bad');
});

test('closes the socket when receive throws', function () {
    [$service] = fakeDerivSocket([new RuntimeException('boom')]);

    expect(fn () => $service->getBalance(DerivConnection::factory()->create(), 'DOT100'))
        ->toThrow(DerivApiException::class, 'Deriv WebSocket unavailable: boom');
});

test('clearCache refreshes every cached call', function () {
    [$service] = fakeDerivSocket([
        ['profit_table' => ['transactions' => [['contract_id' => 1]]]],
        ['profit_table' => ['transactions' => [['contract_id' => 2]]]],
    ], sockets: 2);
    $connection = DerivConnection::factory()->create();

    $first = $service->getProfitTable($connection, 'DOT100', 20, 0);
    $cached = $service->getProfitTable($connection, 'DOT100', 20, 0);
    $service->clearCache($connection);
    $fresh = $service->getProfitTable($connection, 'DOT100', 20, 0);

    expect($cached)->toBe($first)
        ->and($fresh['profit_table']['transactions'][0]['contract_id'])->toBe(2);
});

test('otp failure surfaces the deriv message', function () {
    [$service] = fakeDerivSocket([], sockets: 0);

    expect(fn () => $service->getOtpUrl(DerivConnection::factory()->create(), 'DOT999'))
        ->toThrow(DerivApiException::class, 'Account not found');
});

test('balance is cached per account', function () {
    [$service, $record] = fakeDerivSocket([
        ['balance' => ['balance' => 10, 'currency' => 'USD']],
        ['balance' => ['balance' => 20, 'currency' => 'USD']],
    ], sockets: 2);
    $connection = DerivConnection::factory()->create();

    $demo = $service->getBalance($connection, 'DOT100');
    $real = $service->getBalance($connection, 'DOT200');
    $demoAgain = $service->getBalance($connection, 'DOT100');

    expect($demo['balance']['balance'])->toBe(10)
        ->and($real['balance']['balance'])->toBe(20)
        ->and($demoAgain)->toBe($demo)
        ->and($record['urls'])->toBe([
            'wss://api.derivws.com/trading/v1/options/ws/demo?otp=DOT100',
            'wss://api.derivws.com/trading/v1/options/ws/demo?otp=DOT200',
        ]);
});

test('contract details are read on the given account', function () {
    [$service, $record] = fakeDerivSocket([
        ['proposal_open_contract' => ['contract_id' => 123, 'is_sold' => 1]],
    ]);

    $contract = $service->getContractDetails(DerivConnection::factory()->create(), 'DOT200', '123');

    expect($contract)->toBe(['contract_id' => 123, 'is_sold' => 1])
        ->and($record['urls'])->toBe(['wss://api.derivws.com/trading/v1/options/ws/demo?otp=DOT200'])
        ->and($record['sent'][0])->toMatchArray(['proposal_open_contract' => 1, 'contract_id' => 123]);
});

test('buying without a follower account fails before any session opens', function () {
    [$service] = fakeDerivSocket([], sockets: 0);

    expect(fn () => $service->buyContract(DerivConnection::factory()->create(), [
        'contract_type' => 'CALL',
        'symbol' => 'R_50',
        'duration' => 5,
        'duration_unit' => 't',
        'stake' => 1.0,
        'currency' => 'USD',
    ]))->toThrow(DerivApiException::class, 'No follower account selected.');

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/otp'));
});

test('profit table since sends an uncached string date_from', function () {
    [$service, $record] = fakeDerivSocket([
        ['profit_table' => ['count' => 1, 'transactions' => [['contract_id' => 9]]]],
        ['profit_table' => ['count' => 0, 'transactions' => []]],
    ], sockets: 2);
    $connection = DerivConnection::factory()->create();

    $first = $service->getProfitTableSince($connection, 'DOT200', 1761050000);
    $second = $service->getProfitTableSince($connection, 'DOT200', 1761050000);

    expect($first)->toBe([['contract_id' => 9]])
        ->and($second)->toBe([])
        ->and($record['urls'][0])->toEndWith('otp=DOT200')
        ->and($record['sent'][0])->toMatchArray(['profit_table' => 1, 'date_from' => '1761050000', 'limit' => 500, 'sort' => 'ASC']);
});

test('a ws call makes exactly one rest request', function () {
    [$service] = fakeDerivSocket([['balance' => ['balance' => 1]]]);

    $service->getBalance(DerivConnection::factory()->create(), 'DOT100');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/accounts/DOT100/otp'));
});

test('a 429 starts a cooldown and throws the rate-limit error', function () {
    $this->freezeSecond();
    $this->otpResponses['DOT100'] = Http::response(['errors' => [['status' => 429, 'code' => 'RateLimit', 'message' => 'Too many requests']]], 429, ['Retry-After' => '40']);
    [$service] = fakeDerivSocket([], sockets: 0);

    expect(fn () => $service->getBalance(DerivConnection::factory()->create(), 'DOT100'))
        ->toThrow(DerivRateLimitedException::class, 'Deriv rate limit reached — try again shortly.');

    expect(Cache::get(DerivRateLimiter::COOLDOWN_KEY))->toBe(now()->addSeconds(40)->timestamp);
});

test('a RateLimit error code is treated like a 429', function () {
    $this->freezeSecond();
    $this->otpResponses['DOT100'] = Http::response(['errors' => [['status' => 400, 'code' => 'RateLimit', 'message' => 'Slow down']]], 400);
    [$service] = fakeDerivSocket([], sockets: 0);

    expect(fn () => $service->getBalance(DerivConnection::factory()->create(), 'DOT100'))
        ->toThrow(DerivRateLimitedException::class);

    expect(Cache::get(DerivRateLimiter::COOLDOWN_KEY))->toBe(now()->addSeconds(30)->timestamp);
});

test('throttled reads serve the last good value', function () {
    [$service] = fakeDerivSocket([['balance' => ['balance' => 12.5]]]);
    $connection = DerivConnection::factory()->create();

    $fresh = $service->getBalance($connection, 'DOT100');
    $service->clearCache($connection);
    app(DerivRateLimiter::class)->coolDown(60);

    expect($service->getBalance($connection, 'DOT100'))->toBe($fresh);
});

test('stale copies are only served when throttled', function () {
    [$service] = fakeDerivSocket([['balance' => ['balance' => 12.5]]]);
    $connection = DerivConnection::factory()->create();

    $service->getBalance($connection, 'DOT100');
    $service->clearCache($connection);
    $this->otpResponses['DOT100'] = Http::response(['errors' => [['status' => 401, 'code' => 'Unauthorized', 'message' => 'Token expired']]], 401);

    expect(fn () => $service->getBalance($connection, 'DOT100'))
        ->toThrow(DerivApiException::class, 'Token expired');
});

test('buys use trade priority', function () {
    config(['deriv.rate_limits.read_share' => 0]);
    [$service] = fakeDerivSocket([
        ['proposal' => ['id' => 'p1']],
        ['buy' => ['contract_id' => 11, 'transaction_id' => 22]],
    ]);

    $result = $service->buyContract(DerivConnection::factory()->create(), [
        'contract_type' => 'CALL',
        'symbol' => 'R_50',
        'duration' => 5,
        'duration_unit' => 't',
        'stake' => 1.0,
        'currency' => 'USD',
        'follower_account_id' => 'DOT200',
    ]);

    expect($result['buy']['contract_id'])->toBe(11);
    expect(fn () => $service->getBalance(DerivConnection::factory()->create(), 'DOT100'))->toThrow(DerivRateLimitedException::class);
});

test('a longer max age reuses the cached balance', function () {
    [$service] = fakeDerivSocket([['balance' => ['balance' => 5]]]);
    $connection = DerivConnection::factory()->create();

    $first = $service->getBalance($connection, 'DOT100', 120);
    $this->travel(60)->seconds();

    expect($service->getBalance($connection, 'DOT100', 120))->toBe($first);
});

test('default callers never get the two minute balance', function () {
    [$service] = fakeDerivSocket([['balance' => ['balance' => 5]], ['balance' => ['balance' => 9]]], sockets: 2);
    $connection = DerivConnection::factory()->create();

    $service->getBalance($connection, 'DOT100', 120);

    expect($service->getBalance($connection, 'DOT100')['balance']['balance'])->toBe(9);
});

test('a balance read that refuses stale copies throws when throttled', function () {
    [$service] = fakeDerivSocket([['balance' => ['balance' => 12.5]]]);
    $connection = DerivConnection::factory()->create();

    $service->getBalance($connection, 'DOT100');
    $service->clearCache($connection);
    app(DerivRateLimiter::class)->coolDown(60);

    expect(fn () => $service->getBalance($connection, 'DOT100', allowStale: false))
        ->toThrow(DerivRateLimitedException::class);
});

test('an otp 401 throws DerivAuthException', function () {
    $this->otpResponses['DOT100'] = Http::response(['errors' => [['code' => 'InvalidToken', 'message' => 'Token revoked']]], 401);

    expect(fn () => app(DerivApiService::class)->getOtpUrl(DerivConnection::factory()->create(), 'DOT100'))
        ->toThrow(DerivAuthException::class, 'Token revoked');
});

test('an accounts 401 throws DerivAuthException', function () {
    Http::swap(new Factory);
    Http::fake(['api.derivws.com/trading/v1/options/accounts' => Http::response(['errors' => [['code' => 'InvalidToken', 'message' => 'Token revoked']]], 401)]);

    expect(fn () => app(DerivApiService::class)->getAccounts(DerivConnection::factory()->create()))
        ->toThrow(DerivAuthException::class);
});

test('getAccounts refreshes a token about to expire before calling deriv', function () {
    Http::fake(['auth.deriv.com/oauth2/token' => Http::response(['access_token' => 'at_new', 'expires_in' => 3600])]);
    $connection = DerivConnection::factory()->create(['access_token' => 'at_old', 'refresh_token' => 'rt_1', 'expires_at' => now()->addMinute()]);

    app(DerivApiService::class)->getAccounts($connection);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/accounts')
        && $request->hasHeader('Authorization', 'Bearer at_new'));
    Http::assertNotSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer at_old'));
});

test('rest calls send the app id that matches the token type', function (string $tokenType, ?string $patAppId, string $expected) {
    config(['deriv.app_id' => 'oauth-app', 'deriv.app_id_pat' => $patAppId]);
    $connection = DerivConnection::factory()->create(['token_type' => $tokenType]);

    app(DerivApiService::class)->getAccounts($connection);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/accounts')
        && $request->hasHeader('Deriv-App-ID', $expected));
})->with([
    'pat' => ['pat', 'pat-app', 'pat-app'],
    'pat without a pat app id' => ['pat', null, 'oauth-app'],
    'oauth' => ['Bearer', 'pat-app', 'oauth-app'],
]);

test('checkPat sends the pat app id', function () {
    config(['deriv.app_id' => 'oauth-app', 'deriv.app_id_pat' => 'pat-app']);

    app(DerivApiService::class)->checkPat('a1b2c3d4e5f6g7h8i9j0');

    Http::assertSent(fn (Request $request) => $request->hasHeader('Deriv-App-ID', 'pat-app')
        && $request->hasHeader('Authorization', 'Bearer a1b2c3d4e5f6g7h8i9j0'));
});
