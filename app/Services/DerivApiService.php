<?php

namespace App\Services;

use App\Exceptions\DerivApiException;
use App\Models\DerivConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use WebSocket\Client;

class DerivApiService
{
    /** New REST API — reliable, no WebSocket needed. */
    private const REST_BASE = 'https://api.derivws.com/trading/v1/options';

    /** Authenticated WebSocket base — OTP appended at runtime. */
    private const WS_BASE = 'wss://api.derivws.com/trading/v1/options';

    /**
     * Stream context for every Deriv socket: certificates and host names are always verified.
     *
     * @return resource
     */
    public static function streamContext(): mixed
    {
        return stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
    }

    // ─── REST API ────────────────────────────────────────────────────────────

    /**
     * Get all accounts (demo + real) for this token.
     * Uses the new REST API — no WebSocket needed.
     */
    public function getAccounts(DerivConnection $connection): array
    {
        return $this->cachedCall($connection, 'accounts', 3600, function () use ($connection) {
            try {
                $response = Http::withHeaders($this->restHeaders($connection->access_token))
                    ->get(self::REST_BASE.'/accounts');
            } catch (\Throwable $e) {
                throw new DerivApiException('Deriv API unreachable: '.$e->getMessage());
            }

            if ($response->status() === 401) {
                throw new DerivApiException('Token expired or invalid. Please reconnect your Deriv account.');
            }

            if ($response->failed()) {
                $msg = $response->json('errors.0.message', 'Failed to load accounts');
                throw new DerivApiException($msg);
            }

            return $response->json('data') ?? [];
        });
    }

    /**
     * Get all CFD (MT5) accounts for this token.
     * Returns normalized account data matching the Options account shape.
     */
    public function getCfdAccounts(DerivConnection $connection): array
    {
        return $this->cachedCall($connection, 'cfd_accounts', 3600, function () use ($connection) {
            $response = $this->wsCall($connection->access_token, [
                'trading_platform_accounts' => 1,
                'platform' => 'mt5',
            ]);

            return array_map(fn (array $a) => [
                'account_id' => $a['login'] ?? '—',
                'currency' => strtoupper($a['currency'] ?? 'USD'),
                'is_demo' => ($a['account_type'] ?? '') === 'demo',
                'balance' => isset($a['balance']) ? (float) $a['balance'] : null,
                'landing_company_name' => 'MT5 '.ucfirst($a['market_type'] ?? 'CFD'),
                'product_type' => 'cfd',
            ], $response['trading_platform_accounts'] ?? []);
        });
    }

