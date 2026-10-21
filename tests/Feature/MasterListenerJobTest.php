<?php

use App\Exceptions\DerivApiException;
use App\Exceptions\DerivAuthException;
use App\Jobs\MasterListenerJob;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\DerivRateLimiter;
use App\Services\WebSocketFrameCodec;
use Carbon\CarbonInterval;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

use function Pest\Laravel\mock;

/**
 * A listener whose sessions run over a local socket pair. Its openSocket writes the handshake reply
 * and $serverBytes to the peer, then runs the real handshake, so tests drive genuine framing.
 *
 * @return array{0: MasterListenerJob, 1: resource}
 */
function listenerOverPair(int $connectionId, string $serverBytes = '', string $handshakeReply = "HTTP/1.1 101 Switching Protocols\r\n\r\n"): array
{
    [$listenerEnd, $peer] = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    $job = new class($connectionId, $listenerEnd, $peer, $handshakeReply.$serverBytes) extends MasterListenerJob
    {
        protected float $readTimeoutSeconds = 0.2;

        /**
         * @param  resource  $listenerEnd
         * @param  resource  $peer
         */
        public function __construct(int $connectionId, private $listenerEnd, private $peer, private string $serverBytes)
        {
            parent::__construct($connectionId);
        }

        protected function openSocket(string $wsUrl)
        {
            fwrite($this->peer, $this->serverBytes);
            $this->performHandshake($this->listenerEnd, $wsUrl);

            return $this->listenerEnd;
        }
    };

    return [$job, $peer];
}

/** An unmasked frame as Deriv's server sends it. */
function serverFrame(int $opcode, string $payload, bool $fin = true): string
{
    $length = strlen($payload);
    $header = chr(($fin ? 0x80 : 0) | $opcode);

    return $length <= 125
        ? $header.chr($length).$payload
        : $header.chr(126).pack('n', $length).$payload;
}

/**
 * Every frame the listener wrote to the peer after its handshake request.
 *
 * @param  resource  $peer
 * @return list<array{opcode: int, payload: string}>
 */
function listenerFrames($peer): array
{
    stream_set_blocking($peer, false);
    $bytes = (string) stream_get_contents($peer);
    $codec = new WebSocketFrameCodec;
    $codec->push(substr($bytes, strpos($bytes, "\r\n\r\n") + 4));
    $frames = [];

    while (($frame = $codec->next()) !== null) {
        $frames[] = $frame;
    }

    return $frames;
}

/**
 * Run each step on successive heartbeat writes made inside a session. The loop's own write before
 * the OTP is skipped; once the steps run out, the next write switches every follower off.
 */
function onSessionHeartbeats(int $connectionId, Closure ...$steps): void
{
    $writes = 0;

    Event::listen(KeyWritten::class, function (KeyWritten $event) use ($connectionId, $steps, &$writes): void {
        if ($event->key !== MasterListenerJob::heartbeatKey($connectionId)) {
            return;
        }

        $writes++;

        if ($writes === 1) {
            return;
        }

        $step = $steps[$writes - 2] ?? fn () => CopySetting::query()->update(['is_running' => false]);
        $step();
    });
}

/** OTP mock: the first call opens a session; any later call switches followers off and ends the loop. */
function otpOpensOneSession(): ArrayObject
{
    $calls = new ArrayObject;

    mock(DerivApiService::class)
        ->shouldReceive('getOtpUrl')
        ->andReturnUsing(function (...$arguments) use ($calls) {
            $calls[] = $arguments;

            if (count($calls) > 1) {
                CopySetting::query()->update(['is_running' => false]);

                throw new DerivApiException('stop after one session');
            }

            return 'wss://ws.derivws.com/websockets/v3?otp=abc';
        });

    return $calls;
}

/** A running follower on a fresh master connection whose admin-chosen account is DOT500. */
function runningMaster(): DerivConnection
{
    $master = DerivConnection::factory()->master()->create(['master_account_id' => 'DOT500']);
    CopySetting::factory()->create(['master_connection_id' => $master->id, 'is_running' => true]);

    return $master;
}

