<?php

namespace App\Services;

use App\Exceptions\UncopyableTradeException;
use App\Jobs\CopyTradeJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Handles messages from a master's transaction stream for one WebSocket session.
 *
 * Returns the messages the listener must send back instead of writing to the
 * socket itself, so the trading logic can be tested without a connection.
 */
class MasterStreamProcessor
{
    public const TRANSACTION_REQ_ID = 2;

    public const PING_REQ_ID = 99;

    private const FIRST_CONTRACT_REQ_ID = 50;

    /** Buy prices outlive a single session so a sell after a reconnect can still be scored. */
    private const BUY_PRICE_TTL_SECONDS = 86400;

    /** @var array<int, array<string, mixed>> req_id => buy transaction awaiting contract details */
    private array $pendingContracts = [];

    private int $nextReqId = self::FIRST_CONTRACT_REQ_ID;

    public function __construct(private readonly int $connectionId) {}

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>> Messages to send back on the socket.
     *
     * @throws RuntimeException When the transaction subscription itself fails.
     */
    public function process(array $message): array
    {
        if (isset($message['error'])) {
            return $this->handleError($message);
        }

        return match ($message['msg_type'] ?? '') {
            'transaction' => $this->handleTransaction($message['transaction'] ?? []),
            'proposal_open_contract' => $this->handleContract($message),
            default => [],
        };
    }

    public static function buyPriceKey(int $connectionId, string $contractId): string
    {
        return "master_buy_price:{$connectionId}:{$contractId}";
    }

    /**
     * Deriv reports errors with msg_type set to the request type, so match on req_id.
     *
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    private function handleError(array $message): array
    {
        $reqId = $message['req_id'] ?? null;
        $error = $message['error'];
        $context = [
            'msg_type' => $message['msg_type'] ?? null,
            'code' => $error['code'] ?? 'unknown',
            'message' => $error['message'] ?? 'unknown',
        ];

        if ($reqId === self::TRANSACTION_REQ_ID || ($message['msg_type'] ?? null) === 'transaction') {
            throw new RuntimeException('Deriv transaction stream error: '.$context['message']);
        }

        if ($reqId !== null && isset($this->pendingContracts[$reqId])) {
            $contractId = $this->pendingContracts[$reqId]['contract_id'] ?? '?';
            unset($this->pendingContracts[$reqId]);

            Log::warning("MasterStreamProcessor: copy skipped for contract #{$contractId} on connection #{$this->connectionId} — contract lookup failed.", $context);

            return [];
        }

        Log::warning("MasterStreamProcessor: Deriv error on connection #{$this->connectionId}.", $context);

        return [];
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return list<array<string, mixed>>
     */
    private function handleTransaction(array $transaction): array
    {
        return match ($transaction['action'] ?? '') {
            'buy' => $this->handleBuy($transaction),
            'sell' => $this->handleSell($transaction),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return list<array<string, mixed>>
     */
    private function handleBuy(array $transaction): array
    {
        $contractId = $transaction['contract_id'] ?? null;

        if (! $contractId) {
            Log::warning("MasterStreamProcessor: buy without contract_id on connection #{$this->connectionId} — not copied.");

            return [];
        }

        if (is_numeric($transaction['amount'] ?? null)) {
            Redis::setex(
                self::buyPriceKey($this->connectionId, (string) $contractId),
                self::BUY_PRICE_TTL_SECONDS,
                abs((float) $transaction['amount']),
            );
        }

        $reqId = $this->takeReqId();
        $this->pendingContracts[$reqId] = $transaction;

        Log::info("MasterStreamProcessor: buy detected on connection #{$this->connectionId} (contract #{$contractId}).");

        return [[
            'proposal_open_contract' => 1,
            'contract_id' => $contractId,
            'req_id' => $reqId,
        ]];
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return list<array<string, mixed>>
     */
    private function handleSell(array $transaction): array
    {
        $contractId = (string) ($transaction['contract_id'] ?? '');
        $buyPriceKey = self::buyPriceKey($this->connectionId, $contractId);
        $buyPrice = $contractId !== '' ? Redis::get($buyPriceKey) : null;

        if (is_numeric($buyPrice) && is_numeric($transaction['amount'] ?? null)) {
            $isWin = ((float) $transaction['amount'] - (float) $buyPrice) > 0 ? 1 : 0;
            $key = "master_outcomes:{$this->connectionId}";

            Redis::lpush($key, $isWin);
            Redis::ltrim($key, 0, 49);
            Redis::incr("master_outcomes_count:{$this->connectionId}");
            Redis::del($buyPriceKey);

            Log::debug("MasterStreamProcessor: sell recorded for connection #{$this->connectionId}", ['is_win' => $isWin]);
        } else {
            Log::warning("MasterStreamProcessor: sell for contract #{$contractId} on connection #{$this->connectionId} has no known buy price — outcome not recorded.");
        }

        CopyTradeJob::clearAllPatternConsumed($this->connectionId);

        return [];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<array<string, mixed>>
     */
    private function handleContract(array $message): array
    {
        $reqId = $message['req_id'] ?? null;

        if ($reqId === null || ! isset($this->pendingContracts[$reqId])) {
            return [];
        }

        $transaction = $this->pendingContracts[$reqId];
        unset($this->pendingContracts[$reqId]);

        try {
            $trade = MasterContractParser::parse($transaction, $message['proposal_open_contract'] ?? []);
        } catch (UncopyableTradeException $e) {
            Log::info("MasterStreamProcessor: copy skipped on connection #{$this->connectionId} — {$e->getMessage()}");

            return [];
        }

        CopyTradeJob::dispatch($this->connectionId, $trade);

        Log::info("MasterStreamProcessor: dispatched CopyTradeJob for connection #{$this->connectionId} (contract #{$trade['contract_id']}).");

        return [];
    }

    private function takeReqId(): int
    {
        $reqId = $this->nextReqId++;

        if ($reqId === self::PING_REQ_ID) {
            $reqId = $this->nextReqId++;
        }

        return $reqId;
    }
}
