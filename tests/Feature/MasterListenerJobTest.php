<?php

use App\Exceptions\DerivApiException;
use App\Jobs\MasterListenerJob;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\DerivRateLimiter;
use App\Services\WebSocketFrameCodec;
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

/** A running follower on a fresh master connection with a known master account. */
function runningMaster(): DerivConnection
{
    $master = DerivConnection::factory()->master()->create();
    CopySetting::factory()->create(['master_connection_id' => $master->id, 'master_account_id' => 'DOT500', 'is_running' => true]);

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