test('the listener\'s otp is a trade call', function () {
    Sleep::fake();
    $master = runningMaster();
    $calls = new ArrayObject;

    mock(DerivApiService::class)
        ->shouldReceive('getOtpUrl')
        ->andReturnUsing(function (...$arguments) use ($calls) {
            $calls[] = $arguments;
            CopySetting::query()->update(['is_running' => false]);

            throw new DerivApiException('stop after one session attempt');
        });

    (new MasterListenerJob($master->id))->handle();

    expect($calls)->toHaveCount(1)
        ->and($calls[0][1])->toBe('DOT500')
        ->and($calls[0][2] ?? null)->toBe(DerivRateLimiter::PRIORITY_TRADE);

    Sleep::assertSleptTimes(1);
});

test('ping is answered with the same payload', function () {
    Sleep::fake();
    $master = runningMaster();
    otpOpensOneSession();
    onSessionHeartbeats($master->id);
    [$job, $peer] = listenerOverPair($master->id, serverFrame(WebSocketFrameCodec::OPCODE_PING, 'hb1'));

    $job->handle();

    expect(collect(listenerFrames($peer))->firstWhere('opcode', WebSocketFrameCodec::OPCODE_PONG))
        ->toBe(['opcode' => WebSocketFrameCodec::OPCODE_PONG, 'payload' => 'hb1']);
});

test('a message split across a read timeout is still processed', function () {
    Sleep::fake();
    $master = runningMaster();
    otpOpensOneSession();
    $frame = serverFrame(WebSocketFrameCodec::OPCODE_TEXT, json_encode([
        'msg_type' => 'transaction',
        'transaction' => ['action' => 'buy', 'contract_id' => 4242, 'transaction_id' => 1, 'symbol' => 'R_50'],
    ]));
    $half = intdiv(strlen($frame), 2);
    [$job, $peer] = listenerOverPair($master->id, substr($frame, 0, $half));
    onSessionHeartbeats($master->id, fn () => fwrite($peer, substr($frame, $half)));

    $job->handle();

    $requests = collect(listenerFrames($peer))
        ->where('opcode', WebSocketFrameCodec::OPCODE_TEXT)
        ->map(fn (array $frame) => json_decode($frame['payload'], true));

    expect($requests->firstWhere('proposal_open_contract', 1))->toMatchArray(['contract_id' => 4242]);
});

test('a close frame is echoed and the session ends', function () {
    Sleep::fake();
    $master = runningMaster();
    $calls = otpOpensOneSession();
    [$job, $peer] = listenerOverPair($master->id, serverFrame(WebSocketFrameCodec::OPCODE_CLOSE, "\x03\xE8"));

    $job->handle();

    expect(collect(listenerFrames($peer))->firstWhere('opcode', WebSocketFrameCodec::OPCODE_CLOSE))
        ->toBe(['opcode' => WebSocketFrameCodec::OPCODE_CLOSE, 'payload' => "\x03\xE8"])
        ->and($calls)->toHaveCount(2);
});

test('a handshake without 101 fails the session', function () {
    Sleep::fake();
    Log::spy();
    $master = runningMaster();
    $calls = otpOpensOneSession();
    [$job, $peer] = listenerOverPair($master->id, handshakeReply: "HTTP/1.1 403 Forbidden\r\n\r\n");

    $job->handle();

    expect(listenerFrames($peer))->toBe([])
        ->and($calls)->toHaveCount(2);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'session ended') && str_contains($message, '403'));
});

/**
 * A listener that believes pcntl is loaded: it reports $previous as the worker's handler for every
 * signal and records each handler it installs as [signal, handler].
 */
function listenerWithSignals(int $connectionId, ArrayObject $installs, Closure $previous): MasterListenerJob
{
    return new class($connectionId, $installs, $previous) extends MasterListenerJob
    {
        public function __construct(int $connectionId, private ArrayObject $installs, private Closure $previous)
        {
            parent::__construct($connectionId);
        }

        protected function supportsSignals(): bool
        {
            return true;
        }

        protected function signalHandler(int $signal): mixed
        {
            return $this->previous;
        }

        protected function installSignalHandler(int $signal, mixed $handler): void
        {
            $this->installs[] = [$signal, $handler];
        }
    };
}

