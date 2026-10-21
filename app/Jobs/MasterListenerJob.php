<?php

namespace App\Jobs;

use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\MasterStreamProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MasterListenerJob implements ShouldQueue
{
    use Queueable;

    /** Never time out — this job runs for as long as the session is active. */
    public int $timeout = 0;

    /** Single attempt; the scheduler restarts it if it dies. */
    public int $tries = 1;

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

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$stopping) {
                $stopping = true;
            });
            pcntl_signal(SIGINT, function () use (&$stopping) {
                $stopping = true;
            });
        }

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
                $wsUrl = app(DerivApiService::class)->getOtpUrl($connection, $masterAccountId);
                Log::info("MasterListenerJob: opening session for connection #{$this->connectionId}");
                $this->runSession($wsUrl, $stopping);
            } catch (\Throwable $e) {
                Log::warning("MasterListenerJob: session ended for connection #{$this->connectionId} — {$e->getMessage()}. Reconnecting in 5s...");
                sleep(5);
            }
        }
    }

    // ─── WebSocket session ─────────────────────────────────────────────────────

    private function runSession(string $wsUrl, bool &$stopping): void
    {
        $host = (string) parse_url($wsUrl, PHP_URL_HOST);

        $context = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! $socket) {
            throw new \RuntimeException("WebSocket connection failed: {$errstr}");
        }

        // 30-second read timeout — lets us check stop condition periodically when idle
        stream_set_timeout($socket, 30);

        $this->sendWsHandshake($socket, $wsUrl);

        // OTP session is pre-authenticated; no authorize message required
        $this->sendWsMessage($socket, ['transaction' => 1, 'subscribe' => 1, 'req_id' => MasterStreamProcessor::TRANSACTION_REQ_ID]);

        Log::info("MasterListenerJob: subscribed to transaction stream for connection #{$this->connectionId}");

        $processor = new MasterStreamProcessor($this->connectionId);
        $lastHeartbeat = 0;

        while (true) {
            $message = $this->receiveWsMessage($socket);

            if ($message === null) {
                $meta = stream_get_meta_data($socket);

                if ($meta['timed_out'] ?? false) {
                    $this->updateHeartbeat();
                    $lastHeartbeat = time();

                    if ($stopping || ! $this->hasActiveFollowers()) {
                        Log::info('MasterListenerJob: shutting down cleanly.');
                        fclose($socket);

                        return;
                    }

                    $this->sendWsMessage($socket, ['ping' => 1, 'req_id' => MasterStreamProcessor::PING_REQ_ID]);

                    continue;
                }

                fclose($socket);
                throw new \RuntimeException('Connection closed by server');
            }

            // Refresh heartbeat during active trading so it doesn't expire
            // when the socket never idles long enough to trigger the timeout branch.
            if (time() - $lastHeartbeat >= 30) {
                $this->updateHeartbeat();
                $lastHeartbeat = time();
            }

            try {
                $outgoing = $processor->process($message);
            } catch (\Throwable $e) {
                fclose($socket);
                throw $e;
            }

            foreach ($outgoing as $reply) {
                $this->sendWsMessage($socket, $reply);
            }
        }
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

    // ─── Raw WebSocket framing ─────────────────────────────────────────────────

    private function sendWsHandshake($socket, string $wsUrl): void
    {
        $host = parse_url($wsUrl, PHP_URL_HOST);
        $path = parse_url($wsUrl, PHP_URL_PATH).'?'.parse_url($wsUrl, PHP_URL_QUERY);
        $key = base64_encode(random_bytes(16));

        $handshake = "GET {$path} HTTP/1.1\r\n"
            ."Host: {$host}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n";

        fwrite($socket, $handshake);
        stream_get_line($socket, 1024, "\r\n\r\n");
    }

    private function sendWsMessage($socket, array $data): void
    {
        $json = json_encode($data);
        $length = strlen($json);
        $frame = chr(0x81);

        if ($length <= 125) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0xFE).pack('n', $length);
        } else {
            $frame .= chr(0xFF).pack('J', $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        for ($i = 0; $i < $length; $i++) {
            $frame .= $json[$i] ^ $mask[$i % 4];
        }

        fwrite($socket, $frame);
    }

    private function receiveWsMessage($socket): ?array
    {
        $header = $this->readExactly($socket, 2);

        if (strlen($header) < 2) {
            return null;
        }

        $isMasked = (ord($header[1]) & 0x80) !== 0;
        $payloadLength = ord($header[1]) & 0x7F;

        if ($payloadLength === 126) {
            $payloadLength = (int) unpack('n', $this->readExactly($socket, 2))[1];
        } elseif ($payloadLength === 127) {
            $payloadLength = (int) unpack('J', $this->readExactly($socket, 8))[1];
        }

        $mask = $isMasked ? $this->readExactly($socket, 4) : null;
        $payload = $this->readExactly($socket, $payloadLength);

        if ($mask) {
            for ($i = 0; $i < $payloadLength; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }

        return json_decode($payload, true);
    }

    private function readExactly($socket, int $length): string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($socket, $remaining);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }
}
