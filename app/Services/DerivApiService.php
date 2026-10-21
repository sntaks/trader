<?php

namespace App\Services;

use App\Exceptions\DerivApiException;
use App\Exceptions\DerivAuthException;
use App\Exceptions\DerivRateLimitedException;
use App\Models\DerivConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use WebSocket\Client;

class DerivApiService
{
    /** New REST API — reliable, no WebSocket needed. */
    private const REST_BASE = 'https://api.derivws.com/trading/v1/options';

    /** How long a last good read is kept to show while Deriv's rate limit is reached. */
    private const STALE_TTL_SECONDS = 86400;

    public function __construct(
        private readonly DerivRateLimiter $rateLimiter,
        private readonly DerivTokenRefresher $tokenRefresher = new DerivTokenRefresher,
    ) {}

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

    /**
     * The accounts that can be traded on or read: Deriv marks closed ones `inactive`.
     *
     * @param  list<array<string, mixed>>  $accounts  As returned by getAccounts().
     * @return list<array<string, mixed>>
     */
    public static function activeAccounts(array $accounts): array
    {
        return array_values(array_filter($accounts, fn (array $account) => ($account['status'] ?? 'active') !== 'inactive'));
    }

    // ─── REST API ────────────────────────────────────────────────────────────

    /**
     * Get all accounts (demo + real) for this token.
     * Uses the new REST API — no WebSocket needed.
     */
    public function getAccounts(DerivConnection $connection, string $priority = DerivRateLimiter::PRIORITY_READ): array
    {
        return $this->cachedCall($connection, 'accounts', 3600, function () use ($connection, $priority) {
            $response = $this->restRequest($connection, $priority, fn () => Http::withHeaders($this->restHeaders($connection))
                ->get(self::REST_BASE.'/accounts'));

            if ($response->status() === 401) {
                throw new DerivAuthException('Token expired or invalid. Please reconnect your Deriv account.');
            }

            if ($response->failed()) {
                $msg = $response->json('errors.0.message', 'Failed to load accounts');
                throw new DerivApiException($msg);
            }

            return $response->json('data') ?? [];
        });
    }

    /**
     * Reset a demo account balance back to $10,000.
     * Only works for demo accounts.
     */
    public function resetDemoBalance(DerivConnection $connection, string $accountId): void
    {
        $response = $this->restRequest($connection, DerivRateLimiter::PRIORITY_READ, fn () => Http::withHeaders($this->restHeaders($connection))
            ->post(self::REST_BASE."/accounts/{$accountId}/reset-demo-balance"));

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

        $this->clearCache($connection);
    }