test('the worker\'s handler still runs on SIGTERM', function () {
    Sleep::fake();
    $master = runningMaster();
    $installs = new ArrayObject;
    $workerCalls = new ArrayObject;
    $otpCalls = new ArrayObject;

    mock(DerivApiService::class)
        ->shouldReceive('getOtpUrl')
        ->andReturnUsing(function () use ($installs, $otpCalls) {
            $otpCalls[] = true;
            $sigterm = collect($installs)->first(fn (array $install) => $install[0] === 15);

            if ($sigterm === null) {
                CopySetting::query()->update(['is_running' => false]);

                throw new DerivApiException('no SIGTERM handler installed');
            }

            ($sigterm[1])(15);

            throw new DerivApiException('interrupted by SIGTERM');
        });

    listenerWithSignals($master->id, $installs, function () use ($workerCalls) {
        $workerCalls[] = true;
    })->handle();

    expect($workerCalls)->toHaveCount(1)
        ->and($otpCalls)->toHaveCount(1);

    Sleep::assertNeverSlept();
});

test('the worker\'s handlers are restored when the job ends', function () {
    Sleep::fake();
    $master = runningMaster();
    $installs = new ArrayObject;
    $previous = fn () => null;

    mock(DerivApiService::class)
        ->shouldReceive('getOtpUrl')
        ->andReturnUsing(function () {
            CopySetting::query()->update(['is_running' => false]);

            throw new DerivApiException('stop');
        });

    listenerWithSignals($master->id, $installs, $previous)->handle();

    $last = collect($installs)->groupBy(fn (array $install) => $install[0])->map(fn ($group) => $group->last()[1]);

    expect($last->keys()->sort()->values()->all())->toBe([2, 15])
        ->and($last[15])->toBe($previous)
        ->and($last[2])->toBe($previous);
});

