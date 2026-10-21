<?php

namespace App\Services;

use App\Exceptions\DerivApiException;
use App\Models\DerivConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Handles authentication and account fetching for legacy Deriv accounts
 * using the binary.com WebSocket API (wss://ws.binaryws.com/websockets/v3).
 *
 * Legacy PATs do not work with the new REST API — this service uses raw
 * socket WebSocket frames to avoid textalk/websocket opcode limitations.
 */
class DerivLegacyApiService
{
    private const WS_HOST = 'ws.binaryws.com';

    private const WS_PORT = 443;

    /**
     * Validate a PAT by authorizing against the legacy WS API.
     * Returns the full authorize response on success.
     *
     * @throws DerivApiException
     */
    public function authorize(string $token): array
    {
        return $this->wsSession(function ($socket) use ($token): array {
            $response = $this->sendAndReceive($socket, ['authorize' => $token]);

            if (isset($response['error'])) {
                throw new DerivApiException($response['error']['message'] ?? 'Authorization failed');
            }

            return $response;
        });
    }

    /**
     * Get all accounts for a legacy PAT connection.
     * Returns them in the same shape as DerivApiService::getAccounts().
     *
     * @return array<int, array{account_id: string, currency: string, account_type: string, balance: float|null, landing_company_name: string|null}>
     *
     * @throws DerivApiException
     */
    public function getAccounts(DerivConnection $connection): array
    {
        return Cache::remember("deriv:legacy:conn:{$connection->id}:accounts", 3600, function () use ($connection): array {
            return $this->wsSession(function ($socket) use ($connection): array {
                $auth = $this->sendAndReceive($socket, ['authorize' => $connection->access_token]);

                if (isset($auth['error'])) {
                    throw new DerivApiException($auth['error']['message'] ?? 'Authorization failed');
                }

                $balances = $this->fetchAllBalances($socket);
                $accountList = $auth['authorize']['account_list'] ?? [];

                return array_map(function (array $a) use ($balances): array {
                    $loginid = $a['loginid'];

                    return [
                        'account_id' => $loginid,
                        'currency' => strtoupper($a['currency'] ?? 'USD'),
                        'account_type' => ($a['is_virtual'] ?? 0) ? 'demo' : 'real',
                        'balance' => isset($balances[$loginid]) ? (float) $balances[$loginid]['balance'] : null,
                        'landing_company_name' => $a['landing_company_name'] ?? null,
                    ];
                }, $accountList);
            });
        });
    }

    /** Flush cached accounts for this connection. */
    public function clearCache(DerivConnection $connection): void
    {
        Cache::forget("deriv:legacy:conn:{$connection->id}:accounts");
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    /**
     * Fetch balances for all accounts in the current authorized session.
     * Returns a map of loginid → ['balance' => float, 'currency' => string].
     */
    private function fetchAllBalances($socket): array
    {
        $response = $this->sendAndReceive($socket, ['balance' => 1, 'account' => 'all']);

        return $response['balance']['accounts'] ?? [];
    }

    /**
     * Open a raw WebSocket session to the legacy Deriv API and run a callback.
     *
     * @throws DerivApiException
     */
    private function wsSession(callable $fn): array
    {
        $appId = config('deriv.legacy_app_id');
        $path = "/websockets/v3?app_id={$appId}";

        $context = DerivApiService::streamContext();

        $socket = @stream_socket_client(
            'ssl://'.self::WS_HOST.':'.self::WS_PORT,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! $socket) {
            throw new DerivApiException("Cannot connect to legacy Deriv API: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 15);

        $this->doHandshake($socket, self::WS_HOST, $path);

        try {
            $result = $fn($socket);
        } catch (DerivApiException $e) {
            fclose($socket);
            throw $e;
        } catch (\Throwable $e) {
            fclose($socket);
            throw new DerivApiException('Legacy Deriv WebSocket error: '.$e->getMessage());
        }

        fclose($socket);

        return $result;
    }

    /**
     * Perform the HTTP → WebSocket upgrade handshake.
     *
     * @throws DerivApiException
     */
    private function doHandshake($socket, string $host, string $path): void
    {
        $key = base64_encode(random_bytes(16));

        $request = "GET {$path} HTTP/1.1\r\n"
            ."Host: {$host}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n";

        fwrite($socket, $request);

        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fread($socket, 256);
            if ($chunk === false || $chunk === '') {
                throw new DerivApiException('WebSocket handshake failed: connection closed early');
            }
            $response .= $chunk;
        }

        if (! str_contains($response, '101')) {
            $status = substr($response, 0, strpos($response, "\r\n") ?: 80);
            throw new DerivApiException("WebSocket upgrade rejected: {$status}");
        }
    }

    /**
     * Send a JSON payload as a masked text frame and return the first text response.
     * Skips non-text frames (ping/pong/unknown) transparently.
     *
     * @throws DerivApiException
     */
    private function sendAndReceive($socket, array $payload): array
    {
        $this->sendFrame($socket, json_encode($payload));

        for ($i = 0; $i < 20; $i++) {
            $frame = $this->readFrame($socket);

            if ($frame['opcode'] === 1) {
                return json_decode($frame['data'], true) ?? [];
            }

            if ($frame['opcode'] === 8 || $frame['opcode'] === -1) {
                throw new DerivApiException('Legacy WS connection closed unexpectedly');
            }

            // opcode 9 = ping: send pong back
            if ($frame['opcode'] === 9 && $frame['data'] !== '') {
                $this->sendFrame($socket, $frame['data'], 0x8A);
            }

            // all other opcodes (including undocumented ones like 11): skip
        }

        throw new DerivApiException('No response received from legacy Deriv API');
    }

    /**
     * Send one WebSocket frame (client → server, always masked).
     */
    private function sendFrame($socket, string $data, int $header0 = 0x81): void
    {
        $len = strlen($data);
        $mask = random_bytes(4);

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }

        $frame = chr($header0);

        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } else {
            $frame .= chr(0x80 | 126).pack('n', $len);
        }

        fwrite($socket, $frame.$mask.$masked);
    }

    /**
     * Read one WebSocket frame from the socket.
     * Returns ['opcode' => int, 'data' => string], or opcode = -1 on read error.
     */
    private function readFrame($socket): array
    {
        $header = fread($socket, 2);

        if (strlen($header) < 2) {
            return ['opcode' => -1, 'data' => ''];
        }

        $opcode = ord($header[0]) & 0x0F;
        $len = ord($header[1]) & 0x7F;

        if ($len === 126) {
            $ext = fread($socket, 2);
            $len = (int) unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = fread($socket, 8);
            $len = (int) unpack('J', $ext)[1];
        }

        $data = '';
        $remaining = $len;

        while ($remaining > 0) {
            $chunk = fread($socket, $remaining);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return ['opcode' => $opcode, 'data' => $data];
    }
}