    /**
     * Reset a demo account balance back to $10,000.
     * Only works for demo accounts.
     */
    public function resetDemoBalance(DerivConnection $connection, string $accountId): void
    {
        try {
            $response = Http::withHeaders($this->restHeaders($connection->access_token))
                ->post(self::REST_BASE."/accounts/{$accountId}/reset-demo-balance");
        } catch (\Throwable $e) {
            throw new DerivApiException('Deriv API unreachable: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            throw new DerivApiException('Token expired or invalid. Please reconnect your Deriv account.');
        }

        if ($response->status() === 400) {
            $msg = $response->json('errors.0.message', 'Only demo accounts can be reset.');
            throw new DerivApiException($msg);
        }

        if ($response->failed()) {
            $msg = $response->json('errors.0.message', 'Failed to reset demo balance.');
            throw new DerivApiException($msg);
        }

        Cache::forget("deriv:conn:{$connection->id}:accounts");
    }

    // ─── WebSocket API ────────────────────────────────────────────────────────

    /** Get the current account balance. Cached 30 s. */
    public function getBalance(DerivConnection $connection): array
    {
        return $this->cachedCall($connection, 'balance', 30, fn () => $this->wsCall($connection->access_token, ['balance' => 1]));
    }

    /**
     * Get profit/loss table (closed positions). Cached 5 min.
     *
     * @param  int  $limit  Max 500 per page.
     * @param  int  $offset  Pagination offset.
     */
    public function getProfitTable(DerivConnection $connection, int $limit = 25, int $offset = 0): array
    {
        return $this->cachedCall($connection, "profit_table:{$limit}:{$offset}", 300, fn () => $this->wsCall($connection->access_token, [
            'profit_table' => 1,
            'limit' => $limit,
            'offset' => $offset,
            'sort' => 'DESC',
        ]));
    }

    /**
     * Get account statement (deposits, withdrawals, trades). Cached 5 min.
     */
    public function getStatement(DerivConnection $connection, int $limit = 25, int $offset = 0): array
    {
        return $this->cachedCall($connection, "statement:{$limit}:{$offset}", 300, fn () => $this->wsCall($connection->access_token, [
            'statement' => 1,
            'limit' => $limit,
            'offset' => $offset,
        ]));
    }

    /** Get open/active positions (portfolio). Cached 30 s. */
    public function getPortfolio(DerivConnection $connection): array
    {
        return $this->cachedCall($connection, 'portfolio', 30, fn () => $this->wsCall($connection->access_token, ['portfolio' => 1]));
    }

    /** Fetch current details for a single contract (used to settle copy trades). */
    public function getContractDetails(DerivConnection $connection, string $contractId): array
    {
        $response = $this->wsCall($connection->access_token, [
            'proposal_open_contract' => 1,
            'contract_id' => (int) $contractId,
            'req_id' => 1,
        ]);

        return $response['proposal_open_contract'] ?? [];
    }

    /**
     * Currency of the given account, or of the first account when none is selected
     * (the same account wsSession() falls back to).
     *
     * @throws DerivApiException
     */
    public function accountCurrency(DerivConnection $connection, ?string $accountId): string
    {
        $accounts = $this->getAccounts($connection);

        $account = $accountId === null
            ? ($accounts[0] ?? null)
            : collect($accounts)->firstWhere('account_id', $accountId);

        if (empty($account['currency'])) {
            throw new DerivApiException('Account '.($accountId ?? '(default)').' not found for this connection.');
        }

        return strtoupper($account['currency']);
    }

    /**
     * Build the `proposal` request for a follower buy. Barriers are sent only when set.
     *
     * @param  array{contract_type: string, symbol: string, duration: int, duration_unit: string, stake: float, currency: string, basis?: string, barrier?: string|null, barrier2?: string|null}  $params
     * @return array<string, mixed>
     */
    public static function proposalRequest(array $params): array
    {
        $proposal = [
            'proposal' => 1,
            'amount' => $params['stake'],
            'basis' => $params['basis'] ?? 'stake',
            'contract_type' => $params['contract_type'],
            'currency' => $params['currency'],
            'duration' => $params['duration'],
            'duration_unit' => $params['duration_unit'],
            'underlying_symbol' => $params['symbol'],
        ];

        foreach (['barrier', 'barrier2'] as $barrierKey) {
            if (isset($params[$barrierKey]) && $params[$barrierKey] !== '') {
                $proposal[$barrierKey] = $params[$barrierKey];
            }
        }

        return $proposal;
    }

    /**
     * Buy a contract on behalf of a follower.
     * Fetches a price proposal then immediately buys it.
     *
     * @param  array{contract_type: string, symbol: string, duration: int, duration_unit: string, stake: float, currency: string, basis?: string, barrier?: string|null, barrier2?: string|null, follower_account_id?: string|null}  $params
     */
    public function buyContract(DerivConnection $connection, array $params): array
    {
        return $this->wsSession($connection->access_token, function (Client $client) use ($params): array {
            $proposal = [...self::proposalRequest($params), 'req_id' => 1];

            Log::debug('buyContract proposal', $proposal);

            $this->wsSend($client, $proposal);

            $proposal = $this->wsReceive($client);

            if (isset($proposal['error'])) {
                throw new DerivApiException($proposal['error']['message'] ?? 'Proposal request failed');
            }

            $proposalId = $proposal['proposal']['id'] ?? null;

            if (! $proposalId) {
                throw new DerivApiException('Proposal returned no ID — cannot place buy');
            }

            $this->wsSend($client, ['buy' => $proposalId, 'price' => $params['stake'], 'req_id' => 2]);

            $buyResponse = $this->wsReceive($client);

            if (isset($buyResponse['error'])) {
                throw new DerivApiException($buyResponse['error']['message'] ?? 'Buy request failed');
            }

            return $buyResponse;
        }, $params['follower_account_id'] ?? null);
    }

    /**
     * Obtain a pre-authenticated WebSocket URL (OTP) for a specific account.
     * The returned URL requires no further authorization — just connect and send messages.
     */
    public function getOtpUrl(DerivConnection $connection, string $accountId): string
    {
        $headers = $this->restHeaders($connection->access_token);

        try {
            $otpResp = Http::withHeaders($headers)->post(self::REST_BASE."/accounts/{$accountId}/otp");
        } catch (\Throwable $e) {
            throw new DerivApiException('Deriv API unreachable: '.$e->getMessage());
        }

        if ($otpResp->failed()) {
            throw new DerivApiException($otpResp->json('errors.0.message', 'Failed to obtain WebSocket session token'));
        }

        $wsUrl = $otpResp->json('data.url');

        if (! $wsUrl) {
            throw new DerivApiException('OTP response contained no WebSocket URL');
        }

        return $wsUrl;
    }

    /** Flush all cached data for this connection. */
    public function clearCache(DerivConnection $connection): void
    {
        $keys = ['accounts', 'cfd_accounts', 'balance', 'portfolio'];

        foreach ($keys as $key) {
            Cache::forget("deriv:conn:{$connection->id}:{$key}");
        }

        foreach ([25, 50, 100] as $limit) {
            foreach ([0, 25, 50, 75, 100] as $offset) {
                Cache::forget("deriv:conn:{$connection->id}:profit_table:{$limit}:{$offset}");
                Cache::forget("deriv:conn:{$connection->id}:statement:{$limit}:{$offset}");
            }
        }
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    private function restHeaders(string $token): array
    {
        return [
            'Deriv-App-ID' => (string) config('deriv.app_id'),
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    private function cachedCall(DerivConnection $connection, string $key, int $ttl, callable $fn): array
    {
        return Cache::remember("deriv:conn:{$connection->id}:{$key}", $ttl, $fn);
    }

    private function wsCall(string $token, array $request, ?string $accountId = null): array
    {
        return $this->wsSession($token, function (Client $client) use ($request): array {
            $this->wsSend($client, $request);
            $response = $this->wsReceive($client);

            if (isset($response['error'])) {
                throw new DerivApiException($response['error']['message'] ?? 'API call failed');
            }

            return $response;
        }, $accountId);
    }

    private function wsSession(string $token, callable $fn, ?string $accountId = null): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Deriv-App-ID' => (string) config('deriv.app_id'),
            'Accept' => 'application/json',
        ];

        if (! $accountId) {
            try {
                $accountsResp = Http::withHeaders($headers)->get(self::REST_BASE.'/accounts');
            } catch (\Throwable $e) {
                throw new DerivApiException('Deriv API unreachable: '.$e->getMessage());
            }

            if ($accountsResp->failed()) {
                throw new DerivApiException($accountsResp->json('errors.0.message', 'Failed to retrieve accounts'));
            }

            $accountId = ($accountsResp->json('data') ?? [])[0]['account_id'] ?? null;

            if (! $accountId) {
                throw new DerivApiException('No Deriv account found — cannot open WebSocket session');
            }
        }

        try {
            $otpResp = Http::withHeaders($headers)->post(self::REST_BASE."/accounts/{$accountId}/otp");
        } catch (\Throwable $e) {
            throw new DerivApiException('Deriv API unreachable: '.$e->getMessage());
        }

        if ($otpResp->failed()) {
            throw new DerivApiException($otpResp->json('errors.0.message', 'Failed to obtain WebSocket session token'));
        }

        $wsUrl = $otpResp->json('data.url');

        if (! $wsUrl) {
            throw new DerivApiException('OTP response contained no WebSocket URL');
        }

        $context = self::streamContext();

        try {
            $client = new Client($wsUrl, ['timeout' => 15, 'context' => $context]);
            $result = $fn($client);
            $client->close();

            return $result;
        } catch (DerivApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DerivApiException('Deriv WebSocket unavailable: '.$e->getMessage());
        }
    }

    private function wsSend(Client $client, array $data): void
    {
        $json = json_encode($data);

        if (method_exists($client, 'text')) {
            $client->text($json);
        } else {
            $client->send($json);
        }
    }

    private function wsReceive(Client $client): array
    {
        $raw = $client->receive();

        $content = match (true) {
            is_string($raw) => $raw,
            method_exists($raw, 'getContent') => $raw->getContent(),
            default => (string) $raw,
        };

        return json_decode($content, true) ?? [];
    }
}