test('a dead token stops the listener and its followers', function () {
    Sleep::fake();
    $master = DerivConnection::factory()->master()->create(['expires_at' => null, 'master_account_id' => 'DOT500']);
    $followers = CopySetting::factory()->count(2)->create(['master_connection_id' => $master->id, 'is_running' => true]);
    $otherMaster = DerivConnection::factory()->master()->create();
    $bystander = CopySetting::factory()->create(['master_connection_id' => $otherMaster->id, 'is_running' => true]);
    $otpCalls = new ArrayObject;

    mock(DerivApiService::class)
        ->shouldReceive('getOtpUrl')
        ->andReturnUsing(function () use ($otpCalls) {
            $otpCalls[] = true;

            if (count($otpCalls) > 1) {
                CopySetting::query()->update(['is_running' => false]);
            }

            throw new DerivAuthException('Token revoked');
        });

    (new MasterListenerJob($master->id))->handle();

    expect($otpCalls)->toHaveCount(1)
        ->and($master->fresh()->isExpired())->toBeTrue()
        ->and($followers->map(fn (CopySetting $setting) => $setting->fresh()->only('is_running', 'stop_reason'))->all())
        ->each->toBe(['is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_REAUTH])
        ->and($bystander->fresh()->is_running)->toBeTrue();

    Sleep::assertNeverSlept();
});

test('other failures back off exponentially with jitter', function () {
    Sleep::fake();
    $durations = new ArrayObject;
    Sleep::whenFakingSleep(function (CarbonInterval $duration) use ($durations) {
        $durations[] = $duration->totalSeconds;
    });
    $master = runningMaster();
    $otpCalls = new ArrayObject;

    mock(DerivApiService::class)
        ->shouldReceive('getOtpUrl')
        ->andReturnUsing(function () use ($otpCalls) {
            $otpCalls[] = true;

            if (count($otpCalls) === 4) {
                CopySetting::query()->update(['is_running' => false]);
            }

            throw new DerivApiException('Deriv unreachable');
        });

    (new MasterListenerJob($master->id))->handle();

    expect($durations)->toHaveCount(4);

    foreach ([[2, 5], [5, 10], [10, 20], [20, 40]] as $i => [$low, $high]) {
        expect($durations[$i])->toBeGreaterThanOrEqual($low)->toBeLessThanOrEqual($high);
    }
});

test('backoff is capped at five minutes', function () {
    expect(MasterListenerJob::backoffSeconds(1))->toBe(5)
        ->and(MasterListenerJob::backoffSeconds(4))->toBe(40)
        ->and(MasterListenerJob::backoffSeconds(10))->toBe(300)
        ->and(MasterListenerJob::backoffSeconds(64))->toBe(300);
});

/**
 * OTP mock that records the account of each call, then switches followers off and ends the loop.
 *
 * @return ArrayObject<int, string>
 */
function otpAccounts(): ArrayObject
{
    $accounts = new ArrayObject;

    mock(DerivApiService::class)
        ->shouldReceive('getOtpUrl')
        ->andReturnUsing(function (DerivConnection $connection, string $accountId) use ($accounts) {
            $accounts[] = $accountId;
            CopySetting::query()->update(['is_running' => false]);

            throw new DerivApiException('stop after one session attempt');
        });

    return $accounts;
}

test('a master listens to the admin-chosen account', function () {
    Sleep::fake();
    $master = DerivConnection::factory()->master()->create(['master_account_id' => 'CR900']);
    CopySetting::factory()->create(['master_connection_id' => $master->id, 'is_running' => true]);
    $accounts = otpAccounts();

    (new MasterListenerJob($master->id))->handle();

    expect($accounts->getArrayCopy())->toBe(['CR900']);
});

test('a follower\'s master_account_id never redirects a master listener', function () {
    Sleep::fake();
    $master = DerivConnection::factory()->master()->create(['master_account_id' => 'CR900']);
    CopySetting::factory()->create(['master_connection_id' => $master->id, 'master_account_id' => 'VRTC111', 'is_running' => true]);
    $accounts = otpAccounts();

    (new MasterListenerJob($master->id))->handle();

    expect($accounts->getArrayCopy())->toBe(['CR900']);
});

test('a master without a chosen account uses its first active account at trade priority', function () {
    Sleep::fake();
    $master = DerivConnection::factory()->master()->create(['master_account_id' => null]);
    CopySetting::factory()->create(['master_connection_id' => $master->id, 'is_running' => true]);
    $accounts = new ArrayObject;
    $priorities = new ArrayObject;

    mock(DerivApiService::class)
        ->shouldReceive('getAccounts')
        ->andReturnUsing(function (DerivConnection $connection, string $priority = DerivRateLimiter::PRIORITY_READ) use ($priorities) {
            $priorities[] = $priority;

            return [
                ['account_id' => 'CR111', 'status' => 'inactive'],
                ['account_id' => 'CR777', 'status' => 'active'],
            ];
        })
        ->getMock()
        ->shouldReceive('getOtpUrl')
        ->andReturnUsing(function (DerivConnection $connection, string $accountId) use ($accounts) {
            $accounts[] = $accountId;
            CopySetting::query()->update(['is_running' => false]);

            throw new DerivApiException('stop after one session attempt');
        });

    (new MasterListenerJob($master->id))->handle();

    expect($accounts->getArrayCopy())->toBe(['CR777'])
        ->and($priorities->getArrayCopy())->toBe([DerivRateLimiter::PRIORITY_TRADE]);
});

test('an own-account listener uses its owner\'s setting', function () {
    Sleep::fake();
    $own = DerivConnection::factory()->create();
    CopySetting::factory()->create(['user_id' => $own->user_id, 'master_connection_id' => $own->id, 'master_account_id' => 'VRTC222', 'is_running' => true]);
    $accounts = otpAccounts();

    (new MasterListenerJob($own->id))->handle();

    expect($accounts->getArrayCopy())->toBe(['VRTC222']);
});

test('another user\'s setting cannot keep an own-account listener alive', function () {
    Sleep::fake();
    $own = DerivConnection::factory()->create();
    CopySetting::factory()->create(['master_connection_id' => $own->id, 'master_account_id' => 'VRTC333', 'is_running' => true]);
    $accounts = otpAccounts();

    (new MasterListenerJob($own->id))->handle();

    expect($accounts->getArrayCopy())->toBe([]);
});

test('an admin account change ends the session', function () {
    Sleep::fake();
    $master = runningMaster();
    $calls = otpOpensOneSession();
    [$job] = listenerOverPair($master->id);
    onSessionHeartbeats($master->id, fn () => $master->update(['master_account_id' => 'CR901']));

    $job->handle();

    expect(array_map(fn (array $call) => $call[1], $calls->getArrayCopy()))->toBe(['DOT500', 'CR901']);
});
