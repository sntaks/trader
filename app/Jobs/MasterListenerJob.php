<?php

namespace App\Jobs;

use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\DerivRateLimiter;
use App\Services\MasterStreamProcessor;
use App\Services\WebSocketFrameCodec;
use Illuminate\Contracts\Queue\ShouldQueue;
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

    /** Seconds a read waits before the idle tick (heartbeat, stop check, ping). */
    protected float $readTimeoutSeconds = 30.0;

    public function __construct(
        public readonly int $connectionId,
    ) {
        $this->onQueue('listener');
    }

    /** Cache key for deduplication — prevents multiple instances for the same connection. */
    public static function runningKey(int $connectionId): string
    {
        return "deriv:listener:running:{$connectionId}";
    }

    public function handle(): void
    {
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
            Cache::forget(self::runningKey($this->connectionId));
        }
    }

    private function runListener(): void
    {
        $connection = DerivConnection::find($this->connectionId);

        if (! $connection) {
            Log::error("MasterListenerJob: connection #{$this->connectionId} not found.");

            return;
        }

        $masterAccountId = $this->resolveMasterAccountId($connection);

        Log::info("MasterListenerJob starting for connection #{$this->connectionId}, account {$masterAccountId}");

        $stopping = false;
        $workerHandlers = $this->chainStopSignals($stopping);

        try {
            // Self-healing loop: reconnects automatically if the WebSocket drops or
            // Deriv closes the session. Exits only when there are no active followers.
            while (! $stopping) {
                if (! $this->hasActiveFollowers()) {
                    Log::info("MasterListenerJob: no active followers for connection #{$this->connectionId} — exiting.");

                    return;
                }

                $connection = $connection->fresh() ?? $connection;
                $this->updateHeartbeat();

                try {
                    $wsUrl = app(DerivApiService::class)->getOtpUrl($connection, $masterAccountId, DerivRateLimiter::PRIORITY_TRADE);
                    Log::info("MasterListenerJob: opening session for connection #{$this->connectionId}");
                    $this->runSession($wsUrl, $stopping);
                } catch (\Throwable $e) {
                    Log::warning("MasterListenerJob: session ended for connection #{$this->connectionId} — {$e->getMessage()}. Reconnecting in 5s...");

                    if (! $stopping) {
                        Sleep::for(5)->seconds();
                    }
                }
            }
        } finally {
            foreach ($workerHandlers as $signal => $handler) {
                $this->installSignalHandler($signal, $handler);
            }
        }
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

    private function runSession(string $wsUrl, bool &$stopping): void
    {
        $socket = $this->openSocket($wsUrl);

        try {
            $this->setReadTimeout($socket);
            $this->listen($socket, $stopping);
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /**
     * Read frames until the session should end. Bytes of a frame that has not fully arrived stay in
     * the codec across read timeouts, so a slow frame is never lost or misread.
     *
     * @param  resource  $socket
     */
    private function listen($socket, bool &$stopping): void
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

                if ($stopping || ! $this->hasActiveFollowers()) {
                    Log::info('MasterListenerJob: shutting down cleanly.');

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
            if (time() - $lastHeartbeat >= 30) {
                $this->updateHeartbeat();
                $lastHeartbeat = time();
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

    private function hasActiveFollowers(): bool
    {
        return CopySetting::query()
            ->where('master_connection_id', $this->connectionId)
            ->where('is_running', true)
            ->exists();
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

    private function resolveMasterAccountId(DerivConnection $connection): string
    {
        $stored = CopySetting::query()
            ->where('master_connection_id', $this->connectionId)
            ->whereNotNull('master_account_id')
            ->value('master_account_id');

        if ($stored) {
            return $stored;
        }

        $accounts = app(DerivApiService::class)->getAccounts($connection);
        $first = $accounts[0]['account_id'] ?? null;

        if (! $first) {
            throw new \RuntimeException('No Deriv accounts found for this connection.');
        }

        return $first;
    }
}
