<?php

namespace App\Jobs;

use App\Exceptions\DerivAuthException;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\DerivRateLimiter;
use App\Services\MasterStreamProcessor;
use App\Services\WebSocketFrameCodec;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class MasterListenerJob implements ShouldQueue
{
    use Queueable;

    /** Never time out — this job runs for as long as the session is active. */
    public int $timeout = 0;

    /** Single attempt; the scheduler restarts it if it dies. */
    public int $tries = 1;

    /** POSIX signal numbers; Windows PHP has no SIGTERM/SIGINT constants. */
    private const SIGTERM = 15;

    private const SIGINT = 2;

    /** Reconnect delays double from this many seconds after each failure in a row... */
    private const BACKOFF_BASE_SECONDS = 5;

    /** ...up to this ceiling. */
    private const BACKOFF_MAX_SECONDS = 300;

    /**
     * The running key lives 60 s and the heartbeat 90 s; refreshing them at least this often, during
     * sessions and through long backoff sleeps, keeps a second listener from starting beside this one.
     */
    private const HEARTBEAT_REFRESH_SECONDS = 20;

    /** A session that lasted this long counts as healthy and resets the failure count. */
    private const HEALTHY_SESSION_SECONDS = 60;

    /** Hand over to a fresh job before the listener connection's 24 h retry_after re-queues this one. */
    private const MAX_LIFETIME_SECONDS = 82800;

    /** How long a queued-but-not-started job blocks another dispatch for the same connection. */
    private const QUEUED_MARKER_SECONDS = 600;

    /** Seconds a read waits before the idle tick (heartbeat, stop check, ping). */
    protected float $readTimeoutSeconds = 30.0;

    /** When this run started, for the lifetime limit. */
    private int $startedAt = 0;

    /** Set once a successor is queued, so this run's cleanup leaves the successor's keys alone. */
    private bool $handedOver = false;

    /** When the last session failed; cleared once the next session opens and the gap is logged. */
    private ?int $disconnectedAt = null;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onConnection('listener');
        $this->onQueue('listener');
    }

    /** Queue a listener unless one is already queued for this connection; true when it queued one. */
    public static function dispatchOnce(int $connectionId): bool
    {
        if (! Cache::add(self::queuedKey($connectionId), true, self::QUEUED_MARKER_SECONDS)) {
            return false;
        }

        dispatch(new self($connectionId));

        return true;
    }

    /** Marks a listener as queued but not yet started. */
    public static function queuedKey(int $connectionId): string
    {
        return "deriv:listener:queued:{$connectionId}";
    }

    /** Cache key for deduplication — prevents multiple instances for the same connection. */
    public static function runningKey(int $connectionId): string
    {
        return "deriv:listener:running:{$connectionId}";
    }

    public function handle(): void
    {
        Cache::forget(self::queuedKey($this->connectionId));

        // Atomic mutex: exit immediately if another instance is already running for this connection.
        // TTL 60 s; refreshed every ~30 s via updateHeartbeat() so it never expires while healthy.
        // If the process is killed without reaching the finally block, the key naturally expires
        // and the next startBot() dispatch or EnsureListenersRunning tick can proceed.
        if (! Cache::add(self::runningKey($this->connectionId), true, 60)) {
            Log::info("MasterListenerJob: duplicate instance for connection #{$this->connectionId} — exiting.");

            return;
        }

        try {
            $this->runListener();
        } finally {
            if (! $this->handedOver) {
                Cache::forget(self::runningKey($this->connectionId));
                Cache::forget(self::heartbeatKey($this->connectionId));
            }
        }
    }

    private function runListener(): void
    {
        $connection = DerivConnection::find($this->connectionId);

        if (! $connection) {
            Log::error("MasterListenerJob: connection #{$this->connectionId} not found.");

            return;
        }

        Log::info("MasterListenerJob starting for connection #{$this->connectionId}");

        $this->startedAt = now()->getTimestamp();

        $stopping = false;
        $failures = 0;
        $workerHandlers = $this->chainStopSignals($stopping);

        try {
            // Self-healing loop: reconnects automatically if the WebSocket drops or
            // Deriv closes the session. Exits only when there are no active followers.
            while (! $stopping) {
                $connection = $connection->fresh() ?? $connection;

                if (! $this->hasActiveFollowers($connection)) {
                    Log::info("MasterListenerJob: no active followers for connection #{$this->connectionId} — exiting.");

                    return;
                }

                if ($this->pastLifetime()) {
                    $this->handOver('lifetime reached');

                    return;
                }

                $this->updateHeartbeat();

                $sessionStartedAt = time();

                try {
                    $chosenAccountId = $this->chosenAccountId($connection);
                    $accountId = $chosenAccountId ?? $this->fallbackAccountId($connection);

                    if ($accountId === null) {
                        Log::error("MasterListenerJob: no account to listen to for connection #{$this->connectionId} — exiting.");

                        return;
                    }

                    $wsUrl = app(DerivApiService::class)->getOtpUrl($connection, $accountId, DerivRateLimiter::PRIORITY_TRADE);
                    Log::info("MasterListenerJob: opening session for connection #{$this->connectionId}, account {$accountId}");
                    $this->runSession($wsUrl, $connection, $chosenAccountId, $stopping);
                    $failures = 0;
                } catch (DerivAuthException $e) {
                    $this->stopForReauth($connection, $e);

                    return;
                } catch (\Throwable $e) {
                    $this->disconnectedAt ??= now()->getTimestamp();
                    $failures = time() - $sessionStartedAt >= self::HEALTHY_SESSION_SECONDS ? 1 : $failures + 1;
                    $maxDelay = self::backoffSeconds($failures);
                    $delay = random_int(intdiv($maxDelay, 2), $maxDelay);

                    Log::warning("MasterListenerJob: session ended for connection #{$this->connectionId} — {$e->getMessage()}. Reconnecting in {$delay}s...");

                    $this->sleepKeepingAlive($delay, $stopping);
                }
            }

            if ($stopping && $this->hasActiveFollowers($connection->fresh() ?? $connection)) {
                $this->handOver('the worker is stopping');
            }
        } finally {
            foreach ($workerHandlers as $signal => $handler) {
                $this->installSignalHandler($signal, $handler);
            }
        }
    }

    private function pastLifetime(): bool
    {
        return now()->getTimestamp() - $this->startedAt >= self::MAX_LIFETIME_SECONDS;
    }

    /** Sleep the backoff in slices, refreshing the keys between them and waking early when stopping. */
    private function sleepKeepingAlive(int $seconds, bool &$stopping): void
    {
        while ($seconds > 0 && ! $stopping) {
            $slice = min($seconds, self::HEARTBEAT_REFRESH_SECONDS);
            Sleep::for($slice)->seconds();
            $seconds -= $slice;
            $this->updateHeartbeat();
        }
    }

    /** Free this connection's keys and queue the successor, so the gap is about one worker poll. */
    private function handOver(string $reason): void
    {
        Log::info("MasterListenerJob: {$reason} for connection #{$this->connectionId} — handing over to a fresh job.");

        Cache::forget(self::runningKey($this->connectionId));
        Cache::forget(self::heartbeatKey($this->connectionId));
        $this->handedOver = true;

        self::dispatchOnce($this->connectionId);
    }

    /** Upper bound of the reconnect delay after the n-th failure in a row; the actual delay is jittered between half of it and all of it. */
    public static function backoffSeconds(int $failures): int
    {
        return (int) min(self::BACKOFF_MAX_SECONDS, self::BACKOFF_BASE_SECONDS * 2 ** max(0, min($failures - 1, 16)));
    }

    /**
     * Deriv rejected the token: retrying cannot help. Mark the connection expired so every page and
     * trade path treats it as needing a reconnect, and stop each bot that depends on it, with a reason.
     */
    private function stopForReauth(DerivConnection $connection, DerivAuthException $e): void
    {
        $stopped = $connection->requireReauth();

        Log::error("MasterListenerJob: Deriv rejected the token for connection #{$this->connectionId} — {$e->getMessage()}. Stopped {$stopped} bot(s); the owner must reconnect.");
    }

    // ─── Signals ──────────────────────────────────────────────────────────────

    /**
     * Stop the loop on SIGTERM/SIGINT while still running the queue worker's own handlers,
     * so the worker also learns it must quit once this job returns.
     *
     * @return array<int, mixed> The handlers that were installed before, by signal, to restore later.
     */
    private function chainStopSignals(bool &$stopping): array
    {
        if (! $this->supportsSignals()) {
            return [];
        }

        $workerHandlers = [];

        foreach ([self::SIGTERM, self::SIGINT] as $signal) {
            $workerHandler = $this->signalHandler($signal);
            $workerHandlers[$signal] = $workerHandler;

            $this->installSignalHandler($signal, function (int $signo, mixed $info = null) use (&$stopping, $workerHandler): void {
                $stopping = true;

                if (is_callable($workerHandler)) {
                    $workerHandler($signo, $info);
                }
            });
        }

        return $workerHandlers;
    }

    protected function supportsSignals(): bool
    {
        return extension_loaded('pcntl');
    }

    protected function signalHandler(int $signal): mixed
    {
        return pcntl_signal_get_handler($signal);
    }

    /** @param  callable|int  $handler  A closure, or SIG_DFL / SIG_IGN as returned by signalHandler(). */
    protected function installSignalHandler(int $signal, mixed $handler): void
    {
        pcntl_async_signals(true);
        pcntl_signal($signal, $handler);
    }

    // ─── WebSocket session ─────────────────────────────────────────────────────

    private function runSession(string $wsUrl, DerivConnection $connection, ?string $chosenAccountId, bool &$stopping): void
    {
        $socket = $this->openSocket($wsUrl);
        $this->updateHeartbeat();
        $this->logReconnectGap();

        try {
            $this->setReadTimeout($socket);
            $this->listen($socket, $connection, $chosenAccountId, $stopping);
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /** Master trades placed while disconnected are not copied late: a late copy is a different trade. */
    private function logReconnectGap(): void
    {
        if ($this->disconnectedAt === null) {
            return;
        }

        $gap = now()->getTimestamp() - $this->disconnectedAt;
        $from = date('H:i:s', $this->disconnectedAt);
        $this->disconnectedAt = null;

        Log::warning("MasterListenerJob: connection #{$this->connectionId} reconnected after a gap of {$gap}s (since {$from}); master trades in that window were not copied.");
    }

    /**
     * Read frames until the session should end. Bytes of a frame that has not fully arrived stay in
     * the codec across read timeouts, so a slow frame is never lost or misread.
     *
     * @param  resource  $socket
     */
    private function listen($socket, DerivConnection $connection, ?string $chosenAccountId, bool &$stopping): void
    {
        // OTP session is pre-authenticated; no authorize message required
        $this->sendJson($socket, ['transaction' => 1, 'subscribe' => 1, 'req_id' => MasterStreamProcessor::TRANSACTION_REQ_ID]);

        Log::info("MasterListenerJob: subscribed to transaction stream for connection #{$this->connectionId}");

        $codec = new WebSocketFrameCodec;
        $processor = new MasterStreamProcessor($this->connectionId);
        $lastHeartbeat = time();

        while (true) {
            $bytes = fread($socket, 8192);

            if ($bytes === false || $bytes === '') {
                if (! (stream_get_meta_data($socket)['timed_out'] ?? false)) {
                    throw new \RuntimeException('Connection closed by server');
                }

                $this->updateHeartbeat();
                $lastHeartbeat = time();

                if ($this->shouldEndSession($connection, $chosenAccountId, $stopping)) {
                    return;
                }

                $this->sendJson($socket, ['ping' => 1, 'req_id' => MasterStreamProcessor::PING_REQ_ID]);

                continue;
            }

            $codec->push($bytes);

            while (($frame = $codec->next()) !== null) {
                $this->handleFrame($socket, $frame, $processor);
            }

            // Refresh heartbeat during active trading so it doesn't expire
            // when the socket never idles long enough to trigger the timeout branch.
            if (time() - $lastHeartbeat >= self::HEARTBEAT_REFRESH_SECONDS) {
                $this->updateHeartbeat();
                $lastHeartbeat = time();

                if ($this->shouldEndSession($connection, $chosenAccountId, $stopping)) {
                    return;
                }
            }
        }
    }

    /**
     * @param  resource  $socket
     * @param  array{opcode: int, payload: string}  $frame
     */
    private function handleFrame($socket, array $frame, MasterStreamProcessor $processor): void
    {
        switch ($frame['opcode']) {
            case WebSocketFrameCodec::OPCODE_PING:
                fwrite($socket, WebSocketFrameCodec::encode(WebSocketFrameCodec::OPCODE_PONG, $frame['payload']));

                return;
            case WebSocketFrameCodec::OPCODE_PONG:
                return;
            case WebSocketFrameCodec::OPCODE_CLOSE:
                fwrite($socket, WebSocketFrameCodec::encode(WebSocketFrameCodec::OPCODE_CLOSE, $frame['payload']));

                throw new \RuntimeException('Connection closed by server (close frame)');
        }

        $message = json_decode($frame['payload'], true);

        if (! is_array($message)) {
            return;
        }

        foreach ($processor->process($message) as $reply) {
            $this->sendJson($socket, $reply);
        }
    }

    /**
     * Connect to Deriv over TLS and complete the WebSocket handshake.
     *
     * @return resource
     */
    protected function openSocket(string $wsUrl)
    {
        $host = (string) parse_url($wsUrl, PHP_URL_HOST);

        $socket = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            DerivApiService::streamContext(),
        );

        if (! $socket) {
            throw new \RuntimeException("WebSocket connection failed: {$errstr}");
        }

        $this->setReadTimeout($socket);
        $this->performHandshake($socket, $wsUrl);

        return $socket;
    }

    /**
     * Send the upgrade request and require a 101 reply.
     *
     * @param  resource  $socket
     */
    protected function performHandshake($socket, string $wsUrl): void
    {
        $host = parse_url($wsUrl, PHP_URL_HOST);
        $path = parse_url($wsUrl, PHP_URL_PATH).'?'.parse_url($wsUrl, PHP_URL_QUERY);
        $key = base64_encode(random_bytes(16));

        fwrite($socket, "GET {$path} HTTP/1.1\r\n"
            ."Host: {$host}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n");

        $response = (string) stream_get_line($socket, 4096, "\r\n\r\n");

        if (! str_starts_with($response, 'HTTP/1.1 101')) {
            $statusLine = strtok($response, "\r\n") ?: 'no response';

            throw new \RuntimeException("WebSocket handshake failed: {$statusLine}");
        }
    }

    /** @param resource $socket */
    private function setReadTimeout($socket): void
    {
        $seconds = (int) floor($this->readTimeoutSeconds);

        stream_set_timeout($socket, $seconds, (int) round(($this->readTimeoutSeconds - $seconds) * 1_000_000));
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $data
     */
    private function sendJson($socket, array $data): void
    {
        fwrite($socket, WebSocketFrameCodec::encode(WebSocketFrameCodec::OPCODE_TEXT, (string) json_encode($data)));
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function hasActiveFollowers(DerivConnection $connection): bool
    {
        return $this->followerSettings($connection)->where('is_running', true)->exists();
    }

    /** End the session cleanly when stopping, when no one is copying, or when the chosen account changed. */
    private function shouldEndSession(DerivConnection $connection, ?string $chosenAccountId, bool $stopping): bool
    {
        $connection = $connection->fresh() ?? $connection;

        if ($stopping || ! $this->hasActiveFollowers($connection)) {
            Log::info('MasterListenerJob: shutting down cleanly.');

            return true;
        }

        if ($this->pastLifetime()) {
            return true;
        }

        if ($this->chosenAccountId($connection) !== $chosenAccountId) {
            Log::info("MasterListenerJob: chosen account changed for connection #{$this->connectionId} — reconnecting.");

            return true;
        }

        return false;
    }

    /** Cache key other processes use to confirm this listener is alive. */
    public static function heartbeatKey(int $connectionId): string
    {
        return "deriv:listener:heartbeat:{$connectionId}";
    }

    private function updateHeartbeat(): void
    {
        Cache::put(self::heartbeatKey($this->connectionId), now()->toIso8601String(), 90);
        Cache::put(self::runningKey($this->connectionId), true, 60);
    }

    /**
     * The settings this listener copies for. A master serves everyone who follows it; any other
     * connection only ever runs its owner's own-account session, so no one else's setting counts.
     *
     * @return Builder<CopySetting>
     */
    private function followerSettings(DerivConnection $connection): Builder
    {
        $query = CopySetting::query()->where('master_connection_id', $connection->id);

        if (! $connection->isMaster()) {
            $query->where('user_id', $connection->user_id);
        }

        return $query;
    }

    /**
     * The account chosen in the database: the admin's choice for a master, the owner's own-account
     * setting otherwise. Never calls Deriv, so it is cheap enough to re-check during a session.
     */
    private function chosenAccountId(DerivConnection $connection): ?string
    {
        if ($connection->isMaster()) {
            return $connection->master_account_id;
        }

        return $this->followerSettings($connection)->value('master_account_id');
    }

    /** A master with no admin choice listens to its first active account. */
    private function fallbackAccountId(DerivConnection $connection): ?string
    {
        if (! $connection->isMaster()) {
            return null;
        }

        $accounts = DerivApiService::activeAccounts(app(DerivApiService::class)->getAccounts($connection, DerivRateLimiter::PRIORITY_TRADE));

        return $accounts[0]['account_id'] ?? null;
    }
}