    /**
     * Check a Personal Access Token against the accounts endpoint, through the rate limiter. Never cached.
     *
     * @throws DerivRateLimitedException When the budget is spent or Deriv answers 429.
     * @throws DerivApiException When Deriv cannot be reached.
     */
    public function checkPat(string $token): Response
    {
        return $this->restRequest(null, DerivRateLimiter::PRIORITY_READ, fn () => Http::withHeaders([
            'Deriv-App-ID' => $this->appIdFor(isPat: true),
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->get(self::REST_BASE.'/accounts'));
    }

    // ─── WebSocket API ────────────────────────────────────────────────────────

    /**
     * Get the account's balance, at most $maxAgeSeconds old. Each max age has its own cache entry,
     * so a caller that needs a fresh balance never receives one cached for a longer-lived caller.
     * Callers that store the balance pass $allowStale = false, so a throttled read throws instead
     * of returning the last good value.
     */
    public function getBalance(DerivConnection $connection, string $accountId, int $maxAgeSeconds = 30, bool $allowStale = true): array
    {
        return $this->cachedCall($connection, "{$accountId}:balance:{$maxAgeSeconds}s", $maxAgeSeconds, fn () => $this->wsCall($connection, $accountId, ['balance' => 1]), $allowStale);
    }

    /**
     * Get profit/loss table (closed positions). Cached 5 min.
     *
     * @param  int  $limit  Max 500 per page.
     * @param  int  $offset  Pagination offset.
     */
    public function getProfitTable(DerivConnection $connection, string $accountId, int $limit = 25, int $offset = 0): array
    {
        return $this->cachedCall($connection, "{$accountId}:profit_table:{$limit}:{$offset}", 300, fn () => $this->wsCall($connection, $accountId, [
            'profit_table' => 1,
            'limit' => $limit,
            'offset' => $offset,
            'sort' => 'DESC',
        ]));
    }

    /**
     * Closed contracts sold since $dateFrom, oldest first. Never cached: the settlement sweeper needs fresh results.
     *
     * @return list<array<string, mixed>> The `profit_table.transactions` rows.
     *
     * @throws DerivApiException
     */
    public function getProfitTableSince(DerivConnection $connection, string $accountId, int $dateFrom, int $limit = 500, int $offset = 0): array
    {
        $response = $this->wsCall($connection, $accountId, [
            'profit_table' => 1,
            'date_from' => (string) $dateFrom,
            'limit' => $limit,
            'offset' => $offset,
            'sort' => 'ASC',
        ]);

        return $response['profit_table']['transactions'] ?? [];
    }

    /**
     * Get account statement (deposits, withdrawals, trades). Cached 5 min.
     */
    public function getStatement(DerivConnection $connection, string $accountId, int $limit = 25, int $offset = 0): array
    {
        return $this->cachedCall($connection, "{$accountId}:statement:{$limit}:{$offset}", 300, fn () => $this->wsCall($connection, $accountId, [
            'statement' => 1,
            'limit' => $limit,
            'offset' => $offset,
        ]));
    }

    /** Get open/active positions (portfolio). Cached 30 s. */
    public function getPortfolio(DerivConnection $connection, string $accountId): array
    {
        return $this->cachedCall($connection, "{$accountId}:portfolio", 30, fn () => $this->wsCall($connection, $accountId, ['portfolio' => 1]));
    }

    /** Fetch current details for a single contract (used to settle copy trades). */
    public function getContractDetails(DerivConnection $connection, string $accountId, string $contractId): array
    {
        $response = $this->wsCall($connection, $accountId, [
            'proposal_open_contract' => 1,
            'contract_id' => (int) $contractId,
            'req_id' => 1,
        ]);

        return $response['proposal_open_contract'] ?? [];
    }

    /**
     * Currency of the given account.
     *
     * @throws DerivApiException
     */
    public function accountCurrency(DerivConnection $connection, string $accountId): string
    {
        $account = collect($this->getAccounts($connection, DerivRateLimiter::PRIORITY_TRADE))->firstWhere('account_id', $accountId);

        if (empty($account['currency'])) {
            throw new DerivApiException("Account {$accountId} not found for this connection.");
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
     * @param  array{contract_type: string, symbol: string, duration: int, duration_unit: string, stake: float, currency: string, basis?: string, barrier?: string|null, barrier2?: string|null, follower_account_id: string|null}  $params
     *
     * @throws DerivApiException
     */
    public function buyContract(DerivConnection $connection, array $params): array
    {
        $accountId = $params['follower_account_id'] ?? null;

        if (! $accountId) {
            throw new DerivApiException('No follower account selected.');
        }

        return $this->wsSession($connection, $accountId, DerivRateLimiter::PRIORITY_TRADE, function (Client $client) use ($params): array {
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
        });
    }

    /**
     * Obtain a pre-authenticated WebSocket URL (OTP) for a specific account.
     * The returned URL requires no further authorization — just connect and send messages.
     */
    public function getOtpUrl(DerivConnection $connection, string $accountId, string $priority = DerivRateLimiter::PRIORITY_READ): string
    {
        $otpResp = $this->restRequest($connection, $priority, fn () => Http::withHeaders($this->restHeaders($connection))
            ->post(self::REST_BASE."/accounts/{$accountId}/otp"));

        if ($otpResp->status() === 401) {
            throw new DerivAuthException($otpResp->json('errors.0.message', 'Token expired or invalid. Please reconnect your Deriv account.'));
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

    /** Retire every cached call for this connection by moving it to a new cache-key version. */
    public function clearCache(DerivConnection $connection): void
    {
        $versionKey = $this->versionKey($connection);

        Cache::forever($versionKey, (int) Cache::get($versionKey, 0) + 1);
    }

    /**
     * Retire everything cached for this connection, stale copies included. For a token change
     * (connect, reconnect, disconnect), where the old reads may belong to a different Deriv login.
     */
    public function forgetConnection(DerivConnection $connection): void
    {
        $this->clearCache($connection);

        $generationKey = $this->staleGenerationKey($connection);

        Cache::forever($generationKey, (int) Cache::get($generationKey, 0) + 1);
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    /**
     * Headers for an authenticated call, refreshing the connection's token first when it is about to expire.
     *
     * @return array<string, string>
     *
     * @throws DerivAuthException When the sign-in has ended and could not be renewed.
     */
    private function restHeaders(DerivConnection $connection): array
    {
        $this->tokenRefresher->ensureFresh($connection);

        return [
            'Deriv-App-ID' => $this->appIdFor($connection->isPat()),
            'Authorization' => 'Bearer '.$connection->access_token,
            'Accept' => 'application/json',
        ];
    }

    /** PAT requests carry the PAT app's ID, which Deriv requires; sign-in requests carry the OAuth client ID. */
    private function appIdFor(bool $isPat): string
    {
        return (string) ($isPat ? (config('deriv.app_id_pat') ?: config('deriv.app_id')) : config('deriv.app_id'));
    }

    /**
     * Send one REST request through the rate limiter. A 429 or a `RateLimit` error starts a cooldown for everyone.
     *
     * @param  callable(): Response  $send
     *
     * @throws DerivApiException
     */
    private function restRequest(?DerivConnection $connection, string $priority, callable $send): Response
    {
        $this->rateLimiter->acquire($connection, $priority);

        try {
            $response = $send();
        } catch (\Throwable $e) {
            throw new DerivApiException('Deriv API unreachable: '.$e->getMessage());
        }

        if ($response->status() === 429 || $response->json('errors.0.code') === 'RateLimit') {
            $retryAfter = $response->header('Retry-After');
            $this->rateLimiter->coolDown(is_numeric($retryAfter) ? (int) $retryAfter : null);

            throw new DerivRateLimitedException('Deriv rate limit reached — try again shortly.');
        }

        return $response;
    }

    /**
     * Cache a read for $ttl seconds, and keep its last good value for when Deriv's rate limit is reached.
     * The stale copy outlives clearCache() on purpose; it is served only for a rate-limit refusal.
     * forgetConnection() retires it by moving to a new stale generation.
     */
    private function cachedCall(DerivConnection $connection, string $key, int $ttl, callable $fn, bool $allowStale = true): array
    {
        $generation = (int) Cache::get($this->staleGenerationKey($connection), 0);
        $staleKey = "deriv:conn:{$connection->id}:stale:g{$generation}:{$key}";

        try {
            return Cache::remember($this->cacheKey($connection, $key), $ttl, function () use ($fn, $staleKey): array {
                $value = $fn();
                Cache::put($staleKey, $value, self::STALE_TTL_SECONDS);

                return $value;
            });
        } catch (DerivRateLimitedException $e) {
            if (! $allowStale) {
                throw $e;
            }

            return Cache::get($staleKey) ?? throw $e;
        }
    }

    /** Cache key for one call, under the connection's current version so clearCache() retires all of them at once. */
    private function cacheKey(DerivConnection $connection, string $key): string
    {
        $version = (int) Cache::get($this->versionKey($connection), 0);

        return "deriv:conn:{$connection->id}:v{$version}:{$key}";
    }

    private function versionKey(DerivConnection $connection): string
    {
        return "deriv:conn:{$connection->id}:version";
    }

    private function staleGenerationKey(DerivConnection $connection): string
    {
        return "deriv:conn:{$connection->id}:stale-generation";
    }

    private function wsCall(DerivConnection $connection, string $accountId, array $request): array
    {
        return $this->wsSession($connection, $accountId, DerivRateLimiter::PRIORITY_READ, function (Client $client) use ($request): array {
            $this->wsSend($client, $request);
            $response = $this->wsReceive($client);

            if (isset($response['error'])) {
                throw new DerivApiException($response['error']['message'] ?? 'API call failed');
            }

            return $response;
        });
    }

    /**
     * Run $fn on a socket authenticated for one account. The socket is always closed afterwards.
     *
     * @throws DerivApiException
     */
    private function wsSession(DerivConnection $connection, string $accountId, string $priority, callable $fn): array
    {
        $wsUrl = $this->getOtpUrl($connection, $accountId, $priority);
        $client = null;

        try {
            $client = $this->openSocket($wsUrl);

            return $fn($client);
        } catch (DerivApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DerivApiException('Deriv WebSocket unavailable: '.$e->getMessage());
        } finally {
            try {
                $client?->close();
            } catch (\Throwable $e) {
                Log::debug("Deriv socket close failed: {$e->getMessage()}");
            }
        }
    }

    /** The one place a Deriv socket is opened, so tests can substitute a fake client. */
    protected function openSocket(string $url): Client
    {
        return new Client($url, ['timeout' => 15, 'context' => self::streamContext()]);
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
